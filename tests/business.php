<?php
declare(strict_types=1);
// Run after integration installation: php tests/business.php /path/to/tipi_cms
$root=realpath($argv[1]??'');
if(!$root)exit(1);
require $root.'/tipi-system/bridge.php';
require $root.'/tipi-system/codegraft/bootstrap.php';
const TIPI_VERSION = 'test';
function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
$tmp=sys_get_temp_dir().'/tipi-business-test-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
try{
    $cfg=['ai_profile'=>'manage','business_read'=>true,'business_write'=>true,'business_approved'=>tipiBusinessManifest($root)];
    // Only test the shipped example when its source exactly matches the repo copy.
    check(isset($cfg['business_approved']['Contacts.php']),'Demo module absent');
    check(hash_equals(hash_file('sha256',dirname(__DIR__).'/examples/Contacts.php'),$cfg['business_approved']['Contacts.php']),'Demo differs: test only the original example');
    $cfg['business_approved']=array_intersect_key($cfg['business_approved'],['Contacts.php'=>true]);
    $r=tipiRegistry($root,$tmp,$cfg);
    check(count($r->definitions())===3,'Tool discovery');
    check(!is_dir($tmp.'/business'),'Discovery created database');
    $principal=['role'=>'admin','id'=>1];
    check($r->run('contact_list',[],$principal)['items']===[],'Initial empty');
    check(!is_dir($tmp.'/business'),'Read created database');
    $item=$r->run('contact_create',['name'=>'Test contact','email'=>'test@example.test'],$principal);
    check($item['id']>0,'Insert failed');
    $r->run('contact_update',['id'=>$item['id'],'name'=>'Updated','email'=>'test@example.test'],$principal);
    check($r->run('contact_list',[],$principal)['items'][0]['name']==='Updated','Update failed');
    try{$r->run('contact_create',['name'=>'Bad','email'=>'invalid'],$principal);throw new LogicException('Invalid input accepted');}catch(\CGF\HttpError){}
    $rpc=static fn($name,$args=[])=>['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]];
    $reader=['admin_id'=>1,'scopes'=>[\CodeGraft\DynamicOAuth::APP_READ]];
    $writer=['admin_id'=>1,'scopes'=>[\CodeGraft\DynamicOAuth::APP_READ,\CodeGraft\DynamicOAuth::APP_WRITE]];
    $developer=['admin_id'=>1,'scopes'=>[\CodeGraft\DynamicOAuth::READ,\CodeGraft\DynamicOAuth::WRITE]];
    $call=$rpc('app_contact_create',['name'=>'Second','email'=>'second@example.test']);
    check(tipiBridgeDispatch($call,$reader,[],$tmp,$cfg)['result']['isError']===true,'Read-only scope accepted write');
    check(tipiBridgeDispatch($call,$developer,[],$tmp,$cfg)['result']['isError']===true,'Developer scope accepted business write');
    $cfg['business_write']=false;
    check(tipiBridgeDispatch($call,$writer,[],$tmp,$cfg)['result']['isError']===true,'Local write denial bypassed');
    $cfg['business_write']=true;
    check(tipiBridgeDispatch($call,$writer,[],$tmp,$cfg)['result']['isError']===false,'Authorized business write failed');
    $catalog=tipiBridgeDispatch($rpc('cg_app_catalog'),$reader,[],$tmp,$cfg)['result']['structuredContent'];
    check($catalog['count']===3,'Dynamic catalog missing tools');
    $list=tipiBridgeDispatch($rpc('cg_app_invoke',['action'=>'contact_list','arguments'=>[]]),$reader,[],$tmp,$cfg)['result']['structuredContent'];
    check(count($list['items'])===2,'Generic invocation failed');
    $cfg['business_approved']['Contacts.php']=str_repeat('0',64);
    check(tipiRegistry($root,$tmp,$cfg)->definitions()===[],'Unapproved version discovered');
    echo "Business smoke OK (isolated temporary SQLite)\n";
}finally{
    foreach(glob($tmp.'/business/*')?:[] as $f)unlink($f);
    if(is_dir($tmp.'/business'))rmdir($tmp.'/business');rmdir($tmp);
}
