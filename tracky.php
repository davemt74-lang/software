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
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Please try again.');redirect(url('/tracky.php'));}
    $action=trim((string)($_POST['action']??''));
    try{
        if($action!=='save_surface_policy')throw new RuntimeException('Unknown Tracky action.');
        $input=[
            'now_enabled'=>isset($_POST['now_enabled']),
            'chat_enabled'=>isset($_POST['chat_enabled']),
            'voice_enabled'=>isset($_POST['voice_enabled']),
            'automation_enabled'=>isset($_POST['automation_enabled']),
            'cross_plugin_access'=>isset($_POST['cross_plugin_access']),
            'cross_plugin_plugins'=>is_array($_POST['cross_plugin_plugins']??null)?$_POST['cross_plugin_plugins']:[],
            'active_perception_enabled'=>isset($_POST['active_perception_enabled']),
            'auto_refresh_stale'=>isset($_POST['auto_refresh_stale']),
            'now_classes'=>is_array($_POST['now_classes']??null)?$_POST['now_classes']:[],
            'chat_classes'=>is_array($_POST['chat_classes']??null)?$_POST['chat_classes']:[],
            'voice_classes'=>is_array($_POST['voice_classes']??null)?$_POST['voice_classes']:[],
            'automation_classes'=>is_array($_POST['automation_classes']??null)?$_POST['automation_classes']:[],
        ];
        tracky_v272_settings_save($pdo,$user,$input);
        flash('notice','Tracky awareness surfaces updated.');
    }catch(Throwable $e){flash('error',$e->getMessage());}
    $site=trim((string)($_POST['site']??''));
    redirect(url('/tracky.php'.($site!==''?'?site='.rawurlencode($site):'')));
}
$surfaceSettings=function_exists('tracky_v272_settings')?tracky_v272_settings($pdo,$userId):[];
$globalVoiceEnabled=function_exists('chat_settings_agent_voice_enabled_v237')?chat_settings_agent_voice_enabled_v237($pdo,$user):false;
$eventClasses=function_exists('tracky_v272_event_classes')?tracky_v272_event_classes():[];
$triggerCatalog=function_exists('tracky_v272_trigger_catalog')?tracky_v272_trigger_catalog():[];
$crossPluginCandidates=[];
if(function_exists('vp3_plugin_catalog_v320')&&function_exists('vp3_plugin_effective_enabled_v360')){
    foreach(vp3_plugin_catalog_v320() as $pluginKey=>$pluginMeta){
        if($pluginKey==='tracky')continue;
        try{$state=vp3_plugin_effective_enabled_v360($pdo,$user,$pluginKey);}catch(Throwable $e){$state=false;}
        if($state)$crossPluginCandidates[$pluginKey]=(string)($pluginMeta['label']??$pluginKey);
    }
}
$sites=tracky_cloud_v270_sites($pdo,$userId);
$selected=trim((string)($_GET['site']??''));
if($selected===''&&!empty($sites))$selected=(string)$sites[0]['site_id'];
$context=$selected!==''?tracky_cloud_v270_current_context($pdo,$userId,$selected):[];
$events=$selected!==''?tracky_cloud_v270_recent_events($pdo,$userId,$selected,25):[];
$world=$selected!==''?tracky_cloud_v270_world_state($pdo,$userId,$selected,100):[];
$selectedEventId=trim((string)($_GET['event']??''));
$selectedEvent=$selected!==''&&$selectedEventId!==''&&function_exists('tracky_v272_event_row')
    ?tracky_v272_event_row($pdo,$userId,$selected,$selectedEventId):null;
$selectedEventCard=$selectedEvent&&function_exists('tracky_v272_card')
    ?tracky_v272_card($pdo,$user,'system',['type'=>'physical_event','id'=>(string)$selectedEvent['id'],'scope'=>'personal'],'expanded')
    :null;
$agentIntegrated=function_exists('tracky_agent_register_cognitive_v271')&&function_exists('tracky_agent_tools_query_v271');
$proactiveIntegrated=function_exists('tracky_v272_observation_now_allowed')&&function_exists('tracky_v272_on_sync');
$joinCapabilities=function_exists('tracky_v273_capabilities')
    ?tracky_v273_capabilities($pdo,$user,false)
    :['available'=>false,'compatible'=>false,'connected'=>false,'reason'=>'v2.73_unavailable','tracky'=>[],'reconciliation'=>[]];
$memberHeaderUser=$user;$memberHeaderTitle='Tracky';$memberHeaderSubtitle='Physical Awareness · physical_context.v1';$memberHeaderActions='';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f8"><title>VP3 | Tracky</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><style>
.tracky-page{background:#f7f7f8;color:#1d1f23}.tracky-main{min-width:0}.tracky-wrap{max-width:1180px;margin:0 auto;padding:28px 24px 70px}.tracky-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}.card{grid-column:span 12;background:#fff;border:1px solid #e3e4e7;border-radius:18px;padding:22px}.card.half{grid-column:span 6}.muted{color:#6e7177}.status{display:inline-flex;padding:6px 10px;border-radius:999px;background:#eef0f2;font-size:12px;font-weight:800}.status.healthy{background:#eaf7ef}.site-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.site-tabs a{text-decoration:none;color:#1d1f23;border:1px solid #dfe1e4;background:#fff;border-radius:999px;padding:8px 12px;font-weight:700}.site-tabs a.active{background:#1d1f23;color:#fff}.metric{font-size:28px;font-weight:850}.list{display:grid;gap:10px}.row{border-top:1px solid #ececef;padding-top:10px}.row:first-child{border-top:0;padding-top:0}.pill{display:inline-flex;font-size:11px;font-weight:750;padding:4px 7px;border-radius:999px;background:#f0f1f3;margin-right:6px}.empty{padding:18px;border:1px dashed #d9dbe0;border-radius:12px;color:#6e7177}.privacy{background:#f7faf8}.privacy strong{display:block;margin-bottom:5px}.policy-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.policy-box{border:1px solid #ececef;border-radius:14px;padding:16px}.policy-box h3{margin:0 0 10px}.checks{display:flex;flex-wrap:wrap;gap:8px 12px}.check{display:flex;gap:7px;align-items:center;font-size:13px}.check input{width:16px;height:16px}.policy-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:16px}.selected-event{border-color:#b9bdc5;box-shadow:0 10px 30px rgba(0,0,0,.05)}.selected-event h2{margin-top:0}.btn-save{border:0;background:#1d1f23;color:#fff;border-radius:11px;padding:10px 15px;font-weight:800;cursor:pointer}@media(max-width:800px){.card.half{grid-column:span 12}.policy-grid{grid-template-columns:1fr}}
</style></head><body class="tracky-page"><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='plugins';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main tracky-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="tracky-wrap">
<section class="card" style="margin-bottom:16px"><h2>Awareness surfaces</h2><p class="muted">Choose where governed Tracky events may appear. VP3's existing attention budget, quiet/focus rules, permissions and global voice setting still apply.</p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_surface_policy"><input type="hidden" name="site" value="<?= e($selected) ?>">
<div class="policy-grid">
<div class="policy-box"><h3>Agent Now</h3><label class="check"><input type="checkbox" name="now_enabled" value="1" <?= !empty($surfaceSettings['now_enabled'])?'checked':'' ?>> Enabled</label><div class="checks" style="margin-top:10px"><?php foreach($eventClasses as $class): ?><label class="check"><input type="checkbox" name="now_classes[]" value="<?= e($class) ?>" <?= in_array($class,(array)($surfaceSettings['now_classes']??[]),true)?'checked':'' ?>><?= e(ucfirst($class)) ?></label><?php endforeach; ?></div></div>
<div class="policy-box"><h3>Proactive Agent Chat</h3><label class="check"><input type="checkbox" name="chat_enabled" value="1" <?= !empty($surfaceSettings['chat_enabled'])?'checked':'' ?>> Enabled</label><div class="checks" style="margin-top:10px"><?php foreach($eventClasses as $class): ?><label class="check"><input type="checkbox" name="chat_classes[]" value="<?= e($class) ?>" <?= in_array($class,(array)($surfaceSettings['chat_classes']??[]),true)?'checked':'' ?>><?= e(ucfirst($class)) ?></label><?php endforeach; ?></div></div>
<div class="policy-box"><h3>Agent voice</h3><label class="check"><input type="checkbox" name="voice_enabled" value="1" <?= !empty($surfaceSettings['voice_enabled'])?'checked':'' ?>> Allow Tracky voice alerts</label><div class="checks" style="margin-top:10px"><?php foreach($eventClasses as $class): ?><label class="check"><input type="checkbox" name="voice_classes[]" value="<?= e($class) ?>" <?= in_array($class,(array)($surfaceSettings['voice_classes']??[]),true)?'checked':'' ?>><?= e(ucfirst($class)) ?></label><?php endforeach; ?></div><p class="muted" style="margin-bottom:0">Global Agent voice: <strong><?= $globalVoiceEnabled?'On':'Off' ?></strong>. Tracky voice is off by default and never bypasses global voice, focus/quiet or interruption rules.</p></div>
<div class="policy-box"><h3>Automation triggers</h3><label class="check"><input type="checkbox" name="automation_enabled" value="1" <?= !empty($surfaceSettings['automation_enabled'])?'checked':'' ?>> Publish governed triggers</label><div class="checks" style="margin-top:10px"><?php foreach($eventClasses as $class): ?><label class="check"><input type="checkbox" name="automation_classes[]" value="<?= e($class) ?>" <?= in_array($class,(array)($surfaceSettings['automation_classes']??[]),true)?'checked':'' ?>><?= e(ucfirst($class)) ?></label><?php endforeach; ?></div><p class="muted" style="margin-bottom:0"><?= count($triggerCatalog) ?> trigger families are available. V2.72 publishes trigger metadata only; it does not execute physical actions.</p></div>
</div>
<div class="policy-actions"><label class="check"><input type="checkbox" name="cross_plugin_access" value="1" <?= !empty($surfaceSettings['cross_plugin_access'])?'checked':'' ?>> Allow selected VP3 plugins to read the governed current physical-context summary</label></div>
<div class="policy-box" style="margin-top:16px"><h3>Active perception</h3>
<label class="check"><input type="checkbox" name="active_perception_enabled" value="1" <?= !empty($surfaceSettings['active_perception_enabled'])?'checked':'' ?>> Allow Agent to request a fresh HomeServer perception check</label>
<label class="check" style="margin-top:8px"><input type="checkbox" name="auto_refresh_stale" value="1" <?= !empty($surfaceSettings['auto_refresh_stale'])?'checked':'' ?>> Automatically refresh stale physical answers when possible</label>
<p class="muted" style="margin-bottom:0">Off by default. Requests are sensor reads only, use the existing v2.4 HomeServer relay, respect local privacy, and never authorize physical device actions.</p>
</div>
<?php if($crossPluginCandidates): ?><div class="checks" style="margin-top:10px"><?php foreach($crossPluginCandidates as $pluginKey=>$pluginLabel): ?><label class="check"><input type="checkbox" name="cross_plugin_plugins[]" value="<?= e($pluginKey) ?>" <?= in_array($pluginKey,(array)($surfaceSettings['cross_plugin_plugins']??[]),true)?'checked':'' ?>><?= e($pluginLabel) ?></label><?php endforeach; ?></div><?php else: ?><p class="muted">No other enabled plugins are currently eligible for physical-context access.</p><?php endif; ?>
<div class="policy-actions"><button class="btn-save" type="submit">Save awareness settings</button></div>
</form></section>
<?php if(empty($sites)): ?><section class="card"><h2>No Tracky site has synchronized yet.</h2><p class="muted">Tracky is enabled in VP3 Cloud. When your paired OTRO HomeServer advertises <code>physical_context.v1</code> and begins governed synchronization, its site will appear here.</p></section><?php else: ?>
<nav class="site-tabs"><?php foreach($sites as $site): ?><a class="<?= $selected===(string)$site['site_id']?'active':'' ?>" href="<?= e(url('/tracky.php?site='.rawurlencode((string)$site['site_id']))) ?>"><?= e((string)($site['label']?:$site['site_id'])) ?></a><?php endforeach; ?></nav>
<?php $site=null;foreach($sites as $candidate)if((string)$candidate['site_id']===$selected){$site=$candidate;break;} ?>
<div class="tracky-grid">
<?php if(is_array($selectedEventCard)): ?><section class="card selected-event"><h2><?= e((string)$selectedEventCard['title']) ?></h2><div class="muted"><?= e((string)$selectedEventCard['subtitle']) ?> · <?= e((string)$selectedEventCard['timestamp']) ?></div><p><?= e((string)$selectedEventCard['summary']) ?></p><div class="checks"><?php foreach((array)($selectedEventCard['badges']??[]) as $badge): ?><span class="pill"><?= e((string)$badge) ?></span><?php endforeach; ?><span class="pill"><?= e((string)$selectedEventCard['status']) ?></span></div></section><?php endif; ?>
<section class="card"><div style="display:flex;justify-content:space-between;gap:20px;align-items:flex-start;flex-wrap:wrap"><div><div class="muted">Site</div><h1 style="margin:4px 0 6px"><?= e((string)($site['label']??$selected)) ?></h1><div class="muted"><?= e($selected) ?> · <?= e((string)($site['device_id']??'')) ?></div></div><div><span class="status <?= e((string)($site['status']??'')) ?>"><?= e(ucfirst((string)($site['status']??'unknown'))) ?></span><div class="muted" style="margin-top:8px">Protocol <?= e((string)($site['protocol_version']??'')) ?></div></div></div></section>
<section class="card half"><div class="muted">Current room</div><div class="metric"><?= e((string)($context['context']['current_room']??'Unknown')) ?></div><p class="muted">Confidence <?= e(number_format((float)($context['context']['confidence']??0)*100,1)) ?>%</p></section>
<section class="card half"><div class="muted">Cloud projection</div><div class="metric"><?= count($world) ?> facts</div><p class="muted"><?= count($events) ?> recent events loaded · sequence <?= e((string)($site['last_sequence']??0)) ?></p></section>
<section class="card half"><h2>Physical context</h2><?php if(empty($context)): ?><div class="empty">No context snapshot yet.</div><?php else: ?><div class="list"><div class="row"><strong>Present</strong><div class="muted"><?= e(implode(', ',(array)($context['context']['people_present']??[]))?:'No one reported') ?></div></div><div class="row"><strong>Environment</strong><div class="muted"><?= e((string)($context['context']['environment_status']??'unknown')) ?></div></div><div class="row"><strong>Recent changes</strong><div class="muted"><?= e(implode(' · ',(array)($context['context']['recent_changes']??[]))?:'None') ?></div></div><div class="row"><strong>Exceptions</strong><div class="muted"><?= e(implode(' · ',(array)($context['context']['exceptions']??[]))?:'None') ?></div></div></div><?php endif; ?></section>
<section class="card half"><h2>Health & capabilities</h2><div class="list"><div class="row"><strong>Health</strong><pre style="white-space:pre-wrap;margin:6px 0 0"><?= e(json_encode($site['health']??[],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></pre></div><div class="row"><strong>Capabilities</strong><pre style="white-space:pre-wrap;margin:6px 0 0"><?= e(json_encode($site['capabilities']??[],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></pre></div></div></section>
<section class="card"><h2>Recent governed events</h2><?php if(empty($events)): ?><div class="empty">No governed events yet.</div><?php else: ?><div class="list"><?php foreach($events as $event): ?><div class="row"><span class="pill"><?= e((string)$event['event_type']) ?></span><span class="pill"><?= e((string)$event['severity']) ?></span><strong><?= e((string)$event['occurred_at']) ?></strong><div class="muted">Confidence <?= e(number_format((float)$event['confidence']*100,1)) ?>% · <?= !empty($event['is_fresh'])?'fresh':'historical' ?> · <?= e((string)$event['privacy_class']) ?></div></div><?php endforeach; ?></div><?php endif; ?></section>
<section class="card half"><div class="muted">Agent Brain integration</div><div class="metric"><?= $agentIntegrated?'Ready':'Unavailable' ?></div><p class="muted">Ask Agent Chat where something is, who is present, what changed, when an entity was last seen, how confident Tracky is, why it believes a physical fact, or whether Tracky is healthy.</p></section>
<section class="card half"><div class="muted">Cross-surface integration</div><div class="metric"><?= $proactiveIntegrated?'Ready':'Unavailable' ?></div><p class="muted">Meaningful physical events can flow through Agent Now, existing attention-budgeted notifications, proactive Chat and opt-in voice according to the policy above.</p></section>
<section class="card half"><div class="muted">OTRO contract join</div><div class="metric"><?= !empty($joinCapabilities['compatible'])?'Compatible':'Not ready' ?></div><p class="muted">HomeServer: <?= !empty($joinCapabilities['connected'])?'connected':'offline' ?> · V2.4 reconciliation: <?= !empty($joinCapabilities['reconciliation']['needs_reconciliation'])?'required':'current' ?> · local perception provider: <?= !empty($joinCapabilities['tracky']['provider']['available'])?'available':'unavailable' ?>.</p></section>
<section class="card half"><div class="muted">Agent authority</div><div class="metric">Read + verify</div><p class="muted">Tracky V2.73 may request a fresh governed sensor/perception read when you enable it. Device control and autonomous physical actions remain outside this phase.</p></section>
<section class="card privacy"><strong>Privacy boundary</strong><div class="muted">This Cloud view contains governed physical meaning only. Tracky rejects raw frames, video, audio, face embeddings, local filesystem paths and camera source URIs from the Cloud synchronization contract. Agent Brain receives the same governed projection, never the raw perception stream.</div></section>
</div><?php endif; ?>
</div></main></div><script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script></body></html>
