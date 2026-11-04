<?php
declare(strict_types=1);

require_once __DIR__.'/profile-handoff-pa3.php';

// The provider boundary is the only substituted part of the checkout service.
putenv('VP3_COMMERCE_ENCRYPTION_KEY=pa5-fixture-encryption');
putenv('VP3_COMMERCE_PLATFORM_FEE_BPS=0');
putenv('VP3_COMMERCE_STRIPE_SECRET_KEY=pa5-fixture-stripe-key');
putenv('VP3_COMMERCE_STRIPE_CONNECT_CLIENT_ID=pa5-fixture-connect');
$GLOBALS['config']['site']['base_url']='https://fixture.example.test';
$GLOBALS['pa5ProviderRequests']=[];
$GLOBALS['pa5ProviderHook']=null;

function agent_commerce_provider_http_pa2(string $method,string $url,array $headers=[],mixed $body=null,bool $form=false): array
{
    $log=getenv('VP3_PA5_PROVIDER_LOG');
    if($method==='GET'&&$log)file_put_contents($log,json_encode(['method'=>$method,'url'=>$url],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    if($method==='GET'&&($_SERVER['HTTP_X_FIXTURE_PROVIDER']??'')==='settled'
        &&in_array('Stripe-Account: acct_pa5_seller',$headers,true)
        &&preg_match('~^https://api.stripe.com/v1/(checkout/sessions/cs|payment_intents/pi)_pa5_([0-9]+)$~D',$url,$matches)){
        $orderId=(int)$matches[2];
        $evidence=pa5_fixture_stripe_evidence($orderId,pa5_fixture_attempt($orderId),'acct_pa5_seller');
        return ['status'=>200,'json'=>$evidence[$matches[1]==='checkout/sessions/cs'?'session':'payment_intent']];
    }
    if($method!=='POST'||$url!=='https://api.stripe.com/v1/checkout/sessions'||!$form||!is_array($body)){
        throw new RuntimeException('Unexpected PA5 fixture provider request.');
    }
    $request=['key'=>'','account'=>'','body'=>$body];
    foreach($headers as $header){
        if(str_starts_with($header,'Idempotency-Key: '))$request['key']=substr($header,17);
        if(str_starts_with($header,'Stripe-Account: '))$request['account']=substr($header,16);
    }
    $GLOBALS['pa5ProviderRequests'][]=$request;
    if($log)file_put_contents($log,json_encode($request,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    if(is_callable($GLOBALS['pa5ProviderHook']))($GLOBALS['pa5ProviderHook'])($request);
    if(($_SERVER['HTTP_X_FIXTURE_PROVIDER']??'')==='uncertain')throw new RuntimeException('PRIVATE_PROVIDER_DIAGNOSTIC');
    $order=(int)($body['metadata']['vp3_commerce_order_id']??0);
    if(!$order||strlen($request['key'])!==64||$request['account']!=='acct_pa5_seller')throw new RuntimeException('Unexpected PA5 provider identity.');
    return ['status'=>200,'json'=>['id'=>'cs_pa5_'.$order,'url'=>'https://checkout.stripe.com/c/pay/cs_pa5_'.$order]];
}

require_once dirname(__DIR__,2).'/includes/agent-commerce-v800.php';
require_once dirname(__DIR__,2).'/includes/agent-commerce-provider-evidence-pa2.php';
require_once dirname(__DIR__,2).'/includes/profile-conversation-checkout-pa5.php';

function pa5_fixture_insert(string $table,array $values): int
{
    $keys=implode(',',array_keys($values));
    $params=implode(',',array_fill(0,count($values),'?'));
    db()->prepare("INSERT INTO $table ($keys) VALUES ($params)")->execute(array_values($values));
    return (int)db()->lastInsertId();
}

function pa5_fixture_product(string $slug,int $owner,int $connection,array $changes=[]): int
{
    return pa5_fixture_insert('agent_commerce_products_v800',array_replace([
        'owner_user_id'=>$owner,
        'product_key'=>$slug,
        'title'=>ucwords(str_replace('-',' ',$slug)),
        'description'=>'Fixture purchase description',
        'product_type'=>'digital',
        'fulfillment_type'=>'digital',
        'payment_mode'=>'full',
        'price_cents'=>2500,
        'currency'=>'usd',
        'provider_mode'=>'fixed',
        'fixed_connection_id'=>$connection,
        'hold_minutes'=>30,
        'refund_before_hours'=>24,
        'cancellation_fee_cents'=>300,
        'cancellation_policy'=>'Fixture cancellation terms',
        'metadata_json'=>json_encode(['profile_visibility'=>'public','profile_slug'=>$slug],JSON_THROW_ON_ERROR),
    ],$changes));
}

function pa5_fixture_init(): array
{
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        db()->exec('CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INTEGER,display_name VARCHAR(190),is_active INTEGER)');
    }
    pa3_reset();
    agent_commerce_ensure_schema_v800(db());
    vp3_profile_checkout_ensure_schema_pa5(db());
    $connection=static fn(int $owner,string $account,string $status='connected')=>pa5_fixture_insert('agent_commerce_provider_connections_v800',[
        'owner_user_id'=>$owner,
        'provider'=>'stripe',
        'external_account_id'=>$account,
        'account_label'=>'PRIVATE_ACCOUNT_LABEL',
        'access_token_ciphertext'=>agent_commerce_encrypt_v800('PRIVATE_PROVIDER_TOKEN'),
        'status'=>$status,
    ]);
    $seller=$connection(1,'acct_pa5_seller');
    $foreign=$connection(4,'acct_pa5_foreign');
    $disabled=$connection(1,'acct_pa5_disabled','disconnected');
    $product=pa5_fixture_product('field-guide',1,$seller);
    pa5_fixture_product('private-guide',1,$seller,['metadata_json'=>'{"profile_visibility":"hidden"}']);
    pa5_fixture_product('foreign-guide',4,$foreign);
    $appointment=pa5_fixture_product('visit',1,$seller,['product_type'=>'service','fulfillment_type'=>'appointment']);
    pa5_fixture_insert('agent_commerce_product_bindings_v800',['product_id'=>$appointment,'binding_type'=>'appointment_event_type','binding_id'=>22]);
    pa5_fixture_product('physical',1,$seller,['product_type'=>'physical','fulfillment_type'=>'physical']);
    pa5_fixture_product('disconnected',1,$disabled);
    pa5_fixture_product('inactive',1,$seller,['is_active'=>0]);
    return compact('seller','foreign','disabled','product');
}

function pa5_fixture_row(string $table,int $id): array
{
    $q=db()->prepare("SELECT * FROM $table WHERE id=?");
    $q->execute([$id]);
    return $q->fetch()?:throw new RuntimeException('Missing fixture row.');
}

function pa5_fixture_attempt(int $orderId): array
{
    $q=db()->prepare('SELECT * FROM agent_commerce_checkout_attempts_v800 WHERE order_id=?');
    $q->execute([$orderId]);
    return $q->fetch()?:throw new RuntimeException('Missing fixture checkout attempt.');
}

function pa5_fixture_stripe_evidence(int $orderId,array $attempt,string $account): array
{
    $session=(string)$attempt['external_session_id'];
    $payment='pi_pa5_'.$orderId;
    $amount=(int)$attempt['amount_cents'];
    return [
        'account_id'=>$account,
        'session'=>[
            'id'=>$session,'object'=>'checkout.session','mode'=>'payment','status'=>'complete',
            'payment_status'=>'paid','amount_total'=>$amount,'currency'=>'usd','payment_intent'=>$payment,
            'metadata'=>['vp3_commerce_order_id'=>(string)$orderId],
        ],
        'payment_intent'=>[
            'id'=>$payment,'object'=>'payment_intent','status'=>'succeeded','amount'=>$amount,
            'amount_received'=>$amount,'currency'=>'usd','metadata'=>['vp3_commerce_order_id'=>(string)$orderId],
        ],
    ];
}

function pa5_fixture_verify_payment(int $orderId,bool $wrongMerchant=false): array
{
    $pdo=db();
    $order=agent_commerce_order_v800($pdo,$orderId);
    $attempt=pa5_fixture_attempt($orderId);
    $connection=agent_commerce_connection_v800($pdo,(int)$order['connection_id']);
    $account=$wrongMerchant?'acct_wrong':$connection['external_account_id'];
    $evidence=agent_commerce_provider_evidence_pa2('stripe',$attempt,$order,$connection,pa5_fixture_stripe_evidence($orderId,$attempt,$account));
    return agent_commerce_mark_paid_v800($pdo,$orderId,'stripe',$evidence['external_session_id'],$evidence['external_payment_id'],$evidence['amount_cents'],$evidence['currency'],$evidence);
}
