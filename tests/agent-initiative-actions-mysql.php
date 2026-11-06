<?php
declare(strict_types=1);

ini_set('zend.exception_ignore_args','1');
set_exception_handler(static function(Throwable $error):void{
    fwrite(STDERR,json_encode(['fixture_failure'=>true,'error_class'=>get_class($error),'public_code'=>(int)$error->getCode(),
        'driver_code'=>$error instanceof PDOException?(int)($error->errorInfo[1]??0):0,'file'=>basename($error->getFile()),'line'=>$error->getLine()],JSON_THROW_ON_ERROR).PHP_EOL);exit(1);
});
$dsn=(string)getenv('VP3_MYSQL_TEST_DSN');if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('An explicit disposable native MySQL DSN is required.');
$GLOBALS['actionMysqlUser']=(string)(getenv('VP3_MYSQL_TEST_USER')?:'root');$GLOBALS['actionMysqlPassword']=(string)getenv('VP3_MYSQL_TEST_PASSWORD');
$mode=(string)($argv[1]??'');$worker=str_starts_with($mode,'--');$database=$worker?(string)($argv[2]??''):'initiative_actions_'.bin2hex(random_bytes(6));
if(!preg_match('/\Ainitiative_actions_[a-f0-9]{12}\z/D',$database))throw new RuntimeException('Invalid disposable native action database name.');
$admin=new PDO($dsn,$GLOBALS['actionMysqlUser'],$GLOBALS['actionMysqlPassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!$worker)$admin->exec('CREATE DATABASE `'.$database.'`');
$GLOBALS['actionMysqlDsn']=preg_replace('/;dbname=[^;]*/','',$dsn).';dbname='.$database;
require __DIR__.'/fixtures/initiative-actions-mysql-v1.php';
date_default_timezone_set('UTC');
function action_mysql_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['nativeActionChecks']=($GLOBALS['nativeActionChecks']??0)+1;echo 'PASS '.$label.PHP_EOL;}
function action_mysql_wait(callable $ready,string $label):void{$until=microtime(true)+20;while(!$ready()){if(microtime(true)>$until)throw new RuntimeException($label);usleep(10000);}}

if($worker){
    $gate=(string)($argv[3]??'');$index=(string)($argv[4]??'');
    if(!is_dir($gate)||!preg_match('/\A[0-9]+\z/D',$index))throw new RuntimeException('Invalid native action process barrier.');
    $config=json_decode((string)file_get_contents($gate.'/config-'.$index.'.json'),true,32,JSON_THROW_ON_ERROR);
    db()->exec('SET SESSION innodb_lock_wait_timeout=1');
    if($mode==='--write'){
        file_put_contents($gate.'/ready-'.$index,'ready');action_mysql_wait(static fn()=>is_file($gate.'/go-'.$index),'Native raw writer start timed out.');
        try{
            if($config['write']==='source')db()->prepare('UPDATE agent_memory_items SET subject=? WHERE id=?')->execute(['Independent source edit',$config['task_id']]);
            elseif($config['write']==='permission')db()->exec("DELETE FROM role_permissions WHERE role='artist' AND permission_key='agent_brain.access'");
            elseif($config['write']==='chat')db()->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(10,1,'user','Independent competing owner turn')");
            else throw new LogicException('Invalid independent native writer.');
            echo json_encode(['blocked'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
        }catch(PDOException $error){if((int)($error->errorInfo[1]??0)!==1205)throw $error;echo json_encode(['blocked'=>true,'driver_code'=>1205],JSON_THROW_ON_ERROR).PHP_EOL;}
        exit;
    }
    $loaded=action_mysql_load($config['message_id']);
    $during=static function()use($gate,$index,$config):void{
        file_put_contents($gate.'/ready-'.$index,'ready');action_mysql_wait(static fn()=>is_file($gate.'/go-'.$index),'Native approval inference barrier timed out.');
    };
    $held=false;$authority=static function()use($gate,$index,$config,&$held):void{
        if(!$held&&($config['hold_effect']??false)&&db()->inTransaction()&&(action_mysql_task($config['task_id'])['metadata']['task_status']??'open')==='completed'){
            $held=true;file_put_contents($gate.'/effect-'.$index,'effect-uncommitted');action_mysql_wait(static fn()=>is_file($gate.'/commit-'.$index),'Native effect commit barrier timed out.');
        }
    };
    try{
        $result=action_mysql_decide($loaded,['action_decision'=>'approve'],$config['query'],$during,$authority,$config['owner_message_id']);
        if($config['drop_ack']??false){file_put_contents($gate.'/committed-'.$index,'committed-before-ack');action_mysql_wait(static fn()=>is_file($gate.'/finish-'.$index),'Native lost-ack kill barrier timed out.');}
        echo json_encode(['ok'=>true,'outcome'=>$result['initiative_followthrough'],'status'=>$result['advisory_context']['verified_action']['status']],JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(Throwable $error){echo json_encode(['ok'=>false,'code'=>(int)$error->getCode(),'class'=>get_class($error)],JSON_THROW_ON_ERROR).PHP_EOL;}
    exit;
}

$gate=sys_get_temp_dir().'/vp3-native-actions-'.bin2hex(random_bytes(8));$children=[];$childIndex=0;
try{
    mkdir($gate,0700);action_mysql_schema();
    $start=static function(string $mode,array $config)use($database,$gate,&$children,&$childIndex):int{
        $id=++$childIndex;file_put_contents($gate.'/config-'.$id.'.json',json_encode($config,JSON_THROW_ON_ERROR));
        $process=proc_open([PHP_BINARY,__FILE__,$mode,$database,$gate,(string)$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Could not launch an independent native action process.');fclose($pipes[0]);$children[$id]=['process'=>$process,'stdout'=>$pipes[1],'stderr'=>$pipes[2]];return $id;
    };
    $finish=static function(int $id)use(&$children):array{
        $child=$children[$id];$stdout=stream_get_contents($child['stdout']);$stderr=stream_get_contents($child['stderr']);fclose($child['stdout']);fclose($child['stderr']);$status=proc_close($child['process']);unset($children[$id]);
        if($status!==0||$stderr!=='')throw new RuntimeException('Independent native action process failed.');
        $lines=array_values(array_filter(explode("\n",trim($stdout))));return json_decode(end($lines),true,32,JSON_THROW_ON_ERROR);
    };
    $count=static function(string $table,string $where='1=1'):int{return (int)db()->query('SELECT COUNT(*) FROM '.$table.' WHERE '.$where)->fetchColumn();};

    $root=action_mysql_review(action_mysql_seed('Native concurrent approval'));$approval=action_mysql_owner('Yes, complete that exact reviewed task');
    $config=['message_id'=>$root['message_id'],'task_id'=>$root['task_id'],'owner_message_id'=>$approval,'query'=>'Yes, complete that exact reviewed task'];
    $a=$start('--approve',$config);$b=$start('--approve',$config);
    action_mysql_wait(static fn()=>is_file($gate.'/ready-'.$a)&&is_file($gate.'/ready-'.$b),'Both native approval models did not reach their independent barrier.');
    file_put_contents($gate.'/go-'.$a,'go');file_put_contents($gate.'/go-'.$b,'go');$results=[$finish($a),$finish($b)];
    $accepted=array_values(array_filter($results,static fn($r)=>$r['ok']));$rejected=array_values(array_filter($results,static fn($r)=>!$r['ok']));
    $loaded=action_mysql_load($root['message_id']);$action=$loaded['domain_action'];$aid=(int)$action['action']['id'];
    action_mysql_check(count($accepted)===1&&count($rejected)===1&&$rejected[0]['code']===409,'two independent exact approvals commit once and reject the losing proposal revision');
    action_mysql_check(action_mysql_task($root['task_id'])['metadata']['task_status']==='completed'&&$count('agent_workflow_receipts','action_id='.$aid)===1
        &&$action['result']['domain_result']['verified']===true,'the winning approval has one actual canonical task mutation and matching completed native receipt');
    action_mysql_check($action['result']['source_transition']['removed_refs']===['task:'.$root['task_id']]
        &&$loaded['evidence']['task:'.$root['task_id']]['kind']==='action_tombstone','the verified own-source completion reloads through its narrow before/after receipt instead of a freshness bypass');
    $task=action_mysql_task($root['task_id']);$before=$count('agent_workflow_receipts');
    $replay=vp3_initiative_followthrough_current_v1(db(),action_mysql_user(),action_mysql_principal(),10,$accepted[0]['outcome']);
    action_mysql_check($replay['verified_action']['status']==='completed'&&action_mysql_task($root['task_id'])===$task&&$count('agent_workflow_receipts')===$before,'canonical completion reconciliation is read-only and cannot reset the completed task or add a receipt');

    foreach(['source','permission','chat'] as $write){
        $root=action_mysql_review(action_mysql_seed('Native protected '.$write));$query='Yes, complete this protected task';$approval=action_mysql_owner($query);
        $config=['message_id'=>$root['message_id'],'task_id'=>$root['task_id'],'owner_message_id'=>$approval,'query'=>$query,'hold_effect'=>true];
        $apply=$start('--approve',$config);action_mysql_wait(static fn()=>is_file($gate.'/ready-'.$apply),'Protected native approval did not reach inference.');file_put_contents($gate.'/go-'.$apply,'go');
        action_mysql_wait(static fn()=>is_file($gate.'/effect-'.$apply),'Native effect was not held before receipt commit.');
        action_mysql_check((action_mysql_task($root['task_id'])['metadata']['task_status']??'open')==='open','an independent reader cannot observe an uncommitted '.$write.'-protected task effect');
        $writer=$start('--write',['write'=>$write,'task_id'=>$root['task_id']]);action_mysql_wait(static fn()=>is_file($gate.'/ready-'.$writer),'Independent native writer was not ready.');file_put_contents($gate.'/go-'.$writer,'go');
        $attempt=$finish($writer);action_mysql_check($attempt['blocked']===true,'the action commit boundary blocks an independent raw '.$write.' writer after the real effect and before receipt commit');
        file_put_contents($gate.'/commit-'.$apply,'commit');$result=$finish($apply);
        action_mysql_check($result['ok']&&action_mysql_load($root['message_id'])['domain_action']['result']['domain_result']['verified']===true,'the protected '.$write.' action commits its verified effect and receipt together');
    }

    $root=action_mysql_review(action_mysql_seed('Native crash before receipt'));$query='Yes, commit only verified work';$approval=action_mysql_owner($query);$before=$count('agent_workflow_receipts');
    $crash=$start('--approve',['message_id'=>$root['message_id'],'task_id'=>$root['task_id'],'owner_message_id'=>$approval,'query'=>$query,'hold_effect'=>true]);
    action_mysql_wait(static fn()=>is_file($gate.'/ready-'.$crash),'Pre-commit crash approval did not reach inference.');file_put_contents($gate.'/go-'.$crash,'go');
    action_mysql_wait(static fn()=>is_file($gate.'/effect-'.$crash),'Native effect was not held before the pre-commit crash.');
    proc_terminate($children[$crash]['process'],9);foreach(['stdout','stderr'] as $pipe)fclose($children[$crash][$pipe]);proc_close($children[$crash]['process']);unset($children[$crash]);
    $pending=action_mysql_load($root['message_id']);
    action_mysql_check($pending['domain_action']['run']['status']==='approval_pending'&&(action_mysql_task($root['task_id'])['metadata']['task_status']??'open')==='open'
        &&$count('agent_workflow_receipts')===$before,'killing the process after the native mutation but before receipt commit rolls back the entire effect and preserves the exact pending review');
    $retried=action_mysql_decide($pending,['action_decision'=>'approve'],$query,null,null,$approval);
    action_mysql_check($retried['advisory_context']['verified_action']['status']==='completed'&&$count('agent_workflow_receipts')===$before+1,
        'the same saved approval can safely retry the rolled-back transaction and commit exactly one native result');

    $root=action_mysql_review(action_mysql_seed('Native lost acknowledgement'));$query='Yes, complete before acknowledgement';$approval=action_mysql_owner($query);
    $lost=$start('--approve',['message_id'=>$root['message_id'],'task_id'=>$root['task_id'],'owner_message_id'=>$approval,'query'=>$query,'drop_ack'=>true]);
    action_mysql_wait(static fn()=>is_file($gate.'/ready-'.$lost),'Lost acknowledgement approval did not reach inference.');file_put_contents($gate.'/go-'.$lost,'go');
    action_mysql_wait(static fn()=>is_file($gate.'/committed-'.$lost),'Native action did not commit before the dropped acknowledgement.');
    proc_terminate($children[$lost]['process'],9);foreach(['stdout','stderr'] as $pipe)fclose($children[$lost][$pipe]);proc_close($children[$lost]['process']);unset($children[$lost]);
    $done=action_mysql_load($root['message_id']);$saved=action_mysql_task($root['task_id']);$before=$count('agent_workflow_receipts');
    $reconciled=vp3_initiative_followthrough_current_v1(db(),action_mysql_user(),action_mysql_principal(),10,vp3_initiative_followthrough_outcome_v1($done));
    action_mysql_check($reconciled['verified_action']['status']==='completed'&&$saved['metadata']['task_status']==='completed'
        &&action_mysql_task($root['task_id'])===$saved&&$count('agent_workflow_receipts')===$before,'killing the process after commit and before acknowledgement preserves one verifiable effect without reapplying the domain helper');

    foreach(['source','permission'] as $change){
        $root=action_mysql_review(action_mysql_seed('Native stale '.$change));$before=$count('agent_workflow_receipts');$blocked=false;
        try{action_mysql_decide($root['pending'],['action_decision'=>'approve'],'Yes, approve before a concurrent change',static function()use($change,$root):void{
            $other=new PDO($GLOBALS['actionMysqlDsn'],$GLOBALS['actionMysqlUser'],$GLOBALS['actionMysqlPassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            if($change==='source')$other->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=?')->execute(['Source changed during the actual model call',$root['task_id']]);
            else $other->exec("DELETE FROM role_permissions WHERE role='artist' AND permission_key='agent_brain.access'");
        });}catch(RuntimeException $error){$blocked=in_array((int)$error->getCode(),[403,409],true);}
        action_mysql_check($blocked&&(action_mysql_task($root['task_id'])['metadata']['task_status']??'open')==='open'&&$count('agent_workflow_receipts')===$before,
            'an independently committed '.$change.' change during inference prevents any action effect or completion receipt');
        if($change==='permission')db()->exec("INSERT INTO role_permissions VALUES('artist','agent_brain.access')");
    }
    action_mysql_check(!db()->inTransaction(),'success, stale approval and crash reconciliation release transaction ownership');
    echo 'INITIATIVE_ACTIONS_MYSQL=PASS ('.($GLOBALS['nativeActionChecks']??0).' cases; actual MySQL, production permissions, canonical workflow and native mutations)'.PHP_EOL;
}finally{
    foreach($children as $child){proc_terminate($child['process']);foreach(['stdout','stderr'] as $pipe)if(is_resource($child[$pipe]))fclose($child[$pipe]);proc_close($child['process']);}
    if(db()->inTransaction())db()->rollBack();$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach(glob($gate.'/*')?:[] as $file)unlink($file);if(is_dir($gate))rmdir($gate);
}
