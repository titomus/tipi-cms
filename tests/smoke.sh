#!/usr/bin/env bash
set -euo pipefail
ROOT="$(mktemp -d)"
PID=""
trap 'if [ -n "$PID" ]; then kill "$PID" 2>/dev/null || true; fi; rm -rf "$ROOT"' EXIT
mkdir "$ROOT/public"
cp tipi-install.php "$ROOT/public/tipi-install.php"
export TIPI_SETUP_KEY=preview-test-key-123456789 TIPI_DATA_DIR="$ROOT/private"
cd "$ROOT/public"
php -S 127.0.0.1:18765 tipi-install.php >"$ROOT/server.log" 2>&1 & PID=$!
sleep 1
BASE=http://127.0.0.1:18765
COOKIE="$ROOT/cookies"
curl -fsS -c "$COOKIE" "$BASE/tipi-install.php" >"$ROOT/form"
CSRF=$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$ROOT/form")
test -n "$CSRF"
curl -fsS -b "$COOKIE" -c "$COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode "key=$TIPI_SETUP_KEY" --data-urlencode "name=Smoke test" --data-urlencode "url=$BASE" --data-urlencode "password=preview-password-123" "$BASE/tipi-install.php" >/dev/null
test -f index.php
test ! -f tipi-install.php
kill "$PID"; wait "$PID" || true
php -S 127.0.0.1:18765 index.php >"$ROOT/server.log" 2>&1 & PID=$!
sleep 1
curl -fsS "$BASE/" | grep -q 'Smoke test'
curl -fsS "$BASE/sitemap.xml" | grep -q 'blog/bienvenue'
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/introuvable")" = 404
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/v1/pages")" = 401
curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/admin" >"$ROOT/form"
CSRF=$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$ROOT/form")
curl -fsS -b "$COOKIE" -c "$COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'password=preview-password-123' "$BASE/admin" >/dev/null
curl -fsS -b "$COOKIE" -c "$COOKIE" "$BASE/admin" >"$ROOT/form"
CSRF=$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$ROOT/form")
curl -fsS -b "$COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=rotate' "$BASE/admin" >"$ROOT/token"
TOKEN=$(sed -n 's/.*<pre>\([a-f0-9]\{64\}\)<\/pre>.*/\1/p' "$ROOT/token")
test -n "$TOKEN"
curl -fsS -H "Authorization: Bearer $TOKEN" "$BASE/api/v1/pages" | grep -q 'blog/bienvenue'
BODY='{"raw":"---\ntitle: Private draft\n---\n# Draft\n\n<script>alert(1)</script>","expected_sha256":"","publish":false}'
curl -fsS -X PUT -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d "$BODY" "$BASE/api/v1/pages/test-draft" | grep -q '"status":"draft"'
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/test-draft")" = 404
test "$(curl -s -o /dev/null -w '%{http_code}' -X PUT -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d "$BODY" "$BASE/api/v1/pages/test-draft")" = 409
test "$(curl -s -o /dev/null -w '%{http_code}' -X PUT -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"raw":"---\ntitle: New\n---\nText","expected_sha256":"","publish":true}' "$BASE/api/v1/pages/test-live")" = 403
echo 'Smoke test OK'
