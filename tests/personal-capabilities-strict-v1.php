<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/permissions.php';
require_once dirname(__DIR__).'/includes/personal-capabilities-strict-v1.php';

$checks=0;
function strict_personal_check(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    $GLOBALS['checks']++;echo 'PASS '.$message.PHP_EOL;
}
function strict_personal_unavailable(callable $work,string $message): void
{
    try{$work();}catch(RuntimeException $error){
        strict_personal_check($error->getCode()===503&&$error->getMessage()==='Personal capability permissions are temporarily unavailable.',$message);
        return;
    }
    throw new RuntimeException($message);
}
function db(): ?PDO{return $GLOBALS['strict_personal_db'];}
function current_user(): ?array{return $GLOBALS['strict_personal_user'];}

/** Real SQLite reads; only operational failures are injected at the PDO edge. */
class StrictPersonalPDO extends PDO
{
    public ?string $failRead=null;
    public bool $silentFailure=false;
    public int $reads=0;
    public function __construct(){parent::__construct('sqlite::memory:');$this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);}
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $this->reads++;
        if($this->failRead!==null&&str_contains($query,$this->failRead)){
            if($this->silentFailure)return false;
            throw new PDOException('INTERNAL_PERMISSION_STORAGE_FAILURE');
        }
        return parent::prepare($query,$options);
    }
}
$pdo=new StrictPersonalPDO();$GLOBALS['strict_personal_db']=$pdo;
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
CREATE TABLE user_account_types(user_id INTEGER,role TEXT,assigned_explicitly_at TEXT);
CREATE TABLE permissions(permission_key TEXT PRIMARY KEY);
CREATE TABLE role_permissions(role TEXT,permission_key TEXT);
CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
INSERT INTO users VALUES(1,'fan',1),(2,'admin',1),(3,'fan',0),(4,'fan',1),(5,'unknown',1);
INSERT INTO user_account_types VALUES(1,'producer',NULL),(4,'producer','2026-11-06 00:00:00');
INSERT INTO permissions VALUES('agent_brain.access');
INSERT INTO settings VALUES('personal_capabilities_seed_v242','1');
INSERT INTO role_permissions VALUES('fan','agent_brain.access'),('producer','personal_knowledge.manage');");
$one=['id'=>1,'role'=>'admin','roles'=>['admin']];$GLOBALS['strict_personal_user']=$one;
strict_personal_check(personal_capability_has_strict_v1('agent_brain.access'),'a verified current seeded grant permits the owner');
strict_personal_check(personal_capability_has_strict_v1('agent_brain.access',null),'the current authenticated owner is used when omitted');
strict_personal_check(!personal_capability_has_strict_v1('personal_knowledge.manage',$one),'copied admin roles and legacy unassigned extra roles cannot restore a missing grant');
strict_personal_check(personal_capability_has_strict_v1('personal_knowledge.manage',['id'=>4]),'an explicit current secondary account role retains its canonical grant');
$pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='agent_brain.access'");
strict_personal_check(!personal_capability_has_strict_v1('agent_brain.access',$one),'an explicit seeded revocation is respected on the next read without caching');
$pdo->exec("INSERT INTO role_permissions VALUES('fan','agent_brain.access')");
strict_personal_check(personal_capability_has_strict_v1('agent_brain.access',$one),'a verified restored grant is observed without caching');
strict_personal_check(!personal_capability_has_strict_v1('agent_brain.access',['id'=>3,'role'=>'admin']),'a disabled account cannot retain copied admin authority');
strict_personal_check(!personal_capability_has_strict_v1('agent_brain.access',['id'=>99,'role'=>'admin']),'a missing account cannot retain copied authority');
strict_personal_check(!personal_capability_has_strict_v1('agent_brain.access',['id'=>5]),'an account with no valid current role has no grant');
$before=$pdo->reads;
strict_personal_check(!personal_capability_has_strict_v1('chat.access',$one)&&!personal_capability_has_strict_v1('agent_brain.access',['id'=>0])&&$pdo->reads===$before,'non-personal permissions and invalid identities cannot invent grants or trigger storage work');

foreach(['FROM users','FROM user_account_types','FROM permissions','FROM settings','FROM role_permissions'] as $read){
    $pdo->failRead=$read;
    strict_personal_unavailable(fn()=>personal_capability_has_strict_v1('agent_brain.access',$one),'a '.$read.' failure cannot fall back to default grants');
}
$pdo->failRead='FROM role_permissions';$pdo->silentFailure=true;
strict_personal_unavailable(fn()=>personal_capability_has_strict_v1('agent_brain.access',$one),'a silent PDO prepare failure also fails closed');
$pdo->silentFailure=false;$pdo->failRead=null;
foreach(['settings','role_permissions','user_account_types','permissions'] as $table){
    $pdo->exec('ALTER TABLE '.$table.' RENAME TO unavailable_'.$table);
    strict_personal_unavailable(fn()=>personal_capability_has_strict_v1('agent_brain.access',$one),'a genuinely missing '.$table.' store cannot grant defaults');
    $pdo->exec('ALTER TABLE unavailable_'.$table.' RENAME TO '.$table);
}
$pdo->exec("UPDATE settings SET setting_value='invalid' WHERE setting_key='personal_capabilities_seed_v242'");
strict_personal_unavailable(fn()=>personal_capability_has_strict_v1('agent_brain.access',$one),'an invalid stored seed marker cannot reactivate defaults');

$pdo->exec("DELETE FROM settings WHERE setting_key='personal_capabilities_seed_v242'; DELETE FROM role_permissions");
strict_personal_check(personal_capability_has_strict_v1('agent_brain.access',$one),'intended pre-seed defaults remain available after successful identity, schema, settings and grant reads');
foreach(['FROM settings','FROM role_permissions'] as $read){
    $pdo->failRead=$read;
    strict_personal_unavailable(fn()=>personal_capability_has_strict_v1('agent_brain.access',$one),'a verified pre-seed default is never substituted for a failed '.$read.' read');
}
$pdo->failRead=null;$pdo->exec("INSERT INTO settings VALUES('personal_capabilities_seed_v242','1')");
$pdo->beginTransaction();
strict_personal_check(!personal_capability_has_strict_v1('agent_brain.access',$one)&&$pdo->inTransaction(),'the strict reader preserves its caller transaction and respects the seeded denial');
$pdo->rollBack();
$pdo->failRead='FROM settings';
strict_personal_check(personal_capability_has_strict_v1('agent_brain.access',['id'=>2]),'a verified current primary administrator retains the explicit canonical bypass');
$pdo->failRead=null;$pdo->exec("UPDATE users SET role='fan' WHERE id=2");
strict_personal_check(!personal_capability_has_strict_v1('agent_brain.access',['id'=>2,'role'=>'admin']),'a demoted administrator cannot retain its earlier bypass');
$pdo->exec("INSERT INTO user_account_types VALUES(4,'admin','2026-11-06 00:00:00')");
strict_personal_check(personal_capability_has_strict_v1('agent_brain.access',['id'=>4]),'a verified explicit administrator assignment retains the canonical bypass');
$pdo->exec("UPDATE user_account_types SET assigned_explicitly_at=NULL WHERE user_id=4 AND role='admin'");
strict_personal_check(!personal_capability_has_strict_v1('agent_brain.access',['id'=>4,'roles'=>['admin']]),'revoking the explicit administrator assignment removes its bypass');
$GLOBALS['strict_personal_db']=null;
strict_personal_unavailable(fn()=>personal_capability_has_strict_v1('agent_brain.access',$one),'unavailable storage never restores personal defaults');
$GLOBALS['strict_personal_user']=null;
strict_personal_check(!personal_capability_has_strict_v1('agent_brain.access'),'an unauthenticated call has no personal grant');

// Some existing read-only fixtures provide the legacy helper alone. Loading the
// strict action reader must not redeclare it or accept its projected grant.
$code='function personal_capability_has_v242(string $permission,?array $user=null):bool{return true;}'
    .'require '.var_export(dirname(__DIR__).'/includes/personal-capabilities-strict-v1.php',true).';'
    .'try{personal_capability_has_strict_v1("agent_brain.access",["id"=>1]);exit(2);}'
    .'catch(RuntimeException $error){echo $error->getCode();exit($error->getCode()===503?0:3);}';
$process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($process))throw new RuntimeException('Could not start the isolated compatibility fixture.');
$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
strict_personal_check($status===0&&$stdout==='503'&&$stderr==='','a projected legacy helper loads without redeclaration but cannot authorize strict actions without canonical dependencies');

echo 'PERSONAL_CAPABILITIES_STRICT_V1=PASS ('.$checks.' cases)'.PHP_EOL;
