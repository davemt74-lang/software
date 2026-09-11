<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
}

function public_scheduling_slots_json_v450(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (!$pdo || !agent_scheduling_schema_ready_v430($pdo) || !profile_agent_schema_ready($pdo)) {
    public_scheduling_slots_json_v450(['ok'=>false,'error'=>'Scheduling is not ready.'], 503);
}

$username = profile_username_normalize((string)($_GET['username'] ?? ''));
$eventSlug = agent_scheduling_slug_v430((string)($_GET['event'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
$guestTimezone = agent_scheduling_timezone_v430((string)($_GET['timezone'] ?? 'UTC'), 'UTC');

if ($username === '' || $eventSlug === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    public_scheduling_slots_json_v450(['ok'=>false,'error'=>'Invalid scheduling request.'], 422);
}

$profile = profile_by_username($pdo, $username);
if (!$profile || empty($profile['is_active']) || empty($profile['is_public'])) {
    public_scheduling_slots_json_v450(['ok'=>false,'error'=>'Booking page not found.'], 404);
}

$event = agent_scheduling_public_event_by_slug_v450($pdo, (int)$profile['user_id'], $eventSlug);
if (!$event) public_scheduling_slots_json_v450(['ok'=>false,'error'=>'Appointment type not found.'], 404);

$slots = [];
foreach (agent_scheduling_slots_for_date_v430($pdo, (int)$event['id'], $date, true) as $slot) {
    $utc = (string)$slot['start_at_utc'];
    $slots[] = [
        'start_at_utc' => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        'label' => agent_scheduling_public_display_time_v450($utc, $guestTimezone, 'g:i A'),
        'organizer_label' => agent_scheduling_public_display_time_v450($utc, (string)$event['schedule_timezone'], 'g:i A T'),
    ];
}

public_scheduling_slots_json_v450([
    'ok'=>true,
    'date'=>$date,
    'timezone'=>$guestTimezone,
    'schedule_timezone'=>agent_scheduling_timezone_v430((string)$event['schedule_timezone']),
    'event'=>[
        'id'=>(int)$event['id'],
        'slug'=>(string)$event['slug'],
        'title'=>(string)$event['title'],
        'duration_minutes'=>(int)$event['duration_minutes'],
    ],
    'slots'=>$slots,
]);
