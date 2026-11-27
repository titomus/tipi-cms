<?php
declare(strict_types=1);
/** Release-time build. No shell or package manager is needed on a member's host. */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);
require $root.'/scripts/verify-codegraft.php';
$root=dirname(__DIR__);
$manifest=json_decode((string)file_get_contents($root.'/bundled/manifest.json'),true,32,JSON_THROW_ON_ERROR);
$files=[];
$add=static function(string $dest,string $src)use(&$files,$root): void {
    $path=$root.'/'.$src;
    if(is_link($path)||!is_file($path))throw new RuntimeException('Missing source: '.$src);
    $bytes=file_get_contents($path);
    if($bytes===false)throw new RuntimeException('Unreadable source: '.$src);
    $files[$dest]=base64_encode($bytes);
};
foreach($manifest['files'] as $path=>$hash)$add('tipi-system/'.$path,'bundled/'.$path);
foreach(['bridge.php','developer-guide.md'] as $path)$add('tipi-system/'.$path,'integration/'.$path);
$add('tipi-system/upstream-manifest.json','bundled/manifest.json');
$examples=['app/McpActions/Contacts.php'=>'Contacts.php','app/Controllers/Contacts.php'=>'ContactsController.php','app/Views/Contacts.php'=>'ContactsView.php','app/Routes/contacts.json'=>'contacts-route.json'];
$exampleHashes=[];
foreach($examples as $dest=>$src){
    $add($dest,'examples/'.$src);
    $exampleHashes[$dest]=hash('sha256',base64_decode($files[$dest],true));
}
$files['tipi-system/contacts-manifest.json']=base64_encode(json_encode($exampleHashes,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$src=(string)file_get_contents($root.'/tipi-install.php');
$anchor="const TIPI_BUNDLE_B64 = '';";
if(substr_count($src,$anchor)!==1)throw new RuntimeException('Bundle marker missing');
$src=str_replace($anchor,"const TIPI_BUNDLE_B64 = '".base64_encode(json_encode($files,JSON_THROW_ON_ERROR))."';",$src);
$dest=$argv[1]??$root.'/dist/tipi-install-template.php';
if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0700,true))throw new RuntimeException('Cannot create distribution folder');
if(is_link($dest))throw new RuntimeException('Symlink refused');
$tmp=tempnam(dirname($dest),'.tipi-build-');
if($tmp===false)throw new RuntimeException('Cannot create file');
try{
    if(file_put_contents($tmp,$src)!==strlen($src)||!rename($tmp,$dest))throw new RuntimeException('Cannot publish distribution');
    chmod($dest,0600);
}finally{if(is_file($tmp))unlink($tmp);}
echo "Distribution template: ".$dest.PHP_EOL;
