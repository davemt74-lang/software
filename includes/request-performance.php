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

function request_performance_finish_response(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}
