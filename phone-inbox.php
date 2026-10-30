<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/phone-cloud-v100.php';
require_once __DIR__.'/includes/phone-sms-v110.php';
require_once __DIR__.'/includes/phone-sms-outbound-v120.php';
require_permission('account.access');
$user=current_user();
if (!$user||!has_permission('chat.access',$user)) redirect(url('/account.php'));
$ownerId=(int)$user['id'];$pdo=db();$ready=$pdo instanceof PDO && vp3_phone_sms_schema_ready_v110();
$sendReady=$ready&&vp3_phone_sms_outbound_ready_v120();
$error='';$threads=[];$messages=[];$selected=0;$selectedThread=null;$numbers=[];$draft=null;
$notice=flash('notice');$quota=['used'=>0,'limit'=>0,'remaining'=>0];
if ($ready && ($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if (!verify_csrf()) $error='Session expired. Refresh to continue.';
    else {
        try {
            $action=(string)($_POST['action']??'');
            if ($action==='mark_read') {
                $threadId=max(0,(int)($_POST['thread_id']??0));
                vp3_phone_sms_mark_read_v110($pdo,$ownerId,$threadId);
                redirect(url('/phone-inbox.php?thread='.$threadId));
            }
            if (!$sendReady) throw new RuntimeException('Outgoing SMS requires the Cloud database upgrade.');
            if ($action==='draft') {
                $draftId=vp3_phone_sms_draft_v120($pdo,$ownerId,(int)($_POST['phone_number_id']??0),
                    (string)($_POST['recipient']??''),(string)($_POST['body']??''),
                    (string)($_POST['consent_source']??''));
                redirect(url('/phone-inbox.php?draft='.$draftId));
            }
            if ($action==='send') {
                $result=vp3_phone_sms_authorize_send_v120($pdo,$ownerId,(int)($_POST['draft_id']??0),
                    (string)($_POST['confirmation']??''));
                flash('notice','Text submitted to Twilio. Delivery results will appear in this conversation.');
                redirect(url('/phone-inbox.php?thread='.(int)$result['thread_id']));
            }
            throw new RuntimeException('Unknown inbox action.');
        } catch(InvalidArgumentException|RuntimeException $exception) {$error=$exception->getMessage();}
          catch(Throwable) {$error='SMS operation unavailable. If a send was attempted, review its status before retrying.';}
    }
}
if ($ready) {
    try {
        $threads=vp3_phone_sms_threads_v110($pdo,$ownerId);
        $selected=max(0,(int)($_GET['thread']??0));
        if (!$selected && $threads) $selected=(int)$threads[0]['id'];
        foreach ($threads as $thread) if ((int)$thread['id']===$selected) {$selectedThread=$thread;break;}
        if ($selectedThread) $messages=vp3_phone_sms_messages_v110($pdo,$ownerId,$selected);
        elseif ($selected) $error='This conversation is not available in your account.';
    } catch(Throwable) {$error='The SMS inbox could not be loaded.';}
}
if ($sendReady) {
    try {
        $q=$pdo->prepare('SELECT id,phone_e164 FROM phone_cloud_numbers_v100
          WHERE owner_user_id=? AND sms_enabled=1 ORDER BY id DESC LIMIT 30');
        $q->execute([$ownerId]);$numbers=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $quota=vp3_phone_sms_monthly_quota_v120($pdo,$ownerId);
        $draftId=max(0,(int)($_GET['draft']??0));
        if ($draftId) $draft=vp3_phone_sms_draft_owned_v120($pdo,$ownerId,$draftId);
    } catch(Throwable) {$error='Outgoing SMS settings could not be loaded.';}
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SMS Inbox | VP3</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<style>
.vp3-sms-main{min-height:0}.vp3-sms-canvas{flex:1;min-height:0;overflow:auto;padding:18px;background:#f5f7fa}
.vp3-sms-wrap{max-width:1050px;margin:auto;display:grid;gap:14px}
.vp3-sms-board{display:grid;grid-template-columns:minmax(220px,310px) minmax(0,1fr);gap:14px;min-height:420px}
.vp3-sms-card{background:#fff;border:1px solid #dfe5ee;border-radius:13px;padding:18px;color:#273342}
.vp3-sms-card h1{font-size:24px;margin:0 0 8px}.vp3-sms-card h2{font-size:18px;margin:0 0 12px}
.vp3-sms-card p{color:#64748b;line-height:1.5}.vp3-sms-threads{display:grid;align-content:start;gap:7px}
.vp3-sms-thread{border:1px solid #dce4ed;border-radius:9px;padding:10px;text-decoration:none;color:#1e293b;display:block}
.vp3-sms-thread[aria-current="page"]{background:#eaf3ff;border-color:#8db7e8}.vp3-sms-thread small{display:block;color:#64748b;margin-top:4px}
.vp3-sms-bubble{max-width:85%;border:1px solid #dee6ee;background:#f8fafc;border-radius:13px;padding:11px;margin:10px 0;overflow-wrap:anywhere}
.vp3-sms-bubble.outbound{margin-left:auto;background:#eaf5ff}.vp3-sms-bubble small{display:block;margin-top:7px;color:#65738a}
.vp3-sms-alert{background:#fff3e7;border:1px solid #edc5a5;border-radius:9px;padding:12px}
.vp3-sms-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.vp3-sms-actions button{background:#253b53;color:white;border:0;border-radius:9px;padding:9px 12px;font-weight:700;cursor:pointer}
.vp3-sms-actions a{color:#244c79}.vp3-sms-pill{background:#f1f5f9;border:1px solid #dbe3eb;border-radius:20px;padding:3px 7px;font-size:11px}
.vp3-sms-compose{display:grid;gap:11px}.vp3-sms-compose label{display:grid;gap:5px;font-weight:700;font-size:12px;color:#45566a}
.vp3-sms-compose input,.vp3-sms-compose select,.vp3-sms-compose textarea{border:1px solid #cad4df;border-radius:8px;padding:10px;background:#fff;color:#273342;font:inherit;min-width:0}
.vp3-sms-compose button{border:0;border-radius:9px;background:#1b314a;color:white;padding:11px;font-weight:800;cursor:pointer}
.vp3-sms-compose button:disabled{background:#9ca3af}
@media(max-width:720px){.vp3-sms-board{grid-template-columns:1fr;min-height:0}.vp3-sms-canvas{padding:11px}.vp3-sms-card{padding:13px}}
</style></head><body><div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='account';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main vp3-sms-main">
<?php $memberHeaderUser=$user;$memberHeaderTitle='Agent SMS Inbox';$memberHeaderSubtitle='Private conversations for your registered numbers';$memberHeaderActiveKey='account';require __DIR__.'/includes/member-header.php'; ?>
<section class="vp3-sms-canvas"><div class="vp3-sms-wrap">
<section class="vp3-sms-card"><h1>Messages</h1><p>Incoming SMS belongs to the phone number’s owner and selected agent. Unknown senders are not authenticated users. Messages stay in the Cloud inbox and are not automatically shared with an AI provider.</p>
<div class="vp3-sms-actions"><a href="<?= e(url('/phone.php')) ?>">Manage phone numbers</a><span class="vp3-sms-pill">Incoming SMS · PH3A</span><span class="vp3-sms-pill">Owner-approved outgoing · PH3B</span></div>
<?php if(!$ready): ?><p class="vp3-sms-alert">Run the Cloud database upgrade before using SMS Inbox.</p><?php endif; ?>
<?php if($notice): ?><p role="status"><?= e($notice) ?></p><?php endif; ?>
<?php if($error): ?><p class="vp3-sms-alert" role="alert"><?= e($error) ?></p><?php endif; ?>
</section>
<div class="vp3-sms-board">
<aside class="vp3-sms-card"><h2>Conversations (<?= count($threads) ?>)</h2><nav class="vp3-sms-threads" aria-label="SMS conversations">
<?php if(!$threads): ?><p>No incoming texts yet. Your administrator can enable incoming SMS from Phone &amp; Messaging.</p><?php endif; ?>
<?php foreach($threads as $thread): ?><a class="vp3-sms-thread" href="<?= e(url('/phone-inbox.php?thread='.(int)$thread['id'])) ?>" <?= (int)$thread['id']===$selected?'aria-current="page"':'' ?>><strong><?= e((string)$thread['contact_e164']) ?></strong><?php if((int)$thread['unread_count']>0): ?> <span class="vp3-sms-pill"><?= (int)$thread['unread_count'] ?> new</span><?php endif; ?><small>To <?= e((string)$thread['phone_e164']) ?> · <?= e((string)($thread['agent_name']?:'Your agent')) ?></small><small><?= e((string)$thread['last_message_at']) ?></small></a><?php endforeach; ?>
</nav></aside>
<section class="vp3-sms-card" aria-label="Selected SMS conversation">
<?php if($selectedThread): ?><h2><?= e((string)$selectedThread['contact_e164']) ?></h2><p>Agent: <?= e((string)($selectedThread['agent_name']?:'Your agent')) ?> · Number: <?= e((string)$selectedThread['phone_e164']) ?></p>
<?php if((int)$selectedThread['unread_count']>0): ?><form method="post" class="vp3-sms-actions"><?= csrf_field() ?><input type="hidden" name="action" value="mark_read"><input type="hidden" name="thread_id" value="<?= $selected ?>"><button>Mark conversation read</button></form><?php endif; ?>
<?php foreach($messages as $message): ?><article class="vp3-sms-bubble <?= (string)$message['direction']==='outbound'?'outbound':'' ?>"><div><?= nl2br(e((string)$message['body'])) ?></div><small><?= e((string)$message['created_at']) ?> · <?= e((string)$message['delivery_status']) ?></small></article><?php endforeach; ?>
<?php else: ?><p>Select a conversation to view its messages.</p><?php endif; ?>
</section></div>
<section class="vp3-sms-card" id="compose"><h2>Compose an authorized text</h2>
<?php if(!$sendReady): ?><p>Run the Cloud upgrade to enable drafts and approvals.</p>
<?php else: ?>
<p>Monthly outgoing allowance: <strong><?= (int)$quota['used'] ?></strong> used of <strong><?= $quota['limit']===null?'unlimited':(int)$quota['limit'] ?></strong>. Drafts do not send anything. The owner authorizes each exact text before Twilio is contacted.</p>
<?php if(!vp3_phone_sms_live_enabled_v120()): ?><p class="vp3-sms-alert">Live sending is disabled until a VP3 administrator verifies messaging compliance and enables the gateway. You can prepare drafts now.</p><?php endif; ?>
<?php if($draft): ?><h2>Review draft #<?= (int)$draft['id'] ?></h2><div class="vp3-sms-bubble"><strong>From:</strong> <?= e((string)$draft['phone_e164']) ?><br><strong>To:</strong> <?= e((string)$draft['recipient_e164']) ?><p><?= nl2br(e((string)$draft['body'])) ?></p><small>Consent record: <?= e((string)$draft['consent_source']) ?> · <?= e((string)$draft['status']) ?></small></div>
<?php if($draft['status']==='draft'): ?><form class="vp3-sms-compose" method="post" onsubmit="return confirm('Send this exact message via Twilio? This may incur carrier charges.')"><?= csrf_field() ?><input type="hidden" name="action" value="send"><input type="hidden" name="draft_id" value="<?= (int)$draft['id'] ?>"><label>Type SEND <?= e((string)$draft['recipient_e164']) ?> to approve<input name="confirmation" autocomplete="off" required placeholder="SEND <?= e((string)$draft['recipient_e164']) ?>"></label><button <?= vp3_phone_sms_live_enabled_v120()?'':'disabled' ?>>Authorize and send text</button></form><?php endif; ?>
<?php endif; ?>
<form class="vp3-sms-compose" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="draft"><label>Send from your agent number<select name="phone_number_id" required><?php foreach($numbers as $number): ?><option value="<?= (int)$number['id'] ?>"><?= e((string)$number['phone_e164']) ?></option><?php endforeach; ?></select></label>
<label>Recipient number (international format)<input name="recipient" type="tel" required placeholder="+16025550123" value="<?= e((string)($selectedThread['contact_e164']??'')) ?>"></label>
<label>Text message<textarea name="body" rows="4" maxlength="800" required placeholder="Write the message for review"></textarea></label>
<label>How did this contact consent to receive this text?<input name="consent_source" required minlength="8" maxlength="250" placeholder="e.g. Requested an appointment confirmation at checkout"></label>
<button <?= $numbers?'':'disabled' ?>>Create draft (no text sent)</button></form>
<?php endif; ?></section>
</div></section></main></div></body></html>
