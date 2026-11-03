<?php
declare(strict_types=1);
require __DIR__.'/commerce-recovery-pa2.php';
$operation=$argv[1]??'';$file=$argv[2]??'';
if ($operation==='init') {
    $database=commerce_fixture_config_pa2(dirname($file).'/commerce.sqlite');$pdo=commerce_fixture_connect_pa2($database);
    commerce_fixture_schema_pa2($pdo);
    $orders=['normal'=>commerce_fixture_order_pa2($pdo),'early'=>commerce_fixture_order_pa2($pdo),
        'late'=>commerce_fixture_order_pa2($pdo,['payment_status'=>'expired','order_status'=>'expired','hold_expires_at'=>null],['status'=>'expired'])];
    $pdo->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET external_session_id='pending:early_http',provider_order_id='',status='creating' WHERE id=?")->execute([$orders['early']['attempt_id']]);
    file_put_contents($file,json_encode(compact('database','orders'),JSON_THROW_ON_ERROR));echo json_encode(['driver'=>$database['driver'],'orders'=>$orders]);exit;
}
$fixture=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
if ($operation==='cleanup') {commerce_fixture_cleanup_pa2($fixture['database']);exit;}
$pdo=commerce_fixture_connect_pa2($fixture['database']);
if ($operation==='publish_early') {
    $f=$fixture['orders']['early'];$pdo->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET external_session_id=?,provider_order_id=?,status='open' WHERE id=?")->execute([$f['session'],$f['session'],$f['attempt_id']]);
    $pdo->exec("UPDATE agent_commerce_webhook_events_v800 SET next_attempt_at='2000-01-01 00:00:00' WHERE status='pending'");
    $GLOBALS['pa2_http']=commerce_fixture_stripe_transport_pa2(array_values($fixture['orders']));
    echo json_encode(agent_commerce_recovery_housekeeping_pa2($pdo));exit;
}
if ($operation==='drop_recovery') {$pdo->exec('DROP TABLE agent_commerce_fulfillment_jobs_pa2');exit;}
if ($operation!=='state') throw new RuntimeException('Unknown test operation.');
$state=[];
foreach (['agent_commerce_orders_v800','agent_commerce_payments_v800','agent_commerce_checkout_attempts_v800','agent_commerce_webhook_events_v800','agent_commerce_fulfillment_jobs_pa2'] as $table) {
    $state[$table]=commerce_fixture_rows_pa2($pdo,$table);
}
echo json_encode($state,JSON_THROW_ON_ERROR);
