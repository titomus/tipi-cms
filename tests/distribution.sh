#!/usr/bin/env bash
set -euo pipefail
ROOT="$(mktemp -d)"
trap 'rm -rf "$ROOT"' EXIT
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$ROOT/signing.pem" 2>/dev/null
TIPI_CMS_SIGNING_KEY="$ROOT/signing.pem" php scripts/build-distribution.php "$ROOT/template.php"
php -l "$ROOT/template.php"
php -r '
define("TIPI_LIBRARY_ONLY",true);
require $argv[1];
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$release=tipiVerifyCoreRelease(file_get_contents($argv[1].".release.json"));
check($release["version"]===TIPI_VERSION,"signed version");
check($release["bundle"]===TIPI_BUNDLE_B64,"bundle identity");
$bad=json_decode(file_get_contents($argv[1].".release.json"),true);
$bad["signature"]=base64_encode("invalid");
$refused=false;try{tipiVerifyCoreRelease(json_encode($bad));}catch(RuntimeException $e){$refused=true;}
check($refused,"invalid signature refused");
foreach(["one","two"] as $site){
    $d=$argv[2]."/".$site;mkdir($d,0700);
    jwrite($d."/config.json",["runtime_layout"=>"private"]);
    check(tipiRuntimeRoot($d)===$d."/runtime","no sibling fallback");
    tipiInstallBundledRuntime($d,"https://".$site.".example.test");
    $cfg=require $d."/runtime/tipi-system/codegraft/standalone/config.php";
    check($cfg["root"]===$d."/runtime","private project root");
    check($cfg["vault"]===$d."/runtime-vault","private vault");
    $cg=codegraft($cfg["root"],$cfg["vault"],"generic");
    check($cg["vault"]->policy()["write_dirs"]===[],"read-only default");
    check(is_file($cfg["root"]."/app/McpActions/Contacts.php"),"bundled example");
    $refused=false;try{tipiInstallBundledRuntime($d,"https://".$site.".example.test");}catch(RuntimeException $e){$refused=true;}
    check($refused,"no overwrite");
}
echo "Signed distribution and two isolated runtimes OK\n";
' "$ROOT/template.php" "$ROOT"
