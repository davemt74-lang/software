<?php
declare(strict_types=1);

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
    $source=substr(preg_replace('/[^a-z0-9_-]+/i','_',trim($source))??'admin_assigned',0,40);
    if($source==='')$source='admin_assigned';
    $status=(int)$package['is_trial']===1?'trialing':($source==='complimentary'?'complimentary':'active');
    $now=new DateTimeImmutable('now');
    if($endsAt!==null&&trim($endsAt)!==''){
        $end=new DateTimeImmutable($endsAt);
    }elseif((int)$package['is_trial']===1&&((int)$package['trial_days'])>0){
        $end=$now->modify('+'.(int)$package['trial_days'].' days');
    }else{$end=null;}
    $periodEnd=$status==='trialing'?$end:$now->modify('+1 month');

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        // Lock the account so concurrent package changes serialize. This also
        // allows signup to create the user and package atomically in one outer transaction.
        $userStmt=$pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');
        $userStmt->execute([$userId]);if(!$userStmt->fetchColumn())throw new RuntimeException('User account not found.');
        $old=subscription_current_for_user_id($userId,$pdo,true);
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
        if($ownsTransaction)$pdo->commit();
        return $subscriptionId;
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
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
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $userStmt=$pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');$userStmt->execute([$userId]);if(!$userStmt->fetchColumn())throw new RuntimeException('User account not found.');
        $old=subscription_current_for_user_id($userId,$pdo,true);
        $pdo->prepare("UPDATE user_subscriptions SET status='cancelled',ends_at=COALESCE(ends_at,NOW()),updated_at=NOW() WHERE user_id=? AND status IN ('trialing','active','complimentary')")->execute([$userId]);
        subscription_audit($pdo,$actorUserId,$userId,'package_removed',(int)($old['package_id']??0)?:null,null,$reason,[]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
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
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $userStmt=$pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');$userStmt->execute([$userId]);if(!$userStmt->fetchColumn())throw new RuntimeException('User account not found.');
        $stmt=$pdo->prepare('INSERT INTO ai_token_credits (user_id,amount,remaining_amount,source,reason,expires_at,created_by) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$userId,$amount,$amount,$source,mb_strimwidth(trim($reason),0,500,''),$expiresAt?:null,$actorUserId&&$actorUserId>0?$actorUserId:null]);
        $id=(int)$pdo->lastInsertId();
        subscription_audit($pdo,$actorUserId,$userId,'tokens_added',null,null,$reason,['credit_id'=>$id,'amount'=>$amount,'source'=>$source,'expires_at'=>$expiresAt]);
        if($ownsTransaction)$pdo->commit();
        return $id;
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function subscription_remove_token_credit(int $creditId,int $userId,?int $actorUserId=null,string $reason=''): void
{
    $pdo=db();if(!$pdo||!subscription_schema_ready($pdo))throw new RuntimeException('Token credit storage is unavailable.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT amount,remaining_amount FROM ai_token_credits WHERE id=? AND user_id=? LIMIT 1 FOR UPDATE');$stmt->execute([$creditId,$userId]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Token credit not found.');
        $pdo->prepare('UPDATE ai_token_credits SET remaining_amount=0,updated_at=NOW() WHERE id=? AND user_id=?')->execute([$creditId,$userId]);
        subscription_audit($pdo,$actorUserId,$userId,'tokens_removed',null,null,$reason,['credit_id'=>$creditId,'previous_remaining'=>(int)$row['remaining_amount']]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}