<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$pdo = db();
$token = strtolower(trim((string)($_GET['token'] ?? '')));
$booking = $pdo ? agent_scheduling_booking_by_public_token_v450($pdo, $token) : null;
if (!$booking) {
    http_response_code(404);
    exit('Calendar event not found.');
}

function booking_ics_escape_v450(string $value): string
{
    $value = trim($value);
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace([';', ','], ['\\;', '\\,'], $value);
    $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    return $value;
}

function booking_ics_fold_v450(string $line): string
{
    $out = '';
    while (strlen($line) > 73) {
        $cut = 73;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line;
}

function booking_ics_line_v450(string $name, string $value): string
{
    return booking_ics_fold_v450($name . ':' . booking_ics_escape_v450($value));
}

$start = new DateTimeImmutable((string)$booking['start_at_utc'], new DateTimeZone('UTC'));
$end = new DateTimeImmutable((string)$booking['end_at_utc'], new DateTimeZone('UTC'));
$domain = (string)(parse_url(url('/'), PHP_URL_HOST) ?: 'vp3.me');
$status = (string)$booking['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED';
$uid = 'vp3-booking-' . (int)$booking['id'] . '@' . preg_replace('/[^A-Za-z0-9.-]/', '', $domain);
$summary = (string)$booking['event_title'];
$description = 'Scheduled through ' . system_agent_name() . '.';
$location = trim((string)($booking['location_value'] ?? ''));

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//' . preg_replace('/[^A-Za-z0-9 ._-]/', '', system_agent_name()) . '//Agent Scheduling//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    booking_ics_line_v450('UID', $uid),
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART:' . $start->format('Ymd\THis\Z'),
    'DTEND:' . $end->format('Ymd\THis\Z'),
    booking_ics_line_v450('SUMMARY', $summary),
    booking_ics_line_v450('DESCRIPTION', $description),
    booking_ics_line_v450('STATUS', $status),
];
if ($location !== '') $lines[] = booking_ics_line_v450('LOCATION', $location);
$lines[] = 'END:VEVENT';
$lines[] = 'END:VCALENDAR';

if (!headers_sent()) {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="appointment-' . (int)$booking['id'] . '.ics"');
    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}
echo implode("\r\n", $lines) . "\r\n";
