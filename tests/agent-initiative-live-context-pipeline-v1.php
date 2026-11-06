<?php
declare(strict_types=1);

require __DIR__.'/agent-live-context-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/agent-top-of-mind-v1.php';
// The older scope foundation never reads history bodies. Add the production
// column so this pipeline can inspect any actual history writes as well.
if(!column_exists('agent_activity_events','details_json'))db()->exec('ALTER TABLE agent_activity_events ADD COLUMN details_json TEXT');

$livePipelineChecks=0;
function live_pipeline_check(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException($label);
    ++$GLOBALS['livePipelineChecks'];echo 'PASS '.$label.PHP_EOL;
}
function live_pipeline_stored(int $run,bool $mayBeEmpty=false):array
{
    $q=db()->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$run]);$action=(string)$q->fetchColumn();
    $q=db()->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=? ORDER BY id DESC LIMIT 1');$q->execute([$run]);$receipt=(string)$q->fetchColumn();
    live_pipeline_check($action===$receipt&&($mayBeEmpty||$action!==''),'actual live action and execution receipt retain identical verified results');
    return $action===''?[]:json_decode($action,true,32,JSON_THROW_ON_ERROR);
}
function live_pipeline_store(array $packet,int $cid,bool $canReport=true):void
{
    live_store($packet,(int)floor(microtime(true)*1000),['conversation_id'=>$cid,'visible'=>true,'focused'=>true,
        'chat_busy'=>!$canReport,'chat_focused'=>true],['context_key'=>'chat:'.$cid]);
}
function live_pipeline_packet(int $agent,int $cid):array
{
    $now=(int)floor(microtime(true)*1000);$raw=live_raw($now);$raw['agent_id']=$agent;$raw['conversation_id']=$cid;
    $binding=live_binding();$binding['agent_id']=$agent;$binding['conversation_id']=$cid;
    return vp3_live_context_sanitize_v1($raw,$binding,$now)??throw new RuntimeException('Live pipeline setup did not yield a packet.');
}
function live_pipeline_feed(array $user,int $agent,int $cid,int $run,string $state):void
{
    $before=count($GLOBALS['scopeInference']);$feed=vp3_top_of_mind_v1(db(),$user,$agent,$cid);
    $matches=array_values(array_filter($feed['items'],static fn(array $item):bool=>$item['run_id']===$run));
    live_pipeline_check(count($matches)===1&&$matches[0]['state']===$state&&count($GLOBALS['scopeInference'])===$before,
        'the same Brain feed exposes actual live work '.$state.' without another inference');
}
function live_pipeline_prepare(array $user,int $agent,int $cid,bool $canReport=true,?callable $during=null,
    string $tool='live.context.read',bool $anonymous=false):array
{
    static $serial=0;++$serial;
    scope_command($user,$agent,$cid,'off');scope_command($user,$agent,$cid,'on');
    db()->prepare("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=?")->execute([$cid]);
    $packet=live_pipeline_packet($agent,$cid);live_pipeline_store($packet,$cid,$canReport);
    $ref='live-context:'.$packet['session_id'];$before=count(scope_runs(1,$agent,'initiative.prepare'));
    $hadHeader=array_key_exists('HTTP_X_FIXTURE_ANONYMOUS',$_SERVER);$oldHeader=$_SERVER['HTTP_X_FIXTURE_ANONYMOUS']??null;
    if($anonymous)$_SERVER['HTTP_X_FIXTURE_ANONYMOUS']='1';
    try{
        if($anonymous)live_pipeline_check(current_user()===null,'the CLI-style live worker begins without a signed-in browser user');
        $GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($ref,$tool,$anonymous):string{
            $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
            live_pipeline_check($protocol==='vp3.initiative-plan.v1'&&!db()->inTransaction()
                &&str_contains(json_encode($input['evidence'],JSON_THROW_ON_ERROR),'LIVE_SCENE_RECORD_SENTINEL')
                &&str_contains(json_encode($input['evidence'],JSON_THROW_ON_ERROR),'LIVE_TAB_AUDIO_SENTINEL'),
                'the actual planner receives current scene and tab transcript content outside a transaction');
            live_pipeline_check(!empty($input['tools'][$tool]['ready'])
                &&($tool==='chat.check_in'||in_array($ref,$input['tools'][$tool]['source_refs'],true)),
                'the actual planner can choose the declared current live leaf or conversational check-in');
            if($anonymous)live_pipeline_check(current_user()===null,'live planning uses explicit canonical scope without browser impersonation');
            return json_encode(['jobs'=>[['tool'=>$tool,'primary'=>$ref,'refs'=>[$ref],
                'focus'=>'Prepare one useful source-grounded next step or question.','report'=>true]]],JSON_THROW_ON_ERROR);
        };
        $plan=scope_make_plan($user,$agent,'Actual live situation '.$serial);$planned=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$plan));
        live_pipeline_check(!empty($planned['ok'])&&count(scope_runs(1,$agent,'initiative.prepare'))===$before+1,
            'one live model decision queues one preparation in the existing canonical workflow');
        $run=array_slice(scope_runs(1,$agent,'initiative.prepare'),-1)[0];$id=(int)$run['id'];$goal=json_decode($run['goal'],true,32,JSON_THROW_ON_ERROR);
        live_pipeline_check($goal['tool']===$tool&&$goal['primary']===$ref&&($goal['capability_sources'][$ref]??null)==='live.context.read'
            &&!str_contains($run['goal'],'LIVE_SCENE_RECORD_SENTINEL')&&!str_contains($run['goal'],'LIVE_TAB_AUDIO_SENTINEL'),
            'canonical live jobs persist exact scoped source hashes and contain no raw live evidence');
        live_pipeline_feed($user,$agent,$cid,$id,'queued');
        $draft=$tool==='chat.check_in'?'The current shared material leaves the release date open. Which constraint should we clarify first?'
            :mb_substr(str_repeat('A private interpretation grounded in the current explicitly shared situation. ',150),0,7999).'.';
        $calls=0;
        $GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($tool,$draft,$during,$anonymous,&$calls):string{
            ++$calls;$input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
            $records=$tool==='chat.check_in'?$input['evidence']:$input['tool_result']['records'];
            live_pipeline_check($protocol==='vp3.initiative-draft.v1'&&!db()->inTransaction()
                &&str_contains(json_encode($records,JSON_THROW_ON_ERROR),'LIVE_SCENE_RECORD_SENTINEL')
                &&str_contains(json_encode($records,JSON_THROW_ON_ERROR),'LIVE_TAB_AUDIO_SENTINEL'),
                'actual current-scene and tab-transcript records reach the existing private worker/check-in model');
            if($anonymous)live_pipeline_check(current_user()===null,'live preparation keeps the sessionless worker unauthenticated as a browser');
            if($during)$during();
            return json_encode(['draft'=>$draft],JSON_THROW_ON_ERROR);
        };
        $messages=count(scope_messages());$claim=scope_claim($user,$id);live_pipeline_feed($user,$agent,$cid,$id,'working');
        $done=vp3_initiative_execute_v1(db(),$user,$claim);$result=live_pipeline_stored($id,$during!==null);
        if($during){
            live_pipeline_check(empty($done['ok'])&&count(scope_messages())===$messages&&$calls===1,
                'a live source change during generation prevents both commit and delivery without repeating the model');
        }else{
            live_pipeline_check(!empty($done['ok'])&&$calls===1&&$result['initiative_draft']===$draft,
                'the leased canonical live worker retains the full verified derived report');
            if($tool==='live.context.read')live_pipeline_check(($result['initiative_tool_result']['output']['type']??null)==='initiative_capability_read'
                &&$result['initiative_tool_result']['output']['tool']===$tool&&$result['initiative_tool_result']['output']['primary']===$ref,
                'actual live action and receipt carry the exact readonly leaf/source proof');
            live_pipeline_check(!str_contains(json_encode($result,JSON_THROW_ON_ERROR),'LIVE_SCENE_RECORD_SENTINEL')
                &&!str_contains(json_encode($result,JSON_THROW_ON_ERROR),'LIVE_TAB_AUDIO_SENTINEL'),
                'saved workflow results retain derived interpretation and hashes without the raw live packet');
            live_pipeline_check($result['initiative_delivery']===($canReport?'delivered':'pending')
                &&count(scope_messages())===$messages+($canReport?1:0),'live output respects the actual Chat attention guard while keeping source authority current');
            if($canReport){
                $q=db()->prepare('SELECT message,context_json FROM chat_messages WHERE id=? AND conversation_id=?');
                $q->execute([$result['initiative_message_id'],$cid]);$message=$q->fetch(PDO::FETCH_ASSOC);
                live_pipeline_check($message&&$message['message']===$draft&&!str_contains($message['context_json'],'LIVE_SCENE_RECORD_SENTINEL'),
                    'one verified derived live result reaches the exact existing Chat with no raw packet in post metadata');
                live_pipeline_feed($user,$agent,$cid,$id,'completed');
            }
        }
        $beforeInference=count($GLOBALS['scopeInference']);$beforeMessages=count(scope_messages());vp3_initiative_execute_v1(db(),$user,$claim);
        live_pipeline_check(count($GLOBALS['scopeInference'])===$beforeInference&&count(scope_messages())===$beforeMessages,
            'replaying the consumed live claim cannot regenerate or repost work');
        if($anonymous)live_pipeline_check(current_user()===null,'the complete sessionless live workflow leaves current_user null');
        return ['id'=>$id,'plan_id'=>$plan,'goal'=>$goal,'packet'=>$packet,'result'=>$result,'draft'=>$draft];
    }finally{if($hadHeader)$_SERVER['HTTP_X_FIXTURE_ANONYMOUS']=$oldHeader;else unset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS']);}
}

$user=['id'=>1,'is_active'=>1];
$first=live_pipeline_prepare($user,17,170);
$root=vp3_initiative_followthrough_load_v1(db(),$user,scope_principal($user,17),170,(int)$first['result']['initiative_message_id']);
$ownerQuery='Develop this private next step from the current scene. '.str_repeat('Keep the shared material advisory. ',12).' OWNER_LIVE_CONTINUATION_TAIL.';
db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user',?)")->execute([$ownerQuery]);$reply=(int)db()->lastInsertId();
$replyRef=['message_id'=>(int)$root['message']['id'],'reply_ref'=>$root['state']['reply_ref'],'revision'=>$root['state']['revision']];
$advance=vp3_initiative_followthrough_reply_v1(db(),$user,$root['principal'],170,$reply,$replyRef,$ownerQuery,null,
    static fn(string $prompt,string $protocol):string=>json_encode(['intent'=>'advance','focus'=>'Develop the private next step from the current shared scene.'],JSON_THROW_ON_ERROR));
$outcome=$advance['initiative_followthrough'];$child=(int)$outcome['continuation_run_id'];
$goal=json_decode(agent_workflow_row_v1400(db(),1,$child)['goal'],true,32,JSON_THROW_ON_ERROR);
live_pipeline_check($goal['tool']==='live.context.read'&&$goal['sources']===$first['goal']['sources']
    &&$goal['capability_sources']===$first['goal']['capability_sources'],'owner advancement preserves the same canonical live read leaf and exact current source binding');
db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','I will develop the private next step.',?)")
    ->execute([json_encode(['initiative_followthrough'=>$outcome],JSON_THROW_ON_ERROR)]);
db()->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=170");
$GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($ownerQuery):string{
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    live_pipeline_check(($input['tool_result']['tool']??null)==='live.context.read'
        &&str_contains(json_encode($input['tool_result']['records'],JSON_THROW_ON_ERROR),'LIVE_SCENE_RECORD_SENTINEL')
        &&($input['owner_continuation']['owner_reply']??null)===$ownerQuery
        &&str_contains($input['owner_continuation']['owner_reply'],'OWNER_LIVE_CONTINUATION_TAIL'),
        'the actual same-leaf continuation re-reads current scene records and the complete saved owner request');
    return json_encode(['draft'=>'The shared material leaves the launch date unresolved. A useful private next step is to list the remaining constraints before proposing any external action.'],JSON_THROW_ON_ERROR);
};
$continued=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$child));$childResult=live_pipeline_stored($child);
live_pipeline_check(!empty($continued['ok'])&&$childResult['initiative_delivery']==='delivered'
    &&vp3_initiative_followthrough_load_v1(db(),$user,$root['principal'],170,(int)$root['message']['id'])['state']['state']==='completed',
    'the live continuation finishes through the existing receipt and updates the original Chat proposal');

// Check-in is the same canonical workflow and source fence, not another loop.
live_pipeline_prepare($user,17,170,true,null,'chat.check_in');

foreach(['semantic edit','explicit stop','expired receipt'] as $case){
    live_pipeline_prepare($user,17,170,true,static function()use($case):void{
        $row=live_row();$details=json_decode($row['details_json'],true,32,JSON_THROW_ON_ERROR);$packet=$details['live_context'];
        if($case==='semantic edit')$packet['screens'][0]['summary'].=' A current source correction.';
        elseif($case==='explicit stop'){$details['live_context']=null;db()->prepare('UPDATE agent_activity_state SET details_json=? WHERE user_id=1')->execute([json_encode($details,JSON_THROW_ON_ERROR)]);return;}
        else $packet['received_at_ms']=(int)floor(microtime(true)*1000)-91000;
        live_pipeline_store($packet,170);
    });
    live_pipeline_check(true,'the actual post-model live source fence covers '.$case);
}

$pending=live_pipeline_prepare($user,17,170,false);$before=live_pipeline_stored($pending['id']);
$messages=count(scope_messages());$models=count($GLOBALS['scopeInference']);$storedDetails=live_row()['details_json'];
db()->exec("UPDATE agent_activity_state SET details_json='{' WHERE user_id=1");
live_reject(static fn()=>vp3_initiative_current_sources_v1(db(),$user,$pending['goal']['sources'],false,
    $pending['goal']['canonical_sources'],17,$pending['goal']['capability_sources']),503,
    'actual unknown live storage failure is unavailable rather than a known source deletion');
live_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&live_pipeline_stored($pending['id'])===$before
    &&count(scope_messages())===$messages&&count($GLOBALS['scopeInference'])===$models,
    'a503 current-live failure preserves pending body/receipt bytes without Chat output or model replay');
db()->prepare('UPDATE agent_activity_state SET details_json=? WHERE user_id=1')->execute([$storedDetails]);
live_pipeline_store($pending['packet'],170,true);
live_pipeline_check(vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages+1
    &&count($GLOBALS['scopeInference'])===$models,'the same verified live result delivers after current-source availability recovers without regeneration');

foreach(['semantic edit','explicit stop','expired receipt','new session'] as $case){
    $pending=live_pipeline_prepare($user,17,170,false);$messages=count(scope_messages());$models=count($GLOBALS['scopeInference']);
    $packet=$pending['packet'];
    if($case==='semantic edit')$packet['screens'][0]['visible_text'].=' A later semantic correction.';
    elseif($case==='expired receipt')$packet['received_at_ms']=(int)floor(microtime(true)*1000)-91000;
    elseif($case==='new session')$packet['session_id']='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    live_pipeline_store($packet,170,true);
    if($case==='explicit stop'){ $details=json_decode(live_row()['details_json'],true);unset($details['live_context']);
        db()->prepare('UPDATE agent_activity_state SET details_json=? WHERE user_id=1')->execute([json_encode($details,JSON_THROW_ON_ERROR)]); }
    live_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages
        &&count($GLOBALS['scopeInference'])===$models&&live_pipeline_stored($pending['id'])['initiative_delivery']==='withheld',
        'late '.$case.' retires the completed live output with409 semantics and no stale post');
}

// Renewal is a current-source proof refresh, not a change in the work itself.
$pending=live_pipeline_prepare($user,17,170,false);$packet=$pending['packet'];$oldRevision=vp3_live_context_revision_v1($packet);
$fresh=(int)floor(microtime(true)*1000);$packet['observed_at']=live_iso($fresh);$packet['received_at_ms']=$fresh;
$packet['screens'][0]['captured_at']=live_iso($fresh);$packet['screens'][0]['tab_audio']['captured_at']=live_iso($fresh);
$packet['eyes']['observed_at']=live_iso($fresh);$packet['microphone']['observed_at']=live_iso($fresh);
live_pipeline_store($packet,170,true);$models=count($GLOBALS['scopeInference']);
live_pipeline_check(vp3_live_context_revision_v1($packet)===$oldRevision&&vp3_initiative_deliver_pending_v1(db(),$user,17)
    &&count($GLOBALS['scopeInference'])===$models,'semantic-only renewal keeps the same pending result current and delivers once without new work');

// The actual scheduler reconsiders changed live context after30s, retaining the
// normal5m cadence for unchanged content and stripping duplicate task aliases.
vp3_initiative_in_scope_v1($user,17,static function()use($user,$packet):void{
    $plans=count(scope_runs(1,17,'initiative.plan'));vp3_initiative_schedule_v1(db(),$user,17);
    live_pipeline_check(count(scope_runs(1,17,'initiative.plan'))===$plans,'renewed live timestamps do not wake the source review');
    $changed=$packet;$changed['screens'][0]['summary'].=' A newly relevant fact.';live_pipeline_store($changed,170,true);
    vp3_initiative_schedule_v1(db(),$user,17);
    live_pipeline_check(count(scope_runs(1,17,'initiative.plan'))===$plans,'a semantic live update respects the bounded30s review cooldown');
    db()->exec("UPDATE agent_memory_items SET last_seen_at=datetime('now','-31 seconds') WHERE user_id=1 AND user_agent_id=17 AND memory_type='initiative_sweep'");
    vp3_initiative_schedule_v1(db(),$user,17);
    live_pipeline_check(count(scope_runs(1,17,'initiative.plan'))===$plans+1,'a meaningful live revision wakes the existing planner after30s without waiting five minutes');
    $coverage=[];$evidence=vp3_initiative_evidence_v1(db(),$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,17);
    live_pipeline_check(($evidence['task:1002']['capability_tool']??null)==='tasks.read'&&!isset($evidence['open-task:1002']),
        'canonical task evidence suppresses its discovery alias while preserving the actual task read leaf');
});

live_pipeline_prepare($user,17,170,true,null,'live.context.read',true);
live_pipeline_prepare($user,0,142,true,null,'live.context.read',true);

// Unsupported ephemeral transport must narrow only the live family. The
// existing configured model transport fixture remains unchanged for the actual
// ordinary task planner/worker; this gate cannot choose or reroute that model.
scope_command($user,17,170,'off');scope_command($user,17,170,'on');
db()->exec("CREATE TABLE agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference TEXT);
    INSERT INTO agent_compute_preferences VALUES(1,'auto');
    CREATE TABLE homeserver_connections(user_id INTEGER PRIMARY KEY,status TEXT,capabilities_json TEXT,homeserver_token_enc TEXT,relay_token_enc TEXT);
    INSERT INTO homeserver_connections VALUES(1,'paired','{\"features\":[\"agent.chat\"]}','fixture-encrypted-home','fixture-encrypted-relay');
    CREATE TABLE homeserver_https_sessions(user_id INTEGER PRIMARY KEY,status TEXT,device_id TEXT,session_token_hash TEXT);
    INSERT INTO homeserver_https_sessions VALUES(1,'active','fixture-device','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    CREATE TABLE homeserver_https_requests(id INTEGER PRIMARY KEY);
    UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=170;");
$ordinaryPacket=live_pipeline_packet(17,170);live_pipeline_store($ordinaryPacket,170,true);
$originalTask=(string)db()->query('SELECT memory_text FROM agent_memory_items WHERE id=1002')->fetchColumn();
db()->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=1002')
    ->execute([str_repeat('An ordinary saved task detail. ',20).'ORDINARY_TASK_ROUTE_SENTINEL. Verify the existing supplier response.']);
$before=count(scope_runs(1,17,'initiative.prepare'));
$GLOBALS['scopeModel']=static function(string $prompt,string $protocol):string{
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);$evidence=array_column($input['evidence'],null,'ref');
    live_pipeline_check($protocol==='vp3.initiative-plan.v1'&&isset($evidence['task:1002'])
        &&!array_filter($evidence,static fn(array $row):bool=>($row['kind']??null)==='live_context')
        &&!isset($input['tools']['live.context.read'])&&!str_contains($prompt,'LIVE_SCENE_RECORD_SENTINEL'),
        'an actual mixed-source assessment omits unsupported live evidence/tool while retaining the ordinary task');
    return json_encode(['jobs'=>[['tool'=>'tasks.read','primary'=>'task:1002','refs'=>['task:1002'],
        'focus'=>'Prepare the ordinary private task follow-up without current sensory context.','report'=>true]]],JSON_THROW_ON_ERROR);
};
$ordinaryPlan=scope_make_plan($user,17,'Ordinary work with unsupported live retention');
$ordinaryPlanned=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$ordinaryPlan));
live_pipeline_check(!empty($ordinaryPlanned['ok'])&&count(scope_runs(1,17,'initiative.prepare'))===$before+1,
    'unsupported live retention does not block the canonical planner from queueing ordinary useful work');
$ordinaryRun=array_slice(scope_runs(1,17,'initiative.prepare'),-1)[0];$ordinaryId=(int)$ordinaryRun['id'];
$GLOBALS['scopeModel']=static function(string $prompt,string $protocol):string{
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    live_pipeline_check(($input['tool_result']['tool']??null)==='tasks.read'
        &&str_contains(json_encode($input['tool_result']['records'],JSON_THROW_ON_ERROR),'ORDINARY_TASK_ROUTE_SENTINEL')
        &&!str_contains($prompt,'LIVE_SCENE_RECORD_SENTINEL')&&!db()->inTransaction(),
        'the unchanged governed model transport receives actual ordinary task records and no unsupported live packet');
    return json_encode(['draft'=>'The saved supplier follow-up remains useful. A private next step is to verify the outstanding response before contacting anyone.'],JSON_THROW_ON_ERROR);
};
$ordinaryDone=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$ordinaryId));$ordinaryResult=live_pipeline_stored($ordinaryId);
live_pipeline_check(!empty($ordinaryDone['ok'])&&$ordinaryResult['initiative_delivery']==='delivered'
    &&$ordinaryResult['initiative_tool_result']['output']['tool']==='tasks.read'
    &&(string)db()->query('SELECT preference FROM agent_compute_preferences WHERE user_id=1')->fetchColumn()==='auto'
    &&(int)db()->query('SELECT COUNT(*) FROM homeserver_https_requests')->fetchColumn()===0,
    'ordinary task work completes through its real receipt and Chat without a policy change, forced Cloud reroute or live relay write');
db()->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=1002')->execute([$originalTask]);
db()->exec('ALTER TABLE homeserver_https_requests ADD COLUMN transient_context INTEGER NOT NULL DEFAULT 0');
live_pipeline_prepare($user,17,170,true,static fn()=>db()->exec("UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=1"));
live_pipeline_check(true,'actual live route/privacy admission is rechecked after inference before committing output');
db()->exec("UPDATE homeserver_https_sessions SET status='active' WHERE user_id=1");
$pending=live_pipeline_prepare($user,17,170,false);$saved=live_pipeline_stored($pending['id']);
$messages=count(scope_messages());$models=count($GLOBALS['scopeInference']);live_fixture_close_cursors();
db()->exec('ALTER TABLE homeserver_https_requests RENAME COLUMN transient_context TO unsupported_transient_context');
live_pipeline_store($pending['packet'],170,true);
live_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&live_pipeline_stored($pending['id'])===$saved
    &&count(scope_messages())===$messages&&count($GLOBALS['scopeInference'])===$models,
    'a missing transient-retention flag at late delivery preserves the exact pending live result without replay or stale Chat output');
live_fixture_close_cursors();db()->exec('ALTER TABLE homeserver_https_requests RENAME COLUMN unsupported_transient_context TO transient_context');
live_pipeline_check(vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages+1
    &&count($GLOBALS['scopeInference'])===$models,'restoring supported transient retention permits the same current saved result to deliver without generation');
// Processing-policy changes between two healthy admitted routes still revoke
// the old source proof, before provider dispatch and at delayed delivery.
db()->exec("UPDATE agent_compute_preferences SET preference='vp3_cloud' WHERE user_id=1");
$policyWork=live_pipeline_prepare($user,17,170);$before=count($GLOBALS['scopeInference']);
$GLOBALS['scopeModel']=static fn(string $prompt,string $protocol):string=>throw new LogicException('The revoked Cloud prompt must never reach a provider.');
live_reject(static fn()=>vp3_initiative_infer_v1(db(),$user,17,json_encode(['private'=>'LIVE_SCENE_RECORD_SENTINEL'],JSON_THROW_ON_ERROR),
    'vp3.initiative-draft.v1',true,static function()use($user,$policyWork):void{
        db()->exec("UPDATE agent_compute_preferences SET preference='homeserver_only' WHERE user_id=1");
        vp3_initiative_current_sources_v1(db(),$user,$policyWork['goal']['sources'],false,
            $policyWork['goal']['canonical_sources'],17,$policyWork['goal']['capability_sources']);
    }),409,'the actual before-inference source callback rejects Cloud-to-healthy-local-only policy revocation');
live_pipeline_check(count($GLOBALS['scopeInference'])===$before,
    'the stale chosen Cloud branch sends no private prompt after its processing policy changes');
db()->exec("UPDATE agent_compute_preferences SET preference='vp3_cloud' WHERE user_id=1");
live_pipeline_prepare($user,17,170,true,static fn()=>db()->exec("UPDATE agent_compute_preferences SET preference='homeserver_only' WHERE user_id=1"));
live_pipeline_check(true,'post-model source validation rejects a processing-policy change even while both transports are healthy');
db()->exec("UPDATE agent_compute_preferences SET preference='vp3_cloud' WHERE user_id=1");
$pending=live_pipeline_prepare($user,17,170,false);$messages=count(scope_messages());$models=count($GLOBALS['scopeInference']);
db()->exec("UPDATE agent_compute_preferences SET preference='homeserver_only' WHERE user_id=1");live_pipeline_store($pending['packet'],170,true);
live_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&live_pipeline_stored($pending['id'])['initiative_delivery']==='withheld'
    &&count(scope_messages())===$messages&&count($GLOBALS['scopeInference'])===$models,
    'a completed Cloud result cannot survive an admitted local-only preference change at late delivery');
live_fixture_close_cursors();db()->exec('DROP TABLE agent_compute_preferences; DROP TABLE homeserver_connections; DROP TABLE homeserver_https_sessions; DROP TABLE homeserver_https_requests');
$retained=db()->query("SELECT goal AS body FROM agent_workflow_runs UNION ALL SELECT result_json FROM agent_workflow_actions UNION ALL SELECT result_json FROM agent_workflow_receipts UNION ALL SELECT details_json FROM agent_activity_events UNION ALL SELECT metadata_json FROM agent_memory_items")->fetchAll(PDO::FETCH_COLUMN);
live_pipeline_check(!str_contains(implode("\n",array_map(static fn($body):string=>(string)$body,$retained)),'LIVE_SCENE_RECORD_SENTINEL')
    &&!str_contains(implode("\n",array_map(static fn($body):string=>(string)$body,$retained)),'LIVE_TAB_AUDIO_SENTINEL'),
    'actual workflow goals, receipts, history and Brain memory contain no raw live scene or tab-transcript packet');
echo 'AGENT_INITIATIVE_LIVE_CONTEXT_PIPELINE_V1=PASS ('.$livePipelineChecks." cases; real planner/worker/receipt/Chat/Brain and late source fences)\n";
