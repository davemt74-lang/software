<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/phone-cloud-v100.php';
require_permission('account.access');
$user=current_user();$pdo=db();
if (!$user || !has_permission('chat.access',$user)) redirect(url('/account.php'));
$ready=$pdo instanceof PDO && vp3_phone_schema_ready_v100($pdo);
$error='';$message='';$available=[];$areaCode='';
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if (!verify_csrf()) $error='Session expired. Refresh and try again.';
    elseif (!$ready) $error='The phone registry is not installed yet. Please contact the administrator.';
    else {
        try {
            $state=vp3_phone_member_state_v100($pdo,(int)$user['id']);
            if (empty($state['can_register'])) throw new RuntimeException('Your subscription phone allowance is already used or unavailable.');
            $action=(string)($_POST['action']??'');
            if ($action==='search') {
                $now=time();$attempts=array_values(array_filter((array)($_SESSION['phone_cloud_search_v100']??[]),
                  static fn($n)=>is_numeric($n)&&(int)$n>$now-60));
                if (count($attempts)>=6) throw new RuntimeException('Please wait a minute before searching again.');
                $attempts[]=$now;$_SESSION['phone_cloud_search_v100']=$attempts;
                $areaCode=trim((string)($_POST['area_code']??''));
                $available=vp3_phone_twilio_search_v100($areaCode);
                if (!$available) $message='Twilio did not return available voice-and-SMS numbers for that area code.';
            } elseif ($action==='request') {
                vp3_phone_request_number_v100($pdo,(int)$user['id'],(int)($_POST['agent_id']??0),
                    (string)($_POST['number']??''));
                flash('notice','Phone number requested. An administrator will review availability and billing before purchase.');
                redirect(url('/phone.php'));
            } else throw new RuntimeException('Choose a phone action.');
        } catch(Throwable $exception) {$error=$exception->getMessage();}
    }
}
$state=$ready?vp3_phone_member_state_v100($pdo,(int)$user['id']):['ready'=>false,'number'=>null,'numbers'=>[],'request'=>null,'can_register'=>false,'package'=>['included'=>false,'limit'=>0,'voice'=>false,'sms'=>false]];
$connected=vp3_phone_twilio_state_v100()['configured'];
$agents=[];
if ($pdo) {
    try {$q=$pdo->prepare('SELECT id,display_name FROM user_agents WHERE owner_user_id=? AND is_active=1 ORDER BY is_default DESC,id ASC');$q->execute([(int)$user['id']]);$agents=$q->fetchAll();}catch(Throwable){}
}
$notice=flash('notice');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Phone & Messaging | VP3</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<style>
.vp3-phone-main{min-height:0}.vp3-phone-canvas{flex:1;min-height:0;overflow:auto;padding:22px 18px;background:#f6f8fa}
.vp3-phone-wrap{max-width:930px;margin:auto;display:grid;gap:16px}.vp3-phone-card{background:#fff;border:1px solid #dee4ea;border-radius:14px;padding:20px;color:#202a37}
.vp3-phone-card h1{margin:0 0 8px;font-size:25px}.vp3-phone-card h2{font-size:18px;margin:0 0 8px}.vp3-phone-card p{color:#647185;line-height:1.55}
.vp3-phone-pill{display:inline-flex;border:1px solid #d3dce4;border-radius:99px;padding:5px 9px;font-size:12px;color:#38465b;background:#f8fafc}
.vp3-phone-number{font-size:clamp(22px,4vw,32px);font-weight:800;letter-spacing:.02em;margin:14px 0}
.vp3-phone-alert{border:1px solid #e7c6ae;background:#fff5ea;border-radius:10px;padding:12px;color:#583a22}
.vp3-phone-success{border-color:#add9bb;background:#effcf3;color:#286644}
.vp3-phone-form{display:grid;gap:12px}.vp3-phone-form label{display:grid;gap:5px;font-size:12px;font-weight:750;color:#475569}
.vp3-phone-form input,.vp3-phone-form select{width:100%;border:1px solid #cad3dd;border-radius:9px;min-height:39px;padding:9px;font:inherit;color:#1f2937;background:#fff}
.vp3-phone-form button{border:0;border-radius:9px;background:#17273a;color:#fff;font-weight:750;min-height:39px;padding:9px 14px;cursor:pointer}
.vp3-phone-form button:disabled{background:#a3aebb}.vp3-phone-results{display:grid;gap:9px}
.vp3-phone-result{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:12px;border:1px solid #e0e6ed;border-radius:9px}
.vp3-phone-result strong{font-size:17px}.vp3-phone-result small{display:block;color:#64748b;margin-top:3px}
.vp3-phone-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,290px),1fr));gap:16px}
@media(max-width:600px){.vp3-phone-canvas{padding:12px}.vp3-phone-card{padding:16px}.vp3-phone-result{align-items:stretch;flex-direction:column}}
</style></head><body><div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='account';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main vp3-phone-main">
<?php $memberHeaderUser=$user;$memberHeaderTitle='Phone & Messaging';$memberHeaderSubtitle='Your number, agent and package';$memberHeaderActiveKey='account';require __DIR__.'/includes/member-header.php'; ?>
<section class="vp3-phone-canvas"><div class="vp3-phone-wrap">
<section class="vp3-phone-card"><h1>Your Agent Phone</h1><p>Choose an agent and request a real phone number inside VP3. Numbers are part of your monthly subscription package. An administrator reviews each carrier purchase before billing occurs.</p>
<p><a href="<?= e(url('/phone-inbox.php')) ?>">Open your SMS inbox</a> · Incoming messages appear once your administrator activates the signed Twilio webhook.</p>
<?php if($notice): ?><div class="vp3-phone-alert vp3-phone-success" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if($error): ?><div class="vp3-phone-alert" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if(!$ready): ?><div class="vp3-phone-alert">The phone registry is pending a Cloud upgrade.</div><?php endif; ?>
</section>
<div class="vp3-phone-two">
<section class="vp3-phone-card"><h2>My registered number<?= count((array)$state['numbers'])===1?'':'s' ?></h2>
<?php if(!empty($state['numbers'])): ?>
<?php foreach($state['numbers'] as $number): ?><div class="vp3-phone-number"><?= e((string)$number['phone_e164']) ?></div><span class="vp3-phone-pill"><?= e((string)$number['status']) ?></span><p>Assigned to <?= e((string)($number['agent_name']?:'your Agent')) ?> · Incoming calls: <?= (int)$number['voice_enabled']===1?'voicemail on':'off' ?> · SMS: <?= (int)$number['sms_enabled']===1?'on':'off' ?></p><?php endforeach; ?>
<?php else: ?><p>No phone number has been assigned yet.</p><?php endif; ?>
<?php if($state['request']): ?><p><strong>Pending request:</strong> <?= e((string)$state['request']['phone_e164']) ?> for <?= e((string)($state['request']['agent_name']?:'your Agent')) ?>.</p><?php endif; ?>
</section>
<section class="vp3-phone-card"><h2>Phone activity</h2><p><a href="<?= e(url('/phone-calls.php')) ?>">View calls and voicemail</a> · <a href="<?= e(url('/phone-inbox.php')) ?>">Open SMS inbox</a></p></section>
<section class="vp3-phone-card"><h2>Subscription allowance</h2>
<?php $plan=$state['package'];if($plan['included']): ?>
<p><strong><?= $plan['limit']===null?'Unlimited':(int)$plan['limit'] ?></strong> agent phone number<?= (int)($plan['limit']??2)===1?'':'s' ?> included.</p>
<p><span class="vp3-phone-pill">Voice <?= $plan['voice']?'included':'not included' ?></span> <span class="vp3-phone-pill">SMS <?= $plan['sms']?'included':'not included' ?></span></p>
<p>Carrier activation, metering, and outbound approvals are handled separately.</p>
<?php else: ?><p>Your current subscription does not include a phone number.</p><a href="<?= e(url('/pricing.php')) ?>">View subscription packages</a><?php endif; ?>
</section></div>
<?php if($state['can_register']): ?>
<section class="vp3-phone-card"><h2>Register a phone number</h2>
<?php if(!$connected): ?><p>Phone registration is waiting for the VP3 administrator to connect Twilio.</p>
<?php else: ?><p>Search available US numbers by area code. The request does not purchase or charge for a number.</p>
<form class="vp3-phone-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="search"><label>US area code<input type="text" name="area_code" pattern="[2-9][0-9]{2}" maxlength="3" inputmode="numeric" required placeholder="e.g. 602" value="<?= e($areaCode) ?>"></label><button type="submit">Find available numbers</button></form>
<?php if($message): ?><p role="status"><?= e($message) ?></p><?php endif; ?>
<?php if($available): ?><h2 style="margin-top:18px">Available numbers</h2><div class="vp3-phone-results">
<?php foreach($available as $candidate): ?><div class="vp3-phone-result"><div><strong><?= e((string)$candidate['number']) ?></strong><small><?= e((string)$candidate['locality']) ?> <?= e((string)$candidate['region']) ?> · Voice + SMS capable</small></div><form class="vp3-phone-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="request"><input type="hidden" name="number" value="<?= e((string)$candidate['number']) ?>"><label>Assign to agent<select name="agent_id" required><?php foreach($agents as $agent): ?><option value="<?= (int)$agent['id'] ?>"><?= e((string)$agent['display_name']) ?></option><?php endforeach; ?></select></label><button <?= $agents?'':'disabled' ?> type="submit">Request number</button></form></div><?php endforeach; ?>
</div><?php endif; ?>
<?php endif; ?></section>
<?php endif; ?>
</div></section></main></div></body></html>
