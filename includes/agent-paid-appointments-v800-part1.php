<?php
declare(strict_types=1);

function agent_paid_appointments_schema_ready_v800(?PDO $pdo=null): bool
{
    $pdo??=db();return (bool)($pdo
        &&function_exists('agent_commerce_schema_ready_v800')&&agent_commerce_schema_ready_v800($pdo)
        &&function_exists('agent_scheduling_schema_ready_v430')&&agent_scheduling_schema_ready_v430($pdo)
        &&function_exists('agent_team_scheduling_schema_ready_v600')&&agent_team_scheduling_schema_ready_v600($pdo)
        &&function_exists('agent_appointment_lifecycle_schema_ready_v700')&&agent_appointment_lifecycle_schema_ready_v700($pdo));
}
function agent_paid_appointments_ensure_schema_v800(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');if(!agent_scheduling_schema_ready_v430($pdo))throw new RuntimeException('Install Agent Scheduling before Appointment Commerce.');if(!agent_team_scheduling_schema_ready_v600($pdo))throw new RuntimeException('Install Team Scheduling before Appointment Commerce.');if(!agent_appointment_lifecycle_schema_ready_v700($pdo))throw new RuntimeException('Install Appointment Lifecycle before Appointment Commerce.');agent_commerce_ensure_schema_v800($pdo);
}

function agent_paid_appointments_config_v800(): array{return agent_commerce_config_v800();}
function agent_paid_appointments_provider_registry_v800(): array{return agent_commerce_provider_registry_v800();}
function agent_paid_appointments_provider_label_v800(string $provider): string{return agent_commerce_provider_label_v800($provider);}
function agent_paid_appointments_provider_ready_v800(string $provider): bool{return agent_commerce_provider_ready_v800($provider);}
function agent_paid_appointments_encrypt_v800(string $plain): string{return agent_commerce_encrypt_v800($plain);}
function agent_paid_appointments_decrypt_v800(string $ciphertext): string{return agent_commerce_decrypt_v800($ciphertext);}
function agent_paid_appointments_absolute_url_v800(string $path): string{return agent_commerce_absolute_url_v800($path);}
function agent_paid_appointments_money_v800(int $cents,string $currency='usd'): string{return agent_commerce_money_v800($cents,$currency);}
function agent_paid_appointments_decimal_to_minor_v800(string $value): int{return agent_commerce_decimal_to_minor_v800($value);}
function agent_paid_appointments_http_v800(string $method,string $url,array $headers=[],mixed $body=null,bool $form=false): array{return agent_commerce_http_v800($method,$url,$headers,$body,$form);}
function agent_paid_appointments_is_team_super_admin_v800(int $workspaceOwnerId,?array $actor=null): bool{return agent_commerce_is_team_super_admin_v800($workspaceOwnerId,$actor);}
function agent_paid_appointments_connection_v800(PDO $pdo,int $connectionId,int $ownerUserId=0): ?array{return agent_commerce_connection_v800($pdo,$connectionId,$ownerUserId);}
function agent_paid_appointments_connections_v800(PDO $pdo,int $ownerUserId,bool $connectedOnly=false): array{return agent_commerce_connections_v800($pdo,$ownerUserId,$connectedOnly);}
function agent_paid_appointments_store_connection_v800(PDO $pdo,int $ownerUserId,string $provider,string $externalAccountId,array $data=[]): array{return agent_commerce_store_connection_v800($pdo,$ownerUserId,$provider,$externalAccountId,$data);}
function agent_paid_appointments_set_personal_default_v800(PDO $pdo,int $ownerUserId,int $connectionId): array{return agent_commerce_set_personal_default_v800($pdo,$ownerUserId,$connectionId);}
function agent_paid_appointments_disconnect_v800(PDO $pdo,int $ownerUserId,int $connectionId): void{agent_commerce_disconnect_v800($pdo,$ownerUserId,$connectionId);}
function agent_paid_appointments_team_primary_v800(PDO $pdo,int $workspaceOwnerId): ?array{return agent_commerce_team_primary_v800($pdo,$workspaceOwnerId);}
function agent_paid_appointments_set_team_primary_v800(PDO $pdo,int $workspaceOwnerId,int $connectionId,?array $actor=null): array{return agent_commerce_set_team_primary_v800($pdo,$workspaceOwnerId,$connectionId,$actor);}
function agent_paid_appointments_currency_v800(string $currency): string{return agent_commerce_currency_v800($currency);}
function agent_paid_appointments_payment_mode_v800(string $mode): string{return agent_commerce_payment_mode_v800($mode);}
function agent_paid_appointments_validate_terms_v800(array $input): array{return agent_commerce_validate_terms_v800($input);}
function agent_paid_appointments_amount_due_v800(array $terms): int{return agent_commerce_amount_due_v800($terms);}
function agent_paid_appointments_audit_v800(PDO $pdo,?int $paidBookingId,int $ownerUserId,?int $workspaceOwnerId,string $actorType,?int $actorUserId,?int $actorAgentId,string $eventType,string $fromStatus='',string $toStatus='',int $amountCents=0,array $metadata=[]): void{agent_commerce_audit_v800($pdo,$paidBookingId,$ownerUserId,$workspaceOwnerId,$actorType,$actorUserId,$actorAgentId,$eventType,$fromStatus,$toStatus,$amountCents,$metadata);}

function agent_paid_appointments_event_terms_v800(PDO $pdo,int $eventTypeId): array
{
    $product=agent_commerce_product_by_binding_v800($pdo,'appointment_event_type',$eventTypeId);if(!$product)return ['event_type_id'=>$eventTypeId,'product_id'=>0,'payment_mode'=>'free','price_cents'=>0,'deposit_cents'=>0,'currency'=>'usd','provider_mode'=>'guest_choice','fixed_connection_id'=>null,'hold_minutes'=>30,'refund_before_hours'=>24,'cancellation_fee_cents'=>0,'cancellation_policy'=>''];$terms=agent_commerce_product_terms_v800($product);$terms['event_type_id']=$eventTypeId;return $terms;
}
function agent_paid_appointments_save_event_terms_v800(PDO $pdo,int $ownerUserId,int $eventTypeId,array $input): array
{
    $event=agent_scheduling_event_type_v430($pdo,$eventTypeId);if(!$event||(int)($event['owner_user_id']??0)!==$ownerUserId)throw new RuntimeException('Appointment type not found.');$terms=agent_commerce_validate_terms_v800($input);$providerMode=(string)($input['provider_mode']??'guest_choice');if(!in_array($providerMode,['guest_choice','fixed'],true))$providerMode='guest_choice';$connectionId=max(0,(int)($input['fixed_connection_id']??0))?:null;if($terms['payment_mode']!=='free'&&$providerMode==='fixed'){$connection=agent_commerce_connection_v800($pdo,(int)$connectionId,$ownerUserId);if(!$connection||$connection['status']!=='connected')throw new RuntimeException('Choose a connected provider for this appointment product.');}elseif($providerMode!=='fixed')$connectionId=null;if($terms['payment_mode']!=='free'&&$providerMode==='guest_choice'&&!agent_commerce_connections_v800($pdo,$ownerUserId,true))throw new RuntimeException('Connect at least one commerce payment provider first.');$product=agent_commerce_upsert_bound_product_v800($pdo,$ownerUserId,null,'appointment:event:'.$eventTypeId,'appointment_event_type',$eventTypeId,array_merge($terms,['title'=>(string)$event['title'],'description'=>'Scheduled appointment product','product_type'=>'service','fulfillment_type'=>'appointment','provider_mode'=>$providerMode,'fixed_connection_id'=>$connectionId,'metadata'=>['appointment_event_type_id'=>$eventTypeId]]));$out=agent_commerce_product_terms_v800($product);$out['event_type_id']=$eventTypeId;return $out;
}
function agent_paid_appointments_team_terms_v800(PDO $pdo,int $poolId): array
{
    $product=agent_commerce_product_by_binding_v800($pdo,'team_scheduling_pool',$poolId);if(!$product)return ['pool_id'=>$poolId,'product_id'=>0,'payment_mode'=>'free','price_cents'=>0,'deposit_cents'=>0,'currency'=>'usd','provider_mode'=>'team_primary','fixed_connection_id'=>null,'hold_minutes'=>30,'refund_before_hours'=>24,'cancellation_fee_cents'=>0,'cancellation_policy'=>''];$terms=agent_commerce_product_terms_v800($product);$terms['pool_id']=$poolId;return $terms;
}
function agent_paid_appointments_save_team_terms_v800(PDO $pdo,int $workspaceOwnerId,int $poolId,array $input,?array $actor=null): array
{
    $actor??=current_user();if(!agent_commerce_is_team_super_admin_v800($workspaceOwnerId,$actor))throw new RuntimeException('Only the Team Super Admin can change Team appointment product pricing.');$pool=agent_team_scheduling_pool_v600($pdo,$workspaceOwnerId,$poolId);if(!$pool)throw new RuntimeException('Team scheduling pool not found.');$terms=agent_commerce_validate_terms_v800($input);$primary=agent_commerce_team_primary_v800($pdo,$workspaceOwnerId);if($terms['payment_mode']!=='free'&&!$primary)throw new RuntimeException('Select the Team primary payment provider before enabling paid Team appointment products.');$product=agent_commerce_upsert_bound_product_v800($pdo,$workspaceOwnerId,$workspaceOwnerId,'appointment:team_pool:'.$poolId,'team_scheduling_pool',$poolId,array_merge($terms,['title'=>(string)$pool['name'],'description'=>'Team scheduled appointment product','product_type'=>'service','fulfillment_type'=>'appointment','provider_mode'=>'team_primary','fixed_connection_id'=>null,'metadata'=>['team_scheduling_pool_id'=>$poolId]]));$out=agent_commerce_product_terms_v800($product);$out['pool_id']=$poolId;return $out;
}
function agent_paid_appointments_personal_connections_for_terms_v800(PDO $pdo,int $ownerUserId,array $terms): array
{
    if((string)($terms['payment_mode']??'free')==='free')return [];if((string)($terms['provider_mode']??'guest_choice')==='fixed'){$c=agent_commerce_connection_v800($pdo,(int)($terms['fixed_connection_id']??0),$ownerUserId);return $c&&$c['status']==='connected'?[$c]:[];}return agent_commerce_connections_v800($pdo,$ownerUserId,true);
}

function agent_paid_appointments_order_adapter_v800(array $order): array
{
    $row=$order;$row['booking_id']=((string)($order['fulfillment_ref_type']??'')==='appointment_booking')?(int)($order['fulfillment_ref_id']??0):0;$row['team_booking_id']=((string)($order['fulfillment_group_type']??'')==='team_booking')?(int)($order['fulfillment_group_id']??0):0;$row['amount_total_cents']=(int)($order['total_cents']??0);return $row;
}
function agent_paid_appointments_paid_booking_v800(PDO $pdo,int $paidBookingId): ?array
{
    $order=agent_commerce_order_v800($pdo,$paidBookingId);return $order&&$order['fulfillment_type']==='appointment'?agent_paid_appointments_order_adapter_v800($order):null;
}
function agent_paid_appointments_paid_booking_for_booking_v800(PDO $pdo,int $bookingId): ?array
{
    $order=agent_commerce_order_by_fulfillment_v800($pdo,'appointment_booking',$bookingId);return $order&&$order['fulfillment_type']==='appointment'?agent_paid_appointments_order_adapter_v800($order):null;
}
function agent_paid_appointments_paid_booking_for_team_v800(PDO $pdo,int $teamBookingId): ?array
{
    $order=agent_commerce_order_by_group_v800($pdo,'team_booking',$teamBookingId);return $order&&$order['fulfillment_type']==='appointment'?agent_paid_appointments_order_adapter_v800($order):null;
}
function agent_paid_appointments_checkout_attempt_v800(PDO $pdo,int $attemptId): ?array
{
    $row=agent_commerce_checkout_attempt_v800($pdo,$attemptId);if($row)$row['paid_booking_id']=(int)$row['order_id'];return $row;
}
function agent_paid_appointments_attempt_by_external_v800(PDO $pdo,string $provider,string $externalId): ?array
{
    $row=agent_commerce_attempt_by_external_v800($pdo,$provider,$externalId);if($row)$row['paid_booking_id']=(int)$row['order_id'];return $row;
}
function agent_paid_appointments_mark_canonical_pending_v800(PDO $pdo,int $bookingId): void
{
    $booking=agent_appointment_lifecycle_booking_v700($pdo,$bookingId);if(!$booking)return;$from=agent_appointment_lifecycle_status_v700($booking);if($from==='pending')return;if(!in_array($from,['confirmed','rescheduled'],true))throw new RuntimeException('This appointment cannot enter payment hold from its current lifecycle state.');$stmt=$pdo->prepare("UPDATE agent_scheduling_bookings SET lifecycle_status='pending',last_lifecycle_event_at=NOW() WHERE id=? AND lifecycle_status=?");$stmt->execute([$bookingId,$from]);if($stmt->rowCount()!==1)throw new RuntimeException('Appointment changed while the payment hold was being created.');$fresh=agent_appointment_lifecycle_booking_v700($pdo,$bookingId)?:$booking;agent_appointment_lifecycle_event_v700($pdo,$fresh,'payment_required',$from,'pending','system',null,null,['source'=>'commerce_v800']);
}
function agent_paid_appointments_create_personal_v800(PDO $pdo,array $booking): ?array
{
    $bookingId=(int)($booking['id']??0);$eventTypeId=(int)($booking['event_type_id']??0);$ownerId=(int)($booking['owner_user_id']??0);
    if($bookingId<1||$eventTypeId<1||$ownerId<1)return null;
    $existing=agent_paid_appointments_paid_booking_for_booking_v800($pdo,$bookingId);if($existing)return $existing;
    $product=agent_commerce_product_by_binding_v800($pdo,'appointment_event_type',$eventTypeId);if(!$product||agent_commerce_amount_due_v800($product)<1)return null;
    $connections=agent_paid_appointments_personal_connections_for_terms_v800($pdo,$ownerId,agent_commerce_product_terms_v800($product));if(!$connections)throw new RuntimeException('This paid appointment product has no connected payment provider.');
    $connection=(string)$product['provider_mode']==='fixed'?$connections[0]:null;
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $order=agent_commerce_create_order_v800($pdo,$product,['connection_id'=>$connection['id']??null,'payer_email'=>(string)($booking['guest_email']??''),'fulfillment_ref_type'=>'appointment_booking','fulfillment_ref_id'=>$bookingId,'metadata'=>['appointment_event_type_id'=>$eventTypeId]]);
        agent_paid_appointments_mark_canonical_pending_v800($pdo,$bookingId);
        agent_commerce_audit_v800($pdo,(int)$order['id'],$ownerId,null,'system',null,null,'appointment_payment_hold_created','confirmed','awaiting_payment',(int)$order['amount_due_cents'],['booking_id'=>$bookingId]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return agent_paid_appointments_order_adapter_v800($order);
}
function agent_paid_appointments_create_team_v800(PDO $pdo,array $teamBooking): ?array
{
    $teamBookingId=(int)($teamBooking['id']??0);$poolId=(int)($teamBooking['pool_id']??0);$workspaceOwnerId=(int)($teamBooking['workspace_owner_user_id']??0);
    if($teamBookingId<1||$poolId<1||$workspaceOwnerId<1)return null;
    $existing=agent_paid_appointments_paid_booking_for_team_v800($pdo,$teamBookingId);if($existing)return $existing;
    $product=agent_commerce_product_by_binding_v800($pdo,'team_scheduling_pool',$poolId);if(!$product||agent_commerce_amount_due_v800($product)<1)return null;
    $primary=agent_commerce_team_primary_v800($pdo,$workspaceOwnerId);if(!$primary||$primary['connection_status']!=='connected')throw new RuntimeException('This Team does not currently have an active primary payment provider.');
    $stmt=$pdo->prepare('SELECT canonical_booking_id,user_id FROM agent_team_scheduling_booking_members WHERE team_booking_id=? ORDER BY id');$stmt->execute([$teamBookingId]);$members=$stmt->fetchAll()?:[];if(!$members)throw new RuntimeException('Team booking participants are unavailable.');
    $bookingId=(int)$members[0]['canonical_booking_id'];$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $order=agent_commerce_create_order_v800($pdo,$product,['connection_id'=>(int)$primary['connection_id'],'payer_email'=>(string)($teamBooking['guest_email']??''),'fulfillment_ref_type'=>'appointment_booking','fulfillment_ref_id'=>$bookingId,'fulfillment_group_type'=>'team_booking','fulfillment_group_id'=>$teamBookingId,'metadata'=>['team_scheduling_pool_id'=>$poolId,'participant_booking_ids'=>array_map(static fn(array $m):int=>(int)$m['canonical_booking_id'],$members)]]);
        $teamUpdate=$pdo->prepare("UPDATE agent_team_scheduling_bookings SET status='pending',updated_at=NOW() WHERE id=? AND status='confirmed'");$teamUpdate->execute([$teamBookingId]);if($teamUpdate->rowCount()!==1)throw new RuntimeException('Team booking changed while the payment hold was being created.');
        foreach($members as $member)agent_paid_appointments_mark_canonical_pending_v800($pdo,(int)$member['canonical_booking_id']);
        agent_commerce_audit_v800($pdo,(int)$order['id'],$workspaceOwnerId,$workspaceOwnerId,'system',null,null,'team_appointment_payment_hold_created','confirmed','awaiting_payment',(int)$order['amount_due_cents'],['team_booking_id'=>$teamBookingId,'connection_id'=>(int)$primary['connection_id']]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return agent_paid_appointments_order_adapter_v800($order);
}
function agent_paid_appointments_available_connections_v800(PDO $pdo,array $paid): array{return agent_commerce_available_connections_v800($pdo,$paid);}
function agent_paid_appointments_oauth_state_v800(array $user,string $provider): string{return agent_commerce_oauth_state_v800($user,$provider);}
function agent_paid_appointments_oauth_state_take_v800(array $user,string $state): array{return agent_commerce_oauth_state_take_v800($user,$state);}
function agent_paid_appointments_oauth_callback_v800(): string{return agent_commerce_oauth_callback_v800();}
function agent_paid_appointments_oauth_url_v800(array $user,string $provider): string{return agent_commerce_oauth_url_v800($user,$provider);}
function agent_paid_appointments_square_base_v800(): string{return agent_commerce_square_base_v800();}
function agent_paid_appointments_capabilities_v800(array $connection): array{return agent_commerce_capabilities_v800($connection);}
function agent_paid_appointments_square_profile_v800(string $accessToken): array{return agent_commerce_square_profile_v800($accessToken);}
function agent_paid_appointments_oauth_exchange_v800(PDO $pdo,array $user,string $provider,string $code): array{return agent_commerce_oauth_exchange_v800($pdo,$user,$provider,$code);}
function agent_paid_appointments_square_access_token_v800(PDO $pdo,array $connection): string{return agent_commerce_square_access_token_v800($pdo,$connection);}
function agent_paid_appointments_paypal_base_v800(): string{return agent_commerce_paypal_base_v800();}
function agent_paid_appointments_paypal_platform_token_v800(): string{return agent_commerce_paypal_platform_token_v800();}
function agent_paid_appointments_paypal_assertion_v800(string $merchantId): string{return agent_commerce_paypal_assertion_v800($merchantId);}
function agent_paid_appointments_attach_paypal_v800(PDO $pdo,int $ownerUserId,string $merchantId): array{return agent_commerce_attach_paypal_v800($pdo,$ownerUserId,$merchantId);}
function agent_paid_appointments_create_checkout_v800(PDO $pdo,array $paid,int $connectionId): array
{
    $manage=agent_paid_appointments_manage_token_v800($pdo,$paid);if(!preg_match('/^[a-f0-9]{64}$/',$manage))throw new RuntimeException('Private appointment payment state is unavailable.');$provider='';foreach(agent_commerce_available_connections_v800($pdo,$paid) as $c)if((int)$c['id']===$connectionId){$provider=(string)$c['provider'];break;}if($provider==='')throw new RuntimeException('Choose an available payment provider.');$return=agent_commerce_absolute_url_v800('/appointment-payment-return.php?paid='.(int)$paid['id'].'&provider='.rawurlencode($provider).'&manage='.rawurlencode($manage));$cancel=agent_commerce_absolute_url_v800('/appointment-payment.php?manage='.rawurlencode($manage).'&cancelled=1');$attempt=agent_commerce_create_checkout_v800($pdo,$paid,$connectionId,$return,$cancel);$attempt['paid_booking_id']=(int)$attempt['order_id'];return $attempt;
}
