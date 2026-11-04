<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-ops-v900.php';

/** The seller's private Chat canvas receives one verified announcement per order. */
function vp3_profile_order_present_pa5(PDO $pdo,array $user,array $notification,int $cid,int $agentId): array
{
    if(!$pdo->inTransaction())throw new LogicException('Order presentation requires the seller transaction.');
    $owner=(int)$user['id'];
    $nid=(int)$notification['id'];
    $orderId=(int)$notification['source_id'];
    $result=['ok'=>true,'handled'=>false,'notification_id'=>$nid,'voice_allowed'=>false,'preserve_unread'=>true];
    if(!notification_is_profile_order_pa5($notification)||(int)$notification['user_id']!==$owner)return $result;

    $order=profile_commerce_order_for_owner_v900($pdo,$owner,$orderId);
    if(!$order||count($order['items'])!==1)return $result;
    try{
        $paid=profile_commerce_verified_conversation_payment_pa5($pdo,$order,$order['items'][0]);
    }catch(RuntimeException $error){
        if($error->getCode()!==409)throw $error;
        return $result;
    }

    $scope=$agentId>0?'user_agent_id=?':'user_agent_id IS NULL';
    $q=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND '.$scope);
    $q->execute($agentId>0?[$cid,$owner,$agentId]:[$cid,$owner]);
    if(!$q->fetchColumn())throw new DomainException('The selected Agent Chat conversation is unavailable.');

    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $marker=$sqlite?"json_extract(CASE WHEN json_valid(m.context_json) THEN m.context_json ELSE '{}' END,'$.attention.notification_id')":
        "JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(m.context_json),m.context_json,'{}'),'$.attention.notification_id'))";
    $q=$pdo->prepare("SELECT m.id,m.conversation_id FROM chat_messages m
        INNER JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE c.user_id=? AND m.role='assistant' AND CAST($marker AS ".($sqlite?'INTEGER':'UNSIGNED').")=? LIMIT 1");
    $q->execute([$owner,$nid]);
    $prior=$q->fetch(PDO::FETCH_ASSOC);
    if($prior){
        if((int)$prior['conversation_id']!==$cid)return $result;
        return array_replace($result,['handled'=>true,'duplicate'=>true,'conversation_id'=>$cid,'message_id'=>(int)$prior['id']]);
    }

    $amount=agent_commerce_money_v800($paid,(string)$order['currency']);
    $orderNumber=(string)$order['order_number'];
    $status=(string)$order['payment_status'];
    $message=match(true){
        $status==='refunded'=>"Profile order $orderNumber was paid and has since been refunded.",
        $status==='partially_refunded'=>"Profile order $orderNumber was paid and has since been partially refunded.",
        $order['order_status']==='payment_review'=>"Profile order $orderNumber received a verified payment of $amount and needs fulfillment review.",
        default=>"New Profile order $orderNumber. Payment of $amount is verified.",
    };
    $target=url('/profile-commerce-products.php#orders');
    $actions=[['type'=>'open_url','label'=>'View Profile orders','url'=>$target]];
    $context=[
        'sources'=>[['source'=>'notification:'.$nid,'title'=>'Profile order']],
        'actions'=>$actions,
        'activity'=>['kind'=>'agent_status_update','status'=>'informational','source_type'=>'profile_commerce_order','source_id'=>$orderId],
        'attention'=>['required'=>false,'notification_id'=>$nid,'notification_type'=>'profile_commerce_order_paid','target_url'=>$target],
    ];
    $q=$pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json)
        VALUES (?,NULL,'assistant',?,?)");
    $q->execute([$cid,$message,json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $messageId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')->execute([$cid,$owner]);
    return array_replace($result,['handled'=>true,'duplicate'=>false,'conversation_id'=>$cid,'message_id'=>$messageId]);
}
