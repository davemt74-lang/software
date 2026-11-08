<?php
declare(strict_types=1);

require __DIR__.'/fixtures/commerce-recovery-pa2.php';
require_once dirname(__DIR__).'/includes/profile-agent.php';
require_once dirname(__DIR__).'/includes/media-studio-v86.php';
require_once dirname(__DIR__).'/includes/profile-product-promotion-v1.php';
require_once dirname(__DIR__).'/includes/profile-promotion-attribution-v1.php';

function attribution_check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}

function attribution_counts(int $clicks, int $starts=0, int $paid=0, int $refunded=0): array
{
    return ['clicks'=>$clicks,'checkout_starts'=>$starts,'paid_orders'=>$paid,'refunded_orders'=>$refunded];
}

function attribution_checkout(PDO $pdo, array $order, int $connection, string $session): array
{
    $GLOBALS['pa2_http'] = static function(string $method, string $url) use ($session): array {
        if ($method !== 'POST' || $url !== 'https://api.stripe.com/v1/checkout/sessions') {
            throw new RuntimeException('Unexpected fixture checkout request.');
        }
        return ['status'=>200,'json'=>['id'=>$session,'url'=>'https://checkout.example.test/'.$session]];
    };
    return agent_commerce_create_checkout_v800($pdo,$order,$connection,
        'https://fixture.example.test/return','https://fixture.example.test/cancel');
}

function attribution_pay(PDO $pdo, array $order, array $attempt): array
{
    $payment = ['id'=>(int)$order['id'],'account'=>$attempt['provider_account_id'],
        'session'=>$attempt['external_session_id'],'payment'=>'pi_attribution_'.$order['id']];
    $GLOBALS['pa2_http'] = commerce_fixture_stripe_transport_pa2([$payment]);
    $event = commerce_fixture_stripe_pa2($payment);
    agent_commerce_process_webhook_v800($pdo,'stripe',$event['payload'],$event['headers']);
    agent_commerce_process_webhook_v800($pdo,'stripe',$event['payload'],$event['headers']);
    return agent_commerce_order_v800($pdo,(int)$order['id']);
}

$fixture = commerce_fixture_config_pa2();
$pdo = commerce_fixture_connect_pa2($fixture);
$root = sys_get_temp_dir().'/vp3-promotion-attribution-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$root);
$checks = 0;
try {
    commerce_fixture_schema_pa2($pdo);
    attribution_check(profile_promotion_attribution_ready_v1($pdo),'canonical Commerce install creates attribution storage');
    $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    $pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(80) NOT NULL DEFAULT 'Seller'");
    $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'user'");
    $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(150) NOT NULL DEFAULT ''");
    $pdo->exec('CREATE TABLE user_profiles(user_id INT UNSIGNED PRIMARY KEY,username VARCHAR(80),is_public INTEGER NOT NULL)');
    $pdo->exec("INSERT INTO user_profiles VALUES(1,'seller',1),(2,'other',1)");
    $pdo->exec('CREATE TABLE user_media_assets(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,
        media_type VARCHAR(12),title VARCHAR(190),mime_type VARCHAR(30),file_path VARCHAR(250),file_size INTEGER NOT NULL,
        duration_seconds INTEGER DEFAULT 0,source VARCHAR(30),metadata_json TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    mkdir($root.'/private/user-media/1',0700,true);
    $assetPath = 'private/user-media/1/design.png';
    $artwork = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWZkAAAAASUVORK5CYII=',true);
    file_put_contents($root.'/'.$assetPath,$artwork);
    $asset = commerce_fixture_insert_pa2($pdo,'user_media_assets',[
        'user_id'=>1,'media_type'=>'photo','title'=>'Artwork','mime_type'=>'image/png','file_path'=>$assetPath,
        'file_size'=>strlen($artwork),'source'=>'promotion_design','metadata_json'=>'{}',
    ]);
    $connection = commerce_fixture_insert_pa2($pdo,'agent_commerce_provider_connections_v800',[
        'owner_user_id'=>1,'provider'=>'stripe','external_account_id'=>'acct_attribution_fixture','status'=>'connected',
        'capabilities_json'=>'{"checkout":true}',
    ]);
    $productValues = ['owner_user_id'=>1,'product_key'=>'fixture-mug','title'=>'Blue mug','product_type'=>'physical',
        'fulfillment_type'=>'local_pickup','payment_mode'=>'full','price_cents'=>5000,'currency'=>'usd','provider_mode'=>'fixed',
        'fixed_connection_id'=>$connection,'metadata_json'=>'{"profile_visibility":"public","profile_slug":"blue-mug"}'];
    $productId = commerce_fixture_insert_pa2($pdo,'agent_commerce_products_v800',$productValues);
    $otherProductId = commerce_fixture_insert_pa2($pdo,'agent_commerce_products_v800',array_replace($productValues,[
        'owner_user_id'=>2,'fixed_connection_id'=>null,'provider_mode'=>'guest_choice',
    ]));
    $differentProductId = commerce_fixture_insert_pa2($pdo,'agent_commerce_products_v800',array_replace($productValues,[
        'product_key'=>'fixture-kettle','title'=>'Kettle','metadata_json'=>'{"profile_visibility":"public","profile_slug":"kettle"}',
    ]));
    $product = agent_commerce_product_v800($pdo,$productId,1);
    $draft = str_repeat('d',64);
    commerce_fixture_insert_pa2($pdo,'agent_commerce_marketing_drafts_v1',[
        'product_id'=>$productId,'owner_user_id'=>1,'request_key'=>$draft,'draft_json'=>'{}',
    ]);
    $first = str_repeat('1',32);
    $second = str_repeat('2',32);
    $republished = str_repeat('3',32);
    $seedPromotion = static function(string $key, string $id, string $headline) use ($pdo,$productId,$draft,$asset,$artwork): void {
        commerce_fixture_insert_pa2($pdo,'agent_commerce_promotions_v1',[
            'id'=>$id,'product_id'=>$productId,'owner_user_id'=>1,'draft_key'=>$draft,'agent_id'=>7,'conversation_id'=>11,
            'source_asset_id'=>$asset+1,'source_sha256'=>hash('sha256','original photo'),'settings_json'=>json_encode([
                'headline'=>$headline,'copy'=>'A ceramic blue mug.','level'=>1,
            ],JSON_THROW_ON_ERROR),'asset_id'=>$asset,'asset_sha256'=>hash('sha256',$artwork),'public_key'=>$key,
            'public_product_url'=>'/seller/product/blue-mug','public_cta'=>'View product','status'=>'published',
        ]);
    };
    $creativeA = str_repeat('4',32);
    $creativeB = str_repeat('5',32);
    $capturePublication = static function(string $key, int $campaign) use ($pdo): void {
        agent_commerce_atomic_pa2($pdo,static function() use ($pdo,$key,$campaign): void {
            $query = $pdo->prepare('SELECT * FROM agent_commerce_promotions_v1 WHERE public_key=?');
            $query->execute([$key]);
            profile_promotion_attribution_register_v1($pdo,$query->fetch(),$campaign);
        });
    };
    $click = static fn(string $key, string $placement, string $id, int $owner=1): bool =>
        profile_promotion_attribution_record_click_v1($pdo,$owner,$key,$placement,str_repeat($id,32));
    $summary = static fn(string $key, int $owner=1): array => profile_promotion_attribution_summary_v1($pdo,$owner,$key);
    $placements = static fn(string $key): array => $summary($key)['placements'];
    $clearReview = $pdo->prepare('UPDATE agent_promotion_publications_v1 SET next_review_at=NULL WHERE publication_id=?');
    $reviewDue = static fn(string $key): bool => agent_commerce_date_pa2($summary($key)['publication']['next_review_at'] ?? null)>time();
    $createOrder = static function(array $product, ?string $id, ?int $provider=null) use ($pdo): array {
        return agent_commerce_create_order_v800($pdo,$product,[
            'connection_id'=>$provider,'payer_email'=>'fixture-buyer@example.test',
            'metadata'=>$id===null ? [] : ['product_promotion_click_v1'=>str_repeat($id,32)],
        ]);
    };
    $bind = static fn(array $order, string $id): bool =>
        profile_promotion_attribution_bind_order_v1($pdo,(int)$order['id'],str_repeat($id,32));

    $seedPromotion($first,$creativeA,'Morning mug');
    $capturePublication($first,41);
    $capturePublication($first,41);
    try {
        $capturePublication($first,42);
        throw new LogicException('A published key accepted a different campaign.');
    } catch (DomainException $error) {
        attribution_check($error->getCode()===409 && (int)$summary($first)['publication']['campaign_id']===41,
            'the same publication key cannot be moved to another campaign');
    }
    attribution_check($click($first,'profile','a') && $click($first,'profile','a')
        && $placements($first)['profile']===attribution_counts(1), 'a replay keeps one Profile click for the exact publication');
    attribution_check(!$click($first,'profile_agent','a') && !$click($first,'profile','c',2)
        && $click($first,'profile_agent','b') && $placements($first)['profile_agent']===attribution_counts(1),
        'Profile Agent has separate evidence and cannot reuse a Profile or another owner’s click');

    if (!agent_commerce_sqlite_pa2($pdo)) $pdo->exec("SET SESSION time_zone='-07:00'");
    try {
        $orderA = $createOrder($product,'a',$connection);
    } finally {
        if (!agent_commerce_sqlite_pa2($pdo)) $pdo->exec("SET SESSION time_zone='+00:00'");
    }
    if (agent_commerce_sqlite_pa2($pdo)) {
        $pdo->prepare('UPDATE agent_commerce_orders_v800 SET created_at=? WHERE id=?')
            ->execute([gmdate('Y-m-d H:i:s',time()-25200),(int)$orderA['id']]);
        $orderA = agent_commerce_order_v800($pdo,(int)$orderA['id']);
    }
    attribution_check(agent_commerce_date_pa2($orderA['created_at'])<time()-21600 && $bind($orderA,'a'),
        'a non-UTC Commerce timestamp cannot shift the server-frozen attribution window');
    $anonymous = $createOrder($product,null,$connection);
    $foreign = $createOrder(agent_commerce_product_v800($pdo,$otherProductId,2),'a');
    $unrelated = $createOrder(agent_commerce_product_v800($pdo,$differentProductId,1),'a',$connection);
    attribution_check($bind($orderA,'a') && $bind($orderA,'a') && !$bind($orderA,'b')
        && !$bind($anonymous,'a') && !$bind($foreign,'a') && !$bind($unrelated,'a')
        && $placements($first)['profile']===attribution_counts(1),
        'checkout freezes one server click on the exact owner and product, before a provider checkout exists');
    $changeClickTime = $pdo->prepare('UPDATE agent_promotion_clicks_v1 SET created_at=? WHERE click_id=?');
    $changeClickTime->execute([gmdate('Y-m-d H:i:s',time()-90000),str_repeat('b',32)]);
    $outsideWindow = $createOrder($product,'b',$connection);
    attribution_check(!$bind($outsideWindow,'b'), 'a purchase outside the click’s 24-hour window cannot claim attribution');
    $changeClickTime->execute([gmdate('Y-m-d H:i:s'),str_repeat('b',32)]);
    $uncertain = $createOrder($product,'b',$connection);
    if (!$bind($uncertain,'b')) throw new LogicException('The current click was unavailable.');
    $GLOBALS['pa2_http'] = static fn(): array => throw new RuntimeException('Fixture provider did not confirm creation.');
    try {
        agent_commerce_create_checkout_v800($pdo,$uncertain,$connection,
            'https://fixture.example.test/return','https://fixture.example.test/cancel');
        throw new LogicException('An unavailable provider unexpectedly opened a checkout.');
    } catch (RuntimeException $error) {
        attribution_check($error->getCode()===503 && $placements($first)['profile_agent']===attribution_counts(1),
            'a durable pending provider attempt is not a checkout start');
    }
    $clearReview->execute([$first]);
    $attemptA = attribution_checkout($pdo,$orderA,$connection,'cs_attribution_a');
    $repeat = attribution_checkout($pdo,$orderA,$connection,'cs_unused_retry');
    attribution_check((int)$repeat['id']===(int)$attemptA['id']
        && $placements($first)['profile']===attribution_counts(1,1) && $reviewDue($first),
        'canonical checkout creation counts once across retries and queues the private review');
    $pdo->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET status='expired' WHERE id=?")->execute([$attemptA['id']]);
    $attemptA = attribution_checkout($pdo,$orderA,$connection,'cs_attribution_a_replacement');
    attribution_check($placements($first)['profile']===attribution_counts(1,1), 'two provider attempts still describe one attributed checkout');

    $pdo->prepare("UPDATE agent_commerce_orders_v800 SET payment_status='paid',order_status='paid',paid_at=?,amount_paid_cents=5000 WHERE id=?")
        ->execute([gmdate('Y-m-d H:i:s'),$orderA['id']]);
    attribution_check($placements($first)['profile']===attribution_counts(1,1), 'an order status without a completed provider receipt is not a paid conversion');
    $pdo->prepare("UPDATE agent_commerce_orders_v800 SET payment_status='awaiting_payment',order_status='pending_payment',paid_at=NULL,amount_paid_cents=0 WHERE id=?")
        ->execute([$orderA['id']]);

    $pdo->prepare("UPDATE agent_commerce_promotions_v1 SET status='paused' WHERE id=?")->execute([$creativeA]);
    attribution_check(!$click($first,'profile','c'), 'a paused publication cannot acquire a new click');
    $seedPromotion($second,$creativeB,'Desk mug');
    $capturePublication($second,41);
    attribution_check($click($second,'profile','c') && $click($second,'profile_agent','d'),
        'the replacement creative measures both of its own placements');
    $orderB = $createOrder($product,'d',$connection);
    attribution_check($bind($orderB,'d'), 'the later checkout is bound to the new creative and its Profile Agent placement');
    $attemptB = attribution_checkout($pdo,$orderB,$connection,'cs_attribution_b');
    $clearReview->execute([$first]);
    $paidA = attribution_pay($pdo,$orderA,$attemptA);
    attribution_check($paidA['payment_status']==='paid' && $placements($first)['profile']===attribution_counts(1,1,1)
        && $placements($second)['profile_agent']===attribution_counts(1,1) && $reviewDue($first),
        'verified payment after a pause credits the first publication once and queues another review');
    $paidB = attribution_pay($pdo,$orderB,$attemptB);
    attribution_check($paidB['payment_status']==='paid' && $placements($second)['profile_agent']===attribution_counts(1,1,1)
        && $placements($second)['profile']===attribution_counts(1) && $placements($first)['profile_agent']===attribution_counts(1),
        'a purchase of the same product credits only the second creative’s selected placement');

    $receipt = commerce_fixture_rows_pa2($pdo,'agent_commerce_payments_v800','order_id='.(int)$orderA['id'])[0];
    $refundValues = ['order_id'=>$orderA['id'],'payment_id'=>$receipt['id'],'checkout_attempt_id'=>$attemptA['id'],
        'provider'=>'stripe','external_refund_id'=>'re_attribution_1','amount_cents'=>1000,'status'=>'pending'];
    $refund = commerce_fixture_insert_pa2($pdo,'agent_commerce_refunds_v800',$refundValues);
    $clearReview->execute([$first]);
    agent_commerce_refresh_refund_totals_v800($pdo,(int)$orderA['id']);
    attribution_check($placements($first)['profile']===attribution_counts(1,1,1) && !$reviewDue($first),
        'a pending refund is neither counted as completed nor sent for a new review');
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare("UPDATE agent_commerce_refunds_v800 SET status='completed',completed_at=? WHERE id=?")->execute([$now,$refund]);
    commerce_fixture_insert_pa2($pdo,'agent_commerce_refunds_v800',array_replace($refundValues,[
        'external_refund_id'=>'re_attribution_2','status'=>'completed','amount_cents'=>4000,'completed_at'=>$now,
    ]));
    $refunded = agent_commerce_refresh_refund_totals_v800($pdo,(int)$orderA['id']);
    attribution_check($refunded['payment_status']==='refunded' && $placements($first)['profile']===attribution_counts(1,1,1,1)
        && $placements($second)['profile_agent']===attribution_counts(1,1,1) && $reviewDue($first),
        'completed refund slices count once per order and queue a review of the paid creative');

    $pdo->prepare("UPDATE agent_commerce_promotions_v1 SET status='paused' WHERE id=?")->execute([$creativeB]);
    $pdo->prepare("UPDATE agent_commerce_promotions_v1 SET status='published',public_key=? WHERE id=?")
        ->execute([$republished,$creativeA]);
    $capturePublication($republished,42);
    attribution_check(!$click($first,'profile','e') && $click($republished,'profile','e')
        && $placements($republished)['profile']===attribution_counts(1),
        'republication of the same creative has a fresh key and cannot add clicks to the original publication');
    $campaign = profile_promotion_attribution_campaign_summary_v1($pdo,1,41);
    attribution_check($campaign['profile']===attribution_counts(2,1,1,1)
        && $campaign['profile_agent']===attribution_counts(2,1,1)
        && profile_promotion_attribution_campaign_summary_v1($pdo,1,42)['profile']===attribution_counts(1),
        'historical outcomes stay with the campaign approved for each publication');
    $otherView = $summary($first,2);
    $privateView = $summary($first);
    attribution_check(empty($otherView['publication']) && empty($otherView['placements'])
        && profile_promotion_attribution_campaign_summary_v1($pdo,2,41)===[]
        && (int)$privateView['publication']['campaign_id']===41 && (string)$privateView['publication']['promotion_id']===$creativeA
        && !str_contains(json_encode($privateView,JSON_THROW_ON_ERROR),'fixture-buyer@example.test'),
        'seller and campaign views are owner scoped and expose only aggregate buyer evidence');
    $pdo->prepare("UPDATE agent_commerce_promotions_v1 SET status='paused' WHERE id=?")->execute([$creativeA]);
    $legacy = str_repeat('6',32);
    $seedPromotion($legacy,str_repeat('7',32),'Previously published mug');
    $pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1');
    attribution_check(!$click($legacy,'profile','f') && $summary($legacy)===[],
        'a hidden profile cannot create a publication or record a visit');
    $pdo->exec('UPDATE user_profiles SET is_public=1 WHERE user_id=1');
    attribution_check($click($legacy,'profile','f') && $placements($legacy)['profile']===attribution_counts(1)
        && $summary($legacy)['publication']['campaign_id']===null,
        'the first verified visit adopts an older public card without assigning it to a campaign');
    echo 'PROFILE_PROMOTION_ATTRIBUTION_V1=PASS ('.$checks.' cases, '.$fixture['driver'].')'.PHP_EOL;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (isset($assetPath)) @unlink($root.'/'.$assetPath);
    foreach (['/private/user-media/1','/private/user-media','/private',''] as $path) if (is_dir($root.$path)) rmdir($root.$path);
    commerce_fixture_cleanup_pa2($fixture);
}
