<?php
declare(strict_types=1);

// Reuse the real catch-up/source fixture; replay validation itself uses no model or cache adapter.
require __DIR__.'/agent-catch-up-v1.php';

$checks=0;
function replay_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}
function replay_rejected(callable $call,int $status): bool
{
    try{$call();}
    catch(RuntimeException $error){
        return $error->getCode()===$status&&!str_contains($error->getMessage(),'PRIVATE CACHED ANSWER');
    }
    return false;
}

$owner=['id'=>1,'is_active'=>1];$system=user_agent_principal_v236($owner,null);
$pdo->exec('INSERT INTO chat_conversations VALUES(52,1,7)');
$at=gmdate('Y-m-d H:i:s',time()-600);$stamp=gmdate('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO agent_reminders_v1(id,owner_user_id,title,timezone,start_local,recurrence,delivery,next_at,status,conversation_id,created_at,updated_at)
    VALUES(900,1,'Current replay source','UTC',?,'once','chat',?,'active',50,?,?)")->execute([$at,$at,$stamp,$stamp]);
$item=array_values(array_filter(vp3_agent_catch_up_v1($pdo,$owner)['items'],static fn(array $row):bool=>$row['id']==='reminder:900'))[0];
$reference=vp3_working_context_key_v1(1,0,50,$item);
$input=['conversation_id'=>50,'working_context_ref'=>$reference,'message'=>'Help me with that'];
$cached=['ok'=>true,'conversation_id'=>50,'agent_id'=>0,'working_context_ref'=>$reference,'answer'=>'PRIVATE CACHED ANSWER'];
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$pdo->exec('PRAGMA query_only=ON');
vp3_working_context_replay_v1($pdo,$owner,$system,$input,$cached);
replay_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'unchanged exact source replay passes through production authorization without writes or inference');
vp3_working_context_replay_v1($pdo,[],[],[],['answer'=>'Ordinary non-context replay']);
replay_check(true,'ordinary replay without either contextual reference keeps its existing behavior');
vp3_working_context_replay_v1($pdo,$owner,$system,array_replace($input,['message'=>'What is the weather?']),
    array_diff_key($cached,['working_context_ref'=>true]));
replay_check(true,'an incidental card reference does not break unrelated ordinary response recovery');
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner,$system,
    array_replace($input,['message'=>'What is the weather?','working_context_ref'=>str_repeat('b',64)]),$cached),409),
    'a marked contextual response cannot use the unrelated-question exception');
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner,$system,$input,array_diff_key($cached,['working_context_ref'=>true])),409),
    'a legacy cached answer missing its reference cannot be reused for a contextual follow-up');
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner,$system,array_diff_key($input,['working_context_ref'=>true]),$cached),409),
    'an input missing the cached contextual reference cannot receive the private answer');
foreach([null,[],str_repeat('A',64),'short'] as $bad){
    replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner,$system,array_replace($input,['working_context_ref'=>$bad]),$cached),409),
        'malformed reference cannot fall through to cached private text: '.json_encode($bad));
}
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner,$system,array_replace($input,['working_context_ref'=>str_repeat('b',64)]),$cached),409),
    'reusing the same idempotency key for another valid-looking reference is rejected');
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner,$system,array_replace($input,['conversation_id'=>52]),$cached),409),
    'a different owned conversation cannot inherit the cached source answer');
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner,$system,$input,array_replace($cached,['agent_id'=>7])),403),
    'a cached named-Agent answer cannot be replayed under the System principal');
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,['id'=>2,'is_active'=>1],user_agent_principal_v236(['id'=>2],null),$input,$cached),403),
    'fresh account and conversation authority blocks another owner before replay');
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner+['denied'=>['chat.access']],$system,$input,$cached),403),
    'revoked Chat permission prevents returning a previously cached private answer');
$GLOBALS['participant_scope']=['participant_id'=>8];
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner,$system,$input,$cached),403),
    'participant presence cannot inherit owner-private cached context');
unset($GLOBALS['participant_scope']);

$named=user_agent_principal_v236($owner,['id'=>7]);
$namedItem=array_values(array_filter(vp3_agent_catch_up_v1($pdo,$owner,'all',7)['items'],static fn(array $row):bool=>$row['id']==='reminder:900'))[0];
$namedRef=vp3_working_context_key_v1(1,7,52,$namedItem);
$namedInput=['conversation_id'=>52,'working_context_ref'=>$namedRef];
$namedCached=['ok'=>true,'conversation_id'=>52,'agent_id'=>7,'working_context_ref'=>$namedRef,'answer'=>'PRIVATE CACHED ANSWER'];
vp3_working_context_replay_v1($pdo,$owner,$named,$namedInput,$namedCached);
replay_check($namedRef!==$reference,'a correct unchanged named-Agent replay uses its own exact source reference');
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner,$system,$namedInput,$namedCached),403),
    'the same owner cannot borrow a named-Agent cache through another principal');
replay_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'all replay reads and denials remain read only');

$pdo->exec('PRAGMA query_only=OFF; UPDATE agent_reminders_v1 SET title=\'Changed after the cached reply\',revision=revision+1 WHERE id=900; PRAGMA query_only=ON');
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner,$system,$input,$cached),409),
    'a source changed after the saved response fences stale replay');
$pdo->exec("PRAGMA query_only=OFF; UPDATE agent_reminders_v1 SET status='cancelled' WHERE id=900; PRAGMA query_only=ON");
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner,$named,$namedInput,$namedCached),409),
    'a revoked named-Agent source cannot release its old cached private answer');
$pdo->exec('PRAGMA query_only=OFF; UPDATE users SET is_active=0 WHERE id=1; PRAGMA query_only=ON');
replay_check(replay_rejected(fn()=>vp3_working_context_replay_v1($pdo,$owner,$system,$input,$cached),403),
    'live account deactivation is checked even when the caller identity was previously valid');
echo 'AGENT_WORKING_CONTEXT_REPLAY_V1=PASS ('.$checks.' cases)'.PHP_EOL;
