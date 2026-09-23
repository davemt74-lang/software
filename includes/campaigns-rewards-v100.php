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
    return [
        'owner'=>'Owner','administrator'=>'Administrator','manager'=>'Manager','marketing'=>'Marketing',
        'customer_service'=>'Customer Service','claim_processor'=>'Claim Processor',
        'fulfillment'=>'Fulfillment','analyst'=>'Analyst','custom'=>'Custom',
    ];
}

function campaigns_rewards_schema_ready_v100(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    foreach([
        'campaign_merchant_accounts_v100','campaign_merchant_members_v100','campaign_merchant_locations_v100',
        'campaigns_v100','campaign_rewards_v100','campaign_customers_v100','campaign_reward_claims_v100',
        'campaign_activity_v100'
    ] as $table)if(!table_exists($table))return false;
    if(!table_exists('workspace_team_access_v1')||!table_exists('workspace_team_invitation_scopes_v1'))return false;
    if(function_exists('column_exists')&&!column_exists('campaign_merchant_members_v100','team_scope_active'))return false;
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
      team_scope_active TINYINT(1) NOT NULL DEFAULT 0,
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

    if(function_exists('column_exists')&&!column_exists('campaign_merchant_members_v100','team_scope_active')){
        $pdo->exec("ALTER TABLE campaign_merchant_members_v100 ADD COLUMN team_scope_active TINYINT(1) NOT NULL DEFAULT 0 AFTER source");
    }

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
    if($merchantId<1)return null;
    if(function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo)){
        $stmt=$pdo->prepare("SELECT m.*,m.owner_user_id profile_user_id,mp.description,mp.website_url,
          '' contact_email,'' contact_phone FROM merchant_accounts m
          LEFT JOIN merchant_profiles mp ON mp.merchant_id=m.id WHERE m.id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
        $stmt->execute([$merchantId]);$row=$stmt->fetch();return $row?:null;
    }
    if(!campaigns_rewards_schema_ready_v100($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM campaign_merchant_accounts_v100 WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$merchantId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_merchant_member_v100(PDO $pdo,int $merchantId,int $userId,bool $forUpdate=false): ?array
{
    if($merchantId<1||$userId<1)return null;
    if(function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo)){
        $stmt=$pdo->prepare("SELECT mm.*,mr.role_key member_role,mm.status member_status,
          IF(mm.is_owner=1,'direct','projected') source
          FROM merchant_members mm INNER JOIN merchant_roles mr ON mr.id=mm.role_id
          WHERE mm.merchant_id=? AND mm.user_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
        $stmt->execute([$merchantId,$userId]);$row=$stmt->fetch();
        if($row){
            $sources=$pdo->prepare("SELECT source_type,role_key,status FROM merchant_member_access_sources WHERE merchant_id=? AND user_id=? ORDER BY FIELD(source_type,'owner','direct','team'),id");
            $sources->execute([$merchantId,$userId]);$row['access_sources']=$sources->fetchAll()?:[];
        }
        return $row?:null;
    }
    if(!campaigns_rewards_schema_ready_v100($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM campaign_merchant_members_v100 WHERE merchant_account_id=? AND user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$merchantId,$userId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_member_role_v100(PDO $pdo,int $merchantId,int $userId): string
{
    $member=campaigns_rewards_merchant_member_v100($pdo,$merchantId,$userId);
    if(!$member||($member['member_status']??'')!=='active')return '';
    $role=(string)($member['member_role']??'');
    return $role==='administrator'?'admin':($role==='merchant_team'?'member':$role);
}

function campaigns_rewards_can_manage_merchant_v100(PDO $pdo,int $merchantId,int $userId): bool
{
    if(function_exists('campaigns_rewards_platform_can_v100')&&function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo)){
        return campaigns_rewards_platform_can_v100($pdo,$merchantId,$userId,'merchant.manage');
    }
    return in_array(campaigns_rewards_member_role_v100($pdo,$merchantId,$userId),['owner','admin'],true);
}

function campaigns_rewards_can_own_merchant_v100(PDO $pdo,int $merchantId,int $userId): bool
{
    $member=campaigns_rewards_merchant_member_v100($pdo,$merchantId,$userId);
    if($member&&array_key_exists('is_owner',$member))return ($member['member_status']??'')==='active'&&!empty($member['is_owner']);
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
    if(function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo)){
        campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'merchant.manage');
        $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant not found.');
        $name=campaigns_rewards_text_v100($input['name']??$merchant['name'],190);if($name==='')throw new RuntimeException('Merchant name is required.');
        $slug=campaigns_rewards_slug_v100((string)($input['slug']??$merchant['slug']),120);if($slug==='')$slug=(string)$merchant['slug'];
        $check=$pdo->prepare('SELECT 1 FROM merchant_accounts WHERE owner_user_id=? AND slug=? AND id<>? LIMIT 1');$check->execute([(int)$merchant['owner_user_id'],$slug,$merchantId]);if($check->fetchColumn())throw new RuntimeException('That Merchant slug is already in use.');
        $pdo->prepare("UPDATE merchant_accounts SET name=?,slug=?,business_name=?,timezone=?,currency=?,sandbox_mode=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
          ->execute([$name,$slug,campaigns_rewards_text_v100($input['business_name']??$name,190),campaigns_rewards_text_v100($input['timezone']??$merchant['timezone'],80),strtoupper(substr((string)($input['currency']??$merchant['currency']),0,3)),!empty($input['sandbox_mode'])?1:0,$merchantId]);
        $contact=['email'=>strtolower(trim((string)($input['contact_email']??''))),'phone'=>campaigns_rewards_text_v100($input['contact_phone']??'',80)];
        if($contact['email']!==''&&!filter_var($contact['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid Merchant email.');
        $pdo->prepare("INSERT INTO merchant_profiles (merchant_id,display_name,description,website_url,social_links_json,public_contact_json,branding_json)
          VALUES (?,?,?,?,? ,?,?)
          ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),description=VALUES(description),website_url=VALUES(website_url),public_contact_json=VALUES(public_contact_json),updated_at=UTC_TIMESTAMP()")
          ->execute([$merchantId,$name,mb_strimwidth(trim((string)($input['description']??'')),0,4000,'…'),campaigns_rewards_safe_url_v100((string)($input['website_url']??'')),campaigns_rewards_json_v100([]),campaigns_rewards_json_v100($contact),campaigns_rewards_json_v100([])]);
        $saved=campaigns_rewards_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant could not be reloaded.');
        campaigns_rewards_activity_event_v100($pdo,$merchantId,'merchant.updated',[],['summary'=>'Merchant updated','merchant_public_id'=>$saved['public_id']],!empty($saved['sandbox_mode'])?'sandbox':'production',$actorUserId);
        return $saved;
    }
    throw new RuntimeException('Run the VP3 database upgrade before changing Merchant data.');
}

function campaigns_rewards_accessible_merchants_v100(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);if($uid<1)return [];
    if(function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo)){
        $stmt=$pdo->prepare("SELECT m.*,mp.description,mp.website_url,mr.role_key access_role,mm.status access_status
          FROM merchant_members mm INNER JOIN merchant_accounts m ON m.id=mm.merchant_id
          INNER JOIN merchant_roles mr ON mr.id=mm.role_id LEFT JOIN merchant_profiles mp ON mp.merchant_id=m.id
          WHERE mm.user_id=? AND mm.status='active' AND m.status='active' ORDER BY m.name,m.id");
        $stmt->execute([$uid]);return $stmt->fetchAll()?:[];
    }
    if(!campaigns_rewards_schema_ready_v100($pdo))return [];
    $stmt=$pdo->prepare("SELECT m.*,COALESCE(mm.member_role,IF(m.owner_user_id=?,'owner','')) access_role,
      COALESCE(mm.member_status,IF(m.owner_user_id=?,'active','')) access_status
      FROM campaign_merchant_accounts_v100 m
      LEFT JOIN campaign_merchant_members_v100 mm ON mm.merchant_account_id=m.id AND mm.user_id=?
      WHERE m.status='active' AND (m.owner_user_id=? OR (mm.user_id=? AND mm.member_status='active'))
      ORDER BY m.name,m.id");
    $stmt->execute([$uid,$uid,$uid,$uid,$uid]);return $stmt->fetchAll()?:[];
}

function campaigns_rewards_owned_merchants_v100(PDO $pdo,int $ownerUserId): array
{
    if($ownerUserId<1)return [];
    if(function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo)){
        $stmt=$pdo->prepare("SELECT m.*,mp.description,mp.website_url FROM merchant_accounts m
          LEFT JOIN merchant_profiles mp ON mp.merchant_id=m.id
          WHERE m.owner_user_id=? AND m.status='active' ORDER BY m.name,m.id");
        $stmt->execute([$ownerUserId]);return $stmt->fetchAll()?:[];
    }
    if(!campaigns_rewards_schema_ready_v100($pdo))return [];
    $stmt=$pdo->prepare("SELECT * FROM campaign_merchant_accounts_v100 WHERE owner_user_id=? AND status='active' ORDER BY name,id");
    $stmt->execute([$ownerUserId]);return $stmt->fetchAll()?:[];
}

function campaigns_rewards_user_has_access_v100(PDO $pdo,array $user): bool
{
    if(!campaigns_rewards_schema_ready_v100($pdo))return false;
    return campaigns_rewards_enabled_v100($user,$pdo)||campaigns_rewards_accessible_merchants_v100($pdo,$user)!==[];
}

function campaigns_rewards_locations_v100(PDO $pdo,int $merchantId): array
{
    if(function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo)){
        $stmt=$pdo->prepare("SELECT ml.*,ml.merchant_id merchant_account_id,ml.address1 address_line1,ml.address2 address_line2,
          IF(ml.is_active=1,'active','inactive') status FROM merchant_locations ml WHERE ml.merchant_id=? AND ml.is_active=1
          ORDER BY ml.is_primary DESC,ml.name,ml.id");
        $stmt->execute([$merchantId]);return $stmt->fetchAll()?:[];
    }
    return [];
}

function campaigns_rewards_save_location_v100(PDO $pdo,int $merchantId,int $actorUserId,array $input,int $locationId=0): array
{
    if(function_exists('campaigns_rewards_save_platform_location_v100'))return campaigns_rewards_save_platform_location_v100($pdo,$merchantId,$actorUserId,$input,$locationId);
    throw new RuntimeException('Campaigns & Rewards V1 Location runtime is unavailable.');
}

function campaigns_rewards_campaign_v100(PDO $pdo,int $campaignId,bool $forUpdate=false): ?array
{
    if($campaignId<1||!function_exists('campaigns_rewards_campaign_platform_v100'))return null;
    $row=campaigns_rewards_campaign_platform_v100($pdo,$campaignId,$forUpdate);if(!$row)return null;
    $lp=$pdo->prepare('SELECT * FROM campaign_landing_pages WHERE campaign_id=? LIMIT 1');$lp->execute([$campaignId]);$landing=$lp->fetch()?:[];
    $bind=$pdo->prepare("SELECT target_id FROM campaigns_rewards_object_bindings WHERE merchant_id=? AND subject_type='campaign' AND subject_id=? AND purpose='location' ORDER BY id DESC LIMIT 1");
    $bind->execute([(int)$row['merchant_id'],$campaignId]);$locationId=(int)$bind->fetchColumn();
    $terms='';$termsJson=json_decode((string)($landing['terms_json']??''),true);if(is_array($termsJson))$terms=(string)($termsJson['text']??'');
    return $row+[
      'merchant_account_id'=>(int)$row['merchant_id'],'title'=>(string)$row['name'],'subtitle'=>(string)($landing['subheadline']??''),
      'cta_label'=>(string)($landing['cta_label']??'Claim reward'),'terms'=>$terms,'profile_visible'=>(($landing['visibility']??'')==='profile_public'?1:0),
      'published_at'=>$landing['published_at']??null,'location_id'=>$locationId?:null,
    ];
}

function campaigns_rewards_campaigns_v100(PDO $pdo,int $merchantId,bool $activeOnly=false): array
{
    $sql="SELECT c.id FROM campaigns c WHERE c.merchant_id=?";
    if($activeOnly)$sql.=" AND c.status='active' AND c.environment='production' AND (c.starts_at IS NULL OR c.starts_at<=UTC_TIMESTAMP()) AND (c.ends_at IS NULL OR c.ends_at>UTC_TIMESTAMP())";
    $sql.=" ORDER BY c.updated_at DESC,c.id DESC";
    $stmt=$pdo->prepare($sql);$stmt->execute([$merchantId]);$rows=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $id){
        $row=campaigns_rewards_campaign_v100($pdo,(int)$id);if(!$row)continue;
        $row['location_name']='';
        if(!empty($row['location_id'])){$q=$pdo->prepare('SELECT name FROM merchant_locations WHERE id=? AND merchant_id=? LIMIT 1');$q->execute([(int)$row['location_id'],$merchantId]);$row['location_name']=(string)($q->fetchColumn()?:'');}
        $rows[]=$row;
    }
    return $rows;
}

function campaigns_rewards_save_campaign_v100(PDO $pdo,int $merchantId,int $actorUserId,array $input,int $campaignId=0): array
{
    if(!function_exists('campaigns_rewards_create_campaign_v100'))throw new RuntimeException('Campaigns & Rewards V1 runtime is unavailable.');
    if($campaignId<1){
        $created=campaigns_rewards_create_campaign_v100($pdo,$merchantId,$actorUserId,[
            'name'=>$input['title']??'','slug'=>$input['slug']??'','description'=>$input['description']??'',
            'campaign_type'=>$input['campaign_type']??'signup','objective'=>$input['objective']??'',
            'starts_at'=>$input['starts_at']??'','ends_at'=>$input['ends_at']??'','visibility'=>!empty($input['profile_visible'])?'profile_public':'unlisted',
            'subheadline'=>$input['subtitle']??'','cta_label'=>$input['cta_label']??'Claim reward',
        ]);
        $campaignId=(int)$created['id'];
    }else{
        $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
        if((int)$campaign['merchant_id']!==$merchantId)throw new RuntimeException('Campaign not found.');
        campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.edit');
        $title=campaigns_rewards_text_v100($input['title']??$campaign['name'],190);if($title==='')throw new RuntimeException('Campaign title is required.');
        $slug=campaigns_rewards_slug_v100((string)($input['slug']??$campaign['slug']),120)?:$campaign['slug'];
        $check=$pdo->prepare('SELECT 1 FROM campaigns WHERE merchant_id=? AND slug=? AND id<>? LIMIT 1');$check->execute([$merchantId,$slug,$campaignId]);if($check->fetchColumn())throw new RuntimeException('That Campaign slug is already in use.');
        $starts=campaigns_rewards_datetime_v100((string)($input['starts_at']??$campaign['starts_at']??''));$ends=campaigns_rewards_datetime_v100((string)($input['ends_at']??$campaign['ends_at']??''));
        if($starts&&$ends&&strtotime($ends)<=strtotime($starts))throw new RuntimeException('Campaign end must be after its start.');
        $typeKey=campaigns_rewards_slug_v100((string)($input['campaign_type']??$campaign['campaign_type_key']??'signup'),80)?:'signup';
        $type=campaigns_rewards_campaign_type_v100($pdo,$merchantId,$typeKey)?:throw new RuntimeException('Campaign Type is unavailable.');
        $pdo->prepare("UPDATE campaigns SET campaign_type_id=?,name=?,slug=?,description=?,objective=?,starts_at=?,ends_at=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND merchant_id=?")
          ->execute([(int)$type['id'],$title,$slug,mb_strimwidth(trim((string)($input['description']??$campaign['description']??'')),0,8000,'…'),campaigns_rewards_text_v100($input['objective']??$campaign['objective']??'',500),$starts,$ends,$campaignId,$merchantId]);
        $pdo->prepare("UPDATE campaign_landing_pages SET slug=?,visibility=?,headline=?,subheadline=?,cta_label=?,terms_json=?,updated_at=UTC_TIMESTAMP() WHERE campaign_id=?")
          ->execute([$slug,!empty($input['profile_visible'])?'profile_public':'unlisted',$title,campaigns_rewards_text_v100($input['subtitle']??'',500),campaigns_rewards_text_v100($input['cta_label']??'Claim reward',80),campaigns_rewards_json_v100(['text'=>mb_strimwidth(trim((string)($input['terms']??'')),0,12000,'…')]),$campaignId]);
        campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.updated',['campaign_id'=>$campaignId],['summary'=>'Campaign updated','campaign_public_id'=>$campaign['public_id'],'merchant_public_id'=>$campaign['merchant_public_id']],(string)$campaign['environment'],$actorUserId);
    }
    $campaignRow=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign could not be loaded.');
    $pdo->prepare("UPDATE campaign_landing_pages SET slug=?,visibility=?,headline=?,subheadline=?,cta_label=?,terms_json=?,updated_at=UTC_TIMESTAMP() WHERE campaign_id=?")
      ->execute([(string)$campaignRow['slug'],!empty($input['profile_visible'])?'profile_public':'unlisted',(string)$campaignRow['name'],
        campaigns_rewards_text_v100($input['subtitle']??'',500),campaigns_rewards_text_v100($input['cta_label']??'Claim reward',80),
        campaigns_rewards_json_v100(['text'=>mb_strimwidth(trim((string)($input['terms']??'')),0,12000,'…')]),$campaignId]);

    $locationId=max(0,(int)($input['location_id']??0));
    $pdo->prepare("DELETE FROM campaigns_rewards_object_bindings WHERE merchant_id=? AND subject_type='campaign' AND subject_id=? AND purpose='location'")->execute([$merchantId,$campaignId]);
    if($locationId>0){
        $q=$pdo->prepare('SELECT public_id FROM merchant_locations WHERE id=? AND merchant_id=? AND is_active=1 LIMIT 1');$q->execute([$locationId,$merchantId]);$locationPublic=$q->fetchColumn();
        if(!$locationPublic)throw new RuntimeException('Choose a valid Merchant Location.');
        $pdo->prepare("INSERT INTO campaigns_rewards_object_bindings (merchant_id,subject_type,subject_id,purpose,target_type,target_id,target_public_id,settings_json)
          VALUES (?,'campaign',?,'location','merchant_location',?,?, '{}')")->execute([$merchantId,$campaignId,(string)$locationId,(string)$locationPublic]);
    }
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId);
    $profileUserId=(int)($merchant['owner_user_id']??0);
    if($profileUserId>0)campaigns_rewards_publish_profile_v100($pdo,$campaignId,$actorUserId,$profileUserId,!empty($input['profile_visible']));
    return campaigns_rewards_campaign_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign could not be reloaded.');
}

function campaigns_rewards_set_campaign_status_v100(PDO $pdo,int $campaignId,int $actorUserId,string $status): array
{
    $map=['draft'=>'draft','active'=>'active','paused'=>'paused','ended'=>'completed','completed'=>'completed','archived'=>'archived','scheduled'=>'scheduled'];
    if(!isset($map[$status]))throw new RuntimeException('Choose a valid Campaign status.');
    return campaigns_rewards_set_campaign_lifecycle_v100($pdo,$campaignId,$actorUserId,$map[$status]);
}

function campaigns_rewards_reward_v100(PDO $pdo,int $rewardId,bool $forUpdate=false): ?array
{
    if($rewardId<1)return null;
    $sql="SELECT rp.*,rt.type_key reward_type FROM reward_products rp INNER JOIN reward_types rt ON rt.id=rp.reward_type_id WHERE rp.id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$rewardId]);$row=$stmt->fetch();if(!$row)return null;
    $settings=json_decode((string)($row['settings_json']??''),true);if(!is_array($settings))$settings=[];
    $row['title']=$row['name'];$row['value_label']=(string)($settings['value_label']??'');
    $row['inventory_limit']=(int)($settings['inventory_limit']??0);$row['per_customer_limit']=(int)($row['claim_limit']??1);
    $row['status']=!empty($row['is_active'])?'active':'inactive';
    return $row;
}

function campaigns_rewards_rewards_v100(PDO $pdo,int $campaignId,bool $publicOnly=false): array
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId);if(!$campaign)return [];
    $sql="SELECT DISTINCT rp.id FROM campaign_reward_sets rs
      INNER JOIN campaign_reward_set_items i ON i.reward_set_id=rs.id
      INNER JOIN reward_products rp ON rp.id=i.reward_product_id
      WHERE rs.campaign_id=?";
    if($publicOnly)$sql.=" AND rp.is_active=1";
    $sql.=" ORDER BY rp.id";
    $stmt=$pdo->prepare($sql);$stmt->execute([$campaignId]);$rows=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $id){$row=campaigns_rewards_reward_v100($pdo,(int)$id);if($row){$row['campaign_id']=$campaignId;$row['merchant_account_id']=(int)$campaign['merchant_id'];$rows[]=$row;}}
    return $rows;
}

function campaigns_rewards_save_reward_v100(PDO $pdo,int $campaignId,int $actorUserId,array $input,int $rewardId=0): array
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    $legacyType=(string)($input['reward_type']??'gift');
    $typeMap=['offer'=>'custom','gift'=>'free_product','discount'=>'percentage_discount','access'=>'membership_access','recognition'=>'custom'];
    $type=$typeMap[$legacyType]??$legacyType;$inventory=max(0,(int)($input['inventory_limit']??0));
    $settings=['value_label'=>campaigns_rewards_text_v100($input['value_label']??'',120),'inventory_limit'=>$inventory];
    $reward=campaigns_rewards_save_reward_product_v100($pdo,(int)$campaign['merchant_id'],$actorUserId,[
        'name'=>$input['title']??'','description'=>$input['description']??'','reward_type'=>$type,'claim_limit'=>max(1,(int)($input['per_customer_limit']??1)),
        'inventory_mode'=>$inventory>0?'tracked':'none','pickup_enabled'=>1,'is_active'=>(string)($input['status']??'active')==='active',
        'settings'=>$settings,'currency'=>$campaign['budget_currency']??'USD',
    ],$rewardId);
    campaigns_rewards_attach_reward_v100($pdo,$campaignId,(int)$reward['id'],$actorUserId,'fixed',1);
    if($inventory>0){
        $q=$pdo->prepare("SELECT id,on_hand,reserved FROM reward_inventory_balances WHERE reward_product_id=? AND variant_id=0 AND location_id=0 LIMIT 1 FOR UPDATE");
        $q->execute([(int)$reward['id']]);$balance=$q->fetch();$old=$balance?(int)$balance['on_hand']:0;
        if($balance){
            $reserved=min((int)$balance['reserved'],$inventory);
            $pdo->prepare("UPDATE reward_inventory_balances SET on_hand=?,reserved=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$inventory,$reserved,(int)$balance['id']]);
        }else{
            $pdo->prepare("INSERT INTO reward_inventory_balances (reward_product_id,variant_id,location_id,on_hand,reserved) VALUES (?,0,0,?,0)")->execute([(int)$reward['id'],$inventory]);
        }
        $delta=$inventory-$old;if($delta!==0)$pdo->prepare("INSERT INTO reward_inventory_ledger (reward_product_id,variant_id,location_id,movement_type,quantity_delta,source_type,source_id,actor_user_id,metadata_json)
          VALUES (?,0,0,'adjust',?,'reward_product',?,?,?)")->execute([(int)$reward['id'],$delta,(string)$reward['id'],$actorUserId,campaigns_rewards_json_v100(['target_on_hand'=>$inventory])]);
    }
    return campaigns_rewards_reward_v100($pdo,(int)$reward['id'])?:throw new RuntimeException('Reward Product could not be reloaded.');
}
function campaigns_rewards_campaign_by_slug_v100(PDO $pdo,string $slug,bool $publicOnly=true): ?array
{
    $slug=campaigns_rewards_slug_v100($slug);if($slug==='')return null;
    $sql="SELECT c.*,c.merchant_id merchant_account_id,c.name title,m.public_id merchant_public_id,m.owner_user_id,m.owner_user_id profile_user_id,
      m.name merchant_name,m.slug merchant_slug,mp.description merchant_description,mp.website_url merchant_website_url,mp.public_contact_json,
      lp.subheadline subtitle,lp.cta_label,lp.terms_json,lp.visibility,lp.is_published,lp.published_at,
      u.display_name profile_display_name,u.avatar_path profile_avatar_path,p.username profile_username,p.is_public profile_is_public
      FROM campaigns c INNER JOIN merchant_accounts m ON m.id=c.merchant_id AND m.status='active'
      INNER JOIN campaign_landing_pages lp ON lp.campaign_id=c.id
      LEFT JOIN merchant_profiles mp ON mp.merchant_id=m.id LEFT JOIN users u ON u.id=m.owner_user_id
      LEFT JOIN user_profiles p ON p.user_id=m.owner_user_id WHERE c.slug=?";
    if($publicOnly)$sql.=" AND c.status='active' AND c.environment='production' AND lp.is_published=1 AND lp.visibility<>'private'
      AND (c.starts_at IS NULL OR c.starts_at<=UTC_TIMESTAMP()) AND (c.ends_at IS NULL OR c.ends_at>UTC_TIMESTAMP())";
    $stmt=$pdo->prepare($sql.' ORDER BY c.id DESC LIMIT 1');$stmt->execute([$slug]);$row=$stmt->fetch();if(!$row)return null;
    if(function_exists('campaigns_rewards_owner_plugin_enabled_v100')&&!campaigns_rewards_owner_plugin_enabled_v100($pdo,(int)$row['owner_user_id']))return null;
    $contact=json_decode((string)($row['public_contact_json']??''),true);if(!is_array($contact))$contact=[];
    $row['merchant_contact_email']=(string)($contact['email']??'');$row['merchant_contact_phone']=(string)($contact['phone']??'');
    $terms=json_decode((string)($row['terms_json']??''),true);$row['terms']=is_array($terms)?(string)($terms['text']??''):'';
    $bind=$pdo->prepare("SELECT ml.* FROM campaigns_rewards_object_bindings b INNER JOIN merchant_locations ml ON ml.id=CAST(b.target_id AS UNSIGNED)
      WHERE b.merchant_id=? AND b.subject_type='campaign' AND b.subject_id=? AND b.purpose='location' AND ml.merchant_id=b.merchant_id LIMIT 1");
    $bind->execute([(int)$row['merchant_id'],(int)$row['id']]);$loc=$bind->fetch()?:[];
    $row['location_id']=$loc['id']??null;$row['location_public_id']=$loc['public_id']??null;$row['location_name']=$loc['name']??'';
    $row['address_line1']=$loc['address1']??'';$row['address_line2']=$loc['address2']??'';$row['city']=$loc['city']??'';$row['region']=$loc['region']??'';$row['postal_code']=$loc['postal_code']??'';$row['country']=$loc['country']??'';$row['location_phone']=$loc['phone']??'';
    return $row;
}

function campaigns_rewards_profile_campaigns_v100(PDO $pdo,int $profileUserId,int $limit=24): array
{
    if($profileUserId<1)return [];$limit=max(1,min(50,$limit));
    if(!function_exists('campaigns_rewards_profile_campaigns_platform_v100'))return [];
    $rows=array_slice(campaigns_rewards_profile_campaigns_platform_v100($pdo,$profileUserId),0,$limit);$out=[];
    foreach($rows as $row){
        $row['merchant_account_id']=(int)$row['merchant_id'];$row['owner_user_id']=$profileUserId;
        $row['title']=$row['name'];$row['subtitle']=$row['subheadline']??'';$row['cta_label']=$row['cta_label']??'View campaign';
        $out[]=$row;
    }
    return $out;
}

function campaigns_rewards_campaign_url_v100(string $slug): string{return url('/campaign/'.rawurlencode(campaigns_rewards_slug_v100($slug)));}
function campaigns_rewards_claim_url_v100(string $code): string{return url('/campaign-claim/'.rawurlencode(strtoupper(trim($code))));}

function campaigns_rewards_customer_upsert_v100(PDO $pdo,array $campaign,string $name,string $email,string $phone=''): array
{
    $email=strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
    $merchantId=(int)($campaign['merchant_id']??$campaign['merchant_account_id']??0);if($merchantId<1)throw new RuntimeException('Merchant is unavailable.');
    $userStmt=$pdo->prepare('SELECT id FROM users WHERE email=? AND is_active=1 LIMIT 1');$userStmt->execute([$email]);$vp3UserId=(int)$userStmt->fetchColumn();
    $contact=campaigns_rewards_resolve_contact_v100($pdo,$merchantId,[
      'name'=>$name,'email'=>$email,'phone'=>$phone,'source'=>'campaigns_rewards','vp3_user_id'=>$vp3UserId,
    ]);
    $relationship=campaigns_rewards_ensure_merchant_relationship_v100($pdo,$merchantId,(int)$contact['id'],[
      'customer_status'=>'customer','acquisition_source'=>'campaign:'.(string)($campaign['public_id']??''),
    ]);
    return $contact+[
      'merchant_account_id'=>$merchantId,'crm_contact_id'=>(int)$contact['id'],'relationship_id'=>(int)$relationship['id'],
    ];
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
    $campaignId=(int)($campaign['id']??0);$merchantId=(int)($campaign['merchant_id']??$campaign['merchant_account_id']??0);
    if($campaignId<1||$merchantId<1)return;
    $sessionKey=campaigns_rewards_text_v100($sessionKey,500);if($sessionKey==='')$sessionKey='anonymous';
    $hash=hash('sha256',$sessionKey);$profileUserId=(int)($campaign['profile_user_id']??$campaign['owner_user_id']??0);
    $pdo->prepare("INSERT INTO campaign_public_sessions
      (campaign_id,merchant_id,profile_owner_user_id,session_hash,source,medium,referral_ref,first_seen_at,last_seen_at,view_count,metadata_json)
      VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1,'{}')
      ON DUPLICATE KEY UPDATE last_seen_at=UTC_TIMESTAMP(),view_count=view_count+1")
      ->execute([$campaignId,$merchantId,$profileUserId?:null,campaigns_rewards_text_v100($hash,64),campaigns_rewards_text_v100($_GET['utm_source']??'',120),campaigns_rewards_text_v100($_GET['utm_medium']??'',120),campaigns_rewards_text_v100($_GET['ref']??'',190)]);
    $q=$pdo->prepare('SELECT id FROM campaign_public_sessions WHERE campaign_id=? AND session_hash=? LIMIT 1');$q->execute([$campaignId,$hash]);$sessionId=(int)$q->fetchColumn();if($sessionId<1)return;
    $dedupe=$pdo->prepare("SELECT 1 FROM campaign_public_events WHERE campaign_id=? AND session_id=? AND event_type='landing_view' AND occurred_at>=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:00:00') LIMIT 1");
    $dedupe->execute([$campaignId,$sessionId]);if($dedupe->fetchColumn())return;
    $pdo->prepare("INSERT INTO campaign_public_events (campaign_id,session_id,event_type,occurred_at,metadata_json) VALUES (?,?,'landing_view',UTC_TIMESTAMP(),'{}')")->execute([$campaignId,$sessionId]);
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.signup_started',['campaign_id'=>$campaignId],[
      'summary'=>'Campaign landing viewed','merchant_public_id'=>$campaign['merchant_public_id']??'','campaign_public_id'=>$campaign['public_id']??'',
    ],(string)($campaign['environment']??'production'),null,'system');
}

function campaigns_rewards_reporting_v100(PDO $pdo,int $merchantId,int $userId): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$userId,'analytics.view');
    $scalar=static function(PDO $pdo,string $sql,array $params): int{$s=$pdo->prepare($sql);$s->execute($params);return (int)$s->fetchColumn();};
    $active=$scalar($pdo,"SELECT COUNT(*) FROM campaigns WHERE merchant_id=? AND status='active'",[$merchantId]);
    $views=$scalar($pdo,"SELECT COUNT(*) FROM campaign_public_events pe INNER JOIN campaigns c ON c.id=pe.campaign_id WHERE c.merchant_id=? AND pe.event_type='landing_view'",[$merchantId]);
    $customers=$scalar($pdo,"SELECT COUNT(*) FROM crm_merchant_relationships WHERE merchant_id=?",[$merchantId]);
    $issued=$scalar($pdo,"SELECT COUNT(*) FROM reward_issuances WHERE merchant_id=?",[$merchantId]);
    $redeemed=$scalar($pdo,"SELECT COUNT(*) FROM reward_claims WHERE merchant_id=? AND status='claimed'",[$merchantId]);
    $sent=$scalar($pdo,"SELECT COUNT(*) FROM reward_issuances WHERE merchant_id=? AND status IN ('sent','viewed','claimed')",[$merchantId]);
    $viewed=$scalar($pdo,"SELECT COUNT(*) FROM reward_issuances WHERE merchant_id=? AND status IN ('viewed','claimed')",[$merchantId]);
    return ['active_campaigns'=>$active,'landing_views'=>$views,'customers'=>$customers,'claims_issued'=>$issued,'claims_redeemed'=>$redeemed,
      'redemption_rate'=>$issued>0?round(($redeemed/$issued)*100,1):0.0,'rewards_sent'=>$sent,'rewards_viewed'=>$viewed];
}

function campaigns_rewards_merchant_members_v100(PDO $pdo,int $merchantId): array
{
    if(function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo)){
        $stmt=$pdo->prepare("SELECT mm.*,mr.role_key member_role,mr.name role_name,mm.status member_status,
          CASE WHEN mm.is_owner=1 THEN 'owner' WHEN mr.role_key='administrator' THEN 'admin' WHEN mr.role_key='merchant_team' THEN 'member' ELSE mr.role_key END effective_role,
          EXISTS(SELECT 1 FROM merchant_member_access_sources src WHERE src.merchant_id=mm.merchant_id AND src.user_id=mm.user_id AND src.source_type='team' AND src.status='active') team_scope_active,
          u.display_name,u.email,u.avatar_path,u.is_active
          FROM merchant_members mm INNER JOIN merchant_roles mr ON mr.id=mm.role_id INNER JOIN users u ON u.id=mm.user_id
          WHERE mm.merchant_id=? AND mm.status<>'removed'
          ORDER BY mm.is_owner DESC,FIELD(mr.role_key,'administrator','manager','marketing','customer_service','claim_processor','fulfillment','analyst','merchant_team','custom'),u.display_name,u.id");
        $stmt->execute([$merchantId]);return $stmt->fetchAll()?:[];
    }
    $stmt=$pdo->prepare("SELECT mm.*,CASE WHEN mm.member_status='active' AND mm.source='direct' THEN mm.member_role WHEN mm.team_scope_active=1 THEN 'member' ELSE mm.member_role END effective_role,u.display_name,u.email,u.avatar_path,u.is_active FROM campaign_merchant_members_v100 mm INNER JOIN users u ON u.id=mm.user_id WHERE mm.merchant_account_id=? AND (mm.member_status<>'removed' OR mm.team_scope_active=1) ORDER BY u.display_name,u.id");
    $stmt->execute([$merchantId]);return $stmt->fetchAll()?:[];
}

function campaigns_rewards_set_member_role_v100(PDO $pdo,int $merchantId,int $actorUserId,int $targetUserId,string $role): void
{
    if(function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo)){
        if(!campaigns_rewards_can_own_merchant_v100($pdo,$merchantId,$actorUserId))throw new RuntimeException('Merchant Owner access is required to change Merchant roles.');
        $canonical=$role==='admin'?'administrator':$role;
        if(!isset(campaigns_rewards_merchant_roles_v100()[$canonical]))throw new RuntimeException('Choose a valid Merchant role.');
        if($canonical==='owner')campaigns_rewards_grant_platform_member_v100($pdo,$merchantId,$actorUserId,$targetUserId,'owner',true);
        else campaigns_rewards_grant_platform_member_v100($pdo,$merchantId,$actorUserId,$targetUserId,$canonical,false);
        return;
    }
    throw new RuntimeException('Run the VP3 database upgrade before changing Merchant roles.');
}

function campaigns_rewards_team_scope_v100(PDO $pdo,int $ownerUserId,int $memberUserId): array
{
    $basic=function_exists('workspace_team_v350_basic_enabled_v1')?workspace_team_v350_basic_enabled_v1($pdo,$ownerUserId,$memberUserId):true;
    $merchantId=0;
    if(function_exists('campaigns_rewards_platform_schema_ready_v100')&&campaigns_rewards_platform_schema_ready_v100($pdo)){
        $stmt=$pdo->prepare("SELECT src.merchant_id
          FROM merchant_member_access_sources src INNER JOIN merchant_accounts m ON m.id=src.merchant_id
          WHERE m.owner_user_id=? AND src.user_id=? AND src.source_type='team' AND src.status='active' AND m.status='active'
          ORDER BY src.updated_at DESC,src.merchant_id DESC LIMIT 1");
        $stmt->execute([$ownerUserId,$memberUserId]);$merchantId=(int)$stmt->fetchColumn();
    }
    $category=$merchantId>0?($basic?'both':'merchant'):'basic';
    return ['workspace_owner_user_id'=>$ownerUserId,'member_user_id'=>$memberUserId,'team_category'=>$category,'merchant_account_id'=>$merchantId];
}

function campaigns_rewards_team_scopes_v100(PDO $pdo,int $ownerUserId): array
{
    if($ownerUserId<1)return [];
    $stmt=$pdo->prepare("SELECT wm.member_user_id,COALESCE(wa.basic_team_enabled,1) basic_team_enabled,
      (SELECT src.merchant_id FROM merchant_member_access_sources src INNER JOIN merchant_accounts m ON m.id=src.merchant_id
       WHERE src.user_id=wm.member_user_id AND src.source_type='team' AND src.status='active'
         AND m.owner_user_id=wm.workspace_owner_user_id AND m.status='active'
       ORDER BY src.updated_at DESC,src.merchant_id DESC LIMIT 1) merchant_account_id
      FROM workspace_memberships_v350 wm
      LEFT JOIN workspace_team_access_v1 wa ON wa.workspace_owner_user_id=wm.workspace_owner_user_id AND wa.member_user_id=wm.member_user_id
      WHERE wm.workspace_owner_user_id=?");
    $stmt->execute([$ownerUserId]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $merchantId=(int)($row['merchant_account_id']??0);$basic=!empty($row['basic_team_enabled']);
        $row['team_category']=$merchantId>0?($basic?'both':'merchant'):'basic';
        $out[(int)$row['member_user_id']]=$row;
    }
    return $out;
}

function campaigns_rewards_assert_team_merchant_v100(PDO $pdo,int $ownerUserId,int $merchantId): array
{
    $merchant=campaigns_rewards_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Choose a valid merchant account.');
    if((int)$merchant['owner_user_id']!==$ownerUserId)throw new RuntimeException('Team merchant scope must belong to this workspace owner.');
    return $merchant;
}

function campaigns_rewards_sync_team_merchant_member_v100(PDO $pdo,int $ownerUserId,int $memberUserId,string $category,int $merchantId,?int $actorUserId=null): void
{
    if(!function_exists('campaigns_rewards_set_access_source_v100'))return;
    $old=campaigns_rewards_team_scope_v100($pdo,$ownerUserId,$memberUserId);$oldMerchant=(int)($old['merchant_account_id']??0);
    if($oldMerchant>0&&($oldMerchant!==$merchantId||$category==='basic')){
        campaigns_rewards_set_access_source_v100($pdo,$oldMerchant,$memberUserId,'team','workspace:'.$ownerUserId,'merchant_team','removed',$actorUserId?:$ownerUserId);
    }
    if(in_array($category,['merchant','both'],true)){
        campaigns_rewards_assert_team_merchant_v100($pdo,$ownerUserId,$merchantId);
        campaigns_rewards_set_access_source_v100($pdo,$merchantId,$memberUserId,'team','workspace:'.$ownerUserId,'merchant_team','active',$actorUserId?:$ownerUserId);
    }
}

function campaigns_rewards_set_team_scope_v100(PDO $pdo,int $ownerUserId,int $memberUserId,string $category,int $merchantId=0,?int $actorUserId=null): array
{
    if(!isset(campaigns_rewards_team_categories_v100()[$category]))throw new RuntimeException('Choose Basic Team, Merchant Team or Both.');
    $membership=workspace_team_v350_membership($pdo,$ownerUserId,$memberUserId);
    if(!$membership||($membership['membership_status']??'')==='removed')throw new RuntimeException('A canonical VP3 Team relationship is required.');
    if(in_array($category,['merchant','both'],true)){
        if($merchantId<1)throw new RuntimeException('Choose a merchant account for Merchant Team access.');
        campaigns_rewards_assert_team_merchant_v100($pdo,$ownerUserId,$merchantId);
    }else{$merchantId=0;}
    $basic=$category!=='merchant';
    if(function_exists('workspace_team_v350_set_basic_enabled_v1'))workspace_team_v350_set_basic_enabled_v1($pdo,$ownerUserId,$memberUserId,$basic);
    campaigns_rewards_sync_team_merchant_member_v100($pdo,$ownerUserId,$memberUserId,$category,$merchantId,$actorUserId);
    return campaigns_rewards_team_scope_v100($pdo,$ownerUserId,$memberUserId);
}

function campaigns_rewards_set_invite_scope_v100(PDO $pdo,int $inviteId,int $ownerUserId,string $category,int $merchantId=0): void
{
    if($inviteId<1)return;
    if(!isset(campaigns_rewards_team_categories_v100()[$category]))throw new RuntimeException('Choose Basic Team, Merchant Team or Both.');
    if(in_array($category,['merchant','both'],true)){
        if($merchantId<1)throw new RuntimeException('Choose a merchant account for Merchant Team access.');
        campaigns_rewards_assert_team_merchant_v100($pdo,$ownerUserId,$merchantId);
    }else{$merchantId=0;}
    $pdo->prepare('DELETE FROM workspace_team_invitation_scopes_v1 WHERE invitation_id=?')->execute([$inviteId]);
    if($category!=='merchant'){
        $pdo->prepare("INSERT INTO workspace_team_invitation_scopes_v1 (invitation_id,scope_type,scope_id,role_key,metadata_json) VALUES (?,'basic_team',NULL,NULL,NULL)")
            ->execute([$inviteId]);
    }
    if($merchantId>0){
        $pdo->prepare("INSERT INTO workspace_team_invitation_scopes_v1 (invitation_id,scope_type,scope_id,role_key,metadata_json) VALUES (?,'merchant',?,'member',NULL)")
            ->execute([$inviteId,$merchantId]);
    }
}

function campaigns_rewards_pending_invite_scopes_v100(PDO $pdo,int $ownerUserId): array
{
    if(!campaigns_rewards_schema_ready_v100($pdo))return [];
    $stmt=$pdo->prepare("SELECT i.id invitation_id,
      MAX(CASE WHEN s.scope_type='basic_team' THEN 1 ELSE 0 END) basic_enabled,
      MAX(CASE WHEN s.scope_type='merchant' THEN s.scope_id ELSE 0 END) merchant_account_id
      FROM workspace_team_invitations_v350 i
      LEFT JOIN workspace_team_invitation_scopes_v1 s ON s.invitation_id=i.id
      WHERE i.workspace_owner_user_id=? AND i.invitation_status='pending'
      GROUP BY i.id");
    $stmt->execute([$ownerUserId]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $merchantId=(int)($row['merchant_account_id']??0);$basic=!empty($row['basic_enabled']);
        $row['team_category']=$merchantId>0?($basic?'both':'merchant'):'basic';
        $out[(int)$row['invitation_id']]=$row;
    }
    return $out;
}

function campaigns_rewards_apply_invite_scope_v100(PDO $pdo,int $inviteId,int $ownerUserId,int $memberUserId): void
{
    if(!campaigns_rewards_schema_ready_v100($pdo))return;
    $stmt=$pdo->prepare("SELECT scope_type,scope_id,role_key FROM workspace_team_invitation_scopes_v1 WHERE invitation_id=? ORDER BY id");
    $stmt->execute([$inviteId]);$rows=$stmt->fetchAll()?:[];
    $basic=false;$merchantId=0;
    foreach($rows as $scope){
        if(($scope['scope_type']??'')==='basic_team')$basic=true;
        if(($scope['scope_type']??'')==='merchant')$merchantId=(int)($scope['scope_id']??0);
    }
    if(!$rows)$basic=true;
    $category=$merchantId>0?($basic?'both':'merchant'):'basic';
    campaigns_rewards_set_team_scope_v100($pdo,$ownerUserId,$memberUserId,$category,$merchantId,$ownerUserId);
    $pdo->prepare('DELETE FROM workspace_team_invitation_scopes_v1 WHERE invitation_id=?')->execute([$inviteId]);
}

function campaigns_rewards_clear_invite_scope_v100(PDO $pdo,int $inviteId): void
{
    if(table_exists('workspace_team_invitation_scopes_v1'))$pdo->prepare('DELETE FROM workspace_team_invitation_scopes_v1 WHERE invitation_id=?')->execute([$inviteId]);
}

function campaigns_rewards_team_membership_status_v100(PDO $pdo,int $ownerUserId,int $memberUserId,string $status): void
{
    if(!function_exists('campaigns_rewards_set_access_source_v100'))return;
    $scope=campaigns_rewards_team_scope_v100($pdo,$ownerUserId,$memberUserId);$merchantId=(int)($scope['merchant_account_id']??0);
    if($merchantId<1)return;
    $next=$status==='active'?'active':($status==='suspended'?'suspended':'removed');
    campaigns_rewards_set_access_source_v100($pdo,$merchantId,$memberUserId,'team','workspace:'.$ownerUserId,'merchant_team',$next,$ownerUserId);
}

function campaigns_rewards_cognitive_object_v100(PDO $pdo,array $user,array $ref): ?array
{
    $type=(string)($ref['type']??'');
    if(in_array($type,['merchant','merchant_location','merchant_team_member','campaign','campaign_enrollment','campaign_case','reward_product','reward_issuance','reward_claim','claim_code','loyalty_account'],true)
        &&function_exists('campaigns_rewards_cognitive_object_canonical_v100')){
        return campaigns_rewards_cognitive_object_canonical_v100($pdo,$user,$ref);
    }
    $uid=(int)($user['id']??0);$public=(string)($ref['id']??'');
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
    $type=(string)($ref['type']??'');
    if(in_array($type,['merchant','merchant_location','merchant_team_member','campaign','campaign_enrollment','campaign_case','reward_product','reward_issuance','reward_claim','claim_code','loyalty_account'],true)
        &&function_exists('campaigns_rewards_cognitive_context_canonical_v100')){
        return campaigns_rewards_cognitive_context_canonical_v100($pdo,$user,$ref);
    }
    $row=campaigns_rewards_cognitive_object_v100($pdo,$user,$ref);
    if(!$row)throw new RuntimeException('Campaigns & Rewards object is unavailable.');
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
    $type=(string)($ref['type']??'');
    if(in_array($type,['merchant','merchant_location','merchant_team_member','campaign','campaign_enrollment','campaign_case','reward_product','reward_issuance','reward_claim','claim_code','loyalty_account'],true)
        &&function_exists('campaigns_rewards_cognitive_relationships_canonical_v100')){
        return campaigns_rewards_cognitive_relationships_canonical_v100($pdo,$user,$ref);
    }
    $row=campaigns_rewards_cognitive_object_v100($pdo,$user,$ref);if(!$row)return [];$edges=[];$scope=(string)($ref['scope']??'workspace');
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
