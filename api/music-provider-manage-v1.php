<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/music-provider-http-v1.php';

try {
    music_provider_http_v1_headers();
    music_provider_http_v1_method(['POST']);
    $user=current_user();
    if (!$user) music_provider_v1_error('authentication_required',401,'Sign in to manage your music sites.');
    $input=music_provider_http_v1_input();
    $_POST=$input;
    if (!verify_csrf()) music_provider_v1_error('session_changed',419,'Your session changed. Refresh before managing music sites.');
    if ($_GET) music_provider_v1_error('invalid_request',422,'Send music site selectors in the JSON request.');
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    music_provider_http_v1_reply(music_provider_v1_owner_action(music_provider_http_v1_db(),$user,$input));
} catch (Throwable $error) {music_provider_http_v1_failure($error);}
