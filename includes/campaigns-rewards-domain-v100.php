<?php
declare(strict_types=1);

/**
 * Campaigns & Rewards V1 canonical domain runtime.
 *
 * Domain records here are authoritative. VP3 Core remains authoritative for
 * identity, Team lifecycle, CRM contact identity, messaging, commerce,
 * scheduling, action approvals and cognition.
 */
const VP3_CAMPAIGNS_REWARDS_DOMAIN_V100='vp3-campaigns-rewards-domain-v100-20260923';
const VP3_CAMPAIGNS_REWARDS_ENTITLEMENT_V100='campaigns_rewards.access';

function campaigns_rewards_platform_require_v100(PDO $pdo): void
{
    if(!function_exists('campaigns_rewards_platform_schema_ready_v100')||!campaigns_rewards_platform_schema_ready_v100($pdo)){
        throw new RuntimeException('Run the VP3 database upgrade to enable Campaigns & Rewards V1.');
    }
}

function campaigns_rewards_platform_user_v100(int $userId): ?array
{
    if($userId<1)return null;
    $pdo=db();if(!$pdo)return null;
    $stmt=$pdo->prepare('SELECT id,email,display_name,role,is_active,avatar_path FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_json_v100(mixed $value): string
{
    $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    return is_string($json)?$json:'{}';
}

function campaigns_rewards_secret_v100(int $bytes=24): string
{
    return rtrim(strtr(base64_encode(random_bytes(max(16,$bytes))),'+/','-_'),'=');
}

function campaigns_rewards_secret_hash_v100(string $secret): string
{
    return hash('sha256',trim($secret));
}

function campaigns_rewards_public_code_v100(int $length=10): string
{
    return strtoupper(campaigns_rewards_claim_code_v100($length));
}

function campaigns_rewards_system_role_id_v100(PDO $pdo,string $roleKey): int
{
    $stmt=$pdo->prepare("SELECT id FROM merchant_roles WHERE merchant_id IS NULL AND role_key=? AND is_active=1 ORDER BY id LIMIT 1");
    $stmt->execute([$roleKey]);return (int)$stmt->fetchColumn();
}

function campaigns_rewards_platform_merchant_v100(PDO $pdo,int $merchantId,bool $forUpdate=false): ?array
{
    if($merchantId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM merchant_accounts WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$merchantId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_platform_member_v100(PDO $pdo,int $merchantId,int $userId,bool $forUpdate=false): ?array
{
    if($merchantId<1||$userId<1)return null;
    $stmt=$pdo->prepare("SELECT mm.*,mr.role_key,mr.name role_name
      FROM merchant_members mm INNER JOIN merchant_roles mr ON mr.id=mm.role_id
      WHERE mm.merchant_id=? AND mm.user_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$merchantId,$userId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_platform_role_capabilities_v100(PDO $pdo,int $roleId): array
{
    if($roleId<1)return [];
    $stmt=$pdo->prepare("SELECT capability_key,effect FROM merchant_role_capabilities WHERE role_id=? ORDER BY capability_key");
    $stmt->execute([$roleId]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row)$out[(string)$row['capability_key']]=(string)$row['effect'];
    return $out;
}

function campaigns_rewards_platform_can_v100(PDO $pdo,int $merchantId,int $userId,string $capability): bool
{
    if($merchantId<1||$userId<1||$capability==='')return false;
    $user=campaigns_rewards_platform_user_v100($userId);if(!$user||(int)($user['is_active']??0)!==1)return false;
    if(function_exists('user_has_role')&&user_has_role('admin',$user))return true;
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId);
    if(!$merchant||!in_array((string)$merchant['status'],['active','suspended'],true))return false;
    $member=campaigns_rewards_platform_member_v100($pdo,$merchantId,$userId);
    if(!$member||($member['status']??'')!=='active')return false;
    if(!empty($member['is_owner']))return true;

    $override=$pdo->prepare("SELECT effect FROM merchant_member_capability_overrides WHERE merchant_member_id=? AND capability_key=? LIMIT 1");
    $override->execute([(int)$member['id'],$capability]);$effect=$override->fetchColumn();
    if($effect!==false)return $effect==='allow';

    $stmt=$pdo->prepare("SELECT effect FROM merchant_role_capabilities WHERE role_id=? AND capability_key=? LIMIT 1");
    $stmt->execute([(int)$member['role_id'],$capability]);return $stmt->fetchColumn()==='allow';
}

function campaigns_rewards_platform_assert_can_v100(PDO $pdo,int $merchantId,int $userId,string $capability): void
{
    if(!campaigns_rewards_platform_can_v100($pdo,$merchantId,$userId,$capability)){
        throw new RuntimeException('You do not have '.$capability.' access for this Merchant.');
    }
}

function campaigns_rewards_platform_merchants_v100(PDO $pdo,int $userId): array
{
    if($userId<1)return [];
    $stmt=$pdo->prepare("SELECT m.*,mm.id merchant_member_id,mm.status member_status,mm.is_owner,mr.role_key,mr.name role_name
      FROM merchant_members mm INNER JOIN merchant_accounts m ON m.id=mm.merchant_id
      INNER JOIN merchant_roles mr ON mr.id=mm.role_id
      WHERE mm.user_id=? AND mm.status='active' AND m.status<>'closed'
      ORDER BY m.updated_at DESC,m.id DESC");
    $stmt->execute([$userId]);return $stmt->fetchAll()?:[];
}

function campaigns_rewards_activity_event_v100(PDO $pdo,int $merchantId,string $eventType,array $refs=[],array $details=[],string $environment='production',?int $actorUserId=null,string $actorType='user'): int
{
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId);
    if(!$merchant)return 0;
    $pick=static function(string $key)use($refs): ?int{
        $v=(int)($refs[$key]??0);return $v>0?$v:null;
    };
    $public=campaigns_rewards_uuid_v100();
    $stmt=$pdo->prepare("INSERT INTO campaign_activity_events
      (public_id,merchant_id,campaign_id,contact_id,enrollment_id,campaign_case_id,reward_issuance_id,claim_id,location_id,merchant_member_id,actor_type,actor_user_id,event_type,summary,details_json,environment,occurred_at,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $stmt->execute([
        $public,$merchantId,$pick('campaign_id'),$pick('contact_id'),$pick('enrollment_id'),$pick('campaign_case_id'),
        $pick('reward_issuance_id'),$pick('claim_id'),$pick('location_id'),$pick('merchant_member_id'),
        $actorType,$actorUserId&&$actorUserId>0?$actorUserId:null,$eventType,
        campaigns_rewards_text_v100($details['summary']??$eventType,500),campaigns_rewards_json_v100($details),$environment,
    ]);
    $activityId=(int)$pdo->lastInsertId();

    // Sandbox evidence stays in the domain audit stream but cannot settle
    // production cognition/outcome learning as real evidence.
    if($environment==='production'&&function_exists('vp3_cognitive_domain_ingest_v2600')){
        $objects=[];
        foreach([
            'merchant'=>'merchant_public_id','merchant_location'=>'location_public_id','campaign'=>'campaign_public_id',
            'campaign_enrollment'=>'enrollment_public_id','campaign_case'=>'case_public_id','reward_product'=>'reward_product_public_id',
            'reward_issuance'=>'reward_issuance_public_id','reward_claim'=>'claim_public_id','claim_code'=>'claim_code_public_id',
            'loyalty_account'=>'loyalty_account_public_id',
        ] as $type=>$key){
            $id=trim((string)($details[$key]??''));
            if($id!=='')$objects[]=['type'=>$type,'id'=>$id,'scope'=>'workspace'];
        }
        $owner=(int)$merchant['owner_user_id'];
        if($owner>0){
            try{
                vp3_cognitive_domain_ingest_v2600($pdo,$owner,'campaigns_rewards',$eventType,$objects,[
                    'merchant_id'=>(string)$merchant['public_id'],
                    'activity_event_id'=>$public,
                    'environment'=>'production',
                ],[
                    'external_event_id'=>'campaigns-rewards:'.$public,
                    'occurred_at'=>gmdate('c'),
                ]);
            }catch(Throwable $e){error_log('Campaigns cognitive bridge failed: '.$e->getMessage());}
        }
    }
    return $activityId;
}

function campaigns_rewards_create_platform_merchant_v100(PDO $pdo,array $user,array $input): array
{
    campaigns_rewards_platform_require_v100($pdo);
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('Sign in to create a Merchant.');
    if(function_exists('campaigns_rewards_enabled_v100')&&!campaigns_rewards_enabled_v100($user,$pdo))throw new RuntimeException('Enable Campaigns & Rewards first.');
    $name=campaigns_rewards_text_v100($input['name']??'',190);if($name==='')throw new RuntimeException('Merchant name is required.');
    $slug=campaigns_rewards_slug_v100((string)($input['slug']??$name),100);if($slug==='')$slug='merchant-'.$uid;
    $base=$slug;$i=1;$check=$pdo->prepare('SELECT 1 FROM merchant_accounts WHERE owner_user_id=? AND slug=? LIMIT 1');
    while(true){$check->execute([$uid,$slug]);if(!$check->fetchColumn())break;$i++;$slug=substr($base,0,90).'-'.$i;}
    $roleId=campaigns_rewards_system_role_id_v100($pdo,'owner');if($roleId<1)throw new RuntimeException('Merchant Owner role is unavailable.');
    $public=campaigns_rewards_uuid_v100();$timezone=campaigns_rewards_text_v100($input['timezone']??'UTC',80)?:'UTC';
    $currency=strtoupper(substr(preg_replace('/[^A-Z]/','',strtoupper((string)($input['currency']??'USD')))??'USD',0,3));if(strlen($currency)!==3)$currency='USD';
    $sandbox=!empty($input['sandbox_mode']);

    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("INSERT INTO merchant_accounts
          (public_id,owner_user_id,name,slug,business_name,status,timezone,currency,sandbox_mode,settings_json)
          VALUES (?,?,?,?,?,'active',?,?,?,?)");
        $stmt->execute([$public,$uid,$name,$slug,campaigns_rewards_text_v100($input['business_name']??$name,190),$timezone,$currency,$sandbox?1:0,campaigns_rewards_json_v100([])]);
        $merchantId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO merchant_profiles (merchant_id,display_name,description,website_url,social_links_json,public_contact_json,branding_json)
          VALUES (?,?,?,?,?,?,?)")->execute([
            $merchantId,$name,mb_strimwidth(trim((string)($input['description']??'')),0,4000,'…'),
            campaigns_rewards_safe_url_v100((string)($input['website_url']??'')),
            campaigns_rewards_json_v100([]),campaigns_rewards_json_v100([]),campaigns_rewards_json_v100([]),
        ]);
        $pdo->prepare("INSERT INTO merchant_members (merchant_id,user_id,role_id,status,is_owner,joined_at) VALUES (?,?,?,'active',1,UTC_TIMESTAMP())")
            ->execute([$merchantId,$uid,$roleId]);
        $pdo->prepare("INSERT INTO merchant_ownership_events (merchant_id,event_type,to_user_id,actor_user_id,reason) VALUES (?,'owner_added',?,?,?)")
            ->execute([$merchantId,$uid,$uid,'Merchant created']);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=campaigns_rewards_platform_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant could not be loaded.');
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'merchant.created',[],[
        'summary'=>'Merchant created','merchant_public_id'=>$public,
    ],$sandbox?'sandbox':'production',$uid);
    return $row;
}

function campaigns_rewards_set_platform_merchant_status_v100(PDO $pdo,int $merchantId,int $actorUserId,string $status,string $reason=''): array
{
    $allowed=['active','suspended','archived','closed'];if(!in_array($status,$allowed,true))throw new RuntimeException('Choose a valid Merchant status.');
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'merchant.manage');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId,true)?:throw new RuntimeException('Merchant not found.');
        $old=(string)$merchant['status'];if($old===$status){if($owns)$pdo->commit();return $merchant;}
        $fields=[
            'suspended'=>$status==='suspended'?'UTC_TIMESTAMP()':'NULL',
            'archived'=>$status==='archived'?'UTC_TIMESTAMP()':'NULL',
            'closed'=>$status==='closed'?'UTC_TIMESTAMP()':'NULL',
        ];
        $pdo->prepare("UPDATE merchant_accounts SET status=?,suspended_at={$fields['suspended']},archived_at={$fields['archived']},closed_at={$fields['closed']},updated_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([$status,$merchantId]);
        $event=match($status){'suspended'=>'merchant_suspended','archived'=>'merchant_archived','closed'=>'merchant_closed',default=>'merchant_restored'};
        $pdo->prepare("INSERT INTO merchant_ownership_events (merchant_id,event_type,actor_user_id,reason) VALUES (?,?,?,?)")
            ->execute([$merchantId,$event,$actorUserId,campaigns_rewards_text_v100($reason,500)]);
        if($status!=='active'){
            $pdo->prepare("UPDATE merchant_claim_codes SET status='suspended',updated_at=UTC_TIMESTAMP() WHERE merchant_id=? AND status='active'")->execute([$merchantId]);
        }
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    $eventName=match($status){'suspended'=>'merchant.suspended','archived'=>'merchant.archived','closed'=>'merchant.closed',default=>'merchant.restored'};
    $saved=campaigns_rewards_platform_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant unavailable.');
    campaigns_rewards_activity_event_v100($pdo,$merchantId,$eventName,[],[
        'summary'=>'Merchant status changed to '.$status,'merchant_public_id'=>$saved['public_id'],
    ],!empty($saved['sandbox_mode'])?'sandbox':'production',$actorUserId);
    return $saved;
}

function campaigns_rewards_grant_platform_member_v100(PDO $pdo,int $merchantId,int $actorUserId,int $targetUserId,string $roleKey='customer_service',bool $asOwner=false): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'merchant.team.manage');
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant not found.');
    $target=campaigns_rewards_platform_user_v100($targetUserId);if(!$target||(int)$target['is_active']!==1)throw new RuntimeException('Active VP3 user required.');
    if(!$asOwner){
        $ownerUserId=(int)$merchant['owner_user_id'];
        if(!function_exists('workspace_team_v350_membership'))throw new RuntimeException('Canonical Team lifecycle is unavailable.');
        $team=workspace_team_v350_membership($pdo,$ownerUserId,$targetUserId);
        if(!$team||($team['membership_status']??'')!=='active')throw new RuntimeException('Merchant staff must have an active canonical VP3 Team relationship.');
    }
    $roleKey=$asOwner?'owner':$roleKey;$roleId=campaigns_rewards_system_role_id_v100($pdo,$roleKey);if($roleId<1)throw new RuntimeException('Merchant role not found.');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO merchant_members (merchant_id,user_id,role_id,status,is_owner,joined_at)
          VALUES (?,?,?,'active',?,UTC_TIMESTAMP())
          ON DUPLICATE KEY UPDATE role_id=VALUES(role_id),status='active',is_owner=VALUES(is_owner),suspended_at=NULL,removed_at=NULL,updated_at=UTC_TIMESTAMP()")
          ->execute([$merchantId,$targetUserId,$roleId,$asOwner?1:0]);
        if($asOwner)$pdo->prepare("INSERT INTO merchant_ownership_events (merchant_id,event_type,to_user_id,actor_user_id,reason) VALUES (?,'owner_added',?,?,?)")
            ->execute([$merchantId,$targetUserId,$actorUserId,'Owner granted']);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    $member=campaigns_rewards_platform_member_v100($pdo,$merchantId,$targetUserId)?:throw new RuntimeException('Merchant member could not be loaded.');
    campaigns_rewards_activity_event_v100($pdo,$merchantId,$asOwner?'merchant.owner_added':'merchant.member_granted',['merchant_member_id'=>(int)$member['id']],[
        'summary'=>$asOwner?'Merchant owner added':'Merchant member granted','merchant_public_id'=>$merchant['public_id'],
    ],!empty($merchant['sandbox_mode'])?'sandbox':'production',$actorUserId);
    return $member;
}

function campaigns_rewards_remove_platform_member_v100(PDO $pdo,int $merchantId,int $actorUserId,int $targetUserId,string $reason=''): void
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'merchant.team.manage');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId,true)?:throw new RuntimeException('Merchant not found.');
        $member=campaigns_rewards_platform_member_v100($pdo,$merchantId,$targetUserId,true)?:throw new RuntimeException('Merchant member not found.');
        if(!empty($member['is_owner'])){
            $count=$pdo->prepare("SELECT COUNT(*) FROM merchant_members WHERE merchant_id=? AND status='active' AND is_owner=1 FOR UPDATE");
            $count->execute([$merchantId]);if((int)$count->fetchColumn()<=1)throw new RuntimeException('A Merchant must always have at least one active Owner. Transfer ownership before removing the final Owner.');
        }
        $pdo->prepare("UPDATE merchant_members SET status='removed',is_owner=0,removed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$member['id']]);
        $pdo->prepare("UPDATE merchant_claim_codes SET status='suspended',updated_at=UTC_TIMESTAMP() WHERE merchant_id=? AND merchant_member_id=? AND status='active'")
            ->execute([$merchantId,(int)$member['id']]);
        if(!empty($member['is_owner']))$pdo->prepare("INSERT INTO merchant_ownership_events (merchant_id,event_type,from_user_id,actor_user_id,reason) VALUES (?,'owner_removed',?,?,?)")
            ->execute([$merchantId,$targetUserId,$actorUserId,campaigns_rewards_text_v100($reason,500)]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    campaigns_rewards_activity_event_v100($pdo,$merchantId,!empty($member['is_owner'])?'merchant.owner_removed':'merchant.member_revoked',[],[
        'summary'=>'Merchant access removed','merchant_public_id'=>$merchant['public_id'],
    ],!empty($merchant['sandbox_mode'])?'sandbox':'production',$actorUserId);
}

function campaigns_rewards_transfer_ownership_v100(PDO $pdo,int $merchantId,int $actorUserId,int $toUserId,string $reason=''): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'merchant.manage');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId,true)?:throw new RuntimeException('Merchant not found.');
        $fromUserId=(int)$merchant['owner_user_id'];
        if($fromUserId!==$actorUserId&&!campaigns_rewards_platform_can_v100($pdo,$merchantId,$actorUserId,'merchant.settings.manage'))throw new RuntimeException('Ownership transfer is restricted.');
        $to=campaigns_rewards_platform_user_v100($toUserId);if(!$to||(int)$to['is_active']!==1)throw new RuntimeException('New Owner must be an active VP3 user.');
        $ownerRole=campaigns_rewards_system_role_id_v100($pdo,'owner');if($ownerRole<1)throw new RuntimeException('Owner role unavailable.');
        $pdo->prepare("INSERT INTO merchant_members (merchant_id,user_id,role_id,status,is_owner,joined_at)
          VALUES (?,?,?,'active',1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id),status='active',is_owner=1,suspended_at=NULL,removed_at=NULL,updated_at=UTC_TIMESTAMP()")
          ->execute([$merchantId,$toUserId,$ownerRole]);
        $pdo->prepare("UPDATE merchant_accounts SET owner_user_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$toUserId,$merchantId]);
        $pdo->prepare("INSERT INTO merchant_ownership_events (merchant_id,event_type,from_user_id,to_user_id,actor_user_id,reason)
          VALUES (?,'ownership_transferred',?,?,?,?)")->execute([$merchantId,$fromUserId,$toUserId,$actorUserId,campaigns_rewards_text_v100($reason,500)]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    $saved=campaigns_rewards_platform_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant unavailable.');
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'merchant.ownership_transferred',[],[
        'summary'=>'Merchant ownership transferred','merchant_public_id'=>$saved['public_id'],
    ],!empty($saved['sandbox_mode'])?'sandbox':'production',$actorUserId);
    return $saved;
}

function campaigns_rewards_save_platform_location_v100(PDO $pdo,int $merchantId,int $actorUserId,array $input,int $locationId=0): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'locations.manage');
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant not found.');
    $name=campaigns_rewards_text_v100($input['name']??'',190);if($name==='')throw new RuntimeException('Location name is required.');
    $type=campaigns_rewards_slug_v100((string)($input['location_type']??'store'),30)?:'store';
    $allowed=['store','restaurant','office','warehouse','pop-up','event','mobile','online','other'];if(!in_array($type,$allowed,true))$type='other';
    if($locationId>0){
        $stmt=$pdo->prepare("UPDATE merchant_locations SET name=?,location_type=?,address1=?,address2=?,city=?,region=?,postal_code=?,country=?,phone=?,timezone=?,is_active=?,metadata_json=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND merchant_id=?");
        $stmt->execute([$name,$type,campaigns_rewards_text_v100($input['address1']??'',190),campaigns_rewards_text_v100($input['address2']??'',190),campaigns_rewards_text_v100($input['city']??'',120),campaigns_rewards_text_v100($input['region']??'',120),campaigns_rewards_text_v100($input['postal_code']??'',40),campaigns_rewards_text_v100($input['country']??'US',80),campaigns_rewards_text_v100($input['phone']??'',80),campaigns_rewards_text_v100($input['timezone']??$merchant['timezone'],80),empty($input['is_active'])?0:1,campaigns_rewards_json_v100($input['metadata']??[]),$locationId,$merchantId]);
        if($stmt->rowCount()===0){$check=$pdo->prepare('SELECT 1 FROM merchant_locations WHERE id=? AND merchant_id=?');$check->execute([$locationId,$merchantId]);if(!$check->fetchColumn())throw new RuntimeException('Location not found.');}
        $event='merchant.location_updated';
    }else{
        $public=campaigns_rewards_uuid_v100();
        $stmt=$pdo->prepare("INSERT INTO merchant_locations (public_id,merchant_id,name,location_type,address1,address2,city,region,postal_code,country,phone,timezone,is_active,metadata_json)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$public,$merchantId,$name,$type,campaigns_rewards_text_v100($input['address1']??'',190),campaigns_rewards_text_v100($input['address2']??'',190),campaigns_rewards_text_v100($input['city']??'',120),campaigns_rewards_text_v100($input['region']??'',120),campaigns_rewards_text_v100($input['postal_code']??'',40),campaigns_rewards_text_v100($input['country']??'US',80),campaigns_rewards_text_v100($input['phone']??'',80),campaigns_rewards_text_v100($input['timezone']??$merchant['timezone'],80),isset($input['is_active'])&&!$input['is_active']?0:1,campaigns_rewards_json_v100($input['metadata']??[])]);
        $locationId=(int)$pdo->lastInsertId();$event='merchant.location_created';
    }
    $stmt=$pdo->prepare('SELECT * FROM merchant_locations WHERE id=? AND merchant_id=? LIMIT 1');$stmt->execute([$locationId,$merchantId]);$row=$stmt->fetch()?:throw new RuntimeException('Location unavailable.');
    campaigns_rewards_activity_event_v100($pdo,$merchantId,$event,['location_id'=>$locationId],[
        'summary'=>$event==='merchant.location_created'?'Merchant location created':'Merchant location updated',
        'merchant_public_id'=>$merchant['public_id'],'location_public_id'=>$row['public_id'],
    ],!empty($merchant['sandbox_mode'])?'sandbox':'production',$actorUserId);
    return $row;
}

function campaigns_rewards_resolve_contact_v100(PDO $pdo,int $merchantId,array $data): array
{
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant not found.');
    $owner=(int)$merchant['owner_user_id'];
    $contactId=crm_v180_upsert_contact($pdo,[
        'owner_user_id'=>$owner,
        'vp3_user_id'=>(int)($data['vp3_user_id']??0),
        'name'=>$data['name']??'',
        'email'=>$data['email']??'',
        'phone'=>$data['phone']??'',
        'company'=>$data['company']??'',
        'source'=>$data['source']??'campaign',
    ]);
    $contact=function_exists('crm_v180_contact_for_owner')?crm_v180_contact_for_owner($pdo,$owner,$contactId):null;
    if(!$contact)throw new RuntimeException('CRM Contact could not be resolved.');
    return $contact;
}

function campaigns_rewards_merchant_relationship_v100(PDO $pdo,int $merchantId,int $contactId,bool $forUpdate=false): ?array
{
    if($merchantId<1||$contactId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM crm_merchant_relationships WHERE merchant_id=? AND contact_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$merchantId,$contactId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_ensure_merchant_relationship_v100(PDO $pdo,int $merchantId,int $contactId,array $input=[]): array
{
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant not found.');
    $contact=function_exists('crm_v180_contact_for_owner')?crm_v180_contact_for_owner($pdo,(int)$merchant['owner_user_id'],$contactId):null;
    if(!$contact)throw new RuntimeException('CRM Contact is outside this Merchant owner CRM.');
    $pdo->prepare("INSERT INTO crm_merchant_relationships
      (merchant_id,contact_id,customer_status,loyalty_status,acquisition_source,assigned_member_id,marketing_status,customer_since,merchant_notes,metadata_json)
      VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),?,?)
      ON DUPLICATE KEY UPDATE customer_status=VALUES(customer_status),loyalty_status=VALUES(loyalty_status),
        acquisition_source=CASE WHEN VALUES(acquisition_source)<>'' THEN VALUES(acquisition_source) ELSE acquisition_source END,
        assigned_member_id=COALESCE(VALUES(assigned_member_id),assigned_member_id),marketing_status=VALUES(marketing_status),
        merchant_notes=CASE WHEN VALUES(merchant_notes)<>'' THEN VALUES(merchant_notes) ELSE merchant_notes END,updated_at=UTC_TIMESTAMP()")
      ->execute([
        $merchantId,$contactId,campaigns_rewards_text_v100($input['customer_status']??'prospect',40),
        campaigns_rewards_text_v100($input['loyalty_status']??'',40),campaigns_rewards_text_v100($input['acquisition_source']??'',120),
        ((int)($input['assigned_member_id']??0))?:null,campaigns_rewards_text_v100($input['marketing_status']??'unknown',30),
        mb_strimwidth(trim((string)($input['merchant_notes']??'')),0,4000,'…'),campaigns_rewards_json_v100($input['metadata']??[]),
      ]);
    return campaigns_rewards_merchant_relationship_v100($pdo,$merchantId,$contactId)?:throw new RuntimeException('Merchant relationship unavailable.');
}

function campaigns_rewards_campaign_type_v100(PDO $pdo,int $merchantId,string $typeKey): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM campaign_types WHERE type_key=? AND is_active=1 AND (merchant_id=? OR merchant_id IS NULL)
      ORDER BY merchant_id IS NULL ASC,id DESC LIMIT 1");
    $stmt->execute([$typeKey,$merchantId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_campaign_platform_v100(PDO $pdo,int $campaignId,bool $forUpdate=false): ?array
{
    if($campaignId<1)return null;
    $stmt=$pdo->prepare("SELECT c.*,ct.type_key campaign_type_key,ct.name campaign_type_name,m.public_id merchant_public_id,m.owner_user_id merchant_owner_user_id,m.sandbox_mode merchant_sandbox_mode,m.status merchant_status
      FROM campaigns c INNER JOIN campaign_types ct ON ct.id=c.campaign_type_id INNER JOIN merchant_accounts m ON m.id=c.merchant_id
      WHERE c.id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$campaignId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_create_campaign_v100(PDO $pdo,int $merchantId,int $actorUserId,array $input): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.create');
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant not found.');
    if((string)$merchant['status']!=='active')throw new RuntimeException('Merchant must be active to create a Campaign.');
    $name=campaigns_rewards_text_v100($input['name']??'',190);if($name==='')throw new RuntimeException('Campaign name is required.');
    $typeKey=campaigns_rewards_slug_v100((string)($input['campaign_type']??'signup'),80)?:'signup';
    $type=campaigns_rewards_campaign_type_v100($pdo,$merchantId,$typeKey)?:throw new RuntimeException('Campaign Type is unavailable.');
    $slug=campaigns_rewards_slug_v100((string)($input['slug']??$name),120);if($slug==='')$slug='campaign';
    $base=$slug;$n=1;$check=$pdo->prepare('SELECT 1 FROM campaigns WHERE merchant_id=? AND slug=? LIMIT 1');
    while(true){$check->execute([$merchantId,$slug]);if(!$check->fetchColumn())break;$n++;$slug=substr($base,0,110).'-'.$n;}
    $public=campaigns_rewards_uuid_v100();$code='';for($i=0;$i<20;$i++){ $candidate=campaigns_rewards_public_code_v100();$q=$pdo->prepare('SELECT 1 FROM campaigns WHERE merchant_id=? AND public_code=? LIMIT 1');$q->execute([$merchantId,$candidate]);if(!$q->fetchColumn()){$code=$candidate;break;}}
    if($code==='')throw new RuntimeException('Campaign code could not be generated.');
    $environment=!empty($merchant['sandbox_mode'])?'sandbox':((string)($input['environment']??'production')==='sandbox'?'sandbox':'production');
    $stmt=$pdo->prepare("INSERT INTO campaigns
      (public_id,public_code,merchant_id,campaign_type_id,name,slug,description,status,environment,objective,owner_user_id,starts_at,ends_at,audience_mode,budget_minor,budget_currency,budget_quantity,max_enrollments,max_rewards,per_contact_limit,settings_json)
      VALUES (?,?,?,?,?,?,?,'draft',?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $public,$code,$merchantId,(int)$type['id'],$name,$slug,mb_strimwidth(trim((string)($input['description']??'')),0,8000,'…'),
        $environment,campaigns_rewards_text_v100($input['objective']??'',500),$actorUserId,
        campaigns_rewards_datetime_v100((string)($input['starts_at']??'')),campaigns_rewards_datetime_v100((string)($input['ends_at']??'')),
        in_array((string)($input['audience_mode']??'static'),['static','dynamic'],true)?(string)$input['audience_mode']:'static',
        isset($input['budget_minor'])?max(0,(int)$input['budget_minor']):null,
        strtoupper(substr((string)($input['budget_currency']??$merchant['currency']),0,3)),
        isset($input['budget_quantity'])?max(0,(int)$input['budget_quantity']):null,
        isset($input['max_enrollments'])?max(0,(int)$input['max_enrollments']):null,
        isset($input['max_rewards'])?max(0,(int)$input['max_rewards']):null,
        isset($input['per_contact_limit'])?max(1,(int)$input['per_contact_limit']):null,
        campaigns_rewards_json_v100($input['settings']??[]),
    ]);
    $campaignId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO campaign_landing_pages (campaign_id,slug,visibility,presentation_mode,headline,subheadline,cta_label,content_json,terms_json,is_published)
      VALUES (?,?,?,'merchant',?,?,?,?,'{}',0)")
      ->execute([$campaignId,$slug,in_array((string)($input['visibility']??'profile_public'),['profile_public','unlisted','private'],true)?(string)$input['visibility']:'profile_public',$name,campaigns_rewards_text_v100($input['subheadline']??'',500),campaigns_rewards_text_v100($input['cta_label']??'Claim reward',80),campaigns_rewards_json_v100($input['landing_content']??[])]);
    $row=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign unavailable.');
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.created',['campaign_id'=>$campaignId],[
        'summary'=>'Campaign created','merchant_public_id'=>$merchant['public_id'],'campaign_public_id'=>$public,
    ],$environment,$actorUserId);
    return $row;
}

function campaigns_rewards_snapshot_campaign_v100(PDO $pdo,int $campaignId,int $actorUserId,string $status='published'): array
{
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId,true)?:throw new RuntimeException('Campaign not found.');
        campaigns_rewards_platform_assert_can_v100($pdo,(int)$campaign['merchant_id'],$actorUserId,'campaigns.publish');
        $next=max(1,(int)$campaign['current_version_no']+1);
        $landingStmt=$pdo->prepare('SELECT * FROM campaign_landing_pages WHERE campaign_id=? LIMIT 1');$landingStmt->execute([$campaignId]);$landing=$landingStmt->fetch()?:[];
        $sets=$pdo->prepare("SELECT rs.*,GROUP_CONCAT(CONCAT(i.reward_product_id,':',i.quantity) ORDER BY i.priority,i.reward_product_id SEPARATOR ',') reward_items
          FROM campaign_reward_sets rs LEFT JOIN campaign_reward_set_items i ON i.reward_set_id=rs.id WHERE rs.campaign_id=? GROUP BY rs.id ORDER BY rs.id");
        $sets->execute([$campaignId]);$rewardSnapshot=$sets->fetchAll()?:[];
        $stmt=$pdo->prepare("INSERT INTO campaign_versions
          (campaign_id,version_no,status,campaign_snapshot_json,audience_snapshot_json,eligibility_snapshot_json,trigger_snapshot_json,landing_snapshot_json,reward_snapshot_json,terms_snapshot_json,created_by_user_id)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$campaignId,$next,$status,campaigns_rewards_json_v100($campaign),campaigns_rewards_json_v100([]),campaigns_rewards_json_v100([]),campaigns_rewards_json_v100([]),campaigns_rewards_json_v100($landing),campaigns_rewards_json_v100($rewardSnapshot),campaigns_rewards_json_v100(['campaign'=>$campaign['settings_json']??'{}','landing'=>$landing['terms_json']??'{}']),$actorUserId]);
        $versionId=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE campaigns SET current_version_no=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$next,$campaignId]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    $q=$pdo->prepare('SELECT * FROM campaign_versions WHERE id=? LIMIT 1');$q->execute([$versionId]);return $q->fetch()?:throw new RuntimeException('Campaign version unavailable.');
}

function campaigns_rewards_set_campaign_lifecycle_v100(PDO $pdo,int $campaignId,int $actorUserId,string $status): array
{
    $allowed=['draft','scheduled','active','paused','completed','archived'];if(!in_array($status,$allowed,true))throw new RuntimeException('Choose a valid Campaign status.');
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    $cap=in_array($status,['active','paused','completed'],true)?'campaigns.launch':'campaigns.edit';
    if($status==='archived')$cap='campaigns.archive';
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$campaign['merchant_id'],$actorUserId,$cap);
    if($status==='active'&&(int)$campaign['current_version_no']<1)campaigns_rewards_snapshot_campaign_v100($pdo,$campaignId,$actorUserId,'published');
    $timestamp=match($status){'active'=>'launched_at','paused'=>'paused_at','completed'=>'completed_at','archived'=>'archived_at',default=>''};
    $sql="UPDATE campaigns SET status=?,updated_at=UTC_TIMESTAMP()".($timestamp!==''?",{$timestamp}=UTC_TIMESTAMP()":'')." WHERE id=?";
    $pdo->prepare($sql)->execute([$status,$campaignId]);
    if($status==='active')$pdo->prepare("UPDATE campaign_landing_pages SET is_published=IF(visibility='private',0,1),published_at=IF(visibility='private',published_at,COALESCE(published_at,UTC_TIMESTAMP())),updated_at=UTC_TIMESTAMP() WHERE campaign_id=?")->execute([$campaignId]);
    $saved=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign unavailable.');
    $event=match($status){'active'=>'campaign.activated','paused'=>'campaign.paused','completed'=>'campaign.completed','archived'=>'campaign.archived',default=>'campaign.updated'};
    campaigns_rewards_activity_event_v100($pdo,(int)$saved['merchant_id'],$event,['campaign_id'=>$campaignId],[
        'summary'=>'Campaign status changed to '.$status,'merchant_public_id'=>$saved['merchant_public_id'],'campaign_public_id'=>$saved['public_id'],
    ],(string)$saved['environment'],$actorUserId);
    return $saved;
}

function campaigns_rewards_publish_profile_v100(PDO $pdo,int $campaignId,int $actorUserId,int $profileUserId,bool $visible=true): void
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$campaign['merchant_id'],$actorUserId,'campaigns.publish');
    $pdo->prepare("INSERT INTO campaign_profile_publications (campaign_id,profile_user_id,status,featured,sort_order)
      VALUES (?,?,'active',0,100) ON DUPLICATE KEY UPDATE status=VALUES(status),updated_at=UTC_TIMESTAMP()")
      ->execute([$campaignId,$profileUserId]);
    if(!$visible)$pdo->prepare("UPDATE campaign_profile_publications SET status='hidden',updated_at=UTC_TIMESTAMP() WHERE campaign_id=? AND profile_user_id=?")->execute([$campaignId,$profileUserId]);
}

function campaigns_rewards_profile_campaigns_platform_v100(PDO $pdo,int $profileUserId): array
{
    if($profileUserId<1)return [];
    $stmt=$pdo->prepare("SELECT c.*,lp.headline,lp.subheadline,lp.hero_media_ref,lp.cta_label,lp.presentation_mode,m.name merchant_name,m.public_id merchant_public_id
      FROM campaign_profile_publications pp
      INNER JOIN campaigns c ON c.id=pp.campaign_id
      INNER JOIN campaign_landing_pages lp ON lp.campaign_id=c.id
      INNER JOIN merchant_accounts m ON m.id=c.merchant_id
      WHERE pp.profile_user_id=? AND pp.status='active' AND c.status='active' AND c.environment='production'
        AND lp.visibility='profile_public' AND lp.is_published=1 AND m.status='active'
        AND (c.starts_at IS NULL OR c.starts_at<=UTC_TIMESTAMP()) AND (c.ends_at IS NULL OR c.ends_at>UTC_TIMESTAMP())
      ORDER BY pp.featured DESC,pp.sort_order ASC,c.updated_at DESC,c.id DESC");
    $stmt->execute([$profileUserId]);return $stmt->fetchAll()?:[];
}

function campaigns_rewards_reward_type_v100(PDO $pdo,int $merchantId,string $typeKey): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM reward_types WHERE type_key=? AND is_active=1 AND (merchant_id=? OR merchant_id IS NULL)
      ORDER BY merchant_id IS NULL ASC,id DESC LIMIT 1");
    $stmt->execute([$typeKey,$merchantId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_save_reward_product_v100(PDO $pdo,int $merchantId,int $actorUserId,array $input,int $rewardProductId=0): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'rewards.manage');
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant not found.');
    $name=campaigns_rewards_text_v100($input['name']??'',190);if($name==='')throw new RuntimeException('Reward name is required.');
    $typeKey=campaigns_rewards_slug_v100((string)($input['reward_type']??'free_product'),80)?:'free_product';
    $type=campaigns_rewards_reward_type_v100($pdo,$merchantId,$typeKey)?:throw new RuntimeException('Reward Type is unavailable.');
    $values=[
        $name,mb_strimwidth(trim((string)($input['description']??'')),0,8000,'…'),campaigns_rewards_text_v100($input['sku']??'',120),
        campaigns_rewards_text_v100($input['image_ref']??'',255),isset($input['retail_value_minor'])?max(0,(int)$input['retail_value_minor']):null,
        isset($input['internal_cost_minor'])?max(0,(int)$input['internal_cost_minor']):null,
        strtoupper(substr((string)($input['currency']??$merchant['currency']),0,3)),
        (string)($input['inventory_mode']??'none')==='tracked'?'tracked':'none',
        campaigns_rewards_text_v100($input['fulfillment_type']??'merchant',40),!empty($input['pickup_enabled'])?1:0,!empty($input['shipping_enabled'])?1:0,!empty($input['digital_enabled'])?1:0,
        campaigns_rewards_text_v100($input['expiration_policy']??'campaign',30),isset($input['expiration_days'])?max(1,(int)$input['expiration_days']):null,
        max(1,(int)($input['claim_limit']??1)),!empty($input['transferable'])?1:0,!empty($input['regiftable'])?1:0,
        mb_strimwidth(trim((string)($input['terms']??'')),0,12000,'…'),campaigns_rewards_json_v100($input['settings']??[]),isset($input['is_active'])&&!$input['is_active']?0:1,
    ];
    if($rewardProductId>0){
        $stmt=$pdo->prepare("UPDATE reward_products SET reward_type_id=?,name=?,description=?,sku=?,image_ref=?,retail_value_minor=?,internal_cost_minor=?,currency=?,inventory_mode=?,fulfillment_type=?,pickup_enabled=?,shipping_enabled=?,digital_enabled=?,expiration_policy=?,expiration_days=?,claim_limit=?,transferable=?,regiftable=?,terms=?,settings_json=?,is_active=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND merchant_id=?");
        $stmt->execute(array_merge([(int)$type['id']],$values,[$rewardProductId,$merchantId]));
        $event='reward.product_updated';
    }else{
        $public=campaigns_rewards_uuid_v100();
        $stmt=$pdo->prepare("INSERT INTO reward_products
          (public_id,merchant_id,reward_type_id,name,description,sku,image_ref,retail_value_minor,internal_cost_minor,currency,inventory_mode,fulfillment_type,pickup_enabled,shipping_enabled,digital_enabled,expiration_policy,expiration_days,claim_limit,transferable,regiftable,terms,settings_json,is_active)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute(array_merge([$public,$merchantId,(int)$type['id']],$values));$rewardProductId=(int)$pdo->lastInsertId();$event='reward.product_created';
    }
    $q=$pdo->prepare("SELECT rp.*,rt.type_key reward_type_key,rt.name reward_type_name FROM reward_products rp INNER JOIN reward_types rt ON rt.id=rp.reward_type_id WHERE rp.id=? AND rp.merchant_id=? LIMIT 1");
    $q->execute([$rewardProductId,$merchantId]);$row=$q->fetch()?:throw new RuntimeException('Reward Product unavailable.');
    campaigns_rewards_activity_event_v100($pdo,$merchantId,$event,[],[
        'summary'=>$event==='reward.product_created'?'Reward Product created':'Reward Product updated',
        'merchant_public_id'=>$merchant['public_id'],'reward_product_public_id'=>$row['public_id'],
    ],!empty($merchant['sandbox_mode'])?'sandbox':'production',$actorUserId);
    return $row;
}

function campaigns_rewards_attach_reward_v100(PDO $pdo,int $campaignId,int $rewardProductId,int $actorUserId,string $selectionMode='fixed',int $quantity=1): int
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$campaign['merchant_id'],$actorUserId,'campaigns.edit');
    $q=$pdo->prepare('SELECT id FROM reward_products WHERE id=? AND merchant_id=? AND is_active=1 LIMIT 1');$q->execute([$rewardProductId,(int)$campaign['merchant_id']]);if(!$q->fetchColumn())throw new RuntimeException('Reward Product is outside this Merchant.');
    $selectionMode=in_array($selectionMode,['fixed','choose_one','choose_n','conditional','tier-based'],true)?$selectionMode:'fixed';
    $stmt=$pdo->prepare("SELECT id FROM campaign_reward_sets WHERE campaign_id=? ORDER BY id LIMIT 1");$stmt->execute([$campaignId]);$setId=(int)$stmt->fetchColumn();
    if($setId<1){$pdo->prepare("INSERT INTO campaign_reward_sets (campaign_id,name,selection_mode,min_choices,max_choices) VALUES (?,'Default rewards',?,1,1)")->execute([$campaignId,$selectionMode]);$setId=(int)$pdo->lastInsertId();}
    $pdo->prepare("INSERT INTO campaign_reward_set_items (reward_set_id,reward_product_id,variant_id,quantity,priority,conditions_json)
      VALUES (?,?,0,?,100,'{}') ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),updated_at=updated_at")->execute([$setId,$rewardProductId,max(1,$quantity)]);
    return $setId;
}

function campaigns_rewards_idempotency_begin_v100(PDO $pdo,int $merchantId,string $operation,string $key,array $request=[]): array
{
    $key=campaigns_rewards_text_v100($key,190);if($key==='')throw new RuntimeException('An idempotency key is required.');
    $hash=hash('sha256',campaigns_rewards_json_v100($request));
    try{
        $pdo->prepare("INSERT INTO campaign_idempotency_keys (merchant_id,operation_key,idempotency_key,request_hash,status,created_at)
          VALUES (?,?,?,?,'started',UTC_TIMESTAMP())")->execute([$merchantId,$operation,$key,$hash]);
        return ['new'=>true,'id'=>(int)$pdo->lastInsertId(),'status'=>'started','request_hash'=>$hash];
    }catch(Throwable $e){
        $stmt=$pdo->prepare("SELECT * FROM campaign_idempotency_keys WHERE merchant_id=? AND operation_key=? AND idempotency_key=? LIMIT 1");
        $stmt->execute([$merchantId,$operation,$key]);$row=$stmt->fetch();if(!$row)throw $e;
        if(!hash_equals((string)$row['request_hash'],$hash))throw new RuntimeException('Idempotency key was reused with a different request.');
        return ['new'=>false]+$row;
    }
}

function campaigns_rewards_idempotency_complete_v100(PDO $pdo,int $id,string $refType,int|string $refId): void
{
    if($id<1)return;
    $pdo->prepare("UPDATE campaign_idempotency_keys SET status='completed',result_ref_type=?,result_ref_id=?,completed_at=UTC_TIMESTAMP() WHERE id=?")
        ->execute([$refType,(string)$refId,$id]);
}

function campaigns_rewards_enroll_contact_v100(PDO $pdo,int $campaignId,int $contactId,int $actorUserId,string $source='manual',string $idempotencyKey=''): array
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$campaign['merchant_id'],$actorUserId,'campaigns.enrollment.manage');
    if((int)$campaign['current_version_no']<1)$version=campaigns_rewards_snapshot_campaign_v100($pdo,$campaignId,$actorUserId,'draft');
    else{$q=$pdo->prepare('SELECT * FROM campaign_versions WHERE campaign_id=? AND version_no=? LIMIT 1');$q->execute([$campaignId,(int)$campaign['current_version_no']]);$version=$q->fetch()?:throw new RuntimeException('Campaign version not found.');}
    campaigns_rewards_ensure_merchant_relationship_v100($pdo,(int)$campaign['merchant_id'],$contactId,['acquisition_source'=>$source]);
    $key=$idempotencyKey!==''?$idempotencyKey:'enroll:'.$campaignId.':'.$contactId.':'.$version['id'];
    $idem=campaigns_rewards_idempotency_begin_v100($pdo,(int)$campaign['merchant_id'],'campaign.enroll',$key,['campaign_id'=>$campaignId,'contact_id'=>$contactId,'version_id'=>(int)$version['id']]);
    if(empty($idem['new'])&&($idem['status']??'')==='completed'&&($idem['result_ref_type']??'')==='campaign_enrollment'){
        $q=$pdo->prepare('SELECT * FROM campaign_enrollments WHERE id=? LIMIT 1');$q->execute([(int)$idem['result_ref_id']]);$row=$q->fetch();if($row)return $row;
    }
    $existing=$pdo->prepare("SELECT * FROM campaign_enrollments WHERE campaign_id=? AND contact_id=? AND status IN ('eligible','enrolled','completed') ORDER BY id DESC LIMIT 1");
    $existing->execute([$campaignId,$contactId]);$row=$existing->fetch();
    if(!$row){
        $public=campaigns_rewards_uuid_v100();
        $pdo->prepare("INSERT INTO campaign_enrollments (public_id,campaign_id,campaign_version_id,contact_id,source,status,environment,qualified_at,enrolled_at,metadata_json)
          VALUES (?,?,?,?,?,'enrolled',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'{}')")->execute([$public,$campaignId,(int)$version['id'],$contactId,$source,(string)$campaign['environment']]);
        $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM campaign_enrollments WHERE id=?');$q->execute([$id]);$row=$q->fetch();
        campaigns_rewards_activity_event_v100($pdo,(int)$campaign['merchant_id'],'campaign.enrollment_created',['campaign_id'=>$campaignId,'contact_id'=>$contactId,'enrollment_id'=>$id],[
            'summary'=>'Contact enrolled','merchant_public_id'=>$campaign['merchant_public_id'],'campaign_public_id'=>$campaign['public_id'],'enrollment_public_id'=>$public,
        ],(string)$campaign['environment'],$actorUserId);
    }
    campaigns_rewards_idempotency_complete_v100($pdo,(int)$idem['id'],'campaign_enrollment',(int)$row['id']);
    return $row;
}

function campaigns_rewards_reward_product_v100(PDO $pdo,int $rewardProductId): ?array
{
    $stmt=$pdo->prepare("SELECT rp.*,rt.type_key reward_type_key FROM reward_products rp INNER JOIN reward_types rt ON rt.id=rp.reward_type_id WHERE rp.id=? LIMIT 1");
    $stmt->execute([$rewardProductId]);$row=$stmt->fetch();return $row?:null;
}

function campaigns_rewards_issue_reward_v100(PDO $pdo,int $campaignId,int $rewardProductId,int $contactId,int $actorUserId,array $options=[]): array
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$campaign['merchant_id'],$actorUserId,'rewards.issue');
    if(!in_array((string)$campaign['status'],['active','scheduled','draft'],true))throw new RuntimeException('Campaign cannot issue Rewards in its current state.');
    $reward=campaigns_rewards_reward_product_v100($pdo,$rewardProductId)?:throw new RuntimeException('Reward Product not found.');
    if((int)$reward['merchant_id']!==(int)$campaign['merchant_id']||!(int)$reward['is_active'])throw new RuntimeException('Reward Product is not available to this Campaign.');
    $relationship=campaigns_rewards_ensure_merchant_relationship_v100($pdo,(int)$campaign['merchant_id'],$contactId,['acquisition_source'=>$options['source']??'reward_issue']);
    if((int)$campaign['current_version_no']<1)$version=campaigns_rewards_snapshot_campaign_v100($pdo,$campaignId,$actorUserId,'issuance');
    else{$q=$pdo->prepare('SELECT * FROM campaign_versions WHERE campaign_id=? AND version_no=? LIMIT 1');$q->execute([$campaignId,(int)$campaign['current_version_no']]);$version=$q->fetch()?:throw new RuntimeException('Campaign version unavailable.');}
    $idempotency=(string)($options['idempotency_key']??'issue:'.$campaignId.':'.$rewardProductId.':'.$contactId.':'.$version['id']);
    $request=['campaign_id'=>$campaignId,'reward_product_id'=>$rewardProductId,'contact_id'=>$contactId,'version_id'=>(int)$version['id'],'quantity'=>max(1,(int)($options['quantity']??1))];

    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $idem=campaigns_rewards_idempotency_begin_v100($pdo,(int)$campaign['merchant_id'],'reward.issue',$idempotency,$request);
        if(empty($idem['new'])&&($idem['status']??'')==='completed'&&($idem['result_ref_type']??'')==='reward_issuance'){
            $q=$pdo->prepare('SELECT * FROM reward_issuances WHERE id=? LIMIT 1');$q->execute([(int)$idem['result_ref_id']]);$row=$q->fetch();
            if($row){if($owns)$pdo->commit();$row['credential']=null;$row['idempotent_replay']=true;return $row;}
        }
        $credential=campaigns_rewards_secret_v100(24);$hash=campaigns_rewards_secret_hash_v100($credential);$last4=substr($credential,-4);
        $public=campaigns_rewards_uuid_v100();$quantity=max(1,(int)($options['quantity']??1));
        $expiresAt=null;if(!empty($options['expires_at']))$expiresAt=campaigns_rewards_datetime_v100((string)$options['expires_at']);
        elseif((int)($reward['expiration_days']??0)>0)$expiresAt=gmdate('Y-m-d H:i:s',time()+86400*(int)$reward['expiration_days']);
        elseif(!empty($campaign['ends_at']))$expiresAt=(string)$campaign['ends_at'];
        $terms=[
            'reward_product_public_id'=>$reward['public_id'],'name'=>$reward['name'],'reward_type'=>$reward['reward_type_key'],
            'terms'=>$reward['terms'],'retail_value_minor'=>$reward['retail_value_minor'],'internal_cost_minor'=>$reward['internal_cost_minor'],
            'currency'=>$reward['currency'],'claim_limit'=>$reward['claim_limit'],'transferable'=>(bool)$reward['transferable'],'regiftable'=>(bool)$reward['regiftable'],
            'campaign_version'=>(int)$version['version_no'],
        ];
        $recipientUserId=isset($options['recipient_user_id'])?max(0,(int)$options['recipient_user_id']):0;
        $pdo->prepare("INSERT INTO reward_issuances
          (public_id,merchant_id,campaign_id,campaign_version_id,campaign_enrollment_id,campaign_case_id,reward_product_id,reward_variant_id,recipient_contact_id,recipient_user_id,issued_by_user_id,issued_by_actor_type,environment,status,credential_hash,credential_last4,quantity,remaining_quantity,face_value_minor,currency,terms_snapshot_json,issued_at,expires_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'issued',?,?,?,?,?,?,?,UTC_TIMESTAMP(),?)")
          ->execute([
            $public,(int)$campaign['merchant_id'],$campaignId,(int)$version['id'],((int)($options['campaign_enrollment_id']??0))?:null,((int)($options['campaign_case_id']??0))?:null,
            $rewardProductId,((int)($options['reward_variant_id']??0))?:null,$contactId,$recipientUserId?:null,$actorUserId,(string)($options['actor_type']??'user'),(string)$campaign['environment'],
            $hash,$last4,$quantity,$quantity,$reward['retail_value_minor'],$reward['currency'],campaigns_rewards_json_v100($terms),$expiresAt,
          ]);
        $issuanceId=(int)$pdo->lastInsertId();
        if((string)$campaign['environment']==='production'){
            $pdo->prepare("INSERT INTO reward_liability_ledger
              (merchant_id,campaign_id,reward_issuance_id,entry_type,face_value_delta_minor,estimated_cost_delta_minor,currency,source_type,source_id,metadata_json)
              VALUES (?,?,?,'issued',?,?,?,?,?,'{}')")
              ->execute([(int)$campaign['merchant_id'],$campaignId,$issuanceId,$reward['retail_value_minor'],$reward['internal_cost_minor'],$reward['currency'],'reward_issuance',(string)$issuanceId]);
        }
        campaigns_rewards_idempotency_complete_v100($pdo,(int)$idem['id'],'reward_issuance',$issuanceId);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    $q=$pdo->prepare('SELECT * FROM reward_issuances WHERE id=? LIMIT 1');$q->execute([$issuanceId]);$row=$q->fetch()?:throw new RuntimeException('Reward Issuance unavailable.');
    $row['credential']=$credential;$row['idempotent_replay']=false;
    campaigns_rewards_activity_event_v100($pdo,(int)$campaign['merchant_id'],'reward.issued',['campaign_id'=>$campaignId,'contact_id'=>$contactId,'reward_issuance_id'=>$issuanceId],[
        'summary'=>'Reward issued','merchant_public_id'=>$campaign['merchant_public_id'],'campaign_public_id'=>$campaign['public_id'],
        'reward_product_public_id'=>$reward['public_id'],'reward_issuance_public_id'=>$public,
    ],(string)$campaign['environment'],$actorUserId);
    return $row;
}

function campaigns_rewards_wallet_v100(PDO $pdo,int $contactId=0,int $userId=0): array
{
    if($contactId<1&&$userId<1)return ['inbox'=>[],'sent'=>[],'claimed'=>[]];
    $where=[];$params=[];
    if($contactId>0){$where[]='ri.recipient_contact_id=?';$params[]=$contactId;}
    if($userId>0){$where[]='ri.recipient_user_id=?';$params[]=$userId;}
    $stmt=$pdo->prepare("SELECT ri.*,rp.name reward_name,rp.description reward_description,c.name campaign_name,m.name merchant_name,
      rc.status claim_status,rc.claimed_at
      FROM reward_issuances ri INNER JOIN reward_products rp ON rp.id=ri.reward_product_id
      INNER JOIN campaigns c ON c.id=ri.campaign_id INNER JOIN merchant_accounts m ON m.id=ri.merchant_id
      LEFT JOIN reward_claims rc ON rc.reward_issuance_id=ri.id AND rc.status='claimed'
      WHERE (".implode(' OR ',$where).") ORDER BY ri.issued_at DESC,ri.id DESC");
    $stmt->execute($params);$out=['inbox'=>[],'sent'=>[],'claimed'=>[]];
    foreach($stmt->fetchAll()?:[] as $row){
        if(($row['status']??'')==='claimed'||($row['claim_status']??'')==='claimed')$out['claimed'][]=$row;
        elseif(in_array((string)$row['status'],['issued','sent','viewed'],true)&&((empty($row['expires_at']))||strtotime((string)$row['expires_at'])>time()))$out['inbox'][]=$row;
        $out['sent'][]=$row;
    }
    return $out;
}

function campaigns_rewards_create_claim_code_v100(PDO $pdo,int $merchantId,int $actorUserId,array $input=[]): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'claim_codes.manage');
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant not found.');
    $locationId=((int)($input['location_id']??0))?:null;$memberId=((int)($input['merchant_member_id']??0))?:null;
    if($locationId){$q=$pdo->prepare('SELECT 1 FROM merchant_locations WHERE id=? AND merchant_id=? AND is_active=1');$q->execute([$locationId,$merchantId]);if(!$q->fetchColumn())throw new RuntimeException('Claim Code Location is invalid.');}
    if($memberId){$q=$pdo->prepare("SELECT 1 FROM merchant_members WHERE id=? AND merchant_id=? AND status='active'");$q->execute([$memberId,$merchantId]);if(!$q->fetchColumn())throw new RuntimeException('Claim Code Team Member is invalid.');}
    $secret=campaigns_rewards_secret_v100(18);$hash=campaigns_rewards_secret_hash_v100($secret);$public=campaigns_rewards_uuid_v100();
    $stmt=$pdo->prepare("INSERT INTO merchant_claim_codes
      (public_id,merchant_id,display_name,code_hash,code_last4,location_id,merchant_member_id,device_label,status,active_from,active_until,daily_claim_limit,total_claim_limit,max_value_minor,currency,rules_json,created_by_user_id)
      VALUES (?,?,?,?,?,?,?,?, 'active',?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $public,$merchantId,campaigns_rewards_text_v100($input['display_name']??'Claim Code',190),$hash,substr($secret,-4),$locationId,$memberId,
        campaigns_rewards_text_v100($input['device_label']??'',120),campaigns_rewards_datetime_v100((string)($input['active_from']??'')),campaigns_rewards_datetime_v100((string)($input['active_until']??'')),
        isset($input['daily_claim_limit'])?max(1,(int)$input['daily_claim_limit']):null,isset($input['total_claim_limit'])?max(1,(int)$input['total_claim_limit']):null,
        isset($input['max_value_minor'])?max(0,(int)$input['max_value_minor']):null,strtoupper(substr((string)($input['currency']??$merchant['currency']),0,3)),
        campaigns_rewards_json_v100($input['rules']??[]),$actorUserId,
    ]);
    $id=(int)$pdo->lastInsertId();
    foreach(array_unique(array_filter(array_map('intval',(array)($input['campaign_ids']??[])))) as $campaignId){
        $q=$pdo->prepare('SELECT 1 FROM campaigns WHERE id=? AND merchant_id=?');$q->execute([$campaignId,$merchantId]);if($q->fetchColumn())$pdo->prepare('INSERT IGNORE INTO merchant_claim_code_campaigns (claim_code_id,campaign_id) VALUES (?,?)')->execute([$id,$campaignId]);
    }
    $q=$pdo->prepare('SELECT * FROM merchant_claim_codes WHERE id=?');$q->execute([$id]);$row=$q->fetch()?:throw new RuntimeException('Claim Code unavailable.');
    $row['code']=$secret;
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'claim_code.created',[],[
        'summary'=>'Merchant Claim Code created','merchant_public_id'=>$merchant['public_id'],'claim_code_public_id'=>$public,
    ],!empty($merchant['sandbox_mode'])?'sandbox':'production',$actorUserId);
    return $row;
}

function campaigns_rewards_record_claim_attempt_v100(PDO $pdo,?int $merchantId,?int $issuanceId,?int $claimCodeId,string $result,string $reason,array $meta=[]): void
{
    try{$pdo->prepare("INSERT INTO reward_claim_attempts (merchant_id,reward_issuance_id,claim_code_id,attempt_result,reason_code,request_fingerprint_hash,occurred_at,metadata_json)
      VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),?)")->execute([$merchantId?:null,$issuanceId?:null,$claimCodeId?:null,$result,$reason,isset($meta['request_fingerprint'])?hash('sha256',(string)$meta['request_fingerprint']):null,campaigns_rewards_json_v100($meta)]);}catch(Throwable $e){}
}

function campaigns_rewards_process_claim_v100(PDO $pdo,string $rewardCredential,string $merchantClaimCode,int $actorUserId=0,array $context=[]): array
{
    if(empty($context['online']))throw new RuntimeException('Authoritative V1 Claim processing is online-only.');
    $rewardHash=campaigns_rewards_secret_hash_v100($rewardCredential);$claimHash=campaigns_rewards_secret_hash_v100($merchantClaimCode);
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    $merchantId=0;$issuanceId=0;$claimCodeId=0;
    try{
        $stmt=$pdo->prepare("SELECT ri.*,c.public_id campaign_public_id,c.status campaign_status,c.environment,c.merchant_id,
          rp.public_id reward_product_public_id,rp.name reward_name,m.public_id merchant_public_id,m.status merchant_status
          FROM reward_issuances ri INNER JOIN campaigns c ON c.id=ri.campaign_id
          INNER JOIN reward_products rp ON rp.id=ri.reward_product_id INNER JOIN merchant_accounts m ON m.id=ri.merchant_id
          WHERE ri.credential_hash=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$rewardHash]);$issuance=$stmt->fetch()?:throw new RuntimeException('Reward Credential is invalid.');
        $issuanceId=(int)$issuance['id'];$merchantId=(int)$issuance['merchant_id'];
        if((string)$issuance['merchant_status']!=='active')throw new RuntimeException('Merchant is not accepting Claims.');
        if(!in_array((string)$issuance['campaign_status'],['active','completed'],true))throw new RuntimeException('Campaign is not claimable.');
        if(!in_array((string)$issuance['status'],['issued','sent','viewed'],true)||((int)$issuance['remaining_quantity'])<1)throw new RuntimeException('Reward is no longer claimable.');
        if(!empty($issuance['expires_at'])&&strtotime((string)$issuance['expires_at'])<=time())throw new RuntimeException('Reward has expired.');

        $codeStmt=$pdo->prepare("SELECT * FROM merchant_claim_codes WHERE code_hash=? LIMIT 1 FOR UPDATE");$codeStmt->execute([$claimHash]);$code=$codeStmt->fetch()?:throw new RuntimeException('Merchant Claim Code is invalid.');
        $claimCodeId=(int)$code['id'];
        if((int)$code['merchant_id']!==$merchantId||($code['status']??'')!=='active')throw new RuntimeException('Merchant Claim Code is not active for this Merchant.');
        if(!empty($code['active_from'])&&strtotime((string)$code['active_from'])>time())throw new RuntimeException('Merchant Claim Code is not active yet.');
        if(!empty($code['active_until'])&&strtotime((string)$code['active_until'])<=time())throw new RuntimeException('Merchant Claim Code has expired.');
        if($actorUserId>0)campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'claims.process');
        if(!empty($code['merchant_member_id'])&&$actorUserId>0){
            $member=campaigns_rewards_platform_member_v100($pdo,$merchantId,$actorUserId);
            if(!$member||(int)$member['id']!==(int)$code['merchant_member_id'])throw new RuntimeException('Merchant Claim Code is assigned to a different Team Member.');
        }
        $requestedLocation=((int)($context['location_id']??0))?:null;
        if(!empty($code['location_id'])&&$requestedLocation!==(int)$code['location_id'])throw new RuntimeException('Reward must be claimed at the assigned Location.');
        $restrictions=$pdo->prepare('SELECT campaign_id FROM merchant_claim_code_campaigns WHERE claim_code_id=?');$restrictions->execute([$claimCodeId]);$allowed=array_map('intval',$restrictions->fetchAll(PDO::FETCH_COLUMN)?:[]);
        if($allowed&&!in_array((int)$issuance['campaign_id'],$allowed,true))throw new RuntimeException('Merchant Claim Code is not authorized for this Campaign.');
        if($code['max_value_minor']!==null&&$issuance['face_value_minor']!==null&&(int)$issuance['face_value_minor']>(int)$code['max_value_minor'])throw new RuntimeException('Reward exceeds this Claim Code value limit.');
        if($code['daily_claim_limit']!==null){
            $q=$pdo->prepare("SELECT COUNT(*) FROM reward_claims WHERE claim_code_id=? AND status='claimed' AND claimed_at>=UTC_DATE()");$q->execute([$claimCodeId]);if((int)$q->fetchColumn()>=(int)$code['daily_claim_limit'])throw new RuntimeException('Merchant Claim Code daily limit reached.');
        }
        if($code['total_claim_limit']!==null){
            $q=$pdo->prepare("SELECT COUNT(*) FROM reward_claims WHERE claim_code_id=? AND status='claimed'");$q->execute([$claimCodeId]);if((int)$q->fetchColumn()>=(int)$code['total_claim_limit'])throw new RuntimeException('Merchant Claim Code total limit reached.');
        }
        $dup=$pdo->prepare("SELECT * FROM reward_claims WHERE reward_issuance_id=? AND status='claimed' LIMIT 1 FOR UPDATE");$dup->execute([$issuanceId]);if($dup->fetch())throw new RuntimeException('Reward has already been claimed.');

        $public=campaigns_rewards_uuid_v100();$quantity=max(1,min((int)$issuance['remaining_quantity'],(int)($context['quantity']??1)));
        $memberId=null;if($actorUserId>0){$m=campaigns_rewards_platform_member_v100($pdo,$merchantId,$actorUserId);$memberId=$m?(int)$m['id']:null;}
        $pdo->prepare("INSERT INTO reward_claims
          (public_id,merchant_id,campaign_id,reward_issuance_id,claim_code_id,location_id,merchant_member_id,processed_by_user_id,processed_by_actor_type,environment,quantity,value_minor,currency,status,order_ref,claimed_at,metadata_json)
          VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,?,'claimed',?,UTC_TIMESTAMP(),?)")
          ->execute([$public,$merchantId,(int)$issuance['campaign_id'],$issuanceId,$claimCodeId,$requestedLocation,$memberId,$actorUserId?:null,(string)($context['actor_type']??'user'),(string)$issuance['environment'],$quantity,$issuance['face_value_minor'],$issuance['currency'],campaigns_rewards_text_v100($context['order_ref']??'',190)?:null,campaigns_rewards_json_v100(['online'=>true])]);
        $claimId=(int)$pdo->lastInsertId();$remaining=max(0,(int)$issuance['remaining_quantity']-$quantity);
        $pdo->prepare("UPDATE reward_issuances SET remaining_quantity=?,status=?,claimed_at=IF(?=0,UTC_TIMESTAMP(),claimed_at),updated_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([$remaining,$remaining===0?'claimed':(string)$issuance['status'],$remaining,$issuanceId]);
        $pdo->prepare("UPDATE merchant_claim_codes SET last_used_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$claimCodeId]);
        if((string)$issuance['environment']==='production'){
            $pdo->prepare("INSERT INTO reward_liability_ledger
              (merchant_id,campaign_id,reward_issuance_id,entry_type,face_value_delta_minor,estimated_cost_delta_minor,currency,source_type,source_id,metadata_json)
              VALUES (?,?,?,'claimed',?,?,?,?,?,'{}')")
              ->execute([$merchantId,(int)$issuance['campaign_id'],$issuanceId,$issuance['face_value_minor']===null?null:-1*(int)$issuance['face_value_minor'],null,$issuance['currency'],'reward_claim',(string)$claimId]);
        }
        if($owns)$pdo->commit();
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        campaigns_rewards_record_claim_attempt_v100($pdo,$merchantId?:null,$issuanceId?:null,$claimCodeId?:null,'rejected',campaigns_rewards_slug_v100($e->getMessage(),80),$context);
        if($merchantId>0){
            $m=campaigns_rewards_platform_merchant_v100($pdo,$merchantId);
            if($m)campaigns_rewards_activity_event_v100($pdo,$merchantId,'claim.rejected',['reward_issuance_id'=>$issuanceId],[
                'summary'=>'Claim rejected','merchant_public_id'=>$m['public_id'],
            ],isset($issuance['environment'])?(string)$issuance['environment']:'production',$actorUserId?:null);
        }
        throw $e;
    }
    campaigns_rewards_record_claim_attempt_v100($pdo,$merchantId,$issuanceId,$claimCodeId,'accepted','accepted',$context);
    $q=$pdo->prepare("SELECT rc.*,ri.public_id reward_issuance_public_id,c.public_id campaign_public_id,rp.public_id reward_product_public_id,m.public_id merchant_public_id,mc.public_id claim_code_public_id
      FROM reward_claims rc INNER JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id INNER JOIN campaigns c ON c.id=rc.campaign_id
      INNER JOIN reward_products rp ON rp.id=ri.reward_product_id INNER JOIN merchant_accounts m ON m.id=rc.merchant_id
      INNER JOIN merchant_claim_codes mc ON mc.id=rc.claim_code_id WHERE rc.id=? LIMIT 1");
    $q->execute([$claimId]);$claim=$q->fetch()?:throw new RuntimeException('Claim unavailable.');
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'claim.accepted',['campaign_id'=>(int)$claim['campaign_id'],'reward_issuance_id'=>$issuanceId,'claim_id'=>$claimId,'location_id'=>((int)($claim['location_id']??0))?:0,'merchant_member_id'=>((int)($claim['merchant_member_id']??0))?:0],[
        'summary'=>'Reward claimed','merchant_public_id'=>$claim['merchant_public_id'],'campaign_public_id'=>$claim['campaign_public_id'],
        'reward_product_public_id'=>$claim['reward_product_public_id'],'reward_issuance_public_id'=>$claim['reward_issuance_public_id'],
        'claim_public_id'=>$claim['public_id'],'claim_code_public_id'=>$claim['claim_code_public_id'],
    ],(string)$claim['environment'],$actorUserId?:null);
    return $claim;
}

function campaigns_rewards_make_good_v100(PDO $pdo,int $merchantId,int $contactId,int $actorUserId,array $rewardProductIds,array $case=[]): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'rewards.issue');
    $campaignStmt=$pdo->prepare("SELECT c.id FROM campaigns c INNER JOIN campaign_types ct ON ct.id=c.campaign_type_id
      WHERE c.merchant_id=? AND ct.type_key='make_good' AND c.status='active' ORDER BY c.id DESC LIMIT 1");
    $campaignStmt->execute([$merchantId]);$campaignId=(int)$campaignStmt->fetchColumn();
    if($campaignId<1){
        $campaign=campaigns_rewards_create_campaign_v100($pdo,$merchantId,$actorUserId,[
            'name'=>'Make Good','campaign_type'=>'make_good','visibility'=>'private','objective'=>'Customer service recovery',
        ]);
        $campaignId=(int)$campaign['id'];campaigns_rewards_set_campaign_lifecycle_v100($pdo,$campaignId,$actorUserId,'active');
    }
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Make Good Campaign unavailable.');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $public=campaigns_rewards_uuid_v100();
        $pdo->prepare("INSERT INTO campaign_cases
          (public_id,merchant_id,campaign_id,contact_id,case_type,reason_code,summary,internal_notes,status,environment,created_by_user_id,created_by_actor_type)
          VALUES (?,?,?,?,'make_good',?,?,?,'open',?,?,'user')")
          ->execute([$public,$merchantId,$campaignId,$contactId,campaigns_rewards_text_v100($case['reason_code']??'service_recovery',80),campaigns_rewards_text_v100($case['summary']??'Make Good',500),mb_strimwidth(trim((string)($case['internal_notes']??'')),0,8000,'…'),(string)$campaign['environment'],$actorUserId]);
        $caseId=(int)$pdo->lastInsertId();
        $enrollment=campaigns_rewards_enroll_contact_v100($pdo,$campaignId,$contactId,$actorUserId,'make_good','make-good-enroll:'.$public);
        $issued=[];
        foreach(array_values(array_unique(array_filter(array_map('intval',$rewardProductIds)))) as $rewardProductId){
            $issued[]=campaigns_rewards_issue_reward_v100($pdo,$campaignId,$rewardProductId,$contactId,$actorUserId,[
                'campaign_case_id'=>$caseId,'campaign_enrollment_id'=>(int)$enrollment['id'],
                'idempotency_key'=>'make-good:'.$public.':'.$rewardProductId,
            ]);
        }
        if(!$issued)throw new RuntimeException('Choose at least one Reward Product for Make Good.');
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.case_opened',['campaign_id'=>$campaignId,'contact_id'=>$contactId,'campaign_case_id'=>$caseId],[
        'summary'=>'Make Good case opened','merchant_public_id'=>$campaign['merchant_public_id'],'campaign_public_id'=>$campaign['public_id'],'case_public_id'=>$public,
    ],(string)$campaign['environment'],$actorUserId);
    return ['case_id'=>$caseId,'case_public_id'=>$public,'campaign'=>$campaign,'enrollment'=>$enrollment,'issuances'=>$issued];
}

function campaigns_rewards_loyalty_adjust_v100(PDO $pdo,int $programId,int $contactId,int $actorUserId,int $points,string $sourceType='manual',string $sourceId=''): array
{
    $q=$pdo->prepare("SELECT lp.*,m.public_id merchant_public_id,m.sandbox_mode FROM loyalty_programs lp INNER JOIN merchant_accounts m ON m.id=lp.merchant_id WHERE lp.id=? LIMIT 1");
    $q->execute([$programId]);$program=$q->fetch()?:throw new RuntimeException('Loyalty Program not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$program['merchant_id'],$actorUserId,'loyalty.adjust');
    $pdo->prepare("INSERT INTO loyalty_accounts (program_id,contact_id,status,joined_at) VALUES (?,?,'active',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE updated_at=UTC_TIMESTAMP()")
        ->execute([$programId,$contactId]);
    $q=$pdo->prepare('SELECT * FROM loyalty_accounts WHERE program_id=? AND contact_id=? LIMIT 1');$q->execute([$programId,$contactId]);$account=$q->fetch()?:throw new RuntimeException('Loyalty Account unavailable.');
    $entry=$points>=0?'earn':'spend';
    $pdo->prepare("INSERT INTO loyalty_ledger (loyalty_account_id,entry_type,points_delta,source_type,source_id,metadata_json) VALUES (?,?,?,?,?,'{}')")
        ->execute([(int)$account['id'],$entry,$points,$sourceType,$sourceId]);
    $balanceStmt=$pdo->prepare('SELECT COALESCE(SUM(points_delta),0) FROM loyalty_ledger WHERE loyalty_account_id=?');$balanceStmt->execute([(int)$account['id']]);$balance=(int)$balanceStmt->fetchColumn();
    campaigns_rewards_activity_event_v100($pdo,(int)$program['merchant_id'],$points>=0?'loyalty.earned':'loyalty.spent',['contact_id'=>$contactId],[
        'summary'=>'Loyalty balance changed','merchant_public_id'=>$program['merchant_public_id'],'loyalty_account_public_id'=>'loyalty-'.$account['id'],
        'points_delta'=>$points,'balance'=>$balance,
    ],!empty($program['sandbox_mode'])?'sandbox':'production',$actorUserId);
    return ['account'=>$account,'balance'=>$balance,'points_delta'=>$points];
}

function campaigns_rewards_simulate_campaign_v100(PDO $pdo,int $campaignId,int $actorUserId,array $input=[]): array
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$campaign['merchant_id'],$actorUserId,'campaigns.audience.preview');
    $q=$pdo->prepare("SELECT COUNT(DISTINCT am.contact_id) FROM campaign_audiences a INNER JOIN campaign_audience_members am ON am.audience_id=a.id WHERE a.campaign_id=? AND a.status='active'");
    $q->execute([$campaignId]);$eligible=(int)$q->fetchColumn();
    $suppressed=0;$projected=$eligible;
    if($campaign['max_enrollments']!==null)$projected=min($projected,(int)$campaign['max_enrollments']);
    $value=$pdo->prepare("SELECT COALESCE(SUM(COALESCE(rp.retail_value_minor,0)*i.quantity),0) face_value,COALESCE(SUM(COALESCE(rp.internal_cost_minor,0)*i.quantity),0) cost
      FROM campaign_reward_sets rs INNER JOIN campaign_reward_set_items i ON i.reward_set_id=rs.id INNER JOIN reward_products rp ON rp.id=i.reward_product_id WHERE rs.campaign_id=?");
    $value->execute([$campaignId]);$unit=$value->fetch()?:['face_value'=>0,'cost'=>0];
    $face=$projected*(int)$unit['face_value'];$cost=$projected*(int)$unit['cost'];
    $snapshot=['campaign_id'=>$campaignId,'version'=>(int)$campaign['current_version_no'],'eligible'=>$eligible,'suppressed'=>$suppressed,'projected'=>$projected,'face_value_minor'=>$face,'cost_minor'=>$cost];
    $public=campaigns_rewards_uuid_v100();
    $pdo->prepare("INSERT INTO campaign_simulation_runs
      (public_id,merchant_id,campaign_id,campaign_version_id,requested_by_user_id,input_snapshot_json,result_summary_json,eligible_count,suppressed_count,projected_issuance_count,projected_face_value_minor,projected_cost_minor,inventory_risk_count,status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,'complete')")
      ->execute([$public,(int)$campaign['merchant_id'],$campaignId,null,$actorUserId,campaigns_rewards_json_v100($input),campaigns_rewards_json_v100($snapshot),$eligible,$suppressed,$projected,$face,$cost]);
    return $snapshot+['public_id'=>$public];
}

function campaigns_rewards_reconcile_v100(PDO $pdo,?int $merchantId,int $actorUserId=0,string $scope='all'): array
{
    if($merchantId&&$actorUserId>0)campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'analytics.view');
    $public=campaigns_rewards_uuid_v100();
    $pdo->prepare("INSERT INTO campaign_reconciliation_runs (public_id,merchant_id,scope_type,status,started_by,started_by_user_id,started_at)
      VALUES (?,?,'all','running',?, ?,UTC_TIMESTAMP())")->execute([$public,$merchantId?:null,$actorUserId>0?'user':'system',$actorUserId?:null]);
    $runId=(int)$pdo->lastInsertId();$findings=[];
    $where=$merchantId?' AND ri.merchant_id='.(int)$merchantId:'';
    $rows=$pdo->query("SELECT ri.id,ri.status FROM reward_issuances ri WHERE ri.status='claimed' AND NOT EXISTS (SELECT 1 FROM reward_claims rc WHERE rc.reward_issuance_id=ri.id AND rc.status='claimed'){$where}")->fetchAll()?:[];
    foreach($rows as $row)$findings[]=['severity'=>'error','type'=>'claimed_issuance_missing_claim','subject_type'=>'reward_issuance','subject_id'=>(int)$row['id'],'expected'=>['claim'=>'claimed'],'actual'=>['claim'=>'missing']];
    $rows=$pdo->query("SELECT rc.id,rc.reward_issuance_id FROM reward_claims rc INNER JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id WHERE rc.status='claimed' AND ri.status NOT IN ('claimed','sent','viewed','issued')".($merchantId?' AND rc.merchant_id='.(int)$merchantId:''))->fetchAll()?:[];
    foreach($rows as $row)$findings[]=['severity'=>'warning','type'=>'claim_issuance_state_mismatch','subject_type'=>'reward_claim','subject_id'=>(int)$row['id'],'expected'=>['issuance_state'=>'claimable_or_claimed'],'actual'=>['issuance_id'=>(int)$row['reward_issuance_id']]];
    $ins=$pdo->prepare("INSERT INTO campaign_reconciliation_findings (run_id,severity,finding_type,subject_type,subject_id,expected_json,actual_json,repair_status,created_at)
      VALUES (?,?,?,?,?,?,?,'none',UTC_TIMESTAMP())");
    foreach($findings as $f)$ins->execute([$runId,$f['severity'],$f['type'],$f['subject_type'],$f['subject_id'],campaigns_rewards_json_v100($f['expected']),campaigns_rewards_json_v100($f['actual'])]);
    $summary=['findings'=>count($findings),'errors'=>count(array_filter($findings,fn($f)=>$f['severity']==='error')),'warnings'=>count(array_filter($findings,fn($f)=>$f['severity']==='warning')),'repairs_applied'=>0];
    $pdo->prepare("UPDATE campaign_reconciliation_runs SET status='completed',completed_at=UTC_TIMESTAMP(),summary_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($summary),$runId]);
    if($merchantId){
        $m=campaigns_rewards_platform_merchant_v100($pdo,$merchantId);
        if($m)campaigns_rewards_activity_event_v100($pdo,$merchantId,'reconciliation.completed',[],[
            'summary'=>'Campaigns & Rewards reconciliation completed','merchant_public_id'=>$m['public_id'],'finding_count'=>count($findings),
        ],!empty($m['sandbox_mode'])?'sandbox':'production',$actorUserId?:null,$actorUserId>0?'user':'system');
    }
    return ['run_id'=>$runId,'public_id'=>$public,'summary'=>$summary,'findings'=>$findings,'side_effects_replayed'=>false];
}

function campaigns_rewards_recommendation_v100(PDO $pdo,int $merchantId,int $forUserId,string $type,string $summary,array $evidenceRefs,array $impactPreview=[],?int $agentId=null): int
{
    $public=campaigns_rewards_uuid_v100();
    $pdo->prepare("INSERT INTO campaign_agent_recommendations
      (public_id,merchant_id,recommendation_type,summary,status,evidence_refs_json,impact_preview_json,created_by_agent_id,created_for_user_id,created_at)
      VALUES (?,?,?,?,'proposed',?,?,?,?,UTC_TIMESTAMP())")
      ->execute([$public,$merchantId,campaigns_rewards_text_v100($type,80),campaigns_rewards_text_v100($summary,1000),campaigns_rewards_json_v100(array_slice($evidenceRefs,0,30)),campaigns_rewards_json_v100($impactPreview),$agentId,$forUserId]);
    return (int)$pdo->lastInsertId();
}

function campaigns_rewards_cognitive_object_canonical_v100(PDO $pdo,array $user,array $ref): ?array
{
    $uid=(int)($user['id']??0);$type=(string)($ref['type']??'');$id=trim((string)($ref['id']??''));if($uid<1||$id==='')return null;
    $map=[
        'merchant'=>['merchant_accounts','public_id','id'],
        'merchant_location'=>['merchant_locations','public_id','merchant_id'],
        'merchant_team_member'=>['merchant_members','id','merchant_id'],
        'campaign'=>['campaigns','public_id','merchant_id'],
        'campaign_enrollment'=>['campaign_enrollments','public_id','campaign_id'],
        'campaign_case'=>['campaign_cases','public_id','merchant_id'],
        'reward_product'=>['reward_products','public_id','merchant_id'],
        'reward_issuance'=>['reward_issuances','public_id','merchant_id'],
        'reward_claim'=>['reward_claims','public_id','merchant_id'],
        'claim_code'=>['merchant_claim_codes','public_id','merchant_id'],
        'loyalty_account'=>['loyalty_accounts','id','program_id'],
    ];
    if(!isset($map[$type]))return null;
    [$table,$key,$scope]=$map[$type];
    if($type==='merchant_team_member'||$type==='loyalty_account'){if(!ctype_digit($id))return null;$queryId=(int)$id;}else{$queryId=$id;}
    $stmt=$pdo->prepare("SELECT * FROM {$table} WHERE {$key}=? LIMIT 1");$stmt->execute([$queryId]);$row=$stmt->fetch();if(!$row)return null;
    $merchantId=match($type){
        'merchant'=>(int)$row['id'],
        'campaign_enrollment'=>(int)(campaigns_rewards_campaign_platform_v100($pdo,(int)$row['campaign_id'])['merchant_id']??0),
        'loyalty_account'=>(int)($pdo->query("SELECT merchant_id FROM loyalty_programs WHERE id=".(int)$row['program_id'])->fetchColumn()?:0),
        default=>(int)($row['merchant_id']??0),
    };
    return $merchantId>0&&campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'merchant.view')?($row+['_merchant_id'=>$merchantId]):null;
}

function campaigns_rewards_cognitive_context_canonical_v100(PDO $pdo,array $user,array $ref): array
{
    $row=campaigns_rewards_cognitive_object_canonical_v100($pdo,$user,$ref);if(!$row)throw new RuntimeException('Campaigns & Rewards object is unavailable.');
    $type=(string)$ref['type'];
    $allowed=match($type){
        'merchant'=>['public_id','name','slug','business_name','status','timezone','currency','sandbox_mode','created_at','updated_at'],
        'merchant_location'=>['public_id','merchant_id','name','location_type','city','region','country','timezone','is_active','created_at','updated_at'],
        'merchant_team_member'=>['merchant_id','user_id','role_id','status','is_owner','joined_at','suspended_at','removed_at'],
        'campaign'=>['public_id','public_code','merchant_id','campaign_type_id','name','slug','description','status','environment','objective','current_version_no','starts_at','ends_at','audience_mode','budget_minor','budget_currency','max_enrollments','max_rewards','per_contact_limit','created_at','updated_at'],
        'campaign_enrollment'=>['public_id','campaign_id','campaign_version_id','source','status','environment','qualified_at','enrolled_at','completed_at','created_at','updated_at'],
        'campaign_case'=>['public_id','merchant_id','campaign_id','case_type','reason_code','summary','status','environment','created_at','resolved_at','updated_at'],
        'reward_product'=>['public_id','merchant_id','reward_type_id','name','description','sku','retail_value_minor','internal_cost_minor','currency','inventory_mode','fulfillment_type','expiration_policy','expiration_days','claim_limit','transferable','regiftable','is_active','created_at','updated_at'],
        'reward_issuance'=>['public_id','merchant_id','campaign_id','campaign_version_id','reward_product_id','status','environment','quantity','remaining_quantity','face_value_minor','currency','issued_at','sent_at','viewed_at','claimed_at','expires_at','voided_at','created_at','updated_at'],
        'reward_claim'=>['public_id','merchant_id','campaign_id','reward_issuance_id','location_id','merchant_member_id','quantity','value_minor','currency','status','environment','claimed_at','reversed_at','created_at','updated_at'],
        'claim_code'=>['public_id','merchant_id','display_name','location_id','merchant_member_id','device_label','status','active_from','active_until','daily_claim_limit','total_claim_limit','max_value_minor','currency','last_used_at','created_at','updated_at'],
        'loyalty_account'=>['program_id','current_tier_id','status','joined_at','updated_at'],
        default=>[],
    };
    $safe=[];foreach($allowed as $key)if(array_key_exists($key,$row))$safe[$key]=$row[$key];
    return ['record'=>$safe,'authority'=>'campaigns_rewards_domain_v100','build'=>VP3_CAMPAIGNS_REWARDS_DOMAIN_V100];
}


function campaigns_rewards_cognitive_relationships_canonical_v100(PDO $pdo,array $user,array $ref): array
{
    $row=campaigns_rewards_cognitive_object_canonical_v100($pdo,$user,$ref);if(!$row)return [];
    $type=(string)($ref['type']??'');$scope=(string)($ref['scope']??'workspace');$edges=[];
    $add=static function(array &$edges,string $relation,string $type,mixed $id,string $scope): void{
        if((string)$id==='')return;
        $edges[]=['relation'=>$relation,'object_ref'=>campaigns_rewards_ref_v100($type,$id,$scope),'provenance'=>'campaigns_rewards_domain_v100','confidence'=>1,'confirmation_state'=>'deterministic'];
    };
    if($type==='merchant'){
        $q=$pdo->prepare("SELECT public_id FROM campaigns WHERE merchant_id=? AND status<>'archived' ORDER BY updated_at DESC,id DESC LIMIT 20");$q->execute([(int)$row['id']]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$add($edges,'owns','campaign',$id,$scope);
        $q=$pdo->prepare("SELECT public_id FROM merchant_locations WHERE merchant_id=? AND is_active=1 ORDER BY id LIMIT 20");$q->execute([(int)$row['id']]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$add($edges,'has_location','merchant_location',$id,$scope);
    }elseif($type==='merchant_location'){
        $m=campaigns_rewards_platform_merchant_v100($pdo,(int)$row['merchant_id']);if($m)$add($edges,'location_of','merchant',$m['public_id'],$scope);
    }elseif($type==='campaign'){
        $m=campaigns_rewards_platform_merchant_v100($pdo,(int)$row['merchant_id']);if($m)$add($edges,'owned_by','merchant',$m['public_id'],$scope);
        $q=$pdo->prepare("SELECT DISTINCT rp.public_id FROM campaign_reward_sets rs INNER JOIN campaign_reward_set_items i ON i.reward_set_id=rs.id INNER JOIN reward_products rp ON rp.id=i.reward_product_id WHERE rs.campaign_id=? ORDER BY rp.id LIMIT 20");
        $q->execute([(int)$row['id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$add($edges,'offers','reward_product',$id,$scope);
    }elseif($type==='campaign_enrollment'){
        $c=campaigns_rewards_campaign_platform_v100($pdo,(int)$row['campaign_id']);if($c)$add($edges,'enrolled_in','campaign',$c['public_id'],$scope);
    }elseif($type==='campaign_case'){
        $c=campaigns_rewards_campaign_platform_v100($pdo,(int)$row['campaign_id']);if($c)$add($edges,'case_for','campaign',$c['public_id'],$scope);
    }elseif($type==='reward_product'){
        $q=$pdo->prepare("SELECT DISTINCT c.public_id FROM campaign_reward_sets rs INNER JOIN campaign_reward_set_items i ON i.reward_set_id=rs.id INNER JOIN campaigns c ON c.id=rs.campaign_id WHERE i.reward_product_id=? ORDER BY c.id DESC LIMIT 20");
        $q->execute([(int)$row['id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$add($edges,'offered_by','campaign',$id,$scope);
    }elseif($type==='reward_issuance'){
        $c=campaigns_rewards_campaign_platform_v100($pdo,(int)$row['campaign_id']);if($c)$add($edges,'issued_by_campaign','campaign',$c['public_id'],$scope);
        $rp=campaigns_rewards_reward_product_v100($pdo,(int)$row['reward_product_id']);if($rp)$add($edges,'instance_of','reward_product',$rp['public_id'],$scope);
    }elseif($type==='reward_claim'){
        $q=$pdo->prepare("SELECT public_id FROM reward_issuances WHERE id=? LIMIT 1");$q->execute([(int)$row['reward_issuance_id']]);$id=$q->fetchColumn();if($id)$add($edges,'claims','reward_issuance',$id,$scope);
    }elseif($type==='claim_code'){
        $m=campaigns_rewards_platform_merchant_v100($pdo,(int)$row['merchant_id']);if($m)$add($edges,'authorizes_for','merchant',$m['public_id'],$scope);
    }
    return array_slice($edges,0,30);
}
