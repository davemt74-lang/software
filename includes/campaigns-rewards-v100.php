<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_V100='vp3-campaigns-rewards-v100-20260923';
const VP3_CAMPAIGNS_REWARDS_PLUGIN_KEY_V100='campaigns_rewards';
const VP3_CAMPAIGNS_REWARDS_MAX_CLAIMS_PER_SESSION_V100=5;
const VP3_CAMPAIGNS_REWARDS_CLAIM_WINDOW_SECONDS_V100=600;

function campaigns_rewards_text_v100(mixed $value,int $limit=500): string
{
    $value=preg_replace('/\s+/u',' ',trim((string)$value))??'';
    return mb_strimwidth($value,0,max(1,$limit),'…');
}

function campaigns_rewards_slug_v100(string $value,int $limit=80): string
{
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/','-',$value)??'';
    return substr(trim($value,'-'),0,max(1,$limit));
}

function campaigns_rewards_uuid_v100(): string
{
    if(function_exists('vp3_cognitive_uuid_v500'))return vp3_cognitive_uuid_v500();
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}

function campaigns_rewards_claim_code_v100(int $length=10): string
{
    $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';$out='';$bytes=random_bytes(max(8,$length));
    for($i=0;$i<$length;$i++)$out.=$alphabet[ord($bytes[$i%strlen($bytes)])%strlen($alphabet)];
    return $out;
}

function campaigns_rewards_datetime_v100(string $value): ?string
{
    $value=trim($value);if($value==='')return null;
    $ts=strtotime($value);if($ts===false)throw new RuntimeException('Enter a valid date and time.');
    return gmdate('Y-m-d H:i:s',$ts);
}

function campaigns_rewards_team_categories_v100(): array
{
    return ['basic'=>'Basic Team','merchant'=>'Merchant Team','both'=>'Both'];
}

function campaigns_rewards_merchant_roles_v100(): array
{
    return ['owner'=>'Owner','admin'=>'Admin','member'=>'Member'];
}

function campaigns_rewards_schema_ready_v100(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    foreach([
        'campaign_merchant_accounts_v100','campaign_merchant_members_v100','campaign_merchant_locations_v100',
        'campaigns_v100','campaign_rewards_v100','campaign_customers_v100','campaign_reward_claims_v100',
        'campaign_activity_v100','campaign_team_scopes_v100','campaign_team_invite_scopes_v100'
    ] as $table)if(!table_exists($table))return false;
    return true;
}

function campaigns_rewards_ensure_schema_v100(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if($pdo->inTransaction()&&!campaigns_rewards_schema_ready_v100($pdo))throw new RuntimeException('Run the VP3 database upgrade before changing Campaigns & Rewards data.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_merchant_accounts_v100 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      profile_user_id INT UNSIGNED NULL,
      name VARCHAR(190) NOT NULL,
      slug VARCHAR(100) NOT NULL,
      description TEXT NULL,
      website_url VARCHAR(500) NOT NULL DEFAULT '',
      contact_email VARCHAR(190) NOT NULL DEFAULT '',
      contact_phone VARCHAR(80) NOT NULL DEFAULT '',
      status VARCHAR(20) NOT NULL DEFAULT 'active',
      created_by_user_id INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_merchant_public (public_id),
      UNIQUE KEY uq_campaign_merchant_slug (slug),
      INDEX idx_campaign_merchant_owner (owner_user_id,status,updated_at,id),
      INDEX idx_campaign_merchant_profile (profile_user_id,status,id),
      CONSTRAINT fk_campaign_merchant_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_merchant_profile FOREIGN KEY (profile_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_campaign_merchant_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_merchant_members_v100 (
      merchant_account_id BIGINT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      member_role VARCHAR(20) NOT NULL DEFAULT 'member',
      member_status VARCHAR(20) NOT NULL DEFAULT 'active',
      source VARCHAR(30) NOT NULL DEFAULT 'direct',
      created_by_user_id INT UNSIGNED NULL,
      joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      suspended_at DATETIME NULL,
      removed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (merchant_account_id,user_id),
      INDEX idx_campaign_merchant_member_user (user_id,member_status,merchant_account_id),
      INDEX idx_campaign_merchant_member_role (merchant_account_id,member_status,member_role,user_id),
      CONSTRAINT fk_campaign_merchant_member_account FOREIGN KEY (merchant_account_id) REFERENCES campaign_merchant_accounts_v100(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_merchant_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_merchant_member_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_merchant_locations_v100 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      merchant_account_id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(190) NOT NULL,
      address_line1 VARCHAR(190) NOT NULL DEFAULT '',
      address_line2 VARCHAR(190) NOT NULL DEFAULT '',
      city VARCHAR(120) NOT NULL DEFAULT '',
      region VARCHAR(120) NOT NULL DEFAULT '',
      postal_code VARCHAR(40) NOT NULL DEFAULT '',
      country VARCHAR(80) NOT NULL DEFAULT 'US',
      phone VARCHAR(80) NOT NULL DEFAULT '',
      is_primary TINYINT(1) NOT NULL DEFAULT 0,
      status VARCHAR(20) NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_location_public (public_id),
      INDEX idx_campaign_location_merchant (merchant_account_id,status,is_primary,id),
      CONSTRAINT fk_campaign_location_merchant FOREIGN KEY (merchant_account_id) REFERENCES campaign_merchant_accounts_v100(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaigns_v100 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      merchant_account_id BIGINT UNSIGNED NOT NULL,
      location_id BIGINT UNSIGNED NULL,
      created_by_user_id INT UNSIGNED NOT NULL,
      slug VARCHAR(100) NOT NULL,
      title VARCHAR(190) NOT NULL,
      subtitle VARCHAR(255) NOT NULL DEFAULT '',
      description TEXT NULL,
      cta_label VARCHAR(80) NOT NULL DEFAULT 'Claim reward',
      terms TEXT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'draft',
      profile_visible TINYINT(1) NOT NULL DEFAULT 1,
      starts_at DATETIME NULL,
      ends_at DATETIME NULL,
      published_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_public (public_id),
      UNIQUE KEY uq_campaign_slug (slug),
      INDEX idx_campaign_merchant_status (merchant_account_id,status,updated_at,id),
      INDEX idx_campaign_profile (merchant_account_id,profile_visible,status,starts_at,ends_at),
      CONSTRAINT fk_campaign_merchant FOREIGN KEY (merchant_account_id) REFERENCES campaign_merchant_accounts_v100(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_location FOREIGN KEY (location_id) REFERENCES campaign_merchant_locations_v100(id) ON DELETE SET NULL,
      CONSTRAINT fk_campaign_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_rewards_v100 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      merchant_account_id BIGINT UNSIGNED NOT NULL,
      campaign_id BIGINT UNSIGNED NOT NULL,
      title VARCHAR(190) NOT NULL,
      description TEXT NULL,
      reward_type VARCHAR(30) NOT NULL DEFAULT 'offer',
      value_label VARCHAR(120) NOT NULL DEFAULT '',
      inventory_limit INT UNSIGNED NOT NULL DEFAULT 0,
      per_customer_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1,
      status VARCHAR(20) NOT NULL DEFAULT 'active',
      starts_at DATETIME NULL,
      ends_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_reward_public (public_id),
      INDEX idx_campaign_reward_campaign (campaign_id,status,id),
      INDEX idx_campaign_reward_merchant (merchant_account_id,status,id),
      CONSTRAINT fk_campaign_reward_merchant FOREIGN KEY (merchant_account_id) REFERENCES campaign_merchant_accounts_v100(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_reward_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns_v100(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_customers_v100 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      merchant_account_id BIGINT UNSIGNED NOT NULL,
      crm_contact_id BIGINT UNSIGNED NULL,
      email VARCHAR(190) NOT NULL,
      email_normalized VARCHAR(190) NOT NULL,
      name VARCHAR(190) NOT NULL DEFAULT '',
      phone VARCHAR(80) NOT NULL DEFAULT '',
      first_campaign_id BIGINT UNSIGNED NULL,
      last_campaign_id BIGINT UNSIGNED NULL,
      first_engaged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_engaged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_customer_public (public_id),
      UNIQUE KEY uq_campaign_customer_merchant_email (merchant_account_id,email_normalized),
      INDEX idx_campaign_customer_crm (crm_contact_id),
      INDEX idx_campaign_customer_recent (merchant_account_id,last_engaged_at,id),
      CONSTRAINT fk_campaign_customer_merchant FOREIGN KEY (merchant_account_id) REFERENCES campaign_merchant_accounts_v100(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_customer_first FOREIGN KEY (first_campaign_id) REFERENCES campaigns_v100(id) ON DELETE SET NULL,
      CONSTRAINT fk_campaign_customer_last FOREIGN KEY (last_campaign_id) REFERENCES campaigns_v100(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_reward_claims_v100 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      merchant_account_id BIGINT UNSIGNED NOT NULL,
      campaign_id BIGINT UNSIGNED NOT NULL,
      reward_id BIGINT UNSIGNED NOT NULL,
      customer_id BIGINT UNSIGNED NOT NULL,
      claim_code VARCHAR(24) NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'issued',
      issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      validated_at DATETIME NULL,
      redeemed_at DATETIME NULL,
      refunded_at DATETIME NULL,
      expired_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_claim_public (public_id),
      UNIQUE KEY uq_campaign_claim_code (claim_code),
      INDEX idx_campaign_claim_merchant (merchant_account_id,status,updated_at,id),
      INDEX idx_campaign_claim_reward (reward_id,status,issued_at,id),
      INDEX idx_campaign_claim_customer (customer_id,reward_id,status,issued_at,id),
      CONSTRAINT fk_campaign_claim_merchant FOREIGN KEY (merchant_account_id) REFERENCES campaign_merchant_accounts_v100(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_claim_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns_v100(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_claim_reward FOREIGN KEY (reward_id) REFERENCES campaign_rewards_v100(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_claim_customer FOREIGN KEY (customer_id) REFERENCES campaign_customers_v100(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_activity_v100 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      merchant_account_id BIGINT UNSIGNED NOT NULL,
      campaign_id BIGINT UNSIGNED NULL,
      reward_id BIGINT UNSIGNED NULL,
      claim_id BIGINT UNSIGNED NULL,
      customer_id BIGINT UNSIGNED NULL,
      actor_user_id INT UNSIGNED NULL,
      event_type VARCHAR(120) NOT NULL,
      dedupe_key CHAR(64) NULL,
      metadata_json LONGTEXT NULL,
      occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_activity_dedupe (dedupe_key),
      INDEX idx_campaign_activity_merchant (merchant_account_id,occurred_at,id),
      INDEX idx_campaign_activity_campaign (campaign_id,event_type,occurred_at,id),
      INDEX idx_campaign_activity_claim (claim_id,event_type,id),
      CONSTRAINT fk_campaign_activity_merchant FOREIGN KEY (merchant_account_id) REFERENCES campaign_merchant_accounts_v100(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_activity_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns_v100(id) ON DELETE SET NULL,
      CONSTRAINT fk_campaign_activity_reward FOREIGN KEY (reward_id) REFERENCES campaign_rewards_v100(id) ON DELETE SET NULL,
      CONSTRAINT fk_campaign_activity_claim FOREIGN KEY (claim_id) REFERENCES campaign_reward_claims_v100(id) ON DELETE SET NULL,
      CONSTRAINT fk_campaign_activity_customer FOREIGN KEY (customer_id) REFERENCES campaign_customers_v100(id) ON DELETE SET NULL,
      CONSTRAINT fk_campaign_activity_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_team_scopes_v100 (
      workspace_owner_user_id INT UNSIGNED NOT NULL,
      member_user_id INT UNSIGNED NOT NULL,
      team_category VARCHAR(20) NOT NULL DEFAULT 'basic',
      merchant_account_id BIGINT UNSIGNED NULL,
      created_by_user_id INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (workspace_owner_user_id,member_user_id),
      INDEX idx_campaign_team_scope_merchant (merchant_account_id,team_category,member_user_id),
      CONSTRAINT fk_campaign_team_scope_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_team_scope_member FOREIGN KEY (member_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_team_scope_merchant FOREIGN KEY (merchant_account_id) REFERENCES campaign_merchant_accounts_v100(id) ON DELETE SET NULL,
      CONSTRAINT fk_campaign_team_scope_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_team_invite_scopes_v100 (
      invitation_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      workspace_owner_user_id INT UNSIGNED NOT NULL,
      team_category VARCHAR(20) NOT NULL DEFAULT 'basic',
      merchant_account_id BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_campaign_invite_scope_owner (workspace_owner_user_id,team_category,invitation_id),
      CONSTRAINT fk_campaign_invite_scope_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_campaign_invite_scope_merchant FOREIGN KEY (merchant_account_id) REFERENCES campaign_merchant_accounts_v100(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function campaigns_rewards_plugin_state_v100(?array $user=null,?PDO $pdo=null): array
{
    $user??=current_user();$pdo??=db();
    if(!$user||!$pdo)return ['enabled'=>false,'reason'=>'unavailable','schema_ready'=>false];
    $state=function_exists('vp3_plugin_effective_state_v360')?vp3_plugin_effective_state_v360($pdo,$user,VP3_CAMPAIGNS_REWARDS_PLUGIN_KEY_V100):['enabled'=>false,'reason'=>'unavailable'];
    $state['schema_ready']=campaigns_rewards_schema_ready_v100($pdo);return $state;
}

function campaigns_rewards_enabled_v100(?array $user=null,?PDO $pdo=null): bool
{
    return !empty(campaigns_rewards_plugin_state_v100($user,$pdo)['enabled']);
}

function campaigns_rewards_user_row_v100(PDO $pdo,int $userId): ?array
{
    if($userId<1)return null;
    $stmt=$pdo->prepare('SELECT id,email,display_name,role,is_active,avatar_path FROM users WHERE id=? LIMIT 1');$stmt->execute([$userId]);
    $row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_owner_plugin_enabled_v100(PDO $pdo,int $ownerUserId): bool
{
    $owner=campaigns_rewards_user_row_v100($pdo,$ownerUserId);return $owner?campaigns_rewards_enabled_v100($owner,$pdo):false;
}

function campaigns_rewards_merchant_v100(PDO $pdo,int $merchantId,bool $forUpdate=false): ?array
{
    if($merchantId<1||!campaigns_rewards_schema_ready_v100($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM campaign_merchant_accounts_v100 WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$merchantId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_merchant_member_v100(PDO $pdo,int $merchantId,int $userId,bool $forUpdate=false): ?array
{
    if($merchantId<1||$userId<1||!campaigns_rewards_schema_ready_v100($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM campaign_merchant_members_v100 WHERE merchant_account_id=? AND user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$merchantId,$userId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_member_role_v100(PDO $pdo,int $merchantId,int $userId): string
{
    $merchant=campaigns_rewards_merchant_v100($pdo,$merchantId);if(!$merchant)return '';
    if((int)$merchant['owner_user_id']===$userId)return 'owner';
    $member=campaigns_rewards_merchant_member_v100($pdo,$merchantId,$userId);
    return $member&&($member['member_status']??'')==='active'?(string)$member['member_role']:'';
}

function campaigns_rewards_can_manage_merchant_v100(PDO $pdo,int $merchantId,int $userId): bool
{
    return in_array(campaigns_rewards_member_role_v100($pdo,$merchantId,$userId),['owner','admin'],true);
}

function campaigns_rewards_can_own_merchant_v100(PDO $pdo,int $merchantId,int $userId): bool
{
    return campaigns_rewards_member_role_v100($pdo,$merchantId,$userId)==='owner';
}

function campaigns_rewards_unique_slug_v100(PDO $pdo,string $table,string $value,int $excludeId=0): string
{
    if(!in_array($table,['campaign_merchant_accounts_v100','campaigns_v100'],true))throw new InvalidArgumentException('Unsupported slug authority.');
    $base=campaigns_rewards_slug_v100($value);if($base==='')$base='campaign';
    for($i=0;$i<100;$i++){
        $slug=$i===0?$base:substr($base,0,72).'-'.($i+1);
        $sql="SELECT id FROM {$table} WHERE slug=?".($excludeId>0?' AND id<>?':'')." LIMIT 1";
        $stmt=$pdo->prepare($sql);$stmt->execute($excludeId>0?[$slug,$excludeId]:[$slug]);if(!$stmt->fetchColumn())return $slug;
    }
    throw new RuntimeException('A unique public slug could not be generated.');
}

function campaigns_rewards_safe_url_v100(string $value): string
{
    $value=trim($value);if($value==='')return '';
    if(!filter_var($value,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($value,PHP_URL_SCHEME)),['http','https'],true))throw new RuntimeException('Website URL must use http or https.');
    return mb_strimwidth($value,0,500,'');
}

function campaigns_rewards_ref_v100(string $type,mixed $id,string $scope='workspace'): array
{
    if(function_exists('vp3_cognitive_domain_entity_ref_v2600'))return vp3_cognitive_domain_entity_ref_v2600('campaigns_rewards',$type,$id,$scope);
    return ['domain'=>'campaigns_rewards','type'=>$type,'id'=>(string)$id,'scope'=>$scope];
}

function campaigns_rewards_emit_v100(PDO $pdo,int $ownerUserId,string $eventType,array $refs,array $payload=[],array $options=[]): array
{
    if(!function_exists('vp3_cognitive_domain_ingest_v2600'))return ['accepted'=>false,'reason'=>'cognitive_runtime_unavailable'];
    return vp3_cognitive_domain_ingest_v2600($pdo,$ownerUserId,'campaigns_rewards',$eventType,$refs,$payload,$options);
}

function campaigns_rewards_record_activity_v100(PDO $pdo,int $merchantId,string $eventType,array $ids=[],array $metadata=[],?string $dedupeKey=null): int
{
    $meta=function_exists('vp3_cognitive_sanitize_value_v500')?vp3_cognitive_sanitize_value_v500($metadata):$metadata;
    $json=json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    $dedupe=$dedupeKey!==null&&$dedupeKey!==''?hash('sha256',$dedupeKey):null;
    $stmt=$pdo->prepare("INSERT IGNORE INTO campaign_activity_v100
      (merchant_account_id,campaign_id,reward_id,claim_id,customer_id,actor_user_id,event_type,dedupe_key,metadata_json,occurred_at)
      VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
    $stmt->execute([
        $merchantId,!empty($ids['campaign_id'])?(int)$ids['campaign_id']:null,!empty($ids['reward_id'])?(int)$ids['reward_id']:null,
        !empty($ids['claim_id'])?(int)$ids['claim_id']:null,!empty($ids['customer_id'])?(int)$ids['customer_id']:null,
        !empty($ids['actor_user_id'])?(int)$ids['actor_user_id']:null,campaigns_rewards_text_v100($eventType,120),$dedupe,is_string($json)?$json:'{}'
    ]);
    return (int)$pdo->lastInsertId();
}

function campaigns_rewards_create_merchant_v100(PDO $pdo,array $user,array $input): array
{
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('Sign in to create a merchant account.');
    if(!campaigns_rewards_enabled_v100($user,$pdo))throw new RuntimeException('Enable Campaigns & Rewards before creating a merchant account.');
    campaigns_rewards_ensure_schema_v100($pdo);
    $name=campaigns_rewards_text_v100($input['name']??'',190);if($name==='')throw new RuntimeException('Merchant name is required.');
    $slug=campaigns_rewards_unique_slug_v100($pdo,'campaign_merchant_accounts_v100',(string)($input['slug']??$name));
    $description=mb_strimwidth(trim((string)($input['description']??'')),0,4000,'…');
    $website=campaigns_rewards_safe_url_v100((string)($input['website_url']??''));
    $email=strtolower(trim((string)($input['contact_email']??$user['email']??'')));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid merchant email.');
    $phone=campaigns_rewards_text_v100($input['contact_phone']??'',80);$public=campaigns_rewards_uuid_v100();
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("INSERT INTO campaign_merchant_accounts_v100
          (public_id,owner_user_id,profile_user_id,name,slug,description,website_url,contact_email,contact_phone,status,created_by_user_id)
          VALUES (?,?,?,?,?,?,?,?,?,'active',?)");
        $stmt->execute([$public,$uid,$uid,$name,$slug,$description,$website,$email,$phone,$uid]);$merchantId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO campaign_merchant_members_v100
          (merchant_account_id,user_id,member_role,member_status,source,created_by_user_id)
          VALUES (?,?,'owner','active','direct',?)")->execute([$merchantId,$uid,$uid]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $merchant=campaigns_rewards_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant account could not be loaded.');
    campaigns_rewards_record_activity_v100($pdo,$merchantId,'merchant.created',['actor_user_id'=>$uid],['merchant_public_id'=>$public]);
    campaigns_rewards_emit_v100($pdo,$uid,'merchant.created',[campaigns_rewards_ref_v100('merchant_account',$public)],['merchant_id'=>$public],['external_event_id'=>'merchant:'.$public.':created']);
    return $merchant;
}

function campaigns_rewards_update_merchant_v100(PDO $pdo,int $merchantId,int $actorUserId,array $input): array
{
    if(!campaigns_rewards_can_manage_merchant_v100($pdo,$merchantId,$actorUserId))throw new RuntimeException('Merchant admin access is required.');
    $merchant=campaigns_rewards_merchant_v100($pdo,$merchantId,true)?:throw new RuntimeException('Merchant account not found.');
    $name=campaigns_rewards_text_v100($input['name']??$merchant['name'],190);if($name==='')throw new RuntimeException('Merchant name is required.');
    $slug=campaigns_rewards_unique_slug_v100($pdo,'campaign_merchant_accounts_v100',(string)($input['slug']??$merchant['slug']),$merchantId);
    $description=mb_strimwidth(trim((string)($input['description']??$merchant['description']??'')),0,4000,'…');
    $website=campaigns_rewards_safe_url_v100((string)($input['website_url']??$merchant['website_url']??''));
    $email=strtolower(trim((string)($input['contact_email']??$merchant['contact_email']??'')));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid merchant email.');
    $phone=campaigns_rewards_text_v100($input['contact_phone']??$merchant['contact_phone']??'',80);
    $profileUserId=max(0,(int)($input['profile_user_id']??$merchant['profile_user_id']??0));if($profileUserId>0&&!campaigns_rewards_user_row_v100($pdo,$profileUserId))throw new RuntimeException('Profile owner is unavailable.');
    $pdo->prepare("UPDATE campaign_merchant_accounts_v100 SET name=?,slug=?,description=?,website_url=?,contact_email=?,contact_phone=?,profile_user_id=? WHERE id=?")
        ->execute([$name,$slug,$description,$website,$email,$phone,$profileUserId?:null,$merchantId]);
    $saved=campaigns_rewards_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant account could not be reloaded.');
    campaigns_rewards_record_activity_v100($pdo,$merchantId,'merchant.updated',['actor_user_id'=>$actorUserId],[]);
    campaigns_rewards_emit_v100($pdo,(int)$saved['owner_user_id'],'merchant.updated',[campaigns_rewards_ref_v100('merchant_account',$saved['public_id'])],['merchant_id'=>$saved['public_id']]);
    return $saved;
}

function campaigns_rewards_accessible_merchants_v100(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);if($uid<1||!campaigns_rewards_schema_ready_v100($pdo))return [];
    $stmt=$pdo->prepare("SELECT m.*,COALESCE(mm.member_role,IF(m.owner_user_id=?,'owner','')) access_role,
      COALESCE(mm.member_status,IF(m.owner_user_id=?,'active','')) access_status
      FROM campaign_merchant_accounts_v100 m
      LEFT JOIN campaign_merchant_members_v100 mm ON mm.merchant_account_id=m.id AND mm.user_id=?
      WHERE m.status='active' AND (m.owner_user_id=? OR (mm.user_id=? AND mm.member_status='active'))
      ORDER BY m.name,m.id");
    $stmt->execute([$uid,$uid,$uid,$uid,$uid]);$rows=[];
    foreach($stmt->fetchAll()?:[] as $row)if(campaigns_rewards_owner_plugin_enabled_v100($pdo,(int)$row['owner_user_id']))$rows[]=$row;
    return $rows;
}

function campaigns_rewards_owned_merchants_v100(PDO $pdo,int $ownerUserId): array
{
    if($ownerUserId<1||!campaigns_rewards_schema_ready_v100($pdo))return [];
    $stmt=$pdo->prepare("SELECT * FROM campaign_merchant_accounts_v100 WHERE owner_user_id=? AND status='active' ORDER BY name,id");$stmt->execute([$ownerUserId]);
    return $stmt->fetchAll()?:[];
}

function campaigns_rewards_user_has_access_v100(PDO $pdo,array $user): bool
{
    if(!campaigns_rewards_schema_ready_v100($pdo))return false;
    return campaigns_rewards_enabled_v100($user,$pdo)||campaigns_rewards_accessible_merchants_v100($pdo,$user)!==[];
}

function campaigns_rewards_locations_v100(PDO $pdo,int $merchantId): array
{
    $stmt=$pdo->prepare("SELECT * FROM campaign_merchant_locations_v100 WHERE merchant_account_id=? AND status='active' ORDER BY is_primary DESC,name,id");$stmt->execute([$merchantId]);
    return $stmt->fetchAll()?:[];
}

function campaigns_rewards_save_location_v100(PDO $pdo,int $merchantId,int $actorUserId,array $input,int $locationId=0): array
{
    if(!campaigns_rewards_can_manage_merchant_v100($pdo,$merchantId,$actorUserId))throw new RuntimeException('Merchant admin access is required.');
    $name=campaigns_rewards_text_v100($input['name']??'',190);if($name==='')throw new RuntimeException('Location name is required.');
    $fields=[campaigns_rewards_text_v100($input['address_line1']??'',190),campaigns_rewards_text_v100($input['address_line2']??'',190),campaigns_rewards_text_v100($input['city']??'',120),campaigns_rewards_text_v100($input['region']??'',120),campaigns_rewards_text_v100($input['postal_code']??'',40),strtoupper(campaigns_rewards_text_v100($input['country']??'US',80))?:'US',campaigns_rewards_text_v100($input['phone']??'',80)];
    $primary=!empty($input['is_primary'])?1:0;$creating=$locationId<1;
    if(!$creating){
        $check=$pdo->prepare('SELECT id FROM campaign_merchant_locations_v100 WHERE id=? AND merchant_account_id=? LIMIT 1');$check->execute([$locationId,$merchantId]);if(!$check->fetchColumn())throw new RuntimeException('Location not found.');
        if($primary)$pdo->prepare('UPDATE campaign_merchant_locations_v100 SET is_primary=0 WHERE merchant_account_id=?')->execute([$merchantId]);
        $pdo->prepare("UPDATE campaign_merchant_locations_v100 SET name=?,address_line1=?,address_line2=?,city=?,region=?,postal_code=?,country=?,phone=?,is_primary=?,status='active' WHERE id=? AND merchant_account_id=?")
            ->execute(array_merge([$name],$fields,[$primary,$locationId,$merchantId]));
    }else{
        if($primary)$pdo->prepare('UPDATE campaign_merchant_locations_v100 SET is_primary=0 WHERE merchant_account_id=?')->execute([$merchantId]);
        $stmt=$pdo->prepare("INSERT INTO campaign_merchant_locations_v100 (public_id,merchant_account_id,name,address_line1,address_line2,city,region,postal_code,country,phone,is_primary,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'active')");
        $stmt->execute(array_merge([campaigns_rewards_uuid_v100(),$merchantId,$name],$fields,[$primary]));$locationId=(int)$pdo->lastInsertId();
    }
    $stmt=$pdo->prepare('SELECT * FROM campaign_merchant_locations_v100 WHERE id=? AND merchant_account_id=? LIMIT 1');$stmt->execute([$locationId,$merchantId]);$row=$stmt->fetch()?:throw new RuntimeException('Location could not be loaded.');
    $merchant=campaigns_rewards_merchant_v100($pdo,$merchantId);
    if($merchant){
        $event=$creating?'merchant.location_created':'merchant.location_updated';
        campaigns_rewards_record_activity_v100($pdo,$merchantId,$event,['actor_user_id'=>$actorUserId],['location_public_id'=>$row['public_id']]);
        campaigns_rewards_emit_v100($pdo,(int)$merchant['owner_user_id'],$event,[campaigns_rewards_ref_v100('merchant_account',$merchant['public_id']),campaigns_rewards_ref_v100('merchant_location',$row['public_id'])],['merchant_location_id'=>$row['public_id']]);
    }
    return $row;
}

function campaigns_rewards_campaign_v100(PDO $pdo,int $campaignId,bool $forUpdate=false): ?array
{
    if($campaignId<1||!campaigns_rewards_schema_ready_v100($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM campaigns_v100 WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$campaignId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_campaigns_v100(PDO $pdo,int $merchantId,bool $activeOnly=false): array
{
    $sql='SELECT c.*,l.name location_name FROM campaigns_v100 c LEFT JOIN campaign_merchant_locations_v100 l ON l.id=c.location_id WHERE c.merchant_account_id=?';
    if($activeOnly)$sql.=" AND c.status='active' AND (c.starts_at IS NULL OR c.starts_at<=UTC_TIMESTAMP()) AND (c.ends_at IS NULL OR c.ends_at>UTC_TIMESTAMP())";
    $sql.=' ORDER BY c.updated_at DESC,c.id DESC';$stmt=$pdo->prepare($sql);$stmt->execute([$merchantId]);return $stmt->fetchAll()?:[];
}

function campaigns_rewards_save_campaign_v100(PDO $pdo,int $merchantId,int $actorUserId,array $input,int $campaignId=0): array
{
    if(!campaigns_rewards_can_manage_merchant_v100($pdo,$merchantId,$actorUserId))throw new RuntimeException('Merchant admin access is required.');
    $merchant=campaigns_rewards_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant account not found.');
    $existing=$campaignId>0?campaigns_rewards_campaign_v100($pdo,$campaignId):null;if($campaignId>0&&(!$existing||(int)$existing['merchant_account_id']!==$merchantId))throw new RuntimeException('Campaign not found.');
    $title=campaigns_rewards_text_v100($input['title']??$existing['title']??'',190);if($title==='')throw new RuntimeException('Campaign title is required.');
    $slug=campaigns_rewards_unique_slug_v100($pdo,'campaigns_v100',(string)($input['slug']??$existing['slug']??$title),$campaignId);
    $subtitle=campaigns_rewards_text_v100($input['subtitle']??$existing['subtitle']??'',255);$description=mb_strimwidth(trim((string)($input['description']??$existing['description']??'')),0,8000,'…');
    $cta=campaigns_rewards_text_v100($input['cta_label']??$existing['cta_label']??'Claim reward',80)?:'Claim reward';$terms=mb_strimwidth(trim((string)($input['terms']??$existing['terms']??'')),0,6000,'…');
    $starts=campaigns_rewards_datetime_v100((string)($input['starts_at']??$existing['starts_at']??''));$ends=campaigns_rewards_datetime_v100((string)($input['ends_at']??$existing['ends_at']??''));
    if($starts&&$ends&&strtotime($ends)<=strtotime($starts))throw new RuntimeException('Campaign end must be after its start.');
    $locationId=max(0,(int)($input['location_id']??$existing['location_id']??0));
    if($locationId>0){$s=$pdo->prepare("SELECT id FROM campaign_merchant_locations_v100 WHERE id=? AND merchant_account_id=? AND status='active' LIMIT 1");$s->execute([$locationId,$merchantId]);if(!$s->fetchColumn())throw new RuntimeException('Choose a valid merchant location.');}
    $profileVisible=!empty($input['profile_visible'])?1:0;
    if($existing){
        $pdo->prepare("UPDATE campaigns_v100 SET location_id=?,slug=?,title=?,subtitle=?,description=?,cta_label=?,terms=?,profile_visible=?,starts_at=?,ends_at=? WHERE id=? AND merchant_account_id=?")->execute([$locationId?:null,$slug,$title,$subtitle,$description,$cta,$terms,$profileVisible,$starts,$ends,$campaignId,$merchantId]);$event='campaign.updated';
    }else{
        $stmt=$pdo->prepare("INSERT INTO campaigns_v100 (public_id,merchant_account_id,location_id,created_by_user_id,slug,title,subtitle,description,cta_label,terms,status,profile_visible,starts_at,ends_at) VALUES (?,?,?,?,?,?,?,?,?,?,'draft',?,?,?)");
        $stmt->execute([campaigns_rewards_uuid_v100(),$merchantId,$locationId?:null,$actorUserId,$slug,$title,$subtitle,$description,$cta,$terms,$profileVisible,$starts,$ends]);$campaignId=(int)$pdo->lastInsertId();$event='campaign.created';
    }
    $saved=campaigns_rewards_campaign_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign could not be loaded.');
    campaigns_rewards_record_activity_v100($pdo,$merchantId,$event,['campaign_id'=>$campaignId,'actor_user_id'=>$actorUserId],[]);
    campaigns_rewards_emit_v100($pdo,(int)$merchant['owner_user_id'],$event,[campaigns_rewards_ref_v100('merchant_account',$merchant['public_id']),campaigns_rewards_ref_v100('campaign',$saved['public_id'])],['campaign_id'=>$saved['public_id']]);
    return $saved;
}

function campaigns_rewards_set_campaign_status_v100(PDO $pdo,int $campaignId,int $actorUserId,string $status): array
{
    if(!in_array($status,['draft','active','paused','ended'],true))throw new RuntimeException('Choose a valid campaign status.');
    $campaign=campaigns_rewards_campaign_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');$merchant=campaigns_rewards_merchant_v100($pdo,(int)$campaign['merchant_account_id'])?:throw new RuntimeException('Merchant account not found.');
    if(!campaigns_rewards_can_manage_merchant_v100($pdo,(int)$merchant['id'],$actorUserId))throw new RuntimeException('Merchant admin access is required.');
    $before=(string)$campaign['status'];$published=$status==='active'?gmdate('Y-m-d H:i:s'):($campaign['published_at']??null);
    $pdo->prepare("UPDATE campaigns_v100 SET status=?,published_at=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$status,$published,$campaignId]);
    $event=match($status){'active'=>'campaign.launched','paused'=>'campaign.paused','ended'=>'campaign.ended',default=>'campaign.updated'};$saved=campaigns_rewards_campaign_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign could not be reloaded.');
    campaigns_rewards_record_activity_v100($pdo,(int)$merchant['id'],$event,['campaign_id'=>$campaignId,'actor_user_id'=>$actorUserId],['from_status'=>$before,'to_status'=>$status]);
    campaigns_rewards_emit_v100($pdo,(int)$merchant['owner_user_id'],$event,[campaigns_rewards_ref_v100('merchant_account',$merchant['public_id']),campaigns_rewards_ref_v100('campaign',$saved['public_id'])],['campaign_id'=>$saved['public_id'],'status'=>$status]);
    return $saved;
}

function campaigns_rewards_reward_v100(PDO $pdo,int $rewardId,bool $forUpdate=false): ?array
{
    if($rewardId<1)return null;$stmt=$pdo->prepare('SELECT * FROM campaign_rewards_v100 WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$rewardId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_rewards_v100(PDO $pdo,int $campaignId,bool $publicOnly=false): array
{
    $sql='SELECT * FROM campaign_rewards_v100 WHERE campaign_id=?';if($publicOnly)$sql.=" AND status='active' AND (starts_at IS NULL OR starts_at<=UTC_TIMESTAMP()) AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP())";
    $sql.=' ORDER BY id';$stmt=$pdo->prepare($sql);$stmt->execute([$campaignId]);return $stmt->fetchAll()?:[];
}

function campaigns_rewards_save_reward_v100(PDO $pdo,int $campaignId,int $actorUserId,array $input,int $rewardId=0): array
{
    $campaign=campaigns_rewards_campaign_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');$merchantId=(int)$campaign['merchant_account_id'];
    if(!campaigns_rewards_can_manage_merchant_v100($pdo,$merchantId,$actorUserId))throw new RuntimeException('Merchant admin access is required.');
    $existing=$rewardId>0?campaigns_rewards_reward_v100($pdo,$rewardId):null;if($rewardId>0&&(!$existing||(int)$existing['campaign_id']!==$campaignId))throw new RuntimeException('Reward not found.');
    $title=campaigns_rewards_text_v100($input['title']??$existing['title']??'',190);if($title==='')throw new RuntimeException('Reward title is required.');
    $description=mb_strimwidth(trim((string)($input['description']??$existing['description']??'')),0,5000,'…');$type=(string)($input['reward_type']??$existing['reward_type']??'offer');if(!in_array($type,['offer','gift','discount','access','recognition'],true))$type='offer';
    $value=campaigns_rewards_text_v100($input['value_label']??$existing['value_label']??'',120);$inventory=max(0,(int)($input['inventory_limit']??$existing['inventory_limit']??0));$per=max(1,min(25,(int)($input['per_customer_limit']??$existing['per_customer_limit']??1)));
    $status=(string)($input['status']??$existing['status']??'active');if(!in_array($status,['active','inactive'],true))$status='active';$starts=campaigns_rewards_datetime_v100((string)($input['starts_at']??$existing['starts_at']??''));$ends=campaigns_rewards_datetime_v100((string)($input['ends_at']??$existing['ends_at']??''));
    if($starts&&$ends&&strtotime($ends)<=strtotime($starts))throw new RuntimeException('Reward end must be after its start.');
    if($existing){$pdo->prepare("UPDATE campaign_rewards_v100 SET title=?,description=?,reward_type=?,value_label=?,inventory_limit=?,per_customer_limit=?,status=?,starts_at=?,ends_at=? WHERE id=? AND campaign_id=?")->execute([$title,$description,$type,$value,$inventory,$per,$status,$starts,$ends,$rewardId,$campaignId]);$event='reward.updated';}
    else{$stmt=$pdo->prepare("INSERT INTO campaign_rewards_v100 (public_id,merchant_account_id,campaign_id,title,description,reward_type,value_label,inventory_limit,per_customer_limit,status,starts_at,ends_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");$stmt->execute([campaigns_rewards_uuid_v100(),$merchantId,$campaignId,$title,$description,$type,$value,$inventory,$per,$status,$starts,$ends]);$rewardId=(int)$pdo->lastInsertId();$event='reward.created';}
    $saved=campaigns_rewards_reward_v100($pdo,$rewardId)?:throw new RuntimeException('Reward could not be loaded.');$merchant=campaigns_rewards_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant account not found.');
    campaigns_rewards_record_activity_v100($pdo,$merchantId,$event,['campaign_id'=>$campaignId,'reward_id'=>$rewardId,'actor_user_id'=>$actorUserId],[]);
    campaigns_rewards_emit_v100($pdo,(int)$merchant['owner_user_id'],$event,[campaigns_rewards_ref_v100('campaign',$campaign['public_id']),campaigns_rewards_ref_v100('reward',$saved['public_id'])],['campaign_id'=>$campaign['public_id'],'reward_id'=>$saved['public_id']]);
    return $saved;
}

function campaigns_rewards_campaign_by_slug_v100(PDO $pdo,string $slug,bool $publicOnly=true): ?array
{
    $slug=campaigns_rewards_slug_v100($slug);if($slug==='')return null;
    $sql="SELECT c.*,m.public_id merchant_public_id,m.owner_user_id,m.profile_user_id,m.name merchant_name,m.slug merchant_slug,m.description merchant_description,m.website_url merchant_website_url,m.contact_email merchant_contact_email,m.contact_phone merchant_contact_phone,
      l.public_id location_public_id,l.name location_name,l.address_line1,l.address_line2,l.city,l.region,l.postal_code,l.country,l.phone location_phone,
      u.display_name profile_display_name,u.avatar_path profile_avatar_path,p.username profile_username,p.is_public profile_is_public
      FROM campaigns_v100 c INNER JOIN campaign_merchant_accounts_v100 m ON m.id=c.merchant_account_id AND m.status='active'
      LEFT JOIN campaign_merchant_locations_v100 l ON l.id=c.location_id LEFT JOIN users u ON u.id=m.profile_user_id LEFT JOIN user_profiles p ON p.user_id=m.profile_user_id WHERE c.slug=?";
    if($publicOnly)$sql.=" AND c.status='active' AND (c.starts_at IS NULL OR c.starts_at<=UTC_TIMESTAMP()) AND (c.ends_at IS NULL OR c.ends_at>UTC_TIMESTAMP())";
    $stmt=$pdo->prepare($sql.' LIMIT 1');$stmt->execute([$slug]);$row=$stmt->fetch();if(!$row||!campaigns_rewards_owner_plugin_enabled_v100($pdo,(int)$row['owner_user_id']))return null;return $row;
}

function campaigns_rewards_profile_campaigns_v100(PDO $pdo,int $profileUserId,int $limit=24): array
{
    if($profileUserId<1||!campaigns_rewards_schema_ready_v100($pdo))return [];$limit=max(1,min(50,$limit));
    $stmt=$pdo->prepare("SELECT c.id,c.public_id,c.slug,c.title,c.subtitle,c.description,c.cta_label,c.ends_at,m.id merchant_account_id,m.public_id merchant_public_id,m.owner_user_id,m.name merchant_name
      FROM campaigns_v100 c INNER JOIN campaign_merchant_accounts_v100 m ON m.id=c.merchant_account_id
      WHERE m.profile_user_id=? AND m.status='active' AND c.profile_visible=1 AND c.status='active'
        AND (c.starts_at IS NULL OR c.starts_at<=UTC_TIMESTAMP()) AND (c.ends_at IS NULL OR c.ends_at>UTC_TIMESTAMP())
      ORDER BY c.published_at DESC,c.id DESC LIMIT {$limit}");
    $stmt->execute([$profileUserId]);$rows=[];foreach($stmt->fetchAll()?:[] as $row)if(campaigns_rewards_owner_plugin_enabled_v100($pdo,(int)$row['owner_user_id']))$rows[]=$row;return $rows;
}

function campaigns_rewards_campaign_url_v100(string $slug): string{return url('/campaign/'.rawurlencode(campaigns_rewards_slug_v100($slug)));}
function campaigns_rewards_claim_url_v100(string $code): string{return url('/campaign-claim/'.rawurlencode(strtoupper(trim($code))));}

function campaigns_rewards_customer_upsert_v100(PDO $pdo,array $campaign,string $name,string $email,string $phone=''): array
{
    $email=strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
    $name=campaigns_rewards_text_v100($name,190);if($name==='')$name=$email;$phone=campaigns_rewards_text_v100($phone,80);$merchantId=(int)$campaign['merchant_account_id'];$campaignId=(int)$campaign['id'];$crmContactId=null;
    if(function_exists('crm_v180_schema_ready')&&crm_v180_schema_ready($pdo)&&function_exists('crm_v180_upsert_contact')){
        try{
            $existingCrm=$pdo->prepare('SELECT id FROM crm_contacts WHERE email_normalized=? LIMIT 1');
            $existingCrm->execute([$email]);$crmContactId=(int)$existingCrm->fetchColumn();
            if($crmContactId<1)$crmContactId=crm_v180_upsert_contact($pdo,['name'=>$name,'email'=>$email,'phone'=>$phone,'company'=>(string)$campaign['merchant_name'],'source'=>'campaigns_rewards']);
        }catch(Throwable $e){$crmContactId=null;}
    }
    $public=campaigns_rewards_uuid_v100();$stmt=$pdo->prepare("INSERT INTO campaign_customers_v100
      (public_id,merchant_account_id,crm_contact_id,email,email_normalized,name,phone,first_campaign_id,last_campaign_id,first_engaged_at,last_engaged_at)
      VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE crm_contact_id=COALESCE(VALUES(crm_contact_id),crm_contact_id),email=VALUES(email),name=VALUES(name),phone=IF(VALUES(phone)<>'',VALUES(phone),phone),last_campaign_id=VALUES(last_campaign_id),last_engaged_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()");
    $stmt->execute([$public,$merchantId,$crmContactId,$email,$email,$name,$phone,$campaignId,$campaignId]);$find=$pdo->prepare('SELECT * FROM campaign_customers_v100 WHERE merchant_account_id=? AND email_normalized=? LIMIT 1');$find->execute([$merchantId,$email]);
    return $find->fetch()?:throw new RuntimeException('Campaign customer could not be loaded.');
}

function campaigns_rewards_public_claim_rate_limit_v100(string $campaignPublicId): void
{
    if(session_status()!==PHP_SESSION_ACTIVE)return;$now=time();$key='campaign_claim_rate_v100';$rows=is_array($_SESSION[$key]??null)?$_SESSION[$key]:[];
    $rows=array_values(array_filter($rows,static fn($row)=>is_array($row)&&(int)($row['at']??0)>$now-VP3_CAMPAIGNS_REWARDS_CLAIM_WINDOW_SECONDS_V100));$count=0;
    foreach($rows as $row)if(hash_equals((string)($row['campaign']??''),$campaignPublicId))$count++;if($count>=VP3_CAMPAIGNS_REWARDS_MAX_CLAIMS_PER_SESSION_V100)throw new RuntimeException('Too many reward requests. Try again shortly.');
    $rows[]=['campaign'=>$campaignPublicId,'at'=>$now];$_SESSION[$key]=array_slice($rows,-50);
}

function campaigns_rewards_issue_claim_v100(PDO $pdo,array $campaign,string $rewardPublicId,string $name,string $email,string $phone=''): array
{
    $campaignId=(int)($campaign['id']??0);$merchantId=(int)($campaign['merchant_account_id']??0);if($campaignId<1||$merchantId<1||($campaign['status']??'')!=='active')throw new RuntimeException('This campaign is not accepting claims.');
    campaigns_rewards_public_claim_rate_limit_v100((string)$campaign['public_id']);$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM campaign_rewards_v100 WHERE public_id=? AND campaign_id=? AND status='active' AND (starts_at IS NULL OR starts_at<=UTC_TIMESTAMP()) AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP()) LIMIT 1 FOR UPDATE");$stmt->execute([$rewardPublicId,$campaignId]);$reward=$stmt->fetch();if(!$reward)throw new RuntimeException('That reward is not currently available.');
        $customer=campaigns_rewards_customer_upsert_v100($pdo,$campaign,$name,$email,$phone);$activeStatuses="'issued','validated','redeemed'";
        $countStmt=$pdo->prepare("SELECT COUNT(*) FROM campaign_reward_claims_v100 WHERE reward_id=? AND status IN ({$activeStatuses})");$countStmt->execute([(int)$reward['id']]);$issued=(int)$countStmt->fetchColumn();$inventory=(int)$reward['inventory_limit'];if($inventory>0&&$issued>=$inventory)throw new RuntimeException('This reward has reached its claim limit.');
        $perStmt=$pdo->prepare("SELECT COUNT(*) FROM campaign_reward_claims_v100 WHERE reward_id=? AND customer_id=? AND status IN ({$activeStatuses})");$perStmt->execute([(int)$reward['id'],(int)$customer['id']]);if((int)$perStmt->fetchColumn()>=(int)$reward['per_customer_limit'])throw new RuntimeException('You have already reached the claim limit for this reward.');
        $code='';for($i=0;$i<20;$i++){$candidate=campaigns_rewards_claim_code_v100();$check=$pdo->prepare('SELECT 1 FROM campaign_reward_claims_v100 WHERE claim_code=? LIMIT 1');$check->execute([$candidate]);if(!$check->fetchColumn()){$code=$candidate;break;}}
        if($code==='')throw new RuntimeException('A unique claim code could not be generated.');$public=campaigns_rewards_uuid_v100();
        $insert=$pdo->prepare("INSERT INTO campaign_reward_claims_v100 (public_id,merchant_account_id,campaign_id,reward_id,customer_id,claim_code,status,issued_at) VALUES (?,?,?,?,?,?,'issued',UTC_TIMESTAMP())");$insert->execute([$public,$merchantId,$campaignId,(int)$reward['id'],(int)$customer['id'],$code]);$claimId=(int)$pdo->lastInsertId();
        campaigns_rewards_record_activity_v100($pdo,$merchantId,'claim.created',['campaign_id'=>$campaignId,'reward_id'=>(int)$reward['id'],'claim_id'=>$claimId,'customer_id'=>(int)$customer['id']],[]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $claim=campaigns_rewards_claim_by_code_v100($pdo,$code)?:throw new RuntimeException('Reward claim could not be loaded.');$owner=(int)$campaign['owner_user_id'];
    $refs=[campaigns_rewards_ref_v100('campaign',$campaign['public_id'],'public'),campaigns_rewards_ref_v100('reward',$reward['public_id'],'public'),campaigns_rewards_ref_v100('reward_claim',$claim['public_id'],'public'),campaigns_rewards_ref_v100('campaign_customer',$customer['public_id'],'workspace')];
    campaigns_rewards_emit_v100($pdo,$owner,'campaign.customer_engaged',$refs,['campaign_id'=>$campaign['public_id'],'reward_id'=>$reward['public_id'],'customer_id'=>$customer['public_id']],['external_event_id'=>'claim:'.$public.':engaged']);
    campaigns_rewards_emit_v100($pdo,$owner,'reward.issued',$refs,['campaign_id'=>$campaign['public_id'],'reward_id'=>$reward['public_id'],'claim_id'=>$claim['public_id']],['external_event_id'=>'claim:'.$public.':issued']);
    campaigns_rewards_emit_v100($pdo,$owner,'claim.created',$refs,['campaign_id'=>$campaign['public_id'],'reward_id'=>$reward['public_id'],'claim_id'=>$claim['public_id']],['external_event_id'=>'claim:'.$public.':created']);
    return $claim;
}

function campaigns_rewards_claim_by_code_v100(PDO $pdo,string $code): ?array
{
    $code=strtoupper(preg_replace('/[^A-Z0-9]/i','',trim($code))??'');if($code==='')return null;
    $stmt=$pdo->prepare("SELECT cl.*,r.public_id reward_public_id,r.title reward_title,r.value_label,r.description reward_description,c.public_id campaign_public_id,c.slug campaign_slug,c.title campaign_title,m.id merchant_account_id,m.public_id merchant_public_id,m.owner_user_id,m.name merchant_name,m.slug merchant_slug
      FROM campaign_reward_claims_v100 cl INNER JOIN campaign_rewards_v100 r ON r.id=cl.reward_id INNER JOIN campaigns_v100 c ON c.id=cl.campaign_id INNER JOIN campaign_merchant_accounts_v100 m ON m.id=cl.merchant_account_id WHERE cl.claim_code=? LIMIT 1");
    $stmt->execute([$code]);$row=$stmt->fetch();if(!$row||!campaigns_rewards_owner_plugin_enabled_v100($pdo,(int)$row['owner_user_id']))return null;return $row;
}

function campaigns_rewards_validate_claim_v100(PDO $pdo,string $code,int $actorUserId): array
{
    $claim=campaigns_rewards_claim_by_code_v100($pdo,$code)?:throw new RuntimeException('Claim code not found.');if(!campaigns_rewards_can_manage_merchant_v100($pdo,(int)$claim['merchant_account_id'],$actorUserId))throw new RuntimeException('Merchant admin access is required.');
    if(!in_array((string)$claim['status'],['issued','validated'],true))throw new RuntimeException('This claim cannot be validated in its current state.');
    if((string)$claim['status']==='issued')$pdo->prepare("UPDATE campaign_reward_claims_v100 SET status='validated',validated_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='issued'")->execute([(int)$claim['id']]);
    $saved=campaigns_rewards_claim_by_code_v100($pdo,$code)?:throw new RuntimeException('Claim could not be reloaded.');$merchant=campaigns_rewards_merchant_v100($pdo,(int)$saved['merchant_account_id'])?:throw new RuntimeException('Merchant account not found.');
    campaigns_rewards_record_activity_v100($pdo,(int)$merchant['id'],'claim.validated',['campaign_id'=>(int)$saved['campaign_id'],'reward_id'=>(int)$saved['reward_id'],'claim_id'=>(int)$saved['id'],'actor_user_id'=>$actorUserId],[]);
    campaigns_rewards_emit_v100($pdo,(int)$merchant['owner_user_id'],'claim.validated',[campaigns_rewards_ref_v100('reward_claim',$saved['public_id']),campaigns_rewards_ref_v100('campaign',$saved['campaign_public_id'])],['claim_id'=>$saved['public_id'],'campaign_id'=>$saved['campaign_public_id']]);return $saved;
}

function campaigns_rewards_redeem_claim_v100(PDO $pdo,string $code,int $actorUserId): array
{
    $preview=campaigns_rewards_claim_by_code_v100($pdo,$code)?:throw new RuntimeException('Claim code not found.');if(!campaigns_rewards_can_manage_merchant_v100($pdo,(int)$preview['merchant_account_id'],$actorUserId))throw new RuntimeException('Merchant admin access is required.');
    $pdo->beginTransaction();try{$stmt=$pdo->prepare('SELECT * FROM campaign_reward_claims_v100 WHERE id=? LIMIT 1 FOR UPDATE');$stmt->execute([(int)$preview['id']]);$locked=$stmt->fetch();if(!$locked||!in_array((string)$locked['status'],['issued','validated'],true))throw new RuntimeException('This claim is not redeemable.');
        $pdo->prepare("UPDATE campaign_reward_claims_v100 SET status='redeemed',validated_at=COALESCE(validated_at,UTC_TIMESTAMP()),redeemed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$locked['id']]);
        campaigns_rewards_record_activity_v100($pdo,(int)$locked['merchant_account_id'],'claim.completed',['campaign_id'=>(int)$locked['campaign_id'],'reward_id'=>(int)$locked['reward_id'],'claim_id'=>(int)$locked['id'],'customer_id'=>(int)$locked['customer_id'],'actor_user_id'=>$actorUserId],[]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $saved=campaigns_rewards_claim_by_code_v100($pdo,$code)?:throw new RuntimeException('Claim could not be reloaded.');$merchant=campaigns_rewards_merchant_v100($pdo,(int)$saved['merchant_account_id'])?:throw new RuntimeException('Merchant account not found.');
    $refs=[campaigns_rewards_ref_v100('campaign',$saved['campaign_public_id']),campaigns_rewards_ref_v100('reward',$saved['reward_public_id']),campaigns_rewards_ref_v100('reward_claim',$saved['public_id'])];
    foreach(['reward.claimed','claim.completed','campaign.conversion'] as $event)campaigns_rewards_emit_v100($pdo,(int)$merchant['owner_user_id'],$event,$refs,['campaign_id'=>$saved['campaign_public_id'],'reward_id'=>$saved['reward_public_id'],'claim_id'=>$saved['public_id'],'status'=>'redeemed'],['external_event_id'=>'claim:'.$saved['public_id'].':'.$event]);
    return $saved;
}

function campaigns_rewards_record_landing_view_v100(PDO $pdo,array $campaign,string $sessionKey): void
{
    $sessionKey=campaigns_rewards_text_v100($sessionKey,120);if($sessionKey==='')$sessionKey='anonymous';$bucket=gmdate('YmdH');$dedupe='campaign-view|'.$campaign['public_id'].'|'.hash('sha256',$sessionKey).'|'.$bucket;
    $activityId=campaigns_rewards_record_activity_v100($pdo,(int)$campaign['merchant_account_id'],'campaign.landing_viewed',['campaign_id'=>(int)$campaign['id']],[],$dedupe);if($activityId<1)return;
    campaigns_rewards_emit_v100($pdo,(int)$campaign['owner_user_id'],'campaign.landing_viewed',[campaigns_rewards_ref_v100('campaign',$campaign['public_id'],'public')],['campaign_id'=>$campaign['public_id']],['external_event_id'=>hash('sha256',$dedupe)]);
}

function campaigns_rewards_reporting_v100(PDO $pdo,int $merchantId,int $userId): array
{
    if(!campaigns_rewards_can_manage_merchant_v100($pdo,$merchantId,$userId))throw new RuntimeException('Merchant admin access is required.');
    $scalar=static function(PDO $pdo,string $sql,array $params): int{$s=$pdo->prepare($sql);$s->execute($params);return (int)$s->fetchColumn();};
    $active=$scalar($pdo,"SELECT COUNT(*) FROM campaigns_v100 WHERE merchant_account_id=? AND status='active'",[$merchantId]);$views=$scalar($pdo,"SELECT COUNT(*) FROM campaign_activity_v100 WHERE merchant_account_id=? AND event_type='campaign.landing_viewed'",[$merchantId]);
    $customers=$scalar($pdo,"SELECT COUNT(*) FROM campaign_customers_v100 WHERE merchant_account_id=?",[$merchantId]);$issued=$scalar($pdo,"SELECT COUNT(*) FROM campaign_reward_claims_v100 WHERE merchant_account_id=?",[$merchantId]);$redeemed=$scalar($pdo,"SELECT COUNT(*) FROM campaign_reward_claims_v100 WHERE merchant_account_id=? AND status='redeemed'",[$merchantId]);
    return ['active_campaigns'=>$active,'landing_views'=>$views,'customers'=>$customers,'claims_issued'=>$issued,'claims_redeemed'=>$redeemed,'redemption_rate'=>$issued>0?round(($redeemed/$issued)*100,1):0.0];
}

function campaigns_rewards_merchant_members_v100(PDO $pdo,int $merchantId): array
{
    $stmt=$pdo->prepare("SELECT mm.*,u.display_name,u.email,u.avatar_path,u.is_active FROM campaign_merchant_members_v100 mm INNER JOIN users u ON u.id=mm.user_id WHERE mm.merchant_account_id=? AND mm.member_status<>'removed' ORDER BY FIELD(mm.member_role,'owner','admin','member'),u.display_name,u.id");
    $stmt->execute([$merchantId]);return $stmt->fetchAll()?:[];
}

function campaigns_rewards_set_member_role_v100(PDO $pdo,int $merchantId,int $actorUserId,int $targetUserId,string $role): void
{
    if(!campaigns_rewards_can_own_merchant_v100($pdo,$merchantId,$actorUserId))throw new RuntimeException('Merchant owner access is required to change merchant roles.');
    if(!isset(campaigns_rewards_merchant_roles_v100()[$role]))throw new RuntimeException('Choose a valid merchant role.');if(!campaigns_rewards_user_row_v100($pdo,$targetUserId))throw new RuntimeException('VP3 user not found.');
    $existing=campaigns_rewards_merchant_member_v100($pdo,$merchantId,$targetUserId);if($existing&&$existing['member_role']==='owner'&&$role!=='owner'){$s=$pdo->prepare("SELECT COUNT(*) FROM campaign_merchant_members_v100 WHERE merchant_account_id=? AND member_role='owner' AND member_status='active'");$s->execute([$merchantId]);if((int)$s->fetchColumn()<=1)throw new RuntimeException('A merchant account must keep at least one active owner.');}
    $pdo->prepare("INSERT INTO campaign_merchant_members_v100 (merchant_account_id,user_id,member_role,member_status,source,created_by_user_id,joined_at) VALUES (?,?,?,'active','direct',?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE member_role=VALUES(member_role),member_status='active',source='direct',created_by_user_id=COALESCE(created_by_user_id,VALUES(created_by_user_id)),suspended_at=NULL,removed_at=NULL,updated_at=UTC_TIMESTAMP()")->execute([$merchantId,$targetUserId,$role,$actorUserId]);
}

function campaigns_rewards_team_scope_v100(PDO $pdo,int $ownerUserId,int $memberUserId): array
{
    if(!campaigns_rewards_schema_ready_v100($pdo))return ['team_category'=>'basic','merchant_account_id'=>0];
    $stmt=$pdo->prepare('SELECT * FROM campaign_team_scopes_v100 WHERE workspace_owner_user_id=? AND member_user_id=? LIMIT 1');$stmt->execute([$ownerUserId,$memberUserId]);$row=$stmt->fetch();
    return $row?:['workspace_owner_user_id'=>$ownerUserId,'member_user_id'=>$memberUserId,'team_category'=>'basic','merchant_account_id'=>0];
}

function campaigns_rewards_team_scopes_v100(PDO $pdo,int $ownerUserId): array
{
    if(!campaigns_rewards_schema_ready_v100($pdo))return [];$stmt=$pdo->prepare('SELECT * FROM campaign_team_scopes_v100 WHERE workspace_owner_user_id=?');$stmt->execute([$ownerUserId]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row)$out[(int)$row['member_user_id']]=$row;return $out;
}

function campaigns_rewards_assert_team_merchant_v100(PDO $pdo,int $ownerUserId,int $merchantId): array
{
    $merchant=campaigns_rewards_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Choose a valid merchant account.');if((int)$merchant['owner_user_id']!==$ownerUserId)throw new RuntimeException('Team merchant scope must belong to this workspace owner.');return $merchant;
}

function campaigns_rewards_sync_team_merchant_member_v100(PDO $pdo,int $ownerUserId,int $memberUserId,string $category,int $merchantId,?int $actorUserId=null): void
{
    $old=campaigns_rewards_team_scope_v100($pdo,$ownerUserId,$memberUserId);$oldMerchant=(int)($old['merchant_account_id']??0);
    if($oldMerchant>0&&($oldMerchant!==$merchantId||$category==='basic'))$pdo->prepare("UPDATE campaign_merchant_members_v100 SET member_status='removed',removed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE merchant_account_id=? AND user_id=? AND member_role='member' AND source='team_scope'")->execute([$oldMerchant,$memberUserId]);
    if(in_array($category,['merchant','both'],true)){campaigns_rewards_assert_team_merchant_v100($pdo,$ownerUserId,$merchantId);$pdo->prepare("INSERT INTO campaign_merchant_members_v100 (merchant_account_id,user_id,member_role,member_status,source,created_by_user_id,joined_at) VALUES (?,?,'member','active','team_scope',?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE member_status='active',suspended_at=NULL,removed_at=NULL,updated_at=UTC_TIMESTAMP()")->execute([$merchantId,$memberUserId,$actorUserId?:$ownerUserId]);}
}

function campaigns_rewards_set_team_scope_v100(PDO $pdo,int $ownerUserId,int $memberUserId,string $category,int $merchantId=0,?int $actorUserId=null): array
{
    if(!isset(campaigns_rewards_team_categories_v100()[$category]))throw new RuntimeException('Choose Basic Team, Merchant Team or Both.');
    if(in_array($category,['merchant','both'],true)){if($merchantId<1)throw new RuntimeException('Choose a merchant account for Merchant Team access.');campaigns_rewards_assert_team_merchant_v100($pdo,$ownerUserId,$merchantId);}else{$merchantId=0;}
    campaigns_rewards_sync_team_merchant_member_v100($pdo,$ownerUserId,$memberUserId,$category,$merchantId,$actorUserId);
    $pdo->prepare("INSERT INTO campaign_team_scopes_v100 (workspace_owner_user_id,member_user_id,team_category,merchant_account_id,created_by_user_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE team_category=VALUES(team_category),merchant_account_id=VALUES(merchant_account_id),created_by_user_id=VALUES(created_by_user_id),updated_at=UTC_TIMESTAMP()")->execute([$ownerUserId,$memberUserId,$category,$merchantId?:null,$actorUserId?:$ownerUserId]);
    return campaigns_rewards_team_scope_v100($pdo,$ownerUserId,$memberUserId);
}

function campaigns_rewards_set_invite_scope_v100(PDO $pdo,int $inviteId,int $ownerUserId,string $category,int $merchantId=0): void
{
    if($inviteId<1)return;if(!isset(campaigns_rewards_team_categories_v100()[$category]))throw new RuntimeException('Choose Basic Team, Merchant Team or Both.');
    if(in_array($category,['merchant','both'],true)){if($merchantId<1)throw new RuntimeException('Choose a merchant account for Merchant Team access.');campaigns_rewards_assert_team_merchant_v100($pdo,$ownerUserId,$merchantId);}else{$merchantId=0;}
    $pdo->prepare("INSERT INTO campaign_team_invite_scopes_v100 (invitation_id,workspace_owner_user_id,team_category,merchant_account_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE team_category=VALUES(team_category),merchant_account_id=VALUES(merchant_account_id),updated_at=UTC_TIMESTAMP()")->execute([$inviteId,$ownerUserId,$category,$merchantId?:null]);
}

function campaigns_rewards_pending_invite_scopes_v100(PDO $pdo,int $ownerUserId): array
{
    if(!campaigns_rewards_schema_ready_v100($pdo))return [];$stmt=$pdo->prepare('SELECT * FROM campaign_team_invite_scopes_v100 WHERE workspace_owner_user_id=?');$stmt->execute([$ownerUserId]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row)$out[(int)$row['invitation_id']]=$row;return $out;
}

function campaigns_rewards_apply_invite_scope_v100(PDO $pdo,int $inviteId,int $ownerUserId,int $memberUserId): void
{
    if(!campaigns_rewards_schema_ready_v100($pdo))return;$stmt=$pdo->prepare('SELECT * FROM campaign_team_invite_scopes_v100 WHERE invitation_id=? AND workspace_owner_user_id=? LIMIT 1');$stmt->execute([$inviteId,$ownerUserId]);$scope=$stmt->fetch();
    if($scope)campaigns_rewards_set_team_scope_v100($pdo,$ownerUserId,$memberUserId,(string)$scope['team_category'],(int)($scope['merchant_account_id']??0),$ownerUserId);$pdo->prepare('DELETE FROM campaign_team_invite_scopes_v100 WHERE invitation_id=?')->execute([$inviteId]);
}

function campaigns_rewards_clear_invite_scope_v100(PDO $pdo,int $inviteId): void
{
    if(campaigns_rewards_schema_ready_v100($pdo))$pdo->prepare('DELETE FROM campaign_team_invite_scopes_v100 WHERE invitation_id=?')->execute([$inviteId]);
}

function campaigns_rewards_team_membership_status_v100(PDO $pdo,int $ownerUserId,int $memberUserId,string $status): void
{
    if(!campaigns_rewards_schema_ready_v100($pdo))return;$scope=campaigns_rewards_team_scope_v100($pdo,$ownerUserId,$memberUserId);$merchantId=(int)($scope['merchant_account_id']??0);$category=(string)($scope['team_category']??'basic');
    if($merchantId<1||!in_array($category,['merchant','both'],true))return;
    if($status==='active')$pdo->prepare("UPDATE campaign_merchant_members_v100 SET member_status='active',suspended_at=NULL,removed_at=NULL,updated_at=UTC_TIMESTAMP() WHERE merchant_account_id=? AND user_id=? AND member_role='member' AND source='team_scope'")->execute([$merchantId,$memberUserId]);
    elseif($status==='suspended')$pdo->prepare("UPDATE campaign_merchant_members_v100 SET member_status='suspended',suspended_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE merchant_account_id=? AND user_id=? AND member_role='member' AND source='team_scope'")->execute([$merchantId,$memberUserId]);
    elseif($status==='removed')$pdo->prepare("UPDATE campaign_merchant_members_v100 SET member_status='removed',removed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE merchant_account_id=? AND user_id=? AND member_role='member' AND source='team_scope'")->execute([$merchantId,$memberUserId]);
}


function campaigns_rewards_cognitive_object_v100(PDO $pdo,array $user,array $ref): ?array
{
    $uid=(int)($user['id']??0);$type=(string)($ref['type']??'');$public=(string)($ref['id']??'');
    if($uid<1||$public===''||!campaigns_rewards_schema_ready_v100($pdo))return null;
    $map=[
        'merchant_account'=>['table'=>'campaign_merchant_accounts_v100','merchant'=>'id'],
        'merchant_location'=>['table'=>'campaign_merchant_locations_v100','merchant'=>'merchant_account_id'],
        'campaign'=>['table'=>'campaigns_v100','merchant'=>'merchant_account_id'],
        'reward'=>['table'=>'campaign_rewards_v100','merchant'=>'merchant_account_id'],
        'reward_claim'=>['table'=>'campaign_reward_claims_v100','merchant'=>'merchant_account_id'],
        'campaign_customer'=>['table'=>'campaign_customers_v100','merchant'=>'merchant_account_id'],
    ];
    $cfg=$map[$type]??null;if(!$cfg)return null;
    $stmt=$pdo->prepare("SELECT * FROM {$cfg['table']} WHERE public_id=? LIMIT 1");$stmt->execute([$public]);$row=$stmt->fetch();
    if(!$row)return null;$merchantId=$cfg['merchant']==='id'?(int)$row['id']:(int)$row[$cfg['merchant']];
    if(campaigns_rewards_member_role_v100($pdo,$merchantId,$uid)==='')return null;
    $row['_merchant_account_id']=$merchantId;return $row;
}

function campaigns_rewards_cognitive_permission_v100(PDO $pdo,array $user,string $agentNamespace,array $ref,string $operation='read'): bool
{
    return $operation==='read'&&campaigns_rewards_cognitive_object_v100($pdo,$user,$ref)!==null;
}

function campaigns_rewards_cognitive_context_v100(PDO $pdo,array $user,string $agentNamespace,array $ref,array $options=[]): array
{
    $row=campaigns_rewards_cognitive_object_v100($pdo,$user,$ref);
    if(!$row)throw new RuntimeException('Campaigns & Rewards object is unavailable.');
    $type=(string)$ref['type'];
    $allowed=match($type){
        'merchant_account'=>['public_id','name','slug','description','website_url','status','profile_user_id','created_at','updated_at'],
        'merchant_location'=>['public_id','merchant_account_id','name','city','region','country','is_primary','status','created_at','updated_at'],
        'campaign'=>['public_id','merchant_account_id','location_id','slug','title','subtitle','description','cta_label','terms','status','profile_visible','starts_at','ends_at','published_at','created_at','updated_at'],
        'reward'=>['public_id','merchant_account_id','campaign_id','title','description','reward_type','value_label','inventory_limit','per_customer_limit','status','starts_at','ends_at','created_at','updated_at'],
        'reward_claim'=>['public_id','merchant_account_id','campaign_id','reward_id','status','issued_at','validated_at','redeemed_at','refunded_at','expired_at','created_at','updated_at'],
        'campaign_customer'=>['public_id','merchant_account_id','crm_contact_id','first_campaign_id','last_campaign_id','first_engaged_at','last_engaged_at','created_at','updated_at'],
        default=>[],
    };
    $safe=[];foreach($allowed as $key)if(array_key_exists($key,$row))$safe[$key]=$row[$key];
    return ['record'=>$safe,'authority'=>'campaigns_rewards_v100','build'=>VP3_CAMPAIGNS_REWARDS_V100];
}

function campaigns_rewards_cognitive_relationships_v100(PDO $pdo,array $user,string $agentNamespace,array $ref,array $options=[]): array
{
    $row=campaigns_rewards_cognitive_object_v100($pdo,$user,$ref);if(!$row)return [];
    $type=(string)$ref['type'];$edges=[];$scope=(string)($ref['scope']??'workspace');
    $add=static function(array &$edges,string $relation,string $type,mixed $id,string $scope): void{
        if((string)$id==='')return;$edges[]=['relation'=>$relation,'object_ref'=>campaigns_rewards_ref_v100($type,$id,$scope),'provenance'=>'campaigns_rewards_v100','confidence'=>1,'confirmation_state'=>'deterministic'];
    };
    if($type==='campaign'){
        $merchant=campaigns_rewards_merchant_v100($pdo,(int)$row['merchant_account_id']);if($merchant)$add($edges,'owned_by','merchant_account',$merchant['public_id'],$scope);
        $stmt=$pdo->prepare('SELECT public_id FROM campaign_rewards_v100 WHERE campaign_id=? AND status="active" ORDER BY id LIMIT 20');$stmt->execute([(int)$row['id']]);
        foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $rewardPublic)$add($edges,'offers','reward',$rewardPublic,$scope);
    }elseif($type==='reward'){
        $campaign=campaigns_rewards_campaign_v100($pdo,(int)$row['campaign_id']);if($campaign)$add($edges,'belongs_to','campaign',$campaign['public_id'],$scope);
    }elseif($type==='reward_claim'){
        $stmt=$pdo->prepare('SELECT c.public_id campaign_public,r.public_id reward_public FROM campaigns_v100 c INNER JOIN campaign_rewards_v100 r ON r.campaign_id=c.id WHERE c.id=? AND r.id=? LIMIT 1');
        $stmt->execute([(int)$row['campaign_id'],(int)$row['reward_id']]);$related=$stmt->fetch();
        if($related){$add($edges,'claims_from','campaign',$related['campaign_public'],$scope);$add($edges,'claims','reward',$related['reward_public'],$scope);}
    }elseif($type==='merchant_location'){
        $merchant=campaigns_rewards_merchant_v100($pdo,(int)$row['merchant_account_id']);if($merchant)$add($edges,'location_of','merchant_account',$merchant['public_id'],$scope);
    }
    return array_slice($edges,0,30);
}

function campaigns_rewards_register_cognitive_module_v100(): void
{
    if(!function_exists('vp3_cognitive_register_module_v500')||!function_exists('vp3_cognitive_registry_storage_v500'))return;
    $registry=vp3_cognitive_registry_storage_v500();if(isset($registry['modules']['campaigns_rewards']))return;
    $contract=function_exists('vp3_cognitive_campaigns_rewards_contract_v2600')?vp3_cognitive_campaigns_rewards_contract_v2600():[];
    $objects=(array)($contract['objects']??['merchant_account','merchant_location','campaign','reward','reward_claim','campaign_customer']);
    $events=(array)($contract['events']??[]);
    vp3_cognitive_register_module_v500([
        'module'=>'campaigns_rewards',
        'version'=>'campaigns-rewards-v100',
        'objects'=>$objects,
        'events'=>$events,
        'permission_resolver'=>'campaigns_rewards_cognitive_permission_v100',
        'context_provider'=>'campaigns_rewards_cognitive_context_v100',
        'relationship_provider'=>'campaigns_rewards_cognitive_relationships_v100',
        'cards'=>[],
        'tools'=>[],
        'freshness_policy'=>['default_seconds'=>60],
        'sensitivity_policy'=>[
            'owner_scoped'=>true,
            'merchant_member_scoped'=>true,
            'customer_pii_exposed'=>false,
            'claim_codes_exposed'=>false,
            'raw_activity_metadata_exposed'=>false,
        ],
        'surfaces'=>['memory','brief','away_digest','notification','ask_user','chat_response'],
        'voice_safe'=>false,
    ]);
}

campaigns_rewards_register_cognitive_module_v100();
