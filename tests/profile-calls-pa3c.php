<?php
declare(strict_types=1);

require_once __DIR__.'/fixtures/profile-calls-pa3c.php';
$checks=0;
function pcCheck(bool $ok,string $label): void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'ok '.$checks.' - '.$label."\n";}
function pcReject(callable $work,string $label,?string $code=null): Throwable
{
    try{$work();}catch(Throwable $error){pcCheck($code===null||($error instanceof VP3ProfileCallException&&$error->publicCode===$code),$label);return $error;}throw new RuntimeException('FAIL: '.$label.' (was accepted)');
}
function pcRow(int $id): array{return vp3_profile_call_row_pa3c(db(),$id)??throw new RuntimeException('Missing call.');}
function pcCall(int $id,string $role='owner'): array{return vp3_profile_call_projection_pa3c(pcRow($id),$role);}
function pcAccepted(string $mode='audio',string $offerRole='visitor'): array
{
    $offered=pa3c_offer($offerRole,null,$mode);
    $accepted=$offerRole==='visitor'?vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input($offered,'accept')):vp3_profile_call_visitor_action_pa3c(db(),$offered['context'],pa3c_visitor_input($offered,'accept'));
    return $accepted+['context'=>$offered['context'],'offer'=>$offered];
}
function pcReady(string $mode='audio'): array
{
    $call=pcAccepted($mode);vp3_profile_call_work_pa3c(db(),$call['call_id'],'pa3c_provider');return $call;
}
function pcJoin(array $call,string $role='owner',string $instance='c',?array $input=null): array
{
    $fields=['client_instance_id'=>str_repeat($instance,32),'microphone'=>true,'camera'=>false];$input??=$role==='owner'?pa3c_owner_input(pcCall($call['call_id']),'join',$fields):pa3c_visitor_input(pcCall($call['call_id'],'visitor'),'join',$fields);
    return vp3_profile_call_join_pa3c(db(),$role,$role==='owner'?pa3c_owner():$call['context'],$input,'pa3c_provider');
}
function pcDue(int $id): void
{
    $row=pcRow($id);db()->prepare('UPDATE profile_calls_pa3c SET provider_lease_until_ms=0,cleanup_not_before_ms=0 WHERE id=?')->execute([$id]);
    if((int)$row['cleanup_run_id']>0){db()->prepare("UPDATE agent_workflow_runs SET next_attempt_at='2000-01-01',lease_expires_at=CASE WHEN status='executing' THEN '2000-01-01' ELSE lease_expires_at END WHERE id=?")->execute([(int)$row['cleanup_run_id']]);db()->prepare("UPDATE agent_workflow_actions SET available_at='2000-01-01' WHERE id=?")->execute([(int)$row['cleanup_action_id']]);}
}
function pcOther(): array
{
    pa3_login(0);$_COOKIE=['sf_profile_visitor'=>str_repeat('e',64),'sf_profile_conversation'=>str_repeat('f',64)];$ctx=vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'other-owner'),null);
    vp3_profile_handoff_presence_pa3(db(),pa3_owner(4),['tab_id'=>str_repeat('e',32),'chat_conversation_id'=>13,'chat_agent_id'=>9,'visible'=>true,'presence_sequence'=>1]);
    $started=vp3_profile_agent_public_start_v1(db(),$ctx);$cid=$started['conversation']['conversation_id'];$offer=vp3_profile_call_visitor_action_pa3c(db(),$ctx,['operation'=>'offer','request_id'=>bin2hex(random_bytes(16)),'conversation_id'=>$cid,'mode'=>'audio']);
    $delivery=vp3_profile_call_owner_delivery_pa3c(db(),pa3_owner(4),['call_id'=>$offer['call_id'],'revision'=>$offer['revision'],'chat_conversation_id'=>13,'chat_agent_id'=>9]);
    $input=['operation'=>'accept','request_id'=>bin2hex(random_bytes(16)),'conversation_id'=>$cid,'call_id'=>$offer['call_id'],'revision'=>$offer['revision'],'chat_conversation_id'=>13,'chat_agent_id'=>9,'delivery_id'=>$delivery['delivery_id']];
    return vp3_profile_call_owner_action_pa3c(db(),pa3_owner(4),$input)+['context'=>$ctx];
}

pa3c_reset();$start=pa3c_start();$before=pa3_count('profile_calls_pa3c');$state=vp3_profile_call_visitor_state_pa3c(db(),$start['context'],$start['conversation_id']);
pcCheck($state['can_offer']===true&&$state['calls']===[],'read-only native capability discovery');
pcCheck(pa3_count('profile_calls_pa3c')===$before&&count($GLOBALS['pa3cProviderCalls'])===0,'reads create no call, provider work or media');
$blank=vp3_profile_call_owner_list_pa3c(db(),pa3c_owner(),['chat_conversation_id'=>0,'chat_agent_id'=>7]);pcCheck($blank['calls']===[]&&$blank['can_offer']===false,'blank private Chat does not target arbitrary visitors');
$offer=pa3c_offer();$cid=$offer['conversation_id'];pcCheck($offer['call']['state']==='offered'&&$offer['call']['mode']==='audio','visitor consent produces exact offered audio call');
pcCheck(pa3_count('profile_agent_handoff_turns_pa3')===0&&pa3_count('agent_workflow_runs')===0&&pa3_count('profile_agent_messages',"sender_type<>'system'")===0,'call-only offer creates no fabricated text or inference turn');
pcReject(fn()=>vp3_profile_call_visitor_action_pa3c(db(),$offer['context'],pa3c_visitor_input($offer,'accept')),'offerer cannot manufacture second-party consent');
$input=pa3c_owner_input($offer,'accept');$accepted=vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),$input+['mode'=>'video']);
pcCheck($accepted['call']['state']==='preparing'&&$accepted['call']['mode']==='audio','recipient acceptance preserves exact offered media');
pcCheck((int)vp3_profile_call_slot_pa3c(db(),1)['active_call_id']===$offer['call_id']&&vp3_profile_handoff_thread_pa3(db(),1,$cid)['mode']==='owner','one owner slot and atomic thread takeover');
pcCheck(pa3_count('profile_agent_handoff_turns_pa3')===0&&pa3_count('agent_workflow_runs')===0,'acceptance still creates no fake visitor turn or model work');
$replay=vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),$input);pcCheck($replay['duplicate']===true&&$replay['call_id']===$offer['call_id'],'exact lost acceptance response replays safely');
pcReject(fn()=>vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),array_replace($input,['operation'=>'end'])),'request key cannot be rebound to another operation');
$serialized=json_encode(vp3_profile_call_owner_list_pa3c(db(),pa3c_owner(),['chat_conversation_id'=>11,'chat_agent_id'=>7]));pcCheck(!str_contains($serialized,'PRIVATE_CHAT_SECRET')&&!str_contains($serialized,'credential_hash')&&!str_contains($serialized,'encrypted-fixture'),'owner call projections omit private Chat and provider authority');
$marker=db()->query("SELECT context_json FROM chat_messages WHERE message='Profile visitor call.'")->fetchColumn();pcCheck(str_contains($marker,'profile_call')&&!str_contains($marker,'profile_handoff')&&!str_contains($marker,'credential'),'dedicated call marker contains selectors only');

pa3c_reset();$offer=pa3c_offer('owner',null,'video');pcCheck($offer['call']['offered_by']==='owner','owner can explicitly offer video');
pcReject(fn()=>vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input($offer,'accept')),'owner cannot accept their own offer');
$accept=vp3_profile_call_visitor_action_pa3c(db(),$offer['context'],pa3c_visitor_input($offer,'accept'));pcCheck($accept['call']['state']==='preparing','visitor accepts owner invitation');
pcReject(fn()=>vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),array_replace(pa3c_owner_input($accept,'end'),['chat_agent_id'=>8])),'private Chat Agent assignment is exact');
pcReject(fn()=>vp3_profile_call_owner_action_pa3c(db(),pa3_owner(4),pa3c_owner_input($accept,'end')),'foreign owner cannot control another call');
pa3_context(0,'c');pcReject(fn()=>vp3_profile_call_visitor_state_pa3c(db(),$offer['context'],$offer['conversation_id']),'another native browser cannot read old visitor call');
pa3c_reset();$offer=pa3c_offer();db()->exec('UPDATE profile_visit_sessions SET visitor_user_id=2,identity_disclosed=1');$card=vp3_profile_call_owner_list_pa3c(db(),pa3c_owner(),['chat_conversation_id'=>11,'chat_agent_id'=>7])['calls'][0];pcCheck($card['visitor_label']==='Visitor','CRM recognition never relabels an anonymous participant');
pa3c_reset();$ctx=pa3c_context(2);$offer=pa3c_offer('visitor',$ctx);db()->exec('UPDATE profile_visit_sessions SET visitor_user_id=3,identity_disclosed=1');$card=vp3_profile_call_owner_list_pa3c(db(),pa3c_owner(),['chat_conversation_id'=>11,'chat_agent_id'=>7])['calls'][0];pcCheck($card['visitor_label']==='Visitor','changed aggregate contact cannot assign another member name');
db()->exec("UPDATE users SET password_hash='changed-password' WHERE id=2");pcReject(fn()=>vp3_profile_call_visitor_action_pa3c(db(),$ctx,pa3c_visitor_input($offer,'cancel')),'member password revision revokes an old call');

pa3c_reset();$call=pcReady();$id=$call['call_id'];pcCheck(pcRow($id)['state']==='ready'&&count($GLOBALS['pa3cProviderCalls'])===1,'exact room proof prepares media without inventing connected people');
pcCheck(pcCall($id)['connection_verified']===true&&pcCall($id)['owner_connected']===false,'created room is not a connected call');
pcReject(fn()=>pcJoin($call,'owner','c',pa3c_owner_input(pcCall($id),'join',['client_instance_id'=>str_repeat('c',32),'microphone'=>false,'camera'=>false])),'explicit microphone consent required');
pcReject(fn()=>pcJoin($call,'owner','c',pa3c_owner_input(pcCall($id),'join',['client_instance_id'=>str_repeat('c',32),'microphone'=>true,'camera'=>true])),'audio offer cannot be upgraded to camera by join arguments');
$joinInput=pa3c_owner_input(pcCall($id),'join',['client_instance_id'=>str_repeat('c',32),'microphone'=>true,'camera'=>false]);$join=pcJoin($call,'owner','c',$joinInput);
pcCheck($join['role']==='owner'&&$join['authority_valid_until_ms']===$GLOBALS['pa3Now']+30000&&$join['expires_at_ms']-$join['issued_at_ms']===60000,'join has exact role and bounded token/authority leases');
pcCheck($join['participant_identity']!==$join['peer_identity']&&$join['participant_identity']===pcRow($id)['owner_identity'],'participant identity is server-derived and distinct');
$calls=count($GLOBALS['pa3cProviderCalls']);pcReject(fn()=>pcJoin($call,'owner','d'),'second tab cannot evict the existing role','PROFILE_CALL_IN_USE');pcCheck(count($GLOBALS['pa3cProviderCalls'])===$calls,'duplicate tab is rejected before token/provider work');
$again=pcJoin($call,'owner','c',$joinInput);pcCheck($again['duplicate']===true&&$again['join_intent_id']===$join['join_intent_id']&&$again['issued_at_ms']===$join['issued_at_ms'],'same-instance lost response retries exact join reservation');
$v=pcJoin($call,'visitor','d');pcCheck($v['participant_identity']===pcRow($id)['visitor_identity'],'second person gets only their exact role');
$all=json_encode(['calls'=>db()->query('SELECT * FROM profile_calls_pa3c')->fetchAll(),'operations'=>db()->query('SELECT * FROM profile_call_operations_pa3c')->fetchAll(),'messages'=>db()->query('SELECT * FROM chat_messages')->fetchAll()]);pcCheck(!str_contains($all,'SECRET_PARTICIPANT_TOKEN'),'signed participant tokens are never persisted');
$until=(int)pcRow($id)['owner_authority_until_ms'];$GLOBALS['pa3Now']+=5000;vp3_profile_call_owner_list_pa3c(db(),pa3c_owner(),['chat_conversation_id'=>11,'chat_agent_id'=>7]);pcCheck((int)pcRow($id)['owner_authority_until_ms']===$until,'GET-style reads do not renew media authority');
$beat=pa3c_owner_input(pcCall($id),'heartbeat',['client_instance_id'=>str_repeat('c',32)]);$beatResult=vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),$beat);pcCheck($beatResult['call']['authority_valid_until_ms']===$GLOBALS['pa3Now']+30000,'explicit authorized heartbeat renews the same role');
pcReject(fn()=>vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),array_replace($beat,['client_instance_id'=>str_repeat('e',32)])),'another instance cannot renew a role lease');
$room=pcRow($id)['room_name'];$GLOBALS['pa3cRooms'][$room]['owner']=true;$GLOBALS['pa3cRooms'][$room]['visitor']=true;$GLOBALS['pa3Now']+=6000;vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck(pcRow($id)['state']==='active'&&pcCall($id)['owner_connected']&&pcCall($id)['visitor_connected'],'both exact provider participants establish active state');
$GLOBALS['pa3Now']+=16000;pcCheck(pcCall($id)['state']==='ready'&&pcCall($id)['connection_verified']===false,'stale provider observation is not labelled live');

pa3c_reset();$call=pcReady();$id=$call['call_id'];$busyCode='';$peerInput=pa3c_visitor_input(pcCall($id),'join',['client_instance_id'=>str_repeat('d',32),'microphone'=>true,'camera'=>false]);
$GLOBALS['pa3cProviderHook']=function($operation)use($call,$peerInput,&$busyCode){if($operation!=='token')return;$GLOBALS['pa3cProviderHook']=null;try{pcJoin($call,'visitor','d',$peerInput);}catch(VP3ProfileCallException $e){$busyCode=$e->publicCode;}};
pcJoin($call);pcCheck($busyCode==='PROFILE_CALL_WORK_PENDING'&&pcRow($id)['state']==='ready','normal simultaneous second-person Join waits without ending first join');
$peer=pcJoin($call,'visitor','d',$peerInput);pcCheck($peer['role']==='visitor','same peer request succeeds after short provider lease releases');

pa3c_reset();$call=pcAccepted();$id=$call['call_id'];$during=[];
$GLOBALS['pa3cProviderHook']=function($operation)use($id,&$during){if($operation!=='create')return;$GLOBALS['pa3cProviderHook']=null;$end=vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input(pcCall($id),'end'));$during=vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');};
vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck(($during['reason']??'')==='provider_settling'&&pcRow($id)['state']==='ending','end cannot certify closure while CreateRoom is in flight');
pcCheck((int)vp3_profile_call_slot_pa3c(db(),1)['active_call_id']===$id&&pa3_count('agent_workflow_receipts')===0,'outstanding create retains owner slot and no success receipt');
$closed=vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck($closed['state']==='ended'&&!isset($GLOBALS['pa3cRooms'][pcRow($id)['room_name']]),'settled late create is cleaned before slot release');

pa3c_reset();$call=pcAccepted();$id=$call['call_id'];$GLOBALS['pa3cProviderMode']='lost_create';vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck(pcRow($id)['state']==='preparing'&&(int)pcRow($id)['provider_started_at_ms']>0,'lost CreateRoom response retains uncertain creation marker');
$GLOBALS['pa3cProviderMode']='';$GLOBALS['pa3Now']+=6000;vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck(pcRow($id)['state']==='ready'&&count(array_filter($GLOBALS['pa3cProviderCalls'],fn($c)=>$c['operation']==='create'))===1,'restart reconciles same room without duplicate create');

foreach(['agent','profile','permission','member'] as $revoke){
    pa3c_reset();$ctx=$revoke==='member'?pa3c_context(2):null;$offered=pa3c_offer('visitor',$ctx);$accepted=vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input($offered,'accept'));$id=$accepted['call_id'];
    $GLOBALS['pa3cProviderHook']=function($operation)use($revoke){if($operation!=='create')return;$GLOBALS['pa3cProviderHook']=null;match($revoke){'agent'=>db()->exec('UPDATE user_agents SET is_active=0 WHERE id=7'),'profile'=>db()->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1'),'permission'=>db()->exec("DELETE FROM role_permissions WHERE permission_key='profile_chat.access'"),'member'=>db()->exec("UPDATE users SET password_hash='revoked' WHERE id=2")};};
    vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck(pcRow($id)['state']==='ending','late '.$revoke.' revocation fences room readiness and queues cleanup');
    $closed=vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck($closed['state']==='ended','revoked '.$revoke.' still permits exact old-room cleanup');
}

pa3c_reset();$call=pcReady();$id=$call['call_id'];$GLOBALS['pa3cProviderHook']=function($operation)use($id){if($operation!=='token')return;$GLOBALS['pa3cProviderHook']=null;vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input(pcCall($id),'end'));};
pcReject(fn()=>pcJoin($call),'end wins against a late token response');pcCheck(pcRow($id)['state']==='ending'&&(int)pcRow($id)['owner_token_issued_at_ms']>0,'unreturned minted token retains revocation cutoff');
vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');$cleanup=end($GLOBALS['pa3cProviderCalls']);pcCheck($cleanup['operation']==='cleanup'&&$cleanup['binding']['revoke_before_sec']>=intdiv((int)pcRow($id)['owner_token_issued_at_ms'],1000)+1,'cleanup covers reserved token issuance even when response lost');

pa3c_reset();$call=pcReady();$id=$call['call_id'];$joinInput=pa3c_owner_input(pcCall($id),'join',['client_instance_id'=>str_repeat('c',32),'microphone'=>true,'camera'=>false]);$GLOBALS['pa3cProviderMode']='token_error';pcReject(fn()=>pcJoin($call,'owner','c',$joinInput),'transient token response stays safely retryable');pcCheck(pcRow($id)['state']==='ready','uncertain token response does not end peer call automatically');$GLOBALS['pa3cProviderMode']='';$join=pcJoin($call,'owner','c',$joinInput);pcCheck($join['duplicate']===true,'retry uses original durable token reservation');

pa3c_reset();$call=pcAccepted();$id=$call['call_id'];vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input(pcCall($id),'end'));$closed=vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');$receipt=json_decode((string)db()->query('SELECT result_json FROM agent_workflow_receipts')->fetchColumn(),true);
pcCheck($closed['state']==='ended'&&count($GLOBALS['pa3cProviderCalls'])===0,'provably never-started call closes without invented provider operations');
pcCheck(($receipt['closure_basis']??'')==='never_started'&&!empty($receipt['provider_not_started'])&&empty($receipt['participants_revoked']),'local closure receipt distinguishes no provider work from verified revocation');

pa3c_reset();$call=pcReady();$id=$call['call_id'];$input=pa3c_owner_input(pcCall($id),'end');db()->beginTransaction();db()->failSql='INSERT INTO agent_workflow_actions';pcReject(fn()=>vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),$input),'cleanup obligation creation failure rolls back only its savepoint');db()->failSql='';db()->commit();
pcCheck(pcRow($id)['state']==='ready'&&pa3_count('agent_workflow_runs')===0&&pa3_count('profile_call_operations_pa3c',"operation='end'")===0,'caller cannot commit ending without exact cleanup obligation');
vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),$input);db()->failSql='INSERT INTO agent_workflow_receipts';vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');db()->failSql='';
pcCheck(pcRow($id)['state']==='ending'&&(int)vp3_profile_call_slot_pa3c(db(),1)['active_call_id']===$id&&pa3_count('agent_workflow_receipts')===0,'lost cleanup receipt cannot commit ended state or release slot');
pcCheck(pcRow($id)['cleanup_proof_json']!==null,'verified provider cleanup proof survives a later failed receipt write');$providerCount=count($GLOBALS['pa3cProviderCalls']);pcDue($id);vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcDue($id);$closed=vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck($closed['state']==='ended'&&pa3_count('agent_workflow_receipts',"status='completed'")===1,'expired canonical lease safely retries receipt and commits one success');
pcCheck(count($GLOBALS['pa3cProviderCalls'])===$providerCount,'receipt retry uses saved exact proof without assuming revocation from an absent room');

pa3c_reset();$call=pcReady();$id=$call['call_id'];$saved=pcRow($id)['provider_snapshot'];vp3_profile_call_configuration_changed_pa3c(db());$GLOBALS['pa3cFingerprint']=hash('sha256','replacement-provider');$GLOBALS['pa3cReady']=false;
pcCheck(pcRow($id)['state']==='ending'&&pcRow($id)['provider_snapshot']===$saved,'rotation closes admission while retaining immutable original credentials');
vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');$last=end($GLOBALS['pa3cProviderCalls']);pcCheck($last['operation']==='cleanup'&&$last['binding']['provider_snapshot']===$saved&&pcRow($id)['state']==='ended','disabled or rotated config cleanup uses original provider');
pa3c_reset();$call=pcReady();$id=$call['call_id'];db()->exec('UPDATE users SET is_active=0 WHERE id=1');vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck(pcRow($id)['state']==='ended','soft-disabled owner does not strand exact authorized cleanup');

pa3c_reset();$call=pcReady();$id=$call['call_id'];vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input(pcCall($id),'end'));$GLOBALS['pa3cProviderMode']='cleanup_error';vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck(pcRow($id)['state']==='ending'&&vp3_profile_call_slot_pa3c(db(),1)['active_call_id']!==null,'unverified cleanup holds owner slot');
$returned=vp3_profile_call_visitor_action_pa3c(db(),$call['context'],pa3c_visitor_input(pcCall($id),'return_to_agent'));pcCheck(vp3_profile_handoff_thread_pa3(db(),1,$call['conversation_id'])['mode']==='agent'&&$returned['call']['cleanup_pending']===true,'explicit visitor fallback restores text while cleanup remains durable');
pcReject(fn()=>vp3_profile_call_visitor_action_pa3c(db(),$call['context'],pa3c_visitor_input(pcCall($id),'return_to_agent')),'old acceptance epoch cannot delegate twice');

pa3c_reset();$call=pcReady();$id=$call['call_id'];$admitted=pa3_admit('Owner question during call',$call['conversation_id']);$turn=vp3_profile_handoff_row_pa3(db(),$admitted['handoff']['turn_id']);
vp3_profile_handoff_owner_action_pa3(db(),pa3c_owner(),['operation'=>'reply','request_id'=>bin2hex(random_bytes(16)),'conversation_id'=>$call['conversation_id'],'turn_id'=>(int)$turn['id'],'revision'=>(int)$turn['version'],'message'=>'I will follow up personally.'],true);
vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input(pcCall($id),'end'));pcReject(fn()=>vp3_profile_call_visitor_action_pa3c(db(),$call['context'],pa3c_visitor_input(pcCall($id),'return_to_agent')),'later explicit owner reply wins over stale call fallback');
pcCheck(vp3_profile_handoff_thread_pa3(db(),1,$call['conversation_id'])['mode']==='owner','fallback never overrides newer owner handling');

pa3c_reset();$admitted=pa3_admit();$tid=$admitted['handoff']['turn_id'];$cid=$admitted['conversation']['conversation_id'];$offer=pa3c_offer();$acceptInput=pa3c_owner_input($offer,'accept');
vp3_profile_handoff_atomic_pa3(db(),function()use($cid){vp3_profile_handoff_conversation_pa3(db(),1,$cid,true);vp3_profile_handoff_mode_pa3(db(),1,$cid,'agent');});
$GLOBALS['pa3ModelHook']=function()use($acceptInput){$GLOBALS['pa3ModelHook']=null;vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),$acceptInput);};vp3_profile_handoff_dispatch_pa3(db(),$tid);
pcCheck(pcRow($offer['call_id'])['state']==='preparing'&&pa3_count('profile_agent_messages',"sender_type='agent'")===0,'call acceptance fences an Agent answer already in inference');

pa3c_reset();$call=pcReady();$id=$call['call_id'];pcJoin($call);$GLOBALS['pa3Now']+=30001;$r=vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck($r['state']==='ended'&&pcRow($id)['last_reason']==='heartbeat_expired','abandoned role expires and closes under the same durable cleanup');
pa3c_reset();$call=pcAccepted();$id=$call['call_id'];$GLOBALS['pa3Now']+=120001;vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck(pcRow($id)['state']==='ended'&&pcRow($id)['last_reason']==='connection_timeout','unjoined call times out without claiming a provider room');

pa3c_reset();$offer=pa3c_offer();vp3_profile_call_visitor_action_pa3c(db(),$offer['context'],pa3c_visitor_input($offer,'cancel'));db()->exec("UPDATE user_profiles SET bio='New biography',profile_agent_greeting='Hello again' WHERE user_id=1");$fresh=pa3c_context();$list=vp3_profile_call_visitor_state_pa3c(db(),$fresh,$offer['conversation_id']);pcCheck(count($list['calls'])===1&&$list['can_offer']===true,'harmless Profile prose edit does not poison historical call listing');
$GLOBALS['pa3Now']+=10;$next=pa3c_offer('visitor',$fresh);pcCheck($next['call_id']!==$offer['call_id'],'fresh valid call remains possible after ended history');

pa3c_reset();$ctx=pa3c_context();
for($i=0;$i<3;$i++){$start=vp3_profile_handoff_atomic_pa3(db(),fn()=>vp3_profile_agent_public_create_bound_v1(db(),$ctx));$offer=vp3_profile_call_visitor_action_pa3c(db(),$ctx,['operation'=>'offer','request_id'=>bin2hex(random_bytes(16)),'conversation_id'=>(int)$start['id'],'mode'=>'audio']);vp3_profile_call_visitor_action_pa3c(db(),$ctx,pa3c_visitor_input($offer,'cancel'));}
$start=vp3_profile_handoff_atomic_pa3(db(),fn()=>vp3_profile_agent_public_create_bound_v1(db(),$ctx));$error=pcReject(fn()=>vp3_profile_call_visitor_action_pa3c(db(),$ctx,['operation'=>'offer','request_id'=>bin2hex(random_bytes(16)),'conversation_id'=>(int)$start['id'],'mode'=>'audio']),'new conversation cannot reset visitor call rate','PROFILE_CALL_RATE_LIMITED');pcCheck($error->httpStatus===429&&$error->retryAfterSeconds>0,'rate rejection has bounded safe retry timing');
pa3c_reset();for($i=0;$i<6;$i++){$offer=pa3c_offer('owner');vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input($offer,'cancel'));}pcReject(fn()=>pa3c_offer('owner'),'owner-origin call offers are also bounded','PROFILE_CALL_RATE_LIMITED');

pa3c_reset();foreach(['b','c','d'] as $browser)pa3c_offer('visitor',pa3c_context(0,$browser));$other=pcOther();$result=vp3_profile_call_runner_pa3c(db(),1,'pa3c_provider');pcCheck($result['checked']===1&&pcRow($other['call_id'])['state']==='ready','unexpired offers cannot starve another owner due preparation');

pa3c_reset();$call=pcReady();$id=$call['call_id'];vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input(pcCall($id),'end'));$run=(int)pcRow($id)['cleanup_run_id'];
require_once dirname(__DIR__).'/includes/agent-work-control-v173.php';agent_work_control_ensure_schema_v173(db());agent_work_control_pause_v173(db(),pa3c_owner(),$run);$other=pcOther();$before=count($GLOBALS['pa3cProviderCalls']);vp3_profile_call_runner_pa3c(db(),1,'pa3c_provider');pcCheck(pcRow($other['call_id'])['state']==='ready'&&pcRow($id)['state']==='ending','paused cleanup cannot starve another owner media work');
pcCheck(db()->query('SELECT status FROM agent_workflow_runs WHERE id='.$run)->fetchColumn()==='paused'&&(int)vp3_profile_call_slot_pa3c(db(),1)['active_call_id']===$id,'runner preserves canonical manual pause and truthful cleanup obligation');

pa3c_reset();$call=pcReady();$id=$call['call_id'];vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input(pcCall($id),'end'));$run=(int)pcRow($id)['cleanup_run_id'];db()->prepare('UPDATE agent_workflow_runs SET requires_approval=1,approval_status=\'rejected\' WHERE id=?')->execute([$run]);$before=count($GLOBALS['pa3cProviderCalls']);pcReject(fn()=>vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider'),'cleanup cannot bypass changed canonical approval binding');pcCheck(count($GLOBALS['pa3cProviderCalls'])===$before&&pcRow($id)['state']==='ending','invalid job never invokes provider or releases owner');

foreach([[1,2],[2,1]] as [$blocker,$blocked]){
    pa3c_reset();$ctx=pa3c_context(2);db()->prepare('INSERT INTO user_blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$blocker,$blocked]);
    pcReject(fn()=>pa3c_offer('visitor',$ctx),'member call admission honors either direction of an existing block','PROFILE_CALL_UNAVAILABLE');
    pcCheck(pa3_count('profile_calls_pa3c')===0&&count($GLOBALS['pa3cProviderCalls'])===0,'blocked member creates no call or media operation');
}
pa3c_reset();$ctx=pa3c_context(2);$o=pa3c_offer('visitor',$ctx);$a=vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input($o,'accept'));$call=$a+['context'=>$ctx];$id=$call['call_id'];vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');
$GLOBALS['pa3cProviderHook']=function($operation){if($operation!=='token')return;$GLOBALS['pa3cProviderHook']=null;db()->exec('INSERT INTO user_blocks(blocker_user_id,blocked_user_id) VALUES(1,2)');};
pcReject(fn()=>pcJoin($call,'visitor','d'),'block committed during slow Join prevents credential disclosure','PROFILE_CALL_UNAVAILABLE');pcCheck(pcRow($id)['state']==='ending','late block retains a durable closure obligation');vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck(pcRow($id)['state']==='ended','blocked visitor cannot strand original-room cleanup');

pa3c_reset();$ctx=pa3c_context(2);$o=pa3c_offer('visitor',$ctx);$a=vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input($o,'accept'));$call=$a+['context'=>$ctx];$id=$call['call_id'];vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcJoin($call);pcJoin($call,'visitor','d');$room=pcRow($id)['room_name'];$GLOBALS['pa3cRooms'][$room]['owner']=true;$GLOBALS['pa3cRooms'][$room]['visitor']=true;$GLOBALS['pa3Now']+=11000;vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck(pcRow($id)['state']==='active','block teardown fixture starts from a verified active human call');db()->exec('INSERT INTO user_blocks(blocker_user_id,blocked_user_id) VALUES(2,1)');$GLOBALS['pa3Now']+=10001;vp3_profile_call_runner_pa3c(db(),20,'pa3c_provider');pcCheck(pcRow($id)['state']==='ended'&&pcRow($id)['last_reason']==='authority_revoked','filtered worker closes an active call after a bidirectional block');

pa3c_reset();$ctx=pa3c_context(2);db()->exec('DROP TABLE user_blocks');pcReject(fn()=>pa3c_offer('visitor',$ctx),'missing block ledger fails closed for member calls','PROFILE_CALL_NOT_READY');pcCheck(!table_exists('user_blocks'),'member request does not initialize social schema inside admission');$guest=pa3c_context();$o=pa3c_offer('visitor',$guest);pcCheck($o['call']['state']==='offered'&&!table_exists('user_blocks'),'anonymous calling does not depend on account block lookup');

pa3c_reset();$o=pa3c_offer();$id=$o['call_id'];$GLOBALS['pa3Now']+=60001;pcReject(fn()=>vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input($o,'accept')),'late acceptance cannot revive an unanswered offer');vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck(pcRow($id)['state']==='expired'&&count($GLOBALS['pa3cProviderCalls'])===0&&pa3_count('agent_workflow_runs')===0,'unanswered offer expires without media or cleanup claims');pcCheck(vp3_profile_handoff_conversation_pa3(db(),1,$o['conversation_id'])['status']==='open','unanswered offer preserves its usable public text thread');
pa3c_reset();$o=pa3c_offer();$in=pa3c_owner_input($o,'decline');$d=vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),$in);$repeat=vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),$in);pcCheck($d['call']['state']==='declined'&&$repeat['duplicate']===true,'explicit decline and lost-response replay settle only one offer');pcReject(fn()=>vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input($o,'accept')),'declined offer cannot later be accepted');$text=pa3_admit('A text question after declining the call',$o['conversation_id']);pcCheck($text['accepted']===true&&(int)$text['conversation']['conversation_id']===$o['conversation_id'],'decline leaves ordinary visitor text and Agent handoff on the same conversation');
pa3c_reset();$o=pa3c_offer('owner');$in=pa3c_owner_input($o,'cancel');$cancelled=vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),$in);$repeat=vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),$in);pcCheck($cancelled['call']['state']==='cancelled'&&$repeat['duplicate']===true,'caller cancellation is durable and exact replay is harmless');pcReject(fn()=>vp3_profile_call_visitor_action_pa3c(db(),$o['context'],pa3c_visitor_input($o,'accept')),'cancellation wins over a stale recipient acceptance');

foreach([[false,false],[false,true],[true,false]] as [$successor,$uncertain]){
    pa3c_reset();$call=pcAccepted();$id=$call['call_id'];$trace=[];
    $GLOBALS['pa3cProviderHook']=function($operation,$binding)use($id,$successor,$uncertain,&$trace){
        if($operation!=='create')return;$GLOBALS['pa3cProviderHook']=null;$trace['old_lease']=pcRow($id);
        db()->prepare('UPDATE profile_calls_pa3c SET provider_lease_until_ms=0 WHERE id=?')->execute([$id]);vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input(pcCall($id),'end'));vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');$trace['original_closed']=pcRow($id);
        if($successor){$ctx=pa3c_context(0,'d');$offered=pa3c_offer('visitor',$ctx);$a=vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input($offered,'accept'));vp3_profile_call_work_pa3c(db(),$a['call_id'],'pa3c_provider');$trace['successor']=pcRow($a['call_id']);}
        if($uncertain)$GLOBALS['pa3cProviderMode']='lost_create';
    };
    vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');$GLOBALS['pa3cProviderMode']='';$row=pcRow($id);$label=$successor?'with successor':($uncertain?'uncertain late result':'late successful result');
    pcCheck($trace['original_closed']['state']==='ended'&&$row['state']==='ending'&&(int)$row['cleanup_generation']===2&&$row['cleanup_proof_json']===null,'late creation invalidates old completion and creates fresh obligation: '.$label);
    pcCheck((int)$row['cleanup_run_id']!==(int)$trace['original_closed']['cleanup_run_id']&&db()->query('SELECT status FROM agent_workflow_runs WHERE id='.(int)$trace['original_closed']['cleanup_run_id'])->fetchColumn()==='completed','prior cleanup receipt remains historical evidence: '.$label);
    $again=vp3_profile_call_late_create_pa3c(db(),$trace['old_lease'],$trace['old_lease']['provider_lease_token']);pcCheck((int)$again['cleanup_generation']===2,'same late work identity cannot enqueue repeated repair: '.$label);
    if($successor){$next=$trace['successor'];pcCheck((int)vp3_profile_call_slot_pa3c(db(),1)['active_call_id']===(int)$next['id']&&pcRow((int)$next['id'])['revision']===$next['revision']&&isset($GLOBALS['pa3cRooms'][$next['room_name']]),'late old creation preserves accepted successor room, revision and slot');}
    else pcCheck((int)vp3_profile_call_slot_pa3c(db(),1)['active_call_id']===$id,'free owner slot is retained for newly discovered original-room cleanup');
    vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');pcCheck(pcRow($id)['state']==='ended'&&!isset($GLOBALS['pa3cRooms'][$row['room_name']])&&pa3_count('agent_workflow_receipts',"status='completed'")===2,'fresh exact-room proof completes second canonical cleanup: '.$label);
    if($successor)pcCheck((int)vp3_profile_call_slot_pa3c(db(),1)['active_call_id']===(int)$next['id']&&pcRow((int)$next['id'])['state']==='ready'&&pcRow((int)$next['id'])['revision']===$next['revision']&&isset($GLOBALS['pa3cRooms'][$next['room_name']]),'old repair never releases, reassigns or ends successor call');
}

pa3c_reset();$call=pcAccepted();$id=$call['call_id'];$pausedRun=0;$GLOBALS['pa3cProviderHook']=function($operation)use($id,&$pausedRun){if($operation!=='create')return;$GLOBALS['pa3cProviderHook']=null;db()->prepare('UPDATE profile_calls_pa3c SET provider_lease_until_ms=0 WHERE id=?')->execute([$id]);vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input(pcCall($id),'end'));$pausedRun=(int)pcRow($id)['cleanup_run_id'];agent_work_control_pause_v173(db(),pa3c_owner(),$pausedRun);};vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');
pcCheck(pcRow($id)['state']==='ending'&&(int)pcRow($id)['cleanup_run_id']===$pausedRun&&(int)pcRow($id)['cleanup_generation']===1&&db()->query('SELECT status FROM agent_workflow_runs WHERE id='.$pausedRun)->fetchColumn()==='paused','late result invalidates proof without resetting explicitly paused cleanup authority');

pcCheck($GLOBALS['pa3ModelCalls']===[],'human call lifecycle never invokes a model');
echo 'PROFILE_CALLS_PA3C=PASS ('.$checks.' checks; '.db()->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
