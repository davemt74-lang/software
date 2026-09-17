<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/browser-share-chat-feed-v2020.php';

$publicId=trim((string)($_GET['browser_share_id']??''));
if($publicId===''){
    flash('error','Browser Share is required.');
    redirect(url('/messages.php'));
}

$user=current_user();
if(!$user){
    $returnTo=url('/browser-share-agent-handoff.php?browser_share_id='.rawurlencode($publicId));
    redirect(url('/login.php?return_to='.rawurlencode($returnTo)));
}

$pdo=db();
if(!$pdo){
    flash('error','VP3 is temporarily unavailable.');
    redirect(url('/messages.php'));
}

try{
    $share=vp3_browser_share_resolve_v2020($pdo,$publicId,(int)$user['id']);
    if(!$share)throw new RuntimeException('This Browser Share is no longer available or you no longer have access to it.');
    $_SESSION['vp3_browser_share_agent_context_v2020']=[
        'browser_share_id'=>(string)$share['id'],
        'conversation_id'=>0,
        'created_at'=>time(),
    ];
    redirect(url('/chat.php?browser_share_id='.rawurlencode((string)$share['id'])));
}catch(Throwable $e){
    flash('error',$e instanceof RuntimeException?$e->getMessage():'Browser Share could not be opened in Agent Chat.');
    redirect(url('/messages.php'));
}
