<?php
declare(strict_types=1);
require __DIR__.'/fixtures/profile-browser-phone-v1.php';

profile_phone_fixture_reset_v1();
$ctx=pa3_context();$input=profile_phone_fixture_input_v1();
pa3_check(vp3_profile_browser_phone_availability_v1($pdo,$ctx['profile'])['enabled'],'currently approved assigned line exposes browser calling');
$beforePresence=$pdo->query('SELECT * FROM phone_browser_presence_v1')->fetchAll();
$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);$cid=$prepared['conversation_id'];$id=$prepared['call']['id'];
$call=vp3_profile_browser_phone_row_v1($pdo,$id);$parts=explode('.',$prepared['token']);$claims=json_decode(base64_decode(strtr($parts[1],'-_','+/')),true,512,JSON_THROW_ON_ERROR);
pa3_check($prepared['contract']===VP3_PROFILE_BROWSER_PHONE_V1&&$call['state']==='prepared'&&$cid>0,'anonymous visitor creates one durable call in its exact native conversation');
pa3_check((int)$call['profile_agent_id']===7&&(int)$call['phone_agent_id']===8&&(int)$call['phone_number_id']===61,'Profile Agent and separately assigned Phone Agent keep distinct exact authority');
pa3_check($claims['grants']['identity']===$call['identity']&&str_starts_with($call['identity'],'vp3_profile_')&&$claims['grants']['voice']['incoming']['allow']===false,'public token has a per-intent anonymous identity and no incoming grant');
pa3_check($claims['exp']<=time()+90&&$claims['grants']['voice']['outgoing']['application_sid']===setting('phone_browser_twiml_app_sid_v1'),'public token is bounded by the short call reservation and fixed TwiML App');
pa3_check(hash_equals(vp3_phone_browser_base64_v1(hash_hmac('sha256',$parts[0].'.'.$parts[1],'profile-phone-api-fixture-123456',true)),$parts[2]),'public token is authenticated with the configured API key');
pa3_check($pdo->query('SELECT * FROM phone_browser_presence_v1')->fetchAll()===$beforePresence&&pa3_count('phone_browser_calls_v1')===0,'visitor preparation cannot replace owner browser presence or invent a connected Phone call');
pa3_check(pa3_count('profile_agent_messages',"conversation_id=$cid AND sender_type='receipt'")===1&&pa3_count('profile_agent_messages',"conversation_id=$cid AND sender_type='agent'")===0,'call preparation writes one system receipt without an Agent answer');
$repeat=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);
pa3_check($repeat['call']['id']===$id&&$repeat['connect_params']===$prepared['connect_params']&&pa3_count('profile_browser_phone_calls_v1')===1,'lost prepare response recovers the same reservation and one-use intent');
profile_phone_fixture_denied_v1(fn()=>vp3_profile_browser_phone_prepare_v1($pdo,$ctx,profile_phone_fixture_input_v1('second')),'another call cannot bypass the owner reservation','PROFILE_PHONE_BUSY');
$foreign=pa3_context(0,'f');
profile_phone_fixture_denied_v1(fn()=>vp3_profile_browser_phone_state_v1($pdo,$foreign,$input+['call_id'=>$id]),'another anonymous credential cannot read the saved call','PROFILE_PHONE_NOT_FOUND');
$ctx=pa3_context();$form=profile_phone_fixture_voice_form_v1($prepared);
try{vp3_phone_browser_voice_v1($pdo,$form,'forged');throw new LogicException('Forged signature accepted.');}catch(RuntimeException $e){pa3_check(str_contains($e->getMessage(),'signature'),'shared voice dispatch verifies the carrier before Profile intent handling');}
pa3_check(vp3_profile_browser_phone_row_v1($pdo,$id)['provider_call_sid']===null&&pa3_count('phone_voice_calls_v130')===0,'invalid signature cannot consume the public intent');
$wrong=$form;$wrong['From']='client:'.vp3_phone_browser_identity_v1(1,0);
pa3_check(str_contains(vp3_phone_browser_voice_v1($pdo,$wrong,profile_phone_fixture_signature_v1('voice',$wrong)),'Hangup'),'an owner browser identity cannot consume a visitor call intent');
$withoutIntent=$form;unset($withoutIntent['profile_call_intent']);$withoutIntent['call_intent']=str_repeat('f',64);
pa3_check(str_contains(vp3_phone_browser_voice_v1($pdo,$withoutIntent,profile_phone_fixture_signature_v1('voice',$withoutIntent)),'Hangup'),'Profile client prefix cannot fall through to owner dialing by omitting its Profile intent');
[$xml,$form]=profile_phone_fixture_voice_v1($prepared);
pa3_check(str_contains($xml,'<Record ')&&!str_contains($xml,'<Number')&&!str_contains($xml,'<Sip'),'offline owner uses existing voicemail directly without a PSTN self-dial');
$voice=$pdo->query('SELECT * FROM phone_voice_calls_v130')->fetch();$call=vp3_profile_browser_phone_row_v1($pdo,$id);
pa3_check($voice['provider_call_sid']===$form['CallSid']&&$voice['caller_e164']===''&&(int)$voice['agent_id']===8&&$call['route_kind']==='voicemail','canonical inbound receipt retains the exact Phone Agent and no invented caller number');
pa3_check(pa3_count('phone_browser_deliveries_v1')===1&&pa3_count('phone_browser_calls_v1')===0,'offline route announces once in the existing owner Phone Chat without a phantom browser leg');
pa3_check(vp3_phone_browser_voice_v1($pdo,$form,profile_phone_fixture_signature_v1('voice',$form))===$xml&&pa3_count('phone_voice_calls_v130')===1,'signed retry reuses saved TwiML and the original carrier receipt');
$otherParent=$form;$otherParent['CallSid']='CA'.md5('other-parent');
pa3_check(str_contains(vp3_phone_browser_voice_v1($pdo,$otherParent,profile_phone_fixture_signature_v1('voice',$otherParent)),'Hangup')&&pa3_count('phone_voice_calls_v130')===1,'one public intent cannot be replayed into a second carrier parent');
pa3_check(vp3_phone_voice_current_route_v131($pdo,$form['CallSid'])['authority']==='unauthenticated_caller','Profile browser calling never grants member or tool authority to the Phone caller');
pa3_check(profile_phone_fixture_parent_v1($form['CallSid'])['accepted'],'signed parent completion settles the exact public call');
$ended=vp3_profile_browser_phone_state_v1($pdo,$ctx,$input+['call_id'=>$id]);
pa3_check($ended['call']['terminal']&&$ended['call']['state']==='completed'&&!$ended['call']['answered']&&$ended['call']['duration_seconds']===12,'parent completion records duration without claiming an owner pickup');
pa3_check(!vp3_phone_voice_current_route_v131($pdo,$form['CallSid'])['accepted'],'a completed public call cannot continue routing through its historical voice receipt');
pa3_check(profile_phone_fixture_parent_v1($form['CallSid'],'failed','0')['accepted']&&vp3_profile_browser_phone_row_v1($pdo,$id)['state']==='completed','late terminal callback cannot regress the public call outcome');

// A connected SDK transport alone is not evidence that the owner answered.
profile_phone_fixture_reset_v1();$ctx=pa3_context(2);$input=profile_phone_fixture_input_v1('owner-call');
$pdo->prepare('INSERT INTO phone_browser_presence_v1(owner_user_id,last_epoch,conversation_id,chat_agent_id) VALUES(1,?,14,0)')->execute([time()]);
$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);[$xml,$form]=profile_phone_fixture_voice_v1($prepared,'owner-parent');
$id=$prepared['call']['id'];$phone=vp3_phone_browser_call_by_sid_v1($pdo,$form['CallSid']);
pa3_check(str_contains($xml,'<Client ')&&str_contains($xml,'<Identity>'.vp3_phone_browser_identity_v1(1,0).'</Identity>')&&str_contains($xml,'timeLimit="600"'),'public browser call rings the existing owner identity with a bounded bridge');
pa3_check($phone&&$phone['direction']==='inbound'&&$phone['peer_e164']===''&&vp3_profile_browser_phone_row_v1($pdo,$id)['route_kind']==='owner','owner ringing creates the ordinary inbound browser ledger with no member caller identity');
$poll=vp3_profile_browser_phone_state_v1($pdo,$ctx,$input);
pa3_check(!$poll['call']['answered']&&$poll['call']['state']==='ringing','ringing alone does not report an owner pickup');
$wrong=['AccountSid'=>(string)setting('phone_twilio_account_sid'),'CallSid'=>'CA'.md5('wrong-child'),'ParentCallSid'=>'CA'.md5('foreign-parent'),'CallStatus'=>'answered'];
pa3_check(!vp3_phone_browser_status_v1($pdo,$wrong,profile_phone_fixture_signature_v1('status',$wrong,$phone['public_id']),$phone['public_id'])['accepted']&&vp3_profile_browser_phone_row_v1($pdo,$id)['owner_answered_ms']===null,'signed but unrelated child cannot claim an owner pickup');
pa3_check(profile_phone_fixture_child_v1($phone,'completed','9')['accepted'],'completed-first child callback is accepted by the existing Phone ledger');
$poll=vp3_profile_browser_phone_state_v1($pdo,$ctx,$input);
pa3_check($poll['call']['answered']&&!$poll['call']['terminal'],'completed child with positive duration proves pickup without falsely terminating its parent');
profile_phone_fixture_parent_v1($form['CallSid'],'completed','12');$answeredAt=vp3_profile_browser_phone_row_v1($pdo,$id)['owner_answered_ms'];
$late=profile_phone_fixture_child_v1($phone,'answered');
pa3_check($late['duplicate']&&vp3_profile_browser_phone_row_v1($pdo,$id)['state']==='completed'&&vp3_profile_browser_phone_row_v1($pdo,$id)['owner_answered_ms']===$answeredAt,'late answered callback preserves terminal state and first pickup evidence');

profile_phone_fixture_reset_v1();$ctx=pa3_context();$input=profile_phone_fixture_input_v1('missed-owner');
$pdo->prepare('INSERT INTO phone_browser_presence_v1(owner_user_id,last_epoch,conversation_id,chat_agent_id) VALUES(1,?,14,0)')->execute([time()]);
$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);[$xml,$form]=profile_phone_fixture_voice_v1($prepared,'missed-parent');$phone=vp3_phone_browser_call_by_sid_v1($pdo,$form['CallSid']);
profile_phone_fixture_child_v1($phone,'no-answer');$fallback=profile_phone_fixture_child_v1($phone,'no-answer','0',true);
$poll=vp3_profile_browser_phone_state_v1($pdo,$ctx,$input);
pa3_check($fallback['fallback']&&!$poll['call']['terminal']&&!$poll['call']['answered'],'missed owner pickup permits the existing fallback without falsely ending the public parent');
pa3_check(!profile_phone_fixture_child_v1($phone,'no-answer','0',true)['fallback'],'duplicate completion cannot claim a second fallback');

// Per-number Phone conversation consent is reused; streaming needs caller proof.
profile_phone_fixture_reset_v1();$ctx=pa3_context();$input=profile_phone_fixture_input_v1('phone-agent');
$pdo->exec("INSERT INTO phone_voice_modes_v140(phone_number_id,owner_user_id,agent_id,mode) VALUES(61,1,8,'conversation')");
$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);[$xml,$form]=profile_phone_fixture_voice_v1($prepared,'agent-parent');
pa3_check(str_contains($xml,'<Gather ')&&str_contains($xml,'Other Guide')&&vp3_profile_browser_phone_row_v1($pdo,$prepared['call']['id'])['route_kind']==='agent','supported fallback follows the current Phone Agent conversation configuration');
$speech=$form+['SpeechResult'=>'What can this phone line help with?'];$turnUrl=vp3_phone_voice_turn_url_v140(vp3_phone_voice_callback_url_v130(),1);
$answer=vp3_phone_voice_turn_v140($pdo,$speech,vp3_phone_sms_twilio_signature_v110($turnUrl,$speech,'profile-phone-carrier-fixture'),vp3_phone_voice_callback_url_v130(),'profile-phone-carrier-fixture',(string)setting('phone_twilio_account_sid'),1);
pa3_check($answer['accepted']&&count($GLOBALS['pa3ModelCalls'])===1&&$GLOBALS['pa3ModelCalls'][0]['scope']==='phone_voice'&&$GLOBALS['pa3ModelCalls'][0]['context']===[],'Phone Agent uses its existing isolated phone scope without private Profile Knowledge or Chat');
$pdo->prepare('UPDATE phone_voice_calls_v130 SET created_at=? WHERE provider_call_sid=?')->execute([gmdate('Y-m-d H:i:s',time()-601),$form['CallSid']]);
pa3_check(!vp3_phone_voice_current_route_v131($pdo,$form['CallSid'],1800,['conversation_active'])['accepted'],'public call routing expires at ten minutes even when legacy Phone allows a longer call');

profile_phone_fixture_reset_v1();$ctx=pa3_context();
$GLOBALS['pa3Settings']['phone_voice_stream_sip_uri_v150']='sip:fixture@fixture.sip.livekit.cloud;transport=tls';
$GLOBALS['pa3Settings']['phone_voice_stream_worker_secret_v150']=ai_encrypt_secret(str_repeat('stream-fixture-',3));
$pdo->exec('INSERT INTO phone_voice_stream_routes_v150(phone_number_id,owner_user_id,cloud_agent_id,homeserver_agent_id) VALUES(61,1,8,207)');
$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,profile_phone_fixture_input_v1('no-anonymous-stream'));[$xml,$form]=profile_phone_fixture_voice_v1($prepared,'anonymous-stream-parent');
pa3_check(vp3_phone_stream_route_v150($pdo,['phone_number_id'=>61,'owner_user_id'=>1,'agent_id'=>8])!==false&&str_contains($xml,'<Record ')&&!str_contains($xml,'<Sip'),'configured HomeServer route is bypassed for an anonymous Profile SDK caller lacking carrier-number proof');
$pstn=['AccountSid'=>(string)setting('phone_twilio_account_sid'),'CallSid'=>'CA'.md5('ordinary-pstn'),'From'=>'+16025550999','To'=>'+16025550101','Direction'=>'inbound'];$callback=vp3_phone_voice_callback_url_v130();
$routed=vp3_phone_voice_incoming_v130($pdo,$pstn,vp3_phone_sms_twilio_signature_v110($callback,$pstn,'profile-phone-carrier-fixture'),$callback,'profile-phone-carrier-fixture',(string)setting('phone_twilio_account_sid'));
pa3_check($routed['accepted']&&str_contains($routed['twiml'],'<Sip'),'unlinked ordinary PSTN calls retain their existing approved HomeServer routing');

// A prepared token cannot outlive the exact Profile, visitor, line or config grant.
$revocations=[
    'Profile publication'=>static fn()=>db()->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1'),
    'Profile Agent'=>static fn()=>db()->exec('UPDATE user_profiles SET profile_agent_id=8 WHERE user_id=1'),
    'Phone Agent assignment'=>static fn()=>db()->exec('UPDATE phone_cloud_numbers_v100 SET agent_id=7 WHERE id=61'),
    'explicit phone approval'=>static fn()=>db()->exec('UPDATE profile_phone_settings_v1 SET revision=revision+1 WHERE owner_user_id=1'),
    'browser line approval'=>static fn()=>db()->exec('UPDATE phone_browser_numbers_v1 SET browser_enabled=0 WHERE phone_number_id=61'),
    'owner account'=>static fn()=>db()->exec('UPDATE users SET is_active=0 WHERE id=1'),
    'owner Profile permission'=>static fn()=>db()->exec("DELETE FROM role_permissions WHERE permission_key='profile_chat.access'"),
    'voice entitlement'=>static function(){$GLOBALS['profilePhoneDeniedEntitlements']=['phone.voice'];},
    'Twilio API secret'=>static function(){$GLOBALS['pa3Settings']['phone_browser_api_key_secret_v1']=ai_encrypt_secret('different-api-fixture-123456');},
    'visitor session'=>static fn()=>db()->exec("UPDATE profile_visit_sessions SET session_key='revoked-session' WHERE owner_user_id=1"),
    'member password'=>static fn()=>db()->exec("UPDATE users SET password_hash='new-password' WHERE id=2"),
    'social block'=>static fn()=>db()->exec('INSERT INTO user_blocks(blocker_user_id,blocked_user_id) VALUES(1,2)'),
];
foreach($revocations as $label=>$revoke){
    profile_phone_fixture_reset_v1();$ctx=pa3_context(2);$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,profile_phone_fixture_input_v1('revocation'));
    $form=profile_phone_fixture_voice_form_v1($prepared,'revoked-parent');$revoke();
    try{$rejected=str_contains(vp3_phone_browser_voice_v1($pdo,$form,profile_phone_fixture_signature_v1('voice',$form)),'Hangup');}
    catch(VP3ProfileBrowserPhoneException $e){$rejected=$e->publicCode==='PROFILE_PHONE_CHANGED';}
    pa3_check($rejected&&pa3_count('phone_voice_calls_v130')===0,$label.' revocation invalidates the prepared token before carrier admission');
}
profile_phone_fixture_reset_v1();$ctx=pa3_context();$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,profile_phone_fixture_input_v1('revoked-live'));[$xml,$form]=profile_phone_fixture_voice_v1($prepared,'revoked-live-parent');
$pdo->exec('UPDATE profile_phone_settings_v1 SET enabled=0,revision=revision+1 WHERE owner_user_id=1');
pa3_check(!vp3_phone_voice_current_route_v131($pdo,$form['CallSid'])['accepted']&&str_contains(vp3_phone_browser_voice_v1($pdo,$form,profile_phone_fixture_signature_v1('voice',$form)),'Hangup'),'revoked publication also fences current routing and a signed retry');
$pdo->exec("UPDATE phone_voice_calls_v130 SET state='voicemail_ready'");$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
pa3_check(profile_phone_fixture_parent_v1($form['CallSid'])['accepted']&&vp3_profile_browser_phone_row_v1($pdo,$prepared['call']['id'])['state']==='completed','historical signed completion can settle after owner or publication revocation');
pa3_check($pdo->query('SELECT state FROM phone_voice_calls_v130')->fetchColumn()==='voicemail_ready','public parent completion preserves canonical voicemail evidence');

// Cancel-before-prepare and uncertain carrier end keep deterministic reservations.
profile_phone_fixture_reset_v1();$ctx=pa3_context();$input=profile_phone_fixture_input_v1('cancel-before');$carrierCalls=[];
$offline=static function(string $method,string $path,array $data=[])use(&$carrierCalls){$carrierCalls[]=compact('method','path','data');throw new RuntimeException('Fixture carrier offline.');};
$ended=vp3_profile_browser_phone_end_v1($pdo,$ctx,$input,$offline);
pa3_check($ended['call']===null&&pa3_count('profile_browser_phone_cancellations_v1')===1&&$carrierCalls===[],'cancel before preparation stores an exact tombstone without carrier I/O');
profile_phone_fixture_denied_v1(fn()=>vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input,$offline),'late preparation cannot resurrect a canceled request','PROFILE_PHONE_CANCELED');
$input=profile_phone_fixture_input_v1('cancel-prepared');$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input,$offline);$form=profile_phone_fixture_voice_form_v1($prepared,'canceled-parent');
$ended=vp3_profile_browser_phone_end_v1($pdo,$ctx,$input,$offline);
pa3_check($ended['call']['state']==='canceled'&&$ended['call']['terminal']&&$carrierCalls===[],'unconsumed cancellation is terminal without a provider request');
pa3_check(str_contains(vp3_phone_browser_voice_v1($pdo,$form,profile_phone_fixture_signature_v1('voice',$form)),'Hangup')&&pa3_count('phone_voice_calls_v130')===0,'late signed admission cannot consume the canceled intent');
$input=profile_phone_fixture_input_v1('uncertain-end');$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input,$offline);[$xml,$form]=profile_phone_fixture_voice_v1($prepared,'uncertain-parent');
$ended=vp3_profile_browser_phone_end_v1($pdo,$ctx,$input,$offline);
pa3_check($ended['call']['end_requested']&&!$ended['call']['terminal']&&count($carrierCalls)===1&&$carrierCalls[0]['method']==='POST'
    &&str_ends_with($carrierCalls[0]['path'],'/Calls/'.$form['CallSid'].'.json')&&$carrierCalls[0]['data']===['Status'=>'completed'],'uncertain end targets only the stored carrier parent and retains an active reservation');
pa3_check(!vp3_profile_browser_phone_current_sid_v1($pdo,$form['CallSid']),'requested end immediately fences new call routing even while carrier outcome is uncertain');
profile_phone_fixture_denied_v1(fn()=>vp3_profile_browser_phone_prepare_v1($pdo,$ctx,profile_phone_fixture_input_v1('competing-after-end'),$offline),'uncertain end cannot admit a competing owner call','PROFILE_PHONE_BUSY');
profile_phone_fixture_parent_v1($form['CallSid']);pa3_check(!vp3_profile_browser_phone_active_v1($pdo,1),'signed parent termination releases an uncertain end reservation');

// Missing callbacks are reconciled by an exact provider receipt outside SQL locks.
profile_phone_fixture_reset_v1();$ctx=pa3_context();$input=profile_phone_fixture_input_v1('reconcile');
$pdo->prepare('INSERT INTO phone_browser_presence_v1(owner_user_id,last_epoch,conversation_id,chat_agent_id) VALUES(1,?,14,0)')->execute([time()]);
$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);[$xml,$form]=profile_phone_fixture_voice_v1($prepared,'reconcile-parent');$id=$prepared['call']['id'];$phone=vp3_phone_browser_call_by_sid_v1($pdo,$form['CallSid']);
$pdo->prepare('UPDATE profile_browser_phone_calls_v1 SET created_ms=?,last_reconciled_ms=0 WHERE public_id=?')->execute([vp3_profile_browser_phone_now_v1()-31000,$id]);
$carrierCalls=[];$carrierReceipt=['sid'=>$form['CallSid'],'account_sid'=>(string)setting('phone_twilio_account_sid'),'status'=>'completed','duration'=>'21'];
$carrier=static function(string $method,string $path,array $data=[])use(&$carrierCalls,&$carrierReceipt){pa3_check(!db()->inTransaction(),'carrier reconciliation never executes under a database transaction');$carrierCalls[]=compact('method','path','data');return $carrierReceipt;};
$reconciled=vp3_profile_browser_phone_state_v1($pdo,$ctx,$input,$carrier);
pa3_check($reconciled['call']['state']==='completed'&&$reconciled['call']['duration_seconds']===21&&count($carrierCalls)===1&&$carrierCalls[0]['method']==='GET','a missing callback is recovered from the exact current carrier parent receipt');
$phone=vp3_phone_browser_call_by_sid_v1($pdo,$form['CallSid']);
pa3_check($phone['state']==='completed'&&!vp3_profile_browser_phone_active_v1($pdo,1),'verified parent end releases the matching canonical owner browser reservation');
pa3_check(!$reconciled['call']['answered'],'parent REST completion alone is never promoted to owner pickup proof');
foreach(['wrong SID','wrong account','unknown status'] as $badReceipt){
    profile_phone_fixture_reset_v1();$ctx=pa3_context();$input=profile_phone_fixture_input_v1($badReceipt);$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);[$xml,$form]=profile_phone_fixture_voice_v1($prepared,$badReceipt.' parent');$id=$prepared['call']['id'];
    $pdo->prepare('UPDATE profile_browser_phone_calls_v1 SET created_ms=?,last_reconciled_ms=0 WHERE public_id=?')->execute([vp3_profile_browser_phone_now_v1()-31000,$id]);
    $carrierReceipt=['sid'=>$form['CallSid'],'account_sid'=>(string)setting('phone_twilio_account_sid'),'status'=>'completed','duration'=>'10'];
    if($badReceipt==='wrong SID')$carrierReceipt['sid']='CA'.md5('wrong-receipt');elseif($badReceipt==='wrong account')$carrierReceipt['account_sid']='AC'.str_repeat('f',32);else $carrierReceipt['status']='in-progress';
    $result=vp3_profile_browser_phone_state_v1($pdo,$ctx,$input,$carrier);
    pa3_check(!$result['call']['terminal']&&vp3_profile_browser_phone_active_v1($pdo,1),$badReceipt.' reconciliation cannot invent terminal completion or release capacity');
}

// The two calling transports share owner capacity; phone activity stays neutral in Chat.
profile_phone_fixture_reset_v1();$ctx=pa3_context();$pdo->prepare('INSERT INTO profile_call_slots_pa3c(owner_user_id,active_call_id,revision,updated_at_ms) VALUES(1,900,1,?)')->execute([vp3_profile_browser_phone_now_v1()]);
profile_phone_fixture_denied_v1(fn()=>vp3_profile_browser_phone_prepare_v1($pdo,$ctx,profile_phone_fixture_input_v1('native-busy')),'an existing native call reservation blocks public phone admission across conversations','PROFILE_PHONE_BUSY');
$pdo->exec('DELETE FROM profile_call_slots_pa3c');$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,profile_phone_fixture_input_v1('phone-busy'));
pa3_check(vp3_profile_call_owner_busy_pa3c($pdo,1),'a public phone reservation blocks native owner call admission');
$chat=vp3_profile_agent_public_poll_service_v110($pdo,$ctx,(int)$prepared['conversation_id'])['chat'];
pa3_check($chat['phone_call_active']===true&&$chat['media_call_active']===false&&$chat['live_status']!=='accepted','public Phone holding is distinct from an accepted human native call in Chat');

profile_phone_fixture_reset_v1();$receipt=pa3_admit();$turn=pa3_turn($receipt);$ctx=pa3_context();$input=profile_phone_fixture_input_v1('text-hold',(int)$turn['conversation_id']);
$threadBefore=vp3_profile_handoff_thread_pa3($pdo,1,(int)$turn['conversation_id']);$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);
vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);$held=vp3_profile_handoff_row_pa3($pdo,(int)$turn['id']);
$q=$pdo->prepare('SELECT status FROM agent_workflow_runs WHERE id=?');$q->execute([(int)$held['run_id']]);
pa3_check(count($GLOBALS['pa3ModelCalls'])===0&&$held['state']==='agent_ready'&&$q->fetchColumn()==='paused','public phone reservation pauses existing accepted text work before inference');
$q->closeCursor();
pa3_check(vp3_profile_handoff_thread_pa3($pdo,1,(int)$turn['conversation_id'])['mode']===$threadBefore['mode'],'holding text work does not change the chosen responder mode');
vp3_profile_browser_phone_end_v1($pdo,$ctx,$input,$offline);vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);
pa3_check(count($GLOBALS['pa3ModelCalls'])===1&&vp3_profile_handoff_row_pa3($pdo,(int)$turn['id'])['state']==='replied','verified cancellation releases the same queued text turn exactly once');

profile_phone_fixture_reset_v1();$ctx=pa3_context();$input=profile_phone_fixture_input_v1('overdue');$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);[$xml,$form]=profile_phone_fixture_voice_v1($prepared,'overdue-parent');
$pdo->prepare('UPDATE profile_browser_phone_calls_v1 SET created_ms=?,last_reconciled_ms=0 WHERE public_id=?')->execute([vp3_profile_browser_phone_now_v1()-601000,$prepared['call']['id']]);
$carrierCalls=[];$carrierReceipt=['sid'=>$form['CallSid'],'account_sid'=>(string)setting('phone_twilio_account_sid'),'status'=>'completed','duration'=>'600'];
$overdue=vp3_profile_browser_phone_state_v1($pdo,$ctx,$input,$carrier);
pa3_check($overdue['call']['terminal']&&$carrierCalls[0]['method']==='POST'&&$carrierCalls[0]['data']===['Status'=>'completed'],'ten-minute reconciliation requests bounded termination on the exact carrier parent');

profile_phone_fixture_reset_v1();$ctx=pa3_context();$input=profile_phone_fixture_input_v1('unavailable-provider');$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);[$xml,$form]=profile_phone_fixture_voice_v1($prepared,'unavailable-parent');
$pdo->prepare('UPDATE profile_browser_phone_calls_v1 SET created_ms=?,last_reconciled_ms=0 WHERE public_id=?')->execute([vp3_profile_browser_phone_now_v1()-31000,$prepared['call']['id']]);
$carrierCalls=[];$unknown=vp3_profile_browser_phone_state_v1($pdo,$ctx,$input,$offline);vp3_profile_browser_phone_state_v1($pdo,$ctx,$input,$offline);
pa3_check(!$unknown['call']['terminal']&&vp3_profile_browser_phone_active_v1($pdo,1)&&count($carrierCalls)===1,'provider outage leaves capacity held and repeated polling respects the reconciliation interval');
$pdo->prepare('UPDATE profile_browser_phone_calls_v1 SET last_reconciled_ms=0 WHERE public_id=?')->execute([$prepared['call']['id']]);
$carrierCalls=[];$carrierReceipt=['sid'=>$form['CallSid'],'account_sid'=>(string)setting('phone_twilio_account_sid'),'status'=>'completed','duration'=>'7'];
$next=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,profile_phone_fixture_input_v1('recovered-admission'),$carrier);
pa3_check($next['call']['state']==='prepared'&&$next['call']['id']!==$prepared['call']['id']&&vp3_profile_browser_phone_row_v1($pdo,$prepared['call']['id'])['state']==='completed','new admission can recover a prior missing callback before allocating one replacement reservation');

profile_phone_fixture_reset_v1();$ctx=pa3_context();$input=profile_phone_fixture_input_v1('late-pickup');
$pdo->prepare('INSERT INTO phone_browser_presence_v1(owner_user_id,last_epoch,conversation_id,chat_agent_id) VALUES(1,?,14,0)')->execute([time()]);
$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);[$xml,$form]=profile_phone_fixture_voice_v1($prepared,'late-pickup-parent');$phone=vp3_phone_browser_call_by_sid_v1($pdo,$form['CallSid']);
profile_phone_fixture_parent_v1($form['CallSid']);$late=profile_phone_fixture_child_v1($phone,'answered');$done=vp3_profile_browser_phone_state_v1($pdo,$ctx,$input);
pa3_check($late['duplicate']&&$done['call']['state']==='completed'&&$done['call']['answered'],'a delayed verified pickup can enrich an ended parent without reopening either canonical call');

profile_phone_fixture_reset_v1();$ctx=pa3_context();$input=profile_phone_fixture_input_v1('expired-token');$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);
$pdo->prepare('UPDATE profile_browser_phone_calls_v1 SET expires_ms=? WHERE public_id=?')->execute([vp3_profile_browser_phone_now_v1()-1,$prepared['call']['id']]);$carrierCalls=[];
$expired=vp3_profile_browser_phone_state_v1($pdo,$ctx,$input,$offline);
pa3_check($expired['call']['state']==='expired'&&$expired['call']['terminal']&&$carrierCalls===[],'expired unconsumed token releases its reservation without a fabricated carrier call');

profile_phone_fixture_reset_v1();$ctx=pa3_context();$known=profile_phone_fixture_input_v1('known-during-cancel-flood');$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$known);
for($i=0;$i<30;$i++)vp3_profile_browser_phone_end_v1($pdo,$ctx,profile_phone_fixture_input_v1('unknown-cancel-'.$i),$offline);
profile_phone_fixture_denied_v1(fn()=>vp3_profile_browser_phone_end_v1($pdo,$ctx,profile_phone_fixture_input_v1('unknown-cancel-over-limit'),$offline),'unknown cancellation flooding has a bounded per-credential admission rate','PROFILE_PHONE_RATE_LIMITED');
pa3_check(vp3_profile_browser_phone_end_v1($pdo,$ctx,profile_phone_fixture_input_v1('unknown-cancel-0'),$offline)['call']===null,'retry of a saved cancellation remains idempotent after the admission cap');
pa3_check(vp3_profile_browser_phone_end_v1($pdo,$ctx,$known,$offline)['call']['state']==='canceled','cancellation admission cap cannot block ending a known current call');

profile_phone_fixture_reset_v1();$receipt=pa3_admit();$turn=pa3_turn($receipt);$ctx=pa3_context();$input=profile_phone_fixture_input_v1('inflight-hold',(int)$turn['conversation_id']);
$inflight=null;$GLOBALS['pa3ModelHook']=static function()use($ctx,$input,&$inflight){$inflight=vp3_profile_browser_phone_prepare_v1(db(),$ctx,$input);};
vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);$GLOBALS['pa3ModelHook']=null;
pa3_check(is_array($inflight)&&pa3_count('profile_agent_messages',"conversation_id=".(int)$turn['conversation_id']." AND sender_type='agent'")===0,'a call admitted during inference fences the stale text answer before publication');

profile_phone_fixture_reset_v1();$ctx=pa3_context();$input=profile_phone_fixture_input_v1('revoked-owner-recovery');
$pdo->prepare('INSERT INTO phone_browser_presence_v1(owner_user_id,last_epoch,conversation_id,chat_agent_id) VALUES(1,?,14,0)')->execute([time()]);
$prepared=vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input);[$xml,$form]=profile_phone_fixture_voice_v1($prepared,'revoked-owner-recovery-parent');
$pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1');
$pdo->prepare('UPDATE profile_browser_phone_calls_v1 SET created_ms=?,last_reconciled_ms=0 WHERE public_id=?')->execute([vp3_profile_browser_phone_now_v1()-31000,$prepared['call']['id']]);
$carrierCalls=[];$carrierReceipt=['sid'=>$form['CallSid'],'account_sid'=>(string)setting('phone_twilio_account_sid'),'status'=>'completed','duration'=>'8'];
vp3_profile_browser_phone_reconcile_owner_v1($pdo,4,$carrier);
pa3_check($carrierCalls===[],'owner recovery cannot reconcile another owner’s public carrier reservation');
vp3_profile_browser_phone_reconcile_owner_v1($pdo,1,$carrier);
pa3_check(vp3_profile_browser_phone_row_v1($pdo,$prepared['call']['id'])['state']==='completed'&&!vp3_profile_browser_phone_active_v1($pdo,1),'authenticated owner recovery settles verified carrier completion despite revoked public visibility');
$scope=vp3_phone_workspace_scope_v1($pdo,pa3_owner(),['conversation_id'=>14,'agent_id'=>0]);
$ownerPrepared=vp3_phone_browser_action_v1($pdo,$scope,['action'=>'prepare_call','number_id'=>61,'to_number'=>'+16025550999']);
pa3_check($ownerPrepared['call']['state']==='prepared'&&!vp3_profile_phone_public_v1($pdo,profile_by_username($pdo,'fixture-owner'))['enabled'],'verified owner recovery restores private owner dialing without reopening public calling');

profile_phone_fixture_reset_v1();$ctx=pa3_context();$pdo->exec('DROP TABLE profile_browser_phone_calls_v1');$ddl=[];
$pdo->beforeSql=static function(string $sql)use(&$ddl){if(preg_match('/\A\s*(CREATE|ALTER|DROP)\b/i',$sql))$ddl[]=$sql;};
pa3_check(!vp3_profile_browser_phone_availability_v1($pdo,$ctx['profile'])['enabled'],'missing public-call migration explicitly disables availability');
profile_phone_fixture_denied_v1(fn()=>vp3_profile_browser_phone_prepare_v1($pdo,$ctx,profile_phone_fixture_input_v1('missing-schema')),'uninstalled public-call schema cannot admit a call','PROFILE_PHONE_UPGRADE_REQUIRED');
pa3_check($ddl===[]&&!table_exists('profile_browser_phone_calls_v1'),'public runtime reads and denied admission never install or repair missing schema');
$pdo->beforeSql=null;

echo 'PROFILE_BROWSER_PHONE_V1=PASS ('.$checks." checks)\n";
