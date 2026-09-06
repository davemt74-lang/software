<?php
declare(strict_types=1);

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
    if(subscription_is_internal_admin($user))return ['reservation_id'=>0,'max_output_tokens'=>max(64,$requestedOutputTokens),'remaining_before'=>PHP_INT_MAX,'unlimited'=>true];

    $pdo=db();
    if(!$pdo||!subscription_schema_ready($pdo)){
        $balance=subscription_ai_balance($user,$pdo,false);
        if(!empty($balance['unlimited']))return ['reservation_id'=>0,'max_output_tokens'=>max(64,$requestedOutputTokens),'remaining_before'=>PHP_INT_MAX,'unlimited'=>true];
        throw new RuntimeException('AI quota storage is unavailable.');
    }

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        // Lock the active subscription row while balance + outstanding reservations
        // are calculated and the new reservation is inserted. This prevents two
        // simultaneous requests from reserving the same remaining monthly tokens.
        $balance=subscription_ai_balance($user,$pdo,true);
        if(!empty($balance['unlimited'])){
            if($ownsTransaction)$pdo->commit();
            return ['reservation_id'=>0,'max_output_tokens'=>max(64,$requestedOutputTokens),'remaining_before'=>PHP_INT_MAX,'unlimited'=>true];
        }
        $remaining=(int)$balance['remaining'];$estimatedInputTokens=max(0,$estimatedInputTokens);$requestedOutputTokens=max(64,$requestedOutputTokens);
        $minimumNeeded=$estimatedInputTokens+64;
        if($remaining<$minimumNeeded)throw new RuntimeException('Your AI token balance is exhausted. Add tokens or upgrade your package to continue.');
        $maxOutput=max(64,min($requestedOutputTokens,$remaining-$estimatedInputTokens));
        $reserve=min($remaining,$estimatedInputTokens+$maxOutput);
        $sub=$balance['subscription'];
        $stmt=$pdo->prepare("INSERT INTO ai_token_reservations (user_id,subscription_id,scope,reserved_tokens,expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 3 MINUTE))");
        $stmt->execute([$uid,(int)($sub['id']??0)?:null,mb_strimwidth($scope,0,60,''),$reserve]);
        $reservationId=(int)$pdo->lastInsertId();
        if($ownsTransaction)$pdo->commit();
        return ['reservation_id'=>$reservationId,'max_output_tokens'=>$maxOutput,'remaining_before'=>$remaining,'unlimited'=>false];
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
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
        // Consume the included monthly allowance before purchased/admin token
        // credits. Credits are additive capacity and should not be burned while
        // the package still has included tokens available.
        $packageUsed=0;
        if($sub){
            $allowance=subscription_package_allowance($sub);$period=subscription_period($sub);
            $usedSql='SELECT COALESCE(SUM(package_tokens_used),0) FROM ai_usage_ledger WHERE user_id=? AND subscription_id=?';
            $usedParams=[$uid,(int)$sub['id']];
            if(!empty($period['start'])){$usedSql.=' AND created_at>=?';$usedParams[]=$period['start'];}
            if(!empty($period['end'])){$usedSql.=' AND created_at<?';$usedParams[]=$period['end'];}
            $usedStmt=$pdo->prepare($usedSql);$usedStmt->execute($usedParams);$alreadyUsed=(int)$usedStmt->fetchColumn();
            $packageAvailable=max(0,$allowance-$alreadyUsed);
            $packageUsed=min($packageAvailable,$remaining);$remaining-=$packageUsed;
        }
        $credits=$pdo->prepare("SELECT id,remaining_amount FROM ai_token_credits WHERE user_id=? AND remaining_amount>0 AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END ASC,expires_at ASC,id ASC FOR UPDATE");
        $credits->execute([$uid]);
        foreach($credits->fetchAll()?:[] as $credit){
            if($remaining<=0)break;$available=(int)$credit['remaining_amount'];if($available<1)continue;$take=min($available,$remaining);
            $pdo->prepare('UPDATE ai_token_credits SET remaining_amount=remaining_amount-?,updated_at=NOW() WHERE id=?')->execute([$take,(int)$credit['id']]);
            $creditUsed+=$take;$remaining-=$take;
        }
        // Provider-reported input can exceed the estimate used for reservation.
        // Record any unavoidable overrun against package usage so the ledger
        // remains exact and subsequent requests see a zero package balance.
        if($remaining>0){$packageUsed+=$remaining;$remaining=0;}
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
