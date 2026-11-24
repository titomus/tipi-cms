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
test "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOKEN" "$BASE/mcp")" = 401
curl -fsS -b "$COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=mcp_rotate' "$BASE/admin" >"$ROOT/mcp-token"
MCP=$(sed -n 's/.*<pre>\([a-f0-9]\{64\}\)<\/pre>.*/\1/p' "$ROOT/mcp-token")
test -n "$MCP"
rpc(){ curl -fsS -H "Authorization: Bearer $MCP" -H 'Content-Type: application/json' -d "$1" "$BASE/mcp"; }
rpc '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}' | grep -q '"protocolVersion":"2025-11-25"'
rpc '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' | grep -q 'cms_save_draft'
rpc '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"cms_save_draft","arguments":{"slug":"mcp-test","raw":"---\ntitle: MCP test\n---\nDraft","expected_sha256":""}}}' | grep -q '"isError":true'
curl -fsS -b "$COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=mcp_policy' --data-urlencode 'mcp_write=1' "$BASE/admin" >/dev/null
rpc '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"cms_save_draft","arguments":{"slug":"mcp-test","raw":"---\ntitle: MCP test\n---\nDraft","expected_sha256":""}}}' | grep -q 'revision'
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/mcp-test")" = 404
rpc '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"cms_publish","arguments":{"slug":"mcp-publish","raw":"---\ntitle: MCP test\n---\nDraft","expected_sha256":""}}}' | grep -q '"isError":true'
curl -fsS -b "$COOKIE" "$BASE/admin?settings=1" >"$ROOT/design"
DESIGN=$(sed -n 's/.*name="expected" value="\([a-f0-9]*\)".*/\1/p' "$ROOT/design")
test -n "$DESIGN"
curl -fsS -b "$COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode "expected=$DESIGN" --data-urlencode 'action=settings' --data-urlencode 'name=New name' --data-urlencode 'theme=ink' --data-urlencode 'accent=#123456' "$BASE/admin" >/dev/null
curl -fsS "$BASE/" | grep -q '#123456'
curl -fsS -b "$COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=mcp_revoke' "$BASE/admin" >/dev/null
test "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $MCP" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$BASE/mcp")" = 401
# Tracking enabled only by owner configuration, with public consent and no tracking in admin.
curl -fsS "$BASE/" >"$ROOT/no-tracking"
if grep -q 'tipi-metrics.js' "$ROOT/no-tracking"; then exit 1; fi
curl -fsS -b "$COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=hub_save' --data-urlencode 'tracker_key=local-test-key-not-live-123456' --data-urlencode 'analytics=1' --data-urlencode 'optimize=1' "$BASE/admin" >/dev/null
curl -fsS "$BASE/" >"$ROOT/optin"
grep -q 'tipi-metrics.js' "$ROOT/optin"
grep -q 'data-tipi-consent-choice="no"' "$ROOT/optin"
if grep -q 'analitycs.le-tipi.club/tracker.js' "$ROOT/optin"; then exit 1; fi
BIND=$(php -r '$h=json_decode(file_get_contents(getenv("TIPI_DATA_DIR")."/hub.json"),true);echo substr(hash("sha256",$h["installation_uid"]."|".$h["consent_epoch"]."|".$h["tracker_key"]."|1"),0,32);')
curl -fsS -D "$ROOT/tracking-headers" -H "Cookie: tipi_metrics_consent=$BIND.yes" "$BASE/" >"$ROOT/tracking"
test "$(grep -o 'analitycs.le-tipi.club/tracker.js' "$ROOT/tracking" | wc -l)" = 1
test "$(grep -o 'optimize.le-tipi.club/optimize.js' "$ROOT/tracking" | wc -l)" = 1
grep -qi 'connect-src.*https://analitycs.le-tipi.club' "$ROOT/tracking-headers"
curl -fsS -b "$COOKIE" -b "tipi_metrics_consent=$BIND.yes" "$BASE/admin?hub=1" >"$ROOT/admin-hub"
if grep -q 'analitycs.le-tipi.club/tracker.js' "$ROOT/admin-hub"; then exit 1; fi
curl -fsS -H "Cookie: tipi_metrics_consent=$BIND.no" "$BASE/" >"$ROOT/refused"
if grep -q 'analitycs.le-tipi.club/tracker.js' "$ROOT/refused"; then exit 1; fi
curl -fsS "$BASE/tipi-metrics.js" | grep -q 'window.Tipi.track'
curl -fsS -b "$COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=hub_disconnect' "$BASE/admin" >/dev/null
curl -fsS -H "Cookie: tipi_metrics_consent=$BIND.yes" "$BASE/" >"$ROOT/disconnected"
if grep -q 'tipi-metrics.js' "$ROOT/disconnected"; then exit 1; fi
echo 'Smoke test OK'
