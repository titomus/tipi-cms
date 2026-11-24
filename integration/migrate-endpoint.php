<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||$argc!==3)exit(1);
$root=realpath($argv[1]);$backup=realpath($argv[2]);
if(!$root||!$backup||!is_file($root.'/tipi-system/codegraft/standalone/config.php'))throw new RuntimeException('Installation invalide');
require $root.'/tipi-system/codegraft/standalone/bootstrap.php';
$vault=$codegraft['vault'];
$vault->locked(function()use($vault,$backup){
    $endpoint=$vault->json('endpoint.json');$issuer=$endpoint['oauth_issuer']??'';
    if(!is_string($issuer)||!preg_match('~^https://[a-z0-9][a-z0-9.-]+$~D',$issuer))throw new RuntimeException('Origine OAuth invalide');
    $old=$endpoint['oauth_resource']??'';
    if(!in_array($old,[$issuer.'/codegraft-api/',$issuer.'/mcp',$issuer.'/mcp/'],true))throw new RuntimeException('Ressource OAuth inattendue');
    if(file_put_contents($backup.'/endpoint.json',json_encode($endpoint,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Sauvegarde endpoint impossible');
    chmod($backup.'/endpoint.json',0600);
    $endpoint['path']='/mcp/';$endpoint['oauth_resource']=$issuer.'/mcp';$endpoint['oauth_admin_path']='/admin?connections=1';
    $vault->put('endpoint.json',$endpoint);
    if($old!==$endpoint['oauth_resource'])echo "Nouvelle ressource OAuth /mcp. Reconnecter les clients utilisant l'ancienne ressource.\n";
});
