<?php
declare(strict_types=1);

const VP3_AGENT_PAID_APPOINTMENTS_HOMESERVER_V810='agent-paid-appointments-homeserver-v810-20260911';

function agent_paid_appointments_homeserver_schema_v810(PDO $pdo): void
{
    if(!column_exists('agent_appointment_payment_connections_v800','authority'))$pdo->exec("ALTER TABLE agent_appointment_payment_connections_v800 ADD COLUMN authority VARCHAR(24) NOT NULL DEFAULT 'cloud' AFTER provider");
    if(!column_exists('agent_paid_bookings_v800','payment_authority'))$pdo->exec("ALTER TABLE agent_paid_bookings_v800 ADD COLUMN payment_authority VARCHAR(24) NOT NULL DEFAULT 'cloud' AFTER provider_snapshot");
    if(!column_exists('agent_paid_checkout_attempts_v800','payment_authority'))$pdo->exec("ALTER TABLE agent_paid_checkout_attempts_v800 ADD COLUMN payment_authority VARCHAR(24) NOT NULL DEFAULT 'cloud' AFTER provider");
}

function agent_paid_appointments_homeserver_remote_v810(int $userId,string $operation,array $payload=[]): array
{
    $row=homeserver_vp3_connection($userId);if(!$row||($row['status']??'')!=='paired')throw new RuntimeException('HomeServer payments are unavailable.');
    $relay=homeserver_vp3_decrypt((string)($row['relay_token_enc']??''));$token=homeserver_vp3_decrypt((string)($row['homeserver_token_enc']??''));
    if($relay===''||$token==='')throw new RuntimeException('HomeServer pairing credentials are unavailable.');
    return homeserver_vp3_remote_operation($relay,$operation,$payload,$token);
}

function agent_paid_appointments_homeserver_status_v810(int $userId): ?array
{
    try{$result=agent_paid_appointments_homeserver_remote_v810($userId,'vp3.payments.status');$stripe=$result['providers']['stripe']??null;if(!is_array($stripe)||empty($stripe['configured']))return null;$account=$stripe['account']??null;if(!is_array($account)||empty($account['account_id'])||empty($account['charges_enabled']))return null;return $stripe;}catch(Throwable $e){return null;}
}

function agent_paid_appointments_homeserver_sync_v810(PDO $pdo,int $userId): ?array
{
    if($userId<1)return null;agent_paid_appointments_homeserver_schema_v810($pdo);$stripe=agent_paid_appointments_homeserver_status_v810($userId);
    if(!$stripe){$pdo->prepare("UPDATE agent_appointment_payment_connections_v800 SET status='disconnected',is_default_personal=0,updated_at=NOW() WHERE owner_user_id=? AND authority='homeserver'")->execute([$userId]);return null;}
    $account=$stripe['account'];$external=mb_strimwidth(trim((string)$account['account_id']),0,190,'');if($external==='')return null;
    $caps=json_encode(['checkout'=>true,'refunds'=>true,'webhook_verify'=>!empty($stripe['webhook_configured']),'authority'=>'homeserver','livemode'=>!empty($account['livemode'])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $stmt=$pdo->prepare("INSERT INTO agent_appointment_payment_connections_v800 (owner_user_id,provider,authority,external_account_id,account_label,capabilities_json,status,last_verified_at,last_error) VALUES (?,'stripe','homeserver',?,'Stripe via HomeServer',?,'connected',NOW(),'') ON DUPLICATE KEY UPDATE authority='homeserver',account_label='Stripe via HomeServer',capabilities_json=VALUES(capabilities_json),status='connected',last_verified_at=NOW(),last_error='',updated_at=NOW()");
    $stmt->execute([$userId,$external,$caps]);$q=$pdo->prepare("SELECT * FROM agent_appointment_payment_connections_v800 WHERE owner_user_id=? AND provider='stripe' AND external_account_id=? LIMIT 1");$q->execute([$userId,$external]);return $q->fetch()?:null;
}

function agent_paid_appointments_available_connections_dual_v810(PDO $pdo,array $paid): array
{
    $owner=(int)($paid['team_booking_id']??0)>0?(int)($paid['workspace_owner_user_id']??0):(int)($paid['owner_user_id']??0);if($owner>0)agent_paid_appointments_homeserver_sync_v810($pdo,$owner);
    return agent_paid_appointments_available_connections_v800($pdo,$paid);
}

function agent_paid_appointments_create_checkout_dual_v810(PDO $pdo,array $paid,int $connectionId): array
{
    agent_paid_appointments_homeserver_schema_v810($pdo);$owner=(int)($paid['team_booking_id']??0)>0?(int)($paid['workspace_owner_user_id']??0):(int)($paid['owner_user_id']??0);if($owner>0)agent_paid_appointments_homeserver_sync_v810($pdo,$owner);
    $connection=agent_paid_appointments_connection_v800($pdo,$connectionId,$owner);if(!$connection||($connection['status']??'')!=='connected')throw new RuntimeException('Choose an available payment provider.');
    if((string)($connection['authority']??'cloud')!=='homeserver')return agent_paid_appointments_create_checkout_v800($pdo,$paid,$connectionId);
    if((string)$connection['provider']!=='stripe')throw new RuntimeException('This HomeServer payment provider is not supported yet.');
    if((string)$paid['payment_status']!=='awaiting_payment')throw new RuntimeException('This appointment is not awaiting payment.');if(!empty($paid['hold_expires_at'])&&strtotime((string)$paid['hold_expires_at'])<=time())throw new RuntimeException('This appointment payment hold has expired.');
    $existing=$pdo->prepare("SELECT * FROM agent_paid_checkout_attempts_v800 WHERE paid_booking_id=? AND connection_id=? ORDER BY id DESC LIMIT 1");$existing->execute([(int)$paid['id'],$connectionId]);$row=$existing->fetch();if($row&&($row['status']??'')==='open')return $row;
    $count=$pdo->prepare('SELECT COUNT(*) FROM agent_paid_checkout_attempts_v800 WHERE paid_booking_id=? AND connection_id=?');$count->execute([(int)$paid['id'],$connectionId]);$number=(int)$count->fetchColumn()+1;
    $due=max(1,(int)$paid['amount_due_cents']);$currency=strtolower((string)$paid['currency']);$idem=hash('sha256','vp3-paid-checkout|homeserver|'.(int)$paid['id'].'|'.$connectionId.'|'.$due.'|'.$currency.'|'.$number);
    $manage=agent_paid_appointments_manage_token_v800($pdo,$paid);if(!preg_match('/^[a-f0-9]{64}$/',$manage))throw new RuntimeException('Private appointment payment state is unavailable.');
    $return=agent_paid_appointments_absolute_url_v800('/appointment-payment-return.php?paid='.(int)$paid['id'].'&provider=stripe&manage='.rawurlencode($manage).'&session_id={CHECKOUT_SESSION_ID}');$cancel=agent_paid_appointments_absolute_url_v800('/appointment-payment.php?manage='.rawurlencode($manage).'&cancelled=1');
    $result=agent_paid_appointments_homeserver_remote_v810($owner,'vp3.payments.checkout.create',['paid_booking_id'=>(int)$paid['id'],'amount_cents'=>$due,'currency'=>$currency,'success_url'=>$return,'cancel_url'=>$cancel,'payer_email'=>(string)$paid['payer_email'],'idempotency_key'=>$idem]);
    $external=trim((string)($result['external_session_id']??''));$url=trim((string)($result['checkout_url']??''));if($external===''||!preg_match('#^https://#i',$url))throw new RuntimeException('HomeServer Stripe did not return a usable checkout.');
    $stmt=$pdo->prepare("INSERT INTO agent_paid_checkout_attempts_v800 (paid_booking_id,connection_id,provider,payment_authority,external_session_id,external_payment_id,checkout_url,status,amount_cents,currency,idempotency_key,expires_at) VALUES (?,?, 'stripe','homeserver',?, '',?,'open',?,?,?,?)");$stmt->execute([(int)$paid['id'],$connectionId,$external,$url,$due,$currency,$idem,$paid['hold_expires_at']]);
    $pdo->prepare("UPDATE agent_paid_bookings_v800 SET connection_id=?,provider_snapshot='stripe',payment_authority='homeserver',external_account_snapshot=?,updated_at=NOW() WHERE id=? AND payment_status='awaiting_payment'")->execute([$connectionId,(string)$connection['external_account_id'],(int)$paid['id']]);
    $id=(int)$pdo->lastInsertId();$fresh=agent_paid_appointments_checkout_attempt_v800($pdo,$id);if(!$fresh)throw new RuntimeException('HomeServer checkout lineage could not be saved.');agent_paid_appointments_audit_v800($pdo,(int)$paid['id'],(int)$paid['owner_user_id'],(int)($paid['workspace_owner_user_id']??0)?:null,'system',null,null,'checkout_created','','',$due,['provider'=>'stripe','authority'=>'homeserver','checkout_attempt_id'=>$id]);return $fresh;
}

function agent_paid_appointments_return_verify_dual_v810(PDO $pdo,array $paid,string $provider,array $query): array
{
    agent_paid_appointments_homeserver_schema_v810($pdo);$stmt=$pdo->prepare("SELECT * FROM agent_paid_checkout_attempts_v800 WHERE paid_booking_id=? AND provider=? AND status='open' ORDER BY id DESC LIMIT 1");$stmt->execute([(int)$paid['id'],$provider]);$attempt=$stmt->fetch();if(!$attempt||(string)($attempt['payment_authority']??'cloud')!=='homeserver')return agent_paid_appointments_return_verify_v800($pdo,$paid,$provider,$query);
    $session=trim((string)($query['session_id']??''));if($session===''||!hash_equals((string)$attempt['external_session_id'],$session))return $paid;$owner=(int)($paid['team_booking_id']??0)>0?(int)$paid['workspace_owner_user_id']:(int)$paid['owner_user_id'];$result=agent_paid_appointments_homeserver_remote_v810($owner,'vp3.payments.checkout.retrieve',['external_session_id'=>$session]);
    if((string)($result['payment_status']??'')==='paid'&&(int)($result['paid_booking_id']??0)===(int)$paid['id'])return agent_paid_appointments_mark_paid_v800($pdo,(int)$paid['id'],'stripe',$session,(string)($result['external_payment_id']??''),(int)($result['amount_cents']??0),(string)($result['currency']??''));return $paid;
}

function agent_paid_appointments_process_webhook_dual_v810(PDO $pdo,string $provider,string $payload,array $headers=[]): void
{
    if($provider!=='stripe') {agent_paid_appointments_process_webhook_v800($pdo,$provider,$payload,$headers);return;}
    $event=json_decode($payload,true);$obj=is_array($event)?($event['data']['object']??[]):[];$metadata=is_array($obj)?($obj['metadata']??[]):[];$authority=is_array($metadata)?(string)($metadata['vp3_payment_authority']??''):'';
    if($authority!=='homeserver'){agent_paid_appointments_process_webhook_v800($pdo,$provider,$payload,$headers);return;}
    $paidId=(int)($metadata['vp3_paid_booking_id']??0);$paid=agent_paid_appointments_paid_booking_v800($pdo,$paidId);if(!$paid||(string)($paid['payment_authority']??'')!=='homeserver')throw new RuntimeException('HomeServer payment lineage was not found.');$owner=(int)($paid['team_booking_id']??0)>0?(int)$paid['workspace_owner_user_id']:(int)$paid['owner_user_id'];
    $verified=agent_paid_appointments_homeserver_remote_v810($owner,'vp3.payments.webhook.verify',['payload_b64'=>base64_encode($payload),'stripe_signature'=>(string)($headers['stripe-signature']??'')]);if(empty($verified['verified'])||(int)($verified['paid_booking_id']??0)!==$paidId)throw new RuntimeException('HomeServer Stripe webhook verification failed.');
    $eventId=trim((string)($verified['event_id']??''));$eventType=trim((string)($verified['event_type']??''));if($eventId===''||$eventType==='')throw new RuntimeException('Verified Stripe event identity is missing.');if(!agent_paid_appointments_webhook_claim_v800($pdo,'stripe',$eventId,$eventType,$payload))return;
    try{if($eventType==='checkout.session.completed'&&(string)($verified['payment_status']??'')==='paid')agent_paid_appointments_mark_paid_v800($pdo,$paidId,'stripe',(string)($verified['object_id']??''),(string)($verified['external_payment_id']??''),(int)($verified['amount_cents']??0),(string)($verified['currency']??''));agent_paid_appointments_webhook_finish_v800($pdo,'stripe',$eventId,'processed');}catch(Throwable $e){agent_paid_appointments_webhook_finish_v800($pdo,'stripe',$eventId,'failed',$e->getMessage());throw $e;}
}
