<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require dirname(__DIR__).'/includes/user-agent-system-v236.php';
require dirname(__DIR__).'/includes/system-apps-v300.php';
require __DIR__.'/fixtures/private-share-agent-v1.php';

/** Real SQL with write tripwires and deterministic concurrent-authority changes. */
class PrivateShareReadPDO extends PDO
{
    public bool $readGuard=false,$failShareRead=false;
    public int $writeAttempts=0,$shareReads=0;
    public mixed $onShareRead=null;

    private function inspect(string $sql): void
    {
        if($this->readGuard&&!preg_match('/^\s*(SELECT|PRAGMA)\b/i',$sql)){
            $this->writeAttempts++;
            throw new RuntimeException('A stored share read attempted a write.');
        }
        if(!preg_match('/\b(?:FROM|JOIN)\s+vp3_user_app_(?:private_shares_v240|share_installs_v250|share_updates_v250)\b/i',$sql))return;
        $this->shareReads++;
        if($this->failShareRead)throw new PDOException('SECRET raw database failure /private/path');
        if($this->onShareRead){$callback=$this->onShareRead;$this->onShareRead=null;$callback();}
    }
    public function exec(string $sql): int|false {$this->inspect($sql);return parent::exec($sql);}
    public function prepare(string $sql,array $options=[]): PDOStatement|false {$this->inspect($sql);return parent::prepare($sql,$options);}
    public function query(string $sql,?int $fetchMode=null,mixed ...$args): PDOStatement|false
    {
        $this->inspect($sql);
        return $fetchMode===null?parent::query($sql):parent::query($sql,$fetchMode,...$args);
    }
}

$mysqlDsn=getenv('VP3_TEST_MYSQL_DSN')?:getenv('VP3_SECTION7_MYSQL_DSN');
$dsn='sqlite::memory:';$dbUser=null;$dbPassword=null;
if($mysqlDsn){
    $dbUser=getenv('VP3_TEST_MYSQL_USER')?:'root';
    $dbPassword=getenv('VP3_TEST_MYSQL_PASS')?:getenv('VP3_SECTION7_MYSQL_PASSWORD')?:'section7-fixture';
    $admin=new PDO($mysqlDsn,$dbUser,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $database='vp3_private_share_test_'.bin2hex(random_bytes(5));
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsn=preg_match('/dbname=[^;]+/',$mysqlDsn)?preg_replace('/dbname=[^;]+/','dbname='.$database,$mysqlDsn):rtrim($mysqlDsn,';').';dbname='.$database;
    register_shutdown_function(static function()use($admin,$database): void {$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');});
}
$pdo=new PrivateShareReadPDO($dsn,$dbUser,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
$checks=0;$private_share_remote_calls=[];$permissions=['account.access'=>true,'chat.access'=>true];
function db(): PDO{return $GLOBALS['pdo'];}
function has_permission($key,$user): bool{return $GLOBALS['permissions'][$key]??false;}
function personal_capability_has_v242(...$args): bool{return false;}
function table_exists($table): bool
{
    $sql=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT name FROM sqlite_master WHERE type='table' AND name=?":"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?";
    $q=db()->prepare($sql);$q->execute([$table]);return(bool)$q->fetchColumn();
}
function column_exists(...$args): bool{return false;}
function url($path): string{return $path;}
function agent_tool_log(...$args): void{}
function homeserver_vp3_remote_operation_for_user(...$args): array{return private_share_agent_remote_v1(...$args);}
function check($value,string $label): void
{
    if(!$value)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo "PASS $label\n";
}
function private_share_writable_v1(callable $work): mixed
{
    $pdo=db();$guard=$pdo->readGuard;$pdo->readGuard=false;
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->exec('PRAGMA query_only=OFF');
    try{return $work();}
    finally{
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'&&$guard)$pdo->exec('PRAGMA query_only=ON');
        $pdo->readGuard=$guard;
    }
}
function private_share_snapshot_v1(): array
{
    $out=[];
    foreach(['vp3_user_app_private_shares_v240','vp3_user_app_share_installs_v250','vp3_user_app_share_updates_v250'] as $table)$out[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
    return $out;
}
function readShares(array $extra=[]): array
{
    return vp3_capability_chat_v1(db(),$GLOBALS['user'],$GLOBALS['principal'],'Show my private app share records',['conversation_id'=>1],'off',[],
        fn()=>['ok'=>true,'answer'=>json_encode(['operation'=>'private_share_read','ids'=>['apps.shares.read']]+$extra)]);
}
function directShareRead(array $extra=[],?array $principal=null,array $context=['conversation_id'=>1]): array
{
    return vp3_private_share_read_v1(db(),$GLOBALS['user'],$principal??$GLOBALS['principal'],
        array_replace(['operation'=>'private_share_read','ids'=>['apps.shares.read'],'view'=>'all','query'=>''],$extra),$context,'private_share_agent_remote_v1');
}
function private_share_result_safe_v1(array $result): bool
{
    $json=json_encode($result,JSON_UNESCAPED_SLASHES);
    foreach(['SECRET','recipient@example.test','owner@example.test','/private/path',hash('sha256','SECRET_GRANT_CODE_1')] as $forbidden)if(str_contains($json,$forbidden))return false;
    return !isset($result['private_app_lifecycle'])&&empty($result['tool_receipt']['changes_applied']);
}

$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,display_name VARCHAR(160),email VARCHAR(190))');
$pdo->exec("INSERT INTO users VALUES(1,1,'Fixture Owner','owner@example.test'),(2,1,'Fixture Publisher','recipient@example.test'),(3,1,'SECRET Third Recipient','third@example.test')");
$pdo->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER)');
$pdo->exec('INSERT INTO user_agents VALUES(7,1,1),(8,1,1),(9,2,1),(10,1,0)');
$pdo->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER)');
$pdo->exec('INSERT INTO chat_conversations VALUES(1,1,7),(2,2,9)');
private_share_agent_fixture_v1($pdo);
$user=['id'=>1,'is_active'=>1];$principal=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];
$pdo->readGuard=true;
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->exec('PRAGMA query_only=ON');
$before=private_share_snapshot_v1();

$readCount=$pdo->shareReads;
$r=vp3_capability_chat_v1($pdo,$user,$principal,'Can you inspect private app shares?',['conversation_id'=>1],'off',[],fn()=>['ok'=>true,'answer'=>'{"operation":"inspect","ids":["apps.shares.read"]}']);
check($pdo->shareReads===$readCount&&$private_share_remote_calls===[]&&!str_contains($r['answer'],'Kitchen Notes'),'ability questions read no share records and invoke no HomeServer service');
$r=readShares();
check(($r['tool_receipt']['status']??'')==='read_complete'&&$r['tool_receipt']['count']===6&&str_contains($r['answer'],'Kitchen Notes')&&str_contains($r['answer'],'Sent Inventory'),'default all view reads exactly the current owner sent and received shares');
check(private_share_result_safe_v1($r)&&!str_contains($r['answer'],'Foreign Workspace'),'share results exclude foreign accounts, grant material, permission JSON, recipient email and raw lifecycle payloads');
check(($r['actions'][0]['url']??'')==='/apps.php'&&($r['actions'][0]['auto']??null)===false,'Open Apps is an explicit manual link');
check($before===private_share_snapshot_v1()&&$pdo->writeAttempts===0&&$private_share_remote_calls===[],'read-only SQL leaves all share/install/update records unchanged and performs zero schema, synchronization or network writes');
$r=readShares(['view'=>'received']);
check($r['tool_receipt']['count']===5&&!str_contains($r['answer'],'Sent Inventory'),'received view uses exact recipient ownership');
$r=readShares(['view'=>'sent']);
check($r['tool_receipt']['count']===1&&str_contains($r['answer'],'Sent Inventory')&&!str_contains($r['answer'],'Kitchen Notes')&&!str_contains($r['answer'],'Fixture Publisher'),'sent view uses exact sender ownership and omits recipient name');
$r=readShares(['query'=>'kItChEn NoTeS']);
check($r['tool_receipt']['count']===1&&str_contains($r['answer'],'Kitchen Notes'),'title filtering is case-insensitive and bounded to the selected owner');
$r=readShares(['query'=>'user.weekend']);
check($r['tool_receipt']['count']===1&&str_contains($r['answer'],'Weekend Plan'),'literal app-key text is searchable without an executable app selector');
foreach(['SECRET','unknown app',"' OR 1=1 --",'%','_'] as $query){
    $r=readShares(['query'=>$query]);
    check($r['tool_receipt']['status']==='no_matches'&&private_share_result_safe_v1($r),'literal query cannot widen ownership or wildcard scope: '.json_encode($query));
}
$r=readShares(['query'=>'Kitchen Notes']);
check(str_contains($r['answer'],'2020-01-02')&&preg_match('/histor|observ/i',$r['answer'])&&!preg_match('/(?:is|currently) installed/i',$r['answer']),'an exact installation observation is historical and includes its stored timestamp');
$r=readShares(['query'=>'Kitchen Replacement']);
check(!str_contains($r['answer'],'2020-01-02')&&$r['tool_receipt']['count']===1,'a different share with the same app key cannot inherit another grant installation observation');
foreach(['share_public_id'=>str_pad('9',32,'0',STR_PAD_LEFT),'sender_user_id'=>3,'recipient_user_id'=>3,'app_key'=>'user.other','package_sha256'=>str_repeat('f',64),'publisher_fingerprint'=>str_repeat('f',64)] as $field=>$value){
    $saved=$pdo->query('SELECT '.$field.' FROM vp3_user_app_share_installs_v250 WHERE id=1')->fetchColumn();
    private_share_writable_v1(fn()=>$pdo->prepare('UPDATE vp3_user_app_share_installs_v250 SET '.$field.'=? WHERE id=1')->execute([$value]));
    $r=readShares(['query'=>'Kitchen Notes']);
    check($r['tool_receipt']['status']==='read_complete'&&!str_contains($r['answer'],'2020-01-02'),'installation evidence requires exact '.$field.' provenance');
    private_share_writable_v1(fn()=>$pdo->prepare('UPDATE vp3_user_app_share_installs_v250 SET '.$field.'=? WHERE id=1')->execute([$saved]));
}
foreach([['app_key','USER.KITCHEN'],['app_key','user.kitchen '],['package_sha256',str_repeat('A',64)],['publisher_fingerprint',str_repeat('E',64)]] as [$field,$value]){
    $saved=$pdo->query('SELECT '.$field.' FROM vp3_user_app_share_installs_v250 WHERE id=1')->fetchColumn();
    private_share_writable_v1(fn()=>$pdo->prepare('UPDATE vp3_user_app_share_installs_v250 SET '.$field.'=? WHERE id=1')->execute([$value]));
    $r=readShares(['query'=>'Kitchen Notes']);
    check($r['tool_receipt']['status']==='read_complete'&&!str_contains($r['answer'],'2020-01-02'),'case-insensitive SQL collation cannot substitute byte-different installation '.$field);
    private_share_writable_v1(fn()=>$pdo->prepare('UPDATE vp3_user_app_share_installs_v250 SET '.$field.'=? WHERE id=1')->execute([$saved]));
}
private_share_writable_v1(function()use($pdo): void {
    $pdo->exec("UPDATE vp3_user_app_private_shares_v240 SET publisher_fingerprint='fixture-publisher' WHERE id=1");
    $pdo->exec("UPDATE vp3_user_app_share_installs_v250 SET publisher_fingerprint='fixture-publisher ' WHERE id=1");
});
$r=readShares(['query'=>'Kitchen Notes']);
check($r['tool_receipt']['status']==='read_complete'&&!str_contains($r['answer'],'2020-01-02'),'MySQL padding equivalence cannot substitute a different publisher fingerprint');
private_share_writable_v1(function()use($pdo): void {
    $publisher=str_repeat('e',64);
    $pdo->prepare('UPDATE vp3_user_app_private_shares_v240 SET publisher_fingerprint=? WHERE id=1')->execute([$publisher]);
    $pdo->prepare('UPDATE vp3_user_app_share_installs_v250 SET publisher_fingerprint=? WHERE id=1')->execute([$publisher]);
});
$r=readShares(['query'=>'Kitchen Replacement']);
check($r['tool_receipt']['status']==='read_complete'&&str_contains($r['answer'],'matching update offer recorded')&&str_contains($r['answer'],'additional permissions require review')&&private_share_result_safe_v1($r),'a matching update offer reports stored review requirements without exposing permission identifiers or claiming availability');
foreach(['prior_share_public_id'=>str_pad('9',32,'0',STR_PAD_LEFT),'update_share_public_id'=>str_pad('2',32,'0',STR_PAD_LEFT),'sender_user_id'=>3,'recipient_user_id'=>3,'app_key'=>'USER.KITCHEN','from_package_sha256'=>str_repeat('A',64),'to_package_sha256'=>str_repeat('f',64)] as $field=>$value){
    $saved=$pdo->query('SELECT '.$field.' FROM vp3_user_app_share_updates_v250 WHERE id=1')->fetchColumn();
    private_share_writable_v1(fn()=>$pdo->prepare('UPDATE vp3_user_app_share_updates_v250 SET '.$field.'=? WHERE id=1')->execute([$value]));
    $r=readShares(['query'=>'Kitchen Replacement']);
    check($r['tool_receipt']['status']==='read_complete'&&!str_contains($r['answer'],'matching update offer recorded'),'update evidence requires exact '.$field.' and prior-share provenance');
    private_share_writable_v1(fn()=>$pdo->prepare('UPDATE vp3_user_app_share_updates_v250 SET '.$field.'=? WHERE id=1')->execute([$saved]));
}
$savedDelta=$pdo->query('SELECT permission_delta_json FROM vp3_user_app_share_updates_v250 WHERE id=1')->fetchColumn();
foreach(['["SECRET_UPDATE_PERMISSION"]','{"added":["SECRET_UPDATE_PERMISSION"],"removed":[],"retained":[],"requires_review":false}','{invalid-json'] as $invalidDelta){
    private_share_writable_v1(fn()=>$pdo->prepare('UPDATE vp3_user_app_share_updates_v250 SET permission_delta_json=? WHERE id=1')->execute([$invalidDelta]));
    $r=readShares(['query'=>'Kitchen Replacement']);
    check($r['tool_receipt']['status']==='failed'&&private_share_result_safe_v1($r),'malformed or contradictory update review metadata fails without raw permission disclosure');
}
private_share_writable_v1(fn()=>$pdo->prepare('UPDATE vp3_user_app_share_updates_v250 SET permission_delta_json=? WHERE id=1')->execute([$savedDelta]));
$r=readShares(['query'=>'Expired Planner']);
check($r['tool_receipt']['status']==='read_complete'&&str_contains(mb_strtolower($r['answer']),'expired'),'an expired pending grant is labelled expired without mutating its stored state');
$r=readShares(['query'=>'Revoked Diagram']);
check($r['tool_receipt']['status']==='read_complete'&&str_contains(mb_strtolower($r['answer']),'revoked'),'a revoked grant remains explicit history rather than an installation or uninstall claim');

private_share_writable_v1(function()use($pdo): void {
    private_share_fixture_share_v1($pdo,20,['app_name'=>'100%_Complete! Plans','app_key'=>'user.literal']);
    for($id=30;$id<50;$id++)private_share_fixture_share_v1($pdo,$id,['app_name'=>'Newer Filler '.$id,'created_at'=>'2025-01-01 00:00:00']);
});
foreach(['%','_','!'] as $query){$r=readShares(['query'=>$query]);check($r['tool_receipt']['count']===1&&str_contains($r['answer'],'100%')&&str_contains($r['answer'],'Complete'),'escaped search preserves a literal '.$query.' in the app title');}
$r=readShares(['query'=>'Kitchen Notes']);
check($r['tool_receipt']['count']===1&&str_contains($r['answer'],'Kitchen Notes'),'SQL applies the search before the twelve-row result limit');
$r=readShares();
check($r['tool_receipt']['count']===12&&str_contains($r['answer'],'12'),'an unfiltered read has an explicit twelve-record bound');
private_share_writable_v1(fn()=>$pdo->exec('DELETE FROM vp3_user_app_private_shares_v240 WHERE id>=20'));

foreach(['account.access','chat.access'] as $permission){
    $permissions[$permission]=false;$count=$pdo->shareReads;
    try{$r=directShareRead();$denied=($r['tool_receipt']['status']??'')==='blocked';}catch(Throwable $e){$denied=$e->getCode()===403;}
    check($denied&&$pdo->shareReads===$count,$permission.' denial blocks record access before querying shares');
    $permissions[$permission]=true;
}
foreach([
    'foreign owner principal'=>['kind'=>'user_agent','owner_user_id'=>2,'agent_id'=>7],
    'foreign Agent'=>['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>9],
    'inactive Agent'=>['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>10],
    'conversation Agent mismatch'=>['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>8],
    'public Profile Agent'=>['kind'=>'profile_agent','owner_user_id'=>1],
] as $label=>$who){
    $count=$pdo->shareReads;$r=directShareRead([],$who);
    check(($r['tool_receipt']['status']??'')==='blocked'&&$pdo->shareReads===$count&&!str_contains($r['answer'],'Kitchen Notes'),$label.' cannot enter private share reads');
}
foreach([2,999] as $cid){$count=$pdo->shareReads;$r=directShareRead([],null,['conversation_id'=>$cid]);check($r['tool_receipt']['status']==='blocked'&&$pdo->shareReads===$count,'foreign or missing conversation '.$cid.' blocks private share access');}
private_share_writable_v1(fn()=>$pdo->exec('UPDATE users SET is_active=0 WHERE id=1'));
$count=$pdo->shareReads;$r=directShareRead();
check($r['tool_receipt']['status']==='blocked'&&$pdo->shareReads===$count,'stale active user input cannot bypass current inactive-account state');
private_share_writable_v1(fn()=>$pdo->exec('UPDATE users SET is_active=1 WHERE id=1'));

$races=[
    'account deactivated'=>'UPDATE users SET is_active=0 WHERE id=1',
    'Agent deactivated'=>'UPDATE user_agents SET is_active=0 WHERE id=7',
    'Agent transferred'=>'UPDATE user_agents SET owner_user_id=2 WHERE id=7',
    'conversation transferred'=>'UPDATE chat_conversations SET user_id=2 WHERE id=1',
    'conversation Agent changed'=>'UPDATE chat_conversations SET user_agent_id=8 WHERE id=1',
];
foreach($races as $label=>$sql){
    $pdo->onShareRead=fn()=>private_share_writable_v1(fn()=>$pdo->exec($sql));
    $r=directShareRead();
    check($r['tool_receipt']['status']==='blocked'&&!str_contains($r['answer'],'Kitchen Notes')&&empty($r['sources']),'mid-read '.$label.' discards collected evidence');
    private_share_writable_v1(function()use($pdo): void {$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');$pdo->exec('UPDATE user_agents SET is_active=1,owner_user_id=1 WHERE id=7');$pdo->exec('UPDATE chat_conversations SET user_id=1,user_agent_id=7 WHERE id=1');});
}
foreach(['account.access','chat.access'] as $permission){
    $pdo->onShareRead=function()use($permission): void {$GLOBALS['permissions'][$permission]=false;};
    $r=directShareRead();$permissions[$permission]=true;
    check($r['tool_receipt']['status']==='blocked'&&!str_contains($r['answer'],'Kitchen Notes'),'mid-read '.$permission.' removal discards collected records');
}

$pdo->failShareRead=true;$r=directShareRead();$pdo->failShareRead=false;
check($r['tool_receipt']['status']==='failed'&&$r['tool_receipt']['reason']==='execution_failed'&&private_share_result_safe_v1($r),'database failure remains failed rather than empty and exposes no raw SQL or paths');
foreach(['status'=>'unverified_status','public_id'=>'bad-id','package_sha256'=>'bad-package'] as $field=>$value){
    $saved=$pdo->query('SELECT '.$field.' FROM vp3_user_app_private_shares_v240 WHERE id=1')->fetchColumn();
    private_share_writable_v1(fn()=>$pdo->prepare('UPDATE vp3_user_app_private_shares_v240 SET '.$field.'=? WHERE id=1')->execute([$value]));
    $r=readShares(['query'=>'Kitchen Notes']);
    check($r['tool_receipt']['status']==='failed'&&private_share_result_safe_v1($r),'invalid stored '.$field.' cannot become verified share evidence');
    private_share_writable_v1(fn()=>$pdo->prepare('UPDATE vp3_user_app_private_shares_v240 SET '.$field.'=? WHERE id=1')->execute([$saved]));
}

$snapshot=vp3_capability_snapshot_v1($pdo,$user,$principal,['conversation_id'=>1]);
foreach([
    ['view'=>'status'],['view'=>'install'],['query'=>['not-text']],['query'=>str_repeat('x',161)],
    ['app_key'=>'user.kitchen'],['action'=>'revoke'],['confirmed'=>true],['user_id'=>2],
    ['ids'=>['apps.shares.read','apps.user.read']],['ids'=>['apps.user.read']],
] as $extra){
    $rejected=false;
    try{vp3_capability_plan_v1(json_encode(array_replace(['operation'=>'private_share_read','ids'=>['apps.shares.read']],$extra)),$snapshot);}catch(InvalidArgumentException $e){$rejected=true;}
    check($rejected,'strict share plan rejects unsupported action or selector '.json_encode($extra));
}
$r=vp3_user_app_share_agent_query_v250('Show private apps shared with me',$user,1,'private_share_agent_remote_v1',$pdo);
check(($r['tool_receipt']['status']??'')==='read_complete'&&private_share_result_safe_v1($r)&&$private_share_remote_calls===[],'legacy shared-app handler uses the same authorized stored read and receipt');
$count=$pdo->shareReads;
$r=vp3_user_app_share_agent_query_v250('Can you read private app shares?',$user,1,'private_share_agent_remote_v1',$pdo);
check($pdo->shareReads===$count,'legacy capability question does not read stored shares');
$count=$pdo->shareReads;
$r=vp3_user_app_share_agent_query_v250('Do you have permission to read private app shares?',$user,1,'private_share_agent_remote_v1',$pdo);
check($pdo->shareReads===$count,'legacy permission question cannot retrieve stored shares');
foreach(['What is a private app?','How do private app shares work?','Explain private app sharing','Build a private app share dashboard','If I build a private app, what should it do?'] as $query){
    $count=$pdo->shareReads;$r=vp3_user_app_share_agent_query_v250($query,$user,1,'private_share_agent_remote_v1',$pdo);
    check(empty($r['handled'])&&$pdo->shareReads===$count,'legacy discussion cannot become a record request: '.$query);
}
$r=vp3_user_app_share_agent_query_v250('Show private apps I sent and received',$user,1,'private_share_agent_remote_v1',$pdo);
check(($r['tool_receipt']['count']??0)===6&&str_contains($r['answer'],'Kitchen Notes')&&str_contains($r['answer'],'Sent Inventory'),'mixed sent-and-received legacy request preserves both directions');
foreach(['Revoke the private app share','Install the shared app','Update my shared app'] as $query){
    $count=$pdo->shareReads;$r=vp3_user_app_share_agent_query_v250($query,$user,1,'private_share_agent_remote_v1',$pdo);
    check(empty($r['handled'])&&$pdo->shareReads===$count,'legacy read handler leaves an explicit mutation to its governed service: '.$query);
}

foreach(['vp3_user_app_private_shares_v240','vp3_user_app_share_installs_v250','vp3_user_app_share_updates_v250'] as $table){
    private_share_writable_v1(fn()=>$pdo->exec('ALTER TABLE '.$table.' RENAME TO missing_share_fixture'));
    $r=directShareRead();
    check($r['tool_receipt']['status']==='blocked'&&$r['tool_receipt']['reason']==='upgrade_required'&&$pdo->writeAttempts===0,'missing '.$table.' reports upgrade_required without creating schema');
    private_share_writable_v1(fn()=>$pdo->exec('ALTER TABLE missing_share_fixture RENAME TO '.$table));
}
private_share_writable_v1(function()use($pdo): void {foreach(['vp3_user_app_share_updates_v250','vp3_user_app_share_installs_v250','vp3_user_app_private_shares_v240'] as $table)$pdo->exec('DELETE FROM '.$table);});
$r=directShareRead();
check($r['tool_receipt']['status']==='no_matches'&&$r['tool_receipt']['count']===0,'confirmed empty share storage is distinct from missing schema or database failure');
check($pdo->writeAttempts===0&&$private_share_remote_calls===[],'every tested read remains free of schema changes, sync writes and remote operations');
echo 'PRIVATE_SHARE_AGENT_V1=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'; real scoped storage, guarded SQL and authority races)' . "\n";
