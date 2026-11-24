<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
if($argc!==4){fwrite(STDERR,"Usage: init-codegraft.php PROJECT VAULT HTTPS_ORIGIN\n");exit(1);}
[$script,$root,$vault,$url]=$argv;
$root=realpath($root);
if($root===false||!is_dir($root.'/public')||!preg_match('~^https://[a-z0-9][a-z0-9.-]+$~D',$url)||!extension_loaded('pdo_sqlite'))throw new RuntimeException('Projet, HTTPS ou PDO SQLite invalide');
$sys=$root.'/tipi-system';
require $sys.'/codegraft/bootstrap.php';
if(file_exists($sys.'/codegraft/standalone/config.php'))throw new RuntimeException('CodeGraft déjà initialisé');
$password=bin2hex(random_bytes(32)); // Internal owner marker; no separate interactive login.
$cg=codegraft($root,$vault,'generic');
if(is_file($cg['vault']->directory.'/admin.json'))throw new RuntimeException('Coffre existant : ne pas réinitialiser');
$cg['vault']->put('admin.json',['password_hash'=>password_hash($password,PASSWORD_BCRYPT),'created_at'=>gmdate('c')]);
$policy=$cg['vault']->policy();$policy['read_dirs']=['app/McpActions'];$policy['write_dirs']=[];
$cg['vault']->savePolicy($policy);
$cg['vault']->put('endpoint.json',['path'=>'/codegraft-api/','oauth_issuer'=>$url,'oauth_resource'=>$url.'/codegraft-api/','oauth_admin_path'=>'/admin?connections=1','created_at'=>gmdate('c')]);
$path=$sys.'/codegraft/standalone/config.php';
$php="<?php\nreturn ".var_export(['root'=>$root,'vault'=>$cg['vault']->directory,'adapter'=>'generic'],true).";\n";
$handle=fopen($path,'x');if(!$handle)throw new RuntimeException('Configuration impossible');
try{if(fwrite($handle,$php)!==strlen($php))throw new RuntimeException('Configuration incomplète');}finally{fclose($handle);}
chmod($path,0600);
echo "CodeGraft initialisé, tous les droits de développement désactivés.\n";
