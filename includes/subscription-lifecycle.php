<?php
declare(strict_types=1);

/**
 * Subscription lifecycle maintenance.
 * Keeps recurring token periods current and snapshots assigned package terms
 * for support/audit history without changing live entitlement behavior.
 */
function subscription_lifecycle_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo||!subscription_schema_ready($pdo))return;
    if(!column_exists('user_subscriptions','package_snapshot_json')){
        $pdo->exec("ALTER TABLE user_subscriptions ADD COLUMN package_snapshot_json LONGTEXT NULL AFTER metadata_json");
    }
    if(!column_exists('user_subscriptions','period_sequence')){
        $pdo->exec("ALTER TABLE user_subscriptions ADD COLUMN period_sequence INT UNSIGNED NOT NULL DEFAULT 0 AFTER current_period_end");
    }
}

function subscription_lifecycle_package_snapshot(PDO $pdo,int $packageId): array
{
    $stmt=$pdo->prepare('SELECT id,slug,name,description,monthly_price_cents,annual_price_cents,ai_tokens_monthly,trial_days,trial_tokens,is_trial,is_public,is_active FROM subscription_packages WHERE id=? LIMIT 1');
    $stmt->execute([$packageId]);$package=$stmt->fetch();if(!$package)return [];
    $ent=$pdo->prepare('SELECT capability_key,is_enabled,limit_value,metadata_json FROM package_entitlements WHERE package_id=? ORDER BY capability_key');
    $ent->execute([$packageId]);$package['entitlements']=$ent->fetchAll()?:[];
    $package['captured_at']=gmdate('c');
    return $package;
}

function subscription_lifecycle_snapshot_subscription(int $subscriptionId,?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo||$subscriptionId<1||!subscription_schema_ready($pdo))return;
    subscription_lifecycle_ensure_schema($pdo);
    $stmt=$pdo->prepare('SELECT package_id,package_snapshot_json FROM user_subscriptions WHERE id=? LIMIT 1');
    $stmt->execute([$subscriptionId]);$row=$stmt->fetch();if(!$row||trim((string)($row['package_snapshot_json']??''))!=='')return;
    $snapshot=subscription_lifecycle_package_snapshot($pdo,(int)$row['package_id']);if(!$snapshot)return;
    $update=$pdo->prepare('UPDATE user_subscriptions SET package_snapshot_json=? WHERE id=? AND (package_snapshot_json IS NULL OR package_snapshot_json=\'\')');
    $update->execute([json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$subscriptionId]);
}

function subscription_lifecycle_backfill_snapshots(?PDO $pdo=null,int $limit=500): int
{
    $pdo??=db();if(!$pdo||!subscription_schema_ready($pdo))return 0;
    subscription_lifecycle_ensure_schema($pdo);
    $limit=max(1,min(5000,$limit));
    $rows=$pdo->query("SELECT id FROM user_subscriptions WHERE package_snapshot_json IS NULL OR package_snapshot_json='' ORDER BY id ASC LIMIT {$limit}")->fetchAll()?:[];
    foreach($rows as $row)subscription_lifecycle_snapshot_subscription((int)$row['id'],$pdo);
    return count($rows);
}

function subscription_lifecycle_refresh_user_period(int $userId,?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo||$userId<1||!subscription_schema_ready($pdo))return false;
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        vp3_ai_service_usage_lock_owner_v1($pdo,$userId);
        $row=subscription_current_for_user_id($userId,$pdo,true);
        $now=gmdate('Y-m-d H:i:s');
        if(!$row||!subscription_lifecycle_local_period_due($row,$now)){
            if($ownsTransaction)$pdo->commit();
            return false;
        }
        $utc=new DateTimeZone('UTC');
        $periodEnd=new DateTimeImmutable($row['current_period_end'],$utc);
        $anchorDay=(int)(new DateTimeImmutable($row['starts_at'],$utc))->format('j');
        $sequence=(int)($row['period_sequence']??0);
        do{
            $periodStart=$periodEnd;
            $nextMonth=$periodEnd->modify('first day of next month');
            $periodEnd=$nextMonth->setDate((int)$nextMonth->format('Y'),(int)$nextMonth->format('n'),min($anchorDay,(int)$nextMonth->format('t')));
            $sequence++;
        }while($periodEnd->format('Y-m-d H:i:s')<=$now);
        $end=$periodEnd->format('Y-m-d H:i:s');
        if(!empty($row['ends_at']))$end=min($end,$row['ends_at']);
        $hasSequence=array_key_exists('period_sequence',$row);
        $update=$pdo->prepare('UPDATE user_subscriptions SET current_period_start=?,current_period_end=?'.($hasSequence?',period_sequence=?':'').',updated_at=NOW() WHERE id=?');
        $params=[$periodStart->format('Y-m-d H:i:s'),$end];
        if($hasSequence)$params[]=$sequence;
        $update->execute([...$params,(int)$row['id']]);
        if($ownsTransaction)$pdo->commit();return true;
    }catch(Throwable $e){
        if(!$ownsTransaction)throw $e;
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('VP3 subscription period refresh failed: '.$e->getMessage());
        return false;
    }
}

function subscription_lifecycle_local_period_due(array $subscription,string $now): bool
{
    return ($subscription['assignment_source']??'')!=='stripe'
        && in_array($subscription['status']??'',['active','complimentary'],true)
        && empty($subscription['is_trial'])
        && !empty($subscription['current_period_end'])
        && $subscription['current_period_end']<=$now
        && (empty($subscription['ends_at'])||$subscription['ends_at']>$now);
}

function subscription_lifecycle_after_assignment(int $subscriptionId,int $userId): void
{
    $pdo=db();if(!$pdo||!subscription_schema_ready($pdo))return;
    subscription_lifecycle_ensure_schema($pdo);
    subscription_lifecycle_snapshot_subscription($subscriptionId,$pdo);
    subscription_lifecycle_refresh_user_period($userId,$pdo);
}

function subscription_lifecycle_boot(): void
{
    $pdo=db();if(!$pdo||!subscription_schema_ready($pdo))return;
    try{
        subscription_lifecycle_ensure_schema($pdo);
        if(function_exists('current_user')){
            $user=current_user();
            if($user){
                $userId=(int)($user['id']??0);
                // Apply a period-end cancellation/free-plan transition before
                // recurring-period maintenance can advance the renewal window.
                if($userId>0&&subscription_self_service_schema_ready($pdo))subscription_self_service_apply_due_for_user($userId,$pdo);
                if($userId>0)subscription_lifecycle_refresh_user_period($userId,$pdo);
            }
        }
        // Small bounded backfill keeps upgrades cheap while eventually covering history.
        subscription_lifecycle_backfill_snapshots($pdo,25);
    }catch(Throwable $e){error_log('VP3 subscription lifecycle boot failed: '.$e->getMessage());}
}
