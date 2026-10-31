<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/phone-cloud-v100.php';
require_once __DIR__.'/includes/phone-voice-v130.php';
require_permission('account.access');
$user=current_user();
if (!$user||!has_permission('chat.access',$user)) redirect(url('/account.php'));
$ready=vp3_phone_voice_schema_ready_v130();$calls=[];$error='';
if ($ready) {
    try {$calls=vp3_phone_voice_calls_v130(db(),(int)$user['id']);}
    catch(Throwable) {$error='Call history is temporarily unavailable.';}
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Agent Phone Calls | VP3</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<style>
.vp3-calls-canvas{flex:1;min-height:0;overflow:auto;padding:18px;background:#f5f7fa}
.vp3-calls-wrap{max-width:1000px;margin:auto;background:#fff;border:1px solid #dce3ed;border-radius:14px;padding:20px;color:#23334a}
.vp3-calls-wrap h1{font-size:24px;margin:0 0 8px}.vp3-calls-wrap p{color:#596b80;line-height:1.5}
.vp3-calls-wrap table{width:100%;border-collapse:collapse}.vp3-calls-wrap th,.vp3-calls-wrap td{padding:12px;border-bottom:1px solid #e6ebf1;text-align:left;vertical-align:middle}
.vp3-calls-wrap th{color:#5f6d7e;font-size:12px}.vp3-calls-wrap audio{max-width:225px;width:100%}
@media(max-width:720px){.vp3-calls-canvas{padding:10px}.vp3-calls-wrap{padding:13px}.vp3-calls-list{overflow:auto}}
</style></head><body><div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='account';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main">
<?php $memberHeaderUser=$user;$memberHeaderTitle='Agent Phone Calls';$memberHeaderSubtitle='Private inbound call history and voicemail';$memberHeaderActiveKey='account';require __DIR__.'/includes/member-header.php'; ?>
<section class="vp3-calls-canvas"><div class="vp3-calls-wrap">
<h1>Calls & voicemail</h1><p><a href="<?= e(url('/phone.php')) ?>">Manage phone numbers</a> · <a href="<?= e(url('/phone-inbox.php')) ?>">SMS inbox</a></p>
<p>Calls belong to the registered number's owner and selected agent. A caller's phone number alone does not authenticate their identity. Voicemail stays private to your account and is not automatically sent to an AI provider.</p>
<?php if (!$ready): ?><p role="alert">Run the Cloud database upgrade to enable call history.</p><?php elseif ($error): ?><p role="alert"><?= e($error) ?></p><?php elseif (!$calls): ?><p>No incoming calls recorded yet. Your administrator can activate the voice webhook for an assigned number.</p><?php else: ?>
<div class="vp3-calls-list"><table><thead><tr><th>When</th><th>Caller</th><th>Called number / agent</th><th>Result</th><th>Voicemail</th></tr></thead><tbody>
<?php foreach ($calls as $call): ?><tr>
<td><?= e((string)$call['created_at']) ?></td><td><?= e((string)($call['caller_e164']?:'Private caller')) ?></td>
<td><?= e((string)$call['called_e164']) ?><br><small><?= e((string)($call['agent_name']?:'Agent')) ?></small></td>
<td><?= e((string)$call['state']) ?></td><td>
<?php if (!empty($call['voicemail_id'])): ?><audio controls preload="none" aria-label="Voicemail playback" src="<?= e(url('/api/phone-voice-audio-v130.php?id='.(int)$call['voicemail_id'])) ?>"></audio><small><?= (int)$call['duration_seconds'] ?> sec</small>
<?php else: ?><small>None available</small><?php endif; ?>
</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div></section></main></div></body></html>
