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
    // Mock transport: exercise native pairing without any external request.
    $calls=[];$token='ltcms_'.str_repeat('a',48);$poll=str_repeat('b',48);
    $key='fake-paired-tracker-123456';$public=str_repeat('c',24);
    $request=function($method,$path,$payload=[],$headers=[])use(&$calls,$token,$poll,$key,$public){
        $calls[]=[$method,$path,$payload,$headers];
        if($path==='pairings/init')return ['pairing'=>['public_id'=>$public,'poll_token'=>$poll,'pairing_code'=>'ABC234']];
        if($path==='pairings/'.$public)return ['pairing'=>['status'=>'approved','platform'=>'tipi_cms','access_token'=>$token,'tracker_key'=>$key,'site_id'=>42]];
        if($path==='site-config')return ['site'=>['platform'=>'tipi_cms','tracker_key'=>$key,'site_id'=>42]];
        if($path==='revoke')return ['status'=>'ok'];
        throw new RuntimeException('Unexpected request');
    };
    $cfg=jread($d.'/config.json');
    tipiHubPairing($d,$cfg,'hub_pair_start',$request);
    expect($calls[0][2]['platform']==='tipi_cms','CMS pairing platform');
    expect(tipiHubConfig($d)['pairing']['poll_token']===$poll,'Private poll token persisted');
    tipiHubPairing($d,$cfg,'hub_pair_finish',$request);
    expect($calls[1][3]===['X-Tipi-Poll-Token: '.$poll],'Poll secret only in header');
    expect(tipiHubStatus($d)['mode']==='paired','Paired mode');
    expect(tipiHubConfig($d)['tracker_key']===$key,'Automatically obtain tracking key');
    expect(!str_contains(json_encode(tipiHubStatus($d)),$token),'No bearer in public tracking status');
    // Editorial synchronization uses only published revisions, never the draft.
    atomic(fileDoc($d,'blog/one','published'),rawDoc('Published title',"A **test** [link](/services).",['id'=>str_repeat('1',24),'seo_title'=>'SEO title','description'=>'Meta description','author'=>'Editor']));
    atomic(fileDoc($d,'blog/one','drafts'),rawDoc('Private draft','UNPUBLISHED SECRET',['id'=>str_repeat('1',24)]));
    $syncRequest=function($method,$path,$payload,$headers)use($token){
        expect($method==='POST'&&$path==='hector/cms/inventory','Hector endpoint');
        expect($headers===['Authorization: Bearer '.$token],'Scoped bearer');
        expect($payload['sequence']===1&&count($payload['documents'])===1,'Complete inventory');
        $doc=$payload['documents'][0];
        expect($doc['source_uid']===str_repeat('1',24)&&$doc['has_draft']===true,'Stable source UID and draft flag');
        expect($doc['title']==='Published title'&&$doc['seo_title']==='SEO title'&&$doc['meta_description']==='Meta description','Editorial metadata');
        expect(str_contains($doc['content_html'],'<strong>test</strong>'),'Rendered HTML for Hector');
        expect(!str_contains(json_encode($payload),'UNPUBLISHED SECRET'),'No private draft content');
        return ['inventory'=>['platform'=>'tipi_cms','mode'=>'read_only','sequence'=>1,'count'=>1,'articles'=>1,'synced_at'=>'2026-11-24T12:00:00Z']];
    };
    $sync=tipiHectorSync($d,$cfg,$syncRequest);
    expect($sync['count']===1&&tipiHubStatus($d)['hector_sync']['articles']===1,'Confirmed editorial snapshot');
    try {
        tipiHectorSync($d,$cfg,fn()=>fail('Temporary outage',503));
        throw new LogicException('Expected outage');
    } catch(RuntimeException $ex){expect($ex->getCode()===503,'Synchronization failure surfaced');}
    expect(tipiHubStatus($d)['hector_sync']['count']===1&&tipiHubConfig($d)['hector_sequence']===2,'Keep last confirmed state, reserve next sequence');
    tipiHubPairing($d,$cfg,'hub_refresh',$request);
    expect($calls[2][3]===['Authorization: Bearer '.$token],'Authenticated refresh');
    try {
        tipiHubPairing($d,$cfg,'hub_disconnect',fn()=>fail('Temporary outage',503));
        throw new LogicException('Expected outage');
    } catch(RuntimeException $ex) {
        expect($ex->getCode()===503,'Temporary failure surfaced');
    }
    expect(tipiHubStatus($d)['mode']==='paired','Temporary outage preserves connection');
    tipiHubPairing($d,$cfg,'hub_disconnect',$request);
    expect($calls[3][1]==='revoke'&&tipiHubStatus($d)['mode']==='disconnected','Remote revoke then local disconnect');
    expect(!isset(tipiHubConfig($d)['access_token']),'Local bearer removed');
    echo "Tracking SDK and pairing offline tests OK\n";
}finally{
    // Shutdown queue flush is disabled, and its configuration no longer exists.
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}
    rmdir($d);
}
