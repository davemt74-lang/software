<?php
declare(strict_types=1);

/**
 * VP3 v3.40 composable product entitlements.
 *
 * A package is the account's base commercial plan. Add-on grants are composed
 * on top of that base without changing identity, platform roles, or workspace
 * membership. Security permissions are deliberately excluded from this layer.
 */

const VP3_ENTITLEMENT_COMPOSITION_VERSION = 'subscription-entitlements-v340';

function subscription_entitlement_key_is_product_v340(string $key): bool
{
    $key=trim($key);
    if($key==='')return false;
    if(str_starts_with($key,'permission.'))return false;
    if($key==='legacy.permissions')return false;
    return (bool)preg_match('/^[a-z0-9][a-z0-9._-]{0,119}$/',$key);
}

function subscription_entitlements_v340_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo&&table_exists('user_entitlement_grants');
}

function subscription_entitlements_v340_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_entitlement_grants (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      capability_key VARCHAR(120) NOT NULL,
      is_enabled TINYINT(1) NOT NULL DEFAULT 1,
      limit_value BIGINT UNSIGNED NULL,
      source_kind VARCHAR(40) NOT NULL DEFAULT 'admin',
      source_ref VARCHAR(190) NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'active',
      starts_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ends_at DATETIME NULL,
      metadata_json LONGTEXT NULL,
      created_by INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_user_entitlement_active (user_id,capability_key,status,starts_at,ends_at,id),
      INDEX idx_user_entitlement_source (source_kind,source_ref,status,id),
      CONSTRAINT fk_user_entitlement_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_entitlement_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Permission-shaped package rows were created by the retired v105
    // commercial-authority model. Remove them once the composable product model
    // is installed so they cannot be mistaken for security policy later.
    if(table_exists('package_entitlements')){
        $pdo->exec("DELETE FROM package_entitlements WHERE capability_key LIKE 'permission.%'");
    }
}

function subscription_active_entitlement_grants_v340(int $userId,string $key,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo||$userId<1||!subscription_entitlement_key_is_product_v340($key)||!subscription_entitlements_v340_schema_ready($pdo))return [];
    $stmt=$pdo->prepare("SELECT * FROM user_entitlement_grants
      WHERE user_id=? AND capability_key=? AND status='active' AND is_enabled=1
        AND starts_at<=NOW() AND (ends_at IS NULL OR ends_at>NOW())
      ORDER BY id ASC");
    $stmt->execute([$userId,$key]);
    return $stmt->fetchAll()?:[];
}

function subscription_effective_entitlement_v340(?array $user,string $key): array
{
    $state=[
        'key'=>$key,
        'enabled'=>false,
        'limit'=>0,
        'unlimited'=>false,
        'base_enabled'=>false,
        'base_limit'=>null,
        'grant_count'=>0,
        'grant_limit'=>0,
    ];
    if(!$user||!subscription_entitlement_key_is_product_v340($key))return $state;
    $userId=(int)($user['id']??0);if($userId<1)return $state;

    $sub=function_exists('subscription_current')?subscription_current($user):null;
    if($sub&&function_exists('subscription_entitlement_row')){
        $row=subscription_entitlement_row((int)$sub['package_id'],$key);
        if($row&&(int)($row['is_enabled']??0)===1){
            $state['base_enabled']=true;
            $state['enabled']=true;
            $state['base_limit']=$row['limit_value']===null?null:max(0,(int)$row['limit_value']);
            if($row['limit_value']===null)$state['unlimited']=true;
            else $state['limit']=max(0,(int)$row['limit_value']);
        }
    }

    $pdo=db();
    foreach(subscription_active_entitlement_grants_v340($userId,$key,$pdo) as $grant){
        $state['enabled']=true;
        $state['grant_count']++;
        if($grant['limit_value']!==null){
            $delta=max(0,(int)$grant['limit_value']);
            $state['grant_limit']+=$delta;
            if(!$state['unlimited'])$state['limit']+=$delta;
        }
    }
    return $state;
}

function subscription_grant_entitlement_v340(
    int $userId,
    string $key,
    ?int $limitValue=null,
    string $sourceKind='admin',
    ?string $sourceRef=null,
    ?string $endsAt=null,
    ?int $actorUserId=null,
    array $metadata=[]
): int {
    if($userId<1)throw new RuntimeException('A user is required.');
    if(!subscription_entitlement_key_is_product_v340($key))throw new RuntimeException('Only product capabilities and limits can be granted. Security permissions must use roles or workspace membership.');
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    subscription_entitlements_v340_ensure_schema($pdo);
    $sourceKind=substr(preg_replace('/[^a-z0-9_-]+/i','_',trim($sourceKind))??'admin',0,40);if($sourceKind==='')$sourceKind='admin';
    $sourceRef=$sourceRef===null||trim($sourceRef)===''?null:mb_substr(trim($sourceRef),0,190);
    $limitValue=$limitValue===null?null:max(0,$limitValue);
    $endsAt=$endsAt===null||trim($endsAt)===''?null:(new DateTimeImmutable($endsAt))->format('Y-m-d H:i:s');

    $stmt=$pdo->prepare("INSERT INTO user_entitlement_grants
      (user_id,capability_key,is_enabled,limit_value,source_kind,source_ref,status,starts_at,ends_at,metadata_json,created_by)
      VALUES (?,?,?,?,?,?,'active',NOW(),?,?,?)");
    $stmt->execute([
        $userId,$key,1,$limitValue,$sourceKind,$sourceRef,$endsAt,
        json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        $actorUserId&&$actorUserId>0?$actorUserId:null,
    ]);
    $id=(int)$pdo->lastInsertId();
    if(function_exists('subscription_audit'))subscription_audit($pdo,$actorUserId,$userId,'entitlement_granted',null,null,'',['grant_id'=>$id,'capability_key'=>$key,'limit_value'=>$limitValue,'source_kind'=>$sourceKind,'source_ref'=>$sourceRef,'ends_at'=>$endsAt]);
    return $id;
}

function subscription_revoke_entitlement_grant_v340(int $grantId,int $userId,?int $actorUserId=null,string $reason=''): bool
{
    $pdo=db();if(!$pdo||$grantId<1||$userId<1||!subscription_entitlements_v340_schema_ready($pdo))return false;
    $stmt=$pdo->prepare("UPDATE user_entitlement_grants SET status='revoked',updated_at=NOW() WHERE id=? AND user_id=? AND status='active'");
    $stmt->execute([$grantId,$userId]);
    $changed=$stmt->rowCount()>0;
    if($changed&&function_exists('subscription_audit'))subscription_audit($pdo,$actorUserId,$userId,'entitlement_revoked',null,null,$reason,['grant_id'=>$grantId]);
    return $changed;
}
