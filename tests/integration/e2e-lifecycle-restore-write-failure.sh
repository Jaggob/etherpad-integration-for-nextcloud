#!/usr/bin/env bash
set -euo pipefail

# Usage:
# NC_BASE_URL="https://cloud.example.tld" \
# NC_USER="admin" \
# NC_APP_PASSWORD="app-password" \
# ./tests/integration/e2e-lifecycle-restore-write-failure.sh "/Apps/Test/lifecycle-restore-write-fail"
#
# Purpose:
# - Verify restore fails hard when writing updated .pad metadata fails.
# - Verify binding stays trashed after failure.
# - Verify restore can succeed after clearing the injected write fault.
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

echo "[1/8] CREATE ${INPUT_PATH}"
CREATE_RES="$(request_with_code POST "$PAD_API_BASE" --data-urlencode "file=${INPUT_PATH}" --data-urlencode "accessMode=protected")"
CREATE_CODE="$(extract_http_code "$CREATE_RES")"
echo "$(extract_body "$CREATE_RES")"
if [[ "$CREATE_CODE" -ne 200 ]]; then
	echo "Create failed with HTTP ${CREATE_CODE}" >&2
	exit 1
fi

echo "[2/8] TRASH setup"
TRASH_RES="$(request_with_code POST "$PAD_API_BASE/trash" --data-urlencode "file=${INPUT_PATH}")"
TRASH_CODE="$(extract_http_code "$TRASH_RES")"
echo "$(extract_body "$TRASH_RES")"
if [[ "$TRASH_CODE" -ne 200 ]]; then
	echo "Expected trash setup success, got HTTP ${TRASH_CODE}" >&2
	exit 1
fi

echo "[3/8] SET TEST FAULT restore_write_fail"
set_test_fault "restore_write_fail"

echo "[4/8] RESTORE (expected failure)"
RESTORE_FAIL_RES="$(request_with_code POST "$PAD_API_BASE/restore" --data-urlencode "file=${INPUT_PATH}")"
RESTORE_FAIL_CODE="$(extract_http_code "$RESTORE_FAIL_RES")"
echo "$(extract_body "$RESTORE_FAIL_RES")"
if [[ "$RESTORE_FAIL_CODE" -ge 200 && "$RESTORE_FAIL_CODE" -lt 300 ]]; then
	echo "Expected restore failure with write fault, but got HTTP ${RESTORE_FAIL_CODE}" >&2
	exit 1
fi

echo "[5/8] TRASH again must still be skipped (binding remains trashed)"
TRASH_AGAIN_RES="$(request_with_code POST "$PAD_API_BASE/trash" --data-urlencode "file=${INPUT_PATH}")"
TRASH_AGAIN_CODE="$(extract_http_code "$TRASH_AGAIN_RES")"
echo "$(extract_body "$TRASH_AGAIN_RES")"
if [[ "$TRASH_AGAIN_CODE" -ne 409 ]]; then
	echo "Expected HTTP 409 for trash-again guard, got ${TRASH_AGAIN_CODE}" >&2
	exit 1
fi
assert_body_contains "trash-again" "\"status\":\"skipped\"" "$TRASH_AGAIN_RES"
assert_body_contains "trash-again" "binding_not_active" "$TRASH_AGAIN_RES"

echo "[6/8] CLEAR TEST FAULT"
set_test_fault ""

echo "[7/8] RESTORE retry (must succeed)"
RESTORE_OK_RES="$(request_with_code POST "$PAD_API_BASE/restore" --data-urlencode "file=${INPUT_PATH}")"
RESTORE_OK_CODE="$(extract_http_code "$RESTORE_OK_RES")"
echo "$(extract_body "$RESTORE_OK_RES")"
if [[ "$RESTORE_OK_CODE" -ne 200 ]]; then
	echo "Expected restore success after clearing write fault, got HTTP ${RESTORE_OK_CODE}" >&2
	exit 1
fi
assert_body_contains "restore-retry" "\"status\":\"restored\"" "$RESTORE_OK_RES"

echo "[8/8] DONE"
echo "Restore write-failure path behaved as expected."

