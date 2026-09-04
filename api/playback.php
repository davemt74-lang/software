<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$pdo = db();
if (!$pdo || !table_exists('track_play_sessions')) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Listening analytics is not ready.']);
    exit;
}

$raw = (string)file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrf = (string)($input['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    http_response_code(419);
    echo json_encode(['ok'=>false,'error'=>'Session expired.']);
    exit;
}

function sf_device_type(string $ua): string
{
    $ua = strtolower($ua);
    if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) return 'tablet';
    if (str_contains($ua, 'mobile') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) return 'mobile';
    if ($ua === '') return 'unknown';
    return 'desktop';
}

function sf_listener_hash(): string
{
    $cookie = (string)($_COOKIE['sf_listener'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $cookie)) {
        $cookie = bin2hex(random_bytes(32));
        setcookie('sf_listener', $cookie, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    return hash('sha256', $cookie);
}

function sf_referrer_host(): string
{
    $referrer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referrer === '') return '';
    $host = parse_url($referrer, PHP_URL_HOST);
    return is_string($host) ? substr($host, 0, 190) : '';
}

function sf_play_session(PDO $pdo, string $token, int $trackId): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = $pdo->prepare('SELECT * FROM track_play_sessions WHERE session_token=? AND track_id=? LIMIT 1');
    $stmt->execute([$token, $trackId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

try {
    $action = trim((string)($input['action'] ?? ''));
    $trackId = (int)($input['track_id'] ?? 0);
    $position = max(0.0, (float)($input['position'] ?? 0));
    $duration = max(0.0, (float)($input['duration'] ?? 0));
    $delta = max(0.0, min(20.0, (float)($input['delta'] ?? 0)));
    $sourceContext = trim((string)($input['source'] ?? 'player'));
    $geo = is_array($input['geo'] ?? null) ? $input['geo'] : [];
    $listenerCity = trim((string)($geo['city'] ?? $_SERVER['HTTP_X_VERCEL_IP_CITY'] ?? $_SERVER['HTTP_CF_IPCITY'] ?? ''));
    $listenerRegion = trim((string)($geo['region'] ?? $_SERVER['HTTP_X_VERCEL_IP_COUNTRY_REGION'] ?? $_SERVER['HTTP_CF_REGION'] ?? ''));
    $listenerCountry = trim((string)($geo['country'] ?? $_SERVER['HTTP_X_VERCEL_IP_COUNTRY'] ?? $_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    $listenerLatitude = isset($geo['latitude']) && is_numeric($geo['latitude']) ? max(-90.0,min(90.0,(float)$geo['latitude'])) : null;
    $listenerLongitude = isset($geo['longitude']) && is_numeric($geo['longitude']) ? max(-180.0,min(180.0,(float)$geo['longitude'])) : null;
    if (!in_array($sourceContext, ['player','agent_chat','agent_player'], true)) {
        $sourceContext = 'player';
    }

    $track = get_track_by_id($trackId);
    if (!$track || !can_view_track($track)) {
        throw new RuntimeException('Track is not available.');
    }

    if ($action === 'start') {
        $token = bin2hex(random_bytes(32));
        $user = current_user();
        $stmt = $pdo->prepare(
            'INSERT INTO track_play_sessions
             (session_token,track_id,user_id,listener_hash,device_type,referrer_host,source_context,duration_seconds,last_position_seconds,max_position_seconds,listener_city,listener_region,listener_country,listener_latitude,listener_longitude)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $token,
            $trackId,
            (int)($user['id'] ?? 0) ?: null,
            sf_listener_hash(),
            sf_device_type((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
            sf_referrer_host(),
            $sourceContext,
            $duration,
            $position,
            $position,
            mb_substr($listenerCity,0,120),
            mb_substr($listenerRegion,0,120),
            mb_substr($listenerCountry,0,80),
            $listenerLatitude,
            $listenerLongitude,
        ]);

        $sessionId = (int)$pdo->lastInsertId();
        $event = $pdo->prepare(
            'INSERT INTO track_play_events (session_id,event_type,position_seconds,listened_delta_seconds)
             VALUES (?,?,?,0)'
        );
        $event->execute([$sessionId,'start',$position]);

        if (
            $user &&
            (string)($user['role'] ?? '') === 'supervisor'
        ) {
            $ownerUserId = (int)($track['owner_user_id'] ?? 0);
            $supervisorUserId = (int)($user['id'] ?? 0);
            $supervisorName = trim(
                (string)($user['display_name'] ?? '')
            );
            $listenSource = match ($sourceContext) {
                'agent_chat' => 'Agent Chat',
                'agent_player' => 'Player',
                default => 'Music Player',
            };

            $title = 'Supervisor started listening';
            $body =
                ($supervisorName !== ''
                    ? $supervisorName
                    : 'A Supervisor')
                . ' started listening to '
                . (string)$track['title']
                . ' in '
                . $listenSource
                . '.';

            if (
                $ownerUserId > 0 &&
                $ownerUserId !== $supervisorUserId
            ) {
                create_notification(
                    $ownerUserId,
                    'agent_supervisor_listen',
                    $title,
                    $body,
                    url('/admin/track.php?id=' . $trackId),
                    'supervisor_play_session',
                    $sessionId
                );
            } elseif ($ownerUserId < 1) {
                create_notification_for_permission(
                    'tracks.manage',
                    'agent_supervisor_listen',
                    $title,
                    $body,
                    url('/admin/track.php?id=' . $trackId),
                    'supervisor_play_session',
                    $sessionId
                );
            }
        }

        echo json_encode(['ok'=>true,'session_token'=>$token,'session_id'=>$sessionId]);
        exit;
    }

    $token = trim((string)($input['session_token'] ?? ''));
    $session = sf_play_session($pdo, $token, $trackId);
    if (!$session) {
        throw new RuntimeException('Listening session not found.');
    }

    $allowedEvents = ['heartbeat','pause','resume','seek','ended','stop'];
    if (!in_array($action, $allowedEvents, true)) {
        throw new RuntimeException('Invalid listening event.');
    }

    $newListened = (float)$session['listened_seconds'] + $delta;
    $newMaxPosition = max((float)$session['max_position_seconds'], $position);
    $effectiveDuration = $duration > 0 ? $duration : (float)$session['duration_seconds'];
    $completion = $effectiveDuration > 0
        ? min(100.0, ($newMaxPosition / $effectiveDuration) * 100.0)
        : 0.0;

    $qualified = $newListened >= 10.0 ? 1 : (int)$session['qualified_play'];
    $completed = (
        $action === 'ended' ||
        ($effectiveDuration > 0 && $newListened >= ($effectiveDuration * 0.80))
    ) ? 1 : (int)$session['completed'];

    $endedAt = in_array($action, ['ended','stop'], true) ? date('Y-m-d H:i:s') : $session['ended_at'];

    $stmt = $pdo->prepare(
        'UPDATE track_play_sessions
         SET last_event_at=NOW(),
             ended_at=?,
             listened_seconds=?,
             last_position_seconds=?,
             max_position_seconds=?,
             duration_seconds=?,
             completion_percent=?,
             qualified_play=?,
             completed=?
         WHERE id=?'
    );
    $stmt->execute([
        $endedAt,
        round($newListened,2),
        round($position,2),
        round($newMaxPosition,2),
        round($effectiveDuration,2),
        round($completion,2),
        $qualified,
        $completed,
        (int)$session['id'],
    ]);

    $event = $pdo->prepare(
        'INSERT INTO track_play_events (session_id,event_type,position_seconds,listened_delta_seconds)
         VALUES (?,?,?,?)'
    );
    $event->execute([
        (int)$session['id'],
        $action,
        round($position,2),
        round($delta,2),
    ]);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
