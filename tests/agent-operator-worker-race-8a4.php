<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/agent-operator-worker-authority-8a4.php';
if (PHP_SAPI!=='cli') throw new RuntimeException('CLI only.');
function raceDb(string $dsn):PDO {
    if (!str_starts_with($dsn,'sqlite:'.sys_get_temp_dir().DIRECTORY_SEPARATOR.'vp3-worker-race-') &&
        $dsn!=='mysql:host=127.0.0.1;port=3306;dbname=operator_worker_ci') throw new RuntimeException('Dedicated CI database only.');
    $db=new PDO($dsn,getenv('VP3_WORKER_TEST_USER')?:null,getenv('VP3_WORKER_TEST_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') $db->exec('PRAGMA busy_timeout=10000');
    return $db;
}
function raceCredential():array {
    return ['credential_id'=>str_repeat('a',32),'owner_id'=>99,'device_id'=>str_repeat('b',32),
        'worker_id'=>'race-fixture','secret'=>hash('sha256','race-fixture-key',true),
        'capabilities'=>['session.recover','session.simulate'],'route'=>'homeserver'];
}
function raceFrame(string $boot):array{
    $c=raceCredential();$p=['action'=>'register','boot_id'=>$boot,'capabilities'=>$c['capabilities'],
        'credential_id'=>$c['credential_id'],'device_id'=>$c['device_id'],'generation'=>0,'nonce'=>str_repeat('f',32),
        'owner_id'=>99,'protocol'=>'vp3.operator.worker.v1','sent_at'=>1800000000,'sequence'=>1,'worker_id'=>$c['worker_id']];
    ksort($p,SORT_STRING);$body=json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    return [$body,hash_hmac('sha256',"vp3.operator.worker.v1/request\0".$body,$c['secret'])];
}
if (($argv[1]??'')==='child') {
    [$script,$mode,$dsn,$barrier,$which,$operation,$occurrence]=$argv;
    $authority=new VP3OperatorWorkerAuthority8A4(raceDb($dsn),[raceCredential()]);
    file_put_contents($barrier.'.ready'.$which,'ready',LOCK_EX);
    $deadline=microtime(true)+15;
    while (!is_file($barrier)) {
        if (microtime(true)>$deadline) throw new RuntimeException('Barrier timeout.');
        usleep(10000);
    }
    try {
        if ($operation==='register') {
            [$raw,$mac]=raceFrame(str_repeat($which,32));
            $authority->receive($raw,$mac,1800000000);
            echo 'registered';
        } else {
            $r=$authority->claimSimulation(99,$occurrence,1800000001);
            echo $r===null?'unavailable':($r['duplicate']?'duplicate':'claimed');
        }
    } catch (DomainException $e) {echo 'rejected';}
    exit;
}
function compete(string $dsn,string $barrier,string $operation,array $occurrences,array $expected):void {
    $processes=[];$pipesets=[];
    try {
        foreach (['1','2'] as $i=>$which) {
            $proc=proc_open([PHP_BINARY,__FILE__,'child',$dsn,$barrier,$which,$operation,$occurrences[$i]],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($proc)) throw new RuntimeException('Cannot start child process.');
            fclose($pipes[0]);$processes[]=$proc;$pipesets[]=$pipes;
        }
        $deadline=microtime(true)+15;
        while (!is_file($barrier.'.ready1') || !is_file($barrier.'.ready2')) {
            if (microtime(true)>$deadline) throw new RuntimeException('Children did not reach race barrier.');
            usleep(10000);
        }
        touch($barrier);
        $actual=[];
        foreach ($processes as $i=>$proc) {
            $pipes=$pipesets[$i];
            stream_set_timeout($pipes[1],20);stream_set_timeout($pipes[2],20);
            $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);unset($processes[$i]);
            if ($exit!==0 || $err!=='') throw new RuntimeException('Race child failed: '.$err);
            $actual[]=$out;
        }
        sort($actual);sort($expected);
        if ($actual!==$expected) throw new RuntimeException('Unexpected race result: '.json_encode($actual));
        echo "PASS: $operation race ".implode(', ',$actual)."\n";
    } finally {
        foreach ($processes as $i=>$proc) {proc_terminate($proc);foreach (array_slice($pipesets[$i],1) as $pipe) if(is_resource($pipe))fclose($pipe);proc_close($proc);}
        foreach ([$barrier,$barrier.'.ready1',$barrier.'.ready2'] as $path) if (is_file($path))unlink($path);
    }
}
$dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'vp3-worker-race-'.bin2hex(random_bytes(8));
if (!mkdir($dir,0700)) throw new RuntimeException('Cannot create test directory.');
$dsn=getenv('VP3_WORKER_TEST_DSN')?:'sqlite:'.$dir.'/ledger.sqlite';
try {
    $db=raceDb($dsn);VP3OperatorWorkerAuthority8A4::install($db);
    compete($dsn,$dir.'/register','register',['',''],['registered','rejected']);
    compete($dsn,$dir.'/same','claim',[str_repeat('c',32),str_repeat('c',32)],['claimed','duplicate']);
    // Another two occurrences cannot acquire the already occupied worker.
    compete($dsn,$dir.'/busy','claim',[str_repeat('d',32),str_repeat('e',32)],['unavailable','unavailable']);
    $held=$db->query("SELECT COUNT(*) FROM operator_worker_claims_8a4 WHERE owner_id=99 AND state='held'")->fetchColumn();
    if ((int)$held!==1) throw new RuntimeException('Exclusive claim ledger violated.');
    echo "PASS: exactly one held occurrence persisted\n";
} finally {
    $db=null;foreach (glob($dir.'/*')?:[] as $path)unlink($path);rmdir($dir);
}
