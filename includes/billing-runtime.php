<?php
declare(strict_types=1);

function billing_subscription_for_user(int $userId,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo||$userId<1||!billing_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT * FROM billing_subscriptions WHERE user_id=? AND provider='stripe' AND status NOT IN ('canceled','incomplete_expired') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);$row=$stmt->fetch();return $row?:null;
}

function billing_subscription_by_provider_id(string $subscriptionId,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo||$subscriptionId===''||!billing_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT * FROM billing_subscriptions WHERE provider='stripe' AND provider_subscription_id=? LIMIT 1");$stmt->execute([$subscriptionId]);$row=$stmt->fetch();return $row?:null;
}

function billing_plan_request(int $requestId,int $userId,?PDO $pdo=null,bool $forUpdate=false): ?array
{
    $pdo??=db();if(!$pdo||$requestId<1||$userId<1||!subscription_self_service_schema_ready($pdo))return null;
    $sql='SELECT * FROM subscription_plan_requests WHERE id=? AND user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');$stmt=$pdo->prepare($sql);$stmt->execute([$requestId,$userId]);$row=$stmt->fetch();return $row?:null;
}

function billing_pending_request_for_package(int $userId,int $packageId,?PDO $pdo=null,bool $forUpdate=false): ?array
{
    $pdo??=db();if(!$pdo||$userId<1||$packageId<1)return null;
    $sql="SELECT * FROM subscription_plan_requests WHERE user_id=? AND target_package_id=? AND action='change' AND status='pending_billing' ORDER BY id DESC LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$userId,$packageId]);$row=$stmt->fetch();return $row?:null;
}

function billing_stripe_user_id(array $subscription,?PDO $pdo=null): int
{
    $pdo??=db();$subscriptionId=trim((string)($subscription['id']??''));
    if($subscriptionId!==''){$known=billing_subscription_by_provider_id($subscriptionId,$pdo);if($known)return (int)$known['user_id'];}
    $metaId=(int)($subscription['metadata']['vp3_user_id']??0);if($metaId>0)return $metaId;
    $customer=$subscription['customer']??'';if(is_array($customer))$customer=$customer['id']??'';
    return billing_user_id_for_customer(trim((string)$customer),$pdo);
}

function billing_stripe_request_id(array $subscription): int
{
    return max(0,(int)($subscription['metadata']['vp3_plan_request_id']??0));
}

function billing_stripe_provider_status(array $subscription): string
{
    return strtolower(trim((string)($subscription['status']??'')));
}

function billing_stripe_is_entitlement_active(array $subscription): bool
{
    $status=billing_stripe_provider_status($subscription);
    if(!in_array($status,['active','trialing'],true))return false;
    $pending=$subscription['pending_update']??null;
    return $pending===null||$pending===[]||$pending==='';
}

function billing_stripe_customer_id(array $subscription): string
{
    $customer=$subscription['customer']??'';if(is_array($customer))$customer=$customer['id']??'';return trim((string)$customer);
}

function billing_local_subscription_metadata(array $providerSubscription,array $mapping): string
{
    return json_encode([
        'provider'=>'stripe',
        'provider_subscription_id'=>(string)$providerSubscription['id'],
        'provider_customer_id'=>billing_stripe_customer_id($providerSubscription),
        'provider_price_id'=>(string)$mapping['provider_price_id'],
        'billing_interval'=>(string)$mapping['billing_interval'],
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

function billing_sync_cancel_request(PDO $pdo,int $userId,int $packageId,bool $cancelAtPeriodEnd,?string $periodEnd): void
{
    $stmt=$pdo->prepare("SELECT * FROM subscription_plan_requests WHERE user_id=? AND action='cancel' AND status='scheduled' ORDER BY id DESC LIMIT 1 FOR UPDATE");$stmt->execute([$userId]);$existing=$stmt->fetch()?:null;
    if($cancelAtPeriodEnd&&$periodEnd){
        if($existing){$pdo->prepare("UPDATE subscription_plan_requests SET subscription_id=(SELECT id FROM user_subscriptions WHERE user_id=? AND status IN ('active','trialing','complimentary') ORDER BY id DESC LIMIT 1),from_package_id=?,effective_at=?,updated_at=NOW() WHERE id=?")->execute([$userId,$packageId,$periodEnd,(int)$existing['id']]);return;}
        subscription_self_service_insert_request($pdo,$userId,null,$packageId,null,'cancel','monthly','scheduled',0,$periodEnd,['source'=>'stripe','cancel_at_period_end'=>true]);
        return;
    }
    if(!$cancelAtPeriodEnd&&$existing){
        $pdo->prepare("UPDATE subscription_plan_requests SET status='cancelled',resolved_at=NOW(),updated_at=NOW(),metadata_json=JSON_SET(COALESCE(metadata_json,'{}'),'$.provider_resumed',true) WHERE id=?")->execute([(int)$existing['id']]);
    }
}

function billing_activate_stripe_subscription(array $providerSubscription,array $mapping,int $userId,?int $requestId=null,?PDO $pdo=null): int
{
    $pdo??=db();if(!$pdo||$userId<1)throw new RuntimeException('Billing reconciliation account is invalid.');
    $providerSubscriptionId=trim((string)($providerSubscription['id']??''));$customerId=billing_stripe_customer_id($providerSubscription);$priceId=billing_stripe_subscription_price_id($providerSubscription);
    if($providerSubscriptionId===''||$customerId===''||$priceId===''||(string)$mapping['provider_price_id']!==$priceId)throw new RuntimeException('Stripe subscription identity does not match the package price mapping.');
    $packageId=(int)$mapping['package_id'];$period=billing_stripe_period($providerSubscription);$periodStart=$period['start']??gmdate('Y-m-d H:i:s');$periodEnd=$period['end']??null;$providerStatus=billing_stripe_provider_status($providerSubscription);
    $cancelAtPeriodEnd=!empty($providerSubscription['cancel_at_period_end']);

    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $userLock=$pdo->prepare('SELECT id,email FROM users WHERE id=? LIMIT 1 FOR UPDATE');$userLock->execute([$userId]);$userRow=$userLock->fetch();if(!$userRow)throw new RuntimeException('Billing user no longer exists.');
        $billingExistingStmt=$pdo->prepare("SELECT * FROM billing_subscriptions WHERE provider='stripe' AND provider_subscription_id=? LIMIT 1 FOR UPDATE");$billingExistingStmt->execute([$providerSubscriptionId]);$billingExisting=$billingExistingStmt->fetch()?:null;
        $current=subscription_current_for_user_id($userId,$pdo,true);
        $request=null;
        if($requestId&&$requestId>0){
            $candidate=billing_plan_request($requestId,$userId,$pdo,true);
            if($candidate&&in_array((string)$candidate['status'],['pending_billing','applied'],true))$request=$candidate;
        }
        if(!$request)$request=billing_pending_request_for_package($userId,$packageId,$pdo,true);
        if($request&&(int)($request['target_package_id']??0)!==$packageId)$request=null;
        if(!$billingExisting&&!$request){
            throw new RuntimeException('Stripe confirmed a subscription that is not linked to an active VP3 plan request; entitlements were not changed.');
        }

        $localSubscriptionId=(int)($billingExisting['user_subscription_id']??0);
        $localIsCurrent=$current&&$localSubscriptionId>0&&(int)$current['id']===$localSubscriptionId&&(int)$current['package_id']===$packageId&&(int)$current['billing_required']===1;
        if($localIsCurrent){
            $pdo->prepare("UPDATE user_subscriptions SET status='active',assignment_source='stripe',billing_required=1,ends_at=NULL,current_period_start=?,current_period_end=?,metadata_json=?,updated_at=NOW() WHERE id=? AND user_id=?")->execute([$periodStart,$periodEnd,billing_local_subscription_metadata($providerSubscription,$mapping),$localSubscriptionId,$userId]);
        }else{
            $oldPackageId=(int)($current['package_id']??0)?:null;
            $pdo->prepare("UPDATE user_subscriptions SET status='replaced',ends_at=LEAST(COALESCE(ends_at,NOW()),NOW()),updated_at=NOW() WHERE user_id=? AND status IN ('trialing','active','complimentary')")->execute([$userId]);
            $stmt=$pdo->prepare("INSERT INTO user_subscriptions (user_id,package_id,status,assignment_source,billing_required,starts_at,ends_at,current_period_start,current_period_end,ai_token_override,assigned_by,metadata_json) VALUES (?,?,'active','stripe',1,NOW(),NULL,?,?,NULL,NULL,?)");
            $stmt->execute([$userId,$packageId,$periodStart,$periodEnd,billing_local_subscription_metadata($providerSubscription,$mapping)]);$localSubscriptionId=(int)$pdo->lastInsertId();
            subscription_audit($pdo,null,$userId,'stripe_package_activated',$oldPackageId,$packageId,'Stripe confirmed paid subscription access.',[
                'provider_subscription_id'=>$providerSubscriptionId,'provider_price_id'=>$priceId,'provider_status'=>$providerStatus,'plan_request_id'=>$request?(int)$request['id']:null,'billing_interval'=>$mapping['billing_interval'],
            ]);
        }
        billing_store_customer($userId,$customerId,(string)($userRow['email']??''),$pdo);
        $planRequestId=$request?(int)$request['id']:((int)($billingExisting['plan_request_id']??0)?:null);
        $upsert=$pdo->prepare("INSERT INTO billing_subscriptions (user_id,user_subscription_id,package_id,plan_request_id,provider,provider_customer_id,provider_subscription_id,provider_price_id,billing_interval,status,cancel_at_period_end,current_period_start,current_period_end,metadata_json) VALUES (?,?,?,?,'stripe',?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),user_subscription_id=VALUES(user_subscription_id),package_id=VALUES(package_id),plan_request_id=COALESCE(VALUES(plan_request_id),plan_request_id),provider_customer_id=VALUES(provider_customer_id),provider_price_id=VALUES(provider_price_id),billing_interval=VALUES(billing_interval),status=VALUES(status),cancel_at_period_end=VALUES(cancel_at_period_end),current_period_start=VALUES(current_period_start),current_period_end=VALUES(current_period_end),metadata_json=VALUES(metadata_json),updated_at=NOW()");
        $upsert->execute([$userId,$localSubscriptionId,$packageId,$planRequestId,$customerId,$providerSubscriptionId,$priceId,(string)$mapping['billing_interval'],$providerStatus,$cancelAtPeriodEnd?1:0,$periodStart,$periodEnd,json_encode(['livemode'=>!empty($providerSubscription['livemode'])],JSON_UNESCAPED_SLASHES)]);
        if($request){
            $pdo->prepare("UPDATE subscription_plan_requests SET subscription_id=?,status='applied',effective_at=COALESCE(effective_at,NOW()),resolved_at=NOW(),updated_at=NOW(),metadata_json=JSON_SET(COALESCE(metadata_json,'{}'),'$.provider','stripe','$.provider_subscription_id',?,'$.provider_price_id',?) WHERE id=?")->execute([$localSubscriptionId,$providerSubscriptionId,$priceId,(int)$request['id']]);
            $pdo->prepare("UPDATE billing_checkout_sessions SET provider_subscription_id=?,status='completed',updated_at=NOW() WHERE plan_request_id=? AND provider='stripe' AND status='open'")->execute([$providerSubscriptionId,(int)$request['id']]);
        }
        billing_sync_cancel_request($pdo,$userId,$packageId,$cancelAtPeriodEnd,$periodEnd);
        if($ownsTransaction)$pdo->commit();return $localSubscriptionId;
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function billing_end_stripe_subscription(array $providerSubscription,int $userId,?PDO $pdo=null): void
{
    $pdo??=db();$providerSubscriptionId=trim((string)($providerSubscription['id']??''));if(!$pdo||$userId<1||$providerSubscriptionId==='')return;
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$userId]);if(!$lock->fetchColumn()){if($ownsTransaction)$pdo->commit();return;}
        $bStmt=$pdo->prepare("SELECT * FROM billing_subscriptions WHERE provider='stripe' AND provider_subscription_id=? LIMIT 1 FOR UPDATE");$bStmt->execute([$providerSubscriptionId]);$billing=$bStmt->fetch()?:null;
        $localId=(int)($billing['user_subscription_id']??0);$oldPackage=(int)($billing['package_id']??0)?:null;
        if($localId>0)$pdo->prepare("UPDATE user_subscriptions SET status='cancelled',ends_at=LEAST(COALESCE(ends_at,NOW()),NOW()),updated_at=NOW() WHERE id=? AND user_id=?")->execute([$localId,$userId]);
        $pdo->prepare("UPDATE billing_subscriptions SET status=?,cancel_at_period_end=0,current_period_end=COALESCE(current_period_end,NOW()),updated_at=NOW() WHERE provider='stripe' AND provider_subscription_id=?")->execute([billing_stripe_provider_status($providerSubscription)?:'canceled',$providerSubscriptionId]);
        $cancelStmt=$pdo->prepare("SELECT id FROM subscription_plan_requests WHERE user_id=? AND action='cancel' AND status='scheduled' ORDER BY id DESC LIMIT 1 FOR UPDATE");$cancelStmt->execute([$userId]);$cancelId=(int)$cancelStmt->fetchColumn();if($cancelId>0)$pdo->prepare("UPDATE subscription_plan_requests SET status='applied',effective_at=COALESCE(effective_at,NOW()),resolved_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$cancelId]);

        $otherCurrent=subscription_current_for_user_id($userId,$pdo,true);
        if(!$otherCurrent){
            $fallback=$pdo->query("SELECT id FROM subscription_packages WHERE is_active=1 AND is_public=1 AND is_trial=0 AND monthly_price_cents=0 ORDER BY is_default DESC,sort_order ASC,id ASC LIMIT 1")->fetchColumn();
            if((int)$fallback>0){
                $stmt=$pdo->prepare("INSERT INTO user_subscriptions (user_id,package_id,status,assignment_source,billing_required,starts_at,current_period_start,current_period_end,metadata_json) VALUES (?,?,'active','billing_fallback',0,NOW(),NOW(),NULL,?)");$stmt->execute([$userId,(int)$fallback,json_encode(['ended_provider_subscription_id'=>$providerSubscriptionId],JSON_UNESCAPED_SLASHES)]);
                subscription_audit($pdo,null,$userId,'stripe_subscription_ended',$oldPackage,(int)$fallback,'Stripe subscription ended; free fallback package assigned.',['provider_subscription_id'=>$providerSubscriptionId]);
            }else subscription_audit($pdo,null,$userId,'stripe_subscription_ended',$oldPackage,null,'Stripe subscription ended; no free fallback package is configured.',['provider_subscription_id'=>$providerSubscriptionId]);
        }else subscription_audit($pdo,null,$userId,'stripe_subscription_ended',$oldPackage,(int)$otherCurrent['package_id'],'Stripe subscription ended; another current package remains active.',['provider_subscription_id'=>$providerSubscriptionId]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function billing_reconcile_stripe_subscription(array $providerSubscription,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!billing_schema_ready($pdo))throw new RuntimeException('Billing storage is unavailable.');
    $subscriptionId=trim((string)($providerSubscription['id']??''));if($subscriptionId==='')throw new RuntimeException('Stripe subscription ID is missing.');
    $userId=billing_stripe_user_id($providerSubscription,$pdo);if($userId<1)throw new RuntimeException('Stripe subscription is not linked to a VP3 user.');
    $customerId=billing_stripe_customer_id($providerSubscription);if($customerId!=='')billing_store_customer($userId,$customerId,'',$pdo);
    $status=billing_stripe_provider_status($providerSubscription);$priceId=billing_stripe_subscription_price_id($providerSubscription);$mapping=$priceId!==''?billing_price_mapping_by_provider_price($priceId,$pdo):null;
    if(in_array($status,['canceled','unpaid','incomplete_expired'],true)){
        billing_end_stripe_subscription($providerSubscription,$userId,$pdo);return ['state'=>'ended','user_id'=>$userId,'provider_subscription_id'=>$subscriptionId];
    }
    if(!$mapping){
        $known=billing_subscription_by_provider_id($subscriptionId,$pdo);
        if($known)$pdo->prepare("UPDATE billing_subscriptions SET status=?,provider_price_id=?,updated_at=NOW() WHERE id=?")->execute([$status,$priceId,(int)$known['id']]);
        throw new RuntimeException('Stripe Price '.$priceId.' is not mapped to a VP3 package; entitlements were not changed.');
    }
    if(billing_stripe_is_entitlement_active($providerSubscription)){
        $localId=billing_activate_stripe_subscription($providerSubscription,$mapping,$userId,billing_stripe_request_id($providerSubscription)?:null,$pdo);
        return ['state'=>'active','user_id'=>$userId,'local_subscription_id'=>$localId,'package_id'=>(int)$mapping['package_id']];
    }
    $known=billing_subscription_by_provider_id($subscriptionId,$pdo);$period=billing_stripe_period($providerSubscription);
    if($known)$pdo->prepare("UPDATE billing_subscriptions SET status=?,cancel_at_period_end=?,current_period_start=?,current_period_end=?,updated_at=NOW() WHERE id=?")->execute([$status,!empty($providerSubscription['cancel_at_period_end'])?1:0,$period['start'],$period['end'],(int)$known['id']]);
    return ['state'=>'pending','user_id'=>$userId,'provider_status'=>$status];
}

function billing_expire_request_checkout(int $userId,int $requestId,bool $strict=true,?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo||$userId<1||$requestId<1||!billing_schema_ready($pdo))return;
    $stmt=$pdo->prepare("SELECT * FROM billing_checkout_sessions WHERE user_id=? AND plan_request_id=? AND provider='stripe' AND session_type='checkout' AND status='open' ORDER BY id DESC");$stmt->execute([$userId,$requestId]);$sessions=$stmt->fetchAll()?:[];
    foreach($sessions as $row){
        $sessionId=trim((string)$row['provider_session_id']);if($sessionId==='')continue;
        try{
            $session=billing_stripe_checkout_session($sessionId);$status=(string)($session['status']??'');
            if($status==='complete'){
                billing_process_checkout_session($session,$pdo);
                if($strict)throw new RuntimeException('Stripe Checkout already completed before this plan change could be cancelled. Billing status was synchronized instead.');
                continue;
            }
            if($status==='open')billing_stripe_expire_checkout_session($sessionId);
            $pdo->prepare("UPDATE billing_checkout_sessions SET status='expired',updated_at=NOW() WHERE id=? AND status='open'")->execute([(int)$row['id']]);
        }catch(Throwable $e){
            if($strict)throw $e;
            error_log('VP3 could not expire superseded Stripe Checkout '.$sessionId.': '.$e->getMessage());
        }
    }
}

function billing_expire_superseded_checkouts(int $userId,?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo||$userId<1||!billing_schema_ready($pdo))return;
    $stmt=$pdo->prepare("SELECT DISTINCT b.plan_request_id FROM billing_checkout_sessions b INNER JOIN subscription_plan_requests r ON r.id=b.plan_request_id WHERE b.user_id=? AND b.provider='stripe' AND b.session_type='checkout' AND b.status='open' AND r.status<>'pending_billing'");$stmt->execute([$userId]);
    foreach($stmt->fetchAll()?:[] as $row){$requestId=(int)($row['plan_request_id']??0);if($requestId>0)billing_expire_request_checkout($userId,$requestId,false,$pdo);}
}

function billing_begin_paid_flow(array $user,array $request,array $package,string $interval): array
{
    $pdo=db();if(!$pdo||!billing_schema_ready($pdo)||!billing_stripe_configured())throw new RuntimeException('Stripe checkout is not configured yet.');
    $userId=(int)($user['id']??0);$requestId=(int)($request['id']??0);if($userId<1||$requestId<1||$request['status']!=='pending_billing')throw new RuntimeException('This plan selection is no longer awaiting billing.');
    billing_expire_superseded_checkouts($userId,$pdo);
    $current=subscription_current_for_user_id($userId,$pdo);$billing=billing_subscription_for_user($userId,$pdo);
    if($current&&$billing&&(int)$current['billing_required']===1&&(int)($billing['user_subscription_id']??0)===(int)$current['id']&&in_array((string)$billing['status'],['active','trialing','past_due'],true)){
        return billing_stripe_create_plan_change_portal($user,$request,$package,$interval,$billing,$pdo);
    }
    return billing_stripe_create_checkout($user,$request,$package,$interval,$pdo);
}

function billing_resume_request(array $user,int $requestId): array
{
    $pdo=db();$userId=(int)($user['id']??0);if(!$pdo||$userId<1)throw new RuntimeException('Billing account unavailable.');
    $request=billing_plan_request($requestId,$userId,$pdo);if(!$request||$request['status']!=='pending_billing')throw new RuntimeException('That billing request is no longer active.');
    $package=subscription_package((int)$request['target_package_id']);if(!$package)throw new RuntimeException('Selected package no longer exists.');
    return billing_begin_paid_flow($user,$request,$package,(string)$request['billing_interval']);
}

function billing_schedule_cancel(array $user): array
{
    $pdo=db();$userId=(int)($user['id']??0);if(!$pdo||$userId<1)throw new RuntimeException('Billing account unavailable.');
    $billing=billing_subscription_for_user($userId,$pdo);if(!$billing||trim((string)$billing['provider_subscription_id'])==='')return subscription_self_service_schedule_cancel($user);
    $providerId=(string)$billing['provider_subscription_id'];$nonce=substr(bin2hex(random_bytes(8)),0,16);
    $provider=billing_stripe_request('POST','subscriptions/'.rawurlencode($providerId),['cancel_at_period_end'=>true],'vp3-cancel-at-period-end-'.$providerId.'-'.$nonce);
    billing_reconcile_stripe_subscription($provider,$pdo);
    $open=subscription_self_service_open_request($userId,$pdo);if(!$open||(string)$open['action']!=='cancel')throw new RuntimeException('Stripe cancellation was accepted but local cancellation state could not be synchronized.');
    return ['status'=>'scheduled','request_id'=>(int)$open['id'],'effective_at'=>(string)$open['effective_at']];
}

function billing_cancel_request(array $user,int $requestId): void
{
    $pdo=db();$userId=(int)($user['id']??0);if(!$pdo||$userId<1)throw new RuntimeException('Billing account unavailable.');
    $request=billing_plan_request($requestId,$userId,$pdo);if(!$request)throw new RuntimeException('That plan request is no longer active.');
    if((string)$request['action']==='cancel'&&(string)$request['status']==='scheduled'){
        $billing=billing_subscription_for_user($userId,$pdo);
        if($billing&&trim((string)$billing['provider_subscription_id'])!==''){
            $providerId=(string)$billing['provider_subscription_id'];$nonce=substr(bin2hex(random_bytes(8)),0,16);
            $provider=billing_stripe_request('POST','subscriptions/'.rawurlencode($providerId),['cancel_at_period_end'=>false],'vp3-resume-'.$providerId.'-'.$nonce);
            billing_reconcile_stripe_subscription($provider,$pdo);return;
        }
    }
    if((string)$request['status']==='pending_billing')billing_expire_request_checkout($userId,$requestId,true,$pdo);
    subscription_self_service_cancel_request($user,$requestId);
}

function billing_manage_portal(array $user): string
{
    $pdo=db();$userId=(int)($user['id']??0);if(!$pdo||$userId<1||!billing_stripe_configured())throw new RuntimeException('Stripe billing is unavailable.');
    $billing=billing_subscription_for_user($userId,$pdo);if(!$billing)throw new RuntimeException('There is no Stripe billing account to manage.');
    $session=billing_stripe_create_manage_portal($user,$billing,$pdo);$url=trim((string)($session['url']??''));if($url==='')throw new RuntimeException('Stripe billing portal is unavailable.');return $url;
}

function billing_reconcile_user(array $user): ?array
{
    $pdo=db();$userId=(int)($user['id']??0);if(!$pdo||$userId<1||!billing_stripe_configured()||!billing_schema_ready($pdo))return null;
    $billing=billing_subscription_for_user($userId,$pdo);if(!$billing)return null;
    try{$provider=billing_stripe_subscription((string)$billing['provider_subscription_id']);return billing_reconcile_stripe_subscription($provider,$pdo);}catch(Throwable $e){error_log('VP3 billing user reconciliation failed: '.$e->getMessage());return null;}
}

function billing_reconcile_checkout_return(array $user,string $sessionId): ?array
{
    $pdo=db();$userId=(int)($user['id']??0);$sessionId=trim($sessionId);if(!$pdo||$userId<1||$sessionId===''||!billing_schema_ready($pdo)||!billing_stripe_configured())return null;
    $stmt=$pdo->prepare("SELECT * FROM billing_checkout_sessions WHERE user_id=? AND provider='stripe' AND provider_session_id=? AND session_type='checkout' LIMIT 1");$stmt->execute([$userId,$sessionId]);$local=$stmt->fetch();if(!$local)return null;
    $session=billing_stripe_checkout_session($sessionId);return billing_process_checkout_session($session,$pdo);
}

function billing_process_checkout_session(array $session,?PDO $pdo=null): ?array
{
    $pdo??=db();$sessionId=trim((string)($session['id']??''));if(!$pdo||$sessionId==='')return null;
    $meta=$session['metadata']??[];$userId=max(0,(int)($meta['vp3_user_id']??$session['client_reference_id']??0));$requestId=max(0,(int)($meta['vp3_plan_request_id']??0));
    $customer=$session['customer']??'';if(is_array($customer))$customer=$customer['id']??'';$customerId=trim((string)$customer);
    $sub=$session['subscription']??'';if(is_array($sub))$sub=$sub['id']??'';$subscriptionId=trim((string)$sub);
    $status=trim((string)($session['status']??'complete'));
    $pdo->prepare("UPDATE billing_checkout_sessions SET provider_customer_id=?,provider_subscription_id=?,status=?,updated_at=NOW() WHERE provider='stripe' AND provider_session_id=?")->execute([$customerId,$subscriptionId,$status==='complete'?'completed':$status,$sessionId]);
    if($userId>0&&$customerId!=='')billing_store_customer($userId,$customerId,'',$pdo);
    if($subscriptionId!==''){
        $provider=billing_stripe_subscription($subscriptionId);
        if($requestId>0&&!isset($provider['metadata']['vp3_plan_request_id']))$provider['metadata']['vp3_plan_request_id']=(string)$requestId;
        if($userId>0&&!isset($provider['metadata']['vp3_user_id']))$provider['metadata']['vp3_user_id']=(string)$userId;
        return billing_reconcile_stripe_subscription($provider,$pdo);
    }
    return ['state'=>'checkout_completed','user_id'=>$userId];
}

function billing_webhook_begin(array $event,string $payload,?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo||!billing_schema_ready($pdo))throw new RuntimeException('Billing webhook storage is unavailable.');
    $eventId=trim((string)($event['id']??''));$type=trim((string)($event['type']??''));if($eventId===''||$type==='')throw new RuntimeException('Stripe webhook event is missing an ID or type.');
    $hash=hash('sha256',$payload);
    try{
        $stmt=$pdo->prepare("INSERT INTO billing_webhook_events (provider,event_id,event_type,livemode,payload_sha256,status) VALUES ('stripe',?,?,?,?, 'processing')");
        $stmt->execute([$eventId,$type,!empty($event['livemode'])?1:0,$hash]);return true;
    }catch(PDOException $e){
        if((string)$e->getCode()!=='23000')throw $e;
        $stmt=$pdo->prepare("SELECT status,payload_sha256,updated_at FROM billing_webhook_events WHERE provider='stripe' AND event_id=? LIMIT 1");$stmt->execute([$eventId]);$existing=$stmt->fetch();if(!$existing)return false;
        if(!hash_equals((string)$existing['payload_sha256'],$hash))throw new RuntimeException('Stripe event ID was replayed with a different payload.');
        $status=(string)$existing['status'];$stale=$status==='processing'&&strtotime((string)$existing['updated_at'])<time()-300;
        if($status==='failed'||$stale){
            $update=$pdo->prepare("UPDATE billing_webhook_events SET event_type=?,livemode=?,status='processing',error_message='',processed_at=NULL,updated_at=NOW() WHERE provider='stripe' AND event_id=? AND (status='failed' OR (status='processing' AND updated_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)))");
            $update->execute([$type,!empty($event['livemode'])?1:0,$eventId]);return $update->rowCount()===1;
        }
        return false;
    }
}

function billing_webhook_finish(string $eventId,string $status,string $error='',?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo||$eventId==='')return;
    $pdo->prepare("UPDATE billing_webhook_events SET status=?,error_message=?,processed_at=NOW(),updated_at=NOW() WHERE provider='stripe' AND event_id=?")->execute([$status,mb_strimwidth($error,0,1000,''),$eventId]);
}

function billing_process_stripe_event(array $event,string $payload,?PDO $pdo=null): array
{
    $pdo??=db();$eventId=trim((string)($event['id']??''));if(!$pdo)throw new RuntimeException('Database unavailable.');
    if(!billing_webhook_begin($event,$payload,$pdo))return ['duplicate'=>true,'event_id'=>$eventId];
    try{
        $type=(string)$event['type'];$object=$event['data']['object']??null;if(!is_array($object))throw new RuntimeException('Stripe webhook object is missing.');
        $result=['ignored'=>true,'type'=>$type];
        if($type==='checkout.session.completed')$result=billing_process_checkout_session($object,$pdo)??$result;
        elseif($type==='checkout.session.expired'){
            $sid=(string)($object['id']??'');$pdo->prepare("UPDATE billing_checkout_sessions SET status='expired',updated_at=NOW() WHERE provider='stripe' AND provider_session_id=?")->execute([$sid]);$result=['state'=>'expired'];
        }elseif(in_array($type,['customer.subscription.created','customer.subscription.updated','customer.subscription.deleted'],true))$result=billing_reconcile_stripe_subscription($object,$pdo);
        elseif(in_array($type,['invoice.paid','invoice.payment_failed','invoice.payment_action_required'],true)){
            $subscription=$object['subscription']??$object['parent']['subscription_details']['subscription']??'';if(is_array($subscription))$subscription=$subscription['id']??'';
            if(trim((string)$subscription)!=='')$result=billing_reconcile_stripe_subscription(billing_stripe_subscription((string)$subscription),$pdo);
        }
        billing_webhook_finish($eventId,'processed','',$pdo);return $result;
    }catch(Throwable $e){billing_webhook_finish($eventId,'failed',$e->getMessage(),$pdo);throw $e;}
}
