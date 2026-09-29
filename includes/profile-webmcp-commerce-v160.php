<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-v900.php';
require_once __DIR__.'/profile-commerce-checkout-v900.php';
require_once __DIR__.'/profile-commerce-lifecycle-v1100.php';
require_once __DIR__.'/profile-commerce-delivery-v1120.php';
require_once __DIR__.'/profile-commerce-delivery-file-v1130.php';
require_once __DIR__.'/profile-webmcp-scheduling-v150.php';

const VP3_PROFILE_WEBMCP_COMMERCE_V160='profile-webmcp-commerce-v160-20260928';
const VP3_PROFILE_WEBMCP_COMMERCE_INTENT_TTL_V160=600;

function vp3_profile_webmcp_commerce_tool_catalog_v160(): array
{
    $confirm=[
        'type'=>'object',
        'properties'=>[
            'confirmation_token'=>['type'=>'string','minLength'=>20,'maxLength'=>4096],
            'idempotency_key'=>['type'=>'string','minLength'=>8,'maxLength'=>96],
            'intent'=>['type'=>'object','additionalProperties'=>true],
        ],
        'required'=>['confirmation_token','idempotency_key','intent'],
        'additionalProperties'=>false,
    ];
    return [
        'vp3.commerce.products.list'=>[
            'title'=>'List public products','description'=>'List canonical public Profile Commerce products.',
            'capability'=>'commerce','input_schema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.commerce.product.get'=>[
            'title'=>'Get public product','description'=>'Return one public product, current seller terms, and safe payment-provider choices.',
            'capability'=>'commerce','input_schema'=>['type'=>'object','properties'=>['product_slug'=>['type'=>'string','minLength'=>1,'maxLength'=>80]],'required'=>['product_slug'],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.commerce.checkout.prepare'=>[
            'title'=>'Prepare commerce checkout','description'=>'Validate the canonical product, price, seller terms, payer email, and payment provider without creating an order.',
            'capability'=>'commerce','input_schema'=>[
                'type'=>'object','properties'=>[
                    'product_slug'=>['type'=>'string','minLength'=>1,'maxLength'=>80],
                    'payer_email'=>['type'=>'string','minLength'=>3,'maxLength'=>190],
                    'connection_id'=>['type'=>'integer','minimum'=>1],
                ],
                'required'=>['product_slug','payer_email'],'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.commerce.checkout.confirm'=>[
            'title'=>'Confirm commerce checkout','description'=>'Create the exact prepared canonical order and hosted provider checkout. This does not mark payment paid.',
            'capability'=>'commerce','input_schema'=>array_replace_recursive($confirm,['properties'=>['terms_accepted'=>['type'=>'boolean']]+$confirm['properties'],'required'=>['confirmation_token','idempotency_key','intent','terms_accepted']]),
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>true,'debugging'=>false],
        ],
        'vp3.commerce.order.get'=>[
            'title'=>'Get commerce order','description'=>'Return the customer-safe canonical order projection using receipt authority.',
            'capability'=>'commerce','input_schema'=>vp3_profile_webmcp_commerce_receipt_schema_v160(),
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.commerce.receipt.get'=>[
            'title'=>'Get commerce receipt','description'=>'Return the customer-safe receipt projection and receipt URL using receipt authority.',
            'capability'=>'commerce','input_schema'=>vp3_profile_webmcp_commerce_receipt_schema_v160(),
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.commerce.delivery.get'=>[
            'title'=>'Get fulfillment status','description'=>'Return fulfillment state and whether private delivery is available, without embedding private delivery content.',
            'capability'=>'commerce','input_schema'=>vp3_profile_webmcp_commerce_receipt_schema_v160(),
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.commerce.refund.status'=>[
            'title'=>'Get refund eligibility and request status','description'=>'Return refundable balance and the customer refund-request state.',
            'capability'=>'commerce','input_schema'=>vp3_profile_webmcp_commerce_receipt_schema_v160(),
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.commerce.refund.prepare'=>[
            'title'=>'Prepare refund request','description'=>'Validate and preview a seller-reviewed customer refund request. No money moves.',
            'capability'=>'commerce','input_schema'=>[
                'type'=>'object','properties'=>[
                    'order_number'=>['type'=>'string','minLength'=>1,'maxLength'=>80],
                    'receipt_token'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
                    'reason'=>['type'=>'string','minLength'=>3,'maxLength'=>500],
                ],
                'required'=>['order_number','receipt_token','reason'],'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.commerce.refund.confirm'=>[
            'title'=>'Confirm refund request','description'=>'Submit the exact prepared refund request for seller review. This never executes a provider refund.',
            'capability'=>'commerce','input_schema'=>$confirm,
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>true,'debugging'=>false],
        ],
    ];
}

function vp3_profile_webmcp_commerce_receipt_schema_v160(): array
{
    return [
        'type'=>'object',
        'properties'=>[
            'order_number'=>['type'=>'string','minLength'=>1,'maxLength'=>80],
            'receipt_token'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
        ],
        'required'=>['order_number','receipt_token'],
        'additionalProperties'=>false,
    ];
}

function vp3_profile_webmcp_commerce_secret_v160(string $surface,int $ownerUserId,string $nativeProof='',?array $property=null): string
{
    if($surface==='native_profile'){
        return hash_hmac('sha256','commerce-v160',vp3_profile_webmcp_native_signing_secret_v150($ownerUserId),true);
    }
    if($surface==='external_site'&&$property){
        $verification=strtolower(trim((string)($property['verification_token']??'')));
        $publicKey=strtolower(trim((string)($property['public_key']??'')));
        if(empty($property['is_active'])||!preg_match('/^[a-f0-9]{64}$/',$verification)||!preg_match('/^[a-f0-9]{40}$/',$publicKey)){
            throw new RuntimeException('Connected-site commerce authority is unavailable.');
        }
        return hash('sha256','vp3-webmcp-commerce-v160|'.$publicKey.'|'.$verification,true);
    }
    throw new RuntimeException('WebMCP commerce authority is unavailable.');
}

function vp3_profile_webmcp_commerce_context_v160(
    array $profile,string $surface,array $telemetry,string $nativeProof='',?array $property=null,string $origin=''
): array {
    $owner=(int)($profile['user_id']??0);
    $username=(string)($profile['username']??'');
    $session=vp3_profile_webmcp_transport_id_v130((string)($telemetry['webmcp_session_id']??''));
    if($owner<1||$username===''||$session==='')throw new RuntimeException('WebMCP commerce session is unavailable.');
    $propertyId=$surface==='external_site'?(int)($property['id']??0):0;
    if($surface==='external_site'&&($propertyId<1||$origin===''))throw new RuntimeException('Connected-site commerce session is unavailable.');
    return [
        'owner_user_id'=>$owner,'profile_username'=>$username,'surface'=>$surface,'property_id'=>$propertyId,
        'session_hash'=>hash('sha256',$session),'origin_hash'=>$surface==='external_site'?hash('sha256',$origin):'',
        'secret'=>vp3_profile_webmcp_commerce_secret_v160($surface,$owner,$nativeProof,$property),
    ];
}

function vp3_profile_webmcp_commerce_token_v160(array $action,array $context): string
{
    $payload=[
        'v'=>1,'owner_user_id'=>(int)$action['owner_user_id'],'profile_username'=>(string)$action['profile_username'],
        'surface'=>(string)$action['surface'],'property_id'=>(int)($action['property_id']??0),
        'session_hash'=>(string)$action['session_hash'],'origin_hash'=>(string)($context['origin_hash']??''),
        'intent_id'=>(string)$action['intent_id'],'operation'=>(string)$action['operation'],
        'payload_hash'=>(string)$action['payload_hash'],'exp'=>(int)$action['expires_at_unix'],
    ];
    $encoded=vp3_profile_webmcp_b64url_encode_v140(json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $sig=hash_hmac('sha256',$encoded,(string)$context['secret'],true);
    return $encoded.'.'.vp3_profile_webmcp_b64url_encode_v140($sig);
}

function vp3_profile_webmcp_commerce_token_verify_v160(string $token,array $context,string $operation,array $intent): array
{
    $parts=explode('.',$token);if(count($parts)!==2)throw new RuntimeException('Commerce confirmation token is invalid.');
    [$encoded,$sigEncoded]=$parts;$sig=vp3_profile_webmcp_b64url_decode_v140($sigEncoded);
    $expected=hash_hmac('sha256',$encoded,(string)$context['secret'],true);
    if($sig===''||!hash_equals($expected,$sig))throw new RuntimeException('Commerce confirmation token is invalid.');
    $json=vp3_profile_webmcp_b64url_decode_v140($encoded);$payload=$json!==''?json_decode($json,true):null;
    if(!is_array($payload)||($payload['v']??null)!==1)throw new RuntimeException('Commerce confirmation token is invalid.');
    if((int)($payload['exp']??0)<time())throw new RuntimeException('Commerce confirmation expired. Prepare the action again.');
    $checks=[
        (int)($payload['owner_user_id']??0)===(int)$context['owner_user_id'],
        hash_equals((string)($payload['profile_username']??''),(string)$context['profile_username']),
        hash_equals((string)($payload['surface']??''),(string)$context['surface']),
        (int)($payload['property_id']??0)===(int)($context['property_id']??0),
        hash_equals((string)($payload['session_hash']??''),(string)$context['session_hash']),
        hash_equals((string)($payload['origin_hash']??''),(string)($context['origin_hash']??'')),
        hash_equals((string)($payload['operation']??''),$operation),
        hash_equals((string)($payload['payload_hash']??''),vp3_profile_webmcp_payload_hash_v150($intent)),
    ];
    if(in_array(false,$checks,true))throw new RuntimeException('Commerce confirmation does not match this session or action.');
    if(!preg_match('/^[a-f0-9]{32}$/',(string)($payload['intent_id']??'')))throw new RuntimeException('Commerce confirmation token is invalid.');
    return $payload;
}

function vp3_profile_webmcp_commerce_public_product_v160(PDO $pdo,array $profile,string $slug): array
{
    $projection=profile_commerce_public_product_v900($pdo,$profile,$slug);
    if(!$projection)throw new RuntimeException('This product is not available.');
    $row=profile_commerce_owner_product_v900($pdo,(int)$profile['user_id'],(int)$projection['id']);
    if(!$row||empty($row['is_active'])||profile_commerce_visibility_v900($row)!=='public')throw new RuntimeException('This product is not available.');
    return ['projection'=>$projection,'row'=>$row];
}

function vp3_profile_webmcp_commerce_provider_projection_v160(array $connection): array
{
    return [
        'connection_id'=>(int)$connection['id'],
        'provider'=>(string)$connection['provider'],
        'provider_label'=>agent_commerce_provider_label_v800((string)$connection['provider']),
    ];
}

function vp3_profile_webmcp_commerce_terms_projection_v160(array $row): array
{
    $terms=agent_commerce_product_terms_v800($row);
    return [
        'payment_mode'=>(string)$terms['payment_mode'],'price_cents'=>(int)$terms['price_cents'],
        'currency'=>(string)$terms['currency'],'hold_minutes'=>(int)$terms['hold_minutes'],
        'refund_before_hours'=>(int)$terms['refund_before_hours'],
        'cancellation_fee_cents'=>(int)$terms['cancellation_fee_cents'],
        'cancellation_policy'=>(string)$terms['cancellation_policy'],
    ];
}

function vp3_profile_webmcp_commerce_product_state_hash_v160(array $row): string
{
    return hash('sha256',json_encode([
        'id'=>(int)$row['id'],'active'=>(int)$row['is_active'],'visibility'=>profile_commerce_visibility_v900($row),
        'payment_mode'=>(string)$row['payment_mode'],'price_cents'=>(int)$row['price_cents'],
        'deposit_cents'=>(int)$row['deposit_cents'],'currency'=>(string)$row['currency'],
        'fulfillment_type'=>(string)$row['fulfillment_type'],'provider_mode'=>(string)$row['provider_mode'],
        'fixed_connection_id'=>(int)($row['fixed_connection_id']??0),
        'terms_digest'=>profile_commerce_terms_digest_v900($row),'updated_at'=>(string)($row['updated_at']??''),
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

function vp3_profile_webmcp_commerce_product_detail_v160(PDO $pdo,array $profile,string $slug): array
{
    $product=vp3_profile_webmcp_commerce_public_product_v160($pdo,$profile,$slug);
    $connections=array_map('vp3_profile_webmcp_commerce_provider_projection_v160',profile_commerce_checkout_connections_v900($pdo,$product['row']));
    $row=$product['row'];
    $checkoutSupported=(string)$row['payment_mode']==='full'
        && (string)($row['fulfillment_type']??'')!=='physical'
        && (string)($row['binding_type']??'')!=='appointment_event_type'
        && count($connections)>0;
    return [
        'product'=>$product['projection'],'terms'=>vp3_profile_webmcp_commerce_terms_projection_v160($row),
        'payment_providers'=>$connections,'checkout_supported'=>$checkoutSupported,
    ];
}

function vp3_profile_webmcp_commerce_products_v160(PDO $pdo,array $profile): array
{
    return profile_commerce_products_for_profile_v900($pdo,$profile,true,80);
}

function vp3_profile_webmcp_commerce_checkout_prepare_v160(PDO $pdo,array $profile,array $context,array $input): array
{
    $product=vp3_profile_webmcp_commerce_public_product_v160($pdo,$profile,(string)($input['product_slug']??''));
    $row=$product['row'];
    if((string)($row['binding_type']??'')==='appointment_event_type')throw new RuntimeException('This offer is booked through Scheduling WebMCP.');
    if((string)$row['payment_mode']!=='full')throw new RuntimeException('This public checkout supports canonical full-payment products only.');
    if((string)($row['fulfillment_type']??'')==='physical')throw new RuntimeException('Shipped physical checkout is unavailable until the shipping-address fulfillment adapter is installed.');
    $payer=strtolower(trim((string)($input['payer_email']??'')));
    if($payer===''||!filter_var($payer,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid receipt email.');

    $connections=profile_commerce_checkout_connections_v900($pdo,$row);
    $providers=array_map('vp3_profile_webmcp_commerce_provider_projection_v160',$connections);
    $wanted=max(0,(int)($input['connection_id']??0));$selected=null;
    if($wanted>0)foreach($connections as $candidate)if((int)$candidate['id']===$wanted){$selected=$candidate;break;}
    if(!$selected&&count($connections)===1)$selected=$connections[0];
    if(!$selected){
        if(!$connections)throw new RuntimeException('No connected payment provider is available for this product.');
        return [
            'provider_selection_required'=>true,'payment_providers'=>$providers,
            'product'=>$product['projection'],'terms'=>vp3_profile_webmcp_commerce_terms_projection_v160($row),
            'confirmation_required'=>false,
        ];
    }

    $intent=[
        'product_id'=>(int)$row['id'],'product_slug'=>(string)$product['projection']['slug'],
        'product_state_hash'=>vp3_profile_webmcp_commerce_product_state_hash_v160($row),
        'terms_digest'=>profile_commerce_terms_digest_v900($row),
        'payer_email'=>mb_strimwidth($payer,0,190,''),
        'connection_id'=>(int)$selected['id'],
        'receipt_token'=>profile_commerce_receipt_token_v1100(),
    ];
    $action=vp3_profile_webmcp_action_prepare_v150($pdo,$context,'commerce.checkout',$intent,VP3_PROFILE_WEBMCP_COMMERCE_INTENT_TTL_V160);
    return [
        'intent_id'=>$action['intent_id'],'confirmation_token'=>vp3_profile_webmcp_commerce_token_v160($action,$context),
        'expires_at_unix'=>$action['expires_at_unix'],'intent'=>$intent,'confirmation_required'=>true,
        'preview'=>[
            'product'=>$product['projection'],'terms'=>vp3_profile_webmcp_commerce_terms_projection_v160($row),
            'payment_provider'=>vp3_profile_webmcp_commerce_provider_projection_v160($selected),
            'payer_email'=>$payer,'seller_terms_acceptance_required'=>true,
        ],
    ];
}

function vp3_profile_webmcp_commerce_validate_action_v160(array $action,array $context,string $operation,array $intent): void
{
    if((string)$action['operation']!==$operation)throw new RuntimeException('Prepared commerce operation changed.');
    if(!hash_equals((string)$action['profile_username'],(string)$context['profile_username']))throw new RuntimeException('Prepared commerce profile changed.');
    if(!hash_equals((string)$action['payload_hash'],vp3_profile_webmcp_payload_hash_v150($intent)))throw new RuntimeException('Prepared commerce payload changed.');
    if(!hash_equals((string)$action['session_hash'],(string)$context['session_hash'])
        ||(string)$action['surface']!==(string)$context['surface']
        ||(int)($action['property_id']??0)!==(int)($context['property_id']??0)){
        throw new RuntimeException('Prepared commerce action belongs to a different WebMCP session.');
    }
    $expires=(new DateTimeImmutable((string)$action['expires_at'],new DateTimeZone('UTC')))->getTimestamp();
    if($expires<time())throw new RuntimeException('Commerce confirmation expired. Prepare the action again.');
}

function vp3_profile_webmcp_commerce_action_complete_v160(PDO $pdo,int $actionId,string $idem,string $resultType,int $resultId,array $safe=[]): void
{
    $stmt=$pdo->prepare("UPDATE profile_webmcp_actions
        SET idempotency_hash=?,state='committed',result_type=?,result_id=?,result_json=?,last_error_code='',committed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
        WHERE id=? AND state IN ('prepared','executing')");
    $stmt->execute([$idem,mb_strimwidth($resultType,0,40,''),$resultId>0?$resultId:null,$safe?json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$actionId]);
    if($stmt->rowCount()!==1)throw new RuntimeException('Commerce action changed before it could be committed.');
}

function vp3_profile_webmcp_commerce_order_response_v160(PDO $pdo,array $profile,array $order,string $receiptToken,bool $includeCheckout=false,?array $attempt=null): array
{
    $orderNumber=(string)$order['order_number'];
    $customer=profile_commerce_customer_order_v1100($pdo,(int)$profile['user_id'],$orderNumber,$receiptToken);
    if(!$customer)throw new RuntimeException('Commerce receipt authority could not be verified.');
    $view=profile_commerce_customer_projection_v1100($customer);
    $out=[
        'order'=>$view,
        'receipt_url'=>profile_commerce_customer_order_url_v1100((string)$profile['username'],$orderNumber,$receiptToken),
    ];
    if($includeCheckout){
        $out['checkout_url']=$attempt?(string)($attempt['checkout_url']??''):'';
        $out['checkout_status']=$attempt?(string)($attempt['status']??''):'';
        $out['receipt_token']=$receiptToken;
    }
    return $out;
}

function vp3_profile_webmcp_commerce_return_grant_v160(array $profile,array $order,string $receiptToken): string
{
    $payload=json_encode([
        'v'=>1,'owner_user_id'=>(int)$profile['user_id'],'profile_username'=>(string)$profile['username'],
        'order_id'=>(int)$order['id'],'receipt_token'=>$receiptToken,'exp'=>time()+7200,
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    return agent_commerce_encrypt_v800($payload);
}

function vp3_profile_webmcp_commerce_checkout_resume_v160(PDO $pdo,array $profile,array $intent,array $action): array
{
    $order=agent_commerce_order_v800($pdo,(int)($action['result_id']??0));
    if(!$order||(int)$order['owner_user_id']!==(int)$profile['user_id'])throw new RuntimeException('Commerce order could not be resumed safely.');
    $receipt=(string)($intent['receipt_token']??'');
    if(!profile_commerce_receipt_token_valid_v1100($receipt))throw new RuntimeException('Commerce receipt authority is invalid.');
    $status=(string)($order['payment_status']??'awaiting_payment');
    if(!in_array($status,['awaiting_payment','partially_paid'],true)){
        return vp3_profile_webmcp_commerce_order_response_v160($pdo,$profile,$order,$receipt,true,null)+['idempotent_replay'=>true];
    }
    $connectionId=(int)($intent['connection_id']??0);
    $returnGrant=vp3_profile_webmcp_commerce_return_grant_v160($profile,$order,$receipt);
    $return=agent_commerce_absolute_url_v800('/profile-webmcp-commerce-return-v160.php?grant='.rawurlencode($returnGrant));
    $cancel=agent_commerce_absolute_url_v800('/'.rawurlencode((string)$profile['username']).'/product/'.rawurlencode((string)$intent['product_slug']).'?commerce=cancelled');
    $attempt=agent_commerce_create_checkout_v800($pdo,$order,$connectionId,$return,$cancel);
    return vp3_profile_webmcp_commerce_order_response_v160($pdo,$profile,$order,$receipt,true,$attempt);
}

function vp3_profile_webmcp_commerce_attribution_metadata_v160(array $telemetryContext): array
{
    $owner=(int)($telemetryContext['owner_user_id']??0);
    $session=(string)($telemetryContext['webmcp_session_id']??'');
    $referralId=(int)($telemetryContext['referral_id']??0);
    return [
        'webmcp_surface'=>(string)($telemetryContext['surface']??''),
        'webmcp_property_id'=>(int)($telemetryContext['property_id']??0),
        'webmcp_agent_contact_id'=>(int)($telemetryContext['agent_contact_id']??0),
        'webmcp_referral_id'=>$referralId,
        'webmcp_referral_session_hash'=>$referralId>0&&$owner>0&&$session!==''?vp3_agent_referral_session_hash($owner,'webmcp-commerce|'.$session):'',
    ];
}

function vp3_profile_webmcp_commerce_checkout_confirm_v160(
    PDO $pdo,array $profile,array $context,array $telemetryContext,array $intent,string $confirmationToken,string $idempotencyKey,bool $termsAccepted
): array {
    if(!$termsAccepted)throw new RuntimeException('Accept the seller terms before checkout.');
    $verified=vp3_profile_webmcp_commerce_token_verify_v160($confirmationToken,$context,'commerce.checkout',$intent);
    $owner=(int)$profile['user_id'];$idem=vp3_profile_webmcp_idempotency_hash_v150($owner,'commerce.checkout',$idempotencyKey);
    $lock='vp3_webmcp_commerce_'.substr($idem,0,32);
    $ls=$pdo->prepare('SELECT GET_LOCK(?,5)');$ls->execute([$lock]);
    if((int)$ls->fetchColumn()!==1)throw new RuntimeException('That checkout confirmation is already in progress. Retry with the same idempotency key.');
    try{
        $pdo->beginTransaction();
        $action=vp3_profile_webmcp_action_row_v150($pdo,$owner,(string)$verified['intent_id'],true);
        if(!$action)throw new RuntimeException('Prepared checkout was not found.');
        vp3_profile_webmcp_commerce_validate_action_v160($action,$context,'commerce.checkout',$intent);
        if((string)$action['state']==='committed'){
            if(!hash_equals((string)$action['idempotency_hash'],$idem))throw new RuntimeException('This checkout intent was already confirmed with a different idempotency key.');
            $pdo->commit();
            $out=vp3_profile_webmcp_commerce_checkout_resume_v160($pdo,$profile,$intent,$action);$out['idempotent_replay']=true;return $out;
        }
        if((string)$action['state']==='executing'){
            if(!hash_equals((string)$action['idempotency_hash'],$idem))throw new RuntimeException('This checkout is already executing under a different idempotency key.');
            $pdo->commit();
            $out=vp3_profile_webmcp_commerce_checkout_resume_v160($pdo,$profile,$intent,$action);
            vp3_profile_webmcp_commerce_action_complete_v160($pdo,(int)$action['id'],$idem,'order',(int)$action['result_id'],['order_number'=>(string)($out['order']['order_number']??'')]);
            return $out;
        }
        if((string)$action['state']!=='prepared')throw new RuntimeException('Prepared checkout is no longer executable.');

        $existing=vp3_profile_webmcp_action_by_idempotency_v150($pdo,$owner,'commerce.checkout',$idem,true);
        if($existing&&(int)$existing['id']!==(int)$action['id']){
            if(!hash_equals((string)$existing['payload_hash'],(string)$action['payload_hash']))throw new RuntimeException('Idempotency key was already used for a different checkout.');
            if(!hash_equals((string)$existing['profile_username'],(string)$context['profile_username'])
                ||!hash_equals((string)$existing['session_hash'],(string)$context['session_hash'])
                ||(string)$existing['surface']!==(string)$context['surface']
                ||(int)($existing['property_id']??0)!==(int)($context['property_id']??0)){
                throw new RuntimeException('Idempotency key belongs to a different WebMCP commerce session.');
            }
            if(!in_array((string)$existing['state'],['executing','committed'],true))throw new RuntimeException('That idempotent checkout is still being prepared.');
            $pdo->commit();$out=vp3_profile_webmcp_commerce_checkout_resume_v160($pdo,$profile,$intent,$existing);$out['idempotent_replay']=true;return $out;
        }

        $product=vp3_profile_webmcp_commerce_public_product_v160($pdo,$profile,(string)$intent['product_slug']);
        $row=$product['row'];
        if((int)$row['id']!==(int)$intent['product_id']
            ||!hash_equals((string)$intent['product_state_hash'],vp3_profile_webmcp_commerce_product_state_hash_v160($row))
            ||!hash_equals((string)$intent['terms_digest'],profile_commerce_terms_digest_v900($row))){
            throw new RuntimeException('Product price or seller terms changed. Prepare checkout again.');
        }
        if((string)$row['payment_mode']!=='full'||(string)$row['fulfillment_type']==='physical'||(string)($row['binding_type']??'')==='appointment_event_type'){
            throw new RuntimeException('This product is no longer eligible for this checkout flow.');
        }
        $connection=null;foreach(profile_commerce_checkout_connections_v900($pdo,$row) as $candidate)if((int)$candidate['id']===(int)$intent['connection_id']){$connection=$candidate;break;}
        if(!$connection)throw new RuntimeException('Selected payment provider is no longer available.');

        $receipt=(string)$intent['receipt_token'];if(!profile_commerce_receipt_token_valid_v1100($receipt))throw new RuntimeException('Receipt authority is invalid.');
        $metadata=[
            'source'=>'profile_commerce_v900','profile_username'=>(string)$profile['username'],
            'profile_product_slug'=>(string)$product['projection']['slug'],
            'receipt_token_sha256'=>profile_commerce_receipt_token_hash_v1100($receipt),
            'terms_accepted_at'=>gmdate('c'),'terms_snapshot_sha256'=>profile_commerce_terms_digest_v900($row),
            'profile_conversion_source'=>'profile_commerce_v900','profile_session_id'=>0,
            'profile_target_id'=>(int)$row['id'],'profile_target_slug'=>(string)$product['projection']['slug'],
            'profile_target_title'=>(string)$product['projection']['title'],'profile_target_url'=>(string)$product['projection']['product_url'],
            'webmcp_checkout'=>true,
        ]+vp3_profile_webmcp_commerce_attribution_metadata_v160($telemetryContext);
        $order=agent_commerce_create_order_v800($pdo,$row,[
            'connection_id'=>(int)$connection['id'],'payer_email'=>(string)$intent['payer_email'],'metadata'=>$metadata
        ]);
        $claim=$pdo->prepare("UPDATE profile_webmcp_actions SET idempotency_hash=?,state='executing',result_type='order',result_id=?,result_json=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND state='prepared'");
        $claim->execute([$idem,(int)$order['id'],json_encode(['order_number'=>(string)$order['order_number'],'connection_id'=>(int)$connection['id']],JSON_UNESCAPED_SLASHES),(int)$action['id']]);
        if($claim->rowCount()!==1)throw new RuntimeException('Checkout action changed before the canonical order was created.');
        $pdo->commit();
        $action['state']='executing';$action['idempotency_hash']=$idem;$action['result_id']=(int)$order['id'];
        $out=vp3_profile_webmcp_commerce_checkout_resume_v160($pdo,$profile,$intent,$action);
        vp3_profile_webmcp_commerce_action_complete_v160($pdo,(int)$action['id'],$idem,'order',(int)$order['id'],['order_number'=>(string)$order['order_number'],'connection_id'=>(int)$connection['id']]);
        return $out;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }finally{
        try{$rs=$pdo->prepare('SELECT RELEASE_LOCK(?)');$rs->execute([$lock]);}catch(Throwable $ignored){}
    }
}

function vp3_profile_webmcp_commerce_customer_order_v160(PDO $pdo,array $profile,array $input): array
{
    $orderNumber=trim((string)($input['order_number']??''));$receipt=strtolower(trim((string)($input['receipt_token']??'')));
    $order=profile_commerce_customer_order_v1100($pdo,(int)$profile['user_id'],$orderNumber,$receipt);
    if(!$order)throw new RuntimeException('Order not found.');
    return $order;
}

function vp3_profile_webmcp_commerce_order_get_v160(PDO $pdo,array $profile,array $input): array
{
    $order=vp3_profile_webmcp_commerce_customer_order_v160($pdo,$profile,$input);
    return vp3_profile_webmcp_commerce_order_response_v160($pdo,$profile,$order,(string)$input['receipt_token']);
}

function vp3_profile_webmcp_commerce_delivery_get_v160(PDO $pdo,array $profile,array $input): array
{
    $order=vp3_profile_webmcp_commerce_customer_order_v160($pdo,$profile,$input);
    $delivery=profile_commerce_delivery_for_customer_v1120($order);
    $file=profile_commerce_delivery_file_for_customer_v1130($order);
    $view=profile_commerce_customer_projection_v1100($order);
    return [
        'order_number'=>(string)$view['order_number'],'payment_status'=>(string)$view['payment_status'],
        'fulfillment_status'=>(string)$view['fulfillment_status'],
        'private_delivery_available'=>(bool)$delivery||(bool)$file,
        'delivery_resource_available'=>(bool)$delivery&&trim((string)($delivery['resource_url']??''))!=='',
        'delivery_file_available'=>(bool)$file,
        'receipt_url'=>profile_commerce_customer_order_url_v1100((string)$profile['username'],(string)$order['order_number'],(string)$input['receipt_token']),
    ];
}

function vp3_profile_webmcp_commerce_refund_status_v160(PDO $pdo,array $profile,array $input): array
{
    $order=vp3_profile_webmcp_commerce_customer_order_v160($pdo,$profile,$input);$view=profile_commerce_customer_projection_v1100($order);
    $request=$view['refund_request']??null;
    $eligible=(int)$view['refundable_cents']>0
        &&in_array((string)$view['payment_status'],['paid','partially_refunded'],true)
        &&(string)($order['fulfillment_type']??'')!=='appointment'
        &&(!$request||($request['status']??'')==='declined');
    return [
        'order_number'=>(string)$view['order_number'],'payment_status'=>(string)$view['payment_status'],
        'refundable_cents'=>(int)$view['refundable_cents'],'currency'=>(string)$view['currency'],
        'eligible_to_request'=>$eligible,'refund_request'=>$request,
    ];
}

function vp3_profile_webmcp_commerce_order_state_hash_v160(array $order): string
{
    return hash('sha256',implode('|',[
        (int)$order['id'],(string)$order['order_number'],(string)$order['payment_status'],
        (int)$order['amount_paid_cents'],(int)$order['amount_refunded_cents'],(string)($order['updated_at']??'')
    ]));
}

function vp3_profile_webmcp_commerce_refund_prepare_v160(PDO $pdo,array $profile,array $context,array $input): array
{
    $order=vp3_profile_webmcp_commerce_customer_order_v160($pdo,$profile,$input);
    $status=vp3_profile_webmcp_commerce_refund_status_v160($pdo,$profile,$input);
    if(empty($status['eligible_to_request']))throw new RuntimeException('This order is not eligible for a new refund request.');
    $reason=mb_strimwidth(trim((string)($input['reason']??'')),0,500,'');
    if(mb_strlen($reason)<3)throw new RuntimeException('Tell the seller why you are requesting a refund.');
    $intent=[
        'order_id'=>(int)$order['id'],'order_number'=>(string)$order['order_number'],
        'order_state_hash'=>vp3_profile_webmcp_commerce_order_state_hash_v160($order),
        'receipt_token'=>(string)$input['receipt_token'],'reason'=>$reason,
    ];
    $action=vp3_profile_webmcp_action_prepare_v150($pdo,$context,'commerce.refund_request',$intent,VP3_PROFILE_WEBMCP_COMMERCE_INTENT_TTL_V160);
    return [
        'intent_id'=>$action['intent_id'],'confirmation_token'=>vp3_profile_webmcp_commerce_token_v160($action,$context),
        'expires_at_unix'=>$action['expires_at_unix'],'intent'=>$intent,'confirmation_required'=>true,
        'preview'=>['order_number'=>(string)$order['order_number'],'requested_amount_cents'=>(int)$status['refundable_cents'],'currency'=>(string)$status['currency'],'reason'=>$reason,'money_moves_on_confirm'=>false,'seller_review_required'=>true],
    ];
}

function vp3_profile_webmcp_commerce_refund_confirm_v160(
    PDO $pdo,array $profile,array $context,array $intent,string $confirmationToken,string $idempotencyKey
): array {
    $verified=vp3_profile_webmcp_commerce_token_verify_v160($confirmationToken,$context,'commerce.refund_request',$intent);
    $owner=(int)$profile['user_id'];$idem=vp3_profile_webmcp_idempotency_hash_v150($owner,'commerce.refund_request',$idempotencyKey);
    $lock='vp3_webmcp_refund_'.substr($idem,0,32);$ls=$pdo->prepare('SELECT GET_LOCK(?,5)');$ls->execute([$lock]);
    if((int)$ls->fetchColumn()!==1)throw new RuntimeException('That refund request confirmation is already in progress.');
    try{
        $pdo->beginTransaction();$action=vp3_profile_webmcp_action_row_v150($pdo,$owner,(string)$verified['intent_id'],true);
        if(!$action)throw new RuntimeException('Prepared refund request was not found.');
        vp3_profile_webmcp_commerce_validate_action_v160($action,$context,'commerce.refund_request',$intent);
        if((string)$action['state']==='committed'){
            if(!hash_equals((string)$action['idempotency_hash'],$idem))throw new RuntimeException('This refund request was already confirmed with a different idempotency key.');
            $pdo->commit();return vp3_profile_webmcp_commerce_refund_status_v160($pdo,$profile,['order_number'=>$intent['order_number'],'receipt_token'=>$intent['receipt_token']])+['idempotent_replay'=>true];
        }
        if(!in_array((string)$action['state'],['prepared','executing'],true))throw new RuntimeException('Prepared refund request is no longer executable.');
        if((string)$action['state']==='prepared'){
            $existing=vp3_profile_webmcp_action_by_idempotency_v150($pdo,$owner,'commerce.refund_request',$idem,true);
            if($existing&&(int)$existing['id']!==(int)$action['id']){
                if(!hash_equals((string)$existing['payload_hash'],(string)$action['payload_hash']))throw new RuntimeException('Idempotency key was already used for a different refund request.');
                $pdo->commit();return vp3_profile_webmcp_commerce_refund_status_v160($pdo,$profile,['order_number'=>$intent['order_number'],'receipt_token'=>$intent['receipt_token']])+['idempotent_replay'=>true];
            }
            $pdo->prepare("UPDATE profile_webmcp_actions SET idempotency_hash=?,state='executing',result_type='order',result_id=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND state='prepared'")
                ->execute([$idem,(int)$intent['order_id'],(int)$action['id']]);
        }elseif(!hash_equals((string)$action['idempotency_hash'],$idem)){
            throw new RuntimeException('This refund request is already executing under a different idempotency key.');
        }
        $pdo->commit();

        $order=profile_commerce_customer_order_v1100($pdo,$owner,(string)$intent['order_number'],(string)$intent['receipt_token']);
        if(!$order||(int)$order['id']!==(int)$intent['order_id'])throw new RuntimeException('Refund request order authority changed.');
        if(!hash_equals((string)$intent['order_state_hash'],vp3_profile_webmcp_commerce_order_state_hash_v160($order))){
            $existing=profile_commerce_customer_refund_request_v1100($order);
            if(!$existing||!in_array((string)$existing['status'],['pending','seller_refund_submitted'],true))throw new RuntimeException('Order payment/refund state changed. Prepare the refund request again.');
        }
        $fresh=profile_commerce_customer_refund_request_create_v1100($pdo,$owner,(string)$intent['order_number'],(string)$intent['receipt_token'],(string)$intent['reason']);
        vp3_profile_webmcp_commerce_action_complete_v160($pdo,(int)$action['id'],$idem,'order',(int)$fresh['id'],['order_number'=>(string)$fresh['order_number']]);
        return vp3_profile_webmcp_commerce_refund_status_v160($pdo,$profile,['order_number'=>$intent['order_number'],'receipt_token'=>$intent['receipt_token']]);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();throw $e;
    }finally{
        try{$rs=$pdo->prepare('SELECT RELEASE_LOCK(?)');$rs->execute([$lock]);}catch(Throwable $ignored){}
    }
}

function vp3_profile_webmcp_commerce_record_purchase_v160(PDO $pdo,array $order): void
{
    if((string)($order['payment_status']??'')!=='paid')return;
    $owner=(int)($order['owner_user_id']??0);if($owner<1)return;
    $meta=profile_commerce_order_metadata_v900($order);
    if(empty($meta['webmcp_checkout']))return;

    $referralId=(int)($meta['webmcp_referral_id']??0);
    $sessionHash=(string)($meta['webmcp_referral_session_hash']??'');
    $propertyId=(int)($meta['webmcp_property_id']??0);
    if($referralId>0&&preg_match('/^[a-f0-9]{64}$/',$sessionHash)&&vp3_agent_referral_schema_ready($pdo)){
        $stmt=$pdo->prepare("SELECT r.*,c.display_name,c.operator_name,c.risk_score,c.trust_score FROM vp3_agent_referrals r INNER JOIN vp3_agent_contacts c ON c.id=r.agent_contact_id WHERE r.id=? AND r.owner_user_id=? LIMIT 1");
        $stmt->execute([$referralId,$owner]);$ref=$stmt->fetch();
        if($ref)vp3_agent_referral_record($pdo,$ref,$propertyId,$sessionHash,'purchase',((int)($order['amount_paid_cents']??0))/100);
    }

    if(!vp3_radar_schema_ready($pdo)||$propertyId<1)return;
    $stmt=$pdo->prepare('SELECT metadata_json FROM agent_commerce_orders_v800 WHERE id=? AND owner_user_id=? LIMIT 1 FOR UPDATE');
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $stmt->execute([(int)$order['id'],$owner]);$row=$stmt->fetch();$current=is_array($row)?json_decode((string)$row['metadata_json'],true):null;
        if(!is_array($current))$current=[];
        if(!empty($current['webmcp_purchase_telemetry_recorded_at'])){if($owns)$pdo->commit();return;}
        $details=[
            'envelope_version'=>VP3_PROFILE_WEBMCP_EVENT_ENVELOPE_V130,'event_name'=>'webmcp_purchase_completed',
            'surface'=>(string)($current['webmcp_surface']??''),'tool'=>'vp3.commerce.checkout.confirm','status'=>'paid',
            'attribution_origin'=>$referralId>0?'agent_referral':'webmcp_agent','order_id'=>(int)$order['id'],
            'value_cents'=>(int)($order['amount_paid_cents']??0),'currency'=>(string)($order['currency']??''),
        ];
        $event=$pdo->prepare("INSERT INTO vp3_radar_events (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at) VALUES (?,?,NULL,?,'webmcp_purchase_completed','low','/','WEBMCP',NULL,95,0,?,?,NOW())");
        $event->execute([$owner,$propertyId,(int)($current['webmcp_agent_contact_id']??0)?:null,'WebMCP · commerce purchase · completed',json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        $current['webmcp_purchase_telemetry_recorded_at']=gmdate('c');
        $pdo->prepare('UPDATE agent_commerce_orders_v800 SET metadata_json=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([json_encode($current,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$order['id'],$owner]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        error_log('WebMCP purchase attribution failed: '.$e->getMessage());
    }
}
