<?php
declare(strict_types=1);

function agent_paid_appointments_paid_booking_v800(PDO $pdo,int $paidBookingId): ?array
{
    if($paidBookingId<1)return null;$stmt=$pdo->prepare('SELECT * FROM agent_paid_bookings_v800 WHERE id=? LIMIT 1');$stmt->execute([$paidBookingId]);return $stmt->fetch()?:null;
}
function agent_paid_appointments_paid_booking_for_booking_v800(PDO $pdo,int $bookingId): ?array
{
    if($bookingId<1)return null;$stmt=$pdo->prepare('SELECT * FROM agent_paid_bookings_v800 WHERE booking_id=? LIMIT 1');$stmt->execute([$bookingId]);return $stmt->fetch()?:null;
}
function agent_paid_appointments_paid_booking_for_team_v800(PDO $pdo,int $teamBookingId): ?array
{
    if($teamBookingId<1)return null;$stmt=$pdo->prepare('SELECT * FROM agent_paid_bookings_v800 WHERE team_booking_id=? LIMIT 1');$stmt->execute([$teamBookingId]);return $stmt->fetch()?:null;
}
function agent_paid_appointments_checkout_attempt_v800(PDO $pdo,int $attemptId): ?array
{
    if($attemptId<1)return null;$stmt=$pdo->prepare('SELECT a.*,b.booking_id,b.team_booking_id,b.owner_user_id,b.workspace_owner_user_id,b.payment_status booking_payment_status FROM agent_paid_checkout_attempts_v800 a JOIN agent_paid_bookings_v800 b ON b.id=a.paid_booking_id WHERE a.id=? LIMIT 1');$stmt->execute([$attemptId]);return $stmt->fetch()?:null;
}
function agent_paid_appointments_attempt_by_external_v800(PDO $pdo,string $provider,string $externalId): ?array
{
    $provider=strtolower(trim($provider));$externalId=trim($externalId);if($externalId==='')return null;
    $stmt=$pdo->prepare('SELECT a.*,b.booking_id,b.team_booking_id,b.owner_user_id,b.workspace_owner_user_id,b.payment_status booking_payment_status FROM agent_paid_checkout_attempts_v800 a JOIN agent_paid_bookings_v800 b ON b.id=a.paid_booking_id WHERE a.provider=? AND (a.external_session_id=? OR a.external_payment_id=?) ORDER BY a.id DESC LIMIT 1');$stmt->execute([$provider,$externalId,$externalId]);return $stmt->fetch()?:null;
}

function agent_paid_appointments_mark_canonical_pending_v800(PDO $pdo,int $bookingId): void
{
    $booking=agent_appointment_lifecycle_booking_v700($pdo,$bookingId);if(!$booking)return;
    $from=agent_appointment_lifecycle_status_v700($booking);
    if($from==='pending')return;
    if(!in_array($from,['confirmed','rescheduled'],true))throw new RuntimeException('This appointment cannot enter payment hold from its current lifecycle state.');
    $stmt=$pdo->prepare("UPDATE agent_scheduling_bookings SET lifecycle_status='pending',last_lifecycle_event_at=NOW() WHERE id=? AND lifecycle_status=?");$stmt->execute([$bookingId,$from]);if($stmt->rowCount()!==1)throw new RuntimeException('Appointment changed while the payment hold was being created.');
    $fresh=agent_appointment_lifecycle_booking_v700($pdo,$bookingId)?:$booking;
    agent_appointment_lifecycle_event_v700($pdo,$fresh,'payment_required',$from,'pending','system',null,null,['source'=>'paid_appointments_v800']);
}

function agent_paid_appointments_create_personal_v800(PDO $pdo,array $booking): ?array
{
    $bookingId=(int)($booking['id']??0);$eventTypeId=(int)($booking['event_type_id']??0);$ownerId=(int)($booking['owner_user_id']??0);if($bookingId<1||$eventTypeId<1||$ownerId<1)return null;
    $existing=agent_paid_appointments_paid_booking_for_booking_v800($pdo,$bookingId);if($existing)return $existing;
    $terms=agent_paid_appointments_event_terms_v800($pdo,$eventTypeId);$due=agent_paid_appointments_amount_due_v800($terms);if($due<1)return null;
    $connections=agent_paid_appointments_personal_connections_for_terms_v800($pdo,$ownerId,$terms);if(!$connections)throw new RuntimeException('This paid appointment has no connected payment provider.');
    $connection=null;if((string)$terms['provider_mode']==='fixed')$connection=$connections[0];
    $holdMinutes=max(5,min(120,(int)$terms['hold_minutes']));$hold=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$holdMinutes.' minutes')->format('Y-m-d H:i:s');
    $fee=(int)floor($due*(int)agent_paid_appointments_config_v800()['platform_fee_bps']/10000);
    $snapshot=['event_type_id'=>$eventTypeId,'payment_mode'=>$terms['payment_mode'],'price_cents'=>(int)$terms['price_cents'],'deposit_cents'=>(int)$terms['deposit_cents'],'currency'=>$terms['currency'],'refund_before_hours'=>(int)$terms['refund_before_hours'],'cancellation_fee_cents'=>(int)$terms['cancellation_fee_cents'],'cancellation_policy'=>$terms['cancellation_policy'],'provider_mode'=>$terms['provider_mode']];
    $pdo->prepare("INSERT INTO agent_paid_bookings_v800 (booking_id,owner_user_id,connection_id,provider_snapshot,external_account_snapshot,payment_mode,currency,amount_total_cents,amount_due_cents,platform_fee_cents,payment_status,payer_email,hold_expires_at,terms_snapshot_json) VALUES (?,?,?,?,?,?,?,?,?,?, 'awaiting_payment',?,?,?)")->execute([$bookingId,$ownerId,$connection['id']??null,$connection['provider']??'',$connection['external_account_id']??'',$terms['payment_mode'],$terms['currency'],(int)$terms['price_cents'],$due,$fee,mb_strimwidth(strtolower(trim((string)($booking['guest_email']??''))),0,190,''),$hold,json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $paid=agent_paid_appointments_paid_booking_v800($pdo,(int)$pdo->lastInsertId())?:throw new RuntimeException('Paid appointment record could not be created.');agent_paid_appointments_mark_canonical_pending_v800($pdo,$bookingId);agent_paid_appointments_audit_v800($pdo,(int)$paid['id'],$ownerId,null,'system',null,null,'payment_hold_created','confirmed','awaiting_payment',$due,['hold_expires_at'=>$hold]);return $paid;
}

function agent_paid_appointments_create_team_v800(PDO $pdo,array $teamBooking): ?array
{
    $teamBookingId=(int)($teamBooking['id']??0);$poolId=(int)($teamBooking['pool_id']??0);$workspaceOwnerId=(int)($teamBooking['workspace_owner_user_id']??0);if($teamBookingId<1||$poolId<1||$workspaceOwnerId<1)return null;
    $existing=agent_paid_appointments_paid_booking_for_team_v800($pdo,$teamBookingId);if($existing)return $existing;
    $terms=agent_paid_appointments_team_terms_v800($pdo,$poolId);$due=agent_paid_appointments_amount_due_v800($terms);if($due<1)return null;
    $primary=agent_paid_appointments_team_primary_v800($pdo,$workspaceOwnerId);if(!$primary||$primary['connection_status']!=='connected')throw new RuntimeException('This Team does not currently have an active primary payment provider.');
    $stmt=$pdo->prepare('SELECT canonical_booking_id,user_id FROM agent_team_scheduling_booking_members WHERE team_booking_id=? ORDER BY id');$stmt->execute([$teamBookingId]);$members=$stmt->fetchAll()?:[];if(!$members)throw new RuntimeException('Team booking participants are unavailable.');$bookingId=(int)$members[0]['canonical_booking_id'];
    $holdMinutes=max(5,min(120,(int)$terms['hold_minutes']));$hold=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$holdMinutes.' minutes')->format('Y-m-d H:i:s');$fee=(int)floor($due*(int)agent_paid_appointments_config_v800()['platform_fee_bps']/10000);
    $snapshot=['pool_id'=>$poolId,'payment_mode'=>$terms['payment_mode'],'price_cents'=>(int)$terms['price_cents'],'deposit_cents'=>(int)$terms['deposit_cents'],'currency'=>$terms['currency'],'refund_before_hours'=>(int)$terms['refund_before_hours'],'cancellation_fee_cents'=>(int)$terms['cancellation_fee_cents'],'cancellation_policy'=>$terms['cancellation_policy'],'team_primary_connection_id'=>(int)$primary['connection_id']];
    $pdo->prepare("INSERT INTO agent_paid_bookings_v800 (booking_id,team_booking_id,owner_user_id,workspace_owner_user_id,connection_id,provider_snapshot,external_account_snapshot,payment_mode,currency,amount_total_cents,amount_due_cents,platform_fee_cents,payment_status,payer_email,hold_expires_at,terms_snapshot_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'awaiting_payment',?,?,?)")->execute([$bookingId,$teamBookingId,$workspaceOwnerId,$workspaceOwnerId,(int)$primary['connection_id'],$primary['provider'],$primary['external_account_id'],$terms['payment_mode'],$terms['currency'],(int)$terms['price_cents'],$due,$fee,mb_strimwidth(strtolower(trim((string)($teamBooking['guest_email']??''))),0,190,''),$hold,json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $paid=agent_paid_appointments_paid_booking_v800($pdo,(int)$pdo->lastInsertId())?:throw new RuntimeException('Paid Team appointment record could not be created.');
    $pdo->prepare("UPDATE agent_team_scheduling_bookings SET status='pending',updated_at=NOW() WHERE id=? AND status='confirmed'")->execute([$teamBookingId]);foreach($members as $member)agent_paid_appointments_mark_canonical_pending_v800($pdo,(int)$member['canonical_booking_id']);
    agent_paid_appointments_audit_v800($pdo,(int)$paid['id'],$workspaceOwnerId,$workspaceOwnerId,'system',null,null,'team_payment_hold_created','confirmed','awaiting_payment',$due,['hold_expires_at'=>$hold,'connection_id'=>(int)$primary['connection_id']]);return $paid;
}

function agent_paid_appointments_available_connections_v800(PDO $pdo,array $paid): array
{
    if((int)($paid['team_booking_id']??0)>0){$c=agent_paid_appointments_connection_v800($pdo,(int)($paid['connection_id']??0),(int)$paid['workspace_owner_user_id']);return $c&&$c['status']==='connected'?[$c]:[];}
    $snapshot=json_decode((string)($paid['terms_snapshot_json']??''),true);if(!is_array($snapshot))$snapshot=[];
    if((string)($snapshot['provider_mode']??'guest_choice')==='fixed'){$c=agent_paid_appointments_connection_v800($pdo,(int)($paid['connection_id']??0),(int)$paid['owner_user_id']);return $c&&$c['status']==='connected'?[$c]:[];}
    return agent_paid_appointments_connections_v800($pdo,(int)$paid['owner_user_id'],true);
}

function agent_paid_appointments_oauth_state_v800(array $user,string $provider): string
{
    $provider=strtolower(trim($provider));if(!in_array($provider,['stripe','square'],true))throw new RuntimeException('That provider does not use this connection flow.');$token=bin2hex(random_bytes(24));$_SESSION['vp3_appointment_payment_oauth'][$token]=['user_id'=>(int)$user['id'],'provider'=>$provider,'expires_at'=>time()+900];return $token;
}
function agent_paid_appointments_oauth_state_take_v800(array $user,string $state): array
{
    $row=$_SESSION['vp3_appointment_payment_oauth'][$state]??null;unset($_SESSION['vp3_appointment_payment_oauth'][$state]);if(!is_array($row)||(int)($row['user_id']??0)!==(int)$user['id']||(int)($row['expires_at']??0)<time())throw new RuntimeException('Payment provider connection session expired. Please try again.');return $row;
}
function agent_paid_appointments_oauth_callback_v800(): string{return agent_paid_appointments_absolute_url_v800('/appointment-payment-oauth.php');}
function agent_paid_appointments_oauth_url_v800(array $user,string $provider): string
{
    $provider=strtolower(trim($provider));if(!agent_paid_appointments_provider_ready_v800($provider))throw new RuntimeException(agent_paid_appointments_provider_label_v800($provider).' is not configured for appointment payments.');$cfg=agent_paid_appointments_config_v800();$state=agent_paid_appointments_oauth_state_v800($user,$provider);$redirect=agent_paid_appointments_oauth_callback_v800();
    if($provider==='stripe')return 'https://connect.stripe.com/oauth/authorize?'.http_build_query(['response_type'=>'code','client_id'=>$cfg['stripe']['connect_client_id'],'scope'=>'read_write','redirect_uri'=>$redirect,'state'=>$state],'','&',PHP_QUERY_RFC3986);
    $base=$cfg['square']['environment']==='production'?'https://connect.squareup.com':'https://connect.squareupsandbox.com';return $base.'/oauth2/authorize?'.http_build_query(['client_id'=>$cfg['square']['application_id'],'scope'=>'MERCHANT_PROFILE_READ PAYMENTS_READ PAYMENTS_WRITE ORDERS_READ ORDERS_WRITE PAYMENTS_WRITE_ADDITIONAL_RECIPIENTS','session'=>'false','redirect_uri'=>$redirect,'state'=>$state],'','&',PHP_QUERY_RFC3986);
}
function agent_paid_appointments_oauth_exchange_v800(PDO $pdo,array $user,string $provider,string $code): array
{
    $cfg=agent_paid_appointments_config_v800();$redirect=agent_paid_appointments_oauth_callback_v800();$provider=strtolower(trim($provider));$code=trim($code);if($code==='')throw new RuntimeException('Payment provider authorization code is missing.');
    if($provider==='stripe'){$resp=agent_paid_appointments_http_v800('POST','https://connect.stripe.com/oauth/token',[],['client_secret'=>$cfg['stripe']['secret_key'],'code'=>$code,'grant_type'=>'authorization_code'],true)['json'];$external=trim((string)($resp['stripe_user_id']??''));if($external==='')throw new RuntimeException('Stripe did not return a connected account ID.');return agent_paid_appointments_store_connection_v800($pdo,(int)$user['id'],'stripe',$external,['access_token'=>(string)($resp['access_token']??''),'refresh_token'=>(string)($resp['refresh_token']??''),'account_label'=>'Stripe '.$external,'capabilities'=>['checkout'=>true,'refunds'=>true]]);}
    if($provider==='square'){$base=$cfg['square']['environment']==='production'?'https://connect.squareup.com':'https://connect.squareupsandbox.com';$resp=agent_paid_appointments_http_v800('POST',$base.'/oauth2/token',['Square-Version: 2026-08-19'],['client_id'=>$cfg['square']['application_id'],'client_secret'=>$cfg['square']['client_secret'],'code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>$redirect])['json'];$external=trim((string)($resp['merchant_id']??''));if($external==='')throw new RuntimeException('Square did not return a merchant ID.');$expires='';if(!empty($resp['expires_at'])){try{$expires=(new DateTimeImmutable((string)$resp['expires_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable $e){}}return agent_paid_appointments_store_connection_v800($pdo,(int)$user['id'],'square',$external,['access_token'=>(string)($resp['access_token']??''),'refresh_token'=>(string)($resp['refresh_token']??''),'token_expires_at'=>$expires?:null,'account_label'=>'Square '.$external,'capabilities'=>['checkout'=>true,'refunds'=>true]]);}
    throw new RuntimeException('Unsupported payment provider connection flow.');
}

function agent_paid_appointments_square_access_token_v800(PDO $pdo,array $connection): string
{
    $token=agent_paid_appointments_decrypt_v800((string)($connection['access_token_ciphertext']??''));if($token==='')throw new RuntimeException('Square connection token is unavailable.');$expires=(string)($connection['token_expires_at']??'');if($expires===''||strtotime($expires)>time()+300)return $token;
    $refresh=agent_paid_appointments_decrypt_v800((string)($connection['refresh_token_ciphertext']??''));if($refresh==='')throw new RuntimeException('Square connection needs to be reconnected.');$cfg=agent_paid_appointments_config_v800();$base=$cfg['square']['environment']==='production'?'https://connect.squareup.com':'https://connect.squareupsandbox.com';$resp=agent_paid_appointments_http_v800('POST',$base.'/oauth2/token',['Square-Version: 2026-08-19'],['client_id'=>$cfg['square']['application_id'],'client_secret'=>$cfg['square']['client_secret'],'refresh_token'=>$refresh,'grant_type'=>'refresh_token'])['json'];$new=trim((string)($resp['access_token']??''));if($new==='')throw new RuntimeException('Square token refresh failed.');$newRefresh=trim((string)($resp['refresh_token']??$refresh));$newExpires=null;if(!empty($resp['expires_at'])){try{$newExpires=(new DateTimeImmutable((string)$resp['expires_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable $e){}}$pdo->prepare('UPDATE agent_appointment_payment_connections_v800 SET access_token_ciphertext=?,refresh_token_ciphertext=?,token_expires_at=?,last_verified_at=NOW(),last_error=\'\' WHERE id=?')->execute([agent_paid_appointments_encrypt_v800($new),agent_paid_appointments_encrypt_v800($newRefresh),$newExpires,(int)$connection['id']]);return $new;
}

function agent_paid_appointments_paypal_base_v800(): string
{
    return agent_paid_appointments_config_v800()['paypal']['environment']==='production'?'https://api-m.paypal.com':'https://api-m.sandbox.paypal.com';
}
function agent_paid_appointments_paypal_platform_token_v800(): string
{
    $cfg=agent_paid_appointments_config_v800()['paypal'];$basic=base64_encode($cfg['client_id'].':'.$cfg['client_secret']);$resp=agent_paid_appointments_http_v800('POST',agent_paid_appointments_paypal_base_v800().'/v1/oauth2/token',['Authorization: Basic '.$basic],['grant_type'=>'client_credentials'],true)['json'];$token=trim((string)($resp['access_token']??''));if($token==='')throw new RuntimeException('PayPal platform access token could not be created.');return $token;
}
function agent_paid_appointments_paypal_assertion_v800(string $merchantId): string
{
    $client=agent_paid_appointments_config_v800()['paypal']['client_id'];$enc=static fn(array $v):string=>rtrim(strtr(base64_encode((string)json_encode($v,JSON_UNESCAPED_SLASHES)),'+/','-_'),'=');return $enc(['alg'=>'none']).'.'.$enc(['iss'=>$client,'payer_id'=>$merchantId]).'.';
}
function agent_paid_appointments_attach_paypal_v800(PDO $pdo,int $ownerUserId,string $merchantId): array
{
    if(!agent_paid_appointments_provider_ready_v800('paypal'))throw new RuntimeException('PayPal appointment payments are not configured.');$merchantId=strtoupper(trim($merchantId));if(!preg_match('/^[2-9A-HJ-NP-Z]{13}$/',$merchantId))throw new RuntimeException('Enter a valid PayPal Merchant ID.');$cfg=agent_paid_appointments_config_v800()['paypal'];$token=agent_paid_appointments_paypal_platform_token_v800();$url=agent_paid_appointments_paypal_base_v800().'/v1/customer/partners/'.rawurlencode($cfg['partner_id']).'/merchant-integrations/'.rawurlencode($merchantId);$resp=agent_paid_appointments_http_v800('GET',$url,['Authorization: Bearer '.$token,'PayPal-Partner-Attribution-Id: '.$cfg['bn_code']])['json'];if(empty($resp['payments_receivable'])||empty($resp['primary_email_confirmed']))throw new RuntimeException('This PayPal merchant is not currently eligible to receive appointment payments.');return agent_paid_appointments_store_connection_v800($pdo,$ownerUserId,'paypal',$merchantId,['account_label'=>(string)($resp['legal_name']??'PayPal '.$merchantId),'account_email'=>(string)($resp['primary_email']??''),'capabilities'=>['checkout'=>true,'refunds'=>true,'payments_receivable'=>true]]);
}

function agent_paid_appointments_create_checkout_v800(PDO $pdo,array $paid,int $connectionId): array
{
    if((string)$paid['payment_status']!=='awaiting_payment')throw new RuntimeException('This appointment is not awaiting payment.');if(!empty($paid['hold_expires_at'])&&strtotime((string)$paid['hold_expires_at'])<=time())throw new RuntimeException('This appointment payment hold has expired.');
    $allowed=agent_paid_appointments_available_connections_v800($pdo,$paid);$connection=null;foreach($allowed as $candidate)if((int)$candidate['id']===$connectionId){$connection=$candidate;break;}if(!$connection)throw new RuntimeException('Choose an available payment provider.');$provider=(string)$connection['provider'];if(!agent_paid_appointments_provider_ready_v800($provider))throw new RuntimeException(agent_paid_appointments_provider_label_v800($provider).' is not configured on this VP3 deployment.');
    $due=max(1,(int)$paid['amount_due_cents']);$currency=strtolower((string)$paid['currency']);$idempotency=hash('sha256','vp3-paid-checkout|'.(int)$paid['id'].'|'.$connectionId.'|'.$due.'|'.$currency);$existing=$pdo->prepare('SELECT * FROM agent_paid_checkout_attempts_v800 WHERE idempotency_key=? LIMIT 1');$existing->execute([$idempotency]);$attempt=$existing->fetch();if($attempt&&$attempt['status']==='open')return $attempt;
    $return=agent_paid_appointments_absolute_url_v800('/appointment-payment-return.php?paid='.(int)$paid['id'].'&provider='.rawurlencode($provider));$cancel=agent_paid_appointments_absolute_url_v800('/appointment-payment.php?paid='.(int)$paid['id'].'&cancelled=1');$name='VP3 appointment payment';$fee=max(0,min($due-1,(int)$paid['platform_fee_cents']));
    $externalId='';$externalPayment='';$checkoutUrl='';
    if($provider==='stripe'){$cfg=agent_paid_appointments_config_v800()['stripe'];$params=['mode'=>'payment','success_url'=>$return.'&session_id={CHECKOUT_SESSION_ID}','cancel_url'=>$cancel,'customer_email'=>(string)$paid['payer_email'],'line_items'=>[['quantity'=>1,'price_data'=>['currency'=>$currency,'unit_amount'=>$due,'product_data'=>['name'=>$name]]]],'metadata'=>['vp3_paid_booking_id'=>(string)$paid['id']],'payment_intent_data'=>['metadata'=>['vp3_paid_booking_id'=>(string)$paid['id']]]];if($fee>0)$params['payment_intent_data']['application_fee_amount']=$fee;$resp=agent_paid_appointments_http_v800('POST','https://api.stripe.com/v1/checkout/sessions',['Authorization: Bearer '.$cfg['secret_key'],'Stripe-Account: '.(string)$connection['external_account_id'],'Idempotency-Key: '.$idempotency],$params,true)['json'];$externalId=trim((string)($resp['id']??''));$checkoutUrl=trim((string)($resp['url']??''));}
    elseif($provider==='square'){$cfg=agent_paid_appointments_config_v800()['square'];$base=$cfg['environment']==='production'?'https://connect.squareup.com':'https://connect.squareupsandbox.com';$token=agent_paid_appointments_square_access_token_v800($pdo,$connection);$body=['idempotency_key'=>$idempotency,'quick_pay'=>['name'=>$name,'price_money'=>['amount'=>$due,'currency'=>strtoupper($currency)],'location_id'=>(string)($connection['external_account_id'])],'checkout_options'=>['redirect_url'=>$return]];if($fee>0)$body['checkout_options']['app_fee_money']=['amount'=>$fee,'currency'=>strtoupper($currency)];$resp=agent_paid_appointments_http_v800('POST',$base.'/v2/online-checkout/payment-links',['Authorization: Bearer '.$token,'Square-Version: 2026-08-19'],$body)['json'];$link=$resp['payment_link']??[];$externalId=trim((string)($link['id']??''));$externalPayment=trim((string)($link['order_id']??''));$checkoutUrl=trim((string)($link['url']??''));}
    elseif($provider==='paypal'){$cfg=agent_paid_appointments_config_v800()['paypal'];$token=agent_paid_appointments_paypal_platform_token_v800();$merchant=(string)$connection['external_account_id'];$headers=['Authorization: Bearer '.$token,'PayPal-Request-Id: '.$idempotency,'PayPal-Auth-Assertion: '.agent_paid_appointments_paypal_assertion_v800($merchant)];if($cfg['bn_code']!=='')$headers[]='PayPal-Partner-Attribution-Id: '.$cfg['bn_code'];$unit=['reference_id'=>'vp3-'.$paid['id'],'payee'=>['merchant_id'=>$merchant],'amount'=>['currency_code'=>strtoupper($currency),'value'=>number_format($due/100,2,'.','')]];if($fee>0)$unit['payment_instruction']=['disbursement_mode'=>'INSTANT','platform_fees'=>[['amount'=>['currency_code'=>strtoupper($currency),'value'=>number_format($fee/100,2,'.','')]]]];$resp=agent_paid_appointments_http_v800('POST',agent_paid_appointments_paypal_base_v800().'/v2/checkout/orders',$headers,['intent'=>'CAPTURE','purchase_units'=>[$unit],'application_context'=>['return_url'=>$return,'cancel_url'=>$cancel,'shipping_preference'=>'NO_SHIPPING']])['json'];$externalId=trim((string)($resp['id']??''));foreach((array)($resp['links']??[]) as $link)if(($link['rel']??'')==='approve'){$checkoutUrl=(string)($link['href']??'');break;}}
    if($externalId===''||$checkoutUrl==='')throw new RuntimeException(agent_paid_appointments_provider_label_v800($provider).' did not return a usable checkout.');
    $pdo->prepare('INSERT INTO agent_paid_checkout_attempts_v800 (paid_booking_id,connection_id,provider,external_session_id,external_payment_id,checkout_url,status,amount_cents,currency,idempotency_key,expires_at) VALUES (?,?,?,?,?,?,\'open\',?,?,?,?,?)')->execute([(int)$paid['id'],$connectionId,$provider,$externalId,$externalPayment,$checkoutUrl,$due,$currency,$idempotency,$paid['hold_expires_at']]);
    $attemptId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE agent_paid_bookings_v800 SET connection_id=?,provider_snapshot=?,external_account_snapshot=?,updated_at=NOW() WHERE id=? AND payment_status=\'awaiting_payment\'')->execute([$connectionId,$provider,(string)$connection['external_account_id'],(int)$paid['id']]);agent_paid_appointments_audit_v800($pdo,(int)$paid['id'],(int)$paid['owner_user_id'],(int)($paid['workspace_owner_user_id']??0)?:null,'guest',null,null,'checkout_created','awaiting_payment','awaiting_payment',$due,['provider'=>$provider,'attempt_id'=>$attemptId]);return agent_paid_appointments_checkout_attempt_v800($pdo,$attemptId)?:throw new RuntimeException('Checkout attempt could not be saved.');
}
