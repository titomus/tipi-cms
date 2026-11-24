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

# Complete OAuth and check that the browser and MCP share the contacts database.
CONSENT=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' "$TMP/consent")
test -n "$CONSENT"
curl -fsS -D "$TMP/redirect" -o /dev/null -H "Cookie: $COOKIE" -H 'Origin: https://cms.example.test' \
  --data-urlencode "client_id=$CLIENT" --data-urlencode 'redirect_uri=https://chatgpt.com/connector_platform_oauth_redirect' \
  --data-urlencode 'response_type=code' --data-urlencode "code_challenge=$CHALLENGE" --data-urlencode 'code_challenge_method=S256' \
  --data-urlencode 'scope=app:read app:write codegraft:read codegraft:write' --data-urlencode 'state=test' \
  --data-urlencode "_csrf=$CONSENT" --data-urlencode 'decision=approve' "$BASE/mcp/oauth/authorize/"
CODE=$(php -r 'foreach(file($argv[1]) as $line){if(stripos($line,"Location: ")===0){parse_str(parse_url(trim(substr($line,10)),PHP_URL_QUERY),$q);echo $q["code"]??"";}}' "$TMP/redirect")
test -n "$CODE"
VERIFIER=$(printf 'a%.0s' {1..43})
curl -fsS --data-urlencode 'grant_type=authorization_code' --data-urlencode "client_id=$CLIENT" --data-urlencode "code=$CODE" --data-urlencode "code_verifier=$VERIFIER" --data-urlencode 'redirect_uri=https://chatgpt.com/connector_platform_oauth_redirect' "$BASE/mcp/oauth/token/" >"$TMP/token"
ACCESS=$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["access_token"]??"";' "$TMP/token")
test -n "$ACCESS"
rpc(){ curl -fsS -H "Authorization: Bearer $ACCESS" -H 'Content-Type: application/json' -d "$1" "$BASE/mcp"; }
rpc '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' >"$TMP/tools"
grep -q 'cms_developer_guide' "$TMP/tools"
grep -q 'app_contact_list' "$TMP/tools"
rpc '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"app_contact_list","arguments":{}}}' | grep -q 'ui@example.test'
rpc '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"app_contact_create","arguments":{"name":"Contact MCP","email":"mcp@example.test"}}}' | grep -q '"isError":false'
curl -fsS -H "Cookie: $COOKIE" "$BASE/admin/apps/contacts" | grep -q 'mcp@example.test'
mkdir -p "$TIPI_DATA_DIR/published"
printf '%s\n' '---' 'title: Test home' '---' '# Test home' '{{component:hero}}' >"$TIPI_DATA_DIR/published/home.md"
php -r '$files=[
["path"=>"assets/js/animation.js","content"=>"document.addEventListener(\"DOMContentLoaded\",()=>document.body.classList.add(\"ready\"));"],
["path"=>"assets/css/animation.css","content"=>"h1{color:#884422}"],
["path"=>"themes/layouts/site.html","content"=>"<!doctype html><html><head>{{head}}</head><body><main id=\"custom-layout\">{{content}}</main><footer>{{credit}}</footer></body></html>"],
["path"=>"themes/components/hero.html","content"=>"<section>Custom hero</section>"]];foreach($files as &$f)$f["expected_sha256"]="";echo json_encode(["jsonrpc"=>"2.0","id"=>4,"method"=>"tools/call","params"=>["name"=>"cg_prepare_patch","arguments"=>["summary"=>"Test design and animation","files"=>$files]]]);' >"$TMP/patch"
rpc "$(cat "$TMP/patch")" >"$TMP/prepared"
PATCH=$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["result"]["structuredContent"]["id"]??"";' "$TMP/prepared")
test -n "$PATCH"
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/js/animation.js")" = 404
# Get the exact review fingerprint from the owner panel.
curl -fsS -H "Cookie: $COOKIE" "$BASE/admin?connections=1" >"$TMP/review"
FP=$(sed -n 's/.*name="fingerprint" value="\([a-f0-9]*\)".*/\1/p' "$TMP/review" | head -n 1)
test -n "$FP"
curl -fsS -H "Cookie: $COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=ai_deploy' --data-urlencode "patch=$PATCH" --data-urlencode "fingerprint=$FP" --data-urlencode 'owner_password=cms-password-123456' "$BASE/admin" >/dev/null
curl -fsS "$BASE/" >"$TMP/home-html"
grep -q 'custom-layout' "$TMP/home-html"
grep -q 'Custom hero' "$TMP/home-html"
grep -q '/assets/js/animation.js' "$TMP/home-html"
curl -fsS "$BASE/assets/js/animation.js" | grep -q 'DOMContentLoaded'
curl -fsS -H "Cookie: $COOKIE" "$BASE/admin" >"$TMP/admin-html"
if grep -q '/assets/js/animation.js' "$TMP/admin-html"; then exit 1; fi
# Native tracking survives the custom HTML layout, without external requests in this test.
curl -fsS -H "Cookie: $COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=hub_save' --data-urlencode 'tracker_key=isolated-test-key-not-live-123' --data-urlencode 'analytics=1' "$BASE/admin" >/dev/null
BIND=$(php -r '$h=json_decode(file_get_contents(getenv("TIPI_DATA_DIR")."/hub.json"),true);echo substr(hash("sha256",$h["installation_uid"]."|".$h["consent_epoch"]."|".$h["tracker_key"]."|0"),0,32);')
curl -fsS -H "Cookie: tipi_metrics_consent=$BIND.yes" "$BASE/" >"$TMP/custom-tracking"
grep -q 'custom-layout' "$TMP/custom-tracking"
test "$(grep -o 'analitycs.le-tipi.club/tracker.js' "$TMP/custom-tracking" | wc -l)" = 1
if grep -q 'optimize.le-tipi.club/optimize.js' "$TMP/custom-tracking"; then exit 1; fi
curl -fsS -H "Cookie: $COOKIE; tipi_metrics_consent=$BIND.yes" "$BASE/admin?preview=home" >"$TMP/private-tracking"
if grep -q 'analitycs.le-tipi.club/tracker.js' "$TMP/private-tracking"; then exit 1; fi
rpc '{"jsonrpc":"2.0","id":42,"method":"tools/call","params":{"name":"cms_tracking_info","arguments":{}}}' >"$TMP/tracking-status"
grep -q 'explicit_opt_in' "$TMP/tracking-status"
if grep -q 'isolated-test-key-not-live-123' "$TMP/tracking-status"; then exit 1; fi
curl -fsS -H "Cookie: $COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=hub_disconnect' "$BASE/admin" >/dev/null
echo 'OAuth MCP, shared contacts, reviewed HTML/CSS/JS patch and native tracking OK'

# V0.6: owner selects approval policy once; same OAuth client deploys and restores.
test "$(curl -fsS -H "Cookie: $COOKIE" "$BASE/admin?connections=1" | grep -c 'Validation des développements')" -ge 1
rpc '{"jsonrpc":"2.0","id":50,"method":"tools/call","params":{"name":"cms_site_info","arguments":{}}}' >"$TMP/info"
php -r '$j=json_decode(file_get_contents($argv[1]),true)["result"]["structuredContent"];if($j["approval_mode"]!=="cms"||$j["remote_deploy"]!==false)exit(1);' "$TMP/info"
# New version of the already active JavaScript, to verify automatic reactivation on rollback.
OLD_SHA=$(php -r 'echo hash_file("sha256",$argv[1]);' "$TMP/site/assets/js/animation.js")
php -r 'echo json_encode(["jsonrpc"=>"2.0","id"=>51,"method"=>"tools/call","params"=>["name"=>"cg_prepare_patch","arguments"=>["summary"=>"Update animation remotely","files"=>[["path"=>"assets/js/animation.js","expected_sha256"=>$argv[1],"content"=>"document.body.dataset.version=\"remote\";"]]]]]);' "$OLD_SHA" >"$TMP/remote-patch"
rpc "$(cat "$TMP/remote-patch")" >"$TMP/remote-prepared"
REMOTE=$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["result"]["structuredContent"]["id"]??"";' "$TMP/remote-prepared")
test -n "$REMOTE"
release_rpc(){ rpc "$(php -r 'echo json_encode(["jsonrpc"=>"2.0","id"=>52,"method"=>"tools/call","params"=>["name"=>$argv[1],"arguments"=>["id"=>$argv[2],$argv[3]=>$argv[4]]]]);' "$1" "$2" "$3" "$4")"; }
release_rpc cg_review_release "$REMOTE" operation deploy >"$TMP/remote-review"
REMOTE_FP=$(php -r '$j=json_decode(file_get_contents($argv[1]),true)["result"]["structuredContent"];if(!$j["validation"]["valid"])exit(1);echo $j["fingerprint"];' "$TMP/remote-review")
test -n "$REMOTE_FP"
release_rpc cg_deploy_patch "$REMOTE" fingerprint "$REMOTE_FP" | grep -q '"isError":true'
test "$(php -r 'echo hash_file("sha256",$argv[1]);' "$TMP/site/assets/js/animation.js")" = "$OLD_SHA"
# Delegation cannot be enabled without the owner password and explicit consent.
test "$(curl -s -o /dev/null -w '%{http_code}' -H "Cookie: $COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=ai_approval_mode' --data-urlencode 'approval_mode=chat' --data-urlencode 'accept_delegation=1' --data-urlencode 'owner_password=wrong' "$BASE/admin")" = 403
test "$(curl -s -o /dev/null -w '%{http_code}' -H "Cookie: $COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=ai_approval_mode' --data-urlencode 'approval_mode=chat' --data-urlencode 'owner_password=cms-password-123456' "$BASE/admin")" = 400
set_mode(){ curl -fsS -H "Cookie: $COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=ai_approval_mode' --data-urlencode "approval_mode=$1" --data-urlencode 'accept_delegation=1' --data-urlencode 'owner_password=cms-password-123456' "$BASE/admin" >/dev/null; }
set_mode chat
release_rpc cg_deploy_patch "$REMOTE" fingerprint "$(printf '0%.0s' {1..64})" | grep -q '"isError":true'
release_rpc cg_deploy_patch "$REMOTE" fingerprint "$REMOTE_FP" >"$TMP/deployed-remote"
php -r '$j=json_decode(file_get_contents($argv[1]),true)["result"]["structuredContent"];if($j["status"]!=="deployed"||$j["approval_mode"]!=="chat"||$j["human_approval_verified"]!==false)exit(1);' "$TMP/deployed-remote"
curl -fsS "$BASE/assets/js/animation.js" | grep -q 'dataset.version'
release_rpc cg_deploy_patch "$REMOTE" fingerprint "$REMOTE_FP" | grep -q '"isError":true'
release_rpc cg_review_release "$REMOTE" operation rollback >"$TMP/rollback-review"
ROLL_FP=$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["result"]["structuredContent"]["fingerprint"]??"";' "$TMP/rollback-review")
test -n "$ROLL_FP"
release_rpc cg_rollback_patch "$REMOTE" fingerprint "$ROLL_FP" | grep -q '"status":"rolled_back"'
curl -fsS "$BASE/assets/js/animation.js" | grep -q 'DOMContentLoaded'
test "$(php -r 'echo hash_file("sha256",$argv[1]);' "$TMP/site/assets/js/animation.js")" = "$OLD_SHA"
set_mode auto
rpc '{"jsonrpc":"2.0","id":53,"method":"tools/call","params":{"name":"cg_prepare_patch","arguments":{"summary":"Add autonomous test stylesheet","files":[{"path":"assets/css/autonomy.css","expected_sha256":"","content":"body{letter-spacing:0.01em}"}]}}}' >"$TMP/auto-prepared"
AUTO=$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["result"]["structuredContent"]["id"]??"";' "$TMP/auto-prepared")
test -n "$AUTO"
release_rpc cg_review_release "$AUTO" operation deploy >"$TMP/auto-review"
AUTO_FP=$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["result"]["structuredContent"]["fingerprint"]??"";' "$TMP/auto-review")
test -n "$AUTO_FP"
release_rpc cg_deploy_patch "$AUTO" fingerprint "$AUTO_FP" | grep -q '"approval_mode":"auto"'
curl -fsS "$BASE/assets/css/autonomy.css" | grep -q 'letter-spacing'
curl -fsS -H "Cookie: $COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=ai_profile' --data-urlencode 'profile=manage' "$BASE/admin" >/dev/null
rpc '{"jsonrpc":"2.0","id":54,"method":"tools/call","params":{"name":"cms_site_info","arguments":{}}}' >"$TMP/info-end"
php -r '$j=json_decode(file_get_contents($argv[1]),true)["result"]["structuredContent"];if($j["approval_mode"]!=="cms"||$j["remote_deploy"]!==false)exit(1);' "$TMP/info-end"
release_rpc cg_rollback_patch "$AUTO" fingerprint "$AUTO_FP" | grep -q '"isError":true'
echo 'V0.6 approval policies, fingerprint binding, remote deployment and rollback OK'

# V0.7: enable build again, allow remote patches, then trash & restore a published page with draft.
curl -fsS -H "Cookie: $COOKIE" --data-urlencode "csrf=$CSRF" --data-urlencode 'action=ai_profile' --data-urlencode 'profile=build' --data-urlencode 'accept_php=1' "$BASE/admin" >/dev/null
set_mode chat
rpc '{"jsonrpc":"2.0","id":70,"method":"tools/call","params":{"name":"cms_list_pages","arguments":{}}}' >"$TMP/pages"
STATE=$(php -r '$j=json_decode(file_get_contents($argv[1]),true);foreach($j["result"]["structuredContent"]["pages"] as $p)if($p["slug"]==="home")echo $p["state_sha256"];' "$TMP/pages")
test -n "$STATE"
doc_rpc(){ rpc "$(php -r 'echo json_encode(["jsonrpc"=>"2.0","id"=>71,"method"=>"tools/call","params"=>["name"=>$argv[1],"arguments"=>["slug"=>"home",$argv[2]=>$argv[3]]]]);' "$1" "$2" "$3")"; }
doc_rpc cms_delete_page state_sha256 "$(printf '0%.0s' {1..64})" | grep -q '"isError":true'
doc_rpc cms_delete_page state_sha256 "$STATE" >"$TMP/trashed"
REV=$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["result"]["structuredContent"]["documents"]["home"]["revision"]??"";' "$TMP/trashed")
test -n "$REV"
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" = 404
rpc '{"jsonrpc":"2.0","id":72,"method":"tools/call","params":{"name":"cms_list_trash","arguments":{}}}' | grep -q "$REV"
doc_rpc cms_restore_page revision "$REV" | grep -q '"status":"restored"'
curl -fsS "$BASE/" | grep -q 'Custom hero'
SHA_DEL=$(php -r 'echo hash_file("sha256",$argv[1]);' "$TMP/site/assets/css/autonomy.css")
rpc "$(php -r 'echo json_encode(["jsonrpc"=>"2.0","id"=>73,"method"=>"tools/call","params"=>["name"=>"cg_prepare_delete","arguments"=>["summary"=>"Remove test stylesheet","files"=>[["path"=>"assets/css/autonomy.css","expected_sha256"=>$argv[1]]]]]]);' "$SHA_DEL")" >"$TMP/delete-patch"
DEL=$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["result"]["structuredContent"]["id"]??"";' "$TMP/delete-patch")
test -n "$DEL"
release_rpc cg_review_release "$DEL" operation deploy >"$TMP/delete-review"
DEL_FP=$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["result"]["structuredContent"]["fingerprint"]??"";' "$TMP/delete-review")
test -n "$DEL_FP"
release_rpc cg_deploy_patch "$DEL" fingerprint "$DEL_FP" | grep -q '"status":"deployed"'
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/css/autonomy.css")" = 404
release_rpc cg_review_release "$DEL" operation rollback >"$TMP/delete-rollback"
DEL_ROLL=$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["result"]["structuredContent"]["fingerprint"]??"";' "$TMP/delete-rollback")
test -n "$DEL_ROLL"
release_rpc cg_rollback_patch "$DEL" fingerprint "$DEL_ROLL" | grep -q '"status":"rolled_back"'
curl -fsS "$BASE/assets/css/autonomy.css" | grep -q 'letter-spacing'
curl -fsS -H "Cookie: $COOKIE" "$BASE/admin/apps/contacts" | grep -q 'mcp@example.test'
# Bulk removal must fail as a whole on a stale item, and preserve both draft/live snapshots.
rpc '{"jsonrpc":"2.0","id":75,"method":"tools/call","params":{"name":"cms_publish","arguments":{"slug":"blog/reset-test","raw":"---\ntitle: Published reset test\n---\nPublished body","expected_sha256":""}}}' >"$TMP/published-reset"
PUB=$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["result"]["structuredContent"]["sha256"]??"";' "$TMP/published-reset")
test -n "$PUB"
rpc "$(php -r 'echo json_encode(["jsonrpc"=>"2.0","id"=>76,"method"=>"tools/call","params"=>["name"=>"cms_save_draft","arguments"=>["slug"=>"blog/reset-test","raw"=>"---\ntitle: Draft reset test\n---\nDraft body","expected_sha256"=>$argv[1]]]]);' "$PUB")" | grep -q '"isError":false'
rpc '{"jsonrpc":"2.0","id":77,"method":"tools/call","params":{"name":"cms_list_pages","arguments":{}}}' >"$TMP/bulk-pages"
php -r '$j=json_decode(file_get_contents($argv[1]),true);$p=[];foreach($j["result"]["structuredContent"]["pages"] as $x)if(in_array($x["slug"],["home","blog/reset-test"],true))$p[]=["slug"=>$x["slug"],"state_sha256"=>$x["state_sha256"]];if(count($p)!==2)exit(1);$r=["jsonrpc"=>"2.0","id"=>78,"method"=>"tools/call","params"=>["name"=>"cms_delete_pages","arguments"=>["pages"=>$p]]];file_put_contents($argv[2],json_encode($r));$r["params"]["arguments"]["pages"][1]["state_sha256"]=str_repeat("0",64);file_put_contents($argv[3],json_encode($r));' "$TMP/bulk-pages" "$TMP/bulk-ok" "$TMP/bulk-bad"
rpc "$(cat "$TMP/bulk-bad")" | grep -q '"isError":true'
curl -fsS "$BASE/blog/reset-test" | grep -q 'Published body'
rpc "$(cat "$TMP/bulk-ok")" >"$TMP/bulk-result"
REV2=$(php -r '$j=json_decode(file_get_contents($argv[1]),true)["result"]["structuredContent"];if($j["count"]!==2)exit(1);echo $j["documents"]["blog/reset-test"]["revision"];' "$TMP/bulk-result")
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/blog/reset-test")" = 404
test "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" = 404
rpc "$(php -r 'echo json_encode(["jsonrpc"=>"2.0","id"=>79,"method"=>"tools/call","params"=>["name"=>"cms_restore_page","arguments"=>["slug"=>"blog/reset-test","revision"=>$argv[1]]]]);' "$REV2")" | grep -q '"status":"restored"'
curl -fsS "$BASE/blog/reset-test" | grep -q 'Published body'
rpc '{"jsonrpc":"2.0","id":80,"method":"tools/call","params":{"name":"cms_read_page","arguments":{"slug":"blog/reset-test"}}}' | grep -q 'Draft body'
echo 'V0.7 trash, bulk deletion, both content versions and reversible extension deletion OK'
