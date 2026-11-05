<?php
declare(strict_types=1);

// Explicit disposable MySQL only. Independent PHP processes exercise the real
// source reader, optimistic proposal revision, canonical queue and final guard.
$dsn=(string)(getenv('VP3_SECTION7_MYSQL_DSN')?:getenv('VP3_MYSQL_TEST_DSN'));
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('An explicit disposable MySQL follow-through fixture DSN is required.');
$password=(string)(getenv('VP3_SECTION7_MYSQL_PASSWORD')?:getenv('VP3_MYSQL_TEST_PASSWORD'));
$databaseUser=(string)(getenv('VP3_SECTION7_MYSQL_USER')?:getenv('VP3_MYSQL_TEST_USER')?:'root');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$mode=(string)($argv[1]??'');$worker=str_starts_with($mode,'--');
$database=$worker?(string)($argv[2]??''):'initiative_followthrough_'.bin2hex(random_bytes(6));
if(!preg_match('/\Ainitiative_followthrough_[a-f0-9]{12}\z/D',$database))throw new RuntimeException('Invalid disposable database name.');
$admin=new PDO($dsn,$databaseUser,$password,$options);
if(!$worker)$admin->exec('CREATE DATABASE `'.$database.'`');
$fixtureDsn=preg_replace('/;dbname=[^;]*/','',$dsn).';dbname='.$database;
putenv('VP3_IMAGE_TEST_DSN='.$fixtureDsn);putenv('VP3_IMAGE_TEST_USER='.$databaseUser);putenv('VP3_IMAGE_TEST_PASSWORD='.$password);
require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__).'/includes/agent-activity-v94.php';
require_once dirname(__DIR__).'/includes/agent-reminders-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-followthrough-v1.php';
$GLOBALS['initiativeBrainAccess']=true;
date_default_timezone_set('UTC');

function agent_brain_schema_ready(): bool{return true;}
function agent_brain_normalize(string $text): string{return strtolower(trim($text));}
function agent_brain_sentences(string $text): array{return [$text];}
function user_calendar_default_timezone_v1300(PDO $pdo,array $user): string{return 'UTC';}
function calendar_schedule_awareness_snapshot_v1330(PDO $pdo,array $user,DateTimeImmutable $now,int $days,bool $includeHomeServer=true): array
{
    if($includeHomeServer)throw new LogicException('The fixture cannot probe a paired calendar.');
    return ['available'=>true,'timezone'=>'UTC','upcoming'=>[],'current'=>null,'conflicts'=>[],'next'=>null,'starts_soon'=>false];
}
function ai_generate_chat_response(...$args): array{throw new LogicException('No native fixture may call a live inference provider.');}
function followthrough_mysql_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);$GLOBALS['followthroughMysqlChecks']=($GLOBALS['followthroughMysqlChecks']??0)+1;echo 'PASS '.$label.PHP_EOL;
}
function followthrough_mysql_wait(callable $ready,string $message): void
{
    $deadline=microtime(true)+20;
    while(!$ready()){if(microtime(true)>$deadline)throw new RuntimeException($message);usleep(10000);}
}
function followthrough_mysql_principal(): array
{
    $user=['id'=>1,'is_active'=>1];return vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380(db(),$user,7));
}
function followthrough_mysql_model(string $prompt,string $protocol): string
{
    if(db()->inTransaction())throw new LogicException('Inference was invoked under a database transaction.');
    $GLOBALS['followthroughMysqlModelCalls']=($GLOBALS['followthroughMysqlModelCalls']??0)+1;
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    if($protocol==='vp3.initiative-reply.v1')return json_encode(['intent'=>$GLOBALS['followthroughMysqlIntent']??'discuss','focus'=>'Prepare a private next step for the verified launch task.'],JSON_THROW_ON_ERROR);
    if($protocol==='vp3.initiative-draft.v1')return json_encode(['draft'=>'Verified private launch preparation from the current saved task.'],JSON_THROW_ON_ERROR);
    throw new LogicException('Unexpected native fixture model protocol.');
}

if($worker){
    $gate=(string)($argv[3]??'');$index=(string)($argv[4]??'');
    if(!preg_match('/\A[0-9]+\z/D',$index)||!is_dir($gate))throw new RuntimeException('Invalid native process barrier.');
    $config=json_decode((string)file_get_contents($gate.'/config-'.$index.'.json'),true,32,JSON_THROW_ON_ERROR);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
    if($mode==='--decision'){
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
        $model=static function(string $prompt,string $protocol)use($gate,$index): string{
            if(db()->inTransaction())throw new LogicException('Decision inference held a transaction.');
            file_put_contents($gate.'/ready-'.$index,'ready');
            followthrough_mysql_wait(static fn()=>is_file($gate.'/go-'.$index),'Decision inference barrier timed out.');
            $GLOBALS['followthroughMysqlIntent']='advance';return followthrough_mysql_model($prompt,$protocol);
        };
        try{
            $result=vp3_initiative_followthrough_reply_v1($pdo,['id'=>1,'is_active'=>1],followthrough_mysql_principal(),$config['cid'],$config['reply'],$config['reference'],$config['query'],null,$model);
            echo json_encode(['ok'=>true,'outcome'=>$result['initiative_followthrough']],JSON_THROW_ON_ERROR).PHP_EOL;
        }catch(RuntimeException $error){echo json_encode(['ok'=>false,'code'=>$error->getCode(),'error'=>$error->getMessage(),
            'error_info'=>$error instanceof PDOException?$error->errorInfo:null],JSON_THROW_ON_ERROR).PHP_EOL;}
        exit;
    }
    if($mode==='--deadlock-peer'){
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=10');$pdo->beginTransaction();
        $pdo->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetchColumn();
        // Give the peer a larger real InnoDB rollback weight, so the short
        // production helper transaction is selected as the deadlock victim.
        $pdo->exec('UPDATE followthrough_native_deadlock_weight SET weight_value=weight_value+1');
        file_put_contents($gate.'/deadlock-owner-'.$config['tag'],'locked');
        followthrough_mysql_wait(static fn()=>is_file($gate.'/deadlock-source-'.$config['tag']),'The deadlock helper did not acquire its source row.');
        if($config['change_source'])$pdo->exec("UPDATE agent_memory_items SET subject='Source changed by the deadlock peer' WHERE id=100");
        else $pdo->query('SELECT id FROM agent_memory_items WHERE id=100 FOR UPDATE')->fetchColumn();
        $pdo->commit();echo json_encode(['ok'=>true,'peer_committed'=>true],JSON_THROW_ON_ERROR).PHP_EOL;exit;
    }
    if($mode==='--deadlock-helper'){
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=10');$attempts=0;$driverCodes=[];
        try{
            $value=vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$gate,$config,&$attempts,&$driverCodes): array{
                ++$attempts;
                $pdo->query('SELECT id FROM agent_memory_items WHERE id=100 FOR UPDATE')->fetchColumn();
                $pdo->prepare('INSERT INTO followthrough_native_retry_markers(run_id,attempt) VALUES(?,?)')->execute([$config['run'],$attempts]);
                if($attempts===1){
                    file_put_contents($gate.'/deadlock-source-'.$config['tag'],'locked');
                    followthrough_mysql_wait(static fn()=>is_file($gate.'/deadlock-owner-'.$config['tag']),'The deadlock peer did not acquire its owner row.');
                }
                try{$pdo->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetchColumn();}
                catch(PDOException $error){$driverCodes[]=(int)($error->errorInfo[1]??0);throw $error;}
                agent_workflow_event_v1400($pdo,1,$config['run'],'fixture_deadlock_attempt','','','system','Native transaction attempt.',['attempt'=>$attempts]);
                vp3_initiative_followthrough_current_v1($pdo,['id'=>1,'is_active'=>1],followthrough_mysql_principal(),$config['cid'],$config['outcome']);
                vp3_initiative_in_scope_v1(['id'=>1,'is_active'=>1],7,static fn()=>vp3_initiative_enqueue_v1($pdo,['id'=>1,'is_active'=>1],
                    'initiative.prepare',$config['goal'],$config['child_hash']));
                $q=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND dedupe_key=?');
                $q->execute([hash('sha256','initiative.prepare|'.$config['child_hash'])]);$child=(int)$q->fetchColumn();
                if($child<1)throw new RuntimeException('The guarded canonical child was not saved.');
                agent_workflow_event_v1400($pdo,1,$config['run'],'fixture_deadlock_effect','','','system','One guarded native effect.',['attempt'=>$attempts]);
                return ['fresh_guard'=>true,'child_id'=>$child];
            });
            echo json_encode(['ok'=>true,'attempts'=>$attempts,'driver_codes'=>$driverCodes,'value'=>$value,'model_calls'=>$GLOBALS['followthroughMysqlModelCalls']??0],JSON_THROW_ON_ERROR).PHP_EOL;
        }catch(RuntimeException $error){echo json_encode(['ok'=>false,'code'=>$error->getCode(),'attempts'=>$attempts,'driver_codes'=>$driverCodes,'model_calls'=>$GLOBALS['followthroughMysqlModelCalls']??0],JSON_THROW_ON_ERROR).PHP_EOL;}
        exit;
    }
    file_put_contents($gate.'/ready-'.$index,'ready');
    followthrough_mysql_wait(static fn()=>is_file($gate.'/go-'.$index),'Raw writer start barrier timed out.');
    file_put_contents($gate.'/entered-'.$index,'entered');
    $write=static function()use($pdo,$config): void{
        if($config['write']==='source')$pdo->prepare('UPDATE agent_memory_items SET subject=?,memory_text=?,last_seen_at=NOW() WHERE id=100')->execute([$config['text'],$config['text']]);
        elseif($config['write']==='chat')$pdo->prepare('UPDATE chat_conversations SET user_agent_id=8 WHERE id=?')->execute([$config['cid']]);
        elseif($config['write']==='tail')$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES (?,1,'user','A newer independent owner turn')")->execute([$config['cid']]);
        else throw new LogicException('Unknown raw native write.');
    };
    $blocked=false;
    try{$write();}
    catch(PDOException $error){if((int)($error->errorInfo[1]??0)!==1205)throw $error;$blocked=true;}
    file_put_contents($gate.'/attempt-'.$index,json_encode(['blocked'=>$blocked],JSON_THROW_ON_ERROR));
    if($blocked){followthrough_mysql_wait(static fn()=>is_file($gate.'/retry-'.$index),'Raw writer retry barrier timed out.');$write();}
    echo json_encode(['blocked'=>$blocked,'completed'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}

$gate=sys_get_temp_dir().'/vp3-initiative-followthrough-'.bin2hex(random_bytes(8));$children=[];$childIndex=0;
try{
    mkdir($gate,0700);
    $pdo->exec("ALTER TABLE chat_conversations ADD COLUMN updated_at DATETIME;
        ALTER TABLE chat_messages ADD CONSTRAINT fk_followthrough_native_chat FOREIGN KEY(conversation_id) REFERENCES chat_conversations(id);
        CREATE TABLE agent_memory_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,user_agent_id INT UNSIGNED NULL,
          memory_type VARCHAR(40),subject VARCHAR(190),memory_text TEXT,memory_hash CHAR(40),memory_scope_version SMALLINT,
          source_archive_id BIGINT NULL,confidence DOUBLE,occurrence_count INT,first_seen_at DATETIME,last_seen_at DATETIME,is_active TINYINT,metadata_json TEXT,
          UNIQUE KEY owner_memory(user_id,memory_hash),KEY idx_agent_memory_agent_type_v410(user_id,user_agent_id,memory_type),
          KEY idx_agent_memory_agent_occurrence_v410(user_id,user_agent_id,last_seen_at)) ENGINE=InnoDB;
        CREATE TABLE agent_chat_archive(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,user_agent_id INT UNSIGNED NULL,created_at DATETIME,
          KEY idx_agent_archive_agent_created_v410(user_id,user_agent_id,created_at)) ENGINE=InnoDB;
        CREATE TABLE user_data_policies(owner_user_id INT UNSIGNED,resource_type VARCHAR(40),resource_id VARCHAR(190),audience_scope VARCHAR(40),
          owner_agents_allowed TINYINT,profile_agent_allowed TINYINT,stonefellow_shared TINYINT) ENGINE=InnoDB;
        CREATE TABLE user_agent_data_rules(agent_id INT UNSIGNED,resource_type VARCHAR(40),resource_id VARCHAR(190),access_mode VARCHAR(40)) ENGINE=InnoDB;
        CREATE TABLE agent_activity_state(user_id INT UNSIGNED PRIMARY KEY,surface VARCHAR(30),context_key VARCHAR(80),task_title VARCHAR(190),
          activity_state VARCHAR(30),last_heartbeat_at DATETIME,idle_since_at DATETIME,details_json TEXT) ENGINE=InnoDB;
        CREATE TABLE agent_activity_events(id INT PRIMARY KEY) ENGINE=InnoDB;
        CREATE TABLE followthrough_native_deadlock_weight(id INT PRIMARY KEY,weight_value INT NOT NULL) ENGINE=InnoDB;
        CREATE TABLE followthrough_native_retry_markers(run_id BIGINT NOT NULL,attempt INT NOT NULL,PRIMARY KEY(run_id,attempt)) ENGINE=InnoDB;
        INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,memory_scope_version,
          confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
          VALUES(100,1,7,'task','Native launch task','Initial private launch source','native-followthrough-task',410,1,1,NOW(),NOW(),1,
          '{\"task_status\":\"open\",\"due_at\":\"2020-01-01T00:00:00Z\"}')");
    $weight=$pdo->prepare('INSERT INTO followthrough_native_deadlock_weight(id,weight_value) VALUES(?,0)');for($id=1;$id<=32;$id++)$weight->execute([$id]);
    $user=['id'=>1,'is_active'=>1];$principal=followthrough_mysql_principal();
    $seed=static function(string $label)use($pdo,$user,$principal): array{
        $cid=(int)$pdo->query('SELECT MAX(id) FROM chat_conversations')->fetchColumn()+1;
        $pdo->prepare('INSERT INTO chat_conversations(id,user_id,user_agent_id,updated_at) VALUES (?,1,7,NOW())')->execute([$cid]);
        $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES (?,1,'user','Review the launch task'),(?,NULL,'assistant','I will review the saved task.')")->execute([$cid,$cid]);
        $pdo->prepare('UPDATE agent_memory_items SET subject=?,memory_text=?,last_seen_at=NOW() WHERE id=100')->execute(['Native launch task '.$label,'Verified launch source '.$label]);
        $pdo->prepare("INSERT INTO agent_activity_state(user_id,surface,context_key,task_title,activity_state,last_heartbeat_at,idle_since_at,details_json)
            VALUES(1,'chat',?,'Launch follow-up','paused',NOW(),NULL,?) ON DUPLICATE KEY UPDATE context_key=VALUES(context_key),last_heartbeat_at=NOW(),details_json=VALUES(details_json)")
            ->execute(['chat:'.$cid,json_encode(['visible'=>true,'conversation_id'=>$cid,'chat_focused'=>true,'chat_busy'=>false],JSON_THROW_ON_ERROR)]);
        vp3_initiative_chat_v1($pdo,$user,$principal,['conversation_id'=>$cid],'on',null);
        $policy=vp3_initiative_policy_v1($user,7);$coverage=[];
        $evidence=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,['task:100'],7);
        if(!isset($evidence['task:100']))throw new RuntimeException('The canonical named task reader did not supply the native source.');
        $goal=['binding'=>$policy,'sources'=>['task:100'=>$evidence['task:100']['revision']],
            'canonical_sources'=>['task:100'=>$evidence['task:100']['canonical_source_revision']??$evidence['task:100']['revision']],
            'tool'=>'work.prepare_private','primary'=>'task:100','focus'=>'Prepare a private launch next step','report'=>true];
        vp3_initiative_in_scope_v1($user,7,static fn()=>vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',$goal,hash('sha256',$label.random_bytes(12))));
        $run=(int)$pdo->query("SELECT MAX(id) FROM agent_workflow_runs WHERE source_kind='agent_initiative'")->fetchColumn();
        $claim=agent_job_claim_run_v1900($pdo,$user,$run,'cloud',agent_worker_cloud_worker_id_v1910(1));
        if(!$claim)throw new RuntimeException('The canonical native claimant did not acquire preparation.');
        $result=vp3_initiative_execute_v1($pdo,$user,$claim,'followthrough_mysql_model');
        if(empty($result['ok']))throw new RuntimeException('Canonical native preparation failed: '.json_encode($result));
        $q=$pdo->prepare("SELECT * FROM chat_messages WHERE conversation_id=? AND context_json LIKE '%agent_initiative%' ORDER BY id DESC LIMIT 1");$q->execute([$cid]);$message=$q->fetch();
        $context=json_decode($message['context_json'],true,32,JSON_THROW_ON_ERROR);$reference=$context['initiative_work']['followthrough'];unset($reference['state']);$reference=['message_id'=>(int)$message['id']]+$reference;
        return ['cid'=>$cid,'run'=>$run,'message'=>(int)$message['id'],'reference'=>$reference];
    };
    $reply=static function(array $root,string $query='Discuss this exact source')use($pdo): array{
        $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES (?,1,'user',?)")->execute([$root['cid'],$query]);
        return $root+['reply'=>(int)$pdo->lastInsertId(),'query'=>$query];
    };
    $discuss=static function(array $root)use($pdo,$user,$principal,$reply): array{
        $turn=$reply($root);$GLOBALS['followthroughMysqlIntent']='discuss';
        $selection=vp3_initiative_followthrough_reply_v1($pdo,$user,$principal,$turn['cid'],$turn['reply'],$turn['reference'],$turn['query'],null,'followthrough_mysql_model');
        return $turn+['outcome'=>$selection['initiative_followthrough']];
    };
    $start=static function(string $workerMode,array $config)use($database,$gate,&$children,&$childIndex): int{
        $index=++$childIndex;file_put_contents($gate.'/config-'.$index.'.json',json_encode($config,JSON_THROW_ON_ERROR));
        $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,$workerMode,$database,$gate,(string)$index],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Could not start an independent native worker.');fclose($pipes[0]);
        $children[$index]=['process'=>$process,'stdout'=>$pipes[1],'stderr'=>$pipes[2]];return $index;
    };
    $finish=static function(int $index)use(&$children): array{
        $child=$children[$index];$output=stream_get_contents($child['stdout']);$errors=stream_get_contents($child['stderr']);
        fclose($child['stdout']);fclose($child['stderr']);$exit=proc_close($child['process']);unset($children[$index]);
        if($exit!==0)throw new RuntimeException('Independent native worker failed: '.$errors.$output);
        return json_decode(trim($output),true,32,JSON_THROW_ON_ERROR);
    };

    $turn=$reply($seed('double-decision'),'Please prepare this private next step');
    $beforeRunIds=array_map('intval',$pdo->query('SELECT id FROM agent_workflow_runs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $first=$start('--decision',$turn);$second=$start('--decision',$turn);
    followthrough_mysql_wait(static fn()=>is_file($gate.'/ready-'.$first)&&is_file($gate.'/ready-'.$second),'Both intent models did not reach the concurrent barrier.');
    file_put_contents($gate.'/go-'.$first,'go');file_put_contents($gate.'/go-'.$second,'go');
    $decisions=[$finish($first),$finish($second)];$accepted=array_values(array_filter($decisions,static fn(array $row):bool=>$row['ok']));$rejected=array_values(array_filter($decisions,static fn(array $row):bool=>!$row['ok']));
    $q=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_runs WHERE source_kind='agent_initiative' AND JSON_EXTRACT(goal,'$.followthrough.message_id')=?");$q->execute([$turn['message']]);
    $rawJsonCount=(int)$q->fetchColumn();
    $newRuns=array_values(array_filter($pdo->query('SELECT id,owner_user_id,agent_id,source_kind,capability_key,status FROM agent_workflow_runs ORDER BY id')->fetchAll(),
        static fn(array $row):bool=>!in_array((int)$row['id'],$beforeRunIds,true)));
    $q=$pdo->prepare("SELECT id,status,goal FROM agent_workflow_runs WHERE owner_user_id=? AND agent_id=? AND source_kind=? AND capability_key=? ORDER BY id");
    $q->execute([1,7,'agent_initiative','initiative.prepare']);$childrenForRoot=[];
    foreach($q->fetchAll() as $row){
        $goal=json_decode($row['goal'],true,32,JSON_THROW_ON_ERROR);$relation=$goal['followthrough']??null;
        if(is_array($relation)&&($relation['message_id']??null)===$turn['message']&&($relation['root_run_id']??null)===$turn['run']){
            $childrenForRoot[]=['id'=>(int)$row['id'],'status'=>$row['status'],'followthrough'=>$relation,'binding'=>$goal['binding']??null];
        }
    }
    $acceptedOutcome=$accepted[0]['outcome']??[];$child=$childrenForRoot[0]??[];$relation=$child['followthrough']??[];
    $diagnostic=json_encode(['raw_json_count'=>$rawJsonCount,'strict_decoded_count'=>count($childrenForRoot),
        'decisions'=>$decisions,'new_runs'=>$newRuns,'decoded_children'=>$childrenForRoot],JSON_THROW_ON_ERROR);
    echo 'INFO double-decision '.$diagnostic.PHP_EOL;
    followthrough_mysql_check(count($accepted)===1&&count($rejected)===1&&$rejected[0]['code']===409
        &&count($newRuns)===1&&count($childrenForRoot)===1&&(int)$newRuns[0]['id']===$child['id']
        &&($acceptedOutcome['continuation_run_id']??null)===$child['id']&&($acceptedOutcome['state']??null)==='queued'
        &&($acceptedOutcome['owner_user_id']??null)===1&&($acceptedOutcome['agent_id']??null)===7
        &&($acceptedOutcome['conversation_id']??null)===$turn['cid']&&($acceptedOutcome['message_id']??null)===$turn['message']
        &&($acceptedOutcome['run_id']??null)===$turn['run']&&($acceptedOutcome['reply_message_id']??null)===$turn['reply']
        &&($acceptedOutcome['reply_ref']??null)===$turn['reference']['reply_ref']&&($acceptedOutcome['revision']??null)===$turn['reference']['revision']+1
        &&($relation['reply_message_id']??null)===$turn['reply']&&($relation['reply_ref']??null)===$turn['reference']['reply_ref']
        &&($relation['revision']??null)===$acceptedOutcome['revision']&&($relation['reply_hash']??null)===hash('sha256',$turn['query'])
        &&($child['binding']['conversation_id']??null)===$turn['cid'],
        'two independent current-revision decisions commit exactly one canonical private child; '.$diagnostic);
    $q=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_events WHERE run_id=? AND event_type='initiative_owner_decision'");$q->execute([$turn['run']]);
    followthrough_mysql_check((int)$q->fetchColumn()===1,'the losing decision writes no duplicate owner event');

    $ack=static function(array $turn)use($pdo,$user,$principal): void{
        vp3_initiative_followthrough_begin_v1($pdo);
        vp3_initiative_followthrough_current_v1($pdo,$user,$principal,$turn['cid'],$turn['outcome']);
        $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES (?,NULL,'assistant','Verified natural acknowledgement',?)")
            ->execute([$turn['cid'],json_encode(['initiative_followthrough'=>$turn['outcome']],JSON_THROW_ON_ERROR)]);
    };
    // Reproduce the old final-read window first, then roll it back. The same
    // real source read below acquires a shared lock through the one-shot helper.
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $turn=$discuss($seed('unlocked-control'));$pdo->beginTransaction();
    vp3_initiative_followthrough_current_v1($pdo,$user,$principal,$turn['cid'],$turn['outcome']);
    $index=$start('--write',['write'=>'source','cid'=>$turn['cid'],'text'=>'Control write after a plain final source read']);
    followthrough_mysql_wait(static fn()=>is_file($gate.'/ready-'.$index),'The control source writer was not ready.');file_put_contents($gate.'/go-'.$index,'go');
    followthrough_mysql_wait(static fn()=>is_file($gate.'/attempt-'.$index),'The control source writer did not attempt its update.');
    $attempt=json_decode((string)file_get_contents($gate.'/attempt-'.$index),true,16,JSON_THROW_ON_ERROR);
    if($attempt['blocked']){$pdo->rollBack();file_put_contents($gate.'/retry-'.$index,'retry');}
    $result=$finish($index);$changed=false;
    if($pdo->inTransaction()){
        try{vp3_initiative_followthrough_current_v1($pdo,$user,$principal,$turn['cid'],$turn['outcome']);}
        catch(RuntimeException $error){$changed=$error->getCode()===409;}
        $pdo->rollBack();
    }
    followthrough_mysql_check($attempt['blocked']===false&&$result['completed']===true&&$changed,
        'the READ COMMITTED control reproduces an independent source mutation after a previously valid final read');

    foreach(['source','chat','tail'] as $write){
        $turn=$discuss($seed('blocked-'.$write));$ack($turn);
        $index=$start('--write',['write'=>$write,'cid'=>$turn['cid'],'text'=>'Independent source update after final acknowledgement']);
        followthrough_mysql_wait(static fn()=>is_file($gate.'/ready-'.$index),'The independent raw writer was not ready.');file_put_contents($gate.'/go-'.$index,'go');
        followthrough_mysql_wait(static fn()=>is_file($gate.'/attempt-'.$index),'The raw writer did not complete its bounded lock attempt.');
        $attempt=json_decode((string)file_get_contents($gate.'/attempt-'.$index),true,16,JSON_THROW_ON_ERROR);
        followthrough_mysql_check($attempt['blocked']===true,'the production final acknowledgement transaction blocks an independent '.$write.' writer');
        $pdo->commit();file_put_contents($gate.'/retry-'.$index,'retry');$result=$finish($index);
        followthrough_mysql_check($result['blocked']===true&&$result['completed']===true,'the same '.$write.' writer proceeds only after the final acknowledgement commits');
        $cached=['ok'=>true,'conversation_id'=>$turn['cid'],'agent_id'=>7,'initiative_followthrough'=>$turn['outcome']];
        $input=['conversation_id'=>$turn['cid'],'message'=>$turn['query'],'initiative_reply'=>$turn['reference']];
        try{vp3_initiative_followthrough_replay_v1($pdo,$user,$principal,$input,$cached);$stale=false;}
        catch(RuntimeException $error){$stale=in_array($error->getCode(),[403,409],true);}
        followthrough_mysql_check($stale,'a cached acknowledgement cannot replay after the independent '.$write.' mutation commits');
    }

    $turn=$discuss($seed('writer-first'));
    $other=new PDO($fixtureDsn,$databaseUser,$password,$options);$other->exec("UPDATE agent_memory_items SET subject='Writer committed before final validation',memory_text='Writer committed before final validation',last_seen_at=NOW() WHERE id=100");
    $before=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
    try{$ack($turn);$stale=false;}
    catch(RuntimeException $error){$stale=$error->getCode()===409;}
    finally{if($pdo->inTransaction())$pdo->rollBack();}
    followthrough_mysql_check($stale&&(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$before,
        'an independent source write committed first prevents any stale final acknowledgement');

    // This read permits a raw writer only if the helper changed the next final
    // transaction, without leaving SERIALIZABLE on the reusable connection.
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $turn=$discuss($seed('one-shot-isolation'));$ack($turn);$pdo->commit();
    $pdo->beginTransaction();$pdo->query('SELECT memory_text FROM agent_memory_items WHERE id=100')->fetchColumn();
    $index=$start('--write',['write'=>'source','cid'=>$turn['cid'],'text'=>'Ordinary transaction must not retain serializable source locks']);
    followthrough_mysql_wait(static fn()=>is_file($gate.'/ready-'.$index),'Isolation writer was not ready.');file_put_contents($gate.'/go-'.$index,'go');
    followthrough_mysql_wait(static fn()=>is_file($gate.'/attempt-'.$index),'Isolation writer did not attempt its update.');
    $attempt=json_decode((string)file_get_contents($gate.'/attempt-'.$index),true,16,JSON_THROW_ON_ERROR);$pdo->rollBack();
    if($attempt['blocked'])file_put_contents($gate.'/retry-'.$index,'retry');$result=$finish($index);
    followthrough_mysql_check($attempt['blocked']===false&&$result['completed']===true,
        'the next unrelated READ COMMITTED transaction retains its prior isolation and permits an independent source writer');

    foreach([false,true] as $changeSource){
        $turn=$discuss($seed($changeSource?'deadlock-stale':'deadlock-retry'));
        $q=$pdo->prepare('SELECT goal FROM agent_workflow_runs WHERE id=?');$q->execute([$turn['run']]);$goal=json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
        $childHash=hash('sha256','native-deadlock-child:'.$turn['run']);
        $config=$turn+['tag'=>$changeSource?'stale':'retry','change_source'=>$changeSource,'goal'=>$goal,'child_hash'=>$childHash];
        $peer=$start('--deadlock-peer',$config);
        followthrough_mysql_wait(static fn()=>is_file($gate.'/deadlock-owner-'.$config['tag']),'The deadlock peer did not acquire its independent owner lock.');
        $victim=$start('--deadlock-helper',$config);$peerResult=$finish($peer);$retried=$finish($victim);
        $q=$pdo->prepare("SELECT event_type,metadata_json FROM agent_workflow_events WHERE run_id=? AND event_type IN ('fixture_deadlock_attempt','fixture_deadlock_effect') ORDER BY id");$q->execute([$turn['run']]);$events=$q->fetchAll();
        $q=$pdo->prepare('SELECT attempt FROM followthrough_native_retry_markers WHERE run_id=? ORDER BY attempt');$q->execute([$turn['run']]);$markers=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        $q=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND dedupe_key=?');$q->execute([hash('sha256','initiative.prepare|'.$childHash)]);$canonicalChildren=$q->fetchAll(PDO::FETCH_COLUMN);
        if(!$changeSource){
            followthrough_mysql_check($peerResult['peer_committed']&&$retried['ok']&&$retried['attempts']===2&&$retried['driver_codes']===[1213]
                &&count($events)===2&&array_column($events,'event_type')===['fixture_deadlock_attempt','fixture_deadlock_effect']
                &&$markers===[2]&&count($canonicalChildren)===1&&$retried['model_calls']===0
                &&json_decode($events[0]['metadata_json'],true)['attempt']===2&&json_decode($events[1]['metadata_json'],true)['attempt']===2,
                'an actual InnoDB 1213 cycle rolls back the first attempt and commits one freshly guarded canonical effect on the single retry');
            $child=(int)$canonicalChildren[0];$claim=agent_job_claim_run_v1900($pdo,$user,$child,'cloud',agent_worker_cloud_worker_id_v1910(1));
            if(!$claim)throw new RuntimeException('The actual deadlock-recovered child could not be claimed.');
            $modelCalls=(int)($GLOBALS['followthroughMysqlModelCalls']??0);$completed=vp3_initiative_execute_v1($pdo,$user,$claim,'followthrough_mysql_model');
            $q=$pdo->prepare('SELECT a.status,a.result_json,p.result_json AS receipt_result FROM agent_workflow_actions a JOIN agent_workflow_receipts p ON p.action_id=a.id AND p.run_id=a.run_id WHERE a.run_id=?');$q->execute([$child]);$receipts=$q->fetchAll();
            followthrough_mysql_check(!empty($completed['ok'])&&count($receipts)===1&&$receipts[0]['status']==='completed'
                &&$receipts[0]['result_json']===$receipts[0]['receipt_result']&&(int)$GLOBALS['followthroughMysqlModelCalls']===$modelCalls+1,
                'the one recovered canonical child executes once outside the retry transaction and saves one matching actual receipt without model replay');
        }else{
            followthrough_mysql_check($peerResult['peer_committed']&&!$retried['ok']&&$retried['code']===409&&$retried['attempts']===2
                &&$retried['driver_codes']===[1213]&&$events===[]&&$markers===[]&&$canonicalChildren===[]&&$retried['model_calls']===0,
                'the deadlock retry rereads a source changed by its peer and rolls back every stale canonical effect');
        }
    }
    $turn=$discuss($seed('lock-timeout-no-retry'));$other->beginTransaction();
    $other->query('SELECT id FROM agent_memory_items WHERE id=100 FOR UPDATE')->fetchColumn();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');$attempts=0;$driverCode=0;
    try{
        vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$principal,$turn,&$attempts): void{
            ++$attempts;agent_workflow_event_v1400($pdo,1,$turn['run'],'fixture_timeout_effect','','','system','This effect must roll back.');
            vp3_initiative_followthrough_current_v1($pdo,$user,$principal,$turn['cid'],$turn['outcome']);
        });
    }catch(PDOException $error){$driverCode=(int)($error->errorInfo[1]??0);}
    finally{$other->rollBack();}
    $q=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_events WHERE run_id=? AND event_type='fixture_timeout_effect'");$q->execute([$turn['run']]);
    followthrough_mysql_check($driverCode===1205&&$attempts===1&&(int)$q->fetchColumn()===0,
        'a real InnoDB 1205 lock timeout rolls back its effect and is never retried as a deadlock');
    echo 'AGENT_INITIATIVE_FOLLOWTHROUGH_MYSQL=PASS ('.$GLOBALS['followthroughMysqlChecks'].' cases)'.PHP_EOL;
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($children as $child){proc_terminate($child['process']);foreach(['stdout','stderr'] as $pipe)if(is_resource($child[$pipe]))fclose($child[$pipe]);proc_close($child['process']);}
    foreach(glob($gate.'/*')?:[] as $file)unlink($file);if(is_dir($gate))rmdir($gate);
    $admin->exec('DROP DATABASE `'.$database.'`');
}
