#!/bin/sh
# Shell test for docker/secret-files.sh (the *_FILE support of the entrypoint).
# Run: sh tests/Shell/secret-files.test.sh   (exit 0 = all passed)
# single quotes are intentional below
# shellcheck disable=SC2016
# CI-friendly: POSIX sh, no dependencies besides coreutils.

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
LIB="$ROOT/docker/secret-files.sh"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/qp-secret-files.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

failures=0
checks=0

# Runs a snippet in a fresh sh with a clean secret environment; the snippet
# sees $WORK. Prints "<exit>|<stdout>" and keeps stderr in $WORK/stderr.
run() {
    env -i PATH="$PATH" WORK="$WORK" LIB="$LIB" "$@" sh -c '
        . "$LIB"
        load_secret_files
        status=$?
        printf "%s|%s|%s|%s" "$status" "${DB_PASSWORD-<unset>}" "${DB_PASSWORD_FILE-<unset>}" "${APP_KEY-<unset>}"
    ' 2>"$WORK/stderr"
}

expect() {
    checks=$((checks + 1))
    if [ "$2" != "$3" ]; then
        failures=$((failures + 1))
        echo "FAIL: $1" >&2
        echo "  expected: $3" >&2
        echo "  actual:   $2" >&2
    fi
}

printf 's3cr3t\n' >"$WORK/db_password"
printf 'p@ss word\r\n' >"$WORK/crlf"
printf 'base64:abc=' >"$WORK/app_key"
: >"$WORK/empty"

# 1. DB_PASSWORD_FILE is read, the trailing newline dropped, the _FILE variable unset.
expect "DB_PASSWORD_FILE value reaches the environment" \
    "$(run DB_PASSWORD_FILE="$WORK/db_password")" "0|s3cr3t|<unset>|<unset>"

# 2. CRLF is stripped, inner whitespace is kept.
expect "CRLF stripped, inner whitespace kept" \
    "$(run DB_PASSWORD_FILE="$WORK/crlf")" "0|p@ss word|<unset>|<unset>"

# 3. APP_KEY_FILE works for the application key.
expect "APP_KEY_FILE supplies APP_KEY" \
    "$(run APP_KEY_FILE="$WORK/app_key")" "0|<unset>|<unset>|base64:abc="

# 4. Plain variable without _FILE is untouched.
expect "plain DB_PASSWORD is left alone" \
    "$(run DB_PASSWORD=plain)" "0|plain|<unset>|<unset>"

# 5. Both set is an error, and the message names the variable but not the value.
result="$(run DB_PASSWORD=plain DB_PASSWORD_FILE="$WORK/db_password")"
expect "NAME and NAME_FILE together fail" "${result%%|*}" "1"
case "$(cat "$WORK/stderr")" in
    *"Both DB_PASSWORD and DB_PASSWORD_FILE are set"*) ;;
    *) failures=$((failures + 1)); echo "FAIL: conflict message" >&2 ;;
esac
checks=$((checks + 1))
case "$(cat "$WORK/stderr")" in
    *plain* | *s3cr3t*) failures=$((failures + 1)); echo "FAIL: error message leaks a secret value" >&2 ;;
esac
checks=$((checks + 1))

# 6. Missing file, empty file.
result="$(run DB_PASSWORD_FILE="$WORK/does-not-exist")"
expect "missing file fails" "${result%%|*}" "1"
result="$(run DB_PASSWORD_FILE="$WORK/empty")"
expect "empty file fails" "${result%%|*}" "1"

# 7. An empty NAME_FILE variable counts as not set.
expect "empty DB_PASSWORD_FILE is ignored" \
    "$(run DB_PASSWORD_FILE= DB_PASSWORD=plain)" "0|plain||<unset>"

# 7b. Laravel env() placeholders next to NAME_FILE are not a second definition.
for placeholder in null '(null)' NULL '(empty)' empty; do
    expect "placeholder $placeholder does not conflict with DB_PASSWORD_FILE" \
        "$(run DB_PASSWORD="$placeholder" DB_PASSWORD_FILE="$WORK/db_password")" "0|s3cr3t|<unset>|<unset>"
done

# 7c. A path that starts with a dash is a path, not an option.
(cd "$WORK" && printf 'dash-secret' >./-dash && cp "$LIB" ./lib.sh)
result="$(cd "$WORK" && env -i PATH="$PATH" DB_PASSWORD_FILE=-dash sh -c '. ./lib.sh; load_secret_files; printf "%s|%s" "$?" "${DB_PASSWORD-<unset>}"' 2>"$WORK/stderr")"
expect "dash-leading path is read" "$result" "0|dash-secret"

# 7d. NAME_FILE is a path, taken verbatim like SecretFile does: "null" is a file
# called null, and quotes are part of the path.
printf 'file-called-null' >"$WORK/null"
result="$(cd "$WORK" && env -i PATH="$PATH" DB_PASSWORD_FILE=null sh -c '. ./lib.sh; load_secret_files; printf "%s|%s" "$?" "${DB_PASSWORD-<unset>}"' 2>"$WORK/stderr")"
expect "NAME_FILE=null reads the file called null" "$result" "0|file-called-null"
result="$(run DB_PASSWORD_FILE="\"$WORK/db_password\"")"
expect "a quoted NAME_FILE path is not unquoted" "${result%%|*}" "1"

# 8. A value with shell metacharacters is exported verbatim.
printf '%s' '$HOME "quoted" `cmd` ; rm -rf' >"$WORK/meta"
expect "metacharacters survive" \
    "$(run DB_PASSWORD_FILE="$WORK/meta")" '0|$HOME "quoted" `cmd` ; rm -rf|<unset>|<unset>'

if [ "$failures" -gt 0 ]; then
    echo "$failures of $checks checks failed" >&2
    exit 1
fi

echo "secret-files: $checks checks passed"
