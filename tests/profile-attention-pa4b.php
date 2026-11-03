<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/profile-attention-pa4b.php';
$checks=0;
function pa4b_check(bool $ok,string $name): void{if(!$ok)throw new RuntimeException($name);$GLOBALS['checks']++;}
function pa4b_denied(callable $run,string $name,?int $status=null): void
{
    try{$run();}catch(VP3ProfileAttentionException $e){pa4b_check($status===null||$e->httpStatus===$status,$name.' status');return;}throw new RuntimeException($name.' was accepted');
}

pa4b_reset();$n=pa4b_note();$before=pa4b_snapshot();$state=pa4b_readonly(fn()=>vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope()));
pa4b_check($state['complete']&&$state['voice_allowed']===false&&count($state['items'])===1,'current exact source has a complete text-only list');
pa4b_check($state['items'][0]['source_status']==='verified'&&$state['items'][0]['source_label']==='Visitor A','voluntary display identity uses immutable native actor');
pa4b_check(pa4b_snapshot()===$before,'owner GET is read-only');
pa4b_check(pa3_count('profile_agent_messages')===0&&pa3_count('profile_agent_conversations')===0,'page event does not fabricate a public conversation or message');
$input=pa4b_input($n);$shown=vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),$input);
pa4b_check($shown['item']['delivery_location']==='current'&&!$shown['item']['presented']&&!$shown['item']['read'],'inserting a marker does not mean visible or read');
$q=$pdo->prepare('SELECT * FROM chat_messages WHERE id=?');$q->execute([$shown['chat_message_id']]);$marker=$q->fetch();
pa4b_check($marker['message']==='Profile attention update.'&&!str_contains($marker['message'],'Visitor A'),'trusted private assistant history stores only a neutral marker');
pa4b_check(pa3_count('profile_event_projections_pa4b',"destination_kind='brain_archive'")===1,'delivery atomically records its exact Brain obligation');
$again=vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),$input);
pa4b_check($again['duplicate']&&$again['chat_message_id']===$shown['chat_message_id']&&pa3_count('profile_attention_deliveries_pa4b')===1,'same request repeats the saved delivery once');
$elsewhere=vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),pa4b_input($shown,['conversation_id'=>12,'agent_id'=>8]));
pa4b_check($elsewhere['item']['delivery_location']==='elsewhere'&&$elsewhere['chat_message_id']===$shown['chat_message_id']&&pa3_count('chat_messages','conversation_id=12')===0,'another current Chat receives elsewhere without copying or switching');
$ack=pa4b_input($shown,['delivery_id'=>$shown['delivery_id'],'chat_message_id'=>$shown['chat_message_id'],'visible'=>true,'tab_id'=>str_repeat('a',32),'client_instance_id'=>str_repeat('c',32)]);
pa4b_denied(fn()=>vp3_profile_attention_ack_pa4b($pdo,pa4b_owner(),array_replace($ack,['visible'=>false])),'hidden card cannot ACK',422);
$visible=vp3_profile_attention_ack_pa4b($pdo,pa4b_owner(),$ack);
pa4b_check($visible['item']['presented']&&!$visible['item']['read']&&$visible['item']['disposition']==='pending','visible acknowledgement leaves read and disposition separate');
pa4b_check(!$visible['item']['eligible']&&$visible['item']['reason']==='presented','presented generation cannot repeatedly solicit automatic presentation');
pa4b_check(vp3_profile_attention_ack_pa4b($pdo,pa4b_owner(),$ack)['duplicate'],'same visible acknowledgement is idempotent');
$read=vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),pa4b_input($visible,['operation'=>'read']));
pa4b_check($read['item']['read']&&$read['item']['presented']&&$read['item']['disposition']==='pending','explicit read leaves disposition pending');
$snoozed=vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),pa4b_input($read,['operation'=>'snooze']));
pa4b_check($snoozed['item']['disposition']==='snoozed'&&!$snoozed['item']['eligible']&&$snoozed['item']['read'],'snooze preserves previous read receipt');
$GLOBALS['pa3Now']+=86400001;$before=pa4b_snapshot();$due=pa4b_readonly(fn()=>vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope(['after_id'=>999999])));
pa4b_check(count($due['items'])===1&&$due['items'][0]['wake_due']&&$due['items'][0]['eligible'],'expired snooze is visible even behind the notification cursor');
pa4b_check(pa4b_snapshot()===$before&&$due['items'][0]['wake_generation']===1,'GET exposes due eligibility without advancing the durable generation');
pa4b_readonly(function()use($pdo){profile_agent_reconcile_notifications_v1341($pdo,pa3_owner(4));profile_agent_reconcile_notifications_v1341($pdo,pa4b_owner());});
pa4b_check(pa4b_snapshot()===$before,'legacy notification reads cannot run global or other-owner recovery');
$wake=vp3_profile_attention_maintenance_pa4b($pdo);$fresh=pa4b_fresh($n);
pa4b_check($wake['woken']===1&&$fresh['wake_generation']===2&&!$fresh['read'],'maintenance wakes once into a new unread generation');
pa4b_check(vp3_profile_attention_maintenance_pa4b($pdo)['woken']===0&&pa3_count('profile_events')===1,'repeat wake creates no event or extra generation');
pa4b_denied(fn()=>vp3_profile_attention_ack_pa4b($pdo,pa4b_owner(),array_replace($ack,['request_id'=>bin2hex(random_bytes(16))])),'old visible acknowledgement cannot revive or read the next wake');
$renewed=vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),pa4b_input($fresh));
pa4b_check($renewed['delivery_id']!==$shown['delivery_id']&&pa3_count('profile_attention_deliveries_pa4b')===2,'each wake gets at most one exact delivery');
$old=vp3_profile_attention_item_pa4b($pdo,pa4b_owner(),pa4b_scope(['delivery_id'=>$shown['delivery_id']]));
pa4b_check(!$old['item']['eligible']&&$old['item']['reason']==='superseded','old private marker restores without reactivating an earlier generation');
$handledInput=pa4b_input($renewed,['operation'=>'handled']);$handled=vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),$handledInput);
pa4b_check($handled['item']['disposition']==='handled'&&!$handled['item']['read']&&$handled['item']['can_read'],'handled and unread are independent');
pa4b_check(vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),$handledInput)['duplicate'],'terminal operation retry returns the same durable result');
foreach(['snooze','ignored','handled'] as $op)pa4b_denied(fn()=>vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),pa4b_input($handled,['operation'=>$op])),'terminal cannot transition '.$op);
$terminalRead=vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),pa4b_input($handled,['operation'=>'read']));pa4b_check($terminalRead['item']['read']&&$terminalRead['item']['disposition']==='handled','terminal item can be explicitly read without reopening');
pa4b_check($GLOBALS['pa3ModelCalls']===[]&&$GLOBALS['psProviderCalls']===[]&&$GLOBALS['pa3cProviderCalls']===[]&&pa3_count('agent_workflow_runs')===0,'attention lifecycle starts no model, speech, call or public workflow');

pa4b_reset();$m=pa4b_message('PUBLIC_STATEMENT <script>not a private instruction</script>');$item=$m['item'];
pa4b_check($item['source_role']==='visitor'&&$item['public_message_id']===$m['public_message_id']&&str_contains($item['display_text'],'PUBLIC_STATEMENT'),'exact persisted public statement has typed visitor attribution');
$saved=vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),pa4b_input($m));
pa4b_check(!str_contains(json_encode($pdo->query('SELECT message,context_json FROM chat_messages')->fetchAll()),'PUBLIC_STATEMENT'),'public statement never becomes a trusted assistant instruction');
$pdo->exec("UPDATE profile_visit_sessions SET visitor_user_id=3,identity_disclosed=1");$item=pa4b_fresh($m);
pa4b_check($item['source_status']==='verified'&&$item['source_label']==='Visitor A','mutating aggregate CRM actor cannot relabel exact native message');
$pdo->exec('UPDATE user_profiles SET share_visit_identity=0 WHERE user_id=2');$item=pa4b_fresh($m);
pa4b_check($item['source_status']==='verified'&&$item['source_label']==='A visitor','current disclosure opt-out removes the saved actor label');
$pdo->exec("UPDATE users SET password_hash='rotated' WHERE id=2");$item=pa4b_fresh($m);
pa4b_check($item['source_status']==='unavailable'&&!str_contains($item['display_text'],'PUBLIC_STATEMENT')&&!$item['eligible'],'password rotation revokes source presentation');
pa4b_denied(fn()=>vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),pa4b_input($item)),'revoked source cannot present');
$clean=vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),pa4b_input($item,['operation'=>'ignored']));
pa4b_check($clean['item']['disposition']==='ignored'&&!str_contains(json_encode($clean),'PUBLIC_STATEMENT'),'explicit cleanup of revoked owned attention reveals no source body');
$clean=vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),pa4b_input($clean,['operation'=>'read']));pa4b_check($clean['item']['read'],'revoked owned item can be explicitly read');

foreach(['permission','block','profile','agent','message'] as $change){
    pa4b_reset();$m=pa4b_message();
    match($change){'permission'=>$pdo->exec("DELETE FROM role_permissions WHERE permission_key='profile_chat.access'"),'block'=>$pdo->exec('INSERT INTO user_blocks(blocker_user_id,blocked_user_id) VALUES(2,1)'),'profile'=>$pdo->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1'),'agent'=>$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7'),'message'=>$pdo->prepare('UPDATE profile_agent_messages SET message=? WHERE id=?')->execute(['changed',$m['public_message_id']])};
    $source=vp3_profile_attention_source_pa4b($pdo,pa4b_owner(),(int)$m['notification']['id']);
    pa4b_check($source['status']==='unavailable'&&!str_contains($source['projection']['body'],'PUBLIC_STATEMENT'),'current '.$change.' change revokes exact source');
}

pa4b_reset();$n=pa4b_note();$pdo->prepare('UPDATE profile_events SET metadata_json=? WHERE id=?')->execute(['{}',(int)$n['event']['id']]);$old=pa4b_fresh($n);
pa4b_check($old['source_status']==='historical_unverified'&&$old['source_label']==='Profile source'&&!$old['eligible'],'unproved historical CRM event remains neutral');
$clean=vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),pa4b_input($old,['operation'=>'read']));pa4b_check($clean['item']['read'],'historical owned notification can be cleared without fabricating source proof');

pa4b_reset();$m=pp_message();$eventId=$m['event_id'];$pdo->exec('DELETE FROM profile_speech_items_pa4a');$q=$pdo->prepare('SELECT metadata_json FROM profile_events WHERE id=?');$q->execute([$eventId]);$metadata=json_decode($q->fetchColumn(),true);unset($metadata['source_authority']);$pdo->prepare('UPDATE profile_events SET metadata_json=? WHERE id=?')->execute([json_encode($metadata),$eventId]);
$pdo->prepare("UPDATE profile_agent_conversations SET status='owner_joined' WHERE id=?")->execute([(int)$m['turn']['conversation_id']]);
$source=vp3_profile_event_source_pa4b($pdo,pa4b_owner(),$eventId);pa4b_check($source['status']==='verified','exact pre-speech PA3 accepted proof remains readable after owner joins');

pa4b_reset();$n=pa4b_note();$before=pa4b_snapshot();$pdo->failSql='INSERT INTO profile_event_projections_pa4b';
try{vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),pa4b_input($n));throw new RuntimeException('Projection failure was accepted');}catch(PDOException $e){}finally{$pdo->failSql='';}
pa4b_check(pa4b_snapshot()===$before,'failed projection obligation rolls back delivery and private marker');
$pdo->beginTransaction();$pdo->failSql='INSERT INTO profile_attention_operations_pa4b';
try{vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),pa4b_input($n));throw new RuntimeException('Receipt failure was accepted');}catch(PDOException $e){}finally{$pdo->failSql='';}
$pdo->commit();pa4b_check(pa4b_snapshot()===$before,'savepoint prevents caller catch-and-commit of an orphan marker');

pa4b_reset();$n=pa4b_note();$pdo->afterSql=function(string $sql,PA3PDO $p){if(str_starts_with($sql,'INSERT INTO chat_messages')){$p->afterSql=null;$p->exec('UPDATE user_agents SET is_active=0 WHERE id=7');}};
pa4b_denied(fn()=>vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),pa4b_input($n)),'late Agent revoke rolls back private delivery');$pdo->afterSql=null;
pa4b_check(pa3_count('profile_attention_deliveries_pa4b')===0&&pa3_count('chat_messages')===2,'late denied presentation publishes nothing');

pa4b_reset();$n=pa4b_note();$pdo->prepare('DELETE FROM notifications WHERE id=?')->execute([(int)$n['notification']['id']]);
$before=pa4b_snapshot();pa4b_readonly(fn()=>vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope()));pa4b_check(pa4b_snapshot()===$before,'missing notification GET does not repair storage');
$repair=vp3_profile_attention_maintenance_pa4b($pdo);pa4b_check($repair['notifications_repaired']===1&&vp3_profile_attention_maintenance_pa4b($pdo)['notifications_repaired']===0,'explicit maintenance repairs a missing notification once');
pa4b_check(pa3_count('profile_events')===1&&$GLOBALS['pa3ModelCalls']===[],'notification repair never repeats public activity');
$pdo->exec("INSERT INTO notifications(user_id,type,title,body,source_type,source_id,is_read) VALUES(1,'profile_view','Unproved','SECRET','profile_event',99999,0)");$state=vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope());
pa4b_check(!$state['complete']&&$state['counts']===null&&count($state['errors'])===1&&!str_contains(json_encode($state),'SECRET'),'orphan source is an explicit partial read, not caught up or raw text');
$pdo->failSql='SELECT COUNT(*) FROM notifications';try{vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope());throw new RuntimeException('Failed count was treated as zero');}catch(PDOException $e){pa4b_check(true,'failed mandatory read remains a failure');}finally{$pdo->failSql='';}

pa4b_reset();$n=pa4b_note();mark_notification_read((int)$n['notification']['id'],1);mark_all_notifications_read(1);
pa4b_check(!pa4b_fresh($n)['read']&&(int)$pdo->query('SELECT is_read FROM notifications')->fetchColumn()===0,'legacy read helpers cannot bypass captured Profile generation');
foreach([['conversation_id'=>13,'agent_id'=>9],['conversation_id'=>11,'agent_id'=>8],['conversation_id'=>0,'agent_id'=>7]] as $bad)pa4b_denied(fn()=>vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),pa4b_input($n,$bad)),'invalid selected private scope',isset($bad['conversation_id'])&&$bad['conversation_id']===0?422:403);
pa4b_denied(fn()=>vp3_profile_attention_state_pa4b($pdo,pa3_owner(4),pa4b_scope()),'foreign owner cannot select private scope',403);
$pdo->exec('ALTER TABLE profile_attention_deliveries_pa4b RENAME COLUMN source_hash TO missing_source_hash');$before=pa3_count('profile_events');
pa4b_denied(fn()=>pa4b_readonly(fn()=>vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope())),'partial schema does not appear empty',503);
pa4b_check(vp3_profile_attention_maintenance_pa4b($pdo)['reason']==='upgrade_required'&&pa3_count('profile_events')===$before,'incomplete maintenance performs no DDL or source work');
$pdo->exec('ALTER TABLE profile_attention_deliveries_pa4b RENAME COLUMN missing_source_hash TO source_hash');

pa4b_reset();$m=pa4b_message();$d=vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),pa4b_input($m));
$pdo->afterSql=static function(string $sql,PA3PDO $p):void{if(str_starts_with($sql,'SELECT m.role,m.user_id,m.message,m.context_json')){$p->afterSql=null;$p->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1');}};
$view=vp3_profile_attention_item_pa4b($pdo,pa4b_owner(),pa4b_scope(['delivery_id'=>$d['delivery_id']]));$pdo->afterSql=null;
pa4b_check($view['item']['source_status']==='unavailable'&&!str_contains($view['item']['display_text'],'PUBLIC_VISITOR_STATEMENT'),'source is revalidated after the last marker read');

pa4b_reset();$n=pa4b_note();$pdo->exec('UPDATE user_profiles SET share_visit_identity=0 WHERE user_id=2');
$rows=notification_recent(pa4b_owner(),25);$runtime=profile_runtime_attention_list($pdo,1);$legacy=profile_attention_list($pdo,1);
pa4b_check(!str_contains(json_encode([$rows,$runtime,$legacy]),'Visitor A')&&!str_contains(json_encode([$rows,$runtime,$legacy]),'SAVED_UNTRUSTED_SUMMARY'),'generic notification and both portal lists honor current identity disclosure and source body');
pa4b_check($runtime[0]['visitor_label']==='A visitor'&&$runtime[0]['source_status']==='verified','portal list retains exact authorized source with anonymous current label');
$pdo->prepare('UPDATE profile_events SET metadata_json=? WHERE id=?')->execute(['{}',(int)$n['event']['id']]);
$neutral=[notification_recent(pa4b_owner()),profile_runtime_attention_list($pdo,1)];
pa4b_check(!str_contains(json_encode($neutral),'Visitor A')&&!str_contains(json_encode($neutral),'SAVED_UNTRUSTED_SUMMARY')&&$neutral[1][0]['source_status']==='historical_unverified','unproved legacy actor and saved summary remain neutral on generic surfaces');

$api=file_get_contents(dirname(__DIR__).'/api/chat-notifications-brain-v240.php');$start=strpos($api,'function chat_notifications_v240_json');$end=strpos($api,"\n\$user = current_user();");eval(substr($api,$start,$end-$start));
$pdo->exec("INSERT INTO notifications(user_id,type,title,body,target_url,source_type,source_id,is_read) VALUES(1,'profile_view','UNBOUND_NAME','UNBOUND_PUBLIC_INSTRUCTION','/private-target','','0',0)");$unbound=(int)$pdo->lastInsertId();$before=pa4b_snapshot();
$blocked=chat_notifications_v240_present_attention($pdo,pa4b_owner(),['notification_id'=>$unbound]);
pa4b_check(!$blocked['handled']&&$blocked['voice_allowed']===false&&$blocked['source_status']==='historical_unverified'&&pa4b_snapshot()===$before,'unbound Profile display type cannot produce private Chat text or a speech grant');
$generic=notification_recent(pa4b_owner(),25);pa4b_check(!str_contains(json_encode($generic),'UNBOUND_NAME')&&!str_contains(json_encode($generic),'UNBOUND_PUBLIC_INSTRUCTION')&&!str_contains(json_encode($generic),'/private-target'),'unbound legacy notification reads have no saved content or target fallback');

pa4b_reset();$ctx=pa4b_context();for($i=0;$i<65;$i++)pa4b_note($ctx);$seen=[];$paged=[];$before=pa4b_snapshot();
for($page=0;$page<3;$page++){
    $batch=pa4b_readonly(fn()=>vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope(['page'=>$page])));
    pa4b_check($batch['page']===$page&&$batch['page_size']===25&&count($batch['items'])===($page===2?15:25)&&$batch['has_more']===($page<2)&&$batch['counts']===['unread'=>65,'attention'=>65],'explicit Profile page '.$page.' is bounded with all-owner counts');
    $paged=array_merge($paged,$batch['items']);
}
pa4b_check(count(array_unique(array_column($paged,'notification_id')))===65&&pa4b_snapshot()===$before,'all 65 real Profile sources remain reachable without presentation, ACK or read writes');
pa4b_denied(fn()=>vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope(['page'=>100001])),'page above the bounded range is rejected',422);
pa4b_denied(fn()=>vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope(['page'=>-1])),'negative page is rejected',422);
for($page=0;$page<4;$page++){
    $batch=vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope());
    foreach($batch['items'] as $item){if(!$item['eligible'])continue;$shown=vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),pa4b_input($item));vp3_profile_attention_ack_pa4b($pdo,pa4b_owner(),pa4b_input($shown,['delivery_id'=>$shown['delivery_id'],'chat_message_id'=>$shown['chat_message_id'],'visible'=>true,'tab_id'=>str_repeat('a',32),'client_instance_id'=>str_repeat('c',32)]));$seen[]=$item['notification_id'];}
}
pa4b_check(count($seen)===65&&count(array_unique($seen))===65&&pa3_count('profile_attention_deliveries_pa4b')===65,'bounded current polling drains 65 exact unpresented sources without cursor loss or duplicate markers');
pa4b_check(pa3_count('profile_events')===65&&$GLOBALS['psProviderCalls']===[]&&$GLOBALS['pa3ModelCalls']===[],'backlog drain creates no new source or provider action');
foreach(array_slice($paged,0,30) as $item)vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),pa4b_input(pa4b_fresh($item),['operation'=>'read']));
foreach(array_slice($paged,30,10) as $item)vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),pa4b_input(pa4b_fresh($item),['operation'=>'handled']));
$before=pa4b_snapshot();$remaining=vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope(['page'=>0]));
pa4b_check(array_column($remaining['items'],'notification_id')===array_column(array_slice($paged,40),'notification_id')&&$remaining['counts']===['unread'=>35,'attention'=>55],'read and handled history cannot hide the remaining 25 unread active Profile sources');
$history=[];for($page=0;$page<3;$page++)$history=array_merge($history,vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope(['page'=>$page]))['items']);
pa4b_check(count($history)===65&&count(array_unique(array_column($history,'notification_id')))===65&&count(array_filter($history,fn(array $item):bool=>$item['disposition']==='handled'))===10&&pa4b_snapshot()===$before,'explicit pages keep read and terminal history reachable without reviving or acknowledging it');
$beyond=vp3_profile_attention_state_pa4b($pdo,pa4b_owner(),pa4b_scope(['page'=>3]));pa4b_check($beyond['items']===[]&&!$beyond['has_more']&&$beyond['counts']===['unread'=>35,'attention'=>55],'out-of-range current page is empty without losing global attention counts');

echo 'PROFILE_ATTENTION_PA4B=PASS ('.$checks.' checks; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
