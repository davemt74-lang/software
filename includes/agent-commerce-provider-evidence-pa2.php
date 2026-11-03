<?php
declare(strict_types=1);

/**
 * Read-only payment evidence. The caller owns authenticated transport, current
 * database authority, transaction locks and all money/fulfillment mutations.
 * Never infer settlement from a redirect, an account-wide search or an approval.
 */
function agent_commerce_evidence_fail_pa2(bool $lineage = false): never
{
    throw new RuntimeException($lineage
        ? 'Payment checkout lineage is unavailable.'
        : 'Payment provider evidence did not match this checkout.', 409);
}

function agent_commerce_evidence_object_pa2(mixed $value): array
{
    if (!is_array($value) || ($value !== [] && array_is_list($value))) agent_commerce_evidence_fail_pa2();
    return $value;
}

function agent_commerce_evidence_id_pa2(mixed $value, bool $empty = false): string
{
    if ($empty && $value === '') return '';
    if (!is_string($value) || !preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D', $value)) agent_commerce_evidence_fail_pa2();
    return $value;
}

function agent_commerce_evidence_integer_pa2(mixed $value, bool $database = false, int $maximum = 4294967295): int
{
    if ($database && is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $value)
        && strlen($value) <= strlen((string)$maximum)
        && (strlen($value) < strlen((string)$maximum) || strcmp($value, (string)$maximum) <= 0)) $value = (int)$value;
    if (!is_int($value) || $value < 0 || $value > $maximum) agent_commerce_evidence_fail_pa2();
    return $value;
}

function agent_commerce_evidence_currency_pa2(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^[A-Za-z]{3}$/D', $value)) agent_commerce_evidence_fail_pa2();
    return strtolower($value);
}

function agent_commerce_evidence_decimal_pa2(mixed $value): int
{
    // Commerce stores two-decimal minor units. Never use binary float or accept
    // exponent/coercible values supplied by a provider response.
    if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $value, $m)) agent_commerce_evidence_fail_pa2();
    $amount = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    return agent_commerce_evidence_integer_pa2($amount);
}

function agent_commerce_evidence_pending_pa2(string $reason = 'awaiting_payment'): array
{
    return ['status' => 'pending', 'reason' => $reason];
}

/** The attempt, rather than the order's mutable selected route, is authority. */
function agent_commerce_evidence_binding_pa2(string $provider, array $attempt, array $order, array $connection): array
{
    if (!in_array($provider, ['stripe', 'square', 'paypal'], true)) agent_commerce_evidence_fail_pa2();
    $orderId = agent_commerce_evidence_integer_pa2($order['id'] ?? null, true, PHP_INT_MAX);
    $connectionId = agent_commerce_evidence_integer_pa2($connection['id'] ?? null, true, PHP_INT_MAX);
    if ($orderId < 1 || $connectionId < 1
        || agent_commerce_evidence_integer_pa2($attempt['order_id'] ?? null, true, PHP_INT_MAX) !== $orderId
        || agent_commerce_evidence_integer_pa2($attempt['connection_id'] ?? null, true, PHP_INT_MAX) !== $connectionId
        || ($attempt['provider'] ?? null) !== $provider || ($connection['provider'] ?? null) !== $provider) agent_commerce_evidence_fail_pa2();
    $owner = agent_commerce_evidence_integer_pa2($order['owner_user_id'] ?? null, true, PHP_INT_MAX);
    $workspace = $order['workspace_owner_user_id'] ?? null;
    if ($workspace !== null) $workspace = agent_commerce_evidence_integer_pa2($workspace, true, PHP_INT_MAX);
    if (($workspace ?? 0) > 0) $owner = $workspace;
    if ($owner < 1 || agent_commerce_evidence_integer_pa2($connection['owner_user_id'] ?? null, true, PHP_INT_MAX) !== $owner) agent_commerce_evidence_fail_pa2();
    foreach (['external_session_id', 'provider_order_id', 'provider_account_id'] as $key) {
        if (!isset($attempt[$key]) || $attempt[$key] === '') agent_commerce_evidence_fail_pa2(true);
    }
    $session = agent_commerce_evidence_id_pa2($attempt['external_session_id']);
    $providerOrder = agent_commerce_evidence_id_pa2($attempt['provider_order_id']);
    $account = agent_commerce_evidence_id_pa2($attempt['provider_account_id']);
    $location = agent_commerce_evidence_id_pa2($attempt['provider_location_id'] ?? '', true);
    if ($account !== agent_commerce_evidence_id_pa2($connection['external_account_id'] ?? null)) agent_commerce_evidence_fail_pa2();
    if ($provider === 'square' && $location === '') agent_commerce_evidence_fail_pa2(true);
    if ($provider !== 'square' && ($providerOrder !== $session || $location !== '')) agent_commerce_evidence_fail_pa2();
    $amount = agent_commerce_evidence_integer_pa2($attempt['amount_cents'] ?? null, true);
    $currency = agent_commerce_evidence_currency_pa2($attempt['currency'] ?? null);
    if ($amount < 1 || $currency !== agent_commerce_evidence_currency_pa2($order['currency'] ?? null)) agent_commerce_evidence_fail_pa2();
    $savedPayment = agent_commerce_evidence_id_pa2(array_key_exists('external_payment_id', $attempt) ? $attempt['external_payment_id'] : '', true);
    $expectedPayment = agent_commerce_evidence_id_pa2(array_key_exists('expected_payment_id', $attempt) ? $attempt['expected_payment_id'] : '', true);
    if ($savedPayment !== '' && $expectedPayment !== '' && $savedPayment !== $expectedPayment) agent_commerce_evidence_fail_pa2();
    return ['provider' => $provider, 'external_session_id' => $session, 'provider_order_id' => $providerOrder,
        'provider_account_id' => $account, 'provider_location_id' => $location, 'amount_cents' => $amount,
        'currency' => $currency, 'local_order_id' => $orderId, 'expected_payment_id' => $expectedPayment ?: $savedPayment];
}

function agent_commerce_evidence_money_pa2(mixed $money, array $binding, bool $paypal = false): void
{
    $money = agent_commerce_evidence_object_pa2($money);
    $amount = $paypal ? agent_commerce_evidence_decimal_pa2($money['value'] ?? null)
        : agent_commerce_evidence_integer_pa2($money['amount'] ?? null);
    $currency = agent_commerce_evidence_currency_pa2($money[$paypal ? 'currency_code' : 'currency'] ?? null);
    if ($amount !== $binding['amount_cents'] || $currency !== $binding['currency']) agent_commerce_evidence_fail_pa2();
}

function agent_commerce_evidence_payment_pa2(string $id, array $binding): string
{
    if ($binding['expected_payment_id'] !== '' && $id !== $binding['expected_payment_id']) agent_commerce_evidence_fail_pa2();
    return $id;
}

function agent_commerce_evidence_verified_pa2(array $binding, string $id): array
{
    agent_commerce_evidence_payment_pa2($id, $binding);
    unset($binding['local_order_id'], $binding['expected_payment_id']);
    return ['status' => 'verified'] + $binding + ['external_payment_id' => $id];
}

function agent_commerce_evidence_stripe_session_pa2(array $binding, mixed $raw, mixed $account): array
{
    $session = agent_commerce_evidence_object_pa2($raw);
    if (agent_commerce_evidence_id_pa2($account) !== $binding['provider_account_id']
        || agent_commerce_evidence_id_pa2($session['id'] ?? null) !== $binding['external_session_id']
        || ($session['object'] ?? null) !== 'checkout.session' || ($session['mode'] ?? null) !== 'payment'
        || !in_array($session['status'] ?? null, ['open', 'complete', 'expired'], true)
        || !in_array($session['payment_status'] ?? null, ['paid', 'unpaid', 'no_payment_required'], true)) agent_commerce_evidence_fail_pa2();
    $metadata = agent_commerce_evidence_object_pa2($session['metadata'] ?? null);
    if (($metadata['vp3_commerce_order_id'] ?? null) !== (string)$binding['local_order_id']
        || agent_commerce_evidence_integer_pa2($session['amount_total'] ?? null) !== $binding['amount_cents']
        || agent_commerce_evidence_currency_pa2($session['currency'] ?? null) !== $binding['currency']) agent_commerce_evidence_fail_pa2();
    if (array_key_exists('livemode', $session) && !is_bool($session['livemode'])) agent_commerce_evidence_fail_pa2();
    return $session;
}

function agent_commerce_evidence_square_order_pa2(array $binding, mixed $raw): array
{
    $order = agent_commerce_evidence_object_pa2($raw);
    if (agent_commerce_evidence_id_pa2($order['id'] ?? null) !== $binding['provider_order_id']
        || agent_commerce_evidence_id_pa2($order['location_id'] ?? null) !== $binding['provider_location_id']
        || !in_array($order['state'] ?? null, ['OPEN', 'COMPLETED', 'CANCELED', 'DRAFT'], true)) agent_commerce_evidence_fail_pa2();
    agent_commerce_evidence_money_pa2($order['total_money'] ?? null, $binding);
    $tenders = $order['tenders'] ?? [];
    if (!is_array($tenders) || !array_is_list($tenders) || count($tenders) > 1
        || (array_key_exists('tenders', $order) && $order['tenders'] === null)) agent_commerce_evidence_fail_pa2();
    if (!$tenders) return [];
    $tender = agent_commerce_evidence_object_pa2($tenders[0]);
    $id = agent_commerce_evidence_id_pa2($tender['payment_id'] ?? null);
    agent_commerce_evidence_payment_pa2($id, $binding);
    agent_commerce_evidence_money_pa2($tender['amount_money'] ?? null, $binding);
    if (array_key_exists('id', $tender) && agent_commerce_evidence_id_pa2($tender['id']) !== $id) agent_commerce_evidence_fail_pa2();
    if (array_key_exists('location_id', $tender) && agent_commerce_evidence_id_pa2($tender['location_id']) !== $binding['provider_location_id']) agent_commerce_evidence_fail_pa2();
    return $tender;
}

function agent_commerce_evidence_paypal_capture_pa2(array $binding, mixed $raw): array
{
    $capture = agent_commerce_evidence_object_pa2($raw);
    agent_commerce_evidence_payment_pa2(agent_commerce_evidence_id_pa2($capture['id'] ?? null), $binding);
    agent_commerce_evidence_money_pa2($capture['amount'] ?? null, $binding, true);
    if (!in_array($capture['status'] ?? null, ['COMPLETED', 'PENDING', 'DECLINED', 'DENIED', 'PARTIALLY_REFUNDED', 'REFUNDED', 'FAILED'], true)) agent_commerce_evidence_fail_pa2();
    if (array_key_exists('payee', $capture)) {
        $payee = agent_commerce_evidence_object_pa2($capture['payee']);
        if (agent_commerce_evidence_id_pa2($payee['merchant_id'] ?? null) !== $binding['provider_account_id']) agent_commerce_evidence_fail_pa2();
    }
    if (array_key_exists('supplementary_data', $capture)) {
        $supplementary = agent_commerce_evidence_object_pa2($capture['supplementary_data']);
        $related = agent_commerce_evidence_object_pa2($supplementary['related_ids'] ?? null);
        if (agent_commerce_evidence_id_pa2($related['order_id'] ?? null) !== $binding['provider_order_id']) agent_commerce_evidence_fail_pa2();
    }
    return $capture;
}

function agent_commerce_evidence_paypal_order_pa2(array $binding, mixed $raw): array
{
    $order = agent_commerce_evidence_object_pa2($raw);
    if (agent_commerce_evidence_id_pa2($order['id'] ?? null) !== $binding['provider_order_id']
        || ($order['intent'] ?? null) !== 'CAPTURE'
        || !in_array($order['status'] ?? null, ['CREATED', 'SAVED', 'APPROVED', 'VOIDED', 'COMPLETED', 'PAYER_ACTION_REQUIRED'], true)) agent_commerce_evidence_fail_pa2();
    $units = $order['purchase_units'] ?? null;
    if (!is_array($units) || !array_is_list($units) || count($units) !== 1) agent_commerce_evidence_fail_pa2();
    $unit = agent_commerce_evidence_object_pa2($units[0]);
    $payee = agent_commerce_evidence_object_pa2($unit['payee'] ?? null);
    if (($unit['reference_id'] ?? null) !== 'vp3-commerce-'.$binding['local_order_id']
        || agent_commerce_evidence_id_pa2($payee['merchant_id'] ?? null) !== $binding['provider_account_id']) agent_commerce_evidence_fail_pa2();
    agent_commerce_evidence_money_pa2($unit['amount'] ?? null, $binding, true);
    $payments = array_key_exists('payments', $unit) ? agent_commerce_evidence_object_pa2($unit['payments']) : [];
    $captures = $payments['captures'] ?? [];
    if (!is_array($captures) || !array_is_list($captures) || count($captures) > 1
        || (array_key_exists('captures', $payments) && $payments['captures'] === null)) agent_commerce_evidence_fail_pa2();
    if (!$captures) return [];
    return agent_commerce_evidence_paypal_capture_pa2($binding, $captures[0]);
}

/** Validate exact authenticated provider objects; no network or database work. */
function agent_commerce_provider_evidence_pa2(string $provider, array $attempt, array $order, array $connection, array $evidence): array
{
    $binding = agent_commerce_evidence_binding_pa2($provider, $attempt, $order, $connection);
    if ($provider === 'stripe') {
        $session = agent_commerce_evidence_stripe_session_pa2($binding, $evidence['session'] ?? null, $evidence['account_id'] ?? null);
        if ($session['payment_status'] !== 'paid') return agent_commerce_evidence_pending_pa2($session['payment_status'] === 'no_payment_required' ? 'no_paid_evidence' : 'awaiting_payment');
        if ($session['status'] !== 'complete') agent_commerce_evidence_fail_pa2();
        $reference = $session['payment_intent'] ?? null;
        if (is_array($reference)) $reference = agent_commerce_evidence_object_pa2($reference)['id'] ?? null;
        $id = agent_commerce_evidence_payment_pa2(agent_commerce_evidence_id_pa2($reference), $binding);
        $payment = agent_commerce_evidence_object_pa2($evidence['payment_intent'] ?? null);
        $metadata = agent_commerce_evidence_object_pa2($payment['metadata'] ?? null);
        if (agent_commerce_evidence_id_pa2($payment['id'] ?? null) !== $id || ($payment['object'] ?? null) !== 'payment_intent'
            || ($metadata['vp3_commerce_order_id'] ?? null) !== (string)$binding['local_order_id']
            || agent_commerce_evidence_integer_pa2($payment['amount'] ?? null) !== $binding['amount_cents']
            || agent_commerce_evidence_currency_pa2($payment['currency'] ?? null) !== $binding['currency']
            || !in_array($payment['status'] ?? null, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'requires_capture', 'canceled', 'succeeded'], true)) agent_commerce_evidence_fail_pa2();
        if (array_key_exists('livemode', $payment) && (!is_bool($payment['livemode'])
            || (array_key_exists('livemode', $session) && $payment['livemode'] !== $session['livemode']))) agent_commerce_evidence_fail_pa2();
        $received = agent_commerce_evidence_integer_pa2($payment['amount_received'] ?? null);
        if ($payment['status'] !== 'succeeded') return agent_commerce_evidence_pending_pa2('payment_not_completed');
        if ($received !== $binding['amount_cents']) agent_commerce_evidence_fail_pa2();
        return agent_commerce_evidence_verified_pa2($binding, $id);
    }
    if ($provider === 'square') {
        $tender = agent_commerce_evidence_square_order_pa2($binding, $evidence['order'] ?? null);
        if (!$tender) return agent_commerce_evidence_pending_pa2();
        $payment = agent_commerce_evidence_object_pa2($evidence['payment'] ?? null);
        $id = agent_commerce_evidence_id_pa2($payment['id'] ?? null);
        if ($id !== $tender['payment_id']
            || agent_commerce_evidence_id_pa2($payment['order_id'] ?? null) !== $binding['provider_order_id']
            || agent_commerce_evidence_id_pa2($payment['location_id'] ?? null) !== $binding['provider_location_id']
            || !in_array($payment['status'] ?? null, ['APPROVED', 'PENDING', 'COMPLETED', 'CANCELED', 'FAILED'], true)) agent_commerce_evidence_fail_pa2();
        agent_commerce_evidence_money_pa2($payment['amount_money'] ?? null, $binding);
        if (array_key_exists('total_money', $payment)) agent_commerce_evidence_money_pa2($payment['total_money'], $binding);
        if (array_key_exists('tip_money', $payment)) {
            $tip = agent_commerce_evidence_object_pa2($payment['tip_money']);
            if (agent_commerce_evidence_integer_pa2($tip['amount'] ?? null) !== 0
                || agent_commerce_evidence_currency_pa2($tip['currency'] ?? null) !== $binding['currency']) agent_commerce_evidence_fail_pa2();
        }
        if ($payment['status'] !== 'COMPLETED') return agent_commerce_evidence_pending_pa2('payment_not_completed');
        if (array_key_exists('refunded_money', $payment)) {
            $refund = agent_commerce_evidence_object_pa2($payment['refunded_money']);
            if (agent_commerce_evidence_currency_pa2($refund['currency'] ?? null) !== $binding['currency']) agent_commerce_evidence_fail_pa2();
            if (agent_commerce_evidence_integer_pa2($refund['amount'] ?? null) > 0) return agent_commerce_evidence_pending_pa2('payment_adjusted');
        }
        return agent_commerce_evidence_verified_pa2($binding, $id);
    }
    $member = agent_commerce_evidence_paypal_order_pa2($binding, $evidence['order'] ?? null);
    if (!$member) return agent_commerce_evidence_pending_pa2();
    $capture = agent_commerce_evidence_paypal_capture_pa2($binding, $evidence['capture'] ?? null);
    $id = $capture['id'];
    if ($id !== $member['id']) agent_commerce_evidence_fail_pa2();
    if (in_array($member['status'], ['REFUNDED', 'PARTIALLY_REFUNDED'], true)) return agent_commerce_evidence_pending_pa2('payment_adjusted');
    if ($capture['status'] !== 'COMPLETED') return agent_commerce_evidence_pending_pa2('payment_not_completed');
    if (!in_array($member['status'], ['COMPLETED', 'PENDING'], true)) agent_commerce_evidence_fail_pa2();
    return agent_commerce_evidence_verified_pa2($binding, $id);
}

/**
 * The callback must GET the relative path with the exact connection's existing
 * credentials/account scope. Provider links, search endpoints and payload URLs
 * are never followed. This routine deliberately cannot capture or refund.
 */
function agent_commerce_provider_read_pa2(string $provider, array $attempt, array $order, array $connection, callable $get): array
{
    $binding = agent_commerce_evidence_binding_pa2($provider, $attempt, $order, $connection);
    if ($provider === 'stripe') {
        $session = $get('/v1/checkout/sessions/'.rawurlencode($binding['external_session_id']));
        $session = agent_commerce_evidence_stripe_session_pa2($binding, $session, $binding['provider_account_id']);
        $evidence = ['session' => $session, 'account_id' => $binding['provider_account_id']];
        if ($session['payment_status'] === 'paid') {
            $reference = $session['payment_intent'] ?? null;
            if (is_array($reference)) $reference = agent_commerce_evidence_object_pa2($reference)['id'] ?? null;
            $id = agent_commerce_evidence_payment_pa2(agent_commerce_evidence_id_pa2($reference), $binding);
            $evidence['payment_intent'] = $get('/v1/payment_intents/'.rawurlencode($id));
        }
    } elseif ($provider === 'square') {
        $response = agent_commerce_evidence_object_pa2($get('/v2/orders/'.rawurlencode($binding['provider_order_id'])));
        $tender = agent_commerce_evidence_square_order_pa2($binding, $response['order'] ?? null);
        $evidence = ['order' => $response['order']];
        if ($tender) {
            $response = agent_commerce_evidence_object_pa2($get('/v2/payments/'.rawurlencode($tender['payment_id'])));
            $evidence['payment'] = $response['payment'] ?? null;
        }
    } else {
        $providerOrder = $get('/v2/checkout/orders/'.rawurlencode($binding['provider_order_id']));
        $member = agent_commerce_evidence_paypal_order_pa2($binding, $providerOrder);
        $evidence = ['order' => $providerOrder];
        if ($member) $evidence['capture'] = $get('/v2/payments/captures/'.rawurlencode($member['id']));
    }
    return agent_commerce_provider_evidence_pa2($provider, $attempt, $order, $connection, $evidence);
}

/** Routing hints only. A verified signature is mandatory before this function. */
function agent_commerce_provider_event_target_pa2(string $provider, array $event): array
{
    if (!in_array($provider, ['stripe', 'square', 'paypal'], true)) agent_commerce_evidence_fail_pa2();
    $id = agent_commerce_evidence_id_pa2($event[$provider === 'square' ? 'event_id' : 'id'] ?? null);
    $type = agent_commerce_evidence_id_pa2($event[$provider === 'paypal' ? 'event_type' : 'type'] ?? null);
    $supported = ['stripe' => ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed'],
        'square' => ['payment.created', 'payment.updated'],
        'paypal' => ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED']];
    if (!in_array($type, $supported[$provider], true)) return ['status' => 'ignored', 'event_id' => $id, 'event_type' => $type];
    $target = ['status' => 'target', 'provider' => $provider, 'event_id' => $id, 'event_type' => $type,
        'provider_account_id' => '', 'provider_location_id' => '', 'external_session_id' => '', 'provider_order_id' => '', 'expected_payment_id' => ''];
    if ($provider === 'stripe') {
        $data = agent_commerce_evidence_object_pa2($event['data'] ?? null);
        $session = agent_commerce_evidence_object_pa2($data['object'] ?? null);
        if (($session['object'] ?? null) !== 'checkout.session') agent_commerce_evidence_fail_pa2();
        $target['provider_account_id'] = agent_commerce_evidence_id_pa2($event['account'] ?? null);
        $target['external_session_id'] = $target['provider_order_id'] = agent_commerce_evidence_id_pa2($session['id'] ?? null);
        $reference = $session['payment_intent'] ?? null;
        if (is_array($reference)) $reference = agent_commerce_evidence_object_pa2($reference)['id'] ?? null;
        if ($reference !== null) $target['expected_payment_id'] = agent_commerce_evidence_id_pa2($reference);
    } elseif ($provider === 'square') {
        $data = agent_commerce_evidence_object_pa2($event['data'] ?? null);
        $object = agent_commerce_evidence_object_pa2($data['object'] ?? null);
        $payment = agent_commerce_evidence_object_pa2($object['payment'] ?? null);
        $target['provider_account_id'] = agent_commerce_evidence_id_pa2($event['merchant_id'] ?? null);
        $target['provider_location_id'] = agent_commerce_evidence_id_pa2($payment['location_id'] ?? null);
        $target['provider_order_id'] = agent_commerce_evidence_id_pa2($payment['order_id'] ?? null);
        $target['expected_payment_id'] = agent_commerce_evidence_id_pa2($payment['id'] ?? null);
        if (array_key_exists('id', $data) && agent_commerce_evidence_id_pa2($data['id']) !== $target['expected_payment_id']) agent_commerce_evidence_fail_pa2();
    } else {
        $capture = agent_commerce_evidence_object_pa2($event['resource'] ?? null);
        $payee = agent_commerce_evidence_object_pa2($capture['payee'] ?? null);
        $supplementary = agent_commerce_evidence_object_pa2($capture['supplementary_data'] ?? null);
        $related = agent_commerce_evidence_object_pa2($supplementary['related_ids'] ?? null);
        $target['provider_account_id'] = agent_commerce_evidence_id_pa2($payee['merchant_id'] ?? null);
        $target['external_session_id'] = $target['provider_order_id'] = agent_commerce_evidence_id_pa2($related['order_id'] ?? null);
        $target['expected_payment_id'] = agent_commerce_evidence_id_pa2($capture['id'] ?? null);
    }
    return $target;
}
