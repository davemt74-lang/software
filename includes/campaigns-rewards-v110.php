<?php
declare(strict_types=1);

/**
 * Campaigns & Rewards V1.10 — Rewards Workspace + Reward Tray.
 *
 * Reward Issuance remains the certificate authority. The tray is a projection;
 * SEND changes the canonical holder transactionally and records reward_transfers.
 * CLAIM delegates to the existing online three-factor claim engine.
 */
const VP3_CAMPAIGNS_REWARDS_V110='vp3-campaigns-rewards-v110-20260923';

function campaigns_rewards_v110_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && function_exists('campaigns_rewards_platform_schema_ready_v100')
        && campaigns_rewards_platform_schema_ready_v100($pdo)
        && table_exists('reward_transfers');
}

function campaigns_rewards_reward_products_v110(PDO $pdo,int $merchantId,int $actorUserId): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'merchant.view');
    $stmt=$pdo->prepare("SELECT rp.*,rt.type_key reward_type_key,rt.name reward_type_name,
      COALESCE((SELECT SUM(b.on_hand) FROM reward_inventory_balances b WHERE b.reward_product_id=rp.id),0) inventory_on_hand,
      COALESCE((SELECT SUM(b.reserved) FROM reward_inventory_balances b WHERE b.reward_product_id=rp.id),0) inventory_reserved,
      (SELECT COUNT(DISTINCT rs.campaign_id) FROM campaign_reward_set_items i INNER JOIN campaign_reward_sets rs ON rs.id=i.reward_set_id WHERE i.reward_product_id=rp.id) campaign_count
      FROM reward_products rp INNER JOIN reward_types rt ON rt.id=rp.reward_type_id
      WHERE rp.merchant_id=? ORDER BY rp.is_active DESC,rp.updated_at DESC,rp.id DESC");
    $stmt->execute([$merchantId]);return $stmt->fetchAll()?:[];
}

function campaigns_rewards_reward_campaign_ids_v110(PDO $pdo,int $rewardProductId): array
{
    $stmt=$pdo->prepare("SELECT DISTINCT rs.campaign_id FROM campaign_reward_set_items i
      INNER JOIN campaign_reward_sets rs ON rs.id=i.reward_set_id WHERE i.reward_product_id=? ORDER BY rs.campaign_id");
    $stmt->execute([$rewardProductId]);
    return array_values(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]));
}

function campaigns_rewards_sync_reward_campaigns_v110(PDO $pdo,int $merchantId,int $rewardProductId,int $actorUserId,array $campaignIds): void
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'rewards.manage');
    $selected=array_values(array_unique(array_filter(array_map('intval',$campaignIds),static fn(int $id):bool=>$id>0)));
    $existing=campaigns_rewards_reward_campaign_ids_v110($pdo,$rewardProductId);
    $all=array_values(array_unique(array_merge($existing,$selected)));
    foreach($all as $campaignId){
        $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId,true);
        if(!$campaign||(int)$campaign['merchant_id']!==$merchantId)throw new RuntimeException('Reward Campaign attachment is outside this Merchant.');
        campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.edit');
        $wasActive=(string)$campaign['status']==='active';
        if($wasActive)campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.publish');
        $want=in_array($campaignId,$selected,true);
        $has=in_array($campaignId,$existing,true);
        if($want&&!$has){
            campaigns_rewards_attach_reward_v100($pdo,$campaignId,$rewardProductId,$actorUserId,'fixed',1);
            if($wasActive)campaigns_rewards_snapshot_campaign_v100($pdo,$campaignId,$actorUserId,'published');
        }elseif(!$want&&$has){
            $pdo->prepare("DELETE i FROM campaign_reward_set_items i INNER JOIN campaign_reward_sets rs ON rs.id=i.reward_set_id WHERE rs.campaign_id=? AND i.reward_product_id=?")
                ->execute([$campaignId,$rewardProductId]);
            if($wasActive)campaigns_rewards_snapshot_campaign_v100($pdo,$campaignId,$actorUserId,'published');
        }
    }
}

function campaigns_rewards_set_reward_inventory_v110(PDO $pdo,int $rewardProductId,int $actorUserId,int $targetOnHand): void
{
    $reward=campaigns_rewards_reward_product_v100($pdo,$rewardProductId)?:throw new RuntimeException('Reward Product not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$reward['merchant_id'],$actorUserId,'rewards.manage');
    $targetOnHand=max(0,$targetOnHand);
    $q=$pdo->prepare("SELECT id,on_hand,reserved FROM reward_inventory_balances WHERE reward_product_id=? AND variant_id=0 AND location_id=0 LIMIT 1 FOR UPDATE");
    $q->execute([$rewardProductId]);$balance=$q->fetch();$old=$balance?(int)$balance['on_hand']:0;$reserved=$balance?(int)$balance['reserved']:0;
    if($targetOnHand<$reserved)throw new RuntimeException('Inventory cannot be reduced below quantity already reserved by issued Rewards.');
    if($balance){
        $pdo->prepare("UPDATE reward_inventory_balances SET on_hand=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$targetOnHand,(int)$balance['id']]);
    }else{
        $pdo->prepare("INSERT INTO reward_inventory_balances (reward_product_id,variant_id,location_id,on_hand,reserved) VALUES (?,0,0,?,0)")
            ->execute([$rewardProductId,$targetOnHand]);
    }
    $delta=$targetOnHand-$old;
    if($delta!==0)$pdo->prepare("INSERT INTO reward_inventory_ledger
      (reward_product_id,variant_id,location_id,movement_type,quantity_delta,on_hand_delta,reserved_delta,source_type,source_id,actor_user_id,metadata_json)
      VALUES (?,0,0,'adjust',?,?,0,'reward_workspace',?,?,?)")
      ->execute([$rewardProductId,$delta,$delta,(string)$rewardProductId,$actorUserId,campaigns_rewards_json_v100(['target_on_hand'=>$targetOnHand])]);
}

function campaigns_rewards_save_reward_workspace_v110(PDO $pdo,int $merchantId,int $actorUserId,array $input,int $rewardProductId=0): array
{
    $tracked=(string)($input['inventory_mode']??'none')==='tracked';
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $reward=campaigns_rewards_save_reward_product_v100($pdo,$merchantId,$actorUserId,[
            'name'=>$input['name']??'',
            'description'=>$input['description']??'',
            'sku'=>$input['sku']??'',
            'image_ref'=>$input['image_ref']??'',
            'reward_type'=>$input['reward_type']??'free_product',
            'retail_value_minor'=>isset($input['retail_value_minor'])&&$input['retail_value_minor']!==''?(int)$input['retail_value_minor']:null,
            'internal_cost_minor'=>isset($input['internal_cost_minor'])&&$input['internal_cost_minor']!==''?(int)$input['internal_cost_minor']:null,
            'currency'=>$input['currency']??'USD',
            'inventory_mode'=>$tracked?'tracked':'none',
            'fulfillment_type'=>$input['fulfillment_type']??'merchant',
            'pickup_enabled'=>!empty($input['pickup_enabled']),
            'shipping_enabled'=>!empty($input['shipping_enabled']),
            'digital_enabled'=>!empty($input['digital_enabled']),
            'expiration_policy'=>$input['expiration_policy']??'campaign',
            'expiration_days'=>isset($input['expiration_days'])&&$input['expiration_days']!==''?(int)$input['expiration_days']:null,
            'claim_limit'=>max(1,(int)($input['claim_limit']??1)),
            'transferable'=>!empty($input['transferable']),
            'regiftable'=>!empty($input['regiftable']),
            'terms'=>$input['terms']??'',
            'settings'=>[
                'value_label'=>campaigns_rewards_text_v100($input['value_label']??'',120),
            ],
            'is_active'=>!isset($input['is_active'])||!empty($input['is_active']),
        ],$rewardProductId);
        if($tracked)campaigns_rewards_set_reward_inventory_v110($pdo,(int)$reward['id'],$actorUserId,max(0,(int)($input['inventory_on_hand']??0)));
        campaigns_rewards_sync_reward_campaigns_v110($pdo,$merchantId,(int)$reward['id'],$actorUserId,(array)($input['campaign_ids']??[]));
        if($owns)$pdo->commit();
        return campaigns_rewards_reward_product_v100($pdo,(int)$reward['id'])?:$reward;
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function campaigns_rewards_reward_holder_v110(PDO $pdo,int $issuanceId,int $userId,bool $forUpdate=false): array
{
    if($issuanceId<1||$userId<1)throw new RuntimeException('Reward certificate is unavailable.');
    $sql="SELECT ri.*,cc.name recipient_name,cc.email recipient_email,
      rp.public_id reward_product_public_id,rp.name reward_name,
      c.public_id campaign_public_id,c.name campaign_name,c.status campaign_status,
      m.public_id merchant_public_id,m.name merchant_name,m.status merchant_status,
      EXISTS (SELECT 1 FROM reward_transfers t WHERE t.reward_issuance_id=ri.id) has_prior_transfer
      FROM reward_issuances ri
      INNER JOIN crm_contacts cc ON cc.id=ri.recipient_contact_id
      INNER JOIN reward_products rp ON rp.id=ri.reward_product_id
      INNER JOIN campaigns c ON c.id=ri.campaign_id
      INNER JOIN merchant_accounts m ON m.id=ri.merchant_id
      WHERE ri.id=? AND ri.recipient_user_id=?
        AND EXISTS (SELECT 1 FROM users u WHERE u.id=ri.recipient_user_id AND u.is_active=1)
      LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$issuanceId,$userId]);
    return $stmt->fetch()?:throw new RuntimeException('Reward certificate is not in your Inbox.');
}

function campaigns_rewards_transfer_allowed_v110(array $issuance): bool
{
    $terms=json_decode((string)($issuance['terms_snapshot_json']??''),true);
    $policy=empty($issuance['has_prior_transfer'])?'transferable':'regiftable';
    return is_array($terms)&&!empty($terms[$policy]);
}

function campaigns_rewards_tray_row_v110(array $row,string $bucket): array
{
    $terms=json_decode((string)($row['terms_snapshot_json']??''),true);if(!is_array($terms))$terms=[];
    $claimMetadata=json_decode((string)($row['claim_metadata_json']??''),true);
    return [
        'id'=>(int)($row['id']??$row['reward_issuance_id']??0),
        'public_id'=>(string)($row['public_id']??$row['issuance_public_id']??''),
        'bucket'=>$bucket,
        'status'=>(string)($row['status']??'issued'),
        'reward_name'=>(string)($row['reward_name']??'Reward'),
        'merchant_name'=>(string)($row['merchant_name']??''),
        'campaign_name'=>(string)($row['campaign_name']??''),
        'value_label'=>(string)($terms['value_label']??''),
        'quantity'=>(int)($row['remaining_quantity']??$row['quantity']??1),
        'currency'=>(string)($row['currency']??'USD'),
        'face_value_minor'=>$row['face_value_minor']===null?null:(int)$row['face_value_minor'],
        'transferable'=>$bucket==='inbox'&&campaigns_rewards_transfer_allowed_v110($row),
        'expires_at'=>(string)($row['expires_at']??''),
        'issued_at'=>(string)($row['issued_at']??''),
        'sent_at'=>(string)($row['transferred_at']??$row['sent_at']??''),
        'claimed_at'=>(string)($row['claimed_at']??''),
        'claim_public_id'=>(string)($row['claim_public_id']??''),
        'claim_location_name'=>(string)($claimMetadata['location_name']??$row['claim_location_name']??''),
        'claim_location_timezone'=>(string)($claimMetadata['location_timezone']??$row['claim_location_timezone']??''),
        'recipient_name'=>(string)($row['target_name']??$row['recipient_name']??''),
        'recipient_email'=>(string)($row['target_email']??$row['recipient_email']??''),
        'delivery_mode'=>(string)($row['delivery_mode']??''),
    ];
}

function campaigns_rewards_reward_tray_v110(PDO $pdo,int $userId): array
{
    if($userId<1||!campaigns_rewards_v110_schema_ready($pdo))return ['inbox'=>[],'sent'=>[],'claimed'=>[],'counts'=>['inbox'=>0,'sent'=>0,'claimed'=>0]];
    $inboxStmt=$pdo->prepare("SELECT ri.*,rp.name reward_name,
      c.name campaign_name,m.name merchant_name,cc.name recipient_name,cc.email recipient_email,
      EXISTS(SELECT 1 FROM reward_transfers t WHERE t.reward_issuance_id=ri.id) has_prior_transfer
      FROM reward_issuances ri
      INNER JOIN users u ON u.id=ri.recipient_user_id AND u.is_active=1
      INNER JOIN reward_products rp ON rp.id=ri.reward_product_id
      INNER JOIN campaigns c ON c.id=ri.campaign_id
      INNER JOIN merchant_accounts m ON m.id=ri.merchant_id
      INNER JOIN crm_contacts cc ON cc.id=ri.recipient_contact_id
      WHERE ri.recipient_user_id=? AND ri.status IN ('issued','sent','viewed') AND ri.remaining_quantity>0
      AND (ri.expires_at IS NULL OR ri.expires_at>UTC_TIMESTAMP()) ORDER BY ri.issued_at DESC,ri.id DESC");
    $inboxStmt->execute([$userId]);$inbox=[];
    foreach($inboxStmt->fetchAll()?:[] as $row)$inbox[]=campaigns_rewards_tray_row_v110($row,'inbox');

    $claimedStmt=$pdo->prepare("SELECT ri.id,ri.public_id,rc.status,rc.quantity,
      rc.value_minor face_value_minor,rc.currency,ri.terms_snapshot_json,ri.issued_at,rc.claimed_at,
      rp.name reward_name,c.name campaign_name,m.name merchant_name,rc.public_id claim_public_id,
      rc.metadata_json claim_metadata_json,ml.name claim_location_name,ml.timezone claim_location_timezone
      FROM reward_claims rc
      INNER JOIN users u ON u.id=rc.holder_user_id AND u.is_active=1
      INNER JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id
      INNER JOIN reward_products rp ON rp.id=ri.reward_product_id
      INNER JOIN campaigns c ON c.id=rc.campaign_id
      INNER JOIN merchant_accounts m ON m.id=rc.merchant_id
      LEFT JOIN merchant_locations ml ON ml.id=rc.location_id AND ml.merchant_id=rc.merchant_id
      WHERE rc.holder_user_id=? AND rc.status='claimed' ORDER BY rc.claimed_at DESC,rc.id DESC");
    $claimedStmt->execute([$userId]);$claimed=[];
    foreach($claimedStmt->fetchAll()?:[] as $row)$claimed[]=campaigns_rewards_tray_row_v110($row,'claimed');

    $sentStmt=$pdo->prepare("SELECT t.*,ri.public_id issuance_public_id,ri.quantity,ri.face_value_minor,ri.currency,ri.terms_snapshot_json,ri.expires_at,ri.issued_at,
      rp.name reward_name,
      c.name campaign_name,m.name merchant_name,target.name target_name,target.email target_email
      FROM reward_transfers t
      INNER JOIN users u ON u.id=t.sent_by_user_id AND u.is_active=1
      INNER JOIN reward_issuances ri ON ri.id=t.reward_issuance_id
      INNER JOIN reward_products rp ON rp.id=ri.reward_product_id
      INNER JOIN campaigns c ON c.id=ri.campaign_id
      INNER JOIN merchant_accounts m ON m.id=ri.merchant_id
      INNER JOIN crm_contacts target ON target.id=t.to_contact_id
      WHERE t.sent_by_user_id=? ORDER BY t.transferred_at DESC,t.id DESC LIMIT 250");
    $sentStmt->execute([$userId]);$sent=[];
    foreach($sentStmt->fetchAll()?:[] as $row)$sent[]=campaigns_rewards_tray_row_v110($row,'sent');
    return ['inbox'=>$inbox,'sent'=>$sent,'claimed'=>$claimed,'counts'=>['inbox'=>count($inbox),'sent'=>count($sent),'claimed'=>count($claimed)]];
}

function campaigns_rewards_send_contacts_v110(PDO $pdo,int $userId): array
{
    if($userId<1||!function_exists('column_exists')||!column_exists('crm_contacts','owner_user_id'))return [];
    $stmt=$pdo->prepare("SELECT cc.*,u.id target_user_id,u.email account_email FROM crm_contacts cc
      INNER JOIN users u ON u.email=CONVERT(cc.email_normalized USING utf8mb4) COLLATE utf8mb4_unicode_ci
        AND u.is_active=1
      WHERE cc.owner_user_id=? AND cc.status<>'archived' AND u.id<>?
        AND (COALESCE(cc.vp3_user_id,0)=0 OR cc.vp3_user_id=u.id)
      ORDER BY cc.updated_at DESC,cc.id DESC LIMIT 500");
    $stmt->execute([$userId,$userId]);
    $out=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $email=strtolower(trim((string)$row['email']));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strtolower(trim((string)$row['account_email']))!==$email)continue;
        $out[]=[
            'id'=>(int)$row['id'],'name'=>(string)($row['name']??$email),'email'=>$email,
            'phone'=>(string)($row['phone']??''),'company'=>(string)($row['company']??''),
            'vp3_user_id'=>(int)$row['target_user_id'],
        ];
    }
    return $out;
}

function campaigns_rewards_transfer_receipt_v110(PDO $pdo,int $transferId,int $issuanceId,int $senderUserId,bool $duplicate): array
{
    $stmt=$pdo->prepare('SELECT * FROM reward_transfers WHERE id=? AND reward_issuance_id=? AND sent_by_user_id=? LIMIT 1 FOR UPDATE');
    $stmt->execute([$transferId,$issuanceId,$senderUserId]);
    $row=$stmt->fetch()?:throw new RuntimeException('Reward transfer receipt is unavailable.');
    return $row+['duplicate'=>$duplicate,'target_user_id'=>(int)$row['to_user_id']];
}

function campaigns_rewards_transfer_reward_v110(PDO $pdo,int $issuanceId,int $senderUserId,int $sourceContactId,string $note='',string $idempotencyKey='',int $expectedTargetUserId=0): array
{
    if($issuanceId<1||$senderUserId<1)throw new RuntimeException('Reward certificate is unavailable.');
    $idempotencyKey=trim($idempotencyKey);
    if($idempotencyKey==='')$idempotencyKey='transfer:'.bin2hex(random_bytes(16));
    if(strlen($idempotencyKey)>190)throw new RuntimeException('Reward transfer idempotency key is too long.');
    $receiptKey='transfer-v2:'.hash('sha256',$senderUserId.'|'.$idempotencyKey);
    $note=campaigns_rewards_text_v100($note,500);

    $owns=!$pdo->inTransaction();
    $savepoint='reward_transfer_'.bin2hex(random_bytes(6));
    if($owns)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT '.$savepoint);
    try{
        // Serialize requests and recover a prior sender receipt even after the holder changed.
        $lock=$pdo->prepare('SELECT ri.merchant_id FROM reward_issuances ri WHERE ri.id=?
          AND EXISTS (SELECT 1 FROM users u WHERE u.id=? AND u.is_active=1) LIMIT 1 FOR UPDATE');
        $lock->execute([$issuanceId,$senderUserId]);
        $merchantId=(int)$lock->fetchColumn();
        if($merchantId<1)throw new RuntimeException('Reward certificate is not in your Inbox.');
        $operation=campaigns_rewards_idempotency_begin_v100($pdo,$merchantId,'reward.transfer',$receiptKey,[
            'issuance_id'=>$issuanceId,'source_contact_id'=>$sourceContactId,'note'=>$note,'expected_target_user_id'=>$expectedTargetUserId,
        ],true);
        if(!$operation['new']){
            if($operation['status']!=='completed'||$operation['result_ref_type']!=='reward_transfer')throw new RuntimeException('Reward transfer receipt is unavailable.');
            $receipt=campaigns_rewards_transfer_receipt_v110($pdo,(int)$operation['result_ref_id'],$issuanceId,$senderUserId,true);
            if($owns)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
            return $receipt;
        }
        // Historical transfers have a sender and key but no original request to validate.
        $legacy=$pdo->prepare('SELECT 1 FROM reward_transfers WHERE reward_issuance_id=? AND idempotency_key=? AND sent_by_user_id=? LIMIT 1 FOR UPDATE');
        $legacy->execute([$issuanceId,campaigns_rewards_text_v100($idempotencyKey,190),$senderUserId]);
        if($legacy->fetchColumn())throw new RuntimeException('A prior Reward transfer used this key. Check Sent for its receipt.');

        $issuance=campaigns_rewards_reward_holder_v110($pdo,$issuanceId,$senderUserId,true);
        if(!in_array((string)$issuance['status'],['issued','sent','viewed'],true)||(int)$issuance['remaining_quantity']<1)throw new RuntimeException('This Reward can no longer be sent.');
        if(!empty($issuance['expires_at'])&&strtotime((string)$issuance['expires_at'])<=time())throw new RuntimeException('This Reward has expired.');
        $history=$pdo->prepare('SELECT id FROM reward_transfers WHERE reward_issuance_id=? LIMIT 1 FOR UPDATE');
        $history->execute([$issuanceId]);$issuance['has_prior_transfer']=(bool)$history->fetchColumn();
        if(!campaigns_rewards_transfer_allowed_v110($issuance))throw new RuntimeException('The issuing Merchant does not allow this Reward to be sent.');

        $sourceContact=crm_v180_contact_for_owner($pdo,$senderUserId,$sourceContactId,true);
        if(!$sourceContact)throw new RuntimeException('Choose a Contact from your VP3 CRM.');
        $targetUserId=campaigns_rewards_active_contact_user_v100($pdo,$sourceContact,$expectedTargetUserId);
        if($targetUserId<1)throw new RuntimeException('The selected Contact needs a matching active VP3 account.');
        if($targetUserId===$senderUserId)throw new RuntimeException('Choose a different Contact to send this Reward to.');
        $targetEmail=strtolower(trim((string)$sourceContact['email']));
        // Lock the accounts in ID order and confirm the current target before changing the holder.
        $accounts=$pdo->prepare('SELECT id,email FROM users WHERE id IN (?,?) AND is_active=1 ORDER BY id FOR UPDATE');
        $accounts->execute([$senderUserId,$targetUserId]);$active=$accounts->fetchAll(PDO::FETCH_KEY_PAIR);
        if(!isset($active[$senderUserId]))throw new RuntimeException('Reward certificate is not in your Inbox.');
        if(strtolower(trim((string)($active[$targetUserId]??'')))!==$targetEmail)throw new RuntimeException('The selected Contact needs a matching active VP3 account.');
        $target=campaigns_rewards_resolve_contact_v100($pdo,$merchantId,[
            'name'=>$sourceContact['name']??$targetEmail,'email'=>$targetEmail,'phone'=>$sourceContact['phone']??'',
            'company'=>$sourceContact['company']??'','vp3_user_id'=>$targetUserId,'source'=>'reward_transfer',
        ]);
        if((int)$target['id']===(int)$issuance['recipient_contact_id'])throw new RuntimeException('Choose a different Contact to send this Reward to.');
        campaigns_rewards_ensure_merchant_relationship_v100($pdo,$merchantId,(int)$target['id'],[
            'customer_status'=>'customer','acquisition_source'=>'reward_transfer',
        ]);

        // Invalidate any previously revealed credential when holder changes.
        $newCredential=campaigns_rewards_secret_v100(24);$hash=campaigns_rewards_secret_hash_v100($newCredential);$last4=substr($newCredential,-4);
        $pdo->prepare("UPDATE reward_issuances SET recipient_contact_id=?,recipient_user_id=?,status='sent',credential_hash=?,credential_last4=?,
          sent_at=UTC_TIMESTAMP(),viewed_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=?")
          ->execute([(int)$target['id'],$targetUserId,$hash,$last4,$issuanceId]);

        $public=campaigns_rewards_uuid_v100();
        $pdo->prepare("INSERT INTO reward_transfers
          (public_id,merchant_id,reward_issuance_id,from_contact_id,from_user_id,to_contact_id,to_user_id,sent_by_user_id,delivery_mode,status,note,idempotency_key,transferred_at)
          VALUES (?,?,?,?,?,?,?,?,'vp3_inbox','sent',?,?,UTC_TIMESTAMP())")
          ->execute([$public,$merchantId,$issuanceId,(int)$issuance['recipient_contact_id'],$senderUserId,(int)$target['id'],$targetUserId,$senderUserId,$note,$receiptKey]);
        $transferId=(int)$pdo->lastInsertId();
        campaigns_rewards_idempotency_complete_v100($pdo,(int)$operation['id'],'reward_transfer',$transferId);
        campaigns_rewards_activity_event_v100($pdo,$merchantId,'reward.sent',[
            'campaign_id'=>(int)$issuance['campaign_id'],'contact_id'=>(int)$target['id'],'reward_issuance_id'=>$issuanceId,
        ],[
            'summary'=>'Reward certificate sent to a CRM Contact',
            'merchant_public_id'=>$issuance['merchant_public_id'],'campaign_public_id'=>$issuance['campaign_public_id'],
            'reward_product_public_id'=>$issuance['reward_product_public_id'],'reward_issuance_public_id'=>$issuance['public_id'],
            'reward_transfer_public_id'=>$public,
        ],(string)$issuance['environment'],$senderUserId,'user');
        $receipt=campaigns_rewards_transfer_receipt_v110($pdo,$transferId,$issuanceId,$senderUserId,false);
        if($owns)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
    }catch(Throwable $e){
        if($pdo->inTransaction()){
            if($owns)$pdo->rollBack();
            else{
                $pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
                $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
            }
        }
        throw $e;
    }

    if(function_exists('create_notification')){
        try{create_notification($targetUserId,'reward_received','New Reward received',
            (string)$issuance['reward_name'].' from '.(string)$issuance['merchant_name'],
            url('/chat.php?reward_tray=inbox'),'reward_transfer',$transferId);}catch(Throwable $e){}
    }
    return $receipt;
}

function campaigns_rewards_prepare_claim_v110(PDO $pdo,int $issuanceId,int $userId): array
{
    $issuance=campaigns_rewards_reward_holder_v110($pdo,$issuanceId,$userId,false);
    if(!in_array((string)$issuance['status'],['issued','sent','viewed'],true)||(int)$issuance['remaining_quantity']<1)throw new RuntimeException('This Reward is no longer claimable.');
    if(!empty($issuance['expires_at'])&&strtotime((string)$issuance['expires_at'])<=time())throw new RuntimeException('This Reward has expired.');
    $revealed=campaigns_rewards_rotate_reward_credential_v100($pdo,$issuanceId,$userId);
    $merchantId=(int)$issuance['merchant_id'];$canProcess=false;
    try{$canProcess=campaigns_rewards_platform_can_v100($pdo,$merchantId,$userId,'claims.process');}catch(Throwable $e){}
    $locations=$canProcess?campaigns_rewards_claim_locations_v100($pdo,$merchantId,$userId):[];
    $terminal=url('/campaign-claim.php?merchant='.$merchantId);
    return [
        'issuance_id'=>$issuanceId,'merchant_id'=>$merchantId,'merchant_name'=>(string)$issuance['merchant_name'],
        'reward_name'=>(string)$issuance['reward_name'],'credential'=>(string)$revealed['credential'],
        'credential_last4'=>(string)$revealed['credential_last4'],'can_process'=>$canProcess,
        // Credential lives in the URL fragment so it is not sent in an HTTP request/log.
        'qr_payload'=>$terminal.'#reward='.rawurlencode((string)$revealed['credential']),
        'claim_terminal_url'=>$terminal,'locations'=>$locations,
    ];
}

function campaigns_rewards_claim_from_tray_v110(PDO $pdo,int $issuanceId,int $actorUserId,string $merchantClaimCode,array $context=[]): array
{
    $issuance=campaigns_rewards_reward_holder_v110($pdo,$issuanceId,$actorUserId,false);
    $merchantId=(int)$issuance['merchant_id'];
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'claims.process');
    return campaigns_rewards_process_claim_v100($pdo,trim((string)($context['reward_credential']??'')),$merchantClaimCode,$actorUserId,[
        'online'=>true,'expected_merchant_id'=>$merchantId,'actor_type'=>'user',
        'expected_holder_user_id'=>$actorUserId,'expected_issuance_id'=>$issuanceId,
        'idempotency_key'=>(string)($context['idempotency_key']??''),
        'location_id'=>max(0,(int)($context['location_id']??0)),
        'order_ref'=>campaigns_rewards_text_v100($context['order_ref']??'',190),
        'request_fingerprint'=>(string)($context['request_fingerprint']??'reward-tray'),
        'claim_surface'=>'reward_tray_v110',
    ]);
}

function campaigns_rewards_reward_metrics_v110(PDO $pdo,int $merchantId,int $actorUserId): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'analytics.view');
    $scalar=static function(string $sql,array $params)use($pdo): int{$q=$pdo->prepare($sql);$q->execute($params);return (int)$q->fetchColumn();};
    return [
        'products'=>$scalar('SELECT COUNT(*) FROM reward_products WHERE merchant_id=? AND is_active=1',[$merchantId]),
        'issued'=>$scalar("SELECT COUNT(*) FROM reward_issuances WHERE merchant_id=? AND status NOT IN ('voided','expired')",[$merchantId]),
        'active'=>$scalar("SELECT COUNT(*) FROM reward_issuances WHERE merchant_id=? AND status IN ('issued','sent','viewed') AND remaining_quantity>0 AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())",[$merchantId]),
        'claimed'=>$scalar("SELECT COUNT(*) FROM reward_claims WHERE merchant_id=? AND status='claimed'",[$merchantId]),
        'sent'=>$scalar('SELECT COUNT(*) FROM reward_transfers WHERE merchant_id=?',[$merchantId]),
    ];
}
