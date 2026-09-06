<?php
declare(strict_types=1);

/**
 * VP3 subscription, entitlement and AI quota runtime.
 *
 * Commercial access is intentionally separate from identity/team relationships.
 * Admin remains an internal authority and is never represented as a public plan.
 */

const VP3_SUBSCRIPTION_SCHEMA_VERSION = 'subscription-packages-20260906';

function subscription_schema_ready(?PDO $pdo = null): bool
{
    $pdo ??= db();
    if (!$pdo) return false;
    foreach ([
        'subscription_packages',
        'package_entitlements',
        'user_subscriptions',
        'ai_token_credits',
        'ai_usage_ledger',
        'ai_token_reservations',
        'subscription_audit_log',
    ] as $table) {
        if (!table_exists($table)) return false;
    }
    return true;
}

function subscription_capability_catalog(): array
{
    return [
        'main_ai.access' => ['label'=>'Main AI / Agent Chat','type'=>'boolean','category'=>'AI'],
        'agent_brain.access' => ['label'=>'Agent Brain','type'=>'boolean','category'=>'AI'],
        'agent_memory.access' => ['label'=>'Agent Memory','type'=>'boolean','category'=>'AI'],
        'knowledge.access' => ['label'=>'Knowledge','type'=>'boolean','category'=>'AI'],
        'profile_agent.access' => ['label'=>'Profile Agent','type'=>'boolean','category'=>'AI'],
        'voice.access' => ['label'=>'Voice','type'=>'boolean','category'=>'AI'],
        'voice_clone.access' => ['label'=>'Voice Clone','type'=>'boolean','category'=>'AI'],
        'transcription.access' => ['label'=>'Transcription','type'=>'boolean','category'=>'AI'],
        'stem_editor.access' => ['label'=>'Stem Editor','type'=>'boolean','category'=>'Studio'],
        'video_editor.access' => ['label'=>'Video Editor','type'=>'boolean','category'=>'Studio'],
        'team_seats' => ['label'=>'Team Seats','type'=>'limit','category'=>'Collaboration'],
        'projects.limit' => ['label'=>'Project Limit','type'=>'limit','category'=>'Limits'],
        'storage_mb' => ['label'=>'Storage (MB)','type'=>'limit','category'=>'Limits'],
        'ai.unlimited' => ['label'=>'Unlimited AI Tokens','type'=>'boolean','category'=>'AI'],
        'legacy.permissions' => ['label'=>'Legacy Permission Compatibility','type'=>'boolean','category'=>'Internal'],
    ];
}

function subscription_permission_key(string $permission): string
{
    return 'permission.' . trim($permission);
}

function subscription_ensure_schema(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_packages (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      slug VARCHAR(80) NOT NULL,
      name VARCHAR(120) NOT NULL,
      description TEXT NULL,
      monthly_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
      annual_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
      ai_tokens_monthly BIGINT UNSIGNED NOT NULL DEFAULT 0,
      trial_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      trial_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
      is_trial TINYINT(1) NOT NULL DEFAULT 0,
      is_default TINYINT(1) NOT NULL DEFAULT 0,
      is_public TINYINT(1) NOT NULL DEFAULT 1,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 100,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_subscription_package_slug (slug),
      INDEX idx_subscription_packages_public (is_active,is_public,sort_order,id),
      INDEX idx_subscription_packages_default (is_default,is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS package_entitlements (
      package_id INT UNSIGNED NOT NULL,
      capability_key VARCHAR(120) NOT NULL,
      is_enabled TINYINT(1) NOT NULL DEFAULT 0,
      limit_value BIGINT NULL,
      metadata_json LONGTEXT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (package_id,capability_key),
      INDEX idx_package_entitlements_key (capability_key,is_enabled,package_id),
      CONSTRAINT fk_package_entitlements_package FOREIGN KEY (package_id) REFERENCES subscription_packages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_subscriptions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      package_id INT UNSIGNED NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'active',
      assignment_source VARCHAR(40) NOT NULL DEFAULT 'admin_assigned',
      billing_required TINYINT(1) NOT NULL DEFAULT 0,
      starts_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ends_at DATETIME NULL,
      current_period_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      current_period_end DATETIME NULL,
      ai_token_override BIGINT UNSIGNED NULL,
      assigned_by INT UNSIGNED NULL,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_user_subscriptions_current (user_id,status,starts_at,ends_at,id),
      INDEX idx_user_subscriptions_package (package_id,status,id),
      CONSTRAINT fk_user_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_subscriptions_package FOREIGN KEY (package_id) REFERENCES subscription_packages(id) ON DELETE RESTRICT,
      CONSTRAINT fk_user_subscriptions_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_token_credits (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      amount BIGINT UNSIGNED NOT NULL,
      remaining_amount BIGINT UNSIGNED NOT NULL,
      source VARCHAR(40) NOT NULL DEFAULT 'admin_topup',
      reason VARCHAR(500) NOT NULL DEFAULT '',
      expires_at DATETIME NULL,
      created_by INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_ai_token_credits_user (user_id,expires_at,remaining_amount,id),
      CONSTRAINT fk_ai_token_credits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_ai_token_credits_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_usage_ledger (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      subscription_id BIGINT UNSIGNED NULL,
      scope VARCHAR(60) NOT NULL DEFAULT 'chat',
      provider VARCHAR(40) NOT NULL DEFAULT '',
      model VARCHAR(120) NOT NULL DEFAULT '',
      input_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
      output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
      total_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
      credit_tokens_used BIGINT UNSIGNED NOT NULL DEFAULT 0,
      package_tokens_used BIGINT UNSIGNED NOT NULL DEFAULT 0,
      trace_id VARCHAR(120) NOT NULL DEFAULT '',
      request_key VARCHAR(160) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_ai_usage_request_key (request_key),
      INDEX idx_ai_usage_user_time (user_id,created_at,id),
      INDEX idx_ai_usage_subscription_time (subscription_id,created_at,id),
      INDEX idx_ai_usage_scope_time (user_id,scope,created_at,id),
      CONSTRAINT fk_ai_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_ai_usage_subscription FOREIGN KEY (subscription_id) REFERENCES user_subscriptions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_token_reservations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      subscription_id BIGINT UNSIGNED NULL,
      scope VARCHAR(60) NOT NULL DEFAULT 'chat',
      reserved_tokens BIGINT UNSIGNED NOT NULL,
      expires_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_ai_reservations_user (user_id,expires_at,id),
      CONSTRAINT fk_ai_reservations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_ai_reservations_subscription FOREIGN KEY (subscription_id) REFERENCES user_subscriptions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_audit_log (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      actor_user_id INT UNSIGNED NULL,
      target_user_id INT UNSIGNED NOT NULL,
      action VARCHAR(60) NOT NULL,
      old_package_id INT UNSIGNED NULL,
      new_package_id INT UNSIGNED NULL,
      reason VARCHAR(500) NOT NULL DEFAULT '',
      details_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_subscription_audit_target (target_user_id,created_at,id),
      INDEX idx_subscription_audit_actor (actor_user_id,created_at,id),
      CONSTRAINT fk_subscription_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_subscription_audit_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_subscription_audit_old_package FOREIGN KEY (old_package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL,
      CONSTRAINT fk_subscription_audit_new_package FOREIGN KEY (new_package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    subscription_seed_defaults($pdo);
    subscription_migrate_existing_users($pdo);
}

function subscription_seed_defaults(PDO $pdo): void
{
    $trial = $pdo->prepare("INSERT INTO subscription_packages
      (slug,name,description,monthly_price_cents,annual_price_cents,ai_tokens_monthly,trial_days,trial_tokens,is_trial,is_default,is_public,is_active,sort_order)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE name=VALUES(name),is_active=1");
    $trial->execute([
        'free-trial','Free Trial','Default trial assigned to new accounts.',0,0,0,14,50000,1,1,1,1,10,
    ]);

    $legacy = $pdo->prepare("INSERT INTO subscription_packages
      (slug,name,description,monthly_price_cents,annual_price_cents,ai_tokens_monthly,trial_days,trial_tokens,is_trial,is_default,is_public,is_active,sort_order)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE name=VALUES(name),is_active=1");
    $legacy->execute([
        'legacy-access','Legacy Access','Compatibility package for accounts that existed before package monetization.',0,0,0,0,0,0,0,0,1,999,
    ]);

    $trialId = subscription_package_id_by_slug($pdo, 'free-trial');
    $legacyId = subscription_package_id_by_slug($pdo, 'legacy-access');

    $trialEntitlements = [
        'main_ai.access'=>[1,null],
        'agent_brain.access'=>[1,null],
        'agent_memory.access'=>[1,null],
        'knowledge.access'=>[1,null],
        'profile_agent.access'=>[1,null],
        'voice.access'=>[1,null],
        'voice_clone.access'=>[0,null],
        'transcription.access'=>[1,null],
        'stem_editor.access'=>[0,null],
        'video_editor.access'=>[0,null],
        'team_seats'=>[1,0],
        'permission.account.access'=>[1,null],
        'permission.chat.access'=>[1,null],
        'permission.artist_listening.access'=>[1,null],
        'permission.knowledge.access'=>[1,null],
    ];
    foreach ($trialEntitlements as $key => [$enabled,$limit]) {
        subscription_seed_entitlement($pdo,$trialId,$key,(bool)$enabled,$limit);
    }

    $legacyEntitlements = [
        'main_ai.access'=>[1,null],
        'agent_brain.access'=>[1,null],
        'agent_memory.access'=>[1,null],
        'knowledge.access'=>[1,null],
        'profile_agent.access'=>[1,null],
        'voice.access'=>[1,null],
        'voice_clone.access'=>[1,null],
        'transcription.access'=>[1,null],
        'stem_editor.access'=>[1,null],
        'video_editor.access'=>[1,null],
        'team_seats'=>[1,2],
        'ai.unlimited'=>[1,null],
        'legacy.permissions'=>[1,null],
    ];
    foreach ($legacyEntitlements as $key => [$enabled,$limit]) {
        subscription_seed_entitlement($pdo,$legacyId,$key,(bool)$enabled,$limit);
    }
}

function subscription_seed_entitlement(PDO $pdo,int $packageId,string $key,bool $enabled,?int $limit): void
{
    if ($packageId < 1) return;
    $stmt=$pdo->prepare("INSERT IGNORE INTO package_entitlements (package_id,capability_key,is_enabled,limit_value) VALUES (?,?,?,?)");
    $stmt->execute([$packageId,$key,$enabled?1:0,$limit]);
}

function subscription_package_id_by_slug(PDO $pdo,string $slug): int
{
    $stmt=$pdo->prepare('SELECT id FROM subscription_packages WHERE slug=? LIMIT 1');
    $stmt->execute([$slug]);
    return (int)$stmt->fetchColumn();
}

function subscription_migrate_existing_users(PDO $pdo): void
{
    $legacyId=subscription_package_id_by_slug($pdo,'legacy-access');
    if($legacyId<1)return;
    $stmt=$pdo->query("SELECT u.id FROM users u WHERE NOT EXISTS (SELECT 1 FROM user_subscriptions s WHERE s.user_id=u.id)");
    $insert=$pdo->prepare("INSERT INTO user_subscriptions
      (user_id,package_id,status,assignment_source,billing_required,starts_at,current_period_start,current_period_end,metadata_json)
      VALUES (?,?,'active','migration',0,NOW(),NOW(),NULL,?)");
    foreach($stmt->fetchAll()?:[] as $row){
        $uid=(int)$row['id'];
        if($uid<1)continue;
        $insert->execute([$uid,$legacyId,json_encode(['schema'=>VP3_SUBSCRIPTION_SCHEMA_VERSION],JSON_UNESCAPED_SLASHES)]);
    }
}

function subscription_packages(bool $publicOnly=false): array
{
    $pdo=db();if(!$pdo||!subscription_schema_ready($pdo))return [];
    $where=$publicOnly?'WHERE is_active=1 AND is_public=1':'WHERE 1=1';
    return $pdo->query("SELECT * FROM subscription_packages {$where} ORDER BY sort_order ASC,name ASC,id ASC")->fetchAll()?:[];
}

function subscription_package(int $packageId): ?array
{
    $pdo=db();if(!$pdo||$packageId<1||!subscription_schema_ready($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM subscription_packages WHERE id=? LIMIT 1');$stmt->execute([$packageId]);$row=$stmt->fetch();
    if(!$row)return null;
    $ent=$pdo->prepare('SELECT capability_key,is_enabled,limit_value,metadata_json FROM package_entitlements WHERE package_id=? ORDER BY capability_key');$ent->execute([$packageId]);
    $row['entitlements']=$ent->fetchAll()?:[];
    return $row;
}

function subscription_default_trial_package(?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo||!subscription_schema_ready($pdo))return null;
    $row=$pdo->query("SELECT * FROM subscription_packages WHERE is_active=1 AND is_trial=1 AND is_default=1 ORDER BY sort_order,id LIMIT 1")->fetch();
    return $row?:null;
}

function subscription_current_for_user_id(int $userId,?PDO $pdo=null,bool $forUpdate=false): ?array
{
    $pdo??=db();if(!$pdo||$userId<1||!subscription_schema_ready($pdo))return null;
    $sql="SELECT s.*,p.slug package_slug,p.name package_name,p.description package_description,p.ai_tokens_monthly,p.trial_tokens,p.trial_days,p.is_trial,p.is_public
      FROM user_subscriptions s INNER JOIN subscription_packages p ON p.id=s.package_id
      WHERE s.user_id=? AND s.status IN ('trialing','active','complimentary')
        AND s.starts_at<=NOW() AND (s.ends_at IS NULL OR s.ends_at>NOW())
      ORDER BY s.id DESC LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$userId]);$row=$stmt->fetch();
    return $row?:null;
}

function subscription_current(?array $user=null): ?array
{
    $user??=function_exists('current_user')?current_user():null;
    return $user?subscription_current_for_user_id((int)($user['id']??0)):null;
}

function subscription_is_internal_admin(?array $user=null): bool
{
    $user??=function_exists('current_user')?current_user():null;
    if(!$user)return false;
    if(function_exists('user_has_role'))return user_has_role('admin',$user);
    return (string)($user['role']??'')==='admin';
}

function subscription_entitlement_row(int $packageId,string $key): ?array
{
    $pdo=db();if(!$pdo||$packageId<1||$key===''||!subscription_schema_ready($pdo))return null;
    $stmt=$pdo->prepare('SELECT is_enabled,limit_value,metadata_json FROM package_entitlements WHERE package_id=? AND capability_key=? LIMIT 1');
    $stmt->execute([$packageId,$key]);$row=$stmt->fetch();return $row?:null;
}

function subscription_has_entitlement(?array $user,string $key): bool
{
    if(subscription_is_internal_admin($user))return true;
    $sub=subscription_current($user);
    if(!$sub)return !subscription_schema_ready();
    $row=subscription_entitlement_row((int)$sub['package_id'],$key);
    return $row?(int)$row['is_enabled']===1:false;
}

function subscription_entitlement_limit(?array $user,string $key,?int $default=null): ?int
{
    if(subscription_is_internal_admin($user))return null;
    $sub=subscription_current($user);if(!$sub)return $default;
    $row=subscription_entitlement_row((int)$sub['package_id'],$key);
    if(!$row||(int)$row['is_enabled']!==1)return 0;
    return $row['limit_value']===null?$default:(int)$row['limit_value'];
}

function subscription_permissions_authoritative(?array $user=null): bool
{
    if(subscription_is_internal_admin($user))return false;
    $sub=subscription_current($user);if(!$sub)return false;
    return !subscription_has_entitlement($user,'legacy.permissions');
}

function subscription_package_grants_permission(?array $user,string $permission): bool
{
    return subscription_has_entitlement($user,subscription_permission_key($permission));
}

function subscription_assign_package(
    int $userId,
    int $packageId,
    string $source='admin_assigned',
    ?int $actorUserId=null,
    ?string $endsAt=null,
    ?int $aiTokenOverride=null,
    bool $billingRequired=false,
    string $reason=''
): int {
    $pdo=db();if(!$pdo||!subscription_schema_ready($pdo))throw new RuntimeException('Subscription storage is unavailable.');
    $package=subscription_package($packageId);if(!$package||!(int)$package['is_active'])throw new RuntimeException('Select an active package.');
    $userStmt=$pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1');$userStmt->execute([$userId]);if(!$userStmt->fetchColumn())throw new RuntimeException('User account not found.');
    $source=substr(preg_replace('/[^a-z0-9_-]+/i','_',trim($source))??'admin_assigned',0,40);
    if($source==='')$source='admin_assigned';
    $old=subscription_current_for_user_id($userId,$pdo);
    $status=(int)$package['is_trial']===1?'trialing':($source==='complimentary'?'complimentary':'active');
    $now=new DateTimeImmutable('now');
    if($endsAt!==null&&trim($endsAt)!==''){
        $end=new DateTimeImmutable($endsAt);
    }elseif((int)$package['is_trial']===1&&((int)$package['trial_days'])>0){
        $end=$now->modify('+'.(int)$package['trial_days'].' days');
    }else{$end=null;}
    $periodEnd=$status==='trialing'?$end:$now->modify('+1 month');

    $pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE user_subscriptions SET status='replaced',ends_at=COALESCE(ends_at,NOW()),updated_at=NOW() WHERE user_id=? AND status IN ('trialing','active','complimentary')")->execute([$userId]);
        $stmt=$pdo->prepare("INSERT INTO user_subscriptions
          (user_id,package_id,status,assignment_source,billing_required,starts_at,ends_at,current_period_start,current_period_end,ai_token_override,assigned_by,metadata_json)
          VALUES (?,?,?,?,?,NOW(),?,NOW(),?,?,?,?)");
        $stmt->execute([
          $userId,$packageId,$status,$source,$billingRequired?1:0,
          $end?$end->format('Y-m-d H:i:s'):null,
          $periodEnd?$periodEnd->format('Y-m-d H:i:s'):null,
          $aiTokenOverride,$actorUserId,
          json_encode(['assigned_reason'=>$reason],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        ]);
        $subscriptionId=(int)$pdo->lastInsertId();
        subscription_audit($pdo,$actorUserId,$userId,'package_assigned',(int)($old['package_id']??0)?:null,$packageId,$reason,[
          'source'=>$source,'status'=>$status,'ends_at'=>$end?$end->format('c'):null,'ai_token_override'=>$aiTokenOverride,'billing_required'=>$billingRequired,
        ]);
        $pdo->commit();
        return $subscriptionId;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function subscription_assign_default_trial(int $userId): int
{
    $pdo=db();if(!$pdo||!subscription_schema_ready($pdo))return 0;
    $package=subscription_default_trial_package($pdo);if(!$package)return 0;
    return subscription_assign_package($userId,(int)$package['id'],'self_service',null,null,null,false,'Automatic signup trial');
}

function subscription_remove_package(int $userId,?int $actorUserId=null,string $reason=''): void
{
    $pdo=db();if(!$pdo||!subscription_schema_ready($pdo))throw new RuntimeException('Subscription storage is unavailable.');
    $old=subscription_current_for_user_id($userId,$pdo);
    $pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE user_subscriptions SET status='cancelled',ends_at=COALESCE(ends_at,NOW()),updated_at=NOW() WHERE user_id=? AND status IN ('trialing','active','complimentary')")->execute([$userId]);
        subscription_audit($pdo,$actorUserId,$userId,'package_removed',(int)($old['package_id']??0)?:null,null,$reason,[]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function subscription_audit(PDO $pdo,?int $actor,int $target,string $action,?int $oldPackage,?int $newPackage,string $reason,array $details): void
{
    $stmt=$pdo->prepare('INSERT INTO subscription_audit_log (actor_user_id,target_user_id,action,old_package_id,new_package_id,reason,details_json) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$actor&&$actor>0?$actor:null,$target,$action,$oldPackage,$newPackage,mb_strimwidth(trim($reason),0,500,''),json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
}

function subscription_add_token_credit(int $userId,int $amount,string $source='admin_topup',string $reason='',?string $expiresAt=null,?int $actorUserId=null): int
{
    if($amount<1)throw new RuntimeException('Token top-up must be greater than zero.');
    $pdo=db();if(!$pdo||!subscription_schema_ready($pdo))throw new RuntimeException('Token credit storage is unavailable.');
    $source=substr(preg_replace('/[^a-z0-9_-]+/i','_',trim($source))??'admin_topup',0,40);if($source==='')$source='admin_topup';
    $stmt=$pdo->prepare('INSERT INTO ai_token_credits (user_id,amount,remaining_amount,source,reason,expires_at,created_by) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$userId,$amount,$amount,$source,mb_strimwidth(trim($reason),0,500,''),$expiresAt?:null,$actorUserId&&$actorUserId>0?$actorUserId:null]);
    $id=(int)$pdo->lastInsertId();
    subscription_audit($pdo,$actorUserId,$userId,'tokens_added',null,null,$reason,['credit_id'=>$id,'amount'=>$amount,'source'=>$source,'expires_at'=>$expiresAt]);
    return $id;
}

function subscription_remove_token_credit(int $creditId,int $userId,?int $actorUserId=null,string $reason=''): void
{
    $pdo=db();if(!$pdo||!subscription_schema_ready($pdo))throw new RuntimeException('Token credit storage is unavailable.');
    $stmt=$pdo->prepare('SELECT amount,remaining_amount FROM ai_token_credits WHERE id=? AND user_id=? LIMIT 1');$stmt->execute([$creditId,$userId]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Token credit not found.');
    $pdo->prepare('UPDATE ai_token_credits SET remaining_amount=0,updated_at=NOW() WHERE id=? AND user_id=?')->execute([$creditId,$userId]);
    subscription_audit($pdo,$actorUserId,$userId,'tokens_removed',null,null,$reason,['credit_id'=>$creditId,'previous_remaining'=>(int)$row['remaining_amount']]);
}

function subscription_period(?array $subscription): array
{
    if(!$subscription)return ['start'=>null,'end'=>null];
    return ['start'=>$subscription['current_period_start']??$subscription['starts_at']??null,'end'=>$subscription['current_period_end']??$subscription['ends_at']??null];
}

function subscription_package_allowance(array $subscription): int
{
    if($subscription['ai_token_override']!==null)return max(0,(int)$subscription['ai_token_override']);
    if((int)($subscription['is_trial']??0)===1)return max(0,(int)($subscription['trial_tokens']??0));
    return max(0,(int)($subscription['ai_tokens_monthly']??0));
}

function subscription_ai_balance(?array $user=null,?PDO $pdo=null,bool $forUpdate=false): array
{
    $user??=function_exists('current_user')?current_user():null;$uid=(int)($user['id']??0);
    if($uid<1)return ['available'=>0,'remaining'=>0,'used'=>0,'package_allowance'=>0,'package_remaining'=>0,'credits_remaining'=>0,'reserved'=>0,'unlimited'=>false,'subscription'=>null];
    if(subscription_is_internal_admin($user))return ['available'=>PHP_INT_MAX,'remaining'=>PHP_INT_MAX,'used'=>0,'package_allowance'=>0,'package_remaining'=>PHP_INT_MAX,'credits_remaining'=>0,'reserved'=>0,'unlimited'=>true,'subscription'=>null];
    $pdo??=db();if(!$pdo||!subscription_schema_ready($pdo))return ['available'=>PHP_INT_MAX,'remaining'=>PHP_INT_MAX,'used'=>0,'package_allowance'=>0,'package_remaining'=>PHP_INT_MAX,'credits_remaining'=>0,'reserved'=>0,'unlimited'=>true,'subscription'=>null,'compatibility'=>true];
    $sub=subscription_current_for_user_id($uid,$pdo,$forUpdate);if(!$sub)return ['available'=>0,'remaining'=>0,'used'=>0,'package_allowance'=>0,'package_remaining'=>0,'credits_remaining'=>0,'reserved'=>0,'unlimited'=>false,'subscription'=>null];
    if(subscription_has_entitlement($user,'ai.unlimited'))return ['available'=>PHP_INT_MAX,'remaining'=>PHP_INT_MAX,'used'=>0,'package_allowance'=>0,'package_remaining'=>PHP_INT_MAX,'credits_remaining'=>0,'reserved'=>0,'unlimited'=>true,'subscription'=>$sub];
    $allowance=subscription_package_allowance($sub);$period=subscription_period($sub);
    $sql='SELECT COALESCE(SUM(package_tokens_used),0) FROM ai_usage_ledger WHERE user_id=? AND subscription_id=?';$params=[$uid,(int)$sub['id']];
    if(!empty($period['start'])){$sql.=' AND created_at>=?';$params[]=$period['start'];}
    if(!empty($period['end'])){$sql.=' AND created_at<?';$params[]=$period['end'];}
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$used=(int)$stmt->fetchColumn();
    $creditsStmt=$pdo->prepare('SELECT COALESCE(SUM(remaining_amount),0) FROM ai_token_credits WHERE user_id=? AND remaining_amount>0 AND (expires_at IS NULL OR expires_at>NOW())');$creditsStmt->execute([$uid]);$credits=(int)$creditsStmt->fetchColumn();
    $pdo->prepare('DELETE FROM ai_token_reservations WHERE expires_at<=NOW()')->execute();
    $resStmt=$pdo->prepare('SELECT COALESCE(SUM(reserved_tokens),0) FROM ai_token_reservations WHERE user_id=? AND expires_at>NOW()');$resStmt->execute([$uid]);$reserved=(int)$resStmt->fetchColumn();
    $packageRemaining=max(0,$allowance-$used);$available=max(0,$packageRemaining+$credits);$remaining=max(0,$available-$reserved);
    return ['available'=>$available,'remaining'=>$remaining,'used'=>$used,'package_allowance'=>$allowance,'package_remaining'=>$packageRemaining,'credits_remaining'=>$credits,'reserved'=>$reserved,'unlimited'=>false,'subscription'=>$sub,'period'=>$period];
}

function subscription_estimate_tokens_from_chars(int $chars): int
{
    return max(1,(int)ceil(max(0,$chars)/3.5));
}

function subscription_ai_preflight(?array $user,string $scope,int $estimatedInputTokens,int $requestedOutputTokens): array
{
    $user??=function_exists('current_user')?current_user():null;$uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in to use AI features.');
    if(!subscription_has_entitlement($user,'main_ai.access'))throw new RuntimeException('AI access is not included in your current package.');
    $pdo=db();$balance=subscription_ai_balance($user,$pdo,true);
    if(!empty($balance['unlimited']))return ['reservation_id'=>0,'max_output_tokens'=>max(64,$requestedOutputTokens),'remaining_before'=>PHP_INT_MAX,'unlimited'=>true];
    $remaining=(int)$balance['remaining'];$estimatedInputTokens=max(0,$estimatedInputTokens);$requestedOutputTokens=max(64,$requestedOutputTokens);
    $minimumNeeded=$estimatedInputTokens+64;
    if($remaining<$minimumNeeded)throw new RuntimeException('Your AI token balance is exhausted. Add tokens or upgrade your package to continue.');
    $maxOutput=max(64,min($requestedOutputTokens,$remaining-$estimatedInputTokens));
    $reserve=min($remaining,$estimatedInputTokens+$maxOutput);
    $sub=$balance['subscription'];
    $stmt=$pdo->prepare("INSERT INTO ai_token_reservations (user_id,subscription_id,scope,reserved_tokens,expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 3 MINUTE))");
    $stmt->execute([$uid,(int)($sub['id']??0)?:null,mb_strimwidth($scope,0,60,''),$reserve]);
    return ['reservation_id'=>(int)$pdo->lastInsertId(),'max_output_tokens'=>$maxOutput,'remaining_before'=>$remaining,'unlimited'=>false];
}

function subscription_ai_release_reservation(int $reservationId): void
{
    if($reservationId<1)return;$pdo=db();if(!$pdo||!subscription_schema_ready($pdo))return;
    $pdo->prepare('DELETE FROM ai_token_reservations WHERE id=?')->execute([$reservationId]);
}

function subscription_ai_commit_usage(int $reservationId,?array $user,string $scope,string $provider,string $model,array $usage,string $requestKey=''): void
{
    $user??=function_exists('current_user')?current_user():null;$uid=(int)($user['id']??0);if($uid<1)return;
    $total=max(0,(int)($usage['total_tokens']??((int)($usage['input_tokens']??0)+(int)($usage['output_tokens']??0))));
    if($total<1){subscription_ai_release_reservation($reservationId);return;}
    $pdo=db();if(!$pdo||!subscription_schema_ready($pdo)||subscription_is_internal_admin($user)){subscription_ai_release_reservation($reservationId);return;}
    $pdo->beginTransaction();
    try{
        $sub=subscription_current_for_user_id($uid,$pdo,true);$creditUsed=0;$remaining=$total;
        $credits=$pdo->prepare("SELECT id,remaining_amount FROM ai_token_credits WHERE user_id=? AND remaining_amount>0 AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END ASC,expires_at ASC,id ASC FOR UPDATE");
        $credits->execute([$uid]);
        foreach($credits->fetchAll()?:[] as $credit){
            if($remaining<=0)break;$available=(int)$credit['remaining_amount'];if($available<1)continue;$take=min($available,$remaining);
            $pdo->prepare('UPDATE ai_token_credits SET remaining_amount=remaining_amount-?,updated_at=NOW() WHERE id=?')->execute([$take,(int)$credit['id']]);
            $creditUsed+=$take;$remaining-=$take;
        }
        $packageUsed=$remaining;
        $trace=function_exists('agent_runtime_v125_trace_id')?(string)agent_runtime_v125_trace_id():'';
        $requestKey=trim($requestKey);if($requestKey==='')$requestKey=null;
        $stmt=$pdo->prepare('INSERT INTO ai_usage_ledger (user_id,subscription_id,scope,provider,model,input_tokens,output_tokens,total_tokens,credit_tokens_used,package_tokens_used,trace_id,request_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$uid,(int)($sub['id']??0)?:null,mb_strimwidth($scope,0,60,''),mb_strimwidth($provider,0,40,''),mb_strimwidth($model,0,120,''),max(0,(int)($usage['input_tokens']??0)),max(0,(int)($usage['output_tokens']??0)),$total,$creditUsed,$packageUsed,mb_strimwidth($trace,0,120,''),$requestKey]);
        if($reservationId>0)$pdo->prepare('DELETE FROM ai_token_reservations WHERE id=? AND user_id=?')->execute([$reservationId,$uid]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();subscription_ai_release_reservation($reservationId);error_log('VP3 AI usage ledger failed: '.$e->getMessage());}
}

function subscription_usage_by_scope(int $userId,int $limit=20): array
{
    $pdo=db();if(!$pdo||$userId<1||!subscription_schema_ready($pdo))return [];
    $stmt=$pdo->prepare('SELECT scope,SUM(total_tokens) total_tokens,SUM(input_tokens) input_tokens,SUM(output_tokens) output_tokens,COUNT(*) requests FROM ai_usage_ledger WHERE user_id=? GROUP BY scope ORDER BY total_tokens DESC LIMIT '.max(1,min(100,$limit)));
    $stmt->execute([$userId]);return $stmt->fetchAll()?:[];
}

function subscription_recent_usage(int $userId,int $limit=30): array
{
    $pdo=db();if(!$pdo||$userId<1||!subscription_schema_ready($pdo))return [];
    $stmt=$pdo->prepare('SELECT * FROM ai_usage_ledger WHERE user_id=? ORDER BY id DESC LIMIT '.max(1,min(200,$limit)));$stmt->execute([$userId]);return $stmt->fetchAll()?:[];
}

function subscription_recent_credits(int $userId,int $limit=30): array
{
    $pdo=db();if(!$pdo||$userId<1||!subscription_schema_ready($pdo))return [];
    $stmt=$pdo->prepare('SELECT c.*,u.display_name created_by_name FROM ai_token_credits c LEFT JOIN users u ON u.id=c.created_by WHERE c.user_id=? ORDER BY c.id DESC LIMIT '.max(1,min(100,$limit)));$stmt->execute([$userId]);return $stmt->fetchAll()?:[];
}

function subscription_effective_access(?array $user=null): array
{
    $user??=function_exists('current_user')?current_user():null;
    $sub=subscription_current($user);$balance=subscription_ai_balance($user);
    $caps=[];foreach(subscription_capability_catalog() as $key=>$meta){$caps[$key]=['enabled'=>subscription_has_entitlement($user,$key),'limit'=>subscription_entitlement_limit($user,$key,null)]+$meta;}
    $permissions=[];if(function_exists('permission_catalog'))foreach(permission_catalog() as $key=>$meta)$permissions[$key]=['allowed'=>function_exists('has_permission')?has_permission($key,$user):false,'package_grant'=>subscription_package_grants_permission($user,$key),'label'=>$meta['label']??$key];
    return ['subscription'=>$sub,'balance'=>$balance,'capabilities'=>$caps,'permissions'=>$permissions];
}
