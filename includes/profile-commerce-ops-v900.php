<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-v900.php';

function profile_commerce_order_metadata_v900(array $order): array
{
    $decoded=json_decode((string)($order['metadata_json']??''),true);
    return is_array($decoded)?$decoded:[];
}

function profile_commerce_order_is_profile_v900(array $order): bool
{
    $meta=profile_commerce_order_metadata_v900($order);
    return (string)($meta['source']??'')==='profile_commerce_v900';
}

function profile_commerce_order_for_owner_v900(PDO $pdo,int $ownerUserId,int $orderId): ?array
{
    if($ownerUserId<1||$orderId<1)return null;
    $order=agent_commerce_order_v800($pdo,$orderId);
    if(!$order||(int)($order['owner_user_id']??0)!==$ownerUserId||!profile_commerce_order_is_profile_v900($order))return null;
    $order['items']=agent_commerce_order_items_v800($pdo,$orderId);
    return $order;
}

function profile_commerce_orders_for_owner_v900(PDO $pdo,int $ownerUserId,int $limit=60): array
{
    if($ownerUserId<1)return [];$limit=max(1,min(200,$limit));$out=[];
    foreach(agent_commerce_orders_for_owner_v800($pdo,$ownerUserId,min(500,$limit*4)) as $order){
        if((int)($order['owner_user_id']??0)!==$ownerUserId||!profile_commerce_order_is_profile_v900($order))continue;
        $order['items']=agent_commerce_order_items_v800($pdo,(int)$order['id']);$out[]=$order;
        if(count($out)>=$limit)break;
    }
    return $out;
}

function profile_commerce_fulfillment_state_v900(array $order): string
{
    $items=(array)($order['items']??[]);if(!$items)return 'pending';
    $states=array_values(array_unique(array_map(static fn(array $item):string=>(string)($item['fulfillment_status']??'pending'),$items)));
    return count($states)===1?$states[0]:'mixed';
}

function profile_commerce_set_fulfillment_v900(PDO $pdo,int $ownerUserId,int $orderId,string $status): array
{
    $status=strtolower(trim($status));if(!in_array($status,['processing','fulfilled'],true))throw new RuntimeException('Choose a valid fulfillment state.');
    $order=profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId);if(!$order)throw new RuntimeException('Profile Commerce order not found.');
    if((string)($order['fulfillment_type']??'')==='appointment')throw new RuntimeException('Appointment fulfillment remains authoritative in Scheduling.');
    if((string)($order['payment_status']??'')!=='paid')throw new RuntimeException('Only fully paid Profile Commerce orders can enter fulfillment.');
    $from=profile_commerce_fulfillment_state_v900($order);
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("UPDATE agent_commerce_order_items_v800 SET fulfillment_status=?,updated_at=NOW() WHERE order_id=? AND fulfillment_type<>'appointment'");
        $stmt->execute([$status,$orderId]);if($stmt->rowCount()<1)throw new RuntimeException('No Profile Commerce items were available for fulfillment.');
        $pdo->prepare('UPDATE agent_commerce_orders_v800 SET order_status=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$status,$orderId,$ownerUserId]);
        agent_commerce_audit_v800($pdo,$orderId,$ownerUserId,(int)($order['workspace_owner_user_id']??0)?:null,'user',$ownerUserId,null,'profile_fulfillment_'.$status,$from,$status,0,['source'=>'profile_commerce_v900']);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId)?:throw new RuntimeException('Profile Commerce order could not be reloaded.');
}

function profile_commerce_refund_order_v900(PDO $pdo,int $ownerUserId,int $orderId,int $amountMinor,string $reason,array $actor,bool $approved): array
{
    $order=profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId);if(!$order)throw new RuntimeException('Profile Commerce order not found.');
    if((string)($order['fulfillment_type']??'')==='appointment')throw new RuntimeException('Appointment refunds remain managed through Appointment Commerce.');
    return agent_commerce_refund_v800($pdo,$order,$amountMinor,$reason,$actor,$approved);
}
