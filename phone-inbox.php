<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/phone-cloud-v100.php';
require_once __DIR__.'/includes/phone-sms-v110.php';
require_permission('account.access');
$user=current_user();
if (!$user||!has_permission('chat.access',$user)) redirect(url('/account.php'));
$ownerId=(int)$user['id'];$pdo=db();$ready=$pdo instanceof PDO && vp3_phone_sms_schema_ready_v110();
$error='';$threads=[];$messages=[];$selected=0;$selectedThread=null;
if ($ready && ($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if (!verify_csrf()) $error='Session expired. Refresh to continue.';
    else {
        try {
            $threadId=max(0,(int)($_POST['thread_id']??0));
            if (($_POST['action']??'')!=='mark_read') throw new RuntimeException('Unknown inbox action.');
            vp3_phone_sms_mark_read_v110($pdo,$ownerId,$threadId);
            redirect(url('/phone-inbox.php?thread='.$threadId));
        } catch(Throwable) {$error='The selected conversation is unavailable.';}
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
@media(max-width:720px){.vp3-sms-board{grid-template-columns:1fr;min-height:0}.vp3-sms-canvas{padding:11px}.vp3-sms-card{padding:13px}}
</style></head><body><div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='account';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main vp3-sms-main">
<?php $memberHeaderUser=$user;$memberHeaderTitle='Agent SMS Inbox';$memberHeaderSubtitle='Private conversations for your registered numbers';$memberHeaderActiveKey='account';require __DIR__.'/includes/member-header.php'; ?>
<section class="vp3-sms-canvas"><div class="vp3-sms-wrap">
<section class="vp3-sms-card"><h1>Messages</h1><p>Incoming SMS belongs to the phone number’s owner and selected agent. Unknown senders are not authenticated users. Messages stay in the Cloud inbox and are not automatically shared with an AI provider.</p>
<div class="vp3-sms-actions"><a href="<?= e(url('/phone.php')) ?>">Manage phone numbers</a><span class="vp3-sms-pill">Incoming SMS · PH3A</span><span class="vp3-sms-pill">Outgoing · PH3B pending</span></div>
<?php if(!$ready): ?><p class="vp3-sms-alert">Run the Cloud database upgrade before using SMS Inbox.</p><?php endif; ?>
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
</section></div></div></section></main></div></body></html>
