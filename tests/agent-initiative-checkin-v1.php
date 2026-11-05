<?php
declare(strict_types=1);

// The existing named-Agent fixture exercises the real governed model adapter,
// policy, canonical planner/queue/lease, receipt and ordinary Chat delivery.
require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-tools-v1.php';

$checkinChecks=0;
function checkin_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    ++$GLOBALS['checkinChecks'];echo 'PASS '.$label.PHP_EOL;
}
function checkin_reject(callable $work,int $code,string $label): void
{
    try{$work();}catch(RuntimeException|InvalidArgumentException $e){checkin_check($e->getCode()===$code,$label);return;}
    throw new RuntimeException('Expected rejection: '.$label);
}
function checkin_reset(bool $visible=true): void
{
    $user=['id'=>1,'is_active'=>1];
    scope_command($user,17,170,'off');scope_command($user,17,170,'on');
    db()->exec('DELETE FROM chat_messages WHERE conversation_id=170');
    db()->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at)
        VALUES(170,1,'user','The launch needs a clear agenda.','2000-01-01'),
            (170,NULL,'assistant','Saved private assistant tool result SECRET_ASSISTANT','2000-01-01')");
    db()->prepare("UPDATE agent_activity_state SET context_key='chat:170',activity_state='paused',last_heartbeat_at=NOW(),details_json=? WHERE user_id=1")
        ->execute([json_encode(['visible'=>$visible,'conversation_id'=>170,'chat_focused'=>true,'chat_busy'=>false],JSON_THROW_ON_ERROR)]);
}
function checkin_result(int $run): array
{
    $q=db()->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$run]);
    $saved=(string)$q->fetchColumn();
    return $saved===''?[]:json_decode($saved,true,24,JSON_THROW_ON_ERROR);
}
function checkin_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM chat_messages WHERE conversation_id=170 AND role='assistant'
        AND JSON_EXTRACT(CASE WHEN JSON_VALID(context_json) THEN context_json ELSE '{}' END,'$.initiative_output.type')='initiative_chat_check_in'")->fetchColumn();
}
function checkin_execute(?callable $draft=null): array
{
    $user=['id'=>1,'is_active'=>1];
    $GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($draft):string{
        $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
        if($protocol==='vp3.initiative-plan.v1'){
            checkin_check(!empty($input['tools']['chat.check_in']['ready'])&&$input['tools']['chat.check_in']['mode']==='private',
                'actual planner discovers the ready optional private check-in tool');
            return json_encode(['jobs'=>[['tool'=>'chat.check_in','primary'=>'calendar:event:1','refs'=>['calendar:event:1'],
                'focus'=>'Offer a useful next step for the current launch situation','report'=>true]]],JSON_THROW_ON_ERROR);
        }
        checkin_check(!db()->inTransaction()&&$protocol==='vp3.initiative-draft.v1'
            &&$input['response_kind']==='chat.check_in'&&$input['max_draft_chars']===1200,
            'check-in uses the existing governed draft protocol without a database transaction');
        return $draft?$draft($input):json_encode(['draft'=>'Your launch review is coming up. Would it help to turn the saved task into an agenda?'],JSON_THROW_ON_ERROR);
    };
    $plan=scope_make_plan($user,17,'Launch check-in evidence');
    $planned=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$plan));
    checkin_check($planned['ok'],'check-in assessment uses the canonical queue and lease');
    $run=(int)array_slice(scope_runs(1,17,'initiative.prepare'),-1)[0]['id'];
    $payload=json_decode(agent_workflow_row_v1400(db(),1,$run)['goal'],true,24,JSON_THROW_ON_ERROR);
    $completed=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$run));
    return ['run'=>$run,'payload'=>$payload,'completed'=>$completed,'result'=>checkin_result($run)];
}
function checkin_change(string $kind): void
{
    if($kind==='user')db()->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user','A new owner question')");
    elseif($kind==='assistant')db()->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,NULL,'assistant','A normal Agent completion')");
    elseif($kind==='edit')db()->exec("UPDATE chat_messages SET message='The owner edited the agenda request.' WHERE conversation_id=170 AND role='user'");
    elseif($kind==='delete')db()->exec("DELETE FROM chat_messages WHERE conversation_id=170 AND role='user'");
    else throw new InvalidArgumentException('Unknown fixture mutation.');
}

$user=['id'=>1,'is_active'=>1];
db()->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES
    (142,1,'user','SYSTEM_CHAT_SECRET'),(171,1,'user','OTHER_SAME_AGENT_CHAT_SECRET'),
    (180,1,'user','SIBLING_AGENT_SECRET'),(190,2,'user','OTHER_OWNER_SECRET')");
checkin_reset();
$callsBefore=count($GLOBALS['scopeInference']);
$done=checkin_execute(static function(array $input):string{
    $prompt=json_encode($input,JSON_THROW_ON_ERROR);
    checkin_check($input['agent']['name']==='Owner one Agent A'
        &&array_column($input['recent_user_messages'],'text')===['The launch needs a clear agenda.']
        &&isset($input['owner_context']['current_time'])&&$input['evidence'][0]['ref']==='calendar:event:1',
        'generation has the exact selected Agent, owner moment, verified source and recent owner turn');
    checkin_check(!preg_match('/SYSTEM_CHAT_SECRET|OTHER_SAME_AGENT_CHAT_SECRET|SIBLING_AGENT_SECRET|OTHER_OWNER_SECRET|SECRET_ASSISTANT/',$prompt),
        'System, sibling, other-owner, other-Chat and sourced assistant history never enter the prompt');
    return '{"draft":"Your launch review is coming up. Shall we turn the saved task into an agenda?"}';
});
checkin_check($done['completed']['ok']&&isset($done['result']['initiative_tool_result']['output']),
    'the actual saved canonical result retains its required check-in revision output');
$result=$done['result'];$tool=$result['initiative_tool_result'];$output=$tool['output'];
$q=db()->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');$q->execute([$done['run']]);
checkin_check($done['completed']['ok']&&$result['initiative_delivery']==='delivered'&&checkin_count()===1
    &&json_decode((string)$q->fetchColumn(),true,24,JSON_THROW_ON_ERROR)===$result,
    'one canonical successful receipt and saved ordinary Chat post contain the same verified check-in');
checkin_check(array_keys($output)===['type','status','conversation_id','agent_id','conversation_revision','message_ids']
    &&$output['agent_id']===17&&$output['conversation_id']===170
    &&!str_contains(json_encode($output,JSON_THROW_ON_ERROR),'agenda'),
    'saved tool output contains only its state, exact binding, tail revision and message IDs, never raw history');
$serialized=agent_workflow_initiative_result_v1400(['draft'=>$tool['draft'],'output'=>$output+['raw_history'=>'SECRET_HISTORY']]);
checkin_check($serialized['output']===$output&&!str_contains(json_encode($serialized,JSON_THROW_ON_ERROR),'SECRET_HISTORY')
    &&!isset(agent_workflow_public_json_v1400(json_encode(['initiative_tool_result'=>$serialized],JSON_THROW_ON_ERROR))['initiative_tool_result']),
    'strict private receipt serialization retains only check-in identifiers and ordinary public workflow projections redact it');
$stripped=agent_workflow_initiative_result_v1400(['output'=>array_replace($output,['message_ids'=>['1']])]);
checkin_check(!isset($stripped['output']),'malformed revision message IDs cannot survive private receipt serialization');
checkin_reject(fn()=>vp3_initiative_result_current_v1(db(),$user,$done['payload']['binding'],$done['payload'],['draft'=>'Missing revision']),409,
    'a saved check-in missing its revision output cannot inherit the legacy private-draft bypass');
$q=db()->prepare('SELECT message,context_json FROM chat_messages WHERE id=?');$q->execute([$result['initiative_message_id']]);$posted=$q->fetch();
$context=json_decode($posted['context_json'],true,24,JSON_THROW_ON_ERROR);
checkin_check($posted['message']===$tool['draft']&&$context['source']==='agent_initiative'
    &&$context['initiative_output']===$output&&!isset($context['speak'])&&!isset($context['auto_speak']),
    'check-in reuses canonical silent Initiative Chat metadata and the existing new-message presentation');
vp3_initiative_tools_result_current_v1(db(),$user,$done['payload']['binding'],$done['payload'],$tool);
checkin_check(checkin_count()===1&&count($GLOBALS['scopeInference'])===$callsBefore+2,
    'its own canonical Initiative post does not invalidate the ordinary-tail revision or call another model');
$beforeRuns=count(scope_runs(1,17,'initiative.prepare'));
scope_make_plan($user,17,'Launch check-in evidence');
checkin_check(count(scope_runs(1,17,'initiative.prepare'))===$beforeRuns&&checkin_count()===1
    &&!vp3_initiative_deliver_pending_v1(db(),$user,17),
    'reassessment of identical source/tool work uses existing dedupe and cannot queue or deliver a second check-in');
checkin_reject(fn()=>vp3_initiative_tools_result_current_v1(db(),['id'=>2,'is_active'=>1],$done['payload']['binding'],$done['payload'],$tool),403,
    'another owner cannot validate the check-in binding');
checkin_reject(fn()=>vp3_initiative_tools_result_current_v1(db(),$user,array_replace($done['payload']['binding'],['agent_id'=>18]),$done['payload'],$tool),403,
    'another Agent cannot validate the check-in binding');
checkin_check(vp3_initiative_plan_v1('{"jobs":[]}',[],['chat.check_in'])===[],
    'advertising check-in still permits the model to select zero jobs');

foreach(['user','assistant','edit','delete'] as $kind){
    checkin_reset();
    $changed=checkin_execute(static function()use($kind):string{checkin_change($kind);return '{"draft":"A now stale check-in."}';});
    checkin_check(!$changed['completed']['ok']&&checkin_count()===0&&!isset($changed['result']['initiative_draft']),
        'ordinary '.$kind.' during inference withholds the stale check-in and stores no draft');
}
foreach(['source','policy'] as $kind){
    checkin_reset();$changed=checkin_execute(static function()use($kind,$user):string{
        if($kind==='source')db()->exec("UPDATE initiative_scope_calendar SET title='Changed source during check-in' WHERE owner_id=1");
        else scope_command($user,17,170,'off');
        return '{"draft":"A check-in whose authority has changed."}';
    });
    checkin_check(!$changed['completed']['ok']&&checkin_count()===0&&!isset($changed['result']['initiative_draft']),
        'canonical '.$kind.' revocation during check-in inference still prevents delivery and draft storage');
}
foreach(['user','assistant','edit','delete'] as $kind){
    checkin_reset(false);$pending=checkin_execute();
    checkin_check($pending['completed']['ok']&&$pending['result']['initiative_delivery']==='pending'&&checkin_count()===0,
        'absent-owner check-in remains private for delayed '.$kind.' validation');
    checkin_change($kind);$calls=count($GLOBALS['scopeInference']);
    checkin_reject(fn()=>vp3_initiative_tools_result_current_v1(db(),$user,$pending['payload']['binding'],$pending['payload'],$pending['result']['initiative_tool_result']),409,
        'ordinary '.$kind.' after generation invalidates the saved tail revision');
    db()->exec("UPDATE agent_activity_state SET details_json='{\"visible\":true,\"conversation_id\":170,\"chat_focused\":true,\"chat_busy\":false}' WHERE user_id=1");
    vp3_initiative_deliver_pending_v1(db(),$user,17);
    checkin_check(checkin_result($pending['run'])['initiative_delivery']==='withheld'&&checkin_count()===0
        &&count($GLOBALS['scopeInference'])===$calls,
        'delayed '.$kind.' check-in is atomically withheld without replaying inference');
}
foreach(['{"draft":"<b>Hello</b>"}','{"draft":""}','not json','{"draft":"hello","extra":"bad"}',
    json_encode(['draft'=>str_repeat('x',1201)],JSON_THROW_ON_ERROR)] as $invalid){
    checkin_reset();$bad=checkin_execute(static fn():string=>$invalid);
    checkin_check(!$bad['completed']['ok']&&checkin_count()===0&&!isset($bad['result']['initiative_draft']),
        'malformed, HTML, empty, extra-field and excessive check-in output cannot reach Chat');
}

checkin_reset(false);
db()->exec('DELETE FROM chat_messages WHERE conversation_id=170');
$insert=db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user',?)");
for($i=0;$i<32;$i++)$insert->execute([str_repeat('Owner context '.$i.' ',90)]);
$bounded=checkin_execute(static function(array $input):string{
    $history=$input['recent_user_messages'];
    checkin_check(count($history)<=6&&array_sum(array_map(static fn(array $m):int=>mb_strlen($m['text']),$history))<=2400
        &&max(array_map(static fn(array $m):int=>mb_strlen($m['text']),$history))<=650,
        'recent user context is bounded by count, per-message size and total characters');
    return '{"draft":"Would you like to choose the next step from the saved launch context?"}';
});
checkin_check(count($bounded['result']['initiative_tool_result']['output']['message_ids'])===24,
    'the ordinary revision covers the latest 24 saved turns while model context remains smaller');
checkin_reset(false);
db()->prepare('UPDATE user_agents SET display_name=? WHERE id=17')->execute(["<b>Owner A</b>\x01 https://invalid.example/"]);
$named=checkin_execute(static function(array $input):string{
    checkin_check($input['agent']['name']==='Owner A','selected Agent identity is sanitized to a bounded plain display name');
    return '{"draft":"Shall we choose a useful next step for the launch?"}';
});
db()->exec("UPDATE user_agents SET display_name='Renamed Agent A' WHERE id=17");
checkin_reject(fn()=>vp3_initiative_tools_result_current_v1(db(),$user,$named['payload']['binding'],$named['payload'],$named['result']['initiative_tool_result']),409,
    'renaming the selected Agent also invalidates an undelivered old-identity check-in');
db()->exec("UPDATE user_agents SET display_name='Owner one Agent A' WHERE id=17");

// Observe the actual SQL emitted by the production final fence and execute it
// against the disposable DB. The adapter only removes unsupported FOR UPDATE.
class CheckinLockPDO extends InitiativeScopePDO
{
    public int $lockedAgentReads=0;
    public function prepare(string $sql,array $options=[]):PDOStatement|false
    {
        if($sql==='SELECT id,owner_user_id,display_name,is_active FROM user_agents WHERE id=? AND owner_user_id=? LIMIT 1 FOR UPDATE')$this->lockedAgentReads++;
        return parent::prepare($sql,$options);
    }
}
$lockPdo=new CheckinLockPDO('sqlite:'.$scopeFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$lockScope=['user'=>$user,'agent_id'=>17,'conversation_id'=>170];
$beforeRename=vp3_initiative_checkin_snapshot_v1($lockPdo,$lockScope);
db()->exec("UPDATE user_agents SET display_name='Current locked Agent identity' WHERE id=17");
$lockPdo->beginTransaction();
$lockedSnapshot=vp3_initiative_checkin_snapshot_v1($lockPdo,$lockScope);
checkin_check($lockPdo->lockedAgentReads===1&&$lockedSnapshot['agent_name']==='Current locked Agent identity'
    &&$lockedSnapshot['conversation_revision']!==$beforeRename['conversation_revision'],
    'the actual final-fence query locks the exact owner/Agent row and consumes its current identity');
$lockPdo->rollBack();
db()->exec('UPDATE user_agents SET is_active=0 WHERE id=17');
$lockPdo->beginTransaction();
checkin_reject(fn()=>vp3_initiative_checkin_snapshot_v1($lockPdo,$lockScope),403,
    'the actual final locking read rejects a now inactive selected Agent');
$lockPdo->rollBack();
$lockPdo->beginTransaction();
checkin_reject(fn()=>vp3_initiative_checkin_snapshot_v1($lockPdo,array_replace($lockScope,['agent_id'=>19])),403,
    'the final locking read cannot consume another owner’s Agent identity');
$lockPdo->rollBack();
db()->exec("UPDATE user_agents SET is_active=1,display_name='Owner one Agent A' WHERE id=17");
checkin_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,'all check-in success and failure paths restore the prior owner/Agent namespace');
echo 'AGENT_INITIATIVE_CHECKIN_V1=PASS ('.$checkinChecks.' cases)'.PHP_EOL;
