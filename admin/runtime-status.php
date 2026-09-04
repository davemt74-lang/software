<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';
require_permission('admin.access');
$user=current_user();
if(!$user||!user_has_role('admin',$user)){http_response_code(403);exit('Admin account required.');}

const STONEFELLOW_RUNTIME_BUILD='production-hardening-v126-20260826';
if(!headers_sent()){
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Stonefellow-Runtime-Diagnostic: '.STONEFELLOW_RUNTIME_BUILD);
}

$appRoot=realpath(dirname(__DIR__))?:dirname(__DIR__);$actionResult=null;$actionKind='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){http_response_code(419);exit('Session expired.');}
    $actionKind=(string)($_POST['action']??'');
    if($actionKind==='self_test')$actionResult=agent_runtime_v126_self_test();
    elseif($actionKind==='cleanup')$actionResult=agent_runtime_v126_cleanup();
    elseif($actionKind==='opcache'){
        $ok=false;if(function_exists('opcache_reset')){try{$ok=(bool)opcache_reset();}catch(Throwable $e){$ok=false;}}clearstatcache(true);$actionResult=['passed'=>$ok,'message'=>$ok?'OPcache reset confirmed.':'OPcache reset was not confirmed.'];
    }
}

$files=[
    'chat.php'=>['path'=>$appRoot.'/chat.php','markers'=>['conversation-phase2-v122-20260826','chat-conversation-v121.js','team-chat-admin-v109.js']],
    'admin/stems.php'=>['path'=>$appRoot.'/admin/stems.php','markers'=>['stem-tools-phase1-v127-20260826','stem-tools-phase2-v128-20260826','admin/stem-agent-v127.js','admin/stem-tool-bridge-v127.js','admin/stem-advanced-tools-v128.js','editor-voice-barge-v117.js']],
    'admin/stem-tool-bridge-v127.js'=>['path'=>$appRoot.'/admin/stem-tool-bridge-v127.js','markers'=>['stem-tool-truth-v127-20260826','no_change','was not confirmed by Studio state']],
    'admin/stem-advanced-tools-v128.js'=>['path'=>$appRoot.'/admin/stem-advanced-tools-v128.js','markers'=>['stem-tools-phase2-v128-20260826','stem.plugins.added','automation.delete-exact','clip.split-exact','region.added']],
    'admin/stem-agent-v127.js'=>['path'=>$appRoot.'/admin/stem-agent-v127.js','markers'=>['stem-agent-truth-v127-20260826','strictResult','I can’t safely execute all of that in Stem Studio yet']],
    'video-editor.php'=>['path'=>$appRoot.'/video-editor.php','markers'=>['editor-voice-barge-v117.js','editor-agent-v114.js','data-stonefellow-build']],
    'includes/bootstrap.php'=>['path'=>$appRoot.'/includes/bootstrap.php','markers'=>['agent-runtime-v125.php','agent-ops-v126.php','X-Stonefellow-Production','agent_runtime_v126_housekeeping_maybe']],
    'includes/agent-runtime-v125.php'=>['path'=>$appRoot.'/includes/agent-runtime-v125.php','markers'=>['X-Stonefellow-Trace','agent_runtime_v125_idempotency_claim','agent_background_v125_recover_orphans']],
    'includes/agent-ops-v126.php'=>['path'=>$appRoot.'/includes/agent-ops-v126.php','markers'=>['STONEFELLOW_PRODUCTION_V126','agent_runtime_v126_retention_policy','agent_runtime_v126_self_test']],
    'includes/agent-action-system-v124.php'=>['path'=>$appRoot.'/includes/agent-action-system-v124.php','markers'=>['agent_action_v124_dependency_graph','agent_action_v124_outcome_factor']],
    'includes/agent-memory-lifecycle-v123.php'=>['path'=>$appRoot.'/includes/agent-memory-lifecycle-v123.php','markers'=>['agent_memory_v123_effective_confidence','agent_memory_v123_reconcile_user']],
    'conversation-voice-v122.js'=>['path'=>$appRoot.'/conversation-voice-v122.js','markers'=>['stonefellow:voice-mode:','StonefellowConversationVoiceV121=api']],
    'team-chat-v109.css'=>['path'=>$appRoot.'/team-chat-v109.css','markers'=>['width:48px','.sf-online-rail-v109{display:none!important}']],
    'api/team-chat-v109.php'=>['path'=>$appRoot.'/api/team-chat-v109.php','markers'=>['LEFT JOIN team_user_presence',"'artist','manager','producer','supervisor','admin'"]],
];

$rows=[];$filesGood=true;
foreach($files as $label=>$definition){
    $path=$definition['path'];$exists=is_file($path);$content=$exists?(string)@file_get_contents($path):'';$markers=[];
    foreach($definition['markers'] as $marker)$markers[$marker]=$exists&&str_contains($content,$marker);
    $good=$exists&&!in_array(false,$markers,true);$filesGood=$filesGood&&$good;
    $rows[$label]=['good'=>$good,'exists'=>$exists,'path'=>$exists?(realpath($path)?:$path):$path,'sha256'=>$exists?(string)hash_file('sha256',$path):'','mtime'=>$exists?(int)filemtime($path):0,'markers'=>$markers];
}

$health=agent_runtime_v126_health_snapshot();
$teamTables=['team_user_presence'=>table_exists('team_user_presence'),'team_direct_messages'=>table_exists('team_direct_messages'),'user_account_types'=>table_exists('user_account_types')];
$teamProbeUrl=url('/api/team-chat-v109.php?action=poll&since=0&page=runtime_status&context=Runtime%20Status');
$overall=$filesGood&&!empty($health['healthy']);
function runtime_status_h(mixed $value): string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function runtime_status_bytes(int $bytes): string{if($bytes<1024)return $bytes.' B';if($bytes<1048576)return number_format($bytes/1024,1).' KB';return number_format($bytes/1048576,1).' MB';}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Runtime Status | Stonefellow</title>
<style>
body{margin:0;background:#f5f5f4;color:#171717;font:14px/1.45 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1220px;margin:auto;padding:28px}.card{background:#fff;border:1px solid #dededb;border-radius:14px;padding:20px;margin-bottom:18px;overflow:auto}.ok{color:#087a2f}.bad{color:#b42318}.warn{color:#9a6700}.muted{color:#6b6b67}h1,h2{margin:0 0 12px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.metric{border:1px solid #e8e8e5;border-radius:10px;padding:12px}.metric strong{display:block;font-size:22px}.details{display:grid;grid-template-columns:190px 1fr;gap:8px 14px;margin-top:16px}table{border-collapse:collapse;width:100%;min-width:900px}th,td{text-align:left;vertical-align:top;padding:9px;border-bottom:1px solid #e7e7e7}code{font:12px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}button{border:0;border-radius:8px;background:#111;color:#fff;padding:10px 14px;font-weight:800;cursor:pointer;margin-right:8px}.marker{display:block}.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}.actions form{margin:0}@media(max-width:760px){.grid{grid-template-columns:1fr 1fr}.details{grid-template-columns:1fr}table{min-width:760px}}
</style>
</head>
<body><div class="wrap">
<div class="card">
<h1>Stonefellow Production Runtime</h1>
<p class="<?= $overall?'ok':'warn' ?>"><strong><?= $overall?'Runtime checks are healthy.':'Runtime needs attention.' ?></strong></p>
<div class="grid">
<div class="metric"><span>Queued jobs</span><strong><?= (int)$health['jobs']['queued'] ?></strong><small class="muted"><?= (int)$health['jobs']['failed'] ?> quarantined failures</small></div>
<div class="metric"><span>Open breakers</span><strong><?= (int)$health['breakers']['open'] ?></strong><small class="muted"><?= (int)$health['breakers']['total'] ?> tracked services</small></div>
<div class="metric"><span>Voice quality</span><strong><?= $health['voice']['sessions']?runtime_status_h(number_format((float)$health['voice']['average_quality']*100,1)).'%':'—' ?></strong><small class="muted"><?= (int)$health['voice']['sessions'] ?> recent sessions</small></div>
<div class="metric"><span>Trace</span><strong style="font-size:13px"><code><?= runtime_status_h($health['trace_id']) ?></code></strong><small class="muted">current request</small></div>
</div>
<div class="details">
<strong>Production build</strong><code><?= runtime_status_h(STONEFELLOW_RUNTIME_BUILD) ?></code>
<strong>App root</strong><code><?= runtime_status_h($appRoot) ?></code>
<strong>Document root</strong><code><?= runtime_status_h((string)($_SERVER['DOCUMENT_ROOT']??'')) ?></code>
<strong>Team Chat tables</strong><code><?= runtime_status_h(json_encode($teamTables,JSON_UNESCAPED_SLASHES)) ?></code>
<strong>Team Chat API</strong><span id="teamChatProbe" class="muted">Checking…</span>
<strong>Stem Studio tools</strong><span>v127 execution truth + v128 dedicated advanced verification</span>
<strong>Automatic housekeeping</strong><span>Every 6 hours; traces 14d, voice 30d, idempotency 2d, breakers 7d, quarantined jobs 14d.</span>
</div>
<?php if(is_array($actionResult)): ?><p class="<?= !empty($actionResult['passed'])||$actionKind==='cleanup'?'ok':'bad' ?>"><strong><?= runtime_status_h(ucwords(str_replace('_',' ',$actionKind))) ?>:</strong> <code><?= runtime_status_h(json_encode($actionResult,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?></code></p><?php endif; ?>
<div class="actions">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="self_test"><button type="submit">Run Resilience Self-Test</button></form>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cleanup"><button type="submit">Run Runtime Cleanup</button></form>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="opcache"><button type="submit">Reset PHP Runtime Cache</button></form>
</div>
</div>

<div class="card"><h2>Runtime storage</h2><table><thead><tr><th>Bucket</th><th>Files</th><th>Size</th><th>Latest</th><th>Retention</th></tr></thead><tbody>
<?php foreach($health['buckets'] as $bucket=>$stats): $policyKey=$bucket==='jobs'?'jobs-failed':$bucket; ?>
<tr><td><strong><?= runtime_status_h($bucket) ?></strong></td><td><?= (int)$stats['count'] ?></td><td><?= runtime_status_h(runtime_status_bytes((int)$stats['bytes'])) ?></td><td><?= runtime_status_h($stats['latest_at']?:'—') ?></td><td><?= isset($health['retention_seconds'][$policyKey])?runtime_status_h(number_format((int)$health['retention_seconds'][$policyKey]/86400,0)).' days':'active queue' ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>

<div class="card"><h2>Critical production files</h2><table><thead><tr><th>File</th><th>Real disk path</th><th>SHA-256 / modified</th><th>Required markers</th></tr></thead><tbody>
<?php foreach($rows as $label=>$row): ?><tr>
<td class="<?= $row['good']?'ok':'bad' ?>"><strong><?= runtime_status_h($label) ?></strong><br><?= $row['exists']?'present':'missing' ?></td>
<td><code><?= runtime_status_h($row['path']) ?></code></td>
<td><code><?= runtime_status_h($row['sha256']) ?></code><br><span class="muted"><?= $row['mtime']?runtime_status_h(date('c',$row['mtime'])):'—' ?></span></td>
<td><?php foreach($row['markers'] as $marker=>$found): ?><span class="marker <?= $found?'ok':'bad' ?>"><?= $found?'✓':'✗' ?> <code><?= runtime_status_h($marker) ?></code></span><?php endforeach; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
</div>
<script>
fetch(<?= json_encode($teamProbeUrl) ?>,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
 .then(async r=>({r,d:await r.json().catch(()=>({ok:false,error:'invalid_json'}))}))
 .then(({r,d})=>{const e=document.getElementById('teamChatProbe');const ok=r.ok&&d.ok===true;e.className=ok?'ok':'bad';e.textContent=ok?`HTTP ${r.status} · ok · ${Array.isArray(d.users)?d.users.length:0} directory users`:`HTTP ${r.status} · ${String(d.error||'poll_failed')}`;})
 .catch(error=>{const e=document.getElementById('teamChatProbe');e.className='bad';e.textContent='Request failed · '+String(error?.message||error);});
</script>
</body></html>