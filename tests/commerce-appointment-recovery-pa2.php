<?php
declare(strict_types=1);

require __DIR__.'/fixtures/commerce-recovery-pa2.php';
require dirname(__DIR__).'/includes/agent-commerce-provider-evidence-pa2.php';
require dirname(__DIR__).'/includes/agent-scheduling-v430.php';
require dirname(__DIR__).'/includes/agent-team-scheduling-v600.php';
// Load the real lifecycle services without booting unrelated Video Meeting UI.
foreach ([1, 2, 3, 5] as $part) require dirname(__DIR__).'/includes/agent-appointment-lifecycle-v700-part'.$part.'.php';
require dirname(__DIR__).'/includes/agent-paid-appointments-v800.php';

$fixtureConfig = commerce_fixture_config_pa2();
register_shutdown_function(static fn() => commerce_fixture_cleanup_pa2($fixtureConfig));
$pdo = commerce_fixture_connect_pa2($fixtureConfig); $GLOBALS['pdo'] = $pdo;
commerce_fixture_schema_pa2($pdo);
$pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(190) NOT NULL DEFAULT ''");
$pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(190) NOT NULL DEFAULT ''");
$pdo->exec("UPDATE users SET display_name='Fixture host',email='host@example.test'");
agent_scheduling_ensure_schema_v430($pdo);
agent_team_scheduling_ensure_schema_v600($pdo);
agent_appointment_lifecycle_ensure_schema_v700($pdo);
$checks = 0;
function appointmentCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    $GLOBALS['checks']++; echo "PASS $label\n";
}
function appointmentRow(string $table, int $id): array
{
    $q = db()->prepare('SELECT * FROM '.$table.' WHERE id=?'); $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC); $q->closeCursor(); return $row ?: [];
}
function appointmentCount(string $table, int $bookingId, string $condition = '1=1'): int
{
    $q = db()->prepare('SELECT COUNT(*) FROM '.$table.' WHERE booking_id=? AND '.$condition); $q->execute([$bookingId]);
    $count = (int)$q->fetchColumn(); $q->closeCursor(); return $count;
}
function appointmentJobs(array $fixture): array
{
    return commerce_fixture_rows_pa2(db(), 'agent_commerce_fulfillment_jobs_pa2', 'order_id='.(int)$fixture['id']." AND event_type<>'project_paid'");
}
function appointmentBooking(int $owner = 1): array
{
    $pdo = db(); $suffix = bin2hex(random_bytes(6));
    $schedule = commerce_fixture_insert_pa2($pdo, 'agent_scheduling_schedules', ['owner_user_id' => $owner, 'slug' => 'schedule-'.$suffix]);
    $event = commerce_fixture_insert_pa2($pdo, 'agent_scheduling_event_types', ['schedule_id' => $schedule, 'slug' => 'event-'.$suffix,
        'title' => 'Fixture appointment', 'confirmation_enabled' => 1, 'reminder_24h_enabled' => 0]);
    $booking = commerce_fixture_insert_pa2($pdo, 'agent_scheduling_bookings', ['owner_user_id' => $owner,
        'schedule_id' => $schedule, 'event_type_id' => $event, 'event_title' => 'Fixture appointment', 'duration_minutes' => 30,
        'start_at_utc' => gmdate('Y-m-d H:i:s', time()+172800), 'end_at_utc' => gmdate('Y-m-d H:i:s', time()+174600),
        'guest_name' => 'Fixture guest', 'guest_email' => 'guest@example.test', 'status' => 'confirmed', 'lifecycle_status' => 'confirmed',
        'public_token' => hash('sha256', 'public-'.$suffix), 'cancel_token' => hash('sha256', 'cancel-'.$suffix)]);
    // This is the source-defined payment hold: status stays confirmed while the
    // lifecycle becomes pending. A fake pending/pending seed would miss the bug.
    agent_paid_appointments_mark_canonical_pending_v800($pdo, $booking);
    return ['booking' => $booking, 'schedule' => $schedule, 'event' => $event, 'owner' => $owner];
}
function appointmentOrder(array $booking, array $changes = []): array
{
    return commerce_fixture_order_pa2(db(), array_replace(['fulfillment_type' => 'appointment',
        'fulfillment_ref_type' => 'appointment_booking', 'fulfillment_ref_id' => $booking['booking']], $changes), [],
        ['fulfillment_type' => 'appointment', 'fulfillment_ref_type' => 'appointment_booking', 'fulfillment_ref_id' => $booking['booking']]);
}
function appointmentTeam(): array
{
    $pdo = db(); $first = appointmentBooking(1); $second = appointmentBooking(2); $suffix = bin2hex(random_bytes(6));
    $pool = commerce_fixture_insert_pa2($pdo, 'agent_team_scheduling_pools', ['workspace_owner_user_id' => 1,
        'name' => 'Fixture team pool', 'slug' => 'team-'.$suffix, 'public_key' => hash('sha256', $suffix), 'mode' => 'collective']);
    $team = commerce_fixture_insert_pa2($pdo, 'agent_team_scheduling_bookings', ['workspace_owner_user_id' => 1,
        'pool_id' => $pool, 'assigned_user_id' => 1, 'start_at_utc' => gmdate('Y-m-d H:i:s', time()+172800),
        'end_at_utc' => gmdate('Y-m-d H:i:s', time()+174600), 'guest_name' => 'Fixture guest', 'guest_email' => 'guest@example.test',
        'status' => 'pending', 'public_token' => hash('sha256', 'team-public-'.$suffix), 'cancel_token' => hash('sha256', 'team-cancel-'.$suffix)]);
    foreach ([$first, $second] as $booking) {
        $member = commerce_fixture_insert_pa2($pdo, 'agent_team_scheduling_members', ['pool_id' => $pool, 'user_id' => $booking['owner'],
            'schedule_id' => $booking['schedule'], 'event_type_id' => $booking['event']]);
        commerce_fixture_insert_pa2($pdo, 'agent_team_scheduling_booking_members', ['team_booking_id' => $team,
            'pool_member_id' => $member, 'user_id' => $booking['owner'], 'canonical_booking_id' => $booking['booking']]);
    }
    $fixture = appointmentOrder($first, ['workspace_owner_user_id' => 1, 'fulfillment_group_type' => 'team_booking',
        'fulfillment_group_id' => $team, 'metadata_json' => json_encode(['team_scheduling_pool_id' => $pool,
            'participant_booking_ids' => [$first['booking'], $second['booking']]])]);
    return compact('first', 'second', 'team', 'fixture');
}
function appointmentPay(array $fixture, int $amount = 5000): array
{
    $fixture['amount'] = $amount;
    $order = agent_commerce_order_v800(db(), (int)$fixture['id']);
    $attempt = appointmentRow('agent_commerce_checkout_attempts_v800', (int)$fixture['attempt_id']);
    $proof = agent_commerce_read_attempt_pa2(db(), $order, $attempt, commerce_fixture_stripe_transport_pa2([$fixture]));
    return agent_commerce_mark_paid_v800(db(), (int)$fixture['id'], 'stripe', $fixture['session'], $fixture['payment'], $amount, 'usd', $proof);
}
function appointmentCancel(int $booking): void
{
    agent_appointment_lifecycle_transition_v700(db(), agent_appointment_lifecycle_booking_v700(db(), $booking), 'cancelled', 'guest');
}
function appointmentState(int $booking): string { return (string)(appointmentRow('agent_scheduling_bookings', $booking)['lifecycle_status'] ?? 'missing'); }
function appointmentConfirmationCount(int $booking): int { return appointmentCount('agent_scheduling_automation_deliveries', $booking, "automation_key='confirmation'"); }
function appointmentConfirmationEvents(int $booking): int { return appointmentCount('agent_scheduling_lifecycle_events', $booking, "event_type='status_changed' AND to_status='confirmed'"); }

// Real successful payment hold, durable financial receipt, and canonical queue.
$booking = appointmentBooking(); $f = appointmentOrder($booking);
appointmentCheck(appointmentRow('agent_scheduling_bookings', $booking['booking'])['status'] === 'confirmed' && appointmentState($booking['booking']) === 'pending', 'Canonical hold preserves the confirmed reservation with pending lifecycle');
appointmentPay($f);
appointmentCheck(appointmentState($booking['booking']) === 'pending' && appointmentConfirmationCount($booking['booking']) === 0, 'Payment commits before appointment work and does not send an early confirmation');
$result = agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck($result['completed'] === 1 && appointmentState($booking['booking']) === 'confirmed', 'Real held booking confirms through durable fulfillment');
appointmentCheck(appointmentRow('agent_commerce_order_items_v800', $f['item_id'])['fulfillment_status'] === 'confirmed' && appointmentJobs($f)[0]['status'] === 'completed', 'Appointment and item confirmation share a successful job receipt');
appointmentCheck(appointmentConfirmationEvents($booking['booking']) === 1 && appointmentConfirmationCount($booking['booking']) === 2, 'Canonical lifecycle and queue record one host and one guest confirmation');
appointmentPay($f); agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(count(appointmentJobs($f)) === 1 && appointmentConfirmationCount($booking['booking']) === 2 && appointmentConfirmationEvents($booking['booking']) === 1, 'Repeated verified payment does not duplicate appointment confirmation');

// Old stores can have pending/pending instead of confirmed/pending.
$legacy = appointmentBooking(); $pdo->prepare("UPDATE agent_scheduling_bookings SET status='pending' WHERE id=?")->execute([$legacy['booking']]);
$f = appointmentOrder($legacy); appointmentPay($f); agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentState($legacy['booking']) === 'confirmed' && appointmentConfirmationEvents($legacy['booking']) === 1, 'Canonical legacy pending status still uses the real transition service');

// Missing and cancelled records cannot be converted into a completion claim.
$f = appointmentOrder(['booking' => 987654321]); appointmentPay($f); agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentJobs($f)[0]['status'] === 'review_required' && appointmentRow('agent_commerce_order_items_v800', $f['item_id'])['fulfillment_status'] === 'review_required', 'Missing personal booking remains unfulfilled and requires review');
$cancelled = appointmentBooking(); appointmentCancel($cancelled['booking']); $f = appointmentOrder($cancelled);
$before = appointmentCount('agent_scheduling_automation_deliveries', $cancelled['booking']);
appointmentPay($f); agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentState($cancelled['booking']) === 'cancelled' && appointmentJobs($f)[0]['status'] === 'review_required', 'Cancelled personal booking is never reopened by payment recovery');
appointmentCheck(appointmentConfirmationCount($cancelled['booking']) === 0 && appointmentCount('agent_scheduling_automation_deliveries', $cancelled['booking']) === $before, 'Rejected cancelled booking adds no confirmation or notification');

// Every member is part of one domain transaction; the first transition rolls
// back when the second canonical member is cancelled or missing.
$team = appointmentTeam(); appointmentCancel($team['second']['booking']); appointmentPay($team['fixture']);
agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentState($team['first']['booking']) === 'pending' && appointmentState($team['second']['booking']) === 'cancelled', 'A cancelled team member rolls back all earlier member confirmation');
appointmentCheck(appointmentRow('agent_team_scheduling_bookings', $team['team'])['status'] === 'pending'
    && appointmentJobs($team['fixture'])[0]['status'] === 'review_required', 'Team and fulfillment receipt cannot claim partial success');
appointmentCheck(appointmentConfirmationEvents($team['first']['booking']) === 0 && appointmentConfirmationCount($team['first']['booking']) === 0, 'Rolled-back team member leaves no lifecycle confirmation or queued notice');
$team = appointmentTeam();
// Corrupt historical associations can predate constraints. Only fixture setup
// disables the check, then restores it before the real adapter runs.
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$pdo->exec($mysql ? 'SET FOREIGN_KEY_CHECKS=0' : 'PRAGMA foreign_keys=OFF');
$pdo->prepare('DELETE FROM agent_scheduling_bookings WHERE id=?')->execute([$team['second']['booking']]);
$pdo->exec($mysql ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys=ON');
appointmentPay($team['fixture']); agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentState($team['first']['booking']) === 'pending' && appointmentJobs($team['fixture'])[0]['status'] === 'review_required', 'Missing canonical team member rolls back every other member');
appointmentCheck(appointmentConfirmationEvents($team['first']['booking']) === 0 && appointmentConfirmationCount($team['first']['booking']) === 0, 'Missing member cannot leave an earlier confirmation or notification committed');

$team = appointmentTeam();
$pdo->prepare('DELETE FROM agent_team_scheduling_booking_members WHERE team_booking_id=? AND canonical_booking_id=?')->execute([$team['team'], $team['second']['booking']]);
appointmentPay($team['fixture']); agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentState($team['first']['booking']) === 'pending' && appointmentState($team['second']['booking']) === 'pending'
    && appointmentJobs($team['fixture'])[0]['status'] === 'review_required', 'A removed team membership cannot erase a participant from the immutable paid booking');
appointmentCheck(appointmentConfirmationEvents($team['first']['booking']) === 0 && appointmentConfirmationCount($team['first']['booking']) === 0,
    'Missing membership cannot produce a partial team confirmation');

$team = appointmentTeam(); appointmentPay($team['fixture']); agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentState($team['first']['booking']) === 'confirmed' && appointmentState($team['second']['booking']) === 'confirmed'
    && appointmentRow('agent_team_scheduling_bookings', $team['team'])['status'] === 'confirmed', 'Valid team payment confirms both exact canonical bookings and team');
appointmentCheck(appointmentConfirmationCount($team['first']['booking']) === 2 && appointmentConfirmationCount($team['second']['booking']) === 1, 'Real team queue sends each host one confirmation and the guest only one');

// Deposit confirmation remains singular after the later balance receipt.
$deposit = appointmentBooking();
$f = appointmentOrder($deposit, ['payment_mode' => 'deposit', 'amount_due_cents' => 1000,
    'terms_snapshot_json' => '{"payment_mode":"deposit","price_cents":5000,"deposit_cents":1000,"currency":"usd"}']);
appointmentPay($f, 1000); agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentState($deposit['booking']) === 'confirmed' && appointmentConfirmationCount($deposit['booking']) === 2, 'Deposit activates exactly one appointment confirmation');
$balance = $f; $balance['session'] .= '_balance'; $balance['payment'] .= '_balance';
$balance['attempt_id'] = commerce_fixture_insert_pa2($pdo, 'agent_commerce_checkout_attempts_v800', [
    'order_id' => $f['id'], 'connection_id' => $f['connection_id'], 'provider' => 'stripe', 'external_session_id' => $balance['session'],
    'provider_order_id' => $balance['session'], 'provider_account_id' => $f['account'], 'provider_location_id' => '',
    'amount_cents' => 4000, 'currency' => 'usd', 'idempotency_key' => hash('sha256', $balance['session']),
]);
appointmentPay($balance, 4000); agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentRow('agent_commerce_orders_v800', $f['id'])['payment_status'] === 'paid' && count(appointmentJobs($f)) === 2, 'Balance payment has its own durable receipt and fulfillment update');
appointmentCheck(appointmentConfirmationCount($deposit['booking']) === 2 && appointmentConfirmationEvents($deposit['booking']) === 1, 'Balance payment never duplicates the original confirmation or notifications');
appointmentPay($f, 1000); appointmentPay($balance, 4000); agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentConfirmationCount($deposit['booking']) === 2 && count(appointmentJobs($f)) === 2, 'Replaying both deposit and balance preserves canonical occurrence uniqueness');

// A crash after actual domain writes must roll back them and their queued
// notifications while leaving the separately committed financial receipt.
$crash = appointmentBooking(); $f = appointmentOrder($crash); appointmentPay($f); $fired = false;
$pdo->afterSql = static function(string $sql) use (&$fired): void {
    if (!$fired && str_contains($sql, "UPDATE agent_commerce_order_items_v800 SET fulfillment_status='confirmed'")) {
        $fired = true; throw new PDOException('Fixture post-domain-write crash');
    }
};
agent_commerce_fulfillment_run_pa2($pdo); $pdo->afterSql = null;
appointmentCheck($fired && appointmentState($crash['booking']) === 'pending'
    && appointmentJobs($f)[0]['status'] === 'retry', 'Post-domain-write crash rolls back booking and queues a retry');
appointmentCheck(appointmentConfirmationCount($crash['booking']) === 0 && appointmentConfirmationEvents($crash['booking']) === 0
    && appointmentRow('agent_commerce_order_items_v800', $f['item_id'])['fulfillment_status'] === 'pending', 'Post-domain-write rollback includes item, lifecycle event and notifications');
appointmentCheck((int)appointmentRow('agent_commerce_orders_v800', $f['id'])['amount_paid_cents'] === 5000
    && count(commerce_fixture_rows_pa2($pdo, 'agent_commerce_payments_v800', 'order_id='.(int)$f['id'])) === 1, 'Fulfillment crash leaves the verified money recorded once');
$pdo->prepare("UPDATE agent_commerce_fulfillment_jobs_pa2 SET next_attempt_at=NULL WHERE order_id=? AND status='retry'")->execute([$f['id']]);
agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentState($crash['booking']) === 'confirmed' && appointmentJobs($f)[0]['status'] === 'completed'
    && (int)appointmentJobs($f)[0]['attempt_count'] === 2, 'The same durable job completes on retry');
appointmentCheck(appointmentConfirmationCount($crash['booking']) === 2 && appointmentConfirmationEvents($crash['booking']) === 1, 'Successful retry establishes one lifecycle event and one notice per recipient');

// Provider settlement can arrive after the hold expires but before expiry cron.
$late = appointmentBooking(); $f = appointmentOrder($late, ['hold_expires_at' => '2000-01-01 00:00:00']);
appointmentPay($f); agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentRow('agent_commerce_orders_v800', $f['id'])['order_status'] === 'payment_review'
    && (int)appointmentRow('agent_commerce_orders_v800', $f['id'])['amount_paid_cents'] === 5000, 'Late money is recorded for review without confirming expired availability');
appointmentCheck(appointmentState($late['booking']) === 'cancelled' && appointmentConfirmationCount($late['booking']) === 0,
    'Late payment before expiry cron releases only its pending held reservation');
$confirmed = appointmentBooking(); $pdo->prepare("UPDATE agent_scheduling_bookings SET lifecycle_status='confirmed' WHERE id=?")->execute([$confirmed['booking']]);
$f = appointmentOrder($confirmed, ['hold_expires_at' => '2000-01-01 00:00:00']); appointmentPay($f); agent_commerce_fulfillment_run_pa2($pdo);
appointmentCheck(appointmentState($confirmed['booking']) === 'confirmed' && appointmentRow('agent_scheduling_bookings', $confirmed['booking'])['status'] === 'confirmed',
    'Late cleanup never cancels an already confirmed appointment');
appointmentCheck($GLOBALS['pa2_http_calls'] === [], 'No provider or notification network transport is invoked during appointment fulfillment');
echo 'COMMERCE_APPOINTMENT_RECOVERY_PA2=PASS checks='.$checks.' driver='.$fixtureConfig['driver']."\n";
