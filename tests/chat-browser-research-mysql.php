<?php
declare(strict_types=1);
require __DIR__.'/../includes/chat-browser-research-v1.php';
$dsn=(string)getenv('VP3_SECTION7_MYSQL_DSN');$password=(string)getenv('VP3_SECTION7_MYSQL_PASSWORD');
if(!$dsn)throw new RuntimeException('MySQL research certification requires its explicit fixture DSN.');
$pdo=new PDO($dsn,'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name=(string)getenv('VP3_RESEARCH_FIXTURE_DATABASE');$child=($argv[1]??'')!=='';
if(!$child){$name='research_'.bin2hex(random_bytes(6));$pdo->exec('CREATE DATABASE `'.$name.'`');putenv('VP3_RESEARCH_FIXTURE_DATABASE='.$name);}
if(!preg_match('/^research_[a-f0-9]{12}$/D',$name))throw new RuntimeException('Invalid isolated fixture database.');
$pdo->exec('USE `'.$name.'`');
$user=['id'=>7];$runtime='11111111-1111-4111-8111-111111111111';
function vp3_extension_uuid_v2000():string{$v=bin2hex(random_bytes(16));return substr($v,0,8).'-'.substr($v,8,4).'-'.substr($v,12,4).'-'.substr($v,16,4).'-'.substr($v,20);}
function vp3_browser_delegation_domain_v2190(mixed $host):string{return strtolower((string)$host);}
function vp3_browser_delegation_json_array_v2190(mixed $json):array{return json_decode((string)$json,true)?:[];}
function vp3_browser_runtime_row_v2200(PDO $pdo,int $uid,string $ns,string $id,bool $lock=false):?array{
 $q=$pdo->prepare('SELECT id FROM fixture_runtimes WHERE id=? AND owner_user_id=?'.($lock?' FOR UPDATE':''));$q->execute([$id,$uid]);
 return $q->fetchColumn()?['status'=>'active','allowed_actions_json'=>'["browser_research"]']:null;
}
function vp3_browser_research_runtime_v2230(PDO $pdo,array $u,string $ns,string $id):array{
 if(!vp3_browser_runtime_row_v2200($pdo,$u['id'],$ns,$id))throw new RuntimeException('Unknown runtime',403);return ['_multisite'=>['id'=>1]];
}
function vp3_browser_multisite_row_v2220(PDO $pdo,array $r):?array{return ['id'=>1];}
function vp3_browser_research_allowed_domains_v2230(PDO $pdo,array $m):array{return ['one.example','two.example'];}
function vp3_browser_research_start_v2230(PDO $pdo,array $u,string $ns,?array $agent,string $id,array $input):array{
 $q=$pdo->prepare('SELECT id FROM fixture_missions WHERE runtime_id=?');$q->execute([$id]);
 if($q->fetchColumn())throw new RuntimeException('An active mission already owns this runtime.',409);
 usleep(120000);$mission=vp3_extension_uuid_v2000();$pdo->prepare('INSERT INTO fixture_missions(id,runtime_id) VALUES(?,?)')->execute([$mission,$id]);return ['mission_id'=>$mission];
}
function vp3_browser_research_public_v2230(PDO $pdo,array $u,string $id):array{return ['mission_id'=>$id,'status'=>'active','pages'=>[],'claims'=>[]];}
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
if($child){
 try{
  if($argv[1]==='start'){$row=chat_research_row_v1($pdo,7,$argv[2]);chat_research_start_v1($pdo,$user,['id'=>$row['id'],'expected_hash'=>$row['review_hash'],'confirmed'=>true]);}
  elseif($argv[1]==='claim')chat_research_claim_v1($pdo,$user,$argv[3],['id'=>$argv[2]]);
  else throw new RuntimeException('Unknown fixture child');
  echo '200';
 }catch(Throwable $e){echo (int)$e->getCode()?:500;}
 exit;
}
function concurrent(array $one,array $two):array{
 $processes=[];foreach([$one,$two] as $args){
  $command=array_merge([PHP_BINARY,__FILE__],$args);$pipes=[];$proc=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if(!is_resource($proc))throw new RuntimeException('Cannot launch MySQL acceptance child.');$processes[]=[$proc,$pipes];
 }
 $results=[];foreach($processes as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0&&$err==='','Fixture child failed');$results[]=(int)$out;}sort($results);return $results;
}
try{
 $pdo->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;INSERT INTO users VALUES(7);CREATE TABLE fixture_runtimes(id CHAR(36) PRIMARY KEY,owner_user_id INT) ENGINE=InnoDB;CREATE TABLE fixture_missions(id CHAR(36) PRIMARY KEY,runtime_id CHAR(36)) ENGINE=InnoDB');
 $pdo->prepare('INSERT INTO fixture_runtimes VALUES(?,7)')->execute([$runtime]);
 chat_research_schema_v1($pdo);chat_research_schema_v1($pdo);
 $first=chat_research_prepare_v1($pdo,$user,'system',null,['question'=>'Compare https://one.example/about and https://two.example/services','runtime_id'=>$runtime,'context_key'=>'cloud:0','request_id'=>'22222222-2222-4222-8222-222222222222']);
 $second=chat_research_prepare_v1($pdo,$user,'system',null,['question'=>'Compare https://one.example/about and https://two.example/services','runtime_id'=>$runtime,'context_key'=>'cloud:0','request_id'=>'33333333-3333-4333-8333-333333333333']);
 check(concurrent(['start',$first['id']],['start',$second['id']])===[200,409],'Concurrent starts must create one canonical mission');
 check((int)$pdo->query('SELECT COUNT(*) FROM fixture_missions')->fetchColumn()===1,'One canonical mission after concurrency');
 $row=$pdo->query("SELECT * FROM chat_browser_research_requests_v1 WHERE status='waiting_chrome'")->fetch(PDO::FETCH_ASSOC);
 check(concurrent(['claim',$row['id'],'chrome-a'],['claim',$row['id'],'chrome-b'])===[200,409],'One Chrome reader after concurrency');
 $pdo->exec('DELETE FROM users WHERE id=7');check((int)$pdo->query('SELECT COUNT(*) FROM chat_browser_research_requests_v1')->fetchColumn()===0,'Owner deletion cascades request metadata');
 echo "PASS: production MySQL schema upgrade/idempotency, FK cleanup, parallel same-runtime starts, one canonical mission and exclusive concurrent Chrome reader.\n";
}finally{$pdo->exec('DROP DATABASE `'.$name.'`');}
