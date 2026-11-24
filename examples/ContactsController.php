<?php
declare(strict_types=1);
return static function(TipiWebContext $ctx,array $query,array $post): string {
    $error='';
    if($ctx->method==='POST'){
        try{
            $args=['name'=>trim((string)($post['name']??'')),'email'=>trim((string)($post['email']??'')),'note'=>(string)($post['note']??'')];
            if(($post['id']??'')!==''){$args['id']=(int)$post['id'];$ctx->action('contact_update',$args);}
            else $ctx->action('contact_create',$args);
            redirect($ctx->path);
        }catch(\CGF\HttpError $ex){$error=$ex->getMessage();}
    }
    $items=$ctx->action('contact_list',['limit'=>100])['items'];
    $q=trim((string)($query['q']??''));
    $edit=null;foreach($items as $item)if((string)$item['id']===(string)($query['edit']??''))$edit=$item;
    if($q!=='')$items=array_values(array_filter($items,fn($x)=>stripos($x['name'].' '.$x['email'].' '.$x['note'],$q)!==false));
    return $ctx->view('Contacts',['items'=>$items,'q'=>$q,'edit'=>$edit,'error'=>$error]);
};
