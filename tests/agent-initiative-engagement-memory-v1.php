<?php
declare(strict_types=1);

// Reuse actual authorized sources, leases, receipts and delivered Chat posts.
// In particular, do not replace the new follow-through loader with a fixture.
require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-engagement-memory-v1.php';

$memoryChecks=0;
function engagement_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    ++$GLOBALS['memoryChecks'];echo 'PASS '.$label.PHP_EOL;
}

$memoryPrincipal=scope_principal($one,17);
$memoryBinding=vp3_initiative_policy_v1($one,17);
$q=$pdo->query("SELECT * FROM chat_messages WHERE conversation_id=170 AND role='assistant' AND context_json IS NOT NULL ORDER BY id DESC LIMIT 1");
$memoryPost=$q->fetch(PDO::FETCH_ASSOC);
$memoryContext=json_decode($memoryPost['context_json'],true,32,JSON_THROW_ON_ERROR);
if(!isset($memoryContext['initiative_followthrough_state'])){
    $pdo->beginTransaction();
    vp3_initiative_owner_lock_v1($pdo,$one);
    vp3_initiative_followthrough_attach_v1($pdo,$one,17,170,(int)$memoryContext['initiative_run_id'],
        (int)$memoryPost['id'],$memoryPost['message'],$memoryContext);
    $pdo->commit();
}
$memoryLoaded=vp3_initiative_followthrough_load_v1($pdo,$one,$memoryPrincipal,170,(int)$memoryPost['id']);
engagement_check($memoryLoaded['state']['state']==='open','memory starts from a real completed and delivered workflow post');
$q=$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user',?)");
$q->execute(['Let us revisit that after the launch.']);$memoryReplyId=(int)$pdo->lastInsertId();
$ref=['message_id'=>(int)$memoryPost['id'],'reply_ref'=>$memoryLoaded['state']['reply_ref'],'revision'=>$memoryLoaded['state']['revision']];
$memoryDecision=vp3_initiative_followthrough_reply_v1($pdo,$one,$memoryPrincipal,170,$memoryReplyId,$ref,
    'Let us revisit that after the launch.',null,static fn()=>'{"intent":"defer","focus":"Revisit after the launch."}');
engagement_check(($memoryDecision['initiative_followthrough']['state']??'')==='deferred',
    'an actual owner decision is saved before it is presented as engagement memory');

$memoryBefore=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$memoryScopeBefore=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;
$memoryInferenceBefore=count($GLOBALS['scopeInference']);
$pdo->exec('PRAGMA query_only=ON');
$engagement=vp3_initiative_engagement_memory_v1($pdo,$one,$memoryBinding);
$selected=array_values(array_filter($engagement['items'],static fn(array $row):bool=>$row['message_id']===(int)$memoryPost['id']));
engagement_check($engagement['available']&&count($selected)===1&&$selected[0]['state']==='deferred'
    &&$selected[0]['owner_decision']==='defer'&&$selected[0]['owner_reply']==='Let us revisit that after the launch.',
    'the planner receives the exact earlier Agent post and linked explicit owner decision');
engagement_check(!str_contains(json_encode($engagement,JSON_THROW_ON_ERROR),'outcome_ignored')
    &&!str_contains(json_encode($engagement,JSON_THROW_ON_ERROR),'business_completed'),
    'an open or deferred item does not fabricate ignored engagement or a business outcome');
$contextWithMemory=vp3_initiative_owner_context_v1($pdo,$one,$memoryBinding);
engagement_check(($contextWithMemory['engagement']['items']??null)===$engagement['items'],
    'ordinary planning and check-ins receive the same authorized engagement projection');
$availabilityOnly=vp3_initiative_owner_context_v1($pdo,$one,$memoryBinding,false);
engagement_check(!isset($availabilityOnly['engagement'])&&$availabilityOnly['state']===$contextWithMemory['state'],
    'availability-only scheduling does not assemble engagement history under its owner lock');
engagement_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$memoryBefore
    &&count($GLOBALS['scopeInference'])===$memoryInferenceBefore
    &&($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null)===$memoryScopeBefore,
    'engagement projection performs no writes or inference and restores the caller namespace');

$foreign=vp3_initiative_engagement_memory_v1($pdo,$two,$memoryBinding);
engagement_check(!$foreign['available']&&$foreign['items']===[],
    'another account cannot inherit the owner conversation or prior questions');
$wrongAgent=vp3_initiative_engagement_memory_v1($pdo,$one,array_replace($memoryBinding,['agent_id'=>18]));
engagement_check(!$wrongAgent['available']&&$wrongAgent['items']===[],
    'a different named Agent cannot inherit this conversation memory');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>17,'participant'=>8,'revision'=>1];
try{$participantMemory=vp3_initiative_engagement_memory_v1($pdo,$one,$memoryBinding);}
finally{unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);}
engagement_check(!$participantMemory['available']&&$participantMemory['items']===[],
    'recognized participant presence does not grant private owner engagement history');
$pdo->exec('PRAGMA query_only=OFF');

$q->execute(['That discussion is finished.']);$resolvedReplyId=(int)$pdo->lastInsertId();
$resolvedPost=vp3_initiative_followthrough_load_v1($pdo,$one,$memoryPrincipal,170,(int)$memoryPost['id']);
$resolvedRef=['message_id'=>(int)$memoryPost['id'],'reply_ref'=>$resolvedPost['state']['reply_ref'],'revision'=>$resolvedPost['state']['revision']];
vp3_initiative_followthrough_reply_v1($pdo,$one,$memoryPrincipal,170,$resolvedReplyId,$resolvedRef,
    'That discussion is finished.',null,static fn()=>'{"intent":"resolve","focus":"Owner closed the conversation item."}');
$resolvedMemory=vp3_initiative_engagement_memory_v1($pdo,$one,$memoryBinding);
$resolvedItem=array_values(array_filter($resolvedMemory['items'],static fn(array $row):bool=>$row['message_id']===(int)$memoryPost['id']))[0]??[];
engagement_check(($resolvedItem['state']??'')==='owner_resolved'
    &&($resolvedItem['outcome_kind']??'')==='owner_reported_conversation_closed',
    'an actual owner closure remains explicitly self-reported rather than an executor business outcome');

$originalText=$memoryPost['message'];
$q=$pdo->prepare('UPDATE chat_messages SET message=? WHERE id=?');$q->execute(['Forged historical proposal',(int)$memoryPost['id']]);
$changed=vp3_initiative_engagement_memory_v1($pdo,$one,$memoryBinding);
engagement_check(!array_filter($changed['items'],static fn(array $row):bool=>$row['message_id']===(int)$memoryPost['id']),
    'edited prose without the original canonical receipt is excluded from memory');
$q->execute([$originalText,(int)$memoryPost['id']]);
$memoryCalendarTitle=$pdo->query('SELECT title FROM initiative_scope_calendar WHERE owner_id=1')->fetchColumn();
$pdo->exec("UPDATE initiative_scope_calendar SET title='A source changed after the earlier proposal' WHERE owner_id=1");
$revoked=vp3_initiative_engagement_memory_v1($pdo,$one,$memoryBinding);
engagement_check(!array_filter($revoked['items'],static fn(array $row):bool=>$row['message_id']===(int)$memoryPost['id']),
    'a changed underlying source removes the old proposal and linked reply from future model context');
engagement_check(vp3_initiative_engagement_text_v1('<b>Past</b> https://private.invalid/token'.chr(0),4)==='Past',
    'continuity text is bounded plain content without embedded markup, links or control bytes');
$pdo->prepare('UPDATE initiative_scope_calendar SET title=? WHERE owner_id=1')->execute([$memoryCalendarTitle]);
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,NULL,'assistant','The conversation item is closed.')");

function engagement_real_post(array $binding,array $sourceGoal,string $focus,string $draft): array
{
    $pdo=$GLOBALS['pdo'];$owner=$GLOBALS['one'];
    $payload=['binding'=>$binding,'sources'=>$sourceGoal['sources'],'canonical_sources'=>$sourceGoal['canonical_sources']??[],
        'tool'=>'work.prepare_private','primary'=>array_key_first($sourceGoal['sources']),'focus'=>$focus,'report'=>true];
    $hash=hash('sha256','engagement-memory-test|'.$focus);
    engagement_check(vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',$payload,$hash),
        'canonical engagement-memory fixture queues real private work: '.$focus);
    $q=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND dedupe_key=?');
    $q->execute([hash('sha256','initiative.prepare|'.$hash)]);$run=(int)$q->fetchColumn();
    $GLOBALS['ageReports']->execute([gmdate('Y-m-d H:i:s',time()-121)]);
    $result=vp3_initiative_execute_v1($pdo,$owner,scope_claim($owner,$run),static fn()=>json_encode(['draft'=>$draft],JSON_THROW_ON_ERROR));
    engagement_check($result['ok'],'canonical private work completes with actual receipts: '.$focus);
    $q=$pdo->prepare("SELECT * FROM chat_messages WHERE conversation_id=170 AND role='assistant' AND context_json IS NOT NULL ORDER BY id DESC LIMIT 1");
    $q->execute();return $q->fetch(PDO::FETCH_ASSOC);
}

$continuationRoot=engagement_real_post($memoryBinding,$memoryLoaded['goal'],'Prepare launch comparison',
    'Would you like a comparison of the current authorized launch evidence?');
$root=vp3_initiative_followthrough_load_v1($pdo,$one,$memoryPrincipal,170,(int)$continuationRoot['id']);
$rootRef=['message_id'=>(int)$continuationRoot['id'],'reply_ref'=>$root['state']['reply_ref'],'revision'=>$root['state']['revision']];
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user','Please compare the authorized launch evidence.')");
$advanceId=(int)$pdo->lastInsertId();
$advance=vp3_initiative_followthrough_reply_v1($pdo,$one,$memoryPrincipal,170,$advanceId,$rootRef,
    'Please compare the authorized launch evidence.',null,static fn()=>'{"intent":"advance","focus":"Compare the authorized launch evidence."}');
$child=$advance['initiative_followthrough']['continuation_run_id'];
engagement_check($child>0,'the owner-selected continuation is a real canonical workflow run');
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,NULL,'assistant','The private comparison is queued.')");
$ageReports->execute([gmdate('Y-m-d H:i:s',time()-121)]);
$childResult=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$child),static fn()=>'{"draft":"The private comparison uses the verified launch evidence."}');
engagement_check($childResult['ok'],'the same canonical worker verifies the requested private continuation');
$completedMemory=vp3_initiative_engagement_memory_v1($pdo,$one,$memoryBinding);
$completedItem=array_values(array_filter($completedMemory['items'],static fn(array $row):bool=>$row['message_id']===(int)$continuationRoot['id']))[0]??[];
engagement_check(($completedItem['state']??'')==='completed'
    &&($completedItem['outcome_kind']??'')==='private_continuation_completed',
    'planning sees the actual receipt-backed private outcome and its exact owner request');
$failedRoot=engagement_real_post($memoryBinding,$memoryLoaded['goal'],'Review interruption evidence',
    'Would you like a private comparison of the authorized interruption evidence?');
$failedLoaded=vp3_initiative_followthrough_load_v1($pdo,$one,$memoryPrincipal,170,(int)$failedRoot['id']);
$failedRef=['message_id'=>(int)$failedRoot['id'],'reply_ref'=>$failedLoaded['state']['reply_ref'],'revision'=>$failedLoaded['state']['revision']];
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user','Please prepare that private comparison.')");
$failedReply=(int)$pdo->lastInsertId();
$failedAdvance=vp3_initiative_followthrough_reply_v1($pdo,$one,$memoryPrincipal,170,$failedReply,$failedRef,
    'Please prepare that private comparison.',null,static fn()=>'{"intent":"advance","focus":"Compare the authorized interruption evidence."}');
$queuedContext=$pdo->query('SELECT context_json FROM chat_messages WHERE id='.(int)$failedRoot['id'])->fetchColumn();
$failedChild=(int)$failedAdvance['initiative_followthrough']['continuation_run_id'];
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,NULL,'assistant','The private interruption comparison is queued.')");
$failedResult=vp3_initiative_execute_v1($pdo,$one,scope_claim($one,$failedChild),static function():string{throw new RuntimeException('model_unavailable');});
engagement_check(!$failedResult['ok'],'the actual canonical worker records a terminal private continuation failure');
// Simulate the optional root bookkeeping hook being lost; leave its actual
// canonical failed action and receipt untouched. Reads must repair no rows.
$pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([$queuedContext,(int)$failedRoot['id']]);
$failedBefore=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$failedCalls=count($GLOBALS['scopeInference']);$pdo->exec('PRAGMA query_only=ON');
$failedMemory=vp3_initiative_engagement_memory_v1($pdo,$one,$memoryBinding);
$failedItem=array_values(array_filter($failedMemory['items'],static fn(array $row):bool=>$row['message_id']===(int)$failedRoot['id']))[0]??[];
engagement_check(($failedItem['state']??'')==='queued'&&($failedItem['continuation_status']??'')==='failed'
    &&($failedItem['outcome_kind']??'')==='private_continuation_failed',
    'planning distinguishes the saved queued decision from the actual failed receipt when root bookkeeping was lost');
engagement_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$failedBefore&&count($GLOBALS['scopeInference'])===$failedCalls,
    'failure projection performs no repair writes, inference or automatic retry');
$pdo->exec('PRAGMA query_only=OFF');
for($i=0;$i<6;$i++)engagement_real_post($memoryBinding,$memoryLoaded['goal'],'Bounded continuity '.$i,str_repeat('Authorized launch evidence. ',70));
$bounded=vp3_initiative_engagement_memory_v1($pdo,$one,$memoryBinding);
$chars=0;foreach($bounded['items'] as $item)$chars+=mb_strlen($item['agent_post']??'')+mb_strlen($item['owner_reply']??'');
engagement_check(count($bounded['items'])===6&&$chars<=2400,'actual canonical history is bounded to six items and 2400 content characters');
echo 'AGENT_INITIATIVE_ENGAGEMENT_MEMORY_V1=PASS ('.$memoryChecks.' cases)'.PHP_EOL;
