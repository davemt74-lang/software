<?php
declare(strict_types=1);

// Real production permission helpers, deliberately separate from the isolated
// catalog/browser fixtures' account and session adapters.
require_once dirname(__DIR__).'/includes/permissions.php';
require_once dirname(__DIR__).'/includes/music-listening-totals-v1.php';

final class MusicTotalsAuthorityPDO extends PDO
{
    public string $failRead='';
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        if($this->failRead!==''&&str_contains($query,$this->failRead))throw new PDOException('PRIVATE authority diagnostic');
        return parent::prepare($query,$options);
    }
}
$pdo=new MusicTotalsAuthorityPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['musicTotalsAuthorityDB']=$pdo;
function db():PDO{return $GLOBALS['musicTotalsAuthorityDB'];}
function current_user():?array{return $GLOBALS['musicTotalsAuthorityUser']??null;}
foreach([
    'users (id INTEGER PRIMARY KEY,role VARCHAR(32),is_active INTEGER,display_name VARCHAR(190))',
    'permissions (permission_key VARCHAR(120) PRIMARY KEY)',
    'role_permissions (role VARCHAR(32),permission_key VARCHAR(120),PRIMARY KEY(role,permission_key))',
    'user_account_types (user_id INTEGER,role VARCHAR(32),assigned_explicitly_at DATETIME NULL,PRIMARY KEY(user_id,role))',
] as $definition)$pdo->exec('CREATE TABLE '.$definition);
$pdo->exec("INSERT INTO users VALUES (91,'supervisor',1,'Reporting administrator'),(92,'fan',1,'Ordinary listener'),(93,'admin',1,'Administrator')");
$pdo->exec("INSERT INTO permissions VALUES ('listening.view')");
$pdo->exec("INSERT INTO role_permissions VALUES ('supervisor','listening.view')");
$GLOBALS['musicTotalsAuthorityUser']=['id'=>91,'role'=>'supervisor','is_active'=>1];
$checks=0;
$check=static function(bool $result,string $message)use(&$checks):void{if(!$result)throw new RuntimeException($message);$checks++;};
$can=static fn(array $user):bool=>music_listening_totals_v1_can_view_platform(db(),$user);
$cached=$GLOBALS['musicTotalsAuthorityUser'];
$check($can($cached),'Current listening.view grant must allow platform aggregates');
$check(!$can(['id'=>92,'role'=>'admin','roles'=>['admin']]),'Copied administrator fields must not bypass current identity');
$check(!$can(['role'=>'admin']),'Anonymous copied role cannot acquire platform authority');
$check(!$can(['id'=>999,'role'=>'admin']),'Missing account must be denied');
$pdo->exec("DELETE FROM role_permissions WHERE role='supervisor'");
$check(!$can($cached),'Removing a grant must invalidate an already cached principal');
$pdo->exec("INSERT INTO role_permissions VALUES ('supervisor','listening.view')");
$check($can($cached),'Restored current grant must be reread without a stale denial');
$pdo->exec("UPDATE users SET role='fan' WHERE id=91");
$check(!$can($cached),'Demotion must invalidate a cached supervisor role');
$pdo->exec("INSERT INTO user_account_types VALUES (91,'supervisor',NULL),(91,'admin',NULL)");
$check(!$can($cached),'Legacy unassigned secondary roles must not restore a removed grant');
$pdo->exec("UPDATE user_account_types SET assigned_explicitly_at='2026-11-26 12:00:00' WHERE role='supervisor'");
$check($can($cached),'Current explicitly assigned secondary role must retain its grant');
$pdo->exec("DELETE FROM user_account_types WHERE role='supervisor'");
$check(!$can($cached),'Removing explicit secondary assignment must revoke access');
$pdo->exec("UPDATE user_account_types SET assigned_explicitly_at='2026-11-26 12:00:00' WHERE role='admin'");
$check($can($cached),'Current explicit administrator assignment keeps the canonical bypass');
$pdo->exec('UPDATE users SET is_active=0 WHERE id=91');
$check(!$can($cached),'Disabled account cannot use a copied active session or secondary admin');
$pdo->exec('UPDATE users SET is_active=1 WHERE id=91');
$pdo->exec('DELETE FROM user_account_types');
$pdo->exec("UPDATE users SET role='supervisor' WHERE id=91");
$pdo->failRead='FROM role_permissions';
$check(!$can($cached),'Permission query failure must fail closed');
$pdo->failRead='';
$check($can(['id'=>93,'role'=>'fan']),'Fresh primary administrator is honored regardless of a copied role');
$pdo->exec('UPDATE users SET is_active=0 WHERE id=93');
$check(!$can(['id'=>93,'role'=>'admin']),'Disabled primary administrator is denied');
$other=new PDO('sqlite::memory:');
$check(!music_listening_totals_v1_can_view_platform($other,$cached),'A different reporting connection must not borrow global permission authority');
$before=$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();
$check($can($cached),'Restored supervisor remains authorized');
$check($before===$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(),'Authority reads must not modify accounts');
echo "MUSIC_LISTENING_TOTALS_AUTHORITY_V1=PASS {$checks} real production primary/secondary permissions, fresh grants, disabled accounts and connection-scope checks\n";
