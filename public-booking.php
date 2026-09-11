<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$pdo = db();
if (!$pdo || !agent_scheduling_schema_ready_v430($pdo) || !profile_agent_schema_ready($pdo)) {
    http_response_code(503);
    exit('Scheduling is not ready.');
}

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
        url('/scheduling.php?schedule=' . (int)$booking['schedule_id'] . '&open=' . (int)$booking['id'] . '#bookings'),
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
            public_booking_owner_notification_v450($profile, $booking, 'scheduling_booking_created', 'New appointment booked');
            public_booking_redirect_v450(agent_scheduling_public_manage_url_v450($username, (string)$booking['cancel_token']) . '?confirmed=1');
        }

        if ($action === 'cancel') {
            if (!$managedBooking || !hash_equals((string)$managedBooking['cancel_token'], $manageToken)) throw new RuntimeException('This booking management link is not valid.');
            if (!agent_scheduling_cancel_booking_v430($pdo, (int)$managedBooking['id'], null, $manageToken)) {
                throw new RuntimeException('This appointment could not be cancelled.');
            }
            $managedBooking['status'] = 'cancelled';
            public_booking_owner_notification_v450($profile, $managedBooking, 'scheduling_booking_cancelled', 'Appointment cancelled');
            public_booking_redirect_v450(agent_scheduling_public_manage_url_v450($username, $manageToken) . '?cancelled=1');
        }

        if ($action === 'reschedule') {
            agent_scheduling_public_rate_limit_v450('reschedule', 10, 60);
            if (!$managedBooking || !hash_equals((string)$managedBooking['cancel_token'], $manageToken)) throw new RuntimeException('This booking management link is not valid.');
            $newBooking = agent_scheduling_public_reschedule_v450(
                $pdo,
                $managedBooking,
                $manageToken,
                trim((string)($_POST['start_at_utc'] ?? '')),
                trim((string)($_POST['guest_timezone'] ?? (string)$managedBooking['guest_timezone']))
            );
            public_booking_owner_notification_v450($profile, $newBooking, 'scheduling_booking_rescheduled', 'Appointment rescheduled');
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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f5f5f3">
<?php if ($managedBooking): ?><meta name="robots" content="noindex,nofollow,noarchive"><?php endif; ?>
<title><?= e($bookingPageTitle) ?> | <?= e($displayName) ?></title>
<link rel="stylesheet" href="<?= e(url('/public-booking.css?v=agent-scheduling-public-v450-20260911')) ?>">
</head>
<body class="public-booking-page">
<header class="booking-topbar">
  <a class="booking-brand" href="<?= e(url('/')) ?>"><span><?= e(mb_strtoupper(mb_substr(system_agent_name(),0,1))) ?></span><strong><?= e(system_agent_name()) ?></strong></a>
  <a class="booking-profile-link" href="<?= e($profileUrl) ?>">← <?= e($displayName) ?>’s profile</a>
</header>

<main class="booking-shell">
  <aside class="booking-owner-card">
    <div class="booking-owner-mark"><?= e(mb_strtoupper(mb_substr($displayName,0,1))) ?></div>
    <span class="booking-kicker">Scheduling with</span>
    <h1><?= e($displayName) ?></h1>
    <?php if ($agentName !== ''): ?><p><strong><?= e($agentName) ?></strong> is handling availability and booking for this schedule.</p><?php else: ?><p>Choose an available time that works for you.</p><?php endif; ?>
    <?php if ($schedule): ?><div class="booking-timezone-note">Schedule timezone · <?= e($scheduleTimezone) ?></div><?php endif; ?>
  </aside>

  <section class="booking-card">
    <?php if ($pageError !== ''): ?><div class="booking-notice error" role="alert"><?= e($pageError) ?></div><?php endif; ?>

    <?php if ($managedBooking): ?>
      <?php
        $bookingTimezone = agent_scheduling_timezone_v430((string)($managedBooking['guest_timezone'] ?: $managedBooking['organizer_timezone']));
        $bookingWhen = agent_scheduling_public_display_time_v450((string)$managedBooking['start_at_utc'], $bookingTimezone);
        $activeBooking = in_array((string)$managedBooking['status'], ['pending','confirmed'], true);
        $calendarUrl = agent_scheduling_public_calendar_url_v450((string)$managedBooking['public_token']);
        $rebookUrl = $managedEvent ? agent_scheduling_public_booking_url_v450($username, (string)$managedEvent['slug']) : agent_scheduling_public_booking_url_v450($username);
      ?>
      <?php if ($confirmed): ?><div class="booking-notice success" role="status">Your appointment is confirmed.</div><?php endif; ?>
      <?php if ($rescheduled): ?><div class="booking-notice success" role="status">Your appointment has been rescheduled.</div><?php endif; ?>
      <?php if ($cancelled): ?><div class="booking-notice neutral" role="status">Your appointment was cancelled.</div><?php endif; ?>

      <div class="booking-section-head"><span class="booking-kicker">Your appointment</span><h2><?= e((string)$managedBooking['event_title']) ?></h2></div>
      <div class="booking-confirmation-grid">
        <div><span>Date + time</span><strong><?= e($bookingWhen !== '' ? $bookingWhen : (string)$managedBooking['start_at_utc'] . ' UTC') ?></strong></div>
        <div><span>Duration</span><strong><?= (int)$managedBooking['duration_minutes'] ?> minutes</strong></div>
        <div><span>With</span><strong><?= e($displayName) ?></strong></div>
        <div><span>Status</span><strong><?= e(ucfirst((string)$managedBooking['status'])) ?></strong></div>
      </div>

      <?php if ($activeBooking && trim((string)$managedBooking['location_value']) !== ''): ?>
        <div class="booking-location-card"><span><?= e(public_booking_location_label_v450($managedBooking)) ?></span>
          <?php $locationValue=trim((string)$managedBooking['location_value']); ?>
          <?php if (filter_var($locationValue,FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($locationValue,PHP_URL_SCHEME)),['http','https'],true)): ?>
            <a href="<?= e($locationValue) ?>" target="_blank" rel="noopener noreferrer nofollow">Open meeting location ↗</a>
          <?php else: ?><strong><?= e($locationValue) ?></strong><?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="booking-manage-actions">
        <?php if ($activeBooking && $calendarUrl !== ''): ?><a class="booking-button secondary" href="<?= e($calendarUrl) ?>">Add to calendar</a><?php endif; ?>
        <?php if (!$activeBooking && $rebookUrl !== ''): ?><a class="booking-button primary" href="<?= e($rebookUrl) ?>">Book another time</a><?php endif; ?>
      </div>

      <?php if ($activeBooking && $slotEventPublic): ?>
        <details class="booking-manage-panel" <?= $pageError !== '' ? 'open' : '' ?>>
          <summary>Reschedule appointment</summary>
          <form method="post" class="booking-reschedule-form" data-slot-form data-username="<?= e($username) ?>" data-event="<?= e((string)$slotEventPublic['slug']) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="reschedule"><input type="hidden" name="start_at_utc" data-slot-input><input type="hidden" name="guest_timezone" data-guest-timezone value="<?= e($bookingTimezone) ?>"><input type="text" name="website" class="booking-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
            <label class="booking-date-field"><span>Choose a new date</span><input type="date" data-booking-date value="<?= e($selectedDate) ?>" min="<?= e($today) ?>" max="<?= e($maxDate) ?>"></label>
            <div class="booking-slot-list" data-slot-list aria-live="polite">
              <?php foreach ($serverSlots as $slot): ?><button type="button" data-slot-value="<?= e((string)$slot['start_at_utc']) ?>"><?= e(agent_scheduling_public_display_time_v450((string)$slot['start_at_utc'],$bookingTimezone,'g:i A')) ?></button><?php endforeach; ?>
              <?php if (!$serverSlots): ?><p class="booking-empty-slots">No open times on this date.</p><?php endif; ?>
            </div>
            <button class="booking-button primary" type="submit" data-slot-submit disabled>Reschedule</button>
          </form>
        </details>
      <?php endif; ?>

      <?php if ($activeBooking): ?>
        <details class="booking-manage-panel danger-panel">
          <summary>Cancel appointment</summary>
          <form method="post" class="booking-cancel-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="text" name="website" class="booking-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
            <p>This releases the time back to <?= e($displayName) ?>’s schedule.</p>
            <button class="booking-button danger" type="submit">Cancel appointment</button>
          </form>
        </details>
      <?php endif; ?>

      <p class="booking-manage-security">Keep this page private. Its address is your secure appointment-management link.</p>

    <?php elseif (!$schedule || !$events): ?>
      <div class="booking-empty-state"><span class="booking-kicker">Scheduling</span><h2>No public appointment times are available right now.</h2><p><?= e($displayName) ?> has not published an active booking type yet.</p><a class="booking-button secondary" href="<?= e($profileUrl) ?>">Return to profile</a></div>

    <?php elseif (!$event): ?>
      <div class="booking-section-head"><span class="booking-kicker">Choose an appointment</span><h2>What would you like to book?</h2><p>Select a meeting type to see live availability.</p></div>
      <div class="booking-event-list">
        <?php foreach ($events as $item): ?>
          <a href="<?= e(agent_scheduling_public_booking_url_v450($username,(string)$item['slug'])) ?>">
            <div><strong><?= e((string)$item['title']) ?></strong><?php if (trim((string)($item['description']??'')) !== ''): ?><p><?= e((string)$item['description']) ?></p><?php endif; ?></div>
            <span><?= (int)$item['duration_minutes'] ?> min · <?= e(public_booking_location_label_v450($item)) ?> →</span>
          </a>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <div class="booking-section-head"><a class="booking-back" href="<?= e(agent_scheduling_public_booking_url_v450($username)) ?>">← Appointment types</a><span class="booking-kicker">Book a time</span><h2><?= e((string)$event['title']) ?></h2><p><?= e(trim((string)($event['description']??'')) !== '' ? (string)$event['description'] : public_booking_location_label_v450($event)) ?></p></div>
      <div class="booking-event-meta"><span><?= (int)$event['duration_minutes'] ?> minutes</span><span><?= e(public_booking_location_label_v450($event)) ?></span><span>Times shown in your timezone</span></div>

      <form method="post" class="booking-form" data-slot-form data-username="<?= e($username) ?>" data-event="<?= e((string)$event['slug']) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="book"><input type="hidden" name="event_type_id" value="<?= (int)$event['id'] ?>"><input type="hidden" name="start_at_utc" data-slot-input><input type="hidden" name="guest_timezone" data-guest-timezone value="<?= e($scheduleTimezone) ?>"><input type="text" name="website" class="booking-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
        <div class="booking-pick-grid">
          <label class="booking-date-field"><span>Choose a date</span><input type="date" data-booking-date value="<?= e($selectedDate) ?>" min="<?= e($today) ?>" max="<?= e($maxDate) ?>"></label>
          <div><span class="booking-field-label">Available times</span><div class="booking-slot-list" data-slot-list aria-live="polite">
            <?php foreach ($serverSlots as $slot): ?><button type="button" data-slot-value="<?= e((string)$slot['start_at_utc']) ?>"><?= e(agent_scheduling_public_display_time_v450((string)$slot['start_at_utc'],$scheduleTimezone,'g:i A')) ?></button><?php endforeach; ?>
            <?php if (!$serverSlots): ?><p class="booking-empty-slots">No open times on this date.</p><?php endif; ?>
          </div></div>
        </div>
        <div class="booking-details-grid">
          <label><span>Your name</span><input name="guest_name" maxlength="190" autocomplete="name" required value="<?= e((string)($_POST['guest_name']??'')) ?>"></label>
          <label><span>Email</span><input type="email" name="guest_email" maxlength="190" autocomplete="email" required value="<?= e((string)($_POST['guest_email']??'')) ?>"></label>
          <label><span>Phone <small>optional</small></span><input type="tel" name="guest_phone" maxlength="80" autocomplete="tel" value="<?= e((string)($_POST['guest_phone']??'')) ?>"></label>
          <label class="span-2"><span>Anything <?= e($displayName) ?> should know? <small>optional</small></span><textarea name="guest_notes" maxlength="3000" rows="3"><?= e((string)($_POST['guest_notes']??'')) ?></textarea></label>
        </div>
        <button class="booking-button primary book-submit" type="submit" data-slot-submit disabled>Confirm appointment</button>
        <p class="booking-privacy-copy">Your details are used to manage this appointment with <?= e($displayName) ?>.</p>
      </form>
    <?php endif; ?>
  </section>
</main>

<script>window.VP3_PUBLIC_SCHEDULING=<?= json_encode([
    'slotsEndpoint'=>url('/api/public-scheduling-slots-v450.php'),
    'scheduleTimezone'=>$scheduleTimezone,
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= e(url('/public-booking-v450.js?v=agent-scheduling-public-v450-20260911')) ?>"></script>
</body>
</html>
