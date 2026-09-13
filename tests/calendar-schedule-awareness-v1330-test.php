<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/calendar-schedule-awareness-v1330.php';

function calendar_awareness_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$empty = calendar_schedule_awareness_empty_v1330(99);
calendar_awareness_test_assert($empty['read_only'] === true, 'empty snapshot must be read-only');
calendar_awareness_test_assert($empty['availability_authority'] === false, 'awareness must not own availability');
calendar_awareness_test_assert($empty['horizon_days'] === 14, 'horizon must be capped');

$events = [
    [
        'ref'=>'event:1','title'=>'Planning','start_local'=>'2026-09-12T10:00:00-07:00','end_local'=>'2026-09-12T11:00:00-07:00',
    ],
    [
        'ref'=>'booking:2','title'=>'Client call','start_local'=>'2026-09-12T10:30:00-07:00','end_local'=>'2026-09-12T11:30:00-07:00',
    ],
    [
        'ref'=>'event:3','title'=>'Review','start_local'=>'2026-09-12T12:00:00-07:00','end_local'=>'2026-09-12T13:00:00-07:00',
    ],
];
$conflicts = calendar_schedule_awareness_conflicts_v1330($events);
calendar_awareness_test_assert(count($conflicts) === 1, 'one overlap should be detected');
calendar_awareness_test_assert((int)$conflicts[0]['overlap_minutes'] === 30, 'overlap duration should be exact');

$gaps = calendar_schedule_awareness_open_gaps_v1330($events, new DateTimeImmutable('2026-09-12T09:00:00-07:00'));
calendar_awareness_test_assert(count($gaps) >= 2, 'planning gaps should be derived around scheduled items');
calendar_awareness_test_assert($gaps[0]['planning_only'] === true, 'gaps must be explicitly non-authoritative planning data');
calendar_awareness_test_assert((int)$gaps[0]['minutes'] === 60, 'leading planning gap should be one hour');

$snapshot = $empty;
$snapshot['available'] = true;
$snapshot['generated_at'] = '2026-09-12T16:00:00+00:00';
$snapshot['conflicts'] = $conflicts;
$snapshot['starts_soon'] = true;
$snapshot['next'] = [
    'ref'=>'event:9','title'=>'Production review','starts_in_minutes'=>15,'all_day'=>false,
];
$candidates = calendar_schedule_awareness_candidates_v1330($snapshot);
calendar_awareness_test_assert(count($candidates) === 2, 'conflict and starts-soon signals should be bounded Brain candidates');
calendar_awareness_test_assert($candidates[0]['source'] === 'calendar_conflict', 'conflict should lead candidate set');
calendar_awareness_test_assert($candidates[1]['source'] === 'calendar_upcoming', 'starts-soon candidate should follow conflict');
calendar_awareness_test_assert($candidates[0]['url'] === '/calendar.php', 'calendar candidates should route back to User Calendar');

echo "Calendar Schedule Awareness v13.30 helpers: OK\n";
