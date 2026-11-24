<?php
declare(strict_types=1);

/** Example of a private business module. Registration never opens SQLite. */
return static function(\CGF\ActionRegistry $registry, TipiBusinessContext $context): void {
    $schema=static fn(array $p,array $r=[])=>['type'=>'object','properties'=>$p,'required'=>$r,'additionalProperties'=>false];
    $id=['type'=>'integer','minimum'=>1];
    $name=['type'=>'string','minLength'=>1,'maxLength'=>150];
    $email=['type'=>'string','format'=>'email','maxLength'=>254];
    $note=['type'=>'string','maxLength'=>2000];
    $table=static function()use($context): \CGF\Database {
        $db=$context->database('contacts');
        $db->execute('CREATE TABLE IF NOT EXISTS contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, note TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        return $db;
    };
    $registry->register('contact_list','Lister les contacts privés (maximum 100).','read',$schema(['limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100]]),
        static function(array $args)use($context):array{
            if(!$context->existing('contacts'))return ['items'=>[]];
            $db=$context->database('contacts');return ['items'=>$db->rows('SELECT id,name,email,note,created_at,updated_at FROM contacts ORDER BY id DESC LIMIT '.(int)($args['limit']??50))];
        });
    $registry->register('contact_create','Ajouter un contact au carnet privé.','write',$schema(['name'=>$name,'email'=>$email,'note'=>$note],['name','email']),
        static function(array $args)use($table):array{
            $db=$table();return $db->transaction(static function(PDO $pdo)use($args):array{
                $q=$pdo->prepare('INSERT INTO contacts(name,email,note,created_at,updated_at) VALUES(?,?,?,?,?)');
                $now=gmdate(DATE_ATOM);$q->execute([$args['name'],$args['email'],$args['note']??'',$now,$now]);
                return ['id'=>(int)$pdo->lastInsertId(),'created'=>true];
            });
        });
    $registry->register('contact_update','Modifier un contact existant.','write',$schema(['id'=>$id,'name'=>$name,'email'=>$email,'note'=>$note],['id','name','email']),
        static function(array $args)use($context):array{
            if(!$context->existing('contacts'))throw new \CGF\HttpError(404,'Contact introuvable');
            $db=$context->database('contacts');$n=$db->execute('UPDATE contacts SET name=?,email=?,note=?,updated_at=? WHERE id=?',[$args['name'],$args['email'],$args['note']??'',gmdate(DATE_ATOM),$args['id']]);
            if(!$n)throw new \CGF\HttpError(404,'Contact introuvable');return ['id'=>$args['id'],'updated'=>true];
        });
};
