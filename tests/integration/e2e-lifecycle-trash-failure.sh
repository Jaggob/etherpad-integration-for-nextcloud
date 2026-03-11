#!/usr/bin/env bash
set -euo pipefail

# Usage:
# NC_BASE_URL="https://cloud.example.tld" \
# NC_USER="alice" \
# NC_APP_PASSWORD="app-password" \
# ./tests/integration/e2e-lifecycle-trash-failure.sh "/Apps/Test/lifecycle-trash-failure"
#
# Purpose:
# - Verify trash still succeeds when Etherpad is unavailable/misconfigured.
# - Verify response marks deferred deletion via delete_pending=true.
#
# Note:
#   Prepare failure condition before running (e.g. stop Etherpad or set invalid
#   etherpad_host). This script intentionally does not mutate server config.

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

echo "[1/3] CREATE ${INPUT_PATH}"
CREATE_RES="$(request_with_code POST "$API_BASE" --data-urlencode "file=${INPUT_PATH}" --data-urlencode "accessMode=protected")"
CREATE_CODE="$(extract_http_code "$CREATE_RES")"
echo "$(extract_body "$CREATE_RES")"
if [[ "$CREATE_CODE" -ne 200 ]]; then
	echo "Create failed with HTTP ${CREATE_CODE}" >&2
	exit 1
fi

echo "[2/3] TRASH (must succeed with deferred delete)"
TRASH_RES="$(request_with_code POST "$API_BASE/trash" --data-urlencode "file=${INPUT_PATH}")"
TRASH_CODE="$(extract_http_code "$TRASH_RES")"
echo "$(extract_body "$TRASH_RES")"
echo "HTTP ${TRASH_CODE}"
if [[ "$TRASH_CODE" -ne 200 ]]; then
	echo "Expected trash success, got HTTP ${TRASH_CODE}." >&2
	exit 1
fi
assert_body_contains "trash-deferred" "\"status\":\"trashed\"" "$TRASH_RES"
assert_body_contains "trash-deferred" "\"delete_pending\":true" "$TRASH_RES"

echo "[3/3] TRASH again must be skipped (binding is no longer active)"
TRASH_AGAIN_RES="$(request_with_code POST "$API_BASE/trash" --data-urlencode "file=${INPUT_PATH}")"
TRASH_AGAIN_CODE="$(extract_http_code "$TRASH_AGAIN_RES")"
echo "$(extract_body "$TRASH_AGAIN_RES")"
if [[ "$TRASH_AGAIN_CODE" -ne 409 ]]; then
	echo "Expected HTTP 409 for second trash, got ${TRASH_AGAIN_CODE}" >&2
	exit 1
fi
assert_body_contains "trash-again" "\"status\":\"skipped\"" "$TRASH_AGAIN_RES"
assert_body_contains "trash-again" "binding_not_active" "$TRASH_AGAIN_RES"

echo "Trash deferred-delete path behaved as expected."
