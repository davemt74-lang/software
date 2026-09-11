<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f5f5f3">
<?php if ($managedBooking): ?><meta name="robots" content="noindex,nofollow,noarchive"><?php endif; ?>
<title><?= e($bookingPageTitle) ?> | <?= e($displayName) ?></title>
<link rel="stylesheet" href="<?= e(url('/public-booking.css?v=agent-paid-appointments-v800-20260911')) ?>">
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
    <?php if ($agentName !== ''): ?><p><strong><?= e($agentName) ?></strong> is handling availability, reminders and booking preparation for this schedule.</p><?php else: ?><p>Choose an available time that works for you.</p><?php endif; ?>
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
        $displayStatus = $lifecycleReady ? agent_appointment_lifecycle_status_v700($managedBooking) : (string)$managedBooking['status'];
        $paymentAwaiting = $managedPaid && (string)$managedPaid['payment_status']==='awaiting_payment';
        $paymentUrl = $managedPaid ? agent_paid_appointments_payment_url_v800($pdo,$managedPaid) : '';
      ?>
      <?php if ($confirmed): ?><div class="booking-notice success" role="status">Your appointment is confirmed.</div><?php endif; ?>
      <?php if ($rescheduled): ?><div class="booking-notice success" role="status">Your appointment has been rescheduled. Your private management link stays the same and any payment stays attached to this booking.</div><?php endif; ?>
      <?php if ($cancelled): ?><div class="booking-notice neutral" role="status">Your appointment was cancelled. Any refundable amount is now visible to the appointment owner for approval under the stated cancellation policy.</div><?php endif; ?>
      <?php if ($paymentAwaiting): ?><div class="booking-notice neutral" role="status"><strong>Payment required.</strong> This time is being held, but the appointment is not confirmed until payment succeeds.<?php if($paymentUrl!==''):?> <a href="<?=e($paymentUrl)?>">Continue payment →</a><?php endif;?></div><?php endif; ?>

      <div class="booking-section-head"><span class="booking-kicker">Your appointment</span><h2><?= e((string)$managedBooking['event_title']) ?></h2></div>
      <div class="booking-confirmation-grid">
        <div><span>Date + time</span><strong><?= e($bookingWhen !== '' ? $bookingWhen : (string)$managedBooking['start_at_utc'] . ' UTC') ?></strong></div>
        <div><span>Duration</span><strong><?= (int)$managedBooking['duration_minutes'] ?> minutes</strong></div>
        <div><span>With</span><strong><?= e($displayName) ?></strong></div>
        <div><span>Status</span><strong><?= e(ucwords(str_replace('_',' ',$displayStatus))) ?></strong></div>
        <?php if($managedPaid):?><div><span>Payment</span><strong><?=e(ucwords(str_replace('_',' ',(string)$managedPaid['payment_status'])))?></strong></div><div><span>Paid</span><strong><?=e(agent_paid_appointments_money_v800((int)$managedPaid['amount_paid_cents'],(string)$managedPaid['currency']))?></strong></div><?php endif;?>
      </div>

      <?php if ($activeBooking && !$paymentAwaiting && trim((string)$managedBooking['location_value']) !== ''): ?>
        <div class="booking-location-card"><span><?= e(public_booking_location_label_v450($managedBooking)) ?></span>
          <?php $locationValue=trim((string)$managedBooking['location_value']); ?>
          <?php if (filter_var($locationValue,FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($locationValue,PHP_URL_SCHEME)),['http','https'],true)): ?>
            <a href="<?= e($locationValue) ?>" target="_blank" rel="noopener noreferrer nofollow">Open meeting location ↗</a>
          <?php else: ?><strong><?= e($locationValue) ?></strong><?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="booking-manage-actions">
        <?php if ($activeBooking && !$paymentAwaiting && $calendarUrl !== ''): ?><a class="booking-button secondary" href="<?= e($calendarUrl) ?>">Add to calendar</a><?php endif; ?>
        <?php if ($paymentAwaiting && $paymentUrl!==''): ?><a class="booking-button primary" href="<?=e($paymentUrl)?>">Complete payment</a><?php endif;?>
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
            <p class="booking-privacy-copy">Rescheduling moves this same booking, retains its payment record, and updates the connected calendar event in place.</p>
            <button class="booking-button primary" type="submit" data-slot-submit disabled>Reschedule</button>
          </form>
        </details>
      <?php endif; ?>

      <?php if ($activeBooking): ?>
        <details class="booking-manage-panel danger-panel">
          <summary>Cancel appointment</summary>
          <form method="post" class="booking-cancel-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="text" name="website" class="booking-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
            <p>This releases the time back to <?= e($displayName) ?>’s schedule and updates connected calendars. Refund eligibility follows the cancellation terms shown when you booked; money is returned only after the account owner approves the refund.</p>
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
        <?php foreach ($events as $item): $itemTerms=$paidReady?agent_paid_appointments_event_terms_v800($pdo,(int)$item['id']):null; ?>
          <a href="<?= e(agent_scheduling_public_booking_url_v450($username,(string)$item['slug'])) ?>">
            <div><strong><?= e((string)$item['title']) ?></strong><?php if (trim((string)($item['description']??'')) !== ''): ?><p><?= e((string)$item['description']) ?></p><?php endif; ?></div>
            <span><?= (int)$item['duration_minutes'] ?> min<?php if($itemTerms):?> · <?=e(agent_paid_appointments_display_terms_v800($itemTerms))?><?php else:?> · <?= e(public_booking_location_label_v450($item)) ?><?php endif;?> →</span>
          </a>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <div class="booking-section-head"><a class="booking-back" href="<?= e(agent_scheduling_public_booking_url_v450($username)) ?>">← Appointment types</a><span class="booking-kicker">Book a time</span><h2><?= e((string)$event['title']) ?></h2><p><?= e(trim((string)($event['description']??'')) !== '' ? (string)$event['description'] : public_booking_location_label_v450($event)) ?></p></div>
      <div class="booking-event-meta"><span><?= (int)$event['duration_minutes'] ?> minutes</span><span><?= e(public_booking_location_label_v450($event)) ?></span><span>Times shown in your timezone</span><?php if($eventPaymentTerms):?><span><?=e(agent_paid_appointments_display_terms_v800($eventPaymentTerms))?></span><?php endif;?></div>
      <?php if($eventPaymentTerms && (string)$eventPaymentTerms['payment_mode']!=='free'):?><div class="booking-notice neutral"><strong><?=e(agent_paid_appointments_display_terms_v800($eventPaymentTerms))?></strong>. Your selected time is held while you complete secure checkout. The appointment is confirmed only after the provider verifies payment.<?php if(trim((string)$eventPaymentTerms['cancellation_policy'])!==''):?><br><?=nl2br(e((string)$eventPaymentTerms['cancellation_policy']))?><?php endif;?></div><?php endif;?>

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

          <?php if ($intakeQuestions): ?>
            <div class="span-2"><span class="booking-field-label">A few questions before you book</span></div>
            <?php foreach($intakeQuestions as $q):
                $qid=(int)$q['id'];$name='intake['.$qid.']';$posted=$_POST['intake'][$qid]??'';
                $required=!empty($q['is_required']);$type=(string)$q['question_type'];
            ?>
              <?php if($type==='long_text'): ?>
                <label class="span-2"><span><?= e((string)$q['label']) ?><?= $required?' *':'' ?></span><textarea name="<?= e($name) ?>" rows="3" <?= $required?'required':'' ?>><?= e((string)$posted) ?></textarea></label>
              <?php elseif($type==='select'): $opts=json_decode((string)($q['options_json']??'[]'),true);if(!is_array($opts))$opts=[]; ?>
                <label><span><?= e((string)$q['label']) ?><?= $required?' *':'' ?></span><select name="<?= e($name) ?>" <?= $required?'required':'' ?>><option value="">Choose…</option><?php foreach($opts as $opt): ?><option value="<?= e((string)$opt) ?>" <?= (string)$posted===(string)$opt?'selected':'' ?>><?= e((string)$opt) ?></option><?php endforeach; ?></select></label>
              <?php elseif($type==='checkbox'): ?>
                <label class="span-2"><span><input type="checkbox" name="<?= e($name) ?>" value="1" <?= !empty($posted)?'checked':'' ?> <?= $required?'required':'' ?>> <?= e((string)$q['label']) ?><?= $required?' *':'' ?></span></label>
              <?php else: ?>
                <label><span><?= e((string)$q['label']) ?><?= $required?' *':'' ?></span><input name="<?= e($name) ?>" maxlength="1000" value="<?= e((string)$posted) ?>" <?= $required?'required':'' ?>></label>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <button class="booking-button primary book-submit" type="submit" data-slot-submit disabled><?=($eventPaymentTerms && (string)$eventPaymentTerms['payment_mode']!=='free')?'Continue to payment':'Confirm appointment'?></button>
        <p class="booking-privacy-copy">Your details and intake answers are used to manage and prepare for this appointment with <?= e($displayName) ?>. VP3 does not store card numbers.</p>
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
