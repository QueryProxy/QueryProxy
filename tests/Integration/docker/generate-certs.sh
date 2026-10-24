#!/bin/sh
# Test-only certificates for the TLS servers of docker-compose.integration.yml.
#
# Writes a throwaway CA and one server certificate (valid for localhost and
# 127.0.0.1) into tests/Integration/docker/certs/. The files are git-ignored,
# the keys are world-readable on purpose (the database containers run as their
# own user and the keys protect nothing), and an existing set is kept unless
# --force is given or it is about to expire.
#
#   tests/Integration/docker/generate-certs.sh [--force]

set -eu

dir="$(cd "$(dirname "$0")" && pwd)/certs"

# Keep an existing set only while both certificates are good for another day:
# an expired one would make the TLS tests fail with a certificate error that
# looks like an application problem.
if [ -f "$dir/ca.crt" ] && [ -f "$dir/server.crt" ] && [ -f "$dir/server.key" ] && [ "${1:-}" != "--force" ] \
    && openssl x509 -checkend 86400 -noout -in "$dir/ca.crt" >/dev/null \
    && openssl x509 -checkend 86400 -noout -in "$dir/server.crt" >/dev/null; then
    echo "Certificates already exist in $dir (use --force to replace them)."
    exit 0
fi

mkdir -p "$dir"
rm -f "$dir"/*.crt "$dir"/*.key "$dir"/*.csr "$dir"/*.srl "$dir"/*.cnf

openssl req -x509 -newkey rsa:2048 -nodes -days 30 \
    -keyout "$dir/ca.key" -out "$dir/ca.crt" \
    -subj "/CN=QueryProxy integration test CA" 2>/dev/null

openssl req -newkey rsa:2048 -nodes \
    -keyout "$dir/server.key" -out "$dir/server.csr" \
    -subj "/CN=localhost" 2>/dev/null

cat > "$dir/server.cnf" <<'CNF'
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:localhost, IP:127.0.0.1
CNF

openssl x509 -req -in "$dir/server.csr" -days 30 \
    -CA "$dir/ca.crt" -CAkey "$dir/ca.key" -CAcreateserial \
    -extfile "$dir/server.cnf" -out "$dir/server.crt" 2>/dev/null

chmod 644 "$dir"/*.crt "$dir"/*.key
echo "Wrote $dir/{ca,server}.{crt,key}"
