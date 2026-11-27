<?php
declare(strict_types=1);

define('VP3_LIVE_CONTEXT_FIXTURE_ONLY',true);
require __DIR__.'/agent-live-context-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-curiosity-analysis-v1.php';
$curiosityLive=0;
function curiosity_live_check(bool $ok,string $label):void{
    if(!$ok)throw new RuntimeException($label);++$GLOBALS['curiosityLive'];echo 'PASS '.$label.PHP_EOL;
}
$user=$one;$binding=vp3_initiative_policy_v1($user,17);
db()->exec("CREATE TABLE agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference TEXT);
    CREATE TABLE agent_compute_overrides(user_id INTEGER,agent_id INTEGER,preference TEXT);
    INSERT INTO agent_compute_preferences VALUES(1,'vp3_cloud')");
$publish=static function(string $summary='CURRENT_EMAIL_PAGE_ANALYSIS'):array{
    $now=(int)floor(microtime(true)*1000);$raw=live_raw($now);$raw['screens'][0]['summary']=$summary;
    $packet=vp3_live_context_sanitize_v1($raw,live_binding(),$now)??throw new RuntimeException('The current share fixture was rejected.');
    live_store($packet,$now);return $packet;
};
$packet=$publish();
$screen=vp3_curiosity_live_screen_v1(db(),$user,$binding);
curiosity_live_check($screen!==null&&str_contains(json_encode($screen),'CURRENT_EMAIL_PAGE_ANALYSIS'),
    'Curiosity uses the current description from the actual authorized shared-screen adapter');
curiosity_live_check(!str_contains(json_encode($screen),'LIVE_SCENE_RECORD_SENTINEL')
    &&!str_contains(json_encode($screen),'LIVE_TAB_AUDIO_SENTINEL')&&!str_contains(json_encode($screen),'participant_count'),
    'The email-page bridge excludes literal screen text, tab audio, camera presence and microphone activity');
$oldRevision=vp3_curiosity_analysis_snapshot_v1(db(),$user,$binding)['revision'];
$packet['eyes']=null;$packet['microphone']=null;live_store($packet,(int)floor(microtime(true)*1000));
curiosity_live_check(vp3_curiosity_analysis_snapshot_v1(db(),$user,$binding)['revision']===$oldRevision,
    'Unrelated camera or microphone changes cannot manufacture new email-page analysis');
$packet['screens'][0]['session_id']='33333333-3333-4333-8333-333333333333';
live_store($packet,(int)floor(microtime(true)*1000));
curiosity_live_check(vp3_curiosity_analysis_snapshot_v1(db(),$user,$binding)['revision']!==$oldRevision,
    'An identical-looking replacement share has its own source authority');
try{
    vp3_curiosity_live_screen_v1(db(),$user,array_replace($binding,['conversation_id'=>180,'agent_id'=>18]));
    throw new RuntimeException('A sibling Agent unexpectedly received shared-screen access.');
}catch(RuntimeException $error){
    curiosity_live_check($error->getCode()===403,'A sibling Agent and Chat cannot borrow the live screen description');
}
$beforeMessages=count(scope_messages());
curiosity_live_check(vp3_curiosity_analysis_schedule_v1(db(),$user,$binding)==='queued',
    'A fresh shared page participates in the ordinary private analyst queue');
$rows=array_filter(scope_runs(1,17,'initiative.prepare'),static fn(array $row):bool=>(json_decode($row['goal'],true)['tool']??null)==='curiosity.analyze');
$run=(int)end($rows)['id'];$calls=0;
$done=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$run),static function(string $prompt,string $protocol)use(&$calls):string{
    ++$calls;$input=json_decode($prompt,true);
    curiosity_live_check(!db()->inTransaction()&&$protocol==='vp3.initiative-draft.v1'&&$input['role']==='curiosity'
        &&$input['current_shared_screen']['ephemeral']===true,
        'The leased analyst receives a fresh provisional screen input outside database locks');
    curiosity_live_check(str_contains($prompt,'CURRENT_EMAIL_PAGE_ANALYSIS')&&!str_contains($prompt,'LIVE_TAB_AUDIO_SENTINEL'),
        'The actual analysis request is grounded in the shared page while excluding unrelated live inputs');
    return json_encode(['draft'=>'The currently shared page leaves a launch date unresolved. Brainstorming could explore ways to clarify that constraint. This observation is provisional.']);
},null,false);
curiosity_live_check($done['ok']&&$calls===1&&count(scope_messages())===$beforeMessages,
    'A live-page analysis produces one private finding without posting an unsolicited screen transcript');
curiosity_live_check(vp3_curiosity_analysis_report_v1(db(),$user,$binding)!==null,
    'The currently authorized result is available for the creative handoff');
$clear=static function():void{
    $row=live_row();$details=json_decode($row['details_json'],true);unset($details['live_context']);
    db()->prepare('UPDATE agent_activity_state SET details_json=? WHERE user_id=1')->execute([json_encode($details)]);
};
$clear();
curiosity_live_check(vp3_curiosity_live_screen_v1(db(),$user,$binding)===null
    &&vp3_curiosity_analysis_report_v1(db(),$user,$binding)===null,
    'Stopping shared context withdraws its analysis from the next Brainstorming or OB1 read');
$publish('A DIFFERENT_CURRENT_EMAIL_PAGE');
curiosity_live_check(vp3_curiosity_analysis_schedule_v1(db(),$user,$binding)==='queued','A different shared page can receive a fresh analysis');
$rows=array_filter(scope_runs(1,17,'initiative.prepare'),static fn(array $row):bool=>(json_decode($row['goal'],true)['tool']??null)==='curiosity.analyze');
$late=(int)end($rows)['id'];
$failed=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$late),static function()use($clear):string{
    $clear();return json_encode(['draft'=>'A late result from a stopped share must not be retained.']);
},null,false);
curiosity_live_check(!$failed['ok']&&vp3_curiosity_analysis_report_v1(db(),$user,$binding)===null,
    'Stopping sharing during inference fences the late result before its private receipt commits');
$packet=$publish('A THIRD_CURRENT_EMAIL_PAGE');
curiosity_live_check(vp3_curiosity_analysis_schedule_v1(db(),$user,$binding)==='queued',
    'A new shared-page source can receive its own analysis');
$rows=array_filter(scope_runs(1,17,'initiative.prepare'),static fn(array $row):bool=>(json_decode($row['goal'],true)['tool']??null)==='curiosity.analyze');
$replacement=(int)end($rows)['id'];
$failed=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$replacement),static function()use($packet):string{
    $packet['screens'][0]['session_id']='44444444-4444-4444-8444-444444444444';
    live_store($packet,(int)floor(microtime(true)*1000));
    return json_encode(['draft'=>'An identical-looking replacement must not authenticate the previous screen analysis.']);
},null,false);
curiosity_live_check(!$failed['ok']&&vp3_curiosity_analysis_report_v1(db(),$user,$binding)===null,
    'Replacing a share during inference fences the prior result even when the visible description is identical');
$publish();db()->exec("UPDATE agent_compute_preferences SET preference='homeserver_only' WHERE user_id=1");
curiosity_live_check(vp3_curiosity_live_screen_v1(db(),$user,$binding)===null,
    'An unavailable transient local route cannot silently authorize Cloud processing of the shared page');
echo 'CURIOSITY_LIVE_SCREEN=PASS ('.$curiosityLive.' cases)'.PHP_EOL;
