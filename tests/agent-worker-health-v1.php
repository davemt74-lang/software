<?php
declare(strict_types=1);

$healthRoot=sys_get_temp_dir().'/vp3-observer-health-'.bin2hex(random_bytes(6));
mkdir($healthRoot,0755);
define('STONEFELLOW_ROOT',$healthRoot);
require_once dirname(__DIR__).'/includes/agent-runtime-v125.php';
require_once dirname(__DIR__).'/includes/agent-worker-health-v1.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{
    throw new ErrorException($message,0,$severity,$file,$line);
});
$healthChecks=0;
function observer_check(bool $condition,string $label):void
{
    if(!$condition)throw new RuntimeException($label);
    ++$GLOBALS['healthChecks'];echo 'PASS '.$label.PHP_EOL;
}
function observer_remove(string $path):void
{
    if(is_dir($path)&&!is_link($path)){
        foreach(scandir($path)?:[] as $name)if($name!=='.'&&$name!=='..')observer_remove($path.'/'.$name);
        rmdir($path);
    }elseif(file_exists($path)||is_link($path))unlink($path);
}
register_shutdown_function(static function()use($healthRoot):void{restore_error_handler();observer_remove($healthRoot);});

$path=agent_worker_health_path_v1();
observer_check($path===agent_runtime_v125_root().'/workers/initiative-runner-health-v1.json'
    &&!file_exists($healthRoot.'/private'),'observer path resolution uses the canonical root without provisioning storage');
observer_check(agent_worker_health_read_v1(null,2000)['state']==='never_seen'&&!file_exists($healthRoot.'/private'),
    'reading a missing observer leaves the entire runtime directory absent');
observer_check(agent_worker_health_observe_v1(true,null,1000)&&is_file($path),
    'only an actual sweep observation creates canonical runtime storage and its heartbeat');
observer_check(agent_worker_health_read_v1(null,1100)['state']==='fresh'
    &&agent_worker_health_read_v1(null,1400)['state']==='stale','validated successful observations become fresh then stale');
observer_check(agent_worker_health_observe_v1(false,null,1450)
    &&agent_worker_health_read_v1(null,1500)===['state'=>'failed','observation_scope'=>'server',
        'last_attempt_at'=>gmdate(DATE_ATOM,1450),'last_success_at'=>gmdate(DATE_ATOM,1000)],
    'a failed real sweep retains the prior verified success while reporting failure');
chmod(dirname($path),0777);chmod($path,0644);clearstatcache();
$beforeMode=[fileperms(dirname($path))&0777,fileperms($path)&0777];$before=file_get_contents($path);
agent_worker_health_read_v1(null,1500);clearstatcache();
observer_check($beforeMode===[fileperms(dirname($path))&0777,fileperms($path)&0777]&&file_get_contents($path)===$before,
    'status reads neither chmod the existing directory/file nor rewrite heartbeat content');

$valid=['source'=>'initiative_cli_sweep','last_attempt_at'=>gmdate(DATE_ATOM,1000),
    'last_success_at'=>gmdate(DATE_ATOM,1000),'outcome'=>'success'];
$badCases=[
    'corrupt JSON'=>'{broken',
    'oversized metadata'=>str_repeat('x',4097),
    'unrecognized producer'=>json_encode(array_replace($valid,['source'=>'browser_poll']),JSON_THROW_ON_ERROR),
    'malformed timestamp'=>json_encode(array_replace($valid,['last_attempt_at'=>[]]),JSON_THROW_ON_ERROR),
    'future timestamp'=>json_encode(array_replace($valid,['last_attempt_at'=>gmdate(DATE_ATOM,3000)]),JSON_THROW_ON_ERROR),
    'unknown outcome'=>json_encode(array_replace($valid,['outcome'=>'configured']),JSON_THROW_ON_ERROR),
    'success without successful observation'=>json_encode(array_replace($valid,['last_success_at'=>'']),JSON_THROW_ON_ERROR),
    'inconsistent observation ordering'=>json_encode(array_replace($valid,['last_attempt_at'=>gmdate(DATE_ATOM,900)]),JSON_THROW_ON_ERROR),
];
foreach($badCases as $label=>$raw){
    file_put_contents($path,$raw);$unknown=agent_worker_health_read_v1(null,2000);
    observer_check($unknown===agent_worker_health_empty_v1('unknown')&&file_get_contents($path)===$raw,
        $label.' is unknown worker evidence and stays read-only');
}
file_put_contents($path,json_encode($valid,JSON_THROW_ON_ERROR));
$lock=fopen($path,'r+');flock($lock,LOCK_EX);
$started=microtime(true);$locked=agent_worker_health_read_v1(null,1100);
observer_check($locked['state']==='unknown'&&microtime(true)-$started<1,
    'an observer write lock cannot stall a status read or manufacture a healthy runner');
observer_check(!agent_worker_health_observe_v1(false,null,1100),'an overlapping observation does not overwrite the active writer');
flock($lock,LOCK_UN);fclose($lock);
observer_check(agent_worker_health_read_v1(null,1100)['state']==='fresh','releasing the observer lock restores the actual saved observation');

// Test OS permission denial as a real non-owner when the runner is privileged.
$protected=$healthRoot.'/protected';mkdir($protected,0755);
$protectedPath=agent_worker_health_path_v1($protected);
file_put_contents($protectedPath,json_encode($valid,JSON_THROW_ON_ERROR));chmod($protectedPath,0000);
$uid=function_exists('posix_geteuid')?posix_geteuid():null;
$dropped=$uid===0&&function_exists('posix_seteuid')&&posix_seteuid(65534);
if($dropped){
    try{$denied=agent_worker_health_read_v1($protected,1100);$deniedWrite=agent_worker_health_observe_v1(false,$protected,1100);}
    finally{if(!posix_seteuid(0))throw new RuntimeException('Could not restore filesystem fixture identity.');}
}elseif($uid!==0){$denied=agent_worker_health_read_v1($protected,1100);$deniedWrite=agent_worker_health_observe_v1(false,$protected,1100);}
else{
    // Some sandboxed root runners lack SETUID. Exercise a failed real stream
    // open plus the OS-protected proc destination; normal CI uses chmod above.
    class ObserverDeniedStream {
        public mixed $context;
        public function url_stat(string $path,int $flags):array{return ['mode'=>0100000,'size'=>20];}
        public function stream_open(string $path,string $mode,int $options,?string &$opened):bool{return false;}
    }
    stream_wrapper_register('observerdenied',ObserverDeniedStream::class);
    try{$denied=agent_worker_health_read_v1('observerdenied://fixture',1100);}
    finally{stream_wrapper_unregister('observerdenied');}
    $deniedWrite=agent_worker_health_observe_v1(false,'/proc/self',1100);
}
chmod($protectedPath,0600);
observer_check($denied['state']==='unknown'&&!$deniedWrite&&file_get_contents($protectedPath)===json_encode($valid,JSON_THROW_ON_ERROR),
    ($dropped||$uid!==0?'OS permission-denied observer metadata':'failed observer stream and OS-protected write destination')
        .' degrades without throwing or changing its contents');

// Reuse the canonical runtime fixture, not a substitute worker-health projection.
// It exercises real policy/queue/lease/receipt logic with provider transport fixtures.
file_put_contents($path,'corrupt observer state');
require __DIR__.'/agent-initiative-runtime-v1.php';
$user=['id'=>1,'is_active'=>1];$policy=runtime_enable($user,0,42);
$health=vp3_initiative_worker_health_v1(db(),$user,0,42);
observer_check($health['runner_state']==='unknown'&&$health['reason']==='worker_observation_unavailable'
    &&$health['configuration_ready']&&$health['execution_ready']&&$health['pump_available'],
    'optional observer corruption cannot become a Chat access failure or disable an authorized foreground pump');
$modelCalls=0;
$pumped=vp3_initiative_pump_v1(db(),$user,0,42,$policy['revision'],static function()use(&$modelCalls):string{
    ++$modelCalls;if(db()->inTransaction())throw new RuntimeException('Inference cannot hold a transaction.');
    return '{"jobs":[]}';
});
observer_check($pumped['ok']&&$modelCalls===1&&$pumped['health']['runner_state']==='unknown'
    &&$pumped['health']['pump_available']&&file_get_contents($path)==='corrupt observer state',
    'the actual canonical pump completes permitted assessment while leaving an unreadable observation separate and unchanged');

class ObserverFailurePDO extends InitiativeRuntimePDO
{
    public Throwable $failure;
    public function prepare(string $sql,array $options=[]):PDOStatement|false
    {
        if(str_contains($sql,'SELECT * FROM users WHERE id=?'))throw $this->failure;
        return parent::prepare($sql,$options);
    }
}
$realPdo=$pdo;$failurePdo=new ObserverFailurePDO('sqlite:'.$fixtureFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach([[new Error('PRIVATE_SQL_AND_CREDENTIAL_SHOULD_NOT_LEAK'),'runtime_unavailable'],
    [new RuntimeException('PRIVATE_STORAGE_DETAILS',503),'storage_unavailable'],
    [new RuntimeException('PRIVATE_ACCOUNT_DETAILS',403),'context_unavailable']] as [$error,$reason]){
    $failurePdo->failure=$error;$pdo=$failurePdo;
    try{$failureHealth=vp3_initiative_worker_health_v1($failurePdo,$user,0,42);}
    finally{$pdo=$realPdo;}
    observer_check($failureHealth['reason']===$reason&&!$failureHealth['pump_available']
        &&!str_contains(json_encode($failureHealth,JSON_THROW_ON_ERROR),'PRIVATE_'),
        'runtime health distinguishes '.$reason.' without exposing the underlying failure text');
}
$trace=file_get_contents(agent_runtime_v125_root().'/traces/'.gmdate('Y-m-d').'.jsonl');
observer_check(str_contains($trace,'agent_core.health_unavailable')&&str_contains($trace,'"error_class":"Error"')
    &&!str_contains($trace,'PRIVATE_SQL_AND_CREDENTIAL'),
    'unexpected health failures record a class-only diagnostic instead of private SQL, paths or credentials');
echo 'AGENT_WORKER_HEALTH_V1=PASS ('.$healthChecks.' cases)'.PHP_EOL;
