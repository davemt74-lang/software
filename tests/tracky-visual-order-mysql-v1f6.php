<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/tracky-visual-status-order-v1f6.php';
function gateMysql(bool $yes,string $reason): void {if(!$yes)throw new RuntimeException($reason);}
$dsn=getenv('TRACKY_ORDER_TEST_DSN')?:'';
if($dsn==='')throw new RuntimeException('A dedicated ephemeral MySQL service is required.');
$pdo=new PDO($dsn,(string)getenv('TRACKY_ORDER_TEST_USER'),(string)getenv('TRACKY_ORDER_TEST_PASS'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$pdo->exec("INSERT INTO users (id) VALUES (991)");
tracky_v1f6_ensure_schema($pdo);
function report(PDO $pdo,?string $status,mixed $revision): array {
    $pdo->beginTransaction();
    try {$res=tracky_v1f6_apply($pdo,991,'hs-order-site',$status,$revision);
         $pdo->commit();return $res;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function stored(PDO $pdo): array {
    return $pdo->query("SELECT accepted_revision,accepted_state,accepted_at
        FROM tracky_cloud_visual_status_order WHERE user_id=991 AND site_id='hs-order-site'")->fetch();
}
$active='owner_attributed_unverified';
$first=report($pdo,$active,1);
gateMysql($first['accepted']&&stored($pdo)['accepted_state']===$active,'First state accepted');
$revoked=report($pdo,'revoked',2);
gateMysql($revoked['accepted']&&stored($pdo)['accepted_state']==='revoked','Revocation persisted');
gateMysql(!report($pdo,$active,1)['accepted'],'Late active must fail');
gateMysql((int)stored($pdo)['accepted_revision']===2&&stored($pdo)['accepted_state']==='revoked',
    'Stale replay must not alter revoked row');
gateMysql(!report($pdo,$active,2)['accepted'],'Same revision different state conflict');
gateMysql(report($pdo,'revoked',2)['accepted'],'Matching duplicate is idempotent');
gateMysql(report($pdo,$active,3)['accepted'],'New consent may re-enable');
gateMysql(!report($pdo,'revoked',2)['accepted'],'Late revoked must not undo newer explicit opt-in');
$heartbeat=report($pdo,null,null);
gateMysql(!$heartbeat['accepted']&&stored($pdo)['accepted_state']===$active,
    'Generic heartbeat cannot change visual state');
$pdo->exec("INSERT INTO users (id) VALUES (992)");
$pdo->beginTransaction();
$legacy=tracky_v1f6_apply($pdo,992,'pre-upgrade','revoked',null);
$pdo->commit();
gateMysql($legacy['accepted'],'Legacy revocation closes pre-upgrade state only');
$pdo->beginTransaction();
$legacyActive=tracky_v1f6_apply($pdo,992,'pre-upgrade',$active,null);
$pdo->commit();
gateMysql(!$legacyActive['accepted'],'Legacy active cannot reopen pre-upgrade revocation');
$pdo->exec("DELETE FROM users WHERE id IN (991,992)");
gateMysql((int)$pdo->query("SELECT COUNT(*) FROM tracky_cloud_visual_status_order")->fetchColumn()===0,
    'User removal must cascade semantic status ledger without orphan data');
echo "TRACKY_VISUAL_ORDER_MYSQL_V1F6: transaction row lock, stale/replay, CAS revocation, idempotency and user cascade PASS\n";
