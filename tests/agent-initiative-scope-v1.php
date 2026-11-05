<?php
declare(strict_types=1);

// Only provider transport and host availability are fixtures. Policy storage,
// source authorization, workflow leases/receipts and Chat delivery are production.
set_error_handler(static function(int $severity,string $message,string $file,int $line): bool{
    if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
$scopeFile=tempnam(sys_get_temp_dir(),'vp3-initiative-scope-');
if(!$scopeFile)throw new RuntimeException('Could not create scope fixture.');
putenv('VP3_IMAGE_TEST_DSN=sqlite:'.$scopeFile);
require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__).'/includes/agent-initiative-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-runtime-v1.php';
require_once dirname(__DIR__).'/includes/agent-reminders-v1.php';
register_shutdown_function(static fn()=>unlink($scopeFile));
$GLOBALS['initiativeBrainAccess']=true;

class InitiativeScopePDO extends ImageFixturePDO
{
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        if(str_contains($sql,'SELECT id FROM users WHERE id=? FOR UPDATE'))$GLOBALS['scopeOwnerLocks']=($GLOBALS['scopeOwnerLocks']??0)+1;
        $sql=str_replace(["JSON_UNQUOTE(JSON_EXTRACT(p.metadata_json,'$.enabled'))='true'","DATE_SUB(NOW(),INTERVAL 5 MINUTE)"],
            ["JSON_EXTRACT(p.metadata_json,'$.enabled')=1","datetime('now','-5 minutes')"],$sql);
        if(str_contains($sql,'INSERT INTO agent_memory_items')&&str_contains($sql,'ON DUPLICATE KEY UPDATE')){
            [$sql]=explode('ON DUPLICATE KEY UPDATE',$sql,2);
            $sql.='ON CONFLICT(user_id,memory_hash) DO UPDATE SET memory_text=excluded.memory_text,
                confidence=excluded.confidence,last_seen_at=NOW(),is_active=1,metadata_json=excluded.metadata_json,memory_scope_version=410';
        }
        return parent::prepare($sql,$options);
    }
}

$pdo=new InitiativeScopePDO('sqlite:'.$scopeFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach(['NOW','UTC_TIMESTAMP'] as $clock)$pdo->sqliteCreateFunction($clock,static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('DATABASE',static fn()=>'main');
$pdo->sqliteCreateFunction('IF',static fn($condition,$yes,$no)=>$condition?$yes:$no);
$pdo->exec("ALTER TABLE chat_conversations ADD COLUMN updated_at DATETIME;
    ALTER TABLE chat_conversations ADD COLUMN title TEXT;
    INSERT INTO user_agents VALUES(17,1,1,'Owner one Agent A'),(18,1,1,'Owner one Agent B'),(19,2,1,'Owner two Agent');
    INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES
      (142,1,NULL,'System one'),(143,2,NULL,'System two'),(170,1,17,'Agent A'),
      (171,1,17,'Another Agent A Chat'),(180,1,18,'Agent B'),(190,2,19,'Other owner');
    CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,
      subject TEXT,memory_text TEXT,memory_hash TEXT,memory_scope_version INTEGER DEFAULT 410,source_archive_id INTEGER,
      confidence REAL,occurrence_count INTEGER,first_seen_at TEXT,last_seen_at TEXT,is_active INTEGER,metadata_json TEXT,
      UNIQUE(user_id,memory_hash));
    CREATE TABLE agent_chat_archive(user_agent_id INTEGER,created_at TEXT);
    CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);
    CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,last_seen_at);
    CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id,created_at);
    ATTACH DATABASE ':memory:' AS information_schema;
    CREATE TABLE information_schema.statistics AS SELECT 'main' AS table_schema,tbl_name AS table_name,name AS index_name
      FROM main.sqlite_master WHERE type='index';
    CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,
      owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
    CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);
    CREATE TABLE initiative_scope_calendar(owner_id INTEGER PRIMARY KEY,title TEXT,starts_at TEXT);
    CREATE TABLE agent_activity_state(user_id INTEGER PRIMARY KEY,surface TEXT,context_key TEXT,activity_state TEXT,
      last_heartbeat_at TEXT,idle_since_at TEXT,details_json TEXT);
    CREATE TABLE agent_activity_events(id INTEGER PRIMARY KEY);");

function agent_brain_schema_ready(): bool{return true;}
function agent_brain_normalize(string $text): string{return strtolower(trim($text));}
function agent_brain_sentences(string $text): array{return [$text];}
function vp3_participant_scope_v1(): ?array{return $GLOBALS['initiativeScopeParticipant']??null;}
function user_calendar_default_timezone_v1300(PDO $pdo,array $user): string{return 'UTC';}
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $agentId,string $surface='chat'): array
{
    $GLOBALS['scopeRoutes'][]=[(int)$user['id'],$agentId,$surface];
    return ['route'=>empty($GLOBALS['scopeRouteBlocked'])?'vp3_cloud':'homeserver','blocked'=>!empty($GLOBALS['scopeRouteBlocked']),
        'effective_preference'=>'vp3_cloud','cloud'=>['ready'=>empty($GLOBALS['scopeRouteBlocked'])],
        'home'=>['supported'=>false],'try_homeserver'=>false];
}
function ai_generate_chat_response(string $query,array $history,array $context,?array $user=null,?string $scope=null,?array $control=null): array
{
    $GLOBALS['scopeInference'][]=[(int)$user['id'],$scope,$control, json_decode($query,true,16,JSON_THROW_ON_ERROR)];
    $answer=($GLOBALS['scopeModel'])($query,(string)$control['protocol']);
    return ['ok'=>true,'answer'=>$answer];
}
function calendar_schedule_awareness_snapshot_v1330(PDO $pdo,array $user,DateTimeImmutable $now,int $days,bool $includeHomeServer=true): array
{
    if($includeHomeServer)throw new LogicException('A background projection must not probe a paired calendar.');
    $q=$pdo->prepare('SELECT * FROM initiative_scope_calendar WHERE owner_id=?');$q->execute([(int)$user['id']]);$row=$q->fetch();
    $event=$row?['ref'=>'event:'.(int)$user['id'],'id'=>(int)$user['id'],'kind'=>'event','title'=>$row['title'],'start_local'=>$row['starts_at']]:null;
    return ['available'=>true,'timezone'=>'UTC','upcoming'=>$event?[$event]:[],'current'=>null,'conflicts'=>[],
      'next'=>$event,'starts_soon'=>$event!==null];
}

$checks=0;
function scope_check(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
    ++$GLOBALS['checks'];echo "PASS $message\n";
}
function scope_principal(array $user,int $agent): array
{
    return vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380(db(),$user,$agent));
}
function scope_command(array $user,int $agent,int $cid,string $command): array
{
    return vp3_initiative_chat_v1(db(),$user,scope_principal($user,$agent),['conversation_id'=>$cid],$command,null);
}
function scope_control(array $user,int $agent,int $cid,string $command,string $revision): array
{
    return vp3_initiative_chat_v1(db(),$user,scope_principal($user,$agent),
        ['conversation_id'=>$cid,'initiative_expected_revision'=>$revision],$command,null);
}
function scope_runs(int $owner,int $agent,string $capability): array
{
    $q=db()->prepare('SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND '.($agent?'agent_id=?':'agent_id IS NULL')." AND source_kind='agent_initiative' AND capability_key=? ORDER BY id");
    $q->execute($agent?[$owner,$agent,$capability]:[$owner,$capability]);return $q->fetchAll();
}
function scope_claim(array $user,int $run): array
{
    return agent_job_claim_run_v1900(db(),$user,$run,'cloud',agent_worker_cloud_worker_id_v1910((int)$user['id']))
      ?:throw new RuntimeException('Scope fixture could not claim its canonical job.');
}
function scope_messages(): array
{
    return db()->query("SELECT conversation_id,message,context_json FROM chat_messages WHERE role='assistant' AND context_json IS NOT NULL ORDER BY id")->fetchAll();
}
function scope_make_plan(array $user,int $agent,string $title): int
{
    db()->prepare('UPDATE initiative_scope_calendar SET title=? WHERE owner_id=?')->execute([$title,(int)$user['id']]);
    db()->prepare("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE user_id=? AND memory_type='initiative_sweep'")->execute([(int)$user['id']]);
    vp3_initiative_schedule_v1(db(),$user,$agent);
    return (int)array_slice(scope_runs((int)$user['id'],$agent,'initiative.plan'),-1)[0]['id'];
}

$one=['id'=>1,'is_active'=>1];$two=['id'=>2,'is_active'=>1];
$at=gmdate('Y-m-d H:i:s',time()+600);
$pdo->prepare('INSERT INTO initiative_scope_calendar VALUES(1,?,?),(2,?,?)')->execute(['Owner one launch',$at,'Owner two private launch',$at]);
$incoming=['user_id'=>2,'user_agent_id'=>19];$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$incoming;
scope_check(vp3_initiative_in_scope_v1($one,17,static fn()=>vp3_agent_memory_scope_current_v410(['id'=>1]))===17
    && $GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,'scope helper restores the previous owner/Agent after a successful callback');
try{vp3_initiative_in_scope_v1($one,17,static fn()=>throw new RuntimeException('Expected fixture failure'));}catch(RuntimeException $error){}
scope_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,'scope helper restores the previous owner/Agent after a failed callback');
unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);
vp3_initiative_in_scope_v1($one,17,static fn()=>true);
scope_check(!array_key_exists('VP3_AGENT_MEMORY_SCOPE_V410',$GLOBALS),'scope helper also restores an originally absent memory context');
$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$incoming;
scope_check(vp3_initiative_binding_agent_v1([])===0&&vp3_initiative_binding_agent_v1(['agent_id'=>17])===17,
    'legacy absent Agent bindings remain System and named bindings retain their integer identity');
foreach(['17',-1,17.0,null,[]] as $invalid){
    $rejected=false;
    try{vp3_initiative_binding_agent_v1(['agent_id'=>$invalid]);}catch(RuntimeException $error){$rejected=$error->getCode()===403;}
    scope_check($rejected,'malformed Agent binding cannot fall back to the System namespace: '.json_encode($invalid));
}

$taskInsert=$pdo->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,
    memory_scope_version,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
    VALUES(?,?,?,'task',?,'Existing private task',?,410,.95,1,NOW(),NOW(),1,?)");
foreach([[1001,1,null,'Only System task'],[1002,1,17,'Only Agent A task'],[1003,1,18,'Only Agent B task'],[1004,2,19,'Other owner task']]
    as [$id,$owner,$agent,$title]){
    $taskInsert->execute([$id,$owner,$agent,$title,'scope-task-'.$id,
        json_encode(['task_status'=>'open','due_at'=>gmdate('Y-m-d H:i:s',time()-60)],JSON_THROW_ON_ERROR)]);
}
$coverage=[];
$ownEvidence=vp3_initiative_evidence_v1($pdo,$one,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,17);
scope_check(isset($ownEvidence['task:1002'])&&!isset($ownEvidence['task:1001'])&&!isset($ownEvidence['task:1003'])&&!isset($ownEvidence['task:1004'])
    &&!str_contains(json_encode($ownEvidence,JSON_THROW_ON_ERROR),'Owner two private launch'),
    'named evidence reuses the exact Agent task reader without System, sibling or foreign-owner tasks');
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(17,'knowledge','brain:1002','deny')");
$deniedEvidence=vp3_initiative_evidence_v1($pdo,$one,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,17);
scope_check(!isset($deniedEvidence['task:1002']),'an explicit named-Agent resource denial removes that task from preparation evidence');
$pdo->exec("DELETE FROM user_agent_data_rules WHERE agent_id=17");
scope_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,'evidence readers and permission failures restore the previous memory scope');

scope_check(str_contains(scope_command($one,0,142,'on')['answer'],'Initiative is on'),'existing System Chat can still opt in');
$legacy=vp3_initiative_policy_v1($one,0);
scope_check(!isset($legacy['agent_id'])&&!isset($legacy['owner_user_id'])&&$legacy['conversation_id']===142,'legacy System policy retains its existing binding shape');
scope_check(vp3_initiative_policy_v1($one,17)===[]&&vp3_initiative_foreground_status_v1($pdo,$one,17,170)['state']==='off',
    'System opt-in is never inherited by an owned named Agent');
foreach([[$one,17,170],[$one,18,180],[$two,19,190]] as [$user,$agent,$cid]){
    $initial=scope_control($user,$agent,$cid,'on','');
    scope_check($initial['ok']&&!$pdo->inTransaction()&&str_contains($initial['answer'],'Initiative is on'),
        "owner {$user['id']} explicitly opts Agent $agent into its own Chat with the initial empty control revision");
    $policy=vp3_initiative_policy_v1($user,$agent);
    scope_check($policy['agent_id']===$agent&&$policy['owner_user_id']===$user['id']&&$policy['conversation_id']===$cid,
        "Agent $agent policy records exact owner, Agent and conversation");
}
scope_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,'commands and policy reads preserve the caller memory scope across two owners');
$currentPolicy=vp3_initiative_policy_v1($one,17);$peerPolicy=vp3_initiative_policy_v1($one,18);
$same=scope_control($one,17,170,'on',$currentPolicy['revision']);
scope_check($same['ok']&&vp3_initiative_policy_v1($one,17)===$currentPolicy&&!$pdo->inTransaction(),
    'a base control with the exact current revision succeeds without unnecessarily changing the policy');
$changed=scope_control($one,17,170,'off',$currentPolicy['revision']);
$changedPolicy=vp3_initiative_policy_v1($one,17);$locksBefore=$GLOBALS['scopeOwnerLocks'];
$staleControl=scope_control($one,17,170,'on',$currentPolicy['revision']);
scope_check($changed['ok']&&$staleControl['ok']===false&&$staleControl['reason']==='settings_changed'
    &&vp3_initiative_policy_v1($one,17)===$changedPolicy&&!$pdo->inTransaction()&&$GLOBALS['scopeOwnerLocks']>$locksBefore,
    'an old base control revision is rejected under the owner lock after another tab changes the setting, leaving policy intact and transaction closed');
scope_check(vp3_initiative_policy_v1($one,18)===$peerPolicy
    &&scope_control($one,18,180,'on',$peerPolicy['revision'])['ok'],
    'one Agent control revision change does not invalidate its sibling Agent setting');
scope_check(scope_control($one,17,170,'on',$changedPolicy['revision'])['ok']&&!$pdo->inTransaction(),
    'the latest verified control revision can explicitly enable the named Agent again');
$before=vp3_initiative_policy_v1($one,17);
scope_command($one,17,180,'off');
scope_check(vp3_initiative_policy_v1($one,17)===$before,'a command in another Agent conversation cannot change the first Agent policy');
scope_check(vp3_initiative_foreground_status_v1($pdo,$one,17,171)['state']==='linked_elsewhere'
    && vp3_initiative_foreground_status_v1($pdo,$one,18,170)['state']==='unavailable'
    && vp3_initiative_foreground_status_v1($pdo,$two,19,170)['state']==='unavailable',
    'foreground status separates another bound Chat, another Agent and another owner');
$GLOBALS['initiativeScopeParticipant']=['participant_id'=>5];
scope_command($one,17,170,'off');
scope_check(vp3_initiative_policy_v1($one,17)===$before&&vp3_initiative_foreground_status_v1($pdo,$one,17,170)['state']==='unavailable',
    'participant presence cannot change or inspect owner-private Initiative');
unset($GLOBALS['initiativeScopeParticipant']);

vp3_initiative_sweep_v1($pdo,25);
foreach([[1,0],[1,17],[1,18],[2,19]] as [$owner,$agent])scope_check(count(scope_runs($owner,$agent,'initiative.plan'))===1,
    "owner $owner Agent $agent receives exactly one scoped canonical review");
vp3_initiative_schedule_v1($pdo,$one,17);
vp3_initiative_in_scope_v1($one,17,static fn()=>vp3_initiative_schedule_v1(db(),['id'=>1,'is_active'=>1]));
scope_check(count(scope_runs(1,17,'initiative.plan'))===1&&$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,
    'repeated named scheduling deduplicates evidence and preserves incoming memory scope');

$GLOBALS['scopeModel']=static function(string $prompt,string $protocol): string{
    if($protocol==='vp3.initiative-draft.v1')return json_encode(['draft'=>str_repeat('Useful verified preparation. ',25)],JSON_THROW_ON_ERROR);
    $input=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);$rows=$input['evidence'];$ref=$rows[0]['ref'];
    scope_check(isset($input['tools'],$input['budget'])&&is_array($input['tools'])&&is_array($input['budget']),
        'named planning receives governed tool availability and bounded execution budget');
    scope_check(!str_contains($prompt,'Owner two private launch'),'owner one model receives no other owner evidence');
    return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$ref,'refs'=>[$ref],
      'focus'=>'Prepare the next concrete step from this evidence','report'=>true]]],JSON_THROW_ON_ERROR);
};
$plan=scope_runs(1,17,'initiative.plan')[0];
$planned=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,(int)$plan['id']));
$prepared=scope_runs(1,17,'initiative.prepare');
scope_check($planned['ok']&&count($prepared)===1&&scope_runs(1,18,'initiative.prepare')===[]&&scope_runs(2,19,'initiative.prepare')===[],
    'named planning queues preparation only under the exact original Agent');
scope_check($GLOBALS['scopeRoutes'][0]===[1,17,'chat']&&$GLOBALS['scopeInference'][0][1]==='main_ai'
    &&$GLOBALS['scopeInference'][0][2]['surface']==='tool_json','inference uses the named Agent route and existing governed AI surface');
$pdo->prepare("INSERT INTO agent_activity_state VALUES(1,'chat','chat:170','paused',NOW(),NULL,?)")
    ->execute([json_encode(['visible'=>true,'conversation_id'=>170],JSON_THROW_ON_ERROR)]);
$done=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,(int)$prepared[0]['id']));
$messages=scope_messages();
scope_check($done['ok']&&count($messages)===1&&(int)$messages[0]['conversation_id']===170&&mb_strlen($messages[0]['message'])>490,
    'useful longer preparation commits into the opted-in named Chat rather than System or another Agent');
scope_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,'planning and delivery restore the previous memory scope');

$siblingPlan=scope_runs(1,18,'initiative.plan')[0];
scope_check(vp3_initiative_execute_v1($pdo,$one,scope_claim($one,(int)$siblingPlan['id']))['ok'],
    'a sibling Agent can independently plan its own preparation');
$siblingDraft=scope_runs(1,18,'initiative.prepare')[0];
scope_check(vp3_initiative_execute_v1($pdo,$one,scope_claim($one,(int)$siblingDraft['id']))['ok']&&count(scope_messages())===1,
    'a sibling preparation stays pending while its owner is viewing another Agent Chat');
scope_check(!vp3_initiative_deliver_pending_v1($pdo,$one,17)&&count(scope_messages())===1,
    'delivery polling one Agent cannot publish another Agent pending preparation');
$pdo->exec("UPDATE agent_activity_state SET context_key='chat:180',details_json='{\"visible\":true,\"conversation_id\":180}',last_heartbeat_at=NOW() WHERE user_id=1");
scope_check(vp3_initiative_deliver_pending_v1($pdo,$one,18)&&count(scope_messages())===2
    &&(int)scope_messages()[1]['conversation_id']===180,'returning to the sibling Chat delivers its pending result into that exact conversation');
scope_check(!vp3_initiative_deliver_pending_v1($pdo,$one,18)&&count(scope_messages())===2
    &&$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,'pending named delivery is idempotent and restores the incoming owner scope');
$deliveredCount=count(scope_messages());

$tampered=scope_make_plan($one,17,'Source before job scope tampering');
$q=$pdo->prepare('SELECT goal FROM agent_workflow_runs WHERE id=?');$q->execute([$tampered]);
$payload=json_decode((string)$q->fetchColumn(),true,16,JSON_THROW_ON_ERROR);$payload['binding']['agent_id']=18;
$pdo->prepare('UPDATE agent_workflow_runs SET goal=? WHERE id=?')->execute([json_encode($payload,JSON_THROW_ON_ERROR),$tampered]);
$inferenceBefore=count($GLOBALS['scopeInference']);
$badBinding=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$tampered));
scope_check(!$badBinding['ok']&&count($GLOBALS['scopeInference'])===$inferenceBefore&&count(scope_messages())===$deliveredCount,
    'job binding cannot select a different Agent from its canonical workflow ledger identity');

$newPlan=scope_make_plan($one,17,'Source before a stale inference');$before=count(scope_runs(1,17,'initiative.prepare'));
$stale=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$newPlan),static function(string $prompt,string $protocol): string{
    $answer=($GLOBALS['scopeModel'])($prompt,$protocol);
    db()->exec("UPDATE initiative_scope_calendar SET title='Source changed during inference' WHERE owner_id=1");
    return $answer;
});
scope_check(!$stale['ok']&&count(scope_runs(1,17,'initiative.prepare'))===$before&&count(scope_messages())===$deliveredCount,
    'a named source changed during inference cannot queue stale preparation or deliver a report');

$newPlan=scope_make_plan($one,17,'Source before owner revokes initiative');
$revoked=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$newPlan),static function(string $prompt,string $protocol)use($one):string{
    $answer=($GLOBALS['scopeModel'])($prompt,$protocol);scope_command($one,17,170,'off');return $answer;
});
scope_check(!$revoked['ok']&&count(scope_runs(1,17,'initiative.prepare'))===$before&&count(scope_messages())===$deliveredCount
    &&!vp3_initiative_policy_v1($one,17)['enabled'],'owner revocation during named inference fences its result before commit');
scope_check(vp3_initiative_policy_v1($one,18)['enabled']&&vp3_initiative_policy_v1($two,19)['enabled']
    &&vp3_initiative_policy_v1($one,0)===$legacy,'revoking one named Agent preserves sibling, foreign-owner and legacy System policies');

scope_command($one,17,170,'on');$newPlan=scope_make_plan($one,17,'Source before Brain access revocation');
$lostAccess=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$newPlan),static function(string $prompt,string $protocol):string{
    $answer=($GLOBALS['scopeModel'])($prompt,$protocol);$GLOBALS['initiativeBrainAccess']=false;return $answer;
});
$GLOBALS['initiativeBrainAccess']=true;
scope_check(!$lostAccess['ok']&&count(scope_runs(1,17,'initiative.prepare'))===$before&&count(scope_messages())===$deliveredCount,
    'Brain permission revocation during named inference prevents committing its result');

$newPlan=scope_make_plan($one,17,'Source with a blocked provider route');
$GLOBALS['scopeRouteBlocked']=true;$inferenceBefore=count($GLOBALS['scopeInference']);
$blocked=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$newPlan));
$GLOBALS['scopeRouteBlocked']=false;
scope_check(!$blocked['ok']&&count($GLOBALS['scopeInference'])===$inferenceBefore&&$GLOBALS['scopeRoutes'][array_key_last($GLOBALS['scopeRoutes'])]===[1,17,'chat'],
    'a blocked named Agent runtime route performs no Cloud provider call');

$newPlan=scope_make_plan($one,17,'Source before Agent is retired');
$retired=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$newPlan),static function(string $prompt,string $protocol):string{
    $answer=($GLOBALS['scopeModel'])($prompt,$protocol);db()->exec('UPDATE user_agents SET is_active=0 WHERE id=17');return $answer;
});
scope_check(!$retired['ok']&&count(scope_runs(1,17,'initiative.prepare'))===$before&&count(scope_messages())===$deliveredCount,
    'Agent deactivation during inference prevents new preparation and Chat delivery');
scope_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,'stale, revoked and retired inference paths restore the caller memory scope');

// The foreground fallback pumps this same ledger; it never becomes a second queue.
$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=17');
$copiedPayload=json_decode((string)$plan['goal'],true,16,JSON_THROW_ON_ERROR);
$copiedPayload['binding']=vp3_initiative_policy_v1($one,17);
$copyEvidence=vp3_initiative_evidence_v1($pdo,$one,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,17);
$copiedPayload['sources']=array_column($copyEvidence,'revision','ref');
$copiedPayload['canonical_sources']=array_map(static fn(array $row):string=>$row['canonical_source_revision']??$row['revision'],$copyEvidence);
vp3_initiative_enqueue_v1($pdo,$one,'initiative.plan',$copiedPayload,hash('sha256','copied-old-policy-pump-job'));
$q=$pdo->prepare("SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND agent_id=17 AND source_kind='agent_initiative' AND source_key=?");
$q->execute([hash('sha256','copied-old-policy-pump-job')]);$copiedRun=(int)$q->fetchColumn();
$systemRun=(int)scope_runs(1,0,'initiative.plan')[0]['id'];
$foreignRun=(int)scope_runs(2,19,'initiative.plan')[0]['id'];
scope_command($one,17,170,'off');scope_command($one,17,170,'on');
$pumpPolicy=vp3_initiative_policy_v1($one,17);
$pdo->exec("UPDATE initiative_scope_calendar SET title='A fresh named foreground situation' WHERE owner_id=1;
    UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE user_id=1 AND user_agent_id=17 AND memory_type='initiative_sweep';
    UPDATE agent_activity_state SET context_key='chat:170',details_json='{\"visible\":true,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1;
    UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=170;");
$maxDraft=str_repeat('A',8000);$pumpCalls=[];
$pumpModel=static function(string $prompt,string $protocol)use(&$pumpCalls,$maxDraft):string{
    $pumpCalls[]=$protocol;
    if($protocol==='vp3.initiative-draft.v1')return json_encode(['draft'=>$maxDraft],JSON_THROW_ON_ERROR);
    $input=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);$ref=$input['evidence'][0]['ref'];
    return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$ref,'refs'=>[$ref],
      'focus'=>'Prepare a complete bounded report for the current named situation','report'=>true]]],JSON_THROW_ON_ERROR);
};
$firstPump=vp3_initiative_pump_v1($pdo,$one,17,170,$pumpPolicy['revision'],$pumpModel);
scope_check($firstPump['ok']&&$firstPump['reason']==='completed'&&$pumpCalls===['vp3.initiative-plan.v1'],
    'the named foreground pump schedules and executes exactly one canonical plan per call');
$newPrepared=scope_runs(1,17,'initiative.prepare');$pumpPrepare=(int)array_slice($newPrepared,-1)[0]['id'];
scope_check(array_slice($newPrepared,-1)[0]['status']==='approved',
    'the first foreground pump leaves preparation queued for the next bounded call');
$secondPump=vp3_initiative_pump_v1($pdo,$one,17,170,$pumpPolicy['revision'],$pumpModel);
scope_check($secondPump['ok']&&$secondPump['reason']==='completed'
    &&$pumpCalls===['vp3.initiative-plan.v1','vp3.initiative-draft.v1'],
    'the next named foreground pump executes its canonical preparation instead of rerunning the plan');
$q=$pdo->prepare('SELECT status,attempt_count FROM agent_workflow_runs WHERE id IN (?,?,?) ORDER BY id');
$q->execute([$systemRun,$foreignRun,$copiedRun]);$untouched=$q->fetchAll();
scope_check(count($untouched)===3&&!array_filter($untouched,static fn(array $row):bool=>$row['status']!=='approved'||(int)$row['attempt_count']!==0),
    'the named foreground pump never claims a System, foreign-owner or copied old-policy run');
$q=$pdo->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$pumpPrepare]);
$executedAction=json_decode((string)$q->fetchColumn(),true,16,JSON_THROW_ON_ERROR);
$q=$pdo->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');$q->execute([$pumpPrepare]);
$executedReceipt=json_decode((string)$q->fetchColumn(),true,16,JSON_THROW_ON_ERROR);
scope_check(($executedAction['initiative_draft']??null)===$maxDraft&&($executedReceipt['initiative_draft']??null)===$maxDraft
    &&($executedAction['initiative_tool_result']['draft']??null)===$maxDraft,
    'actual named plan and prepare execution retains the exact 8000-character body in action and receipt JSON');
scope_check((string)array_slice(scope_messages(),-1)[0]['message']===$maxDraft
    &&$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,
    'the complete 8000-character report reaches the exact named Chat and the foreground pump restores memory scope');

// Isolate the durable result boundary from any single tool implementation.
$binding=vp3_initiative_policy_v1($one,17);$current=vp3_initiative_evidence_v1($pdo,$one,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,17);
$ref=array_key_first($current);$revision=$current[$ref]['revision'];
$serialPayload=['binding'=>$binding,'sources'=>[$ref=>$revision],'canonical_sources'=>[$ref=>$current[$ref]['canonical_source_revision']??$revision],
    'tool'=>'work.prepare_private','primary'=>$ref,'focus'=>'Verify the bounded durable result schema','report'=>false];
$serialHash=hash('sha256','full-private-result-roundtrip');
vp3_initiative_enqueue_v1($pdo,$one,'initiative.prepare',$serialPayload,$serialHash);
$q=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND source_key=?');$q->execute([$serialHash]);$serialRun=(int)$q->fetchColumn();
$serialClaim=scope_claim($one,$serialRun);
$toolReceipt=vp3_tool_receipt_v1('brain.read','search','read_complete','',0,2);
$review=['tool'=>'screen','source_id'=>'owned-capture-77','source_revision'=>hash('sha256','capture-77')];
$safeSources=[['source'=>$ref,'title'=>'Exact saved source','url'=>'/account.php#agent-brain']];
$toolResult=['draft'=>$maxDraft,'sources'=>[array_merge($safeSources[0],['forged_private'=>'DROP_THIS_PRIVATE_EXTRA'])],
    'tool_receipt'=>$toolReceipt+['forged_private'=>'DROP_THIS_PRIVATE_EXTRA'],
    'output'=>['type'=>'initiative_workbench_review','status'=>'review_required',
      'review_action'=>$review+['execute_now'=>true,'authorization'=>'DROP_THIS_PRIVATE_EXTRA'],'forged_private'=>'DROP_THIS_PRIVATE_EXTRA'],
    'forged_private'=>'DROP_THIS_PRIVATE_EXTRA','credentials'=>['secret'=>'DROP_THIS_PRIVATE_EXTRA']];
$recorded=agent_worker_cloud_result_v1910($pdo,$one,$serialClaim,true,'Verified private result schema.',[
    'effect_state'=>'private_draft_ready','initiative_draft'=>$maxDraft,'initiative_tool_result'=>$toolResult,'initiative_delivery'=>'private']);
$q=$pdo->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$serialRun]);$actionJson=(string)$q->fetchColumn();
$q=$pdo->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');$q->execute([$serialRun]);$receiptJson=(string)$q->fetchColumn();
$stored=json_decode($actionJson,true,16,JSON_THROW_ON_ERROR);
$expectedTool=['draft'=>$maxDraft,'sources'=>$safeSources,'tool_receipt'=>$toolReceipt,
    'output'=>['type'=>'initiative_workbench_review','status'=>'review_required','review_action'=>$review]];
scope_check(!$recorded['duplicate']&&$actionJson===$receiptJson&&$stored['initiative_draft']===$maxDraft
    &&$stored['initiative_tool_result']===$expectedTool&&!str_contains($actionJson,'DROP_THIS_PRIVATE_EXTRA'),
    'the actual leased worker stores complete bounded private body, normalized receipt and review action identically in action and receipt while dropping forged keys');
$public=agent_job_public_run_v1900($pdo,agent_workflow_row_v1400($pdo,1,$serialRun),true);
$publicJson=json_encode($public,JSON_THROW_ON_ERROR);
scope_check($public['goal']===''&&!str_contains($publicJson,'initiative_draft')&&!str_contains($publicJson,'initiative_tool_result')
    &&!str_contains($publicJson,substr($maxDraft,0,100))&&!str_contains($publicJson,'owned-capture-77'),
    'the ordinary owner workflow and receipt projections redact the private body and nested tool result entirely');
scope_check(agent_workflow_row_v1400($pdo,2,$serialRun)===null
    &&!in_array($serialRun,array_map('intval',array_column(agent_workflow_recent_v1400($pdo,$two),'id')),true),
    'another owner cannot read or list the private workflow through the canonical API backing services');

$ordinaryHash=hash('sha256','ordinary-workflow-cannot-store-private-schema');
vp3_initiative_enqueue_v1($pdo,$one,'initiative.prepare',$serialPayload,$ordinaryHash);
$q=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND source_key=?');$q->execute([$ordinaryHash]);$ordinaryRun=(int)$q->fetchColumn();
$pdo->prepare("UPDATE agent_workflow_runs SET source_kind='fixture_ordinary_work' WHERE id=?")->execute([$ordinaryRun]);
agent_worker_cloud_result_v1910($pdo,$one,scope_claim($one,$ordinaryRun),true,'Ordinary result.',[
    'effect_state'=>'orchestration_complete','initiative_draft'=>$maxDraft,'initiative_tool_result'=>$toolResult]);
$q=$pdo->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$ordinaryRun]);$ordinaryJson=(string)$q->fetchColumn();
scope_check(!str_contains($ordinaryJson,'initiative_draft')&&!str_contains($ordinaryJson,'initiative_tool_result')
    &&!str_contains($ordinaryJson,substr($maxDraft,0,100)),
    'a non-Initiative workflow cannot retain private Initiative bodies or nested tool results through the generic worker boundary');

foreach(['model_unavailable','quota_exhausted','route_unavailable'] as $code){
    $failureRun=scope_make_plan($one,17,'Verified failure projection '.$code);
    $failedResult=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$failureRun),
        static fn(string $prompt,string $protocol):string=>throw new RuntimeException($code,503));
    $failedRow=agent_workflow_row_v1400($pdo,1,$failureRun);
    scope_check(!$failedResult['ok']&&$failedResult['reason']===$code&&$failedRow['last_error_class']===$code
        &&$failedRow['status']==='failed'&&!$pdo->inTransaction(),
        "the actual worker preserves the governed public failure code $code in its canonical run");
}
$rawFailure='Authorization: Bearer FAKE_FIXTURE_KEY_DO_NOT_EXPOSE; password=PRIVATE_FIXTURE_CREDENTIAL';
$failureRun=scope_make_plan($one,17,'Unknown provider failure stays private');
$unknownFailure=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$failureRun),
    static fn(string $prompt,string $protocol):string=>throw new RuntimeException($rawFailure,503));
$failedRow=agent_workflow_row_v1400($pdo,1,$failureRun);
$q=$pdo->prepare('SELECT result_json,summary,error_class FROM agent_workflow_receipts WHERE run_id=?');$q->execute([$failureRun]);
$failureReceipt=json_encode($q->fetchAll(),JSON_THROW_ON_ERROR);
$publicFailure=json_encode(agent_job_public_run_v1900($pdo,$failedRow,true),JSON_THROW_ON_ERROR);
scope_check(!$unknownFailure['ok']&&$unknownFailure['reason']==='initiative_unconfirmed'
    &&$failedRow['last_error_class']==='initiative_unconfirmed'
    &&!str_contains($failureReceipt,'FAKE_FIXTURE_KEY_DO_NOT_EXPOSE')&&!str_contains($failureReceipt,'PRIVATE_FIXTURE_CREDENTIAL')
    &&!str_contains($publicFailure,'FAKE_FIXTURE_KEY_DO_NOT_EXPOSE')&&!str_contains($publicFailure,'PRIVATE_FIXTURE_CREDENTIAL'),
    'unknown provider exceptions become a generic failure without raw credential-like content in stored receipts or public projections');

// Clock fixtures isolate the two delivery guards without waiting or inventing a new owner turn.
putenv('VP3_INITIATIVE_REPORT_COOLDOWN_SECONDS=120');
$oldReportAt=gmdate('Y-m-d H:i:s',time()-121);
$ageReports=$pdo->prepare("UPDATE chat_messages SET created_at=? WHERE conversation_id=170
    AND JSON_EXTRACT(CASE WHEN JSON_VALID(context_json) THEN context_json ELSE '{}' END,'$.source')='agent_initiative'");
$ageReports->execute([$oldReportAt]);
scope_check(vp3_initiative_can_report_v1($pdo,$one,170),'an old completed Initiative report no longer blocks a later useful update');
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user','A direct owner question is still waiting')");
scope_check(!vp3_initiative_can_report_v1($pdo,$one,170),'pending owner input blocks an Initiative report even after the previous report cooldown expires');
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,NULL,'assistant','The direct owner question is answered')");
scope_check(vp3_initiative_can_report_v1($pdo,$one,170),'completing the direct reply releases the pending-user guard');
$currentReportPlan=scope_make_plan($one,17,'A current report after the direct reply');
$currentPlanResult=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$currentReportPlan),$GLOBALS['scopeModel']);
$currentPrepared=scope_runs(1,17,'initiative.prepare');$currentReportRun=(int)array_slice($currentPrepared,-1)[0]['id'];
$currentMessagesBefore=count(scope_messages());
$currentReportResult=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$currentReportRun),$GLOBALS['scopeModel']);
scope_check($currentPlanResult['ok']&&$currentReportResult['ok']&&count(scope_messages())===$currentMessagesBefore+1,
    'actual canonical preparation delivers a current report after the owner direct reply completes');
scope_check(!vp3_initiative_can_report_v1($pdo,$one,170),'a recently delivered Initiative report suppresses another immediate report');
$cooldownPlan=scope_make_plan($one,17,'Useful next update during report cooldown');
scope_check(vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$cooldownPlan),$GLOBALS['scopeModel'])['ok'],
    'new useful work can still finish planning while its report is cooling down');
$cooldownPrepared=scope_runs(1,17,'initiative.prepare');$cooldownRun=(int)array_slice($cooldownPrepared,-1)[0]['id'];
$messagesBefore=count(scope_messages());
$cooldownResult=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$cooldownRun),$GLOBALS['scopeModel']);
$q=$pdo->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$cooldownRun]);
$cooldownStored=json_decode((string)$q->fetchColumn(),true,16,JSON_THROW_ON_ERROR);
scope_check($cooldownResult['ok']&&$cooldownStored['initiative_delivery']==='pending'&&count(scope_messages())===$messagesBefore,
    'the actual worker retains a verified completed report as pending instead of posting during cooldown');
$q=$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE conversation_id=170 AND role='user'");$ownerTurnsBefore=(int)$q->fetchColumn();
$ageReports->execute([$oldReportAt]);
$providerCallsBefore=count($GLOBALS['scopeInference']);
scope_check(vp3_initiative_can_report_v1($pdo,$one,170)&&vp3_initiative_deliver_pending_v1($pdo,$one,17)
    &&count(scope_messages())===$messagesBefore+1,
    'after the prior report is 121 seconds old the saved pending result delivers without requiring another owner turn');
$q=$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE conversation_id=170 AND role='user'");
scope_check((int)$q->fetchColumn()===$ownerTurnsBefore&&count($GLOBALS['scopeInference'])===$providerCallsBefore
    &&!vp3_initiative_can_report_v1($pdo,$one,170)&&$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,
    'cooldown delivery uses the saved verified result, adds no synthetic owner turn or inference replay, and reinstates the current-report guard');

echo 'AGENT_INITIATIVE_SCOPE_V1=PASS ('.$checks." cases)\n";
