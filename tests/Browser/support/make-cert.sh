#!/bin/sh
# Generates the self-signed certificate the browser group's TLS proxy serves.
#
# The analytics plugin attaches the API key only when beaconUrl is https, so the
# browser test posts to https://127.0.0.1:8443 (tls-proxy.mjs), which forwards to
# the plain-http ingest. The certificate covers 127.0.0.1 and localhost and is
# regenerated on every run: it is a throwaway, gitignored, and never trusted by
# anything but a browser launched with ignoreHTTPSErrors.
#
# Output: tests/Fixtures/tls/cert.pem and tests/Fixtures/tls/key.pem
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
out="${root}/tests/Fixtures/tls"

if ! command -v openssl >/dev/null 2>&1; then
    echo "make-cert.sh: openssl is not on PATH" >&2
    exit 1
fi

mkdir -p "$out"
rm -f "$out/cert.pem" "$out/key.pem"

openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 2 \
    -keyout "$out/key.pem" \
    -out "$out/cert.pem" \
    -subj "/CN=127.0.0.1" \
    -addext "subjectAltName=IP:127.0.0.1,DNS:localhost" \
    -addext "basicConstraints=critical,CA:FALSE" \
    -addext "extendedKeyUsage=serverAuth" \
    >/dev/null 2>&1

chmod 600 "$out/key.pem"
echo "make-cert.sh: wrote $out/cert.pem and $out/key.pem"
