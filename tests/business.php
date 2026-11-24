<?php
declare(strict_types=1);
// Run after integration installation: php tests/business.php /path/to/tipi_cms
$root=realpath($argv[1]??'');
if(!$root)exit(1);
require $root.'/tipi-system/bridge.php';
function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
$tmp=sys_get_temp_dir().'/tipi-business-test-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
try{
    $cfg=['business_read'=>true,'business_write'=>true,'business_approved'=>tipiBusinessManifest($root)];
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
    $cfg['business_approved']['Contacts.php']=str_repeat('0',64);
    check(tipiRegistry($root,$tmp,$cfg)->definitions()===[],'Unapproved version discovered');
    echo "Business smoke OK (isolated temporary SQLite)\n";
}finally{
    foreach(glob($tmp.'/business/*')?:[] as $f)unlink($f);
    if(is_dir($tmp.'/business'))rmdir($tmp.'/business');rmdir($tmp);
}
