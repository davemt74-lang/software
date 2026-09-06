<?php
declare(strict_types=1);

/**
 * Customer-facing plan-management state.
 *
 * This layer intentionally does not grant a paid package before a billing
 * provider confirms payment. Paid choices become durable pending-billing
 * requests that Phase 2 can hand directly to checkout/webhook processing.
 */

function subscription_self_service_interval(string $interval): string
{
    return strtolower(trim($interval)) === 'annual' ? 'annual' : 'monthly';
}

function subscription_self_service_storage_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo&&subscription_schema_ready($pdo)&&subscription_self_service_schema_ready($pdo);
}

function subscription_self_service_price_cents(array $package,string $interval): ?int
{
    $interval=subscription_self_service_interval($interval);
    $monthly=max(0,(int)($package['monthly_price_cents']??0));
    $annual=max(0,(int)($package['annual_price_cents']??0));
    if($interval==='annual'){
        if($annual===0&&$monthly>0)return null;
        return $annual;
    }
    return $monthly;
}

function subscription_self_service_open_request(int $userId,?PDO $pdo=null,bool $forUpdate=false): ?array
{
    $pdo??=db();
    if(!$pdo||$userId<1||!subscription_self_service_storage_ready($pdo))return null;
    $sql="SELECT r.*,fp.name from_package_name,tp.name target_package_name,tp.slug target_package_slug
      FROM subscription_plan_requests r
      LEFT JOIN subscription_packages fp ON fp.id=r.from_package_id
      LEFT JOIN subscription_packages tp ON tp.id=r.target_package_id
      WHERE r.user_id=? AND r.status IN ('pending_billing','scheduled')
      ORDER BY r.id DESC LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$userId]);$row=$stmt->fetch();
    return $row?:null;
}

function subscription_self_service_history(int $userId,int $limit=20): array
{
    $pdo=db();if(!$pdo||$userId<1||!subscription_self_service_storage_ready($pdo))return [];
    $limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT r.*,fp.name from_package_name,tp.name target_package_name
      FROM subscription_plan_requests r
      LEFT JOIN subscription_packages fp ON fp.id=r.from_package_id
      LEFT JOIN subscription_packages tp ON tp.id=r.target_package_id
      WHERE r.user_id=? ORDER BY r.id DESC LIMIT {$limit}");
    $stmt->execute([$userId]);return $stmt->fetchAll()?:[];
}

function subscription_self_service_supersede_open(PDO $pdo,int $userId,string $reason): void
{
    $stmt=$pdo->prepare("UPDATE subscription_plan_requests
      SET status='superseded',resolved_at=NOW(),updated_at=NOW(),metadata_json=JSON_SET(COALESCE(metadata_json,'{}'),'$.superseded_reason',?)
      WHERE user_id=? AND status IN ('pending_billing','scheduled')");
    $stmt->execute([$reason,$userId]);
}

function subscription_self_service_insert_request(
    PDO $pdo,
    int $userId,
    ?int $subscriptionId,
    ?int $fromPackageId,
    ?int $targetPackageId,
    string $action,
    string $interval,
    string $status,
    int $amountCents,
    ?string $effectiveAt,
    array $metadata=[]
): int {
    $stmt=$pdo->prepare("INSERT INTO subscription_plan_requests
      (user_id,subscription_id,from_package_id,target_package_id,action,billing_interval,status,amount_cents,effective_at,metadata_json)
      VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $userId,$subscriptionId,$fromPackageId,$targetPackageId,$action,
        subscription_self_service_interval($interval),$status,max(0,$amountCents),$effectiveAt,
        json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    ]);
    return (int)$pdo->lastInsertId();
}

function subscription_self_service_effective_at(?array $subscription): ?string
{
    if(!$subscription)return null;
    $candidates=[
        trim((string)($subscription['ends_at']??'')),
        trim((string)($subscription['current_period_end']??'')),
    ];
    foreach($candidates as $value){
        if($value===''||strtotime($value)===false)continue;
        if(strtotime($value)>time())return date('Y-m-d H:i:s',strtotime($value));
    }
    return null;
}

function subscription_self_service_select_plan(array $user,int $packageId,string $interval='monthly'): array
{
    $userId=(int)($user['id']??0);if($userId<1)throw new RuntimeException('Sign in to manage your plan.');
    $pdo=db();if(!$pdo||!subscription_self_service_storage_ready($pdo))throw new RuntimeException('Plan management is unavailable until the database upgrade is complete.');
    $interval=subscription_self_service_interval($interval);
    $package=subscription_package($packageId);
    if(!$package||(int)$package['is_active']!==1||(int)$package['is_public']!==1)throw new RuntimeException('That package is not available for self-service selection.');
    if((int)$package['is_trial']===1)throw new RuntimeException('Trial packages cannot be restarted or selected manually.');
    $price=subscription_self_service_price_cents($package,$interval);
    if($price===null)throw new RuntimeException('Annual billing is not available for that package.');

    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$userId]);if(!$lock->fetchColumn())throw new RuntimeException('Account not found.');
        $current=subscription_current_for_user_id($userId,$pdo,true);
        if($current&&(int)$current['package_id']===$packageId){
            if($ownsTransaction)$pdo->commit();
            return ['status'=>'current','package'=>$package,'request_id'=>0,'effective_at'=>null];
        }
        subscription_self_service_supersede_open($pdo,$userId,'Replaced by a newer self-service plan selection.');
        $fromPackageId=$current?(int)$current['package_id']:null;
        $subscriptionId=$current?(int)$current['id']:null;
        $effectiveAt=subscription_self_service_effective_at($current);

        if($price>0){
            $requestId=subscription_self_service_insert_request($pdo,$userId,$subscriptionId,$fromPackageId,$packageId,'change',$interval,'pending_billing',$price,null,[
                'source'=>'self_service','payment_required'=>true,
            ]);
            subscription_audit($pdo,$userId,$userId,'self_service_plan_selected',$fromPackageId,$packageId,'Paid plan selected; payment required before activation.',[
                'request_id'=>$requestId,'billing_interval'=>$interval,'amount_cents'=>$price,'status'=>'pending_billing',
            ]);
            if($ownsTransaction)$pdo->commit();
            return ['status'=>'pending_billing','package'=>$package,'request_id'=>$requestId,'effective_at'=>null];
        }

        $preserveCurrent=$current&&$effectiveAt!==null&&((int)($current['is_trial']??0)===1||(int)($current['billing_required']??0)===1);
        if($preserveCurrent){
            $requestId=subscription_self_service_insert_request($pdo,$userId,$subscriptionId,$fromPackageId,$packageId,'change',$interval,'scheduled',0,$effectiveAt,[
                'source'=>'self_service','payment_required'=>false,
            ]);
            subscription_audit($pdo,$userId,$userId,'self_service_plan_scheduled',$fromPackageId,$packageId,'Free plan change scheduled for the end of current access.',[
                'request_id'=>$requestId,'effective_at'=>$effectiveAt,
            ]);
            if($ownsTransaction)$pdo->commit();
            return ['status'=>'scheduled','package'=>$package,'request_id'=>$requestId,'effective_at'=>$effectiveAt];
        }

        $newSubscriptionId=subscription_assign_package($userId,$packageId,'self_service',$userId,null,null,false,'Self-service free plan change');
        $requestId=subscription_self_service_insert_request($pdo,$userId,$newSubscriptionId,$fromPackageId,$packageId,'change',$interval,'applied',0,date('Y-m-d H:i:s'),[
            'source'=>'self_service','payment_required'=>false,
        ]);
        $pdo->prepare("UPDATE subscription_plan_requests SET resolved_at=NOW() WHERE id=?")->execute([$requestId]);
        subscription_audit($pdo,$userId,$userId,'self_service_plan_applied',$fromPackageId,$packageId,'Free plan applied immediately.',[
            'request_id'=>$requestId,'subscription_id'=>$newSubscriptionId,
        ]);
        if($ownsTransaction)$pdo->commit();
        return ['status'=>'applied','package'=>$package,'request_id'=>$requestId,'effective_at'=>date('Y-m-d H:i:s')];
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function subscription_self_service_schedule_cancel(array $user): array
{
    $userId=(int)($user['id']??0);if($userId<1)throw new RuntimeException('Sign in to manage your plan.');
    $pdo=db();if(!$pdo||!subscription_self_service_storage_ready($pdo))throw new RuntimeException('Plan management is unavailable until the database upgrade is complete.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$userId]);if(!$lock->fetchColumn())throw new RuntimeException('Account not found.');
        $current=subscription_current_for_user_id($userId,$pdo,true);if(!$current)throw new RuntimeException('There is no active plan to cancel.');
        if((int)($current['is_trial']??0)===1)throw new RuntimeException('Your trial already ends automatically; there is no recurring plan to cancel.');
        if((int)($current['billing_required']??0)!==1)throw new RuntimeException('This package is managed by an administrator and cannot be canceled from this page.');
        $effectiveAt=subscription_self_service_effective_at($current);if($effectiveAt===null)throw new RuntimeException('A renewal date is required before cancellation can be scheduled.');
        subscription_self_service_supersede_open($pdo,$userId,'Replaced by a cancellation request.');
        $requestId=subscription_self_service_insert_request($pdo,$userId,(int)$current['id'],(int)$current['package_id'],null,'cancel','monthly','scheduled',0,$effectiveAt,[
            'source'=>'self_service','cancel_at_period_end'=>true,
        ]);
        subscription_audit($pdo,$userId,$userId,'self_service_cancel_scheduled',(int)$current['package_id'],null,'Plan cancellation scheduled for period end.',[
            'request_id'=>$requestId,'effective_at'=>$effectiveAt,
        ]);
        if($ownsTransaction)$pdo->commit();
        return ['status'=>'scheduled','request_id'=>$requestId,'effective_at'=>$effectiveAt];
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function subscription_self_service_cancel_request(array $user,int $requestId): void
{
    $userId=(int)($user['id']??0);if($userId<1||$requestId<1)throw new RuntimeException('Plan request not found.');
    $pdo=db();if(!$pdo||!subscription_self_service_storage_ready($pdo))throw new RuntimeException('Plan management is unavailable.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM subscription_plan_requests WHERE id=? AND user_id=? AND status IN ('pending_billing','scheduled') LIMIT 1 FOR UPDATE");
        $stmt->execute([$requestId,$userId]);$request=$stmt->fetch();if(!$request)throw new RuntimeException('That plan request is no longer active.');
        $pdo->prepare("UPDATE subscription_plan_requests SET status='cancelled',resolved_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=?")->execute([$requestId,$userId]);
        subscription_audit($pdo,$userId,$userId,'self_service_request_cancelled',(int)($request['from_package_id']??0)?:null,(int)($request['target_package_id']??0)?:null,'Self-service plan request cancelled.',[
            'request_id'=>$requestId,'request_action'=>$request['action'],'previous_status'=>$request['status'],
        ]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function subscription_self_service_apply_due_for_user(int $userId,?PDO $pdo=null): int
{
    $pdo??=db();if(!$pdo||$userId<1||!subscription_self_service_storage_ready($pdo))return 0;
    $idsStmt=$pdo->prepare("SELECT id FROM subscription_plan_requests WHERE user_id=? AND status='scheduled' AND effective_at IS NOT NULL AND effective_at<=NOW() ORDER BY id ASC LIMIT 10");
    $idsStmt->execute([$userId]);$ids=array_map('intval',array_column($idsStmt->fetchAll()?:[],'id'));$applied=0;
    foreach($ids as $requestId){
        $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare("SELECT * FROM subscription_plan_requests WHERE id=? AND user_id=? AND status='scheduled' AND effective_at<=NOW() LIMIT 1 FOR UPDATE");
            $stmt->execute([$requestId,$userId]);$request=$stmt->fetch();if(!$request){if($ownsTransaction)$pdo->commit();continue;}
            $requestSubscription=null;
            if((int)($request['subscription_id']??0)>0){
                $subStmt=$pdo->prepare("SELECT * FROM user_subscriptions WHERE id=? AND user_id=? LIMIT 1 FOR UPDATE");
                $subStmt->execute([(int)$request['subscription_id'],$userId]);$requestSubscription=$subStmt->fetch()?:null;
                if(!$requestSubscription||in_array((string)$requestSubscription['status'],['replaced','cancelled'],true)){
                    $pdo->prepare("UPDATE subscription_plan_requests SET status='superseded',resolved_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$requestId]);
                    if($ownsTransaction)$pdo->commit();continue;
                }
            }
            if((string)$request['action']==='cancel'){
                $pdo->prepare("UPDATE user_subscriptions SET status='cancelled',ends_at=LEAST(COALESCE(ends_at,NOW()),NOW()),updated_at=NOW() WHERE id=? AND user_id=? AND status IN ('active','complimentary')")->execute([(int)$request['subscription_id'],$userId]);
                $pdo->prepare("UPDATE subscription_plan_requests SET status='applied',resolved_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$requestId]);
                subscription_audit($pdo,$userId,$userId,'self_service_cancel_applied',(int)($request['from_package_id']??0)?:null,null,'Scheduled plan cancellation applied.',['request_id'=>$requestId]);
                $applied++;
            }elseif((string)$request['action']==='change'&&(int)($request['target_package_id']??0)>0){
                $target=subscription_package((int)$request['target_package_id']);
                $price=$target?subscription_self_service_price_cents($target,(string)$request['billing_interval']):null;
                if(!$target||(int)$target['is_active']!==1||(int)$target['is_public']!==1||(int)$target['is_trial']===1||$price!==0){
                    $pdo->prepare("UPDATE subscription_plan_requests SET status='failed',resolved_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$requestId]);
                    if($ownsTransaction)$pdo->commit();continue;
                }
                $newSubscriptionId=subscription_assign_package($userId,(int)$target['id'],'self_service',$userId,null,null,false,'Scheduled self-service free plan change');
                $pdo->prepare("UPDATE subscription_plan_requests SET subscription_id=?,status='applied',resolved_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$newSubscriptionId,$requestId]);
                subscription_audit($pdo,$userId,$userId,'self_service_plan_applied',(int)($request['from_package_id']??0)?:null,(int)$target['id'],'Scheduled free plan change applied.',['request_id'=>$requestId,'subscription_id'=>$newSubscriptionId]);
                $applied++;
            }
            if($ownsTransaction)$pdo->commit();
        }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();error_log('VP3 scheduled plan change failed: '.$e->getMessage());}
    }
    return $applied;
}
