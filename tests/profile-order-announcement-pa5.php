<?php
declare(strict_types=1);

require __DIR__.'/fixtures/profile-conversation-checkout-pa5.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';

// Run the real presentation transaction without dispatching an HTTP request.
$source=file_get_contents(dirname(__DIR__).'/api/chat-notifications-brain-v240.php');
$start=strpos($source,'function chat_notifications_v240_json');
$end=strpos($source,"\n\$user = current_user();",$start);
eval(substr($source,$start,$end-$start));

function vp3_cognitive_presentation_owns_attention_v510(): bool{return true;}

$checks=0;
function check_order_announcement_pa5(bool $passed,string $label): void
{
    if(!$passed)throw new RuntimeException('FAIL '.$label);
    $GLOBALS['checks']++;
    echo "PASS $label\n";
}

$fixture=pa5_fixture_init();
$pdo=db();
$profile=profile_by_username($pdo,'fixture-owner');
$buyer=pa3_context(2,'b');
$review=vp3_profile_checkout_prepare_pa5($pdo,$profile,$buyer,0,[
    'product_slug'=>'field-guide','quantity'=>2,'connection_id'=>$fixture['seller'],
    'payer_email'=>'buyer@example.test','request_id'=>bin2hex(random_bytes(16)),
]);
$cid=(int)$review['conversation_id'];
vp3_profile_checkout_confirm_pa5($pdo,$profile,$buyer,$cid,['review_id'=>$review['review']['review_id'],'terms_accepted'=>true]);
$orderId=(int)$pdo->query('SELECT id FROM agent_commerce_orders_v800')->fetchColumn();
$noteId=pa5_fixture_insert('notifications',[
    'user_id'=>1,'type'=>'profile_commerce_order_paid','source_type'=>'profile_commerce_order','source_id'=>$orderId,
    'title'=>'Spoofed order','body'=>'PRIVATE forged content','target_url'=>'https://example.invalid',
]);
$seller=pa3_owner();
$input=['notification_id'=>$noteId,'conversation_id'=>11,'agent_id'=>7];
$present=static fn(array $changes=[])=>chat_notifications_v240_present_attention($pdo,$seller,array_replace($input,$changes));
$assistantCount=static fn()=>pa3_count('chat_messages',"role='assistant'");
check_order_announcement_pa5(!$present()['handled']&&$assistantCount()===0,'a typed notification without verified payment cannot announce an order');

pa5_fixture_verify_payment($orderId);
$items=notification_profile_orders_after_pa5($pdo,1,0);
check_order_announcement_pa5(array_map('intval',array_column($items['items'],'id'))===[$noteId]
    &&$items['latest_id']===$noteId,'scoped polling returns the exact seller notification and advances to its id');
check_order_announcement_pa5(notification_profile_orders_after_pa5($pdo,4,0)['items']===[]
    &&notification_profile_orders_after_pa5($pdo,1,$noteId)['items']===[],'other owners and consumed cursors have no order announcement');
try{
    $present(['conversation_id'=>13]);
    throw new RuntimeException('FAIL accepted foreign Agent Chat');
}catch(DomainException $error){
    check_order_announcement_pa5($assistantCount()===0,'verified order still requires the exact seller Chat and selected Agent');
}

$notice=$present();
check_order_announcement_pa5($notice['handled']&&!$notice['duplicate']&&!$notice['voice_allowed']&&$notice['preserve_unread']
    &&$notice['conversation_id']===11,'verified order appears in the active private Chat with no automatic narration or bell acknowledgement');
$message=pa5_fixture_row('chat_messages',(int)$notice['message_id']);
check_order_announcement_pa5(str_contains($message['message'],'USD 50.00')
    &&str_contains($message['context_json'],'/profile-commerce-products.php#orders')
    &&!preg_match('/buyer@example|PRIVATE|example.invalid|checkout.stripe|\bpi_pa5/',json_encode($message,JSON_THROW_ON_ERROR)),
    'announcement uses canonical amount and seller order link without buyer or provider secrets');
check_order_announcement_pa5((int)pa5_fixture_row('notifications',$noteId)['is_read']===0,'canvas delivery leaves the bell unread');
$replay=$present();
check_order_announcement_pa5($replay['handled']&&$replay['duplicate']&&$replay['message_id']===$notice['message_id'],
    'lost presentation response reuses the saved Chat message');
check_order_announcement_pa5(!$present(['conversation_id'=>12,'agent_id'=>8])['handled']&&$assistantCount()===1,
    'another valid seller Agent Chat cannot duplicate or navigate to the old announcement');
$pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=?')->execute([$noteId]);
check_order_announcement_pa5(count(notification_profile_orders_after_pa5($pdo,1,0)['items'])===1,
    'manual bell read does not suppress canvas delivery');
echo 'PROFILE_ORDER_ANNOUNCEMENT_PA5=PASS '.$checks.' checks ('.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
