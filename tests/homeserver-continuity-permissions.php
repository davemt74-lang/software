<?php
declare(strict_types=1);
// Execute production reconciliation and status against PDO; CI also uses MySQL.
final class ContinuitySQLite extends PDO {
    public function exec(string $query): int|false {
        if(str_contains($query,'CREATE TABLE IF NOT EXISTS homeserver_'))return 0;
        return parent::exec($query);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $query=str_replace(['UTC_TIMESTAMP()','ON DUPLICATE KEY UPDATE'],['CURRENT_TIMESTAMP','ON CONFLICT DO UPDATE SET'],$query);
        $query=preg_replace('/VALUES\(([a-z_]+)\)/i','excluded.$1',$query);
        $query=preg_replace('/\bIF\(/','IIF(',$query);
        return parent::prepare($query,$options);
    }
}
$dsn=getenv('VP3_CONNECTION_MYSQL_DSN')?:'sqlite::memory:';
$mysql=str_starts_with($dsn,'mysql:');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$pdo=$mysql?new PDO($dsn,'root',getenv('VP3_CONNECTION_MYSQL_PASSWORD'),$options):new ContinuitySQLite($dsn,null,null,$options);
function db(): PDO {return $GLOBALS['pdo'];}
function table_exists(string $table): bool {
    if($GLOBALS['mysql']){
        $s=db()->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    }else $s=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $s->execute([$table]);return (bool)$s->fetchColumn();
}
function check(bool $ok,string $detail): void {if(!$ok)throw new RuntimeException($detail);}
const VP3_HOMESERVER_RELEASE_VERSION='2.2';
function url(string $path): string {return $path;}
function homeserver_https_v1300_status(int $id): array {return ['paired'=>true,'connected'=>true];}
function homeserver_https_v1300_remote_operation(int $id,string $op,array $payload=[]): array {return [];}
$queued=0;$remoteFailure=true;$notifications=[];
function homeserver_https_v1300_queue(int $id,string $op,array $payload=[]): string {$GLOBALS['queued']++;return 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';}
function homeserver_https_v1300_wait(string $requestId,int $timeout=24000): array {
    if($GLOBALS['remoteFailure'])throw new RuntimeException('HomeServer shared context permission is unavailable: files.read');
    return ['homeserver_snapshot'=>$GLOBALS['legacy'],
        'reconciliation'=>['cloud_to_homeserver'=>['status'=>'completed','snapshot_mode'=>'full']]];
}
function create_notification(...$args): void {$GLOBALS['notifications'][]=$args;}
require dirname(__DIR__).'/includes/homeserver-federated-data-v240.php';
require dirname(__DIR__).'/includes/homeserver-reconciliation-v246.php';
require dirname(__DIR__).'/includes/homeserver-shared-agent-v210.php';

foreach(['homeserver_agent_events','homeserver_agent_state','homeserver_reconciliation_runs_v246','homeserver_reconciliation_state_v246','homeserver_federated_cursors','homeserver_federated_records'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
if(!table_exists('users'))$pdo->exec($mysql?'CREATE TABLE users(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB':'CREATE TABLE users(id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO users(id) VALUES(91)');
if(!$mysql){
    $pdo->exec("CREATE TABLE homeserver_federated_records(user_id INTEGER,authority_source TEXT,dataset TEXT,authority_key TEXT,canonical_id TEXT,observed_source TEXT,record_hash TEXT,source_updated_at TEXT,tombstoned INTEGER,last_seen_at TEXT,UNIQUE(user_id,authority_source,dataset,authority_key,observed_source))");
    $pdo->exec("CREATE TABLE homeserver_federated_cursors(user_id INTEGER,peer_source TEXT,dataset TEXT,revision TEXT,sync_cursor TEXT,last_sync_at TEXT,last_success_at TEXT,last_error TEXT,UNIQUE(user_id,peer_source,dataset))");
    $pdo->exec("CREATE TABLE homeserver_reconciliation_state_v246(user_id INTEGER PRIMARY KEY,needs_reconciliation INTEGER DEFAULT 1,last_disconnect_at TEXT,last_connected_at TEXT,last_reconciled_at TEXT,cloud_revision TEXT DEFAULT '',homeserver_revision TEXT DEFAULT '',last_run_id TEXT DEFAULT '',last_error TEXT DEFAULT '',last_summary_json TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE homeserver_reconciliation_runs_v246(id TEXT PRIMARY KEY,user_id INTEGER,peer_source TEXT,observed_source TEXT,snapshot_revision TEXT,snapshot_mode TEXT,trigger_reason TEXT,status TEXT,created_count INTEGER DEFAULT 0,updated_count INTEGER DEFAULT 0,restored_count INTEGER DEFAULT 0,unchanged_count INTEGER DEFAULT 0,tombstoned_count INTEGER DEFAULT 0,conflict_count INTEGER DEFAULT 0,dataset_summary_json TEXT,error TEXT DEFAULT '',started_at TEXT DEFAULT CURRENT_TIMESTAMP,completed_at TEXT)");
    $pdo->exec("CREATE TABLE homeserver_agent_state(user_id INTEGER PRIMARY KEY,connection_state TEXT DEFAULT '',status_fingerprint TEXT DEFAULT '',last_event_at TEXT,last_roundtrip_ok INTEGER DEFAULT 0,last_sync_at TEXT,cloud_revision TEXT DEFAULT '',homeserver_revision TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE homeserver_agent_events(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,event_type TEXT,connection_state TEXT,detail TEXT,metadata_json TEXT)");
}
homeserver_federated_v240_ensure_schema($pdo);
homeserver_reconciliation_v246_ensure_schema($pdo);
homeserver_shared_v210_ensure_schema($pdo);
$file=homeserver_federated_v240_envelope('homeserver','files','file:existing','Existing native file','metadata');
$contact=homeserver_federated_v240_envelope('homeserver','contacts','address_book:1','Native contact','native notes');
$cloudFile=homeserver_federated_v240_envelope('vp3_cloud','files','file:existing','Cloud native file','Cloud metadata');
homeserver_federated_v240_observe(91,$cloudFile,'vp3_cloud');
$initial=['authoritative_source'=>'homeserver','snapshot_mode'=>'full','covered_datasets'=>['files','contacts'],'revision'=>'initial','datasets'=>['files'=>[$file],'contacts'=>[$contact]]];
homeserver_reconciliation_v246_reconcile_snapshot(91,$initial);
$legacy=$initial;$legacy['revision']='legacy';$legacy['covered_datasets']=['contacts'];$legacy['datasets']['files']=[];
$legacy['unavailable_datasets']=['files'=>'permission_required:files.read'];
$result=homeserver_reconciliation_v246_reconcile_snapshot(91,$legacy);
check($result['status']==='completed'&&$result['tombstoned']===0,'Authorized context must complete without tombstoning ungranted files');
$state=homeserver_reconciliation_v246_state(91);
check(!$state['needs_reconciliation'],'Authorized context must become current');
check($state['last_summary']['unavailable_datasets']===$legacy['unavailable_datasets'],'File permission gap must remain visible');
check(!homeserver_reconciliation_v246_existing(91,'homeserver','files','file:existing','vp3_cloud')['tombstoned'],'Native file mirror must survive absent coverage');
check(!homeserver_reconciliation_v246_existing(91,'vp3_cloud','files','file:existing','vp3_cloud')['tombstoned'],'Cloud authority must remain intact');
echo "PASS authorized coverage, retained native file mirrors and separate Cloud ownership\n";

homeserver_reconciliation_v246_mark_required(91,'prior reconciliation error');
$live=['connection_state'=>'connected','connected'=>true,'paired'=>true,'transport'=>'vp3_https'];
$status=homeserver_shared_v210_reconcile_status(91,$live);
check($queued===1,'A failed reconciliation must not be queued twice in one status request');
check($status['connected']&&$status['agent_brain_status']['state']==='reconciling','Failed sync must preserve connected transport and stale context');
check(str_contains($status['reconciliation']['last_error'],'files.read'),'Exact remote permission failure must be retained');
$status=homeserver_shared_v210_reconcile_status(91,$live);
check($queued===1,'Pre-run failures must obey the retry cooldown');
echo "PASS one attempt per status request and durable cooldown for remote permission failures\n";

$pdo->exec("UPDATE homeserver_reconciliation_state_v246 SET last_disconnect_at='2000-01-01 00:00:00' WHERE user_id=91");
$pdo->exec("UPDATE homeserver_reconciliation_runs_v246 SET started_at='2000-01-01 00:00:00',completed_at='2000-01-01 00:00:00' WHERE user_id=91");
$remoteFailure=false;
$status=homeserver_shared_v210_reconcile_status(91,$live);
check($queued===2,'Eligible retry must run once');
check(!$status['reconciliation']['needs_reconciliation'],'Successful authorized sync must restore current context');
check($status['agent_brain_status']['priority']==='watch','Ungranted file coverage must be a visible warning');
check($status['diagnostics']['last_shared_sync_at']!==null,'Successful sync timestamp must be recorded');
echo "PASS automatic recovery, successful timestamp and visible file permission warning\n";

$restored=$initial;$restored['revision']='files-authorized';$restored['datasets']['files']=[];
$result=homeserver_reconciliation_v246_reconcile_snapshot(91,$restored);
check($result['tombstoned']===1,'Authorized full file coverage must still reconcile actual deletion');
check(homeserver_reconciliation_v246_state(91)['last_summary']['unavailable_datasets']===[],'Permission warning must clear after file coverage returns');
echo "PASS file coverage restoration retains full-snapshot deletion rules\n";
