<?php
declare(strict_types=1);
// Reuse the real PDO reconciliation fixture and its existing full/filtered
// permission/deletion checks. MySQL CI shares its isolated fixture database.
if(($dsn=getenv('VP3_CONNECTION_MYSQL_DSN'))&&str_starts_with($dsn,'mysql:')){
    $reset=new PDO($dsn,'root',getenv('VP3_CONNECTION_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if($reset->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")->fetchColumn())$reset->exec('DELETE FROM users WHERE id=91');
}
$snapshotFixture=['files'=>[],'contacts'=>[],'calendar'=>[],'tasks'=>[],'fail_files'=>false];
function column_exists(string $table,string $column): bool {
    if($GLOBALS['mysql']){
        $s=db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->execute([$table,$column]);return (bool)$s->fetchColumn();
    }
    foreach(db()->query('PRAGMA table_info('.$table.')')->fetchAll() as $row)if($row['name']===$column)return true;
    return false;
}
function homeserver_files_v244_cloud_items(int $uid,string $query='',int $limit=100): array {
    if($GLOBALS['snapshotFixture']['fail_files'])throw new RuntimeException('Synthetic private failure must not enter coverage metadata.');
    return array_slice($GLOBALS['snapshotFixture']['files'],0,$limit);
}
function homeserver_contacts_v241_snapshot_records(int $uid,string $query='',int $limit=80): array {return array_slice($GLOBALS['snapshotFixture']['contacts'],0,$limit);}
function homeserver_task_calendar_v243_cloud_calendar_records(int $uid,string $from,string $to): array {return $GLOBALS['snapshotFixture']['calendar'];}
function homeserver_task_calendar_v243_cloud_tasks(int $uid,string $query='',int $limit=100): array {return array_slice($GLOBALS['snapshotFixture']['tasks'],0,$limit);}
require __DIR__.'/homeserver-continuity-permissions.php';

$pdo->exec('CREATE TABLE knowledge_items(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,title TEXT,description TEXT,content_text TEXT,updated_at TEXT,file_type TEXT)');
$insert=$pdo->prepare('INSERT INTO knowledge_items VALUES(?,91,?,?,?,CURRENT_TIMESTAMP,NULL)');
$all=[];
for($id=1;$id<=41;$id++){
    $insert->execute([$id,'Fixture note '.$id,'','fixtureword'.$id]);
    $all[]=homeserver_federated_v240_envelope('vp3_cloud','knowledge','knowledge:'.$id,'Fixture note '.$id,'fixtureword'.$id);
}
$initial=['authoritative_source'=>'vp3_cloud','snapshot_mode'=>'full','covered_datasets'=>['knowledge'],'revision'=>'complete-41','datasets'=>['knowledge'=>$all]];
homeserver_reconciliation_v246_reconcile_snapshot(91,$initial,'homeserver');
$bounded=homeserver_shared_v210_cloud_snapshot(91);
check(count($bounded['datasets']['knowledge'])===30,'The bounded producer retains its existing record limit');
check(!in_array('knowledge',$bounded['covered_datasets'],true),'A truncated dataset cannot claim complete absence coverage');
check($bounded['unavailable_datasets']['knowledge']==='snapshot_limited:record_limit','Record truncation must be explicit');
$result=homeserver_reconciliation_v246_reconcile_snapshot(91,$bounded,'homeserver');
check($result['tombstoned']===0,'A bounded Cloud snapshot must not tombstone omitted live notes');
check(!homeserver_reconciliation_v246_existing(91,'vp3_cloud','knowledge','knowledge:1','homeserver')['tombstoned'],'The oldest omitted live note remains available');
check(homeserver_reconciliation_v246_state(91)['last_summary']['unavailable_datasets']===$bounded['unavailable_datasets'],'Partial coverage warnings must survive durable reconciliation');
echo "PASS real 41-note export retains all previously synchronized live notes under its 30-record bound\n";

$pdo->exec('DELETE FROM knowledge_items WHERE id>30');
$complete=homeserver_shared_v210_cloud_snapshot(91);
check(in_array('knowledge',$complete['covered_datasets'],true),'Exactly the record limit remains complete when no extra record exists');
check($complete['snapshot_mode']==='full','Complete datasets keep normal full reconciliation');
check($complete['revision']!==$bounded['revision'],'Coverage changes participate in snapshot identity');
$result=homeserver_reconciliation_v246_reconcile_snapshot(91,$complete,'homeserver');
check($result['tombstoned']===11,'An actually complete snapshot still reconciles real deletion');
$filtered=homeserver_shared_v210_cloud_snapshot(91,'fixtureword1');
check($filtered['snapshot_mode']==='filtered','Query snapshots remain filtered');
check(homeserver_reconciliation_v246_reconcile_snapshot(91,$filtered,'homeserver')['tombstoned']===0,'Filtered absence never deletes remaining notes');
echo "PASS cap-plus-one detection preserves complete-limit deletion and filtered-snapshot behavior\n";

$byteRows=[];
for($id=1;$id<=6;$id++)$byteRows[]=homeserver_federated_v240_envelope('vp3_cloud','knowledge','byte:'.$id,'Byte fixture '.str_repeat('x',180),str_repeat('x',5000));
$byte=['knowledge'=>$byteRows,'contacts'=>[]];
$beforeByte=['authoritative_source'=>'vp3_cloud','snapshot_mode'=>'full','covered_datasets'=>['knowledge'],'revision'=>'byte-initial','datasets'=>$byte];
homeserver_reconciliation_v246_reconcile_snapshot(91,$beforeByte,'homeserver');
$budget=strlen(json_encode($byte,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))-(int)(strlen(json_encode($byteRows[0]))/2);
$unavailable=[];$fit=homeserver_shared_v210_fit_datasets($byte,$budget,$unavailable);
check(count($fit['knowledge'])===5&&$unavailable['knowledge']==='snapshot_limited:byte_limit','Byte pruning must identify the exact incomplete dataset');
$afterByte=['authoritative_source'=>'vp3_cloud','snapshot_mode'=>'full','covered_datasets'=>['contacts'],'unavailable_datasets'=>$unavailable,'revision'=>'byte-limited','datasets'=>$fit];
check(homeserver_reconciliation_v246_reconcile_snapshot(91,$afterByte,'homeserver')['tombstoned']===0,'Byte-pruned absence cannot delete a live mirror');
check(!homeserver_reconciliation_v246_existing(91,'vp3_cloud','knowledge','byte:6','homeserver')['tombstoned'],'The byte-pruned note remains live');
echo "PASS byte-pruned snapshots preserve omitted records and expose their limited coverage\n";

$snapshotFixture['fail_files']=true;
$unavailableSource=homeserver_shared_v210_cloud_snapshot(91);
check(!in_array('files',$unavailableSource['covered_datasets'],true)&&$unavailableSource['unavailable_datasets']['files']==='snapshot_unavailable:read_failed','A failed source read cannot become authoritative empty coverage');
$snapshotFixture['fail_files']=false;
$invalid=$beforeByte;$invalid['datasets']['knowledge']=[false];
try{homeserver_reconciliation_v246_reconcile_snapshot(91,$invalid,'homeserver');throw new LogicException('Malformed full row was accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'invalid record'),'Malformed full rows fail before cleanup');}
check(!homeserver_reconciliation_v246_existing(91,'vp3_cloud','knowledge','byte:6','homeserver')['tombstoned'],'Malformed full data cannot tombstone an existing record');
check(homeserver_reconciliation_v246_unavailable(['unavailable_datasets'=>['knowledge'=>'arbitrary private detail']],[])===[],'Unknown reason text must not be persisted into status');
check(homeserver_reconciliation_v246_unavailable(['unavailable_datasets'=>['files'=>'scope_limited:knowledge_collections']],[])===['files'=>'scope_limited:knowledge_collections'],'File collection scope warnings must survive reconciliation');
$invalid=$beforeByte;$invalid['datasets']['knowledge']=[[]];
try{homeserver_reconciliation_v246_reconcile_snapshot(91,$invalid,'homeserver');throw new LogicException('Identityless full row was accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'invalid record identity'),'Full cleanup cannot use invented positional identities');}
echo "PASS failed reads and malformed full records fail closed without deleting mirrors\n";
