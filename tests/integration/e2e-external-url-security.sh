#!/usr/bin/env bash
set -euo pipefail

# Usage:
# NC_BASE_URL="https://cloud.example.tld" \
# NC_USER="alice" \
# NC_APP_PASSWORD="app-password" \
# ./tests/integration/e2e-external-url-security.sh "/codex-e2e-ssrf"

if [[ $# -lt 1 ]]; then
	echo "Usage: $0 <base-pad-path-prefix>" >&2
	exit 1
fi

: "${NC_BASE_URL:?NC_BASE_URL is required}"
: "${NC_USER:?NC_USER is required}"
: "${NC_APP_PASSWORD:?NC_APP_PASSWORD is required}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tests/integration/lib-nextcloud-auth.sh
source "${SCRIPT_DIR}/lib-nextcloud-auth.sh"

BASE_PREFIX="$1"
API_BASE="${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/api/v1/pads"
RUN_ID="$(date +%s)"
nc_init_auth
trap 'nc_cleanup_auth' EXIT

request_from_url() {
	local file_path="$1"
	local pad_url="$2"
	nc_request_with_code POST "${API_BASE}/from-url" \
		--data-urlencode "file=${file_path}" \
		--data-urlencode "padUrl=${pad_url}"
}

assert_rejected() {
	local case_name="$1"
	local pad_url="$2"
	local expected_substring="$3"
	local file_path="${BASE_PREFIX}-${RUN_ID}-${case_name}.pad"

	echo "[CASE] ${case_name}"
	RESULT="$(request_from_url "$file_path" "$pad_url")"
	HTTP_CODE="$(printf '%s' "$RESULT" | tail -n1)"
	BODY="$(printf '%s' "$RESULT" | sed '$d')"
	echo "$BODY"
	echo "HTTP ${HTTP_CODE}"

	if [[ "$HTTP_CODE" -ne 400 ]]; then
		echo "Expected HTTP 400 for case '${case_name}', got ${HTTP_CODE}" >&2
		exit 1
	fi
	if ! printf '%s' "$BODY" | rg -q "$expected_substring"; then
		echo "Expected error message containing '${expected_substring}' for case '${case_name}'" >&2
		exit 1
	fi
}

assert_rejected "http-scheme" "http://example.org/p/demo-pad" "Invalid public pad URL|valid https URL|Only https pad URLs are allowed"
assert_rejected "localhost" "https://localhost/p/demo-pad" "Local hosts are not allowed"
assert_rejected "private-ip" "https://127.0.0.1/p/demo-pad" "Private\\\\/reserved IPs are not allowed|Private/reserved IPs are not allowed"
assert_rejected "group-pad" "https://example.org/p/g.testgroup\$demo-pad" "Only public pad URLs can be linked"

echo "External URL security checks passed."
