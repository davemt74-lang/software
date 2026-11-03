<?php
declare(strict_types=1);
define('PA3_HANDOFF_HELPERS_ONLY',true);
require __DIR__.'/profile-handoff-pa3.php';

function pa3_expire_run(array $turn): void
{
    db()->prepare("UPDATE agent_workflow_runs SET lease_expires_at='2001-01-01 00:00:00' WHERE id=?")->execute([(int)$turn['run_id']]);
}
function pa3_due_run(array $turn): void
{
    db()->prepare("UPDATE agent_workflow_runs SET next_attempt_at='2001-01-01 00:00:00' WHERE id=?")->execute([(int)$turn['run_id']]);
    db()->prepare("UPDATE agent_workflow_actions SET available_at='2001-01-01 00:00:00' WHERE id=?")->execute([(int)$turn['action_id']]);
}
function pa3_claim(array $turn): array
{
    return agent_job_claim_run_v1900(db(),pa3_owner(),(int)$turn['run_id'],'cloud',agent_worker_cloud_worker_id_v1910(1),300)??throw new RuntimeException('Expected fixture job claim.');
}

pa3_reset();$receipt=pa3_admit();$turn=pa3_turn($receipt);$claim=pa3_claim($turn);
pa3_check(count($GLOBALS['pa3ModelCalls'])===0&&pa3_turn($receipt)['state']==='agent_ready','crash after canonical claim can precede any inference');
pa3_expire_run($turn);vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);
pa3_check(count($GLOBALS['pa3ModelCalls'])===0&&pa3_turn($receipt)['state']==='agent_ready','abandoned pre-inference claim recovers with retry backoff');
pa3_due_run($turn);vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);
pa3_check(pa3_turn($receipt)['state']==='replied'&&count($GLOBALS['pa3ModelCalls'])===1,'due replacement worker completes the abandoned admission once');
$before=count($GLOBALS['pa3ModelCalls']);vp3_profile_handoff_execute_claim_pa3($pdo,pa3_owner(),$claim);
pa3_check(count($GLOBALS['pa3ModelCalls'])===$before&&pa3_count('profile_agent_messages',"sender_type='agent'")===1,'old claim cannot revive a completed replacement');

pa3_reset();$receipt=pa3_admit();$turn=pa3_turn($receipt);$claim=pa3_claim($turn);$duplicateResult=null;
$GLOBALS['pa3ModelHook']=function()use($claim,&$duplicateResult){
    pa3_check(!db()->inTransaction(),'provider work runs outside publication and owner pickup transactions');
    $duplicateResult=vp3_profile_handoff_execute_claim_pa3(db(),pa3_owner(),$claim);
};
vp3_profile_handoff_execute_claim_pa3($pdo,pa3_owner(),$claim);
pa3_check(($duplicateResult['reason']??'')==='handoff_changed'&&count($GLOBALS['pa3ModelCalls'])===1,'reentrant delivery of the same active lease cannot start a second model request');
pa3_check(pa3_count('profile_agent_messages',"sender_type='agent'")===1&&pa3_count('agent_workflow_receipts',"status='completed'")===1,'same-lease replay still produces only one answer and receipt');

pa3_reset();$receipt=pa3_admit();$turn=pa3_turn($receipt);$initialVersion=(int)$turn['version'];
$GLOBALS['pa3ModelHook']=function()use($turn){
    $GLOBALS['pa3ModelHook']=null;pa3_expire_run($turn);vp3_profile_handoff_dispatch_pa3(db(),(int)$turn['id']);pa3_due_run($turn);vp3_profile_handoff_dispatch_pa3(db(),(int)$turn['id']);
};
vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);
pa3_check(count($GLOBALS['pa3ModelCalls'])===2,'uncertain old provider response may require a replacement inference');
pa3_check(pa3_count('profile_agent_messages',"sender_type='agent'")===1&&pa3_count('agent_workflow_receipts',"status='completed'")===1&&pa3_turn($receipt)['state']==='replied','late response from expired lease cannot publish beside the replacement answer');
pa3_check((int)pa3_turn($receipt)['version']>$initialVersion+1,'lease replacement advances the persisted turn fence');

foreach(['answer','receipt'] as $failure){
    pa3_reset();$receipt=pa3_admit();$turn=pa3_turn($receipt);
    $pdo->failSql=$failure==='answer'?"INSERT INTO profile_agent_messages(conversation_id,sender_type,sender_user_id,message,context_json,created_at) VALUES(?,'agent'":'INSERT INTO agent_workflow_receipts';
    vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);$pdo->failSql='';
    pa3_check(pa3_count('profile_agent_messages',"sender_type='agent'")===0&&pa3_count('agent_workflow_receipts',"status='completed'")===0&&!(int)pa3_turn($receipt)['answer_message_id'],'failed '.$failure.' persistence rolls back public answer and completion together');
    if(pa3_turn($receipt)['state']==='agent_running')pa3_expire_run($turn);
    vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);pa3_due_run($turn);vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);
    pa3_check(pa3_count('profile_agent_messages',"sender_type='agent'")===1&&pa3_count('agent_workflow_receipts',"status='completed'")===1,'recovery after '.$failure.' failure publishes one confirmed answer');
}

pa3_reset();$receipt=pa3_admit();$turn=pa3_turn($receipt);$ownerInput=pa3_action_input($turn);
$pdo->beginTransaction();vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$ownerInput);$pdo->rollBack();
pa3_check(pa3_turn($receipt)['state']==='agent_ready'&&pa3_count('profile_agent_messages',"sender_type='owner'")===0&&pa3_count('profile_agent_handoff_operations_pa3')===0,'owner action joins caller transaction and leaves no escaped reply or replay key after rollback');
$pdo->failSql='INSERT INTO profile_agent_handoff_operations_pa3';
try{vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$ownerInput);throw new RuntimeException('Failed operation receipt was accepted');}catch(PDOException $error){$pdo->failSql='';}
pa3_check(pa3_turn($receipt)['state']==='agent_ready'&&pa3_count('profile_agent_messages',"sender_type='owner'")===0,'lost owner operation receipt rolls back ownership and public message');
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$ownerInput);
pa3_check(pa3_count('profile_agent_messages',"sender_type='owner'")===1&&pa3_count('profile_agent_handoff_operations_pa3')===1,'same owner operation can succeed after the failed transaction');

pa3_reset();pa3_presence();$receipt=pa3_admit();$turn=pa3_turn($receipt);$deliveryA=pa3_delivery($turn);
pa3_presence(['tab_id'=>str_repeat('e',32)]);$deliveryB=pa3_delivery($turn,['tab_id'=>str_repeat('e',32)]);
pa3_check($deliveryA['chat_message_id']===$deliveryB['chat_message_id']&&$deliveryA['delivery_id']!==$deliveryB['delivery_id'],'two owner tabs share one saved card and have distinct delivery bindings');
$ackA=vp3_profile_handoff_ack_pa3($pdo,pa3_owner(),pa3_ack_input($deliveryA));$deadline=$ackA['turn']['pickup_deadline_ms'];$GLOBALS['pa3Now']+=1000;
$ackB=vp3_profile_handoff_ack_pa3($pdo,pa3_owner(),pa3_ack_input($deliveryB,['tab_id'=>str_repeat('e',32)]));
pa3_check($ackB['turn']['pickup_deadline_ms']===$deadline,'second visible tab preserves the first server pickup deadline');
$fresh=pa3_turn($receipt);vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input($fresh,'resolve'));
pa3_age();$next=pa3_admit('Can we start a fresh conversation?',(int)$turn['conversation_id']);
pa3_check($next['handoff']['state']==='awaiting_delivery','new visitor message reopens resolved thread with a fresh delivery epoch');
pa3_denied(fn()=>vp3_profile_handoff_ack_pa3($pdo,pa3_owner(),pa3_ack_input($deliveryA)),'old acknowledged card cannot affect a reopened conversation epoch');

pa3_reset();$first=pa3_admit('First question');$firstTurn=pa3_turn($first);pa3_age();$second=pa3_admit('Second question',(int)$firstTurn['conversation_id']);$secondTurn=pa3_turn($second);
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input($secondTurn));
pa3_check(pa3_turn($first)['state']==='replied'&&pa3_turn($second)['state']==='replied'&&pa3_turn($first)['answer_message_id']===pa3_turn($second)['answer_message_id'],'one human reply covers all pending visitor turns through its explicit target');
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($second),'delegate'));vp3_profile_handoff_dispatch_pa3($pdo,(int)$firstTurn['id']);
pa3_check(count($GLOBALS['pa3ModelCalls'])===0,'delegating after human reply cannot resurrect already answered older questions');
pa3_age();$third=pa3_admit('A later question',(int)$firstTurn['conversation_id']);
pa3_check($third['handoff']['state']==='agent_ready','later unanswered question remains eligible after explicit delegation');

if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
    $temp=sys_get_temp_dir().'/vp3-pa3-race-'.bin2hex(random_bytes(8));mkdir($temp,0700);$cfg=$temp.'/database.json';file_put_contents($cfg,json_encode($GLOBALS['pa3Config'],JSON_THROW_ON_ERROR));chmod($cfg,0600);
    $activeWorkers=[];
    try{
        foreach(['owner','timeout'] as $firstActor){
            pa3_reset();pa3_presence();$receipt=pa3_admit();$turn=pa3_turn($receipt);$delivery=pa3_delivery($turn);$acked=vp3_profile_handoff_ack_pa3($pdo,pa3_owner(),pa3_ack_input($delivery));$turn=pa3_turn($receipt);
            $GLOBALS['pa3Now']=(int)$acked['turn']['pickup_deadline_ms'];$input=['config'=>$cfg,'now'=>$GLOBALS['pa3Now'],'turn'=>$turn,'owner_input'=>pa3_action_input($turn)];$inputPath=$temp.'/input.json';file_put_contents($inputPath,json_encode($input,JSON_THROW_ON_ERROR));
            $start=static function(string $actor,bool $hold)use($inputPath,&$activeWorkers){$process=proc_open([PHP_BINARY,__DIR__.'/fixtures/profile-handoff-pa3-race.php',$inputPath,$actor,$hold?'hold':'run'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Race worker did not start.');$activeWorkers[]=[$process,$pipes];stream_set_timeout($pipes[1],8);$ready=json_decode((string)fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);return [$process,$pipes,$ready];};
            [$firstProcess,$firstPipes,$firstReady]=$start($firstActor,true);
            pa3_check(($firstReady['phase']??'')==='locked',$firstActor.' winner holds real MySQL conversation lock before competing operation');
            [$secondProcess,$secondPipes,$secondReady]=$start($firstActor==='owner'?'timeout':'owner',false);
            $wait=false;$q=$pdo->prepare('SELECT 1 FROM performance_schema.data_lock_waits w INNER JOIN information_schema.innodb_trx t ON t.trx_id=w.REQUESTING_ENGINE_TRANSACTION_ID WHERE t.trx_mysql_thread_id=? LIMIT 1');
            for($i=0;$i<500;$i++){$q->execute([(int)$secondReady['connection']]);if($q->fetchColumn()){$wait=true;break;}usleep(10000);}
            pa3_check($wait,'competing '.$firstActor.' race is observed waiting on actual MySQL row lock');
            fwrite($firstPipes[0],"commit\n");fclose($firstPipes[0]);fclose($secondPipes[0]);
            $out1=stream_get_contents($firstPipes[1]);$err1=stream_get_contents($firstPipes[2]);$out2=stream_get_contents($secondPipes[1]);$err2=stream_get_contents($secondPipes[2]);foreach([$firstPipes,$secondPipes] as $pipes){fclose($pipes[1]);fclose($pipes[2]);}
            pa3_check(proc_close($firstProcess)===0&&proc_close($secondProcess)===0,'both independent race workers finish without deadlock '.$err1.$err2);
            $finished=pa3_turn($receipt);
            if($firstActor==='owner')pa3_check($finished['state']==='replied'&&pa3_count('profile_agent_messages',"sender_type='owner'")===1&&pa3_count('profile_agent_messages',"sender_type='agent'")===0,'owner-first race preserves one human reply after concurrent deadline tick');
            else{pa3_check($finished['state']==='agent_ready'&&str_contains($out2,'HANDOFF_CHANGED')&&pa3_count('profile_agent_messages',"sender_type='owner'")===0,'timeout-first race rejects stale owner operation without public text');vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);pa3_check(pa3_count('profile_agent_messages',"sender_type='agent'")===1,'timeout winner completes one canonical Agent response');}
        }
    }finally{foreach($activeWorkers as [$process,$pipes])if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}foreach(glob($temp.'/*')?:[] as $file)unlink($file);rmdir($temp);}
}
echo 'PROFILE_HANDOFF_RECOVERY_PA3=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
