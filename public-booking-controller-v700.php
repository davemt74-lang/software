<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$pdo = db();
if (!$pdo || !agent_scheduling_schema_ready_v430($pdo) || !profile_agent_schema_ready($pdo)) {
    http_response_code(503);
    exit('Scheduling is not ready.');
}
$lifecycleReady = function_exists('agent_appointment_lifecycle_schema_ready_v700')
    && agent_appointment_lifecycle_schema_ready_v700($pdo);

function public_booking_redirect_v450(string $target): never
{
    if ($target === '') {
        http_response_code(500);
        exit('Booking URL could not be created.');
    }
    redirect($target);
}

function public_booking_location_label_v450(array $event): string
{
    return match ((string)($event['location_type'] ?? 'virtual')) {
        'phone' => 'Phone call',
        'in_person' => 'In person',
        'custom' => 'Custom location',
        default => 'Virtual meeting',
    };
}

function public_booking_owner_notification_v450(array $profile, array $booking, string $type, string $title): void
{
    $ownerId = (int)($profile['user_id'] ?? 0);
    if ($ownerId < 1) return;
    $when = agent_scheduling_public_display_time_v450(
        (string)$booking['start_at_utc'],
        (string)$booking['organizer_timezone'],
        'D, M j · g:i A T'
    );
    $guest = trim((string)($booking['guest_name'] ?? '')) ?: 'A guest';
    $event = trim((string)($booking['event_title'] ?? '')) ?: 'appointment';
    $body = $guest . ' · ' . $event . ($when !== '' ? ' · ' . $when : '');
    create_notification(
        $ownerId,
        $type,
        $title,
        $body,
        (function_exists('agent_appointment_lifecycle_schema_ready_v700') && ($pdo=db()) && agent_appointment_lifecycle_schema_ready_v700($pdo))
            ? url('/appointment-lifecycle.php?booking=' . (int)$booking['id'])
            : url('/scheduling.php?schedule=' . (int)$booking['schedule_id'] . '&open=' . (int)$booking['id'] . '#bookings'),
        'agent_scheduling_booking',
        (int)$booking['id']
    );
}

$username = profile_username_normalize((string)($_GET['username'] ?? ''));
$profile = $username !== '' ? profile_by_username($pdo, $username) : null;
if (!$profile || empty($profile['is_active']) || empty($profile['is_public'])) {
    http_response_code(404);
    exit('Booking page not found.');
}

$displayName = trim((string)($profile['display_name'] ?? '')) ?: $username;
$schedule = agent_scheduling_public_schedule_v450($pdo, (int)$profile['user_id']);
$events = $schedule ? agent_scheduling_public_events_v450($pdo, (int)$schedule['id']) : [];
$eventSlug = agent_scheduling_slug_v430((string)($_GET['event'] ?? ''));
$event = $eventSlug !== '' ? agent_scheduling_public_event_by_slug_v450($pdo, (int)$profile['user_id'], $eventSlug) : null;
if ($eventSlug !== '' && !$event) {
    http_response_code(404);
    exit('Appointment type not found.');
}

$manageToken = strtolower(trim((string)($_GET['manage'] ?? '')));
$managedBooking = $manageToken !== '' ? agent_scheduling_booking_by_cancel_token_v450($pdo, $manageToken) : null;
if ($manageToken !== '' && (!$managedBooking || (int)$managedBooking['owner_user_id'] !== (int)$profile['user_id'])) {
    http_response_code(404);
    exit('Booking management link not found.');
}
$managedEvent = $managedBooking && (int)($managedBooking['event_type_id'] ?? 0) > 0
    ? agent_scheduling_event_type_v430($pdo, (int)$managedBooking['event_type_id'])
    : null;

$pageError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your booking session expired. Refresh the page and try again.');
        if (trim((string)($_POST['website'] ?? '')) !== '') throw new RuntimeException('Booking could not be submitted.');

        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'book') {
            agent_scheduling_public_rate_limit_v450('book', 10, 60);
            $eventId = (int)($_POST['event_type_id'] ?? 0);
            $postEvent = agent_scheduling_public_event_for_owner_v450($pdo, (int)$profile['user_id'], $eventId);
            if (!$postEvent || !$schedule || (int)$postEvent['schedule_id'] !== (int)$schedule['id']) {
                throw new RuntimeException('This appointment type is no longer available.');
            }
            $email = strtolower(trim((string)($_POST['guest_email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
            $intakeAnswers = is_array($_POST['intake'] ?? null) ? (array)$_POST['intake'] : [];
            if ($lifecycleReady) agent_appointment_lifecycle_validate_intake_v700($pdo, $eventId, $intakeAnswers);

            $booking = agent_scheduling_create_booking_v430($pdo, [
                'event_type_id' => $eventId,
                'start_at_utc' => trim((string)($_POST['start_at_utc'] ?? '')),
                'guest_timezone' => trim((string)($_POST['guest_timezone'] ?? '')),
                'guest_name' => trim((string)($_POST['guest_name'] ?? '')),
                'guest_email' => $email,
                'guest_phone' => trim((string)($_POST['guest_phone'] ?? '')),
                'guest_notes' => trim((string)($_POST['guest_notes'] ?? '')),
                'source' => 'public',
            ]);

            if ($lifecycleReady) {
                $booking = agent_appointment_lifecycle_booking_v700($pdo, (int)$booking['id'], (int)$profile['user_id']) ?: $booking;
                try {
                    if ($intakeAnswers) agent_appointment_lifecycle_capture_intake_v700($pdo, $booking, $intakeAnswers);
                } catch (Throwable $captureError) {
                    try { agent_scheduling_cancel_booking_v430($pdo, (int)$booking['id'], (int)$profile['user_id']); } catch (Throwable $ignored) {}
                    throw $captureError;
                }
                agent_appointment_lifecycle_event_v700($pdo, $booking, 'confirmed', '', 'confirmed', 'guest', null, null, ['source'=>'public']);
                agent_appointment_lifecycle_queue_booking_v700($pdo, $booking, true);
                agent_appointment_lifecycle_housekeeping_v700($pdo, 100);
            } else {
                public_booking_owner_notification_v450($profile, $booking, 'scheduling_booking_created', 'New appointment booked');
            }
            public_booking_redirect_v450(agent_scheduling_public_manage_url_v450($username, (string)$booking['cancel_token']) . '?confirmed=1');
        }

        if ($action === 'cancel') {
            if (!$managedBooking || !hash_equals((string)$managedBooking['cancel_token'], $manageToken)) throw new RuntimeException('This booking management link is not valid.');
            if ($lifecycleReady) {
                $managedBooking = agent_appointment_lifecycle_booking_v700($pdo, (int)$managedBooking['id'], (int)$profile['user_id']) ?: $managedBooking;
                $managedBooking = agent_appointment_lifecycle_transition_v700($pdo, $managedBooking, 'cancelled', 'guest', null, null, ['source'=>'private_manage_link']);
                agent_appointment_lifecycle_housekeeping_v700($pdo, 100);
            } else {
                if (!agent_scheduling_cancel_booking_v430($pdo, (int)$managedBooking['id'], null, $manageToken)) throw new RuntimeException('This appointment could not be cancelled.');
                $managedBooking['status'] = 'cancelled';
                public_booking_owner_notification_v450($profile, $managedBooking, 'scheduling_booking_cancelled', 'Appointment cancelled');
            }
            public_booking_redirect_v450(agent_scheduling_public_manage_url_v450($username, $manageToken) . '?cancelled=1');
        }

        if ($action === 'reschedule') {
            agent_scheduling_public_rate_limit_v450('reschedule', 10, 60);
            if (!$managedBooking || !hash_equals((string)$managedBooking['cancel_token'], $manageToken)) throw new RuntimeException('This booking management link is not valid.');
            if ($lifecycleReady) {
                $managedBooking = agent_appointment_lifecycle_booking_v700($pdo, (int)$managedBooking['id'], (int)$profile['user_id']) ?: $managedBooking;
                $newBooking = agent_appointment_lifecycle_reschedule_v700(
                    $pdo,
                    $managedBooking,
                    trim((string)($_POST['start_at_utc'] ?? '')),
                    trim((string)($_POST['guest_timezone'] ?? (string)$managedBooking['guest_timezone'])),
                    'guest'
                );
                agent_appointment_lifecycle_housekeeping_v700($pdo, 100);
            } else {
                $newBooking = agent_scheduling_public_reschedule_v450(
                    $pdo,
                    $managedBooking,
                    $manageToken,
                    trim((string)($_POST['start_at_utc'] ?? '')),
                    trim((string)($_POST['guest_timezone'] ?? (string)$managedBooking['guest_timezone']))
                );
                public_booking_owner_notification_v450($profile, $newBooking, 'scheduling_booking_rescheduled', 'Appointment rescheduled');
            }
            public_booking_redirect_v450(agent_scheduling_public_manage_url_v450($username, (string)$newBooking['cancel_token']) . '?rescheduled=1');
        }

        throw new RuntimeException('Unknown booking action.');
    } catch (Throwable $e) {
        $pageError = $e->getMessage();
        if ($manageToken !== '') {
            $managedBooking = agent_scheduling_booking_by_cancel_token_v450($pdo, $manageToken);
            $managedEvent = $managedBooking && (int)($managedBooking['event_type_id'] ?? 0) > 0
                ? agent_scheduling_event_type_v430($pdo, (int)$managedBooking['event_type_id'])
                : null;
        }
    }
}

$scheduleTimezone = $schedule ? agent_scheduling_timezone_v430((string)$schedule['timezone']) : 'UTC';
$today = (new DateTimeImmutable('now', new DateTimeZone($scheduleTimezone)))->format('Y-m-d');
$selectedDate = trim((string)($_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) $selectedDate = $today;
$slotEvent = $managedBooking ? $managedEvent : $event;
$slotEventPublic = $slotEvent ? agent_scheduling_public_event_for_owner_v450($pdo, (int)$profile['user_id'], (int)$slotEvent['id']) : null;
$windowDays = $slotEventPublic ? max(1, min(730, (int)$slotEventPublic['booking_window_days'])) : 60;
$maxDate = (new DateTimeImmutable($today, new DateTimeZone($scheduleTimezone)))->modify('+' . $windowDays . ' days')->format('Y-m-d');
$serverSlots = $slotEventPublic ? agent_scheduling_slots_for_date_v430($pdo, (int)$slotEventPublic['id'], $selectedDate, true) : [];
$intakeQuestions = $lifecycleReady && $event ? agent_appointment_lifecycle_questions_v700($pdo, (int)$event['id'], true) : [];

$confirmed = !empty($_GET['confirmed']);
$rescheduled = !empty($_GET['rescheduled']);
$cancelled = !empty($_GET['cancelled']);
$profileUrl = profile_public_url($username);
$agentName = trim((string)($schedule['agent_name'] ?? ''));
$bookingPageTitle = $managedBooking ? 'Manage appointment' : ($event ? (string)$event['title'] : 'Book a time');

if ($managedBooking && !headers_sent()) {
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}
