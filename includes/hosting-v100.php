<?php
declare(strict_types=1);

/**
 * VP3 Hosting v1.00 — Section 1 foundation.
 *
 * Cloud remains authoritative for commercial entitlement and public hosting
 * identity. HomeServer execution, subdomain provisioning, cPanel, DNS and
 * per-site SQLite are added by later sections behind this contract.
 */
const VP3_HOSTING_V100 = 'vp3-hosting-v100-20260928';

function vp3_hosting_schema_ready_v100(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo&&table_exists('hosting_usage_ledger');
}

function vp3_hosting_ensure_schema_v100(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!table_exists('hosting_usage_ledger')){
        if($pdo->inTransaction())throw new RuntimeException('Hosting schema must be installed before starting a hosting transaction.');
        $pdo->exec("CREATE TABLE IF NOT EXISTS hosting_usage_ledger (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          user_id INT UNSIGNED NOT NULL,
          site_ref VARCHAR(120) NULL,
          metric_key VARCHAR(80) NOT NULL,
          quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
          unit VARCHAR(30) NOT NULL DEFAULT 'count',
          source_kind VARCHAR(40) NOT NULL DEFAULT 'system',
          request_key VARCHAR(160) NULL,
          metadata_json LONGTEXT NULL,
          recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_hosting_usage_request (request_key),
          INDEX idx_hosting_usage_user_metric (user_id,metric_key,recorded_at,id),
          INDEX idx_hosting_usage_site (user_id,site_ref,recorded_at,id),
          CONSTRAINT fk_hosting_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    vp3_hosting_seed_package_entitlements_v100($pdo);
}

function vp3_hosting_seed_package_entitlements_v100(PDO $pdo): void
{
    if(!table_exists('subscription_packages')||!table_exists('package_entitlements'))return;
    $stmt=$pdo->query("SELECT id FROM subscription_packages WHERE slug IN ('basic-user','basic') OR LOWER(name)='basic user' ORDER BY id LIMIT 1");
    $basicId=(int)$stmt->fetchColumn();
    if($basicId<1)return;
    $seed=$pdo->prepare("INSERT IGNORE INTO package_entitlements (package_id,capability_key,is_enabled,limit_value) VALUES (?,?,?,?)");
    foreach([
        ['hosting.access',1,null],
        ['hosting.subdomains',1,1],
        ['hosting.homeserver_sites',1,1],
    ] as [$key,$enabled,$limit])$seed->execute([$basicId,$key,$enabled,$limit]);
}

function vp3_hosting_entitlements_v100(?array $user): array
{
    $user??=function_exists('current_user')?current_user():null;
    return [
        'access'=>subscription_has_entitlement($user,'hosting.access'),
        'subdomains'=>subscription_entitlement_limit($user,'hosting.subdomains',0),
        'homeserver_sites'=>subscription_entitlement_limit($user,'hosting.homeserver_sites',0),
        'storage_mb'=>subscription_entitlement_limit($user,'hosting.storage_mb',0),
        'sqlite_mb'=>subscription_entitlement_limit($user,'hosting.sqlite_mb',0),
        'bandwidth_mb_monthly'=>subscription_entitlement_limit($user,'hosting.bandwidth_mb_monthly',0),
    ];
}

function vp3_hosting_usage_record_v100(
    int $userId,
    string $metricKey,
    int $quantity,
    string $unit='count',
    ?string $siteRef=null,
    string $sourceKind='system',
    ?string $requestKey=null,
    array $metadata=[]
): int {
    if($userId<1)throw new RuntimeException('A user is required.');
    if(!preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/',$metricKey))throw new RuntimeException('Invalid hosting metric.');
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_hosting_ensure_schema_v100($pdo);
    $stmt=$pdo->prepare('INSERT INTO hosting_usage_ledger (user_id,site_ref,metric_key,quantity,unit,source_kind,request_key,metadata_json) VALUES (?,?,?,?,?,?,?,?)');
    try{
        $stmt->execute([
            $userId,
            $siteRef!==null?mb_strimwidth(trim($siteRef),0,120,''):null,
            $metricKey,
            max(0,$quantity),
            mb_strimwidth(trim($unit)?:'count',0,30,''),
            mb_strimwidth(trim($sourceKind)?:'system',0,40,''),
            $requestKey!==null?mb_strimwidth(trim($requestKey),0,160,''):null,
            $metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,
        ]);
        return (int)$pdo->lastInsertId();
    }catch(PDOException $e){
        if($requestKey!==null&&str_contains(strtolower($e->getMessage()),'duplicate')){
            $existing=$pdo->prepare('SELECT id FROM hosting_usage_ledger WHERE request_key=? LIMIT 1');$existing->execute([$requestKey]);
            return (int)$existing->fetchColumn();
        }
        throw $e;
    }
}

function vp3_hosting_agent_context_v100(array $user): array
{
    $pdo=db();$uid=(int)($user['id']??0);
    if(!$pdo||$uid<1||!vp3_hosting_schema_ready_v100($pdo))return [];
    $state=function_exists('vp3_plugin_effective_state_v360')?vp3_plugin_effective_state_v360($pdo,$user,'hosting'):['enabled'=>false];
    if(empty($state['enabled']))return [];

    $e=vp3_hosting_entitlements_v100($user);
    $fmt=static fn(?int $v): string=>$v===null?'unlimited':number_format(max(0,$v));
    $latest=$pdo->prepare("SELECT metric_key,quantity,unit,recorded_at FROM hosting_usage_ledger WHERE user_id=? ORDER BY recorded_at DESC,id DESC LIMIT 8");
    $latest->execute([$uid]);
    $usage=$latest->fetchAll()?:[];

    $lines=[
        'Hosting plugin: enabled',
        'Subdomain allowance: '.$fmt($e['subdomains']),
        'HomeServer hosted-site allowance: '.$fmt($e['homeserver_sites']),
        'Storage allowance: '.$fmt($e['storage_mb']).' MB',
        'SQLite allowance: '.$fmt($e['sqlite_mb']).' MB',
        'Monthly bandwidth allowance: '.$fmt($e['bandwidth_mb_monthly']).' MB',
        'Provisioning authority: VP3 Cloud; runtime authority may be delegated to a governed HomeServer in later hosting sections.',
    ];
    if($usage){
        $lines[]='Recent metering:';
        foreach($usage as $row)$lines[]=(string)$row['metric_key'].': '.number_format((int)$row['quantity']).' '.(string)$row['unit'].' · '.(string)$row['recorded_at'];
    }
    return [[
        'source'=>'hosting:account',
        'title'=>'VP3 Hosting account and package limits',
        'text'=>implode("\n",$lines),
    ]];
}
