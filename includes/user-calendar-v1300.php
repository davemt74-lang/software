<?php
declare(strict_types=1);

/**
 * VP3 User Calendar v13.00
 *
 * User-owned calendar events live separately from canonical scheduling bookings.
 * The calendar projection joins both without copying booking lifecycle state.
 */
const VP3_USER_CALENDAR_V1300 = 'user-calendar-v1300-20260912';

function user_calendar_schema_ready_v1300(?PDO $pdo = null): bool
{
    $pdo ??= db();
    if (!$pdo || !table_exists('user_calendar_events')) return false;
    foreach (['owner_user_id','title','start_at_utc','end_at_utc','timezone','source','source_reference','status'] as $column) {
        if (!column_exists('user_calendar_events', $column)) return false;
    }
    return true;
}

function user_calendar_ensure_schema_v1300(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_calendar_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      created_by_user_id INT UNSIGNED NULL,
      created_by_agent_id BIGINT UNSIGNED NULL,
      title VARCHAR(190) NOT NULL,
      description TEXT NULL,
      location VARCHAR(500) NOT NULL DEFAULT '',
      start_at_utc DATETIME NOT NULL,
      end_at_utc DATETIME NOT NULL,
      timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
      all_day TINYINT(1) NOT NULL DEFAULT 0,
      source VARCHAR(32) NOT NULL DEFAULT 'user',
      source_reference VARCHAR(190) NOT NULL DEFAULT '',
      status VARCHAR(24) NOT NULL DEFAULT 'active',
      cancelled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_user_calendar_owner_range (owner_user_id,status,start_at_utc,end_at_utc,id),
      INDEX idx_user_calendar_source_ref (owner_user_id,source,source_reference),
      INDEX idx_user_calendar_agent (created_by_agent_id,status,start_at_utc,id),
      CONSTRAINT fk_user_calendar_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_calendar_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_user_calendar_agent FOREIGN KEY (created_by_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function user_calendar_timezone_v1300(string $timezone, string $fallback = 'UTC'): string
{
    $timezone = trim($timezone);
    if ($timezone === '') $timezone = $fallback;
    try { new DateTimeZone($timezone); return $timezone; }
    catch (Throwable $e) {
        try { new DateTimeZone($fallback); return $fallback; }
        catch (Throwable $ignored) { return 'UTC'; }
    }
}

function user_calendar_default_timezone_v1300(PDO $pdo, array $user): string
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) return 'UTC';
    if (agent_scheduling_schema_ready_v430($pdo)) {
        try {
            $stmt = $pdo->prepare('SELECT timezone FROM agent_scheduling_schedules WHERE owner_user_id=? ORDER BY is_default DESC,is_active DESC,id ASC LIMIT 1');
            $stmt->execute([$userId]);
            $timezone = trim((string)$stmt->fetchColumn());
            if ($timezone !== '') return user_calendar_timezone_v1300($timezone);
        } catch (Throwable $e) {}
    }
    return 'UTC';
}

function user_calendar_event_v1300(PDO $pdo, int $ownerUserId, int $eventId): ?array
{
    if ($ownerUserId < 1 || $eventId < 1 || !user_calendar_schema_ready_v1300($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM user_calendar_events WHERE id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$eventId, $ownerUserId]);
    return $stmt->fetch() ?: null;
}

function user_calendar_validate_utc_v1300(string $value): DateTimeImmutable
{
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) || $date->format('Y-m-d H:i:s') !== $value) {
        throw new RuntimeException('Calendar event date/time is invalid.');
    }
    return $date;
}

function user_calendar_local_range_v1300(array $input, string $fallbackTimezone = 'UTC'): array
{
    $timezone = user_calendar_timezone_v1300((string)($input['timezone'] ?? ''), $fallbackTimezone);
    $tz = new DateTimeZone($timezone);
    $date = trim((string)($input['date'] ?? $input['start_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Choose an event date.');
    $allDay = !empty($input['all_day']);

    if ($allDay) {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if (!$start || $start->format('Y-m-d') !== $date) throw new RuntimeException('Choose a valid event date.');
        $endDate = trim((string)($input['end_date'] ?? '')) ?: $date;
        $endLocal = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $tz);
        if (!$endLocal || $endLocal->format('Y-m-d') !== $endDate) throw new RuntimeException('Choose a valid end date.');
        $end = $endLocal->modify('+1 day');
        if ($end <= $start) throw new RuntimeException('Event end must be after its start.');
    } else {
        $startTime = trim((string)($input['start_time'] ?? ''));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)) throw new RuntimeException('Choose a valid start time.');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $startTime, $tz);
        if (!$start) throw new RuntimeException('Choose a valid event start.');
        $endDate = trim((string)($input['end_date'] ?? '')) ?: $date;
        $endTime = trim((string)($input['end_time'] ?? ''));
        if ($endTime === '') {
            $duration = max(5, min(10080, (int)($input['duration_minutes'] ?? 60)));
            $end = $start->modify('+' . $duration . ' minutes');
        } else {
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime)) throw new RuntimeException('Choose a valid end time.');
            $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $endDate . ' ' . $endTime, $tz);
            if (!$end) throw new RuntimeException('Choose a valid event end.');
        }
        if ($end <= $start) throw new RuntimeException('Event end must be after its start.');
    }

    return [
        'timezone' => $timezone,
        'all_day' => $allDay,
        'start_at_utc' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'end_at_utc' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
    ];
}

function user_calendar_create_event_v1300(PDO $pdo, array $user, array $input, string $source = 'user', ?int $agentId = null, string $sourceReference = ''): array
{
    if (!user_calendar_schema_ready_v1300($pdo)) throw new RuntimeException('User Calendar is not ready. An administrator needs to run the database upgrade.');
    $ownerUserId = (int)($user['id'] ?? 0);
    if ($ownerUserId < 1) throw new RuntimeException('A signed-in account is required.');
    if (!in_array($source, ['user','agent','automation'], true)) throw new RuntimeException('Unsupported calendar event source.');

    $title = trim((string)($input['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 190) throw new RuntimeException('Enter an event title up to 190 characters.');
    $description = trim((string)($input['description'] ?? ''));
    if (mb_strlen($description) > 10000) throw new RuntimeException('Event notes are too long.');
    $location = trim((string)($input['location'] ?? ''));
    if (mb_strlen($location) > 500) throw new RuntimeException('Event location is too long.');
    $timezone = user_calendar_timezone_v1300((string)($input['timezone'] ?? ''), user_calendar_default_timezone_v1300($pdo, $user));
    $start = user_calendar_validate_utc_v1300((string)($input['start_at_utc'] ?? ''));
    $end = user_calendar_validate_utc_v1300((string)($input['end_at_utc'] ?? ''));
    if ($end <= $start) throw new RuntimeException('Event end must be after its start.');
    $allDay = !empty($input['all_day']);
    $sourceReference = mb_substr(trim($sourceReference), 0, 190);

    if ($source === 'agent') {
        $agentId = max(0, (int)$agentId);
        if ($agentId > 0) {
            $stmt = $pdo->prepare('SELECT id FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1 LIMIT 1');
            $stmt->execute([$agentId, $ownerUserId]);
            if (!(int)$stmt->fetchColumn()) throw new RuntimeException('That Agent is not authorized for this calendar.');
        } else {
            // The built-in VP3 system Agent has no user_agents row but is still an Agent source.
            $agentId = null;
        }
    } else {
        $agentId = null;
    }

    if ($source === 'automation' && $sourceReference !== '') {
        $existing = $pdo->prepare("SELECT * FROM user_calendar_events WHERE owner_user_id=? AND source='automation' AND source_reference=? AND status='active' ORDER BY id DESC LIMIT 1");
        $existing->execute([$ownerUserId, $sourceReference]);
        if ($row = $existing->fetch()) return $row;
    }

    $createdByUserId = $source === 'user' ? $ownerUserId : null;
    $stmt = $pdo->prepare(
        'INSERT INTO user_calendar_events (owner_user_id,created_by_user_id,created_by_agent_id,title,description,location,start_at_utc,end_at_utc,timezone,all_day,source,source_reference,status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'active\')'
    );
    $stmt->execute([
        $ownerUserId,$createdByUserId,$agentId,$title,$description,$location,
        $start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$timezone,$allDay?1:0,$source,$sourceReference,
    ]);
    $event = user_calendar_event_v1300($pdo, $ownerUserId, (int)$pdo->lastInsertId());
    if (!$event) throw new RuntimeException('Calendar event could not be created.');
    return $event;
}

function user_calendar_create_local_event_v1300(PDO $pdo, array $user, array $input, string $source = 'user', ?int $agentId = null, string $sourceReference = ''): array
{
    $range = user_calendar_local_range_v1300($input, user_calendar_default_timezone_v1300($pdo, $user));
    return user_calendar_create_event_v1300($pdo, $user, array_merge($input, $range), $source, $agentId, $sourceReference);
}

function user_calendar_update_event_v1300(PDO $pdo, array $user, int $eventId, array $input): array
{
    $ownerUserId = (int)($user['id'] ?? 0);
    $existing = user_calendar_event_v1300($pdo, $ownerUserId, $eventId);
    if (!$existing || (string)$existing['status'] !== 'active') throw new RuntimeException('Calendar event not found.');
    $range = user_calendar_local_range_v1300($input, (string)$existing['timezone']);
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 190) throw new RuntimeException('Enter an event title up to 190 characters.');
    $description = trim((string)($input['description'] ?? ''));
    $location = trim((string)($input['location'] ?? ''));
    if (mb_strlen($description) > 10000 || mb_strlen($location) > 500) throw new RuntimeException('Event details are too long.');
    $stmt = $pdo->prepare('UPDATE user_calendar_events SET title=?,description=?,location=?,start_at_utc=?,end_at_utc=?,timezone=?,all_day=? WHERE id=? AND owner_user_id=?');
    $stmt->execute([$title,$description,$location,$range['start_at_utc'],$range['end_at_utc'],$range['timezone'],$range['all_day']?1:0,$eventId,$ownerUserId]);
    return user_calendar_event_v1300($pdo, $ownerUserId, $eventId) ?: $existing;
}

function user_calendar_cancel_event_v1300(PDO $pdo, array $user, int $eventId): bool
{
    $ownerUserId = (int)($user['id'] ?? 0);
    if ($ownerUserId < 1 || $eventId < 1) return false;
    $stmt = $pdo->prepare("UPDATE user_calendar_events SET status='cancelled',cancelled_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=? AND status='active'");
    $stmt->execute([$eventId,$ownerUserId]);
    return $stmt->rowCount() > 0;
}

function user_calendar_automation_create_event_v1300(PDO $pdo, array $ownerUser, array $input, string $sourceReference): array
{
    $sourceReference = trim($sourceReference);
    if ($sourceReference === '') throw new RuntimeException('Automated calendar events require an idempotency reference.');
    return user_calendar_create_local_event_v1300($pdo, $ownerUser, $input, 'automation', null, $sourceReference);
}

function user_calendar_events_v1300(PDO $pdo, array $user, string $fromUtc, string $toUtc): array
{
    $ownerUserId = (int)($user['id'] ?? 0);
    if ($ownerUserId < 1 || !user_calendar_schema_ready_v1300($pdo)) return [];
    $from = user_calendar_validate_utc_v1300($fromUtc);
    $to = user_calendar_validate_utc_v1300($toUtc);
    if ($to <= $from || ($to->getTimestamp() - $from->getTimestamp()) > 370 * 86400) throw new RuntimeException('Calendar range is invalid.');

    $events = [];
    $stmt = $pdo->prepare("SELECT * FROM user_calendar_events WHERE owner_user_id=? AND status='active' AND start_at_utc<? AND end_at_utc>? ORDER BY start_at_utc,id");
    $stmt->execute([$ownerUserId,$to->format('Y-m-d H:i:s'),$from->format('Y-m-d H:i:s')]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $events[] = [
            'kind'=>'event','id'=>(int)$row['id'],'title'=>(string)$row['title'],'description'=>(string)$row['description'],
            'location'=>(string)$row['location'],'start_at_utc'=>(string)$row['start_at_utc'],'end_at_utc'=>(string)$row['end_at_utc'],
            'timezone'=>(string)$row['timezone'],'all_day'=>!empty($row['all_day']),'source'=>(string)$row['source'],'status'=>(string)$row['status'],
            'created_by_agent_id'=>(int)($row['created_by_agent_id']??0),'editable'=>true,
        ];
    }

    if (agent_scheduling_schema_ready_v430($pdo)) {
        $bookings = $pdo->prepare("SELECT b.* FROM agent_scheduling_bookings b WHERE b.owner_user_id=? AND b.status IN ('pending','confirmed') AND b.start_at_utc<? AND b.end_at_utc>? ORDER BY b.start_at_utc,b.id");
        $bookings->execute([$ownerUserId,$to->format('Y-m-d H:i:s'),$from->format('Y-m-d H:i:s')]);
        foreach ($bookings->fetchAll() ?: [] as $row) {
            $guest = trim((string)($row['guest_name'] ?? ''));
            $events[] = [
                'kind'=>'booking','id'=>(int)$row['id'],'title'=>(string)$row['event_title'],'description'=>$guest!==''?'Booking with '.$guest:'Scheduled booking',
                'location'=>(string)$row['location_value'],'start_at_utc'=>(string)$row['start_at_utc'],'end_at_utc'=>(string)$row['end_at_utc'],
                'timezone'=>(string)$row['organizer_timezone'],'all_day'=>false,'source'=>'booking','booking_source'=>(string)$row['source'],'status'=>(string)$row['status'],
                'guest_name'=>$guest,'schedule_id'=>(int)$row['schedule_id'],'editable'=>false,
            ];
        }
    }

    usort($events, static function(array $a,array $b): int {
        $cmp = strcmp((string)$a['start_at_utc'], (string)$b['start_at_utc']);
        return $cmp !== 0 ? $cmp : strcmp((string)$a['kind'], (string)$b['kind']);
    });
    return $events;
}

function user_calendar_event_local_parts_v1300(array $event, string $fallbackTimezone = 'UTC'): array
{
    $timezone = user_calendar_timezone_v1300((string)($event['timezone'] ?? ''), $fallbackTimezone);
    $tz = new DateTimeZone($timezone);
    $start = new DateTimeImmutable((string)$event['start_at_utc'], new DateTimeZone('UTC'));
    $end = new DateTimeImmutable((string)$event['end_at_utc'], new DateTimeZone('UTC'));
    $start = $start->setTimezone($tz); $end = $end->setTimezone($tz);
    $allDay = !empty($event['all_day']);
    return [
        'timezone'=>$timezone,'all_day'=>$allDay,'date'=>$start->format('Y-m-d'),'start_time'=>$start->format('H:i'),
        'end_date'=>$allDay?$end->modify('-1 day')->format('Y-m-d'):$end->format('Y-m-d'),'end_time'=>$end->format('H:i'),
    ];
}
