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

/** Presentation of a canonical response; transport success is not payment or fulfillment. */
function vp3_profile_webmcp_confirmation_outcome_v192(string $tool,array $payload): array
{
    $operations=[
        'vp3.booking.confirm'=>'booking.create','vp3.booking.reschedule.confirm'=>'booking.reschedule',
        'vp3.booking.cancel.confirm'=>'booking.cancel','vp3.commerce.checkout.confirm'=>'commerce.checkout',
        'vp3.commerce.refund.confirm'=>'commerce.refund_request','vp3.campaign.participation.confirm'=>'campaign.participate',
        'vp3.rewards.claim.confirm'=>'reward.claim_handoff','vp3.rewards.transfer.confirm'=>'reward.transfer',
    ];
    $operation=$operations[$tool]??'';
    $result=static fn(string $code,string $title,string $message):array=>[
        'contract'=>'vp3.webmcp.confirmation-result.v1','operation'=>$operation,
        'code'=>$code,'title'=>$title,'message'=>$message,
    ];
    $unverified=$result('outcome_unverified','Result unavailable','The request returned, but its saved result could not be verified. Check the existing action before starting another.');
    $text=static fn(mixed $value,int $max=190):bool=>is_string($value)&&trim($value)!==''&&strlen($value)<=$max;
    if($operation==='commerce.checkout'){
        $order=$payload['order']??null;
        if(!is_array($order)||!$text($order['order_number']??null,80))return $unverified;
        $payment=$order['payment_status']??null;
        if(!is_string($payment))return $unverified;
        if(($order['review_required']??false)===true&&in_array($payment,['paid','partially_paid'],true)){
            return $result('payment_review','Payment received; review required',
                ($payment==='paid'?'Your payment is verified. ':'A partial payment is verified. ').'The seller must review fulfillment before this order can proceed.');
        }
        if($payment==='refunded')return $result('refunded','Refunded','The order has been refunded.');
        if($payment==='partially_refunded')return $result('partially_refunded','Partially refunded','Part of the order payment has been refunded. Check the receipt for the remaining balance.');
        if($payment==='paid'){
            return match($order['fulfillment_status']??''){
                'fulfilled'=>$result('fulfilled','Order fulfilled','Payment is verified and the seller has marked the order fulfilled.'),
                'processing'=>$result('processing','Order processing','Payment is verified and fulfillment is processing.'),
                default=>$result('paid','Payment verified','Payment is verified. Fulfillment has not been confirmed complete.'),
            };
        }
        if(in_array($order['order_status']??'',['expired','cancelled'],true)){
            $expired=$order['order_status']==='expired';
            return $result($expired?'order_expired':'order_cancelled',$expired?'Order expired':'Order cancelled',
                'This order is closed. Check the receipt or contact the seller before making another payment.');
        }
        if($payment==='partially_paid')return $result('partially_paid','Partial payment received','A partial payment is verified. Check the receipt for the outstanding balance.');
        if($payment==='awaiting_payment')return $result('payment_pending','Payment pending','Your order is recorded. Complete payment with the selected provider; payment has not been verified yet.');
        if($payment==='failed')return $result('payment_failed','Payment unsuccessful','The payment was not completed. Check the existing receipt before trying again.');
        return $unverified;
    }
    if(str_starts_with($operation,'booking.')){
        $booking=$payload['booking']??null;
        if(!is_array($booking)||!is_int($booking['booking_id']??null)||$booking['booking_id']<1||!in_array($booking['status']??null,['pending','confirmed','cancelled'],true))return $unverified;
        if($operation==='booking.cancel')return $booking['status']==='cancelled'
            ?$result('booking_cancelled','Booking cancelled','The booking is cancelled. Any refund has a separate payment status.'):$unverified;
        if($booking['status']==='cancelled')return $result('booking_cancelled','Booking cancelled','The recorded booking is now cancelled. Check its current details before making another booking.');
        if(!is_bool($payload['payment_required']??null))return $unverified;
        $message=$operation==='booking.reschedule'?'The booking time was updated. ':'The booking was recorded. ';
        if($payload['payment_required']&&($payload['payment_status']??'')!=='paid'){
            return $result('booking_payment_pending','Booking recorded; payment required',$message.'Check the booking payment page to finish payment.');
        }
        return $result($operation==='booking.reschedule'?'booking_rescheduled':'booking_created',
            $operation==='booking.reschedule'?'Booking rescheduled':'Booking recorded',
            $message.($booking['status']==='pending'?'The booking is awaiting confirmation.':'The booking is confirmed.'));
    }
    if($operation==='commerce.refund_request'){
        $request=$payload['refund_request']??null;
        if(!is_array($request)||!$text($payload['order_number']??null,80)||!$text($request['request_id']??null,100))return $unverified;
        return match($request['status']??null){
            'pending'=>$result('refund_requested','Refund request submitted','Your refund request is awaiting seller review. This request has not refunded the payment.'),
            'seller_refund_submitted'=>$result('refund_submitted','Refund submitted by seller','The seller has submitted a refund. Check the receipt for provider-confirmed refund status.'),
            'declined'=>$result('refund_declined','Refund request declined','The seller declined this refund request.'),
            default=>$unverified,
        };
    }
    if($operation==='campaign.participate'&&is_array($payload['participation']??null)){
        $state=$payload['lifecycle']['state']??null;
        if(!in_array($state,['enrolled','awaiting_verification','awaiting_trigger','completed','cancelled','failed','disqualified','expired'],true))return $unverified;
        return $result('campaign_recorded','Campaign participation recorded',match($state){
            'completed'=>'Campaign participation is complete. Reward delivery has a separate status.',
            'awaiting_verification'=>'Participation is recorded and awaiting verification.',
            'awaiting_trigger'=>'Participation is recorded and awaiting the campaign trigger.',
            'cancelled','failed','disqualified','expired'=>'Participation is recorded with status: '.$state.'.',
            default=>'Participation is recorded. Campaign requirements and reward delivery may need further steps.',
        });
    }
    if($operation==='reward.claim_handoff'&&($payload['reward_redeemed']??null)===false
       &&($payload['redemption_requires_merchant_operator']??null)===true&&$text($payload['claim_handoff_url']??null,4096)){
        return $result('reward_claim_handoff','Reward claim ready','Open the Reward Inbox to continue. Redemption requires the merchant operator; this action did not redeem the Reward.');
    }
    if($operation==='reward.transfer'&&($payload['status']??null)==='sent'&&$text($payload['transfer_public_id']??null,100)){
        return $result('reward_transferred','Reward transfer recorded','The Reward transfer is recorded as sent. Recipient delivery and redemption have separate status.');
    }
    return $unverified;
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
        $expires=max(0,(int)($payload['expires_at_unix']??0));
        $action=[
            'contract'=>VP3_PROFILE_WEBMCP_ACTION_CONTRACT_V192,
            'phase'=>$required?'prepared':'needs_input',
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

    $outcome=vp3_profile_webmcp_confirmation_outcome_v192($tool,$payload);
    $payload['action']=[
        'contract'=>VP3_PROFILE_WEBMCP_ACTION_CONTRACT_V192,
        'phase'=>$outcome['code']==='outcome_unverified'?'unverified':'recorded',
        'prepare_tool'=>$prepare,
        'confirm_tool'=>$tool,
        'title'=>(string)($labels[$prepare]??'Action completed'),
        'requires_confirmation'=>false,
        'idempotent_replay'=>($payload['idempotent_replay']??false)===true,
        'outcome'=>$outcome,
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
    }elseif(str_contains($lower,'prepare')&&str_contains($lower,'again')){
        $code='CONFIRMATION_CHANGED';
        $phase='conflict';
        $reprepare=true;
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
