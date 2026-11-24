<?php
declare(strict_types=1);
// This test never contacts production. Execute with -d disable_functions=curl_init.
if(PHP_SAPI!=='cli'||function_exists('curl_init'))exit(1);
define('TIPI_LIBRARY_ONLY',true);
require dirname(__DIR__).'/tipi-install.php';
function expect(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$d=sys_get_temp_dir().'/tipi-tracking-'.bin2hex(random_bytes(12));mkdir($d,0700);
putenv('TIPI_DATA_DIR='.$d);
try{
    jwrite($d.'/config.json',['url'=>'https://cms.example.test','name'=>'Test']);
    tipiHubSettings($d,['action'=>'hub_save','tracker_key'=>'not-a-real-tracker-key-123','analytics'=>'1']);
    $h=tipiHubConfig($d);$binding=tipiHubBinding($h);
    $_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/apps/quote?email=private@example.test';
    $_COOKIE=['ta_visitor_id'=>'visitor-test-123','ta_session_id'=>'session-test-123'];
    expect(!Tipi::track('quote_requested',[],'quote:42'),'No tracking before consent');
    $_COOKIE['tipi_metrics_consent']=$binding.'.yes';
    $_SERVER['REQUEST_METHOD']='GET';
    expect(!Tipi::track('quote_requested',[],'quote:42'),'No PHP conversion on GET');
    $_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/admin/apps/quote';
    expect(!Tipi::track('quote_requested',[],'quote:42'),'No admin analytics');
    $_SERVER['REQUEST_URI']='/apps/quote?email=private@example.test';
    expect(!Tipi::track('revenue',[],'quote:42'),'No unverified payment event');
    expect(Tipi::track('quote_requested',['service'=>'creation-site','value'=>100,'email'=>'secret@example.test','notes'=>'Personal notes','provider'=>'stripe'],'quote:42'),'Queue event');
    expect(Tipi::track('quote_requested',['service'=>'creation-site'],'quote:42'),'Same event id');
    $files=glob($d.'/hub-outbox/*.json')?:[];
    expect(count($files)===1,'Deduplicate stable business reference in outbox');
    $row=jread($files[0]);$payload=$row['payload'];
    expect($payload['event_name']==='event'&&$payload['meta']['original_event']==='quote_requested','Existing tracker contract');
    expect($payload['path']==='/apps/quote'&&$payload['url']==='https://cms.example.test/apps/quote','No query-string PII');
    expect($payload['meta']['revenue_kind']==='estimated'&&$payload['meta']['is_estimated']===true,'No real revenue');
    expect(!isset($payload['meta']['email'],$payload['meta']['notes'],$payload['meta']['provider'],$payload['site_key']),'No sensitive metadata or tracking key in queue');
    expect(!str_contains(json_encode(tipiHubStatus($d)),$h['tracker_key']),'No key in MCP status');
    tipiHubSettings($d,['action'=>'hub_disconnect']);
    expect(!Tipi::track('quote_requested',[],'quote:43'),'Disconnected');
    tipiHubSettings($d,['action'=>'hub_save','tracker_key'=>'not-a-real-tracker-key-123','analytics'=>'1']);
    expect(tipiHubBinding(tipiHubConfig($d))!==$binding,'Old consent invalidated on reconnection');
    expect(!Tipi::track('quote_requested',[],'quote:43'),'No re-use of prior consent');
    echo "Tracking SDK offline tests OK\n";
}finally{
    // Shutdown queue flush is disabled, and its configuration no longer exists.
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}
    rmdir($d);
}
