<?php
declare(strict_types=1);

/**
 * VP3 Agent Scheduling v4.30
 *
 * Native Calendly-class scheduling foundation for VP3 Agents. Schedules belong
 * to users, may bind to canonical user_agents, expose event types, recurring
 * and date-specific availability, and create conflict-safe UTC bookings while
 * preserving organizer/guest timezone context.
 */

const VP3_AGENT_SCHEDULING_V430 = 'agent-scheduling-v430-20260911';

function agent_scheduling_schema_ready_v430(?PDO $pdo = null): bool
{
    $pdo ??= db();
    if (!$pdo) return false;

    foreach ([
        'agent_scheduling_schedules',
        'agent_scheduling_event_types',
        'agent_scheduling_availability',
        'agent_scheduling_overrides',
        'agent_scheduling_bookings',
    ] as $table) {
        if (!table_exists($table)) return false;
    }

    return column_exists('agent_scheduling_schedules', 'agent_id')
        && column_exists('agent_scheduling_event_types', 'minimum_notice_minutes')
        && column_exists('agent_scheduling_bookings', 'start_at_utc')
        && column_exists('agent_scheduling_bookings', 'public_token')
        && column_exists('agent_scheduling_bookings', 'buffer_before_minutes')
        && column_exists('agent_scheduling_bookings', 'buffer_after_minutes');
}

function agent_scheduling_ensure_schema_v430(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_scheduling_schedules (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_id BIGINT UNSIGNED NULL,
      name VARCHAR(190) NOT NULL DEFAULT 'My schedule',
      slug VARCHAR(80) NOT NULL,
      timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
      is_default TINYINT(1) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      public_enabled TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_scheduling_schedule_slug (owner_user_id,slug),
      INDEX idx_agent_scheduling_schedule_owner (owner_user_id,is_active,is_default,id),
      INDEX idx_agent_scheduling_schedule_agent (agent_id,is_active,id),
      CONSTRAINT fk_agent_scheduling_schedule_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_scheduling_schedule_agent FOREIGN KEY (agent_id) REFERENCES user_agents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_scheduling_event_types (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      schedule_id BIGINT UNSIGNED NOT NULL,
      slug VARCHAR(80) NOT NULL,
      title VARCHAR(190) NOT NULL,
      description TEXT NULL,
      duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
      slot_interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
      location_type VARCHAR(30) NOT NULL DEFAULT 'virtual',
      location_value VARCHAR(500) NOT NULL DEFAULT '',
      buffer_before_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      buffer_after_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      minimum_notice_minutes INT UNSIGNED NOT NULL DEFAULT 60,
      booking_window_days SMALLINT UNSIGNED NOT NULL DEFAULT 60,
      max_bookings_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_scheduling_event_slug (schedule_id,slug),
      INDEX idx_agent_scheduling_event_active (schedule_id,is_active,sort_order,id),
      CONSTRAINT fk_agent_scheduling_event_schedule FOREIGN KEY (schedule_id) REFERENCES agent_scheduling_schedules(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_scheduling_availability (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      schedule_id BIGINT UNSIGNED NOT NULL,
      event_type_id BIGINT UNSIGNED NULL,
      weekday TINYINT UNSIGNED NOT NULL,
      start_minute SMALLINT UNSIGNED NOT NULL,
      end_minute SMALLINT UNSIGNED NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_agent_scheduling_avail_lookup (schedule_id,event_type_id,weekday,is_active,start_minute),
      CONSTRAINT fk_agent_scheduling_avail_schedule FOREIGN KEY (schedule_id) REFERENCES agent_scheduling_schedules(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_scheduling_avail_event FOREIGN KEY (event_type_id) REFERENCES agent_scheduling_event_types(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_scheduling_overrides (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      schedule_id BIGINT UNSIGNED NOT NULL,
      event_type_id BIGINT UNSIGNED NULL,
      override_date DATE NOT NULL,
      is_available TINYINT(1) NOT NULL DEFAULT 0,
      start_minute SMALLINT UNSIGNED NULL,
      end_minute SMALLINT UNSIGNED NULL,
      note VARCHAR(255) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_agent_scheduling_override_lookup (schedule_id,event_type_id,override_date,is_available,start_minute),
      CONSTRAINT fk_agent_scheduling_override_schedule FOREIGN KEY (schedule_id) REFERENCES agent_scheduling_schedules(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_scheduling_override_event FOREIGN KEY (event_type_id) REFERENCES agent_scheduling_event_types(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_scheduling_bookings (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      schedule_id BIGINT UNSIGNED NOT NULL,
      event_type_id BIGINT UNSIGNED NULL,
      agent_id BIGINT UNSIGNED NULL,
      created_by_user_id INT UNSIGNED NULL,
      created_by_agent_id BIGINT UNSIGNED NULL,
      rescheduled_from_id BIGINT UNSIGNED NULL,
      event_title VARCHAR(190) NOT NULL,
      duration_minutes SMALLINT UNSIGNED NOT NULL,
      buffer_before_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      buffer_after_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      start_at_utc DATETIME NOT NULL,
      end_at_utc DATETIME NOT NULL,
      organizer_timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
      guest_timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
      guest_name VARCHAR(190) NOT NULL,
      guest_email VARCHAR(190) NOT NULL DEFAULT '',
      guest_phone VARCHAR(80) NOT NULL DEFAULT '',
      status VARCHAR(24) NOT NULL DEFAULT 'confirmed',
      location_type VARCHAR(30) NOT NULL DEFAULT 'virtual',
      location_value VARCHAR(500) NOT NULL DEFAULT '',
      guest_notes TEXT NULL,
      internal_notes TEXT NULL,
      source VARCHAR(40) NOT NULL DEFAULT 'public',
      public_token CHAR(64) NOT NULL,
      cancel_token CHAR(64) NOT NULL,
      cancelled_at DATETIME NULL,
      completed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_scheduling_public_token (public_token),
      UNIQUE KEY uq_agent_scheduling_cancel_token (cancel_token),
      INDEX idx_agent_scheduling_booking_owner (owner_user_id,start_at_utc,status,id),
      INDEX idx_agent_scheduling_booking_schedule (schedule_id,status,start_at_utc,end_at_utc,id),
      INDEX idx_agent_scheduling_booking_agent (agent_id,status,start_at_utc,id),
      INDEX idx_agent_scheduling_booking_guest (guest_email,start_at_utc,id),
      CONSTRAINT fk_agent_scheduling_booking_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_scheduling_booking_schedule FOREIGN KEY (schedule_id) REFERENCES agent_scheduling_schedules(id) ON DELETE RESTRICT,
      CONSTRAINT fk_agent_scheduling_booking_event FOREIGN KEY (event_type_id) REFERENCES agent_scheduling_event_types(id) ON DELETE SET NULL,
      CONSTRAINT fk_agent_scheduling_booking_agent FOREIGN KEY (agent_id) REFERENCES user_agents(id) ON DELETE SET NULL,
      CONSTRAINT fk_agent_scheduling_booking_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_agent_scheduling_booking_creator_agent FOREIGN KEY (created_by_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL,
      CONSTRAINT fk_agent_scheduling_booking_rescheduled FOREIGN KEY (rescheduled_from_id) REFERENCES agent_scheduling_bookings(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (table_exists('agent_scheduling_bookings') && !column_exists('agent_scheduling_bookings', 'buffer_before_minutes')) {
        $pdo->exec('ALTER TABLE agent_scheduling_bookings ADD COLUMN buffer_before_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_minutes');
    }
    if (table_exists('agent_scheduling_bookings') && !column_exists('agent_scheduling_bookings', 'buffer_after_minutes')) {
        $pdo->exec('ALTER TABLE agent_scheduling_bookings ADD COLUMN buffer_after_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER buffer_before_minutes');
    }
}

function agent_scheduling_slug_v430(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
    return substr(trim($value, '-'), 0, 80);
}

function agent_scheduling_timezone_v430(string $value, string $fallback = 'UTC'): string
{
    $value = trim($value);
    if ($value === '') $value = $fallback;
    try {
        new DateTimeZone($value);
        return $value;
    } catch (Throwable $e) {
        try {
            new DateTimeZone($fallback);
            return $fallback;
        } catch (Throwable $ignored) {
            return 'UTC';
        }
    }
}

function agent_scheduling_schedule_v430(PDO $pdo, int $ownerUserId, int $scheduleId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM agent_scheduling_schedules WHERE id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$scheduleId, $ownerUserId]);
    return $stmt->fetch() ?: null;
}

function agent_scheduling_event_type_v430(PDO $pdo, int $eventTypeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT e.*,s.owner_user_id,s.agent_id,s.timezone AS schedule_timezone,s.public_enabled,s.is_active AS schedule_active
         FROM agent_scheduling_event_types e
         JOIN agent_scheduling_schedules s ON s.id=e.schedule_id
         WHERE e.id=? LIMIT 1'
    );
    $stmt->execute([$eventTypeId]);
    return $stmt->fetch() ?: null;
}

function agent_scheduling_default_schedule_v430(PDO $pdo, array $user, ?int $agentId = null): array
{
    $ownerUserId = (int)($user['id'] ?? 0);
    if ($ownerUserId < 1) throw new RuntimeException('A signed-in account is required.');

    $sql = 'SELECT * FROM agent_scheduling_schedules WHERE owner_user_id=?';
    $args = [$ownerUserId];
    if ($agentId !== null && $agentId > 0) {
        $sql .= ' AND agent_id=?';
        $args[] = $agentId;
    }
    $sql .= ' ORDER BY is_default DESC,id ASC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $existing = $stmt->fetch();
    if ($existing) return $existing;

    if ($agentId !== null && $agentId > 0) {
        $agent = user_agent_get_v236($pdo, $ownerUserId, $agentId);
        if (!$agent) throw new RuntimeException('Agent not found.');
        $name = trim((string)($agent['display_name'] ?? '')) ?: 'Booking Agent';
    } else {
        $name = 'My schedule';
        $agentId = null;
    }

    $base = agent_scheduling_slug_v430($name) ?: 'schedule';
    $slug = $base;
    $counter = 1;
    while (true) {
        $check = $pdo->prepare('SELECT 1 FROM agent_scheduling_schedules WHERE owner_user_id=? AND slug=? LIMIT 1');
        $check->execute([$ownerUserId, $slug]);
        if (!$check->fetchColumn()) break;
        $counter++;
        $slug = substr($base, 0, 72) . '-' . $counter;
    }

    $timezone = agent_scheduling_timezone_v430((string)($user['timezone'] ?? date_default_timezone_get()), 'UTC');
    $hasStmt = $pdo->prepare('SELECT COUNT(*) FROM agent_scheduling_schedules WHERE owner_user_id=?');
    $hasStmt->execute([$ownerUserId]);
    $isDefault = (int)$hasStmt->fetchColumn() === 0 ? 1 : 0;

    $insert = $pdo->prepare(
        'INSERT INTO agent_scheduling_schedules (owner_user_id,agent_id,name,slug,timezone,is_default,is_active,public_enabled)
         VALUES (?,?,?,?,?,?,1,1)'
    );
    $insert->execute([$ownerUserId, $agentId, mb_strimwidth($name, 0, 190, ''), $slug, $timezone, $isDefault]);

    $created = agent_scheduling_schedule_v430($pdo, $ownerUserId, (int)$pdo->lastInsertId());
    if (!$created) throw new RuntimeException('Schedule could not be created.');
    return $created;
}

function agent_scheduling_save_schedule_v430(PDO $pdo, array $user, array $input): array
{
    $ownerUserId = (int)($user['id'] ?? 0);
    $scheduleId = (int)($input['id'] ?? 0);
    $schedule = agent_scheduling_schedule_v430($pdo, $ownerUserId, $scheduleId);
    if (!$schedule) throw new RuntimeException('Schedule not found.');

    $name = trim(preg_replace('/\s+/u', ' ', (string)($input['name'] ?? $schedule['name'])) ?? '');
    if ($name === '') throw new RuntimeException('Enter a schedule name.');
    $timezone = agent_scheduling_timezone_v430((string)($input['timezone'] ?? $schedule['timezone']), (string)$schedule['timezone']);
    $agentId = array_key_exists('agent_id', $input) ? (int)$input['agent_id'] : (int)($schedule['agent_id'] ?? 0);
    if ($agentId > 0 && !user_agent_get_v236($pdo, $ownerUserId, $agentId)) {
        throw new RuntimeException('Choose one of your Agents.');
    }
    if ($agentId < 1) $agentId = null;

    $isDefault = array_key_exists('is_default', $input) ? !empty($input['is_default']) : !empty($schedule['is_default']);
    if ($isDefault) $pdo->prepare('UPDATE agent_scheduling_schedules SET is_default=0 WHERE owner_user_id=? AND id<>?')->execute([$ownerUserId, $scheduleId]);

    $stmt = $pdo->prepare('UPDATE agent_scheduling_schedules SET name=?,timezone=?,agent_id=?,is_default=?,is_active=?,public_enabled=? WHERE id=? AND owner_user_id=?');
    $stmt->execute([
        mb_strimwidth($name, 0, 190, ''),
        $timezone,
        $agentId,
        $isDefault ? 1 : 0,
        array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : (int)$schedule['is_active'],
        array_key_exists('public_enabled', $input) ? (!empty($input['public_enabled']) ? 1 : 0) : (int)$schedule['public_enabled'],
        $scheduleId,
        $ownerUserId,
    ]);
    return agent_scheduling_schedule_v430($pdo, $ownerUserId, $scheduleId) ?: throw new RuntimeException('Schedule could not be saved.');
}

function agent_scheduling_save_event_type_v430(PDO $pdo, array $user, array $input): array
{
    $ownerUserId = (int)($user['id'] ?? 0);
    $scheduleId = (int)($input['schedule_id'] ?? 0);
    if (!agent_scheduling_schedule_v430($pdo, $ownerUserId, $scheduleId)) throw new RuntimeException('Schedule not found.');

    $eventId = (int)($input['id'] ?? 0);
    $existing = null;
    if ($eventId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM agent_scheduling_event_types WHERE id=? AND schedule_id=? LIMIT 1');
        $stmt->execute([$eventId, $scheduleId]);
        $existing = $stmt->fetch() ?: null;
        if (!$existing) throw new RuntimeException('Appointment type not found.');
    }

    $title = trim(preg_replace('/\s+/u', ' ', (string)($input['title'] ?? ($existing['title'] ?? ''))) ?? '');
    if ($title === '') throw new RuntimeException('Enter an appointment title.');
    $slug = agent_scheduling_slug_v430((string)($input['slug'] ?? ($existing['slug'] ?? $title))) ?: 'meeting';
    $duration = max(5, min(1440, (int)($input['duration_minutes'] ?? ($existing['duration_minutes'] ?? 30))));
    $slotInterval = max(5, min(1440, (int)($input['slot_interval_minutes'] ?? ($existing['slot_interval_minutes'] ?? $duration))));
    $before = max(0, min(1440, (int)($input['buffer_before_minutes'] ?? ($existing['buffer_before_minutes'] ?? 0))));
    $after = max(0, min(1440, (int)($input['buffer_after_minutes'] ?? ($existing['buffer_after_minutes'] ?? 0))));
    $notice = max(0, min(525600, (int)($input['minimum_notice_minutes'] ?? ($existing['minimum_notice_minutes'] ?? 60))));
    $window = max(1, min(730, (int)($input['booking_window_days'] ?? ($existing['booking_window_days'] ?? 60))));
    $maxPerDay = max(0, min(1000, (int)($input['max_bookings_per_day'] ?? ($existing['max_bookings_per_day'] ?? 0))));
    $locationType = trim((string)($input['location_type'] ?? ($existing['location_type'] ?? 'virtual'))) ?: 'virtual';
    if (!in_array($locationType, ['virtual','phone','in_person','custom'], true)) $locationType = 'custom';

    $collision = $pdo->prepare('SELECT id FROM agent_scheduling_event_types WHERE schedule_id=? AND slug=? AND id<>? LIMIT 1');
    $collision->execute([$scheduleId, $slug, $eventId]);
    if ($collision->fetchColumn()) throw new RuntimeException('That appointment URL is already in use.');

    $values = [
        $slug,
        mb_strimwidth($title, 0, 190, ''),
        trim((string)($input['description'] ?? ($existing['description'] ?? ''))) ?: null,
        $duration,
        $slotInterval,
        $locationType,
        mb_strimwidth(trim((string)($input['location_value'] ?? ($existing['location_value'] ?? ''))), 0, 500, ''),
        $before,
        $after,
        $notice,
        $window,
        $maxPerDay,
        array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : (int)($existing['is_active'] ?? 1),
        (int)($input['sort_order'] ?? ($existing['sort_order'] ?? 0)),
    ];

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE agent_scheduling_event_types SET slug=?,title=?,description=?,duration_minutes=?,slot_interval_minutes=?,location_type=?,location_value=?,buffer_before_minutes=?,buffer_after_minutes=?,minimum_notice_minutes=?,booking_window_days=?,max_bookings_per_day=?,is_active=?,sort_order=? WHERE id=? AND schedule_id=?');
        $stmt->execute([...$values, $eventId, $scheduleId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO agent_scheduling_event_types (schedule_id,slug,title,description,duration_minutes,slot_interval_minutes,location_type,location_value,buffer_before_minutes,buffer_after_minutes,minimum_notice_minutes,booking_window_days,max_bookings_per_day,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$scheduleId, ...$values]);
        $eventId = (int)$pdo->lastInsertId();
    }

    return agent_scheduling_event_type_v430($pdo, $eventId) ?: throw new RuntimeException('Appointment type could not be saved.');
}

function agent_scheduling_validate_window_v430(int $weekday, int $startMinute, int $endMinute): array
{
    if ($weekday < 0 || $weekday > 6) throw new RuntimeException('Availability weekday must be between 0 and 6.');
    if ($startMinute < 0 || $startMinute > 1439 || $endMinute < 1 || $endMinute > 1440 || $endMinute <= $startMinute) {
        throw new RuntimeException('Availability requires a valid start and end time.');
    }
    return [$weekday, $startMinute, $endMinute];
}

function agent_scheduling_replace_weekly_availability_v430(PDO $pdo, array $user, int $scheduleId, ?int $eventTypeId, array $windows): void
{
    $ownerUserId = (int)($user['id'] ?? 0);
    if (!agent_scheduling_schedule_v430($pdo, $ownerUserId, $scheduleId)) throw new RuntimeException('Schedule not found.');
    if ($eventTypeId !== null && $eventTypeId > 0) {
        $event = agent_scheduling_event_type_v430($pdo, $eventTypeId);
        if (!$event || (int)$event['schedule_id'] !== $scheduleId) throw new RuntimeException('Appointment type does not belong to this schedule.');
    } else {
        $eventTypeId = null;
    }

    $normalized = [];
    foreach ($windows as $window) {
        if (!is_array($window)) continue;
        $normalized[] = agent_scheduling_validate_window_v430((int)($window['weekday'] ?? -1), (int)($window['start_minute'] ?? -1), (int)($window['end_minute'] ?? -1));
    }

    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
        if ($eventTypeId === null) {
            $pdo->prepare('DELETE FROM agent_scheduling_availability WHERE schedule_id=? AND event_type_id IS NULL')->execute([$scheduleId]);
        } else {
            $pdo->prepare('DELETE FROM agent_scheduling_availability WHERE schedule_id=? AND event_type_id=?')->execute([$scheduleId, $eventTypeId]);
        }
        $insert = $pdo->prepare('INSERT INTO agent_scheduling_availability (schedule_id,event_type_id,weekday,start_minute,end_minute,is_active) VALUES (?,?,?,?,?,1)');
        foreach ($normalized as [$weekday, $start, $end]) $insert->execute([$scheduleId, $eventTypeId, $weekday, $start, $end]);
        if ($started) $pdo->commit();
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function agent_scheduling_replace_date_override_v430(PDO $pdo, array $user, int $scheduleId, ?int $eventTypeId, string $date, array $windows, string $note = ''): void
{
    $ownerUserId = (int)($user['id'] ?? 0);
    $schedule = agent_scheduling_schedule_v430($pdo, $ownerUserId, $scheduleId);
    if (!$schedule) throw new RuntimeException('Schedule not found.');
    if ($eventTypeId !== null && $eventTypeId > 0) {
        $event = agent_scheduling_event_type_v430($pdo, $eventTypeId);
        if (!$event || (int)$event['schedule_id'] !== $scheduleId) throw new RuntimeException('Appointment type does not belong to this schedule.');
    } else {
        $eventTypeId = null;
    }

    $tz = new DateTimeZone(agent_scheduling_timezone_v430((string)$schedule['timezone']));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $parsed->format('Y-m-d') !== $date) {
        throw new RuntimeException('Enter a valid override date.');
    }

    $normalized = [];
    foreach ($windows as $window) {
        if (!is_array($window)) continue;
        [, $start, $end] = agent_scheduling_validate_window_v430(0, (int)($window['start_minute'] ?? -1), (int)($window['end_minute'] ?? -1));
        $normalized[] = [$start, $end];
    }

    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
        if ($eventTypeId === null) {
            $pdo->prepare('DELETE FROM agent_scheduling_overrides WHERE schedule_id=? AND event_type_id IS NULL AND override_date=?')->execute([$scheduleId, $date]);
        } else {
            $pdo->prepare('DELETE FROM agent_scheduling_overrides WHERE schedule_id=? AND event_type_id=? AND override_date=?')->execute([$scheduleId, $eventTypeId, $date]);
        }
        $insert = $pdo->prepare('INSERT INTO agent_scheduling_overrides (schedule_id,event_type_id,override_date,is_available,start_minute,end_minute,note) VALUES (?,?,?,?,?,?,?)');
        if (!$normalized) {
            $insert->execute([$scheduleId, $eventTypeId, $date, 0, null, null, mb_strimwidth(trim($note), 0, 255, '')]);
        } else {
            foreach ($normalized as [$start, $end]) $insert->execute([$scheduleId, $eventTypeId, $date, 1, $start, $end, mb_strimwidth(trim($note), 0, 255, '')]);
        }
        if ($started) $pdo->commit();
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function agent_scheduling_windows_for_date_v430(PDO $pdo, array $event, string $date): array
{
    $scheduleId = (int)$event['schedule_id'];
    $eventTypeId = (int)$event['id'];
    $timezone = agent_scheduling_timezone_v430((string)($event['schedule_timezone'] ?? 'UTC'));
    $tz = new DateTimeZone($timezone);
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$day || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $day->format('Y-m-d') !== $date) return [];

    $override = $pdo->prepare('SELECT event_type_id,is_available,start_minute,end_minute FROM agent_scheduling_overrides WHERE schedule_id=? AND override_date=? AND (event_type_id=? OR event_type_id IS NULL) ORDER BY event_type_id IS NOT NULL DESC,start_minute,id');
    $override->execute([$scheduleId, $date, $eventTypeId]);
    $overrideRows = $override->fetchAll() ?: [];
    if ($overrideRows) {
        $specific = array_values(array_filter($overrideRows, static fn(array $row): bool => (int)($row['event_type_id'] ?? 0) === $eventTypeId));
        $chosen = $specific ?: array_values(array_filter($overrideRows, static fn(array $row): bool => empty($row['event_type_id'])));
        $windows = [];
        foreach ($chosen as $row) {
            if (empty($row['is_available']) || $row['start_minute'] === null || $row['end_minute'] === null) continue;
            $start = (int)$row['start_minute'];
            $end = (int)$row['end_minute'];
            if ($start >= 0 && $end <= 1440 && $end > $start) $windows[] = [$start, $end];
        }
        return $windows;
    }

    $weekday = (int)$day->format('w');
    $weekly = $pdo->prepare('SELECT event_type_id,start_minute,end_minute FROM agent_scheduling_availability WHERE schedule_id=? AND weekday=? AND is_active=1 AND (event_type_id=? OR event_type_id IS NULL) ORDER BY event_type_id IS NOT NULL DESC,start_minute,id');
    $weekly->execute([$scheduleId, $weekday, $eventTypeId]);
    $rows = $weekly->fetchAll() ?: [];
    $specific = array_values(array_filter($rows, static fn(array $row): bool => (int)($row['event_type_id'] ?? 0) === $eventTypeId));
    $chosen = $specific ?: array_values(array_filter($rows, static fn(array $row): bool => empty($row['event_type_id'])));
    $windows = [];
    foreach ($chosen as $row) {
        $start = (int)$row['start_minute'];
        $end = (int)$row['end_minute'];
        if ($start >= 0 && $end <= 1440 && $end > $start) $windows[] = [$start, $end];
    }
    return $windows;
}

function agent_scheduling_conflict_v430(PDO $pdo, int $scheduleId, string $startUtc, string $endUtc, int $bufferBefore = 0, int $bufferAfter = 0, int $excludeBookingId = 0): ?array
{
    $candidateStart = (new DateTimeImmutable($startUtc, new DateTimeZone('UTC')))->modify('-' . max(0, $bufferBefore) . ' minutes')->format('Y-m-d H:i:s');
    $candidateEnd = (new DateTimeImmutable($endUtc, new DateTimeZone('UTC')))->modify('+' . max(0, $bufferAfter) . ' minutes')->format('Y-m-d H:i:s');
    $sql = "SELECT id,start_at_utc,end_at_utc,status
            FROM agent_scheduling_bookings
            WHERE schedule_id=?
              AND status IN ('pending','confirmed')
              AND DATE_SUB(start_at_utc, INTERVAL buffer_before_minutes MINUTE) < ?
              AND DATE_ADD(end_at_utc, INTERVAL buffer_after_minutes MINUTE) > ?";
    $args = [$scheduleId, $candidateEnd, $candidateStart];
    if ($excludeBookingId > 0) {
        $sql .= ' AND id<>?';
        $args[] = $excludeBookingId;
    }
    $sql .= ' ORDER BY start_at_utc,id LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $native=$stmt->fetch();
    if($native)return $native;
    if(function_exists('agent_calendar_sync_conflict_v500')){
        $external=agent_calendar_sync_conflict_v500($pdo,$scheduleId,$candidateStart,$candidateEnd);
        if($external){$external['external_calendar']=true;return $external;}
    }
    return null;
}

function agent_scheduling_day_booking_count_v430(PDO $pdo, array $event, DateTimeImmutable $localStart): int
{
    $tz = new DateTimeZone(agent_scheduling_timezone_v430((string)$event['schedule_timezone']));
    $dayStart = $localStart->setTimezone($tz)->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'));
    $dayEnd = $dayStart->setTimezone($tz)->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM agent_scheduling_bookings WHERE event_type_id=? AND status IN ('pending','confirmed') AND start_at_utc>=? AND start_at_utc<?");
    $stmt->execute([(int)$event['id'], $dayStart->format('Y-m-d H:i:s'), $dayEnd->format('Y-m-d H:i:s')]);
    return (int)$stmt->fetchColumn();
}

function agent_scheduling_validate_start_v430(PDO $pdo, array $event, DateTimeImmutable $startUtcObject, int $excludeBookingId = 0): void
{
    $timezone = agent_scheduling_timezone_v430((string)$event['schedule_timezone']);
    $tz = new DateTimeZone($timezone);
    $localStart = $startUtcObject->setTimezone($tz);
    $duration = max(5, min(1440, (int)$event['duration_minutes']));
    $localDate = $localStart->format('Y-m-d');
    $startMinute = ((int)$localStart->format('G') * 60) + (int)$localStart->format('i');
    $endMinute = $startMinute + $duration;

    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($startUtcObject <= $nowUtc) throw new RuntimeException('Choose a future appointment time.');
    $minimumNotice = max(0, (int)$event['minimum_notice_minutes']);
    if ($startUtcObject < $nowUtc->modify('+' . $minimumNotice . ' minutes')) throw new RuntimeException('That time is inside the minimum booking notice.');
    $windowDays = max(1, (int)$event['booking_window_days']);
    if ($startUtcObject > $nowUtc->modify('+' . $windowDays . ' days')) throw new RuntimeException('That time is outside the booking window.');

    $windows = agent_scheduling_windows_for_date_v430($pdo, $event, $localDate);
    $slotInterval = max(5, (int)$event['slot_interval_minutes']);
    $allowed = false;
    foreach ($windows as [$windowStart, $windowEnd]) {
        if ($startMinute >= $windowStart && $endMinute <= $windowEnd && (($startMinute - $windowStart) % $slotInterval) === 0) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) throw new RuntimeException('That time is outside the available booking hours.');

    $maxPerDay = max(0, (int)$event['max_bookings_per_day']);
    if ($maxPerDay > 0 && agent_scheduling_day_booking_count_v430($pdo, $event, $localStart) >= $maxPerDay) {
        throw new RuntimeException('The daily booking limit has been reached.');
    }

    $endUtcObject = $startUtcObject->modify('+' . $duration . ' minutes');
    if (agent_scheduling_conflict_v430($pdo, (int)$event['schedule_id'], $startUtcObject->format('Y-m-d H:i:s'), $endUtcObject->format('Y-m-d H:i:s'), (int)$event['buffer_before_minutes'], (int)$event['buffer_after_minutes'], $excludeBookingId)) {
        throw new RuntimeException('That time is already busy. Please choose another time.');
    }
}

function agent_scheduling_slots_for_date_v430(PDO $pdo, int $eventTypeId, string $date, bool $publicOnly = true): array
{
    $event = agent_scheduling_event_type_v430($pdo, $eventTypeId);
    if (!$event || empty($event['is_active']) || empty($event['schedule_active']) || ($publicOnly && empty($event['public_enabled']))) return [];
    if(function_exists('agent_calendar_sync_maybe_schedule_v500'))agent_calendar_sync_maybe_schedule_v500($pdo,(int)$event['schedule_id']);
    $timezone = agent_scheduling_timezone_v430((string)$event['schedule_timezone']);
    $tz = new DateTimeZone($timezone);
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
    if (!$day || $day->format('Y-m-d') !== $date) return [];

    $duration = max(5, (int)$event['duration_minutes']);
    $interval = max(5, (int)$event['slot_interval_minutes']);
    $slots = [];
    foreach (agent_scheduling_windows_for_date_v430($pdo, $event, $date) as [$windowStart, $windowEnd]) {
        for ($minute = $windowStart; $minute + $duration <= $windowEnd; $minute += $interval) {
            $local = $day->setTime(intdiv($minute, 60), $minute % 60);
            $utc = $local->setTimezone(new DateTimeZone('UTC'));
            try {
                agent_scheduling_validate_start_v430($pdo, $event, $utc);
            } catch (RuntimeException $e) {
                continue;
            }
            $endUtc = $utc->modify('+' . $duration . ' minutes');
            $slots[] = [
                'start_at_utc' => $utc->format('Y-m-d H:i:s'),
                'end_at_utc' => $endUtc->format('Y-m-d H:i:s'),
                'start_local' => $local->format('Y-m-d H:i:s'),
                'timezone' => $timezone,
            ];
        }
    }
    return $slots;
}

function agent_scheduling_create_booking_v430(PDO $pdo, array $input): array
{
    $eventTypeId = (int)($input['event_type_id'] ?? 0);
    if ($eventTypeId < 1) throw new RuntimeException('Choose an appointment type.');
    $event = agent_scheduling_event_type_v430($pdo, $eventTypeId);
    if (!$event || empty($event['is_active']) || empty($event['schedule_active'])) throw new RuntimeException('This appointment type is not available.');

    $source = trim((string)($input['source'] ?? 'public')) ?: 'public';
    $source = mb_strimwidth($source, 0, 40, '');
    if ($source === 'public' && empty($event['public_enabled'])) throw new RuntimeException('Public booking is disabled for this schedule.');

    $ownerUserId = (int)$event['owner_user_id'];
    $scheduleId = (int)$event['schedule_id'];
    $agentId = (int)($event['agent_id'] ?? 0) ?: null;
    $duration = max(5, min(1440, (int)($event['duration_minutes'] ?? 30)));
    $bufferBefore = max(0, min(1440, (int)($event['buffer_before_minutes'] ?? 0)));
    $bufferAfter = max(0, min(1440, (int)($event['buffer_after_minutes'] ?? 0)));
    $organizerTimezone = agent_scheduling_timezone_v430((string)($event['schedule_timezone'] ?? 'UTC'));
    $guestTimezone = agent_scheduling_timezone_v430((string)($input['guest_timezone'] ?? $organizerTimezone), $organizerTimezone);
    if(function_exists('agent_calendar_sync_maybe_schedule_v500'))agent_calendar_sync_maybe_schedule_v500($pdo,$scheduleId);

    try {
        if (!empty($input['start_at_utc'])) {
            $startUtcObject = new DateTimeImmutable((string)$input['start_at_utc'], new DateTimeZone('UTC'));
            $startUtcObject = $startUtcObject->setTimezone(new DateTimeZone('UTC'));
        } else {
            $startInput = trim((string)($input['start_at'] ?? ''));
            if ($startInput === '') throw new RuntimeException('Choose an appointment date and time.');
            $startLocal = new DateTimeImmutable($startInput, new DateTimeZone($organizerTimezone));
            $startUtcObject = $startLocal->setTimezone(new DateTimeZone('UTC'));
        }
    } catch (Throwable $e) {
        if ($e instanceof RuntimeException) throw $e;
        throw new RuntimeException('Enter a valid appointment date and time.');
    }
    $endUtcObject = $startUtcObject->modify('+' . $duration . ' minutes');
    $startUtc = $startUtcObject->format('Y-m-d H:i:s');
    $endUtc = $endUtcObject->format('Y-m-d H:i:s');

    $guestName = trim(preg_replace('/\s+/u', ' ', (string)($input['guest_name'] ?? '')) ?? '');
    if ($guestName === '') throw new RuntimeException('Enter the attendee name.');
    $guestEmail = strtolower(trim((string)($input['guest_email'] ?? '')));
    if ($guestEmail !== '' && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid attendee email.');

    $createdByUserId = max(0, (int)($input['created_by_user_id'] ?? 0)) ?: null;
    $createdByAgentId = max(0, (int)($input['created_by_agent_id'] ?? 0)) ?: null;
    if ($createdByAgentId !== null && !user_agent_get_v236($pdo, $ownerUserId, $createdByAgentId)) throw new RuntimeException('Booking Agent does not belong to this account.');

    $lockName = 'vp3_schedule_' . $scheduleId;
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?,5)');
    $lockStmt->execute([$lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) throw new RuntimeException('That schedule is busy. Please choose the time again.');

    $booking=null;
    try {
        agent_scheduling_validate_start_v430($pdo, $event, $startUtcObject);
        $publicToken = bin2hex(random_bytes(32));
        $cancelToken = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            'INSERT INTO agent_scheduling_bookings
             (owner_user_id,schedule_id,event_type_id,agent_id,created_by_user_id,created_by_agent_id,event_title,duration_minutes,buffer_before_minutes,buffer_after_minutes,start_at_utc,end_at_utc,organizer_timezone,guest_timezone,guest_name,guest_email,guest_phone,status,location_type,location_value,guest_notes,internal_notes,source,public_token,cancel_token)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $ownerUserId,$scheduleId,$eventTypeId,$agentId,$createdByUserId,$createdByAgentId,
            mb_strimwidth((string)$event['title'], 0, 190, ''),$duration,$bufferBefore,$bufferAfter,$startUtc,$endUtc,
            $organizerTimezone,$guestTimezone,mb_strimwidth($guestName, 0, 190, ''),mb_strimwidth($guestEmail, 0, 190, ''),
            mb_strimwidth(trim((string)($input['guest_phone'] ?? '')), 0, 80, ''),'confirmed',
            mb_strimwidth((string)($event['location_type'] ?? 'virtual'), 0, 30, ''),mb_strimwidth((string)($event['location_value'] ?? ''), 0, 500, ''),
            trim((string)($input['guest_notes'] ?? '')) ?: null,trim((string)($input['internal_notes'] ?? '')) ?: null,$source,$publicToken,$cancelToken,
        ]);
        $bookingId = (int)$pdo->lastInsertId();
        $find = $pdo->prepare('SELECT * FROM agent_scheduling_bookings WHERE id=? LIMIT 1');
        $find->execute([$bookingId]);
        $booking = $find->fetch();
        if (!$booking) throw new RuntimeException('Appointment could not be created.');
    } finally {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        } catch (Throwable $ignored) {
        }
    }
    if(!$booking)throw new RuntimeException('Appointment could not be created.');
    if(function_exists('agent_calendar_sync_booking_v500')){
        agent_calendar_sync_booking_v500($pdo,$booking);
        $refresh=$pdo->prepare('SELECT * FROM agent_scheduling_bookings WHERE id=? AND owner_user_id=? LIMIT 1');$refresh->execute([(int)$booking['id'],$ownerUserId]);$booking=$refresh->fetch()?:$booking;
    }
    return $booking;
}

function agent_scheduling_cancel_booking_v430(PDO $pdo, int $bookingId, ?int $ownerUserId = null, string $cancelToken = ''): bool
{
    if ($bookingId < 1) return false;
    if ($ownerUserId !== null && $ownerUserId > 0) {
        $find=$pdo->prepare("SELECT * FROM agent_scheduling_bookings WHERE id=? AND owner_user_id=? AND status IN ('pending','confirmed') LIMIT 1");$find->execute([$bookingId,$ownerUserId]);$booking=$find->fetch();
        if(!$booking)return false;
        $stmt = $pdo->prepare("UPDATE agent_scheduling_bookings SET status='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=? AND owner_user_id=? AND status IN ('pending','confirmed')");
        $stmt->execute([$bookingId, $ownerUserId]);
        $changed=$stmt->rowCount()>0;
        if($changed&&function_exists('agent_calendar_sync_cancel_booking_v500'))agent_calendar_sync_cancel_booking_v500($pdo,$booking);
        return $changed;
    }
    $cancelToken = trim($cancelToken);
    if ($cancelToken === '') return false;
    $find=$pdo->prepare("SELECT * FROM agent_scheduling_bookings WHERE id=? AND cancel_token=? AND status IN ('pending','confirmed') LIMIT 1");$find->execute([$bookingId,$cancelToken]);$booking=$find->fetch();
    if(!$booking)return false;
    $stmt = $pdo->prepare("UPDATE agent_scheduling_bookings SET status='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=? AND cancel_token=? AND status IN ('pending','confirmed')");
    $stmt->execute([$bookingId, $cancelToken]);
    $changed=$stmt->rowCount()>0;
    if($changed&&function_exists('agent_calendar_sync_cancel_booking_v500'))agent_calendar_sync_cancel_booking_v500($pdo,$booking);
    return $changed;
}
