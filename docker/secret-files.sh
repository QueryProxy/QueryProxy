# shellcheck shell=sh
#
# Docker / Kubernetes secret support: NAME_FILE=/run/secrets/x supplies NAME.
# Sourced by docker/entrypoint.sh; kept free of side effects until
# load_secret_files runs, so tests/Shell can source it on its own.
#
# Rules (the PHP side, App\Support\SecretFile, applies the same ones):
#   - NAME and NAME_FILE both set (non-empty, and NAME not one of the
#     placeholders null / (null) / (empty), which env() reads as unset)
#                                              -> error, nothing is guessed.
#   - NAME_FILE must be a readable, non-empty file.
#   - Trailing CR/LF are stripped, everything else is kept verbatim.
#   - The value is exported as NAME and NAME_FILE is unset, so child
#     processes (php-fpm, worker, scheduler) see one variable, not two.
# Error messages name the variable and the path, never the value.

# Keep in sync with SecretFile::VARIABLES (app/Support/SecretFile.php).
SECRET_FILE_VARIABLES="APP_KEY APP_PREVIOUS_KEYS DB_PASSWORD MAIL_PASSWORD REDIS_PASSWORD SLACK_BOT_USER_OAUTH_TOKEN QUERYPROXY_ADMIN_PASSWORD QUERYPROXY_METRICS_TOKEN QUERYPROXY_SIEM_WEBHOOK_SECRET"

# Prints the contents of $1 without trailing CR/LF. Returns 1 if unreadable.
read_secret_file() {
    _rsf_value="$(cat -- "$1" 2>/dev/null)" || return 1
    _rsf_nl='
'
    _rsf_cr="$(printf '\r')"
    while :; do
        case "$_rsf_value" in
            *"$_rsf_nl" | *"$_rsf_cr") _rsf_value="${_rsf_value%?}" ;;
            *) break ;;
        esac
    done
    printf '%s' "$_rsf_value"
}

# True when $1 is a value Laravel's env() reads as "not set": null, (null),
# (empty), empty (any case). A `.env` or compose line such as REDIS_PASSWORD=null
# must not count as a second definition next to REDIS_PASSWORD_FILE.
is_unset_placeholder() {
    _iup_lower="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
    case "$_iup_lower" in
        null | '(null)' | empty | '(empty)') return 0 ;;
        *) return 1 ;;
    esac
}

# Resolves every NAME_FILE in SECRET_FILE_VARIABLES. Returns 1 on the first
# misconfiguration (the caller decides whether that is fatal).
load_secret_files() {
    for _lsf_name in $SECRET_FILE_VARIABLES; do
        eval "_lsf_file=\${${_lsf_name}_FILE:-}"
        [ -n "$_lsf_file" ] || continue

        eval "_lsf_current=\${${_lsf_name}:-}"
        if [ -n "$_lsf_current" ] && ! is_unset_placeholder "$_lsf_current"; then
            echo "[entrypoint] Both ${_lsf_name} and ${_lsf_name}_FILE are set; define only one of them." >&2
            return 1
        fi

        if [ ! -f "$_lsf_file" ] || [ ! -r "$_lsf_file" ]; then
            echo "[entrypoint] ${_lsf_name}_FILE points at '${_lsf_file}', which is not a readable file." >&2
            return 1
        fi

        _lsf_value="$(read_secret_file "$_lsf_file")" || {
            echo "[entrypoint] ${_lsf_name}_FILE points at '${_lsf_file}', which could not be read." >&2
            return 1
        }

        if [ -z "$_lsf_value" ]; then
            echo "[entrypoint] ${_lsf_name}_FILE points at '${_lsf_file}', which is empty." >&2
            return 1
        fi

        export "${_lsf_name}=${_lsf_value}"
        unset "${_lsf_name}_FILE"
    done
}
