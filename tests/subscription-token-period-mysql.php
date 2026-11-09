<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/subscriptions.php';

function db(): PDO { return $GLOBALS['tokenPeriodDb']; }
function table_exists(string $table): bool
{
    $stmt=db()->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}
function column_exists(string $table,string $column): bool
{
    $stmt=db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table,$column]);
    return (bool)$stmt->fetchColumn();
}
function period_check(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}
function period_reserve(int $userId,int $tokens): int
{
    return subscription_ai_preflight(['id'=>$userId],'initiative',0,$tokens)['reservation_id'];
}
function period_spend(int $userId,int $reservation,int $tokens): void
{
    subscription_ai_commit_usage($reservation,['id'=>$userId],'initiative','openai','fixture-model',['total_tokens'=>$tokens]);
}
function period_expect_exhausted(int $userId): void
{
    try{period_reserve($userId,64);}
    catch(RuntimeException $error){
        period_check(str_contains($error->getMessage(),'balance is exhausted'),'Unexpected rejection: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected the token balance to block provider admission.');
}

$dsn=getenv('VP3_TEST_MYSQL_DSN');
if(!$dsn)throw new RuntimeException('VP3_TEST_MYSQL_DSN is required for the subscription period test.');
$pdo=new PDO($dsn,getenv('VP3_TEST_MYSQL_USER')?:'root',getenv('VP3_TEST_MYSQL_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$GLOBALS['tokenPeriodDb']=$pdo;
$database='vp3_token_period_'.bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE '.$database);
try{
    $pdo->exec('USE '.$database);
    // Paid periods and new ledger timestamps are UTC even if the host settings differ.
    $pdo->exec("SET time_zone='+09:00'");
    date_default_timezone_set('America/Phoenix');
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $stamp=static fn(DateTimeImmutable $date):string=>$date->format('Y-m-d H:i:s');
    $month=$now->modify('first day of this month')->setTime(0,0);

    $pdo->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    subscription_ensure_schema($pdo);
    subscription_lifecycle_ensure_schema($pdo);
    $pdo->exec('INSERT INTO users(id) VALUES(1),(2),(3),(4)');
    $pdo->exec("INSERT INTO subscription_packages(slug,name,ai_tokens_monthly,monthly_price_cents) VALUES ('paid-fixture','Paid fixture',1000,1000)");
    $packageId=(int)$pdo->lastInsertId();
    subscription_seed_entitlement($pdo,$packageId,'main_ai.access',true,null);
    $trialId=subscription_package_id_by_slug($pdo,'free-trial');
    $insert=$pdo->prepare('INSERT INTO user_subscriptions(user_id,package_id,status,assignment_source,billing_required,starts_at,ends_at,current_period_start,current_period_end) VALUES(?,?,?,?,?,?,?,?,?)');
    $priorStart=$stamp($month->modify('-3 months'));
    $priorEnd=$stamp($month->modify('-2 months'));
    $insert->execute([1,$packageId,'complimentary','complimentary',0,$priorStart,null,$priorStart,$priorEnd]);
    $localId=(int)$pdo->lastInsertId();
    $paidStart=$stamp($now->modify('-20 days'));
    $paidEnd=$stamp($now->modify('+1 hour'));
    $insert->execute([2,$packageId,'active','stripe',1,$paidStart,null,$paidStart,$paidEnd]);
    $paidId=(int)$pdo->lastInsertId();
    $trialStart=$stamp($now->modify('-1 day'));
    $trialEnd=$stamp($now->modify('+1 day'));
    $insert->execute([3,$trialId,'trialing','self_service',0,$trialStart,$trialEnd,$trialStart,$trialEnd]);
    $insert->execute([4,$packageId,'active','stripe',1,$paidStart,null,$paidStart,null]);

    $pdo->prepare("INSERT INTO ai_usage_ledger(user_id,subscription_id,scope,total_tokens,package_tokens_used,created_at) VALUES(1,?,'initiative',1000,1000,?)")
        ->execute([$localId,$priorStart]);
    $localReservation=period_reserve(1,600);
    period_check($localReservation>0,'Detached worker must reserve within the replenished local window.');
    $local=subscription_current_for_user_id(1,$pdo);
    period_check($local['current_period_start']===$stamp($month)&&$local['current_period_end']===$stamp($month->modify('+1 month'))
        &&(int)$local['period_sequence']===3,'Local replenishment must skip missed windows to the single current month.');
    period_spend(1,$localReservation,600);
    $localBalance=subscription_ai_balance(['id'=>1]);
    period_check($localBalance['remaining']===400&&$localBalance['used']===600,'Only current local period usage should count.');
    period_check(!subscription_lifecycle_refresh_user_period(1,$pdo),'Repeat refresh must leave the current allowance intact.');

    period_spend(2,period_reserve(2,600),600);
    $paid=subscription_ai_balance(['id'=>2]);
    period_check($paid['remaining']===400&&$paid['period']['end']===$paidEnd,'Verified paid window remains spendable until its UTC end.');
    $expired=$stamp($now->modify('-1 hour'));
    $pdo->prepare('UPDATE ai_usage_ledger SET created_at=? WHERE user_id=2')->execute([$stamp($now->modify('-2 hours'))]);
    $pdo->prepare('UPDATE user_subscriptions SET current_period_end=? WHERE id=?')->execute([$expired,$paidId]);
    period_check(!subscription_lifecycle_refresh_user_period(2,$pdo),'Stripe periods must never be advanced locally.');
    period_expect_exhausted(2);
    period_expect_exhausted(4);
    $paid=subscription_ai_balance(['id'=>2]);
    period_check($paid['remaining']===0&&$paid['package_remaining']===0&&$paid['period']['end']===$expired,
        'Expired paid package must remain closed until a new paid period is stored.');

    $creditId=subscription_add_token_credit(2,90,'admin_topup','Fixture grant',null,1);
    period_spend(2,period_reserve(2,70),70);
    $creditRemaining=(int)$pdo->query('SELECT remaining_amount FROM ai_token_credits WHERE id='.$creditId)->fetchColumn();
    $creditCharge=$pdo->query('SELECT package_tokens_used,credit_tokens_used FROM ai_usage_ledger WHERE user_id=2 ORDER BY id DESC LIMIT 1')->fetch();
    period_check($creditRemaining===20&&(int)$creditCharge['package_tokens_used']===0&&(int)$creditCharge['credit_tokens_used']===70,
        'Unexpired admin credits remain available and must be debited after the paid period ends.');
    period_expect_exhausted(2);

    // Match the period fields written by the verified Stripe reconciler without making a payment call.
    $pdo->prepare('UPDATE user_subscriptions SET current_period_start=?,current_period_end=? WHERE id=?')
        ->execute([$stamp($now->modify('-1 hour')),$stamp($now->modify('+1 month')),$paidId]);
    period_spend(2,period_reserve(2,500),500);
    $paid=subscription_ai_balance(['id'=>2]);
    period_check($paid['used']===500&&$paid['package_remaining']===500&&$paid['credits_remaining']===20&&$paid['remaining']===520,
        'New paid period replenishes the package while preserving remaining credit.');

    $trial=subscription_ai_balance(['id'=>3]);
    period_check($trial['package_allowance']===50000&&$trial['period']['end']===$trialEnd
        &&!subscription_lifecycle_refresh_user_period(3,$pdo),'Trial allowance and end remain fixed.');
    echo "Subscription token period and credit accounting passed on MySQL.\n";
}finally{
    $pdo->exec('DROP DATABASE '.$database);
}
