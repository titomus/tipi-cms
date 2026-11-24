#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
PID=''
trap 'if [[ -n "$PID" ]]; then kill "$PID" 2>/dev/null || true; fi; rm -rf "$TMP"' EXIT
export HOME="$TMP/home"
mkdir -p "$HOME" "$TMP/site/public" "$TMP/no-network"
cp "$HERE/tipi-install.php" "$TMP/site/public/index.php"
for tool in git curl wget composer; do printf '#!/bin/sh\nexit 99\n' >"$TMP/no-network/$tool"; chmod +x "$TMP/no-network/$tool"; done
ORIGINAL_PATH="$PATH"
export PATH="$TMP/no-network:$PATH"
php "$HERE/scripts/verify-codegraft.php"
printf '%s\n' 'test-password-123456789' 'test-password-123456789' | bash "$HERE/scripts/install-codegraft.sh" "$TMP/site" https://cms.example.test
test -f "$TMP/site/tipi-system/codegraft/standalone/config.php"
test ! -e "$TMP/site/public/codegraft"
cmp "$HERE/tipi-install.php" "$TMP/site/public/index.php"
php "$HERE/tests/business.php" "$TMP/site"
echo "Bundled installation OK"
export PATH="$ORIGINAL_PATH"
export TIPI_TEST_ROOT="$TMP/site"
cat >"$TMP/router.php" <<'PHP'
<?php
// Isolated loopback-only fixture: simulate HTTPS as presented by production Apache.
$_SERVER['HTTPS']='on';
require getenv('TIPI_TEST_ROOT').'/tipi-system/bridge.php';
tipiCodeGraftRoute('/codegraft/standalone/admin.php',getenv('TIPI_TEST_ROOT').'/private',['url'=>'https://cms.example.test']);
PHP
php -S 127.0.0.1:18766 "$TMP/router.php" >"$TMP/admin-server.log" 2>&1 & PID=$!
sleep 1
URL=http://127.0.0.1:18766/codegraft/standalone/admin.php
curl -fsS -D "$TMP/headers" -c "$TMP/cookies" "$URL" >"$TMP/login"
grep -qi '^Referrer-Policy: same-origin' "$TMP/headers"
CSRF=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' "$TMP/login")
test -n "$CSRF"
# A foreign or opaque Origin must still be rejected.
for origin in null https://other.example.test; do
  test "$(curl -s -o /dev/null -w '%{http_code}' -H "Origin: $origin" -d 'action=login' "$URL")" = 403
done
# The origin fix must not bypass CSRF.
test "$(curl -s -o /dev/null -w '%{http_code}' -H 'Origin: https://cms.example.test' -d 'action=login' "$URL")" = 419
# curl does not resend secure cookies over arbitrary HTTP hosts; loopback is trusted.
COOKIE=$(awk '$6=="codegraft_admin"{print $6"="$7}' "$TMP/cookies")
test -n "$COOKIE"
curl -fsS -H "Cookie: $COOKIE" -H 'Origin: https://cms.example.test' --data-urlencode "_csrf=$CSRF" --data-urlencode 'action=login' --data-urlencode 'password=test-password-123456789' "$URL" >"$TMP/logged-in"
grep -q 'Se déconnecter' "$TMP/logged-in"
echo 'CodeGraft admin origin and CSRF regression OK'
