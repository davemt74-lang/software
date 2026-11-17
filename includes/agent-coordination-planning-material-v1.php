<?php
declare(strict_types=1);

/** Facts from the already-authorized Calendar and Scheduling projection. */
function vp3_coordination_planning_slot_v1(array $item): ?array
{
    $ref = $item['ref'] ?? null;
    $status = $item['status'] ?? null;
    if (($item['specialist'] ?? null) !== 'planning' || !is_string($ref)
        || preg_match('/\Aplanning:(?:calendar:event:[1-9][0-9]{0,17}(?:@[0-9]{8}T[0-9]{6}Z)?|scheduling:booking:[1-9][0-9]{0,17})\z/D', $ref) !== 1
        || !in_array($status, ['ready','confirmed','pending','rescheduled','cancelled'], true)) return null;

    $start = vp3_coordination_planning_time_v1($item['start_at'] ?? null);
    $end = vp3_coordination_planning_time_v1($item['end_at'] ?? null);
    if ($status !== 'cancelled' && ($start === null || $end === null || $end <= $start)) return null;
    return ['ref'=>$ref,'status'=>$status,'start'=>$start,'end'=>$end,'all_day'=>($item['all_day'] ?? false) === true];
}

function vp3_coordination_planning_time_v1(mixed $value): ?int
{
    if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $value) !== 1) return null;
    $canonical = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
    $time = DateTimeImmutable::createFromFormat(DATE_ATOM, $canonical);
    return $time && $time->format(DATE_ATOM) === $canonical ? $time->getTimestamp() : null;
}

/** Display revisions can change while the actual appointment, conflict or confirmation stays the same. */
function vp3_coordination_planning_material_v1(array $item, array $items): ?array
{
    $trigger = $item['trigger'] ?? null;
    if (!in_array($trigger, ['pending_confirmation','changed','conflict'], true)) return null;
    $slot = vp3_coordination_planning_slot_v1($item);
    if ($slot === null) return null;

    $ref = $slot['ref'];
    $facts = [$ref=>$slot];
    if ($trigger === 'conflict') {
        $other = $item['related_ref'] ?? null;
        if (!is_string($other) || $other === $ref || !isset($items[$other]) || !is_array($items[$other])) return null;
        $counterpart = vp3_coordination_planning_slot_v1($items[$other]);
        if ($counterpart === null || $counterpart['ref'] !== $other || $slot['all_day'] || $counterpart['all_day']
            || $slot['status'] === 'cancelled' || $counterpart['status'] === 'cancelled'
            || $slot['start'] >= $counterpart['end'] || $counterpart['start'] >= $slot['end']) return null;
        $facts[$other] = $counterpart;
        ksort($facts);
    }
    $refs = array_keys($facts);
    return ['key'=>vp3_coordination_source_hash_v1(['planning-material-v1',$refs]),
        'revision'=>vp3_coordination_source_hash_v1(['planning-material-v1',$trigger,$facts]),
        'related_refs'=>$refs];
}

/** Exact distinctive topic, with only a verified bare weekday suffix permitted on the Calendar title. */
function vp3_coordination_planning_gmail_topic_matches_v1(string $topic, array $planning, array $mail): bool
{
    $slot = vp3_coordination_planning_slot_v1($planning);
    $title = $planning['title'] ?? null;
    $zone = $planning['timezone'] ?? 'UTC';
    if ($slot === null || $slot['all_day'] || $slot['start'] === null || !is_string($title) || !is_string($zone)) return false;
    $meetingTime = $mail['meeting_at'] ?? null;
    if ($meetingTime !== null && vp3_coordination_planning_time_v1($meetingTime) !== $slot['start']) return false;
    try {
        $timezone = new DateTimeZone($zone);
    } catch (Exception $error) {
        return false;
    }
    $local = (new DateTimeImmutable('@'.$slot['start']))->setTimezone($timezone);
    if ($meetingTime === null && isset($mail['meeting_date']) && $mail['meeting_date'] !== $local->format('Y-m-d')) return false;
    if (preg_match('/\A(.+?)\s+[—–-]\s+([A-Za-z]{3,9})\z/uD', $title, $suffix) === 1) {
        $weekday = strtolower($suffix[2]);
        if (in_array($weekday, [strtolower($local->format('l')),strtolower($local->format('D'))], true)) {
            $title = $suffix[1];
        }
    }
    // A Calendar title has no Gmail transport prefixes. Retain any real words outside a verified weekday.
    return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($title,'UTF-8')) ?? '') === $topic;
}

/** Associate only a unique matching authorized event with a single verified overlapping pair. */
function vp3_coordination_planning_conflict_for_gmail_v1(array $gmail, array $items): ?array
{
    $mail = $gmail['material'] ?? [];
    $topic = $mail['topic'] ?? null;
    if (($gmail['specialist'] ?? null) !== 'gmail' || ($mail['intent'] ?? null) !== 'reschedule'
        || !is_string($topic) || preg_match('/\A[\p{L}\p{N}]+(?: [\p{L}\p{N}]+)+\z/uD',$topic) !== 1) return null;
    $matches = [];
    foreach ($items as $ref=>$item) {
        if (($item['ref'] ?? null) === $ref && vp3_coordination_planning_gmail_topic_matches_v1($topic,$item,$mail)) {
            $matches[] = $ref;
        }
    }
    if (count($matches) !== 1) return null;
    $conflict = null;
    foreach ($items as $ref=>$item) {
        if (($item['ref'] ?? null) !== $ref || ($item['trigger'] ?? null) !== 'conflict'
            || ($ref !== $matches[0] && ($item['related_ref'] ?? null) !== $matches[0])) continue;
        $material = vp3_coordination_planning_material_v1($item,$items);
        if ($material === null) continue;
        if ($conflict !== null) return null;
        $conflict = $material + ['ref'=>$ref,'meeting_ref'=>$matches[0]];
    }
    return $conflict;
}
