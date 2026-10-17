<?php
if (!isset($m,$e,$t,$token)) { http_response_code(404);exit; }
$tabs=['catalogue','installed','history','diagnostic','settings','support','suggestion','newsletter'];
$input=static function($name,$value) use ($e) { return '<input type="hidden" name="'.$e($name).'" value="'.$e($value).'">'; };
$csrf=$input('_token',$token);$links=$catalogue['manifest']['links'];
$language=$request->query->get('language',$locale);if (!in_array($language,['fr','en'],true)) { $language=$locale; }
?>
<section class="pa-md" aria-label="<?= $e($t('title')) ?>">
<ul class="nav nav-tabs mb-md" role="navigation" aria-label="<?= $e($t('navigation')) ?>">
<?php foreach ($tabs as $item): if ($item==='settings' && !$m->access->can('configure')) { continue; } ?>
<li class="<?= $tab===$item ? 'active' : '' ?>"><a href="<?= $e($url('wao_manager_index',['tab'=>$item])) ?>"><?= $e($t($item)) ?></a></li>
<?php endforeach ?></ul>
<?php if ($notice): ?><div class="alert alert-info" role="status"><?= $e($t($notice)) ?></div><?php endif ?>
<?php if (!$catalogue['verified']): ?><div class="alert alert-warning"><?= $e($t('bootstrap_notice')) ?></div>
<?php elseif (!$catalogue['fresh']): ?><div class="alert alert-warning"><?= $e($t('expired_notice')) ?></div><?php endif ?>
<?php if (($state['notifications'] ?? true) && $tab!=='history'):
 $pending=count(array_filter($state['jobs'],static function($j) { return in_array($j['status'],['queued','running','recovery_required'],true); }));
 if ($pending): ?><div class="alert alert-info"><a href="<?= $e($url('wao_manager_index',['tab'=>'history'])) ?>"><?= $e($t('operations_attention')) ?> : <?= $e($pending) ?></a></div><?php endif;endif ?>
<p class="text-muted"><?= $e($t('last_sync')) ?> : <?= $catalogue['synced_at'] ? $e(gmdate('Y-m-d H:i:s',$catalogue['synced_at']).' UTC') : $e($t('never')) ?></p>
<?php if ($tab==='wizard' && $m->access->can('configure')): ?>
<div class="panel panel-default pa-md">
<?php if ($state['wizard']===0): ?>
<p class="text-muted">1 / 2</p><h3><?= $e($t('wizard_telemetry_title')) ?></h3><p><?= $e($t('telemetry_explanation')) ?></p>
<form method="post" action="<?= $e($url('wao_manager_action')) ?>"><?= $csrf.$input('action','wizard_consent') ?>
<div class="radio"><label><input type="radio" name="consent" value="yes" required> <?= $e($t('consent_yes')) ?></label></div>
<div class="radio"><label><input type="radio" name="consent" value="no" required> <?= $e($t('consent_no')) ?></label></div>
<button type="submit" class="btn btn-primary"><?= $e($t('continue')) ?></button></form>
<form class="mt-sm" method="post" action="<?= $e($url('wao_manager_action')) ?>"><?= $csrf.$input('action','wizard_consent').$input('consent','skip') ?><button class="btn btn-default" type="submit"><?= $e($t('skip')) ?></button></form>
<?php else: ?>
<p class="text-muted">2 / 2</p><h3><?= $e($t('wizard_newsletter_title')) ?></h3>
<?php require __DIR__.'/newsletter.php'; ?>
<form method="post" action="<?= $e($url('wao_manager_action')) ?>"><?= $csrf.$input('action','wizard_finish') ?><button type="submit" class="btn btn-default"><?= $e($t('skip')) ?></button><button name="done" value="1" type="submit" class="btn btn-primary"><?= $e($t('catalogue')) ?></button></form>
<?php endif ?>
<p class="mt-md"><a href="<?= $e($url('wao_manager_index',['tab'=>'catalogue'])) ?>"><?= $e($t('browse_now')) ?></a></p></div>
<?php elseif ($tab==='catalogue'): ?>
<form method="get" class="panel panel-default pa-md" action="<?= $e($url('wao_manager_index')) ?>"><?= $input('tab','catalogue') ?>
<div class="form-group"><label for="wao-search"><?= $e($t('search')) ?></label><input class="form-control" id="wao-search" name="q" maxlength="100" value="<?= $e($request->query->get('q','')) ?>"></div>
<div class="form-group"><label for="wao-category"><?= $e($t('category')) ?></label><select class="form-control" id="wao-category" name="category"><option value=""><?= $e($t('all')) ?></option><?php $cats=array_unique(array_column($catalogue['manifest']['plugins'],'category'));foreach ($cats as $category): ?><option <?= $request->query->get('category')===$category ? 'selected' : '' ?>><?= $e($category) ?></option><?php endforeach ?></select></div><button class="btn btn-default"><?= $e($t('search')) ?></button></form>
<?php $shown=0;foreach ($catalogue['manifest']['plugins'] as $plugin):
 $search=(string)$request->query->get('q','');if ($search!=='' && stripos($plugin['name'].' '.$plugin['description'][$locale],$search)===false) { continue; }
 if ($request->query->get('category','')!=='' && $request->query->get('category')!==$plugin['category']) { continue; }
 $release=null;foreach ($plugin['releases'] as $r) { if ($r['version']===$plugin['recommended'] && $m->compatibility->evaluate($plugin,$r,$env)==='certified' && $r['rollout']['state']==='active') { $release=$r; } }
 if (!$release || !$catalogue['fresh']) { continue; }++$shown;
 $installed=$env['packages'][$plugin['package']] ?? null;$operation=$installed ? 'update' : 'install';
?>
<article class="panel panel-default pa-md"><h3><?= $e($plugin['name']) ?></h3><p><?= $e($plugin['description'][$locale]) ?></p>
<p><span class="label label-success"><?= $e($t('certified')) ?></span> <?= $e($release['version']) ?> · <?= $e($plugin['category']) ?></p>
<p class="text-muted"><?= $e($t('certification_date')) ?> : <?php $dates=array_column($release['certifications'],'qualified_at'); ?><?= $dates ? $e(gmdate('Y-m-d',max($dates))) : '—' ?></p>
<ul><?php foreach ($plugin['features'] as $feature): ?><li><?= $e($feature[$locale]) ?></li><?php endforeach ?></ul>
<?php foreach ($plugin['screenshots'] as $screenshot): ?><a href="<?= $e($screenshot) ?>" target="_blank" rel="noopener noreferrer"><?= $e($t('screenshot')) ?></a><?php endforeach ?>
<p><a href="<?= $e($plugin['documentation'][$locale]) ?>" target="_blank" rel="noopener noreferrer"><?= $e($t('documentation')) ?></a> · <a href="<?= $e($url('wao_manager_index',['tab'=>'support','plugin'=>$plugin['id']])) ?>"><?= $e($t('support')) ?></a></p>
<?php if (($plugin['adoption']['representative'] ?? false) && isset($plugin['adoption']['observed_active'],$plugin['adoption']['successful_operations'])): ?><p><?= $e($t('observed_active')) ?> : <?= $e($plugin['adoption']['observed_active']) ?> · <?= $e($t('successful_operations')) ?> : <?= $e($plugin['adoption']['successful_operations']) ?><br><small><?= $e($t('adoption_scope')) ?></small></p><?php endif ?>
<?php if ($m->access->can($operation) && (!$installed || version_compare($release['version'],ltrim($installed,'v'),'>'))): ?>
<form method="post" action="<?= $e($url('wao_manager_action')) ?>"><?= $csrf.$input('action',$operation).$input('plugin',$plugin['id']).$input('version',$release['version']).$input('idempotency_key',bin2hex(random_bytes(16))) ?><button class="btn btn-primary" type="submit" <?= in_array(false,$diagnostic,true) ? 'disabled' : '' ?>><?= $e($t($operation)) ?></button></form>
<?php if (in_array(false,$diagnostic,true)): ?><p class="text-muted"><?= $e($t('prerequisites_missing')) ?> <a href="<?= $e($url('wao_manager_index',['tab'=>'diagnostic'])) ?>"><?= $e($t('diagnostic')) ?></a></p><?php endif ?>
<?php endif ?></article>
<?php endforeach;if (!$shown): ?><div class="well"><?= $e($t('no_certified')) ?> <a href="<?= $e($url('wao_manager_index',['tab'=>'diagnostic'])) ?>"><?= $e($t('diagnostic')) ?></a></div><?php endif ?>
<?php elseif ($tab==='installed'): ?>
<table class="table table-striped"><thead><tr><?php foreach (['plugin','installed_version','available_version','health','updated_at'] as $key): ?><th><?= $e($t($key)) ?></th><?php endforeach ?></tr></thead><tbody>
<?php foreach ($inventory as $p): ?><tr><td><?= $e($p['name']) ?><p><a href="<?= $e($url('wao_manager_index',['tab'=>'support','plugin'=>$p['id']])) ?>"><?= $e($t('support')) ?></a></p></td><td><?= $e($p['version']) ?></td><td><?= $e($p['available'] ?: '—') ?>
<?php if ($catalogue['fresh'] && $m->access->can('update') && $p['version']!=='unknown' && $p['available'] && version_compare($p['available'],ltrim($p['version'],'v'),'>')):
 foreach ($catalogue['manifest']['plugins'] as $approved) { if ($approved['id']!==$p['id']) { continue; }
 foreach ($approved['releases'] as $candidate) { if ($candidate['version']!==$p['available'] || $candidate['rollout']['state']!=='active' || $m->compatibility->evaluate($approved,$candidate,$env)!=='certified') { continue; } ?>
 <form method="post" action="<?= $e($url('wao_manager_action')) ?>"><?= $csrf.$input('action','update').$input('plugin',$p['id']).$input('version',$candidate['version']).$input('idempotency_key',bin2hex(random_bytes(16))) ?><button type="submit" class="btn btn-primary" <?= in_array(false,$diagnostic,true) ? 'disabled' : '' ?>><?= $e($t('update')) ?></button></form>
<?php } } endif ?></td><td><?= $e($t($p['health'])) ?><ul><?php foreach ($p['issues'] as $issue): ?><li><?= $e($t('issue.'.$issue)) ?></li><?php endforeach ?></ul></td><td><?= $p['updated_at'] ? $e(gmdate('Y-m-d H:i:s',$p['updated_at']).' UTC') : '—' ?></td></tr><?php endforeach ?></tbody></table>
<?php if (!$inventory): ?><p><?= $e($t('no_installed')) ?></p><?php endif ?>
<?php foreach ($inventory as $p): if ($p['updated_at'] && $p['updated_at']<time()-259200 && !isset($state['feedback'][$p['id']])): ?><p><a href="<?= $e($url('wao_manager_index',['tab'=>'feedback','plugin'=>$p['id']])) ?>"><?= $e($t('feedback_title')) ?> — <?= $e($p['name']) ?></a></p><?php endif;endforeach ?>
<?php elseif ($tab==='history'): ?>
<table class="table table-striped"><thead><tr><?php foreach (['plugin','operation','status','initiator','created_at','duration','error'] as $key): ?><th><?= $e($t($key)) ?></th><?php endforeach ?></tr></thead><tbody><?php foreach (array_reverse($state['jobs']) as $j): ?><tr><td><?= $e($j['plugin'].' '.$j['version']) ?><p><small><?= $e($j['id']) ?></small></p></td><td><?= $e($t($j['operation'])) ?></td><td><?= $e($t('status.'.$j['status'])) ?><p><?= $e($j['phase']) ?></p></td><td><?= $e($j['origin'].' #'.$j['user']) ?></td><td><?= $e(gmdate('Y-m-d H:i:s',$j['created_at']).' UTC') ?></td><td><?= $j['started_at'] ? $e(($j['finished_at'] ?: time())-$j['started_at']).' s' : '—' ?></td><td><?= $e($j['error'] ?: '—') ?><?php if ($j['error']): ?><p><a href="<?= $e($url('wao_manager_index',['tab'=>'support','plugin'=>$j['plugin']])) ?>"><?= $e($t('support')) ?></a></p><p><?= $e($t('recovery_help')) ?></p>
<?php if (in_array($j['status'],['failed','expired','restored'],true) && $m->access->can($j['operation'])): ?><form method="post" action="<?= $e($url('wao_manager_action')) ?>"><?= $csrf.$input('action',$j['operation']).$input('plugin',$j['plugin']).$input('version',$j['version']).$input('idempotency_key',bin2hex(random_bytes(16))) ?><button class="btn btn-default" type="submit"><?= $e($t('retry')) ?></button></form><?php endif ?>
<?php endif ?></td></tr><?php endforeach ?></tbody></table>
<?php elseif ($tab==='diagnostic'): ?>
<h3><?= $e($t('diagnostic_title')) ?></h3><p>Mautic <?= $e($env['mautic']) ?> · PHP <?= $e($env['php']) ?></p>
<table class="table table-striped"><tbody><?php foreach ($diagnostic as $key=>$ok): ?><tr><th><?= $e($t('check.'.$key)) ?></th><td><span class="label <?= $ok ? 'label-success' : 'label-warning' ?>"><?= $e($t($ok ? 'ok' : 'missing')) ?></span></td><td><?php if (!$ok): ?><?= $e($t('help.'.$key)) ?> <a href="<?= $e($url('wao_manager_index',['tab'=>'support'])) ?>"><?= $e($t('support')) ?></a><?php endif ?></td></tr><?php endforeach ?></tbody></table>
<p><?= $e($t('cron_help')) ?></p><pre><?= $diagnostic['native_console'] ? $e('php '. $m->env->console().' wao:plugins:process-queue --env=prod --no-debug') : $e($t('help.native_console')) ?></pre>
<p><a class="btn btn-default" href="<?= $e($url('wao_manager_download')) ?>"><?= $e($t('download')) ?></a></p>
<h3><?= $e($t('compatibility')) ?></h3><table class="table table-striped"><thead><tr><th><?= $e($t('plugin')) ?></th><th><?= $e($t('available_version')) ?></th><th><?= $e($t('status')) ?></th><th><?= $e($t('description')) ?></th></tr></thead><tbody>
<?php foreach ($catalogue['manifest']['plugins'] as $p): if (!$p['releases']): ?><tr><td><?= $e($p['name']) ?></td><td>—</td><td><?= $e($t('unverified')) ?></td><td><?= $e($p['description'][$locale]) ?></td></tr><?php else: foreach ($p['releases'] as $r): ?><tr><td><?= $e($p['name']) ?></td><td><?= $e($r['version']) ?></td><td><?= $e($t($m->compatibility->evaluate($p,$r,$env))) ?></td><td><?= $e($p['description'][$locale]) ?></td></tr><?php endforeach;endif;endforeach ?></tbody></table>
<?php elseif ($tab==='settings' && $m->access->can('configure')): ?>
<form method="post" action="<?= $e($url('wao_manager_action')) ?>" class="panel panel-default pa-md"><?= $csrf.$input('action','settings') ?>
<h3><?= $e($t('telemetry')) ?></h3><p><?= $e($t('telemetry_explanation')) ?></p>
<div class="radio"><label><input type="radio" name="consent" value="yes" <?= $state['consent'] ? 'checked' : '' ?>> <?= $e($t('consent_yes')) ?></label></div>
<div class="radio"><label><input type="radio" name="consent" value="no" <?= !$state['consent'] ? 'checked' : '' ?>> <?= $e($t('consent_no')) ?></label></div>
<div class="checkbox"><label><input type="checkbox" name="auto_patch" value="1" <?= $state['auto_patch'] ? 'checked' : '' ?>> <?= $e($t('auto_patch')) ?></label></div>
<div class="checkbox"><label><input type="checkbox" name="notifications" value="1" <?= ($state['notifications'] ?? true) ? 'checked' : '' ?>> <?= $e($t('notifications')) ?></label></div>
<div class="form-group"><label for="wao-frequency"><?= $e($t('frequency')) ?></label><input class="form-control" type="number" min="1" max="168" name="check_hours" id="wao-frequency" value="<?= $e($state['check_hours']) ?>"></div><button type="submit" class="btn btn-primary"><?= $e($t('save')) ?></button></form>
<form method="post" action="<?= $e($url('wao_manager_action')) ?>"><?= $csrf.$input('action','wizard_reopen') ?><button type="submit" class="btn btn-default"><?= $e($t('reopen_wizard')) ?></button></form>
<p class="mt-md"><a href="<?= $e($url('wao_manager_index',['tab'=>'support','kind'=>'erasure'])) ?>"><?= $e($t('erasure')) ?></a></p><p>Plugin Manager 0.1.0-dev · <?= $e($t('development_status')) ?></p>
<?php elseif ($tab==='newsletter'): ?><h3><?= $e($t('wizard_newsletter_title')) ?></h3><?php require __DIR__.'/newsletter.php'; ?>
<?php elseif (in_array($tab,['support','suggestion','feedback'],true)): ?>
<?php if ($preview): ?><div class="panel panel-default pa-md"><h3><?= $e($t('preview')) ?></h3><p><?= $e($t('recipient')) ?> : <?= $e($preview['to']) ?></p><pre><?= $e(json_encode(array_diff_key($preview,['_expires'=>true]),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre><form method="post" action="<?= $e($url('wao_manager_community')) ?>"><?= $csrf.$input('confirm','1') ?><button type="submit" class="btn btn-primary"><?= $e($t('send')) ?></button></form></div><?php endif ?>
<?php $kind=$tab==='support' ? ($request->query->get('kind')==='erasure' ? 'erasure' : 'support') : $tab; ?>
<h3><?= $e($t($tab==='suggestion' ? 'suggestion_title' : ($tab==='feedback' ? 'feedback_title' : 'support'))) ?></h3>
<form method="post" action="<?= $e($url('wao_manager_community')) ?>" class="panel panel-default pa-md"><?= $csrf.$input('kind',$kind) ?>
<?php foreach (['name','email','subject','plugin'] as $field): ?><div class="form-group"><label for="wao-<?= $e($field) ?>"><?= $e($t($field)) ?> <?= $field==='email' && $tab==='suggestion' ? $e($t('optional')) : '' ?></label><input class="form-control" id="wao-<?= $e($field) ?>" name="<?= $e($field) ?>" type="<?= $field==='email' ? 'email' : 'text' ?>" maxlength="<?= $field==='email' ? '254' : ($field==='plugin' ? '64' : '120') ?>" value="<?= $field==='plugin' ? $e($request->query->get('plugin','')) : '' ?>" <?= $field==='email' && $tab!=='suggestion' ? 'required' : '' ?>></div><?php endforeach ?>
<?php if ($tab==='suggestion'): ?><div class="form-group"><label for="wao-suggest-category"><?= $e($t('category')) ?></label><select name="category" id="wao-suggest-category" class="form-control"><?php foreach (['usability','automation','reporting','api','contacts','other'] as $key): ?><option value="<?= $e($key) ?>"><?= $e($t('category.'.$key)) ?></option><?php endforeach ?></select></div><?php endif ?>
<?php if ($tab==='feedback'): ?><div class="form-group"><label for="wao-answer"><?= $e($t('feedback_title')) ?></label><select name="answer" id="wao-answer" class="form-control"><?php foreach (['yes','partly','no'] as $answer): ?><option value="<?= $e($answer) ?>"><?= $e($t($answer)) ?></option><?php endforeach ?></select></div><?php endif ?>
<?php foreach (array_merge(['description'],$tab==='suggestion' ? ['desired','workaround'] : []) as $field): ?><div class="form-group"><label for="wao-<?= $e($field) ?>"><?= $e($t($field)) ?></label><textarea class="form-control" id="wao-<?= $e($field) ?>" name="<?= $e($field) ?>" maxlength="<?= $field==='description' ? '6000' : '3000' ?>" rows="4" <?= $field==='description' ? 'required' : '' ?>></textarea></div><?php endforeach ?>
<div class="checkbox"><label><input name="diagnostic" value="1" type="checkbox"> <?= $e($t('attach_diagnostic')) ?></label></div><p><?= $e($t('preview_explanation')) ?></p><button type="submit" class="btn btn-default"><?= $e($t('preview')) ?></button></form>
<p><a href="<?= $e($url('wao_manager_download')) ?>"><?= $e($t('download')) ?></a> · support@webanyone.net</p>
<?php if ($tab==='suggestion'): ?><p><?= $e($t('third_party')) ?></p><?php endif ?>
<?php endif ?>
<aside class="well mt-md"><p><?= $e($t('commercial')) ?></p><a href="<?= $e($links['support']) ?>" target="_blank" rel="noopener noreferrer"><?= $e($t('support_offer')) ?></a> · <a href="<?= $e($links['service']) ?>" target="_blank" rel="noopener noreferrer"><?= $e($t('service_offer')) ?></a></aside>
</section>
