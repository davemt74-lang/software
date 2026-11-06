<?php
declare(strict_types=1);

ini_set('zend.exception_ignore_args','1');
set_exception_handler(static function(Throwable $e):void{
    fwrite(STDERR,json_encode(['class'=>get_class($e),'code'=>(int)$e->getCode(),'file'=>basename($e->getFile()),'line'=>$e->getLine(),
        'driver'=>$e instanceof PDOException?($e->errorInfo[1]??0):0,'message'=>$e instanceof PDOException?'Native SQL failure':$e->getMessage()]).PHP_EOL);exit(1);
});
$dsn=(string)getenv('VP3_MYSQL_TEST_DSN');
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('An explicit disposable MySQL DSN is required.');
$GLOBALS['actionMysqlUser']=(string)(getenv('VP3_MYSQL_TEST_USER')?:'root');$GLOBALS['actionMysqlPassword']=(string)getenv('VP3_MYSQL_TEST_PASSWORD');
$child=($argv[1]??'')==='--tick';
$database='initiative_conditions_'.bin2hex(random_bytes(6));
if(!$child){
$admin=new PDO($dsn,$GLOBALS['actionMysqlUser'],$GLOBALS['actionMysqlPassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `'.$database.'`');
$GLOBALS['actionMysqlDsn']=preg_replace('/;dbname=[^;]*/','',$dsn).';dbname='.$database;
}else $GLOBALS['actionMysqlDsn']=(string)getenv('VP3_CONDITIONS_CHILD_DSN');
require __DIR__.'/fixtures/initiative-actions-mysql-v1.php';
require_once dirname(__DIR__).'/includes/cloud-missions-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-followup-conditions-v1.php';
if($child){reminder_tick_v1(db(),1,new DateTimeImmutable((string)$argv[2]));echo 'tick-ok';exit;}
$checks=0;
function fc_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function fc_reject(callable $fn,int $code,string $label):void{
    $rejected=false;try{$fn();}catch(RuntimeException $error){if($error->getCode()!==$code)throw $error;$rejected=true;}fc_check($rejected,$label);
}
function fc_uuid():string{return cloud_mission_uuid_v1(bin2hex(random_bytes(16)));}
try{
    action_mysql_schema();reminder_schema_v1(db());cloud_mission_schema_v1(db());
    db()->exec("CREATE TABLE notifications(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,type VARCHAR(40),title VARCHAR(240),body TEXT,target_url VARCHAR(500),source_type VARCHAR(40),source_id BIGINT UNSIGNED,is_read TINYINT DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $root=action_mysql_seed('Native condition proof source');
    $scope=['user'=>action_mysql_user(),'principal'=>action_mysql_principal(),'agent_id'=>7,'conversation_id'=>10];
    $prepared=cloud_mission_prepare_v1(db(),$scope['user'],['objective'=>'Analyze the current launch checklist','request_id'=>fc_uuid(),'thread_id'=>10,'agent_id'=>7]);
    cloud_mission_configure_v1(db(),$scope['user'],['mission_id'=>$prepared['id'],'request_id'=>fc_uuid(),'confirmed'=>true,'expected_revision'=>0,'assignments'=>$prepared['chat_task']['draft']]);
    $id=$prepared['id'];$target=vp3_initiative_followup_mission_target_v1(db(),$scope,$id);
    $condition=['milestone'=>'mission_completed','target_ref'=>'mission:'.$id,'identity_hash'=>$target['identity_hash']];
    fc_check($target['status']==='approval_pending'&&vp3_initiative_followup_condition_check_v1(db(),$scope,$condition)===null,'an actual prepared Mission remains waiting and follow-up never approves its work');
    $review=action_mysql_review($root,'proposal.revisit.create',['focus'=>'Revisit the launch checklist after this Mission completes','condition'=>['milestone'=>'mission_completed','target_ref'=>'mission:'.$id]]);
    fc_check((int)db()->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn()===0,'conditional Chat review creates no commitment before approval');
    $committed=action_mysql_decide($review['pending'],['action_decision'=>'approve'],'Yes, revisit that exact item when that exact Mission completes.');action_mysql_ack($committed);
    $reminderId=$committed['advisory_context']['domain_result']['record_id'];
    $q=db()->prepare('SELECT * FROM agent_reminders_v1 WHERE id=?');$q->execute([$reminderId]);$reminder=$q->fetch();
    fc_check($reminder['start_local']===''&&$reminder['next_at']===null&&json_decode($reminder['followup_condition_json'],true)===$condition,'native conditional commitment stores the exact milestone without a fabricated deadline');
    $before=(int)db()->query('SELECT COUNT(*) FROM agent_workflow_runs')->fetchColumn();reminder_tick_v1(db(),1);
    fc_check(($GLOBALS['VP3_FOLLOWUP_TICK_V1']['waiting']??0)===1&&(int)db()->query('SELECT COUNT(*) FROM agent_workflow_runs')->fetchColumn()===$before,'pending actual Mission causes waiting without private inference or another workflow');
    fc_check(count(reminder_list_rows_v1(db(),1,'overdue','UTC',new DateTimeImmutable('+1 day')))===0,'waiting for a condition never appears as an overdue timer');
    db()->prepare("UPDATE agent_workflow_runs SET status='completed' WHERE id=?")->execute([$prepared['job_id']]);
    fc_reject(fn()=>vp3_initiative_followup_condition_check_v1(db(),$scope,$condition),409,'an unreceipted completed status cannot satisfy the exact Mission milestone');
    db()->prepare("UPDATE agent_workflow_runs SET status='approval_pending' WHERE id=?")->execute([$prepared['job_id']]);
    cloud_mission_service_v1(db(),$scope['user'],'start',['mission_id'=>$id,'confirmed'=>true,'expected_revision'=>1]);
    for($i=0;$i<2;$i++){
        $claim=agent_job_claim_run_v1900(db(),$scope['user'],$prepared['job_id'],'cloud',agent_worker_cloud_worker_id_v1910(1));
        fc_check($claim!==null,'the actual reviewed Mission receives its normal native worker lease');
        $result=cloud_mission_execute_claim_v1(db(),$scope['user'],$claim,static function()use($i):array{
            if(db()->inTransaction())throw new LogicException('Mission inference owns SQL locks.');
            return ['ok'=>true,'answer'=>$i===0?'Launch checklist draft for synthesis.':'The launch checklist has been analyzed; this is a saved model assessment.'];
        });
        fc_check(!empty($result['ok']),'the normal Mission executor commits its actual result and receipt');
        if($i===0)fc_check(vp3_initiative_followup_condition_check_v1(db(),$scope,$condition)===null,'partial worker completion cannot satisfy the whole-Mission condition');
    }
    $ready=vp3_initiative_followup_condition_check_v1(db(),$scope,$condition);
    fc_check($ready!==null&&count($ready['proof']['native_completion'])===2,'exact real Mission completion proves both canonical worker receipts');
    fc_check($ready['target']['output_freshness']==='saved_run_output_not_new_research'&&str_contains($ready['target']['saved_model_output'],'saved model assessment'),'saved Mission output retains its actual provenance and is not claimed as new research');
    $beforeRuns=(int)db()->query('SELECT COUNT(*) FROM agent_workflow_runs')->fetchColumn();$children=[];
    putenv('VP3_CONDITIONS_CHILD_DSN='.$GLOBALS['actionMysqlDsn']);$at=(new DateTimeImmutable('+61 seconds'))->format(DATE_ATOM);
    for($i=0;$i<2;$i++){
        $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--tick',$at],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Cannot start independent condition ticker.');fclose($pipes[0]);$children[]=[$process,$pipes];
    }
    foreach($children as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);fc_check($exit===0&&str_contains($out,'tick-ok'),'independent native condition ticker completes without duplicate or lock failure');}
    fc_check((int)db()->query('SELECT COUNT(*) FROM agent_workflow_runs')->fetchColumn()===$beforeRuns+1,'competing native condition tickers converge on one canonical reassessment');
    $q=db()->prepare("SELECT * FROM agent_workflow_runs WHERE capability_key='initiative.prepare' AND goal LIKE ? ORDER BY id DESC LIMIT 1");$q->execute(['%\"reminder_id\":'.$reminderId.',%']);$wake=$q->fetch();
    fc_check($wake!==false,'completed native Mission queues the exact original conditional reassessment');
    $claim=agent_job_claim_run_v1900(db(),$scope['user'],(int)$wake['id'],'cloud',agent_worker_cloud_worker_id_v1910(1));
    $result=vp3_initiative_execute_v1(db(),$scope['user'],$claim,static function(string $prompt):string{
        $p=json_decode($prompt,true,64,JSON_THROW_ON_ERROR);$c=$p['owner_followup']['current_context'];
        fc_check(($c['condition_completion']['proof']['condition']['milestone']??null)==='mission_completed'&&count($c['condition_completion']['proof']['native_completion'])===2,'actual wake inference receives the exact native prerequisite proof and fresh original source');
        return json_encode(['draft'=>'The specified Mission has completed. We can now revisit the launch checklist using its saved assessment.'],JSON_THROW_ON_ERROR);
    });
    fc_check(!empty($result['ok']),'conditional reassessment executes through the real worker');
    $q=db()->prepare('SELECT * FROM agent_reminder_occurrences_v1 WHERE reminder_id=?');$q->execute([$reminderId]);$o=$q->fetch();
    fc_check((int)$o['message_id']>0&&$o['notified_at']!==null,'one natural main Chat result atomically owns the conditional occurrence');
    $before=(int)db()->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();reminder_tick_v1(db(),1,new DateTimeImmutable('+2 minutes'));
    fc_check((int)db()->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$before,'replayed condition polling cannot duplicate its committed Chat delivery');
    $q=db()->prepare('SELECT * FROM agent_workflow_actions WHERE run_id=? ORDER BY sequence_no DESC LIMIT 1');$q->execute([$prepared['job_id']]);$last=$q->fetch();
    db()->prepare("UPDATE agent_workflow_actions SET result_json='{}' WHERE id=?")->execute([$last['id']]);
    fc_reject(fn()=>vp3_initiative_followup_condition_check_v1(db(),$scope,$condition),409,'a changed saved action cannot reuse a different native completion receipt');
    db()->prepare('UPDATE agent_workflow_actions SET result_json=? WHERE id=?')->execute([$last['result_json'],$last['id']]);
    db()->prepare("UPDATE agent_workflow_runs SET status='failed' WHERE id=?")->execute([$prepared['job_id']]);
    fc_reject(fn()=>vp3_initiative_followup_condition_check_v1(db(),$scope,$condition),409,'a failed prerequisite remains blocked even when old receipts exist');
    db()->prepare("UPDATE agent_workflow_runs SET status='completed' WHERE id=?")->execute([$prepared['job_id']]);
    db()->beginTransaction();db()->prepare('DELETE FROM agent_workflow_actions WHERE id=?')->execute([$last['id']]);
    fc_reject(fn()=>vp3_initiative_followup_condition_check_v1(db(),$scope,$condition),409,'missing configured worker assignment cannot masquerade as whole-Mission completion');
    db()->rollBack();
    $wrong=$condition;$wrong['identity_hash']=str_repeat('a',64);
    fc_reject(fn()=>vp3_initiative_followup_condition_check_v1(db(),$scope,$wrong),409,'a replaced immutable prerequisite cannot satisfy the original reviewed condition');
    $foreign=$scope;$foreign['user']=['id'=>2,'is_active'=>1,'role'=>'artist'];$foreign['principal']=vp3_initiative_principal_v1(db(),$foreign['user'],9);$foreign['agent_id']=9;$foreign['conversation_id']=12;
    fc_reject(fn()=>vp3_initiative_followup_condition_check_v1(db(),$foreign,$condition),403,'another owner cannot use a private Mission condition or its saved output');
    $q=db()->prepare('SELECT config_json FROM agent_cloud_missions_v1 WHERE mission_id=?');$q->execute([$id]);$oldConfig=$q->fetchColumn();
    $changed=json_decode($oldConfig,true);$changed['max_parallel']=2;
    db()->prepare('UPDATE agent_cloud_missions_v1 SET config_json=? WHERE mission_id=?')->execute([json_encode($changed),$id]);
    fc_reject(fn()=>vp3_initiative_followup_condition_check_v1(db(),$scope,$condition),409,'actual prerequisite reconfiguration invalidates the reviewed identity');
    db()->prepare('UPDATE agent_cloud_missions_v1 SET config_json=? WHERE mission_id=?')->execute([$oldConfig,$id]);
    $otherAgent=$scope;$otherAgent['agent_id']=8;$otherAgent['principal']=vp3_initiative_principal_v1(db(),$scope['user'],8);$otherAgent['conversation_id']=11;
    fc_reject(fn()=>vp3_initiative_followup_condition_check_v1(db(),$otherAgent,$condition),403,'a sibling Agent cannot reuse this private Mission prerequisite');
    fc_reject(fn()=>vp3_initiative_followup_condition_normalize_v1(db(),$scope,['milestone'=>'mission_completed','target_ref'=>'mission:'.$id],[]),403,'a generated reference outside supplied canonical targets cannot create a condition');
    $invalid=false;try{vp3_initiative_followup_condition_normalize_v1(db(),$scope,['milestone'=>'mission_completed','target_ref'=>'mission:'.$id,'predicate'=>'execute arbitrary code'],[]);}catch(InvalidArgumentException $error){$invalid=true;}
    fc_check($invalid,'free-form predicates and executable selectors are refused before any commitment');
    // Final authority failure rolls back the native occurrence as well as both ledgers.
    $rollback=action_mysql_review(action_mysql_seed('Conditional atomic rollback'),'proposal.revisit.create',['focus'=>'Recheck this after the completed Mission','condition'=>['milestone'=>'mission_completed','target_ref'=>'mission:'.$id]]);
    $tables=['agent_reminders_v1','agent_reminder_occurrences_v1','agent_reminder_turns_v1','agent_workflow_receipts'];
    $counts=static fn()=>array_map(static fn($table)=>(int)db()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),$tables);$before=$counts();$seen=false;
    fc_reject(static function()use($rollback,$before,&$seen):void{
        action_mysql_decide($rollback['pending'],['action_decision'=>'approve'],'Save this exact conditional revisit',null,static function()use($before,&$seen):void{
            if(db()->inTransaction()&&(int)db()->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn()>$before[0]){$seen=true;throw new RuntimeException('Final authority changed.',409);}
        });
    },409,'final conditional authority rejection is propagated');
    fc_check($seen&&$counts()===$before,'conditional reminder, occurrence, backlink and both receipts roll back together');
    // A different original task gets its own independently cancellable condition.
    $cancelRoot=action_mysql_review(action_mysql_seed('Cancel while conditional inference completes'),'proposal.revisit.create',['focus'=>'Revisit the cancellable original item','condition'=>['milestone'=>'mission_completed','target_ref'=>'mission:'.$id]]);
    $result=action_mysql_decide($cancelRoot['pending'],['action_decision'=>'approve'],'Save the exact conditional commitment');action_mysql_ack($result);$cancelId=$result['advisory_context']['domain_result']['record_id'];
    reminder_tick_v1(db(),1,new DateTimeImmutable('+2 minutes'));
    $q=db()->prepare("SELECT * FROM agent_workflow_runs WHERE capability_key='initiative.prepare' AND goal LIKE ? ORDER BY id DESC LIMIT 1");$q->execute(['%\"reminder_id\":'.$cancelId.',%']);$cancelRun=$q->fetch();
    $before=(int)db()->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
    $claim=agent_job_claim_run_v1900(db(),$scope['user'],(int)$cancelRun['id'],'cloud',agent_worker_cloud_worker_id_v1910(1));
    $result=vp3_initiative_execute_v1(db(),$scope['user'],$claim,static function()use($cancelId):string{
        reminder_execute_v1(db(),1,'condition-cancel','cancel-condition-123','Cancel this exact conditional revisit',['operation'=>'cancel','id'=>$cancelId],reminder_rows_v1(db(),1),10);
        return json_encode(['draft'=>'This late result must not reach Chat.'],JSON_THROW_ON_ERROR);
    });
    fc_check(empty($result['ok'])&&(int)db()->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$before,'cancellation during conditional inference prevents stale Chat delivery');
    $pendingRoot=action_mysql_review(action_mysql_seed('Conditional delivery rechecks completed Mission'),'proposal.revisit.create',['focus'=>'Revisit this when I return','condition'=>['milestone'=>'mission_completed','target_ref'=>'mission:'.$id]]);
    $result=action_mysql_decide($pendingRoot['pending'],['action_decision'=>'approve'],'Save this exact revisit');action_mysql_ack($result);$pendingId=$result['advisory_context']['domain_result']['record_id'];
    reminder_tick_v1(db(),1,new DateTimeImmutable('+3 minutes'));
    $q=db()->prepare("SELECT * FROM agent_workflow_runs WHERE capability_key='initiative.prepare' AND goal LIKE ? ORDER BY id DESC LIMIT 1");$q->execute(['%\"reminder_id\":'.$pendingId.',%']);$pendingRun=$q->fetch();
    db()->prepare('UPDATE agent_activity_state SET details_json=? WHERE user_id=1')->execute([json_encode(['visible'=>false,'conversation_id'=>10,'chat_focused'=>false,'chat_busy'=>false])]);
    $claim=agent_job_claim_run_v1900(db(),$scope['user'],(int)$pendingRun['id'],'cloud',agent_worker_cloud_worker_id_v1910(1));
    $result=vp3_initiative_execute_v1(db(),$scope['user'],$claim,static fn()=>json_encode(['draft'=>'A privately prepared conditional response.']));
    fc_check(!empty($result['ok']),'closed-browser conditional wake prepares through the actual worker');
    db()->prepare("UPDATE agent_workflow_runs SET status='failed' WHERE id=?")->execute([$prepared['job_id']]);
    db()->prepare('UPDATE agent_activity_state SET last_heartbeat_at=NOW(),details_json=? WHERE user_id=1')->execute([json_encode(['visible'=>true,'conversation_id'=>10,'chat_focused'=>true,'chat_busy'=>false])]);db()->exec("UPDATE chat_messages SET created_at='2000-01-01' WHERE role='assistant'");
    $before=(int)db()->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();vp3_initiative_deliver_pending_v1(db(),$scope['user'],7);
    fc_check((int)db()->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$before,'changed prerequisite completion proof withholds a prepared result when the owner returns');
    echo 'INITIATIVE_FOLLOWUP_CONDITIONS_MYSQL=PASS ('.$checks.' native domain checks)'.PHP_EOL;
}finally{
    if(db()->inTransaction())db()->rollBack();$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');
}
