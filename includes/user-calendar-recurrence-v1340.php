<?php
declare(strict_types=1);

/** Weekly series stay canonical; occurrences are read-only range projections. */
function user_calendar_recurrence_schema_ready_v1340(?PDO $pdo = null): bool
{
    return column_exists('user_calendar_events', 'recurrence') && column_exists('user_calendar_events', 'agent_notifications');
}

function user_calendar_options_v1340(array $input, array $existing = []): array
{
    $recurrence = $input['recurrence'] ?? $existing['recurrence'] ?? 'none';
    if (!is_string($recurrence) || !in_array($recurrence, ['none','weekly'], true)) {
        throw new RuntimeException('Choose One time or Every week.');
    }
    $notify = $input['agent_notifications'] ?? $existing['agent_notifications'] ?? false;
    if (!in_array($notify, [true,false,0,1,'0','1'], true)) throw new RuntimeException('Agent notifications must be enabled or disabled.');
    return ['recurrence'=>$recurrence, 'agent_notifications'=>(int)(bool)$notify];
}

function user_calendar_validate_options_v1340(PDO $pdo, array $options, array $range): void
{
    if (($options['recurrence'] !== 'none' || $options['agent_notifications']) && !user_calendar_recurrence_schema_ready_v1340($pdo)) {
        throw new RuntimeException('Run the database upgrade to enable recurring events and agent notifications.');
    }
    if ($options['recurrence'] === 'weekly') {
        $zone = new DateTimeZone((string)$range['timezone']);
        $start = user_calendar_validate_utc_v1300($range['start_at_utc'])->setTimezone($zone);
        $end = user_calendar_validate_utc_v1300($range['end_at_utc'])->setTimezone($zone);
        if ($end > $start->modify('+1 week')) throw new RuntimeException('Weekly events must end within seven days of their start.');
    }
}

function user_calendar_occurrences_v1340(array $row, string $fromUtc, string $toUtc): array
{
    $from = user_calendar_validate_utc_v1300($fromUtc);
    $to = user_calendar_validate_utc_v1300($toUtc);
    if ($to <= $from || $to->getTimestamp()-$from->getTimestamp() > 370*86400) throw new RuntimeException('Calendar range is invalid.');
    if (($row['status'] ?? 'active') !== 'active') return [];
    $utc = new DateTimeZone('UTC');
    $start = user_calendar_validate_utc_v1300((string)$row['start_at_utc']);
    $end = user_calendar_validate_utc_v1300((string)$row['end_at_utc']);
    $row += ['recurrence'=>'none','agent_notifications'=>0];
    if ($row['recurrence'] !== 'weekly') return $start < $to && $end > $from ? [$row] : [];
    $zone = new DateTimeZone(user_calendar_timezone_v1300((string)$row['timezone']));
    $start = $start->setTimezone($zone); $end = $end->setTimezone($zone);
    // Start near the requested window, even for a series created years ago.
    $week = max(0, (int)floor(($from->getTimestamp()-$end->getTimestamp())/604800)-1);
    $items = [];
    for ($i=0; $i<58; $i++, $week++) {
        $s = $start->modify('+'.$week.' weeks');
        $e = $end->modify('+'.$week.' weeks');
        if ($s >= $to) break;
        // A missing wall time during the spring DST transition skips that week;
        // compute every occurrence from the anchor so later weeks never drift.
        if ($s->format('H:i:s') !== $start->format('H:i:s') || $e->format('H:i:s') !== $end->format('H:i:s')) continue;
        if ($e <= $from || $e <= $s) continue;
        $item = $row;
        $item['series_id'] = (int)$row['id'];
        $item['series_start_at_utc'] = (string)$row['start_at_utc'];
        $item['series_end_at_utc'] = (string)$row['end_at_utc'];
        $item['start_at_utc'] = $s->setTimezone($utc)->format('Y-m-d H:i:s');
        $item['end_at_utc'] = $e->setTimezone($utc)->format('Y-m-d H:i:s');
        $item['occurrence_id'] = 'event:'.(int)$row['id'].'@'.$s->setTimezone($utc)->format('Ymd\THis\Z');
        $items[] = $item;
    }
    return $items;
}

function user_calendar_owned_events_v1340(PDO $pdo, int $owner, string $fromUtc, string $toUtc): array
{
    if ($owner < 1) return [];
    $from = user_calendar_validate_utc_v1300($fromUtc); $to = user_calendar_validate_utc_v1300($toUtc);
    if ($to <= $from || $to->getTimestamp()-$from->getTimestamp() > 370*86400) throw new RuntimeException('Calendar range is invalid.');
    $weekly = user_calendar_recurrence_schema_ready_v1340($pdo);
    $sql = $weekly
        ? "SELECT * FROM user_calendar_events WHERE owner_user_id=? AND status='active' AND start_at_utc<? AND (end_at_utc>? OR recurrence='weekly') ORDER BY start_at_utc,id"
        : "SELECT * FROM user_calendar_events WHERE owner_user_id=? AND status='active' AND start_at_utc<? AND end_at_utc>? ORDER BY start_at_utc,id";
    $stmt = $pdo->prepare($sql); $stmt->execute([$owner,$toUtc,$fromUtc]); $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) foreach (user_calendar_occurrences_v1340($row,$fromUtc,$toUtc) as $item) $items[] = $item;
    usort($items,static fn(array $a,array $b):int=>strcmp($a['start_at_utc'],$b['start_at_utc']) ?: ($a['id']<=>$b['id']));
    return $items;
}
