#!/usr/bin/env bash
set -euo pipefail

# Usage:
# NC_BASE_URL="https://cloud.example.tld" \
# NC_USER="alice" \
# NC_APP_PASSWORD="app-password" \
# ./tests/integration/e2e-lifecycle-restore-failure.sh "/Apps/Test/lifecycle-restore-failure"
#
# Purpose:
# - Verify restore fails hard (non-2xx) when Etherpad is unavailable/misconfigured.
# - Verify binding stays trashed after failure (trash-again => 409 binding_not_active).
#
# Note:
#   This script creates and trashes a pad first. Prepare failure condition before the
#   restore step (e.g. stop Etherpad or set invalid etherpad_host), then run script.

if [[ $# -lt 1 ]]; then
	echo "Usage: $0 <pad-path-without-extension-or-with-.pad>" >&2
	exit 1
fi

: "${NC_BASE_URL:?NC_BASE_URL is required}"
: "${NC_USER:?NC_USER is required}"
: "${NC_APP_PASSWORD:?NC_APP_PASSWORD is required}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tests/integration/lib-nextcloud-auth.sh
source "${SCRIPT_DIR}/lib-nextcloud-auth.sh"

INPUT_PATH="$1"
if [[ "$INPUT_PATH" != *.pad ]]; then
	INPUT_PATH="${INPUT_PATH}.pad"
fi

API_BASE="${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/api/v1/pads"
nc_init_auth
trap 'nc_cleanup_auth' EXIT

request_with_code() {
	local method="$1"
	local url="$2"
	shift 2
	nc_request_with_code "$method" "$url" "$@"
}

extract_http_code() {
	printf '%s' "$1" | tail -n1
}

extract_body() {
	printf '%s' "$1" | sed '$d'
}

assert_body_contains() {
	local label="$1"
	local needle="$2"
	local response="$3"
	local body
	body="$(extract_body "$response")"
	if ! printf '%s' "$body" | rg -q "$needle"; then
		echo "Expected ${label} response body to contain: ${needle}" >&2
		exit 1
	fi
}

echo "[1/4] CREATE ${INPUT_PATH}"
CREATE_RES="$(request_with_code POST "$API_BASE" --data-urlencode "file=${INPUT_PATH}" --data-urlencode "accessMode=protected")"
CREATE_CODE="$(extract_http_code "$CREATE_RES")"
echo "$(extract_body "$CREATE_RES")"
if [[ "$CREATE_CODE" -ne 200 ]]; then
	echo "Create failed with HTTP ${CREATE_CODE}" >&2
	exit 1
fi

echo "[2/4] TRASH (must succeed before restore-failure check)"
TRASH_RES="$(request_with_code POST "$API_BASE/trash" --data-urlencode "file=${INPUT_PATH}")"
TRASH_CODE="$(extract_http_code "$TRASH_RES")"
echo "$(extract_body "$TRASH_RES")"
if [[ "$TRASH_CODE" -ne 200 ]]; then
	echo "Expected successful trash setup, got HTTP ${TRASH_CODE}" >&2
	exit 1
fi

echo "[3/4] RESTORE (expected failure)"
RESTORE_RES="$(request_with_code POST "$API_BASE/restore" --data-urlencode "file=${INPUT_PATH}")"
RESTORE_CODE="$(extract_http_code "$RESTORE_RES")"
echo "$(extract_body "$RESTORE_RES")"
echo "HTTP ${RESTORE_CODE}"
if [[ "$RESTORE_CODE" -ge 200 && "$RESTORE_CODE" -lt 300 ]]; then
	echo "Expected restore failure, but got success (HTTP ${RESTORE_CODE})." >&2
	exit 1
fi

echo "[4/4] TRASH again must stay skipped while binding is trashed"
TRASH_AGAIN_RES="$(request_with_code POST "$API_BASE/trash" --data-urlencode "file=${INPUT_PATH}")"
TRASH_AGAIN_CODE="$(extract_http_code "$TRASH_AGAIN_RES")"
echo "$(extract_body "$TRASH_AGAIN_RES")"
if [[ "$TRASH_AGAIN_CODE" -ne 409 ]]; then
	echo "Expected HTTP 409 for trash-again guard, got ${TRASH_AGAIN_CODE}" >&2
	exit 1
fi
assert_body_contains "trash-again" "\"status\":\"skipped\"" "$TRASH_AGAIN_RES"
assert_body_contains "trash-again" "binding_not_active" "$TRASH_AGAIN_RES"

echo "Restore failure path behaved as expected (hard fail + trashed state retained)."
