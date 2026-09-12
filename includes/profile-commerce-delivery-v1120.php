<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-ops-v900.php';

/** VP3 Profile Commerce v11.20 — private digital/manual delivery metadata. */
const VP3_PROFILE_COMMERCE_DELIVERY_V1120='profile-commerce-delivery-v1120-20260912';

function profile_commerce_delivery_v1120(array $order): ?array
{
    $meta=profile_commerce_order_metadata_v900($order);$delivery=$meta['customer_delivery']??null;
    if(!is_array($delivery))return null;
    $message=mb_strimwidth(trim((string)($delivery['message']??'')),0,4000,'');
    $resourceUrl=trim((string)($delivery['resource_url']??''));
    $resourceLabel=mb_strimwidth(trim((string)($delivery['resource_label']??'')),0,80,'');
    if($message===''&&$resourceUrl==='')return null;
    if($resourceUrl!==''&&!profile_commerce_delivery_url_valid_v1120($resourceUrl))$resourceUrl='';
    if($resourceUrl!==''&&$resourceLabel==='')$resourceLabel='Open delivery resource';
    return [
        'message'=>$message,
        'resource_url'=>$resourceUrl,
        'resource_label'=>$resourceLabel,
        'updated_at'=>(string)($delivery['updated_at']??''),
    ];
}

function profile_commerce_delivery_url_valid_v1120(string $url): bool
{
    $url=trim($url);if($url===''||strlen($url)>2048||filter_var($url,FILTER_VALIDATE_URL)===false)return false;
    $parts=parse_url($url);if(!is_array($parts))return false;
    return strtolower((string)($parts['scheme']??''))==='https'
        &&trim((string)($parts['host']??''))!==''
        &&empty($parts['user'])&&empty($parts['pass']);
}

function profile_commerce_delivery_eligible_v1120(array $order): bool
{
    if(!profile_commerce_order_is_profile_v900($order))return false;
    if((string)($order['payment_status']??'')!=='paid')return false;
    $type=strtolower(trim((string)($order['fulfillment_type']??'')));
    return in_array($type,['digital','virtual','membership','event','gift','local_pickup','other'],true);
}

function profile_commerce_delivery_for_customer_v1120(array $order): ?array
{
    if(!in_array((string)($order['payment_status']??''),['paid','partially_refunded'],true))return null;
    return profile_commerce_delivery_v1120($order);
}

function profile_commerce_delivery_save_v1120(PDO $pdo,int $ownerUserId,int $orderId,string $message,string $resourceUrl,string $resourceLabel,string $fulfillmentState=''): array
{
    $message=mb_strimwidth(trim($message),0,4000,'');
    $resourceUrl=trim($resourceUrl);$resourceLabel=mb_strimwidth(trim($resourceLabel),0,80,'');
    if($resourceUrl!==''&&!profile_commerce_delivery_url_valid_v1120($resourceUrl))throw new RuntimeException('Delivery resource must be a valid HTTPS URL without embedded credentials.');
    if($message===''&&$resourceUrl==='')throw new RuntimeException('Add a delivery message or HTTPS resource before saving.');
    if($resourceUrl!==''&&$resourceLabel==='')$resourceLabel='Open delivery resource';
    $fulfillmentState=strtolower(trim($fulfillmentState));if($fulfillmentState!==''&&!in_array($fulfillmentState,['processing','fulfilled'],true))throw new RuntimeException('Choose a valid fulfillment state.');

    $order=profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId);if(!$order)throw new RuntimeException('Profile Commerce order not found.');
    if(!profile_commerce_delivery_eligible_v1120($order))throw new RuntimeException('Delivery details can be added only to fully paid generic digital/manual Profile Commerce orders.');

    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=? AND owner_user_id=? LIMIT 1 FOR UPDATE');$stmt->execute([$orderId,$ownerUserId]);$locked=$stmt->fetch();
        if(!$locked||!profile_commerce_delivery_eligible_v1120($locked))throw new RuntimeException('Delivery details can be added only to fully paid generic digital/manual Profile Commerce orders.');
        $meta=profile_commerce_order_metadata_v900($locked);$meta['customer_delivery']=[
            'message'=>$message,
            'resource_url'=>$resourceUrl,
            'resource_label'=>$resourceLabel,
            'updated_at'=>gmdate('c'),
        ];
        $pdo->prepare('UPDATE agent_commerce_orders_v800 SET metadata_json=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([
            json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$orderId,$ownerUserId
        ]);
        agent_commerce_audit_v800($pdo,$orderId,$ownerUserId,(int)($locked['workspace_owner_user_id']??0)?:null,'user',$ownerUserId,null,'profile_delivery_saved',(string)$locked['payment_status'],(string)$locked['payment_status'],0,[
            'message_chars'=>mb_strlen($message),'resource_present'=>$resourceUrl!==''
        ]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    if($fulfillmentState!=='')profile_commerce_set_fulfillment_v900($pdo,$ownerUserId,$orderId,$fulfillmentState);
    return profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId)?:throw new RuntimeException('Profile Commerce order could not be reloaded.');
}
