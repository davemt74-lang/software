<?php
declare(strict_types=1);

// Production canonical principals, scoped policy, source readers and SQLite.
// The existing foundation controls only model/host transport, never live readers.
require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-live-context-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-live-context-v1.php';

/** Keep the foundation's unrelated routing transport shim; load this exact production SQL-only helper. */
if(!function_exists('vp3_agent_runtime_preference_readonly_v420')){
    $source=file_get_contents(dirname(__DIR__).'/includes/agent-runtime-routing-v420.php');
    $begin=strpos($source,'function vp3_agent_runtime_preference_readonly_v420(');
    $end=strpos($source,"\nfunction vp3_agent_runtime_cloud_state_v420(",$begin);
    if($begin===false||$end===false)throw new RuntimeException('Canonical readonly compute helper missing.');
    eval(str_replace('__DIR__',var_export(dirname(__DIR__).'/includes',true),substr($source,$begin,$end-$begin)));
}

$liveChecks=0;
function live_check(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException($label);
    ++$GLOBALS['liveChecks'];echo 'PASS '.$label.PHP_EOL;
}
function live_reject(callable $run,int $code,string $label):void
{
    try{$run();}catch(Throwable $error){live_check($error->getCode()===$code,$label.' ('.$error->getMessage().')');return;}
    throw new RuntimeException('Expected refusal: '.$label);
}
function live_iso(int $milliseconds):string{return gmdate('Y-m-d\TH:i:s',(int)floor($milliseconds/1000)).'.'.str_pad((string)($milliseconds%1000),3,'0',STR_PAD_LEFT).'Z';}
function live_binding():array{return ['owner_user_id'=>1,'agent_id'=>17,'conversation_id'=>170,'activity_tab'=>str_repeat('a',32),'visible'=>true,'focused'=>true];}
function live_raw(int $now):array
{
    $at=live_iso($now);$share='11111111-1111-4111-8111-111111111111';
    return ['contract'=>'vp3.live-context.v1','owner_user_id'=>1,'agent_id'=>17,'conversation_id'=>170,
        'activity_tab'=>str_repeat('a',32),'session_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','observed_at'=>$at,
        'screens'=>[['source_id'=>'share-1','session_id'=>$share,'selected'=>true,'active'=>true,'context_allowed'=>true,
            'contract'=>'vp3.screen-observation.v1','owner_key'=>'1','surface'=>'browser','captured_at'=>$at,
            'summary'=>'The shared product page shows an unresolved launch date.','visible_text'=>str_repeat('Visible product context. ',24).'LIVE_SCENE_RECORD_SENTINEL',
            'site_hint'=>'Owner supplied tab','page_title'=>'Product launch','on_screen_translation'=>'',
            'observations'=>[['kind'=>'product','label'=>'Consultation','detail'=>'A service offering under review.','confidence'=>'medium']],
            'tab_audio'=>['text'=>'LIVE_TAB_AUDIO_SENTINEL. We still need to choose the release date.','captured_at'=>$at,
                'duration_seconds'=>4,'capture_id'=>'22222222-2222-4222-8222-222222222222','session_id'=>$share,
                'audio_events'=>[['label'=>'[applause]','start_seconds'=>1,'end_seconds'=>2,'provider'=>'elevenlabs',
                    'model'=>'scribe_v2','source'=>'shared_tab_audio','classification'=>'provider_label']]]]],
        'eyes'=>['active'=>true,'context_allowed'=>true,'observed_at'=>$at,'participant_count'=>2,'known_count'=>1,'unknown_count'=>1,
            'greetings_allowed'=>true,'last_event'=>'arrival','last_event_at'=>$at,'object_detection_active'=>true,'object_labels'=>['tv','person']],
        'microphone'=>['active'=>true,'context_allowed'=>true,'observed_at'=>$at,'activity'=>'quiet','quiet_duration_ms'=>10000,'observed_window_ms'=>2000]];
}
function live_store(array $packet,int $now,array $detailsOverride=[],array $rowOverride=[]):void
{
    $details=array_replace(['conversation_id'=>170,'activity_tab'=>str_repeat('a',32),'visible'=>true,'focused'=>true,
        'activity_observed_at'=>$now,'activity_sequence'=>1,'chat_focused'=>true,'chat_busy'=>false,'live_context'=>$packet],$detailsOverride);
    $row=array_replace(['surface'=>'chat','context_key'=>'chat:170','activity_state'=>'paused','last_heartbeat_at'=>gmdate('Y-m-d H:i:s',(int)floor($now/1000))],$rowOverride);
    db()->prepare('UPDATE agent_activity_state SET surface=?,context_key=?,activity_state=?,last_heartbeat_at=?,details_json=? WHERE user_id=1')
        ->execute([$row['surface'],$row['context_key'],$row['activity_state'],$row['last_heartbeat_at'],
            json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
}
function live_row():array{return db()->query('SELECT * FROM agent_activity_state WHERE user_id=1')->fetch(PDO::FETCH_ASSOC);}
function live_fixture_close_cursors():void
{
    // SQLite schema mutations used solely to simulate real metadata outages
    // require old foundation SELECT cursors to be finalized first.
    foreach($GLOBALS as $value)if($value instanceof PDOStatement)$value->closeCursor();
    gc_collect_cycles();
}

$now=(int)floor(microtime(true)*1000);$raw=live_raw($now);$binding=live_binding();
$packet=vp3_live_context_sanitize_v1($raw,$binding,$now);
live_check($packet!==null&&$packet['received_at_ms']===$now&&count($packet['screens'])===1
    &&$packet['eyes']['identity_provisional']===true&&$packet['microphone']['unclassified']===true,
    'the exact typed current packet retains bounded source-provenance and explicit uncertainty');
$withInput=$raw;$withInput['eyes']['recent_chat_input']=true;$withInput['eyes']['chat_input_observed_at']=live_iso($now-1000);
$activeInput=vp3_live_context_sanitize_v1($withInput,$binding,$now);
$semanticInput=vp3_live_context_semantic_v1($activeInput);
$expiredInput=vp3_live_context_sanitize_v1($activeInput,$binding,$now+30000);
live_check($semanticInput['eyes']['recent_chat_input']===true&&$semanticInput['eyes']['authentication_authority']===false
    &&!isset($semanticInput['eyes']['chat_input_observed_at'])&&$expiredInput['eyes']['recent_chat_input']===false
    &&!isset($expiredInput['eyes']['chat_input_observed_at'])&&$expiredInput['eyes']['participant_count']===2,
    'recent input enters model context as a coarse flag and expires on reread while camera presence remains current');
live_check(vp3_live_context_revision_v1($activeInput)===vp3_live_context_revision_v1($packet)
    &&vp3_live_context_revision_v1($activeInput)===vp3_live_context_revision_v1($expiredInput),
    'current input can inform the Agent without replacing the same live source when input starts or expires');
$renewedInput=$withInput;$renewedInput['eyes']['chat_input_observed_at']=live_iso($now-500);
$absentFace=$withInput;$absentFace['eyes']['participant_count']=$absentFace['eyes']['known_count']=$absentFace['eyes']['unknown_count']=0;
$invalidInput=$withInput;$invalidInput['eyes']['chat_input_observed_at']=live_iso($now+5001);
live_check(vp3_live_context_revision_v1($activeInput)===vp3_live_context_revision_v1(vp3_live_context_sanitize_v1($renewedInput,$binding,$now))
    &&!isset(vp3_live_context_sanitize_v1($absentFace,$binding,$now)['eyes']['recent_chat_input'])
    &&vp3_live_context_sanitize_v1($invalidInput,$binding,$now)['eyes']['recent_chat_input']===false,
    'input timestamps do not churn semantic revisions or indicate activity without a visible face and a valid clock');
$untrusted=$raw;$untrusted['received_at_ms']=PHP_INT_MAX;$untrusted['image']='RAW_PIXELS_SENTINEL';
$untrusted['eyes']['participants']=[['name'=>'PRIVATE_IDENTITY_SENTINEL','embedding'=>[1,2,3]]];
$untrusted['microphone']['meaning']='The owner is coughing and approves a purchase';$untrusted['authentication_authority']=true;
$sanitized=vp3_live_context_sanitize_v1($untrusted,$binding,$now);$json=json_encode($sanitized,JSON_THROW_ON_ERROR);
live_check($sanitized['received_at_ms']===$now&&!str_contains($json,'RAW_PIXELS_SENTINEL')&&!str_contains($json,'PRIVATE_IDENTITY_SENTINEL')
    &&!str_contains($json,'coughing')&&$sanitized['authentication_authority']===false,
    'unknown raw media, identity vectors, inferred sound meaning and client receipt authority are discarded');
foreach(['owner_user_id'=>2,'agent_id'=>18,'conversation_id'=>180,'activity_tab'=>str_repeat('b',32),
    'contract'=>'old.live','session_id'=>'not-a-session','observed_at'=>live_iso($now-90001)] as $field=>$value){
    $bad=$raw;$bad[$field]=$value;live_check(vp3_live_context_sanitize_v1($bad,$binding,$now)===null,
        'a malformed, expired or mismatched packet cannot cross the canonical '.$field.' binding');
}
foreach(['owner_user_id'=>'1','agent_id'=>'17','conversation_id'=>'170','visible'=>false,'focused'=>false,'focused_string'=>'true'] as $field=>$value){
    $bad=$binding;$bad[$field==='focused_string'?'focused':$field]=$value;
    live_check(vp3_live_context_sanitize_v1($raw,$bad,$now)===null,'a noncanonical server binding fails closed: '.$field);
}
foreach([live_iso($now+5001),'2026-02-30T12:00:00.000Z','2026-11-06 12:00:00','2026-11-06T12:00:00Zjunk'] as $at){
    $bad=$raw;$bad['observed_at']=$at;live_check(vp3_live_context_sanitize_v1($bad,$binding,$now)===null,
        'clock validation rejects future, invalid-calendar or timezone-ambiguous observations');
}
foreach(['screens','eyes','microphone'] as $component){
    foreach(['active','context_allowed'] as $control){
        $bad=$raw;if($component==='screens')$bad[$component][0][$control]='true';else $bad[$component][$control]='true';
        $safe=vp3_live_context_sanitize_v1($bad,$binding,$now);
        live_check($component==='screens'?$safe['screens']===[]:$safe[$component]===null,
            'string truthiness cannot authorize '.$component.' '.$control);
    }
}
$bad=$raw;$bad['screens']=[];$bad['eyes']['active']=false;$bad['microphone']['active']=false;
live_check(vp3_live_context_sanitize_v1($bad,$binding,$now)===null,'all stopped sources produce no live situation');
$bad=$raw;$bad['screens'][0]['historical_frame']=true;
live_check(vp3_live_context_sanitize_v1($bad,$binding,$now)['screens']===[],'an explicitly historical scene cannot become a current live source');
$bad=$raw;$bad['screens'][]=$bad['screens'][0];
live_check(vp3_live_context_sanitize_v1($bad,$binding,$now)===null,'duplicate screen slots cannot create competing source identities');
$bad=$raw;$bad['screens'][0]['owner_key']='2';
live_check(vp3_live_context_sanitize_v1($bad,$binding,$now)['screens']===[],'a shared scene cannot claim another owner');
$bad=$raw;$bad['screens'][0]['tab_audio']['session_id']='33333333-3333-4333-8333-333333333333';
live_check(!isset(vp3_live_context_sanitize_v1($bad,$binding,$now)['screens'][0]['tab_audio']),
    'a transcript from another share generation cannot attach to the current screen');
$bad=$raw;$bad['screens'][0]['tab_audio']['captured_at']=live_iso($now-90001);
live_check(!isset(vp3_live_context_sanitize_v1($bad,$binding,$now)['screens'][0]['tab_audio']),
    'a fresh heartbeat cannot retimestamp stale shared-tab transcription');
$cue=$raw;$cue['screens'][0]['tab_audio']['text']='';
$cue=vp3_live_context_sanitize_v1($cue,$binding,$now);
live_check($cue['screens'][0]['tab_audio']['text']===''&&$cue['screens'][0]['tab_audio']['audio_events'][0]['classification']==='provider_label',
    'actual provider-labelled tab audio cues remain advisory evidence without a transcript');
$bad=$raw;$bad['screens'][0]['tab_audio']['audio_events'][0]['provider']='microphone_guess';
live_check(vp3_live_context_sanitize_v1($bad,$binding,$now)['screens'][0]['tab_audio']['audio_events']===[],
    'a guessed or mislabeled sound cannot acquire provider provenance');
$bad=$raw;$bad['eyes']['known_count']=2;
live_check(vp3_live_context_sanitize_v1($bad,$binding,$now)['eyes']===null,'inconsistent provisional participant counts are not admitted');
$bad=$raw;$bad['eyes']['last_event_at']=live_iso($now-90001);
live_check(!isset(vp3_live_context_sanitize_v1($bad,$binding,$now)['eyes']['last_event']),
    'a renewed presence count does not renew an expired arrival or departure');
$bad=$raw;$bad['eyes']['object_detection_active']=false;
live_check(!isset(vp3_live_context_sanitize_v1($bad,$binding,$now)['eyes']['object_labels']),
    'object labels require the already active explicitly supplied object detection source');
foreach([-1,300001,'10000'] as $duration){$bad=$raw;$bad['microphone']['quiet_duration_ms']=$duration;
    live_check(vp3_live_context_sanitize_v1($bad,$binding,$now)['microphone']===null,'quiet-duration bounds reject malformed or excessive microphone values');}
foreach([0=>'none',1=>'brief',4999=>'brief',5000=>'sustained',29999=>'sustained',30000=>'long',119999=>'long',120000=>'extended'] as $duration=>$bucket){
    live_check(vp3_live_context_quiet_bucket_v1($duration)===$bucket,'microphone silence uses the bounded coarse bucket '.$duration);
}
$renewed=$raw;$later=$now+1000;$renewed['observed_at']=live_iso($later);$renewed['screens'][0]['captured_at']=live_iso($later);
$renewed['screens'][0]['tab_audio']['captured_at']=live_iso($later);$renewed['screens'][0]['tab_audio']['capture_id']='44444444-4444-4444-8444-444444444444';
$renewed['screens'][0]['tab_audio']['audio_events'][0]['start_seconds']=2;$renewed['screens'][0]['tab_audio']['audio_events'][0]['end_seconds']=3;
$renewed['eyes']['observed_at']=live_iso($later);$renewed['eyes']['last_event_at']=live_iso($later);
$renewed['microphone']['observed_at']=live_iso($later);$renewed['microphone']['quiet_duration_ms']=11000;$renewed['microphone']['observed_window_ms']=2500;
$renewed=vp3_live_context_sanitize_v1($renewed,$binding,$later);
live_check(vp3_live_context_revision_v1($packet)===vp3_live_context_revision_v1($renewed),
    'heartbeat, frame/audio timestamps, segment bookkeeping and same-bucket silence do not create new work');
$changed=$packet;$changed['screens'][0]['visible_text'].=' A semantic correction.';
live_check(vp3_live_context_revision_v1($packet)!==vp3_live_context_revision_v1($changed),'actual visible source content changes the developing situation revision');
$changed=$packet;$changed['screens'][0]['session_id']='55555555-5555-4555-8555-555555555555';
live_check(vp3_live_context_revision_v1($packet)!==vp3_live_context_revision_v1($changed),'a stopped and restarted share generation invalidates old source authority');
$semantic=vp3_live_context_semantic_v1($packet);$semanticJson=json_encode($semantic,JSON_THROW_ON_ERROR);
foreach(['owner_user_id','agent_id','conversation_id','activity_tab','session_id','capture_id','captured_at','observed_at','received_at_ms','start_seconds','end_seconds'] as $field){
    live_check(!str_contains($semanticJson,'"'.$field.'"'),'model observations omit operational identity and timestamp field '.$field);
}

$user=['id'=>1,'is_active'=>1];$principal=scope_principal($user,17);live_store($packet,$now);
$changes=(int)db()->query('SELECT total_changes()')->fetchColumn();$models=count($GLOBALS['scopeInference']);
$snapshot=vp3_live_context_snapshot_v1(db(),$user,$principal,170,str_repeat('a',32));
live_check($snapshot!==null&&$snapshot['ref']==='live-context:'.$raw['session_id']&&$snapshot['revision']===vp3_live_context_revision_v1($packet),
    'the actual canonical owner/Agent/conversation reader loads exactly the current bound session');
live_check((int)db()->query('SELECT total_changes()')->fetchColumn()===$changes&&count($GLOBALS['scopeInference'])===$models,
    'a foreground current snapshot has no write, model call or historical fallback');
live_check(vp3_live_context_snapshot_v1(db(),$user,$principal,170,str_repeat('b',32))===null,'another browser tab cannot read the current tab as its own live binding');
live_reject(static fn()=>vp3_live_context_snapshot_v1(db(),['id'=>2,'is_active'=>1],$principal,170),403,'another account cannot reuse a live principal');
live_reject(static fn()=>vp3_live_context_snapshot_v1(db(),$user,scope_principal($user,18),170),403,'a sibling Agent cannot read the selected Agent conversation');
live_reject(static fn()=>vp3_live_context_snapshot_v1(db(),$user,scope_principal($user,0),170),403,'the System namespace cannot adopt a named Agent conversation');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_id'=>9];
live_reject(static fn()=>vp3_live_context_snapshot_v1(db(),$user,$principal,170),403,'participant attribution never grants owner live-context access');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
db()->exec('UPDATE user_agents SET is_active=0 WHERE id=17');
live_reject(static fn()=>vp3_live_context_snapshot_v1(db(),$user,$principal,170),403,'Agent deactivation is checked against the actual canonical row');
db()->exec('UPDATE user_agents SET is_active=1 WHERE id=17; UPDATE users SET is_active=0 WHERE id=1');
live_reject(static fn()=>vp3_live_context_snapshot_v1(db(),$user,$principal,170),403,'owner deactivation invalidates a previously admitted packet');
db()->exec('UPDATE users SET is_active=1 WHERE id=1');
foreach([
    [['visible'=>false],[]], [['focused'=>false],[]], [['conversation_id'=>180],[]],
    [['activity_tab'=>str_repeat('b',32)],[]], [[],['activity_state'=>'logged_out']],
    [[],['context_key'=>'chat:180']], [[],['surface'=>'stem']],
    [[],['last_heartbeat_at'=>gmdate('Y-m-d H:i:s',(int)floor($now/1000)-91)]],
] as [$details,$row]){live_store($packet,$now,$details,$row);
    live_check(vp3_live_context_snapshot_v1(db(),$user,$principal,170)===null,'hidden, stopped, rebound or expired activity cannot expose old live evidence');}
live_store($packet,$now);db()->exec("UPDATE agent_activity_state SET details_json='{' WHERE user_id=1");
live_reject(static fn()=>vp3_live_context_snapshot_v1(db(),$user,$principal,170),503,'malformed current storage is unavailable rather than old-context fallback');
live_check(vp3_live_context_expire_v1(db(),$user)&&live_row()['details_json']==='{}',
    'malformed current JSON is physically cleared under the same owner-lock/CAS instead of retaining unparseable raw bytes');
live_store($packet,$now);
live_check(!vp3_live_context_expire_v1(db(),$user),'a fresh canonical current packet needs no physical rewrite');
$events=(int)db()->query('SELECT COUNT(*) FROM agent_activity_events')->fetchColumn();
$future=(new DateTimeImmutable('@'.(string)((int)floor($now/1000)+92)))->setTimezone(new DateTimeZone('UTC'));
live_check(vp3_live_context_snapshot_v1(db(),$user,$principal,170,null,$future)===null&&isset(json_decode(live_row()['details_json'],true)['live_context']),
    'an expired readonly snapshot does not mutate storage before the existing worker scrub');
live_check(vp3_live_context_expire_v1(db(),$user,$future)&&!isset(json_decode(live_row()['details_json'],true)['live_context'])
    &&json_decode(live_row()['details_json'],true)['conversation_id']===170,'the owner-locked expiry scrub physically removes only the expired live packet');
live_check(!vp3_live_context_expire_v1(db(),$user,$future)&&(int)db()->query('SELECT COUNT(*) FROM agent_activity_events')->fetchColumn()===$events,
    'repeat expiry has no effect and never copies the raw observation into activity history');
live_store($packet,$now);db()->beginTransaction();
live_check(vp3_live_context_expire_v1(db(),$user,$future)&&db()->inTransaction(),'expiry never commits a caller-owned source transaction');
db()->rollBack();
live_check(isset(json_decode(live_row()['details_json'],true)['live_context']),'rolling back the caller restores its original current packet');
$partlyOld=$packet;$partlyOld['screens'][0]['captured_at']=live_iso($now-90001);live_store($partlyOld,$now);
live_check(vp3_live_context_expire_v1(db(),$user)&&json_decode(live_row()['details_json'],true)['live_context']['screens']===[]
    &&json_decode(live_row()['details_json'],true)['live_context']['received_at_ms']===$packet['received_at_ms'],
    'component expiry removes an old screen while preserving fresh sources and the original server receipt');

$expired=$packet;$expired['received_at_ms']=$now-91000;
live_store($expired,$now);scope_command($user,17,170,'off');db()->exec('UPDATE users SET is_active=0 WHERE id=1');
live_check(vp3_live_context_expire_all_v1(db(),100)===1&&!isset(json_decode(live_row()['details_json'],true)['live_context']),
    'the existing worker can scrub a crashed-client packet with policy off and its owner inactive');
db()->exec('UPDATE users SET is_active=1 WHERE id=1');
db()->prepare("INSERT INTO agent_activity_state(user_id,surface,context_key,activity_state,last_heartbeat_at,details_json) VALUES(99,'chat','chat:999','idle',?,?)")
    ->execute([gmdate('Y-m-d H:i:s'),'{"live_context":{"raw":"ORPHANED_RAW_SENTINEL"}}']);
live_check(vp3_live_context_expire_all_v1(db(),100)===1
    &&!str_contains((string)db()->query('SELECT details_json FROM agent_activity_state WHERE user_id=99')->fetchColumn(),'ORPHANED_RAW_SENTINEL'),
    'a deleted owner cannot leave an orphaned live packet indefinitely in the current cache');
db()->exec('DELETE FROM agent_activity_state WHERE user_id=99');
live_check(vp3_live_context_expire_all_v1(db(),100)===0&&(int)db()->query('SELECT COUNT(*) FROM agent_activity_events')->fetchColumn()===$events,
    'bounded global cleanup is repeatable and emits no activity history copies');
live_store($expired,$now);
db()->exec("INSERT INTO agent_activity_state(user_id,surface,context_key,activity_state,last_heartbeat_at,details_json)
    VALUES(99,'chat','chat:999','idle','2000-01-01 00:00:00','{\"live_context\":{\"raw\":\"OLD_ORPHAN\"}}')");
live_check(vp3_live_context_expire_all_v1(db(),1)===1&&isset(json_decode(live_row()['details_json'],true)['live_context'])
    &&!str_contains((string)db()->query('SELECT details_json FROM agent_activity_state WHERE user_id=99')->fetchColumn(),'OLD_ORPHAN'),
    'global cleanup respects its row bound and handles the oldest abandoned packet first');
live_check(vp3_live_context_expire_all_v1(db(),1)===1&&!isset(json_decode(live_row()['details_json'],true)['live_context']),
    'the next existing worker pass scrubs the remaining expired packet without a new timer');
db()->exec('DELETE FROM agent_activity_state WHERE user_id=99');

// Actual Initiative scope admission and source read. No domain/authority stub.
live_store($packet,$now);scope_command($user,17,170,'off');scope_command($user,17,170,'on');
vp3_initiative_in_scope_v1($user,17,static function()use($user,$now,$packet):void{
    $binding=vp3_initiative_policy_v1($user,17);$scope=vp3_initiative_tools_scope_v1(db(),$user,$binding);$coverage=[];
    $evidence=vp3_initiative_live_capabilities_evidence_v1(db(),$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),null,$coverage);
    $ref='live-context:'.$packet['session_id'];
    live_check(($coverage['live_context']??null)==='ready'&&isset($evidence[$ref])
        &&str_contains(json_encode($evidence,JSON_THROW_ON_ERROR),'LIVE_SCENE_RECORD_SENTINEL'),
        'the same current actual scene reaches canonical Initiative evidence and the planner/check-in input');
    $job=['tool'=>'live.context.read','primary'=>$ref,'refs'=>[$ref],'focus'=>'Prepare a useful question grounded in the shared situation.','report'=>true];
    $before=(int)db()->query('SELECT total_changes()')->fetchColumn();$models=count($GLOBALS['scopeInference']);
    $read=vp3_initiative_live_capabilities_read_v1(db(),$scope,$binding,'live.context.read',$job,$evidence);$encoded=json_encode($read,JSON_THROW_ON_ERROR);
    live_check(str_contains($encoded,'LIVE_SCENE_RECORD_SENTINEL')&&str_contains($encoded,'LIVE_TAB_AUDIO_SENTINEL')
        &&str_contains($encoded,'provider_label')&&str_contains($encoded,'local_unclassified_microphone_energy'),
        'actual shared scene, tab transcript/provider cues, provisional Eyes and coarse microphone records reach the private reader');
    live_check(count($read['records'])===5&&$read['coverage']['state']==='limited'&&$read['coverage']['not_complete_environment']===true,
        'current observations disclose bounded partial coverage instead of claiming complete environmental awareness');
    live_check((int)db()->query('SELECT total_changes()')->fetchColumn()===$before&&count($GLOBALS['scopeInference'])===$models,
        'actual current-source admission/read performs no model, media upload, personal Knowledge access or mutation');
    $recordJson=json_encode($read['records'],JSON_THROW_ON_ERROR);
    foreach(['captured_at','observed_at','activity_tab','owner_user_id','conversation_id','session_id','capture_id'] as $field){
        live_check(!str_contains($recordJson,'"'.$field.'"'),'actual model records exclude operational identity/clock '.$field);
    }
    $changed=$packet;$changed['screens'][0]['summary'].=' A later correction.';live_store($changed,$now);
    live_reject(static fn()=>vp3_initiative_live_capabilities_read_v1(db(),$scope,$binding,'live.context.read',$job,$evidence),409,
        'a concurrent current-scene correction invalidates an old read proof');
    live_store($packet,$now,['visible'=>false]);
    live_reject(static fn()=>vp3_initiative_live_capabilities_read_v1(db(),$scope,$binding,'live.context.read',$job,$evidence),409,
        'hiding or stopping the current source invalidates the old worker proof');
    live_store($packet,$now);$coverage=[];
    live_check(vp3_initiative_live_capabilities_evidence_v1(db(),$scope,$binding,new DateTimeImmutable('@'.((int)floor($now/1000)+92)),[$ref],$coverage)===[]
        &&$coverage['live_context']==='ready','known live expiry retires a selected source instead of an unavailable retry loop');
    $bad=$job;$bad['refs']=[[]];
    live_reject(static fn()=>vp3_initiative_live_capabilities_read_v1(db(),$scope,$binding,'live.context.read',$bad,$evidence),0,
        'malformed source arrays cannot become a read assignment or emit PHP warnings');
    $huge=live_raw($now);$huge['screens']=[];
    for($i=1;$i<=3;$i++){
        $screen=live_raw($now)['screens'][0];$screen['source_id']='share-'.$i;
        foreach(['summary','visible_text','on_screen_translation'] as $field)$screen[$field]=str_repeat('🙂',5000);
        $screen['observations']=array_fill(0,8,['kind'=>'other','label'=>str_repeat('🙂',300),'detail'=>str_repeat('🙂',1000)]);
        $screen['tab_audio']['text']=str_repeat('🙂',5000);$huge['screens'][]=$screen;
    }
    $bounded=vp3_live_context_sanitize_v1($huge,live_binding(),$now);live_store($bounded,$now);$coverage=[];
    $fresh=vp3_initiative_live_capabilities_evidence_v1(db(),$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),null,$coverage);
    $read=vp3_initiative_live_capabilities_read_v1(db(),$scope,$binding,'live.context.read',$job,$fresh);
    live_check(count($read['records'])<=20&&strlen(json_encode($read,JSON_THROW_ON_ERROR))<=65536
        &&!array_filter($read['records'],static fn(array $row):bool=>mb_strlen($row['text'])>8000),
        'three maximum Unicode sources stay within canonical per-record and 64-KiB packet bounds');
    foreach($huge['screens'] as &$screen){
        foreach(['summary','visible_text','site_hint','page_title','on_screen_translation'] as $field)$screen[$field]=str_repeat('"\\',5000);
        $screen['observations']=array_fill(0,8,['kind'=>'other','label'=>str_repeat('"\\',500),'detail'=>str_repeat('"\\',1000)]);
        $screen['tab_audio']['text']=str_repeat('"\\',5000);
        $screen['tab_audio']['audio_events']=array_fill(0,8,['label'=>str_repeat('"\\',80),'start_seconds'=>1,'end_seconds'=>2,
            'provider'=>'elevenlabs','model'=>'scribe_v2','source'=>'shared_tab_audio','classification'=>'provider_label']);
    }unset($screen);
    $bounded=vp3_live_context_sanitize_v1($huge,live_binding(),$now);live_store($bounded,$now);$coverage=[];
    $fresh=vp3_initiative_live_capabilities_evidence_v1(db(),$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),null,$coverage);
    $read=vp3_initiative_live_capabilities_read_v1(db(),$scope,$binding,'live.context.read',$job,$fresh);
    live_check(strlen(json_encode($bounded,JSON_THROW_ON_ERROR))<=65536&&strlen(json_encode($read,JSON_THROW_ON_ERROR))<=65536
        &&count(array_filter($read['records'],static fn(array $row):bool=>!$row['data']['text_complete']))>0,
        'maximum quoted/escaped content and provider cues stay bounded with honest partial-text coverage');
});
live_store($packet,$now);

// The foreground observation read remains available independently of inference
// transport readiness. Only background live inference admission is narrowed.
db()->exec("CREATE TABLE agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference TEXT);
    CREATE TABLE agent_compute_overrides(user_id INTEGER,agent_id INTEGER,preference TEXT);
    INSERT INTO agent_compute_preferences VALUES(1,'vp3_cloud');
    CREATE TABLE homeserver_connections(broken TEXT);");
vp3_initiative_in_scope_v1($user,17,static function()use($user,$packet):void{
    $binding=vp3_initiative_policy_v1($user,17);$scope=vp3_initiative_tools_scope_v1(db(),$user,$binding);
    $read=static function()use($scope,$binding):array{$coverage=[];
        $rows=vp3_initiative_live_capabilities_evidence_v1(db(),$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),null,$coverage);
        return [$rows,$coverage];};
    [$rows,$coverage]=$read();live_check(count($rows)===1&&$coverage['live_context']==='ready',
        'explicit Cloud bypasses corrupt or missing irrelevant HomeServer metadata and migration state');
    db()->exec("UPDATE agent_compute_preferences SET preference='auto' WHERE user_id=1");
    [$rows,$coverage]=$read();live_check($rows===[]&&$coverage['live_context']==='unavailable',
        'unknown connection schema errors exclude only live inference with unavailable coverage');
    live_fixture_close_cursors();db()->exec('DROP TABLE homeserver_connections');
    [$rows,$coverage]=$read();live_check(count($rows)===1&&$coverage['live_context']==='ready',
        'a known absent pairing store keeps the permitted Auto Cloud path usable without transient storage');
    db()->exec("CREATE TABLE homeserver_connections(user_id INTEGER PRIMARY KEY,status TEXT,capabilities_json TEXT,homeserver_token_enc TEXT,relay_token_enc TEXT);
        INSERT INTO homeserver_connections VALUES(1,'unpaired','invalid legacy cache','','')");
    [$rows,$coverage]=$read();live_check(count($rows)===1,'an explicitly unpaired old connection cannot disable otherwise valid Auto live sources');
    db()->exec("UPDATE homeserver_connections SET status='paired',homeserver_token_enc='fixture-encrypted-home',relay_token_enc='fixture-encrypted-relay',capabilities_json='{\"features\":[\"memory.read\"]}' WHERE user_id=1");
    [$rows,$coverage]=$read();live_check(count($rows)===1,
        'the canonical cached registry positively excluding Agent inference retains its ordinary Auto Cloud behavior');
    db()->exec("UPDATE homeserver_connections SET capabilities_json='{\"features\":[\"agent.chat\"]}' WHERE user_id=1;
        CREATE TABLE homeserver_https_sessions(user_id INTEGER PRIMARY KEY,status TEXT,device_id TEXT,session_token_hash TEXT);
        INSERT INTO homeserver_https_sessions VALUES(1,'active','fixture-device','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        CREATE TABLE homeserver_https_requests(id INTEGER PRIMARY KEY);");
    [$rows,$coverage]=$read();live_check($rows===[]&&$coverage['live_context']==='unavailable',
        'an eligible paired HTTPS route without transient retention excludes live content before planning');
    live_check(vp3_live_context_snapshot_v1(db(),$user,$scope['principal'],170)!==null,
        'the same explicit current foreground context remains readable when background transport retention is unsupported');
    $allCoverage=[];$ordinary=vp3_initiative_evidence_v1(db(),$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$allCoverage,null,17);
    live_check(($ordinary['task:1002']['capability_tool']??null)==='tasks.read'
        &&!isset($ordinary['live-context:'.$packet['session_id']])&&$allCoverage['live_context']==='unavailable',
        'unsupported transient retention leaves ordinary task evidence intact while omitting only the live source');
    db()->exec('ALTER TABLE homeserver_https_requests ADD COLUMN transient_context INTEGER NOT NULL DEFAULT 0');
    $before=(int)db()->query('SELECT total_changes()')->fetchColumn();db()->beginTransaction();
    [$rows,$coverage]=$read();live_check(count($rows)===1&&$coverage['live_context']==='ready'
        &&(int)db()->query('SELECT total_changes()')->fetchColumn()===$before&&db()->inTransaction(),
        'active HTTPS transient readiness is checked using bounded SQL only even inside the final source transaction');
    db()->rollBack();
    $autoEvidence=$rows;$ref=array_key_first($autoEvidence);
    db()->exec("UPDATE agent_compute_preferences SET preference='vp3_cloud' WHERE user_id=1");
    [$cloudEvidence,$coverage]=$read();
    live_check(count($cloudEvidence)===1&&$cloudEvidence[$ref]['revision']!==$autoEvidence[$ref]['revision'],
        'admitted Cloud and Auto policies produce distinct exact processing source proofs');
    $job=['tool'=>'live.context.read','primary'=>$ref,'refs'=>[$ref],'focus'=>'Discuss the current supplied context.','report'=>true];
    db()->exec("UPDATE agent_compute_preferences SET preference='homeserver_only' WHERE user_id=1");
    [$localEvidence,$coverage]=$read();
    live_check(count($localEvidence)===1&&$localEvidence[$ref]['revision']!==$cloudEvidence[$ref]['revision'],
        'a healthy admitted local-only route still invalidates the prior Cloud processing proof');
    live_reject(static fn()=>vp3_initiative_live_capabilities_read_v1(db(),$scope,$binding,'live.context.read',$job,$cloudEvidence),409,
        'an old Cloud source proof cannot read under a newly selected healthy local-only policy');
    db()->exec("UPDATE agent_compute_preferences SET preference='vp3_cloud' WHERE user_id=1;
        INSERT INTO agent_compute_overrides VALUES(1,17,'homeserver_only'),(1,18,'vp3_cloud');
        UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=1;");
    [$rows,$coverage]=$read();live_check($rows===[]&&$coverage['live_context']==='unavailable',
        'the selected Agent override remains authoritative instead of inheriting another Agent or the System Cloud preference');
    db()->exec("UPDATE agent_compute_overrides SET preference='vp3_cloud' WHERE agent_id=17;
        DROP TABLE homeserver_connections; CREATE TABLE homeserver_connections(broken TEXT);");
    [$rows,$coverage]=$read();live_check(count($rows)===1,'an explicit selected-Agent Cloud override bypasses irrelevant broken local metadata');
});
db()->exec('DROP TABLE agent_compute_preferences; DROP TABLE agent_compute_overrides; DROP TABLE homeserver_connections; DROP TABLE homeserver_https_sessions; DROP TABLE homeserver_https_requests');
echo 'AGENT_LIVE_CONTEXT_V1=PASS ('.$liveChecks." cases; canonical scoped SQLite reads, freshness and expiry)\n";
