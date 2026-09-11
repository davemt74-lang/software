<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');

$pdo = db();
$user = current_user();
if (!$pdo || !$user) redirect(url('/login.php'));
if (!agent_scheduling_schema_ready_v430($pdo) || !agent_calendar_sync_schema_ready_v500($pdo)) redirect(url('/upgrade.php'));

function scheduling_ui_time_to_minute(string $value): int
{
    $value = trim($value);
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) throw new RuntimeException('Enter a valid availability time.');
    $hour = (int)$m[1];
    $minute = (int)$m[2];
    if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) throw new RuntimeException('Enter a valid availability time.');
    return $hour * 60 + $minute;
}

function scheduling_ui_minute_to_time(int $minute): string
{
    $minute = max(0, min(1440, $minute));
    if ($minute === 1440) return '24:00';
    return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
}

function scheduling_ui_booking_label(string $utc, string $timezone): string
{
    if ($utc === '') return '—';
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(agent_scheduling_timezone_v430($timezone)))
            ->format('D, M j · g:i A');
    } catch (Throwable $e) {
        return '—';
    }
}

function scheduling_ui_redirect(int $scheduleId, string $saved, string $anchor = ''): never
{
    $target = url('/scheduling.php?schedule=' . $scheduleId . '&saved=' . rawurlencode($saved));
    if ($anchor !== '') $target .= '#' . rawurlencode($anchor);
    redirect($target);
}

$pageError = trim((string)($_GET['calendar_error'] ?? ''));
$schedule = agent_scheduling_default_schedule_v430($pdo, $user);
$requestedScheduleId = (int)($_GET['schedule'] ?? $_POST['schedule_id'] ?? 0);
if ($requestedScheduleId > 0) {
    $requested = agent_scheduling_schedule_v430($pdo, (int)$user['id'], $requestedScheduleId);
    if ($requested) $schedule = $requested;
}
$scheduleId = (int)$schedule['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $pageError = 'Session expired. Please try again.';
    } else {
        try {
            $action = trim((string)($_POST['action'] ?? ''));
            if ($action === 'save_schedule') {
                $schedule = agent_scheduling_save_schedule_v430($pdo, $user, [
                    'id' => $scheduleId,
                    'name' => (string)($_POST['name'] ?? ''),
                    'timezone' => (string)($_POST['timezone'] ?? 'UTC'),
                    'agent_id' => (int)($_POST['agent_id'] ?? 0),
                    'is_default' => !empty($_POST['is_default']),
                    'is_active' => !empty($_POST['is_active']),
                    'public_enabled' => !empty($_POST['public_enabled']),
                ]);
                scheduling_ui_redirect((int)$schedule['id'], 'Schedule settings saved.', 'settings');
            }

            if ($action === 'save_event_type') {
                $event = agent_scheduling_save_event_type_v430($pdo, $user, [
                    'id' => (int)($_POST['event_type_id'] ?? 0),
                    'schedule_id' => $scheduleId,
                    'title' => (string)($_POST['title'] ?? ''),
                    'slug' => (string)($_POST['slug'] ?? ''),
                    'description' => (string)($_POST['description'] ?? ''),
                    'duration_minutes' => (int)($_POST['duration_minutes'] ?? 30),
                    'slot_interval_minutes' => (int)($_POST['slot_interval_minutes'] ?? 30),
                    'location_type' => (string)($_POST['location_type'] ?? 'virtual'),
                    'location_value' => (string)($_POST['location_value'] ?? ''),
                    'buffer_before_minutes' => (int)($_POST['buffer_before_minutes'] ?? 0),
                    'buffer_after_minutes' => (int)($_POST['buffer_after_minutes'] ?? 0),
                    'minimum_notice_minutes' => (int)($_POST['minimum_notice_minutes'] ?? 60),
                    'booking_window_days' => (int)($_POST['booking_window_days'] ?? 60),
                    'max_bookings_per_day' => (int)($_POST['max_bookings_per_day'] ?? 0),
                    'is_active' => !empty($_POST['is_active']),
                ]);
                scheduling_ui_redirect($scheduleId, 'Appointment type saved.', 'event-' . (int)$event['id']);
            }

            if ($action === 'save_availability') {
                $windows = [];
                foreach (range(0, 6) as $weekday) {
                    if (empty($_POST['day'][$weekday]['enabled'])) continue;
                    $start = scheduling_ui_time_to_minute((string)($_POST['day'][$weekday]['start'] ?? '09:00'));
                    $endText = (string)($_POST['day'][$weekday]['end'] ?? '17:00');
                    $end = $endText === '24:00' ? 1440 : scheduling_ui_time_to_minute($endText);
                    $windows[] = ['weekday' => $weekday, 'start_minute' => $start, 'end_minute' => $end];
                }
                agent_scheduling_replace_weekly_availability_v430($pdo, $user, $scheduleId, null, $windows);
                scheduling_ui_redirect($scheduleId, 'Weekly availability saved.', 'availability');
            }

            if ($action === 'save_override') {
                $date = trim((string)($_POST['override_date'] ?? ''));
                $windows = [];
                if (!empty($_POST['override_available'])) {
                    $start = scheduling_ui_time_to_minute((string)($_POST['override_start'] ?? '09:00'));
                    $endText = (string)($_POST['override_end'] ?? '17:00');
                    $end = $endText === '24:00' ? 1440 : scheduling_ui_time_to_minute($endText);
                    $windows[] = ['start_minute' => $start, 'end_minute' => $end];
                }
                agent_scheduling_replace_date_override_v430(
                    $pdo,
                    $user,
                    $scheduleId,
                    null,
                    $date,
                    $windows,
                    (string)($_POST['override_note'] ?? '')
                );
                scheduling_ui_redirect($scheduleId, 'Date override saved.', 'availability');
            }

            if ($action === 'cancel_booking') {
                $bookingId = (int)($_POST['booking_id'] ?? 0);
                if (!agent_scheduling_cancel_booking_v430($pdo, $bookingId, (int)$user['id'])) {
                    throw new RuntimeException('That appointment could not be cancelled.');
                }
                scheduling_ui_redirect($scheduleId, 'Appointment cancelled and connected calendars were updated.', 'bookings');
            }

            if ($action === 'save_calendar_link') {
                $connectionId=(int)($_POST['connection_id']??0);
                agent_calendar_sync_link_schedule_v500($pdo,$user,$scheduleId,$connectionId,!empty($_POST['blocks_availability']),!empty($_POST['writes_bookings']));
                $connection=agent_calendar_sync_connection_v500($pdo,(int)$user['id'],$connectionId);
                if($connection)agent_calendar_sync_now_v500($pdo,$connection,true);
                scheduling_ui_redirect($scheduleId,'Calendar behavior saved.','calendars');
            }

            if ($action === 'sync_calendar') {
                $connectionId=(int)($_POST['connection_id']??0);$connection=agent_calendar_sync_connection_v500($pdo,(int)$user['id'],$connectionId);
                if(!$connection)throw new RuntimeException('Calendar connection not found.');
                $result=agent_calendar_sync_now_v500($pdo,$connection,true);
                if(empty($result['ok']))throw new RuntimeException((string)($result['error']??'Calendar synchronization failed.'));
                agent_tool_log($user,'calendar.sync','Manual calendar sync','success',['connection_id'=>$connectionId,'schedule_id'=>$scheduleId,'busy_count'=>(int)($result['busy_count']??0)]);
                scheduling_ui_redirect($scheduleId,'Calendar synchronized.','calendars');
            }

            if ($action === 'disconnect_calendar') {
                $connectionId=(int)($_POST['connection_id']??0);
                if(!agent_calendar_sync_disconnect_v500($pdo,$user,$connectionId))throw new RuntimeException('Calendar connection could not be disconnected.');
                agent_tool_log($user,'calendar.disconnect','Disconnect calendar','success',['connection_id'=>$connectionId,'schedule_id'=>$scheduleId]);
                scheduling_ui_redirect($scheduleId,'Calendar disconnected.','calendars');
            }

            throw new RuntimeException('Unknown scheduling action.');
        } catch (Throwable $e) {
            $pageError = $e->getMessage();
        }
    }
}

$schedulesStmt = $pdo->prepare('SELECT * FROM agent_scheduling_schedules WHERE owner_user_id=? ORDER BY is_default DESC,is_active DESC,name,id');
$schedulesStmt->execute([(int)$user['id']]);
$schedules = $schedulesStmt->fetchAll() ?: [];

$eventsStmt = $pdo->prepare('SELECT * FROM agent_scheduling_event_types WHERE schedule_id=? ORDER BY sort_order,title,id');
$eventsStmt->execute([$scheduleId]);
$eventTypes = $eventsStmt->fetchAll() ?: [];

$availabilityStmt = $pdo->prepare('SELECT weekday,start_minute,end_minute FROM agent_scheduling_availability WHERE schedule_id=? AND event_type_id IS NULL AND is_active=1 ORDER BY weekday,start_minute,id');
$availabilityStmt->execute([$scheduleId]);
$availabilityRows = $availabilityStmt->fetchAll() ?: [];
$weekly = [];
foreach ($availabilityRows as $row) {
    $day = (int)$row['weekday'];
    if (!isset($weekly[$day])) $weekly[$day] = $row;
}

$overrideStmt = $pdo->prepare('SELECT * FROM agent_scheduling_overrides WHERE schedule_id=? AND event_type_id IS NULL AND override_date>=CURDATE() ORDER BY override_date,start_minute,id LIMIT 30');
$overrideStmt->execute([$scheduleId]);
$overrides = $overrideStmt->fetchAll() ?: [];

$upcomingStmt = $pdo->prepare("SELECT * FROM agent_scheduling_bookings WHERE owner_user_id=? AND schedule_id=? AND status IN ('pending','confirmed') AND end_at_utc>=UTC_TIMESTAMP() ORDER BY start_at_utc,id LIMIT 50");
$upcomingStmt->execute([(int)$user['id'], $scheduleId]);
$upcoming = $upcomingStmt->fetchAll() ?: [];

$historyStmt = $pdo->prepare("SELECT * FROM agent_scheduling_bookings WHERE owner_user_id=? AND schedule_id=? AND (status NOT IN ('pending','confirmed') OR end_at_utc<UTC_TIMESTAMP()) ORDER BY start_at_utc DESC,id DESC LIMIT 30");
$historyStmt->execute([(int)$user['id'], $scheduleId]);
$history = $historyStmt->fetchAll() ?: [];

$allCalendarConnections=agent_calendar_sync_connections_v500($pdo,(int)$user['id']);
$scheduleCalendarConnections=agent_calendar_sync_connections_v500($pdo,(int)$user['id'],$scheduleId);
$calendarLinkMap=[];foreach($scheduleCalendarConnections as $connection)$calendarLinkMap[(int)$connection['id']]=$connection;
$googleCalendarReady=agent_calendar_sync_provider_ready_v500('google');
$microsoftCalendarReady=agent_calendar_sync_provider_ready_v500('microsoft');

$agents = user_agents_list_v236($pdo, (int)$user['id'], true);
$timezoneIds = DateTimeZone::listIdentifiers();
$activeEventCount = count(array_filter($eventTypes, static fn(array $event): bool => !empty($event['is_active'])));
$nextBooking = $upcoming[0] ?? null;
$savedMessage = trim((string)($_GET['saved'] ?? ''));
$weekdayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$scheduleTimezone = agent_scheduling_timezone_v430((string)$schedule['timezone']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f7f7f5">
<title><?= e(system_agent_name()) ?> | Scheduling</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/scheduling.css?v=agent-calendar-sync-v500-20260911')) ?>">
</head>
<body class="scheduling-page">
<div class="chat-app">
  <?php
    $workspaceSidebarUser = $user;
    $workspaceSidebarActive = 'scheduling';
    require __DIR__ . '/includes/workspace-sidebar-v82.php';
  ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>

  <main class="chat-main scheduling-main">
    <?php
      $memberHeaderUser = $user;
      $memberHeaderTitle = 'Scheduling';
      $memberHeaderSubtitle = 'Availability, connected calendars + Agent-managed bookings';
      $memberHeaderActions = '<a class="scheduling-header-button" href="' . e(url('/chat.php')) . '">Ask Agent</a>';
      require __DIR__ . '/includes/member-header.php';
    ?>

    <section class="scheduling-canvas">
      <div class="scheduling-inner">
        <?php if ($savedMessage !== ''): ?><div class="scheduling-notice success" role="status"><?= e($savedMessage) ?></div><?php endif; ?>
        <?php if ($pageError !== ''): ?><div class="scheduling-notice error" role="alert"><?= e($pageError) ?></div><?php endif; ?>

        <section class="scheduling-hero">
          <div>
            <span class="scheduling-eyebrow">Agent Scheduling</span>
            <h1>Your calendar, with an Agent in front of it.</h1>
            <p>Control when people can book you, which appointment types you offer, and which VP3 Agent owns the scheduling workflow.</p>
          </div>
          <nav class="scheduling-jump" aria-label="Scheduling sections">
            <a href="#event-types">Event types</a><a href="#availability">Availability</a><a href="#bookings">Bookings</a><a href="#calendars">Calendars</a><a href="#settings">Settings</a>
          </nav>
        </section>

        <section class="scheduling-metrics" aria-label="Scheduling overview">
          <article><span>Active event types</span><strong><?= $activeEventCount ?></strong><small><?= count($eventTypes) ?> total</small></article>
          <article><span>Upcoming</span><strong><?= count($upcoming) ?></strong><small>Confirmed + pending</small></article>
          <article><span>Next appointment</span><strong class="metric-date"><?= $nextBooking ? e(scheduling_ui_booking_label((string)$nextBooking['start_at_utc'], $scheduleTimezone)) : 'Open' ?></strong><small><?= $nextBooking ? e((string)$nextBooking['guest_name']) : 'No upcoming booking' ?></small></article>
          <article><span>Connected calendars</span><strong><?= count($scheduleCalendarConnections) ?></strong><small><?= !empty($schedule['public_enabled']) ? 'Public booking on' : 'Public booking off' ?></small></article>
        </section>

        <section class="scheduling-schedule-switcher" aria-label="Schedules">
          <div><strong>Schedule</strong><span><?= e($scheduleTimezone) ?></span></div>
          <div class="scheduling-schedule-pills">
            <?php foreach ($schedules as $item): ?>
              <a class="<?= (int)$item['id'] === $scheduleId ? 'active' : '' ?>" href="<?= e(url('/scheduling.php?schedule=' . (int)$item['id'])) ?>"><?= e((string)$item['name']) ?></a>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="scheduling-section" id="event-types">
          <div class="scheduling-section-head">
            <div><span class="scheduling-eyebrow">Meeting types</span><h2>Appointment types</h2><p>Each type can use its own duration, booking cadence, buffers, notice period and location.</p></div>
          </div>

          <div class="scheduling-event-grid">
            <?php foreach ($eventTypes as $event): ?>
              <details class="scheduling-event-card" id="event-<?= (int)$event['id'] ?>">
                <summary>
                  <div class="event-summary-main"><span class="event-status <?= !empty($event['is_active']) ? 'active' : '' ?>"></span><div><strong><?= e((string)$event['title']) ?></strong><small><?= (int)$event['duration_minutes'] ?> min · <?= e(ucwords(str_replace('_',' ',(string)$event['location_type']))) ?></small></div></div>
                  <span class="event-edit">Edit</span>
                </summary>
                <form method="post" class="scheduling-form event-form">
                  <?= csrf_field() ?><input type="hidden" name="action" value="save_event_type"><input type="hidden" name="schedule_id" value="<?= $scheduleId ?>"><input type="hidden" name="event_type_id" value="<?= (int)$event['id'] ?>">
                  <label class="span-2"><span>Title</span><input name="title" maxlength="190" required value="<?= e((string)$event['title']) ?>"></label>
                  <label><span>URL slug</span><input name="slug" maxlength="80" value="<?= e((string)$event['slug']) ?>"></label>
                  <label><span>Duration</span><select name="duration_minutes"><?php foreach ([15,20,30,45,60,90,120] as $minutes): ?><option value="<?= $minutes ?>" <?= (int)$event['duration_minutes']===$minutes?'selected':'' ?>><?= $minutes ?> minutes</option><?php endforeach; ?></select></label>
                  <label><span>Start times every</span><select name="slot_interval_minutes"><?php foreach ([5,10,15,20,30,45,60] as $minutes): ?><option value="<?= $minutes ?>" <?= (int)$event['slot_interval_minutes']===$minutes?'selected':'' ?>><?= $minutes ?> min</option><?php endforeach; ?></select></label>
                  <label><span>Location</span><select name="location_type"><?php foreach (['virtual'=>'Virtual','phone'=>'Phone','in_person'=>'In person','custom'=>'Custom'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= (string)$event['location_type']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                  <label class="span-2"><span>Location / meeting instructions</span><input name="location_value" maxlength="500" value="<?= e((string)$event['location_value']) ?>" placeholder="Video link, phone instructions or address"></label>
                  <label><span>Buffer before</span><input type="number" min="0" max="1440" name="buffer_before_minutes" value="<?= (int)$event['buffer_before_minutes'] ?>"></label>
                  <label><span>Buffer after</span><input type="number" min="0" max="1440" name="buffer_after_minutes" value="<?= (int)$event['buffer_after_minutes'] ?>"></label>
                  <label><span>Minimum notice</span><input type="number" min="0" max="525600" name="minimum_notice_minutes" value="<?= (int)$event['minimum_notice_minutes'] ?>"><small>Minutes</small></label>
                  <label><span>Booking horizon</span><input type="number" min="1" max="730" name="booking_window_days" value="<?= (int)$event['booking_window_days'] ?>"><small>Days ahead</small></label>
                  <label><span>Daily limit</span><input type="number" min="0" max="1000" name="max_bookings_per_day" value="<?= (int)$event['max_bookings_per_day'] ?>"><small>0 = unlimited</small></label>
                  <label class="span-2"><span>Description</span><textarea name="description" rows="3" placeholder="What should guests know before booking?"><?= e((string)$event['description']) ?></textarea></label>
                  <label class="toggle-line span-2"><input type="checkbox" name="is_active" value="1" <?= !empty($event['is_active'])?'checked':'' ?>><span>Accept bookings for this appointment type</span></label>
                  <div class="form-actions span-2"><button type="submit" class="scheduling-button primary">Save appointment type</button></div>
                </form>
              </details>
            <?php endforeach; ?>

            <details class="scheduling-event-card create-card" <?= !$eventTypes ? 'open' : '' ?>>
              <summary><div class="event-summary-main"><span class="event-plus">+</span><div><strong>New appointment type</strong><small>Create another way to book you</small></div></div><span class="event-edit">Create</span></summary>
              <form method="post" class="scheduling-form event-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_event_type"><input type="hidden" name="schedule_id" value="<?= $scheduleId ?>"><input type="hidden" name="event_type_id" value="0">
                <label class="span-2"><span>Title</span><input name="title" maxlength="190" required placeholder="30 Minute Meeting"></label>
                <label><span>URL slug</span><input name="slug" maxlength="80" placeholder="30-minute-meeting"></label>
                <label><span>Duration</span><select name="duration_minutes"><option value="15">15 minutes</option><option value="30" selected>30 minutes</option><option value="45">45 minutes</option><option value="60">60 minutes</option><option value="90">90 minutes</option></select></label>
                <label><span>Start times every</span><select name="slot_interval_minutes"><option value="15">15 min</option><option value="30" selected>30 minutes</option><option value="60">60 minutes</option></select></label>
                <label><span>Location</span><select name="location_type"><option value="virtual">Virtual</option><option value="phone">Phone</option><option value="in_person">In person</option><option value="custom">Custom</option></select></label>
                <label class="span-2"><span>Location / meeting instructions</span><input name="location_value" maxlength="500" placeholder="Video link, phone instructions or address"></label>
                <label><span>Buffer before</span><input type="number" min="0" max="1440" name="buffer_before_minutes" value="0"></label>
                <label><span>Buffer after</span><input type="number" min="0" max="1440" name="buffer_after_minutes" value="0"></label>
                <label><span>Minimum notice</span><input type="number" min="0" max="525600" name="minimum_notice_minutes" value="60"><small>Minutes</small></label>
                <label><span>Booking horizon</span><input type="number" min="1" max="730" name="booking_window_days" value="60"><small>Days ahead</small></label>
                <label><span>Daily limit</span><input type="number" min="0" max="1000" name="max_bookings_per_day" value="0"><small>0 = unlimited</small></label>
                <label class="span-2"><span>Description</span><textarea name="description" rows="3" placeholder="What should guests know before booking?"></textarea></label>
                <label class="toggle-line span-2"><input type="checkbox" name="is_active" value="1" checked><span>Accept bookings for this appointment type</span></label>
                <div class="form-actions span-2"><button type="submit" class="scheduling-button primary">Create appointment type</button></div>
              </form>
            </details>
          </div>
        </section>

        <section class="scheduling-section" id="availability">
          <div class="scheduling-section-head"><div><span class="scheduling-eyebrow">Working hours</span><h2>Availability</h2><p>These hours are the default for every appointment type in this schedule. Connected calendars are checked on top of these hours.</p></div><span class="timezone-chip"><?= e($scheduleTimezone) ?></span></div>
          <div class="scheduling-two-col">
            <form method="post" class="scheduling-panel availability-panel">
              <?= csrf_field() ?><input type="hidden" name="action" value="save_availability"><input type="hidden" name="schedule_id" value="<?= $scheduleId ?>">
              <h3>Weekly hours</h3>
              <div class="availability-list">
                <?php foreach ($weekdayNames as $weekday=>$dayName): $row=$weekly[$weekday]??null; ?>
                  <div class="availability-row">
                    <label class="day-toggle"><input type="checkbox" name="day[<?= $weekday ?>][enabled]" value="1" <?= $row?'checked':'' ?>><span><?= e(substr($dayName,0,3)) ?></span></label>
                    <input type="time" name="day[<?= $weekday ?>][start]" value="<?= e($row?scheduling_ui_minute_to_time((int)$row['start_minute']):'09:00') ?>" aria-label="<?= e($dayName) ?> start time">
                    <span class="availability-separator">to</span>
                    <input type="time" name="day[<?= $weekday ?>][end]" value="<?= e($row?scheduling_ui_minute_to_time((int)$row['end_minute']):'17:00') ?>" aria-label="<?= e($dayName) ?> end time">
                  </div>
                <?php endforeach; ?>
              </div>
              <button class="scheduling-button primary" type="submit">Save weekly hours</button>
            </form>

            <div class="scheduling-panel override-panel">
              <h3>Date overrides</h3><p>Block a day completely or open a special time window.</p>
              <form method="post" class="override-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_override"><input type="hidden" name="schedule_id" value="<?= $scheduleId ?>">
                <label><span>Date</span><input type="date" name="override_date" required min="<?= e(date('Y-m-d')) ?>"></label>
                <label class="toggle-line"><input type="checkbox" name="override_available" value="1"><span>Available on this date</span></label>
                <div class="override-times"><label><span>From</span><input type="time" name="override_start" value="09:00"></label><label><span>To</span><input type="time" name="override_end" value="17:00"></label></div>
                <label><span>Note</span><input name="override_note" maxlength="255" placeholder="Holiday, conference, extended hours…"></label>
                <button class="scheduling-button" type="submit">Save override</button>
              </form>
              <div class="override-list">
                <?php foreach ($overrides as $override): ?>
                  <div><span><strong><?= e(date('M j', strtotime((string)$override['override_date']))) ?></strong><small><?= e((string)$override['note']) ?></small></span><span class="override-state <?= !empty($override['is_available'])?'open':'blocked' ?>"><?= !empty($override['is_available']) ? e(scheduling_ui_minute_to_time((int)$override['start_minute']) . '–' . scheduling_ui_minute_to_time((int)$override['end_minute'])) : 'Blocked' ?></span></div>
                <?php endforeach; ?>
                <?php if (!$overrides): ?><div class="empty-inline">No upcoming overrides.</div><?php endif; ?>
              </div>
            </div>
          </div>
        </section>

        <section class="scheduling-section" id="bookings">
          <div class="scheduling-section-head"><div><span class="scheduling-eyebrow">Calendar</span><h2>Bookings</h2><p>Upcoming appointments created by visitors, you, or an authorized VP3 Agent will appear here and synchronize to connected write calendars.</p></div></div>
          <div class="scheduling-booking-list">
            <?php foreach ($upcoming as $booking): ?>
              <article class="booking-row">
                <div class="booking-time"><strong><?= e(scheduling_ui_booking_label((string)$booking['start_at_utc'], $scheduleTimezone)) ?></strong><small><?= (int)$booking['duration_minutes'] ?> min · <?= e($scheduleTimezone) ?></small></div>
                <div class="booking-person"><strong><?= e((string)$booking['guest_name']) ?></strong><small><?= e((string)$booking['guest_email']) ?></small></div>
                <div class="booking-type"><strong><?= e((string)$booking['event_title']) ?></strong><small><?= e(ucwords(str_replace('_',' ',(string)$booking['location_type']))) ?></small></div>
                <span class="booking-source"><?= e(ucwords(str_replace('_',' ',(string)$booking['source']))) ?></span>
                <form method="post" onsubmit="return confirm('Cancel this appointment?')"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_booking"><input type="hidden" name="schedule_id" value="<?= $scheduleId ?>"><input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>"><button class="booking-cancel" type="submit">Cancel</button></form>
              </article>
            <?php endforeach; ?>
            <?php if (!$upcoming): ?><div class="scheduling-empty"><span>○</span><strong>Your calendar is open.</strong><p>No upcoming bookings yet.</p></div><?php endif; ?>
          </div>
          <?php if ($history): ?>
            <details class="booking-history"><summary>Past + cancelled appointments <span><?= count($history) ?></span></summary><div>
              <?php foreach ($history as $booking): ?><article><span><strong><?= e((string)$booking['guest_name']) ?></strong><small><?= e((string)$booking['event_title']) ?></small></span><span><?= e(scheduling_ui_booking_label((string)$booking['start_at_utc'], $scheduleTimezone)) ?></span><span class="history-status"><?= e(ucfirst((string)$booking['status'])) ?></span></article><?php endforeach; ?>
            </div></details>
          <?php endif; ?>
        </section>

        <section class="scheduling-section" id="calendars">
          <div class="scheduling-section-head">
            <div><span class="scheduling-eyebrow">Two-way calendar sync</span><h2>Connected calendars</h2><p>Busy events block VP3 availability. New VP3 bookings can be written back to Google Calendar or Microsoft Outlook, including Meet/Teams links when supported.</p></div>
            <div class="calendar-connect-actions">
              <?php if($googleCalendarReady): ?><a class="scheduling-button calendar-connect-link" href="<?= e(url('/calendar-oauth.php?action=start&provider=google&schedule='.$scheduleId)) ?>">Connect Google</a><?php else: ?><span class="calendar-provider-disabled">Google not configured</span><?php endif; ?>
              <?php if($microsoftCalendarReady): ?><a class="scheduling-button calendar-connect-link" href="<?= e(url('/calendar-oauth.php?action=start&provider=microsoft&schedule='.$scheduleId)) ?>">Connect Outlook</a><?php else: ?><span class="calendar-provider-disabled">Outlook not configured</span><?php endif; ?>
            </div>
          </div>

          <div class="calendar-connection-grid">
            <?php foreach($allCalendarConnections as $connection): $connectionId=(int)$connection['id'];$linked=$calendarLinkMap[$connectionId]??null;$connected=(string)$connection['status']==='connected'; ?>
              <article class="calendar-connection-card <?= $connected?'connected':'needs-attention' ?>">
                <div class="calendar-connection-head">
                  <span class="calendar-provider-mark"><?= e((string)$connection['provider']==='google'?'G':'M') ?></span>
                  <div><strong><?= e(agent_calendar_sync_provider_label_v500((string)$connection['provider'])) ?></strong><small><?= e((string)($connection['account_email']?:$connection['account_name'])) ?></small></div>
                  <span class="calendar-status"><?= e(ucfirst((string)$connection['status'])) ?></span>
                </div>
                <div class="calendar-sync-meta">
                  <span>Last sync</span><strong><?= !empty($connection['last_synced_at'])?e(date('M j · g:i A',strtotime((string)$connection['last_synced_at']))):'Not yet' ?></strong>
                </div>
                <?php if(!empty($connection['last_error'])): ?><p class="calendar-error-text"><?= e((string)$connection['last_error']) ?></p><?php endif; ?>
                <form method="post" class="calendar-link-form">
                  <?= csrf_field() ?><input type="hidden" name="action" value="save_calendar_link"><input type="hidden" name="schedule_id" value="<?= $scheduleId ?>"><input type="hidden" name="connection_id" value="<?= $connectionId ?>">
                  <label class="toggle-line"><input type="checkbox" name="blocks_availability" value="1" <?= $linked===null||!empty($linked['blocks_availability'])?'checked':'' ?>><span>Block VP3 times when this calendar is busy</span></label>
                  <label class="toggle-line"><input type="checkbox" name="writes_bookings" value="1" <?= $linked===null||!empty($linked['writes_bookings'])?'checked':'' ?>><span>Add VP3 bookings to this calendar</span></label>
                  <button class="scheduling-button primary" type="submit"><?= $linked?'Save calendar behavior':'Use on this schedule' ?></button>
                </form>
                <div class="calendar-card-actions">
                  <?php if($connected): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync_calendar"><input type="hidden" name="schedule_id" value="<?= $scheduleId ?>"><input type="hidden" name="connection_id" value="<?= $connectionId ?>"><button class="scheduling-button" type="submit">Sync now</button></form><?php endif; ?>
                  <form method="post" onsubmit="return confirm('Disconnect this calendar from VP3?')"><?= csrf_field() ?><input type="hidden" name="action" value="disconnect_calendar"><input type="hidden" name="schedule_id" value="<?= $scheduleId ?>"><input type="hidden" name="connection_id" value="<?= $connectionId ?>"><button class="calendar-disconnect" type="submit">Disconnect</button></form>
                </div>
              </article>
            <?php endforeach; ?>
            <?php if(!$allCalendarConnections): ?><div class="scheduling-empty calendar-empty"><span>↔</span><strong>Connect your real calendar.</strong><p>VP3 will stop offering times that are already busy and keep Agent-created bookings synchronized.</p></div><?php endif; ?>
          </div>
          <?php if(!$googleCalendarReady||!$microsoftCalendarReady): ?><p class="calendar-config-note">Provider buttons appear after the deployment config contains an OAuth client plus <code>VP3_CALENDAR_ENCRYPTION_KEY</code>. OAuth tokens are encrypted before they are stored.</p><?php endif; ?>
        </section>

        <section class="scheduling-section" id="settings">
          <div class="scheduling-section-head"><div><span class="scheduling-eyebrow">Ownership</span><h2>Schedule settings</h2><p>Bind this schedule to a VP3 Agent and decide whether it can accept public bookings.</p></div></div>
          <form method="post" class="scheduling-panel scheduling-form settings-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_schedule"><input type="hidden" name="schedule_id" value="<?= $scheduleId ?>">
            <label><span>Schedule name</span><input name="name" maxlength="190" required value="<?= e((string)$schedule['name']) ?>"></label>
            <label><span>Timezone</span><select name="timezone"><?php foreach ($timezoneIds as $timezone): ?><option value="<?= e($timezone) ?>" <?= $timezone===$scheduleTimezone?'selected':'' ?>><?= e($timezone) ?></option><?php endforeach; ?></select></label>
            <label><span>Scheduling Agent</span><select name="agent_id"><option value="0">No dedicated Agent</option><?php foreach ($agents as $agent): ?><option value="<?= (int)$agent['id'] ?>" <?= (int)($schedule['agent_id']??0)===(int)$agent['id']?'selected':'' ?>><?= e((string)$agent['display_name']) ?> · <?= e((string)(user_agent_roles_v236()[(string)$agent['agent_role']] ?? 'Agent')) ?></option><?php endforeach; ?></select><small>A Booking Agent is recommended, but any owned active Agent may run the schedule.</small></label>
            <div class="settings-toggles">
              <label class="toggle-line"><input type="checkbox" name="public_enabled" value="1" <?= !empty($schedule['public_enabled'])?'checked':'' ?>><span>Allow public booking</span></label>
              <label class="toggle-line"><input type="checkbox" name="is_active" value="1" <?= !empty($schedule['is_active'])?'checked':'' ?>><span>Schedule active</span></label>
              <label class="toggle-line"><input type="checkbox" name="is_default" value="1" <?= !empty($schedule['is_default'])?'checked':'' ?>><span>Default schedule</span></label>
            </div>
            <div class="form-actions"><button class="scheduling-button primary" type="submit">Save schedule</button></div>
          </form>
        </section>
      </div>
    </section>
  </main>
</div>
<script src="<?= e(url('/member-shell-v77.js?v=20260911')) ?>"></script>
</body>
</html>