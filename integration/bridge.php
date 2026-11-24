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
function tipiBridgeDispatch(array $request,array $principal,array $system,string $data,array $cfg): ?array {
    $id=$request['id']??null;$method=$request['method']??null;
    if(($request['jsonrpc']??null)!=='2.0'||!is_string($method)||($id!==null&&!is_int($id)&&!is_string($id)))return tipiRpcError(null,-32600,'Invalid request');
    if(!array_key_exists('id',$request))return null;
    $p=$request['params']??[];
    if(!is_array($p))return tipiRpcError($id,-32602,'Invalid params');
    if($method==='initialize'){
        $v=$p['protocolVersion']??'2025-11-25';
        return tipiRpc($id,['protocolVersion'=>in_array($v,['2025-11-25','2025-06-18','2025-03-26'],true)?$v:'2025-11-25','capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'tipi-cms-codegraft','version'=>TIPI_VERSION]]);
    }
    if($method==='ping')return tipiRpc($id,(object)[]);
    if(in_array($method,['resources/list','prompts/list'],true))return tipiRpc($id,[explode('/',$method)[0]=>[]]);
    $root=dirname(__DIR__);
    $canRead=tipiScope($principal,\CodeGraft\DynamicOAuth::APP_READ);
    $canWrite=tipiScope($principal,\CodeGraft\DynamicOAuth::APP_WRITE);
    $cgRead=tipiScope($principal,\CodeGraft\DynamicOAuth::READ);
    $cgWrite=tipiScope($principal,\CodeGraft\DynamicOAuth::WRITE);
    $devDefs=array_values(array_filter(\CodeGraft\Mcp::definitions(),static fn($t)=>!str_starts_with($t['name'],'cg_command_')));
    if($method==='tools/list'){
        $defs=$cgRead||$cgWrite?$devDefs:[];
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
        if(in_array($name,['cms_save_draft','cms_publish'],true)&&!$canWrite)return tipiToolError($id,'Scope app:write requis');
        // OAuth uses the same separate editorial write/publication switches as native MCP.
        try{return tipiToolResult($id,mcpCall($data,$cfg,$name,$args));}
        catch(Throwable $ex){return tipiToolError($id,$ex instanceof RuntimeException&&$ex->getCode()<500?$ex->getMessage():'Action éditoriale indisponible');}
    }
    if(!in_array($name,array_column($devDefs,'name'),true)||(!$cgRead&&!$cgWrite))return tipiRpcError($id,-32602,'Unknown or unauthorized tool');
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
        $_SERVER['SCRIPT_NAME']=$path;
        // The upstream admin emits no-referrer. Browsers may then send Origin: null
        // for its same-origin form POST, which our strict origin check rejects.
        // Buffer the response so we can set same-origin before headers are sent.
        // Cross-origin referrers stay hidden; HTTPS, Origin and CSRF checks remain.
        ob_start();
        try {
            require __DIR__.'/codegraft/standalone/admin.php';
            header('Referrer-Policy: same-origin',true);
        } finally { ob_end_flush(); }
        return;
    }
    require __DIR__.'/codegraft/standalone/bootstrap.php';
    $oauth=new \CodeGraft\OAuthHttp($codegraft['vault']);
    $path=rtrim($path,'/');
    $routes=['/.well-known/oauth-authorization-server'=>'metadata','/.well-known/oauth-protected-resource'=>'protectedResource','/.well-known/oauth-protected-resource/codegraft-api'=>'protectedResource','/codegraft-api/oauth/register'=>'register','/codegraft-api/oauth/authorize'=>'authorize','/codegraft-api/oauth/token'=>'token'];
    if(isset($routes[$path])){
        $method=$_SERVER['REQUEST_METHOD']??'GET';$action=$routes[$path];
        if(!in_array($method,match($action){'register','token'=>['POST'],'authorize'=>['GET','POST'],default=>['GET']},true)){http_response_code(405);return;}
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
