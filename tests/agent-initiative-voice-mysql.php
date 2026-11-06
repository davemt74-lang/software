<?php
declare(strict_types=1);

// Native MySQL only. Each invocation owns one randomly named disposable DB.
// Diagnostics must never expose connection data, provider text or claim tokens.
ini_set('zend.exception_ignore_args','1');umask(0077);date_default_timezone_set('UTC');
$GLOBALS['voiceNativePhase']='startup';$GLOBALS['voiceNativeBarrierEvidence']=[];
set_exception_handler(static function(Throwable $error):void{
    $state=$error instanceof PDOException?(string)($error->errorInfo[0]??''):'';
    fwrite(STDERR,json_encode(['fixture_failure'=>true,'error_class'=>get_class($error),
        'public_code'=>is_int($error->getCode())?$error->getCode():0,
        'sql_state'=>preg_match('/\A[A-Z0-9]{5}\z/D',$state)?$state:'',
        'driver_code'=>$error instanceof PDOException?(int)($error->errorInfo[1]??0):0,
        'file'=>basename($error->getFile()),'line'=>$error->getLine(),
        'phase'=>$GLOBALS['voiceNativePhase']??'unknown','evidence'=>$GLOBALS['voiceNativeBarrierEvidence']??[]],JSON_THROW_ON_ERROR).PHP_EOL);exit(1);
});
$dsn=(string)(getenv('VP3_INITIATIVE_VOICE_MYSQL_DSN')?:getenv('VP3_MYSQL_TEST_DSN'));
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('An explicit disposable MySQL voice fixture DSN is required.');
$databaseUser=(string)(getenv('VP3_INITIATIVE_VOICE_MYSQL_USER')?:getenv('VP3_MYSQL_TEST_USER')?:'root');
$password=(string)(getenv('VP3_INITIATIVE_VOICE_MYSQL_PASSWORD')?:getenv('VP3_MYSQL_TEST_PASSWORD'));
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$mode=(string)($argv[1]??'');$worker=$mode==='--claim';
if($mode!==''&&!$worker)throw new RuntimeException('Invalid native voice worker mode.');
$database=$worker?(string)($argv[2]??''):'initiative_voice_'.bin2hex(random_bytes(6));
if(!preg_match('/\Ainitiative_voice_[a-f0-9]{12}\z/D',$database))throw new RuntimeException('Invalid disposable voice database name.');
$parts=array_values(array_filter(explode(';',substr($dsn,6)),static fn(string $part):bool=>preg_match('/\A\s*dbname\s*=/i',$part)!==1));
$fixtureDsn='mysql:'.implode(';',$parts).';dbname='.$database;
$admin=new PDO($dsn,$databaseUser,$password,$options);$created=false;$children=[];$gate='';$pdo=null;

// Host account entitlement/calendar/provider IO only. Source, scope, workflow,
// lease, check-in, claim and receipt code below are the production functions.
function agent_brain_schema_ready():bool{return true;}
function agent_brain_normalize(string $text):string{return strtolower(trim($text));}
function agent_brain_sentences(string $text):array{return [$text];}
function user_has_role(string $role,array $user):bool{return $role==='admin'&&(int)$user['id']===1;}
function user_calendar_default_timezone_v1300(PDO $pdo,array $user):string{return 'UTC';}
function calendar_schedule_awareness_snapshot_v1330(PDO $pdo,array $user,DateTimeImmutable $now,int $days,bool $includeHomeServer=true):array
{
    if($includeHomeServer)throw new LogicException('A native voice fixture cannot probe paired calendars.');
    return ['available'=>true,'timezone'=>'UTC','upcoming'=>[],'current'=>null,'conflicts'=>[],'next'=>null,'starts_soon'=>false];
}
function ai_generate_chat_response(...$arguments):array{throw new LogicException('A native voice operation cannot call a live provider.');}
function voice_mysql_check(bool $ok,string $label):void
{
    if(!$ok){fwrite(STDERR,'FAIL '.$label.PHP_EOL);throw new RuntimeException('A native voice assertion failed.');}
    $GLOBALS['voiceNativeChecks']=($GLOBALS['voiceNativeChecks']??0)+1;echo 'PASS '.$label.PHP_EOL;
}
function voice_mysql_phase(string $phase):void
{
    if(preg_match('/\A[a-z0-9._-]{1,100}\z/D',$phase)!==1)throw new LogicException('Invalid native voice phase.');
    $GLOBALS['voiceNativePhase']=$phase;
    if(isset($GLOBALS['voiceNativePhaseFile']))file_put_contents($GLOBALS['voiceNativePhaseFile'],$phase);
}
function voice_mysql_child_evidence(array $child,string $gate,int $id):array
{
    $status=proc_get_status($child['process']);$phase=is_file($gate.'/phase-'.$id)?(string)file_get_contents($gate.'/phase-'.$id):'not_started';
    $safe=['running'=>(bool)$status['running'],'exit_code'=>(int)$status['exitcode'],
        'child_phase'=>preg_match('/\A[a-z0-9._-]{1,100}\z/D',$phase)?$phase:'unknown'];
    if(!$status['running']&&is_file($gate.'/result-'.$id.'.json')){
        $value=json_decode((string)file_get_contents($gate.'/result-'.$id.'.json'),true);
        foreach(['ok','in_transaction'] as $key)if(is_bool($value[$key]??null))$safe[$key]=$value[$key];
        foreach(['code','model_calls'] as $key)if(is_int($value[$key]??null))$safe[$key]=$value[$key];
    }
    return $safe;
}
function voice_mysql_owner_wait(PDO $observer,int $waiter,int $blocker,string $database):bool
{
    // Require the actual live edge on users(1), with both connection identities.
    // A transaction snapshot or a still-running process is not lock evidence.
    $q=$observer->prepare("SELECT 1 FROM performance_schema.data_lock_waits w
        JOIN performance_schema.threads requester ON requester.THREAD_ID=w.REQUESTING_THREAD_ID
        JOIN performance_schema.threads blocker ON blocker.THREAD_ID=w.BLOCKING_THREAD_ID
        JOIN performance_schema.data_locks requested ON requested.ENGINE=w.ENGINE
            AND requested.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID
        WHERE requester.PROCESSLIST_ID=? AND blocker.PROCESSLIST_ID=?
            AND requested.OBJECT_SCHEMA=? AND requested.OBJECT_NAME='users'
            AND requested.INDEX_NAME='PRIMARY' AND requested.LOCK_TYPE='RECORD'
            AND requested.LOCK_STATUS='WAITING' AND requested.LOCK_DATA='1' LIMIT 1");
    $q->execute([$waiter,$blocker,$database]);return $q->fetchColumn()!==false;
}
function voice_mysql_wait(callable $ready,string $phase,?callable $evidence=null):void
{
    voice_mysql_phase($phase);
    $deadline=microtime(true)+20;
    while(!$ready()){
        $details=$evidence===null?[]:$evidence();
        if(($details['running']??true)===false||microtime(true)>$deadline){
            $GLOBALS['voiceNativeBarrierEvidence']=$details;throw new RuntimeException('A native voice barrier failed.');
        }
        usleep(10000);
    }
}
function voice_mysql_model(string $prompt,string $protocol):string
{
    if(db()->inTransaction())throw new LogicException('Native voice preparation invoked inference inside a transaction.');
    $GLOBALS['voiceNativeModelCalls']=($GLOBALS['voiceNativeModelCalls']??0)+1;
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    if($protocol==='vp3.initiative-plan.v1'){
        if(empty($input['tools']['chat.check_in']['ready']))throw new LogicException('The real planner did not expose a ready check-in.');
        return json_encode(['jobs'=>[['tool'=>'chat.check_in','primary'=>'task:100','refs'=>['task:100'],
            'focus'=>'Offer one useful private launch next step','report'=>true]]],JSON_THROW_ON_ERROR);
    }
    if($protocol==='vp3.initiative-draft.v1'&&($input['response_kind']??null)==='chat.check_in'){
        return json_encode(['draft'=>'The launch task is ready for review. Would a focused agenda help?','delivery'=>'voice'],JSON_THROW_ON_ERROR);
    }
    throw new LogicException('Unexpected native voice inference protocol.');
}
function voice_mysql_call(string $action,array $config):array
{
    return vp3_initiative_voice_v1(db(),['id'=>1,'is_active'=>1],7,$config['cid'],$action,$config['input']);
}
function voice_mysql_saved(int $run):array
{
    $q=db()->prepare("SELECT a.result_json,rc.result_json AS receipt_result FROM agent_workflow_actions a
        JOIN agent_workflow_receipts rc ON rc.run_id=a.run_id AND rc.action_id=a.id AND rc.owner_user_id=a.owner_user_id
        WHERE a.run_id=? AND a.status='completed' AND rc.status='completed'");
    $q->execute([$run]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1||!hash_equals((string)$rows[0]['result_json'],(string)$rows[0]['receipt_result']))
        throw new RuntimeException('Native voice canonical action and receipt diverged.');
    return ['json'=>$rows[0]['result_json'],'result'=>json_decode($rows[0]['result_json'],true,32,JSON_THROW_ON_ERROR)];
}
function voice_mysql_presence(int $cid,string $tab):void
{
    $details=json_encode(['visible'=>true,'focused'=>true,'conversation_id'=>$cid,'chat_focused'=>true,'chat_busy'=>false,'activity_tab'=>$tab],JSON_THROW_ON_ERROR);
    db()->prepare("INSERT INTO agent_activity_state(user_id,surface,context_key,task_title,activity_state,last_heartbeat_at,idle_since_at,details_json)
        VALUES(1,'chat',?,'Launch task','paused',UTC_TIMESTAMP(),NULL,?) ON DUPLICATE KEY UPDATE surface='chat',context_key=VALUES(context_key),
        activity_state='paused',last_heartbeat_at=UTC_TIMESTAMP(),details_json=VALUES(details_json)")->execute(['chat:'.$cid,$details]);
}
function voice_mysql_seed(string $label):array
{
    $pdo=db();$user=['id'=>1,'is_active'=>1];
    $pdo->exec('UPDATE users SET is_active=1 WHERE id=1;UPDATE team_user_presence SET agent_voice_enabled=1 WHERE user_id=1');
    $pdo->prepare('UPDATE agent_memory_items SET is_active=1,subject=?,memory_text=?,last_seen_at=UTC_TIMESTAMP() WHERE id=100')
        ->execute(['Native voice launch '.$label,'Verified private launch source '.$label]);
    $cid=(int)$pdo->query('SELECT MAX(id) FROM chat_conversations')->fetchColumn()+1;
    $pdo->prepare('INSERT INTO chat_conversations(id,user_id,user_agent_id,updated_at) VALUES(?,1,7,UTC_TIMESTAMP())')->execute([$cid]);
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,1,'user','Review the saved launch task.'),(?,NULL,'assistant','I can review that saved source.')")
        ->execute([$cid,$cid]);
    voice_mysql_presence($cid,str_repeat('a',32));
    $principal=vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380($pdo,$user,7));
    vp3_initiative_chat_v1($pdo,$user,$principal,['conversation_id'=>$cid],'on',null);
    $policy=vp3_initiative_policy_v1($user,7);$coverage=[];
    $evidence=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,['task:100'],7);
    if(!isset($evidence['task:100']))throw new RuntimeException('The actual native task source is unavailable.');
    $goal=['binding'=>$policy,'sources'=>['task:100'=>$evidence['task:100']['revision']],
        'canonical_sources'=>['task:100'=>$evidence['task:100']['canonical_source_revision']??$evidence['task:100']['revision']],'capability_sources'=>[]];
    $before=(int)($GLOBALS['voiceNativeModelCalls']??0);
    vp3_initiative_in_scope_v1($user,7,static fn()=>vp3_initiative_enqueue_v1($pdo,$user,'initiative.plan',$goal,hash('sha256','native-voice:'.$label.random_bytes(12))));
    $plan=(int)$pdo->query("SELECT MAX(id) FROM agent_workflow_runs WHERE capability_key='initiative.plan'")->fetchColumn();
    $claim=agent_job_claim_run_v1900($pdo,$user,$plan,'cloud',agent_worker_cloud_worker_id_v1910(1));
    if(!$claim||empty(vp3_initiative_execute_v1($pdo,$user,$claim,'voice_mysql_model')['ok']))throw new RuntimeException('The real native voice plan did not complete.');
    $q=$pdo->prepare("SELECT id,goal FROM agent_workflow_runs WHERE owner_user_id=1 AND agent_id=7 AND capability_key='initiative.prepare' ORDER BY id DESC LIMIT 1");
    $q->execute();$preparation=$q->fetch(PDO::FETCH_ASSOC);$run=(int)($preparation['id']??0);
    $preparedGoal=json_decode((string)($preparation['goal']??''),true,32,JSON_THROW_ON_ERROR);
    if(($preparedGoal['binding']??null)!==$policy||($preparedGoal['tool']??null)!=='chat.check_in')throw new RuntimeException('The planner did not bind the expected native check-in.');
    $claim=agent_job_claim_run_v1900($pdo,$user,$run,'cloud',agent_worker_cloud_worker_id_v1910(1));
    if(!$claim||empty(vp3_initiative_execute_v1($pdo,$user,$claim,'voice_mysql_model')['ok']))throw new RuntimeException('The real native voice preparation did not complete.');
    $saved=voice_mysql_saved($run)['result'];$message=vp3_initiative_followthrough_id_v1($saved['initiative_message_id']??null);
    if($message<1||($saved['initiative_delivery']??null)!=='delivered'||($saved['initiative_tool_result']['output']['delivery_preference']??null)!=='voice'
        ||mb_strlen($saved['initiative_draft']??'')>320||($GLOBALS['voiceNativeModelCalls']??0)!==$before+2)
        throw new RuntimeException('The actual worker did not deliver one model-selected short voice check-in.');
    voice_mysql_presence($cid,str_repeat('a',32));
    return ['cid'=>$cid,'input'=>['run_id'=>$run,'message_id'=>$message,'request_id'=>'11111111-1111-4111-8111-111111111111',
        'activity_tab'=>str_repeat('a',32),'working_attention_binding'=>vp3_catch_up_page_binding_v1($user)]];
}

try{
    if(!$worker){$admin->exec('CREATE DATABASE `'.$database.'`');$created=true;}
    $pdo=new PDO($fixtureDsn,$databaseUser,$password,$options);
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable native voice database binding could not be verified.');
    // Reuse only the established isolated host-I/O/schema scaffolding. Its DSN
    // is the already verified random database, never an application database.
    putenv('VP3_IMAGE_TEST_DSN='.$fixtureDsn);putenv('VP3_IMAGE_TEST_USER='.$databaseUser);putenv('VP3_IMAGE_TEST_PASSWORD='.$password);
    require __DIR__.'/fixtures/image-generation-v1.php';
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Native voice fixture connection changed.');
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$GLOBALS['pdo']=$pdo;
    $pdo->exec("SET SESSION time_zone='+00:00'");$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
    require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
    require_once dirname(__DIR__).'/includes/agent-brain-v122.php';
    require_once dirname(__DIR__).'/includes/agent-activity-v94.php';
    require_once dirname(__DIR__).'/includes/agent-reminders-v1.php';
    require_once dirname(__DIR__).'/includes/agent-initiative-followthrough-v1.php';
    require_once dirname(__DIR__).'/includes/agent-initiative-tools-v1.php';
    require_once dirname(__DIR__).'/includes/agent-initiative-voice-v1.php';
    $GLOBALS['initiativeBrainAccess']=true;

    if($worker){
        $gate=(string)($argv[3]??'');$index=(string)($argv[4]??'');
        if(!is_dir($gate)||!preg_match('/\A[1-9][0-9]*\z/D',$index))throw new RuntimeException('Invalid native voice process barrier.');
        $GLOBALS['voiceNativePhaseFile']=$gate.'/phase-'.$index;
        $config=json_decode((string)file_get_contents($gate.'/config-'.$index.'.json'),true,32,JSON_THROW_ON_ERROR);
        file_put_contents($gate.'/ready-'.$index,json_encode(['connection_id'=>(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()],JSON_THROW_ON_ERROR));
        voice_mysql_wait(static fn()=>is_file($gate.'/go-'.$index),'worker.go');voice_mysql_phase('worker.claim');
        try{$result=['ok'=>true,'response'=>voice_mysql_call('voice_claim',$config)];}
        catch(RuntimeException $error){$result=['ok'=>false,'code'=>(int)$error->getCode()];}
        $result['in_transaction']=$pdo->inTransaction();$result['model_calls']=$GLOBALS['voiceNativeModelCalls']??0;
        // Claim text/token stays in a private process-result file, not CI logs.
        file_put_contents($gate.'/result-'.$index.'.json',json_encode($result,JSON_THROW_ON_ERROR));
        echo json_encode(['finished'=>true],JSON_THROW_ON_ERROR).PHP_EOL;exit;
    }

    $pdo->exec("ALTER TABLE chat_conversations ADD COLUMN updated_at DATETIME;
        ALTER TABLE chat_messages ADD CONSTRAINT fk_voice_native_chat FOREIGN KEY(conversation_id) REFERENCES chat_conversations(id);
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
        CREATE TABLE team_user_presence(user_id INT UNSIGNED PRIMARY KEY,presence_mode VARCHAR(20),social_chat_enabled TINYINT,sound_enabled TINYINT,agent_voice_enabled TINYINT) ENGINE=InnoDB;
        INSERT INTO team_user_presence VALUES(1,'online',1,1,1);
        INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,memory_scope_version,
          confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
          VALUES(100,1,7,'task','Native voice launch task','Private launch source','native-voice-launch-task',410,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1,
          '{\"task_status\":\"open\",\"due_at\":\"2020-01-01T00:00:00Z\"}')");
    $gate=sys_get_temp_dir().'/initiative-voice-native-'.bin2hex(random_bytes(8));mkdir($gate,0700);$index=0;
    $start=static function(array $config)use($database,$gate,&$children,&$index):array{
        $id=++$index;file_put_contents($gate.'/config-'.$id.'.json',json_encode($config,JSON_THROW_ON_ERROR));
        $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--claim',$database,$gate,(string)$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('An independent native voice process could not start.');
        fclose($pipes[0]);$children[$id]=['process'=>$process,'stdout'=>$pipes[1],'stderr'=>$pipes[2]];
        voice_mysql_wait(static fn()=>is_file($gate.'/ready-'.$id),($GLOBALS['voiceNativeCase']??'concurrent').'.child_ready',
            static fn()=>voice_mysql_child_evidence($children[$id],$gate,$id));
        return [$id,json_decode((string)file_get_contents($gate.'/ready-'.$id),true,16,JSON_THROW_ON_ERROR)];
    };
    $finish=static function(int $id)use(&$children,$gate):array{
        $child=$children[$id];$status=[];
        voice_mysql_wait(static function()use($child,&$status):bool{$status=proc_get_status($child['process']);return !$status['running'];},
            ($GLOBALS['voiceNativeCase']??'concurrent').'.child_finish');
        $stdout=stream_get_contents($child['stdout']);$stderr=stream_get_contents($child['stderr']);
        fclose($child['stdout']);fclose($child['stderr']);$closed=proc_close($child['process']);unset($children[$id]);
        $exit=(int)($status['exitcode']??$closed);if($exit<0)$exit=$closed;
        if($exit!==0||trim($stderr)!==''||json_decode(trim($stdout),true,16,JSON_THROW_ON_ERROR)!==['finished'=>true])
            throw new RuntimeException('An independent native voice process failed.');
        return json_decode((string)file_get_contents($gate.'/result-'.$id.'.json'),true,32,JSON_THROW_ON_ERROR);
    };
    $blocker=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    $locked=static function(array $ready)use($admin,$blocker,$database):bool{
        return voice_mysql_owner_wait($admin,(int)$ready['connection_id'],$blocker,$database);
    };

    $config=voice_mysql_seed('concurrent-claims');$run=$config['input']['run_id'];$before=voice_mysql_saved($run);
    $candidates=voice_mysql_call('voice_candidates',$config);
    voice_mysql_check($candidates['candidates']===[['run_id'=>$run,'message_id'=>$config['input']['message_id']]]
        &&!str_contains(json_encode($candidates),'focused agenda'),'actual native planner and worker produce one delivered voice candidate with identifiers-only projection');
    $stale=$config;$stale['input']['activity_tab']=str_repeat('b',32);$code=0;
    try{voice_mysql_call('voice_claim',$stale);}catch(RuntimeException $error){$code=(int)$error->getCode();}
    voice_mysql_check($code===409&&voice_mysql_saved($run)['json']===$before['json'],'a stale tab cannot claim the current focused tab’s native check-in');
    [$first,$firstReady]=$start($config);$secondConfig=$config;$secondConfig['input']['request_id']='22222222-2222-4222-8222-222222222222';
    [$second,$secondReady]=$start($secondConfig);
    $pdo->beginTransaction();$pdo->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetch();
    file_put_contents($gate.'/go-'.$first,'go');file_put_contents($gate.'/go-'.$second,'go');
    voice_mysql_wait(static fn()=>$locked($firstReady)&&$locked($secondReady),'concurrent.owner_lock',
        static fn()=>voice_mysql_child_evidence($children[$first],$gate,$first));
    voice_mysql_check(voice_mysql_saved($run)['json']===$before['json'],'two independent claimants both wait on the actual canonical owner lock before any voice write');
    $pdo->commit();$outcomes=[$finish($first),$finish($second)];
    $accepted=array_values(array_filter($outcomes,static fn(array $row):bool=>$row['ok']));
    $rejected=array_values(array_filter($outcomes,static fn(array $row):bool=>!$row['ok']));
    $saved=voice_mysql_saved($run);$claim=$accepted[0]['response']['claim']??[];
    voice_mysql_check(count($accepted)===1&&count($rejected)===1&&$rejected[0]['code']===409
        &&!array_filter($outcomes,static fn(array $row):bool=>$row['in_transaction']||$row['model_calls']!==0)
        &&($saved['result']['initiative_voice']['status']??null)==='claimed'
        &&($saved['result']['initiative_voice']['request_id']??null)===($claim['request_id']??null),
        'concurrent native claimants commit one voice grant with identical canonical action and receipt and no inference or transaction leak');
    $winning=$config;$winning['input']['request_id']=$claim['request_id'];[$retry]=$start($winning);file_put_contents($gate.'/go-'.$retry,'go');$recovered=$finish($retry);
    voice_mysql_check($recovered['ok']&&$recovered['response']===$accepted[0]['response']
        &&preg_match('/\A[a-f0-9]{64}\z/D',$claim['token']??'')===1
        &&($saved['result']['initiative_voice']['token_hash']??null)===hash('sha256',$claim['token'])
        &&!str_contains($saved['json'],$claim['token'])&&voice_mysql_saved($run)['json']===$saved['json'],
        'the same UUID acknowledgement retry in another process recovers the identical opaque token without rewriting its hash-only receipt');
    $fresh=$config;$fresh['input']['request_id']='33333333-3333-4333-8333-333333333333';[$competitor]=$start($fresh);
    file_put_contents($gate.'/go-'.$competitor,'go');$refused=$finish($competitor);
    voice_mysql_check(!$refused['ok']&&$refused['code']===409&&voice_mysql_saved($run)['json']===$saved['json'],
        'a fresh native request cannot obtain a competing grant after an uncertain first acknowledgement');
    $finishInput=$winning;$finishInput['input']+=['token'=>$claim['token'],'outcome'=>'spoken'];
    $finished=voice_mysql_call('voice_finish',$finishInput);$finishedAgain=voice_mysql_call('voice_finish',$finishInput);
    voice_mysql_check($finished===$finishedAgain&&$finished['status']==='spoken'
        &&voice_mysql_saved($run)['result']['initiative_voice']['status']==='spoken','native completion is terminal and idempotent in the same canonical action and receipt');

    foreach(['voice_master','source','account','tab'] as $change){
        $GLOBALS['voiceNativeCase']=$change;voice_mysql_phase($change.'.seed');
        $config=voice_mysql_seed('blocked-'.$change);$run=$config['input']['run_id'];$before=voice_mysql_saved($run);
        [$child,$ready]=$start($config);
        $pdo->beginTransaction();$pdo->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetch();
        // These committed authority changes must be reread after the worker’s
        // successful preflight but before the serialized claim can be granted.
        if($change==='voice_master')$pdo->exec('UPDATE team_user_presence SET agent_voice_enabled=0 WHERE user_id=1');
        elseif($change==='source')$pdo->exec('UPDATE agent_memory_items SET is_active=0 WHERE id=100');
        elseif($change==='account')$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
        else voice_mysql_presence($config['cid'],str_repeat('b',32));
        file_put_contents($gate.'/go-'.$child,'go');voice_mysql_wait(static fn()=>$locked($ready),$change.'.owner_lock',
            static fn()=>voice_mysql_child_evidence($children[$child],$gate,$child));
        voice_mysql_check(voice_mysql_saved($run)['json']===$before['json'],'native '.$change.' revocation holds the claimant on the observed owner lock with no premature grant');
        $pdo->commit();$rejected=$finish($child);$after=voice_mysql_saved($run);
        voice_mysql_check(!$rejected['ok']&&$rejected['code']===($change==='account'?403:409)
            &&!$rejected['in_transaction']&&$rejected['model_calls']===0&&$after['json']===$before['json']
            &&!isset($after['result']['initiative_voice'])&&!$pdo->inTransaction(),
            'native '.$change.' revocation committed before the final claim prevents speech and leaves canonical text and receipt intact');
    }
    echo 'AGENT_INITIATIVE_VOICE_MYSQL=PASS ('.$GLOBALS['voiceNativeChecks'].' cases)'.PHP_EOL;
}finally{
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    foreach($children as $child){
        if(is_resource($child['process']))proc_terminate($child['process']);
        foreach(['stdout','stderr'] as $pipe)if(is_resource($child[$pipe]))fclose($child[$pipe]);
        if(is_resource($child['process']))proc_close($child['process']);
    }
    if(!$worker&&$gate!==''){
        foreach(glob($gate.'/*')?:[] as $file)unlink($file);
        if(is_dir($gate))rmdir($gate);
    }
    if($created)$admin->exec('DROP DATABASE `'.$database.'`');
}
