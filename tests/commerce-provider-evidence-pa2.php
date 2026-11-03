<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/agent-commerce-provider-evidence-pa2.php';
require __DIR__.'/fixtures/commerce-provider-evidence-pa2.php';

$dsn = getenv('VP3_TEST_MYSQL_DSN') ?: 'sqlite::memory:';
$user = null; $password = null;
if (str_starts_with($dsn, 'mysql:')) {
    $user = getenv('VP3_TEST_MYSQL_USER') ?: 'root';
    $password = getenv('VP3_TEST_MYSQL_PASS') ?: 'root';
    $admin = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $database = 'vp3_provider_evidence_'.bin2hex(random_bytes(5));
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsn = preg_match('/dbname=[^;]+/', $dsn) ? preg_replace('/dbname=[^;]+/', 'dbname='.$database, $dsn) : rtrim($dsn, ';').';dbname='.$database;
    register_shutdown_function(static fn() => $admin->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
}
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
commerce_provider_sql_fixture_pa2($pdo);
$checks = 0;
function evidenceCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    $GLOBALS['checks']++; echo "PASS $label\n";
}
function evidenceDirect(array $f): array
{
    return agent_commerce_provider_evidence_pa2($f['attempt']['provider'], $f['attempt'], $f['order'], $f['connection'], $f['evidence']);
}
function evidenceReject(callable $work, string $label): void
{
    try { $work(); } catch (RuntimeException $e) {
        evidenceCheck($e->getCode() === 409 && in_array($e->getMessage(), ['Payment checkout lineage is unavailable.', 'Payment provider evidence did not match this checkout.'], true), $label);
        return;
    }
    evidenceCheck(false, $label);
}
function evidenceSet(array $fixture, string $path, mixed $value): array
{
    $parts = explode('.', $path); $cursor =& $fixture;
    foreach ($parts as $part) $cursor =& $cursor[$part];
    $cursor = $value; return $fixture;
}
function evidenceRead(array $fixture, array &$calls): array
{
    return agent_commerce_provider_read_pa2($fixture['attempt']['provider'], $fixture['attempt'], $fixture['order'], $fixture['connection'],
        static function(string $path) use ($fixture, &$calls): mixed {
            $calls[] = $path;
            if (!array_key_exists($path, $fixture['responses'])) throw new RuntimeException('Unexpected provider route.');
            return $fixture['responses'][$path];
        });
}

foreach (['stripe', 'square', 'paypal'] as $provider) {
    $f = commerce_provider_fixture_pa2($provider);
    commerce_provider_sql_store_pa2($pdo, $f);
    $stored = commerce_provider_sql_rows_pa2($pdo);
    $f = array_replace($f, $stored); $calls = [];
    $result = evidenceRead($f, $calls);
    evidenceCheck($result['status'] === 'verified' && $result['amount_cents'] === 10000 && $result['currency'] === 'usd', "$provider exact SQL checkout verifies original deposit");
    evidenceCheck($calls === array_keys($f['responses']), "$provider uses only exact checkout-to-payment GET paths");
    evidenceCheck(array_keys($result) === ['status', 'provider', 'external_session_id', 'provider_order_id', 'provider_account_id', 'provider_location_id', 'amount_cents', 'currency', 'external_payment_id']
        && !str_contains(json_encode($result), 'SECRET') && !str_contains(json_encode($result), 'example.test'), "$provider evidence projection excludes provider secrets and customer data");
    evidenceCheck($stored === commerce_provider_sql_rows_pa2($pdo), "$provider read creates no ledger, order, or attempt changes");
    $pdo->prepare('UPDATE agent_commerce_checkout_attempts_v800 SET external_payment_id=?,status=? WHERE id=21')->execute([$result['external_payment_id'], 'paid']);
    $pdo->exec("UPDATE agent_commerce_orders_v800 SET amount_paid_cents=10000,amount_due_cents=30000,payment_status='partially_paid',order_status='confirmed',connection_id=99,provider_snapshot='other',external_account_snapshot='new_route' WHERE id=101");
    $f = array_replace($f, commerce_provider_sql_rows_pa2($pdo)); $calls = [];
    evidenceCheck(evidenceRead($f, $calls) === $result, "$provider settled replay uses immutable attempt rather than changed balance or selected route");
    $pdo->exec("UPDATE agent_commerce_orders_v800 SET amount_due_cents=0,payment_status='expired',order_status='cancelled' WHERE id=101");
    $f = array_replace($f, commerce_provider_sql_rows_pa2($pdo));
    evidenceCheck(evidenceDirect($f) === $result, "$provider late verified money survives local expiry without changing fulfillment");
    $changed = evidenceSet($f, 'attempt.external_payment_id', 'OTHER-PAYMENT');
    evidenceReject(fn() => evidenceDirect($changed), "$provider rejects a changed persisted payment identity");
    foreach (['provider_order_id', 'provider_account_id', 'external_session_id'] as $field) {
        $legacy = evidenceSet($f, 'attempt.'.$field, ''); $calls = [];
        evidenceReject(function() use ($legacy, &$calls) { evidenceRead($legacy, $calls); }, "$provider missing immutable $field is unprovable");
        evidenceCheck($calls === [], "$provider legacy $field never triggers a speculative provider search");
    }
    $changed = evidenceSet($f, 'attempt.expected_payment_id', 'OTHER-EVENT-PAYMENT');
    evidenceReject(fn() => evidenceDirect($changed), "$provider signed event cannot select a different settled payment");
    foreach ([['order.id', 102], ['attempt.order_id', 102], ['attempt.connection_id', 12], ['connection.owner_user_id', 2],
        ['connection.provider', 'other'], ['connection.external_account_id', 'OTHER-MERCHANT'], ['attempt.amount_cents', 30000],
        ['order.currency', 'eur'], ['attempt.currency', 'eur'], ['attempt.amount_cents', 10000.0], ['attempt.amount_cents', true],
        ['attempt.amount_cents', '1e4'], ['attempt.provider_order_id', "BAD\nPRIVATE"], ['attempt.provider_account_id', 'MERCHANT_FIXTURE'],
        ['attempt.external_payment_id', null], ['attempt.expected_payment_id', null]] as [$path, $value]) {
        $changed = evidenceSet($f, $path, $value);
        evidenceReject(fn() => evidenceDirect($changed), "$provider rejects local binding $path=".json_encode($value));
    }
    $team = evidenceSet(evidenceSet($f, 'order.workspace_owner_user_id', 2), 'connection.owner_user_id', 2);
    evidenceCheck(evidenceDirect($team)['status'] === 'verified', "$provider honors exact team merchant ownership");
    $event = agent_commerce_provider_event_target_pa2($provider, $f['event']);
    evidenceCheck($event['status'] === 'target' && $event['provider_order_id'] === $f['attempt']['provider_order_id']
        && $event['provider_account_id'] === $f['attempt']['provider_account_id']
        && $event['expected_payment_id'] === $result['external_payment_id'], "$provider signed event supplies exact bounded routing hints");
    evidenceCheck(!str_contains(json_encode($event), 'SECRET') && !str_contains(json_encode($event), 'example.test') && !isset($event['amount_cents']), "$provider event routing never claims payment or emits raw payload");
    $foreign = $f['event']; $foreign[$provider === 'paypal' ? 'event_type' : 'type'] = 'unrelated.event';
    evidenceCheck(agent_commerce_provider_event_target_pa2($provider, $foreign)['status'] === 'ignored', "$provider unrelated event stays ignored");
}

$stripe = commerce_provider_fixture_pa2('stripe');
foreach ([['evidence.account_id', 'acct_other'], ['evidence.session.id', 'cs_other'], ['evidence.session.object', 'customer'],
    ['evidence.session.mode', 'subscription'], ['evidence.session.status', 'unknown'], ['evidence.session.status', 'expired'],
    ['evidence.session.payment_status', 'unknown'], ['evidence.session.amount_total', 9999], ['evidence.session.amount_total', '10000'],
    ['evidence.session.currency', 'eur'], ['evidence.session.metadata.vp3_commerce_order_id', '102'],
    ['evidence.session.payment_intent', null], ['evidence.payment_intent.id', 'pi_other'], ['evidence.payment_intent.status', 'unknown'],
    ['evidence.payment_intent.amount', 10001], ['evidence.payment_intent.amount_received', 9999], ['evidence.payment_intent.amount_received', 10001],
    ['evidence.payment_intent.currency', 'eur'], ['evidence.payment_intent.metadata.vp3_commerce_order_id', '102'],
    ['evidence.payment_intent.livemode', true], ['evidence.session', []], ['evidence.payment_intent', []]] as [$path, $value]) {
    $changed = evidenceSet($stripe, $path, $value);
    evidenceReject(fn() => evidenceDirect($changed), "Stripe rejects $path=".json_encode($value));
}
$expanded = evidenceSet($stripe, 'evidence.session.payment_intent', ['id' => 'pi_fixture', 'object' => 'payment_intent']);
evidenceCheck(evidenceDirect($expanded)['status'] === 'verified', 'Stripe accepts an exact expanded payment reference');
foreach (['unpaid', 'no_payment_required'] as $status) {
    $pending = $stripe; $pending['responses']['/v1/checkout/sessions/cs_fixture']['payment_status'] = $status;
    $pending['responses']['/v1/checkout/sessions/cs_fixture']['payment_intent'] = null; $calls = [];
    $r = evidenceRead($pending, $calls);
    evidenceCheck($r['status'] === 'pending' && count($r) === 2 && count($calls) === 1, "Stripe $status yields no paid tuple or speculative PaymentIntent call");
}
foreach (['processing', 'requires_capture', 'canceled'] as $status) {
    $pending = evidenceSet($stripe, 'evidence.payment_intent.status', $status);
    evidenceCheck(evidenceDirect($pending)['status'] === 'pending', "Stripe $status is not settlement");
}
foreach (['checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed'] as $type) {
    $event = $stripe['event']; $event['type'] = $type;
    evidenceCheck(agent_commerce_provider_event_target_pa2('stripe', $event)['status'] === 'target', "Stripe $type reaches independent reconciliation");
}

$square = commerce_provider_fixture_pa2('square');
foreach ([['evidence.order.id', 'OTHER-ORDER'], ['evidence.order.location_id', 'OTHER-LOCATION'], ['evidence.order.total_money.amount', 10001],
    ['evidence.order.total_money.currency', 'EUR'], ['evidence.order.tenders', null],
    ['evidence.order.tenders.0.payment_id', 'OTHER-PAYMENT'], ['evidence.order.tenders.0.id', 'OTHER-PAYMENT'],
    ['evidence.order.tenders.0.location_id', 'OTHER-LOCATION'], ['evidence.order.tenders.0.location_id', null],
    ['evidence.order.tenders.0.amount_money.amount', 9999], ['evidence.payment.id', 'OTHER-PAYMENT'],
    ['evidence.payment.order_id', 'OTHER-ORDER'], ['evidence.payment.location_id', 'OTHER-LOCATION'], ['evidence.payment.status', 'paid'],
    ['evidence.payment.amount_money.amount', 10001], ['evidence.payment.amount_money.amount', '10000'],
    ['evidence.payment.amount_money.currency', 'EUR'], ['evidence.payment.total_money.amount', 9999],
    ['attempt.provider_location_id', ''], ['attempt.provider_location_id', 'OTHER-LOCATION']] as [$path, $value]) {
    $changed = evidenceSet($square, $path, $value);
    evidenceReject(fn() => evidenceDirect($changed), "Square rejects $path=".json_encode($value));
}
$unrelated = $square;
$unrelated['responses']['/v2/payments/PAYMENT-FIXTURE']['payment']['id'] = 'unrelated-payment';
$unrelated['responses']['/v2/payments/PAYMENT-FIXTURE']['payment']['order_id'] = 'OTHER-SQUARE-ORDER';
$unrelated['responses']['/v2/payments/PAYMENT-FIXTURE']['payment']['location_id'] = 'other-location';
$calls = [];
evidenceReject(function() use ($unrelated, &$calls) { evidenceRead($unrelated, $calls); }, 'Square unrelated completed account payment cannot pay this checkout');
evidenceCheck($calls === ['/v2/orders/ORDER-FIXTURE', '/v2/payments/PAYMENT-FIXTURE'], 'Square regression never calls account-wide ListPayments');
$ambiguous = $square; $ambiguous['evidence']['order']['tenders'][] = $ambiguous['evidence']['order']['tenders'][0];
evidenceReject(fn() => evidenceDirect($ambiguous), 'Square duplicate or split tenders require review instead of choosing the first');
$legacy = $square; unset($legacy['evidence']['order']['tenders'][0]['payment_id']);
evidenceReject(fn() => evidenceDirect($legacy), 'Square does not reinterpret a legacy tender ID or overwritten payment field as its order');
$pending = $square; unset($pending['responses']['/v2/orders/ORDER-FIXTURE']['order']['tenders']); $calls = [];
evidenceCheck(evidenceRead($pending, $calls) === ['status' => 'pending', 'reason' => 'awaiting_payment'] && count($calls) === 1, 'Square empty order has no settlement and performs no payment search');
foreach (['APPROVED', 'PENDING', 'CANCELED', 'FAILED'] as $status) {
    $pending = evidenceSet($square, 'evidence.payment.status', $status);
    evidenceCheck(evidenceDirect($pending)['status'] === 'pending', "Square $status never becomes paid");
}
$open = evidenceSet($square, 'evidence.order.state', 'OPEN');
evidenceCheck(evidenceDirect($open)['status'] === 'verified', 'Square completed payment can exist before order fulfillment completes');
$adjusted = evidenceSet($square, 'evidence.payment.refunded_money', ['amount' => 1, 'currency' => 'USD']);
evidenceCheck(evidenceDirect($adjusted) === ['status' => 'pending', 'reason' => 'payment_adjusted'], 'Square adjusted payment is not automatically fulfilled');
$tip = evidenceSet($square, 'evidence.payment.tip_money', ['amount' => 1, 'currency' => 'USD']);
evidenceReject(fn() => evidenceDirect($tip), 'Square unexpected tip cannot silently alter fixed checkout amount');

$paypal = commerce_provider_fixture_pa2('paypal');
foreach ([['evidence.order.id', 'OTHER-ORDER'], ['evidence.order.intent', 'AUTHORIZE'], ['evidence.order.status', 'unknown'],
    ['evidence.order.purchase_units.0.reference_id', 'vp3-commerce-102'], ['evidence.order.purchase_units.0.payee.merchant_id', 'OTHER-MERCHANT'],
    ['evidence.order.purchase_units.0.amount.value', '101.00'], ['evidence.order.purchase_units.0.amount.currency_code', 'EUR'],
    ['evidence.order.purchase_units.0.payments.captures.0.id', 'OTHER-CAPTURE'], ['evidence.order.purchase_units.0.payments.captures.0.amount.value', '99.99'],
    ['evidence.order.purchase_units.0.payments.captures.0.payee.merchant_id', 'OTHER-MERCHANT'],
    ['evidence.order.purchase_units.0.payments.captures.0.supplementary_data.related_ids.order_id', 'OTHER-ORDER'],
    ['evidence.capture.id', 'OTHER-CAPTURE'], ['evidence.capture.status', 'approved'], ['evidence.capture.amount.currency_code', 'EUR'],
    ['evidence.capture.amount.value', 100], ['evidence.capture.amount.value', 100.0], ['evidence.capture.amount.value', '1e2'],
    ['evidence.capture.amount.value', '-100'], ['evidence.capture.amount.value', '100.001'], ['evidence.capture.amount.value', '100.01'],
    ['evidence.capture.amount.value', ' 100.00'], ['evidence.capture.amount.value', '99999999.99'],
    ['evidence.capture.payee.merchant_id', 'OTHER-MERCHANT'], ['evidence.capture.supplementary_data.related_ids.order_id', 'OTHER-ORDER'],
    ['evidence.capture.payee', null], ['evidence.capture.supplementary_data', null]] as [$path, $value]) {
    $changed = evidenceSet($paypal, $path, $value);
    evidenceReject(fn() => evidenceDirect($changed), "PayPal rejects $path=".json_encode($value));
}
$nested = $paypal; unset($nested['evidence']['capture']['payee'], $nested['evidence']['capture']['supplementary_data']);
evidenceCheck(evidenceDirect($nested)['status'] === 'verified', 'PayPal canonical capture response is bound by exact purchase unit and capture membership');
$multiple = $paypal; $multiple['evidence']['order']['purchase_units'][] = $multiple['evidence']['order']['purchase_units'][0];
evidenceReject(fn() => evidenceDirect($multiple), 'PayPal multiple purchase units cannot silently select the first');
$multiple = $paypal; $multiple['evidence']['order']['purchase_units'][0]['payments']['captures'][] = $multiple['evidence']['capture'];
evidenceReject(fn() => evidenceDirect($multiple), 'PayPal duplicate or split captures cannot silently select the first');
$adjusted = evidenceSet($paypal, 'evidence.order.purchase_units.0.payments.captures.0.status', 'REFUNDED');
evidenceCheck(evidenceDirect($adjusted) === ['status' => 'pending', 'reason' => 'payment_adjusted'], 'PayPal order refund evidence prevents fresh fulfillment from a stale completed capture');
$contradictory = evidenceSet($paypal, 'evidence.order.purchase_units.0.payments.captures.0.status', 'FAILED');
evidenceReject(fn() => evidenceDirect($contradictory), 'PayPal failed order capture cannot be silently replaced by a completed claim');
$pending = $paypal; unset($pending['responses']['/v2/checkout/orders/CHECKOUT-FIXTURE']['purchase_units'][0]['payments']);
$pending['responses']['/v2/checkout/orders/CHECKOUT-FIXTURE']['status'] = 'APPROVED'; $calls = [];
evidenceCheck(evidenceRead($pending, $calls) === ['status' => 'pending', 'reason' => 'awaiting_payment'] && count($calls) === 1, 'PayPal payer approval does not imply capture and never triggers a capture mutation');
foreach (['PENDING', 'DECLINED', 'DENIED', 'REFUNDED', 'PARTIALLY_REFUNDED', 'FAILED'] as $status) {
    $pending = evidenceSet($paypal, 'evidence.capture.status', $status);
    evidenceCheck(evidenceDirect($pending)['status'] === 'pending', "PayPal $status never becomes paid");
}
foreach (['PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED'] as $type) {
    $event = $paypal['event']; $event['event_type'] = $type;
    evidenceCheck(agent_commerce_provider_event_target_pa2('paypal', $event)['status'] === 'target', "PayPal $type reaches independent reconciliation without claiming paid");
}

foreach (['stripe', 'square', 'paypal'] as $provider) {
    $f = commerce_provider_fixture_pa2($provider); $event = $f['event'];
    unset($event[$provider === 'square' ? 'event_id' : 'id']);
    evidenceReject(fn() => agent_commerce_provider_event_target_pa2($provider, $event), "$provider malformed event has a fixed diagnostic");
    $event = $f['event'];
    if ($provider === 'stripe') unset($event['account']);
    elseif ($provider === 'square') $event['data']['id'] = 'OTHER-PAYMENT';
    else unset($event['resource']['supplementary_data']);
    evidenceReject(fn() => agent_commerce_provider_event_target_pa2($provider, $event), "$provider incomplete or inconsistent event identity cannot route");
    $calls = 0; $error = new RuntimeException('Transport retry sentinel');
    try {
        agent_commerce_provider_read_pa2($provider, $f['attempt'], $f['order'], $f['connection'], static function() use (&$calls, $error): never { $calls++; throw $error; });
        evidenceCheck(false, "$provider transport failure propagates");
    } catch (RuntimeException $e) {
        evidenceCheck($e === $error && $calls === 1, "$provider transport error stays retryable without an invented payment result");
    }
}
evidenceReject(fn() => agent_commerce_provider_evidence_pa2('unknown', [], [], [], []), 'Unknown provider is rejected');
echo 'COMMERCE_PROVIDER_EVIDENCE_PA2=PASS checks='.$checks.' driver='.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)."\n";
