<?php
declare(strict_types=1);
// Dedicated in-memory cross-runtime fixture. No production bootstrap/config/DB.
if(PHP_SAPI!=='cli' || ($argv[1]??'')!=='--fixture-only'){exit(2);}
require_once __DIR__.'/../../includes/agent-operator-worker-http-8a4.php';
require_once __DIR__.'/../../includes/agent-operator-worker-status-8a4.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE users(id BIGINT PRIMARY KEY,is_active INTEGER NOT NULL)');
$db->exec('CREATE TABLE homeserver_https_sessions(user_id BIGINT PRIMARY KEY,device_id VARCHAR(100) NOT NULL UNIQUE,session_token_hash CHAR(64) NOT NULL,status VARCHAR(20) NOT NULL)');
$db->exec('CREATE TABLE homeserver_connections(user_id BIGINT PRIMARY KEY,device_id VARCHAR(100) NOT NULL,status VARCHAR(20) NOT NULL)');
VP3OperatorPairedWorker8A4::install($db);
$device='hs-'.str_repeat('a',24);
$token=str_repeat('A',64);
$db->exec('INSERT INTO users VALUES(1,1),(2,1)');
$q=$db->prepare("INSERT INTO homeserver_https_sessions VALUES(?,?,?,'active')");
$q->execute([1,$device,hash('sha256',$token)]);
$q->execute([2,'hs-'.str_repeat('b',24),hash('sha256',str_repeat('B',64))]);
$q=$db->prepare("INSERT INTO homeserver_connections VALUES(?,?,'paired')");
$q->execute([1,$device]);$q->execute([2,'hs-'.str_repeat('b',24)]);
$service=new VP3OperatorPairedWorker8A4($db,random_bytes(32));
$service->grant(1,$device,1800000000);
while(($line=fgets(STDIN,32769))!==false){
    try{
        if(strlen($line)>32768)throw new DomainException('Fixture request too large.');
        $req=json_decode($line,true,16,JSON_THROW_ON_ERROR);
        $t=$req['now']??null;
        if(!is_int($t)||$t<1800000000||$t>1800010000)throw new DomainException('Fixture clock invalid.');
        $op=$req['op']??null;
        switch($op){
            case 'http':
                $raw=base64_decode($req['body']??'',true);
                if(!is_string($raw)||!is_array($req['server']??null)||!is_bool($req['credential']??null))throw new DomainException('Invalid fixture frame.');
                $answer=VP3OperatorWorkerHTTP8A4::dispatch($req['server'],$raw,$service,$t,$req['credential']);
                $answer['body']=base64_encode($answer['body']);
                break;
            case 'status':
                $owner=$req['owner']??1;
                if(!in_array($owner,[1,2],true))throw new DomainException('Fixture owner invalid.');
                $answer=vp3_operator_worker_status_8a4($db,$owner,$t,true);
                break;
            case 'disconnect':
                $db->exec("UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=1");
                $answer=['ok'=>true];
                break;
            case 'rotate':
                // Require explicit credential revocation before replacing the session.
                $service->revoke(1,$t);
                $db->prepare("UPDATE homeserver_https_sessions SET status='active',session_token_hash=? WHERE user_id=1")
                    ->execute([hash('sha256',str_repeat('C',64))]);
                $service->grant(1,$device,$t);
                $answer=['ok'=>true];
                break;
            case 'counts':
                $answer=['registry'=>(int)$db->query('SELECT COUNT(*) FROM operator_worker_registry_8a4')->fetchColumn(),
                    'claims'=>(int)$db->query('SELECT COUNT(*) FROM operator_worker_claims_8a4')->fetchColumn()];
                break;
            default:throw new DomainException('Unknown fixture command.');
        }
        echo json_encode(['ok'=>true,'result'=>$answer],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    }catch(Throwable){
        echo "{\"ok\":false}\n";
    }
    flush();
}
