<?php
declare(strict_types=1);

function agent_commerce_oauth_state_v800(array $user,string $provider): string
{
    $provider=strtolower(trim($provider));if(!in_array($provider,['stripe','square'],true))throw new RuntimeException('That provider does not use this connection flow.');$token=bin2hex(random_bytes(24));$_SESSION['vp3_commerce_oauth'][$token]=['user_id'=>(int)$user['id'],'provider'=>$provider,'expires_at'=>time()+900];return $token;
}
function agent_commerce_oauth_state_take_v800(array $user,string $state): array
{
    $row=$_SESSION['vp3_commerce_oauth'][$state]??null;unset($_SESSION['vp3_commerce_oauth'][$state]);if(!is_array($row)||(int)($row['user_id']??0)!==(int)$user['id']||(int)($row['expires_at']??0)<time())throw new RuntimeException('Payment provider connection session expired. Please try again.');return $row;
}
function agent_commerce_oauth_callback_v800(): string
{
    return agent_commerce_absolute_url_v800('/commerce-provider-oauth.php');
}
function agent_commerce_oauth_url_v800(array $user,string $provider): string
{
    $provider=strtolower(trim($provider));if(!agent_commerce_provider_ready_v800($provider))throw new RuntimeException(agent_commerce_provider_label_v800($provider).' is not configured for VP3 Commerce.');$cfg=agent_commerce_config_v800();$state=agent_commerce_oauth_state_v800($user,$provider);$redirect=agent_commerce_oauth_callback_v800();if($provider==='stripe')return 'https://connect.stripe.com/oauth/authorize?'.http_build_query(['response_type'=>'code','client_id'=>$cfg['stripe']['connect_client_id'],'scope'=>'read_write','redirect_uri'=>$redirect,'state'=>$state],'','&',PHP_QUERY_RFC3986);$base=$cfg['square']['environment']==='production'?'https://connect.squareup.com':'https://connect.squareupsandbox.com';return $base.'/oauth2/authorize?'.http_build_query(['client_id'=>$cfg['square']['application_id'],'scope'=>'MERCHANT_PROFILE_READ PAYMENTS_READ PAYMENTS_WRITE ORDERS_READ ORDERS_WRITE PAYMENTS_WRITE_ADDITIONAL_RECIPIENTS','session'=>'false','redirect_uri'=>$redirect,'state'=>$state],'','&',PHP_QUERY_RFC3986);
}
function agent_commerce_square_base_v800(): string
{
    return agent_commerce_config_v800()['square']['environment']==='production'?'https://connect.squareup.com':'https://connect.squareupsandbox.com';
}
function agent_commerce_capabilities_v800(array $connection): array
{
    $decoded=json_decode((string)($connection['capabilities_json']??''),true);return is_array($decoded)?$decoded:[];
}
function agent_commerce_square_profile_v800(string $accessToken): array
{
    $resp=agent_commerce_http_v800('GET',agent_commerce_square_base_v800().'/v2/merchants',['Authorization: Bearer '.$accessToken,'Square-Version: 2026-08-19'])['json'];$merchants=$resp['merchant']??$resp['merchants']??[];$merchant=is_array($merchants)&&isset($merchants[0])&&is_array($merchants[0])?$merchants[0]:[];$merchantId=trim((string)($merchant['id']??''));$locationId=trim((string)($merchant['main_location_id']??''));if($merchantId===''||$locationId==='')throw new RuntimeException('Square merchant or main location could not be verified.');return ['merchant_id'=>$merchantId,'location_id'=>$locationId,'business_name'=>trim((string)($merchant['business_name']??'')),'currency'=>strtolower(trim((string)($merchant['currency']??'')))];
}
function agent_commerce_oauth_exchange_v800(PDO $pdo,array $user,string $provider,string $code): array
{
    $cfg=agent_commerce_config_v800();$redirect=agent_commerce_oauth_callback_v800();$provider=strtolower(trim($provider));$code=trim($code);if($code==='')throw new RuntimeException('Payment provider authorization code is missing.');if($provider==='stripe'){$resp=agent_commerce_http_v800('POST','https://connect.stripe.com/oauth/token',[],['client_secret'=>$cfg['stripe']['secret_key'],'code'=>$code,'grant_type'=>'authorization_code'],true)['json'];$external=trim((string)($resp['stripe_user_id']??''));if($external==='')throw new RuntimeException('Stripe did not return a connected account ID.');return agent_commerce_store_connection_v800($pdo,(int)$user['id'],'stripe',$external,['access_token'=>(string)($resp['access_token']??''),'refresh_token'=>(string)($resp['refresh_token']??''),'account_label'=>'Stripe '.$external,'capabilities'=>['checkout'=>true,'refunds'=>true]]);}if($provider==='square'){$resp=agent_commerce_http_v800('POST',agent_commerce_square_base_v800().'/oauth2/token',['Square-Version: 2026-08-19'],['client_id'=>$cfg['square']['application_id'],'client_secret'=>$cfg['square']['client_secret'],'code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>$redirect])['json'];$access=trim((string)($resp['access_token']??''));if($access==='')throw new RuntimeException('Square did not return an access token.');$profile=agent_commerce_square_profile_v800($access);$external=trim((string)($resp['merchant_id']??$profile['merchant_id']));if($external===''||!hash_equals((string)$profile['merchant_id'],$external))throw new RuntimeException('Square merchant identity could not be verified.');$expires=null;if(!empty($resp['expires_at'])){try{$expires=(new DateTimeImmutable((string)$resp['expires_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable $e){}}return agent_commerce_store_connection_v800($pdo,(int)$user['id'],'square',$external,['access_token'=>$access,'refresh_token'=>(string)($resp['refresh_token']??''),'token_expires_at'=>$expires,'account_label'=>(string)($profile['business_name']?:'Square '.$external),'capabilities'=>['checkout'=>true,'refunds'=>true,'location_id'=>$profile['location_id'],'currency'=>$profile['currency']]]);}throw new RuntimeException('Unsupported payment provider connection flow.');
}
function agent_commerce_square_access_token_v800(PDO $pdo,array $connection): string
{
    $token=agent_commerce_decrypt_v800((string)($connection['access_token_ciphertext']??''));if($token==='')throw new RuntimeException('Square connection token is unavailable.');$expires=(string)($connection['token_expires_at']??'');if($expires===''||strtotime($expires)>time()+300)return $token;$refresh=agent_commerce_decrypt_v800((string)($connection['refresh_token_ciphertext']??''));if($refresh==='')throw new RuntimeException('Square connection needs to be reconnected.');$cfg=agent_commerce_config_v800();$resp=agent_commerce_http_v800('POST',agent_commerce_square_base_v800().'/oauth2/token',['Square-Version: 2026-08-19'],['client_id'=>$cfg['square']['application_id'],'client_secret'=>$cfg['square']['client_secret'],'refresh_token'=>$refresh,'grant_type'=>'refresh_token'])['json'];$new=trim((string)($resp['access_token']??''));if($new==='')throw new RuntimeException('Square token refresh failed.');$newRefresh=trim((string)($resp['refresh_token']??$refresh));$newExpires=null;if(!empty($resp['expires_at'])){try{$newExpires=(new DateTimeImmutable((string)$resp['expires_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable $e){}}$pdo->prepare("UPDATE agent_commerce_provider_connections_v800 SET access_token_ciphertext=?,refresh_token_ciphertext=?,token_expires_at=?,last_verified_at=NOW(),last_error='' WHERE id=?")->execute([agent_commerce_encrypt_v800($new),agent_commerce_encrypt_v800($newRefresh),$newExpires,(int)$connection['id']]);return $new;
}
function agent_commerce_paypal_base_v800(): string
{
    return agent_commerce_config_v800()['paypal']['environment']==='production'?'https://api-m.paypal.com':'https://api-m.sandbox.paypal.com';
}
function agent_commerce_paypal_platform_token_v800(): string
{
    $cfg=agent_commerce_config_v800()['paypal'];$basic=base64_encode($cfg['client_id'].':'.$cfg['client_secret']);$resp=agent_commerce_http_v800('POST',agent_commerce_paypal_base_v800().'/v1/oauth2/token',['Authorization: Basic '.$basic],['grant_type'=>'client_credentials'],true)['json'];$token=trim((string)($resp['access_token']??''));if($token==='')throw new RuntimeException('PayPal platform access token could not be created.');return $token;
}
function agent_commerce_paypal_assertion_v800(string $merchantId): string
{
    $client=agent_commerce_config_v800()['paypal']['client_id'];$enc=static fn(array $v):string=>rtrim(strtr(base64_encode((string)json_encode($v,JSON_UNESCAPED_SLASHES)),'+/','-_'),'=');return $enc(['alg'=>'none']).'.'.$enc(['iss'=>$client,'payer_id'=>$merchantId]).'.';
}
function agent_commerce_attach_paypal_v800(PDO $pdo,int $ownerUserId,string $merchantId): array
{
    if(!agent_commerce_provider_ready_v800('paypal'))throw new RuntimeException('PayPal is not configured for VP3 Commerce.');$merchantId=strtoupper(trim($merchantId));if(!preg_match('/^[2-9A-HJ-NP-Z]{13}$/',$merchantId))throw new RuntimeException('Enter a valid PayPal Merchant ID.');$cfg=agent_commerce_config_v800()['paypal'];$token=agent_commerce_paypal_platform_token_v800();$url=agent_commerce_paypal_base_v800().'/v1/customer/partners/'.rawurlencode($cfg['partner_id']).'/merchant-integrations/'.rawurlencode($merchantId);$headers=['Authorization: Bearer '.$token];if($cfg['bn_code']!=='')$headers[]='PayPal-Partner-Attribution-Id: '.$cfg['bn_code'];$resp=agent_commerce_http_v800('GET',$url,$headers)['json'];if(empty($resp['payments_receivable'])||empty($resp['primary_email_confirmed']))throw new RuntimeException('This PayPal merchant is not currently eligible to receive customer payments.');return agent_commerce_store_connection_v800($pdo,$ownerUserId,'paypal',$merchantId,['account_label'=>(string)($resp['legal_name']??'PayPal '.$merchantId),'account_email'=>(string)($resp['primary_email']??''),'capabilities'=>['checkout'=>true,'refunds'=>true,'payments_receivable'=>true]]);
}
function agent_commerce_checkout_request_pa2(PDO $pdo,array $order,array $connection,string $returnUrl,string $cancelUrl): array
{
    $provider=(string)$connection['provider'];$cfg=agent_commerce_config_v800();$due=(int)$order['amount_due_cents'];$currency=strtolower((string)$order['currency']);
    $name=agent_commerce_order_title_v800($pdo,$order);$fee=max(0,min($due-1,(int)floor($due*(int)$cfg['platform_fee_bps']/10000)));
    if($provider==='stripe'){
        $body=['mode'=>'payment','success_url'=>$returnUrl.'&session_id={CHECKOUT_SESSION_ID}','cancel_url'=>$cancelUrl,
            'line_items'=>[['quantity'=>1,'price_data'=>['currency'=>$currency,'unit_amount'=>$due,'product_data'=>['name'=>$name]]]],
            'metadata'=>['vp3_commerce_order_id'=>(string)$order['id']],'payment_intent_data'=>['metadata'=>['vp3_commerce_order_id'=>(string)$order['id']]]];
        $deadline=agent_commerce_date_pa2($order['hold_expires_at']??null);
        if($deadline>0)$body['expires_at']=min(time()+86400,max(time()+1800,$deadline));
        $email=(string)($order['payer_email']??'');if(filter_var($email,FILTER_VALIDATE_EMAIL))$body['customer_email']=$email;
        if($fee>0)$body['payment_intent_data']['application_fee_amount']=$fee;
        return ['path'=>'/v1/checkout/sessions','form'=>true,'body'=>$body];
    }
    if($provider==='square'){
        $caps=agent_commerce_capabilities_v800($connection);$location=(string)($caps['location_id']??'');
        if($location==='')throw new RuntimeException('The selected Square location must be verified before checkout.',409);
        if((string)($caps['currency']??'')!==''&&strtolower((string)$caps['currency'])!==$currency)throw new RuntimeException('This Square location does not support the order currency.',409);
        $body=['quick_pay'=>['name'=>$name,'price_money'=>['amount'=>$due,'currency'=>strtoupper($currency)],'location_id'=>$location],'checkout_options'=>['redirect_url'=>$returnUrl]];
        if($fee>0){if((string)$cfg['square']['environment']!=='production')throw new RuntimeException('Square sandbox does not support application fees for hosted commerce checkout. Set the commerce platform fee to 0 while testing Square sandbox.');$body['checkout_options']['app_fee_money']=['amount'=>$fee,'currency'=>strtoupper($currency)];}
        return ['path'=>'/v2/online-checkout/payment-links','form'=>false,'body'=>$body];
    }
    if($provider==='paypal'){
        $unit=['reference_id'=>'vp3-commerce-'.$order['id'],'payee'=>['merchant_id'=>(string)$connection['external_account_id']],
            'amount'=>['currency_code'=>strtoupper($currency),'value'=>agent_commerce_minor_to_decimal_v800($due)]];
        if($fee>0)$unit['payment_instruction']=['disbursement_mode'=>'INSTANT','platform_fees'=>[['amount'=>['currency_code'=>strtoupper($currency),'value'=>agent_commerce_minor_to_decimal_v800($fee)]]]];
        return ['path'=>'/v2/checkout/orders','form'=>false,'body'=>['intent'=>'CAPTURE','purchase_units'=>[$unit],
            'application_context'=>['return_url'=>$returnUrl,'cancel_url'=>$cancelUrl,'shipping_preference'=>'NO_SHIPPING']]];
    }
    throw new RuntimeException('Commerce provider is unavailable.',409);
}
function agent_commerce_create_checkout_v800(PDO $pdo,array $order,int $connectionId,string $returnUrl,string $cancelUrl): array
{
    if($pdo->inTransaction())throw new RuntimeException('Checkout must begin after the canonical order is committed.',409);
    if(!agent_commerce_recovery_schema_ready_pa2($pdo))throw new RuntimeException('Commerce recovery schema is unavailable.',503);
    foreach([$returnUrl,$cancelUrl] as $url){$parts=parse_url($url);if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['https','http'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||strlen($url)>8192||preg_match('/[\x00-\x20\x7f]/',$url))throw new RuntimeException('Commerce return and cancel URLs must be absolute.',409);}
    $claim=agent_commerce_atomic_pa2($pdo,function()use($pdo,$order,$connectionId,$returnUrl,$cancelUrl):array{
        $s=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=?'.agent_commerce_lock_pa2($pdo));$s->execute([(int)$order['id']]);$live=$s->fetch(PDO::FETCH_ASSOC);
        if(!$live||!in_array((string)$live['payment_status'],['awaiting_payment','partially_paid'],true)||(int)$live['amount_due_cents']<1)throw new RuntimeException('This order is not awaiting payment.',409);
        if(!empty($live['hold_expires_at'])&&agent_commerce_date_pa2($live['hold_expires_at'])<=time())throw new RuntimeException('This payment hold has expired.',409);
        $connection=null;foreach(agent_commerce_available_connections_v800($pdo,$live) as $candidate)if((int)$candidate['id']===$connectionId){$connection=$candidate;break;}
        if(!$connection)throw new RuntimeException('Choose an available payment provider.',409);
        $provider=(string)$connection['provider'];if(!agent_commerce_provider_ready_v800($provider))throw new RuntimeException(agent_commerce_provider_label_v800($provider).' is not configured on this VP3 deployment.',503);
        $q=$pdo->prepare("SELECT * FROM agent_commerce_checkout_attempts_v800 WHERE order_id=? AND connection_id=? AND status IN ('creating','open') ORDER BY id DESC LIMIT 1".agent_commerce_lock_pa2($pdo));$q->execute([(int)$live['id'],$connectionId]);$a=$q->fetch(PDO::FETCH_ASSOC);
        if($a){
            if((int)$a['amount_cents']!==(int)$live['amount_due_cents']||strtolower((string)$a['currency'])!==strtolower((string)$live['currency'])||!hash_equals((string)$a['provider_account_id'],(string)$connection['external_account_id']))throw new RuntimeException('Checkout terms or provider identity changed. Review the order again.',409);
            if((string)$a['status']==='open')return ['state'=>'open','attempt'=>$a];
            if(agent_commerce_date_pa2($a['creation_lease_expires_at'])>time())throw new RuntimeException('That checkout is already in progress. Retry with the same idempotency key.',503);
            $request=agent_commerce_recovery_json_pa2($a['creation_request_json'],32768);
        }else{
            $request=agent_commerce_checkout_request_pa2($pdo,$live,$connection,$returnUrl,$cancelUrl);
            $count=$pdo->prepare('SELECT COUNT(*) FROM agent_commerce_checkout_attempts_v800 WHERE order_id=? AND connection_id=?');$count->execute([(int)$live['id'],$connectionId]);$number=(int)$count->fetchColumn()+1;
            $idempotency=hash('sha256','vp3-commerce-checkout|'.(int)$live['id'].'|'.$connectionId.'|'.(int)$live['amount_due_cents'].'|'.strtolower((string)$live['currency']).'|'.$number);
            if($provider==='square')$request['body']['idempotency_key']=$idempotency;
            $encoded=json_encode($request,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($encoded)>32768)throw new RuntimeException('Checkout request exceeds the supported size.',409);
            $caps=agent_commerce_capabilities_v800($connection);$location=$provider==='square'?(string)($caps['location_id']??''):'';$now=agent_commerce_time_pa2();
            $pdo->prepare("INSERT INTO agent_commerce_checkout_attempts_v800 (order_id,connection_id,provider,external_session_id,external_payment_id,provider_order_id,provider_account_id,provider_location_id,checkout_url,status,amount_cents,currency,idempotency_key,expires_at,creation_request_json,created_at,updated_at) VALUES (?,?,?,?,?,'',?,?,NULL,'creating',?,?,?,?,?,?,?)")
                ->execute([(int)$live['id'],$connectionId,$provider,'pending:'.$idempotency,'',(string)$connection['external_account_id'],$location,(int)$live['amount_due_cents'],strtolower((string)$live['currency']),$idempotency,$live['hold_expires_at'],$encoded,$now,$now]);
            $id=(int)$pdo->lastInsertId();$fetch=$pdo->prepare('SELECT * FROM agent_commerce_checkout_attempts_v800 WHERE id=?');$fetch->execute([$id]);$a=$fetch->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Checkout attempt could not be recorded.');
            $pdo->prepare('UPDATE agent_commerce_orders_v800 SET connection_id=?,provider_snapshot=?,external_account_snapshot=?,updated_at=? WHERE id=?')->execute([$connectionId,$provider,(string)$connection['external_account_id'],$now,(int)$live['id']]);
            agent_commerce_audit_v800($pdo,(int)$live['id'],(int)$live['owner_user_id'],(int)($live['workspace_owner_user_id']??0)?:null,'guest',null,null,'checkout_requested',(string)$live['payment_status'],(string)$live['payment_status'],(int)$live['amount_due_cents'],['provider'=>$provider,'attempt_id'=>$id,'attempt_number'=>$number]);
        }
        if(!is_string($request['path']??null)||!is_bool($request['form']??null)||!is_array($request['body']??null)||array_diff(array_keys($request),['path','form','body']))throw new RuntimeException('Saved checkout request is unavailable.',409);
        $token=bin2hex(random_bytes(32));$until=gmdate('Y-m-d H:i:s',time()+180);$now=agent_commerce_time_pa2();
        $pdo->prepare('UPDATE agent_commerce_checkout_attempts_v800 SET creation_lease_token=?,creation_lease_expires_at=?,creation_attempt_count=creation_attempt_count+1,last_error_class=?,updated_at=? WHERE id=?')->execute([$token,$until,'',$now,(int)$a['id']]);
        return ['state'=>'claimed','attempt'=>$a,'connection'=>$connection,'request'=>$request,'token'=>$token];
    });
    if($claim['state']==='open')return agent_commerce_checkout_attempt_v800($pdo,(int)$claim['attempt']['id'])?:$claim['attempt'];
    $a=$claim['attempt'];$provider=(string)$a['provider'];
    try{
        $transport=agent_commerce_provider_transport_pa2($pdo,$claim['connection']);$extra=[];
        if($provider==='stripe')$extra[]='Idempotency-Key: '.$a['idempotency_key'];
        if($provider==='paypal')$extra[]='PayPal-Request-Id: '.$a['idempotency_key'];
        $request=$claim['request'];$response=$transport('POST',$request['path'],$request['body'],$request['form'],$extra);
        $session='';$providerOrder='';$url='';
        if($provider==='stripe'){$session=$response['id']??null;$providerOrder=$session;$url=$response['url']??null;}
        elseif($provider==='square'){$link=$response['payment_link']??null;if(is_array($link)){$session=$link['id']??null;$providerOrder=$link['order_id']??null;$url=$link['url']??null;}}
        else{$session=$response['id']??null;$providerOrder=$session;foreach((array)($response['links']??[]) as $link)if(is_array($link)&&($link['rel']??'')==='approve'){$url=$link['href']??null;break;}}
        foreach([$session,$providerOrder] as $id)if(!is_string($id)||!preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D',$id))throw new RuntimeException('Payment provider did not return a usable checkout.',503);
        $parsed=is_string($url)?parse_url($url):false;if(!is_string($url)||strlen($url)>8192||!is_array($parsed)||strtolower((string)($parsed['scheme']??''))!=='https'||empty($parsed['host'])||isset($parsed['user'])||isset($parsed['pass'])||preg_match('/[\x00-\x20\x7f]/',$url))throw new RuntimeException('Payment provider did not return a usable checkout.',503);
        $result=agent_commerce_atomic_pa2($pdo,function()use($pdo,$claim,$a,$session,$providerOrder,$url):array{
            $o=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=?'.agent_commerce_lock_pa2($pdo));$o->execute([(int)$a['order_id']]);$order=$o->fetch(PDO::FETCH_ASSOC);
            $s=$pdo->prepare('SELECT * FROM agent_commerce_checkout_attempts_v800 WHERE id=?'.agent_commerce_lock_pa2($pdo));$s->execute([(int)$a['id']]);$live=$s->fetch(PDO::FETCH_ASSOC);
            if(!$order||!$live||!hash_equals((string)$live['creation_lease_token'],(string)$claim['token'])||agent_commerce_date_pa2($live['creation_lease_expires_at'])<time())throw new RuntimeException('Checkout processing ownership changed.',503);
            agent_commerce_attempt_connection_pa2($pdo,$order,$live);
            $termsMatch=(int)$order['amount_due_cents']===(int)$live['amount_cents']&&strtolower((string)$order['currency'])===strtolower((string)$live['currency'])
                &&(int)$order['connection_id']===(int)$live['connection_id']&&(string)$order['provider_snapshot']===(string)$live['provider']
                &&hash_equals((string)$order['external_account_snapshot'],(string)$live['provider_account_id']);
            $active=$termsMatch&&in_array((string)$order['payment_status'],['awaiting_payment','partially_paid'],true)&&(empty($order['hold_expires_at'])||agent_commerce_date_pa2($order['hold_expires_at'])>time());$status=$active?'open':($termsMatch?'expired':'cancelled');$now=agent_commerce_time_pa2();
            $reason=$termsMatch?'':'checkout_terms_changed';
            $pdo->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET external_session_id=?,provider_order_id=?,checkout_url=?,status=?,creation_lease_token='',creation_lease_expires_at=NULL,last_error_class=?,updated_at=? WHERE id=? AND creation_lease_token=?")
                ->execute([$session,$providerOrder,$url,$status,$reason,$now,(int)$a['id'],(string)$claim['token']]);
            agent_commerce_audit_v800($pdo,(int)$order['id'],(int)$order['owner_user_id'],(int)($order['workspace_owner_user_id']??0)?:null,'guest',null,null,'checkout_created',(string)$order['payment_status'],(string)$order['payment_status'],(int)$live['amount_cents'],['provider'=>(string)$live['provider'],'attempt_id'=>(int)$live['id']]);
            return agent_commerce_checkout_attempt_v800($pdo,(int)$live['id'])?:throw new RuntimeException('Checkout attempt could not be reloaded.');
        });
        if((string)($result['last_error_class']??'')==='checkout_terms_changed')throw new RuntimeException('Checkout terms changed. Prepare checkout again.',409);
        if((string)$result['status']!=='open')throw new RuntimeException('This payment hold has expired. Provider reconciliation will preserve any verified payment.',409);
        return $result;
    }catch(Throwable $e){
        $pdo->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET creation_lease_token='',creation_lease_expires_at=NULL,last_error_class='checkout_provider_uncertain',updated_at=? WHERE id=? AND creation_lease_token=?")
            ->execute([agent_commerce_time_pa2(),(int)$a['id'],(string)$claim['token']]);
        if((int)$e->getCode()===409)throw $e;
        throw new RuntimeException('Checkout could not yet be verified. Retry the same prepared checkout.',503);
    }
}
