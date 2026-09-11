<?php
declare(strict_types=1);

/** Team Super Admin is the canonical workspace owner. No Manager/Producer/global-admin bypass. */
function agent_paid_appointments_is_team_super_admin_v800(int $workspaceOwnerId,?array $actor=null): bool
{
    $actor??=current_user();return $workspaceOwnerId>0&&(int)($actor['id']??0)===$workspaceOwnerId;
}

function agent_paid_appointments_connection_v800(PDO $pdo,int $connectionId,int $ownerUserId=0): ?array
{
    if($connectionId<1)return null;$sql='SELECT * FROM agent_appointment_payment_connections_v800 WHERE id=?';$args=[$connectionId];
    if($ownerUserId>0){$sql.=' AND owner_user_id=?';$args[]=$ownerUserId;}$sql.=' LIMIT 1';$stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetch()?:null;
}

function agent_paid_appointments_connections_v800(PDO $pdo,int $ownerUserId,bool $connectedOnly=false): array
{
    if($ownerUserId<1)return [];$sql='SELECT * FROM agent_appointment_payment_connections_v800 WHERE owner_user_id=?';$args=[$ownerUserId];
    if($connectedOnly)$sql.=" AND status='connected'";$sql.=' ORDER BY is_default_personal DESC,provider,account_label,id';$stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetchAll()?:[];
}

function agent_paid_appointments_store_connection_v800(PDO $pdo,int $ownerUserId,string $provider,string $externalAccountId,array $data=[]): array
{
    $provider=strtolower(trim($provider));$externalAccountId=mb_strimwidth(trim($externalAccountId),0,190,'');
    if($ownerUserId<1||$externalAccountId==='')throw new RuntimeException('Payment provider account identity is required.');
    if(!isset(agent_paid_appointments_provider_registry_v800()[$provider]))throw new RuntimeException('Unsupported appointment payment provider.');
    $access=trim((string)($data['access_token']??''));$refresh=trim((string)($data['refresh_token']??''));$expires=$data['token_expires_at']??null;
    $stmt=$pdo->prepare('SELECT * FROM agent_appointment_payment_connections_v800 WHERE owner_user_id=? AND provider=? AND external_account_id=? LIMIT 1');$stmt->execute([$ownerUserId,$provider,$externalAccountId]);$existing=$stmt->fetch()?:null;
    $accessCipher=$access!==''?agent_paid_appointments_encrypt_v800($access):(string)($existing['access_token_ciphertext']??'');
    $refreshCipher=$refresh!==''?agent_paid_appointments_encrypt_v800($refresh):(string)($existing['refresh_token_ciphertext']??'');
    $capabilities=json_encode(is_array($data['capabilities']??null)?$data['capabilities']:[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $label=mb_strimwidth(trim((string)($data['account_label']??$existing['account_label']??agent_paid_appointments_provider_label_v800($provider))),0,190,'');
    $email=mb_strimwidth(strtolower(trim((string)($data['account_email']??$existing['account_email']??''))),0,190,'');
    $scopes=mb_strimwidth(trim((string)($data['scopes']??$existing['scopes']??'')),0,4000,'');
    $pdo->prepare("INSERT INTO agent_appointment_payment_connections_v800
      (owner_user_id,provider,external_account_id,account_label,account_email,access_token_ciphertext,refresh_token_ciphertext,token_expires_at,scopes,capabilities_json,status,last_verified_at,last_error)
      VALUES (?,?,?,?,?,?,?,?,?,?,'connected',NOW(),'')
      ON DUPLICATE KEY UPDATE account_label=VALUES(account_label),account_email=VALUES(account_email),access_token_ciphertext=VALUES(access_token_ciphertext),refresh_token_ciphertext=VALUES(refresh_token_ciphertext),token_expires_at=VALUES(token_expires_at),scopes=VALUES(scopes),capabilities_json=VALUES(capabilities_json),status='connected',last_verified_at=NOW(),last_error='',updated_at=NOW()")->execute([$ownerUserId,$provider,$externalAccountId,$label,$email,$accessCipher?:null,$refreshCipher?:null,$expires?:null,$scopes?:null,$capabilities]);
    $stmt->execute([$ownerUserId,$provider,$externalAccountId]);return $stmt->fetch()?:throw new RuntimeException('Payment provider connection could not be saved.');
}

function agent_paid_appointments_set_personal_default_v800(PDO $pdo,int $ownerUserId,int $connectionId): array
{
    $connection=agent_paid_appointments_connection_v800($pdo,$connectionId,$ownerUserId);if(!$connection||$connection['status']!=='connected')throw new RuntimeException('Choose one of your connected payment providers.');
    $pdo->beginTransaction();try{$pdo->prepare('UPDATE agent_appointment_payment_connections_v800 SET is_default_personal=0 WHERE owner_user_id=?')->execute([$ownerUserId]);$pdo->prepare('UPDATE agent_appointment_payment_connections_v800 SET is_default_personal=1 WHERE id=? AND owner_user_id=?')->execute([$connectionId,$ownerUserId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return agent_paid_appointments_connection_v800($pdo,$connectionId,$ownerUserId)?:throw new RuntimeException('Default provider could not be saved.');
}

function agent_paid_appointments_disconnect_v800(PDO $pdo,int $ownerUserId,int $connectionId): void
{
    $connection=agent_paid_appointments_connection_v800($pdo,$connectionId,$ownerUserId);if(!$connection)throw new RuntimeException('Payment provider connection not found.');
    $check=$pdo->prepare("SELECT 1 FROM agent_team_payment_provider_v800 WHERE connection_id=? UNION SELECT 1 FROM agent_paid_event_types_v800 WHERE fixed_connection_id=? UNION SELECT 1 FROM agent_paid_bookings_v800 WHERE connection_id=? AND payment_status IN ('awaiting_payment','paid','partially_refunded') LIMIT 1");$check->execute([$connectionId,$connectionId,$connectionId]);
    if($check->fetchColumn())throw new RuntimeException('This provider is still assigned to a Team, appointment type, or active paid booking. Reassign it before disconnecting.');
    $pdo->prepare("UPDATE agent_appointment_payment_connections_v800 SET status='disconnected',is_default_personal=0,access_token_ciphertext=NULL,refresh_token_ciphertext=NULL,token_expires_at=NULL,updated_at=NOW() WHERE id=? AND owner_user_id=?")->execute([$connectionId,$ownerUserId]);
}

function agent_paid_appointments_team_primary_v800(PDO $pdo,int $workspaceOwnerId): ?array
{
    if($workspaceOwnerId<1)return null;$stmt=$pdo->prepare("SELECT tp.*,c.provider,c.external_account_id,c.account_label,c.account_email,c.status connection_status,c.owner_user_id connection_owner_user_id FROM agent_team_payment_provider_v800 tp INNER JOIN agent_appointment_payment_connections_v800 c ON c.id=tp.connection_id WHERE tp.workspace_owner_user_id=? LIMIT 1");$stmt->execute([$workspaceOwnerId]);return $stmt->fetch()?:null;
}

function agent_paid_appointments_set_team_primary_v800(PDO $pdo,int $workspaceOwnerId,int $connectionId,?array $actor=null): array
{
    $actor??=current_user();$actorId=(int)($actor['id']??0);
    if(!agent_paid_appointments_is_team_super_admin_v800($workspaceOwnerId,$actor))throw new RuntimeException('Only the Team Super Admin can change the Team payment provider.');
    $connection=agent_paid_appointments_connection_v800($pdo,$connectionId,$workspaceOwnerId);
    if(!$connection||$connection['status']!=='connected')throw new RuntimeException('The Team primary provider must be a connected provider owned by the Team Super Admin.');
    $pdo->prepare('INSERT INTO agent_team_payment_provider_v800 (workspace_owner_user_id,connection_id,set_by_user_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE connection_id=VALUES(connection_id),set_by_user_id=VALUES(set_by_user_id),updated_at=NOW()')->execute([$workspaceOwnerId,$connectionId,$actorId]);
    agent_paid_appointments_audit_v800($pdo,null,$workspaceOwnerId,$workspaceOwnerId,'user',$actorId,null,'team_primary_provider_changed','','',0,['connection_id'=>$connectionId,'provider'=>$connection['provider']]);
    return agent_paid_appointments_team_primary_v800($pdo,$workspaceOwnerId)?:throw new RuntimeException('Team primary provider could not be saved.');
}

function agent_paid_appointments_currency_v800(string $currency): string
{
    $currency=strtolower(trim($currency));if(!preg_match('/^[a-z]{3}$/',$currency))throw new RuntimeException('Use a three-letter ISO currency code.');return $currency;
}
function agent_paid_appointments_payment_mode_v800(string $mode): string
{
    $mode=strtolower(trim($mode));if(!in_array($mode,['free','full','deposit'],true))throw new RuntimeException('Choose free, full payment, or deposit.');return $mode;
}
function agent_paid_appointments_validate_terms_v800(array $input): array
{
    $mode=agent_paid_appointments_payment_mode_v800((string)($input['payment_mode']??'free'));$price=max(0,(int)($input['price_cents']??0));$deposit=max(0,(int)($input['deposit_cents']??0));$currency=agent_paid_appointments_currency_v800((string)($input['currency']??'usd'));
    if($mode!=='free'&&$price<1)throw new RuntimeException('Paid appointments require a price greater than zero.');
    if($mode==='deposit'&&($deposit<1||$deposit>$price))throw new RuntimeException('Deposit must be greater than zero and no more than the appointment price.');
    if($mode==='full')$deposit=$price;if($mode==='free'){$price=0;$deposit=0;}
    $hold=max(30,min(120,(int)($input['hold_minutes']??30)));$refundHours=max(0,min(2160,(int)($input['refund_before_hours']??24)));$fee=max(0,(int)($input['cancellation_fee_cents']??0));if($fee>$price)$fee=$price;
    return ['payment_mode'=>$mode,'price_cents'=>$price,'deposit_cents'=>$deposit,'currency'=>$currency,'hold_minutes'=>$hold,'refund_before_hours'=>$refundHours,'cancellation_fee_cents'=>$fee,'cancellation_policy'=>mb_strimwidth(trim((string)($input['cancellation_policy']??'')),0,1000,'')];
}

function agent_paid_appointments_event_terms_v800(PDO $pdo,int $eventTypeId): array
{
    $stmt=$pdo->prepare('SELECT * FROM agent_paid_event_types_v800 WHERE event_type_id=? LIMIT 1');$stmt->execute([$eventTypeId]);$row=$stmt->fetch();return $row?:['event_type_id'=>$eventTypeId,'payment_mode'=>'free','price_cents'=>0,'deposit_cents'=>0,'currency'=>'usd','provider_mode'=>'guest_choice','fixed_connection_id'=>null,'hold_minutes'=>30,'refund_before_hours'=>24,'cancellation_fee_cents'=>0,'cancellation_policy'=>''];
}

function agent_paid_appointments_save_event_terms_v800(PDO $pdo,int $ownerUserId,int $eventTypeId,array $input): array
{
    $event=agent_scheduling_event_type_v430($pdo,$eventTypeId);if(!$event||(int)($event['owner_user_id']??0)!==$ownerUserId)throw new RuntimeException('Appointment type not found.');
    $terms=agent_paid_appointments_validate_terms_v800($input);$providerMode=(string)($input['provider_mode']??'guest_choice');if(!in_array($providerMode,['guest_choice','fixed'],true))$providerMode='guest_choice';$connectionId=max(0,(int)($input['fixed_connection_id']??0))?:null;
    if($terms['payment_mode']!=='free'&&$providerMode==='fixed'){$connection=agent_paid_appointments_connection_v800($pdo,(int)$connectionId,$ownerUserId);if(!$connection||$connection['status']!=='connected')throw new RuntimeException('Choose a connected provider for this appointment type.');}
    else if($providerMode!=='fixed')$connectionId=null;
    if($terms['payment_mode']!=='free'&&$providerMode==='guest_choice'&&!agent_paid_appointments_connections_v800($pdo,$ownerUserId,true))throw new RuntimeException('Connect at least one appointment payment provider first.');
    $pdo->prepare('INSERT INTO agent_paid_event_types_v800 (event_type_id,payment_mode,price_cents,deposit_cents,currency,provider_mode,fixed_connection_id,hold_minutes,refund_before_hours,cancellation_fee_cents,cancellation_policy) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payment_mode=VALUES(payment_mode),price_cents=VALUES(price_cents),deposit_cents=VALUES(deposit_cents),currency=VALUES(currency),provider_mode=VALUES(provider_mode),fixed_connection_id=VALUES(fixed_connection_id),hold_minutes=VALUES(hold_minutes),refund_before_hours=VALUES(refund_before_hours),cancellation_fee_cents=VALUES(cancellation_fee_cents),cancellation_policy=VALUES(cancellation_policy),updated_at=NOW()')->execute([$eventTypeId,$terms['payment_mode'],$terms['price_cents'],$terms['deposit_cents'],$terms['currency'],$providerMode,$connectionId,$terms['hold_minutes'],$terms['refund_before_hours'],$terms['cancellation_fee_cents'],$terms['cancellation_policy']]);
    return agent_paid_appointments_event_terms_v800($pdo,$eventTypeId);
}

function agent_paid_appointments_team_terms_v800(PDO $pdo,int $poolId): array
{
    $stmt=$pdo->prepare('SELECT * FROM agent_paid_team_pools_v800 WHERE pool_id=? LIMIT 1');$stmt->execute([$poolId]);$row=$stmt->fetch();return $row?:['pool_id'=>$poolId,'payment_mode'=>'free','price_cents'=>0,'deposit_cents'=>0,'currency'=>'usd','hold_minutes'=>30,'refund_before_hours'=>24,'cancellation_fee_cents'=>0,'cancellation_policy'=>''];
}

function agent_paid_appointments_save_team_terms_v800(PDO $pdo,int $workspaceOwnerId,int $poolId,array $input,?array $actor=null): array
{
    $actor??=current_user();if(!agent_paid_appointments_is_team_super_admin_v800($workspaceOwnerId,$actor))throw new RuntimeException('Only the Team Super Admin can change Team appointment pricing.');
    $pool=agent_team_scheduling_pool_v600($pdo,$workspaceOwnerId,$poolId);if(!$pool)throw new RuntimeException('Team scheduling pool not found.');$terms=agent_paid_appointments_validate_terms_v800($input);
    if($terms['payment_mode']!=='free'&&!agent_paid_appointments_team_primary_v800($pdo,$workspaceOwnerId))throw new RuntimeException('Select the Team primary payment provider before enabling paid Team appointments.');
    $pdo->prepare('INSERT INTO agent_paid_team_pools_v800 (pool_id,payment_mode,price_cents,deposit_cents,currency,hold_minutes,refund_before_hours,cancellation_fee_cents,cancellation_policy) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payment_mode=VALUES(payment_mode),price_cents=VALUES(price_cents),deposit_cents=VALUES(deposit_cents),currency=VALUES(currency),hold_minutes=VALUES(hold_minutes),refund_before_hours=VALUES(refund_before_hours),cancellation_fee_cents=VALUES(cancellation_fee_cents),cancellation_policy=VALUES(cancellation_policy),updated_at=NOW()')->execute([$poolId,$terms['payment_mode'],$terms['price_cents'],$terms['deposit_cents'],$terms['currency'],$terms['hold_minutes'],$terms['refund_before_hours'],$terms['cancellation_fee_cents'],$terms['cancellation_policy']]);
    return agent_paid_appointments_team_terms_v800($pdo,$poolId);
}

function agent_paid_appointments_amount_due_v800(array $terms): int
{
    return match((string)($terms['payment_mode']??'free')){'full'=>(int)($terms['price_cents']??0),'deposit'=>(int)($terms['deposit_cents']??0),default=>0};
}

function agent_paid_appointments_personal_connections_for_terms_v800(PDO $pdo,int $ownerUserId,array $terms): array
{
    if((string)($terms['payment_mode']??'free')==='free')return [];
    if((string)($terms['provider_mode']??'guest_choice')==='fixed'){$c=agent_paid_appointments_connection_v800($pdo,(int)($terms['fixed_connection_id']??0),$ownerUserId);return $c&&$c['status']==='connected'?[$c]:[];}
    return agent_paid_appointments_connections_v800($pdo,$ownerUserId,true);
}

function agent_paid_appointments_audit_v800(PDO $pdo,?int $paidBookingId,int $ownerUserId,?int $workspaceOwnerId,string $actorType,?int $actorUserId,?int $actorAgentId,string $eventType,string $fromStatus='',string $toStatus='',int $amountCents=0,array $metadata=[]): void
{
    if($ownerUserId<1)return;$pdo->prepare('INSERT INTO agent_paid_audit_v800 (paid_booking_id,owner_user_id,workspace_owner_user_id,actor_type,actor_user_id,actor_agent_id,event_type,from_status,to_status,amount_cents,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$paidBookingId,$ownerUserId,$workspaceOwnerId,$actorType,$actorUserId,$actorAgentId,$eventType,$fromStatus,$toStatus,max(0,$amountCents),$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}
