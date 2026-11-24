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
    $new=['id'=>str_repeat('d',32),'slug'=>'blog/hector-test','expected_revision'=>'','mode'=>'publish',
        'fields'=>['title'=>'Hector test','seo_title'=>'SEO','meta_description'=>'Meta','author_name'=>'Auteur','category_name'=>'Cat','content_markdown'=>'A [link](/services).']];
    try{tipiHectorApply($d,$new,$token);throw new LogicException('Expected opt-in rejection');}
    catch(RuntimeException $ex){expect($ex->getCode()===403,'Explicit opt-in required');}
    tipiHectorWriteSettings($d,true);
    $first=tipiHectorApply($d,$new,$token);
    expect($first['status']==='applied'&&$first['mode']==='publish','Native publication');
    expect(tipiHectorApply($d,$new,$token)===$first,'Replay returns identical receipt');
    $raw=loadDoc($d,'blog/hector-test','published');[$meta,$body]=front($raw);
    expect($meta['seo_title']==='SEO'&&$meta['author']==='Auteur'&&$meta['category']==='Cat','Editorial metadata');
    $change=['id'=>str_repeat('e',32),'slug'=>'blog/hector-test','expected_revision'=>sha($raw),'mode'=>'draft','fields'=>['seo_title'=>'New SEO']];
    tipiHectorApply($d,$change,$token);
    expect(loadDoc($d,'blog/hector-test','published')===$raw,'Draft preserves live publication');
    expect(front(loadDoc($d,'blog/hector-test','drafts'))[0]['id']===$meta['id'],'Stable document identity');
    $conflict=array_replace($change,['id'=>str_repeat('f',32),'mode'=>'publish']);
    try{tipiHectorApply($d,$conflict,$token);throw new LogicException('Expected conflict');}
    catch(RuntimeException $ex){expect($ex->getCode()===409,'Existing human draft protected');}
    $dynamic=array_replace($new,['id'=>str_repeat('0',32),'slug'=>'blog/dynamic','fields'=>['title'=>'Dynamic','content_markdown'=>'{{component}}']]);
    try{tipiHectorApply($d,$dynamic,$token);throw new LogicException('Expected dynamic rejection');}
    catch(RuntimeException $ex){expect($ex->getCode()===422,'Dynamic content rejected');}
    // Exercise polling, full inventory refresh and acknowledgement recovery.
    $queued=array_replace($new,['id'=>str_repeat('9',32),'slug'=>'blog/poll']);
    $ackAttempts=0;$polls=0;
    $transport=function($method,$path,$payload,$headers)use($queued,&$ackAttempts,&$polls){
        if($path==='hector/cms/inventory')return ['inventory'=>['platform'=>'tipi_cms','mode'=>'read_only','sequence'=>$payload['sequence'],'count'=>count($payload['documents']),'articles'=>count($payload['documents'])]];
        if(isset($payload['receipt'])){
            $ackAttempts++;
            if($ackAttempts===1)fail('Lost acknowledgement',503);
            return ['commands'=>['acknowledged'=>$payload['receipt']['id']]];
        }
        $polls++;return ['commands'=>['command'=>$queued]];
    };
    try{tipiHectorRun($d,$cfg,$transport);throw new LogicException('Expected lost acknowledgement');}
    catch(RuntimeException $ex){expect($ex->getCode()===503,'Transport error surfaced');}
    $written=loadDoc($d,'blog/poll','published');
    $retry=tipiHectorRun($d,$cfg,$transport);
    expect($retry['command']['status']==='applied'&&$ackAttempts===2&&$polls===1,'Retry ack before new poll');
    expect(loadDoc($d,'blog/poll','published')===$written,'No duplicate publication');
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
