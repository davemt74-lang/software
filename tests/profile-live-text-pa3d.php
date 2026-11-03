<?php
declare(strict_types=1);
/** Exact production SQL checks for the visitor Agent/live text choice. */
define('PA3_HANDOFF_HELPERS_ONLY',true);
require __DIR__.'/profile-handoff-pa3.php';

function pa3d_send(string $mode,string $message='What are the public hours?',int $cid=0,?string $request=null,int $visitor=0,string $browser='b'): array
{
    $ctx=pa3_context($visitor,$browser);$request??=bin2hex(random_bytes(16));
    return vp3_profile_agent_public_message_service_v110(db(),$ctx,$message,$cid,['async'=>true,'client_request_id'=>$request,'chat_mode'=>$mode]);
}
function pa3d_chat(int $cid=0): array{return vp3_profile_handoff_chat_pa3d(db(),1,$cid);}
function pa3d_list(): array{return vp3_profile_handoff_owner_list_pa3(db(),pa3_owner(),['chat_conversation_id'=>11,'chat_agent_id'=>7]);}
function pa3d_settings(string $mode='online',int $social=1): void
{
    $p=db();$q=$p->prepare('SELECT user_id FROM team_user_presence WHERE user_id=1');$q->execute();
    if($q->fetchColumn())$p->prepare('UPDATE team_user_presence SET presence_mode=?,social_chat_enabled=? WHERE user_id=1')->execute([$mode,$social]);
    else $p->prepare('INSERT INTO team_user_presence(user_id,presence_mode,social_chat_enabled) VALUES(1,?,?)')->execute([$mode,$social]);
}
function pa3d_end(array $receipt,?int $revision=null,int $visitor=0,string $browser='b'): array
{
    $turn=pa3_turn($receipt);
    return vp3_profile_agent_public_end_live_chat_pa3d(db(),pa3_context($visitor,$browser),(int)$turn['conversation_id'],(int)$turn['id'],$revision??(int)$turn['version']);
}

pa3_reset();
$empty=vp3_profile_agent_public_state_service_v110($pdo,pa3_context());
pa3_check(($empty['chat']['contract']??'')==='vp3.profile-chat-choice.v1'&&$empty['chat']['owner_name']==='Fixture Owner'&&$empty['chat']['live_text_available']===false,'public state exposes the public owner name and unavailable live choice before a thread exists');
pa3_presence();pa3_check(pa3d_chat()['live_text_available']===true,'fresh exact visible main Chat and canonical settings permit live text');
$wire=json_encode(pa3d_chat(),JSON_THROW_ON_ERROR);
foreach(['chat_conversation_id','chat_agent_id','tab_id','last_seen','credential_hash','PRIVATE_CHAT_SECRET','FOREIGN_CHAT_SECRET','owner@example.test'] as $secret)pa3_check(!str_contains($wire,$secret),'public chat choice excludes '.$secret);
foreach([['offline',1],['online',0],['invalid',1],['online',2],['online',-1]] as [$mode,$social]){
    pa3d_settings($mode,$social);pa3_check(pa3d_chat()['live_text_available']===false,'canonical settings fail closed for presence '.$mode.' and social flag '.$social);
}
pa3d_settings();$pdo->exec('DELETE FROM team_user_presence WHERE user_id=1');
pa3_check(pa3d_chat()['live_text_available']===true,'a missing owner settings row uses canonical defaults only with ready storage');
$pdo->failSql='FROM team_user_presence';
pa3_check(pa3d_chat()['live_text_available']===false,'a settings SQL failure cannot invent online availability');$pdo->failSql='';
$pdo->exec('ALTER TABLE team_user_presence RENAME TO team_user_presence_pa3d_missing');
try{pa3_check(pa3d_chat()['live_text_available']===false&&!table_exists('team_user_presence'),'public availability fails closed without creating missing settings storage');}
finally{$pdo->exec('ALTER TABLE team_user_presence_pa3d_missing RENAME TO team_user_presence');}
foreach(['away','dnd'] as $override){pa3_presence(['presence_sequence'=>$override==='away'?2:3,'availability'=>$override]);pa3_check(pa3d_chat()['live_text_available']===false,'PA3 '.$override.' remains a narrower live text override');}
pa3_presence(['presence_sequence'=>4,'availability'=>'available']);$GLOBALS['pa3Now']+=45001;
pa3_check(pa3d_chat()['live_text_available']===false,'expired exact Chat visibility cannot authorize live text');

foreach([
    'private Profile'=>"UPDATE user_profiles SET is_public=0 WHERE user_id=1",
    'disabled Profile Agent acceptance'=>"UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1",
    'disabled selected Agent'=>"UPDATE user_agents SET is_active=0 WHERE id=7",
    'inactive owner'=>"UPDATE users SET is_active=0 WHERE id=1",
    'revoked owner Chat access'=>"DELETE FROM role_permissions WHERE permission_key='chat.access'",
    'revoked owner Profile access'=>"DELETE FROM role_permissions WHERE permission_key='profile_chat.access'",
] as $revoked=>$sql){
    pa3_reset();pa3_presence();$pdo->exec($sql);
    pa3_check(pa3d_chat()['live_text_available']===false,$revoked.' fails closed in the public live choice');
}

pa3_reset();pa3_presence();$request=str_repeat('d',32);$agent=pa3d_send('agent','Default Agent question',0,$request);$turn=pa3_turn($agent);$cid=(int)$turn['conversation_id'];
pa3_check($turn['state']==='agent_ready'&&$turn['pickup_deadline_ms']===null&&$agent['chat']['mode']==='agent'&&$agent['chat']['live_status']==='none','explicit Agent chat queues immediately while the owner is available');
pa3_check(pa3_count('agent_workflow_runs',"status='approved'")===1&&count($GLOBALS['pa3ModelCalls'])===0,'Agent-only admission schedules the canonical job without paid inference');
pa3_check(pa3d_list()['turns']===[]&&pa3_count('profile_agent_handoff_deliveries_pa3')===0,'Agent-only admission does not mount or ring a new proactive owner card');
pa3_denied(fn()=>pa3_delivery($turn),'Agent-only direct delivery cannot create a new proactive card');
pa3_check(pa3_count('agent_attention_items')>0,'Agent-only chat retains passive durable attention history');
$duplicate=pa3d_send('agent','Default Agent question',0,$request);
pa3_check($duplicate['duplicate']&&$duplicate['handoff']['turn_id']===$agent['handoff']['turn_id'],'same explicit mode retries one durable turn');
pa3_denied(fn()=>pa3d_send('live','Default Agent question',0,$request),'an explicit admission retry cannot change Agent into live','HANDOFF_REPLAY_CONFLICT');
pa3_denied(fn()=>pa3_admit('Default Agent question',0,$request),'an explicit admission retry cannot shed its mode binding','HANDOFF_REPLAY_CONFLICT');
vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);
pa3_check(pa3_turn($agent)['state']==='replied'&&count($GLOBALS['pa3ModelCalls'])===1,'Agent-only conversation publishes exactly one canonical Agent reply');
pa3_reset();$legacy=pa3_admit('Legacy exact request',0,str_repeat('e',32));$again=pa3_admit('Legacy exact request',0,str_repeat('e',32));
pa3_check($again['duplicate']&&pa3_turn($legacy)['chat_mode']==='auto','omitted legacy mode preserves its original idempotent request binding');

pa3_age();$newer=pa3_admit('A newer saved request',(int)$legacy['conversation_id']);$oldRetry=pa3_admit('Legacy exact request',0,str_repeat('e',32));
pa3_check($oldRetry['duplicate']&&$oldRetry['handoff']['turn_id']===$legacy['handoff']['turn_id']&&$oldRetry['handoff']['visitor_message_id']===$legacy['visitor_message_id']&&$oldRetry['visitor_message_id']===$legacy['visitor_message_id'],'late admission retry retains its original turn and visitor message after a newer saved turn');
foreach(['invalid','LIVE','',null,1,true] as $bad){
    pa3_reset();pa3_denied(fn()=>vp3_profile_agent_public_message_service_v110($pdo,pa3_context(),'Bad delivery mode',0,['async'=>true,'client_request_id'=>bin2hex(random_bytes(16)),'chat_mode'=>$bad]),'invalid explicit chat mode is rejected');
    pa3_check(pa3_count('profile_agent_messages',"sender_type='visitor'")===0,'invalid chat mode cannot partially save a visitor message');
}

pa3_reset();pa3_presence();$live=pa3d_send('live');$turn=pa3_turn($live);$cid=(int)$turn['conversation_id'];
pa3_check($turn['state']==='awaiting_delivery'&&$live['chat']['mode']==='live'&&$live['chat']['live_status']==='waiting'&&$live['chat']['can_end_live_chat']===true,'available explicit live request waits for owner acceptance in the durable Profile thread');
pa3_check(count(pa3d_list()['turns'])===1&&pa3_count('agent_workflow_runs',"status='paused'")===1,'live request produces an owner card and holds Agent execution');
$delivery=pa3_delivery($turn);pa3_check(($delivery['turn']['chat']['live_status']??'')==='waiting','exact owner delivery carries the same live chat projection');
$now=$GLOBALS['pa3Now'];$acked=vp3_profile_handoff_ack_pa3($pdo,pa3_owner(),pa3_ack_input($delivery));$deadline=(int)pa3_turn($live)['pickup_deadline_ms'];
pa3_check($deadline===$now+15000&&($acked['turn']['chat']['live_status']??'')==='waiting','exact visible card starts the canonical 15 second live acceptance window');
$GLOBALS['pa3Now']+=3000;pa3_age();$second=pa3d_send('live','An additional live question',$cid);$secondTurn=pa3_turn($second);
pa3_check((int)$secondTurn['pickup_deadline_ms']===$deadline&&$secondTurn['state']==='awaiting_owner','additional live messages reuse the first acceptance deadline');
$reloaded=vp3_profile_agent_public_poll_service_v110($pdo,pa3_context(),$cid);
pa3_check($reloaded['chat']['live_status']==='waiting'&&(int)$reloaded['handoff']['pickup_deadline_ms']===$deadline,'reload recovers the same durable live request and deadline');
$ackAgain=vp3_profile_handoff_ack_pa3($pdo,pa3_owner(),pa3_ack_input($delivery));
pa3_check($ackAgain['duplicate']&&(int)pa3_turn($live)['pickup_deadline_ms']===$deadline,'duplicate delivery acknowledgement cannot restart the acceptance window');
$GLOBALS['pa3Now']=$deadline;vp3_profile_handoff_tick_pa3($pdo,1,$cid);
pa3_check(pa3_turn($live)['state']==='agent_ready'&&pa3_turn($second)['state']==='agent_ready'&&pa3d_chat($cid)['live_status']==='expired','expired live request resumes every unanswered turn through the Agent queue');
pa3_check(count($GLOBALS['pa3ModelCalls'])===0,'live timeout maintenance never calls the provider');

pa3_denied(fn()=>vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($second),'claim')),'expired live request cannot later become falsely accepted');
pa3_check(pa3d_chat($cid)['live_status']==='expired'&&pa3_turn($second)['state']==='agent_ready','late Accept preserves the saved expired fallback');
pa3_reset();pa3_presence();$late=pa3d_send('live');$delivery=pa3_delivery(pa3_turn($late));
vp3_profile_handoff_ack_pa3($pdo,pa3_owner(),pa3_ack_input($delivery));$GLOBALS['pa3Now']=(int)pa3_turn($late)['pickup_deadline_ms'];
pa3_denied(fn()=>vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($late),'claim')),'Accept at the pickup deadline rejects even before maintenance runs');
pa3_check(pa3d_chat((int)$late['conversation_id'])['live_status']!=='accepted','deadline race cannot record false human acceptance');

pa3_reset();pa3_presence();$live=pa3d_send('live');$turn=pa3_turn($live);$cid=(int)$turn['conversation_id'];
$accepted=vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input($turn,'claim'));
pa3_check($accepted['turn']['state']==='owner_claimed'&&$accepted['turn']['chat']['live_status']==='accepted'&&pa3d_chat($cid)['can_end_live_chat']===true,'owner Accept switches the same thread to live human text');
pa3_age();$follow=pa3d_send('live','A message for the accepted owner',$cid);
pa3_check(pa3_turn($follow)['state']==='owner_claimed'&&pa3_turn($follow)['pickup_deadline_ms']===null&&$follow['chat']['live_status']==='accepted','accepted live follow-ups remain human with no restarted pickup clock');
vp3_profile_handoff_dispatch_pa3($pdo,(int)$follow['handoff']['turn_id']);
pa3_check(count($GLOBALS['pa3ModelCalls'])===0,'Agent dispatch cannot respond inside an accepted live chat');
$replied=vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($follow),'reply',['message'=>'Human live answer']));
pa3_check(pa3_count('profile_agent_messages',"sender_type='owner'")===1&&$replied['turn']['chat']['live_status']==='accepted','owner text publishes through the canonical thread and retains accepted chat state');
$revision=(int)pa3_turn($follow)['version'];$ended=pa3d_end($follow,$revision);
pa3_check($ended['chat']['mode']==='agent'&&$ended['chat']['live_status']==='ended'&&$ended['chat']['can_end_live_chat']===false,'visitor may end an accepted live chat after its latest human answer');
pa3_check((int)pa3_turn($follow)['version']>$revision&&count($GLOBALS['pa3ModelCalls'])===0,'ending bumps a terminal turn fence and performs no inference');
$endAgain=pa3d_end($follow,$revision);
pa3_check($endAgain['chat']['live_status']==='ended'&&pa3_count('profile_agent_messages',"sender_type='owner'")===1,'same exact visitor end retry is safely idempotent');

// A lost end receipt can arrive after the canonical queue has made progress.
pa3_reset();pa3_presence();$unanswered=pa3d_send('live');
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($unanswered),'claim'));
$endRevision=(int)pa3_turn($unanswered)['version'];pa3d_end($unanswered,$endRevision);
vp3_profile_handoff_dispatch_pa3($pdo,(int)$unanswered['handoff']['turn_id']);
$progressRetry=pa3d_end($unanswered,$endRevision);
pa3_check($progressRetry['chat']['live_status']==='ended'&&pa3_turn($unanswered)['state']==='replied'&&pa3_count('profile_agent_messages',"sender_type='agent'")===1,'exact lost end receipt retries after canonical Agent progress without repeating a reply');
pa3_denied(fn()=>pa3d_end($unanswered,$endRevision+999),'ended retry still binds the exact originally observed revision','HANDOFF_CHANGED');
// Continue the earlier answered lifecycle for stale end isolation.
pa3_reset();pa3_presence();$follow=pa3d_send('live');$cid=(int)$follow['conversation_id'];
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($follow),'reply'));
$revision=(int)pa3_turn($follow)['version'];pa3d_end($follow,$revision);
pa3_age();$newLive=pa3d_send('live','Please start another live chat',$cid);
pa3_denied(fn()=>pa3d_end($follow,$revision),'an old end request cannot end a newer live request','HANDOFF_CHANGED');
pa3_check(pa3d_chat($cid)['live_status']==='waiting','stale end leaves the newer live request waiting');
pa3_denied(fn()=>vp3_profile_agent_public_end_live_chat_pa3d($pdo,pa3_context(3,'f'),$cid,(int)$newLive['handoff']['turn_id'],(int)$newLive['handoff']['revision']),'another visitor cannot end this live chat');

foreach(['delegate'=>'declined','reply'=>'accepted'] as $operation=>$expected){
    pa3_reset();pa3_presence();$live=pa3d_send('live');$turn=pa3_turn($live);
    $out=vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input($turn,$operation));
    pa3_check($out['turn']['chat']['live_status']===$expected,'owner '.$operation.' during waiting records '.$expected.' live state');
    if($operation==='delegate')pa3_check(pa3_turn($live)['state']==='agent_ready'&&pa3_count('agent_workflow_runs',"status='approved'")===1,'owner Decline immediately schedules Agent fallback');
}
pa3_reset();pa3_presence();$live=pa3d_send('live');vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($live),'claim'));
$out=vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($live),'delegate'));
pa3_check($out['turn']['chat']['live_status']==='ended'&&pa3_turn($live)['state']==='agent_ready','owner delegate after acceptance ends live chat and resumes unanswered Agent work');

pa3_reset();pa3_presence();$a=pa3d_send('live','Visitor A live message',0,null,2,'b');pa3_age();$b=pa3d_send('live','Visitor B live message',0,null,3,'e');
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($a),'claim'));
pa3_check((int)$a['conversation_id']!==(int)$b['conversation_id']&&pa3d_chat((int)$a['conversation_id'])['live_status']==='accepted'&&pa3d_chat((int)$b['conversation_id'])['live_status']==='waiting','two visitors retain independent live acceptance states');
pa3d_end($a,null,2,'b');
pa3_check(pa3d_chat((int)$b['conversation_id'])['live_status']==='waiting'&&pa3_turn($b)['state']==='awaiting_delivery','ending one visitor chat cannot release or cancel another visitor');

foreach(['offline','social-disabled','presence-expired'] as $revocation){
    pa3_reset();pa3_presence();$live=pa3d_send('live');$turn=pa3_turn($live);$cid=(int)$turn['conversation_id'];
    if($revocation==='offline')pa3d_settings('offline');elseif($revocation==='social-disabled')pa3d_settings('online',0);else $GLOBALS['pa3Now']+=45001;
    pa3_denied(fn()=>vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($live),'claim')),$revocation.' blocks a new live acceptance');
    $out=vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($live),'delegate'));
    pa3_check($out['turn']['chat']['live_status']==='declined'&&pa3_turn($live)['state']==='agent_ready',$revocation.' still permits owner Decline cleanup');
    pa3_reset();pa3_presence();$live=pa3d_send('live');$cid=(int)$live['conversation_id'];vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($live),'claim'));
    if($revocation==='offline')pa3d_settings('offline');elseif($revocation==='social-disabled')pa3d_settings('online',0);else $GLOBALS['pa3Now']+=45001;
    $before=$pdo->query('SELECT * FROM profile_agent_handoff_turns_pa3')->fetchAll();$poll=vp3_profile_agent_public_poll_service_v110($pdo,pa3_context(),$cid);
    pa3_check($poll['chat']['live_text_available']===false&&$before===$pdo->query('SELECT * FROM profile_agent_handoff_turns_pa3')->fetchAll(),$revocation.' public poll remains read-only');
    vp3_profile_handoff_tick_pa3($pdo,1,$cid);
    pa3_check(pa3_turn($live)['state']==='agent_ready'&&in_array(pa3d_chat($cid)['live_status'],['ended','unavailable'],true),$revocation.' releases accepted unanswered human work through canonical maintenance');
}
pa3_reset();pa3_presence();pa3d_settings('offline');$race=pa3d_send('live');
pa3_check(pa3_turn($race)['state']==='agent_ready'&&$race['chat']['live_status']==='unavailable'&&pa3_count('profile_agent_messages',"sender_type='visitor'")===1,'live availability race saves the message and falls back to Agent with unavailable status');

pa3_reset();pa3_presence();$old=pa3d_send('agent','First Agent message');$oldTurn=pa3_turn($old);$new=null;
$GLOBALS['pa3ModelHook']=static function()use($old,&$new):void{$GLOBALS['pa3ModelHook']=null;pa3_age();$new=pa3d_send('live','Switch this conversation to live',(int)$old['conversation_id']);};
vp3_profile_handoff_dispatch_pa3($pdo,(int)$oldTurn['id']);
pa3_check(is_array($new)&&pa3_turn($old)['state']==='awaiting_delivery'&&pa3_turn($new)['state']==='awaiting_delivery','new live request atomically holds existing unanswered Agent work');
pa3_check(pa3_count('profile_agent_messages',"sender_type='agent'")===0&&pa3_count('agent_workflow_receipts',"status='completed'")===0,'new live epoch fences an in-flight old Agent answer and successful receipt');
$decline=vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($new),'delegate'));
vp3_profile_handoff_dispatch_pa3($pdo,(int)$oldTurn['id']);vp3_profile_handoff_dispatch_pa3($pdo,(int)$new['handoff']['turn_id']);
pa3_check($decline['turn']['chat']['live_status']==='declined'&&pa3_count('profile_agent_messages',"sender_type='agent'")===2&&pa3_count('agent_workflow_receipts',"status='completed'")===2,'decline resumes both exact durable turns once after old inference is fenced');

pa3_reset();pa3_presence();$live=pa3d_send('live');$turn=pa3_turn($live);$cid=(int)$turn['conversation_id'];$delivered=pa3_delivery($turn);
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input($turn,'claim'));pa3_age();$agent=pa3d_send('agent','Return to the Agent',$cid);
pa3_check(pa3_turn($live)['state']==='agent_ready'&&pa3_turn($agent)['state']==='agent_ready'&&$agent['chat']['mode']==='agent','explicit return to Agent fences the former human responder and queues all unanswered turns');
pa3_denied(fn()=>vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input($turn,'reply')),'stale owner response cannot publish after visitor returns to Agent','HANDOFF_CHANGED');
pa3_check(count(pa3d_list()['turns'])===1,'Agent choice preserves an already delivered owner card history');

$historyDelivery=pa3_delivery(pa3_turn($agent));
pa3_check($historyDelivery['turn']['chat']['mode']==='agent'&&$historyDelivery['chat_conversation_id']===11,'direct delivery may update Agent history in the exact previously delivered private Chat');
pa3_presence(['tab_id'=>str_repeat('f',32),'chat_conversation_id'=>12,'chat_agent_id'=>8]);
pa3_denied(fn()=>pa3_delivery(pa3_turn($agent),['tab_id'=>str_repeat('f',32),'chat_conversation_id'=>12,'chat_agent_id'=>8]),'Agent history cannot create a proactive card in another private Chat');

pa3_reset();pa3_presence();$race=pa3d_send('live');$disabledDuringGate=false;
$pdo->afterSql=static function(string $sql)use($pdo,&$disabledDuringGate):void{
    if(!$disabledDuringGate&&str_contains($sql,'FROM team_user_presence WHERE user_id=? LIMIT 2')){
        $disabledDuringGate=true;$pdo->exec('UPDATE team_user_presence SET social_chat_enabled=0 WHERE user_id=1');
        // The canonical defaults permit an absent row, so ensure the revocation exists.
        if(pa3_count('team_user_presence','user_id=1')===0)$pdo->exec("INSERT INTO team_user_presence(user_id,presence_mode,social_chat_enabled) VALUES(1,'online',0)");
    }
};
try{pa3_denied(fn()=>vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($race),'claim')),'social chat revoked during acceptance is rechecked before commit');}
finally{$pdo->afterSql=null;}
pa3_check($disabledDuringGate&&pa3_turn($race)['state']==='awaiting_delivery'&&pa3_count('profile_agent_handoff_operations_pa3')===0,'late settings revocation leaves no accepted owner state or replay operation');

// Test a separately committed preference against a deliberately older MySQL snapshot.
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
    pa3_reset();pa3_presence();pa3d_settings();$live=pa3d_send('live');$config=$GLOBALS['pa3Config'];
    $other=new PDO($config['dsn'],$config['user']??null,$config['password']??null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
    try{
        pa3_check((int)$pdo->query('SELECT social_chat_enabled FROM team_user_presence WHERE user_id=1')->fetchColumn()===1,'independent MySQL check begins with an older Online snapshot');
        $other->exec('UPDATE team_user_presence SET social_chat_enabled=0 WHERE user_id=1');
        pa3_denied(fn()=>vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($live),'claim')),'current MySQL acceptance sees a separately committed revocation despite caller snapshot','HANDOFF_UNAVAILABLE');
    }finally{$pdo->rollBack();}
    pa3_check(pa3_turn($live)['state']==='awaiting_delivery'&&pa3_count('profile_agent_handoff_operations_pa3')===0,'independent preference revocation leaves no accepted state or replay operation');
}

pa3_reset();pa3_presence();$live=pa3d_send('live');$cid=(int)$live['conversation_id'];
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($live),'reply'));
$answered=pa3_turn($live);pa3d_settings('offline');vp3_profile_handoff_tick_pa3($pdo,1,$cid);
pa3_check($answered['state']==='replied'&&pa3_turn($live)['state']==='replied'&&(int)pa3_turn($live)['version']===(int)$answered['version']+1&&pa3d_chat($cid)['live_status']==='unavailable','disconnect after a human answer bumps the latest terminal revision without reopening answered work');

pa3_reset();pa3_presence();$live=pa3d_send('live');$cid=(int)$live['conversation_id'];
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($live),'delegate'));pa3_age();
$continued=vp3_profile_agent_public_message_service_v110($pdo,pa3_context(),'Continue after owner decline',$cid,['async'=>true,'client_request_id'=>str_repeat('9',32),'chat_mode'=>'live','continue_live'=>true]);
pa3_check($continued['chat']['live_status']==='declined'&&pa3_turn($continued)['state']==='agent_ready'&&pa3d_list()['turns']===[],'stale continuation after decline preserves the outcome and creates no owner card');
pa3_denied(fn()=>pa3d_send('live','Continue after owner decline',$cid,str_repeat('9',32)),'stale continuation retry cannot become a fresh live request','HANDOFF_REPLAY_CONFLICT');
pa3_reset();pa3_presence();
$missing=vp3_profile_agent_public_message_service_v110($pdo,pa3_context(),'Continue without a live request',0,['async'=>true,'chat_mode'=>'live','continue_live'=>true]);
pa3_check($missing['chat']['live_status']==='unavailable'&&pa3_turn($missing)['state']==='agent_ready'&&pa3d_list()['turns']===[],'continuation without any active live request saves the message for Agent without ringing');

// Exercise a real upgrade from the previous table shape, not only fresh CREATE.
pa3_reset();$legacy=pa3_admit('A retained pre-upgrade request');
$pdo->exec('ALTER TABLE profile_agent_handoff_conversations_pa3 DROP COLUMN requested_mode');
$pdo->exec('ALTER TABLE profile_agent_handoff_conversations_pa3 DROP COLUMN live_status');
$pdo->exec('ALTER TABLE profile_agent_handoff_turns_pa3 DROP COLUMN chat_mode');
pa3_check(!vp3_profile_handoff_schema_ready_pa3($pdo),'previous PA3 tables require the additive live choice upgrade');
$pdo->beginTransaction();
try{vp3_profile_handoff_ensure_schema_pa3($pdo);throw new RuntimeException('Upgrade ran inside transaction');}
catch(RuntimeException $error){pa3_check($error->getMessage()==='Profile handoff schema must be initialized before admission.','additive live upgrade refuses implicit transactional DDL');}
finally{$pdo->rollBack();}
pa3_age();$retained=vp3_profile_agent_public_message_service_v110($pdo,pa3_context(),'A retained native caller during additive upgrade',(int)$legacy['conversation_id']);
pa3_check(($retained['accepted']??false)===true&&$retained['handoff']['turn_id']!==$legacy['handoff']['turn_id']&&pa3_count('profile_agent_handoff_turns_pa3')===2&&count($GLOBALS['pa3ModelCalls'])===0,'retained native caller upgrades and joins existing durable work without competing synchronous inference');
vp3_profile_handoff_ensure_schema_pa3($pdo);vp3_profile_handoff_ensure_schema_pa3($pdo);
pa3_check(vp3_profile_handoff_schema_ready_pa3($pdo)&&pa3_turn($legacy)['chat_mode']==='auto'&&pa3d_chat((int)$legacy['conversation_id'])['mode']==='auto','repeatable additive upgrade preserves old rows with auto semantics');

echo 'PROFILE_LIVE_TEXT_PA3D=PASS '.$checks.' checks ('.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
