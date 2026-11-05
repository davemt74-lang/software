<?php
declare(strict_types=1);

// Real policy, source readers, workflow claimant, receipt, Chat post and lifecycle.
// Only the provider transport uses deterministic answers.
require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-followthrough-v1.php';

$followthroughChecks=0;
function followthrough_check(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException($label);
    ++$GLOBALS['followthroughChecks'];echo 'PASS '.$label.PHP_EOL;
}
function followthrough_reject(callable $work,int $status,string $label):void
{
    try{$work();}catch(Throwable $error){followthrough_check($error->getCode()===$status,$label.' ('.$error->getMessage().')');return;}
    throw new RuntimeException('Expected denial: '.$label);
}
function followthrough_owner_turn_v1(string $query,int $cid=170,int $owner=1):int
{
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,?,'user',?)")->execute([$cid,$owner,$query]);
    return (int)db()->lastInsertId();
}
function followthrough_seed_v1(string $title='Private next step',int $agent=17,int $cid=170):array
{
    static $serial=0;++$serial;$user=['id'=>1,'is_active'=>1];
    scope_command($user,$agent,$cid,'on');
    followthrough_owner_turn_v1('Begin a private source review '.$serial,$cid);
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,NULL,'assistant','The source review is ready to assess.')")->execute([$cid]);
    db()->prepare("UPDATE agent_activity_state SET surface='chat',context_key=?,activity_state='paused',last_heartbeat_at=NOW(),details_json=? WHERE user_id=1")
        ->execute(['chat:'.$cid,json_encode(['visible'=>true,'conversation_id'=>$cid,'chat_focused'=>true,'chat_busy'=>false],JSON_THROW_ON_ERROR)]);
    $binding=vp3_initiative_policy_v1($user,$agent);$coverage=[];
    $evidence=vp3_initiative_evidence_v1(db(),$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,$agent);
    $refs=array_values(array_filter(array_keys($evidence),static fn(string $ref):bool=>str_starts_with($ref,'calendar:')));
    if(!$refs)throw new RuntimeException('Canonical fixture calendar source is required.');
    $ref=$refs[0];$goal=['binding'=>$binding,'sources'=>[$ref=>$evidence[$ref]['revision']],
        'canonical_sources'=>[$ref=>$evidence[$ref]['canonical_source_revision']??$evidence[$ref]['revision']],
        'tool'=>'work.prepare_private','primary'=>$ref,'focus'=>$title,'report'=>true];
    vp3_initiative_enqueue_v1(db(),$user,'initiative.prepare',$goal,hash('sha256','followthrough-fixture-'.$serial));
    $run=(int)db()->lastInsertId();
    $q=db()->prepare("SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND dedupe_key=?");$q->execute([hash('sha256','initiative.prepare|'.hash('sha256','followthrough-fixture-'.$serial))]);$run=(int)$q->fetchColumn();
    $result=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$run),static function(string $prompt,string $protocol)use($title):string{
        if(db()->inTransaction())throw new LogicException('Provider cannot own a transaction.');
        return json_encode(['draft'=>$title.' Would a private next-step analysis help?'],JSON_THROW_ON_ERROR);
    });
    if(empty($result['ok']))throw new RuntimeException('Canonical preparation failed: '.json_encode($result));
    $q=db()->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=1');$q->execute([$run]);
    $stored=json_decode((string)$q->fetchColumn(),true);$message=vp3_initiative_followthrough_id_v1($stored['initiative_message_id']??null);
    if($message<1)throw new RuntimeException('Canonical preparation was not delivered.');
    return vp3_initiative_followthrough_load_v1(db(),$user,scope_principal($user,$agent),$cid,$message);
}
function followthrough_ref_v1(array $root):array
{
    return ['message_id'=>(int)$root['message']['id'],'reply_ref'=>$root['state']['reply_ref'],'revision'=>$root['state']['revision']];
}
function followthrough_decide_v1(array $root,string $intent,string $query='Yes, please',?callable $during=null):array
{
    $cid=$root['conversation_id'];$id=followthrough_owner_turn_v1($query,$cid);
    return vp3_initiative_followthrough_reply_v1(db(),$root['user'],$root['principal'],$cid,$id,followthrough_ref_v1($root),$query,null,
        static function(string $prompt,string $protocol)use($intent,$during):string{
            followthrough_check(!db()->inTransaction()&&$protocol==='vp3.initiative-reply.v1','intent inference uses the governed reply protocol outside a transaction');
            if($during)$during();return json_encode(['intent'=>$intent,'focus'=>'Develop the next private analysis from the verified source.'],JSON_THROW_ON_ERROR);
        });
}

$root=followthrough_seed_v1('Product image readiness');$rootMessageId=(int)$root['message']['id'];
followthrough_check($root['state']['state']==='open'&&$root['state']['revision']===1
    &&preg_match('/\A[a-f0-9]{64}\z/D',$root['state']['reply_ref'])===1,
    'a real delivered preparation has a server-bound open proposal tied to its completed receipt');
$decision=followthrough_decide_v1($root,'discuss','Tell me more about that');$outcome=$decision['initiative_followthrough'];
followthrough_check($outcome['state']==='discussed'&&$outcome['revision']===2&&$decision['handled']
    &&str_contains($decision['advisory_context']['proposal_text'],'Product image readiness')
    &&!empty($decision['advisory_context']['verified_sources']),
    'an exact owner reply records discussion and supplies verified proposal facts for a natural response');
followthrough_check((int)db()->query("SELECT COUNT(*) FROM agent_workflow_events WHERE event_type='initiative_owner_decision'")->fetchColumn()===1,
    'the decision is an event on the existing run without a second work queue');
vp3_initiative_followthrough_current_v1(db(),$one,scope_principal($one,17),170,$outcome);
$q=db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','A natural explanation.',?)");
$q->execute([json_encode(['initiative_followthrough'=>$outcome],JSON_THROW_ON_ERROR)]);
vp3_initiative_followthrough_current_v1(db(),$one,scope_principal($one,17),170,$outcome);
$cached=['conversation_id'=>170,'agent_id'=>17,'initiative_followthrough'=>$outcome,'answer'=>'PRIVATE ACK'];
$input=['conversation_id'=>170,'message'=>'Tell me more about that','initiative_reply'=>followthrough_ref_v1($root)];
vp3_initiative_followthrough_replay_v1(db(),$one,scope_principal($one,17),$input,$cached);
followthrough_check(true,'the same bound reply and its own saved assistant completion can be replayed safely');
vp3_initiative_followthrough_replay_v1(db(),$one,scope_principal($one,17),array_replace($input,['message'=>'  Tell me more about that  ']),$cached);
followthrough_check(true,'replay uses the same edge-trimmed owner query as the typed and voice entrypoints');
followthrough_reject(fn()=>vp3_initiative_followthrough_replay_v1(db(),$one,scope_principal($one,17),array_diff_key($input,['initiative_reply'=>true]),$cached),409,
    'an explicit cached association cannot be dropped from a same-key replay');
followthrough_reject(fn()=>vp3_initiative_followthrough_replay_v1(db(),$one,scope_principal($one,17),array_replace($input,['conversation_id'=>171]),$cached),403,'replay cannot move private text into a sibling Chat');
followthrough_reject(fn()=>vp3_initiative_followthrough_replay_v1(db(),$one,scope_principal($one,17),array_replace($input,['message'=>'Different request']),$cached),409,'the same voice idempotency key cannot replay another owner request');
followthrough_owner_turn_v1('A new unrelated turn');
followthrough_reject(fn()=>vp3_initiative_followthrough_current_v1(db(),$one,scope_principal($one,17),170,$outcome),409,'an intervening owner turn fences the prior natural response');
followthrough_reject(fn()=>vp3_initiative_followthrough_load_v1(db(),$two,scope_principal($two,19),190,$rootMessageId),409,'another owner cannot load the original private proposal');
followthrough_reject(fn()=>vp3_initiative_followthrough_load_v1(db(),$one,scope_principal($one,18),180,$rootMessageId),409,'a sibling Agent cannot borrow the proposal');

$root=followthrough_seed_v1('Scoped private continuation');$before=count(scope_runs(1,17,'initiative.prepare'));
$advance=followthrough_decide_v1($root,'advance');$queued=$advance['initiative_followthrough'];
followthrough_check($queued['state']==='queued'&&$queued['continuation_run_id']>0
    &&count(scope_runs(1,17,'initiative.prepare'))===$before+1,
    'advance enqueues one real private continuation through the existing Initiative queue');
$q=db()->prepare('SELECT goal FROM agent_workflow_runs WHERE id=?');$q->execute([$queued['continuation_run_id']]);$goal=json_decode($q->fetchColumn(),true);
followthrough_check($goal['tool']==='work.prepare_private'&&$goal['sources']===$root['goal']['sources']
    &&$goal['followthrough']['root_run_id']===(int)$root['run']['id'],
    'the continuation retains exact root/source identity and grants no domain action');
$done=vp3_initiative_execute_v1(db(),$one,scope_claim($one,$queued['continuation_run_id']),static fn()=>json_encode(['draft'=>'Verified private analysis. No image, purchase or publication was performed.'],JSON_THROW_ON_ERROR));
$loaded=vp3_initiative_followthrough_load_v1(db(),$one,scope_principal($one,17),170,(int)$root['message']['id']);
followthrough_check($done['ok']&&$loaded['state']['state']==='completed',
    'canonical successful continuation receipt advances the original conversation item to private completion');
$fresh=vp3_initiative_followthrough_refresh_v1(db(),$one,scope_principal($one,17),170,$queued);
followthrough_check(is_array($fresh)&&$fresh['state']==='completed'&&$fresh['revision']===$queued['revision'],
    'only actual same-decision receipt completion can refresh a queued natural acknowledgment');
$q=db()->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$queued['continuation_run_id']]);$actionResult=$q->fetchColumn();
$q=db()->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=? AND status=\'completed\'');$q->execute([$queued['continuation_run_id']]);
followthrough_check($actionResult===$q->fetchColumn(),'the real continuation action and successful receipt retain the same verified result');
db()->prepare('DELETE FROM agent_workflow_receipts WHERE run_id=?')->execute([$queued['continuation_run_id']]);
followthrough_reject(fn()=>vp3_initiative_followthrough_load_v1(db(),$one,scope_principal($one,17),170,(int)$root['message']['id']),409,
    'completion cannot be claimed after its actual canonical continuation receipt disappears');

$root=followthrough_seed_v1('Owner changes their mind');$queued=followthrough_decide_v1($root,'advance')['initiative_followthrough'];
$latest=vp3_initiative_followthrough_load_v1(db(),$one,scope_principal($one,17),170,(int)$root['message']['id']);
$defer=followthrough_decide_v1($latest,'defer','Not now');
followthrough_check($defer['initiative_followthrough']['state']==='deferred'&&$defer['advisory_context']['deferred_without_schedule'],
    'deferring queued work changes only the proposal and does not create a reminder');
$calls=0;$cancelled=vp3_initiative_execute_v1(db(),$one,scope_claim($one,$queued['continuation_run_id']),static function()use(&$calls):string{++$calls;return '{"draft":"Should never run"}';});
followthrough_check(!$cancelled['ok']&&$calls===0,'a changed owner decision fences the old child before paid inference');

$root=followthrough_seed_v1('Keep working during a status question');
$longRequest='Yes, prepare a private analysis. '.str_repeat('Keep the product scope private and source-backed. ',10).' UNIQUE_OWNER_DETAIL_BEYOND_300';
$queued=followthrough_decide_v1($root,'advance',$longRequest)['initiative_followthrough'];
db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','The private step is queued.',?)")
    ->execute([json_encode(['initiative_followthrough'=>$queued],JSON_THROW_ON_ERROR)]);
$statusId=followthrough_owner_turn_v1('How is that going?');
$status=vp3_initiative_followthrough_reply_v1(db(),$one,scope_principal($one,17),170,$statusId,null,'How is that going?',null,
    static fn()=>json_encode(['intent'=>'discuss','focus'=>'Explain the current progress.'],JSON_THROW_ON_ERROR));
followthrough_check($status['initiative_followthrough']['state']==='queued'&&$status['initiative_followthrough']['continuation_run_id']===$queued['continuation_run_id']
    &&$status['initiative_followthrough']['revision']===$queued['revision']+1,
    'implicit discussion after a canonical natural acknowledgment preserves the active private continuation');
$sawOwnerDetail=false;
$completed=vp3_initiative_execute_v1(db(),$one,scope_claim($one,$queued['continuation_run_id']),static function(string $prompt)use(&$sawOwnerDetail):string{
    $request=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);$sawOwnerDetail=str_contains($request['owner_continuation']['owner_reply']??'','UNIQUE_OWNER_DETAIL_BEYOND_300');
    return '{"draft":"A verified private result after the owner asked for progress."}';
});
followthrough_check($completed['ok']&&$sawOwnerDetail,
    'actual worker generation retains rich verified owner directions beyond the classifier focus and still completes after a status discussion');

$root=followthrough_seed_v1('Implicit cancellation after acknowledgment');$queued=followthrough_decide_v1($root,'advance')['initiative_followthrough'];
db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','The private next step is queued.',?)")
    ->execute([json_encode(['initiative_followthrough'=>$queued],JSON_THROW_ON_ERROR)]);
$laterId=followthrough_owner_turn_v1('Actually, later');
$later=vp3_initiative_followthrough_reply_v1(db(),$one,scope_principal($one,17),170,$laterId,null,'Actually, later',null,
    static fn()=>'{"intent":"defer","focus":"Set this proposal aside without creating a reminder."}');
followthrough_check($later['initiative_followthrough']['state']==='deferred'&&$later['advisory_context']['deferred_without_schedule'],
    'an immediate natural later reply follows exact saved acknowledgment metadata back to its proposal');
$calls=0;$stopped=vp3_initiative_execute_v1(db(),$one,scope_claim($one,$queued['continuation_run_id']),static function()use(&$calls):string{++$calls;return '{"draft":"No"}';});
followthrough_check(!$stopped['ok']&&$calls===0,'implicit later after acknowledgment withholds the old private continuation before inference');

$root=followthrough_seed_v1('A failed private step can be explicitly retried');
$queued=followthrough_decide_v1($root,'advance')['initiative_followthrough'];
$failed=vp3_initiative_execute_v1(db(),$one,scope_claim($one,$queued['continuation_run_id']),static function():string{throw new RuntimeException('model_unavailable');});
$failedRoot=vp3_initiative_followthrough_load_v1(db(),$one,scope_principal($one,17),170,(int)$root['message']['id']);
followthrough_check(!$failed['ok']&&$failedRoot['state']['state']==='stale'
    &&$failedRoot['continuation']['status']==='failed'&&$failedRoot['state']['revision']===$queued['revision']+1,
    'an actual terminal private-job failure withdraws the pending-work claim rather than leaving the proposal queued forever');
$liveFailed=vp3_initiative_followthrough_updates_v1(db(),$one,scope_principal($one,17),170,[(int)$root['message']['id']]);
followthrough_check($liveFailed[0]['available']&&$liveFailed[0]['recoverable']&&$liveFailed[0]['initiative_followthrough']['state']==='stale',
    'the existing Chat activity read can expose a valid failed root as recoverable without a new Chat message');
$before=count(scope_runs(1,17,'initiative.prepare'));
$retry=followthrough_decide_v1($failedRoot,'advance','Retry that private analysis')['initiative_followthrough'];
followthrough_check($retry['state']==='queued'&&$retry['continuation_run_id']!==$queued['continuation_run_id']
    &&count(scope_runs(1,17,'initiative.prepare'))===$before+1,
    'a fresh explicit owner retry creates exactly one new source-bound private continuation after terminal failure');
followthrough_reject(fn()=>followthrough_decide_v1($failedRoot,'advance','Retry again'),409,
    'a second tab cannot reuse the failed proposal revision to queue another retry');
$recovered=vp3_initiative_execute_v1(db(),$one,scope_claim($one,$retry['continuation_run_id']),static fn()=>'{"draft":"The explicitly retried private analysis completed with verified sources."}');
$recoveredRoot=vp3_initiative_followthrough_load_v1(db(),$one,scope_principal($one,17),170,(int)$root['message']['id']);
followthrough_check($recovered['ok']&&$recoveredRoot['state']['state']==='completed'
    &&$recoveredRoot['state']['continuation_run_id']===$retry['continuation_run_id'],
    'the explicit retry finishes through its own canonical action and successful receipt');

$root=followthrough_seed_v1('A failed metadata hook cannot leave a false active-work claim');$queued=followthrough_decide_v1($root,'advance')['initiative_followthrough'];
$rootId=(int)$root['message']['id'];
db()->exec("CREATE TRIGGER followthrough_test_metadata_lock BEFORE UPDATE OF context_json ON chat_messages
    WHEN OLD.id=".$rootId." AND json_extract(NEW.context_json,'$.initiative_followthrough_state.state')='stale'
    BEGIN SELECT RAISE(ABORT,'simulated optional metadata hook lock timeout'); END");
$failed=vp3_initiative_execute_v1(db(),$one,scope_claim($one,$queued['continuation_run_id']),static function():string{throw new RuntimeException('model_unavailable');});
db()->exec('DROP TRIGGER followthrough_test_metadata_lock');
$unchangedRoot=vp3_initiative_followthrough_load_v1(db(),$one,scope_principal($one,17),170,$rootId);
$before=(int)db()->query('SELECT total_changes()')->fetchColumn();db()->exec('PRAGMA query_only=ON');
$actualFailure=vp3_initiative_followthrough_updates_v1(db(),$one,scope_principal($one,17),170,[$rootId]);
$advisory=vp3_initiative_followthrough_current_v1(db(),$one,scope_principal($one,17),170,vp3_initiative_followthrough_outcome_v1($unchangedRoot));
db()->exec('PRAGMA query_only=OFF');
followthrough_check(!$failed['ok']&&$unchangedRoot['state']['state']==='queued'&&$unchangedRoot['state']['revision']===$queued['revision']
    &&$actualFailure[0]['recoverable']&&$actualFailure[0]['child_execution_state']==='failed'
    &&$advisory['child_execution_state']==='failed'&&(int)db()->query('SELECT total_changes()')->fetchColumn()===$before,
    'an actual optional hook failure is shown as canonical interrupted work without GET mutation or an invented decision revision');
$q=db()->prepare("SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? AND status='failed'");$q->execute([$queued['continuation_run_id']]);$proof=$q->fetch(PDO::FETCH_ASSOC);
db()->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute(['null',$proof['id']]);
followthrough_reject(fn()=>vp3_initiative_followthrough_load_v1(db(),$one,scope_principal($one,17),170,$rootId),409,
    'a malformed or JSON-null failure result cannot masquerade as canonical SQL-null receipt proof');
db()->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$proof['result_json'],$proof['id']]);
$retry=followthrough_decide_v1($unchangedRoot,'advance','Retry that private step')['initiative_followthrough'];
followthrough_check($retry['continuation_run_id']!==$queued['continuation_run_id']&&$retry['state']==='queued',
    'a fresh owner retry works even when the terminal-failure metadata hook could not update the original queued phase');

$root=followthrough_seed_v1('Source changes during interpretation');
followthrough_reject(fn()=>followthrough_decide_v1($root,'advance','Yes',static function():void{db()->exec("UPDATE initiative_scope_calendar SET title='Changed source during intent' WHERE owner_id=1");}),409,
    'source changes during the decision model prevent an obsolete continuation');
$unrelatedId=followthrough_owner_turn_v1('Show my reminders');$calls=0;
$unrelated=vp3_initiative_followthrough_reply_v1(db(),$one,scope_principal($one,17),170,$unrelatedId,null,'Show my reminders',null,
    static function()use(&$calls):string{++$calls;return '{"intent":"none","focus":""}';});
followthrough_check(!$unrelated['handled']&&$calls===0,'a stale old proposal cannot block or add inference to a clearly unrelated new Chat request');
$root=followthrough_seed_v1('Explicit Pause stays paused');scope_command($one,17,170,'off');
$paused=followthrough_decide_v1($root,'advance');
followthrough_check($paused['initiative_followthrough']['state']==='discussed'&&$paused['initiative_followthrough']['continuation_run_id']===0
    &&$paused['advisory_context']['continuation_blocked']!==''&&!vp3_initiative_policy_v1($one,17)['enabled'],
    'an explicit reply can discuss a valid old post while Pause blocks new background work without resuming it');
$root=followthrough_seed_v1('Concurrent owner turn during intent');
followthrough_reject(fn()=>followthrough_decide_v1($root,'discuss','Tell me more',static fn()=>followthrough_owner_turn_v1('A newer turn wins')),409,
    'a concurrent newer owner turn wins before a stale decision commits');
$root=followthrough_seed_v1('A concurrent assistant approval wins before classification commit');$before=count(scope_runs(1,17,'initiative.prepare'));
followthrough_reject(fn()=>followthrough_decide_v1($root,'advance','Yes, please',static function():void{
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','An unrelated tool awaits approval.',?)")
        ->execute([json_encode(['task_context'=>['pending_token'=>'unrelated-approval']],JSON_THROW_ON_ERROR)]);
}),409,'a competing assistant or pending approval during intent blocks the stale decision before any private enqueue');
$stillOpen=vp3_initiative_followthrough_load_v1(db(),$one,scope_principal($one,17),170,(int)$root['message']['id']);
followthrough_check($stillOpen['state']['state']==='open'&&$stillOpen['state']['revision']===1&&count(scope_runs(1,17,'initiative.prepare'))===$before,
    'an assistant-tail classification race leaves no consumed proposal revision, decision event or extra private child');
$root=followthrough_seed_v1('Caller actor authority changes during intent');$query='Yes';$replyId=followthrough_owner_turn_v1($query);$allowed=true;
$before=count(scope_runs(1,17,'initiative.prepare'));
$authority=static function()use(&$allowed):void{if(!$allowed)throw new RuntimeException('The original actor authority changed.',403);};
followthrough_reject(static function()use($one,$replyId,$root,$query,$authority,&$allowed):array{
    return vp3_initiative_followthrough_reply_v1(db(),$one,scope_principal($one,17),170,$replyId,followthrough_ref_v1($root),$query,$authority,
        static function()use(&$allowed):string{$allowed=false;return '{"intent":"advance","focus":"Prepare the private next step."}';});
},403,
    'the original caller actor fence is rechecked after intent inference before an effect');
$unchanged=vp3_initiative_followthrough_load_v1(db(),$one,scope_principal($one,17),170,(int)$root['message']['id']);
followthrough_check($unchanged['state']['state']==='open'&&$unchanged['state']['revision']===1&&count(scope_runs(1,17,'initiative.prepare'))===$before,
    'a revoked actor cannot enqueue a private child or consume the proposal revision');
$root=followthrough_seed_v1('Duplicate old revision');$decision=followthrough_decide_v1($root,'decline','No thanks');
followthrough_check($decision['initiative_followthrough']['state']==='declined','decline records this suggestion without completing the source task');
followthrough_reject(fn()=>followthrough_decide_v1($root,'advance'),409,'a duplicate or second tab using the consumed proposal revision cannot enqueue work');
$root=followthrough_seed_v1('Owner reports completion');$resolved=followthrough_decide_v1($root,'resolve','Already handled');
followthrough_check($resolved['initiative_followthrough']['state']==='owner_resolved'&&$resolved['advisory_context']['owner_reported_resolution']
    &&!$resolved['advisory_context']['verified_private_completion'],'owner-reported resolution is distinct from verified private or business completion');

$projectionRoot=followthrough_seed_v1('Read-only live proposal metadata');$foreignRoot=followthrough_seed_v1('Sibling Agent metadata',18,180);
$before=(int)db()->query('SELECT total_changes()')->fetchColumn();db()->exec('PRAGMA query_only=ON');
$projection=vp3_initiative_followthrough_updates_v1(db(),$one,scope_principal($one,17),170,
    [(int)$projectionRoot['message']['id'],(int)$foreignRoot['message']['id'],999999]);
followthrough_check($projection[0]['available']&&$projection[0]['initiative_followthrough']===vp3_initiative_followthrough_outcome_v1($projectionRoot)
    &&!str_contains(json_encode($projection),'Read-only live proposal metadata')&&(int)db()->query('SELECT total_changes()')->fetchColumn()===$before,
    'live proposal updates expose bounded identifiers and current state without history text, inference or any database write');
followthrough_check($projection[1]===['message_id'=>(int)$foreignRoot['message']['id'],'available'=>false]
    &&$projection[2]===['message_id'=>999999,'available'=>false],
    'foreign, missing and noncanonical requested IDs have the same content-free unavailable shape');
followthrough_reject(fn()=>vp3_initiative_followthrough_updates_v1(db(),$one,scope_principal($one,17),170,['1']),422,'live proposal update IDs retain strict JSON integer types');
followthrough_reject(fn()=>vp3_initiative_followthrough_updates_v1(db(),$one,scope_principal($one,17),170,range(1,13)),422,'live proposal update batches cannot exceed twelve explicit IDs');
followthrough_reject(fn()=>vp3_initiative_followthrough_updates_v1(db(),$one,scope_principal($one,17),170,[1,1]),422,'duplicate update IDs cannot multiply source verification work');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>17,'participant'=>8,'revision'=>1];
followthrough_reject(fn()=>vp3_initiative_followthrough_updates_v1(db(),$one,scope_principal($one,17),170,[(int)$projectionRoot['message']['id']]),403,
    'a real participant scope cannot obtain private proposal availability or recovery metadata');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);db()->exec('PRAGMA query_only=OFF');
$title=db()->query('SELECT title FROM initiative_scope_calendar WHERE owner_id=1')->fetchColumn();
db()->exec("UPDATE initiative_scope_calendar SET title='Changed before live metadata read' WHERE owner_id=1");
$withdrawn=vp3_initiative_followthrough_updates_v1(db(),$one,scope_principal($one,17),170,[(int)$projectionRoot['message']['id']]);
followthrough_check($withdrawn===[['message_id'=>(int)$projectionRoot['message']['id'],'available'=>false]],
    'a changed or revoked source disables the old proposal without copying private source content into the response');
db()->prepare('UPDATE initiative_scope_calendar SET title=? WHERE owner_id=1')->execute([$title]);

$root=followthrough_seed_v1('Implicit retry keeps its original owner request');$query='Yes, please';$ref=followthrough_ref_v1($root);
$before=(int)db()->query('SELECT total_changes()')->fetchColumn();db()->exec('PRAGMA query_only=ON');
$request=vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,'  '.$query.'  ');
db()->exec('PRAGMA query_only=OFF');
followthrough_check($request['user_message_id']===0&&$request['initiative_reply']===$ref&&!$request['explicit']
    &&$request['query_hash']===hash('sha256',$query)&&(int)db()->query('SELECT total_changes()')->fetchColumn()===$before,
    'normal implicit-reply preflight uses exact canonical adjacency and trimmed query identity without writes or inference');
$mid=followthrough_owner_turn_v1($query);$sawSavedAnchor=false;
followthrough_reject(static function()use($one,$mid,$query,&$sawSavedAnchor):array{
    return vp3_initiative_followthrough_reply_v1(db(),$one,scope_principal($one,17),170,$mid,null,$query,null,
        static function()use($mid,&$sawSavedAnchor):string{
            $q=db()->prepare('SELECT context_json FROM chat_messages WHERE id=?');$q->execute([$mid]);$context=json_decode($q->fetchColumn(),true);
            $sawSavedAnchor=!db()->inTransaction()&&($context['initiative_reply_anchor_v1']['user_message_id']??null)===$mid;
            throw new RuntimeException('The provider temporarily failed.',503);
        });
},503,'an intent-provider failure leaves one immutable server-anchored owner turn');
$recovered=vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,$query);
followthrough_check($sawSavedAnchor&&$recovered['user_message_id']===$mid&&$recovered['initiative_reply']===$ref,
    'retry after a failed implicit classifier recovers the same saved owner message and original proposal revision');
followthrough_check(vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,'Show my reminders')===null,
    'a different ordinary request never recovers a failed implicit turn');
$prior=db()->query('SELECT title FROM initiative_scope_calendar WHERE owner_id=1')->fetchColumn();
db()->exec("UPDATE initiative_scope_calendar SET title='Revoked before implicit retry' WHERE owner_id=1");
followthrough_reject(fn()=>vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,$query),409,
    'a saved server request anchor cannot bypass fresh source validation on retry');
db()->prepare('UPDATE initiative_scope_calendar SET title=? WHERE owner_id=1')->execute([$prior]);
$decision=vp3_initiative_followthrough_reply_v1(db(),$one,scope_principal($one,17),170,$mid,null,$query,null,static fn()=>'{"intent":"advance","focus":"Prepare the privately approved next analysis."}');
$outcome=$decision['initiative_followthrough'];
followthrough_check($outcome['reply_message_id']===$mid&&$outcome['state']==='queued',
    'the recovered saved implicit turn commits its one actual canonical continuation');
db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','The private continuation is queued.',?)")
    ->execute([json_encode(['initiative_followthrough'=>$outcome],JSON_THROW_ON_ERROR)]);
$again=vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,$query);
followthrough_check($again===$recovered&&$again['initiative_reply']['revision']===1,
    'a lost successful implicit acknowledgment recovers its original request anchor rather than classifying the updated proposal again');
$newRef=['message_id'=>$outcome['message_id'],'reply_ref'=>$outcome['reply_ref'],'revision'=>$outcome['revision']];
$newExplicit=vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,$query,null,$newRef);
followthrough_check($newExplicit['user_message_id']===0&&$newExplicit['explicit']&&$newExplicit['initiative_reply']===$newRef,
    'explicitly selecting the current revision creates a new request even if its text matches the last implicit reply');
$cached=['conversation_id'=>170,'agent_id'=>17,'initiative_followthrough'=>$outcome,'answer'=>'Only the original implicit answer.'];
vp3_initiative_followthrough_replay_v1(db(),$one,scope_principal($one,17),['conversation_id'=>170,'message'=>$query],$cached);
followthrough_check(true,'the recovered implicit identity can replay only its current same-query canonical answer');
db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','A different reviewed tool needs confirmation.',?)")
    ->execute([json_encode(['task_context'=>['pending_token'=>'a-different-approval']],JSON_THROW_ON_ERROR)]);
followthrough_check(vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,$query)===null,
    'an unrelated newer assistant approval is not treated as recovery of the old same-text Initiative request');
followthrough_owner_turn_v1('A genuinely new ordinary user turn');
followthrough_reject(fn()=>vp3_initiative_followthrough_anchor_v1(db(),$one,scope_principal($one,17),170,$mid),409,
    'direct saved-anchor recovery also fences a newer ordinary turn before provider work');
followthrough_check(vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,$query)===null,
    'an intervening new ordinary turn prevents old implicit request recovery');

$root=followthrough_seed_v1('A killed owner insert without its anchor remains terminal');$query='Yes, please';$ref=followthrough_ref_v1($root);
$mid=followthrough_owner_turn_v1($query);$before=(int)db()->query('SELECT total_changes()')->fetchColumn();$runs=count(scope_runs(1,17,'initiative.prepare'));
followthrough_reject(fn()=>vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,$query),409,
    'a latest exact implicit owner turn without its trusted DB anchor fails closed after canonical Initiative adjacency');
followthrough_reject(fn()=>vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,$query,null,$ref),409,
    'an explicit request also refuses to reconstruct a missing saved-user DB anchor');
followthrough_check((int)db()->query('SELECT total_changes()')->fetchColumn()===$before&&count(scope_runs(1,17,'initiative.prepare'))===$runs,
    'missing-anchor denials create no replacement owner message, request anchor, decision or child job');

$root=followthrough_seed_v1('Explicit request anchors survive a killed classifier');$ref=followthrough_ref_v1($root);$mid=followthrough_owner_turn_v1('Tell me more');
followthrough_reject(fn()=>vp3_initiative_followthrough_reply_v1(db(),$one,scope_principal($one,17),170,$mid,$ref,'Tell me more',null,
    static function():string{throw new RuntimeException('Stopped transport.',503);}),503,'explicit replies also save trusted request identity before provider work');
$anchor=vp3_initiative_followthrough_anchor_v1(db(),$one,scope_principal($one,17),170,$mid);
followthrough_check($anchor['explicit']&&$anchor['initiative_reply']===$ref
    &&vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,'Tell me more',null,$ref)===$anchor,
    'explicit failed-process recovery binds exact original reference, owner turn, principal and query');
followthrough_reject(fn()=>vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,'Tell me more'),409,
    'dropping an explicit reference cannot recover the old private request as an implicit one');

$root=followthrough_seed_v1('An unrelated inferred request retains ordinary tools');$query='Tell me more about that later but first inspect capabilities';$mid=followthrough_owner_turn_v1($query);
$unrelated=vp3_initiative_followthrough_reply_v1(db(),$one,scope_principal($one,17),170,$mid,null,$query,null,
    static fn()=>'{"intent":"none","focus":""}');
$q=db()->prepare('SELECT context_json FROM chat_messages WHERE id=?');$q->execute([$mid]);$context=json_decode($q->fetchColumn(),true);
followthrough_check(!$unrelated['handled']&&!isset($context['initiative_reply_anchor_v1'])
    &&vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,$query)===null,
    'intent-none ordinary dispatch removes its tentative request anchor so future retries cannot replay ordinary effects as Initiative');

foreach([false,true] as $explicitReplay){
    $root=followthrough_seed_v1('Historical queued acknowledgment after actual completion '.($explicitReplay?'explicit':'implicit'));
    $query='Yes, please';$originalRef=followthrough_ref_v1($root);$ownerMid=followthrough_owner_turn_v1($query);
    $decision=vp3_initiative_followthrough_reply_v1(db(),$one,scope_principal($one,17),170,$ownerMid,$explicitReplay?$originalRef:null,$query,null,
        static fn()=>'{"intent":"advance","focus":"Prepare a bounded private analysis."}');
    $old=$decision['initiative_followthrough'];$body='The owner-selected private analysis is queued.';
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant',?,?)")
        ->execute([$body,json_encode(['initiative_followthrough'=>$old],JSON_THROW_ON_ERROR)]);$ackMid=(int)db()->lastInsertId();
    $cached=['ok'=>true,'conversation_id'=>170,'agent_id'=>17,'user_message_id'=>$ownerMid,'assistant_message_id'=>$ackMid,'answer'=>$body,'initiative_followthrough'=>$old];
    $input=['conversation_id'=>170,'message'=>$query]+($explicitReplay?['initiative_reply'=>$originalRef]:[]);
    $executed=vp3_initiative_execute_v1(db(),$one,scope_claim($one,$old['continuation_run_id']),static fn()=>'{"draft":"The exact owner-selected private analysis has now completed."}');
    $complete=vp3_initiative_followthrough_load_v1(db(),$one,scope_principal($one,17),170,(int)$root['message']['id']);
    $childMid=vp3_initiative_followthrough_id_v1($complete['continuation_result']['initiative_message_id']??null);
    followthrough_check($executed['ok']&&$childMid>$ackMid,'the replay fixture has an actual completed child receipt and saved result after its historical queued acknowledgment');
    followthrough_reject(fn()=>vp3_initiative_followthrough_replay_v1(db(),$one,scope_principal($one,17),$input,$cached),409,
        'ordinary replay remains strict when the historical queued answer predates actual private completion');
    $before=(int)db()->query('SELECT total_changes()')->fetchColumn();db()->exec('PRAGMA query_only=ON');
    $fresh=vp3_initiative_followthrough_replay_refresh_v1(db(),$one,scope_principal($one,17),$input,$cached);
    $preflight=vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,$query,null,$explicitReplay?$originalRef:null);
    db()->exec('PRAGMA query_only=OFF');
    followthrough_check($fresh===array_replace($cached,['initiative_followthrough'=>array_replace($old,['state'=>'completed'])])
        &&($preflight['replay_only']??false)&&$preflight['user_message_id']===$ownerMid&&$preflight['initiative_reply']===$originalRef
        &&(int)db()->query('SELECT total_changes()')->fetchColumn()===$before,
        'the separate completion reader preserves the saved answer and IDs, advances only verified metadata, and exposes replay-only identity without writes');
    followthrough_reject(fn()=>vp3_initiative_followthrough_current_v1(db(),$one,scope_principal($one,17),170,$fresh['initiative_followthrough']),409,
        'the completion cache reader does not loosen the normal fresh-response tail authority');
    followthrough_check(vp3_initiative_followthrough_replay_refresh_v1(db(),$one,scope_principal($one,17),$input,array_replace($cached,['assistant_message_id'=>$childMid]))===null,
        'a cached answer cannot substitute the child result message for its exact saved acknowledgment');
    followthrough_check(vp3_initiative_followthrough_replay_refresh_v1(db(),$one,scope_principal($one,17),$input,array_replace($cached,['answer'=>'Altered acknowledgment text']))===null,
        'a cached completion replay must match the exact stored acknowledgment body');
    followthrough_reject(fn()=>vp3_initiative_followthrough_replay_refresh_v1(db(),$one,scope_principal($one,17),array_replace($input,['message'=>'A different request']),$cached),409,
        'completed historical replay cannot reuse the same cached answer for another owner query');
    followthrough_reject(fn()=>vp3_initiative_followthrough_replay_refresh_v1(db(),$one,scope_principal($one,17),array_replace($input,['conversation_id'=>171]),$cached),403,
        'completed historical replay remains bound to its exact saved Chat');
    $changedAssoc=$explicitReplay?array_diff_key($input,['initiative_reply'=>true]):$input+['initiative_reply'=>$originalRef];
    followthrough_reject(fn()=>vp3_initiative_followthrough_replay_refresh_v1(db(),$one,scope_principal($one,17),$changedAssoc,$cached),409,
        'completed historical replay cannot add or remove explicit association');
    $q=db()->prepare('SELECT message FROM chat_messages WHERE id=?');$q->execute([$childMid]);$childBody=$q->fetchColumn();
    db()->prepare('UPDATE chat_messages SET message=? WHERE id=?')->execute(['Changed child private result',$childMid]);
    followthrough_check(vp3_initiative_followthrough_replay_refresh_v1(db(),$one,scope_principal($one,17),$input,$cached)===null,
        'the saved child result must still match the exact canonical completed receipt draft');
    db()->prepare('UPDATE chat_messages SET message=? WHERE id=?')->execute([$childBody,$childMid]);
    $authorityCalls=0;$racingMid=0;
    followthrough_reject(static function()use($one,$input,$cached,&$authorityCalls,&$racingMid):?array{
        return vp3_initiative_followthrough_replay_refresh_v1(db(),$one,scope_principal($one,17),$input,$cached,
            static function()use(&$authorityCalls,&$racingMid):void{if(++$authorityCalls===4)$racingMid=followthrough_owner_turn_v1('A new owner request during completed replay validation');});
    },409,'a newer ordinary owner turn during the final completion validation cannot escape the final exact-tail recheck');
    if($racingMid>0)db()->prepare('DELETE FROM chat_messages WHERE id=?')->execute([$racingMid]);
    $q=db()->prepare("SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? AND status='completed'");$q->execute([$old['continuation_run_id']]);$childReceipt=$q->fetch(PDO::FETCH_ASSOC);
    db()->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute(['{"effect_state":"private_draft_ready","initiative_draft":"Forged receipt"}',$childReceipt['id']]);
    followthrough_reject(fn()=>vp3_initiative_followthrough_replay_refresh_v1(db(),$one,scope_principal($one,17),$input,$cached),409,
        'completed historical replay cannot survive a changed actual child receipt');
    db()->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$childReceipt['result_json'],$childReceipt['id']]);
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','Copied outcome metadata is not a canonical answer.',?)")
        ->execute([json_encode(['initiative_followthrough'=>$old],JSON_THROW_ON_ERROR)]);$extra=(int)db()->lastInsertId();
    followthrough_check(vp3_initiative_followthrough_replay_refresh_v1(db(),$one,scope_principal($one,17),$input,$cached)===null,
        'an additional assistant even with copied matching metadata is excluded from completed cache replay');
    followthrough_reject(fn()=>vp3_initiative_followthrough_request_v1(db(),$one,scope_principal($one,17),170,$query,null,$explicitReplay?$originalRef:null),409,
        'the replay-only preflight cannot bypass an extra conversation turn');
    db()->prepare('DELETE FROM chat_messages WHERE id=?')->execute([$extra]);
    $priorTitle=db()->query('SELECT title FROM initiative_scope_calendar WHERE owner_id=1')->fetchColumn();
    db()->exec("UPDATE initiative_scope_calendar SET title='Changed before historical replay' WHERE owner_id=1");
    followthrough_reject(fn()=>vp3_initiative_followthrough_replay_refresh_v1(db(),$one,scope_principal($one,17),$input,$cached),409,
        'historical completion replay still revalidates current private source revisions');
    db()->prepare('UPDATE initiative_scope_calendar SET title=? WHERE owner_id=1')->execute([$priorTitle]);
}

followthrough_check(!$pdo->inTransaction(),'all model, success and denial paths release transaction ownership');
echo 'AGENT_INITIATIVE_FOLLOWTHROUGH_V1=PASS ('.$followthroughChecks.' cases)'.PHP_EOL;
