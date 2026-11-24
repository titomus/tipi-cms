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
    public function __construct(private readonly string $directory) {}
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
    $context=new TipiBusinessContext($data.'/business');
    foreach(tipiBusinessManifest($root) as $file=>$hash){
        if(!isset($approved[$file])||!is_string($approved[$file])||!hash_equals($approved[$file],$hash))continue;
        $register=require $root.'/app/McpActions/'.$file;
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
function tipiAiPolicy(string $profile): array {
    $p=\CodeGraft\Policy::defaults('generic');
    $p['read_dirs']=['app/McpActions'];
    $p['write_dirs']=$profile==='build'?['app/McpActions']:[];
    $p['enabled']=$profile==='build';
    // Upstream prepare() checks delete as well; our gateway never exposes deletion.
    if($p['enabled'])foreach(['list','search','read','prepare','delete','validate','deploy','rollback','history'] as $k)$p['permissions'][$k]=true;
    return $p;
}
function tipiSystem(): array {
    require __DIR__.'/codegraft/standalone/bootstrap.php';
    return $codegraft;
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
            $c['ai_profile']=$profile;$c['mcp_write']=$profile!=='read';$c['mcp_publish']=$profile!=='read';
            $c['business_read']=true;$c['business_write']=$profile!=='read';
            jwrite($data.'/config.json',$c);
        });return;
    }
    if($action==='ai_revoke'){
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
    if(($cfg['ai_profile']??'read')!=='build')fail('Activez le profil Création de fonctionnalités',403);
    if($action==='ai_approve_module'){
        $file=(string)($post['module']??'');$hash=(string)($post['sha256']??'');
        if(!password_verify((string)($post['owner_password']??''),$cfg['password_hash']))fail('Mot de passe incorrect',403);
        locked($data,function()use($data,$file,$hash){
            $c=jread($data.'/config.json');
            if(($c['ai_profile']??'read')!=='build')fail('Profil modifié',409);
            $m=tipiBusinessManifest(dirname(__DIR__));
            if(!isset($m[$file])||!hash_equals($m[$file],$hash))fail('Version modifiée : rechargez la page',409);
            $c['business_approved'][$file]=$hash;jwrite($data.'/config.json',$c);
        });return;
    }
    $id=(string)($post['patch']??'');
    if(!preg_match('~^[a-f0-9]{32}$~D',$id))fail('Version invalide');
    if(!password_verify((string)($post['owner_password']??''),$cfg['password_hash']))fail('Mot de passe incorrect',403);
    if($action==='ai_deploy'){
        $draft=$vault->json('drafts/'.$id.'.json');
        if(!hash_equals(hash('sha256',json_encode($draft,JSON_THROW_ON_ERROR)),(string)($post['fingerprint']??'')))fail('Patch modifié : rechargez',409);
        foreach($draft['files']??[] as $entry)if(!preg_match('~^app/McpActions/[A-Za-z][A-Za-z0-9_]*\.php$~D',$entry['path']??'')||($entry['operation']??'replace')!=='replace')fail('Seuls les modules métier PHP sont autorisés',403);
        $result=$cg['workspace']->validate($id);if(empty($result['valid']))fail('Le patch ne passe pas les contrôles');
        $cg['workspace']->deploy($id,true);
        // Executable code stays disabled until its exact version is separately approved.
    }elseif($action==='ai_rollback')$cg['workspace']->rollback($id,true);
}
function tipiAdminPanel(string $data,array $cfg): string {
    $cg=tipiSystem();$vault=$cg['vault'];$profile=$cfg['ai_profile']??'read';
    $body='<div class="panel"><h2>Connecter votre assistant</h2><p>Une adresse pour gérer vos articles, votre design et vos outils métier.</p><p><code>'.e($cfg['url'].'/codegraft-api/').'</code></p><p>Dans ChatGPT, ajoutez un serveur MCP personnalisé avec OAuth. Connectez-vous avec votre mot de passe habituel du site.</p></div><h2>Que peut faire votre IA ?</h2><form method="post">'.csrf();
    foreach(['read'=>['Consultation','Lire les contenus, le design et les données des modules approuvés.'],'manage'=>['Gestion du site','Créer et publier des articles, refaire la présentation et utiliser vos outils métier.'],'build'=>['Création de fonctionnalités','Toutes les capacités précédentes, plus la création de nouveaux outils PHP et SQLite. Chaque déploiement nécessite votre accord.']] as $k=>$v){
        $body.='<label class="panel"><input style="display:inline;width:auto" type="radio" name="profile" value="'.$k.'" '.($profile===$k?'checked':'').'> <strong>'.e($v[0]).'</strong><br>'.e($v[1]).'</label>';
    }
    $body.='<label><input style="width:auto;display:inline" type="checkbox" name="accept_php" value="1"> Pour la création de fonctionnalités, je comprends que le PHP approuvé possède les droits de mon hébergement.</label><button name="action" value="ai_profile">Enregistrer mon choix</button></form><h2>Modifications proposées</h2>';
    $files=glob($vault->directory.'/drafts/*.json')?:[];usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));$count=0;
    foreach(array_slice($files,0,50) as $f){
        $id=basename($f,'.json');if(!preg_match('~^[a-f0-9]{32}$~D',$id))continue;
        $draft=$vault->json('drafts/'.$id.'.json');
        if(($draft['status']??'')!=='prepared'||($draft['expires_at']??0)<time())continue;
        $count++;
        $body.='<div class="panel"><h3>'.e((string)($draft['summary']??'Modification')).'</h3><details><summary>Vérifier les fichiers proposés</summary>';
        foreach($draft['files']??[] as $entry)$body.='<p>'.e((string)$entry['path']).'</p><pre>'.e((string)($entry['content']??'')).'</pre>';
        $body.='</details><form method="post">'.csrf().'<input type="hidden" name="patch" value="'.$id.'"><input type="hidden" name="fingerprint" value="'.hash('sha256',json_encode($draft,JSON_THROW_ON_ERROR)).'">'.field('owner_password','Votre mot de passe','','password').'<button name="action" value="ai_deploy">Valider et installer</button></form></div>';
        if($count>=10)break;
    }
    if(!$count)$body.='<p>Aucune modification en attente.</p>';
    $body.='<h2>Fonctionnalités de votre site</h2>';
    foreach(tipiBusinessManifest(dirname(__DIR__)) as $file=>$hash){
        $approved=($cfg['business_approved'][$file]??'')===$hash;
        $body.='<div class="panel"><strong>'.e(basename($file,'.php')).'</strong><p>'.($approved?'Active':'Version à approuver').'</p>';
        if(!$approved){
            $source=file_get_contents(dirname(__DIR__).'/app/McpActions/'.$file);
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
        $body.='<form method="post">'.csrf().'<input type="hidden" name="client" value="'.e($client['client_id']).'"><p>ChatGPT · '.(int)$client['active_tokens'].' jeton(s) actif(s)</p><button name="action" value="ai_revoke" class="secondary">Révoquer</button></form>';
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
        return tipiRpc($id,['protocolVersion'=>in_array($v,['2025-11-25','2025-06-18','2025-03-26'],true)?$v:'2025-11-25','capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'tipi-cms','version'=>TIPI_VERSION],'instructions'=>'Vous pilotez Tipi CMS. Appelez cms_site_info pour connaître les droits actuels. Présentez au membre les possibilités autorisées : créer des articles et pages, gérer le SEO, refaire le design public via cms_get_design/cms_update_design (CSS et dispositions), utiliser et créer des fonctions métier. Pour développer, inspectez app/McpActions et préparez puis validez un patch. Demandez au propriétaire de le valider dans /admin?connections=1 puis d’approuver la version du module. Ne déployez pas directement. Enregistrez les actions avec CGF ActionRegistry et TipiBusinessContext pour SQLite à la demande. Appelez cg_app_catalog puis cg_app_invoke pour les nouveaux outils. Pas de modification du noyau, des secrets ou de commande système. Les permissions réelles priment sur ces instructions.']);
    }
    if($method==='ping')return tipiRpc($id,(object)[]);
    if(in_array($method,['resources/list','prompts/list'],true))return tipiRpc($id,[explode('/',$method)[0]=>[]]);
    $root=dirname(__DIR__);
    $canRead=tipiScope($principal,\CodeGraft\DynamicOAuth::APP_READ);
    $canWrite=tipiScope($principal,\CodeGraft\DynamicOAuth::APP_WRITE);
    $cgRead=tipiScope($principal,\CodeGraft\DynamicOAuth::READ);
    $cgWrite=tipiScope($principal,\CodeGraft\DynamicOAuth::WRITE);
    $devDefs=array_values(array_filter(\CodeGraft\Mcp::definitions(),static fn($t)=>in_array($t['name'],['cg_capabilities','cg_list_files','cg_search','cg_project_map','cg_read_files','cg_prepare_patch','cg_prepare_diff','cg_validate_patch','cg_history'],true)));
    if($method==='tools/list'){
        $defs=($cgRead||$cgWrite)&&($cfg['ai_profile']??'read')==='build'?$devDefs:[];
        if($canRead){
            $defs=array_merge($defs,mcpTools(),tipiBridgeDefinitions());
            try{$defs=array_merge($defs,tipiRegistry($root,$data,$cfg)->definitions());}
            catch(Throwable $ex){error_log('Tipi business registry unavailable: '.get_class($ex));}
        }
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
    if(str_starts_with($name,'cms_')){
        if(!$canRead)return tipiToolError($id,'Scope app:read requis');
        if(in_array($name,['cms_save_draft','cms_publish','cms_update_design'],true)&&!$canWrite)return tipiToolError($id,'Scope app:write requis');
        // OAuth uses the same separate editorial write/publication switches as native MCP.
        try{return tipiToolResult($id,mcpCall($data,$cfg,$name,$args));}
        catch(Throwable $ex){return tipiToolError($id,$ex instanceof RuntimeException&&$ex->getCode()<500?$ex->getMessage():'Action éditoriale indisponible');}
    }
    if(($cfg['ai_profile']??'read')!=='build'||!in_array($name,array_column($devDefs,'name'),true)||(!$cgRead&&!$cgWrite))return tipiRpcError($id,-32602,'Unknown or unauthorized tool');
    // Restrict actual CodeGraft path permissions, even if the owner sets a broader allowlist.
    $policy=$system['vault']->policy();
    foreach(array_merge($policy['read_dirs']??[],$policy['write_dirs']??[]) as $dir)
        if(!is_string($dir)||!preg_match('~^app/McpActions(?:/[A-Za-z0-9_-]+)*$~D',$dir))
            return tipiToolError($id,'Limiter les dossiers CodeGraft à app/McpActions dans son administration.');
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
    if($path==='/codegraft/standalone/admin.php'){
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);return;}
        header('Location: /admin?connections=1',true,303);return;
    }
    require __DIR__.'/codegraft/standalone/bootstrap.php';
    $oauth=new \CodeGraft\OAuthHttp($codegraft['vault']);
    $path=rtrim($path,'/');
    $routes=['/.well-known/oauth-authorization-server'=>'metadata','/.well-known/oauth-protected-resource'=>'protectedResource','/.well-known/oauth-protected-resource/codegraft-api'=>'protectedResource','/codegraft-api/oauth/register'=>'register','/codegraft-api/oauth/authorize'=>'authorize','/codegraft-api/oauth/token'=>'token'];
    if(isset($routes[$path])){
        $method=$_SERVER['REQUEST_METHOD']??'GET';$action=$routes[$path];
        if(!in_array($method,match($action){'register','token'=>['POST'],'authorize'=>['GET','POST'],default=>['GET']},true)){http_response_code(405);return;}
        if($action==='authorize'){
            sessionStart();
            // Only the CMS login is authoritative; old standalone cookies are ignored.
            $_SESSION['cg_authenticated']=!empty($_SESSION['admin']);
            $_SESSION['cg_csrf']=$_SESSION['csrf'];
            // OAuth uses the same browser session, including CSRF consent.
            header('Referrer-Policy: same-origin');
        }
        $oauth->$action();return;
    }
    $auth=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    $principal=null;
    if(preg_match('~^Bearer (\S+)$~D',$auth,$m))$principal=(new \CodeGraft\DynamicOAuth($codegraft['vault']))->principal($m[1]);
    if($principal===null){
        header('WWW-Authenticate: Bearer resource_metadata="'.$cfg['url'].'/.well-known/oauth-protected-resource/codegraft-api", scope="codegraft:read codegraft:write app:read app:write"');
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
