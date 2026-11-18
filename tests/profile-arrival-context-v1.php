<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/profile-visitor-speech-pa4a.php';
$checks=0;
function pac_reset(): void{ps_reset();db()->exec("INSERT INTO team_user_presence(user_id,presence_mode,social_chat_enabled,sound_enabled,agent_voice_enabled) VALUES(1,'online',1,1,1)");}
function pac_ok(bool $ok,string $message): void{if(!$ok)throw new RuntimeException('FAIL '.$message);$GLOBALS['checks']++;}
function pac_denied(callable $run,string $message,?string $code=null): void{try{$run();}catch(VP3ProfileSpeechException $e){pac_ok($code===null||$e->publicCode===$code,$message.($code===null?'':' (expected '.$code.', received '.$e->publicCode.')'));return;}throw new RuntimeException('FAIL expected denial: '.$message);}
function pac_source(array $item): array{return vp3_profile_speech_source_pa4a(db(),vp3_profile_speech_item_pa4a(db(),(int)$item['id']));}
function pac_auto(array $item,array $extra=[]): array{return vp3_profile_speech_delivery_pa4a(db(),ps_owner(),ps_scope($extra+['item_id'=>(int)$item['id'],'revision'=>(int)$item['revision'],'mode'=>'automatic']));}
function pac_view(array $item): array{foreach(ps_state(['public_conversation_id'=>(int)$item['public_conversation_id']])['items'] as $row)if($row['id']===(int)$item['id'])return $row;throw new RuntimeException('Missing exact speech projection.');}

pac_reset();$p=db();$ctx=ps_context(2);$first=ps_page($ctx);$source=pac_source($first);
pac_ok($source['arrival_context']['visit_kind']==='first_recorded'&&str_contains($source['text'],'first recorded visit'),'first-recorded wording is bounded to recorded native history');
$p->exec("UPDATE profile_visit_sessions SET visitor_user_id=3,identity_disclosed=1,view_count=999,first_seen_at='2000-01-01 00:00:00'");
pac_ok(pac_source($first)['arrival_context']['visit_kind']==='first_recorded'&&pac_source($first)['label']==='Visitor A','CRM recognition and raw counts cannot change native arrival identity or history');
$p->exec("INSERT INTO user_relationships(owner_user_id,related_user_id,relationship_scope,status) VALUES(1,2,'collaborator','accepted')");
pac_ok(pac_source($first)['arrival_context']['relationship']==='collaborator'&&str_contains(pac_source($first)['text'],'your collaborator'),'current disclosed native visitor may show current accepted relationship');
$p->exec('UPDATE user_profiles SET share_visit_identity=0 WHERE user_id=2');
$private=pac_source($first);pac_ok($private['label']==='A visitor'&&$private['arrival_context']['relationship']==='none'&&!str_contains($private['text'],'Visitor A'),'identity opt-out also removes relationship disclosure');
$p->exec('UPDATE user_profiles SET share_visit_identity=1 WHERE user_id=2');
$m=ps_message('Can we discuss the autumn exhibition?','agent',$ctx);$prior=(int)$m['item']['visitor_message_id'];ps_tick(1800001);$return=ps_page($ctx);$returnSource=pac_source($return);$context=$returnSource['arrival_context'];
pac_ok($context['visit_kind']==='returning'&&$context['prior_arrival_at_ms']===(int)$first['created_at_ms'],'returning visit uses a strictly earlier native epoch record');
pac_ok($context['prior_conversation']['visitor_message_id']===$prior&&$context['prior_conversation']['public_conversation_id']===(int)$m['item']['public_conversation_id']&&str_contains($returnSource['text'],'autumn exhibition'),'returning context quotes an exact earlier authorized native visitor statement');
pac_ok($context['prior_conversation']['recorded_at_ms']<(int)$return['created_at_ms']&&strlen($context['source_revision'])===64,'context exposes exact prior epoch and semantic revision');
$p->exec("UPDATE profile_agent_messages SET created_at='2099-12-31 23:59:59'");
pac_ok(pac_source($return)['arrival_context']['prior_conversation']['visitor_message_id']===$prior,'legacy DB-clock message times are not used to invent arrival ordering');
$before=[];$p->beforeSql=static function(string $sql)use(&$before):void{$before[]=$sql;if(preg_match('/^\s*(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|REPLACE|TRUNCATE)\b/i',$sql))throw new RuntimeException('Read tried mutation: '.$sql);};
try{$readonly=pac_source($return);$view=pac_view($return);}finally{$p->beforeSql=null;}
pac_ok(count($before)>0&&$readonly['arrival_context']===$context&&$view['arrival_context']===$context,'real source and state projections are query-only with exact contextual proof');
$p->prepare('UPDATE profile_speech_items_pa4a SET created_at_ms=? WHERE id=?')->execute([(int)$return['created_at_ms'],(int)$m['item']['id']]);
pac_ok(pac_source($return)['arrival_context']['prior_conversation']===null,'equal-time visitor statement is not claimed as earlier context');
$p->prepare('UPDATE profile_speech_items_pa4a SET created_at_ms=? WHERE id=?')->execute([(int)$return['created_at_ms'],(int)$first['id']]);
pac_ok(pac_source($return)['arrival_context']['visit_kind']==='unknown','equal-time prior arrival is unknown instead of returning');
$p->prepare('DELETE FROM profile_speech_items_pa4a WHERE id=?')->execute([(int)$first['id']]);
pac_ok(pac_source($return)['arrival_context']['visit_kind']==='unknown','missing earlier generation cannot be relabeled first-time');

pac_reset();$signed=ps_page(ps_context(2,'b'));ps_tick(1800001);$guest=ps_page(ps_context(0,'b'));$guestSource=pac_source($guest);
pac_ok($guestSource['arrival_context']['visit_kind']==='first_recorded'&&$guestSource['label']==='A visitor'&&$guestSource['arrival_context']['prior_conversation']===null,'guest in reused CRM browser never inherits signed visitor history');
$other=ps_page(ps_context(2,'e'));pac_ok(pac_source($other)['arrival_context']['visit_kind']==='first_recorded'&&str_contains(pac_source($other)['text'],'first recorded visit from this browser'),'a different native credential is explicitly scoped to browser history, not a globally new member');
$voice=vp3_profile_speech_voice_row_pa4a($p,(int)$other['voice_assignment_id']);$p->prepare("UPDATE profile_visitor_voices_pa4a SET principal_hash=? WHERE id=?")->execute([str_repeat('f',64),(int)$voice['id']]);
pac_denied(fn()=>pac_source($other),'changed immutable native principal invalidates arrival context','PROFILE_SPEECH_UNAVAILABLE');

foreach(['profile','visitor','block','credential','message'] as $change){
    pac_reset();$ctx=ps_context(2);$old=ps_message('Earlier bounded statement.','agent',$ctx);ps_tick(1800001);$entry=ps_page($ctx);
    if($change==='profile')$p->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1');
    elseif($change==='visitor')$p->exec('UPDATE users SET is_active=0 WHERE id=2');
    elseif($change==='block')$p->exec('INSERT INTO user_blocks(blocker_user_id,blocked_user_id) VALUES(1,2)');
    elseif($change==='credential')$p->exec("UPDATE users SET password_hash='changed' WHERE id=2");
    else $p->prepare('UPDATE profile_agent_messages SET message=? WHERE id=?')->execute(['Changed old source.',(int)$old['item']['visitor_message_id']]);
    if($change==='message')pac_ok(pac_source($entry)['arrival_context']['prior_conversation']===null,'changed historical source text is withheld rather than reused');else pac_denied(fn()=>pac_source($entry),'current '.$change.' authority revocation withholds contextual arrival','PROFILE_SPEECH_UNAVAILABLE');
}

pac_reset();$item=ps_page();$beforeMessages=pa3_count('chat_messages');$GLOBALS['psReady']=false;$p->exec('UPDATE team_user_presence SET sound_enabled=0,agent_voice_enabled=0 WHERE user_id=1');$view=pac_view($item);
pac_ok(!$view['can_play']&&!$view['automatic_allowed']&&$view['automatic_delivery_allowed'],'silent visual notice remains eligible without TTS or voice permissions');
$delivery=pac_auto($item);pac_ok(pa3_count('chat_messages')===$beforeMessages+1&&$delivery['item']['delivery_kind']==='speech','eligible silent arrival creates one ordinary saved notice');
$beforeSource=vp3_profile_speech_item_pa4a($p,(int)$item['id']);ps_message('A visitor message after the silent page notice.','agent',ps_context());$afterSource=vp3_profile_speech_item_pa4a($p,(int)$item['id']);
pac_ok($afterSource['arrival_context']==='page'&&(int)$afterSource['public_conversation_id']===0&&$afterSource['binding_hash']===$beforeSource['binding_hash'],'already saved silent arrival retains original page source when a native chat starts');
$afterChat=pac_auto($afterSource);pac_ok($afterChat['chat_message_id']===$delivery['chat_message_id']&&pa3_count('chat_messages')===$beforeMessages+1,'page-to-chat transition never invalidates or clones a saved silent notice');
ps_tick(300001);$saved=vp3_profile_speech_item_pa4a($p,(int)$item['id']);$again=pac_auto($saved);pac_ok($again['chat_message_id']===$delivery['chat_message_id']&&pa3_count('chat_messages')===$beforeMessages+1&&!$again['item']['automatic_delivery_allowed'],'expired and muted exact saved marker rehydrates without another Chat row');
foreach(['expired','away','dnd','offline','social','hidden','suppressed'] as $reason){
    pac_reset();$item=ps_page();$beforeMessages=pa3_count('chat_messages');
    if($reason==='expired')ps_tick(300001);
    elseif(in_array($reason,['away','dnd'],true))vp3_profile_handoff_presence_pa3($p,ps_owner(),['tab_id'=>str_repeat('a',32),'chat_conversation_id'=>11,'chat_agent_id'=>7,'visible'=>true,'presence_sequence'=>2,'availability'=>$reason]);
    elseif($reason==='offline')$p->exec("UPDATE team_user_presence SET presence_mode='offline' WHERE user_id=1");
    elseif($reason==='social')$p->exec('UPDATE team_user_presence SET social_chat_enabled=0 WHERE user_id=1');
    elseif($reason==='hidden')$p->exec('UPDATE profile_agent_handoff_presence_pa3 SET visible=0 WHERE owner_user_id=1');
    else{$event=profile_event_create($p,1,$ctx=ps_context()['session'],'profile_view',30,7);vp3_profile_speech_link_event_pa4a($p,$item,(int)$event['id']);$p->prepare("INSERT INTO agent_attention_items(owner_user_id,source_event_id,attention_type,status) VALUES(?,?,'profile_view','handled')")->execute([1,(int)$event['id']]);$item=vp3_profile_speech_item_pa4a($p,(int)$item['id']);}
    pac_ok(!pac_view($item)['automatic_delivery_allowed'],$reason.' unsaved notice is not advertised as automatically deliverable');
    pac_denied(fn()=>pac_auto($item),$reason.' cannot bypass policy through direct owner_delivery');
    pac_ok(pa3_count('chat_messages')===$beforeMessages,$reason.' creates no hidden saved Chat row');
}

pac_reset();$item=ps_page();$beforeMessages=pa3_count('chat_messages');$changed=false;$insertedAndBound=false;
$p->afterSql=static function(string $sql)use($p,$item,$beforeMessages,&$changed,&$insertedAndBound):void{
    // Wait until production has captured lastInsertId(). A same-connection UPDATE
    // immediately after INSERT would erase MySQL's pending insertion receipt.
    if(!$changed&&str_starts_with($sql,'UPDATE profile_speech_items_pa4a SET delivery_id=')){
        $changed=true;
        $bound=vp3_profile_speech_item_pa4a($p,(int)$item['id']);
        $insertedAndBound=pa3_count('chat_messages')===$beforeMessages+1&&(int)$bound['chat_message_id']>0&&$bound['delivery_id']!=='';
        $p->exec('UPDATE team_user_presence SET social_chat_enabled=0 WHERE user_id=1');
    }
};
try{pac_denied(fn()=>pac_auto($item),'late owner-setting change after insertion and binding rolls back new visual delivery','PROFILE_SPEECH_DELIVERY_WITHHELD');}finally{$p->afterSql=null;}
pac_ok($insertedAndBound,'late policy mutation runs after a real inserted Chat row and exact source binding exist');
pac_ok($changed&&pa3_count('chat_messages')===$beforeMessages&&(int)vp3_profile_speech_item_pa4a($p,(int)$item['id'])['chat_message_id']===0,'final locked gate rolls back both Chat row and source binding');

pac_reset();$m=ps_message('Read this exact visitor turn.','live',ps_context(2));$item=$m['item'];$arrival=$p->query("SELECT * FROM profile_speech_items_pa4a WHERE kind='arrival' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
pac_ok(!pac_view($arrival)['automatic_delivery_allowed'],'pending owner-visible native handoff owns its arrival interruption');
$native=ps_native_card($item);$beforeMessages=pa3_count('chat_messages');$beforeOperations=pa3_count('profile_agent_handoff_operations_pa3');$turnBefore=vp3_profile_handoff_row_pa3($p,(int)$item['turn_id']);$view=pac_view($item);
pac_ok($view['automatic_delivery_allowed']&&$view['automatic_allowed']&&$view['delivery_kind']==='handoff'&&$view['native_chat_message_id']===$native['chat_message_id'],'current claimed visitor turn preserves automatic speech on its exact native destination');
$delivery=pac_auto($item);pac_ok($delivery['chat_message_id']===$native['chat_message_id']&&$delivery['item']['delivery_kind']==='handoff'&&pa3_count('chat_messages')===$beforeMessages,'native speech binding inserts no second Chat message');
pac_ok(pa3_count('profile_agent_handoff_operations_pa3')===$beforeOperations&&vp3_profile_handoff_row_pa3($p,(int)$item['turn_id'])===$turnBefore,'speech binding neither acknowledges pickup nor mutates native turn/actions');
$marker=$p->prepare('SELECT context_json FROM chat_messages WHERE id=?');$marker->execute([$native['chat_message_id']]);$context=json_decode((string)$marker->fetchColumn(),true);pac_ok(isset($context['profile_handoff'])&&!isset($context['profile_speech']),'native marker ownership remains unchanged');
$input=ps_input($delivery,['mode'=>'automatic']);$claim=vp3_profile_speech_claim_pa4a($p,ps_owner(),$input);$bound=array_replace($input,['revision'=>$claim['item']['revision'],'attempt_id'=>$claim['attempt_id'],'ticket'=>$claim['ticket']]);$begin=vp3_profile_speech_attempt_begin_pa4a($p,ps_owner(),$bound);vp3_profile_speech_attempt_finish_pa4a($p,$begin,vp3_profile_speech_synthesize_pa4a($begin));$permit=vp3_profile_speech_play_pa4a($p,ps_owner(),array_replace($bound,['request_id'=>bin2hex(random_bytes(16))]));
pac_ok($permit['granted']&&count($GLOBALS['psProviderCalls'])===1&&$GLOBALS['psProviderCalls'][0]['text']==='Read this exact visitor turn.','native-hosted automatic playback obtains exact source and independent speech permit');
pac_ok(pa3_count('chat_messages')===$beforeMessages&&pa3_count('profile_agent_handoff_operations_pa3')===$beforeOperations,'automatic speech never fabricates native ACK/action or another Chat row');
ps_tick(300001);$saved=vp3_profile_speech_item_pa4a($p,(int)$item['id']);$p->exec('UPDATE team_user_presence SET sound_enabled=0 WHERE user_id=1');$recovered=pac_auto($saved);pac_ok($recovered['delivery_kind']==='handoff'&&$recovered['chat_message_id']===$native['chat_message_id'],'native delivery kind survives expired muted rereads');
$p->exec('UPDATE team_user_presence SET sound_enabled=1 WHERE user_id=1');$saved=vp3_profile_speech_item_pa4a($p,(int)$item['id']);$manual=ps_delivery($saved);pac_ok($manual['chat_message_id']===$native['chat_message_id']&&pa3_count('chat_messages')===$beforeMessages,'explicit selected manual replay uses the same native card');
pac_denied(fn()=>ps_delivery($saved,['visitor_message_id'=>(int)$item['visitor_message_id']+1]),'manual source selector cannot retarget a saved item','PROFILE_SPEECH_UNAVAILABLE');
pac_denied(fn()=>ps_delivery($saved,['public_conversation_id'=>(int)$item['public_conversation_id']+1]),'manual thread selector cannot retarget a saved item','PROFILE_SPEECH_UNAVAILABLE');
pac_denied(fn()=>vp3_profile_speech_delivery_pa4a($p,ps_owner(),ps_scope(['item_id'=>(int)$saved['id'],'revision'=>(int)$saved['revision'],'mode'=>'manual'])),'manual delivery requires an explicit exact selected visitor source','PROFILE_SPEECH_INVALID');
$otherTab=str_repeat('e',32);vp3_profile_handoff_presence_pa3($p,ps_owner(),['tab_id'=>$otherTab,'chat_conversation_id'=>11,'chat_agent_id'=>7,'visible'=>true,'presence_sequence'=>1]);
$beforeMessages=pa3_count('chat_messages');$saved=vp3_profile_speech_item_pa4a($p,(int)$item['id']);$otherState=vp3_profile_speech_state_pa4a($p,ps_owner(),ps_scope(['tab_id'=>$otherTab,'public_conversation_id'=>(int)$item['public_conversation_id']]));$otherItem=array_values(array_filter($otherState['items'],fn($r)=>$r['id']===(int)$item['id']))[0];
pac_ok($otherItem['delivery_kind']==='handoff'&&$otherItem['native_chat_message_id']===$native['chat_message_id'],'native delivery kind survives reload before a new tab rehydrates its native card');
pac_denied(fn()=>pac_auto($saved,['tab_id'=>$otherTab]),'new tab cannot bind speech before its exact native card delivery');
pac_ok(pa3_count('chat_messages')===$beforeMessages&&vp3_profile_speech_item_pa4a($p,(int)$item['id'])['tab_id']===$saved['tab_id'],'rejected new-tab binding leaves message and original speech target intact');
$nativeOther=ps_native_card($item,false,['tab_id'=>$otherTab]);$rebound=pac_auto($saved,['tab_id'=>$otherTab]);
pac_ok($nativeOther['chat_message_id']===$native['chat_message_id']&&$rebound['chat_message_id']===$native['chat_message_id']&&pa3_count('chat_messages')===$beforeMessages,'explicit current-tab native rehydration permits same-message speech recovery without migration');
pac_denied(fn()=>vp3_profile_speech_audio_authorize_pa4a($p,ps_owner(),$bound),'old tab/client binding cannot use an artifact after exact new-tab recovery');
vp3_profile_handoff_presence_pa3($p,ps_owner(),['tab_id'=>str_repeat('f',32),'chat_conversation_id'=>12,'chat_agent_id'=>8,'visible'=>true,'presence_sequence'=>1]);$saved=vp3_profile_speech_item_pa4a($p,(int)$item['id']);
pac_denied(fn()=>pac_auto($saved,['tab_id'=>str_repeat('f',32),'chat_conversation_id'=>12,'chat_agent_id'=>8]),'saved native speech cannot be retargeted to another private Chat and named Agent');
pac_ok(pa3_count('chat_messages')===$beforeMessages,'cross-scope retries never clone the native message');

pac_reset();$m=ps_message('Owner workspace takeover without a live card.','agent',ps_context(2));$turn=vp3_profile_handoff_row_pa3($p,(int)$m['item']['turn_id']);vp3_profile_handoff_owner_action_pa3($p,ps_owner(),['operation'=>'claim','turn_id'=>(int)$turn['id'],'revision'=>(int)$turn['version'],'conversation_id'=>(int)$turn['conversation_id'],'chat_conversation_id'=>11,'chat_agent_id'=>7,'request_id'=>bin2hex(random_bytes(16))]);
pac_ok(!pac_view($m['item'])['automatic_delivery_allowed'],'owner takeover through workspace does not invent a visible native speech card');pac_denied(fn()=>pac_auto($m['item']),'automatic visitor delivery still requires its exact current native card','PROFILE_SPEECH_DELIVERY_WITHHELD');
pac_reset();$m=ps_message('Agent-only bounded context.','agent',ps_context(2));$arrival=$p->query("SELECT * FROM profile_speech_items_pa4a WHERE kind='arrival' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);pac_ok(pac_view($arrival)['automatic_delivery_allowed'],'Agent-only thread without native owner card keeps its arrival notice');
$offer=vp3_profile_call_visitor_action_pa3c($p,$m['context'],['operation'=>'offer','request_id'=>bin2hex(random_bytes(16)),'conversation_id'=>(int)$m['item']['public_conversation_id'],'mode'=>'audio']);
pac_ok(!pac_view($arrival)['automatic_delivery_allowed'],'actual offered call owns its exact conversation interruption');
pac_denied(fn()=>pac_auto($arrival),'active native call cannot be bypassed through automatic arrival delivery','PROFILE_SPEECH_DELIVERY_WITHHELD');
$accepted=vp3_profile_call_owner_action_pa3c($p,ps_owner(),pa3c_owner_input($offer,'accept'));pac_ok($accepted['call']['state']==='preparing'&&!pac_view($arrival)['automatic_delivery_allowed'],'canonical accepted preparing call retains its native presentation ownership');
vp3_profile_call_work_pa3c($p,(int)$accepted['call']['call_id'],'pa3c_provider');pac_ok(vp3_profile_call_row_pa3c($p,(int)$accepted['call']['call_id'])['state']==='ready'&&!pac_view($arrival)['automatic_delivery_allowed'],'canonical provisioned ready call retains its native presentation ownership');
foreach(['claim','grant','recovery'] as $boundary){
    pac_reset();$ctx=ps_context(2);$m=ps_message('Initial Agent-only visitor request.','agent',$ctx);$arrival=$p->query("SELECT * FROM profile_speech_items_pa4a WHERE kind='arrival' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);$delivered=pac_auto($arrival);$input=ps_input($delivered,['mode'=>'automatic']);$claim=null;$playInput=null;$permit=null;
    if($boundary!=='claim'){$claim=vp3_profile_speech_claim_pa4a($p,ps_owner(),$input);$input=array_replace($input,['revision'=>$claim['item']['revision'],'attempt_id'=>$claim['attempt_id'],'ticket'=>$claim['ticket']]);$begin=vp3_profile_speech_attempt_begin_pa4a($p,ps_owner(),$input);vp3_profile_speech_attempt_finish_pa4a($p,$begin,vp3_profile_speech_synthesize_pa4a($begin));$playInput=array_replace($input,['request_id'=>bin2hex(random_bytes(16))]);if($boundary==='recovery')$permit=vp3_profile_speech_play_pa4a($p,ps_owner(),$playInput);}
    pa3_age();ps_tick(1000);$live=ps_message('Please connect me with the owner.','live',$ctx,(int)$m['item']['public_conversation_id']);$view=pac_view($arrival);
    pac_ok(!$view['automatic_delivery_allowed']&&!$view['automatic_allowed'],'new native obligation withdraws old arrival automatic policy before '.$boundary);
    if($boundary==='claim'){pac_denied(fn()=>vp3_profile_speech_claim_pa4a($p,ps_owner(),$input),'old delivered arrival cannot obtain a new automatic claim after native ownership','PROFILE_SPEECH_AUTOMATIC_UNAVAILABLE');pac_ok(count($GLOBALS['psProviderCalls'])===0&&pa3_count('profile_speech_attempts_pa4a')===0,'withheld automatic claim makes no speech attempt or synthesis');}
    elseif($boundary==='grant'){pac_denied(fn()=>vp3_profile_speech_play_pa4a($p,ps_owner(),$playInput),'ready old arrival cannot obtain its first automatic permit after native ownership','PROFILE_SPEECH_AUTOMATIC_UNAVAILABLE');pac_ok(vp3_profile_speech_attempt_row_pa4a($p,$claim['attempt_id'])['play_request_id']===''&&(int)vp3_profile_speech_item_pa4a($p,(int)$arrival['id'])['auto_consumed']===0&&count($GLOBALS['psProviderCalls'])===1,'withheld first grant preserves prior saved audio without claiming playback, consumption, or another synthesis');}
    else{$again=vp3_profile_speech_play_pa4a($p,ps_owner(),$playInput);pac_ok($again['duplicate']&&$again['play_token']===$permit['play_token']&&$again['lease_until_ms']===$permit['lease_until_ms'],'already issued exact permit recovers independently of fresh-candidate ownership');$report=vp3_profile_speech_report_pa4a($p,ps_owner(),array_replace($input,['request_id'=>bin2hex(random_bytes(16)),'play_token'=>$permit['play_token'],'status'=>'ended']));pac_ok($report['state']==='ended','ongoing exact playback reports remain independent of candidate suppression');}
    $native=ps_native_card($live['item']);
    if($boundary==='grant'){pac_denied(fn()=>ps_claim($live['item']),'existing bounded ready-audio lease is not silently discarded for another source','PROFILE_SPEECH_BUSY');ps_tick(95000);}
    $liveItem=vp3_profile_speech_item_pa4a($p,(int)$live['item']['id']);$manual=ps_claim($liveItem);pac_ok($manual['item']['delivery_kind']==='handoff'&&$manual['item']['chat_message_id']===$native['chat_message_id'],'explicit selected visitor playback remains usable after '.$boundary.' policy change');
}
echo 'PROFILE_ARRIVAL_CONTEXT_V1=PASS ('.$checks.' checks; '.$p->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
