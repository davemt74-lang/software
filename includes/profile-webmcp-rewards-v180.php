<?php
declare(strict_types=1);

require_once __DIR__.'/campaigns-rewards-v110.php';
require_once __DIR__.'/profile-webmcp-actions-v150.php';
require_once __DIR__.'/profile-webmcp-scheduling-v150.php';

const VP3_PROFILE_WEBMCP_REWARDS_V180='profile-webmcp-rewards-v180-20260929';

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
        'vp3.rewards.transfer.contacts.list'=>[
            'title'=>'List Reward transfer contacts',
            'description'=>'List a bounded set of the signed-in viewer\'s VP3 CRM contacts eligible for Reward transfer.',
            'capability'=>'rewards',
            'input_schema'=>['type'=>'object','properties'=>['query'=>['type'=>'string','maxLength'=>120]],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.reward.transfer.prepare'=>[
            'title'=>'Prepare Reward transfer',
            'description'=>'Validate and preview sending one transferable Reward to a selected VP3 CRM contact without changing Reward ownership.',
            'capability'=>'rewards',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>[
                    'reward_public_id'=>['type'=>'string','minLength'=>1,'maxLength'=>100],
                    'target_contact_id'=>['type'=>'integer','minimum'=>1],
                    'note'=>['type'=>'string','maxLength'=>500],
                ],
                'required'=>['reward_public_id','target_contact_id'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.reward.transfer.confirm'=>[
            'title'=>'Confirm Reward transfer',
            'description'=>'Send the exact prepared Reward to the selected contact through the canonical Reward transfer runtime.',
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
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>true,'debugging'=>false],
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


function vp3_profile_webmcp_rewards_transfer_contacts_v182(PDO $pdo,?array $viewer,array $input=[]): array
{
    $viewerId=(int)($viewer['id']??0);
    if($viewerId<1)throw new RuntimeException('Sign in to send Rewards.');
    $query=mb_strtolower(trim((string)($input['query']??'')));
    $out=[];
    foreach(campaigns_rewards_send_contacts_v110($pdo,$viewerId) as $row){
        $name=trim((string)($row['name']??''));
        $email=strtolower(trim((string)($row['email']??'')));
        if($query!==''&&!str_contains(mb_strtolower($name.' '.$email),$query))continue;
        $out[]=[
            'contact_id'=>(int)$row['id'],
            'name'=>$name!==''?$name:$email,
            'email'=>$email,
            'vp3_user'=>max(0,(int)($row['vp3_user_id']??0))>0,
        ];
        if(count($out)>=50)break;
    }
    return ['contacts'=>$out,'count'=>count($out)];
}

function vp3_profile_webmcp_reward_raw_holder_v182(PDO $pdo,int $viewerId,string $publicId): array
{
    if($viewerId<1||trim($publicId)==='')throw new RuntimeException('Reward transfer authority is unavailable.');
    $tray=campaigns_rewards_reward_tray_v110($pdo,$viewerId);
    foreach((array)($tray['inbox']??[]) as $row){
        if(!is_array($row)||!hash_equals((string)($row['public_id']??''),$publicId))continue;
        $issuanceId=(int)($row['id']??0);
        if($issuanceId<1)break;
        return campaigns_rewards_reward_holder_v110($pdo,$issuanceId,$viewerId,false);
    }
    throw new RuntimeException('Reward is not available in your Inbox for transfer.');
}

function vp3_profile_webmcp_reward_transfer_contact_v182(PDO $pdo,int $viewerId,int $contactId): array
{
    if($contactId<1)throw new RuntimeException('Choose a VP3 CRM contact.');
    foreach(campaigns_rewards_send_contacts_v110($pdo,$viewerId) as $row){
        if((int)($row['id']??0)===$contactId)return $row;
    }
    throw new RuntimeException('Choose a Contact from your VP3 CRM.');
}

function vp3_profile_webmcp_reward_transfer_state_hash_v182(array $holder,array $contact): string
{
    return vp3_profile_webmcp_payload_hash_v150([
        'reward'=>[
            'id'=>(int)$holder['id'],'public_id'=>(string)$holder['public_id'],'status'=>(string)$holder['status'],
            'remaining_quantity'=>(int)$holder['remaining_quantity'],'expires_at'=>(string)($holder['expires_at']??''),
            'recipient_contact_id'=>(int)$holder['recipient_contact_id'],'recipient_user_id'=>(int)($holder['recipient_user_id']??0),
            'terms_hash'=>hash('sha256',(string)($holder['terms_snapshot_json']??'')),
            'updated_at'=>(string)($holder['updated_at']??''),
        ],
        'contact'=>[
            'id'=>(int)$contact['id'],'name'=>(string)($contact['name']??''),
            'email'=>strtolower(trim((string)($contact['email']??''))),
            'vp3_user_id'=>max(0,(int)($contact['vp3_user_id']??0)),
        ],
    ]);
}

function vp3_profile_webmcp_rewards_context_v182(array $profile,?array $viewer,array $telemetry): array
{
    $viewerId=(int)($viewer['id']??0);$username=(string)($profile['username']??'');
    $session=vp3_profile_webmcp_transport_id_v130((string)($telemetry['webmcp_session_id']??''));
    if($viewerId<1||$username===''||$session==='')throw new RuntimeException('Reward transfer session is unavailable.');
    return [
        'owner_user_id'=>$viewerId,'profile_username'=>$username,'surface'=>'native_profile','property_id'=>null,
        'session_hash'=>hash('sha256',$session),'origin_hash'=>'',
        'secret'=>hash_hmac('sha256','rewards-transfer-v182',vp3_profile_webmcp_native_signing_secret_v150($viewerId),true),
    ];
}

function vp3_profile_webmcp_rewards_b64url_encode_v182(string $value): string
{
    return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
}

function vp3_profile_webmcp_rewards_b64url_decode_v182(string $value): string
{
    if(!preg_match('/^[A-Za-z0-9_-]+$/',$value))return '';
    $pad=(4-(strlen($value)%4))%4;
    $decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',$pad),true);
    return is_string($decoded)?$decoded:'';
}

function vp3_profile_webmcp_rewards_token_v182(array $action,array $context): string
{
    $payload=[
        'v'=>1,'owner_user_id'=>(int)$action['owner_user_id'],'profile_username'=>(string)$action['profile_username'],
        'surface'=>(string)$action['surface'],'session_hash'=>(string)$action['session_hash'],
        'intent_id'=>(string)$action['intent_id'],'operation'=>(string)$action['operation'],
        'payload_hash'=>(string)$action['payload_hash'],'exp'=>(int)$action['expires_at_unix'],
    ];
    $encoded=vp3_profile_webmcp_rewards_b64url_encode_v182(json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return $encoded.'.'.vp3_profile_webmcp_rewards_b64url_encode_v182(hash_hmac('sha256',$encoded,(string)$context['secret'],true));
}

function vp3_profile_webmcp_rewards_token_verify_v182(string $token,array $context,string $operation,array $intent): array
{
    $parts=explode('.',$token);if(count($parts)!==2)throw new RuntimeException('Reward transfer confirmation token is invalid.');
    [$encoded,$sigEncoded]=$parts;
    $sig=vp3_profile_webmcp_rewards_b64url_decode_v182($sigEncoded);
    $expected=hash_hmac('sha256',$encoded,(string)$context['secret'],true);
    if($sig===''||!hash_equals(vp3_profile_webmcp_rewards_b64url_encode_v182($sig),$sigEncoded)||!hash_equals($expected,$sig)){
        throw new RuntimeException('Reward transfer confirmation token is invalid.');
    }
    $json=vp3_profile_webmcp_rewards_b64url_decode_v182($encoded);$payload=$json!==''?json_decode($json,true):null;
    if(!is_array($payload)||($payload['v']??null)!==1||(int)($payload['exp']??0)<time())throw new RuntimeException('Reward transfer confirmation expired. Prepare the transfer again.');
    $checks=[
        (int)($payload['owner_user_id']??0)===(int)$context['owner_user_id'],
        hash_equals((string)($payload['profile_username']??''),(string)$context['profile_username']),
        hash_equals((string)($payload['surface']??''),'native_profile'),
        hash_equals((string)($payload['session_hash']??''),(string)$context['session_hash']),
        hash_equals((string)($payload['operation']??''),$operation),
        hash_equals((string)($payload['payload_hash']??''),vp3_profile_webmcp_payload_hash_v150($intent)),
    ];
    if(in_array(false,$checks,true)||!preg_match('/^[a-f0-9]{32}$/',(string)($payload['intent_id']??'')))throw new RuntimeException('Reward transfer confirmation does not match this session or action.');
    return $payload;
}

function vp3_profile_webmcp_reward_transfer_prepare_v182(PDO $pdo,array $profile,?array $viewer,array $context,array $input): array
{
    $viewerId=(int)($viewer['id']??0);
    if($viewerId<1)throw new RuntimeException('Sign in to send Rewards.');
    $publicId=trim((string)($input['reward_public_id']??''));
    $contactId=max(0,(int)($input['target_contact_id']??0));
    $holder=vp3_profile_webmcp_reward_raw_holder_v182($pdo,$viewerId,$publicId);
    $contact=vp3_profile_webmcp_reward_transfer_contact_v182($pdo,$viewerId,$contactId);
    $terms=json_decode((string)($holder['terms_snapshot_json']??''),true);if(!is_array($terms))$terms=[];
    $transferable=!empty($terms['transferable'])||!empty($terms['regiftable'])||!empty($holder['product_transferable'])||!empty($holder['product_regiftable']);
    if(!$transferable)throw new RuntimeException('This Reward is marked non-transferable by the issuing Merchant.');
    if(!in_array((string)$holder['status'],['issued','sent','viewed'],true)||(int)$holder['remaining_quantity']<1)throw new RuntimeException('This Reward can no longer be sent.');
    if(!empty($holder['expires_at'])&&strtotime((string)$holder['expires_at'])<=time())throw new RuntimeException('This Reward has expired.');
    $intent=[
        'reward_public_id'=>$publicId,'target_contact_id'=>$contactId,
        'note'=>mb_strimwidth(trim((string)($input['note']??'')),0,500,''),
        'state_hash'=>vp3_profile_webmcp_reward_transfer_state_hash_v182($holder,$contact),
    ];
    $action=vp3_profile_webmcp_action_prepare_v150($pdo,$context,'reward.transfer',$intent,600);
    $safeTerms=json_decode((string)($holder['terms_snapshot_json']??''),true);if(!is_array($safeTerms))$safeTerms=[];
    return [
        'intent_id'=>$action['intent_id'],'confirmation_token'=>vp3_profile_webmcp_rewards_token_v182($action,$context),
        'expires_at_unix'=>$action['expires_at_unix'],'intent'=>$intent,
        'preview'=>[
            'reward'=>vp3_profile_webmcp_reward_row_v180([
                'public_id'=>$publicId,'bucket'=>'inbox','status'=>$holder['status'],'reward_name'=>$holder['reward_name'],
                'merchant_name'=>$holder['merchant_name'],'campaign_name'=>$holder['campaign_name'],
                'value_label'=>(string)($safeTerms['value_label']??''),
                'quantity'=>$holder['remaining_quantity'],'currency'=>$holder['currency'],'face_value_minor'=>$holder['face_value_minor'],
                'transferable'=>true,'expires_at'=>$holder['expires_at'],'issued_at'=>$holder['issued_at'],'sent_at'=>$holder['sent_at']??'','claimed_at'=>'',
            ]),
            'target'=>['contact_id'=>$contactId,'name'=>(string)($contact['name']??$contact['email']),'email'=>(string)$contact['email']],
            'note'=>$intent['note'],
        ],
        'confirmation_required'=>true,
    ];
}

function vp3_profile_webmcp_reward_transfer_projection_v182(PDO $pdo,int $viewerId,int $transferId): array
{
    $stmt=$pdo->prepare("SELECT t.id,t.public_id,t.status,t.delivery_mode,t.transferred_at,t.note,
      ri.public_id reward_public_id,rp.name reward_name,m.name merchant_name,c.name campaign_name,
      target.name target_name,target.email target_email
      FROM reward_transfers t
      INNER JOIN reward_issuances ri ON ri.id=t.reward_issuance_id
      INNER JOIN reward_products rp ON rp.id=ri.reward_product_id
      INNER JOIN merchant_accounts m ON m.id=t.merchant_id
      INNER JOIN campaigns c ON c.id=ri.campaign_id
      INNER JOIN crm_contacts target ON target.id=t.to_contact_id
      WHERE t.id=? AND t.sent_by_user_id=? LIMIT 1");
    $stmt->execute([$transferId,$viewerId]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Reward transfer result is unavailable.');
    return [
        'transfer'=>[
            'public_id'=>(string)$row['public_id'],'status'=>(string)$row['status'],
            'reward_public_id'=>(string)$row['reward_public_id'],'reward_name'=>(string)$row['reward_name'],
            'merchant_name'=>(string)$row['merchant_name'],'campaign_name'=>(string)$row['campaign_name'],
            'target_name'=>(string)$row['target_name'],'target_email'=>(string)$row['target_email'],
            'delivery_mode'=>(string)$row['delivery_mode'],'transferred_at'=>(string)$row['transferred_at'],
            'note'=>(string)($row['note']??''),
        ],
    ];
}

function vp3_profile_webmcp_reward_transfer_confirm_v182(PDO $pdo,array $profile,?array $viewer,array $context,array $intent,string $token,string $idempotencyKey): array
{
    $operation='reward.transfer';$viewerId=(int)($viewer['id']??0);
    if($viewerId<1)throw new RuntimeException('Sign in to send Rewards.');
    $verified=vp3_profile_webmcp_rewards_token_verify_v182($token,$context,$operation,$intent);
    $intentId=(string)$verified['intent_id'];
    $idem=vp3_profile_webmcp_idempotency_hash_v150($viewerId,$operation,$idempotencyKey);
    $lock='vp3_webmcp_idem_'.substr($idem,0,32);
    $lockStmt=$pdo->prepare('SELECT GET_LOCK(?,5)');$lockStmt->execute([$lock]);
    if((int)$lockStmt->fetchColumn()!==1)throw new RuntimeException('That Reward transfer is already in progress. Retry with the same idempotency key.');
    $started=!$pdo->inTransaction();
    try{
        if($started)$pdo->beginTransaction();
        $action=vp3_profile_webmcp_action_row_v150($pdo,$viewerId,$intentId,true);
        if(!$action||(string)$action['operation']!==$operation)throw new RuntimeException('Prepared Reward transfer was not found.');
        if(!hash_equals((string)$action['profile_username'],(string)$context['profile_username'])||!hash_equals((string)$action['session_hash'],(string)$context['session_hash'])||(string)$action['surface']!=='native_profile'){
            throw new RuntimeException('Prepared Reward transfer belongs to a different WebMCP session.');
        }
        if(!hash_equals((string)$action['payload_hash'],vp3_profile_webmcp_payload_hash_v150($intent)))throw new RuntimeException('Prepared Reward transfer payload changed.');
        if((string)$action['state']==='committed'){
            if(!hash_equals((string)$action['idempotency_hash'],$idem))throw new RuntimeException('This Reward transfer was already confirmed with a different idempotency key.');
            $result=vp3_profile_webmcp_reward_transfer_projection_v182($pdo,$viewerId,(int)$action['result_id']);
            $result['idempotent_replay']=true;if($started)$pdo->commit();return $result;
        }
        if((string)$action['state']!=='prepared')throw new RuntimeException('Prepared Reward transfer is no longer executable.');
        if((new DateTimeImmutable((string)$action['expires_at'],new DateTimeZone('UTC')))->getTimestamp()<time())throw new RuntimeException('Reward transfer confirmation expired. Prepare the transfer again.');
        $existing=vp3_profile_webmcp_action_by_idempotency_v150($pdo,$viewerId,$operation,$idem,true);
        if($existing&&(int)$existing['id']!==(int)$action['id']){
            if(!hash_equals((string)$existing['payload_hash'],(string)$action['payload_hash']))throw new RuntimeException('Idempotency key was already used for a different Reward transfer.');
            if((string)$existing['state']!=='committed')throw new RuntimeException('That Reward transfer is still in progress.');
            $result=vp3_profile_webmcp_reward_transfer_projection_v182($pdo,$viewerId,(int)$existing['result_id']);
            $result['idempotent_replay']=true;if($started)$pdo->commit();return $result;
        }
        $holder=vp3_profile_webmcp_reward_raw_holder_v182($pdo,$viewerId,(string)$intent['reward_public_id']);
        $contact=vp3_profile_webmcp_reward_transfer_contact_v182($pdo,$viewerId,(int)$intent['target_contact_id']);
        if(!hash_equals((string)$intent['state_hash'],vp3_profile_webmcp_reward_transfer_state_hash_v182($holder,$contact)))throw new RuntimeException('Reward or target contact changed. Prepare the transfer again.');
        $claim=$pdo->prepare("UPDATE profile_webmcp_actions SET idempotency_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND state='prepared' AND idempotency_hash IS NULL");
        $claim->execute([$idem,(int)$action['id']]);
        if($claim->rowCount()!==1&&!hash_equals((string)($action['idempotency_hash']??''),$idem))throw new RuntimeException('Reward transfer idempotency claim changed.');
        $canonical=campaigns_rewards_transfer_reward_v110($pdo,(int)$holder['id'],$viewerId,(int)$intent['target_contact_id'],(string)$intent['note'],'webmcp-v182:'.$idem);
        $transferId=(int)($canonical['id']??0);if($transferId<1)throw new RuntimeException('Canonical Reward transfer did not return a result.');
        $result=vp3_profile_webmcp_reward_transfer_projection_v182($pdo,$viewerId,$transferId);
        vp3_profile_webmcp_action_commit_v150($pdo,(int)$action['id'],$idem,'reward_transfer',$transferId,$result);
        if($started)$pdo->commit();return $result;
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }finally{
        try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lock]);}catch(Throwable $ignored){}
    }
}
