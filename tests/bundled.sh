#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
PID=''
trap 'rc=$?; if [[ $rc -ne 0 && -f "$TMP/admin-server.log" ]]; then cat "$TMP/admin-server.log"; fi; if [[ -n "$PID" ]]; then kill "$PID" 2>/dev/null || true; fi; rm -rf "$TMP"' EXIT
export HOME="$TMP/home"
mkdir -p "$HOME" "$TMP/site/public" "$TMP/no-network"
cp "$HERE/tipi-install.php" "$TMP/site/public/index.php"
for tool in git curl wget composer; do printf '#!/bin/sh\nexit 99\n' >"$TMP/no-network/$tool"; chmod +x "$TMP/no-network/$tool"; done
ORIGINAL_PATH="$PATH"
export PATH="$TMP/no-network:$PATH"
php "$HERE/scripts/verify-codegraft.php"
bash "$HERE/scripts/install-codegraft.sh" "$TMP/site" https://cms.example.test
test -f "$TMP/site/tipi-system/codegraft/standalone/config.php"
test ! -e "$TMP/site/public/codegraft"
cmp "$HERE/tipi-install.php" "$TMP/site/public/index.php"
php "$HERE/tests/business.php" "$TMP/site"
echo "Bundled installation OK"
export PATH="$ORIGINAL_PATH"
export TIPI_TEST_ROOT="$TMP/site" TIPI_DATA_DIR="$TMP/private"
mkdir "$TIPI_DATA_DIR"
php -r 'file_put_contents(getenv("TIPI_DATA_DIR")."/config.json",json_encode(["name"=>"Unified test","url"=>"https://cms.example.test","password_hash"=>password_hash("cms-password-123456",PASSWORD_DEFAULT)]));'
cat >"$TMP/router.php" <<'PHP'
<?php
$_SERVER['HTTPS']='on';
$_SERVER['DOCUMENT_ROOT']=getenv('TIPI_TEST_ROOT').'/public';
require getenv('TIPI_TEST_ROOT').'/public/index.php';
PHP
php -S 127.0.0.1:18766 "$TMP/router.php" >"$TMP/admin-server.log" 2>&1 & PID=$!
sleep 1
BASE=http://localhost:18766
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/codegraft/standalone/admin.php")" = 404
 test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/codegraft-api/")" = 404
curl -fsS -c "$TMP/cookies" "$BASE/admin" >"$TMP/login"
CSRF=$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$TMP/login" | head -n 1)
test -n "$CSRF"
COOKIE=$(awk '$6 ~ /^tipi_cms_/ {print $6"="$7}' "$TMP/cookies")
test -n "$COOKIE"
curl -fsS -H "Cookie: $COOKIE" -c "$TMP/cookies" --data-urlencode "csrf=$CSRF" --data-urlencode 'password=cms-password-123456' "$BASE/admin" >/dev/null
COOKIE=$(awk '$6 ~ /^tipi_cms_/ {print $6"="$7}' "$TMP/cookies")
test -n "$COOKIE"
curl -fsS -H "Cookie: $COOKIE" "$BASE/admin?connections=1" >"$TMP/panel"
grep -q 'Assistant IA' "$TMP/panel"
grep -q 'Création de fonctionnalités' "$TMP/panel"
CSRF=$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$TMP/panel" | head -n 1)
test -n "$CSRF"
echo "CMS login and assistant panel OK"
curl -fsS -H "Cookie: $COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=ai_profile' --data-urlencode 'profile=build' --data-urlencode 'accept_php=1' "$BASE/admin" >/dev/null
php -r '$c=json_decode(file_get_contents(getenv("TIPI_DATA_DIR")."/config.json"),true);if($c["ai_profile"]!=="build")exit(1);'
# OAuth consent recognizes the CMS session rather than the old CodeGraft session.
curl -fsS -H 'Content-Type: application/json' -d '{"redirect_uris":["https://chatgpt.com/connector_platform_oauth_redirect"]}' "$BASE/mcp/oauth/register/" >"$TMP/client"
CLIENT=$(php -r '$v=json_decode(file_get_contents($argv[1]),true);echo $v["client_id"]??"";' "$TMP/client")
test -n "$CLIENT"
CHALLENGE=$(php -r 'echo rtrim(strtr(base64_encode(hash("sha256",str_repeat("a",43),true)),"+/","-_"),"=");')
AUTH="$BASE/mcp/oauth/authorize/?response_type=code&client_id=$CLIENT&redirect_uri=https%3A%2F%2Fchatgpt.com%2Fconnector_platform_oauth_redirect&code_challenge=$CHALLENGE&code_challenge_method=S256&scope=app%3Aread%20app%3Awrite%20codegraft%3Aread%20codegraft%3Awrite&state=test"
test "$(curl -s -o /dev/null -w '%{http_code}' "$AUTH")" = 403
curl -fsS -H "Cookie: $COOKIE" "$AUTH" >"$TMP/consent"
grep -q 'Autoriser' "$TMP/consent"
echo "OAuth consent page OK"
for origin in null https://other.example.test; do
  test "$(curl -s -o /dev/null -w '%{http_code}' -H "Origin: $origin" -d 'decision=approve' "$AUTH")" = 403
done
echo 'Unified CMS login and OAuth consent OK'
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/mcp")" = 401
curl -fsS "$BASE/.well-known/oauth-protected-resource/mcp" | grep -q 'https://cms.example.test/mcp'
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/apps/contacts")" = 404
curl -fsS -H "Cookie: $COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=ai_enable_contacts' --data-urlencode 'owner_password=cms-password-123456' "$BASE/admin" >/dev/null
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/apps/contacts")" = 303
curl -fsS -H "Cookie: $COOKIE" "$BASE/admin/apps/contacts" >"$TMP/contacts"
grep -q 'Nouveau contact' "$TMP/contacts"
test "$(curl -s -o /dev/null -w '%{http_code}' -H "Cookie: $COOKIE" -d 'name=Blocked&email=test@example.test' "$BASE/admin/apps/contacts")" = 403
curl -fsS -H "Cookie: $COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'name=Contact interface' --data-urlencode 'email=ui@example.test' "$BASE/admin/apps/contacts" >/dev/null
curl -fsS -H "Cookie: $COOKIE" "$BASE/admin/apps/contacts" | grep -q 'Contact interface'
php -r '$db=new PDO("sqlite:".getenv("TIPI_DATA_DIR")."/business/contacts.sqlite");if($db->query("SELECT email FROM contacts")->fetchColumn()!=="ui@example.test")exit(1);'
echo 'Contacts private route and shared database OK'
