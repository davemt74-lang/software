<?php
declare(strict_types=1);

// Run against an explicitly supplied disposable MySQL service. Workers below use
// separate PHP processes and the production owner/Chat locks and receipt writer.
$dsn=(string)getenv('VP3_SECTION7_MYSQL_DSN');
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('An explicit MySQL delivery fixture DSN is required.');
$password=(string)getenv('VP3_SECTION7_MYSQL_PASSWORD');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$worker=($argv[1]??'')==='--worker';
$database=$worker?(string)$argv[2]:'initiative_delivery_'.bin2hex(random_bytes(6));
if(!preg_match('/^initiative_delivery_[a-f0-9]{12}$/D',$database))throw new RuntimeException('Invalid disposable database name.');
$admin=new PDO($dsn,'root',$password,$options);
if(!$worker)$admin->exec('CREATE DATABASE `'.$database.'`');
$fixtureDsn=preg_replace('/;dbname=[^;]*/','',$dsn).';dbname='.$database;
putenv('VP3_IMAGE_TEST_DSN='.$fixtureDsn);
putenv('VP3_IMAGE_TEST_USER=root');
putenv('VP3_IMAGE_TEST_PASSWORD='.$password);
require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__).'/includes/agent-initiative-v1.php';
$GLOBALS['initiativeBrainAccess']=true;
date_default_timezone_set('UTC');

function agent_brain_schema_ready(): bool{return true;}
function agent_brain_normalize(string $text): string{return strtolower(trim($text));}
function agent_brain_sentences(string $text): array{return [$text];}
function vp3_participant_scope_v1(): ?array{return null;}
function calendar_schedule_awareness_snapshot_v1330(PDO $pdo,array $user,DateTimeImmutable $now,int $days,bool $includeHomeServer=true): array
{
    if($includeHomeServer)throw new LogicException('The fixture must use the owner Cloud calendar.');
    $stmt=$pdo->prepare('SELECT title,starts_at FROM initiative_fixture_calendar WHERE owner_id=?');
    $stmt->execute([(int)$user['id']]);
    $row=$stmt->fetch();
    $next=$row?['ref'=>'event:42','kind'=>'event','id'=>42,'title'=>$row['title'],'start_local'=>$row['starts_at']]:null;
    return ['available'=>true,'timezone'=>'UTC','upcoming'=>$next?[$next]:[],'current'=>null,'conflicts'=>[],
        'next'=>$next,'starts_soon'=>$next!==null];
}
function delivery_check(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
    $GLOBALS['deliveryChecks']=($GLOBALS['deliveryChecks']??0)+1;
    echo 'PASS '.$message.PHP_EOL;
}
function delivery_wait(callable $ready,string $message): void
{
    $deadline=microtime(true)+20;
    while(!$ready()){
        if(microtime(true)>$deadline)throw new RuntimeException($message);
        usleep(10000);
    }
}

if($worker){
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
    $gate=(string)$argv[3];$index=(string)$argv[4];
    file_put_contents($gate.'/ready-'.$index,'ready');
    delivery_wait(static fn()=>is_file($gate.'/go'),'Concurrent worker did not receive the start barrier.');
    file_put_contents($gate.'/entered-'.$index,'entered');
    $delivered=vp3_initiative_deliver_pending_v1($pdo,['id'=>1,'is_active'=>1]);
    echo json_encode(['delivered'=>$delivered],JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$gate=sys_get_temp_dir().'/vp3-initiative-delivery-'.bin2hex(random_bytes(8));
$children=[];
try{
    mkdir($gate,0700);
    $pdo->exec("ALTER TABLE chat_conversations ADD COLUMN updated_at DATETIME;
        INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES (42,1,NULL),(43,2,NULL);
        CREATE TABLE agent_memory_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,user_agent_id INT UNSIGNED NULL,
            memory_type VARCHAR(40),subject VARCHAR(190),memory_text TEXT,memory_hash CHAR(40),memory_scope_version SMALLINT,
            source_archive_id BIGINT NULL,confidence DOUBLE,occurrence_count INT,first_seen_at DATETIME,last_seen_at DATETIME,
            is_active TINYINT,metadata_json TEXT,UNIQUE KEY owner_memory(user_id,memory_hash)
        ) ENGINE=InnoDB;
        CREATE TABLE initiative_fixture_calendar(owner_id INT UNSIGNED PRIMARY KEY,title VARCHAR(190),starts_at DATETIME) ENGINE=InnoDB;
        CREATE TABLE agent_activity_state(user_id INT UNSIGNED PRIMARY KEY,surface VARCHAR(30),context_key VARCHAR(80),
            task_title VARCHAR(190),activity_state VARCHAR(30),last_heartbeat_at DATETIME,idle_since_at DATETIME,
            details_json TEXT) ENGINE=InnoDB;
        CREATE TABLE agent_activity_events(id INT PRIMARY KEY) ENGINE=InnoDB;
        INSERT INTO agent_activity_state VALUES (1,'chat','chat:42','Agent Chat','paused',NOW(),NULL,
            '{\"visible\":true,\"conversation_id\":42}');
        INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES (42,1,'user','Check useful work'),
            (42,NULL,'assistant','I will review the current work.')");
    $pdo->prepare('INSERT INTO initiative_fixture_calendar VALUES (1,?,?)')
        ->execute(['Owner launch review',gmdate('Y-m-d H:i:s',time()+600)]);
    $user=['id'=>1,'is_active'=>1];
    $principal=vp3_agent_chat_principal_v380($user,null);
    vp3_initiative_chat_v1($pdo,$user,$principal,['conversation_id'=>42],'on',null);

    $seed=static function(string $draft,bool $legacy=false)use($pdo,$user): array{
        $policy=vp3_initiative_policy_v1($user);
        $evidence=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')));
        $ref='calendar:event:42';
        if(!isset($evidence[$ref]))throw new RuntimeException('The real calendar projection did not supply the fixture evidence.');
        $goal=['binding'=>$policy,'sources'=>[$ref=>$evidence[$ref]['revision']],'focus'=>'Prepare a private next step','report'=>true];
        vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',$goal,hash('sha256',random_bytes(16)));
        // The enqueue helper ends with an event insert, whose id is independent.
        $run=(int)$pdo->query('SELECT MAX(id) FROM agent_workflow_runs WHERE owner_user_id=1')->fetchColumn();
        $action=(int)$pdo->query('SELECT id FROM agent_workflow_actions WHERE run_id='.$run)->fetchColumn();
        $result=['effect_state'=>'private_draft_ready','initiative_draft'=>$draft];
        if(!$legacy)$result['initiative_delivery']='pending';
        $json=json_encode($result,JSON_THROW_ON_ERROR);
        $pdo->prepare("UPDATE agent_workflow_runs SET status='completed',completed_at=NOW() WHERE id=?")->execute([$run]);
        $pdo->prepare("UPDATE agent_workflow_actions SET status='completed',result_json=?,completed_at=NOW() WHERE id=?")
            ->execute([$json,$action]);
        $pdo->prepare("INSERT INTO agent_workflow_receipts(run_id,action_id,owner_user_id,receipt_key,status,result_json)
            VALUES (?,?,1,?,'completed',?)")->execute([$run,$action,hash('sha256','delivery:'.$run),$json]);
        return ['run'=>$run,'action'=>$action];
    };
    $state=static function(array $job)use($pdo): array{
        $stmt=$pdo->prepare('SELECT result_json FROM agent_workflow_actions WHERE id=?');$stmt->execute([$job['action']]);
        return json_decode((string)$stmt->fetchColumn(),true,16,JSON_THROW_ON_ERROR);
    };
    $reports=static fn(): int=>(int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE context_json LIKE '%agent_initiative%'")->fetchColumn();
    $engage=static function()use($pdo): void{
        $pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES
            (42,1,'user','Continue'),(42,NULL,'assistant','I am ready.');
            UPDATE agent_activity_state SET last_heartbeat_at=NOW() WHERE user_id=1");
    };
    $job=$seed('Prepare the launch review questions.');
    $pdo->beginTransaction();
    $pdo->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetchColumn();
    for($index=0;$index<2;$index++){
        $pipes=[];
        $process=proc_open([PHP_BINARY,__FILE__,'--worker',$database,$gate,(string)$index],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Could not start an independent delivery worker.');
        fclose($pipes[0]);
        $children[]=['process'=>$process,'stdout'=>$pipes[1],'stderr'=>$pipes[2]];
    }
    delivery_wait(static fn()=>is_file($gate.'/ready-0')&&is_file($gate.'/ready-1'),'Delivery workers did not reach the start barrier.');
    file_put_contents($gate.'/go','go');
    delivery_wait(static fn()=>is_file($gate.'/entered-0')&&is_file($gate.'/entered-1'),'Delivery workers did not invoke the production service.');
    $pdo->commit();
    $outcomes=[];
    foreach($children as $index=>$child){
        $output=stream_get_contents($child['stdout']);$error=stream_get_contents($child['stderr']);
        fclose($child['stdout']);fclose($child['stderr']);
        $exit=proc_close($child['process']);
        unset($children[$index]);
        if($exit!==0)throw new RuntimeException('Independent delivery worker failed: '.$error.$output);
        $outcomes[]=json_decode(trim($output),true,16,JSON_THROW_ON_ERROR)['delivered'];
    }
    $children=[];
    delivery_check(count(array_filter($outcomes))===1&&$reports()===1,'two independent MySQL delivery workers commit exactly one Chat report');
    $delivered=$state($job);
    $receipt=$pdo->query('SELECT result_json FROM agent_workflow_receipts WHERE action_id='.$job['action'])->fetchColumn();
    delivery_check($delivered['initiative_delivery']==='delivered'&&(int)$delivered['initiative_message_id']>0
        &&json_decode((string)$receipt,true)===$delivered,'the action and its original receipt record the same delivered message');
    delivery_check(!vp3_initiative_deliver_pending_v1($pdo,$user)&&$reports()===1,'retrying delivery leaves the one report and original receipt intact');

    $pending=$seed('A second private preparation.');
    delivery_check(!vp3_initiative_deliver_pending_v1($pdo,$user)&&$state($pending)['initiative_delivery']==='pending'
        &&$reports()===1,'another result waits for owner engagement instead of adding another unsolicited report');
    $pdo->exec("UPDATE initiative_fixture_calendar SET title='Changed source' WHERE owner_id=1");
    $engage();
    delivery_check(!vp3_initiative_deliver_pending_v1($pdo,$user)&&$state($pending)['initiative_delivery']==='withheld'
        &&$reports()===1,'a changed source is withheld when the owner returns');

    $oldPolicy=$seed('A result from the prior opt-in revision.');
    $policy=vp3_initiative_policy_v1($user);$policy['revision']=bin2hex(random_bytes(16));
    agent_brain_v122_upsert_system_memory($user,'initiative_policy','owner-opt-in','Initiative is enabled.',$policy);
    delivery_check(!vp3_initiative_deliver_pending_v1($pdo,$user)&&$state($oldPolicy)['initiative_delivery']==='withheld'
        &&$reports()===1,'rotating the owner policy withholds pending work from the old authorization');

    $legacy=$seed('A legacy private result.',true);
    delivery_check(!vp3_initiative_deliver_pending_v1($pdo,$user)&&!isset($state($legacy)['initiative_delivery'])
        &&$reports()===1,'legacy results without a delivery marker remain private');
    $atomic=$seed('A current result that must commit with its receipt.');
    $pdo->exec("CREATE TRIGGER initiative_delivery_fail_receipt BEFORE UPDATE ON agent_workflow_receipts FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture receipt write failed'");
    try{
        vp3_initiative_deliver_pending_v1($pdo,$user);
        throw new RuntimeException('The fixture receipt failure was not observed.');
    }catch(PDOException $e){
        delivery_check(str_contains($e->getMessage(),'fixture receipt write failed')&&$reports()===1
            &&$state($atomic)['initiative_delivery']==='pending','a receipt-write failure rolls back the Chat report and action marker together');
    }
    $pdo->exec('DROP TRIGGER initiative_delivery_fail_receipt');
    delivery_check(vp3_initiative_deliver_pending_v1($pdo,$user)&&$reports()===2
        &&$state($atomic)['initiative_delivery']==='delivered','retry after a rolled-back transaction delivers the verified result once');
    echo 'AGENT_INITIATIVE_DELIVERY_MYSQL=PASS ('.$GLOBALS['deliveryChecks'].' cases)'.PHP_EOL;
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($children as $child){
        proc_terminate($child['process']);
        foreach(['stdout','stderr'] as $pipe)if(is_resource($child[$pipe]))fclose($child[$pipe]);
        proc_close($child['process']);
    }
    foreach(glob($gate.'/*')?:[] as $path)unlink($path);
    if(is_dir($gate))rmdir($gate);
    $admin->exec('DROP DATABASE `'.$database.'`');
}
