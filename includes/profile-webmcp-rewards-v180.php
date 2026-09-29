<?php
declare(strict_types=1);

require_once __DIR__.'/campaigns-rewards-v110.php';

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
