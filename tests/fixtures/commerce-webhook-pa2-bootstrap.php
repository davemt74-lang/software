<?php
declare(strict_types=1);
require dirname(__DIR__).'/tests/fixtures/commerce-recovery-pa2.php';
$fixture=json_decode((string)file_get_contents((string)getenv('VP3_PA2_WEBHOOK_CONFIG')),true,32,JSON_THROW_ON_ERROR);
$pdo=commerce_fixture_connect_pa2($fixture['database']);
$GLOBALS['pa2_http']=commerce_fixture_stripe_transport_pa2(array_values($fixture['orders']));
if (($_SERVER['HTTP_X_PA2_PROVIDER_FAILURE']??'')==='1') {
    $GLOBALS['pa2_http']=static function(){throw new RuntimeException('SECRET private provider credential diagnostic');};
}
if (($_SERVER['HTTP_X_PA2_SQL_FAILURE']??'')==='payment') $pdo->failSql='INTO agent_commerce_payments_v800';
if (($_SERVER['HTTP_X_PA2_SQL_FAILURE']??'')==='fulfillment') $pdo->failSql='INTO agent_commerce_fulfillment_jobs_pa2';
