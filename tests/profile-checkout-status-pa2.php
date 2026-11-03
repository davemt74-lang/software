<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-commerce-v800-part1.php';
require dirname(__DIR__).'/includes/agent-commerce-v800-part2.php';
require dirname(__DIR__).'/includes/profile-commerce-lifecycle-v1100.php';
require dirname(__DIR__).'/includes/profile-commerce-delivery-file-v1130.php';
require dirname(__DIR__).'/includes/profile-webmcp-confirmation-v192.php';
require dirname(__DIR__).'/includes/profile-webmcp-commerce-v160.php';
$checks=0;
function pcCheck(bool $ok,string $message): void{if(!$ok)throw new RuntimeException('FAIL '.$message);$GLOBALS['checks']++;echo 'PASS '.$message."\n";}
$base=['id'=>7,'owner_user_id'=>1,'order_number'=>'ORDER-7','order_status'=>'paid','payment_status'=>'paid','fulfillment_type'=>'digital','currency'=>'usd','total_cents'=>2500,'amount_paid_cents'=>2500,'amount_refunded_cents'=>0,'items'=>[['title_snapshot'=>'Service guide','quantity'=>1,'fulfillment_type'=>'digital','fulfillment_status'=>'pending']],
 'metadata_json'=>json_encode(['source'=>'profile_commerce_v900','private_provider_secret'=>'SECRET_PRIVATE_METADATA','customer_delivery'=>['message'=>'SECRET_DELIVERY','resource_url'=>'https://delivery.example.test/private','resource_label'=>'Private resource'],'customer_delivery_file'=>['file_id'=>str_repeat('a',48),'extension'=>'pdf','mime'=>'application/pdf','original_name'=>'SECRET_DOWNLOAD.pdf','bytes'=>100,'sha256'=>str_repeat('b',64)]])];
foreach([
 ['awaiting_payment','pending_payment','pending','payment_pending'],['partially_paid','paid','pending','partially_paid'],['paid','paid','pending','paid'],['paid','processing','processing','processing'],['paid','fulfilled','fulfilled','fulfilled'],['expired','expired','pending','expired'],['cancelled','cancelled','pending','cancelled'],['failed','pending_payment','pending','payment_failed'],['future_unknown','pending_payment','pending','unverified'],['refunded','refunded','fulfilled','refunded'],['partially_refunded','paid','fulfilled','partially_refunded'],['paid','payment_review','review_required','payment_review'],['partially_paid','payment_review','review_required','payment_review'],['paid','paid','review_required','payment_review']
] as [$payment,$state,$fulfillment,$key]){
    $order=$base;$order['payment_status']=$payment;$order['order_status']=$state;$order['items'][0]['fulfillment_status']=$fulfillment;
    $view=profile_commerce_customer_projection_v1100($order);
    pcCheck($view['status']['key']===$key,$payment.'/'.$state.'/'.$fulfillment.' has truthful customer status');
    pcCheck(!str_contains(json_encode($view),'SECRET'),'customer projection never includes private delivery or raw metadata');
    if($key==='payment_review'){
        pcCheck($view['review_required']===true&&$view['fulfillment_status']==='review_required'&&$view['payment_status']===$payment,'review status preserves the actual financial record');
        pcCheck(!profile_commerce_delivery_eligible_v1120($order)&&profile_commerce_delivery_for_customer_v1120($order)===null&&profile_commerce_delivery_file_for_customer_v1130($order)===null,'review blocks staged private resource and file release');
    }
}
$order=$base;$order['items'][]=['fulfillment_status'=>'review_required','fulfillment_type'=>'digital'];
pcCheck(profile_commerce_customer_status_v1100($order)['key']==='payment_review'&&profile_commerce_delivery_file_for_customer_v1130($order)===null,'one review-required item blocks mixed-order private delivery');
pcCheck(profile_commerce_delivery_eligible_v1120($base)&&profile_commerce_delivery_for_customer_v1120($base)['message']==='SECRET_DELIVERY'&&profile_commerce_delivery_file_for_customer_v1130($base)['original_name']==='SECRET_DOWNLOAD.pdf','ordinary paid seller delivery remains available');
$order=$base;$order['payment_status']='partially_refunded';
pcCheck(profile_commerce_delivery_for_customer_v1120($order)!==null&&profile_commerce_delivery_file_for_customer_v1130($order)!==null&&!profile_commerce_delivery_eligible_v1120($order),'partial-refund read behavior and full-payment writer boundary are preserved');
$order=$base;$order['order_status']='payment_review';$order['payment_status']='partially_refunded';
pcCheck(profile_commerce_delivery_for_customer_v1120($order)===null&&profile_commerce_delivery_file_for_customer_v1130($order)===null,'partial refund cannot bypass unresolved fulfillment review');
foreach(['paid','awaiting_payment','partially_paid','refunded','partially_refunded','failed'] as $payment){
    $order=$base;$order['payment_status']=$payment;$order['order_status']=$payment==='awaiting_payment'?'pending_payment':'paid';
    $result=vp3_profile_webmcp_action_envelope_v192('vp3.commerce.checkout.confirm',['order'=>profile_commerce_customer_projection_v1100($order),'checkout_url'=>'https://checkout.stripe.com/pay','receipt_url'=>'/receipt']);
    pcCheck($result['action']['phase']==='recorded'&&$result['action']['outcome']['code']!== 'outcome_unverified'&&!str_contains($result['action']['outcome']['message'],'Completed.'),'checkout '.$payment.' records an operation-specific outcome without generic completion');
}
$order=$base;$order['order_status']='payment_review';$order['items'][0]['fulfillment_status']='review_required';
$result=vp3_profile_webmcp_action_envelope_v192('vp3.commerce.checkout.confirm',['order'=>profile_commerce_customer_projection_v1100($order)]);
pcCheck($result['action']['outcome']['code']==='payment_review'&&str_contains($result['action']['outcome']['message'],'verified')&&!preg_match('/reopened|refunded|fulfilled/',$result['action']['outcome']['message']),'late paid review never claims an appointment reopened, fulfillment or refund');
foreach([[],['order'=>[]],['order'=>['order_number'=>'ORDER-7','payment_status'=>true]],['order'=>['order_number'=>'ORDER-7','payment_status'=>'future_unknown']],['verified'=>true],['order'=>false]] as $bad){
    $result=vp3_profile_webmcp_action_envelope_v192('vp3.commerce.checkout.confirm',$bad);
    pcCheck($result['action']['phase']==='unverified'&&$result['action']['outcome']['code']==='outcome_unverified','malformed or missing order evidence cannot become verified completion');
}
foreach([
 ['vp3.booking.confirm',['booking'=>['booking_id'=>3,'status'=>'confirmed'],'payment_required'=>false],'booking_created'],
 ['vp3.booking.reschedule.confirm',['booking'=>['booking_id'=>3,'status'=>'confirmed'],'payment_required'=>true,'payment_status'=>'awaiting_payment'],'booking_payment_pending'],
 ['vp3.booking.cancel.confirm',['booking'=>['booking_id'=>3,'status'=>'cancelled']],'booking_cancelled'],
 ['vp3.commerce.refund.confirm',['order_number'=>'ORDER-7','refund_request'=>['request_id'=>str_repeat('a',32),'status'=>'pending']],'refund_requested'],
 ['vp3.commerce.refund.confirm',['order_number'=>'ORDER-7','refund_request'=>['request_id'=>str_repeat('a',32),'status'=>'seller_refund_submitted']],'refund_submitted'],
 ['vp3.campaign.participation.confirm',['participation'=>['status'=>'active'],'lifecycle'=>['state'=>'awaiting_verification']],'campaign_recorded'],
 ['vp3.rewards.claim.confirm',['reward_redeemed'=>false,'redemption_requires_merchant_operator'=>true,'claim_handoff_url'=>'/reward-inbox.php?claim=fixture'],'reward_claim_handoff'],
 ['vp3.rewards.transfer.confirm',['status'=>'sent','transfer_public_id'=>'fixture-transfer'],'reward_transferred']
] as [$tool,$payload,$code]){
    $result=vp3_profile_webmcp_action_envelope_v192($tool,$payload+['idempotent_replay'=>true]);
    pcCheck($result['action']['phase']==='recorded'&&$result['action']['outcome']['code']===$code&&$result['action']['idempotent_replay']===true,$tool.' reports only its actual operation and preserves replay');
}
$bad=vp3_profile_webmcp_action_envelope_v192('vp3.booking.confirm',['booking'=>['booking_id'=>[3],'status'=>'confirmed'],'payment_required'=>false]);
pcCheck($bad['action']['phase']==='unverified','malformed booking identity cannot be cast into a saved action');
$changed=vp3_profile_webmcp_action_error_v192(new RuntimeException('Product price or seller terms changed. Prepare checkout again.'),'unavailable');
pcCheck($changed['payload']['action']['reprepare_required']===true,'changed canonical terms require a new review');
$policy=str_repeat('Long seller policy with a cancellation fee. ',20);
$terms=vp3_profile_webmcp_commerce_terms_projection_v160(['payment_mode'=>'full','price_cents'=>2500,'deposit_cents'=>2500,'currency'=>'usd','hold_minutes'=>30,'refund_before_hours'=>24,'cancellation_fee_cents'=>500,'cancellation_policy'=>$policy]);
pcCheck($terms['cancellation_policy']===$policy&&$terms['cancellation_fee_cents']===500&&$terms['refund_before_hours']===24,'canonical full seller policy, fee and cutoff remain available to the review card');
$prepared=vp3_profile_webmcp_action_envelope_v192('vp3.commerce.checkout.prepare',['confirmation_required'=>true,'intent_id'=>str_repeat('a',32),'confirmation_token'=>'SECRET','intent'=>['terms_digest'=>'SECRET_DIGEST'],'preview'=>['terms'=>$terms]]);
pcCheck($prepared['action']['preview']['terms']===$terms&&$prepared['action']['confirmation']['requires_terms_acceptance']===true,'prepared review retains complete canonical terms without dropping the acceptance gate');
echo 'PROFILE_CHECKOUT_STATUS_PA2=PASS checks='.$checks."\n";
