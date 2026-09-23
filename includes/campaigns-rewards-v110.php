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
    $sql="SELECT ri.*,cc.vp3_user_id contact_vp3_user_id,cc.name recipient_name,cc.email recipient_email,
      rp.public_id reward_product_public_id,rp.name reward_name,rp.transferable product_transferable,rp.regiftable product_regiftable,
      c.public_id campaign_public_id,c.name campaign_name,c.status campaign_status,
      m.public_id merchant_public_id,m.name merchant_name,m.status merchant_status
      FROM reward_issuances ri
      INNER JOIN crm_contacts cc ON cc.id=ri.recipient_contact_id
      INNER JOIN reward_products rp ON rp.id=ri.reward_product_id
      INNER JOIN campaigns c ON c.id=ri.campaign_id
      INNER JOIN merchant_accounts m ON m.id=ri.merchant_id
      WHERE ri.id=? AND (ri.recipient_user_id=? OR cc.vp3_user_id=?) LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$issuanceId,$userId,$userId]);
    return $stmt->fetch()?:throw new RuntimeException('Reward certificate is not in your Inbox.');
}

function campaigns_rewards_tray_row_v110(array $row,string $bucket): array
{
    $terms=json_decode((string)($row['terms_snapshot_json']??''),true);if(!is_array($terms))$terms=[];
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
        'transferable'=>!empty($terms['transferable'])||!empty($terms['regiftable'])||!empty($row['product_transferable'])||!empty($row['product_regiftable']),
        'expires_at'=>(string)($row['expires_at']??''),
        'issued_at'=>(string)($row['issued_at']??''),
        'sent_at'=>(string)($row['sent_at']??$row['transferred_at']??''),
        'claimed_at'=>(string)($row['claimed_at']??''),
        'recipient_name'=>(string)($row['target_name']??$row['recipient_name']??''),
        'recipient_email'=>(string)($row['target_email']??$row['recipient_email']??''),
        'delivery_mode'=>(string)($row['delivery_mode']??''),
    ];
}

function campaigns_rewards_reward_tray_v110(PDO $pdo,int $userId): array
{
    if($userId<1||!campaigns_rewards_v110_schema_ready($pdo))return ['inbox'=>[],'sent'=>[],'claimed'=>[],'counts'=>['inbox'=>0,'sent'=>0,'claimed'=>0]];
    $base="SELECT ri.*,rp.name reward_name,rp.transferable product_transferable,rp.regiftable product_regiftable,
      c.name campaign_name,m.name merchant_name,cc.name recipient_name,cc.email recipient_email,
      rc.claimed_at claim_row_claimed_at
      FROM reward_issuances ri
      INNER JOIN reward_products rp ON rp.id=ri.reward_product_id
      INNER JOIN campaigns c ON c.id=ri.campaign_id
      INNER JOIN merchant_accounts m ON m.id=ri.merchant_id
      INNER JOIN crm_contacts cc ON cc.id=ri.recipient_contact_id
      LEFT JOIN reward_claims rc ON rc.reward_issuance_id=ri.id AND rc.status='claimed'
      WHERE (ri.recipient_user_id=? OR cc.vp3_user_id=?)";
    $q=$pdo->prepare($base." ORDER BY ri.issued_at DESC,ri.id DESC");$q->execute([$userId,$userId]);
    $inbox=[];$claimed=[];
    foreach($q->fetchAll()?:[] as $row){
        $isClaimed=(string)($row['status']??'')==='claimed'||!empty($row['claim_row_claimed_at']);
        if($isClaimed){
            if(empty($row['claimed_at'])&&!empty($row['claim_row_claimed_at']))$row['claimed_at']=$row['claim_row_claimed_at'];
            $claimed[]=campaigns_rewards_tray_row_v110($row,'claimed');
            continue;
        }
        if(in_array((string)($row['status']??''),['issued','sent','viewed'],true)
            &&(int)($row['remaining_quantity']??0)>0
            &&(empty($row['expires_at'])||strtotime((string)$row['expires_at'])>time())){
            $inbox[]=campaigns_rewards_tray_row_v110($row,'inbox');
        }
    }
    $sentStmt=$pdo->prepare("SELECT t.*,ri.public_id issuance_public_id,ri.status,ri.remaining_quantity,ri.quantity,ri.face_value_minor,ri.currency,ri.terms_snapshot_json,ri.expires_at,ri.issued_at,ri.sent_at,
      rp.name reward_name,rp.transferable product_transferable,rp.regiftable product_regiftable,
      c.name campaign_name,m.name merchant_name,target.name target_name,target.email target_email,rc.claimed_at
      FROM reward_transfers t
      INNER JOIN reward_issuances ri ON ri.id=t.reward_issuance_id
      INNER JOIN reward_products rp ON rp.id=ri.reward_product_id
      INNER JOIN campaigns c ON c.id=ri.campaign_id
      INNER JOIN merchant_accounts m ON m.id=ri.merchant_id
      INNER JOIN crm_contacts target ON target.id=t.to_contact_id
      LEFT JOIN reward_claims rc ON rc.reward_issuance_id=ri.id AND rc.status='claimed'
      WHERE t.sent_by_user_id=? ORDER BY t.transferred_at DESC,t.id DESC LIMIT 250");
    $sentStmt->execute([$userId]);$sent=[];
    foreach($sentStmt->fetchAll()?:[] as $row)$sent[]=campaigns_rewards_tray_row_v110($row,'sent');
    return ['inbox'=>$inbox,'sent'=>$sent,'claimed'=>$claimed,'counts'=>['inbox'=>count($inbox),'sent'=>count($sent),'claimed'=>count($claimed)]];
}

function campaigns_rewards_send_contacts_v110(PDO $pdo,int $userId): array
{
    if($userId<1||!function_exists('crm_v180_contacts_for_owner'))return [];
    $out=[];
    foreach(crm_v180_contacts_for_owner($pdo,$userId,500) as $row){
        $email=strtolower(trim((string)($row['email']??'')));if($email===''||!filter_var($email,FILTER_VALIDATE_EMAIL))continue;
        $out[]=[
            'id'=>(int)$row['id'],'name'=>(string)($row['name']??$email),'email'=>$email,
            'phone'=>(string)($row['phone']??''),'company'=>(string)($row['company']??''),
            'vp3_user_id'=>max(0,(int)($row['vp3_user_id']??0)),
        ];
    }
    return $out;
}

function campaigns_rewards_transfer_reward_v110(PDO $pdo,int $issuanceId,int $senderUserId,int $sourceContactId,string $note='',string $idempotencyKey=''): array
{
    $sourceContact=crm_v180_contact_for_owner($pdo,$senderUserId,$sourceContactId);
    if(!$sourceContact)throw new RuntimeException('Choose a Contact from your VP3 CRM.');
    $targetEmail=strtolower(trim((string)($sourceContact['email']??'')));
    if($targetEmail===''||!filter_var($targetEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('The selected Contact needs a valid email.');
    $idempotencyKey=campaigns_rewards_text_v100($idempotencyKey,190);
    if($idempotencyKey==='')$idempotencyKey='transfer:'.bin2hex(random_bytes(16));

    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $issuance=campaigns_rewards_reward_holder_v110($pdo,$issuanceId,$senderUserId,true);
        if(!in_array((string)$issuance['status'],['issued','sent','viewed'],true)||(int)$issuance['remaining_quantity']<1)throw new RuntimeException('This Reward can no longer be sent.');
        if(!empty($issuance['expires_at'])&&strtotime((string)$issuance['expires_at'])<=time())throw new RuntimeException('This Reward has expired.');
        $terms=json_decode((string)$issuance['terms_snapshot_json'],true);if(!is_array($terms))$terms=[];
        $transferable=!empty($terms['transferable'])||!empty($terms['regiftable'])||!empty($issuance['product_transferable'])||!empty($issuance['product_regiftable']);
        if(!$transferable)throw new RuntimeException('This Reward is marked non-transferable by the issuing Merchant.');

        $existing=$pdo->prepare('SELECT * FROM reward_transfers WHERE reward_issuance_id=? AND idempotency_key=? LIMIT 1');
        $existing->execute([$issuanceId,$idempotencyKey]);$dup=$existing->fetch();
        if($dup){if($owns)$pdo->commit();return $dup+['duplicate'=>true];}

        $target=campaigns_rewards_resolve_contact_v100($pdo,(int)$issuance['merchant_id'],[
            'name'=>$sourceContact['name']??$targetEmail,'email'=>$targetEmail,'phone'=>$sourceContact['phone']??'',
            'company'=>$sourceContact['company']??'','vp3_user_id'=>(int)($sourceContact['vp3_user_id']??0),'source'=>'reward_transfer',
        ]);
        $targetUserId=max(0,(int)($target['vp3_user_id']??0));
        if((int)$target['id']===(int)$issuance['recipient_contact_id']||($targetUserId>0&&$targetUserId===$senderUserId))throw new RuntimeException('Choose a different Contact to send this Reward to.');
        campaigns_rewards_ensure_merchant_relationship_v100($pdo,(int)$issuance['merchant_id'],(int)$target['id'],[
            'customer_status'=>'customer','acquisition_source'=>'reward_transfer',
        ]);

        // Invalidate any previously revealed credential when holder changes.
        $newCredential=campaigns_rewards_secret_v100(24);$hash=campaigns_rewards_secret_hash_v100($newCredential);$last4=substr($newCredential,-4);
        $pdo->prepare("UPDATE reward_issuances SET recipient_contact_id=?,recipient_user_id=?,status='sent',credential_hash=?,credential_last4=?,
          sent_at=UTC_TIMESTAMP(),viewed_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=?")
          ->execute([(int)$target['id'],$targetUserId?:null,$hash,$last4,$issuanceId]);

        $public=campaigns_rewards_uuid_v100();
        $deliveryMode=$targetUserId>0?'vp3_inbox':'crm';
        $pdo->prepare("INSERT INTO reward_transfers
          (public_id,merchant_id,reward_issuance_id,from_contact_id,from_user_id,to_contact_id,to_user_id,sent_by_user_id,delivery_mode,status,note,idempotency_key,transferred_at)
          VALUES (?,?,?,?,?,?,?,?,?,'sent',?,?,UTC_TIMESTAMP())")
          ->execute([$public,(int)$issuance['merchant_id'],$issuanceId,(int)$issuance['recipient_contact_id'],$senderUserId,(int)$target['id'],$targetUserId?:null,$senderUserId,$deliveryMode,campaigns_rewards_text_v100($note,500),$idempotencyKey]);
        $transferId=(int)$pdo->lastInsertId();
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}

    campaigns_rewards_activity_event_v100($pdo,(int)$issuance['merchant_id'],'reward.sent',[
        'campaign_id'=>(int)$issuance['campaign_id'],'contact_id'=>(int)$target['id'],'reward_issuance_id'=>$issuanceId,
    ],[
        'summary'=>'Reward certificate sent to a CRM Contact',
        'merchant_public_id'=>$issuance['merchant_public_id'],'campaign_public_id'=>$issuance['campaign_public_id'],
        'reward_product_public_id'=>$issuance['reward_product_public_id'],'reward_issuance_public_id'=>$issuance['public_id'],
    ],(string)$issuance['environment'],$senderUserId,'user');
    if($targetUserId>0&&function_exists('create_notification')){
        try{create_notification($targetUserId,'reward_received','New Reward received',
            (string)$issuance['reward_name'].' from '.(string)$issuance['merchant_name'],
            url('/chat.php?reward_tray=inbox'),'reward_issuance',$issuanceId);}catch(Throwable $e){}
    }
    $q=$pdo->prepare('SELECT * FROM reward_transfers WHERE id=? LIMIT 1');$q->execute([$transferId]);$row=$q->fetch()?:[];
    return $row+['duplicate'=>false,'target_name'=>(string)($target['name']??$targetEmail),'target_email'=>$targetEmail,'target_user_id'=>$targetUserId];
}

function campaigns_rewards_prepare_claim_v110(PDO $pdo,int $issuanceId,int $userId): array
{
    $issuance=campaigns_rewards_reward_holder_v110($pdo,$issuanceId,$userId,false);
    if(!in_array((string)$issuance['status'],['issued','sent','viewed'],true)||(int)$issuance['remaining_quantity']<1)throw new RuntimeException('This Reward is no longer claimable.');
    if(!empty($issuance['expires_at'])&&strtotime((string)$issuance['expires_at'])<=time())throw new RuntimeException('This Reward has expired.');
    $revealed=campaigns_rewards_rotate_reward_credential_v100($pdo,$issuanceId,$userId);
    $merchantId=(int)$issuance['merchant_id'];$canProcess=false;
    try{$canProcess=campaigns_rewards_platform_can_v100($pdo,$merchantId,$userId,'claims.process');}catch(Throwable $e){}
    $locations=[];$stmt=$pdo->prepare("SELECT id,name FROM merchant_locations WHERE merchant_id=? AND is_active=1 ORDER BY is_primary DESC,name,id");
    $stmt->execute([$merchantId]);$locations=$stmt->fetchAll()?:[];
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
    $revealed=campaigns_rewards_rotate_reward_credential_v100($pdo,$issuanceId,$actorUserId);
    return campaigns_rewards_process_claim_v100($pdo,(string)$revealed['credential'],$merchantClaimCode,$actorUserId,[
        'online'=>true,'expected_merchant_id'=>$merchantId,'actor_type'=>'user',
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
