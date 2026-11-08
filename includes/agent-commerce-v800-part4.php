<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/profile-promotion-attribution-v1.php';

function agent_commerce_dispatch_fulfillment_v800(PDO $pdo,array $order,string $event): void
{
    foreach(agent_commerce_order_items_v800($pdo,(int)$order['id']) as $item){
        $type=(string)$item['fulfillment_type'];$fn='agent_commerce_fulfillment_'.$type.'_'.$event.'_v800';
        if(!preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$type)||!function_exists($fn))continue;
        $fn($pdo,$order,$item);
    }
}
function agent_commerce_project_paid_pa2(PDO $pdo,array $order): void
{
    if($pdo->inTransaction())throw new RuntimeException('Commerce projections require a committed receipt.',503);
    if((string)($order['order_status']??'')==='payment_review')throw new RuntimeException('Commerce payment projection requires review.',409);
    profile_promotion_attribution_touch_order_v1($pdo,$order);
    foreach(['profile_conversion_commerce_order_v179','vp3_profile_webmcp_commerce_record_purchase_v160'] as $fn){
        if(function_exists($fn))$fn($pdo,$order);
    }
    if((string)($order['payment_status']??'')==='paid'&&function_exists('campaigns_rewards_automation_external_event_v119')){
        campaigns_rewards_automation_external_event_v119($pdo,'purchase_completed',[
            'owner_user_id'=>(int)$order['owner_user_id'],'payer_email'=>(string)($order['payer_email']??''),
            'order_id'=>(int)$order['id'],'order_number'=>(string)$order['order_number'],
            'amount_paid_cents'=>(int)$order['amount_paid_cents'],'currency'=>(string)$order['currency'],
        ],'commerce-order:'.(int)$order['id'].':paid');
    }
}
function agent_commerce_mark_paid_v800(PDO $pdo,int $orderId,string $provider,string $externalSessionId,string $externalPaymentId,int $amountCents,string $currency,array $evidence=[]): array
{
    $provider=strtolower(trim($provider));$currency=strtolower(trim($currency));
    if(($evidence['status']??'')!=='verified')throw new RuntimeException('Verified payment provider evidence is required.',409);
    if(!in_array($provider,['stripe','square','paypal'],true)||!preg_match('/^[a-z]{3}$/D',$currency)||$amountCents<1
        ||$externalPaymentId===''||strlen($externalPaymentId)>190||preg_match('/[\x00-\x20\x7f]/',$externalPaymentId)
        ||$externalSessionId===''||strlen($externalSessionId)>190||preg_match('/[\x00-\x20\x7f]/',$externalSessionId))throw new RuntimeException('Payment provider transaction identity is invalid.',409);
    if(!agent_commerce_recovery_schema_ready_pa2($pdo))throw new RuntimeException('Commerce recovery schema is unavailable.',503);
    $order=agent_commerce_atomic_pa2($pdo,function()use($pdo,$orderId,$provider,$externalSessionId,$externalPaymentId,$amountCents,$currency,$evidence):array{
        $stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=? LIMIT 1'.agent_commerce_lock_pa2($pdo));$stmt->execute([$orderId]);$order=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$order)throw new RuntimeException('Commerce order not found.',409);
        $q=$pdo->prepare('SELECT * FROM agent_commerce_checkout_attempts_v800 WHERE order_id=? AND provider=? AND external_session_id=? LIMIT 1'.agent_commerce_lock_pa2($pdo));$q->execute([$orderId,$provider,$externalSessionId]);$a=$q->fetch(PDO::FETCH_ASSOC);
        if(!$a||!hash_equals((string)$a['provider'],$provider)||!hash_equals((string)$a['external_session_id'],$externalSessionId)
            ||str_starts_with($externalSessionId,'pending:')||(int)$a['amount_cents']!==$amountCents||strtolower((string)$a['currency'])!==$currency
            ||strtolower((string)$order['currency'])!==$currency)throw new RuntimeException('Payment checkout lineage could not be verified.',409);
        agent_commerce_attempt_connection_pa2($pdo,$order,$a);
        if($evidence){
            if(($evidence['status']??'')!=='verified')throw new RuntimeException('Payment provider evidence is unavailable.',409);
            foreach(['provider','external_session_id','provider_order_id','provider_account_id','provider_location_id'] as $key){
                if(!is_string($evidence[$key]??null)||!hash_equals((string)($a[$key]??''),$evidence[$key]))throw new RuntimeException('Payment checkout lineage changed before receipt.',409);
            }
            if(($evidence['external_payment_id']??null)!==$externalPaymentId||($evidence['amount_cents']??null)!==$amountCents||($evidence['currency']??null)!==$currency)throw new RuntimeException('Payment provider evidence is unavailable.',409);
        }
        $seen=$pdo->prepare('SELECT * FROM agent_commerce_payments_v800 WHERE provider=? AND external_payment_id=? LIMIT 1');$seen->execute([$provider,$externalPaymentId]);$payment=$seen->fetch(PDO::FETCH_ASSOC);
        if($payment){
            if(!hash_equals((string)$payment['provider'],$provider)||!hash_equals((string)$payment['external_payment_id'],$externalPaymentId)
                ||(int)$payment['order_id']!==$orderId||(int)$payment['checkout_attempt_id']!==(int)$a['id']
                ||(int)$payment['amount_cents']!==$amountCents||strtolower((string)$payment['currency'])!==$currency
                ||(string)$payment['status']!=='completed'||!hash_equals((string)$a['external_payment_id'],$externalPaymentId))throw new RuntimeException('Payment replay does not match the original receipt.',409);
            $first=$pdo->prepare('SELECT MIN(id) FROM agent_commerce_payments_v800 WHERE order_id=?');$first->execute([$orderId]);
            $event=(int)$first->fetchColumn()===(int)$payment['id']?'paid':((int)$order['amount_due_cents']===0?'paid_in_full':'payment_updated');
            agent_commerce_fulfillment_enqueue_pa2($pdo,$order,$event,(int)$payment['id'],(string)$order['order_status']==='payment_review');
            return $order;
        }
        if((string)$a['external_payment_id']!==''&&!hash_equals((string)$a['external_payment_id'],$externalPaymentId))throw new RuntimeException('Checkout already has a different verified payment.',409);
        $from=(string)$order['payment_status'];$priorOrder=(string)$order['order_status'];
        if(in_array($from,['refunded','partially_refunded'],true))throw new RuntimeException('Payment requires reconciliation with the existing refund.',409);
        $elapsed=agent_commerce_date_pa2($order['hold_expires_at']??null)>0&&agent_commerce_date_pa2($order['hold_expires_at'])<=time();
        $review=$elapsed||!in_array($from,['awaiting_payment','partially_paid'],true)||in_array($priorOrder,['cancelled','expired','payment_review'],true)||$amountCents>(int)$order['amount_due_cents'];
        $newPaid=(int)$order['amount_paid_cents']+$amountCents;if($newPaid>2147483647)throw new RuntimeException('Payment amount exceeds the supported ledger range.',409);
        $remaining=max(0,(int)$order['total_cents']-$newPaid);$to=$remaining>0?'partially_paid':'paid';
        $orderStatus=$review?'payment_review':($remaining>0?'confirmed':'paid');$now=agent_commerce_time_pa2();
        $pdo->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET external_payment_id=?,status='completed',completed_at=?,updated_at=? WHERE id=?")->execute([$externalPaymentId,$now,$now,(int)$a['id']]);
        $pdo->prepare("INSERT INTO agent_commerce_payments_v800 (order_id,checkout_attempt_id,provider,external_payment_id,amount_cents,currency,status,received_at,created_at,updated_at) VALUES (?,?,?,?,?,?,'completed',?,?,?)")->execute([$orderId,(int)$a['id'],$provider,$externalPaymentId,$amountCents,$currency,$now,$now,$now]);$paymentId=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE agent_commerce_orders_v800 SET amount_paid_cents=?,amount_due_cents=?,payment_status=?,order_status=?,paid_at=COALESCE(paid_at,?),hold_expires_at=NULL,updated_at=? WHERE id=?')->execute([$newPaid,$remaining,$to,$orderStatus,$now,$now,$orderId]);
        if($review){
            $raw=(string)($order['metadata_json']??'');$metadata=$raw===''?[]:agent_commerce_recovery_json_pa2($raw,1048576);
            $metadata['payment_recovery']=['status'=>'review_required','reason'=>$elapsed||in_array($from,['expired','cancelled'],true)?'late_payment':'additional_payment','prior_order_status'=>$priorOrder,'recorded_at'=>$now];
            $pdo->prepare('UPDATE agent_commerce_orders_v800 SET metadata_json=? WHERE id=?')->execute([json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$orderId]);
            $pdo->prepare("UPDATE agent_commerce_order_items_v800 SET fulfillment_status='review_required',updated_at=? WHERE order_id=? AND fulfillment_status<>'fulfilled'")->execute([$now,$orderId]);
        }
        agent_commerce_audit_v800($pdo,$orderId,(int)$order['owner_user_id'],(int)($order['workspace_owner_user_id']??0)?:null,'provider',null,null,$review?'late_payment_recorded':($remaining>0?'deposit_completed':'payment_completed'),$from,$to,$amountCents,['provider'=>$provider,'checkout_attempt_id'=>(int)$a['id'],'external_session_id'=>$externalSessionId,'external_payment_id'=>$externalPaymentId,'balance_due_cents'=>$remaining,'review_required'=>$review]);
        $fresh=agent_commerce_order_v800($pdo,$orderId)?:throw new RuntimeException('Commerce order could not be reloaded.');
        $event=(int)$order['amount_paid_cents']===0?'paid':($remaining===0?'paid_in_full':'payment_updated');
        agent_commerce_fulfillment_enqueue_pa2($pdo,$fresh,$event,$paymentId,$review);
        if($elapsed&&$from==='awaiting_payment')agent_commerce_fulfillment_enqueue_pa2($pdo,$fresh,'expired');
        profile_promotion_attribution_touch_order_v1($pdo,$fresh);
        return $fresh;
    });
    if(!$pdo->inTransaction())agent_commerce_projection_repair_pa2($pdo,(int)$order['id']);return $order;
}
function agent_commerce_expire_one_v800(PDO $pdo,array $order): void
{
    agent_commerce_atomic_pa2($pdo,function()use($pdo,$order):void{
        $s=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=?'.agent_commerce_lock_pa2($pdo));$s->execute([(int)$order['id']]);$live=$s->fetch(PDO::FETCH_ASSOC);
        if(!$live||(string)$live['payment_status']!=='awaiting_payment'||agent_commerce_date_pa2($live['hold_expires_at'])<1||agent_commerce_date_pa2($live['hold_expires_at'])>time())return;
        $now=agent_commerce_time_pa2();
        $pdo->prepare("UPDATE agent_commerce_orders_v800 SET payment_status='expired',order_status='expired',cancelled_at=?,hold_expires_at=NULL,updated_at=? WHERE id=? AND payment_status='awaiting_payment'")->execute([$now,$now,(int)$live['id']]);
        $pdo->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET status='expired',updated_at=? WHERE order_id=? AND status IN ('open','creating')")->execute([$now,(int)$live['id']]);
        agent_commerce_audit_v800($pdo,(int)$live['id'],(int)$live['owner_user_id'],(int)($live['workspace_owner_user_id']??0)?:null,'system',null,null,'payment_hold_expired','awaiting_payment','expired',(int)$live['amount_due_cents']);
        $live['payment_status']='expired';$live['order_status']='expired';agent_commerce_fulfillment_enqueue_pa2($pdo,$live,'expired');
    });
}
function agent_commerce_expire_holds_v800(PDO $pdo,int $limit=100): int
{
    $s=$pdo->prepare("SELECT * FROM agent_commerce_orders_v800 WHERE payment_status='awaiting_payment' AND hold_expires_at IS NOT NULL AND hold_expires_at<=? ORDER BY hold_expires_at,id LIMIT ".max(1,min(500,$limit)));$s->execute([agent_commerce_time_pa2()]);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];$count=0;
    foreach($rows as $row)try{agent_commerce_expire_one_v800($pdo,$row);$count++;}catch(Throwable $e){error_log('Commerce hold expiry pending ['.get_class($e).'].');}return $count;
}
function agent_commerce_housekeeping_v800(PDO $pdo,int $limit=100): array
{
    $result=['expired'=>agent_commerce_expire_holds_v800($pdo,$limit),'refunds_reconciled'=>agent_commerce_reconcile_pending_refunds_v800($pdo,$limit)];
    return $result+agent_commerce_recovery_housekeeping_pa2($pdo,$limit);
}
function agent_commerce_housekeeping_maybe_v800(): void
{
    // Browser requests only run local expiry/fulfillment. Provider reconciliation
    // runs in the bounded Commerce cron and cannot make an unrelated page slow.
    if(PHP_SAPI==='cli'||mt_rand(1,20)!==1)return;$pdo=db();if(!$pdo||!agent_commerce_schema_ready_v800($pdo))return;
    try{agent_commerce_expire_holds_v800($pdo,20);agent_commerce_fulfillment_run_pa2($pdo,20);}catch(Throwable $e){error_log('Commerce housekeeping pending ['.get_class($e).'].');}
}
function agent_commerce_stripe_verify_v800(string $payload,string $signature): bool
{
    $secret=(string)agent_commerce_config_v800()['stripe']['webhook_secret'];if($secret===''||$payload===''||$signature==='')return false;$timestamp=0;$sigs=[];foreach(explode(',',$signature) as $part){[$k,$v]=array_pad(explode('=',trim($part),2),2,'');if($k==='t'&&ctype_digit($v))$timestamp=(int)$v;elseif($k==='v1'&&preg_match('/^[a-f0-9]{64}$/i',$v))$sigs[]=strtolower($v);}if($timestamp<1||abs(time()-$timestamp)>300||!$sigs)return false;$expected=hash_hmac('sha256',$timestamp.'.'.$payload,$secret);foreach($sigs as $sig)if(hash_equals($expected,$sig))return true;return false;
}
function agent_commerce_square_verify_v800(string $payload,string $signature): bool
{
    $key=(string)agent_commerce_config_v800()['square']['webhook_signature_key'];if($key===''||$signature==='')return false;$url=agent_commerce_absolute_url_v800('/commerce-payment-webhook.php?provider=square');$expected=base64_encode(hash_hmac('sha256',$url.$payload,$key,true));return hash_equals($expected,$signature);
}
function agent_commerce_paypal_verify_v800(array $headers,array $event,?callable $http=null): bool
{
    $cfg=agent_commerce_config_v800()['paypal'];if($cfg['webhook_id']==='')return false;
    $required=['paypal-transmission-id','paypal-transmission-time','paypal-transmission-sig','paypal-cert-url','paypal-auth-algo'];foreach($required as $key)if(!is_string($headers[$key]??null)||trim($headers[$key])===''||strlen($headers[$key])>4096)return false;
    $http??='agent_commerce_provider_http_pa2';$reply=$http('POST',agent_commerce_paypal_base_v800().'/v1/oauth2/token',['Authorization: Basic '.base64_encode($cfg['client_id'].':'.$cfg['client_secret'])],['grant_type'=>'client_credentials'],true);$token=$reply['json']['access_token']??'';if(!is_string($token)||$token==='')return false;
    $body=['auth_algo'=>$headers['paypal-auth-algo'],'cert_url'=>$headers['paypal-cert-url'],'transmission_id'=>$headers['paypal-transmission-id'],'transmission_sig'=>$headers['paypal-transmission-sig'],'transmission_time'=>$headers['paypal-transmission-time'],'webhook_id'=>$cfg['webhook_id'],'webhook_event'=>$event];
    $resp=$http('POST',agent_commerce_paypal_base_v800().'/v1/notifications/verify-webhook-signature',['Authorization: Bearer '.$token],$body,false)['json']??[];return ($resp['verification_status']??'')==='SUCCESS';
}
function agent_commerce_webhook_claim_v800(PDO $pdo,string $provider,string $eventId,string $eventType,string $payload): bool
{
    // Retained legacy export cannot acknowledge work on its own. All actual
    // consumers use the fenced claim in process_webhook.
    throw new RuntimeException('Use the authenticated Commerce webhook processor.',409);
}
function agent_commerce_webhook_finish_v800(PDO $pdo,string $provider,string $eventId,string $status,string $error=''): void
{
    throw new RuntimeException('Commerce webhook completion requires its active lease.',409);
}
function agent_commerce_process_webhook_v800(PDO $pdo,string $provider,string $payload,array $headers=[],?callable $http=null): void
{
    if(strlen($payload)>524288)throw new RuntimeException('Webhook payload is too large.',413);
    try{$event=json_decode($payload,true,24,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('Webhook payload is not valid JSON.',400);}
    if(!is_array($event)||array_is_list($event))throw new RuntimeException('Webhook payload is not valid JSON.',400);
    $provider=strtolower(trim($provider));$valid=match($provider){'stripe'=>agent_commerce_stripe_verify_v800($payload,(string)($headers['stripe-signature']??'')),'square'=>agent_commerce_square_verify_v800($payload,(string)($headers['x-square-hmacsha256-signature']??'')),'paypal'=>agent_commerce_paypal_verify_v800($headers,$event,$http),default=>false};
    if(!$valid)throw new RuntimeException('Webhook signature verification failed.',400);
    require_once __DIR__.'/agent-commerce-provider-evidence-pa2.php';
    $target=agent_commerce_provider_event_target_pa2($provider,$event);$target['provider']=$provider;
    $claim=agent_commerce_webhook_claim_pa2($pdo,$provider,(string)($target['event_id']??''),(string)($target['event_type']??''),hash('sha256',$payload),$target);
    agent_commerce_webhook_process_claim_pa2($pdo,$claim,$http);
}
function agent_commerce_return_verify_v800(PDO $pdo,array $order,string $provider,array $query,?callable $http=null): array
{
    $provider=strtolower(trim($provider));if(!in_array($provider,['stripe','square','paypal'],true))return $order;
    $s=$pdo->prepare("SELECT * FROM agent_commerce_checkout_attempts_v800 WHERE order_id=? AND provider=? AND status IN ('open','completed','expired','cancelled') ORDER BY id DESC LIMIT 20");$s->execute([(int)$order['id'],$provider]);$attempt=null;
    foreach($s->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $selector=$provider==='stripe'?($query['session_id']??''):($provider==='paypal'?($query['token']??$row['external_session_id']):$row['external_session_id']);
        if(!is_string($selector)||!hash_equals((string)$row['external_session_id'],$selector))continue;
        $attempt=$row;break;
    }
    if(!$attempt)return agent_commerce_order_v800($pdo,(int)$order['id'])?:$order;
    $live=agent_commerce_order_v800($pdo,(int)$order['id'])?:throw new RuntimeException('Commerce order is unavailable.',409);
    $proof=agent_commerce_read_attempt_pa2($pdo,$live,$attempt,$http);
    if(($proof['status']??'')!=='verified'&&$provider==='paypal'&&in_array((string)$live['payment_status'],['awaiting_payment','partially_paid'],true)
        &&(empty($live['hold_expires_at'])||agent_commerce_date_pa2($live['hold_expires_at'])>time())){
        $connection=agent_commerce_attempt_connection_pa2($pdo,$live,$attempt);$request=agent_commerce_provider_transport_pa2($pdo,$connection,$http);
        $reply=$request('POST','/v2/checkout/orders/'.rawurlencode((string)$attempt['provider_order_id']).'/capture',new stdClass(),false,['PayPal-Request-Id: '.hash('sha256','vp3-commerce-capture|'.$attempt['id'])]);
        $capture=$reply['purchase_units'][0]['payments']['captures'][0]??null;
        if(is_array($capture))$proof=agent_commerce_provider_evidence_pa2('paypal',$attempt,$live,$connection,['order'=>$reply,'capture'=>$capture]);
        else $proof=agent_commerce_read_attempt_pa2($pdo,$live,$attempt,$http);
    }
    if(($proof['status']??'')==='verified')return agent_commerce_mark_paid_v800($pdo,(int)$live['id'],(string)$proof['provider'],(string)$proof['external_session_id'],(string)$proof['external_payment_id'],(int)$proof['amount_cents'],(string)$proof['currency'],$proof);
    return agent_commerce_order_v800($pdo,(int)$live['id'])?:$live;
}

function agent_commerce_refresh_refund_totals_v800(PDO $pdo,int $orderId): array
{
    $order=agent_commerce_order_v800($pdo,$orderId)?:throw new RuntimeException('Commerce order not found.');
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM agent_commerce_refunds_v800 WHERE order_id=? AND status='completed'");
    $stmt->execute([$orderId]);$completed=(int)$stmt->fetchColumn();$completed=min((int)$order['amount_paid_cents'],max(0,$completed));
    $newStatus=$completed>0?($completed>=(int)$order['amount_paid_cents']?'refunded':'partially_refunded'):(string)$order['payment_status'];
    if($completed>0){$pdo->prepare("UPDATE agent_commerce_orders_v800 SET amount_refunded_cents=?,payment_status=?,order_status=?,refunded_at=IF(?='refunded',COALESCE(refunded_at,NOW()),refunded_at),updated_at=NOW() WHERE id=?")->execute([$completed,$newStatus,$newStatus,$newStatus,$orderId]);}
    $fresh=agent_commerce_order_v800($pdo,$orderId)?:$order;
    if($completed>0)profile_promotion_attribution_touch_order_v1($pdo,$fresh);
    return $fresh;
}
function agent_commerce_payment_refund_reserved_v800(PDO $pdo,int $paymentId): int
{
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM agent_commerce_refunds_v800 WHERE payment_id=? AND status IN ('pending','completed')");$stmt->execute([$paymentId]);return max(0,(int)$stmt->fetchColumn());
}
function agent_commerce_issue_refund_slice_v800(PDO $pdo,array $order,array $payment,array $attempt,array $connection,int $amountCents,string $reason,int $actorId,?int $agentId): array
{
    $provider=(string)$payment['provider'];$externalPayment=(string)$payment['external_payment_id'];if($provider===''||$externalPayment==='')throw new RuntimeException('Provider payment lineage is incomplete.');
    $already=agent_commerce_payment_refund_reserved_v800($pdo,(int)$payment['id']);if($amountCents<1||$amountCents>max(0,(int)$payment['amount_cents']-$already))throw new RuntimeException('Refund slice exceeds the remaining amount on its provider payment.');
    $idempotency=hash('sha256','vp3-commerce-refund|'.(int)$order['id'].'|'.(int)$payment['id'].'|'.$amountCents.'|'.$already);$reason=mb_strimwidth(trim($reason),0,500,'');$externalRefund='';$status='completed';
    if($provider==='stripe'){$cfg=agent_commerce_config_v800()['stripe'];$params=['payment_intent'=>$externalPayment,'amount'=>$amountCents,'metadata'=>['vp3_commerce_order_id'=>(string)$order['id'],'vp3_commerce_payment_id'=>(string)$payment['id']]];$resp=agent_commerce_http_v800('POST','https://api.stripe.com/v1/refunds',['Authorization: Bearer '.$cfg['secret_key'],'Stripe-Account: '.(string)$connection['external_account_id'],'Idempotency-Key: '.$idempotency],$params,true)['json'];$externalRefund=trim((string)($resp['id']??''));$providerStatus=strtolower((string)($resp['status']??'succeeded'));$status=$providerStatus==='succeeded'?'completed':(in_array($providerStatus,['failed','canceled'],true)?'failed':'pending');}
    elseif($provider==='square'){$token=agent_commerce_square_access_token_v800($pdo,$connection);$body=['idempotency_key'=>$idempotency,'amount_money'=>['amount'=>$amountCents,'currency'=>strtoupper((string)$order['currency'])],'payment_id'=>$externalPayment,'reason'=>$reason?:'VP3 Commerce refund'];$resp=agent_commerce_http_v800('POST',agent_commerce_square_base_v800().'/v2/refunds',['Authorization: Bearer '.$token,'Square-Version: 2026-08-19'],$body)['json'];$refund=$resp['refund']??[];$externalRefund=trim((string)($refund['id']??''));$providerStatus=strtoupper((string)($refund['status']??''));$status=$providerStatus==='COMPLETED'?'completed':(in_array($providerStatus,['FAILED','REJECTED'],true)?'failed':'pending');}
    elseif($provider==='paypal'){$cfg=agent_commerce_config_v800()['paypal'];$token=agent_commerce_paypal_platform_token_v800();$headers=['Authorization: Bearer '.$token,'PayPal-Request-Id: '.$idempotency,'PayPal-Auth-Assertion: '.agent_commerce_paypal_assertion_v800((string)$connection['external_account_id'])];if($cfg['bn_code']!=='')$headers[]='PayPal-Partner-Attribution-Id: '.$cfg['bn_code'];$resp=agent_commerce_http_v800('POST',agent_commerce_paypal_base_v800().'/v2/payments/captures/'.rawurlencode($externalPayment).'/refund',$headers,['amount'=>['currency_code'=>strtoupper((string)$order['currency']),'value'=>agent_commerce_minor_to_decimal_v800($amountCents)],'note_to_payer'=>$reason?:'VP3 Commerce refund'])['json'];$externalRefund=trim((string)($resp['id']??''));$providerStatus=strtoupper((string)($resp['status']??''));$status=$providerStatus==='COMPLETED'?'completed':(in_array($providerStatus,['FAILED','CANCELLED'],true)?'failed':'pending');}
    else throw new RuntimeException('Unsupported refund provider.');
    if($externalRefund==='')throw new RuntimeException('Payment provider did not return a refund ID.');
    $pdo->prepare('INSERT INTO agent_commerce_refunds_v800 (order_id,payment_id,checkout_attempt_id,provider,external_refund_id,amount_cents,status,reason,requested_by_user_id,requested_by_agent_id,approved_by_user_id,completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([(int)$order['id'],(int)$payment['id'],(int)$attempt['id'],$provider,$externalRefund,$amountCents,$status,$reason,$actorId,$agentId,$actorId,$status==='completed'?gmdate('Y-m-d H:i:s'):null]);
    agent_commerce_audit_v800($pdo,(int)$order['id'],(int)$order['owner_user_id'],(int)($order['workspace_owner_user_id']??0)?:null,'user',$actorId,$agentId,$status==='completed'?'refund_completed':'refund_requested',(string)$order['payment_status'],$status==='completed'?'refund_completed':'refund_pending',$amountCents,['provider'=>$provider,'payment_id'=>(int)$payment['id'],'external_refund_id'=>$externalRefund,'reason'=>$reason]);return ['status'=>$status,'amount_cents'=>$amountCents,'external_refund_id'=>$externalRefund];
}
function agent_commerce_refund_v800(PDO $pdo,array $order,int $amountCents,string $reason,?array $actor=null,bool $approved=false,?int $agentId=null): array
{
    $actor??=current_user();$actorId=(int)($actor['id']??0);$authority=(int)($order['workspace_owner_user_id']??0)>0?(int)$order['workspace_owner_user_id']:(int)$order['owner_user_id'];if($actorId<1||$actorId!==$authority)throw new RuntimeException('Only the commerce account owner can approve a refund.');if(!$approved)throw new RuntimeException('Explicit refund approval is required before money is sent back to the customer.');
    $remaining=max(0,(int)$order['amount_paid_cents']-(int)$order['amount_refunded_cents']);$amountCents=max(0,$amountCents);if($amountCents<1||$amountCents>$remaining)throw new RuntimeException('Refund amount exceeds the remaining paid amount.');
    $stmt=$pdo->prepare("SELECT p.*,a.connection_id,a.id checkout_attempt_id FROM agent_commerce_payments_v800 p INNER JOIN agent_commerce_checkout_attempts_v800 a ON a.id=p.checkout_attempt_id WHERE p.order_id=? AND p.status='completed' ORDER BY p.id DESC");$stmt->execute([(int)$order['id']]);$payments=$stmt->fetchAll()?:[];if(!$payments)throw new RuntimeException('Completed payment lineage was not found.');
    $available=0;foreach($payments as $payment)$available+=max(0,(int)$payment['amount_cents']-agent_commerce_payment_refund_reserved_v800($pdo,(int)$payment['id']));if($amountCents>$available)throw new RuntimeException('Part of this order already has a pending refund. Wait for it to settle before requesting the remaining amount.');
    $left=$amountCents;$completed=0;$pending=0;foreach($payments as $payment){if($left<1)break;$paymentAvailable=max(0,(int)$payment['amount_cents']-agent_commerce_payment_refund_reserved_v800($pdo,(int)$payment['id']));if($paymentAvailable<1)continue;$slice=min($left,$paymentAvailable);$connection=agent_commerce_connection_v800($pdo,(int)$payment['connection_id']);if(!$connection){agent_commerce_refresh_refund_totals_v800($pdo,(int)$order['id']);throw new RuntimeException('Payment provider connection is unavailable for an original payment.');}$attempt=['id'=>(int)$payment['checkout_attempt_id']];try{$result=agent_commerce_issue_refund_slice_v800($pdo,$order,$payment,$attempt,$connection,$slice,$reason,$actorId,$agentId);}catch(Throwable $e){agent_commerce_refresh_refund_totals_v800($pdo,(int)$order['id']);throw $e;}if($result['status']==='failed'){agent_commerce_refresh_refund_totals_v800($pdo,(int)$order['id']);throw new RuntimeException('The payment provider rejected part of this refund. Review the Commerce ledger before retrying.');}if($result['status']==='completed')$completed+=$slice;else $pending+=$slice;$left-=$slice;}
    if($left>0)throw new RuntimeException('The full refund could not be allocated across the original provider payments.');$fresh=agent_commerce_refresh_refund_totals_v800($pdo,(int)$order['id']);if($pending>0)agent_commerce_audit_v800($pdo,(int)$order['id'],(int)$order['owner_user_id'],(int)($order['workspace_owner_user_id']??0)?:null,'user',$actorId,$agentId,'refund_batch_pending',(string)$order['payment_status'],'refund_pending',$pending,['requested_cents'=>$amountCents,'completed_cents'=>$completed]);return $fresh;
}
function agent_commerce_reconcile_refund_v800(PDO $pdo,array $refund): bool
{
    if((string)($refund['status']??'')!=='pending')return false;$paymentId=(int)($refund['payment_id']??0);if($paymentId<1)return false;$stmt=$pdo->prepare('SELECT p.*,a.connection_id FROM agent_commerce_payments_v800 p INNER JOIN agent_commerce_checkout_attempts_v800 a ON a.id=p.checkout_attempt_id WHERE p.id=? LIMIT 1');$stmt->execute([$paymentId]);$payment=$stmt->fetch();if(!$payment)return false;$connection=agent_commerce_connection_v800($pdo,(int)$payment['connection_id']);if(!$connection)return false;$provider=(string)$refund['provider'];$external=trim((string)$refund['external_refund_id']);if($external==='')return false;$status='pending';
    if($provider==='stripe'){$cfg=agent_commerce_config_v800()['stripe'];$resp=agent_commerce_http_v800('GET','https://api.stripe.com/v1/refunds/'.rawurlencode($external),['Authorization: Bearer '.$cfg['secret_key'],'Stripe-Account: '.(string)$connection['external_account_id']])['json'];$providerStatus=strtolower((string)($resp['status']??''));$status=$providerStatus==='succeeded'?'completed':(in_array($providerStatus,['failed','canceled'],true)?'failed':'pending');}
    elseif($provider==='square'){$token=agent_commerce_square_access_token_v800($pdo,$connection);$resp=agent_commerce_http_v800('GET',agent_commerce_square_base_v800().'/v2/refunds/'.rawurlencode($external),['Authorization: Bearer '.$token,'Square-Version: 2026-08-19'])['json'];$providerStatus=strtoupper((string)($resp['refund']['status']??''));$status=$providerStatus==='COMPLETED'?'completed':(in_array($providerStatus,['FAILED','REJECTED'],true)?'failed':'pending');}
    elseif($provider==='paypal'){$token=agent_commerce_paypal_platform_token_v800();$headers=['Authorization: Bearer '.$token,'PayPal-Auth-Assertion: '.agent_commerce_paypal_assertion_v800((string)$connection['external_account_id'])];$cfg=agent_commerce_config_v800()['paypal'];if($cfg['bn_code']!=='')$headers[]='PayPal-Partner-Attribution-Id: '.$cfg['bn_code'];$resp=agent_commerce_http_v800('GET',agent_commerce_paypal_base_v800().'/v2/payments/refunds/'.rawurlencode($external),$headers)['json'];$providerStatus=strtoupper((string)($resp['status']??''));$status=$providerStatus==='COMPLETED'?'completed':(in_array($providerStatus,['FAILED','CANCELLED'],true)?'failed':'pending');}
    if($status==='pending')return false;$pdo->prepare("UPDATE agent_commerce_refunds_v800 SET status=?,completed_at=?,updated_at=NOW() WHERE id=? AND status='pending'")->execute([$status,$status==='completed'?gmdate('Y-m-d H:i:s'):null,(int)$refund['id']]);$order=agent_commerce_refresh_refund_totals_v800($pdo,(int)$refund['order_id']);agent_commerce_audit_v800($pdo,(int)$order['id'],(int)$order['owner_user_id'],(int)($order['workspace_owner_user_id']??0)?:null,'provider',null,null,$status==='completed'?'refund_reconciled':'refund_failed','refund_pending',$status,(int)$refund['amount_cents'],['provider'=>$provider,'external_refund_id'=>$external]);return true;
}
function agent_commerce_reconcile_pending_refunds_v800(PDO $pdo,int $limit=100): int
{
    $limit=max(1,min(500,$limit));$rows=$pdo->query("SELECT * FROM agent_commerce_refunds_v800 WHERE status='pending' ORDER BY created_at,id LIMIT {$limit}")->fetchAll()?:[];$count=0;foreach($rows as $refund){try{if(agent_commerce_reconcile_refund_v800($pdo,$refund))$count++;}catch(Throwable $e){error_log('VP3 commerce refund reconciliation failed: '.$e->getMessage());}}return $count;
}
