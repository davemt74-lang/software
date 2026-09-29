<?php
declare(strict_types=1);

require_once __DIR__.'/campaigns-rewards-v110.php';
require_once __DIR__.'/profile-webmcp-actions-v150.php';
require_once __DIR__.'/profile-webmcp-scheduling-v150.php';

const VP3_PROFILE_WEBMCP_REWARDS_V180='profile-webmcp-rewards-v180-20260929';
const VP3_PROFILE_WEBMCP_REWARD_INTENT_TTL_V182=600;

function vp3_profile_webmcp_rewards_tool_catalog_v180(): array
{
    return [
        'vp3.rewards.wallet.get'=>[
            'title'=>'Get my Reward Wallet',
            'description'=>'Return the signed-in viewer\'s safe Reward Inbox, Sent, and Claimed projections. This never exposes Reward credentials or recipient contact details.',
            'capability'=>'rewards',
            'input_schema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>false,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.reward.get'=>[
            'title'=>'Get my Reward',
            'description'=>'Return one safe Reward Wallet item by opaque Reward public ID, including claim readiness without revealing the Reward credential.',
            'capability'=>'rewards',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>['reward_public_id'=>['type'=>'string','minLength'=>1,'maxLength'=>100]],
                'required'=>['reward_public_id'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>false,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.rewards.claim.prepare'=>[
            'title'=>'Prepare Reward claim handoff',
            'description'=>'Validate an Inbox Reward for redemption and prepare an authenticated handoff without revealing a Reward credential.',
            'capability'=>'rewards',
            'input_schema'=>['type'=>'object','properties'=>['reward_public_id'=>['type'=>'string','minLength'=>1,'maxLength'=>100]],'required'=>['reward_public_id'],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>false,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.rewards.claim.confirm'=>[
            'title'=>'Confirm Reward claim handoff',
            'description'=>'Commit the prepared handoff to the existing authenticated Reward Inbox redemption flow. This does not redeem the Reward.',
            'capability'=>'rewards',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>[
                    'confirmation_token'=>['type'=>'string','minLength'=>20,'maxLength'=>2048],
                    'idempotency_key'=>['type'=>'string','minLength'=>8,'maxLength'=>96],
                    'intent'=>['type'=>'object','additionalProperties'=>true],
                ],
                'required'=>['confirmation_token','idempotency_key','intent'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>false,'consequentialHint'=>true,'debugging'=>false],
        ],
        'vp3.rewards.transfer.contacts.list'=>[
            'title'=>'List my Reward transfer recipients',
            'description'=>'List signed-in viewer CRM contacts eligible for Reward transfer using opaque contact public IDs.',
            'capability'=>'rewards',
            'input_schema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>false,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.rewards.transfer.prepare'=>[
            'title'=>'Prepare Reward transfer',
            'description'=>'Validate a transferable Reward and selected CRM recipient without changing Reward ownership.',
            'capability'=>'rewards',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>[
                    'reward_public_id'=>['type'=>'string','minLength'=>1,'maxLength'=>100],
                    'recipient_public_id'=>['type'=>'string','minLength'=>1,'maxLength'=>100],
                    'note'=>['type'=>'string','maxLength'=>500],
                ],
                'required'=>['reward_public_id','recipient_public_id'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>false,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.rewards.transfer.confirm'=>[
            'title'=>'Confirm Reward transfer',
            'description'=>'Transfer the exact prepared Reward through the canonical Reward transfer authority.',
            'capability'=>'rewards',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>[
                    'confirmation_token'=>['type'=>'string','minLength'=>20,'maxLength'=>2048],
                    'idempotency_key'=>['type'=>'string','minLength'=>8,'maxLength'=>96],
                    'intent'=>['type'=>'object','additionalProperties'=>true],
                ],
                'required'=>['confirmation_token','idempotency_key','intent'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>false,'consequentialHint'=>true,'debugging'=>false],
        ],
    ];
}

function vp3_profile_webmcp_reward_row_v180(array $row): array
{
    $bucket=(string)($row['bucket']??'');
    $status=(string)($row['status']??'issued');
    $expires=(string)($row['expires_at']??'');
    return [
        'public_id'=>(string)($row['public_id']??''),
        'bucket'=>$bucket,
        'status'=>$status,
        'reward_name'=>(string)($row['reward_name']??'Reward'),
        'merchant_name'=>(string)($row['merchant_name']??''),
        'campaign_name'=>(string)($row['campaign_name']??''),
        'value_label'=>(string)($row['value_label']??''),
        'quantity'=>max(0,(int)($row['quantity']??0)),
        'currency'=>(string)($row['currency']??'USD'),
        'face_value_minor'=>array_key_exists('face_value_minor',$row)&&$row['face_value_minor']!==null?(int)$row['face_value_minor']:null,
        'transferable'=>!empty($row['transferable']),
        'claimable'=>$bucket==='inbox'&&in_array($status,['issued','sent','viewed'],true)&&max(0,(int)($row['quantity']??0))>0&&($expires===''||strtotime($expires)>time()),
        'expires_at'=>$expires!==''?$expires:null,
        'issued_at'=>trim((string)($row['issued_at']??''))?:null,
        'sent_at'=>trim((string)($row['sent_at']??''))?:null,
        'claimed_at'=>trim((string)($row['claimed_at']??''))?:null,
        'delivery_mode'=>$bucket==='sent'?(trim((string)($row['delivery_mode']??''))?:null):null,
    ];
}

function vp3_profile_webmcp_rewards_wallet_v180(PDO $pdo,?array $viewer): array
{
    $viewerId=(int)($viewer['id']??0);
    if($viewerId<1)throw new RuntimeException('Sign in to view your Reward Wallet.');
    if(!function_exists('campaigns_rewards_v110_schema_ready')||!campaigns_rewards_v110_schema_ready($pdo)){
        throw new RuntimeException('Reward Wallet is unavailable until Campaigns & Rewards is upgraded.');
    }
    $tray=campaigns_rewards_reward_tray_v110($pdo,$viewerId);
    $out=['inbox'=>[],'sent'=>[],'claimed'=>[],'counts'=>['inbox'=>0,'sent'=>0,'claimed'=>0]];
    foreach(['inbox','sent','claimed'] as $bucket){
        foreach((array)($tray[$bucket]??[]) as $row){
            if(!is_array($row))continue;
            $safe=vp3_profile_webmcp_reward_row_v180($row);
            if($safe['public_id']==='')continue;
            $out[$bucket][]=$safe;
        }
        $out['counts'][$bucket]=count($out[$bucket]);
    }
    return ['wallet'=>$out,'authenticated_viewer'=>true];
}


function vp3_profile_webmcp_reward_get_v181(PDO $pdo,?array $viewer,array $input): array
{
    $publicId=trim((string)($input['reward_public_id']??''));
    if($publicId==='')throw new RuntimeException('Choose a Reward from your Wallet.');
    $wallet=vp3_profile_webmcp_rewards_wallet_v180($pdo,$viewer)['wallet'];
    foreach(['inbox','sent','claimed'] as $bucket){
        foreach((array)($wallet[$bucket]??[]) as $reward){
            if(!is_array($reward)||!hash_equals((string)($reward['public_id']??''),$publicId))continue;
            return [
                'reward'=>$reward,
                'claim_handoff_required'=>!empty($reward['claimable']),
                'claim_handoff_url'=>!empty($reward['claimable'])?url('/reward-inbox.php'):'',
                'credential_exposed'=>false,
            ];
        }
    }
    throw new RuntimeException('Reward not found in your Wallet.');
}


function vp3_profile_webmcp_reward_holder_by_public_id_v182(PDO $pdo,int $viewerId,string $publicId): array
{
    $publicId=trim($publicId);
    if($viewerId<1||$publicId==='')throw new RuntimeException('Reward is unavailable.');
    $stmt=$pdo->prepare("SELECT ri.id FROM reward_issuances ri
      INNER JOIN crm_contacts cc ON cc.id=ri.recipient_contact_id
      WHERE ri.public_id=? AND (ri.recipient_user_id=? OR cc.vp3_user_id=?) LIMIT 1");
    $stmt->execute([$publicId,$viewerId,$viewerId]);
    $id=(int)$stmt->fetchColumn();
    if($id<1)throw new RuntimeException('Reward is not in your Wallet.');
    return campaigns_rewards_reward_holder_v110($pdo,$id,$viewerId,false);
}

function vp3_profile_webmcp_reward_state_hash_v182(array $row): string
{
    return vp3_profile_webmcp_payload_hash_v150([
        'public_id'=>(string)($row['public_id']??''),
        'status'=>(string)($row['status']??''),
        'remaining_quantity'=>(int)($row['remaining_quantity']??0),
        'expires_at'=>(string)($row['expires_at']??''),
        'terms_snapshot_json'=>(string)($row['terms_snapshot_json']??''),
        'updated_at'=>(string)($row['updated_at']??''),
    ]);
}

function vp3_profile_webmcp_reward_claim_context_v182(array $profile,array $viewer,array $telemetry,string $nativeProof): array
{
    $ctx=vp3_profile_webmcp_scheduling_context_v150($profile,'native_profile',$telemetry,$nativeProof,null,'');
    $viewerId=(int)($viewer['id']??0);
    if($viewerId<1)throw new RuntimeException('Sign in to prepare a Reward claim.');
    $ctx['viewer_user_id']=$viewerId;
    return $ctx;
}

function vp3_profile_webmcp_reward_claim_prepare_v182(PDO $pdo,array $profile,array $viewer,array $context,array $input): array
{
    if(!vp3_profile_webmcp_actions_schema_ready_v150($pdo))throw new RuntimeException('Reward confirmation ledger is unavailable.');
    $viewerId=(int)($viewer['id']??0);
    $reward=vp3_profile_webmcp_reward_holder_by_public_id_v182($pdo,$viewerId,(string)($input['reward_public_id']??''));
    if(!in_array((string)$reward['status'],['issued','sent','viewed'],true)||(int)$reward['remaining_quantity']<1){
        throw new RuntimeException('This Reward is no longer claimable.');
    }
    if(!empty($reward['expires_at'])&&strtotime((string)$reward['expires_at'])<=time())throw new RuntimeException('This Reward has expired.');
    $intent=[
        'viewer_user_id'=>$viewerId,
        'reward_public_id'=>(string)$reward['public_id'],
        'reward_state_hash'=>vp3_profile_webmcp_reward_state_hash_v182($reward),
    ];
    $action=vp3_profile_webmcp_action_prepare_v150($pdo,$context,'reward.claim_handoff',$intent,VP3_PROFILE_WEBMCP_REWARD_INTENT_TTL_V182);
    return [
        'intent_id'=>$action['intent_id'],
        'confirmation_token'=>vp3_profile_webmcp_scheduling_token_v150($action,$context),
        'expires_at_unix'=>$action['expires_at_unix'],
        'intent'=>$intent,
        'preview'=>[
            'reward'=>[
                'public_id'=>(string)$reward['public_id'],
                'reward_name'=>(string)$reward['reward_name'],
                'merchant_name'=>(string)$reward['merchant_name'],
                'campaign_name'=>(string)$reward['campaign_name'],
                'expires_at'=>trim((string)($reward['expires_at']??''))?:null,
                'remaining_quantity'=>max(0,(int)$reward['remaining_quantity']),
            ],
            'redemption_requires_merchant_operator'=>true,
            'credential_exposed'=>false,
        ],
        'confirmation_required'=>true,
    ];
}

function vp3_profile_webmcp_reward_claim_confirm_v182(
    PDO $pdo,array $profile,array $viewer,array $context,array $intent,string $confirmationToken,string $idempotencyKey
): array {
    $viewerId=(int)($viewer['id']??0);
    if($viewerId<1||(int)($intent['viewer_user_id']??0)!==$viewerId)throw new RuntimeException('Reward confirmation belongs to a different viewer.');
    $operation='reward.claim_handoff';
    $verified=vp3_profile_webmcp_scheduling_token_verify_v150($confirmationToken,$context,$operation,$intent);
    $intentId=(string)$verified['intent_id'];
    $owner=(int)$profile['user_id'];
    $idem=vp3_profile_webmcp_idempotency_hash_v150($owner,$operation,$idempotencyKey);
    $lock='vp3_webmcp_idem_'.substr($idem,0,32);
    $lockStmt=$pdo->prepare('SELECT GET_LOCK(?,5)');$lockStmt->execute([$lock]);
    if((int)$lockStmt->fetchColumn()!==1)throw new RuntimeException('That Reward handoff is already in progress. Retry with the same idempotency key.');
    $started=!$pdo->inTransaction();
    try{
        if($started)$pdo->beginTransaction();
        $action=vp3_profile_webmcp_action_row_v150($pdo,$owner,$intentId,true);
        if(!$action||(string)$action['operation']!==$operation||(string)$action['state']==='failed')throw new RuntimeException('Prepared Reward action is unavailable.');
        if((string)$action['profile_username']!==(string)$context['profile_username']
           ||(string)$action['surface']!==(string)$context['surface']
           ||(int)($action['property_id']??0)!==(int)($context['property_id']??0)
           ||!hash_equals((string)$action['session_hash'],(string)$context['session_hash'])){
            throw new RuntimeException('Prepared Reward action belongs to a different WebMCP session.');
        }
        if(!hash_equals((string)$action['payload_hash'],vp3_profile_webmcp_payload_hash_v150($intent)))throw new RuntimeException('Prepared Reward payload changed.');
        if((string)$action['state']==='committed'){
            if(!hash_equals((string)$action['idempotency_hash'],$idem))throw new RuntimeException('This Reward intent was already confirmed with a different idempotency key.');
            $result=json_decode((string)($action['result_json']??''),true);
            if(!is_array($result))throw new RuntimeException('Committed Reward handoff result is unavailable.');
            $result['idempotent_replay']=true;if($started)$pdo->commit();return $result;
        }
        if((string)$action['state']!=='prepared')throw new RuntimeException('Prepared Reward action is no longer executable.');
        $expires=(new DateTimeImmutable((string)$action['expires_at'],new DateTimeZone('UTC')))->getTimestamp();
        if($expires<time())throw new RuntimeException('Reward confirmation expired. Prepare the handoff again.');
        $existing=vp3_profile_webmcp_action_by_idempotency_v150($pdo,$owner,$operation,$idem,true);
        if($existing&&(int)$existing['id']!==(int)$action['id']){
            if(!hash_equals((string)$existing['payload_hash'],(string)$action['payload_hash']))throw new RuntimeException('Idempotency key was already used for a different Reward handoff.');
            if((string)$existing['state']!=='committed')throw new RuntimeException('That Reward handoff is still in progress.');
            $result=json_decode((string)($existing['result_json']??''),true);
            if(!is_array($result))throw new RuntimeException('Committed Reward handoff result is unavailable.');
            $result['idempotent_replay']=true;if($started)$pdo->commit();return $result;
        }
        $reward=vp3_profile_webmcp_reward_holder_by_public_id_v182($pdo,$viewerId,(string)$intent['reward_public_id']);
        if(!hash_equals((string)$intent['reward_state_hash'],vp3_profile_webmcp_reward_state_hash_v182($reward))){
            throw new RuntimeException('This Reward changed. Prepare the handoff again.');
        }
        if(!in_array((string)$reward['status'],['issued','sent','viewed'],true)||(int)$reward['remaining_quantity']<1){
            throw new RuntimeException('This Reward is no longer claimable.');
        }
        if(!empty($reward['expires_at'])&&strtotime((string)$reward['expires_at'])<=time())throw new RuntimeException('This Reward has expired.');
        $claim=$pdo->prepare("UPDATE profile_webmcp_actions SET idempotency_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND state='prepared' AND idempotency_hash IS NULL");
        $claim->execute([$idem,(int)$action['id']]);
        if($claim->rowCount()!==1&&!hash_equals((string)($action['idempotency_hash']??''),$idem)){
            throw new RuntimeException('Reward idempotency claim changed before confirmation.');
        }
        $result=[
            'reward_public_id'=>(string)$reward['public_id'],
            'claim_handoff_url'=>url('/reward-inbox.php?claim='.rawurlencode((string)$reward['public_id'])),
            'redemption_requires_merchant_operator'=>true,
            'credential_exposed'=>false,
            'reward_redeemed'=>false,
        ];
        vp3_profile_webmcp_action_commit_v150($pdo,(int)$action['id'],$idem,'reward_claim_handoff',(int)$reward['id'],$result);
        if($started)$pdo->commit();
        return $result;
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }finally{
        try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lock]);}catch(Throwable $ignored){}
    }
}


function vp3_profile_webmcp_reward_transfer_contacts_v183(PDO $pdo,?array $viewer): array
{
    $viewerId=(int)($viewer['id']??0);
    if($viewerId<1)throw new RuntimeException('Sign in to choose a Reward recipient.');
    $out=[];
    foreach(campaigns_rewards_send_contacts_v110($pdo,$viewerId) as $row){
        $id=(int)($row['id']??0);if($id<1)continue;
        $contact=crm_v180_contact_for_owner($pdo,$viewerId,$id);if(!$contact)continue;
        $publicId=trim((string)($contact['public_id']??''));if($publicId==='')continue;
        $out[]=[
            'public_id'=>$publicId,
            'name'=>(string)($row['name']??$row['email']??''),
            'email'=>(string)($row['email']??''),
            'company'=>(string)($row['company']??''),
            'vp3_user'=>(int)($row['vp3_user_id']??0)>0,
        ];
    }
    return ['contacts'=>$out];
}

function vp3_profile_webmcp_reward_transfer_contact_v183(PDO $pdo,int $viewerId,string $publicId): array
{
    $publicId=trim($publicId);
    if($viewerId<1||$publicId==='')throw new RuntimeException('Choose a Reward recipient.');
    $stmt=$pdo->prepare("SELECT * FROM crm_contacts WHERE public_id=? AND owner_user_id=? AND status<>'archived' LIMIT 1");
    $stmt->execute([$publicId,$viewerId]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Reward recipient is unavailable.');
    $email=strtolower(trim((string)($row['email']??'')));
    if($email===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('The selected Contact needs a valid email.');
    return $row;
}

function vp3_profile_webmcp_reward_transfer_contact_hash_v183(array $row): string
{
    return vp3_profile_webmcp_payload_hash_v150([
        'public_id'=>(string)($row['public_id']??''),
        'name'=>(string)($row['name']??''),
        'email_normalized'=>(string)($row['email_normalized']??strtolower(trim((string)($row['email']??'')))),
        'company'=>(string)($row['company']??''),
        'phone'=>(string)($row['phone']??''),
        'vp3_user_id'=>(int)($row['vp3_user_id']??0),
        'status'=>(string)($row['status']??''),
        'updated_at'=>(string)($row['updated_at']??''),
    ]);
}

function vp3_profile_webmcp_reward_transfer_prepare_v183(PDO $pdo,array $profile,array $viewer,array $context,array $input): array
{
    if(!vp3_profile_webmcp_actions_schema_ready_v150($pdo))throw new RuntimeException('Reward confirmation ledger is unavailable.');
    $viewerId=(int)($viewer['id']??0);
    $reward=vp3_profile_webmcp_reward_holder_by_public_id_v182($pdo,$viewerId,(string)($input['reward_public_id']??''));
    if(!in_array((string)$reward['status'],['issued','sent','viewed'],true)||(int)$reward['remaining_quantity']<1)throw new RuntimeException('This Reward can no longer be sent.');
    if(!empty($reward['expires_at'])&&strtotime((string)$reward['expires_at'])<=time())throw new RuntimeException('This Reward has expired.');
    $terms=json_decode((string)($reward['terms_snapshot_json']??''),true);if(!is_array($terms))$terms=[];
    $transferable=!empty($terms['transferable'])||!empty($terms['regiftable'])||!empty($reward['product_transferable'])||!empty($reward['product_regiftable']);
    if(!$transferable)throw new RuntimeException('This Reward is marked non-transferable by the issuing Merchant.');
    $contact=vp3_profile_webmcp_reward_transfer_contact_v183($pdo,$viewerId,(string)($input['recipient_public_id']??''));
    if((int)($contact['vp3_user_id']??0)>0&&(int)$contact['vp3_user_id']===$viewerId)throw new RuntimeException('Choose a different Contact to send this Reward to.');
    $intent=[
        'viewer_user_id'=>$viewerId,
        'reward_public_id'=>(string)$reward['public_id'],
        'reward_state_hash'=>vp3_profile_webmcp_reward_state_hash_v182($reward),
        'recipient_public_id'=>(string)$contact['public_id'],
        'recipient_state_hash'=>vp3_profile_webmcp_reward_transfer_contact_hash_v183($contact),
        'note'=>mb_strimwidth(trim((string)($input['note']??'')),0,500,''),
    ];
    $action=vp3_profile_webmcp_action_prepare_v150($pdo,$context,'reward.transfer',$intent,600);
    return [
        'intent_id'=>$action['intent_id'],
        'confirmation_token'=>vp3_profile_webmcp_scheduling_token_v150($action,$context),
        'expires_at_unix'=>$action['expires_at_unix'],
        'intent'=>$intent,
        'preview'=>[
            'reward'=>['public_id'=>(string)$reward['public_id'],'reward_name'=>(string)$reward['reward_name'],'merchant_name'=>(string)$reward['merchant_name']],
            'recipient'=>['public_id'=>(string)$contact['public_id'],'name'=>(string)$contact['name'],'email'=>(string)$contact['email'],'company'=>(string)($contact['company']??'')],
            'note'=>$intent['note'],
        ],
        'confirmation_required'=>true,
    ];
}

function vp3_profile_webmcp_reward_transfer_confirm_v183(PDO $pdo,array $profile,array $viewer,array $context,array $intent,string $confirmationToken,string $idempotencyKey): array
{
    $viewerId=(int)($viewer['id']??0);
    if($viewerId<1||(int)($intent['viewer_user_id']??0)!==$viewerId)throw new RuntimeException('Reward transfer belongs to a different viewer.');
    $operation='reward.transfer';
    $verified=vp3_profile_webmcp_scheduling_token_verify_v150($confirmationToken,$context,$operation,$intent);
    $intentId=(string)$verified['intent_id'];$owner=(int)$profile['user_id'];
    $idem=vp3_profile_webmcp_idempotency_hash_v150($owner,$operation,$idempotencyKey);
    $lock='vp3_webmcp_idem_'.substr($idem,0,32);
    $ls=$pdo->prepare('SELECT GET_LOCK(?,5)');$ls->execute([$lock]);
    if((int)$ls->fetchColumn()!==1)throw new RuntimeException('That Reward transfer is already in progress. Retry with the same idempotency key.');
    $started=!$pdo->inTransaction();
    try{
        if($started)$pdo->beginTransaction();
        $action=vp3_profile_webmcp_action_row_v150($pdo,$owner,$intentId,true);
        if(!$action||(string)$action['operation']!==$operation)throw new RuntimeException('Prepared Reward transfer is unavailable.');
        if((string)$action['profile_username']!==(string)$context['profile_username']||(string)$action['surface']!==(string)$context['surface']||!hash_equals((string)$action['session_hash'],(string)$context['session_hash']))throw new RuntimeException('Prepared Reward transfer belongs to a different WebMCP session.');
        if(!hash_equals((string)$action['payload_hash'],vp3_profile_webmcp_payload_hash_v150($intent)))throw new RuntimeException('Prepared Reward transfer payload changed.');
        if((string)$action['state']==='committed'){
            if(!hash_equals((string)$action['idempotency_hash'],$idem))throw new RuntimeException('This Reward transfer was already confirmed with a different idempotency key.');
            $result=json_decode((string)($action['result_json']??''),true);if(!is_array($result))throw new RuntimeException('Committed Reward transfer result is unavailable.');
            $result['idempotent_replay']=true;if($started)$pdo->commit();return $result;
        }
        if((string)$action['state']!=='prepared')throw new RuntimeException('Prepared Reward transfer is no longer executable.');
        $existing=vp3_profile_webmcp_action_by_idempotency_v150($pdo,$owner,$operation,$idem,true);
        if($existing&&(int)$existing['id']!==(int)$action['id']){
            if(!hash_equals((string)$existing['payload_hash'],(string)$action['payload_hash']))throw new RuntimeException('Idempotency key was already used for a different Reward transfer.');
            if((string)$existing['state']!=='committed')throw new RuntimeException('That Reward transfer is still in progress.');
            $result=json_decode((string)($existing['result_json']??''),true);if(!is_array($result))throw new RuntimeException('Committed Reward transfer result is unavailable.');
            $result['idempotent_replay']=true;if($started)$pdo->commit();return $result;
        }
        $expires=(new DateTimeImmutable((string)$action['expires_at'],new DateTimeZone('UTC')))->getTimestamp();
        if($expires<time())throw new RuntimeException('Reward transfer confirmation expired. Prepare the transfer again.');
        $reward=vp3_profile_webmcp_reward_holder_by_public_id_v182($pdo,$viewerId,(string)$intent['reward_public_id']);
        if(!hash_equals((string)$intent['reward_state_hash'],vp3_profile_webmcp_reward_state_hash_v182($reward)))throw new RuntimeException('This Reward changed. Prepare the transfer again.');
        $contact=vp3_profile_webmcp_reward_transfer_contact_v183($pdo,$viewerId,(string)$intent['recipient_public_id']);
        if(!hash_equals((string)$intent['recipient_state_hash'],vp3_profile_webmcp_reward_transfer_contact_hash_v183($contact)))throw new RuntimeException('The selected Reward recipient changed. Prepare the transfer again.');
        $claim=$pdo->prepare("UPDATE profile_webmcp_actions SET idempotency_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND state='prepared' AND idempotency_hash IS NULL");
        $claim->execute([$idem,(int)$action['id']]);
        if($claim->rowCount()!==1&&!hash_equals((string)($action['idempotency_hash']??''),$idem))throw new RuntimeException('Reward transfer idempotency changed before confirmation.');
        $canonical=campaigns_rewards_transfer_reward_v110($pdo,(int)$reward['id'],$viewerId,(int)$contact['id'],(string)($intent['note']??''),'webmcp:'.$intentId.':'.$idem);
        $result=[
            'reward_public_id'=>(string)$reward['public_id'],
            'transfer_public_id'=>(string)($canonical['public_id']??''),
            'recipient'=>['public_id'=>(string)$contact['public_id'],'name'=>(string)$contact['name'],'email'=>(string)$contact['email']],
            'delivery_mode'=>(string)($canonical['delivery_mode']??''),
            'status'=>(string)($canonical['status']??'sent'),
        ];
        vp3_profile_webmcp_action_commit_v150($pdo,(int)$action['id'],$idem,'reward_transfer',(int)($canonical['id']??0),$result);
        if($started)$pdo->commit();
        return $result;
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();throw $e;
    }finally{
        try{$r=$pdo->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lock]);}catch(Throwable $ignored){}
    }
}
