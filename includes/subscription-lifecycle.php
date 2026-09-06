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
    subscription_lifecycle_ensure_schema($pdo);
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT s.id,s.package_id,s.status,s.ends_at,s.current_period_start,s.current_period_end,s.period_sequence,p.is_trial
          FROM user_subscriptions s INNER JOIN subscription_packages p ON p.id=s.package_id
          WHERE s.user_id=? AND s.status IN ('active','complimentary') AND s.starts_at<=NOW() AND (s.ends_at IS NULL OR s.ends_at>NOW())
          ORDER BY s.id DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([$userId]);$row=$stmt->fetch();
        if(!$row||!empty($row['is_trial'])){if($ownsTransaction)$pdo->commit();return false;}
        $endRaw=trim((string)($row['current_period_end']??''));
        if($endRaw===''){if($ownsTransaction)$pdo->commit();return false;}
        $now=new DateTimeImmutable('now');$periodEnd=new DateTimeImmutable($endRaw);
        if($periodEnd>$now){if($ownsTransaction)$pdo->commit();return false;}
        $periodStart=$periodEnd;$sequence=(int)($row['period_sequence']??0);
        do{$periodEnd=$periodEnd->modify('+1 month');$sequence++;}while($periodEnd<=$now);
        $subscriptionEnd=trim((string)($row['ends_at']??''));
        if($subscriptionEnd!==''){
            $hardEnd=new DateTimeImmutable($subscriptionEnd);
            if($periodEnd>$hardEnd)$periodEnd=$hardEnd;
        }
        $update=$pdo->prepare('UPDATE user_subscriptions SET current_period_start=?,current_period_end=?,period_sequence=?,updated_at=NOW() WHERE id=?');
        $update->execute([$periodStart->format('Y-m-d H:i:s'),$periodEnd->format('Y-m-d H:i:s'),$sequence,(int)$row['id']]);
        subscription_lifecycle_snapshot_subscription((int)$row['id'],$pdo);
        if($ownsTransaction)$pdo->commit();return true;
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();error_log('VP3 subscription period refresh failed: '.$e->getMessage());return false;}
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
            $user=current_user();if($user)subscription_lifecycle_refresh_user_period((int)($user['id']??0),$pdo);
        }
        // Small bounded backfill keeps upgrades cheap while eventually covering history.
        subscription_lifecycle_backfill_snapshots($pdo,25);
    }catch(Throwable $e){error_log('VP3 subscription lifecycle boot failed: '.$e->getMessage());}
}
