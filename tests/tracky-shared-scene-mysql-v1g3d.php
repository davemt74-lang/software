<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/tracky-cloud-v270.php';
$dsn=getenv('TRACKY_ORDER_TEST_DSN')?:'';if($dsn==='')throw new RuntimeException('Dedicated ephemeral database required.');
$connect=static fn()=>new PDO($dsn,(string)getenv('TRACKY_ORDER_TEST_USER'),(string)getenv('TRACKY_ORDER_TEST_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo=$connect();
function db(): PDO {global $pdo;return $pdo;}
function table_exists(string $table): bool {global $pdo;$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return (bool)$q->fetchColumn();}
function checkSceneMysql(bool $yes,string $message): void {if(!$yes)throw new RuntimeException($message);}
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/tracky-shared-scene-v1g3d.json'),true,32,JSON_THROW_ON_ERROR);
$active=$fixtures[0];$active['summary']['observed_at']=gmdate('Y-m-d\TH:i:s\Z');$active['fingerprint']=tracky_scene_digest_v1g3d($active['summary']);
$revoked=$fixtures[1];
if(($argv[1]??'')==='worker'){
 echo "READY\n";flush();$pdo->beginTransaction();$r=tracky_scene_apply_v1g3d($pdo,993,'scene-site',tracky_scene_normalize_v1g3d($active));$pdo->commit();echo json_encode($r);exit;
}
$pdo->exec('CREATE TABLE IF NOT EXISTS users(id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('INSERT INTO users(id) VALUES(993),(994)');
tracky_scene_ensure_schema_v1g3d($pdo);
$send=function(?array $v,int $user=993,string $site='scene-site')use($pdo){$pdo->beginTransaction();try{$r=tracky_scene_apply_v1g3d($pdo,$user,$site,$v===null?null:tracky_scene_normalize_v1g3d($v));$pdo->commit();return $r;}catch(Throwable $e){$pdo->rollBack();throw $e;}};
checkSceneMysql($send($active)['accepted'],'Active state not accepted');
checkSceneMysql(tracky_scene_read_v1g3d($pdo,994,'scene-site')===[],'Cross-account scene leaked');
checkSceneMysql(tracky_scene_read_v1g3d($pdo,993,'another-site')===[],'Cross-site scene leaked');
$pdo->beginTransaction();$r=tracky_scene_apply_v1g3d($pdo,993,'scene-site',$revoked);
checkSceneMysql($r['accepted'],'Revocation rejected');
// A second connection tries an older active observation while revocation owns
// the InnoDB row lock. It must wait and then see the committed newer revision.
$child=proc_open([getenv('TRACKY_TEST_PHP_BINARY')?:PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($child))throw new RuntimeException('Concurrency worker unavailable');
fclose($pipes[0]);stream_set_timeout($pipes[1],10);checkSceneMysql(trim((string)fgets($pipes[1]))==='READY','Worker did not start');
stream_set_blocking($pipes[1],false);usleep(200000);checkSceneMysql(stream_get_contents($pipes[1])==='','Competing active write bypassed row lock');
$pdo->commit();stream_set_blocking($pipes[1],true);$result=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);checkSceneMysql(proc_close($child)===0,'Worker failed: '.$errors);
$late=json_decode($result,true,32,JSON_THROW_ON_ERROR);checkSceneMysql(!$late['accepted']&&$late['revision']===2&&$late['state']==='revoked','Late upload reopened revoked scene');
checkSceneMysql($send($revoked)['accepted'],'Identical replay not idempotent');
checkSceneMysql(!$send(array_replace($active,['revision'=>2]))['accepted'],'Equal different fingerprint accepted');
checkSceneMysql($send(null)['state']==='revoked','Generic heartbeat restored scene');
checkSceneMysql(!isset(tracky_scene_read_v1g3d($pdo,993,'scene-site')['objects']),'Revocation exposed labels');
$pdo->beginTransaction();$fresh=array_replace($active,['revision'=>3]);tracky_scene_apply_v1g3d($pdo,993,'scene-site',$fresh);$pdo->rollBack();checkSceneMysql(tracky_scene_read_v1g3d($pdo,993,'scene-site')['state']==='revoked','Rolled back share persisted');
// Exercise the existing sync transaction, schema bootstrap, site binding,
// independent context sequence and receipt, rather than a new endpoint.
$payload=['protocol'=>'physical_context.v1','site'=>['id'=>'integrated-site','label'=>'HomeServer'],'status'=>'healthy','capabilities'=>[],'health'=>[],'events'=>[],'world_state'=>[],'context'=>[],'agent_scene_share'=>$fresh];
$result=tracky_cloud_v270_ingest($pdo,993,'device-a',$payload);checkSceneMysql($result['agent_scene_share']['accepted'],'Canonical sync omitted scene receipt');
$projection=tracky_cloud_v270_current_context($pdo,993,'integrated-site');checkSceneMysql(($projection['agent_scene']['state']??'')==='available','Cognitive current-context lost shared scene');
$rows=tracky_cloud_v270_world_state($pdo,993,'integrated-site');checkSceneMysql(count($rows)===5,'Read-time inferred graph absent');
try{tracky_cloud_v270_ingest($pdo,993,'device-b',$payload);throw new LogicException('Device binding replaced');}catch(RuntimeException $e){checkSceneMysql(str_contains($e->getMessage(),'another HomeServer device'),'Wrong device denial');}
$payload['agent_scene_share']=array_replace($revoked,['revision'=>4]);tracky_cloud_v270_ingest($pdo,993,'device-a',$payload);checkSceneMysql(tracky_cloud_v270_world_state($pdo,993,'integrated-site')===[],'Revoked graph still present');
$payload['world_state']=[['subject_id'=>'agent-eyes-object:chair','predicate'=>'possibly_visible_in','object_id'=>'room:studio','source_event_id'=>'agent-eyes:x','as_of'=>gmdate(DATE_ATOM),'sequence'=>1,'value'=>[]]];
try{tracky_cloud_v270_ingest($pdo,993,'device-a',$payload);throw new LogicException('Generic world-state consent bypass');}catch(RuntimeException $e){checkSceneMysql(str_contains($e->getMessage(),'separate ordered'),'Generic upload denial missing');}
$pdo->exec('DELETE FROM users WHERE id IN(993,994)');checkSceneMysql((int)$pdo->query('SELECT COUNT(*) FROM tracky_cloud_scene_share_order WHERE user_id IN(993,994)')->fetchColumn()===0,'Orphan scene metadata after account removal');
echo "TRACKY_SHARED_SCENE_MYSQL_V1G3D: canonical sync, schema, row-lock concurrency, account/site isolation, rollback, replay/revocation, context and inferred graph PASS\n";
