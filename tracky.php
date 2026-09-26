<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();

$user=current_user();$pdo=db();
if(!$pdo){http_response_code(503);exit('Database unavailable.');}
$trackyStatus=vp3_plugin_effective_state_v360($pdo,$user,'tracky');
if(empty($trackyStatus['enabled'])){flash('error','Enable the Tracky plugin before opening Physical Awareness.');redirect(url('/plugins.php'));}
if(!tracky_cloud_v270_schema_ready($pdo)){
    if(user_has_role('admin',$user))redirect(url('/upgrade.php'));
    http_response_code(503);exit('Tracky Cloud requires a database upgrade.');
}
$userId=(int)$user['id'];
$sites=tracky_cloud_v270_sites($pdo,$userId);
$selected=trim((string)($_GET['site']??''));
if($selected===''&&!empty($sites))$selected=(string)$sites[0]['site_id'];
$context=$selected!==''?tracky_cloud_v270_current_context($pdo,$userId,$selected):[];
$events=$selected!==''?tracky_cloud_v270_recent_events($pdo,$userId,$selected,25):[];
$world=$selected!==''?tracky_cloud_v270_world_state($pdo,$userId,$selected,100):[];
$memberHeaderUser=$user;$memberHeaderTitle='Tracky';$memberHeaderSubtitle='Physical Awareness · physical_context.v1';$memberHeaderActions='';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f8"><title>VP3 | Tracky</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><style>
.tracky-page{background:#f7f7f8;color:#1d1f23}.tracky-main{min-width:0}.tracky-wrap{max-width:1180px;margin:0 auto;padding:28px 24px 70px}.tracky-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}.card{grid-column:span 12;background:#fff;border:1px solid #e3e4e7;border-radius:18px;padding:22px}.card.half{grid-column:span 6}.muted{color:#6e7177}.status{display:inline-flex;padding:6px 10px;border-radius:999px;background:#eef0f2;font-size:12px;font-weight:800}.status.healthy{background:#eaf7ef}.site-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.site-tabs a{text-decoration:none;color:#1d1f23;border:1px solid #dfe1e4;background:#fff;border-radius:999px;padding:8px 12px;font-weight:700}.site-tabs a.active{background:#1d1f23;color:#fff}.metric{font-size:28px;font-weight:850}.list{display:grid;gap:10px}.row{border-top:1px solid #ececef;padding-top:10px}.row:first-child{border-top:0;padding-top:0}.pill{display:inline-flex;font-size:11px;font-weight:750;padding:4px 7px;border-radius:999px;background:#f0f1f3;margin-right:6px}.empty{padding:18px;border:1px dashed #d9dbe0;border-radius:12px;color:#6e7177}.privacy{background:#f7faf8}.privacy strong{display:block;margin-bottom:5px}@media(max-width:800px){.card.half{grid-column:span 12}}
</style></head><body class="tracky-page"><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='plugins';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main tracky-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="tracky-wrap">
<?php if(empty($sites)): ?><section class="card"><h2>No Tracky site has synchronized yet.</h2><p class="muted">Tracky is enabled in VP3 Cloud. When your paired OTRO HomeServer advertises <code>physical_context.v1</code> and begins governed synchronization, its site will appear here.</p></section><?php else: ?>
<nav class="site-tabs"><?php foreach($sites as $site): ?><a class="<?= $selected===(string)$site['site_id']?'active':'' ?>" href="<?= e(url('/tracky.php?site='.rawurlencode((string)$site['site_id']))) ?>"><?= e((string)($site['label']?:$site['site_id'])) ?></a><?php endforeach; ?></nav>
<?php $site=null;foreach($sites as $candidate)if((string)$candidate['site_id']===$selected){$site=$candidate;break;} ?>
<div class="tracky-grid">
<section class="card"><div style="display:flex;justify-content:space-between;gap:20px;align-items:flex-start;flex-wrap:wrap"><div><div class="muted">Site</div><h1 style="margin:4px 0 6px"><?= e((string)($site['label']??$selected)) ?></h1><div class="muted"><?= e($selected) ?> · <?= e((string)($site['device_id']??'')) ?></div></div><div><span class="status <?= e((string)($site['status']??'')) ?>"><?= e(ucfirst((string)($site['status']??'unknown'))) ?></span><div class="muted" style="margin-top:8px">Protocol <?= e((string)($site['protocol_version']??'')) ?></div></div></div></section>
<section class="card half"><div class="muted">Current room</div><div class="metric"><?= e((string)($context['context']['current_room']??'Unknown')) ?></div><p class="muted">Confidence <?= e(number_format((float)($context['context']['confidence']??0)*100,1)) ?>%</p></section>
<section class="card half"><div class="muted">Cloud projection</div><div class="metric"><?= count($world) ?> facts</div><p class="muted"><?= count($events) ?> recent events loaded · sequence <?= e((string)($site['last_sequence']??0)) ?></p></section>
<section class="card half"><h2>Physical context</h2><?php if(empty($context)): ?><div class="empty">No context snapshot yet.</div><?php else: ?><div class="list"><div class="row"><strong>Present</strong><div class="muted"><?= e(implode(', ',(array)($context['context']['people_present']??[]))?:'No one reported') ?></div></div><div class="row"><strong>Environment</strong><div class="muted"><?= e((string)($context['context']['environment_status']??'unknown')) ?></div></div><div class="row"><strong>Recent changes</strong><div class="muted"><?= e(implode(' · ',(array)($context['context']['recent_changes']??[]))?:'None') ?></div></div><div class="row"><strong>Exceptions</strong><div class="muted"><?= e(implode(' · ',(array)($context['context']['exceptions']??[]))?:'None') ?></div></div></div><?php endif; ?></section>
<section class="card half"><h2>Health & capabilities</h2><div class="list"><div class="row"><strong>Health</strong><pre style="white-space:pre-wrap;margin:6px 0 0"><?= e(json_encode($site['health']??[],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></pre></div><div class="row"><strong>Capabilities</strong><pre style="white-space:pre-wrap;margin:6px 0 0"><?= e(json_encode($site['capabilities']??[],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></pre></div></div></section>
<section class="card"><h2>Recent governed events</h2><?php if(empty($events)): ?><div class="empty">No governed events yet.</div><?php else: ?><div class="list"><?php foreach($events as $event): ?><div class="row"><span class="pill"><?= e((string)$event['event_type']) ?></span><span class="pill"><?= e((string)$event['severity']) ?></span><strong><?= e((string)$event['occurred_at']) ?></strong><div class="muted">Confidence <?= e(number_format((float)$event['confidence']*100,1)) ?>% · <?= !empty($event['is_fresh'])?'fresh':'historical' ?> · <?= e((string)$event['privacy_class']) ?></div></div><?php endforeach; ?></div><?php endif; ?></section>
<section class="card privacy"><strong>Privacy boundary</strong><div class="muted">This Cloud view contains governed physical meaning only. Tracky V2.7 rejects raw frames, video, audio, face embeddings, local filesystem paths and camera source URIs from the Cloud synchronization contract.</div></section>
</div><?php endif; ?>
</div></main></div><script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script></body></html>
