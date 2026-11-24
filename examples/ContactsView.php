<?php
$edit=$vars['edit']; ?>
<h1>Contacts</h1>
<p>Votre carnet privé. Les contacts enregistrés ici sont également accessibles à votre assistant IA autorisé.</p>
<?php if($vars['error']!==''): ?><p role="alert"><?=e($vars['error'])?></p><?php endif; ?>
<div class="panel">
<h2><?=$edit?'Modifier un contact':'Nouveau contact'?></h2>
<form method="post" action="<?=e($ctx->path)?>">
<?=$ctx->csrf()?>
<input type="hidden" name="id" value="<?=e((string)($edit['id']??''))?>">
<label>Nom<input required maxlength="150" name="name" value="<?=e($edit['name']??'')?>"></label>
<label>E-mail<input required type="email" maxlength="254" name="email" value="<?=e($edit['email']??'')?>"></label>
<label>Notes<textarea name="note" maxlength="2000" style="min-height:90px"><?=e($edit['note']??'')?></textarea></label>
<button>Enregistrer</button><a href="<?=e($ctx->path)?>">Annuler</a>
</form></div>
<h2>Votre carnet</h2>
<form method="get" action="<?=e($ctx->path)?>"><label>Rechercher<input name="q" value="<?=e($vars['q'])?>"></label><button>Rechercher</button></form>
<div class="md-table-wrap"><table><thead><tr><th>Nom</th><th>E-mail</th><th>Notes</th><th></th></tr></thead><tbody>
<?php foreach($vars['items'] as $item): ?>
<tr><td><?=e($item['name'])?></td><td><?=e($item['email'])?></td><td><?=e($item['note'])?></td><td><a href="<?=e($ctx->path)?>?edit=<?=(int)$item['id']?>">Modifier</a></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if(!$vars['items']): ?><p>Aucun contact trouvé.</p><?php endif; ?>
