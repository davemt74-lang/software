<?php
declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,json_encode(['class'=>get_class($e),'code'=>(int)$e->getCode(),'file'=>basename($e->getFile()),'line'=>$e->getLine(),'driver'=>$e instanceof PDOException?($e->errorInfo[1]??0):0]).PHP_EOL);exit(1);});
$child=($argv[1]??'')==='--tick';
$dsn=(string)getenv('VP3_MYSQL_TEST_DSN');if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('An explicit disposable MySQL DSN is required.');
$GLOBALS['actionMysqlUser']=(string)(getenv('VP3_MYSQL_TEST_USER')?:'root');$GLOBALS['actionMysqlPassword']=(string)getenv('VP3_MYSQL_TEST_PASSWORD');
if($child){$GLOBALS['actionMysqlDsn']=(string)getenv('VP3_FOLLOWUP_MYSQL_CHILD_DSN');}
else{$database='initiative_followup_'.bin2hex(random_bytes(6));$admin=new PDO($dsn,$GLOBALS['actionMysqlUser'],$GLOBALS['actionMysqlPassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec('CREATE DATABASE `'.$database.'`');$GLOBALS['actionMysqlDsn']=preg_replace('/;dbname=[^;]*/','',$dsn).';dbname='.$database;}
require __DIR__.'/fixtures/initiative-actions-mysql-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-followups-v1.php';
require_once dirname(__DIR__).'/includes/agent-scheduling-v430.php';
require_once dirname(__DIR__).'/includes/user-calendar-v1300.php';
if($child){reminder_tick_v1(db(),1,new DateTimeImmutable((string)$argv[2]));echo 'tick-ok';exit;}
$checks=0;$children=[];
function fm_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function fm_count(string $table):int{return(int)db()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
function fm_row(int $id):array{$q=db()->prepare('SELECT * FROM agent_reminders_v1 WHERE id=?');$q->execute([$id]);return$q->fetch();}
function fm_schedule(string $label):array{
 $root=action_mysql_seed($label);$when=(new DateTimeImmutable('+1 hour',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');
 $review=action_mysql_review($root,'proposal.revisit.create',['focus'=>$label,'start_local'=>$when,'timezone'=>'UTC']);
 $result=action_mysql_decide($review['pending'],['action_decision'=>'approve'],'Yes, schedule these exact details');action_mysql_ack($result);
 $loaded=action_mysql_load($root['message_id']);$a=$loaded['followup_action'];
 return$root+['reminder_id'=>$a['result']['domain_result']['record_id'],'creating_action'=>$a,'selection'=>$result];
}
function fm_wake_run(int $id):array{$q=db()->prepare("SELECT * FROM agent_workflow_runs WHERE capability_key='initiative.prepare' AND goal LIKE ? ORDER BY id DESC LIMIT 1");$q->execute(['%"reminder_id":'.$id.',%']);$r=$q->fetch();if(!$r)throw new RuntimeException('Expected canonical wake.');return$r;}
function fm_execute(array $run,?callable $during=null,string $expected='open'):array{
 $claim=agent_job_claim_run_v1900(db(),action_mysql_user(),(int)$run['id'],'cloud',agent_worker_cloud_worker_id_v1910(1));if(!$claim)throw new RuntimeException('Expected actual wake claim.');
 return vp3_initiative_execute_v1(db(),action_mysql_user(),$claim,static function(string $prompt)use($during,$expected):string{
  if(db()->inTransaction())throw new LogicException('Wake inference owns a transaction.');$p=json_decode($prompt,true,64,JSON_THROW_ON_ERROR);
  fm_check(($p['owner_followup']['current_context']['records'][0]['status']??null)===$expected,'actual native wake reads the current canonical task state');
  if($during)$during();return json_encode(['draft'=>$expected==='completed'?'The current task is already completed.':'The current task remains open; we can review its next step.'],JSON_THROW_ON_ERROR);
 });
}
try{
 action_mysql_schema();db()->exec("CREATE TABLE notifications(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,type VARCHAR(40),title VARCHAR(240),body TEXT,target_url VARCHAR(500),source_type VARCHAR(40),source_id BIGINT UNSIGNED,is_read TINYINT DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");reminder_schema_v1(db());
 $s=fm_schedule('Native scheduled launch reassessment');$id=$s['reminder_id'];$r=fm_row($id);
 fm_check((int)$r['followup_action_id']===(int)$s['creating_action']['action']['id'],'native reminder backlink names the actual canonical creating action');
 $linked=vp3_initiative_followup_origin_v1(db(),action_mysql_user(),action_mysql_principal(),$id);fm_check((int)$linked['origin']['message']['id']===$s['message_id'],'native bidirectional provenance retains the exact original proposal');
 $at=(new DateTimeImmutable($r['next_at']))->modify('+1 second')->format(DATE_ATOM);$before=fm_count('agent_workflow_runs');
 putenv('VP3_FOLLOWUP_MYSQL_CHILD_DSN='.$GLOBALS['actionMysqlDsn']);
 for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--tick',$at],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot start independent native ticker');fclose($pipes[0]);$children[]=[$process,$pipes];}
 foreach($children as[$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);fm_check($exit===0&&str_contains($out,'tick-ok'),'independent native ticker finishes without duplicate-key or lock-order failure');} $children=[];
 fm_check(fm_count('agent_workflow_runs')===$before+1&&fm_count('agent_reminder_occurrences_v1')===1,'concurrent native tickers create one occurrence and one canonical wake');
 fm_check(fm_count('notifications')===0,'linked wake creates no generic reminder notification');
 db()->prepare('UPDATE agent_activity_state SET details_json=? WHERE user_id=1')->execute([json_encode(['visible'=>false,'conversation_id'=>10,'chat_focused'=>false,'chat_busy'=>false])]);
 $run=fm_wake_run($id);$result=fm_execute($run);fm_check(!empty($result['ok']),'native reassessment executes while the browser is absent');
 $q=db()->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$run['id']]);$private=json_decode((string)$q->fetchColumn(),true);fm_check($private['initiative_delivery']==='pending','absent owner leaves one verified private result awaiting canonical Chat delivery');
 db()->prepare('UPDATE agent_activity_state SET last_heartbeat_at=NOW(),details_json=? WHERE user_id=1')->execute([json_encode(['visible'=>true,'conversation_id'=>10,'chat_focused'=>true,'chat_busy'=>false])]);db()->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE role='assistant'");
 fm_check(vp3_initiative_deliver_pending_v1(db(),action_mysql_user(),7),'returning owner receives the saved native follow-up result');
 $q=db()->prepare('SELECT * FROM agent_reminder_occurrences_v1 WHERE reminder_id=?');$q->execute([$id]);$o=$q->fetch();fm_check((int)$o['message_id']>0&&$o['notified_at']!==null&&$o['presented_at']!==null,'actual natural Chat delivery atomically owns the native occurrence');
 $before=fm_count('chat_messages');reminder_tick_v1(db(),1,new DateTimeImmutable($at));fm_check(fm_count('chat_messages')===$before&&reminder_present_v1(db(),1,['action'=>'claim'])['item']===null,'replayed native tick/presentation cannot duplicate Chat or generic voice');
 $s=fm_schedule('Native cancellation race');$id=$s['reminder_id'];reminder_tick_v1(db(),1,(new DateTimeImmutable(fm_row($id)['next_at']))->modify('+1 second'));$run=fm_wake_run($id);$before=fm_count('chat_messages');
 $result=fm_execute($run,static function()use($id):void{reminder_execute_v1(db(),1,'native-cancel','cancel-race-123','Owner cancels the exact revisit',['operation'=>'cancel','id'=>$id],reminder_rows_v1(db(),1),10);});
 fm_check(empty($result['ok'])&&fm_row($id)['status']==='cancelled'&&fm_count('chat_messages')===$before,'owner cancellation as generation completes prevents any stale native Chat post');
 $s=fm_schedule('Native changed-source reassessment');$id=$s['reminder_id'];$at=(new DateTimeImmutable(fm_row($id)['next_at']))->modify('+1 second');reminder_tick_v1(db(),1,$at);$run=fm_wake_run($id);
 $result=fm_execute($run,static function()use($s):void{vp3_initiative_in_scope_v1(action_mysql_user(),7,static function()use($s):void{if(!agent_task_v123_update(action_mysql_user(),$s['task_id'],'completed'))throw new RuntimeException('Actual task completion failed');});});
 fm_check(empty($result['ok']),'source completion during inference invalidates the old native draft');
 reminder_tick_v1(db(),1,$at->modify('+61 seconds'));$fresh=fm_wake_run($id);fm_check((int)$fresh['id']!==(int)$run['id'],'changed canonical context queues a fresh reassessment of the same occurrence');
 $result=fm_execute($fresh,null,'completed');fm_check(!empty($result['ok']),'fresh native reassessment can recognize a completed task without reusing old approval');
 $root=action_mysql_review(action_mysql_seed('Native atomic revisit rollback'),'proposal.revisit.create',['focus'=>'Review this exact item','start_local'=>(new DateTimeImmutable('+1 hour',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s'),'timezone'=>'UTC']);
 $before=[fm_count('agent_reminders_v1'),fm_count('agent_reminder_turns_v1'),fm_count('agent_workflow_receipts')];$seen=false;$blocked=false;
 try{action_mysql_decide($root['pending'],['action_decision'=>'approve'],'Schedule the exact rollback probe',null,static function()use($before,&$seen):void{if(db()->inTransaction()&&fm_count('agent_reminders_v1')>$before[0]){$seen=true;throw new RuntimeException('Final current authority changed.',409);}});}catch(RuntimeException $e){if($e->getCode()!==409)throw $e;$blocked=true;}
 fm_check($seen&&$blocked&&$before===[fm_count('agent_reminders_v1'),fm_count('agent_reminder_turns_v1'),fm_count('agent_workflow_receipts')],'native reminder, origin backlink and domain/workflow receipts all roll back after final authority rejection');
 fm_check(action_mysql_load($root['message_id'])['followup_action']['run']['status']==='approval_pending','failed final approval preserves the real review and creates no orphan commitment');
 fm_check(!db()->inTransaction(),'native schedule, wake, cancellation and stale-result paths release transaction ownership');
 echo 'INITIATIVE_FOLLOWUPS_MYSQL=PASS ('.$checks.' checks; actual MySQL, independent tickers, canonical worker and delivery)'.PHP_EOL;
}finally{foreach($children as[$process,$pipes]){proc_terminate($process);foreach($pipes as$pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}if(db()->inTransaction())db()->rollBack();$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');}
