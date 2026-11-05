<?php
declare(strict_types=1);

// Policy storage, canonical activity ordering, owner/Agent resolution, workflow
// leases and post-inference fencing are production code. Only provider transport
// and the SQLite spelling of the existing MySQL activity upsert are adapted.
require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-activity-v94.php';
require_once dirname(__DIR__).'/includes/agent-initiative-lifecycle-v1.php';

class CoreAdoptionPDO extends InitiativeScopePDO
{
    public bool $failPolicyRead=false;

    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        if($this->failPolicyRead&&str_starts_with($sql,'SELECT metadata_json,is_active FROM agent_memory_items')){
            throw new PDOException('Fixture strict preference read failure.',503);
        }
        if(str_contains($sql,'INSERT INTO agent_activity_state')&&str_contains($sql,'ON DUPLICATE KEY UPDATE')){
            [$sql]=explode('ON DUPLICATE KEY UPDATE',$sql,2);
            $sql.="ON CONFLICT(user_id) DO UPDATE SET surface=excluded.surface,context_key=excluded.context_key,
                task_kind=excluded.task_kind,task_title=excluded.task_title,activity_state=excluded.activity_state,
                last_input_at=CASE WHEN excluded.activity_state='working' THEN NOW() ELSE last_input_at END,
                last_task_at=CASE WHEN excluded.activity_state='working' THEN NOW() ELSE last_task_at END,
                last_heartbeat_at=NOW(),idle_since_at=CASE WHEN excluded.activity_state='idle'
                  THEN COALESCE(idle_since_at,NOW()) ELSE NULL END,details_json=excluded.details_json,updated_at=NOW()";
        }
        return parent::prepare($sql,$options);
    }
}

// The parent fixture deliberately retains statement handles. Finalize their
// SQLite read cursors before extending its schema through the new adapter.
foreach(get_defined_vars() as $value)if($value instanceof PDOStatement)$value->closeCursor();
unset($value);
$pdo=new CoreAdoptionPDO('sqlite:'.$scopeFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach(['NOW','UTC_TIMESTAMP'] as $clock)$pdo->sqliteCreateFunction($clock,static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('DATABASE',static fn()=>'main');
$pdo->sqliteCreateFunction('IF',static fn($condition,$yes,$no)=>$condition?$yes:$no);
$pdo->exec("ATTACH DATABASE ':memory:' AS information_schema;
    CREATE TABLE information_schema.statistics AS SELECT 'main' AS table_schema,tbl_name AS table_name,name AS index_name
      FROM main.sqlite_master WHERE type='index';
    ALTER TABLE agent_activity_state ADD COLUMN task_kind TEXT;
    ALTER TABLE agent_activity_state ADD COLUMN task_title TEXT;
    ALTER TABLE agent_activity_state ADD COLUMN last_input_at TEXT;
    ALTER TABLE agent_activity_state ADD COLUMN last_task_at TEXT;
    ALTER TABLE agent_activity_state ADD COLUMN updated_at TEXT;
    DROP TABLE agent_activity_events;
    CREATE TABLE agent_activity_events(id INTEGER PRIMARY KEY,user_id INTEGER,surface TEXT,context_key TEXT,
      task_kind TEXT,task_title TEXT,previous_state TEXT,activity_state TEXT,reason TEXT,details_json TEXT,created_at TEXT);
    INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(144,1,NULL,'Another System Chat');
    DELETE FROM agent_memory_items WHERE memory_type IN ('initiative_policy','initiative_sweep');
    DELETE FROM agent_activity_state;");

$adoptionChecks=0;
function adoption_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    ++$GLOBALS['adoptionChecks'];echo "PASS $label\n";
}
function adoption_observation(int $cid,array $overrides=[]): array
{
    static $sequence=1000;
    ++$sequence;
    return array_replace(['surface'=>'chat','conversation_id'=>$cid,'visible'=>true,'focused'=>true,
        'chat_focused'=>true,'chat_busy'=>false,'activity_tab'=>str_repeat('a',32),
        'activity_observed_at'=>(int)floor(microtime(true)*1000)+$sequence,
        'activity_sequence'=>$sequence,'task_title'=>'Agent Chat','path'=>'/chat.php'],$overrides);
}
function adoption_record(array $user,int $cid,array $overrides=[]): array
{
    $context=adoption_observation($cid,$overrides);
    agent_activity_v94_record($user,(string)$context['surface'],'working',$context,'core_adoption_fixture');
    return $context;
}
function adoption_policy_rows(): array
{
    return db()->query("SELECT user_id,user_agent_id,is_active,metadata_json FROM agent_memory_items
        WHERE memory_type='initiative_policy' ORDER BY user_id,user_agent_id,id")->fetchAll();
}
function adoption_save(array $user,int $agent,array $policy): void
{
    vp3_initiative_in_scope_v1($user,$agent,static function()use($user,$policy):void{
        if(agent_brain_v122_upsert_system_memory($user,'initiative_policy','owner-opt-in','Owner initiative preference.',$policy)<1)
            throw new RuntimeException('Could not save an existing policy fixture.');
    });
}
function adoption_declined(array $user,array $context,string $label): void
{
    $before=adoption_policy_rows();$messages=count(scope_messages());$inferences=count($GLOBALS['scopeInference']);
    $changes=(int)db()->query('SELECT total_changes()')->fetchColumn();
    $result=vp3_initiative_adopt_activity_v1(db(),$user,$context);
    adoption_check($result['accepted']===false&&$result['changed']===false&&adoption_policy_rows()===$before
        &&count(scope_messages())===$messages&&count($GLOBALS['scopeInference'])===$inferences
        &&(int)db()->query('SELECT total_changes()')->fetchColumn()===$changes&&!db()->inTransaction(),$label);
}

$one=['id'=>1,'is_active'=>1];$two=['id'=>2,'is_active'=>1];
$incoming=['user_id'=>2,'user_agent_id'=>19];$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$incoming;
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$calls=count($GLOBALS['scopeInference']);
$pdo->exec('PRAGMA query_only=ON');
$missing=vp3_initiative_worker_health_v1($pdo,$one,17,170);
$pdo->exec('PRAGMA query_only=OFF');
adoption_check($missing['state']==='starting'&&$missing['reason']==='automatic_startup'
    &&$missing['policy_exists']===false&&$missing['paused']===false
    &&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before&&count($GLOBALS['scopeInference'])===$calls,
    'a read-only health request identifies automatic startup without inventing Pause, writing policy or calling a model');

foreach([[$one,7,11],[$two,0,143]] as [$owner,$agent,$cid]){
    adoption_check(vp3_initiative_policy_v1($owner,$agent)===[],"fresh owner {$owner['id']} Agent $agent has no prior policy");
    $pausedBeforeAdoption=scope_command($owner,$agent,$cid,'off');$savedPause=vp3_initiative_policy_v1($owner,$agent);
    $pausedAdoption=vp3_initiative_adopt_activity_v1($pdo,$owner,adoption_record($owner,$cid));
    adoption_check($pausedBeforeAdoption['ok']&&$savedPause['enabled']===false&&$pausedAdoption['accepted']
        &&$pausedAdoption['paused']&&!$pausedAdoption['changed']&&vp3_initiative_policy_v1($owner,$agent)===$savedPause,
        "an explicit Pause before first automatic adoption persists for owner {$owner['id']} Agent $agent");
}

// Internal policy storage is independent of optional Brain UI/read permission.
$GLOBALS['initiativeBrainAccess']=false;
$first=adoption_record($one,170);$locks=$GLOBALS['scopeOwnerLocks'];$messages=count(scope_messages());
$adopted=vp3_initiative_adopt_activity_v1($pdo,$one,$first);
$policy=vp3_initiative_policy_v1($one,17);
adoption_check($adopted['accepted']===true&&$adopted['changed']===true&&$adopted['paused']===false
    &&$adopted['agent_id']===17&&$adopted['conversation_id']===170&&$policy['enabled']===true
    &&$policy['owner_user_id']===1&&$policy['agent_id']===17&&$policy['conversation_id']===170
    &&preg_match('/\A[a-f0-9]{32}\z/D',$policy['revision'])===1
    &&$adopted['policy_revision']===$policy['revision']&&$GLOBALS['scopeOwnerLocks']>$locks,
    'the accepted focused saved named Chat automatically adopts its exact owner and Agent under the owner lock without Brain UI access');
adoption_check($policy['knowledge_scope']===['mode'=>'off','folder_id'=>0]&&$policy['screen_enabled']===false
    &&count($GLOBALS['scopeInference'])===$calls&&count(scope_messages())===$messages&&!$pdo->inTransaction(),
    'automatic adoption starts with Knowledge and screen access off and performs no inference or unsolicited Chat delivery');
$same=vp3_initiative_adopt_activity_v1($pdo,$one,$first);
$reload=vp3_initiative_adopt_activity_v1($pdo,$one,adoption_record($one,170));
adoption_check($same['accepted']&&$reload['accepted']&&!$same['changed']&&!$reload['changed']
    &&vp3_initiative_policy_v1($one,17)===$policy,
    'the exact accepted heartbeat and a new same-Chat heartbeat leave the established policy and revision unchanged');
$runtime=vp3_initiative_runtime_context_v1($pdo,$one,17,170);
adoption_check($runtime['policy']===$policy&&vp3_initiative_worker_health_v1($pdo,$one,17,170)['configuration_ready']===true,
    'core runtime and control remain available when optional Brain UI permission is absent');
vp3_initiative_in_scope_v1($one,17,static function()use($pdo,$one,$policy):void{
    $scope=vp3_initiative_tools_scope_v1($pdo,$one,$policy);$denied=false;
    try{vp3_initiative_tools_records_v1($pdo,$scope,$policy,'brain.read','Only Agent A task');}
    catch(RuntimeException $e){$denied=$e->getCode()===403;}
    adoption_check($denied,'automatic core adoption preserves the separate Brain-read permission boundary');
});
$coverage=[];$withoutBrain=vp3_initiative_evidence_v1($pdo,$one,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,17);
adoption_check(!isset($withoutBrain['task:1002'])&&($coverage['tasks']??'')==='forbidden',
    'automatic core adoption does not restore a task source whose optional Brain permission is missing');
$GLOBALS['initiativeBrainAccess']=true;

foreach([[$one,18,180],[$two,19,190],[$one,0,142]] as [$owner,$agent,$cid]){
    $result=vp3_initiative_adopt_activity_v1($pdo,$owner,adoption_record($owner,$cid));
    $saved=vp3_initiative_policy_v1($owner,$agent);
    adoption_check($result['accepted']&&$result['changed']&&$result['agent_id']===$agent&&$saved['enabled']===true
        &&$saved['owner_user_id']===$owner['id']&&$saved['agent_id']===$agent&&$saved['conversation_id']===$cid,
        "owner {$owner['id']} automatically adopts only the resolved Agent $agent namespace, including System");
}
adoption_check(vp3_initiative_policy_v1($one,17)===$policy&&$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,
    'two owners, sibling Agents and System retain independent policy and restore the incoming memory namespace');

$granted=array_replace($policy,['knowledge_scope'=>['mode'=>'folder','folder_id'=>9],'screen_enabled'=>false]);
adoption_save($one,17,$granted);$peer=vp3_initiative_policy_v1($one,18);$foreign=vp3_initiative_policy_v1($two,19);
$oldObservation=adoption_record($one,170);
vp3_initiative_adopt_activity_v1($pdo,$one,$oldObservation);
$newObservation=adoption_record($one,171);
$moved=vp3_initiative_adopt_activity_v1($pdo,$one,$newObservation);$movedPolicy=vp3_initiative_policy_v1($one,17);
adoption_check($moved['accepted']&&$moved['changed']&&$movedPolicy['conversation_id']===171
    &&$movedPolicy['revision']!==$granted['revision']&&$movedPolicy['knowledge_scope']===$granted['knowledge_scope']
    &&$movedPolicy['screen_enabled']===false&&vp3_initiative_policy_v1($one,18)===$peer&&vp3_initiative_policy_v1($two,19)===$foreign,
    'focusing another saved Chat rebinds only its exact Agent, rotates the revision and preserves the saved Knowledge grant');
adoption_declined($one,$oldObservation,'a delayed previously accepted Chat observation cannot rebind the Agent away from the newer focused Chat');

$system=vp3_initiative_policy_v1($one,0);
$system['knowledge_scope']=['mode'=>'folder','folder_id'=>6];$system['screen_enabled']=true;adoption_save($one,0,$system);
$systemMove=vp3_initiative_adopt_activity_v1($pdo,$one,adoption_record($one,144));$systemMoved=vp3_initiative_policy_v1($one,0);
adoption_check($systemMove['accepted']&&$systemMove['changed']&&$systemMoved['conversation_id']===144
    &&$systemMoved['screen_enabled']===true&&$systemMoved['knowledge_scope']===$system['knowledge_scope'],
    'System Chat rebinding preserves its explicit screen and private-folder grants');

$paused=scope_command($one,17,171,'off');$pausePolicy=vp3_initiative_policy_v1($one,17);
$pauseHeartbeat=vp3_initiative_adopt_activity_v1($pdo,$one,adoption_record($one,170));
$pauseReload=vp3_initiative_adopt_activity_v1($pdo,$one,adoption_record($one,171));
$pauseHealth=vp3_initiative_worker_health_v1($pdo,$one,17,171);
adoption_check($paused['ok']&&$pauseHeartbeat['accepted']&&$pauseReload['accepted']&&$pauseHeartbeat['paused']&&$pauseReload['paused']
    &&!$pauseHeartbeat['changed']&&!$pauseReload['changed']&&vp3_initiative_policy_v1($one,17)===$pausePolicy
    &&$pauseHealth['state']==='paused'&&$pauseHealth['reason']==='initiative_paused'&&$pauseHealth['paused']===true
    &&$pauseHealth['policy_exists']===true&&$pauseHealth['knowledge_scope']===$granted['knowledge_scope'],
    'explicit Pause survives reload and switching Chats while health preserves the existing saved Knowledge grant');
$legacyPause=['enabled'=>false,'conversation_id'=>142,'revision'=>bin2hex(random_bytes(16)),
    'screen_enabled'=>true,'knowledge_scope'=>['mode'=>'folder','folder_id'=>6]];
adoption_save($one,0,$legacyPause);
$legacy=vp3_initiative_adopt_activity_v1($pdo,$one,adoption_record($one,144));
adoption_check($legacy['accepted']&&$legacy['paused']&&!$legacy['changed']&&vp3_initiative_policy_v1($one,0)===$legacyPause,
    'a genuine legacy disabled System policy remains Pause without adding a new grant or silently enabling it');
$systemResume=scope_control($one,0,144,'on',$legacyPause['revision']);$resumedSystem=vp3_initiative_policy_v1($one,0);
$systemPause=scope_control($one,0,144,'off',$resumedSystem['revision']);$pausedSystem=vp3_initiative_policy_v1($one,0);
adoption_check($systemResume['ok']&&$systemPause['ok']&&$resumedSystem['screen_enabled']===true&&$pausedSystem['screen_enabled']===true
    &&$resumedSystem['knowledge_scope']===$legacyPause['knowledge_scope']&&$pausedSystem['knowledge_scope']===$legacyPause['knowledge_scope'],
    'explicit System Pause and Resume preserve the saved screen and Knowledge choices');
$GLOBALS['initiativeBrainAccess']=false;
$resumed=scope_control($one,17,170,'on',$pausePolicy['revision']);$resumePolicy=vp3_initiative_policy_v1($one,17);
adoption_check($resumed['ok']&&$resumePolicy['enabled']&&$resumePolicy['conversation_id']===170
    &&$resumePolicy['knowledge_scope']===$pausePolicy['knowledge_scope'],
    'an explicit Resume works without optional Brain UI access and preserves the existing Knowledge scope');
$GLOBALS['initiativeBrainAccess']=true;

// The helper does not trust a request merely because an activity API was called.
// It corroborates exact owner-scoped accepted state, focus, identity and freshness.
$accepted=adoption_record($one,170);vp3_initiative_adopt_activity_v1($pdo,$one,$accepted);
$postedAgent=vp3_initiative_adopt_activity_v1($pdo,$one,array_replace($accepted,['agent_id'=>18,'user_agent_id'=>18]));
adoption_check($postedAgent['accepted']&&$postedAgent['agent_id']===17&&!$postedAgent['changed'],
    'the saved owned conversation determines Agent identity instead of a posted Agent selector');
foreach([
    ['activity_sequence'=>$accepted['activity_sequence']+1],
    ['activity_observed_at'=>$accepted['activity_observed_at']+1],
    ['activity_tab'=>str_repeat('b',32)],
    ['conversation_id'=>171],['conversation_id'=>'170'],['surface'=>'stem'],
    ['chat_busy'=>'false'],['chat_focused'=>false],['focused'=>false],['visible'=>false],
] as $change){
    adoption_declined($one,array_replace($accepted,$change),'an unaccepted or malformed '.implode(',',array_keys($change)).' tuple cannot activate or rebind core work');
}
$stale=adoption_observation(170,['activity_observed_at'=>(int)floor(microtime(true)*1000)-91000]);
agent_activity_v94_record($one,'chat','working',$stale,'stale_core_adoption_fixture');
adoption_declined($one,$stale,'an activity observation rejected by canonical ordering cannot adopt even when all identity fields are plausible');
$hidden=adoption_record($one,170,['visible'=>false,'focused'=>false,'chat_focused'=>false]);
adoption_declined($one,$hidden,'an accepted hidden background observation cannot activate core work');
$unfocused=adoption_record($one,170,['focused'=>false,'chat_focused'=>false]);
adoption_declined($one,$unfocused,'an accepted visible but unfocused Chat cannot activate core work');
$busy=adoption_record($one,170,['chat_busy'=>true]);$busyResult=vp3_initiative_adopt_activity_v1($pdo,$one,$busy);
adoption_check($busyResult['accepted']&&!$busyResult['changed'],
    'a focused busy owner can establish lifecycle binding without pretending the owner is available for a report');
$expired=adoption_record($one,170);$pdo->exec("UPDATE agent_activity_state SET last_heartbeat_at='2000-01-01 00:00:00' WHERE user_id=1");
adoption_declined($one,$expired,'a stale stored heartbeat cannot activate even when the request repeats its exact observation tuple');
$crossOwner=adoption_record($one,190);adoption_declined($one,$crossOwner,'an owner cannot adopt another account conversation through an otherwise valid activity observation');
adoption_declined($two,$crossOwner,'account switching cannot reuse the previous account accepted activity tuple');
$missingChat=adoption_record($one,99999);adoption_declined($one,$missingChat,'an unsaved or deleted Chat cannot become the automatic Agent binding');
$private=adoption_record($one,170);$GLOBALS['initiativeScopeParticipant']=['participant_id'=>5];
adoption_declined($one,$private,'a participant session cannot adopt an owner private Agent policy');unset($GLOBALS['initiativeScopeParticipant']);
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
adoption_declined($one,$private,'a deactivated account cannot retain automatic adoption authority');$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=17');
adoption_declined($one,$private,'a retired named Agent cannot become automatically active');$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=17');

$stablePolicy=vp3_initiative_policy_v1($one,17);
$rawPolicy=$pdo->query("SELECT metadata_json FROM agent_memory_items WHERE user_id=1 AND user_agent_id=17 AND memory_type='initiative_policy'")->fetchColumn();
foreach([
    '{',
    json_encode(array_replace($stablePolicy,['enabled'=>'false']),JSON_THROW_ON_ERROR),
    json_encode(array_replace($stablePolicy,['conversation_id'=>'170']),JSON_THROW_ON_ERROR),
    json_encode(array_replace($stablePolicy,['revision'=>'not-a-policy-revision']),JSON_THROW_ON_ERROR),
    json_encode(array_replace($stablePolicy,['owner_user_id'=>2]),JSON_THROW_ON_ERROR),
    json_encode(array_replace($stablePolicy,['agent_id'=>18]),JSON_THROW_ON_ERROR),
] as $index=>$malformedPolicy){
    $pdo->prepare("UPDATE agent_memory_items SET metadata_json=? WHERE user_id=1 AND user_agent_id=17 AND memory_type='initiative_policy'")->execute([$malformedPolicy]);
    adoption_declined($one,$private,'malformed stored preference '.$index.' cannot be mistaken for a missing policy or overwritten by automatic adoption');
}
$pdo->prepare("UPDATE agent_memory_items SET metadata_json=?,is_active=0 WHERE user_id=1 AND user_agent_id=17 AND memory_type='initiative_policy'")->execute([$rawPolicy]);
adoption_declined($one,$private,'an inactive stored preference cannot be mistaken for absence and silently reactivate a paused Agent');
$pdo->exec("UPDATE agent_memory_items SET is_active=1 WHERE user_id=1 AND user_agent_id=17 AND memory_type='initiative_policy'");
$pdo->failPolicyRead=true;
try{adoption_declined($one,$private,'a strict preference read failure cannot overwrite an existing saved policy or infer useful work');}
finally{$pdo->failPolicyRead=false;}

// Rebind while an actual canonical planner is outside its transaction doing
// inference. The completed response must fail the same policy revision fence.
$current=adoption_record($one,170);vp3_initiative_adopt_activity_v1($pdo,$one,$current);
$oldPolicy=vp3_initiative_policy_v1($one,17);
$provider=static function(string $prompt,string $protocol):string{
    if($protocol==='vp3.initiative-draft.v1')return json_encode(['draft'=>'A useful source-backed result prepared for this saved Chat.'],JSON_THROW_ON_ERROR);
    $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);$ref=$input['evidence'][0]['ref'];
    return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$ref,'refs'=>[$ref],
        'focus'=>'Advance the exact current saved work','report'=>true]]],JSON_THROW_ON_ERROR);
};
$plan=scope_make_plan($one,17,'Owner switches saved Chat during model evaluation');
$prepared=count(scope_runs(1,17,'initiative.prepare'));$messages=count(scope_messages());$rebindDuringInference=null;
$result=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$plan),static function(string $prompt,string $protocol)use($one,$provider,&$rebindDuringInference):string{
    $answer=$provider($prompt,$protocol);
    $rebindDuringInference=vp3_initiative_adopt_activity_v1(db(),$one,adoption_record($one,171));return $answer;
});
adoption_check($rebindDuringInference['accepted']&&$rebindDuringInference['changed']&&!$result['ok']
    &&vp3_initiative_policy_v1($one,17)['conversation_id']===171
    &&count(scope_runs(1,17,'initiative.prepare'))===$prepared&&count(scope_messages())===$messages,
    'automatic focused-Chat rebinding during model inference fences the old revision before any new preparation or old-Chat message commits');
$pumpCalls=0;
$stalePumpRejected=false;
try{vp3_initiative_pump_v1($pdo,$one,17,170,$oldPolicy['revision'],static function(string $prompt,string $protocol)use(&$pumpCalls):string{
    ++$pumpCalls;throw new RuntimeException('An old policy must never infer.');
});}catch(RuntimeException $e){$stalePumpRejected=$e->getCode()===409;}
adoption_check($stalePumpRejected&&$pumpCalls===0&&count(scope_messages())===$messages,
    'a previous Chat tab cannot pump or deliver with its stale pre-rebind revision');

vp3_initiative_adopt_activity_v1($pdo,$one,adoption_record($one,170));
$draftPlan=scope_make_plan($one,17,'Owner switches Chat while the useful private result is being written');
adoption_check(vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$draftPlan),$provider)['ok'],
    'the real canonical planner can queue useful work under the automatically adopted Chat');
$draftRun=(int)array_slice(scope_runs(1,17,'initiative.prepare'),-1)[0]['id'];
$draftRebind=null;
$staleDraft=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$draftRun),static function(string $prompt,string $protocol)use($one,$provider,&$draftRebind):string{
    $answer=$provider($prompt,$protocol);
    $draftRebind=vp3_initiative_adopt_activity_v1(db(),$one,adoption_record($one,171));return $answer;
});
adoption_check($draftRebind['accepted']&&$draftRebind['changed']&&!$staleDraft['ok']&&count(scope_messages())===$messages,
    'a finished Agent draft cannot commit or enter either Chat after automatic rebinding changes its original policy revision');

vp3_initiative_adopt_activity_v1($pdo,$one,adoption_record($one,170));
$pendingPlan=scope_make_plan($one,17,'An absent owner has a prepared result before focusing a different Chat');
adoption_check(vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$pendingPlan),$provider)['ok'],
    'a second source change creates independent useful work in the same canonical queue');
$pendingRun=(int)array_slice(scope_runs(1,17,'initiative.prepare'),-1)[0]['id'];
adoption_record($one,170,['visible'=>false,'focused'=>false,'chat_focused'=>false]);
$pendingResult=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$pendingRun),$provider);
$pendingQuery=$pdo->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$pendingQuery->execute([$pendingRun]);
$pendingStored=json_decode((string)$pendingQuery->fetchColumn(),true,24,JSON_THROW_ON_ERROR);$pendingQuery->closeCursor();
adoption_check($pendingResult['ok']&&$pendingStored['initiative_delivery']==='pending'&&count(scope_messages())===$messages,
    'a verified result waits in its canonical receipt while the bound Chat owner is absent');
vp3_initiative_adopt_activity_v1($pdo,$one,adoption_record($one,171));
$pendingDelivery=vp3_initiative_deliver_pending_v1($pdo,$one,17);
$pendingQuery->execute([$pendingRun]);$withdrawn=json_decode((string)$pendingQuery->fetchColumn(),true,24,JSON_THROW_ON_ERROR);$pendingQuery->closeCursor();
adoption_check(!$pendingDelivery&&$withdrawn['initiative_delivery']==='withheld'&&count(scope_messages())===$messages
    &&vp3_initiative_foreground_status_v1($pdo,$one,17,170)['state']==='linked_elsewhere',
    'automatic rebinding withdraws an old Chat pending result instead of releasing it into the newly focused Chat');

vp3_initiative_adopt_activity_v1($pdo,$one,adoption_record($one,170));
$sourcePlan=scope_make_plan($one,17,'Selected task authorization changes during planning');$prepared=count(scope_runs(1,17,'initiative.prepare'));
$sourceRevoked=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$sourcePlan),static function(string $prompt,string $protocol)use($provider):string{
    $answer=$provider($prompt,$protocol);$GLOBALS['initiativeBrainAccess']=false;return $answer;
});
$GLOBALS['initiativeBrainAccess']=true;
adoption_check(!$sourceRevoked['ok']&&count(scope_runs(1,17,'initiative.prepare'))===$prepared&&count(scope_messages())===$messages,
    'core default activation still discards model work when its selected private task source loses permission during inference');
adoption_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming&&!$pdo->inTransaction(),
    'successful, paused, denied and in-flight adoption paths leave the caller memory scope and transaction ownership intact');

echo 'AGENT_CORE_ADOPTION_V1=PASS ('.$adoptionChecks." cases)\n";
