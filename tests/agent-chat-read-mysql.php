<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';

require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/agent-chat-read-v1.php';
function db(): PDO{return $GLOBALS['pdo'];}
function has_permission(string $permission,array $user): bool{return true;}
function setting(string $name,mixed $default=''): mixed{return $default;}
function url(string $path): string{return $path;}
function mysql_read_check(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
    $GLOBALS['readChecks']=($GLOBALS['readChecks']??0)+1;
    echo 'PASS '.$message.PHP_EOL;
}

$dsn=(string)getenv('VP3_SECTION7_MYSQL_DSN');
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('An explicit MySQL Chat read fixture DSN is required.');
$password=(string)getenv('VP3_SECTION7_MYSQL_PASSWORD');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$worker=($argv[1]??'')==='--worker';
$database=$worker?(string)$argv[2]:'chat_read_'.bin2hex(random_bytes(6));
if(!preg_match('/^chat_read_[a-f0-9]{12}$/D',$database))throw new RuntimeException('Invalid disposable database name.');
$pdo=new PDO($dsn,'root',$password,$options);
if(!$worker)$pdo->exec('CREATE DATABASE `'.$database.'`');
$pdo->exec('USE `'.$database.'`');
if($worker){
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
    if($argv[3]==='install')vp3_chat_read_ensure_schema_v1($pdo);
    else vp3_chat_read_acknowledge_v1($pdo,['id'=>1,'is_active'=>1],0,42,[3]);
    echo "DONE\n";
    exit(0);
}

$children=[];
try{
    $pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active TINYINT NOT NULL) ENGINE=InnoDB;
        INSERT INTO users VALUES (1,1),(2,1);
        CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED,is_active TINYINT,
            display_name VARCHAR(80)) ENGINE=InnoDB;
        CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT UNSIGNED NULL) ENGINE=InnoDB;
        INSERT INTO chat_conversations VALUES (42,1,NULL),(43,2,NULL);
        CREATE TABLE chat_messages(id BIGINT UNSIGNED PRIMARY KEY,conversation_id BIGINT UNSIGNED,role VARCHAR(20),message LONGTEXT,
            context_json LONGTEXT NULL,FOREIGN KEY(conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE) ENGINE=InnoDB;
        INSERT INTO chat_messages(id,conversation_id,role,message) VALUES
            (1,42,'assistant','Historical reply'),(2,42,'assistant','')");
    $parallel=static function(string $action)use($database,&$children): void{
        for($index=0;$index<2;$index++){
            $pipes=[];
            $process=proc_open([PHP_BINARY,__FILE__,'--worker',$database,$action],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not create an independent MySQL read worker.');
            fclose($pipes[0]);
            $children[$index]=['process'=>$process,'stdout'=>$pipes[1],'stderr'=>$pipes[2]];
        }
        foreach($children as $index=>$child){
            $output=stream_get_contents($child['stdout']);$error=stream_get_contents($child['stderr']);
            fclose($child['stdout']);fclose($child['stderr']);
            $exit=proc_close($child['process']);unset($children[$index]);
            if($exit!==0||trim($output)!=='DONE')throw new RuntimeException('MySQL read worker failed: '.$output.$error);
        }
    };
    $parallel('install');
    mysql_read_check(vp3_chat_read_schema_ready_v1($pdo)
        &&(int)$pdo->query('SELECT COUNT(*) FROM agent_chat_read_rollout_v1')->fetchColumn()===1
        &&(int)$pdo->query('SELECT COUNT(*) FROM agent_chat_read_receipts_v1')->fetchColumn()===1,
        'two independent installers initialize historical read receipts exactly once on InnoDB');
    $pdo->exec("INSERT INTO chat_messages(id,conversation_id,role,message) VALUES
        (3,42,'assistant','Visible post'),(4,42,'assistant','Offscreen post'),(5,43,'assistant','Foreign post');
        UPDATE chat_messages SET message='Finished old stream' WHERE id=2");
    $parallel('acknowledge');
    $state=vp3_chat_read_state_v1($pdo,['id'=>1,'is_active'=>1],0,42,[1,2,3,4]);
    mysql_read_check($state['unread_message_ids']===[2,4]
        &&(int)$pdo->query('SELECT COUNT(*) FROM agent_chat_read_receipts_v1 WHERE message_id=3')->fetchColumn()===1,
        'concurrent tabs acknowledge one exact post while the old stream and offscreen post stay unread');
    vp3_chat_read_ensure_schema_v1($pdo);
    mysql_read_check(vp3_chat_read_state_v1($pdo,['id'=>1,'is_active'=>1],0,42,[2,4])['unread_message_ids']===[2,4],
        'rerunning the real MySQL upgrade preserves later unread posts');
    try{
        vp3_chat_read_acknowledge_v1($pdo,['id'=>1,'is_active'=>1],0,42,[4,5]);
        throw new RuntimeException('A foreign message was acknowledged.');
    }catch(DomainException $e){
        mysql_read_check(vp3_chat_read_state_v1($pdo,['id'=>1,'is_active'=>1],0,42,[4])['unread_message_ids']===[4],
            'a batch containing a foreign message rolls back its valid member');
    }
    $pdo->exec('DELETE FROM chat_messages WHERE id=3');
    mysql_read_check((int)$pdo->query('SELECT COUNT(*) FROM agent_chat_read_receipts_v1 WHERE message_id=3')->fetchColumn()===0,
        'deleting a persisted message removes its read receipt through the production foreign key');
    echo 'AGENT_CHAT_READ_MYSQL=PASS ('.$GLOBALS['readChecks'].' cases)'.PHP_EOL;
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($children as $child){
        proc_terminate($child['process']);
        foreach(['stdout','stderr'] as $pipe)if(is_resource($child[$pipe]))fclose($child[$pipe]);
        proc_close($child['process']);
    }
    $pdo->exec('DROP DATABASE `'.$database.'`');
}
