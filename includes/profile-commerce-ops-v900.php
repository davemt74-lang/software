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

function profile_commerce_pa5_review_required(array $order): bool
{
    $metadata=profile_commerce_order_metadata_v900($order);
    $review=$metadata['profile_checkout_review_id']??null;
    if(!is_string($review)||!preg_match('/^[a-f0-9]{32}$/D',$review))return false;
    return $order['order_status']==='payment_review'||in_array('review_required',array_column($order['items']??[],'fulfillment_status'),true);
}

function profile_commerce_pa5_review_releasable(array $order): bool
{
    $recovery=profile_commerce_order_metadata_v900($order)['payment_recovery']??[];
    return $order['order_status']==='payment_review'&&$order['payment_status']==='paid'
        &&($recovery['reason']??'')==='late_payment'&&(int)$order['amount_due_cents']===0
        &&(int)$order['amount_refunded_cents']===0&&(int)$order['amount_paid_cents']===(int)$order['total_cents'];
}

/** Called under the order lock by seller projections and payment review. */
function profile_commerce_verified_conversation_payment_pa5(PDO $pdo,array $order,array $item): int
{
    $metadata=profile_commerce_order_metadata_v900($order);
    $review=$metadata['profile_checkout_review_id']??null;
    $cid=(int)($metadata['profile_conversation_id']??0);
    if(!$pdo->inTransaction()||$cid<1||!is_string($review)||!preg_match('/^[a-f0-9]{32}$/D',$review)
        ||!profile_commerce_order_is_profile_v900($order)||!in_array($order['payment_status'],['paid','refunded','partially_refunded'],true)
        ||(int)$order['amount_due_cents']!==0||(int)$order['total_cents']<1){
        throw new RuntimeException('Verified Profile order payment is unavailable.',409);
    }
    $lineage=$pdo->prepare('SELECT id FROM profile_conversation_checkouts_pa5
        WHERE order_id=? AND review_id=? AND conversation_id=? AND owner_user_id=? AND product_id=? AND connection_id=?');
    $lineage->execute([(int)$order['id'],$review,$cid,(int)$order['owner_user_id'],(int)$item['product_id'],(int)$order['connection_id']]);
    $receipt=$pdo->prepare("SELECT COALESCE(SUM(p.amount_cents),0) FROM agent_commerce_payments_v800 p
        INNER JOIN agent_commerce_checkout_attempts_v800 a ON a.id=p.checkout_attempt_id AND a.order_id=p.order_id
        WHERE p.order_id=? AND p.status='completed' AND p.currency=? AND a.connection_id=?
          AND a.status='completed' AND p.provider=a.provider AND p.external_payment_id=a.external_payment_id
          AND p.amount_cents=a.amount_cents AND p.currency=a.currency");
    $receipt->execute([(int)$order['id'],(string)$order['currency'],(int)$order['connection_id']]);
    $paid=(int)$receipt->fetchColumn();
    if(!$lineage->fetchColumn()||$paid<(int)$order['total_cents']||$paid!==(int)$order['amount_paid_cents']){
        throw new RuntimeException('Verified Profile order payment is unavailable.',409);
    }
    return $paid;
}

function profile_commerce_set_fulfillment_v900(PDO $pdo,int $ownerUserId,int $orderId,string $status,bool $reviewConfirmed=false): array
{
    $status=strtolower(trim($status));if(!in_array($status,['processing','fulfilled'],true))throw new RuntimeException('Choose a valid fulfillment state.');
    agent_commerce_atomic_pa2($pdo,function()use($pdo,$ownerUserId,$orderId,$status,$reviewConfirmed):void{
        $q=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=? AND owner_user_id=?'.agent_commerce_lock_pa2($pdo));
        $q->execute([$orderId,$ownerUserId]);$order=$q->fetch(PDO::FETCH_ASSOC);
        if(!$order||!profile_commerce_order_is_profile_v900($order))throw new RuntimeException('Profile Commerce order not found.');
        if($order['fulfillment_type']==='appointment')throw new RuntimeException('Appointment fulfillment remains authoritative in Scheduling.');
        if($order['payment_status']!=='paid')throw new RuntimeException('Only fully paid Profile Commerce orders can enter fulfillment.');
        $order['items']=agent_commerce_order_items_v800($pdo,$orderId);
        $reviewRequired=profile_commerce_pa5_review_required($order);
        $metadata=profile_commerce_order_metadata_v900($order);
        if($reviewRequired){
            if(!$reviewConfirmed||$status!=='processing')throw new RuntimeException('Review and explicitly approve this payment before starting fulfillment.');
            if(!profile_commerce_pa5_review_releasable($order)||count($order['items'])!==1){
                throw new RuntimeException('This payment requires reconciliation before fulfillment.');
            }
            profile_commerce_verified_conversation_payment_pa5($pdo,$order,$order['items'][0]);
            $refunds=$pdo->prepare("SELECT id FROM agent_commerce_refunds_v800 WHERE order_id=? AND status IN ('pending','completed') LIMIT 1".agent_commerce_lock_pa2($pdo));
            $refunds->execute([$orderId]);
            if($refunds->fetchColumn())throw new RuntimeException('This payment has a refund and requires reconciliation before fulfillment.');
            $metadata['payment_recovery']['status']='resolved';
            $metadata['payment_recovery']['resolved_at']=gmdate('c');
            $pdo->prepare('UPDATE agent_commerce_orders_v800 SET metadata_json=? WHERE id=?')->execute([json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$orderId]);
        }elseif($reviewConfirmed){
            if(($metadata['payment_recovery']['status']??null)==='resolved')return;
            throw new RuntimeException('This order is not awaiting payment review.');
        }
        $from=profile_commerce_fulfillment_state_v900($order);
        $stmt=$pdo->prepare("UPDATE agent_commerce_order_items_v800 SET fulfillment_status=?,updated_at=NOW() WHERE order_id=? AND fulfillment_type<>'appointment'");
        $stmt->execute([$status,$orderId]);if($stmt->rowCount()<1)throw new RuntimeException('No Profile Commerce items were available for fulfillment.');
        $pdo->prepare('UPDATE agent_commerce_orders_v800 SET order_status=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$status,$orderId,$ownerUserId]);
        $event=$reviewRequired?'profile_payment_review_released':'profile_fulfillment_'.$status;
        agent_commerce_audit_v800($pdo,$orderId,$ownerUserId,(int)($order['workspace_owner_user_id']??0)?:null,'user',$ownerUserId,null,$event,$from,$status,0,['source'=>'profile_commerce_v900']);
        if($reviewRequired)profile_promotion_attribution_touch_order_v1($pdo,$order);
    });
    return profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId)?:throw new RuntimeException('Profile Commerce order could not be reloaded.');
}

function profile_commerce_refund_order_v900(PDO $pdo,int $ownerUserId,int $orderId,int $amountMinor,string $reason,array $actor,bool $approved): array
{
    $order=profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId);if(!$order)throw new RuntimeException('Profile Commerce order not found.');
    if((string)($order['fulfillment_type']??'')==='appointment')throw new RuntimeException('Appointment refunds remain managed through Appointment Commerce.');
    return agent_commerce_refund_v800($pdo,$order,$amountMinor,$reason,$actor,$approved);
}
