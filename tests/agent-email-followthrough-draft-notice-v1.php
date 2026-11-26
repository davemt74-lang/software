<?php
declare(strict_types=1);
require __DIR__.'/fixtures/gmail-followthrough-native-v1.php';

$one=ft_seed('one');$beforeUsers=(int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE role='user'")->fetchColumn();
ft_prepare($one);$saved=ft_row($one['row']['id']);$id=agent_email_followthrough_draft_id_v1($saved);
$draft=agent_connection_email_chat_draft_v1($pdo,1,7,11,$id);$payload=agent_connection_email_chat_payload_v1($draft);
ft_check($saved['generated_draft_id']===$id && (int)$saved['generated_revision']===1 && $saved['draft_attempt_state']==='ready'
    && (int)$saved['state_version']>(int)$one['row']['state_version'],'one exact owner deadline stores its canonical draft and advances public state');
ft_check((int)$draft['watch_replies']===1 && (int)$draft['user_message_id']===(int)$one['row']['user_message_id']
    && $payload['composer']['version']===3 && $payload['composer']['origin']==='outbound_followup' && !isset($payload['reply'])
    && $payload['outbound_followup']['operation_key']===$one['watch']['operation_key'],'follow-up draft uses the real deadline marker, distinct outbound binding and reviewed reply-watch default');
ft_check((int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE role='user'")->fetchColumn()===$beforeUsers,
    'background preparation does not fabricate another owner turn');
$inference=$GLOBALS['ft_last_inference'];$prompt=json_decode($inference['prompt'],true,16,JSON_THROW_ON_ERROR);
ft_check($inference['protocol']==='vp3.initiative-draft.v1' && $inference['transient']===true && $inference['job']['background']===true
    && $inference['job']['conversation_id']===11 && $inference['job']['gmail_followthrough_draft']===true && $prompt['response_kind']==='gmail_followthrough_draft'
    && array_keys($prompt)===['response_kind','owner_instruction','owner_settings','verified_check','email_soul','original_sent_message']
    && $prompt['verified_check']['coverage']==='exact_sent_thread' && str_contains($prompt['original_sent_message']['excerpt'],'PRIVATE_ORIGINAL_BODY_one'),
    'metered adapter receives only bounded original source, actual owner settings and email Soul in transient background scope');
ft_check($GLOBALS['ft_http_calls']===6 && $GLOBALS['ft_dispatches']===1,'source is checked before dispatch and before commit, with one external inference dispatch');
ft_prepare($one);ft_check($GLOBALS['ft_dispatches']===1 && $GLOBALS['ft_http_calls']===6,'worker replay never regenerates the same owner deadline');
ft_check(agent_email_followthrough_preparation_v1($pdo,$saved)['state']==='ready','read-only readiness requires the actual saved current draft');

agent_email_followthrough_notice_v1($pdo,$saved,$one['watch'],$one['claim']);$noticed=ft_row($saved['id']);
$q=$pdo->prepare('SELECT * FROM chat_messages WHERE id=?');$q->execute([$noticed['notice_message_id']]);$notice=$q->fetch();$noticeContext=json_decode($notice['context_json'],true,16,JSON_THROW_ON_ERROR);
ft_check($noticed['notice_stage']==='draft_ready' && $noticeContext['actions'][0]['type']==='email_review'
    && $noticeContext['actions'][0]['id']===$id && $noticeContext['actions'][0]['auto']===false
    && $noticeContext['email_followthrough']['source_revision']===1 && $noticeContext['skip_brain_archive']===true,
    'one coalesced Chat milestone points to the exact existing review without send authority');
ft_check(!str_contains($notice['message'],'recipient@') && !str_contains($notice['message'],'PRIVATE_')
    && (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='agent_email_followthrough'")->fetchColumn()===1,
    'milestone and notification use private-safe text and commit together');
agent_email_followthrough_notice_v1($pdo,$saved,$one['watch'],$one['claim']);
ft_check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='agent_email_followthrough'")->fetchColumn()===1,'notice replay is deduped by the saved canonical milestone');

$pause=agent_email_followthrough_update_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$saved['id'],'expected_revision'=>1,'operation'=>'pause'],'fixture');
ft_check($pause['preparation']['state']==='paused','pause is visible without claiming the draft was sent');
agent_email_followthrough_update_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$saved['id'],'expected_revision'=>2,'operation'=>'resume'],'fixture');
$pdo->prepare("UPDATE agent_email_followthrough_v1 SET outcome='overdue',check_state='verified',check_result='no_reply',checked_at=? WHERE id=?")
    ->execute([agent_commerce_time_pa2(),$saved['id']]);$resumed=ft_claim(ft_row($saved['id']));
ft_prepare($resumed);agent_email_followthrough_notice_v1($pdo,$resumed['row'],$resumed['watch'],$resumed['claim']);
ft_check(agent_email_followthrough_draft_id_v1($resumed['row'])===$id && $GLOBALS['ft_dispatches']===1
    && (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='agent_email_followthrough'")->fetchColumn()===1,
    'pause/resume preserves source identity and does not repeat inference or its ready announcement');
$pdo->prepare("UPDATE agent_connection_email_drafts_v1 SET status='discarded' WHERE id=?")->execute([$id]);
ft_check(agent_email_followthrough_preparation_v1($pdo,ft_row($saved['id']))===['state'=>'unavailable','reason'=>'draft_discarded'],
    'discarded generated draft is explained and never remains ready');
ft_prepare($resumed);ft_check($GLOBALS['ft_dispatches']===1,'discard never silently authorizes a replacement model dispatch');

$blocked=ft_seed('policy');ft_policy(false);$reads=$GLOBALS['ft_http_calls'];ft_prepare($blocked);$blockedRow=ft_row($blocked['row']['id']);
ft_check($blockedRow['draft_attempt_state']==='blocked' && $blockedRow['draft_attempt_reason']==='initiative_paused'
    && $GLOBALS['ft_http_calls']===$reads && $GLOBALS['ft_dispatches']===1,'disabled Initiative blocks before provider/model I/O and records a readable reason');
ft_policy(true,12);ft_check(agent_email_followthrough_preparation_v1($pdo,$blockedRow)===['state'=>'blocked','reason'=>'linked_elsewhere'],
    'read-only preparation reflects the live linked-Chat policy instead of stale stored readiness');
ft_policy();ft_prepare($blocked);ft_check(ft_row($blockedRow['id'])['draft_attempt_state']==='ready' && $GLOBALS['ft_dispatches']===2,
    'zero-dispatch policy block may proceed once the same authorized Chat becomes ready');

$fallback=ft_seed('fallback');$first=$GLOBALS['ft_dispatches'];
ft_prepare($fallback,static function($prompt,$protocol,$before,$job,$transient):string{
    $before();$GLOBALS['ft_dispatches']++;$before();$GLOBALS['ft_dispatches']++;return '{"draft":"unreachable"}';
});
$failed=ft_row($fallback['row']['id']);ft_check($GLOBALS['ft_dispatches']===$first+1 && $failed['draft_attempt_state']==='failed'
    && $failed['generated_draft_id']===null,'uncertain first dispatch cannot turn a transport fallback into a second charged draft attempt');
ft_prepare($fallback);ft_check($GLOBALS['ft_dispatches']===$first+1,'failed dispatched attempt cannot automatically retry for the same deadline');

$race=ft_seed('pause_race');$first=$GLOBALS['ft_dispatches'];
ft_prepare($race,static function($prompt,$protocol,$before,$job,$transient)use($race,$pdo,$owner,$principal):string{
    $before();$GLOBALS['ft_dispatches']++;
    agent_email_followthrough_update_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$race['row']['id'],'expected_revision'=>1,'operation'=>'pause'],'fixture');
    return '{"draft":"Stale result must not persist."}';
});
ft_check(ft_row($race['row']['id'])['generated_draft_id']===null && ft_row($race['row']['id'])['paused']==1
    && $GLOBALS['ft_dispatches']===$first+1,'pause during inference fences the result before draft commit');
agent_email_followthrough_update_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$race['row']['id'],'expected_revision'=>2,'operation'=>'resume'],'fixture');
$pdo->prepare("UPDATE agent_email_followthrough_v1 SET outcome='overdue',check_state='verified',check_result='no_reply',checked_at=?,draft_attempt_lease=? WHERE id=?")
    ->execute([agent_commerce_time_pa2(),gmdate('Y-m-d H:i:s',time()-1),$race['row']['id']]);
$retry=ft_claim(ft_row($race['row']['id']));ft_prepare($retry);
ft_check($GLOBALS['ft_dispatches']===$first+1 && agent_email_followthrough_preparation_v1($pdo,$retry['row'])===['state'=>'unavailable','reason'=>'dispatch_uncertain'],
    'resuming after cancelled inference reports uncertainty and preserves the consumed dispatch');

$preDispatchReply=ft_seed('pre_dispatch_reply');$first=$GLOBALS['ft_dispatches'];$dispatchGateReturned=false;
$chatBefore=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
$noticesBefore=(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
ft_prepare($preDispatchReply,static function($prompt,$protocol,$before,$job,$transient)use($preDispatchReply,&$dispatchGateReturned):string{
    $w=$preDispatchReply['watch'];
    $GLOBALS['ft_mail'][$w['thread_id']][]=ft_message('before_dispatch_reply',$w['thread_id'],$w['recipient_email'],$w['account_email'],
        'The recipient replied before inference.', ['INBOX'],'<'.$w['sent_message_id'].'@example.test>',time()-1);
    $before();$dispatchGateReturned=true;return '{"draft":"This source must never reach model dispatch."}';
});
$preDispatchRow=ft_row($preDispatchReply['row']['id']);
ft_check(!$dispatchGateReturned && $GLOBALS['ft_dispatches']===$first && $preDispatchRow['generated_draft_id']===null
    && $preDispatchRow['draft_attempt_state']==='blocked' && $preDispatchRow['draft_attempt_reason']==='source_changed',
    'an exact reply immediately before the dispatch guard blocks inference with its original source-change reason');
ft_check($preDispatchRow['outcome']==='waiting' && $preDispatchRow['check_state']==='unavailable'
    && $preDispatchRow['check_result']===null && $preDispatchRow['checked_at']===null && $preDispatchRow['evidence_digest']===null,
    'pre-dispatch source rejection withdraws every field of the older no-reply observation');
ft_reject(409,static fn()=>agent_email_followthrough_notice_v1($pdo,$preDispatchReply['row'],$preDispatchReply['watch'],$preDispatchReply['claim']),
    'the same worker pass cannot announce overdue or draft readiness after a pre-dispatch reply');
ft_check((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$chatBefore
    && (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===$noticesBefore
    && ft_row($preDispatchRow['id'])['notice_message_id']===null,
    'pre-dispatch reply rejection leaves no Chat milestone or notification behind');

$replyRace=ft_seed('reply_race');
ft_prepare($replyRace,static function($prompt,$protocol,$before,$job,$transient)use($replyRace):string{
    $before();$GLOBALS['ft_dispatches']++;$w=$replyRace['watch'];
    $GLOBALS['ft_mail'][$w['thread_id']][]=ft_message('later_reply',$w['thread_id'],$w['recipient_email'],$w['account_email'],'A reply arrived.',
        ['INBOX'],'<'.$w['sent_message_id'].'@example.test>',time()-1);
    return '{"draft":"Should be withheld after a real reply."}';
});
ft_check(ft_row($replyRace['row']['id'])['generated_draft_id']===null && ft_row($replyRace['row']['id'])['draft_attempt_state']==='failed',
    'a reply arriving during inference prevents a stale follow-up draft');
ft_check(ft_row($replyRace['row']['id'])['outcome']==='waiting' && ft_row($replyRace['row']['id'])['check_state']==='unavailable'
    && ft_row($replyRace['row']['id'])['check_result']===null,'fresh contradictory source evidence withdraws the earlier actionable no-reply observation');
ft_reject(409,static fn()=>agent_email_followthrough_notice_v1($pdo,$replyRace['row'],$replyRace['watch'],$replyRace['claim']),
    'the same worker pass cannot announce an overdue milestone after a reply contradicted its source');
$invalid=ft_seed('invalid_source');$GLOBALS['ft_mail'][$invalid['watch']['thread_id']]=[];$first=$GLOBALS['ft_dispatches'];ft_prepare($invalid);
ft_check($GLOBALS['ft_dispatches']===$first && ft_row($invalid['row']['id'])['draft_attempt_state']==='blocked',
    'missing source evidence cannot be promoted to no reply or sent to inference');
$unavailable=ft_seed('source_unavailable');$GLOBALS['ft_http_unavailable']=true;ft_prepare($unavailable);unset($GLOBALS['ft_http_unavailable']);
ft_check($GLOBALS['ft_dispatches']===$first && ft_row($unavailable['row']['id'])['draft_attempt_reason']==='source_unavailable'
    && ft_row($unavailable['row']['id'])['check_result']===null,'Gmail source failure is explained separately from model availability and withdraws actionable absence');

$previous=ft_row($blockedRow['id']);$oldDraft=$previous['generated_draft_id'];$first=$GLOBALS['ft_dispatches'];
agent_email_followthrough_update_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$previous['id'],'expected_revision'=>1,'operation'=>'deadline',
    'deadline_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'time_zone'=>'America/Phoenix','priority'=>'normal','prepare_if_overdue'=>true],'fixture');
$pdo->prepare("UPDATE agent_email_followthrough_v1 SET deadline_at=?,outcome='overdue',check_state='verified',check_result='no_reply',checked_at=? WHERE id=?")
    ->execute([gmdate('Y-m-d H:i:s',time()-60),agent_commerce_time_pa2(),$previous['id']]);
$next=ft_claim(ft_row($previous['id']));ft_prepare($next);ft_prepare($next);$nextRow=ft_row($previous['id']);
ft_check($GLOBALS['ft_dispatches']===$first+1 && $nextRow['generated_draft_id']!==$oldDraft && (int)$nextRow['generated_revision']===2
    && (int)$nextRow['user_message_id']!==(int)$previous['user_message_id']
    && agent_connection_email_chat_draft_v1($pdo,1,7,11,$oldDraft)['status']==='prepared',
    'only a new explicit deadline marker grants one new draft while preserving the older reviewable draft');
ft_reject(409,static fn()=>agent_email_followthrough_source_v1($pdo,$previous,$blocked['watch'],'ft_http',false),
    'the older draft source cannot authorize a send after the owner changed its deadline');

$preflight=ft_seed('preflight_policy');$first=$GLOBALS['ft_dispatches'];
ft_prepare($preflight,static function($prompt,$protocol,$before):string{ft_policy(false);$before();$GLOBALS['ft_dispatches']++;return '{"draft":"unreachable"}';});
ft_check($GLOBALS['ft_dispatches']===$first && ft_row($preflight['row']['id'])['generated_draft_id']===null,
    'live Initiative revocation immediately before dispatch transmits no source to inference');ft_policy();

$invalidOutput=ft_seed('invalid_output');ft_prepare($invalidOutput,static function($prompt,$protocol,$before):string{
    $before();$GLOBALS['ft_dispatches']++;return '{"draft":"Hello","to":"unapproved@example.test"}';
});$invalidOutputRow=ft_row($invalidOutput['row']['id']);
ft_check($invalidOutputRow['generated_draft_id']===null && $invalidOutputRow['draft_attempt_reason']==='inference_failed'
    && $invalidOutputRow['check_result']==='no_reply','model-supplied envelope fields are rejected without fabricating a source change');

$rollback=ft_seed('draft_rollback');
$pdo->exec("CREATE TRIGGER ft_fail_draft BEFORE INSERT ON agent_connection_email_drafts_v1 FOR EACH ROW BEGIN
    IF NEW.status='prepared' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture draft rejection'; END IF; END");
ft_prepare($rollback);$pdo->exec('DROP TRIGGER ft_fail_draft');$rolled=ft_row($rollback['row']['id']);
$exists=$pdo->prepare('SELECT 1 FROM agent_connection_email_drafts_v1 WHERE id=?');$exists->execute([agent_email_followthrough_draft_id_v1($rolled)]);
ft_check($rolled['generated_draft_id']===null && $rolled['draft_attempt_state']==='failed'
    && !$exists->fetchColumn(),
    'failed draft persistence leaves no orphan draft or ready pointer and preserves the consumed attempt');

// Notice priority uses live source authority, not an unvalidated row that can starve another email.
$high=ft_seed('high',11,false,'high');$normal=ft_seed('normal',11,false,'normal');
ft_check(!agent_email_followthrough_notice_priority_v1($pdo,$normal['row']),'a valid pending high-priority response gets the next notice slot');
$pdo->prepare('DELETE FROM chat_messages WHERE id=?')->execute([$high['row']['user_message_id']]);
ft_check(agent_email_followthrough_notice_priority_v1($pdo,$normal['row']),'a deleted high-priority owner marker cannot indefinitely block lower-priority mail');
$pdo->prepare('UPDATE chat_messages SET created_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',time()-300),$noticed['notice_message_id']]);
for($i=0;$i<90;$i++)$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at) VALUES(11,1,'user','Real recent turn.',?)")
    ->execute([agent_commerce_time_pa2()]);
ft_check(!agent_email_followthrough_notice_spacing_v1($pdo,$normal['row'],'overdue',time()),
    'busy Chat traffic cannot hide the canonical last notice outside the recent-turn window');
$pdo->prepare('UPDATE chat_messages SET created_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',time()-86400),$noticed['notice_message_id']]);
// Resolve prior valid pending work so this notice is the next eligible item.
$pdo->prepare("UPDATE agent_email_followthrough_v1 SET outcome='resolved' WHERE id<>?")->execute([$normal['row']['id']]);
$before=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
$pdo->exec("CREATE TRIGGER ft_fail_notice BEFORE INSERT ON notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture notification rejection'");
try{agent_email_followthrough_notice_v1($pdo,$normal['row'],$normal['watch'],$normal['claim']);throw new LogicException('Expected atomic notification rejection.');}
catch(PDOException){}
ft_check((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$before && ft_row($normal['row']['id'])['notice_message_id']===null,
    'notification failure rolls back the Chat milestone and its marker atomically');
$pdo->exec('DROP TRIGGER ft_fail_notice');
agent_email_followthrough_notice_v1($pdo,$normal['row'],$normal['watch'],$normal['claim']);
$noticeRow=ft_row($normal['row']['id']);$q->execute([$noticeRow['notice_message_id']]);$overdue=json_decode($q->fetch()['context_json'],true,16,JSON_THROW_ON_ERROR);
ft_check($noticeRow['notice_stage']==='overdue' && $overdue['actions'][0]['type']==='open_url'
    && str_contains($overdue['actions'][0]['url'],'gmail_followthrough='.$normal['row']['id']),
    'an overdue-only milestone opens its exact scoped follow-up card');
$pdo->prepare('UPDATE agent_email_followthrough_v1 SET awaiting_new_deadline=1 WHERE id=?')->execute([$normal['row']['id']]);
ft_reject(409,static fn()=>agent_email_followthrough_notice_v1($pdo,$normal['row'],$normal['watch'],$normal['claim']),
    'awaiting a new owner deadline cannot reuse the old overdue notice authority');

echo 'EMAIL_FOLLOWTHROUGH_DRAFT_NOTICE=PASS ('.$GLOBALS['ft_checks'].' assertions, mysql)'.PHP_EOL;
