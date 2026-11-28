<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/music-listening-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST is required.']);exit;}

$pdo = db();
if (!$pdo || !table_exists('track_play_sessions')) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Listening analytics is not ready.']);
    exit;
}

$raw = (string)file_get_contents('php://input', false, null, 0, 32769);
if (strlen($raw)>32768 || (int)($_SERVER['CONTENT_LENGTH']??0)>32768) {
    http_response_code(413);echo json_encode(['ok'=>false,'code'=>'payload_too_large','error'=>'This listening request is too large.']);exit;
}
$input = json_decode($raw, true, 32);
if (!is_array($input)) $input = $_POST;
if (!$input && $raw!=='' && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')) {
    http_response_code(422);echo json_encode(['ok'=>false,'code'=>'invalid_request','error'=>'Use a JSON listening request.']);exit;
}

$csrf = is_string($input['csrf_token']??null)?$input['csrf_token']:'';
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
        $_COOKIE['sf_listener']=$cookie;
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

function sf_play_session(PDO $pdo, string $token, int $trackId,int $userId,string $listenerHash): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = $pdo->prepare('SELECT * FROM track_play_sessions WHERE session_token=? AND track_id=? AND COALESCE(user_id,0)=? AND listener_hash=? LIMIT 1 FOR UPDATE');
    $stmt->execute([$token, $trackId,$userId,$listenerHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// A typo or a partially upgraded client must never downgrade replay-safe data
// into the legacy delta accumulator.
$v1Fields=['listener_user_id','play_id','event_id','seq','cumulative_played_ms','elapsed_ms','position_ms','playback_rate','state','observation_partial'];
$protocol=$input['protocol']??null;
if ((array_key_exists('protocol',$input)&&$protocol!==MUSIC_LISTENING_V1_PROTOCOL)
    || (!array_key_exists('protocol',$input)&&array_intersect($v1Fields,array_keys($input)))) {
    http_response_code(422);echo json_encode(['ok'=>false,'code'=>'unsupported_protocol','error'=>'Use a supported listening protocol.']);exit;
}
if ($protocol===MUSIC_LISTENING_V1_PROTOCOL) {
    try {
        // Do not allocate a play until a listener cookie has made a round trip.
        // A lost first Set-Cookie response can then only lose an initialization
        // acknowledgement, never create an unrepeatable counted start.
        $cookie=$_COOKIE['sf_listener']??null;
        if (!is_string($cookie)||!preg_match('/^[a-f0-9]{64}$/D',$cookie)) {
            if (($input['action']??null)==='start') {
                sf_listener_hash();
                music_listening_v1_error('listener_initialization_required',428,'Initialize the listener cookie before starting measured playback.',['retryable'=>true]);
            }
            music_listening_v1_error('listener_unavailable',403,'The listener cookie is unavailable. Start playback again.');
        }
        $configured=function_exists('site_config')?(string)site_config('base_url',''):'';
        $origin=music_listening_v1_origin($configured);
        // url('/') supplies the actual configured install prefix. No raw Host
        // header or arbitrary submitted path is promoted to a canonical origin.
        $basePath=function_exists('url')?url('/'):'/';
        $context=['listener_hash'=>sf_listener_hash(),'origin'=>$origin!==null?$origin.rtrim($basePath,'/'):'',
            'referrer'=>(string)($_SERVER['HTTP_REFERER']??''),'user_agent'=>(string)($_SERVER['HTTP_USER_AGENT']??'')];
        $result=music_listening_v1_handle($pdo,current_user(),$input,$context);
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    } catch (MusicListeningV1Exception $error) {
        http_response_code($error->httpStatus);
        echo json_encode(['ok'=>false,'code'=>$error->publicCode,'error'=>$error->getMessage()]+$error->details);
    } catch (Throwable) {
        error_log('Native listening request failed before acknowledgement.');
        http_response_code(503);
        echo json_encode(['ok'=>false,'code'=>'listening_unavailable','error'=>'Listening evidence is temporarily unavailable. Retry the same request.','retryable'=>true]);
    }
    exit;
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

    $track = music_media_track($trackId,current_user());
    if (!$track || !can_view_track($track)) {
        throw new RuntimeException('Track is not available.');
    }

    $user=current_user();$listenerHash=sf_listener_hash();
    $requestedTrackId=$trackId;
    $trackId=music_catalog_playback_track_id($pdo,$track);
    $track['id']=$trackId;
    $pdo->beginTransaction();
    if ($action === 'start') {
        $token = bin2hex(random_bytes(32));
        $user = current_user();
        if(is_array($input['media_offer_playback']??null)&&array_key_exists('history_ref',$input['media_offer_playback'])){
            require_once dirname(__DIR__).'/includes/agent-media-playback-v1.php';
            if(!vp3_media_history_start_authorized_v1($pdo,$user,$requestedTrackId,$sourceContext,$input['media_offer_playback'],(float)$position)){
                music_listening_v1_error('history_changed',409,'The selected history playback changed. Choose it again before playing.');
            }
        }
        $offer=null;
        if($user&&in_array($sourceContext,['agent_chat','agent_player'],true)&&is_array($input['media_offer_playback']??null)){
            require_once dirname(__DIR__).'/includes/agent-media-offer-native-receipt-v1.php';
            try{
                $offer=vp3_media_offer_native_start_v1($pdo,$user,$input['media_offer_playback'],$requestedTrackId);
            }catch(Throwable $error){
                if(!$pdo->inTransaction())$pdo->beginTransaction();
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO track_play_sessions
             (session_token,track_id,user_id,listener_hash,device_type,referrer_host,source_context,duration_seconds,last_position_seconds,max_position_seconds,listener_city,listener_region,listener_country,listener_latitude,listener_longitude)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $token,
            $trackId,
            (int)($user['id'] ?? 0) ?: null,
            $listenerHash,
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
        if($offer!==null){
            try{
                vp3_media_offer_native_record_v1($pdo,$offer,$sessionId,$trackId);
            }catch(PDOException $error){
                if(!$pdo->inTransaction())throw $error;
            }
        }

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

        $pdo->commit();
        echo json_encode(['ok'=>true,'session_token'=>$token,'session_id'=>$sessionId,'protocol'=>'legacy_client_delta_v1','metric_version'=>MUSIC_LISTENING_V1_METRIC]);
        exit;
    }

    $token = trim((string)($input['session_token'] ?? ''));
    $session = sf_play_session($pdo, $token, $trackId,(int)($user['id']??0),$listenerHash);
    if (!$session) {
        throw new RuntimeException('Listening session not found.');
    }
    // A native token cannot be submitted through the old unsequenced protocol.
    if (music_listening_v1_columns($pdo,'music_listening_sessions_v1')) {
        $native=$pdo->prepare('SELECT 1 FROM music_listening_sessions_v1 WHERE session_id=?');$native->execute([(int)$session['id']]);
        if ($native->fetchColumn()) music_listening_v1_error('protocol_required',409,'Use the original listening protocol for this session.');
    }

    $allowedEvents = ['heartbeat','pause','resume','seek','ended','stop'];
    if (!in_array($action, $allowedEvents, true)) {
        throw new RuntimeException('Invalid listening event.');
    }

    if(!empty($session['ended_at'])){$pdo->commit();echo json_encode(['ok'=>true,'closed'=>true,'protocol'=>'legacy_client_delta_v1','metric_version'=>MUSIC_LISTENING_V1_METRIC]);exit;}
    $elapsed=max(0,time()-(int)strtotime((string)$session['last_event_at']));
    $delta=min($delta,(float)$elapsed);
    $newListened = (float)$session['listened_seconds'] + $delta;
    $newMaxPosition = max((float)$session['max_position_seconds'], $position);
    $effectiveDuration = $duration > 0 ? $duration : (float)$session['duration_seconds'];
    $completion = $effectiveDuration > 0
        ? min(100.0, ($newMaxPosition / $effectiveDuration) * 100.0)
        : 0.0;

    $qualified = $newListened >= 10.0 ? 1 : (int)$session['qualified_play'];
    $completed = (
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

    $pdo->commit();
    if($user&&$delta>0)player_history_activity_notify($user,$track,(int)$session['id'],(string)$session['started_at']);
    echo json_encode(['ok'=>true,'protocol'=>'legacy_client_delta_v1','metric_version'=>MUSIC_LISTENING_V1_METRIC]);
} catch (Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    if ($e instanceof MusicListeningV1Exception) {
        http_response_code($e->httpStatus);echo json_encode(['ok'=>false,'code'=>$e->publicCode,'error'=>$e->getMessage()]+$e->details);
    } else {
        http_response_code(400);echo json_encode(['ok'=>false,'error'=>'This listening request could not be recorded.']);
    }
}
