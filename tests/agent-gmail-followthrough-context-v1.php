<?php
declare(strict_types=1);
require __DIR__.'/fixtures/gmail-followthrough-native-v1.php';
require_once dirname(__DIR__).'/includes/agent-surface-context-v131.php';

$ready=ft_seed('context_ready',11,true,'high');ft_prepare($ready);
$waiting=ft_seed('context_waiting',12,false,'normal');
$pdo->prepare("UPDATE agent_email_followthrough_v1 SET outcome='waiting',deadline_at=?,checked_at=? WHERE id=?")
    ->execute([gmdate('Y-m-d H:i:s',time()+3600),gmdate('Y-m-d H:i:s',time()-3600),$waiting['row']['id']]);
$paused=ft_seed('context_paused',11,true,'low');
agent_email_followthrough_update_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$paused['row']['id'],'expected_revision'=>1,'operation'=>'pause'],'fixture');
$read=static fn()=>agent_gmail_workspace_context_v1($pdo,$owner,$principal,11);
$pdo->exec("UPDATE agent_connections_v1 SET access_expires_at=1,access_token_enc='' WHERE id=17");
$before=ft_state();$calls=$GLOBALS['ft_http_calls'];$dispatches=$GLOBALS['ft_dispatches'];$snapshot=$read();$work=$snapshot['followthrough'];
ft_check($work['state']==='ready' && $work['counts']['overdue']===2 && $work['counts']['waiting']===1 && $work['counts']['paused']===1,
    'Gmail workspace distinguishes saved owner deadlines and orthogonal pause state from native reply watches');
$items=array_column($work['items'],null,'followthrough_ref');
ft_check(count($items)===3 && $items[$ready['row']['id']]['preparation']['state']==='ready'
    && $items[$paused['row']['id']]['preparation']['state']==='paused'
    && $items[$waiting['row']['id']]['check']['freshness']==='stale' && $items[$waiting['row']['id']]['check']['coverage']==='exact_sent_thread',
    'bounded previews retain actual preparation, deadline and stale exact-thread as-of semantics');
ft_check(ft_state()===$before && $GLOBALS['ft_http_calls']===$calls && $GLOBALS['ft_dispatches']===$dispatches,
    'context reads do not mutate, poll Gmail, refresh expired OAuth or consume inference');
$json=json_encode($snapshot,JSON_THROW_ON_ERROR);
foreach(['PRIVATE_ORIGINAL_BODY','PRIVATE_GENERATED_BODY','PRIVATE_ACCESS','authorization_key','message_enc','review_token',
    $ready['watch']['sent_message_id'],$ready['watch']['thread_id'],$ready['watch']['operation_key']] as $private)
    ft_check(!str_contains($json,$private),'workspace excludes private source or execution material: '.substr($private,0,25));
ft_check($snapshot['capabilities']['response_deadlines']['available'] && str_contains($snapshot['capabilities']['response_deadlines']['limits'],'new owner deadline')
    && $snapshot['coverage']['mailbox_sent']==='on_demand_not_loaded','capability awareness describes bounded deadline preparation and preserves live mailbox coverage limits');

$pdo->prepare('UPDATE agent_connections_v1 SET access_expires_at=?,access_token_enc=? WHERE id=17')
    ->execute([time()+3600,ai_encrypt_secret('PRIVATE_ACCESS_owner')]);
$foreign=ft_seed('other_owner',21,false);$peer=ft_seed('other_agent',13,false);$orphan=ft_seed('orphan',11,false);
$pdo->prepare('DELETE FROM chat_messages WHERE id=?')->execute([$orphan['row']['user_message_id']]);
$resolved=ft_seed('resolved',11,false);
agent_email_followthrough_update_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$resolved['row']['id'],'expected_revision'=>1,'operation'=>'resolve'],'fixture');
$replied=ft_seed('replied',11,false);$w=$replied['watch'];
$GLOBALS['ft_mail'][$w['thread_id']][]=ft_message('real_reply',$w['thread_id'],$w['recipient_email'],$w['account_email'],'PRIVATE_REPLY_BODY',
    ['INBOX'],'<'.$w['sent_message_id'].'@example.test>',time()-1);
$pdo->prepare('UPDATE agent_connection_email_followups_v1 SET claim_key=NULL WHERE operation_key=?')->execute([$w['operation_key']]);
$reply=agent_email_followthrough_check_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$replied['row']['id'],'expected_revision'=>1],'fixture','ft_http');
ft_check($reply['outcome']==='replied' && $reply['reply_target']['message_id']>0,'context fixture uses an actual verified reply delivery receipt');
$snapshot=$read();$work=$snapshot['followthrough'];
ft_check($work['counts']['replied']===1 && $work['counts']['resolved']===1 && $work['counts']['unverified']===1
    && count($work['items'])<=3 && $work['has_more'],'verified outcomes, missing source evidence and bounded preview coverage remain distinct');
$json=json_encode($snapshot,JSON_THROW_ON_ERROR);
ft_check(!str_contains($json,$foreign['row']['id']) && !str_contains($json,$peer['row']['id']) && !str_contains($json,$orphan['row']['id']),
    'another owner, another Agent and a deleted owner marker cannot enter current follow-through previews');
$pdo->prepare('DELETE FROM chat_messages WHERE id=?')->execute([$reply['reply_target']['message_id']]);$work=$read()['followthrough'];
ft_check($work['counts']['replied']===0 && $work['counts']['unverified']===2 && ft_row($replied['row']['id'])['outcome']==='replied',
    'deleted reply evidence withdraws a verified outcome without turning it into no-reply eligibility');

$GLOBALS['vp3_capability_turn_v1']=['owner_user_id'=>1,'owner_conversation'=>true,'checked_at'=>gmdate(DATE_ATOM),
    'agent_id'=>7,'conversation_id'=>11,'knowledge_scope'=>['mode'=>'all'],'capabilities'=>[]];
$model=agent_gmail_workspace_model_item_v1($pdo,$owner,7,11);
ft_check($model!==null && str_contains($model['text'],'DATA ONLY') && str_contains($model['text'],'exact sent thread at checked_at')
    && str_contains($model['text'],$ready['row']['id']),'main Agent context receives bounded current deadlines as untrusted saved data with explicit scope');
foreach(['off','folder','project'] as $mode){
    $GLOBALS['vp3_capability_turn_v1']['knowledge_scope']['mode']=$mode;
    ft_check(agent_gmail_workspace_model_item_v1($pdo,$owner,7,11)===null,$mode.' knowledge scope cannot acquire automatic Gmail deadline context');
}
$GLOBALS['vp3_capability_turn_v1']['knowledge_scope']['mode']='all';
$raw=['surface'=>'chat','conversation_id'=>11,'participants'=>['participants'=>[['speaker_label'=>'Guest','recognized'=>false]]]];
ft_check(!array_filter(agent_surface_v131_context_items($raw),static fn($item)=>$item['source']==='gmail-workspace:current'),
    'selected-participant surface cannot receive unrelated private response deadlines');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'participant'=>44];
ft_check(agent_gmail_workspace_model_item_v1($pdo,$owner,7,11)===null,'participant resource authority suppresses the shared Gmail context');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$events=agent_gmail_workspace_events_v1($snapshot);$eventJson=json_encode($events,JSON_THROW_ON_ERROR);
ft_check(count($events)===2 && max(array_map(static fn($event)=>mb_strlen($event['summary']),$events))<=360
    && str_contains($eventJson,'owner deadlines') && str_contains($eventJson,'not loaded') && !str_contains($eventJson,'Exact subject')
    && !str_contains($eventJson,'recipient@') && !str_contains($eventJson,'PRIVATE_'),
    'HomeServer receives the same bounded counts and scope without subjects, recipients or mail content');

// Inbox matters remain their own lifecycle; an exact selected-Agent deadline is an additive receipt projection.
$pdo->prepare('INSERT INTO agent_connection_gmail_inbox_v1(owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,enabled_at_ms)
    VALUES(1,17,?,1,\'{}\',?,?)')->execute([str_repeat('a',64),str_repeat('c',32),(time()-3600)*1000]);
$scope=agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);$policy=$scope['policy'];
foreach([$ready,$peer] as $fixture)agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_matters_note_send_v1($pdo,$policy,$fixture['receipt'],null,
    'Matter '.$fixture['watch']['thread_id']));
$before=ft_state();$matters=agent_email_matters_summary_v1($pdo,$scope+['agent_id'=>7]);$mine=null;$other=null;
foreach($matters['items'] as $item){if(str_contains($item['subject'],'context_ready'))$mine=$item;else $other=$item;}
ft_check($mine['response_followthrough']['followthrough_ref']===$ready['row']['id']
    && $mine['response_followthrough']['outcome']==='overdue' && $mine['response_followthrough']['deadline_at']!==$mine['next_wake_at'],
    'Top of Mind links an owner response deadline separately from the native watch retention date');
ft_check(!isset($other['response_followthrough']) && ft_state()===$before,
    'matter projection neither exposes another Agent deadline nor changes Inbox work state');
ft_check(!array_filter(agent_email_matters_summary_v1($pdo,$scope)['items'],static fn($item)=>isset($item['response_followthrough'])),
    'mailbox-only callers receive no broad deadline overlay without a selected Agent');
agent_email_followthrough_update_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$ready['row']['id'],'expected_revision'=>1,'operation'=>'resolve'],'fixture');
$matters=agent_email_matters_summary_v1($pdo,$scope+['agent_id'=>7]);
$mine=array_values(array_filter($matters['items'],static fn($item)=>str_contains($item['subject'],'context_ready')))[0];
ft_check($mine['response_followthrough']['outcome']==='resolved' && $mine['response_followthrough']['check']['result']===null,
    'explicit response resolution is available to supersede a derivative ended-watch display without claiming a reply');

$pdo->exec('UPDATE agent_connections_v1 SET followups_enabled=0 WHERE id=17');$disabled=$read();
ft_check($disabled['followthrough']['state']==='disabled' && $disabled['followthrough']['counts']===null
    && !$disabled['capabilities']['response_deadlines']['available'],'revoked follow-up permission is unavailable rather than zero deadlines');
$pdo->exec('UPDATE agent_connections_v1 SET followups_enabled=1,read_enabled=0 WHERE id=17');
ft_check($read()['followthrough']['state']==='unavailable' && $read()['followthrough']['counts']===null,
    'read revocation cannot retain a cached deadline summary');
$pdo->exec('UPDATE agent_connections_v1 SET read_enabled=1 WHERE id=17');
$pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=17')->execute([str_repeat('d',64)]);
ft_check(array_sum($read()['followthrough']['counts'])===0,'replacement Gmail grant excludes prior-mailbox deadlines');
$pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=17')->execute([str_repeat('a',64)]);
$pdo->exec('RENAME TABLE agent_email_followthrough_v1 TO fixture_unavailable_followthrough');
ft_check($read()['followthrough']['counts']===null && !$read()['capabilities']['response_deadlines']['available'],
    'missing follow-through storage is explicitly unavailable and does not advertise a usable capability');
$pdo->exec('RENAME TABLE fixture_unavailable_followthrough TO agent_email_followthrough_v1');

echo 'GMAIL_FOLLOWTHROUGH_CONTEXT=PASS ('.$GLOBALS['ft_checks'].' assertions, mysql)'.PHP_EOL;
