<?php
declare(strict_types=1);

/** Binary reads retain all account/resource gates but do not trigger unrelated maintenance. */
function request_performance_media_read(): bool
{
    return in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET','HEAD'], true)
        && in_array(basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')), [
            'media.php','stem-media-v34.php','artist-track-audio.php','artist-music-image.php',
        ], true);
}

/** Appointment delivery can wait on mail transport. Run request-driven maintenance only on its own surfaces. */
function request_performance_appointment_housekeeping(): bool
{
    $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($script === STONEFELLOW_ROOT.'/api/agent-workflow-runs-v1400.php') {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }
    return in_array($script, [
        STONEFELLOW_ROOT.'/appointment-lifecycle.php', STONEFELLOW_ROOT.'/public-booking.php',
        STONEFELLOW_ROOT.'/team-book.php', STONEFELLOW_ROOT.'/calendar.php',
        STONEFELLOW_ROOT.'/calendar-event.php', STONEFELLOW_ROOT.'/agent-workflows.php',
    ], true);
}

function request_performance_finish_response(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}
