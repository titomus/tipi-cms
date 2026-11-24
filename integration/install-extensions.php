<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||$argc!==2)exit(1);
$root=realpath($argv[1]);
if(!$root||!is_file($root.'/public/index.php')||is_link($root.'/tipi-system'))throw new RuntimeException('Installation invalide');
$system=$root.'/tipi-system';
$dist=dirname(__DIR__);
$dirs=['app/McpActions','app/Controllers','app/Views','app/Services','app/Routes','app/Components','themes/layouts','themes/templates','themes/components','assets/css','assets/js'];
foreach($dirs as $dir){
    $path=$root;
    foreach(explode('/',$dir) as $part){
        $path.='/'.$part;
        if(is_link($path)||(!is_dir($path)&&!mkdir($path,0700)&&!is_dir($path)))throw new RuntimeException('Dossier extension invalide : '.$dir);
    }
}
$examples=['app/McpActions/Contacts.php'=>'Contacts.php','app/Controllers/Contacts.php'=>'ContactsController.php','app/Views/Contacts.php'=>'ContactsView.php','app/Routes/contacts.json'=>'contacts-route.json'];
$manifest=[];
foreach($examples as $path=>$src){
    $source=$dist.'/examples/'.$src;$dest=$root.'/'.$path;
    if(is_link($dest))throw new RuntimeException('Lien symbolique interdit : '.$path);
    $bytes=file_get_contents($source);if(!is_string($bytes))throw new RuntimeException('Exemple absent');
    $manifest[$path]=hash('sha256',$bytes);
    if(!file_exists($dest)){
        $h=fopen($dest,'x');if(!$h)throw new RuntimeException('Création impossible : '.$path);
        try{if(fwrite($h,$bytes)!==strlen($bytes))throw new RuntimeException('Copie incomplète');}finally{fclose($h);}
        chmod($dest,0600);
    }
}
foreach(['developer-guide.md'=>file_get_contents($dist.'/integration/developer-guide.md'),'contacts-manifest.json'=>json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)] as $name=>$bytes){
    $dest=$system.'/'.$name;
    if(is_link($dest)||!is_string($bytes))throw new RuntimeException('Fichier système invalide');
    $tmp=tempnam($system,'.tipi-');
    if($tmp===false)throw new RuntimeException('Stockage indisponible');
    try{if(file_put_contents($tmp,$bytes)!==strlen($bytes)||!rename($tmp,$dest))throw new RuntimeException('Copie impossible');chmod($dest,0600);}finally{if(is_file($tmp))unlink($tmp);}
}
echo "Dossiers et exemple Contacts prêts, aucun fichier métier existant écrasé.\n";
