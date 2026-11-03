<?php
declare(strict_types=1);

/** Provider wire examples retain irrelevant secrets to test strict projection. */
function commerce_provider_fixture_pa2(string $provider, int $amount = 10000): array
{
    $order = ['id' => 101, 'owner_user_id' => 1, 'workspace_owner_user_id' => null,
        'connection_id' => 11, 'provider_snapshot' => $provider, 'external_account_snapshot' => 'merchant_fixture',
        'currency' => 'usd', 'total_cents' => 40000, 'amount_due_cents' => $amount, 'amount_paid_cents' => 0,
        'payment_mode' => 'deposit', 'payment_status' => 'awaiting_payment', 'order_status' => 'pending_payment',
        'terms_snapshot_json' => '{"deposit_cents":10000,"total_cents":40000,"private_note":"SECRET terms"}'];
    $connection = ['id' => 11, 'owner_user_id' => 1, 'provider' => $provider,
        'external_account_id' => 'merchant_fixture', 'status' => 'connected',
        'access_token_ciphertext' => 'SECRET_TOKEN', 'account_email' => 'secret@example.test',
        'capabilities_json' => '{"location_id":"LOCATION-FIXTURE"}'];
    $attempt = ['id' => 21, 'order_id' => 101, 'connection_id' => 11, 'provider' => $provider,
        'external_session_id' => 'CHECKOUT-FIXTURE', 'external_payment_id' => '', 'provider_order_id' => 'CHECKOUT-FIXTURE',
        'provider_account_id' => 'merchant_fixture', 'provider_location_id' => '', 'status' => 'open',
        'amount_cents' => $amount, 'currency' => 'usd', 'checkout_url' => 'https://private.example.test/SECRET_CHECKOUT',
        'idempotency_key' => str_repeat('a', 64)];
    if ($provider === 'stripe') {
        $attempt['external_session_id'] = $attempt['provider_order_id'] = 'cs_fixture';
        $attempt['provider_account_id'] = $connection['external_account_id'] = $order['external_account_snapshot'] = 'acct_fixture';
        $session = ['id' => 'cs_fixture', 'object' => 'checkout.session', 'mode' => 'payment', 'status' => 'complete',
            'payment_status' => 'paid', 'payment_intent' => 'pi_fixture', 'amount_total' => $amount,
            'currency' => 'usd', 'metadata' => ['vp3_commerce_order_id' => '101'], 'livemode' => false,
            'customer_details' => ['email' => 'secret@example.test'], 'url' => 'https://private.example.test/SECRET_CHECKOUT'];
        $payment = ['id' => 'pi_fixture', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => $amount,
            'amount_received' => $amount, 'currency' => 'usd', 'metadata' => ['vp3_commerce_order_id' => '101'],
            'livemode' => false, 'client_secret' => 'SECRET_CLIENT_SECRET'];
        $evidence = ['session' => $session, 'payment_intent' => $payment, 'account_id' => 'acct_fixture'];
        $responses = ['/v1/checkout/sessions/cs_fixture' => $session, '/v1/payment_intents/pi_fixture' => $payment];
        $event = ['id' => 'evt_fixture', 'type' => 'checkout.session.completed', 'account' => 'acct_fixture',
            'data' => ['object' => $session], 'request' => ['idempotency_key' => 'SECRET_REQUEST']];
    } elseif ($provider === 'square') {
        $attempt['provider_order_id'] = 'ORDER-FIXTURE';
        $attempt['provider_location_id'] = 'LOCATION-FIXTURE';
        $money = ['amount' => $amount, 'currency' => 'USD'];
        $providerOrder = ['id' => 'ORDER-FIXTURE', 'location_id' => 'LOCATION-FIXTURE', 'state' => 'COMPLETED',
            'total_money' => $money, 'tenders' => [['id' => 'PAYMENT-FIXTURE', 'payment_id' => 'PAYMENT-FIXTURE',
                'location_id' => 'LOCATION-FIXTURE', 'type' => 'CARD', 'amount_money' => $money]],
            'customer_id' => 'SECRET_CUSTOMER'];
        $payment = ['id' => 'PAYMENT-FIXTURE', 'order_id' => 'ORDER-FIXTURE', 'location_id' => 'LOCATION-FIXTURE',
            'status' => 'COMPLETED', 'amount_money' => $money, 'total_money' => $money,
            'card_details' => ['card' => ['last_4' => '1234']], 'receipt_url' => 'https://private.example.test/SECRET_RECEIPT'];
        $evidence = ['order' => $providerOrder, 'payment' => $payment];
        $responses = ['/v2/orders/ORDER-FIXTURE' => ['order' => $providerOrder], '/v2/payments/PAYMENT-FIXTURE' => ['payment' => $payment]];
        $event = ['event_id' => 'square-event-fixture', 'type' => 'payment.updated', 'merchant_id' => 'merchant_fixture',
            'data' => ['id' => 'PAYMENT-FIXTURE', 'type' => 'payment', 'object' => ['payment' => $payment]]];
    } elseif ($provider === 'paypal') {
        $money = ['currency_code' => 'USD', 'value' => intdiv($amount, 100).'.'.str_pad((string)($amount % 100), 2, '0', STR_PAD_LEFT)];
        $capture = ['id' => 'CAPTURE-FIXTURE', 'status' => 'COMPLETED', 'amount' => $money,
            'final_capture' => true, 'payee' => ['merchant_id' => 'merchant_fixture', 'email_address' => 'secret@example.test'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'CHECKOUT-FIXTURE']],
            'links' => [['rel' => 'self', 'href' => 'https://private.example.test/SECRET_PROVIDER_PATH']]];
        $providerOrder = ['id' => 'CHECKOUT-FIXTURE', 'intent' => 'CAPTURE', 'status' => 'COMPLETED',
            'purchase_units' => [['reference_id' => 'vp3-commerce-101', 'payee' => ['merchant_id' => 'merchant_fixture'],
                'amount' => $money, 'payments' => ['captures' => [$capture]]]],
            'payer' => ['email_address' => 'secret@example.test']];
        $evidence = ['order' => $providerOrder, 'capture' => $capture];
        $responses = ['/v2/checkout/orders/CHECKOUT-FIXTURE' => $providerOrder, '/v2/payments/captures/CAPTURE-FIXTURE' => $capture];
        $event = ['id' => 'paypal-event-fixture', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => $capture];
    } else throw new RuntimeException('Unknown test provider.');
    return compact('order', 'attempt', 'connection', 'evidence', 'responses', 'event');
}

/** A faithful subset of canonical immutable storage, isolated by the runner. */
function commerce_provider_sql_fixture_pa2(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE agent_commerce_provider_connections_v800 (
        id INTEGER PRIMARY KEY, owner_user_id INTEGER NOT NULL, provider VARCHAR(24) NOT NULL,
        external_account_id VARCHAR(190) NOT NULL, status VARCHAR(24) NOT NULL,
        access_token_ciphertext TEXT, account_email VARCHAR(190), capabilities_json TEXT
    )');
    $pdo->exec('CREATE TABLE agent_commerce_orders_v800 (
        id INTEGER PRIMARY KEY, owner_user_id INTEGER NOT NULL, workspace_owner_user_id INTEGER NULL,
        connection_id INTEGER NULL, provider_snapshot VARCHAR(24), external_account_snapshot VARCHAR(190),
        currency CHAR(3) NOT NULL, total_cents INTEGER NOT NULL, amount_due_cents INTEGER NOT NULL,
        amount_paid_cents INTEGER NOT NULL, payment_mode VARCHAR(24), payment_status VARCHAR(32),
        order_status VARCHAR(32), terms_snapshot_json TEXT
    )');
    $pdo->exec('CREATE TABLE agent_commerce_checkout_attempts_v800 (
        id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, connection_id INTEGER NOT NULL,
        provider VARCHAR(24) NOT NULL, external_session_id VARCHAR(190) NOT NULL,
        external_payment_id VARCHAR(190) NOT NULL, provider_order_id VARCHAR(190) NOT NULL,
        provider_account_id VARCHAR(190) NOT NULL, provider_location_id VARCHAR(190) NOT NULL,
        status VARCHAR(32), amount_cents INTEGER NOT NULL, currency CHAR(3) NOT NULL,
        checkout_url TEXT, idempotency_key CHAR(64)
    )');
}

function commerce_provider_sql_store_pa2(PDO $pdo, array $fixture): void
{
    foreach (['attempt' => 'agent_commerce_checkout_attempts_v800', 'order' => 'agent_commerce_orders_v800',
        'connection' => 'agent_commerce_provider_connections_v800'] as $key => $table) {
        $pdo->exec('DELETE FROM '.$table);
        $row = $fixture[$key]; $fields = array_keys($row);
        $pdo->prepare('INSERT INTO '.$table.' ('.implode(',', $fields).') VALUES ('.implode(',', array_fill(0, count($fields), '?')).')')->execute(array_values($row));
    }
}

function commerce_provider_sql_rows_pa2(PDO $pdo): array
{
    $out = [];
    foreach (['attempt' => 'agent_commerce_checkout_attempts_v800', 'order' => 'agent_commerce_orders_v800',
        'connection' => 'agent_commerce_provider_connections_v800'] as $key => $table) {
        $stmt = $pdo->query('SELECT * FROM '.$table.' ORDER BY id');
        $out[$key] = $stmt->fetch(PDO::FETCH_ASSOC); $stmt->closeCursor();
    }
    return $out;
}
