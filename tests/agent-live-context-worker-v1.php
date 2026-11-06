<?php
declare(strict_types=1);

// Execute the unchanged CLI worker and its real cycle/schedule functions in
// fresh processes. Only application host bootstrapping uses a disposable DB;
// no expiry, sweep, readiness, worker, or scheduling function is substituted.
require_once dirname(__DIR__).'/includes/agent-live-context-v1.php';
require_once __DIR__.'/fixtures/workflow-agent-schema-v1.php';

$workerChecks=0;$workerRoot=sys_get_temp_dir().'/vp3-live-worker-'.bin2hex(random_bytes(8));
mkdir($workerRoot,0700,true);mkdir($workerRoot.'/includes',0700,true);
function live_worker_check(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException($label);
    ++$GLOBALS['workerChecks'];echo 'PASS '.$label.PHP_EOL;
}
function live_worker_remove(string $path):void
{
    if(is_dir($path)&&!is_link($path)){foreach(scandir($path)?:[] as $name)if($name!=='.'&&$name!=='..')live_worker_remove($path.'/'.$name);rmdir($path);}
    elseif(is_file($path)||is_link($path))unlink($path);
}
register_shutdown_function(static fn()=>live_worker_remove($workerRoot));
$production=dirname(__DIR__);
foreach(glob($production.'/includes/*.php')?:[] as $path){
    if(basename($path)!=='bootstrap.php')symlink($path,$workerRoot.'/includes/'.basename($path));
}
$source=file_get_contents($production.'/agent-worker-v1910.php');
file_put_contents($workerRoot.'/agent-worker-v1910.php',$source);
live_worker_check(hash_file('sha256',$workerRoot.'/agent-worker-v1910.php')===hash_file('sha256',$production.'/agent-worker-v1910.php'),
    'the fresh-process CLI entry is byte-identical production worker code');

$bootstrap=<<<'PHP'
<?php
declare(strict_types=1);
define('STONEFELLOW_ROOT',getenv('VP3_LIVE_WORKER_ROOT'));
class LiveWorkerHostPDO extends PDO {
    public function prepare(string $sql,array $options=[]):PDOStatement|false {
        return parent::prepare(str_replace([' FOR UPDATE','INSERT IGNORE'],['','INSERT OR IGNORE'],$sql),$options);
    }
}
$GLOBALS['liveWorkerHostPDO']=new LiveWorkerHostPDO(getenv('VP3_LIVE_WORKER_DSN'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach(['NOW','UTC_TIMESTAMP'] as $clock)$GLOBALS['liveWorkerHostPDO']->sqliteCreateFunction($clock,static fn()=>gmdate('Y-m-d H:i:s'));
function db():PDO{return $GLOBALS['liveWorkerHostPDO'];}
function table_exists(string $table):bool {
    $q=db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return $q->fetchColumn()!==false;
}
function column_exists(string $table,string $column):bool {
    if(preg_match('/\A[a-z_0-9]+\z/D',$table)!==1)return false;
    return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
}
function setting(string $name,mixed $default=null):mixed{return $default;}
function has_permission(string $name,array $user):bool{return false;}
function current_user():?array{return null;}
function url(string $path=''):string{return $path;}
require_once getenv('VP3_LIVE_WORKER_PRODUCTION').'/includes/agent-memory-scope-v410.php';
require_once getenv('VP3_LIVE_WORKER_PRODUCTION').'/includes/agent-brain-v82.php';
require_once getenv('VP3_LIVE_WORKER_PRODUCTION').'/includes/agent-runtime-v125.php';
require_once getenv('VP3_LIVE_WORKER_PRODUCTION').'/includes/homeserver-https-relay-v1300.php';
PHP;
file_put_contents($workerRoot.'/includes/bootstrap.php',$bootstrap);
$driver=strpos($source,"\n\$executor=strtolower(agent_worker_runner_option_v1910(");
if($driver===false)throw new RuntimeException('Production worker driver boundary not found.');
$prefix=substr($source,0,$driver);
file_put_contents($workerRoot.'/cycle.php',$prefix."\n\$result=agent_worker_runner_cycle_v1910(db(),'cloud',20);echo json_encode(['cycle'=>\$result,'brain_ready'=>agent_brain_schema_ready(),'jobs_ready'=>agent_job_engine_schema_ready_v1900(db())],JSON_THROW_ON_ERROR).PHP_EOL;\n");
file_put_contents($workerRoot.'/schedule.php',"<?php\nrequire __DIR__.'/includes/bootstrap.php';require_once ".var_export($production.'/includes/agent-job-engine-v1900.php',true).";require_once ".var_export($production.'/includes/agent-initiative-v1.php',true).";vp3_initiative_schedule_v1(db(),['id'=>1,'is_active'=>0],17);echo json_encode(['brain_ready'=>agent_brain_schema_ready(),'jobs_ready'=>agent_job_engine_schema_ready_v1900(db()),'scope'=>\$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null],JSON_THROW_ON_ERROR).PHP_EOL;\n");

function live_worker_iso(int $milliseconds):string
{
    return gmdate('Y-m-d\TH:i:s',(int)floor($milliseconds/1000)).'.'.str_pad((string)($milliseconds%1000),3,'0',STR_PAD_LEFT).'Z';
}
function live_worker_seed(string $file,bool $jobs):PDO
{
    $pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT INTO users VALUES(1,0),(2,1);
        CREATE TABLE agent_activity_state(user_id INTEGER PRIMARY KEY,surface TEXT,context_key TEXT,activity_state TEXT,last_heartbeat_at TEXT,details_json TEXT);
        CREATE TABLE agent_activity_events(id INTEGER PRIMARY KEY,details_json TEXT);');
    if($jobs){workflow_agent_fixture_v1($pdo);$pdo->exec('DELETE FROM agent_workflow_runs');}
    $now=(int)floor(microtime(true)*1000);$insert=$pdo->prepare('INSERT INTO agent_activity_state VALUES(?,?,?,?,?,?)');
    foreach([[1,17,170,$now-120000,str_repeat('a',32)],[2,19,190,$now,str_repeat('b',32)]] as [$owner,$aid,$cid,$at,$tab]){
        $packet=vp3_live_context_sanitize_v1(['contract'=>'vp3.live-context.v1','owner_user_id'=>$owner,'agent_id'=>$aid,
            'conversation_id'=>$cid,'activity_tab'=>$tab,'session_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa'.$owner,
            'observed_at'=>live_worker_iso($at),'screens'=>[['source_id'=>'share-1','session_id'=>'11111111-1111-4111-8111-111111111111',
                'selected'=>true,'active'=>true,'context_allowed'=>true,'surface'=>'browser','captured_at'=>live_worker_iso($at),
                'summary'=>$owner===1?'WORKER_EXPIRED_RAW_SENTINEL':'WORKER_FRESH_RAW_SENTINEL','visible_text'=>'A current supplied scene.']],
            'eyes'=>null,'microphone'=>null],['owner_user_id'=>$owner,'agent_id'=>$aid,'conversation_id'=>$cid,
                'activity_tab'=>$tab,'visible'=>true,'focused'=>true],$at);
        if(!$packet)throw new RuntimeException('Worker fixture canonical packet was not created.');
        $details=['conversation_id'=>$cid,'activity_tab'=>$tab,'visible'=>true,'focused'=>true,'live_context'=>$packet];
        $insert->execute([$owner,'chat','chat:'.$cid,'idle',gmdate('Y-m-d H:i:s',(int)floor($at/1000)),
            json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    }
    return $pdo;
}
function live_worker_run(string $script,string $database):array
{
    $environment=array_replace(getenv(),['VP3_LIVE_WORKER_DSN'=>'sqlite:'.$database,
        'VP3_LIVE_WORKER_ROOT'=>$GLOBALS['workerRoot'],'VP3_LIVE_WORKER_PRODUCTION'=>dirname(__DIR__)]);
    $binary=getenv('VP3_PHP_BINARY')?:PHP_BINARY;
    $process=proc_open([$binary,$GLOBALS['workerRoot'].'/'.$script,'--executor=cloud','--max-owners=20'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$GLOBALS['workerRoot'],$environment);
    if(!is_resource($process))throw new RuntimeException('Worker fixture process could not start.');
    fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    $output='';$error='';$end=microtime(true)+20;$exit=null;
    do{
        $output.=stream_get_contents($pipes[1]);$error.=stream_get_contents($pipes[2]);$status=proc_get_status($process);
        if(!$status['running']){$exit=$status['exitcode'];break;}
        if(microtime(true)>$end){proc_terminate($process);throw new RuntimeException('Worker fixture exceeded its bounded runtime.');}
        usleep(20000);
    }while(true);
    $output.=stream_get_contents($pipes[1]);$error.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);
    return ['exit'=>$exit>=0?$exit:$closed,'output'=>$output,'error'=>$error];
}
function live_worker_verify(PDO $pdo,string $label):void
{
    $rows=$pdo->query('SELECT user_id,details_json FROM agent_activity_state ORDER BY user_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    live_worker_check(!isset(json_decode($rows[1],true)['live_context'])&&!str_contains($rows[1],'WORKER_EXPIRED_RAW_SENTINEL'),
        $label.' physically scrubs the expired inactive owner packet without any active policy');
    live_worker_check(str_contains($rows[2],'WORKER_FRESH_RAW_SENTINEL'),$label.' preserves the other owner fresh packet');
    live_worker_check((int)$pdo->query('SELECT COUNT(*) FROM agent_activity_events')->fetchColumn()===0,
        $label.' does not create an activity-history copy');
}

$missing=$workerRoot.'/entry-missing.sqlite';$pdo=live_worker_seed($missing,false);
$entry=live_worker_run('agent-worker-v1910.php',$missing);
live_worker_check($entry['exit']===3&&str_contains($entry['error'],'durable job schema is not ready'),
    'the actual partial-upgrade CLI entry preserves its established missing-job diagnostic');
live_worker_verify($pdo,'actual missing-job CLI entry');
live_worker_check($pdo->query("SELECT name FROM sqlite_master WHERE name IN ('agent_memory_items','agent_workflow_runs')")->fetchAll()===[],
    'entry cleanup does not provision missing Brain or durable-job schemas');

$cycleFile=$workerRoot.'/cycle-missing.sqlite';$pdo=live_worker_seed($cycleFile,false);
$cycle=live_worker_run('cycle.php',$cycleFile);
live_worker_check($cycle['exit']===0,'the actual worker-cycle functions finish on a partial upgrade'
    .($cycle['exit']===0?'':': '.substr($cycle['error'].$cycle['output'],0,400)));
$result=json_decode(trim($cycle['output']),true,32,JSON_THROW_ON_ERROR);
live_worker_check($result['brain_ready']===false&&$result['jobs_ready']===false,'the actual cycle observes missing Brain and job readiness without replacing those checks');
live_worker_verify($pdo,'actual missing-schema worker cycle');

$brainFile=$workerRoot.'/entry-brain-missing.sqlite';$pdo=live_worker_seed($brainFile,true);
$entry=live_worker_run('agent-worker-v1910.php',$brainFile);
live_worker_check($entry['exit']===0,'the unchanged CLI entry runs its cycle with durable jobs installed and Brain absent'
    .($entry['exit']===0?'':': '.substr($entry['error'].$entry['output'],0,400)));
$result=json_decode(trim($entry['output']),true,32,JSON_THROW_ON_ERROR);
live_worker_check($result['ok']===true&&$result['executor']==='cloud','the actual CLI entry produces its normal cycle result despite an absent optional Brain');
live_worker_verify($pdo,'actual Brain-missing CLI cycle');
live_worker_check($pdo->query("SELECT name FROM sqlite_master WHERE name='agent_memory_items'")->fetchColumn()===false,
    'Brain-missing worker cleanup installs no memory or policy table');

$scheduleFile=$workerRoot.'/schedule-missing.sqlite';$pdo=live_worker_seed($scheduleFile,false);
$scheduled=live_worker_run('schedule.php',$scheduleFile);
live_worker_check($scheduled['exit']===0,'the actual direct scheduling entry returns safely with durable jobs unavailable'
    .($scheduled['exit']===0?'':': '.substr($scheduled['error'].$scheduled['output'],0,700)));
$result=json_decode(trim($scheduled['output']),true,32,JSON_THROW_ON_ERROR);
live_worker_check($result['brain_ready']===false&&$result['jobs_ready']===false&&$result['scope']===null,
    'direct scheduling retains real missing-schema results and restores the incoming Agent namespace');
live_worker_verify($pdo,'actual direct schedule before job readiness');

echo 'AGENT_LIVE_CONTEXT_WORKER_V1=PASS ('.$workerChecks." cases; actual CLI entry/cycle/direct scheduling and partial-upgrade cleanup)\n";
