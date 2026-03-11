#!/usr/bin/env bash
set -euo pipefail

nc_require_env() {
	: "${NC_BASE_URL:?NC_BASE_URL is required}"
	: "${NC_USER:?NC_USER is required}"
	: "${NC_APP_PASSWORD:?NC_APP_PASSWORD is required}"
}

nc_init_auth() {
	nc_require_env
	NC_AUTH="${NC_USER}:${NC_APP_PASSWORD}"
	NC_CSRF_COOKIE_JAR="$(mktemp)"
	nc_refresh_csrf
}

nc_refresh_csrf() {
	local csrf_json
	csrf_json="$(curl --fail-with-body -sS -u "$NC_AUTH" -b "$NC_CSRF_COOKIE_JAR" -c "$NC_CSRF_COOKIE_JAR" "${NC_BASE_URL%/}/index.php/csrftoken")"
	NC_CSRF_TOKEN="$(printf '%s' "$csrf_json" | php -r '
		$data = json_decode(stream_get_contents(STDIN), true);
		if (!is_array($data) || !isset($data["token"]) || !is_string($data["token"])) {
			exit(2);
		}
		echo $data["token"];
	')"
	if [[ -z "${NC_CSRF_TOKEN}" ]]; then
		echo "Could not fetch CSRF token." >&2
		exit 2
	fi
}

nc_cleanup_auth() {
	if [[ -n "${NC_CSRF_COOKIE_JAR:-}" && -f "${NC_CSRF_COOKIE_JAR}" ]]; then
		rm -f "${NC_CSRF_COOKIE_JAR}"
	fi
}

nc_request() {
	local method="$1"
	local url="$2"
	shift 2
	local method_upper
	method_upper="$(printf '%s' "$method" | tr '[:lower:]' '[:upper:]')"

	local -a cmd=(curl --fail-with-body -sS -u "$NC_AUTH" -b "$NC_CSRF_COOKIE_JAR" -c "$NC_CSRF_COOKIE_JAR" -X "$method" "$url")
	case "$method_upper" in
		GET|HEAD|OPTIONS)
			;;
		*)
			nc_refresh_csrf
			cmd+=(-H "requesttoken: ${NC_CSRF_TOKEN}")
			;;
	esac
	cmd+=("$@")
	"${cmd[@]}"
}

nc_request_with_code() {
	local method="$1"
	local url="$2"
	shift 2
	local method_upper
	method_upper="$(printf '%s' "$method" | tr '[:lower:]' '[:upper:]')"

	local -a cmd=(curl -sS -u "$NC_AUTH" -b "$NC_CSRF_COOKIE_JAR" -c "$NC_CSRF_COOKIE_JAR" -X "$method" "$url")
	case "$method_upper" in
		GET|HEAD|OPTIONS)
			;;
		*)
			nc_refresh_csrf
			cmd+=(-H "requesttoken: ${NC_CSRF_TOKEN}")
			;;
	esac
	cmd+=("$@" -w $'\n%{http_code}')
	"${cmd[@]}"
}
