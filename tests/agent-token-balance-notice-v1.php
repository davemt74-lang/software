<?php
declare(strict_types=1);

// Use the actual quota ledger, settings and bell writes. Stub only account entitlements and Voice.
require_once __DIR__.'/../includes/subscription-quota.php';
require_once __DIR__.'/../includes/agent-token-balance-notice-v1.php';

function db(): PDO { return $GLOBALS['tokenNoticeDb']; }
function table_exists(string $table): bool
{
    try{db()->query('SELECT 1 FROM '.$table.' WHERE 1=0');return true;}
    catch(PDOException){return false;}
}
function subscription_schema_ready(?PDO $pdo=null): bool { return table_exists('ai_usage_ledger'); }
function subscription_effective_entitlement_v340(?array $user,string $key): array
{
    return ['enabled'=>$key==='main_ai.access'&&empty($user['no_access'])||$key==='ai.unlimited'&&!empty($user['unlimited'])];
}
function chat_settings_agent_voice_enabled_v237(PDO $pdo,array $user): bool { return empty($user['muted']); }
function vp3_participant_scope_v1(): ?array { return $GLOBALS['tokenNoticeParticipant']??null; }
function url(string $path): string { return $path; }

function token_notice_check(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    echo "PASS $message\n";
}

require_once __DIR__.'/../includes/subscription-lifecycle.php';
require_once __DIR__.'/../includes/subscription-access.php';

$mysqlDsn=getenv('VP3_TEST_MYSQL_DSN');
$database=null;
if($mysqlDsn){
    $pdo=new PDO($mysqlDsn,getenv('VP3_TEST_MYSQL_USER')?:null,getenv('VP3_TEST_MYSQL_PASS')?:null);
    $database='vp3_token_notice_'.bin2hex(random_bytes(6));
    $pdo->exec('CREATE DATABASE '.$database);
    $pdo->exec('USE '.$database);
    register_shutdown_function(static fn()=>$pdo->exec('DROP DATABASE '.$database));
}else{
    $pdo=new class('sqlite::memory:') extends PDO {
        public function prepare(string $query,array $options=[]): PDOStatement|false
        {
            return parent::prepare(preg_replace('/ FOR UPDATE$/','',$query),$options);
        }
    };
    $pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
}
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$GLOBALS['tokenNoticeDb']=$pdo;
$auto=$mysqlDsn?'INT AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
$pdo->exec("CREATE TABLE users(id INT PRIMARY KEY,is_active INT);
    INSERT INTO users VALUES (1,1),(2,1),(3,1),(4,1),(5,0),(6,1);
    CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT);
    CREATE TABLE user_llm_preferences(user_id INT PRIMARY KEY,route VARCHAR(24),provider VARCHAR(32));
    INSERT INTO user_llm_preferences VALUES (3,'own_key','anthropic');
    CREATE TABLE subscription_packages(id INT PRIMARY KEY,slug VARCHAR(80),name VARCHAR(80),description TEXT,
        ai_tokens_monthly INT,trial_tokens INT,trial_days INT,is_trial INT,is_public INT);
    INSERT INTO subscription_packages VALUES(1,'test','Test','',10000,0,0,0,1),(2,'credits','Credits','',0,0,0,0,1);
    CREATE TABLE user_subscriptions(id INT PRIMARY KEY,user_id INT,package_id INT,status VARCHAR(24),starts_at DATETIME,ends_at DATETIME,
        current_period_start DATETIME,current_period_end DATETIME,assignment_source VARCHAR(24),ai_token_override INT);
    CREATE TABLE ai_usage_ledger(user_id INT,subscription_id INT,package_tokens_used INT,credit_tokens_used INT,created_at DATETIME);
    CREATE TABLE ai_token_credits(user_id INT,remaining_amount INT,expires_at DATETIME);
    CREATE TABLE ai_token_reservations(user_id INT,reserved_tokens INT,expires_at DATETIME);
    CREATE TABLE notifications(id $auto,user_id INT,type VARCHAR(50),title VARCHAR(190),body VARCHAR(500),
        target_url VARCHAR(500),source_type VARCHAR(80),source_id INT,is_read INT DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME)");
$start=gmdate('Y-m-d H:i:s',time()-86400);
$end=gmdate('Y-m-d H:i:s',time()+86400);
$sub=$pdo->prepare("INSERT INTO user_subscriptions VALUES (?,?,?,'active',?,NULL,?,?,'stripe',NULL)");
foreach(range(1,6) as $owner)$sub->execute([$owner,$owner,$owner===6?2:1,$start,$start,$end]);
$user=['id'=>1];
$other=['id'=>2];
$ownerNotices=static fn():int=>(int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id=1')->fetchColumn();
$record=$pdo->prepare('INSERT INTO ai_usage_ledger VALUES (?,?,?,?,CURRENT_TIMESTAMP)');
$pdo->prepare('INSERT INTO ai_token_reservations VALUES (1,10000,?)')->execute([$end]);
token_notice_check(vp3_token_balance_notice_sync_v1($pdo,$user)===null&&$ownerNotices()===0,
    'a temporary reservation does not trigger a balance warning');
$pdo->exec('DELETE FROM ai_token_reservations');
$record->execute([1,1,8000,0]);
$low=vp3_token_balance_notice_sync_v1($pdo,$user);
$lowId=$low['notification_id']??0;
token_notice_check($lowId>0&&$low['severity']==='low'&&$low['remaining']===2000
    &&$low['voice_pending']===true&&$ownerNotices()===1&&$low['target_url']==='/subscription.php',
    'exactly 20 percent creates one persisted bell warning and a pending spoken notice');
token_notice_check(vp3_token_balance_notice_sync_v1($pdo,$user)===$low&&$ownerNotices()===1
    &&vp3_token_balance_notice_current_v1($pdo,$other)===null&&vp3_token_balance_notice_claim_v1($pdo,$other,$lowId)===null,
    'repeated heartbeats deduplicate and another account cannot read or claim the warning');
token_notice_check(vp3_token_balance_notice_claim_v1($pdo,$user+['muted'=>true],$lowId)===null
    &&vp3_token_balance_notice_current_v1($pdo,$user)['voice_pending']===true,
    'Agent Voice off leaves the bell notice and does not consume playback');
$spoken=vp3_token_balance_notice_claim_v1($pdo,$user,$lowId);
token_notice_check($spoken['notification_id']===$lowId&&$spoken['voice_pending']===false
    &&vp3_token_balance_notice_claim_v1($pdo,$user,$lowId)===null,
    'a claimed announcement cannot replay in another tab or reload');
$record->execute([1,1,1500,0]);
$critical=vp3_token_balance_notice_sync_v1($pdo,$user);
token_notice_check($critical['severity']==='critical'&&$critical['remaining']===500&&$ownerNotices()===2
    &&vp3_token_balance_notice_claim_v1($pdo,$user,$lowId)===null,
    'exactly 5 percent creates one critical warning and supersedes the older offer');
$record->execute([1,1,500,0]);
$exhausted=vp3_token_balance_notice_sync_v1($pdo,$user);
token_notice_check($exhausted['severity']==='exhausted'&&$exhausted['remaining']===0&&$ownerNotices()===3
    &&(int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id=1 AND is_read=0')->fetchColumn()===1,
    'exhaustion creates one current warning in the notification bell');
$pdo->exec('INSERT INTO ai_token_credits VALUES (1,6000,NULL)');
token_notice_check(vp3_token_balance_notice_claim_v1($pdo,$user,$exhausted['notification_id'])===null
    &&vp3_token_balance_notice_current_v1($pdo,$user)===null,
    'a top-up supersedes a pending voice offer before the browser can claim it');
$pdo->exec('UPDATE ai_token_credits SET remaining_amount=3200 WHERE user_id=1');
$record->execute([1,1,0,2800]);
$nextLow=vp3_token_balance_notice_sync_v1($pdo,$user);
token_notice_check($nextLow['severity']==='low'&&$nextLow['remaining']===3200&&$ownerNotices()===4
    &&$nextLow['notification_id']!==$lowId,
    'consuming purchased credits can cross a re-armed threshold in the same subscription period');
$newStart=gmdate('Y-m-d H:i:s',time()-3600);
$newEnd=gmdate('Y-m-d H:i:s',time()+172800);
$pdo->prepare('UPDATE user_subscriptions SET current_period_start=?,current_period_end=? WHERE id=1')->execute([$newStart,$newEnd]);
$pdo->exec('DELETE FROM ai_usage_ledger WHERE user_id=1');
token_notice_check(vp3_token_balance_notice_sync_v1($pdo,$user)===null&&$ownerNotices()===4,
    'a renewal with replenished allowance clears the previous cycle');
$pdo->exec('DELETE FROM ai_token_credits WHERE user_id=1');
$record->execute([1,1,10000,0]);
token_notice_check(vp3_token_balance_notice_sync_v1($pdo,$user)['severity']==='exhausted'&&$ownerNotices()===5,
    'a fresh cycle that jumps straight to exhaustion creates only its most relevant warning');
$record->execute([3,3,10000,0]);$record->execute([4,4,10000,0]);$record->execute([5,5,10000,0]);
token_notice_check(vp3_token_balance_notice_sync_v1($pdo,['id'=>3])===null
    &&vp3_token_balance_notice_sync_v1($pdo,['id'=>4,'unlimited'=>true])===null
    &&vp3_token_balance_notice_sync_v1($pdo,['id'=>4,'no_access'=>true])===null
    &&vp3_token_balance_notice_sync_v1($pdo,['id'=>5])===null,
    'personal provider keys, unlimited allowances, unentitled plans and inactive accounts receive no false token notices');
$pdo->exec('INSERT INTO ai_token_credits VALUES (6,100,NULL)');
$record->execute([6,6,0,900]);
token_notice_check(vp3_token_balance_notice_sync_v1($pdo,['id'=>6])['severity']==='low',
    'a credits-only subscription can warn against the settled credit capacity in its current period');
$GLOBALS['tokenNoticeParticipant']=['owner_user_id'=>1];
token_notice_check(vp3_token_balance_notice_current_v1($pdo,$user)===null&&vp3_token_balance_notice_sync_v1($pdo,$user)===null
    &&vp3_token_balance_notice_claim_v1($pdo,$user,$nextLow['notification_id'])===null,
    'a participant session cannot see, record or speak owner billing notices');
echo 'AGENT_TOKEN_BALANCE_NOTICE=PASS ('.($mysqlDsn?'MariaDB':'SQLite').")\n";
