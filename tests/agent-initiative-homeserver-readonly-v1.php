<?php
declare(strict_types=1);

// Exercise the actual connection, capability, credentials, and scope chain.
// The unowned HTTPS transport is represented by real disposable SQL receipts;
// these tests never contact a relay, a provider, or an external HomeServer.
ini_set('zend.exception_ignore_args','1');
$fixtureRoot=sys_get_temp_dir().'/vp3-homeserver-readonly-'.bin2hex(random_bytes(8));
mkdir($fixtureRoot.'/private',0700,true);
define('STONEFELLOW_ROOT',$fixtureRoot);
$_SESSION=[];

class InitiativeHomeServerReadonlyPDO extends PDO
{
    public bool $guard=false;
    public bool $bookkeeping=false;
    public bool $legacyInstallerShim=false;
    public int $legacyInstallerCalls=0;
    public string $denyRead='';
    public int $ddlAttempts=0;
    public int $domainWrites=0;
    public array $statements=[];

    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $this->statements[]=$query;
        if($this->guard){
            if($this->denyRead!==''&&str_contains($query,$this->denyRead)){
                $error=new PDOException('Synthetic private metadata denial.');
                $error->errorInfo=['42000',1142,'Synthetic private metadata denial.'];
                throw $error;
            }
            $read=(bool)preg_match('/\A\s*(?:SELECT|PRAGMA)\b/i',$query);
            $bookkeeping=$this->bookkeeping&&(bool)preg_match('/\A\s*(?:INSERT INTO|UPDATE) fixture_https_requests\b/i',$query);
            if(!$read&&!$bookkeeping){
                ++$this->domainWrites;
                if(preg_match('/\A\s*(?:CREATE|ALTER|DROP|TRUNCATE)\b/i',$query))++$this->ddlAttempts;
                throw new LogicException('A readonly metadata request attempted a database mutation.');
            }
        }
        return parent::prepare($query,$options);
    }

    public function exec(string $statement): int|false
    {
        $this->statements[]=$statement;
        if($this->guard){
            if(preg_match('/\A\s*(?:CREATE|ALTER|DROP|TRUNCATE)\b/i',$statement))++$this->ddlAttempts;
            ++$this->domainWrites;
            throw new LogicException('A readonly metadata request attempted DDL or a direct write.');
        }
        // Legacy foreground readers call their original MySQL installer even on
        // an installed database. Only that already-existing fixture schema is
        // acknowledged here; the readonly guard above always rejects all DDL.
        if($this->legacyInstallerShim&&preg_match('/\ACREATE TABLE IF NOT EXISTS (homeserver_connections|homeserver_releases|agent_compute_preferences)\b/',ltrim($statement),$match)){
            $q=parent::prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$match[1]]);
            if($q->fetchColumn()===false)throw new LogicException('The legacy shim cannot initialize an absent table.');
            ++$this->legacyInstallerCalls;return 0;
        }
        return parent::exec($statement);
    }

    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->statements[]=$query;
        if($this->guard&&!preg_match('/\A\s*(?:SELECT|PRAGMA)\b/i',$query)){
            ++$this->domainWrites;
            if(preg_match('/\A\s*(?:CREATE|ALTER|DROP|TRUNCATE)\b/i',$query))++$this->ddlAttempts;
            throw new LogicException('A readonly metadata query attempted a mutation.');
        }
        return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
    }
}

function db(): ?PDO{return $GLOBALS['readonly_hs_pdo']??null;}
function table_exists(string $table): bool
{
    $pdo=db();if(!$pdo)return false;
    $q=$pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $q->execute([$table]);return $q->fetchColumn()!==false;
}
function column_exists(string $table,string $column): bool
{
    if(!preg_match('/\A[a-z_]+\z/i',$table))return false;
    return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
}
function current_user(): ?array{return $GLOBALS['readonly_hs_current_user']??null;}
function homeserver_https_v1300_status(int $owner): ?array
{
    ++$GLOBALS['readonly_hs_status_calls'];
    $pdo=db();if(!$pdo)throw new RuntimeException('Fixture transport database unavailable.',503);
    if($pdo->inTransaction())throw new LogicException('A transport status request held a source transaction.');
    $q=$pdo->prepare('SELECT user_id,status FROM fixture_https_sessions WHERE user_id=?');$q->execute([$owner]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return $row?['paired'=>$row['status']==='active','connected'=>$row['status']==='active']:null;
}
function homeserver_https_v1300_queue(int $owner,string $operation,array $payload=[]): string
{
    ++$GLOBALS['readonly_hs_queue_calls'];
    $pdo=db();if(!$pdo||$pdo->inTransaction())throw new LogicException('A transport queue request held a source transaction.');
    if($operation!=='tools.list'||$payload!==[])throw new LogicException('A readonly scope request attempted an unrelated operation.');
    $id='fixture-'.(++$GLOBALS['readonly_hs_request_counter']);
    $old=$pdo->bookkeeping;$pdo->bookkeeping=true;
    try{$pdo->prepare('INSERT INTO fixture_https_requests(id,user_id,operation,status) VALUES(?,?,?,?)')->execute([$id,$owner,$operation,'queued']);}
    finally{$pdo->bookkeeping=$old;}
    return $id;
}
function homeserver_https_v1300_wait(string $id,int $timeoutMs=24000): array
{
    ++$GLOBALS['readonly_hs_wait_calls'];
    $pdo=db();if(!$pdo||$pdo->inTransaction())throw new LogicException('A transport wait held a source transaction.');
    $q=$pdo->prepare('SELECT user_id,operation,status FROM fixture_https_requests WHERE id=?');$q->execute([$id]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||$row['operation']!=='tools.list')throw new LogicException('A request receipt changed identity.');
    if(!empty($GLOBALS['readonly_hs_transport_outage']))throw new RuntimeException('Synthetic transport outage.',503);
    $old=$pdo->bookkeeping;$pdo->bookkeeping=true;
    try{$pdo->prepare('UPDATE fixture_https_requests SET status=? WHERE id=?')->execute(['completed',$id]);}
    finally{$pdo->bookkeeping=$old;}
    return $GLOBALS['readonly_hs_transport_result'];
}
function homeserver_https_v1300_remote_operation(int $owner,string $operation,array $payload=[]): array
{
    // Controlled transport adapter only. The policy, owner-aware routing,
    // credential selection and scope interpretation remain actual source code.
    return homeserver_https_v1300_wait(homeserver_https_v1300_queue($owner,$operation,$payload));
}

require_once dirname(__DIR__).'/includes/homeserver-vp3.php';
require_once dirname(__DIR__).'/includes/homeserver-agent-v018.php';
require_once dirname(__DIR__).'/includes/homeserver-capabilities-v024.php';
require_once dirname(__DIR__).'/includes/homeserver-scope-v026.php';

$cases=0;
function readonly_hs_assert(bool $condition,string $label): void
{
    if(!$condition)throw new RuntimeException('ASSERTION: '.$label);
    ++$GLOBALS['cases'];echo 'PASS '.$label."\n";
}
function readonly_hs_blocked(callable $read,string $label,array $codes=[503]): void
{
    try{$read();}catch(RuntimeException $error){
        readonly_hs_assert(!($error instanceof PDOException)&&in_array($error->getCode(),$codes,true),$label);
        return;
    }
    throw new RuntimeException('ASSERTION: '.$label.' was allowed.');
}
function readonly_hs_database(bool $schema=true): InitiativeHomeServerReadonlyPDO
{
    $pdo=new InitiativeHomeServerReadonlyPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if($schema)$pdo->exec('CREATE TABLE homeserver_connections(user_id INTEGER PRIMARY KEY,status TEXT,capabilities_json TEXT,homeserver_token_enc TEXT,relay_token_enc TEXT)');
    $pdo->exec('CREATE TABLE homeserver_https_sessions(user_id INTEGER PRIMARY KEY,status TEXT); CREATE TABLE fixture_https_sessions(user_id INTEGER PRIMARY KEY,status TEXT); CREATE TABLE fixture_https_requests(id TEXT PRIMARY KEY,user_id INTEGER,operation TEXT,status TEXT)');
    $pdo->guard=true;return $pdo;
}
function readonly_hs_setup(InitiativeHomeServerReadonlyPDO $pdo,callable $writer): void
{
    $old=$pdo->guard;$pdo->guard=false;
    try{$writer($pdo);}finally{$pdo->guard=$old;}
}
function readonly_hs_calls(): array
{
    return [$GLOBALS['readonly_hs_status_calls'],$GLOBALS['readonly_hs_queue_calls'],$GLOBALS['readonly_hs_wait_calls']];
}

set_exception_handler(static function(Throwable $error)use($fixtureRoot):void{
    $label=str_starts_with($error->getMessage(),'ASSERTION: ')?$error->getMessage():'Unexpected '.get_class($error).' code '.(int)$error->getCode().' at '.basename($error->getFile()).':'.$error->getLine();
    fwrite(STDERR,'FAIL '.$label."\n");exit(1);
});
register_shutdown_function(static function()use($fixtureRoot):void{
    if(is_file($fixtureRoot.'/private/homeserver-vp3.key'))unlink($fixtureRoot.'/private/homeserver-vp3.key');
    if(is_dir($fixtureRoot.'/private'))rmdir($fixtureRoot.'/private');
    if(is_dir($fixtureRoot))rmdir($fixtureRoot);
});

$GLOBALS['readonly_hs_status_calls']=$GLOBALS['readonly_hs_queue_calls']=$GLOBALS['readonly_hs_wait_calls']=$GLOBALS['readonly_hs_request_counter']=0;
$GLOBALS['readonly_hs_transport_result']=['app_scope'=>['cloud_allowed'=>true,'tool_names'=>['tools.list']]];
$pdo=$GLOBALS['readonly_hs_pdo']=readonly_hs_database();
$home=homeserver_vp3_encrypt(str_repeat('h',32));$relay=homeserver_vp3_encrypt(str_repeat('r',32));
$short=homeserver_vp3_encrypt('short');
$keyFile=$fixtureRoot.'/private/homeserver-vp3.key';$keyBytes=file_get_contents($keyFile);
readonly_hs_setup($pdo,static function(PDO $db)use($home,$relay,$short):void{
    $q=$db->prepare('INSERT INTO homeserver_connections VALUES(?,?,?,?,?)');
    foreach([
        [1,'paired','{"features":["app.scopes.v1"]}',$home,$relay],
        [2,'paired','{"features":[]}',$home,$relay],
        [3,'paired','not-json',$home,$relay],
        [4,'paired','[]',$home,$relay],
        [5,'paired','{"features":"app.scopes.v1"}',$home,$relay],
        [6,'paired','{"features":["app.scopes.v1"]}','broken-format',$relay],
        [7,'paired','{"features":["app.scopes.v1"]}','',$relay],
        [8,'paired','{"features":["app.scopes.v1"]}',$home,$relay],
        [9,'paired','{"features":["app.scopes.v1"]}',$home,''],
        [10,'paired','{}',$home,$relay],
        [11,'paired','',$home,$relay],
        [12,'paired','{"features":[true]}',$home,$relay],
        [13,'paired','{"features":["app.scopes.v1"]}',$short,$relay],
        [14,'unpaired','{"features":[]}',null,null],
        [15,'paired','{"features":["app.scopes.v1"]}','v1:'.base64_encode(str_repeat('x',29)),$relay],
        [16,'paired','{"features":["app.scopes.v1"]}',$home,$relay],
        [17,'paired','{"features":{}}',$home,$relay],
        [18,'paired','{"features":{"0":"app.scopes.v1"}}',$home,$relay],
    ] as $row)$q->execute($row);
    $db->exec("INSERT INTO fixture_https_sessions VALUES(1,'active'),(6,'active'),(7,'active'),(13,'active'),(16,'active'); INSERT INTO homeserver_https_sessions VALUES(1,'active'),(6,'active'),(7,'active'),(13,'active'),(16,'active')");
});

$row=homeserver_vp3_connection(1,true,$pdo);
readonly_hs_assert((int)$row['user_id']===1,'strict connection reads the exact owner through the supplied PDO');
$GLOBALS['readonly_hs_pdo']=null;
readonly_hs_assert((int)homeserver_vp3_connection(1,true,$pdo)['user_id']===1,'an explicit source PDO is usable without an unrelated global database');
readonly_hs_assert(homeserver_capability_v024_raw(1,true,$pdo)['features']===['app.scopes.v1'],'capability metadata preserves the supplied PDO rather than consulting global state');
$isolatedCredentials=homeserver_agent_v018_credentials(1,true,$pdo);
readonly_hs_assert($isolatedCredentials['transport']==='vp3_https'&&$isolatedCredentials['user_id']===1,'readonly credential/session metadata is bound to the supplied PDO');
readonly_hs_blocked(static fn()=>homeserver_vp3_remote_operation_for_user(1,'tools.list',[],'',true,$pdo),'a missing canonical transport database cannot be guessed from a source PDO',[409,503]);
$GLOBALS['readonly_hs_pdo']=$pdo;
readonly_hs_assert(homeserver_vp3_connection(999,true,$pdo)===null,'a successful absent connection row is known unpaired');
$empty=readonly_hs_database(false);
readonly_hs_blocked(static fn()=>homeserver_vp3_connection(1,true,$empty),'missing connection storage is unavailable and cannot prove an unpaired account');
readonly_hs_assert($empty->ddlAttempts===0&&$empty->domainWrites===0,'missing storage performs no DDL or domain writes');
$broken=readonly_hs_database(false);
readonly_hs_setup($broken,static fn(PDO $db)=>$db->exec('CREATE TABLE homeserver_connections(unexpected INTEGER)'));
readonly_hs_blocked(static fn()=>homeserver_vp3_connection(1,true,$broken),'unknown connection column/schema failure is unavailable, not unpaired');
$pdo->denyRead='FROM homeserver_connections';
readonly_hs_blocked(static fn()=>homeserver_vp3_connection(1,true,$pdo),'denied connection metadata is unavailable, not unpaired');$pdo->denyRead='';

$raw=homeserver_capability_v024_raw(1,true,$pdo);
readonly_hs_assert($raw['features']===['app.scopes.v1'],'readonly capability metadata uses the real authenticated connection row');
readonly_hs_assert(homeserver_capability_v024_raw(999,true,$pdo)===[],'only a proven absent connection yields an empty readonly capability inventory');
readonly_hs_assert(homeserver_scope_v026_supported(1,true,$pdo),'the strict scope capability recognizes its explicit feature');
readonly_hs_assert(!homeserver_scope_v026_supported(2,true,$pdo),'a valid explicit empty feature list is known legacy scope absence');
foreach([3,4,5,10,11,12,17,18] as $id){
    readonly_hs_blocked(static fn()=>homeserver_capability_v024_raw($id,true,$pdo),'malformed or undeclared capability metadata fails closed for fixture '.$id);
}
readonly_hs_blocked(static fn()=>homeserver_scope_v026_supported(3,true,$pdo),'scope support cannot translate malformed capability JSON into false');

$credentials=homeserver_agent_v018_credentials(1,true,$pdo);
readonly_hs_assert($credentials['transport']==='vp3_https'&&$credentials['user_id']===1&&strlen($credentials['home'])===32,'actual encrypted credentials choose the HTTPS transport without provisioning');
$credentials=homeserver_agent_v018_credentials(8,true,$pdo);
readonly_hs_assert($credentials['transport']==='custom_websocket'&&strlen($credentials['relay'])===32,'actual encrypted credentials preserve the legacy WebSocket route');
readonly_hs_assert(homeserver_agent_v018_credentials(999,true,$pdo)===null,'an absent connection can produce absent credentials');
readonly_hs_assert(homeserver_agent_v018_credentials(14,true,$pdo)===null,'an explicit unpaired row with no credential material remains known unpaired');
foreach([6,7,9,13,15] as $id){
    readonly_hs_blocked(static fn()=>homeserver_agent_v018_credentials($id,true,$pdo),'bad or incomplete existing credentials fail closed for fixture '.$id);
}
$optionalHttps=readonly_hs_database();
readonly_hs_setup($optionalHttps,static function(PDO $db)use($home,$relay):void{
    $db->exec('DROP TABLE homeserver_https_sessions');
    $db->prepare('INSERT INTO homeserver_connections VALUES(?,?,?,?,?)')->execute([8,'paired','{"features":["app.scopes.v1"]}',$home,$relay]);
});
readonly_hs_assert(homeserver_agent_v018_credentials(8,true,$optionalHttps)['transport']==='custom_websocket','a proven absent optional HTTPS table preserves valid WebSocket credentials without provisioning');
$malformedHttps=readonly_hs_database();
readonly_hs_setup($malformedHttps,static function(PDO $db)use($home,$relay):void{
    $db->exec('DROP TABLE homeserver_https_sessions; CREATE TABLE homeserver_https_sessions(user_id INTEGER PRIMARY KEY,unexpected TEXT)');
    $db->prepare('INSERT INTO homeserver_connections VALUES(?,?,?,?,?)')->execute([8,'paired','{"features":["app.scopes.v1"]}',$home,$relay]);
});
readonly_hs_blocked(static fn()=>homeserver_agent_v018_credentials(8,true,$malformedHttps),'unknown optional HTTPS schema cannot become an absent transport');
readonly_hs_assert($optionalHttps->ddlAttempts===0&&$malformedHttps->ddlAttempts===0,'optional transport inspection never creates or repairs its schema');
unlink($keyFile);
readonly_hs_blocked(static fn()=>homeserver_agent_v018_credentials(1,true,$pdo),'a missing key cannot initialize new credential storage during a readonly read');
readonly_hs_assert(!file_exists($keyFile),'readonly decryption leaves a missing key absent');
file_put_contents($keyFile,'invalid-key');
readonly_hs_blocked(static fn()=>homeserver_agent_v018_credentials(1,true,$pdo),'an unreadable credential key cannot become an unpaired decision');
readonly_hs_assert(file_get_contents($keyFile)==='invalid-key','readonly decryption does not replace a damaged key');
file_put_contents($keyFile,$keyBytes);

$before=readonly_hs_calls();$pdo->beginTransaction();
readonly_hs_blocked(static fn()=>homeserver_scope_v026_fetch(1,true,true,$pdo),'a readonly scope refresh cannot perform transport work inside a source transaction',[409,503]);
readonly_hs_assert(readonly_hs_calls()===$before&&$pdo->inTransaction(),'transaction denial occurs before transport and leaves the caller transaction intact');$pdo->rollBack();
$before=readonly_hs_calls();$pdo->beginTransaction();
readonly_hs_blocked(static fn()=>homeserver_vp3_remote_operation_for_user(1,'tools.list',[],'',true,$pdo),'the readonly remote wrapper rejects a source transaction',[409,503]);
readonly_hs_assert(readonly_hs_calls()===$before&&$pdo->inTransaction(),'remote transaction denial creates no request or status read');$pdo->rollBack();
readonly_hs_blocked(static fn()=>homeserver_vp3_remote_operation_for_user(1,'chat.send',[],'',true,$pdo),'readonly policy transport cannot become a general domain action',[403,409,503]);

$beforeRequests=(int)$pdo->query('SELECT COUNT(*) FROM fixture_https_requests')->fetchColumn();
$state=homeserver_scope_v026_fetch(1,true,true,$pdo);
readonly_hs_assert($state['supported']===true&&$state['available']===true&&$state['scope']['cloud_allowed']===true,'actual readonly scope helpers use the HTTPS tools.list response');
readonly_hs_assert((int)$pdo->query("SELECT COUNT(*) FROM fixture_https_requests WHERE operation='tools.list' AND status='completed'")->fetchColumn()===$beforeRequests+1,'HTTPS policy transport creates and completes only its own canonical request receipt');
readonly_hs_assert(!homeserver_scope_v026_blocks_cloud($state),'a fresh strict boolean Cloud allowance is not marked restrictive');
$GLOBALS['readonly_hs_transport_result']=['payload'=>['app_scope'=>['cloud_allowed'=>false]]];
$blocked=homeserver_scope_v026_fetch(1,true,true,$pdo);
readonly_hs_assert($blocked['available']===true&&$blocked['scope']['cloud_allowed']===false&&homeserver_scope_v026_blocks_cloud($blocked),'the existing wrapper restriction survives readonly normalization');
$GLOBALS['readonly_hs_transport_outage']=true;
$offline=homeserver_scope_v026_fetch(1,true,true,$pdo);
readonly_hs_assert($offline['supported']===true&&$offline['available']===false&&$offline['scope']===null,'a transport outage remains explicitly unavailable');
readonly_hs_assert($offline['last_known_cloud_blocked']===true&&homeserver_scope_v026_blocks_cloud($offline),'a known restrictive scope remains restrictive during an outage');
$unknownOffline=homeserver_scope_v026_fetch(16,true,true,$pdo);
readonly_hs_assert($unknownOffline['supported']===true&&$unknownOffline['available']===false&&$unknownOffline['scope']===null&&$unknownOffline['last_known_cloud_blocked']===false,'a first outage without historical restrictions remains unknown rather than an authoritative allowance');
unset($GLOBALS['readonly_hs_transport_outage']);
$_SESSION=[];
foreach([['app_scope'=>[]],['app_scope'=>['cloud_allowed'=>'yes']],['app_scope'=>['cloud_allowed'=>1]]] as $invalid){
    $GLOBALS['readonly_hs_transport_result']=$invalid;
    $state=homeserver_scope_v026_fetch(1,true,true,$pdo);
    readonly_hs_assert($state['supported']===true&&$state['available']===false&&$state['scope']===null,'missing or nonboolean remote Cloud permission cannot become a fresh allowance');
}
$GLOBALS['readonly_hs_transport_result']=['app_scope'=>['cloud_allowed'=>true]];
$allowed=homeserver_scope_v026_fetch(1,true,true,$pdo);
$GLOBALS['readonly_hs_transport_result']=['app_scope'=>['cloud_allowed'=>false]];
$cached=homeserver_scope_v026_fetch(1,false,true,$pdo);
readonly_hs_assert($cached===$allowed,'the same readonly PDO namespace can reuse its current request policy snapshot');
$fresh=homeserver_scope_v026_fetch(1,true,true,$pdo);
readonly_hs_assert($fresh['scope']['cloud_allowed']===false,'force refresh replaces the prior request policy snapshot');

$other=readonly_hs_database();
readonly_hs_setup($other,static fn(PDO $db)=>$db->exec("INSERT INTO homeserver_connections VALUES(1,'paired','{\"features\":[]}',NULL,NULL)"));
$otherState=homeserver_scope_v026_fetch(1,false,true,$other);
readonly_hs_assert($otherState['supported']===false&&$otherState['available']===false,'policy snapshots cannot cross PDO databases with the same owner identifier');
readonly_hs_blocked(static fn()=>homeserver_vp3_remote_operation_for_user(1,'tools.list',[],'',true,$other),'transport cannot silently use a different global PDO than its bound source',[409,503]);
readonly_hs_assert($other->ddlAttempts===0&&$other->domainWrites===0,'a second database is never provisioned or changed by source verification');

$pdo->denyRead='FROM homeserver_connections';
readonly_hs_blocked(static fn()=>homeserver_scope_v026_fetch(1,true,true,$pdo),'a metadata denial propagates instead of becoming unsupported legacy Cloud policy');$pdo->denyRead='';
readonly_hs_assert($pdo->ddlAttempts===0&&$pdo->domainWrites===0,'the complete readonly connection/capability/credential/policy chain performs zero DDL and zero domain writes');

// The old call shape keeps its established soft failure. The guard rejects its
// legacy installer attempt, so this compatibility assertion cannot provision DBs.
$legacyRaw=homeserver_capability_v024_raw(1);
readonly_hs_assert($legacyRaw===[],'the existing one-argument capability API remains fail-soft');
$legacyCredentials=homeserver_agent_v018_credentials(1);
readonly_hs_assert($legacyCredentials===null,'the existing one-argument credentials API remains fail-soft');
$legacyState=homeserver_scope_v026_fetch(1,true);
readonly_hs_assert($legacyState['supported']===false&&$legacyState['available']===false,'the existing default scope call retains its legacy fail-soft namespace');
readonly_hs_assert(homeserver_scope_v026_fetch(1,false,true,$pdo)===$fresh,'a legacy scope cache cannot replace the exact readonly PDO snapshot');

// Exercise the real default foreground chain as well as the stricter worker
// path. Its legacy installer calls acknowledge only already-installed tables;
// no alternative policy implementation is supplied by this fixture.
require_once dirname(__DIR__).'/includes/agent-compute-v020.php';
require_once dirname(__DIR__).'/includes/agent-compute-v023.php';
require_once dirname(__DIR__).'/includes/agent-runtime-routing-v420.php';
require_once dirname(__DIR__).'/includes/video-meetings-v1800.php';
require_once dirname(__DIR__).'/includes/video-meetings-homeserver-v1801.php';
readonly_hs_setup($pdo,static function(PDO $db):void{
    $db->exec('CREATE TABLE homeserver_releases(id INTEGER PRIMARY KEY);
        CREATE TABLE agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference TEXT);
        CREATE TABLE agent_compute_overrides(user_id INTEGER,agent_id INTEGER,preference TEXT);
        CREATE TABLE video_meetings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,organizer_agent_id INTEGER,public_id TEXT,room_name TEXT,agent_mode TEXT,transcription_enabled INTEGER);
        CREATE TABLE video_meeting_participants(id INTEGER PRIMARY KEY,invite_token TEXT);
        CREATE TABLE video_meeting_transcript_segments(id INTEGER PRIMARY KEY);
        CREATE TABLE video_meeting_artifacts(id INTEGER PRIMARY KEY)');
    foreach([1,2,10,17,18,999] as $owner){
        $db->prepare('INSERT INTO agent_compute_preferences VALUES(?,?)')->execute([$owner,'auto']);
        $db->prepare('INSERT INTO video_meetings VALUES(?,?,?,?,?,?,?)')->execute([$owner,$owner,0,sprintf('%032x',$owner),'room-'.$owner,'notes',1]);
    }
    $db->prepare('UPDATE homeserver_connections SET capabilities_json=? WHERE user_id=?')->execute(['{"features":{"0":"legacy.feature"}}',18]);
});
$pdo->legacyInstallerShim=true;
$wholeDdlBefore=$pdo->ddlAttempts;$wholeDomainWritesBefore=$pdo->domainWrites;
$oldRelayEnvironment=getenv('VP3_HOMESERVER_RELAY_URL');putenv('VP3_HOMESERVER_RELAY_URL');
register_shutdown_function(static function()use($oldRelayEnvironment):void{
    putenv($oldRelayEnvironment===false?'VP3_HOMESERVER_RELAY_URL':'VP3_HOMESERVER_RELAY_URL='.$oldRelayEnvironment);
});
$GLOBALS['readonly_hs_bare_calls']=0;
$config=['homeserver'=>['relay_base_url'=>new class{
    public function __toString(): string{++$GLOBALS['readonly_hs_bare_calls'];return '';}
}]];
$GLOBALS['readonly_hs_current_user']=['id'=>1];
$GLOBALS['readonly_hs_transport_result']=['app_scope'=>['cloud_allowed'=>true]];
unset($GLOBALS['readonly_hs_transport_outage']);$_SESSION=[];
$pdo->guard=false;
$before=readonly_hs_calls();
$defaultHealthy=homeserver_scope_v026_fetch(1,true);
readonly_hs_assert($defaultHealthy['supported']===true&&$defaultHealthy['available']===true&&$defaultHealthy['scope']['cloud_allowed']===true,'the real default scope chain reaches a healthy HTTPS-only pairing');
readonly_hs_assert(readonly_hs_calls()[1]===$before[1]+1&&readonly_hs_calls()[2]===$before[2]+1,'default scope reads use the actual owner-aware HTTPS queue and wait route');
readonly_hs_assert($GLOBALS['readonly_hs_bare_calls']===0,'healthy default HTTPS scope never attempts the bare relay');
$meeting=video_meeting_row_v1800($pdo,1);
$defaultMeeting=video_meeting_homeserver_policy_v1801($pdo,$meeting,true);
readonly_hs_assert($defaultMeeting['cloud_processing_allowed']===true&&$defaultMeeting['policy_resolved']===true,'the actual default meeting Auto policy accepts healthy authorized HTTPS scope');
readonly_hs_assert($GLOBALS['readonly_hs_bare_calls']===0,'default meeting policy retains the owner-aware route');
$session=['id'=>101,'created_by_user_id'=>1,'metadata_json'=>json_encode(['capture_mode'=>'vp3_video_meeting','video_meeting_id'=>1,'video_meeting_public_id'=>sprintf('%032x',1)])];
$pdo->guard=true;
$installerCalls=$pdo->legacyInstallerCalls;
$processing=video_meeting_transcription_assert_cloud_ai_v1801($pdo,['id'=>1],$session);
readonly_hs_assert($processing['cloud_ai_allowed']===true,'the actual default processing assertion authorizes that same live HTTPS meeting');
readonly_hs_assert($pdo->legacyInstallerCalls===$installerCalls,'the processing assertion performs no installer DDL even for a legacy caller');

$GLOBALS['readonly_hs_transport_outage']=true;$pdo->guard=false;
$before=readonly_hs_calls();
$defaultOutage=homeserver_scope_v026_fetch(1,true);
readonly_hs_assert($defaultOutage['supported']===true&&$defaultOutage['available']===false&&$defaultOutage['scope']===null,'a returned default HTTPS outage stays unavailable on the actual scope chain');
readonly_hs_assert(readonly_hs_calls()[1]===$before[1]+1&&readonly_hs_calls()[2]===$before[2]+1&&$GLOBALS['readonly_hs_bare_calls']===0,'an owner-aware HTTPS failure is attempted once and never falls back to bare relay');
$defaultMeeting=video_meeting_homeserver_policy_v1801($pdo,$meeting,true);
readonly_hs_assert($defaultMeeting['cloud_processing_allowed']===false,'the actual default meeting policy rejects a returned unavailable scope');
$pdo->guard=true;
readonly_hs_blocked(static fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,['id'=>1],$session),'the actual processing assertion preserves a retryable 503 for scope uncertainty');
readonly_hs_assert($GLOBALS['readonly_hs_bare_calls']===0,'processing uncertainty does not switch to a legacy relay');
unset($GLOBALS['readonly_hs_transport_outage']);

foreach([['app_scope'=>[]],['app_scope'=>['cloud_allowed'=>'false']],['app_scope'=>['cloud_allowed'=>1]]] as $invalid){
    $GLOBALS['readonly_hs_transport_result']=$invalid;$pdo->guard=false;
    $defaultInvalid=homeserver_scope_v026_fetch(1,true);
    readonly_hs_assert($defaultInvalid['available']===false&&$defaultInvalid['scope']===null,'default scope cannot coerce a missing or nonboolean Cloud policy into an allowance');
    $defaultMeeting=video_meeting_homeserver_policy_v1801($pdo,$meeting,true);
    readonly_hs_assert($defaultMeeting['cloud_processing_allowed']===false,'whole default meeting Auto policy rejects malformed reported Cloud permission');
    $pdo->guard=true;
    readonly_hs_blocked(static fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,['id'=>1],$session),'whole processing assertion fails closed on malformed reported Cloud permission');
}

$GLOBALS['readonly_hs_transport_result']=['app_scope'=>['cloud_allowed'=>true]];
foreach([10,17,18] as $owner){
    $GLOBALS['readonly_hs_current_user']=['id'=>$owner];$pdo->guard=false;
    $malformedMeeting=video_meeting_row_v1800($pdo,$owner);
    $policy=video_meeting_homeserver_policy_v1801($pdo,$malformedMeeting,true);
    readonly_hs_assert($policy['cloud_processing_allowed']===false,'whole default meeting policy rejects malformed stored feature metadata for fixture '.$owner);
    $pdo->guard=true;
    $malformedSession=['id'=>100+$owner,'created_by_user_id'=>$owner,'metadata_json'=>json_encode(['capture_mode'=>'vp3_video_meeting','video_meeting_id'=>$owner,'video_meeting_public_id'=>sprintf('%032x',$owner)])];
    readonly_hs_blocked(static fn()=>video_meeting_transcription_assert_cloud_ai_v1801($pdo,['id'=>$owner],$malformedSession),'whole processing assertion cannot treat malformed stored features as legacy absence for fixture '.$owner);
}

$GLOBALS['readonly_hs_current_user']=['id'=>2];$pdo->guard=false;
$legacyMeeting=video_meeting_row_v1800($pdo,2);
$before=readonly_hs_calls();
$legacyPolicy=video_meeting_homeserver_policy_v1801($pdo,$legacyMeeting,true);
readonly_hs_assert($legacyPolicy['cloud_processing_allowed']===true,'whole default meeting Auto policy preserves a valid explicit legacy feature list');
$legacySession=['id'=>102,'created_by_user_id'=>2,'metadata_json'=>json_encode(['capture_mode'=>'vp3_video_meeting','video_meeting_id'=>2,'video_meeting_public_id'=>sprintf('%032x',2)])];
$pdo->guard=true;
readonly_hs_assert(video_meeting_transcription_assert_cloud_ai_v1801($pdo,['id'=>2],$legacySession)['cloud_ai_allowed']===true,'actual processing assertion accepts authenticated known legacy scope absence');
readonly_hs_assert(readonly_hs_calls()[1]===$before[1]&&readonly_hs_calls()[2]===$before[2],'known legacy scope absence creates no speculative transport request');

$GLOBALS['readonly_hs_current_user']=['id'=>999];$pdo->guard=false;
$unpairedMeeting=video_meeting_row_v1800($pdo,999);
$unpairedPolicy=video_meeting_homeserver_policy_v1801($pdo,$unpairedMeeting,true);
readonly_hs_assert($unpairedPolicy['cloud_processing_allowed']===true,'whole default meeting Auto policy preserves a strictly proven absent connection');
$unpairedSession=['id'=>1099,'created_by_user_id'=>999,'metadata_json'=>json_encode(['capture_mode'=>'vp3_video_meeting','video_meeting_id'=>999,'video_meeting_public_id'=>sprintf('%032x',999)])];
$pdo->guard=true;
readonly_hs_assert(video_meeting_transcription_assert_cloud_ai_v1801($pdo,['id'=>999],$unpairedSession)['cloud_ai_allowed']===true,'actual processing assertion preserves Auto for a strictly unpaired owner');
readonly_hs_assert($GLOBALS['readonly_hs_bare_calls']===0,'all whole-policy HTTPS and legacy checks avoid bare relay fallback');
readonly_hs_assert($pdo->ddlAttempts===$wholeDdlBefore&&$pdo->domainWrites===$wholeDomainWritesBefore,'whole processing checks attempt no DDL or domain writes, including caught legacy installer failures');

echo 'AGENT_INITIATIVE_HOMESERVER_READONLY_V1=PASS ('.$cases." cases)\n";
