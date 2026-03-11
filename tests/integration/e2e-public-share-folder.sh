#!/usr/bin/env bash
set -euo pipefail

# Usage:
# NC_BASE_URL="https://cloud.example.tld" \
# NC_USER="alice" \
# NC_APP_PASSWORD="app-password" \
# ./tests/integration/e2e-public-share-folder.sh "/codex-e2e-public-folder"

if [[ $# -lt 1 ]]; then
	echo "Usage: $0 <base-folder-path>" >&2
	exit 1
fi

: "${NC_BASE_URL:?NC_BASE_URL is required}"
: "${NC_USER:?NC_USER is required}"
: "${NC_APP_PASSWORD:?NC_APP_PASSWORD is required}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tests/integration/lib-nextcloud-auth.sh
source "${SCRIPT_DIR}/lib-nextcloud-auth.sh"

BASE_FOLDER="$1"
RUN_ID="$(date +%s)"
FOLDER_PATH="${BASE_FOLDER%/}-${RUN_ID}"
PAD_NAME="public-folder-${RUN_ID}.pad"
PAD_PATH="${FOLDER_PATH}/${PAD_NAME}"

PADS_API="${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/api/v1/pads"
DAV_BASE="${NC_BASE_URL%/}/remote.php/dav/files/${NC_USER}"
OCS_BASE="${NC_BASE_URL%/}/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json"
nc_init_auth
trap 'nc_cleanup_auth' EXIT

request_auth() {
	local method="$1"
	local url="$2"
	shift 2
	nc_request "$method" "$url" "$@"
}

request_auth_with_code() {
	local method="$1"
	local url="$2"
	shift 2
	nc_request_with_code "$method" "$url" "$@"
}

request_public_with_code() {
	local url="$1"
	shift
	curl -sS -X GET "$url" "$@" -w $'\n%{http_code}'
}

json_get() {
	local key="$1"
	php -r '
		$body = stream_get_contents(STDIN);
		$data = json_decode($body, true);
		if (!is_array($data)) {
			fwrite(STDERR, "Invalid JSON\n");
			exit(2);
		}
		$parts = explode(".", $argv[1]);
		$cur = $data;
		foreach ($parts as $p) {
			if (!is_array($cur) || !array_key_exists($p, $cur)) {
				fwrite(STDERR, "Missing key: " . $argv[1] . "\n");
				exit(3);
			}
			$cur = $cur[$p];
		}
		if (is_scalar($cur)) {
			echo (string)$cur;
			exit(0);
		}
		echo json_encode($cur);
	' "$key"
}

assert_http_200() {
	local label="$1"
	local response="$2"
	local code
	code="$(printf '%s' "$response" | tail -n1)"
	local body
	body="$(printf '%s' "$response" | sed '$d')"
	echo "[${label}] HTTP ${code}"
	if [[ "$code" -ne 200 ]]; then
		echo "$body"
		echo "Expected HTTP 200 for ${label}" >&2
		exit 1
	fi
}

assert_http_one_of() {
	local label="$1"
	local response="$2"
	shift 2
	local code
	code="$(printf '%s' "$response" | tail -n1)"
	local body
	body="$(printf '%s' "$response" | sed '$d')"
	echo "[${label}] HTTP ${code}"
	for expected in "$@"; do
		if [[ "$code" -eq "$expected" ]]; then
			return 0
		fi
	done
	echo "$body"
	echo "Expected one of HTTP $* for ${label}" >&2
	exit 1
}

echo "[1/10] MKCOL ${FOLDER_PATH}"
request_auth MKCOL "${DAV_BASE}${FOLDER_PATH}"

echo "[2/10] CREATE PAD ${PAD_PATH}"
CREATE_JSON="$(request_auth POST "${PADS_API}" --data-urlencode "file=${PAD_PATH}" --data-urlencode "accessMode=protected")"
echo "$CREATE_JSON"

echo "[3/10] CREATE PUBLIC SHARE for folder ${FOLDER_PATH}"
SHARE_JSON="$(request_auth POST "${OCS_BASE}" -H "OCS-APIRequest: true" --data-urlencode "path=${FOLDER_PATH}" --data-urlencode "shareType=3" --data-urlencode "permissions=1")"
TOKEN="$(printf '%s' "$SHARE_JSON" | json_get "ocs.data.token")"
if [[ -z "$TOKEN" ]]; then
	echo "Failed to extract share token" >&2
	exit 1
fi
echo "TOKEN=${TOKEN}"

echo "[4/10] PUBLIC VIEWER route"
VIEWER_RES="$(request_public_with_code "${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/public/${TOKEN}?file=/${PAD_NAME}")"
assert_http_one_of "public-viewer-route" "$VIEWER_RES" 200 303

echo "[5/10] PUBLIC OPEN API"
OPEN_RES="$(request_public_with_code "${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/api/v1/public/open/${TOKEN}?file=/${PAD_NAME}")"
assert_http_200 "public-open-api" "$OPEN_RES"
OPEN_BODY="$(printf '%s' "$OPEN_RES" | sed '$d')"
OPEN_URL="$(printf '%s' "$OPEN_BODY" | json_get "url")"
if [[ -z "$OPEN_URL" ]]; then
	echo "Public open API did not return url" >&2
	exit 1
fi

echo "[6/10] PUBLIC OPEN API with DAV-style file parameter"
DAV_FILE_URL="${NC_BASE_URL%/}/public.php/dav/files/${TOKEN}/${PAD_NAME}"
OPEN_DAV_RES="$(request_public_with_code "${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/api/v1/public/open/${TOKEN}?file=${DAV_FILE_URL}")"
assert_http_200 "public-open-api-dav-file-param" "$OPEN_DAV_RES"

echo "[7/10] PUBLIC VIEWER route without file (folder share)"
VIEWER_MISSING_FILE_RES="$(request_public_with_code "${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/public/${TOKEN}")"
# Current behavior redirects to share root (303). Keep 400 as compatibility fallback.
assert_http_one_of "public-viewer-missing-file" "$VIEWER_MISSING_FILE_RES" 303 400

echo "[8/10] PUBLIC DOWNLOAD endpoint for shared file"
DOWNLOAD_RES="$(request_public_with_code "${NC_BASE_URL%/}/index.php/s/${TOKEN}/download?path=/&files=${PAD_NAME}")"
assert_http_one_of "public-download-file" "$DOWNLOAD_RES" 200 302 303

echo "[9/10] ROUTE SWITCH to share root then reopen"
ROOT_RES="$(request_public_with_code "${NC_BASE_URL%/}/index.php/s/${TOKEN}?dir=/")"
assert_http_200 "public-share-root-route" "$ROOT_RES"

echo "[10/10] REOPEN via public viewer route"
REOPEN_RES="$(request_public_with_code "${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/public/${TOKEN}?file=/${PAD_NAME}")"
assert_http_one_of "public-reopen-route" "$REOPEN_RES" 200 303

echo "Public folder share E2E passed."
