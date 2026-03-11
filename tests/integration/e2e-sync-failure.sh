#!/usr/bin/env bash
set -euo pipefail

# Usage:
# NC_BASE_URL="https://cloud.example.tld" \
# NC_USER="alice" \
# NC_APP_PASSWORD="app-password" \
# ./tests/integration/e2e-sync-failure.sh "/Apps/Test/sync-failure-demo"
#
# This script verifies the negative path:
# - sync endpoint must fail hard (non-2xx) when Etherpad is unavailable/misconfigured.
# - no silent "best effort" success is accepted.
#
# Note:
#   Prepare the failure condition before running (e.g. stop Etherpad or set invalid
#   etherpad_host in app config on server). This script intentionally does not mutate
#   server config.

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

json_get() {
	local key="$1"
	php -r '
		$key = $argv[1];
		$data = json_decode(stream_get_contents(STDIN), true);
		if (!is_array($data) || !array_key_exists($key, $data)) {
			exit(2);
		}
		$value = $data[$key];
		if (is_array($value) || is_object($value)) {
			echo json_encode($value);
		} else {
			echo (string)$value;
		}
	' "$key"
}

echo "[1/3] CREATE ${INPUT_PATH}"
CREATE_JSON=$(nc_request POST "$API_BASE" \
	--data-urlencode "file=${INPUT_PATH}" \
	--data-urlencode "accessMode=protected")
echo "$CREATE_JSON"

FILE_ID="$(printf '%s' "$CREATE_JSON" | json_get "file_id")"
if [[ -z "$FILE_ID" ]]; then
	echo "Could not read file_id from create response." >&2
	exit 1
fi

echo "[2/3] SYNC (expected failure) file_id=${FILE_ID}"
SYNC_URL="${API_BASE}/sync/${FILE_ID}?force=1"
set +e
SYNC_BODY="$(nc_request_with_code POST "$SYNC_URL")"
SYNC_EXIT=$?
set -e

HTTP_CODE="$(printf '%s' "$SYNC_BODY" | tail -n1)"
HTTP_BODY="$(printf '%s' "$SYNC_BODY" | sed '$d')"
echo "$HTTP_BODY"
echo "HTTP ${HTTP_CODE}"

if [[ "$SYNC_EXIT" -eq 0 && "$HTTP_CODE" -ge 200 && "$HTTP_CODE" -lt 300 ]]; then
	echo "Expected sync failure, but got success (HTTP ${HTTP_CODE})." >&2
	exit 1
fi

echo "[3/3] RESULT"
echo "Sync failure path behaved as expected (no silent success)."
