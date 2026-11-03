<?php
declare(strict_types=1);
require __DIR__.'/fixtures/commerce-recovery-pa2.php';
$fixtureConfig=commerce_fixture_config_pa2();$pdo=commerce_fixture_connect_pa2($fixtureConfig);
register_shutdown_function(static fn()=>commerce_fixture_cleanup_pa2($fixtureConfig));
commerce_fixture_schema_pa2($pdo);$checks=0;
function creation_check_pa2(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label."\n";}
function creation_reject_pa2(callable $fn,string $label): void {try{$fn();}catch(Throwable){creation_check_pa2(true,$label);return;}throw new RuntimeException($label.' unexpectedly succeeded');}
function creation_order_pa2(string $provider='stripe'): array
{
    $f=commerce_fixture_order_pa2(db(),['provider_snapshot'=>$provider]);db()->prepare('DELETE FROM agent_commerce_checkout_attempts_v800 WHERE id=?')->execute([$f['attempt_id']]);return $f;
}
function creation_call_pa2(array $f,string $suffix=''): array
{
    return agent_commerce_create_checkout_v800(db(),agent_commerce_order_v800(db(),$f['id']),$f['connection_id'],
        'https://fixture.example.test/return?manage=fixture'.$suffix,'https://fixture.example.test/cancel?manage=fixture'.$suffix);
}
function creation_rows_pa2(array $f): array {return commerce_fixture_rows_pa2(db(),'agent_commerce_checkout_attempts_v800','order_id='.(int)$f['id']);}

foreach (['stripe','square','paypal'] as $provider) {
    $f=creation_order_pa2($provider);$writes=[];$fail=true;$nestedChecked=false;
    $GLOBALS['pa2_http']=static function(string $method,string $url,array $headers,mixed $body,bool $form) use ($provider,$f,&$writes,&$fail,&$nestedChecked): array {
        if(str_ends_with($url,'/v1/oauth2/token'))return ['status'=>200,'json'=>['access_token'=>'fixture-provider-token']];
        if($method!=='POST')throw new RuntimeException('Unexpected checkout read.');
        $rows=creation_rows_pa2($f);creation_check_pa2(count($rows)===1&&$rows[0]['status']==='creating'&&!db()->inTransaction(),$provider.' saves exactly one attempt before provider I/O');
        $a=$rows[0];creation_check_pa2($a['provider_account_id']===$f['account']&&$a['creation_request_json']!=='',$provider.' saves exact merchant and frozen checkout request');
        creation_check_pa2(!str_contains($a['creation_request_json'],'fixture-access-token')&&!str_contains($a['creation_request_json'],'Authorization'),$provider.' persisted request excludes credential headers');
        if(!$nestedChecked){$nestedChecked=true;$n=count($writes);creation_reject_pa2(fn()=>creation_call_pa2($f),$provider.' live lease blocks a concurrent checkout submit');creation_check_pa2(count($writes)===$n,$provider.' concurrent submit performs no provider request');}
        $key=$provider==='square'?($body['idempotency_key']??''):'';
        foreach($headers as $header)if(str_starts_with($header,$provider==='stripe'?'Idempotency-Key: ':'PayPal-Request-Id: '))$key=explode(': ',$header,2)[1];
        creation_check_pa2($key===$a['idempotency_key']&&strlen($key)===64,$provider.' uses the persisted operation key');
        $writes[]=['body'=>$body,'key'=>$key,'form'=>$form];
        if($fail)throw new RuntimeException('SECRET response lost after provider accepted the request');
        $session='checkout_'.$provider.'_'.$f['id'];$order='order_'.$provider.'_'.$f['id'];$checkout='https://provider.example.test/'.$session;
        $json=match($provider){'stripe'=>['id'=>$session,'url'=>$checkout],'square'=>['payment_link'=>['id'=>$session,'order_id'=>$order,'url'=>$checkout]],'paypal'=>['id'=>$session,'links'=>[['rel'=>'approve','href'=>$checkout]]],};
        return ['status'=>200,'json'=>$json];
    };
    creation_reject_pa2(fn()=>creation_call_pa2($f),$provider.' uncertain provider response is unsuccessful');
    $a=creation_rows_pa2($f)[0];creation_check_pa2($a['status']==='creating'&&$a['creation_lease_token']===''&&$a['last_error_class']==='checkout_provider_uncertain',$provider.' uncertainty keeps a retryable attempt with safe error state');
    $fail=false;$created=creation_call_pa2($f,'-changed-caller-url');
    creation_check_pa2(count($writes)===2&&$writes[0]===$writes[1],$provider.' uncertain retry reuses identical provider body and idempotency key');
    creation_check_pa2(count(creation_rows_pa2($f))===1&&$created['status']==='open'&&$created['external_payment_id']==='',$provider.' successful retry completes the original attempt without inventing a payment');
    creation_check_pa2($created['provider_order_id']!==''&&$created['provider_account_id']===$f['account'],$provider.' keeps immutable provider order and merchant identity');
    if($provider==='stripe')creation_check_pa2($writes[0]['body']['expires_at']>=time()+1790&&$writes[0]['body']['expires_at']<=time()+86400,'Stripe hosted checkout expiration is explicitly bounded');
    if($provider==='square')creation_check_pa2($created['provider_order_id']!==$created['external_session_id']&&$created['provider_location_id']!=='','Square Link, Order and location identities stay distinct');
    $again=creation_call_pa2($f);creation_check_pa2($again['id']===$created['id']&&count($writes)===2,$provider.' repeat submit returns the existing checkout without new provider I/O');
    db()->prepare('UPDATE agent_commerce_orders_v800 SET amount_due_cents=6000 WHERE id=?')->execute([$f['id']]);
    creation_reject_pa2(fn()=>creation_call_pa2($f),$provider.' changed order terms cannot reuse a stale checkout');
    creation_check_pa2(count($writes)===2,$provider.' changed terms are rejected before provider I/O');
}

// The provider may complete its request even when local completion storage fails.
putenv('VP3_COMMERCE_PLATFORM_FEE_BPS=500');
foreach ([2000,3000] as $due) {
    $feeOrder=creation_order_pa2();$order=agent_commerce_order_v800($pdo,$feeOrder['id']);$order['amount_due_cents']=$due;
    $request=agent_commerce_checkout_request_pa2($pdo,$order,agent_commerce_connection_v800($pdo,$feeOrder['connection_id']),'https://fixture.example.test/return?x=1','https://fixture.example.test/cancel');
    creation_check_pa2($request['body']['payment_intent_data']['application_fee_amount']===(int)($due*0.05),'deposit and balance fees use each original checkout amount');
}
putenv('VP3_COMMERCE_PLATFORM_FEE_BPS=0');
$f=creation_order_pa2();$requests=[];$failPersistence=true;
$GLOBALS['pa2_http']=static function(string $method,string $url,array $headers,mixed $body,bool $form) use ($f,&$requests,&$failPersistence): array {
    $requests[]=[$headers,$body];if($failPersistence)db()->failSql='SET external_session_id=';
    return ['status'=>200,'json'=>['id'=>'cs_persist_'.$f['id'],'url'=>'https://provider.example.test/persist']];
};
creation_reject_pa2(fn()=>creation_call_pa2($f),'completion write failure leaves the provider outcome unconfirmed');$pdo->failSql='';
creation_check_pa2(count(creation_rows_pa2($f))===1&&creation_rows_pa2($f)[0]['status']==='creating','completion write failure retains the original creating attempt');
$failPersistence=false;$saved=creation_call_pa2($f);
creation_check_pa2($requests[0]===$requests[1]&&$saved['status']==='open'&&count(creation_rows_pa2($f))===1,'completion retry cannot create another operation after lost persistence');

$f=creation_order_pa2();$GLOBALS['pa2_http']=static function()use($f):array {
    db()->prepare('UPDATE agent_commerce_checkout_attempts_v800 SET creation_lease_token=? WHERE order_id=?')->execute([str_repeat('b',64),$f['id']]);
    return ['status'=>200,'json'=>['id'=>'cs_stale','url'=>'https://provider.example.test/stale']];
};
creation_reject_pa2(fn()=>creation_call_pa2($f),'stale creation worker cannot publish its checkout result');
$a=creation_rows_pa2($f)[0];creation_check_pa2($a['status']==='creating'&&$a['creation_lease_token']===str_repeat('b',64)&&str_starts_with($a['external_session_id'],'pending:'),'stale failure cannot clear or overwrite a successor’s lease');

foreach (['amount','currency','provider'] as $change) {
    $f=creation_order_pa2();
    $GLOBALS['pa2_http']=static function()use($f,$change):array {
        $sql=match($change){'amount'=>'amount_due_cents=6000','currency'=>"currency='eur'",'provider'=>"external_account_snapshot='changed_account'"};
        db()->prepare('UPDATE agent_commerce_orders_v800 SET '.$sql.' WHERE id=?')->execute([$f['id']]);
        return ['status'=>200,'json'=>['id'=>'cs_changed_'.$f['id'],'url'=>'https://provider.example.test/changed']];
    };
    creation_reject_pa2(fn()=>creation_call_pa2($f),'checkout '.$change.' change during provider I/O requires new review');
    $changed=creation_rows_pa2($f)[0];
    creation_check_pa2($changed['status']!=='open'&&$changed['external_session_id']==='cs_changed_'.$f['id'],'changed '.$change.' retains returned identity for reconciliation without publishing an active checkout');
}

$f=creation_order_pa2();$pdo->beginTransaction();$before=count($GLOBALS['pa2_http_calls']);
creation_reject_pa2(fn()=>creation_call_pa2($f),'uncommitted order cannot initiate a remote checkout');$pdo->rollBack();
creation_check_pa2(creation_rows_pa2($f)===[]&&count($GLOBALS['pa2_http_calls'])===$before,'uncommitted checkout rejection has no storage or provider effects');
$pdo->prepare("UPDATE agent_commerce_orders_v800 SET hold_expires_at='2000-01-01 00:00:00' WHERE id=?")->execute([$f['id']]);
creation_reject_pa2(fn()=>creation_call_pa2($f),'expired hold cannot initiate a provider checkout');

// Exact Square return integration uses real provider proof and real receipt writes.
$square=commerce_fixture_order_pa2($pdo,['provider_snapshot'=>'square'],['provider_order_id'=>'square_exact_order']);
$attempt=agent_commerce_checkout_attempt_v800($pdo,$square['attempt_id']);$wrong=true;$urls=[];
$GLOBALS['pa2_http']=static function(string $method,string $url)use($square,$attempt,&$wrong,&$urls):array {
    $urls[]=$url;$money=['amount'=>5000,'currency'=>'USD'];$location=$attempt['provider_location_id'];
    if(str_ends_with($url,'/v2/orders/square_exact_order'))return ['status'=>200,'json'=>['order'=>['id'=>'square_exact_order','location_id'=>$location,'state'=>'COMPLETED','total_money'=>$money,'tenders'=>[['payment_id'=>$square['payment'],'amount_money'=>$money]]]]];
    if(str_ends_with($url,'/v2/payments/'.$square['payment']))return ['status'=>200,'json'=>['payment'=>['id'=>$square['payment'],'order_id'=>$wrong?'other_order':'square_exact_order','location_id'=>$location,'status'=>'COMPLETED','amount_money'=>$money]]];
    throw new RuntimeException('Unexpected provider URL.');
};
creation_reject_pa2(fn()=>agent_commerce_return_verify_v800($pdo,agent_commerce_order_v800($pdo,$square['id']),'square',[]),'unrelated completed Square payment cannot settle the local order');
creation_check_pa2(commerce_fixture_rows_pa2($pdo,'agent_commerce_payments_v800','order_id='.$square['id'])===[],'unrelated Square payment creates no receipt');
$wrong=false;$paid=agent_commerce_return_verify_v800($pdo,agent_commerce_order_v800($pdo,$square['id']),'square',[]);
creation_check_pa2($paid['payment_status']==='paid'&&(int)$paid['amount_paid_cents']===5000,'exact Square Order-to-Payment evidence settles the matching checkout');
$saved=agent_commerce_checkout_attempt_v800($pdo,$square['attempt_id']);
creation_check_pa2($saved['provider_order_id']==='square_exact_order'&&$saved['external_payment_id']===$square['payment'],'saving Square payment preserves immutable provider Order identity');
$lookup=agent_commerce_attempt_by_external_v800($pdo,'square','square_exact_order');
creation_check_pa2($lookup&&(int)$lookup['id']===$square['attempt_id'],'original Square Order lookup survives payment completion');
creation_check_pa2(!str_contains(implode(' ',$urls),'/v2/payments?'),'Square return verification never searches an account-wide payment list');
echo 'COMMERCE_CHECKOUT_CREATION_PA2=PASS driver='.$fixtureConfig['driver'].' checks='.$checks."\n";
