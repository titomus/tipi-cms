<?php
declare(strict_types=1);

/** Adapter for the pinned CodeGraft Framework engine, not a new OAuth implementation. */
function tipiBusinessManifest(string $root): array {
    $dir=$root.'/app/McpActions';
    if(is_link($root.'/app')||is_link($dir))throw new RuntimeException('Répertoire métier symbolique interdit',400);
    if(!is_dir($dir))return [];
    $files=glob($dir.'/*.php')?:[];sort($files,SORT_STRING);
    if(count($files)>50)throw new RuntimeException('Maximum 50 modules métier',400);
    $result=[];
    foreach($files as $file){
        $name=basename($file);
        if(!preg_match('~^[A-Za-z][A-Za-z0-9_]*\.php$~D',$name)||is_link($file)||!is_file($file)||filesize($file)>150000)throw new RuntimeException('Module métier invalide',400);
        $hash=hash_file('sha256',$file);if($hash===false)throw new RuntimeException('Lecture impossible',500);
        $result[$name]=$hash;
    }
    return $result;
}
final class TipiBusinessContext {
    public function __construct(private readonly string $directory,private readonly string $root='',private readonly array $cfg=[]) {}
    public function service(string $name): mixed {
        $f=tipiApprovedFile($this->root,$this->cfg,'app/Services/'.$name.'.php');if(!$f)throw new RuntimeException('Service non approuvé');return require $f;
    }
    private function path(string $name): string {
        if(!preg_match('~^[a-z][a-z0-9_]{0,39}$~D',$name))throw new RuntimeException('Nom de stockage invalide');
        if(is_link($this->directory)||is_link($this->directory.'/'.$name.'.sqlite'))throw new RuntimeException('Stockage symbolique interdit');
        return $this->directory.'/'.$name.'.sqlite';
    }
    public function existing(string $name): bool {return is_file($this->path($name));}
    /** Only called by a trusted module when it needs SQL; no database on discovery. */
    public function database(string $name): \CGF\Database {
        if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('PDO SQLite indisponible');
        $path=$this->path($name);
        if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true)&&!is_dir($this->directory))throw new RuntimeException('Stockage indisponible');
        $umask=umask(0077);
        try{$db=new \CGF\Database(['driver'=>'sqlite','path'=>$path]);chmod($path,0600);return $db;}
        finally{umask($umask);}
    }
}
function tipiRegistry(string $root,string $data,array $cfg): \CGF\ActionRegistry {
    require_once __DIR__.'/contracts/Http.php';
    require_once __DIR__.'/contracts/ActionSchema.php';
    require_once __DIR__.'/contracts/Actions.php';
    require_once __DIR__.'/contracts/Data.php';
    $registry=new \CGF\ActionRegistry();
    if(empty($cfg['business_read']))return $registry;
    $approved=$cfg['business_approved']??[];
    if(!is_array($approved))throw new RuntimeException('Approbations invalides');
    $context=new TipiBusinessContext($data.'/business',$root,$cfg);
    foreach(tipiBusinessManifest($root) as $file=>$hash){
        $source=tipiApprovedFile($root,$cfg,'app/McpActions/'.$file);if(!$source)continue;
        $register=require $source;
        if(!is_callable($register))throw new RuntimeException('Module non conforme');
        $register($registry,$context);
    }
    return $registry;
}
function tipiRpc(mixed $id,mixed $result): array {return ['jsonrpc'=>'2.0','id'=>$id,'result'=>$result];}
function tipiRpcError(mixed $id,int $code,string $message): array {return ['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]];}
function tipiToolResult(mixed $id,array $value): array {
    return tipiRpc($id,['content'=>[['type'=>'text','text'=>json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]],'structuredContent'=>$value,'isError'=>false]);
}
function tipiToolError(mixed $id,string $message): array {return tipiRpc($id,['content'=>[['type'=>'text','text'=>$message]],'isError'=>true]);}
function tipiScope(array $principal,string $scope): bool {return ($principal['admin_id']??null)===1&&in_array($scope,$principal['scopes']??[],true);}
function tipiBridgeDefinitions(): array {
    return [
        ['name'=>'cg_app_catalog','description'=>'Catalogue des actions métier approuvées, sans rafraîchir le client MCP.','inputSchema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false]],
        ['name'=>'cg_app_invoke','description'=>'Exécute une action métier déclarée (nom sans préfixe app_) avec les mêmes validations et autorisations.','inputSchema'=>['type'=>'object','properties'=>['action'=>['type'=>'string'],'arguments'=>['type'=>'object']],'required'=>['action','arguments'],'additionalProperties'=>false]]
    ];
}
function tipiDevDirs(): array {return ['app/McpActions','app/Controllers','app/Views','app/Services','app/Routes','app/Components','themes/layouts','themes/templates','themes/components','assets/css','assets/js'];}
function tipiDevPath(string $path): bool {
    if(!preg_match('~^[A-Za-z0-9/_-]+\.[a-z]+$~D',$path))return false;
    $dir=dirname($path);$ext=pathinfo($path,PATHINFO_EXTENSION);
    if($dir==='app/McpActions'&&!preg_match('~^[A-Za-z][A-Za-z0-9_]*\.php$~D',basename($path)))return false;
    return in_array($dir,tipiDevDirs(),true)&&in_array($ext,match($dir){
        'app/McpActions','app/Controllers','app/Views','app/Services','app/Components'=>['php'],
        'app/Routes'=>['json'],'themes/layouts','themes/templates','themes/components'=>['html'],
        'assets/css'=>['css'],'assets/js'=>['js'],default=>[]},true);
}
function tipiExtensionManifest(string $root): array {
    $out=[];
    foreach(tipiDevDirs() as $dir){
        $base=$root;
        foreach(explode('/',$dir) as $segment){$base.='/'.$segment;if(is_link($base))fail('Dossier extension symbolique interdit',400);}
        foreach(glob($base.'/*')?:[] as $file){
            $path=$dir.'/'.basename($file);
            if(!tipiDevPath($path)||is_link($file)||!is_file($file)||filesize($file)>150000)continue;
            $hash=hash_file('sha256',$file);if($hash!==false)$out[$path]=$hash;
            if(count($out)>250)fail('Maximum 250 fichiers d’extension',400);
        }
    }
    ksort($out);return $out;
}
function tipiApprovedFile(string $root,array $cfg,string $path): ?string {
    if(!tipiDevPath($path))return null;
    $full=$root;
    foreach(explode('/',$path) as $part){$full.='/'.$part;if(is_link($full))return null;}
    if(!is_file($full)||filesize($full)>150000)return null;
    $approved=$cfg['extension_approved'][$path]??null;
    if($approved===null&&dirname($path)==='app/McpActions')$approved=$cfg['business_approved'][basename($path)]??null;
    $hash=hash_file('sha256',$full);
    return is_string($approved)&&is_string($hash)&&hash_equals($approved,$hash)?$full:null;
}
function tipiRoutes(string $root,array $cfg): array {
    $routes=[];
    foreach(tipiExtensionManifest($root) as $file=>$hash){
        if(dirname($file)!=='app/Routes'||!($full=tipiApprovedFile($root,$cfg,$file)))continue;
        try{$r=json_decode((string)file_get_contents($full),true,16,JSON_THROW_ON_ERROR);}
        catch(JsonException){continue;}
        if(!is_array($r)||!is_string($r['path']??null)||!preg_match('~^/(?:admin/)?apps/[a-z0-9][a-z0-9-]{0,70}$~D',$r['path'])||!is_string($r['title']??null)||!is_string($r['handler']??null)||dirname($r['handler'])!=='app/Controllers'||!tipiApprovedFile($root,$cfg,$r['handler']))continue;
        $private=str_starts_with($r['path'],'/admin/');
        if(isset($routes[$r['path']])){ $routes[$r['path']]['conflict']=true;continue; }
        $routes[$r['path']]=['path'=>$r['path'],'title'=>substr($r['title'],0,120),'handler'=>$r['handler'],'private'=>$private,'post'=>($r['post']??false)===true,'menu'=>($r['menu']??false)===true];
    }
    return array_filter($routes,fn($r)=>empty($r['conflict']));
}
function tipiAppNav(string $root,array $cfg,bool $private): string {
    $out='';
    foreach(tipiRoutes($root,$cfg) as $r)if($r['private']===$private&&$r['menu'])$out.='<a href="'.e($r['path']).'">'.e($r['title']).'</a>';
    return $out;
}
final class TipiWebContext {
    private ?\CGF\ActionRegistry $registry=null;
    public function __construct(private readonly string $root,private readonly string $data,private readonly array $cfg,public readonly bool $owner,public readonly string $path,public readonly string $method){}
    public function action(string $name,array $args=[]): array {
        if(!$this->owner)fail('Action métier privée',403);
        $cfg=$this->cfg;$cfg['business_read']=true; // Local owner is independent of remote AI profile.
        $registry=$this->registry??=tipiRegistry($this->root,$this->data,$cfg);
        $a=$registry->all()[$name]??null;
        if(!$a)fail('Action métier non approuvée',404);
        if($a['mode']==='write'&&$this->method!=='POST')fail('Écriture uniquement via POST',405);
        return $registry->run($name,$args,['role'=>'admin','id'=>1]);
    }
    public function database(string $name): \CGF\Database {
        // Trusted PHP can use a private database; public handlers must validate their own business inputs.
        require_once __DIR__.'/contracts/Data.php';
        return (new TipiBusinessContext($this->data.'/business'))->database($name);
    }
    public function view(string $name,array $vars=[]): string {
        $f=tipiApprovedFile($this->root,$this->cfg,'app/Views/'.$name.'.php');
        if(!$f)fail('Vue non approuvée',404);
        $render=static function(string $file,array $vars,TipiWebContext $ctx): string {
            ob_start();try{require $file;return (string)ob_get_clean();}catch(Throwable $ex){ob_end_clean();throw $ex;}
        };
        return $render($f,$vars,$this);
    }
    public function service(string $name): mixed {
        $f=tipiApprovedFile($this->root,$this->cfg,'app/Services/'.$name.'.php');
        if(!$f)fail('Service non approuvé',404);
        return require $f;
    }
    public function component(string $name,array $vars=[]): string {
        $f=tipiApprovedFile($this->root,$this->cfg,'app/Components/'.$name.'.php');
        if(!$f)fail('Composant non approuvé',404);
        $render=static function(string $file,array $vars,TipiWebContext $ctx): string {
            ob_start();try{require $file;return (string)ob_get_clean();}catch(Throwable $ex){ob_end_clean();throw $ex;}
        };
        return $render($f,$vars,$this);
    }
    public function csrf(): string {return csrf();}
}
function tipiAppRoute(string $path,string $data,array $cfg): bool {
    $root=dirname(__DIR__);$r=tipiRoutes($root,$cfg)[$path]??null;
    if(!$r)return false;
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,$r['post']?['GET','POST']:['GET'],true)){http_response_code(405);return true;}
    sessionStart();
    if($r['private']&&empty($_SESSION['admin'])){redirect('/admin');}
    if($method==='POST')verifyCsrf();
    header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
    $ctx=new TipiWebContext($root,$data,$cfg,$r['private']&&!empty($_SESSION['admin']),$path,$method);
    $handler=require tipiApprovedFile($root,$cfg,$r['handler']);
    if(!is_callable($handler))fail('Contrôleur non conforme',500);
    $html=$handler($ctx,$_GET,$method==='POST'?$_POST:[]);
    if(!is_string($html))fail('Vue non conforme',500);
    $nav=$r['private']?'<a href="/admin">Contenus</a><a href="/admin?connections=1">Assistant IA</a>'.tipiAppNav($root,$cfg,true):navFor($data);
    shell($r['title'],$html,'<meta name="robots" content="noindex">',$nav,$r['private']?'Tipi CMS':$cfg['name'],$r['private']?[]:$cfg);
    return true;
}
function tipiAsset(string $path,array $cfg): bool {
    if(!preg_match('~^/assets/(css|js)/[A-Za-z0-9_-]+\.(css|js)$~D',$path,$m)||$m[1]!==$m[2])return false;
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);return true;}
    $f=tipiApprovedFile(dirname(__DIR__),$cfg,ltrim($path,'/'));
    if(!$f){http_response_code(404);return true;}
    header('Content-Type: '.($m[2]==='js'?'text/javascript':'text/css').'; charset=utf-8');header('Cache-Control: no-cache');
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')readfile($f);
    return true;
}
function tipiTheme(string $data,array $cfg,string $head,string $style,string $body,string $nav,string $brand,string $title,string $template=''): string {
    $root=dirname(__DIR__);$assets='';
    foreach(tipiExtensionManifest($root) as $path=>$hash){
        if(!str_starts_with($path,'assets/')||!tipiApprovedFile($root,$cfg,$path))continue;
        $url='/'.$path.'?v='.substr($hash,0,12);
        $assets.=str_ends_with($path,'.css')?'<link rel="stylesheet" href="'.e($url).'">':'<script defer src="'.e($url).'"></script>';
    }
    if($template!==''&&preg_match('~^[A-Za-z0-9_-]+$~D',$template)&&($f=tipiApprovedFile($root,$cfg,'themes/templates/'.$template.'.html'))){
        $html=(string)file_get_contents($f);if(str_contains($html,'{{content}}'))$body=strtr($html,['{{content}}'=>$body,'{{title}}'=>e($title)]);
    }
    $head='<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title>'.$head.'<style>'.$style.'</style>'.$assets;
    $layout=tipiApprovedFile($root,$cfg,'themes/layouts/site.html');
    $html=$layout?(string)file_get_contents($layout):'';
    if(!str_contains($html,'{{head}}')||!str_contains($html,'{{content}}'))$html='<!doctype html><html lang="fr"><head>{{head}}</head><body><header><strong><a href="/">{{brand}}</a></strong><nav>{{navigation}}</nav></header><main>{{content}}</main><footer>{{credit}}</footer></body></html>';
    $html=preg_replace_callback('~\{\{component:([A-Za-z0-9_-]+)\}\}~',function($m)use($root,$cfg){$f=tipiApprovedFile($root,$cfg,'themes/components/'.$m[1].'.html');return $f?(string)file_get_contents($f):'';},$html);
    return strtr($html,['{{head}}'=>$head,'{{content}}'=>$body,'{{navigation}}'=>$nav.tipiAppNav($root,$cfg,false),'{{brand}}'=>e($brand),'{{title}}'=>e($title),'{{credit}}'=>'Propulsé par Codegraft™']);
}
function tipiMarkdownComponent(string $name,array $cfg): string {
    $f=tipiApprovedFile(dirname(__DIR__),$cfg,'themes/components/'.$name.'.html');
    return $f?(string)file_get_contents($f):'';
}
function tipiAiPolicy(string $profile): array {
    $p=\CodeGraft\Policy::defaults('generic');
    $p['read_dirs']=tipiDevDirs();
    $p['write_dirs']=$profile==='build'?tipiDevDirs():[];
    $p['enabled']=$profile==='build';
    // Upstream prepare() checks delete as well; our gateway never exposes deletion.
    if($p['enabled'])foreach(['list','search','read','prepare','delete','validate','deploy','rollback','history'] as $k)$p['permissions'][$k]=true;
    return $p;
}
function tipiSystem(): array {
    require __DIR__.'/codegraft/standalone/bootstrap.php';
    return $codegraft;
}
// A host confirmation is not an authenticated human approval receipt. Only the
// owner may opt in to delegated deployment; CMS mode remains fail-closed.
function tipiApprovalMode(array $cfg): string {
    $mode=$cfg['ai_approval_mode']??'cms';
    return in_array($mode,['cms','chat','auto'],true)?$mode:'cms';
}
function tipiPatchReview(array $cg,string $id,string $operation): array {
    if(!preg_match('~^[a-f0-9]{32}$~D',$id)||!in_array($operation,['deploy','rollback'],true))fail('Version invalide');
    $record=$cg['vault']->json(($operation==='deploy'?'drafts/':'releases/').$id.'.json');
    if(($record['status']??'')!==($operation==='deploy'?'prepared':'deployed')||($operation==='deploy'&&($record['expires_at']??0)<time()))fail('Version indisponible ou expirée',409);
    if(empty($record['files']))fail('Aucun fichier',409);
    foreach($record['files'] as $entry){
        if(!tipiDevPath((string)($entry['path']??''))||($entry['operation']??'replace')!=='replace')fail('Fichier hors espace de développement',403);
        if($operation==='deploy'&&dirname($entry['path'])==='app/Routes'){
            $route=json_decode($entry['content']??'',true,16,JSON_THROW_ON_ERROR);
            if(!is_array($route)||!is_string($route['path']??null)||!preg_match('~^/(?:admin/)?apps/[a-z0-9][a-z0-9-]{0,70}$~D',$route['path'])||!is_string($route['handler']??null)||dirname($route['handler'])!=='app/Controllers'||!tipiDevPath($route['handler'])||!is_string($route['title']??null))fail('Déclaration de route invalide');
        }
    }
    $validation=$operation==='deploy'?$cg['workspace']->validate($id):['valid'=>true,'warning'=>'Les empreintes seront vérifiées par CodeGraft au moment de la restauration.'];
    return ['id'=>$id,'operation'=>$operation,'summary'=>$record['summary']??'','fingerprint'=>hash('sha256',json_encode($record,JSON_THROW_ON_ERROR)),'files'=>array_map(static fn($e)=>['path'=>$e['path'],'before_sha256'=>$e['before_sha256'],'after_sha256'=>$e['after_sha256']],$record['files']),'validation'=>$validation,'rollback_available'=>$operation==='deploy','database_restored'=>false];
}
function tipiApplyRelease(string $data,array $cg,string $id,string $operation,string $fingerprint,string $channel): array {
    // Serialize profile changes, activation and rollback in the CMS lock.
    // Workspace also locks its own vault, in the same lock order as ai_profile.
    return locked($data,function()use($data,$cg,$id,$operation,$fingerprint,$channel){
        $c=jread($data.'/config.json');$mode=tipiApprovalMode($c);
        if(($c['ai_profile']??'read')!=='build')fail('Profil développement désactivé',403);
        if($channel==='oauth'&&$mode==='cms')fail('Validation dans le CMS obligatoire',403);
        $review=tipiPatchReview($cg,$id,$operation);
        if(!hash_equals($review['fingerprint'],$fingerprint))fail('Version modifiée : refaire la revue',409);
        if(empty($review['validation']['valid']))fail('Le patch ne passe pas les contrôles',409);
        $root=dirname(__DIR__);$history=$data.'/extension-releases/'.$id.'.json';
        $record=$cg['vault']->json(($operation==='deploy'?'drafts/':'releases/').$id.'.json');
        if($operation==='deploy'){
            $prior=[];
            foreach($record['files'] as $entry){
                $p=$entry['path'];
                if(tipiApprovedFile($root,$c,$p)!==null)$prior[$p]=$entry['before_sha256'];
            }
            // Only previously approved exact versions may be reactivated by rollback.
            jwrite($history,['id'=>$id,'prior_approved'=>$prior,'mode'=>$mode,'channel'=>$channel,'review_sha256'=>$fingerprint,'at'=>gmdate('c')]);
            $result=$cg['workspace']->deploy($id,true);
            $current=tipiExtensionManifest($root);
            foreach($record['files'] as $entry){
                $p=$entry['path'];$hash=$entry['after_sha256'];
                if(isset($current[$p])&&hash_equals($current[$p],$hash))$c['extension_approved'][$p]=$hash;
                else unset($c['extension_approved'][$p]);
            }
        }else{
            $prior=jread($history)['prior_approved']??[];
            $result=$cg['workspace']->rollback($id,true);
            $current=tipiExtensionManifest($root);
            foreach($record['files'] as $entry){
                $p=$entry['path'];unset($c['extension_approved'][$p]);
                if(dirname($p)==='app/McpActions')unset($c['business_approved'][basename($p)]);
                if(isset($prior[$p],$current[$p])&&hash_equals($prior[$p],$current[$p])&&hash_equals($entry['before_sha256'],$current[$p]))$c['extension_approved'][$p]=$current[$p];
            }
        }
        jwrite($data.'/config.json',$c);
        $result['approval_mode']=$mode;$result['channel']=$channel;$result['database_restored']=false;
        $result['human_approval_verified']=$channel==='cms'; // authenticated password + CSRF, never inferred from a tool argument
        return $result;
    });
}
function tipiReleaseTools(): array {
    $id=['type'=>'string','pattern'=>'^[a-f0-9]{32}$','description'=>'Identifiant du patch ou de la version'];
    $schema=static fn($properties,$required)=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false];
    $fingerprint=['type'=>'string','pattern'=>'^[a-f0-9]{64}$','description'=>'Empreinte exacte obtenue avec cg_review_release, jamais inventée.'];
    return [
        ['name'=>'cg_review_release','title'=>'Vérifier une modification','description'=>'Revue technique sans déploiement : résumé, fichiers, empreinte exacte, contrôles. Lire avant toute installation ou restauration.','inputSchema'=>$schema(['id'=>$id,'operation'=>['type'=>'string','enum'=>['deploy','rollback']]],['id','operation']),'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'openWorldHint'=>false]],
        ['name'=>'cg_deploy_patch','title'=>'Installer et activer le patch','description'=>'Action à effet réel : déploie et active le patch exact, avec sauvegarde du code. En mode chat, présentez le résumé et obtenez une validation explicite du membre dans la conversation avant cet appel. En mode auto, suivez la demande du membre. En mode CMS, refus serveur. Ne pas appeler si refus.','inputSchema'=>$schema(['id'=>$id,'fingerprint'=>$fingerprint],['id','fingerprint']),'annotations'=>['readOnlyHint'=>false,'destructiveHint'=>true,'idempotentHint'=>false,'openWorldHint'=>true]],
        ['name'=>'cg_rollback_patch','title'=>'Restaurer une version du site','description'=>'Action à effet réel : restaure le code précédent, pas la base SQLite. En mode chat, demander la validation explicite du membre avant appel. Mode CMS : refus serveur. Utiliser la revue rollback et son empreinte.','inputSchema'=>$schema(['id'=>$id,'fingerprint'=>$fingerprint],['id','fingerprint']),'annotations'=>['readOnlyHint'=>false,'destructiveHint'=>true,'idempotentHint'=>false,'openWorldHint'=>true]]
    ];
}
function tipiAdminAction(string $data,array $cfg,string $action,array $post): void {
    $cg=tipiSystem();$vault=$cg['vault'];
    if($action==='ai_profile'){
        $profile=$post['profile']??'';
        if(!in_array($profile,['read','manage','build'],true))fail('Profil inconnu');
        if($profile==='build'&&($post['accept_php']??'')!=='1')fail('Confirmez le risque lié au PHP');
        locked($data,function()use($data,$profile,$vault){
            $c=jread($data.'/config.json');
            $vault->locked(fn()=>$vault->savePolicy(tipiAiPolicy($profile)));
            $c['ai_profile']=$profile;if($profile!=='build')$c['ai_approval_mode']='cms';
            $c['business_read']=true;$c['business_write']=$profile!=='read';
            jwrite($data.'/config.json',$c);
        });return;
    }
    if($action==='ai_approval_mode'){
        $mode=$post['approval_mode']??'';
        if(!in_array($mode,['cms','chat','auto'],true))fail('Mode inconnu');
        if(!password_verify((string)($post['owner_password']??''),$cfg['password_hash'])){usleep(400000);fail('Mot de passe incorrect',403);}
        if($mode!=='cms'&&($post['accept_delegation']??'')!=='1')fail('Confirmez la délégation de déploiement');
        locked($data,function()use($data,$mode){
            $c=jread($data.'/config.json');
            if($mode!=='cms'&&($c['ai_profile']??'read')!=='build')fail('Activez le profil Création de fonctionnalités',403);
            $c['ai_approval_mode']=$mode;jwrite($data.'/config.json',$c);
        });return;
    }
    if($action==='ai_revoke'){
        if(!password_verify((string)($post['owner_password']??''),$cfg['password_hash'])){usleep(400000);fail('Mot de passe incorrect',403);}
        $id=(string)($post['client']??'');
        if(!preg_match('~^mcp_[A-Za-z0-9_-]{10,150}$~D',$id))fail('Client invalide');
        $path=$vault->directory.'/oauth.sqlite';
        if(is_link($path)||!is_file($path))fail('Connexion inconnue',404);
        $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->beginTransaction();
        try{
            $q=$db->prepare('UPDATE mcp_dcr_tokens SET revoked_at=? WHERE client_id=? AND revoked_at IS NULL');$q->execute([time(),$id]);
            $q=$db->prepare('DELETE FROM mcp_dcr_codes WHERE client_id=?');$q->execute([$id]);
            $db->commit();
        }catch(Throwable $ex){$db->rollBack();throw $ex;}return;
    }
    if($action==='ai_enable_contacts'){
        if(!password_verify((string)($post['owner_password']??''),$cfg['password_hash'])){usleep(400000);fail('Mot de passe incorrect',403);}
        $sample=jread(__DIR__.'/contacts-manifest.json');
        if(count($sample)!==4)fail('Exemple non disponible',409);
        locked($data,function()use($data,$sample){
            $c=jread($data.'/config.json');$current=tipiExtensionManifest(dirname(__DIR__));
            foreach($sample as $p=>$h)if(!isset($current[$p])||!hash_equals($h,$current[$p]))fail('Exemple modifié : approbation manuelle nécessaire',409);
            foreach($sample as $p=>$h)$c['extension_approved'][$p]=$h;
            $c['business_read']=true;
            jwrite($data.'/config.json',$c);
        });return;
    }
    if(($cfg['ai_profile']??'read')!=='build')fail('Activez le profil Création de fonctionnalités',403);
    if($action==='ai_approve_module'){
        $file=(string)($post['module']??'');$hash=(string)($post['sha256']??'');
        if(!password_verify((string)($post['owner_password']??''),$cfg['password_hash']))fail('Mot de passe incorrect',403);
        locked($data,function()use($data,$file,$hash){
            $c=jread($data.'/config.json');
            if(($c['ai_profile']??'read')!=='build')fail('Profil modifié',409);
            $m=tipiExtensionManifest(dirname(__DIR__));
            if(!isset($m[$file])||!hash_equals($m[$file],$hash))fail('Version modifiée : rechargez la page',409);
            $c['extension_approved'][$file]=$hash;jwrite($data.'/config.json',$c);
        });return;
    }
    $id=(string)($post['patch']??'');
    if(!preg_match('~^[a-f0-9]{32}$~D',$id))fail('Version invalide');
    if(!password_verify((string)($post['owner_password']??''),$cfg['password_hash']))fail('Mot de passe incorrect',403);
    if($action==='ai_deploy'||$action==='ai_rollback'){
        $operation=$action==='ai_deploy'?'deploy':'rollback';
        $fingerprint=(string)($post['fingerprint']??'');
        // Existing authenticated rollback form can use the current release fingerprint.
        if($operation==='rollback'&&$fingerprint==='')$fingerprint=tipiPatchReview($cg,$id,$operation)['fingerprint'];
        tipiApplyRelease($data,$cg,$id,$operation,$fingerprint,'cms');
    }
}
function tipiAdminPanel(string $data,array $cfg): string {
    $cg=tipiSystem();$vault=$cg['vault'];$profile=$cfg['ai_profile']??'read';
    $body='<div class="panel"><h2>Connecter votre assistant</h2><p>Une adresse pour gérer vos articles, votre design et vos outils métier.</p><p><code>'.e($cfg['url'].'/mcp').'</code></p><p>Dans ChatGPT, ajoutez un serveur MCP personnalisé avec OAuth. Connectez-vous avec votre mot de passe habituel du site.</p></div><h2>Que peut faire votre IA ?</h2><form method="post">'.csrf();
    foreach(['read'=>['Consultation','Lire les contenus, le design et les données des modules approuvés.'],'manage'=>['Gestion du site','Créer et publier des articles, refaire la présentation et utiliser vos outils métier.'],'build'=>['Création de fonctionnalités','Toutes les capacités précédentes, plus les animations JS, templates HTML, vues et applications PHP/SQLite. Chaque déploiement nécessite votre accord.']] as $k=>$v){
        $body.='<label class="panel"><input style="display:inline;width:auto" type="radio" name="profile" value="'.$k.'" '.($profile===$k?'checked':'').'> <strong>'.e($v[0]).'</strong><br>'.e($v[1]).'</label>';
    }
    $body.='<label><input style="width:auto;display:inline" type="checkbox" name="accept_php" value="1"> Pour la création de fonctionnalités, je comprends que le PHP approuvé possède les droits de mon hébergement.</label><button name="action" value="ai_profile">Enregistrer mon choix</button></form>';
    $mode=tipiApprovalMode($cfg);
    $body.='<h2>Validation des développements</h2><form method="post">'.csrf();
    foreach(['chat'=>['Dans ChatGPT (recommandé)','Votre assistant demande votre accord dans la conversation ; la confirmation est gérée par le client MCP. Le serveur délègue le déploiement au client autorisé.'],'auto'=>['Autonomie','Votre assistant peut installer les modifications dans son périmètre sans validation CMS supplémentaire. Les confirmations imposées par ChatGPT restent applicables.'],'cms'=>['Dans le CMS (contrôle strict)','Le serveur refuse tout déploiement MCP. Vous validez dans cette page avec votre mot de passe.']] as $key=>$v){
        $body.='<label class="panel"><input style="width:auto;display:inline" type="radio" name="approval_mode" value="'.$key.'" '.($mode===$key?'checked':'').'> <strong>'.e($v[0]).'</strong><br>'.e($v[1]).'</label>';
    }
    $body.='<p>Les contrôles de fichiers, les sauvegardes et l’historique restent actifs. Le mode ChatGPT ne fournit pas de preuve humaine cryptographique au CMS. Le PHP personnalisé peut agir avec les droits de votre hébergement.</p><label><input style="width:auto;display:inline" type="checkbox" name="accept_delegation" value="1"> J’autorise mon client MCP authentifié à déployer du code dans le périmètre permis.</label>'.field('owner_password','Votre mot de passe','','password').'<button name="action" value="ai_approval_mode">Enregistrer le mode de validation</button></form><h2>Modifications proposées</h2>';
    $files=glob($vault->directory.'/drafts/*.json')?:[];usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));$count=0;
    foreach(array_slice($files,0,50) as $f){
        $id=basename($f,'.json');if(!preg_match('~^[a-f0-9]{32}$~D',$id))continue;
        $draft=$vault->json('drafts/'.$id.'.json');
        if(($draft['status']??'')!=='prepared'||($draft['expires_at']??0)<time())continue;
        $count++;
        $body.='<div class="panel"><h3>'.e((string)($draft['summary']??'Modification')).'</h3><details><summary>Vérifier les fichiers proposés</summary>';
        foreach($draft['files']??[] as $entry)$body.='<p>'.e((string)$entry['path']).'</p><pre>'.e((string)($entry['content']??'')).'</pre>';
        $body.='</details><form method="post">'.csrf().'<input type="hidden" name="patch" value="'.$id.'"><input type="hidden" name="fingerprint" value="'.hash('sha256',json_encode($draft,JSON_THROW_ON_ERROR)).'">'.field('owner_password','Votre mot de passe','','password').'<button name="action" value="ai_deploy">Installer et activer cette fonctionnalité</button></form></div>';
        if($count>=10)break;
    }
    if(!$count)$body.='<p>Aucune modification en attente.</p>';
    $body.='<h2>Fonctionnalités de votre site</h2>';
    if(!isset(tipiRoutes(dirname(__DIR__),$cfg)['/admin/apps/contacts'])&&is_file(__DIR__.'/contacts-manifest.json'))$body.='<div class="panel"><h3>Carnet de contacts</h3><p>Exemple intégré : interface privée et actions MCP sur la même base SQLite.</p><form method="post">'.csrf().field('owner_password','Votre mot de passe','','password').'<button name="action" value="ai_enable_contacts">Activer le carnet de contacts</button></form></div>';
    foreach(tipiExtensionManifest(dirname(__DIR__)) as $file=>$hash){
        $approved=tipiApprovedFile(dirname(__DIR__),$cfg,$file)!==null;
        $body.='<div class="panel"><strong>'.e($file).'</strong><p>'.($approved?'Active':'Version à approuver').'</p>';
        if(!$approved){
            $source=file_get_contents(dirname(__DIR__).'/'.$file);
            $body.='<details><summary>Afficher le code de cette version</summary><pre>'.e((string)$source).'</pre></details><form method="post">'.csrf().'<input type="hidden" name="module" value="'.e($file).'"><input type="hidden" name="sha256" value="'.e($hash).'">'.field('owner_password','Votre mot de passe','','password').'<button name="action" value="ai_approve_module">Approuver cette fonctionnalité</button></form>';
        }
        $body.='</div>';
    }
    $body.='<h2>Historique des évolutions</h2>';
    foreach(array_slice(glob($vault->directory.'/releases/*.json')?:[], -10) as $f){
        $id=basename($f,'.json');if(!preg_match('~^[a-f0-9]{32}$~D',$id))continue;
        $r=$vault->json('releases/'.$id.'.json');$body.='<p>'.e((string)($r['summary']??'')).' · '.e((string)($r['status']??'')).'</p>';
        if(($r['status']??'')==='deployed')$body.='<form method="post">'.csrf().'<input type="hidden" name="patch" value="'.$id.'">'.field('owner_password','Votre mot de passe','','password').'<button name="action" value="ai_rollback" class="secondary">Restaurer la version précédente</button></form>';
    }
    $body.='<h2>Assistants connectés</h2>';
    foreach(\CodeGraft\ControlCenter::clients($vault) as $client){
        $body.='<form method="post">'.csrf().'<input type="hidden" name="client" value="'.e($client['client_id']).'"><p>ChatGPT · '.(int)$client['active_tokens'].' jeton(s) actif(s)</p>'.field('owner_password','Votre mot de passe','','password').'<button name="action" value="ai_revoke" class="secondary">Révoquer</button></form>';
    }
    return $body;
}
function tipiBridgeDispatch(array $request,array $principal,array $system,string $data,array $cfg): ?array {
    $id=$request['id']??null;$method=$request['method']??null;
    if(($request['jsonrpc']??null)!=='2.0'||!is_string($method)||($id!==null&&!is_int($id)&&!is_string($id)))return tipiRpcError(null,-32600,'Invalid request');
    if(!array_key_exists('id',$request))return null;
    $p=$request['params']??[];
    if(!is_array($p))return tipiRpcError($id,-32602,'Invalid params');
    if($method==='initialize'){
        $v=$p['protocolVersion']??'2025-11-25';
        return tipiRpc($id,['protocolVersion'=>in_array($v,['2025-11-25','2025-06-18','2025-03-26'],true)?$v:'2025-11-25','capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'tipi-cms','version'=>TIPI_VERSION],'instructions'=>'Vous pilotez Tipi CMS. Appelez cms_site_info pour connaître les droits actuels. Présentez au membre les possibilités autorisées : créer des articles et pages, gérer le SEO, refaire le design public via cms_get_design/cms_update_design (CSS et dispositions), utiliser et créer des fonctions métier. Pour développer, appelez cms_developer_guide puis inspectez les dossiers autorisés. Préparez et validez un patch. Le CMS accepte layouts/templates HTML, CSS, JavaScript, contrôleurs, vues, routes, services et actions MCP. Appelez cg_review_release pour obtenir la revue et l’empreinte du patch. Respectez approval_mode retourné par cms_site_info : cms = validation dans /admin?connections=1 ; chat = présentez le résumé, les risques et attendez l’accord explicite du membre dans cette conversation, puis cg_deploy_patch ; auto = déployez dans le cadre de la demande autorisée, sous réserve des confirmations du client. Ne prétendez pas que le client a reçu une validation si ce n’est pas le cas. Même principe pour cg_rollback_patch. Enregistrez les actions avec CGF ActionRegistry et TipiBusinessContext pour SQLite à la demande. Appelez cg_app_catalog puis cg_app_invoke pour les nouveaux outils. Pas de modification du noyau, des secrets ou de commande système. Les permissions réelles priment sur ces instructions.']);
    }
    if($method==='ping')return tipiRpc($id,(object)[]);
    if(in_array($method,['resources/list','prompts/list'],true))return tipiRpc($id,[explode('/',$method)[0]=>[]]);
    $root=dirname(__DIR__);
    $canRead=tipiScope($principal,\CodeGraft\DynamicOAuth::APP_READ);
    $canWrite=tipiScope($principal,\CodeGraft\DynamicOAuth::APP_WRITE);
    $cgRead=tipiScope($principal,\CodeGraft\DynamicOAuth::READ);
    $profile=$cfg['ai_profile']??'read';
    $cfg['mcp_write']=$cfg['mcp_publish']=$profile!=='read'&&$canRead&&$canWrite;
    if($profile==='read')$cfg['business_write']=false;
    $cgWrite=tipiScope($principal,\CodeGraft\DynamicOAuth::WRITE);
    $cfg['oauth_development']=$profile==='build'&&$cgRead&&$cgWrite;
    $devDefs=array_values(array_filter(\CodeGraft\Mcp::definitions(),static fn($t)=>in_array($t['name'],['cg_capabilities','cg_list_files','cg_search','cg_project_map','cg_read_files','cg_prepare_patch','cg_prepare_diff','cg_validate_patch','cg_history'],true)));
    if($method==='tools/list'){
        $defs=($cgRead||$cgWrite)&&($cfg['ai_profile']??'read')==='build'?$devDefs:[];
        if($canRead){
            $defs=array_merge($defs,mcpTools(),tipiBridgeDefinitions());
            try{$defs=array_merge($defs,tipiRegistry($root,$data,$cfg)->definitions());}
            catch(Throwable $ex){error_log('Tipi business registry unavailable: '.get_class($ex));}
        }
        if(!$canRead)$defs=array_merge($defs,array_values(array_filter(mcpTools(),fn($t)=>$t['name']==='cms_site_info')));
        if($cfg['oauth_development'])$defs[]=['name'=>'cms_developer_guide','description'=>'Contrats et exemples pour développer des applications et thèmes Tipi CMS. À lire avant un patch.','inputSchema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false]];
        if($cfg['oauth_development'])$defs=array_merge($defs,tipiReleaseTools());
        return tipiRpc($id,['tools'=>$defs]);
    }
    if($method!=='tools/call'||!is_string($p['name']??null)||!is_array($p['arguments']??[]))return tipiRpcError($id,-32602,'Invalid tool call');
    $name=$p['name'];$args=$p['arguments']??[];
    if($args!==[]&&array_is_list($args))return tipiRpcError($id,-32602,'Invalid arguments');
    if($name==='cg_app_catalog'||$name==='cg_app_invoke'||str_starts_with($name,'app_')){
        if(!$canRead||empty($cfg['business_read']))return tipiToolError($id,'Actions métier désactivées ou scope app:read absent');
        try{
            $registry=tipiRegistry($root,$data,$cfg);
            if($name==='cg_app_catalog'){
                if($args!==[])return tipiRpcError($id,-32602,'Catalog takes no arguments');
                $defs=$registry->definitions();return tipiToolResult($id,['actions'=>$defs,'count'=>count($defs),'fingerprint'=>hash('sha256',json_encode($defs,JSON_THROW_ON_ERROR))]);
            }
            if($name==='cg_app_invoke'){
                if(count($args)!==2||!is_string($args['action']??null)||!is_array($args['arguments']??null))return tipiRpcError($id,-32602,'Invalid action invocation');
                $key=$args['action'];$args=$args['arguments'];
            }else $key=substr($name,4);
            if(!preg_match('~^[a-z][a-z0-9_]{1,50}$~D',$key)||($args!==[]&&array_is_list($args)))return tipiRpcError($id,-32602,'Invalid action');
            $action=$registry->all()[$key]??null;
            if($action===null)return tipiToolError($id,'Action non déclarée ou version non approuvée');
            if($action['mode']==='write'&&(!$canWrite||empty($cfg['business_write'])))return tipiToolError($id,'Écriture métier désactivée ou scope app:write absent');
            return tipiToolResult($id,$registry->run($key,$args,['role'=>'admin','id'=>1]));
        }catch(Throwable $ex){error_log('Tipi business action refused: '.get_class($ex));return tipiToolError($id,$ex instanceof \CGF\HttpError?$ex->getMessage():'Action métier indisponible');}
    }
    if(in_array($name,['cg_review_release','cg_deploy_patch','cg_rollback_patch'],true)){
        if(!$cfg['oauth_development'])return tipiToolError($id,'Profil développement et scopes lecture/écriture requis');
        try{
            $idArg=$args['id']??null;
            if(!is_string($idArg))fail('Identifiant requis');
            if($name==='cg_review_release'){
                if(count($args)!==2||!is_string($args['operation']??null))fail('Opération requise');
                $review=tipiPatchReview($system,$idArg,$args['operation']);
                $review['approval_mode']=tipiApprovalMode($cfg);
                $review['human_approval_verified']=false;
                return tipiToolResult($id,$review);
            }
            if(count($args)!==2||!is_string($args['fingerprint']??null))fail('Empreinte de revue requise');
            $result=tipiApplyRelease($data,$system,$idArg,$name==='cg_deploy_patch'?'deploy':'rollback',$args['fingerprint'],'oauth');
            \CodeGraft\AuditLog::record($system['vault'],$name,true,'oauth');
            return tipiToolResult($id,$result);
        }catch(Throwable $ex){
            \CodeGraft\AuditLog::record($system['vault'],$name,false,'oauth');
            return tipiToolError($id,$ex instanceof RuntimeException&&$ex->getCode()>=400&&$ex->getCode()<500?$ex->getMessage():'Modification refusée ou indisponible ; vérifiez les empreintes, la politique et l’historique.');
        }
    }
    if($name==='cms_developer_guide'){
        if(!$cfg['oauth_development'])return tipiToolError($id,'Profil développement et scopes requis');
        return tipiToolResult($id,['guide'=>(string)file_get_contents(__DIR__.'/developer-guide.md'),'directories'=>tipiDevDirs(),'routes'=>array_values(tipiRoutes($root,$cfg))]);
    }
    if(str_starts_with($name,'cms_')){
        if($name==='cms_site_info'){
            $info=mcpCall($data,$cfg,$name,[]);
            $info['oauth_scopes']=$principal['scopes']??[];
            $info['editorial_read']=$canRead;
            $info['business']['read']=$canRead&&!empty($cfg['business_read']);
            $info['business']['write']=$canRead&&$canWrite&&!empty($cfg['business_write']);
            $info['business']['development']=$cfg['oauth_development'];
            $info['design']['read']=$canRead;
            $info['approval_mode']=tipiApprovalMode($cfg);
            $info['remote_deploy']=$cfg['oauth_development']&&tipiApprovalMode($cfg)!=='cms';
            $info['approval_note']='Chat : confirmation gérée par le client MCP ; aucune preuve humaine transmise au serveur. CMS : validation authentifiée obligatoire.';
            return tipiToolResult($id,$info);
        }
        if(!$canRead)return tipiToolError($id,'Scope app:read requis');
        if(in_array($name,['cms_save_draft','cms_publish','cms_update_design'],true)&&!$canWrite)return tipiToolError($id,'Scope app:write requis');
        // OAuth permissions are derived from the live profile and consented scopes, independently of legacy Bearer keys.
        try{return tipiToolResult($id,mcpCall($data,$cfg,$name,$args));}
        catch(Throwable $ex){return tipiToolError($id,$ex instanceof RuntimeException&&$ex->getCode()<500?$ex->getMessage():'Action éditoriale indisponible');}
    }
    if(($cfg['ai_profile']??'read')!=='build'||!in_array($name,array_column($devDefs,'name'),true)||(!$cgRead&&!$cgWrite))return tipiRpcError($id,-32602,'Unknown or unauthorized tool');
    // Restrict actual CodeGraft path permissions, even if the owner sets a broader allowlist.
    $policy=$system['vault']->policy();
    foreach(array_merge($policy['read_dirs']??[],$policy['write_dirs']??[]) as $dir)
        if(!is_string($dir)||!in_array($dir,tipiDevDirs(),true))
            return tipiToolError($id,'Réenregistrez le profil Création de fonctionnalités dans Assistant IA.');
    if(in_array($name,['cg_prepare_patch','cg_prepare_diff'],true)){
        foreach($args['files']??[] as $file)if(!is_array($file)||!tipiDevPath((string)($file['path']??'')))return tipiToolError($id,'Fichier hors espace de développement autorisé');
    }
    return $system['mcp']->dispatch($request,$principal);
}
function tipiCodeGraftRoute(string $path,string $data,array $cfg): void {
    header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
    // Do not accept proxy-controlled Host as an OAuth origin.
    if(!str_starts_with($cfg['url'],'https://')||empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off'){
        http_response_code(403);header('Content-Type: text/plain; charset=utf-8');
        echo 'Tipi CodeGraft : HTTPS obligatoire.';return;
    }
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    if($origin!==''&&$origin!==$cfg['url']){
        http_response_code(403);header('Content-Type: text/plain; charset=utf-8');
        echo 'Tipi CodeGraft : origine de la requête refusée. Ouvrez la page de connexion directement sur le domaine configuré et rechargez-la.';return;
    }
    require __DIR__.'/codegraft/standalone/bootstrap.php';
    $oauth=new \CodeGraft\OAuthHttp($codegraft['vault']);
    $path=rtrim($path,'/');
    $routes=['/.well-known/oauth-authorization-server'=>'metadata','/.well-known/oauth-protected-resource'=>'protectedResource','/.well-known/oauth-protected-resource/mcp'=>'protectedResource','/mcp/oauth/register'=>'register','/mcp/oauth/authorize'=>'authorize','/mcp/oauth/token'=>'token'];
    if(isset($routes[$path])){
        $method=$_SERVER['REQUEST_METHOD']??'GET';$action=$routes[$path];
        if(!in_array($method,match($action){'register','token'=>['POST'],'authorize'=>['GET','POST'],default=>['GET']},true)){http_response_code(405);return;}
        if($action==='authorize'){
            sessionStart();
            // Only the CMS login is authoritative; old standalone cookies are ignored.
            $_SESSION['cg_authenticated']=!empty($_SESSION['admin']);
            $_SESSION['cg_csrf']=$_SESSION['csrf'];
            // Default scopes for clients that omit scope; never broaden an explicit request.
            if($method==='GET'&&!isset($_GET['scope'])){
                $_GET['scope']=match($cfg['ai_profile']??'read'){'build'=>'app:read app:write codegraft:read codegraft:write','manage'=>'app:read app:write',default=>'app:read'};
            }
            // OAuth uses the same browser session, including CSRF consent.
            header('Referrer-Policy: same-origin');
        }
        $oauth->$action();return;
    }
    $auth=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    $principal=null;
    if(preg_match('~^Bearer (\S+)$~D',$auth,$m))$principal=(new \CodeGraft\DynamicOAuth($codegraft['vault']))->principal($m[1]);
    if($principal===null){
        header('WWW-Authenticate: Bearer resource_metadata="'.$cfg['url'].'/.well-known/oauth-protected-resource/mcp", scope="codegraft:read codegraft:write app:read app:write"');
        jsonout(['error'=>'unauthorized'],401);
    }
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');jsonout(['error'=>'method_not_allowed'],405);}
    if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))jsonout(['error'=>'unsupported_media_type'],415);
    $raw=file_get_contents('php://input',false,null,0,1800001);
    if(!is_string($raw)||strlen($raw)>1800000)jsonout(['error'=>'request_too_large'],413);
    try{$request=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}
    catch(JsonException){jsonout(tipiRpcError(null,-32700,'Invalid JSON'));}
    if(!is_array($request)||array_is_list($request))jsonout(tipiRpcError(null,-32600,'Invalid request'));
    $out=tipiBridgeDispatch($request,$principal,$codegraft,$data,$cfg);
    if($out===null){http_response_code(202);return;}jsonout($out);
}
