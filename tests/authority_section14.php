<?php
declare(strict_types=1);
final class Section14PDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if(str_contains($query,'information_schema.COLUMNS'))$query='SELECT COUNT(*) FROM pragma_table_info(?) WHERE name=?';
        if(str_contains($query,'information_schema.TABLES'))$query="SELECT COUNT(*) FROM sqlite_master WHERE name=?";
        return parent::prepare($query,$options);
    }
}
$pdo = new Section14PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): ?PDO { return $GLOBALS['pdo']; }
function current_user(): ?array { return null; }
function check14(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
    INSERT INTO users VALUES(1,'admin',1),(2,'artist',1);
    CREATE TABLE user_account_types(user_id INTEGER,role TEXT,assigned_explicitly_at TEXT);
    INSERT INTO user_account_types VALUES(1,'admin',NULL),(2,'artist',NULL);
    CREATE TABLE permissions(permission_key TEXT);
    CREATE TABLE role_permissions(role TEXT,permission_key TEXT);
    INSERT INTO role_permissions VALUES('fan','chat.access'),('artist','tracks.manage'),('artist','playlists.manage')");
require __DIR__.'/../includes/permissions.php';
require __DIR__.'/../includes/permissions-v105.php';
$copied=['id'=>1,'role'=>'admin','roles'=>['admin']];
check14(has_permission('users.manage',$copied),'Active admin lost authority');
$pdo->exec("UPDATE users SET role='fan' WHERE id=1");
check14(!has_permission('users.manage',$copied)&&!user_has_role('admin',$copied),'Copied admin role survived demotion');
check14(has_permission('chat.access',$copied),'Current Fan permission was lost');
$pdo->exec("INSERT INTO user_account_types VALUES(1,'artist',CURRENT_TIMESTAMP)");
check14(has_permission('tracks.manage',$copied),'Explicit secondary account type was lost');
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
check14(!has_permission('tracks.manage',$copied)&&!permission_v105_has('playlists.manage',$copied),'Disabled account retained authority');
$pdo->exec('DELETE FROM users WHERE id=1');
check14(user_roles_for_user($copied)===[],'Deleted principal retained authority');
$artist=['id'=>2,'role'=>'artist'];
check14(has_permission('tracks.manage',$artist)&&permission_v105_has('playlists.manage',$artist),'Live permission matrix ignored');
$pdo->exec('DELETE FROM role_permissions');
check14(!has_permission('tracks.manage',$artist)&&!permission_v105_has('playlists.manage',$artist),'Removed grants restored by defaults');
$pdo->exec('ALTER TABLE role_permissions RENAME TO broken_permissions; CREATE VIEW role_permissions AS SELECT * FROM missing_permission_storage');
check14(!role_has_permission('artist','tracks.manage')&&!permission_v105_has('playlists.manage',$artist),'Read failure restored default grants');
// Relay failures can only fall back when authority has not been refused.
function homeserver_execution_v220_can_route(...$args): bool { return true; }
function homeserver_https_v1300_remote_operation(...$args): array { throw new RuntimeException($GLOBALS['relayError']); }
require __DIR__.'/../includes/homeserver-local-execution-v230.php';
$GLOBALS['pdo']=null;$calls=0;
$fallback=static function()use(&$calls):array { $calls++;return ['count'=>1]; };
foreach(['403 Forbidden','401 Unauthorized','Permission denied after timeout','Bearer token revoked'] as $error){
    $GLOBALS['relayError']=$error;
    try{homeserver_execution_v230_execute(2,'contacts.list',[],$fallback);throw new LogicException('Authorization fallback accepted');}
    catch(RuntimeException $e){check14($e->getMessage()===$error,'Authorization error changed');}
}
check14($calls===0,'Fallback ran after access denial');
$GLOBALS['relayError']='HomeServer offline';
check14(homeserver_execution_v230_execute(2,'contacts.list',[],$fallback)['execution']['fallback_used']&&$calls===1,'Read-safe outage fallback regressed');
try{homeserver_execution_v230_execute(2,'tasks.create',[],$fallback);throw new LogicException('Write fallback accepted');}
catch(RuntimeException $e){check14($calls===1,'Write operation fell back');}
echo "SECTION14_CLOUD_AUTHORITY=PASS\n";
