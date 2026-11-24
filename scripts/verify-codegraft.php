<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__).'/bundled';
$manifest=json_decode((string)file_get_contents($root.'/manifest.json'),true,32,JSON_THROW_ON_ERROR);
if(($manifest['commit']??null)!=='e128e4bb045df11111729a4b13724976f40b7cdc'||!is_array($manifest['files']??null)||count($manifest['files'])!==17)throw new RuntimeException('Manifeste CodeGraft invalide');
foreach($manifest['files'] as $path=>$hash){
    if(!preg_match('~^(?:codegraft/(?:src/|standalone/)?|contracts/)[A-Za-z]+\.php$~D',$path)||!preg_match('~^[a-f0-9]{40}$~D',$hash))throw new RuntimeException('Entrée invalide');
    $file=$root.'/'.$path;
    if(is_link($file)||!is_file($file))throw new RuntimeException('Fichier absent ou non sûr : '.$path);
    $bytes=file_get_contents($file);
    if(!is_string($bytes)||!hash_equals($hash,sha1('blob '.strlen($bytes)."\0".$bytes)))throw new RuntimeException('Empreinte différente : '.$path);
}
foreach(['codegraft','contracts'] as $sub){
    if(is_link($root.'/'.$sub))throw new RuntimeException('Lien symbolique interdit');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$sub,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $file){
        if($file->isLink())throw new RuntimeException('Lien symbolique interdit dans le paquet');
        if($file->isDir())continue;
        $relative=substr($file->getPathname(),strlen($root)+1);
        if(!isset($manifest['files'][$relative]))throw new RuntimeException('Fichier non déclaré : '.$relative);
    }
}
echo "CodeGraft embarqué : 17 fichiers vérifiés.\n";
