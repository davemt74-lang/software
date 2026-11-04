<?php
declare(strict_types=1);

require __DIR__.'/fixtures/profile-conversation-checkout-pa5.php';
require_once dirname(__DIR__).'/includes/profile-commerce-delivery-v1120.php';

$fixtures=pa5_fixture_init();
$seller=profile_by_username(db(),'fixture-owner');
$visitor=pa3_context(2,'b');
$checks=0;

function pa5_check(bool $passed,string $label): void
{
    if(!$passed)throw new RuntimeException('FAIL '.$label);
    $GLOBALS['checks']++;
    echo "PASS $label\n";
}

function pa5_reject(callable $operation,string $label): void
{
    try{$operation();}
    catch(RuntimeException $e){
        pa5_check(!str_contains($e->getMessage(),'PRIVATE_PROVIDER_DIAGNOSTIC'),$label);
        return;
    }
    throw new RuntimeException('FAIL '.$label.' unexpectedly succeeded');
}

function pa5_prepare(int $cid=0,array $changes=[]): array
{
    $input=array_replace([
        'product_slug'=>'field-guide','quantity'=>2,'connection_id'=>$GLOBALS['fixtures']['seller'],
        'payer_email'=>'buyer@example.test','request_id'=>bin2hex(random_bytes(16)),
    ],$changes);
    return vp3_profile_checkout_prepare_pa5(db(),$GLOBALS['seller'],$GLOBALS['visitor'],$cid,$input);
}

function pa5_confirm(int $cid,string $reviewId,mixed $accepted=true,array $changes=[]): array
{
    return vp3_profile_checkout_confirm_pa5(db(),$GLOBALS['seller'],$GLOBALS['visitor'],$cid,
        array_replace(['review_id'=>$reviewId,'terms_accepted'=>$accepted],$changes));
}

function pa5_review(int $cid,string $id): array
{
    $state=vp3_profile_checkout_state_pa5(db(),$GLOBALS['seller'],$GLOBALS['visitor'],$cid);
    foreach($state['reviews'] as $review)if($review['review_id']===$id)return $review;
    throw new RuntimeException('Missing review in exact conversation.');
}

function pa5_order(int $offset=0): array
{
    $rows=db()->query('SELECT * FROM agent_commerce_orders_v800 ORDER BY id')->fetchAll();
    return $rows[$offset]??throw new RuntimeException('Missing fixture order.');
}

function pa5_seller_alerts(): array
{
    return db()->query("SELECT * FROM notifications WHERE type='profile_commerce_order_paid' ORDER BY id")->fetchAll();
}

$catalog=vp3_profile_checkout_catalog_pa5(db(),$seller);
pa5_check(array_column($catalog['products'],'slug')===['field-guide'],'catalog includes only active, published, payable seller products');
pa5_check(array_column($catalog['products'][0]['payment_providers'],'connection_id')===[$fixtures['seller']],'catalog returns only current merchant payment choices');
pa5_check(!preg_match('/PRIVATE_|acct_pa5|ciphertext/',json_encode($catalog)),'catalog exposes no private account or provider credentials');
foreach(['private-guide','foreign-guide','visit','physical','disconnected','inactive'] as $slug){
    pa5_reject(fn()=>pa5_prepare(0,['product_slug'=>$slug]),'ordinary conversation rejects checkout for '.$slug);
}
pa5_reject(fn()=>pa5_prepare(0,['connection_id'=>$fixtures['foreign']]),'foreign connected merchant cannot replace the seller merchant');
pa5_reject(fn()=>pa5_prepare(0,['quantity'=>0]),'zero quantity cannot produce a purchase review');
pa5_reject(fn()=>pa5_prepare(0,['quantity'=>10000]),'quantity above the supported range cannot produce a review');
pa5_reject(fn()=>pa5_prepare(0,['payer_email'=>'x@example.test\nBcc:y@example.test']),'malformed payer email cannot create a review');
pa5_check(pa3_count('profile_conversation_checkouts_pa5')===0&&pa3_count('agent_commerce_orders_v800')===0,'denied offers leave no review or order');

$requestId=bin2hex(random_bytes(16));
$quote=pa5_prepare(0,['request_id'=>$requestId]);
$cid=(int)$quote['conversation_id'];
$review=$quote['review'];
$id=$review['review_id'];
pa5_check($cid>0&&$review['state']==='prepared'&&preg_match('/^[a-f0-9]{32}$/D',$id)===1,'prepare persists a review in a new bound visitor thread');
pa5_check($review['title']==='Field Guide'&&$review['quantity']===2&&$review['unit_price_cents']===2500&&$review['total_cents']===5000&&strtolower($review['currency'])==='usd','review shows exact product, quantity, unit amount, total and currency');
pa5_check($review['charges_cents']===0&&$review['charges_label']!=='','review explicitly lists applicable charges');
pa5_check($review['fulfillment_type']==='digital'&&$review['terms']['cancellation_policy']==='Fixture cancellation terms'&&$review['terms']['cancellation_fee_cents']===300&&$review['payment_provider']['provider']==='stripe','review exposes fulfillment, terms and provider');
pa5_check($review['expires_at_unix']>time()&&$review['expires_at_unix']<=time()+3600,'review expires within a bounded window');
pa5_check($review['payer_email']==='buyer@example.test'&&!preg_match('/PRIVATE_|acct_pa5|checkout_url|receipt_url/',json_encode($review)),'exact buyer can review the delivery email without authority secrets or provider and receipt links');
pa5_check(pa3_count('agent_commerce_orders_v800')===0&&$GLOBALS['pa5ProviderRequests']===[],'preparing is financially inert');
$same=pa5_prepare($cid,['request_id'=>$requestId]);
pa5_check($same['review']['review_id']===$id,'preparation retry returns the exact durable review');
$same=pa5_prepare(0,['request_id'=>$requestId]);
pa5_check((int)$same['conversation_id']===$cid&&$same['review']['review_id']===$id,'lost first response finds the same review and thread without its conversation ID');
pa5_reject(fn()=>pa5_prepare($cid,['request_id'=>$requestId,'quantity'=>3]),'reusing a prepare request ID cannot change the approved quantity');

foreach([false,'true',1,null] as $accepted){
    pa5_reject(fn()=>pa5_confirm($cid,$id,$accepted),'only a boolean buyer confirmation authorizes checkout ('.get_debug_type($accepted).')');
}
pa5_reject(fn()=>pa5_confirm($cid,str_repeat('f',32)),'an unrelated yes or guessed review ID cannot authorize checkout');
$otherCid=(int)vp3_profile_handoff_atomic_pa3(db(),static function()use($visitor){
    $context=vp3_profile_agent_public_live_v1(db(),$visitor,true);
    vp3_profile_agent_public_admit_v1(db(),$context);
    return vp3_profile_agent_public_create_bound_v1(db(),$context)['id'];
});
pa5_check($otherCid!==$cid,'fixture has a second bound thread for the exact same buyer');
pa5_reject(fn()=>pa5_confirm($otherCid,$id),'buyer cannot confirm a review from another conversation');
$guest=pa3_context(0,'b');
pa5_reject(fn()=>vp3_profile_checkout_state_pa5(db(),$seller,$guest,$cid),'logout removes access to the member checkout state');
$foreign=pa3_context(3,'b');
pa5_reject(fn()=>vp3_profile_checkout_confirm_pa5(db(),$seller,$foreign,$cid,['review_id'=>$id,'terms_accepted'=>true]),'same browser switching accounts cannot confirm the original review');
pa3_login(1);
pa5_reject(fn()=>vp3_profile_checkout_state_pa5(db(),$seller,$visitor,$cid),'seller cannot reuse the buyer context to fetch private checkout state');
$visitor=pa3_context(2,'b');

$productId=$fixtures['product'];
db()->prepare('UPDATE agent_commerce_products_v800 SET price_cents=2600 WHERE id=?')->execute([$productId]);
pa5_reject(fn()=>pa5_confirm($cid,$id),'price change invalidates the original purchase review');
$changed=pa5_prepare($cid)['review'];
pa5_check($changed['total_cents']===5200,'new review reflects changed price');
db()->prepare('UPDATE agent_commerce_products_v800 SET cancellation_policy=? WHERE id=?')->execute(['Updated cancellation terms',$productId]);
pa5_reject(fn()=>pa5_confirm($cid,$changed['review_id']),'seller term change invalidates the review even with the same price');
$changed=pa5_prepare($cid)['review'];
db()->prepare("UPDATE agent_commerce_provider_connections_v800 SET external_account_id='acct_pa5_changed' WHERE id=?")->execute([$fixtures['seller']]);
pa5_reject(fn()=>pa5_confirm($cid,$changed['review_id']),'merchant account replacement invalidates the review');
db()->prepare("UPDATE agent_commerce_provider_connections_v800 SET external_account_id='acct_pa5_seller' WHERE id=?")->execute([$fixtures['seller']]);
$expired=pa5_prepare($cid)['review']['review_id'];
db()->prepare('UPDATE profile_conversation_checkouts_pa5 SET expires_at_unix=? WHERE review_id=?')->execute([time()-1,$expired]);
pa5_check(pa5_review($cid,$expired)['state']==='expired','past deadline renders an expired review');
pa5_reject(fn()=>pa5_confirm($cid,$expired),'expired review requires a new buyer confirmation');
pa5_check(pa3_count('agent_commerce_orders_v800')===0&&$GLOBALS['pa5ProviderRequests']===[],'stale or unauthorized confirmations have no financial effects');

$ready=pa5_prepare($cid)['review'];
$readyId=$ready['review_id'];
$nestedBlocked=false;
$GLOBALS['pa5ProviderHook']=static function()use($cid,$readyId,&$nestedBlocked): void {
    $original=db();
    if($original->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $cfg=$GLOBALS['pa3Config'];
        $GLOBALS['pdo']=new PDO($cfg['dsn'],$cfg['user'],$cfg['password'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    }
    try{pa5_confirm($cid,$readyId);}
    catch(VP3ProfileCheckoutException $e){$nestedBlocked=$e->publicCode==='PROFILE_CHECKOUT_RETRY'&&$e->httpStatus===503;}
    finally{$GLOBALS['pdo']=$original;}
    throw new RuntimeException('PRIVATE_PROVIDER_DIAGNOSTIC');
};
pa5_reject(fn()=>pa5_confirm($cid,$readyId),'uncertain provider response returns safe retry information');
pa5_check($nestedBlocked&&count($GLOBALS['pa5ProviderRequests'])===1,'competing confirmation cannot send a second provider request while the lease is held');
pa5_check(pa3_count('agent_commerce_orders_v800')===1&&pa3_count('agent_commerce_checkout_attempts_v800')===1,'uncertain response preserves the same canonical order and attempt');
$pending=pa5_review($cid,$readyId);
pa5_check($pending['state']==='checkout_pending'&&empty($pending['checkout_url'])&&empty($pending['delivery_url']),'retryable provider uncertainty appears without a fictitious payment or checkout URL');
$GLOBALS['pa5ProviderHook']=null;
$confirmed=pa5_confirm($cid,$readyId)['review'];
$order=pa5_order();
$attempt=pa5_fixture_row('agent_commerce_checkout_attempts_v800',1);
$requests=$GLOBALS['pa5ProviderRequests'];
pa5_check(count($requests)===2&&$requests[0]===$requests[1]&&$requests[1]['key']===$attempt['idempotency_key'],'retry reuses the original frozen provider request and persisted idempotency key');
pa5_check($confirmed['state']==='checkout_ready'&&str_starts_with($confirmed['checkout_url'],'https://checkout.stripe.com/')&&$confirmed['order']['status']['key']==='payment_pending','verified hosted checkout URL appears while payment is pending');
pa5_check((int)$order['total_cents']===5200&&(int)$order['owner_user_id']===1&&(int)$order['connection_id']===$fixtures['seller'],'canonical order stores the reviewed total and exact merchant');
$item=agent_commerce_order_items_v800(db(),(int)$order['id'])[0];
pa5_check((int)$item['quantity']===2&&(int)$item['product_id']===$productId,'canonical order item preserves reviewed quantity and product lineage');
$repeat=pa5_confirm($cid,$readyId)['review'];
pa5_check($repeat['checkout_url']===$confirmed['checkout_url']&&count($GLOBALS['pa5ProviderRequests'])===2&&pa3_count('agent_commerce_orders_v800')===1,'duplicate success reopens the same checkout without further provider I/O');
pa5_reject(fn()=>pa5_fixture_verify_payment((int)$order['id'],true),'provider evidence from a different merchant is rejected');
pa5_check(pa5_review($cid,$readyId)['order']['status']['key']==='payment_pending'&&pa3_count('agent_commerce_payments_v800')===0,'unverified provider evidence cannot manufacture payment status');
pa5_check(pa5_seller_alerts()===[]&&pa3_count('agent_commerce_fulfillment_jobs_pa2',"event_type='announce_order'")===0,'unpaid checkout and wrong merchant evidence create no seller announcement');

$pdo->failSql='INSERT INTO notifications';
$paid=pa5_fixture_verify_payment((int)$order['id']);
$pdo->failSql='';
pa5_check($paid['payment_status']==='paid'&&pa3_count('agent_commerce_payments_v800')===1,'canonical PA2 evidence settles only the exact checkout');
pa5_check(pa5_seller_alerts()===[]&&pa3_count('agent_commerce_fulfillment_jobs_pa2',"event_type='announce_order' AND status='retry'")===1,'notification storage fault leaves payment committed and retries only the seller announcement');
db()->prepare("UPDATE agent_commerce_fulfillment_jobs_pa2 SET next_attempt_at=? WHERE order_id=? AND event_type='announce_order'")->execute(['2000-01-01 00:00:00',(int)$order['id']]);
agent_commerce_fulfillment_run_pa2(db(),20,null,(int)$order['id'],true);
$alerts=pa5_seller_alerts();
pa5_check(count($alerts)===1&&(int)$alerts[0]['user_id']===1&&(int)$alerts[0]['source_id']===(int)$order['id']
    &&$alerts[0]['source_type']==='profile_commerce_order'&&$alerts[0]['title']==='New Profile order'
    &&(int)$alerts[0]['is_read']===0&&$alerts[0]['target_url']==='/profile-commerce-products.php#orders'
    &&str_contains($alerts[0]['body'],$order['order_number'])
    &&str_contains($alerts[0]['body'],agent_commerce_money_v800(5200,'usd')),'recovery produces one unread announcement for the exact seller and verified order');
pa5_check(!preg_match('/buyer@example|receipt|PRIVATE_|checkout\.stripe|manage=/',json_encode($alerts)),'seller alert omits buyer email, private receipt and checkout links');
pa5_fixture_verify_payment((int)$order['id']);
pa5_check(pa5_seller_alerts()===$alerts&&pa3_count('agent_commerce_payments_v800')===1,'replayed verified payment cannot duplicate a seller alert or receipt');
$paidView=pa5_review($cid,$readyId);
pa5_check($paidView['order']['status']['key']==='paid'&&!empty($paidView['receipt_url'])&&empty($paidView['checkout_url']),'same conversation projects verified payment and its receipt');
profile_commerce_delivery_save_v1120(db(),1,(int)$order['id'],'PRIVATE_DELIVERY_MESSAGE','https://fixture.example.test/private-guide','Read the guide','fulfilled');
$fulfilled=pa5_review($cid,$readyId);
pa5_check($fulfilled['order']['status']['key']==='fulfilled'&&!empty($fulfilled['delivery_url'])&&!str_contains(json_encode($fulfilled),'PRIVATE_DELIVERY_MESSAGE'),'fulfilled card uses scoped secure delivery instead of exposing private fulfillment metadata');
pa5_reject(fn()=>vp3_profile_checkout_state_pa5(db(),$seller,pa3_context(3,'f'),$cid),'another visitor cannot fetch the completed order, receipt or delivery');
$visitor=pa3_context(2,'b');

$late=pa5_prepare($cid)['review'];
pa5_confirm($cid,$late['review_id']);
$lateOrder=pa5_order(1);
db()->prepare("UPDATE agent_commerce_orders_v800 SET hold_expires_at='2000-01-01 00:00:00' WHERE id=?")->execute([(int)$lateOrder['id']]);
pa5_fixture_verify_payment((int)$lateOrder['id']);
$lateView=pa5_review($cid,$late['review_id']);
pa5_check($lateView['order']['status']['key']==='payment_review'&&empty($lateView['delivery_url']),'verified late payment shows review required without releasing fulfillment');
pa5_check(pa3_count('agent_commerce_payments_v800')===2,'late payment remains a real ledger receipt');
$alerts=pa5_seller_alerts();
pa5_check(count($alerts)===2&&(int)$alerts[1]['user_id']===1&&(int)$alerts[1]['source_id']===(int)$lateOrder['id']
    &&$alerts[1]['title']==='Profile order needs review'&&str_contains($alerts[1]['body'],'before fulfillment'),'late verified payment alerts the exact seller with its review requirement');
$lateId=(int)$lateOrder['id'];
pa5_reject(fn()=>profile_commerce_set_fulfillment_v900(db(),1,$lateId,'processing'),'generic manual fulfillment cannot silently clear PA5 payment review');
pa5_reject(fn()=>profile_commerce_set_fulfillment_v900(db(),1,$lateId,'fulfilled',true),'seller review approval cannot skip processing and mark an order delivered');
pa5_reject(fn()=>profile_commerce_set_fulfillment_v900(db(),4,$lateId,'processing',true),'another seller cannot approve this order’s payment review');
$changeLineage=db()->prepare('UPDATE profile_conversation_checkouts_pa5 SET connection_id=? WHERE order_id=?');
$changeLineage->execute([$fixtures['foreign'],$lateId]);
pa5_reject(fn()=>profile_commerce_set_fulfillment_v900(db(),1,$lateId,'processing',true),'manual approval requires the exact verified PA5 purchase lineage');
$changeLineage->execute([$fixtures['seller'],$lateId]);
pa5_check(pa5_review($cid,$late['review_id'])['order']['status']['key']==='payment_review','denied approval attempts leave the buyer’s review state unchanged');
$approved=profile_commerce_set_fulfillment_v900(db(),1,$lateId,'processing',true);
pa5_check($approved['order_status']==='processing'&&profile_commerce_fulfillment_state_v900($approved)==='processing'
    &&pa5_review($cid,$late['review_id'])['order']['status']['key']==='processing','the exact seller can explicitly approve verified late payment and start fulfillment');
profile_commerce_set_fulfillment_v900(db(),1,$lateId,'processing',true);
$releaseAudit=db()->prepare("SELECT COUNT(*) FROM agent_commerce_audit_v800 WHERE order_id=? AND event_type='profile_payment_review_released'");
$releaseAudit->execute([$lateId]);
pa5_check((int)$releaseAudit->fetchColumn()===1&&count(pa5_seller_alerts())===2,'approval retry preserves one explicit audit and the original seller alert');
pa5_check(!str_contains(json_encode(db()->query('SELECT context_json FROM profile_agent_messages')->fetchAll()),'buyer@example.test'),'checkout data remains outside ordinary conversation messages');
echo 'PROFILE_CONVERSATION_CHECKOUT_PA5=PASS ('.$checks.' checks; '.db()->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
