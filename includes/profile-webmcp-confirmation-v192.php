<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_CONFIRMATION_V192='profile-webmcp-confirmation-v192-20260929';
const VP3_PROFILE_WEBMCP_ACTION_CONTRACT_V192='vp3.webmcp.action.v1';

function vp3_profile_webmcp_action_pairs_v192(): array
{
    return [
        'vp3.booking.prepare'=>'vp3.booking.confirm',
        'vp3.booking.reschedule.prepare'=>'vp3.booking.reschedule.confirm',
        'vp3.booking.cancel.prepare'=>'vp3.booking.cancel.confirm',
        'vp3.commerce.checkout.prepare'=>'vp3.commerce.checkout.confirm',
        'vp3.commerce.refund.prepare'=>'vp3.commerce.refund.confirm',
        'vp3.campaign.participation.prepare'=>'vp3.campaign.participation.confirm',
        'vp3.rewards.claim.prepare'=>'vp3.rewards.claim.confirm',
        'vp3.rewards.transfer.prepare'=>'vp3.rewards.transfer.confirm',
    ];
}

function vp3_profile_webmcp_action_labels_v192(): array
{
    return [
        'vp3.booking.prepare'=>'Review booking',
        'vp3.booking.reschedule.prepare'=>'Review reschedule',
        'vp3.booking.cancel.prepare'=>'Review cancellation',
        'vp3.commerce.checkout.prepare'=>'Review checkout',
        'vp3.commerce.refund.prepare'=>'Review refund request',
        'vp3.campaign.participation.prepare'=>'Review Campaign participation',
        'vp3.rewards.claim.prepare'=>'Review Reward claim handoff',
        'vp3.rewards.transfer.prepare'=>'Review Reward transfer',
    ];
}

function vp3_profile_webmcp_action_prepare_for_confirm_v192(string $tool): string
{
    foreach(vp3_profile_webmcp_action_pairs_v192() as $prepare=>$confirm){
        if(hash_equals($confirm,$tool))return $prepare;
    }
    return '';
}

function vp3_profile_webmcp_action_envelope_v192(string $tool,array $payload): array
{
    $pairs=vp3_profile_webmcp_action_pairs_v192();
    $confirm=$pairs[$tool]??'';
    $prepare=$confirm!==''?$tool:vp3_profile_webmcp_action_prepare_for_confirm_v192($tool);
    if($prepare==='')return $payload;

    $labels=vp3_profile_webmcp_action_labels_v192();
    $isPrepare=$confirm!=='';
    if($isPrepare){
        $required=!empty($payload['confirmation_required']);
        $needsInput=!$required&&!empty($payload['provider_selection_required']);
        $expires=max(0,(int)($payload['expires_at_unix']??0));
        $action=[
            'contract'=>VP3_PROFILE_WEBMCP_ACTION_CONTRACT_V192,
            'phase'=>$required?'prepared':($needsInput?'needs_input':'completed'),
            'prepare_tool'=>$tool,
            'confirm_tool'=>$confirm,
            'title'=>(string)($labels[$tool]??'Review action'),
            'requires_confirmation'=>$required,
            'intent_id'=>(string)($payload['intent_id']??''),
            'expires_at_unix'=>$expires?:null,
            'expires_at_utc'=>$expires>0?gmdate('c',$expires):null,
            'preview'=>is_array($payload['preview']??null)?$payload['preview']:[],
            'idempotency'=>[
                'required'=>$required,
                'reuse_same_key_on_retry'=>true,
                'minimum_length'=>8,
                'maximum_length'=>96,
            ],
            'confirmation'=>[
                'token'=>$required?(string)($payload['confirmation_token']??''):'',
                'intent'=>$required&&is_array($payload['intent']??null)?$payload['intent']:[],
                'requires_terms_acceptance'=>$tool==='vp3.commerce.checkout.prepare',
            ],
            'reprepare_required'=>false,
        ];
        $payload['action']=$action;
        return $payload;
    }

    $payload['action']=[
        'contract'=>VP3_PROFILE_WEBMCP_ACTION_CONTRACT_V192,
        'phase'=>'completed',
        'prepare_tool'=>$prepare,
        'confirm_tool'=>$tool,
        'title'=>(string)($labels[$prepare]??'Action completed'),
        'requires_confirmation'=>false,
        'idempotent_replay'=>!empty($payload['idempotent_replay']),
        'reprepare_required'=>false,
    ];
    return $payload;
}

function vp3_profile_webmcp_action_error_v192(Throwable $error,string $fallbackMessage): array
{
    $message=$error instanceof RuntimeException?trim($error->getMessage()):trim($fallbackMessage);
    if($message==='')$message=$fallbackMessage;
    $lower=mb_strtolower($message);

    $code='VALIDATION_FAILED';
    $retryable=false;
    $phase='error';
    $reprepare=false;
    $reuseSameKey=false;

    if(str_contains($lower,'expired')){
        $code='CONFIRMATION_EXPIRED';
        $phase='expired';
        $reprepare=true;
    }elseif(str_contains($lower,'already in progress')||str_contains($lower,'still in progress')){
        $code='ACTION_IN_PROGRESS';
        $retryable=true;
        $phase='confirming';
        $reuseSameKey=true;
    }elseif(str_contains($lower,'idempotency')){
        $code='IDEMPOTENCY_CONFLICT';
        $phase='conflict';
    }elseif(
        str_contains($lower,'does not match this session or action')||
        str_contains($lower,'different webmcp session')||
        str_contains($lower,'different viewer')||
        str_contains($lower,'belongs to a different')
    ){
        $code='CONFIRMATION_MISMATCH';
        $phase='conflict';
        $reprepare=true;
    }elseif(str_contains($lower,'confirmation ledger is unavailable')){
        $code='ACTION_UNAVAILABLE';
        $retryable=true;
    }

    return [
        'status'=>422,
        'result_code'=>$code,
        'payload'=>[
            'error'=>[
                'code'=>$code,
                'message'=>$message,
                'retryable'=>$retryable,
            ],
            'action'=>[
                'contract'=>VP3_PROFILE_WEBMCP_ACTION_CONTRACT_V192,
                'phase'=>$phase,
                'reprepare_required'=>$reprepare,
                'reuse_same_idempotency_key'=>$reuseSameKey,
            ],
        ],
    ];
}

function vp3_profile_webmcp_action_respond_v192(
    callable $respond,string $tool,bool $ok,array $payload=[],int $status=200,string $resultCode=''
): never {
    $respond($ok,$ok?vp3_profile_webmcp_action_envelope_v192($tool,$payload):$payload,$status,$resultCode);
}
