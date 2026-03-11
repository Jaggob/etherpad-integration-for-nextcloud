#!/usr/bin/env bash
set -euo pipefail

# Usage:
# NC_BASE_URL="https://cloud.example.tld" \
# NC_USER="admin" \
# NC_APP_PASSWORD="app-password" \
# ./tests/integration/e2e-lifecycle-trash-lock-tolerant.sh "/Apps/Test/lifecycle-trash-lock"
#
# Purpose:
# - Verify trash stays successful when .pad snapshot write hits a lock.
# - Verify lock path reports snapshot_persisted=false and flow remains restorable.
#
# Requirements:
# - Admin user credentials.
# - Nextcloud debug mode enabled (fault injection endpoint is debug-only).

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

PAD_API_BASE="${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/api/v1/pads"
ADMIN_API_BASE="${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/api/v1/admin"
TEST_FAULT_ACTIVE=0

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

set_test_fault() {
	local fault="$1"
	local res
	res="$(request_with_code POST "${ADMIN_API_BASE}/test-fault" --data-urlencode "fault=${fault}")"
	local code
	code="$(extract_http_code "$res")"
	local body
	body="$(extract_body "$res")"
	echo "$body"
	if [[ "$code" -ne 200 ]]; then
		echo "Setting test fault failed with HTTP ${code}. Ensure admin credentials and Nextcloud debug mode are enabled." >&2
		exit 1
	fi
	if [[ "$fault" != "" ]]; then
		TEST_FAULT_ACTIVE=1
	else
		TEST_FAULT_ACTIVE=0
	fi
}

clear_test_fault() {
	if [[ "$TEST_FAULT_ACTIVE" -ne 1 ]]; then
		return
	fi
	set +e
	nc_request POST "${ADMIN_API_BASE}/test-fault" --data-urlencode "fault=" >/dev/null 2>&1
	set -e
	TEST_FAULT_ACTIVE=0
}

nc_init_auth
trap 'clear_test_fault; nc_cleanup_auth' EXIT

echo "[1/6] CREATE ${INPUT_PATH}"
CREATE_RES="$(request_with_code POST "$PAD_API_BASE" --data-urlencode "file=${INPUT_PATH}" --data-urlencode "accessMode=protected")"
CREATE_CODE="$(extract_http_code "$CREATE_RES")"
echo "$(extract_body "$CREATE_RES")"
if [[ "$CREATE_CODE" -ne 200 ]]; then
	echo "Create failed with HTTP ${CREATE_CODE}" >&2
	exit 1
fi

echo "[2/6] SET TEST FAULT trash_write_lock"
set_test_fault "trash_write_lock"

echo "[3/6] TRASH (must still succeed)"
TRASH_RES="$(request_with_code POST "$PAD_API_BASE/trash" --data-urlencode "file=${INPUT_PATH}")"
TRASH_CODE="$(extract_http_code "$TRASH_RES")"
echo "$(extract_body "$TRASH_RES")"
if [[ "$TRASH_CODE" -ne 200 ]]; then
	echo "Expected trash success with lock fault, got HTTP ${TRASH_CODE}" >&2
	exit 1
fi
assert_body_contains "trash-lock" "\"status\":\"trashed\"" "$TRASH_RES"
assert_body_contains "trash-lock" "\"snapshot_persisted\":false" "$TRASH_RES"

echo "[4/6] CLEAR TEST FAULT"
set_test_fault ""

echo "[5/6] RESTORE (must succeed)"
RESTORE_RES="$(request_with_code POST "$PAD_API_BASE/restore" --data-urlencode "file=${INPUT_PATH}")"
RESTORE_CODE="$(extract_http_code "$RESTORE_RES")"
echo "$(extract_body "$RESTORE_RES")"
if [[ "$RESTORE_CODE" -ne 200 ]]; then
	echo "Expected restore success after lock fault path, got HTTP ${RESTORE_CODE}" >&2
	exit 1
fi
assert_body_contains "restore-after-lock" "\"status\":\"restored\"" "$RESTORE_RES"

echo "[6/6] DONE"
echo "Trash lock-tolerant path behaved as expected."

