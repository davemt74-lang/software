<?php
declare(strict_types=1);

require_once __DIR__.'/ai-settings.php';
require_once __DIR__.'/connected-site-http-v1.php';

const VP3_STONEWARD_LINK_V1 = 'stoneward-vp3-link-v1';
const VP3_STONEWARD_HISTORY_SCOPE_V1 = 'listening.history.read';
const VP3_STONEWARD_PLAY_V1 = 'listening.track.play';
const VP3_STONEWARD_QUEUE_V1 = 'listening.track.queue';

final class Vp3StonewardPairingErrorV1 extends RuntimeException
{
    public function __construct(
        string $message,
        int $status,
        public readonly string $stage,
        public readonly string $reason,
        public readonly int $siteHttpStatus,
    ) {
        parent::__construct($message, $status);
    }
}

function vp3_stoneward_schema_v1(PDO $pdo): void
{
    $options = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_stoneward_connections_v1 (
        id CHAR(32) NOT NULL PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        instance_id CHAR(36) NOT NULL,
        remote_connection_id CHAR(32) NOT NULL,
        origin VARCHAR(261) NOT NULL,
        label VARCHAR(80) NOT NULL,
        site_name VARCHAR(120) NOT NULL,
        token_ciphertext TEXT NOT NULL,
        created_at VARCHAR(25) NOT NULL,
        UNIQUE (owner_user_id, origin),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$options);
}

function vp3_stoneward_schema_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT id,token_ciphertext FROM vp3_stoneward_connections_v1 WHERE 1=0') !== false;
    } catch (PDOException) {
        return false;
    }
}

function vp3_stoneward_text_v1(mixed $value, int $length): string
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) return '';
    $value = trim($value);
    return !preg_match('/[\p{Cc}\p{Cf}]/u', $value) && mb_strlen($value, 'UTF-8') <= $length ? $value : '';
}

function vp3_stoneward_transport_v1(string $origin, ?callable $resolver = null, bool $pairing = false, ?callable $observeDiscovery = null): Closure
{
    return vp3_connected_site_transport_v1($origin, '/api/stoneward-vp3.php', 'Stonefellow', $resolver, [404,409,422],
        $pairing ? vp3_stoneward_pairing_error_v1(...) : null, $observeDiscovery);
}

/** Only known protocol codes affect pairing guidance. The site's error text is untrusted. */
function vp3_stoneward_pairing_error_v1(int $status, string $contentType, string $body, ?string $action): ?Vp3StonewardPairingErrorV1
{
    if ($action !== null && $action !== 'exchange') return null;
    $stage = $action ?? 'discovery';
    $error = $contentType === 'application/json' && strlen($body) <= 4096 ? json_decode($body, true, 8) : null;
    $reason = is_array($error) && ($error['ok'] ?? null) === false ? ($error['error'] ?? null) : null;
    if ($status === 421 && $reason === 'site_origin_mismatch') {
        return new Vp3StonewardPairingErrorV1('Use the artist site’s configured HTTPS address in VP3 and create the code on that same address.',
            422, $stage, 'site_origin_mismatch', $status);
    }
    if ($status === 403 && $action === 'exchange' && $reason === 'invalid_pairing_code') {
        return new Vp3StonewardPairingErrorV1('Stonefellow could not use this one-time code. On the same artist site, confirm listening history is enabled and create a new code in VP3 Connections. Enter it within 10 minutes.',
            403, $stage, 'invalid_pairing_code', $status);
    }
    if ($status === 401 || $status === 403) {
        $step = $action === null ? 'connection check' : 'pairing request';
        return new Vp3StonewardPairingErrorV1("The artist site blocked the VP3 {$step} (HTTP {$status}). Check that the site allows VP3 to reach /api/stoneward-vp3.php without a browser sign-in.",
            403, $stage, 'site_access_blocked', $status);
    }
    if ($status === 404 && $action === null) {
        return new Vp3StonewardPairingErrorV1('The Stonefellow connector was not found. Check that /api/stoneward-vp3.php is installed on this artist site.',
            502, $stage, 'connector_missing', $status);
    }
    if ($status === 503 && $reason === 'unavailable') {
        return new Vp3StonewardPairingErrorV1('The artist site reported that its Stonefellow connector is unavailable. Check the artist-site installation and server logs.',
            503, $stage, 'connector_unavailable', $status);
    }
    return null;
}

function vp3_stoneward_discover_v1(callable $request, string $origin): array
{
    $response = $request();
    $instance = $response['instanceId'] ?? null;
    $name = vp3_stoneward_text_v1($response['siteName'] ?? null, 120);
    $capabilities = $response['capabilities'] ?? null;
    if (($response['ok'] ?? null) !== true || ($response['schema'] ?? null) !== VP3_STONEWARD_LINK_V1
        || ($response['origin'] ?? null) !== $origin || $name === ''
        || !is_string($instance) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $instance)
        || !is_array($capabilities) || !array_is_list($capabilities) || count($capabilities) > 20
        || array_filter($capabilities, static fn($capability): bool => !is_string($capability))
        || !in_array(VP3_STONEWARD_HISTORY_SCOPE_V1, $capabilities, true)) {
        throw new RuntimeException('The URL did not identify a supported Stonefellow installation.', 502);
    }
    return ['instanceId'=>$instance, 'siteName'=>$name, 'capabilities'=>$capabilities];
}

/** Project only bounded response metadata and never pass through a response body or credentials. */
function vp3_stoneward_diagnostic_visit_v1(string $contentType, array $headers, string $ip): array
{
    $server = strtolower($headers['server'] ?? '');
    $knownServers = ['cloudflare'=>'Cloudflare', 'litespeed'=>'LiteSpeed', 'apache'=>'Apache',
        'nginx'=>'nginx', 'sucuri'=>'Sucuri', 'akamaighost'=>'Akamai'];
    $name = strtok($server, " /");
    $visit = ['responseType'=>match ($contentType) {
        'application/json'=>'json', 'text/html', 'application/xhtml+xml'=>'html', default=>'other',
    }];
    if (isset($knownServers[$name])) $visit['server'] = $knownServers[$name];
    $ray = $headers['cf-ray'] ?? '';
    $requestId = $headers['x-request-id'] ?? '';
    if (preg_match('/\A[a-f0-9]{16}(?:-[A-Z0-9]{3,5})?\z/iD', $ray)) {
        $visit['requestId'] = $ray;
    } elseif (preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/iD', $requestId)) {
        $visit['requestId'] = $requestId;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP)) $visit['targetIp'] = $ip;
    if (strtolower($headers['cf-mitigated'] ?? '') === 'challenge') $visit['challenge'] = true;
    return $visit;
}

/** Check the same Stoneward discovery used in pairing, with no authorization exchange. */
function vp3_stoneward_check_connection_v1(string $origin, callable $observeDiscovery): void
{
    $request = vp3_stoneward_transport_v1($origin, pairing:true, observeDiscovery:$observeDiscovery);
    vp3_stoneward_discover_v1($request, $origin);
}

function vp3_stoneward_grant_response_v1(array $response, array $row): bool
{
    return ($response['ok'] ?? null) === true
        && ($response['schema'] ?? null) === VP3_STONEWARD_LINK_V1
        && ($response['origin'] ?? null) === $row['origin']
        && ($response['instanceId'] ?? null) === $row['instance_id']
        && ($response['connectionId'] ?? null) === $row['remote_connection_id'];
}

function vp3_stoneward_token_key_v1(array $row): string
{
    $master = ai_master_key();
    if ($master === null) throw new RuntimeException('VP3 credential encryption is unavailable.', 503);
    $binding = json_encode([
        VP3_STONEWARD_LINK_V1, (int)$row['owner_user_id'], $row['id'],
        $row['origin'], $row['instance_id'], $row['remote_connection_id'],
    ], JSON_THROW_ON_ERROR);
    return hash_hmac('sha256', $binding, $master, true);
}

function vp3_stoneward_token_v1(array $row): string
{
    $token = ai_decrypt_secret_with_key($row['token_ciphertext'], vp3_stoneward_token_key_v1($row));
    if (!preg_match('/^sw2_[A-Za-z0-9_-]{43}$/D', $token)) {
        throw new RuntimeException('The saved Stonefellow credential could not be verified.', 503);
    }
    return $token;
}

function vp3_stoneward_public_v1(array $row): array
{
    return [
        'id'=>$row['id'], 'provider'=>'stoneward', 'label'=>$row['label'], 'agentName'=>$row['site_name'],
        'platformName'=>'Stonefellow', 'origin'=>$row['origin'], 'instanceId'=>$row['instance_id'], 'status'=>'active',
        'scopes'=>[VP3_STONEWARD_HISTORY_SCOPE_V1], 'locations'=>[], 'createdAt'=>$row['created_at'],
    ];
}

function vp3_stoneward_connections_v1(PDO $pdo, int $owner, bool $requireSchema = false): array
{
    if ($owner < 1) return [];
    if (!vp3_stoneward_schema_ready_v1($pdo)) {
        if ($requireSchema) throw new RuntimeException('Stonefellow connections need the VP3 database upgrade.', 503);
        return [];
    }
    $query = $pdo->prepare('SELECT id,label,site_name,origin,instance_id,created_at
        FROM vp3_stoneward_connections_v1 WHERE owner_user_id=? ORDER BY site_name,origin');
    $query->execute([$owner]);
    return array_map('vp3_stoneward_public_v1', $query->fetchAll(PDO::FETCH_ASSOC));
}

function vp3_stoneward_connection_v1(PDO $pdo, int $owner, string $id): array
{
    if ($owner < 1 || !preg_match('/^[a-f0-9]{32}$/D', $id) || !vp3_stoneward_schema_ready_v1($pdo)) {
        throw new RuntimeException('Stonefellow connection is unavailable.', 404);
    }
    $query = $pdo->prepare('SELECT * FROM vp3_stoneward_connections_v1 WHERE owner_user_id=? AND id=?');
    $query->execute([$owner, $id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Stonefellow connection is unavailable.', 404);
    return $row;
}

function vp3_stoneward_remote_revoke_v1(callable $request, string $token, array $row): bool
{
    try {
        $response = $request(['action'=>'revoke'], $token);
        return vp3_stoneward_grant_response_v1($response, $row) && ($response['revoked'] ?? null) === true;
    } catch (Throwable) {
        return false;
    }
}

function vp3_stoneward_connect_v1(
    PDO $pdo, int $owner, string $origin, string $code, string $label = '', ?callable $request = null,
    ?callable $observeDiscovery = null
): array {
    if ($owner < 1 || !vp3_stoneward_schema_ready_v1($pdo)) {
        throw new RuntimeException('Stonefellow connections need the VP3 database upgrade.', 503);
    }
    $origin = vp3_connected_site_origin_v1($origin, 'Stonefellow');
    $code = trim($code);
    if (!preg_match('/^swlink_[A-Za-z0-9_-]{43}$/D', $code)) {
        throw new InvalidArgumentException('Enter a current one-time pairing code from the Stonefellow site.');
    }
    $label = trim($label);
    if ($label !== '' && vp3_stoneward_text_v1($label, 80) === '') {
        throw new InvalidArgumentException('Use a connection label of up to 80 characters.');
    }
    $query = $pdo->prepare('SELECT id FROM vp3_stoneward_connections_v1 WHERE owner_user_id=? AND origin=?');
    $query->execute([$owner, $origin]);
    if ($query->fetchColumn()) {
        throw new RuntimeException('This Stonefellow domain is already connected. Disconnect it before pairing again.', 409);
    }

    $request ??= vp3_stoneward_transport_v1($origin, pairing:true, observeDiscovery:$observeDiscovery);
    $discovery = vp3_stoneward_discover_v1($request, $origin);
    // Confirm the key is available before consuming the site's one-time code.
    if (ai_master_key(true) === null || (!function_exists('sodium_crypto_secretbox') && !function_exists('openssl_encrypt'))) {
        throw new RuntimeException('VP3 credential encryption is unavailable.', 503);
    }
    $exchange = $request(['action'=>'exchange','code'=>$code]);
    $token = $exchange['accessToken'] ?? null;
    $grant = $exchange['connectionId'] ?? null;
    $row = [
        'id'=>bin2hex(random_bytes(16)), 'owner_user_id'=>$owner, 'instance_id'=>$discovery['instanceId'],
        'remote_connection_id'=>$grant, 'origin'=>$origin, 'label'=>$label, 'site_name'=>$discovery['siteName'],
        'created_at'=>gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if (!vp3_stoneward_grant_response_v1($exchange, $row) || ($exchange['tokenType'] ?? null) !== 'Bearer'
        || !is_string($token) || !preg_match('/^sw2_[A-Za-z0-9_-]{43}$/D', $token)
        || !is_string($grant) || !preg_match('/^[a-f0-9]{32}$/D', $grant)) {
        throw new RuntimeException('The Stonefellow pairing did not match the discovered domain and installation.', 502);
    }
    try {
        if (($exchange['scopes'] ?? null) !== [VP3_STONEWARD_HISTORY_SCOPE_V1]) {
            throw new RuntimeException('Stonefellow returned unsupported connection permissions.', 502);
        }
        $row['token_ciphertext'] = ai_encrypt_secret_with_key($token, vp3_stoneward_token_key_v1($row));
        $pdo->prepare('INSERT INTO vp3_stoneward_connections_v1
            (id,owner_user_id,instance_id,remote_connection_id,origin,label,site_name,created_at,token_ciphertext)
            VALUES (:id,:owner_user_id,:instance_id,:remote_connection_id,:origin,:label,:site_name,:created_at,:token_ciphertext)')
            ->execute($row);
    } catch (Throwable $e) {
        if (!vp3_stoneward_remote_revoke_v1($request, $token, $row)) {
            throw new RuntimeException('VP3 could not save the connection. Revoke the grant on the Stonefellow site before retrying.', 502);
        }
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            throw new RuntimeException('This Stonefellow domain is already connected.', 409);
        }
        throw $e;
    }
    return vp3_stoneward_public_v1($row);
}

function vp3_stoneward_verified_request_v1(array $row, ?callable $request, string $capability=VP3_STONEWARD_HISTORY_SCOPE_V1): callable
{
    $request ??= vp3_stoneward_transport_v1($row['origin']);
    $discovery = vp3_stoneward_discover_v1($request, $row['origin']);
    if ($discovery['instanceId'] !== $row['instance_id']) {
        throw new RuntimeException('The Stonefellow installation changed. Reconnect this artist domain.', 502);
    }
    if (!in_array($capability, $discovery['capabilities'], true)) {
        throw new RuntimeException('Update this Stonefellow artist site to enable playback in VP3.', 501);
    }
    return $request;
}

function vp3_stoneward_disconnect_v1(PDO $pdo, int $owner, string $id, ?callable $request = null): bool
{
    $row = vp3_stoneward_connection_v1($pdo, $owner, $id);
    require_once __DIR__.'/agent-media-stoneward-v1.php';
    $pdo->beginTransaction();
    try {
        if (vp3_connected_sites_report_ready_v1($pdo)) vp3_stoneward_media_lock_owner_v1($pdo,$owner);
        $delete = $pdo->prepare('DELETE FROM vp3_stoneward_connections_v1 WHERE owner_user_id=? AND id=?');
        $delete->execute([$owner, $id]);
        $removed = $delete->rowCount() === 1;
        if ($removed) vp3_stoneward_media_forget_v1($pdo,$owner,$id);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    if (!$removed) return false;
    try {
        $request = vp3_stoneward_verified_request_v1($row, $request);
        return vp3_stoneward_remote_revoke_v1($request, vp3_stoneward_token_v1($row), $row);
    } catch (Throwable) {
        // Local removal is final, even when remote revocation cannot be confirmed.
        return false;
    }
}

function vp3_stoneward_utc_v1(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value) || !preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,6})?(?:Z|\+00:00)$/D', $value, $parts)) {
        return null;
    }
    $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $parts[1], new DateTimeZone('UTC'));
    return $time && $time->format('Y-m-d\TH:i:s') === $parts[1] ? $time : null;
}

function vp3_stoneward_track_id_v1(mixed $id): bool
{
    return is_string($id)&&preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}\z/D',$id)===1;
}

function vp3_stoneward_playback_track_id_v1(array $playback): ?string
{
    $origin=$playback['origin']??null;
    $url=$playback['url']??null;
    if(!is_string($origin)||!is_string($url))return null;
    $prefix=$origin.'/api/stoneward-vp3-audio.php?track_id=';
    if(!str_starts_with($url,$prefix))return null;
    $id=substr($url,strlen($prefix));
    return vp3_stoneward_track_id_v1($id)?$id:null;
}

function vp3_stoneward_playback_response_v1(
    PDO $pdo,int $owner,string $id,array $selector,string $action,?callable $request,?string $requiredCapability=null
): array
{
    $row=vp3_stoneward_connection_v1($pdo,$owner,$id);
    $request=vp3_stoneward_verified_request_v1($row,$request,$requiredCapability??$action);
    $response=$request(['action'=>$action]+$selector,vp3_stoneward_token_v1($row));
    if(vp3_stoneward_connection_v1($pdo,$owner,$id)!==$row){
        throw new RuntimeException('The Stonefellow connection changed during the lookup.',409);
    }
    $asOf=vp3_stoneward_utc_v1($response['asOf']??null);
    if(!vp3_stoneward_grant_response_v1($response,$row)||$asOf===null||abs(time()-$asOf->getTimestamp())>300
        ||!is_array($response['data']??null)){
        throw new RuntimeException('Stonefellow returned unverifiable playback data.',502);
    }
    return [$row['origin'],$response['data']];
}

function vp3_stoneward_playback_track_v1(string $origin,mixed $track): array
{
    $resolvedId=$track['trackId']??null;
    $title=vp3_stoneward_text_v1($track['title']??null,180);
    $artist=$track['artist']??null;
    if(!is_array($track)||!vp3_stoneward_track_id_v1($resolvedId)||$title===''
        ||!is_string($artist)||($artist!==''&&vp3_stoneward_text_v1($artist,160)==='')
        ||($track['audioUrl']??null)!==$origin.'/api/stoneward-vp3-audio.php?track_id='.$resolvedId
        ||($track['mime']??null)!=='audio/mpeg'){
        throw new RuntimeException('Stonefellow returned an unverifiable audio stream.',502);
    }
    return ['title'=>$title,'artist'=>trim($artist),'origin'=>$origin,'url'=>$track['audioUrl'],'mime'=>'audio/mpeg'];
}

/** Resolve one verified public stream. The browser never receives the grant. */
function vp3_stoneward_playable_v1(PDO $pdo,int $owner,string $id,array $selector,?callable $request=null): array
{
    $key=count($selector)===1?array_key_first($selector):null;
    $trackId=$selector['trackId']??$selector['publicTrackId']??null;
    $title=null;
    if(in_array($key,['trackId','publicTrackId'],true)&&vp3_stoneward_track_id_v1($trackId)){
        $field=[$key=>$trackId];
    }elseif($key==='title'&&($title=vp3_stoneward_text_v1($selector['title'],180))!==''){
        $field=['title'=>$title];
    }else{
        throw new InvalidArgumentException('Choose a Stonefellow song by title or from a verified listening record.');
    }
    [$origin,$data]=vp3_stoneward_playback_response_v1($pdo,$owner,$id,$field,VP3_STONEWARD_PLAY_V1,$request,
        $key==='publicTrackId'?VP3_STONEWARD_QUEUE_V1:null);
    $playback=vp3_stoneward_playback_track_v1($origin,$data['track']??null);
    if(($trackId!==null&&$trackId!==$data['track']['trackId'])||($title!==null&&mb_strtolower($title)!==mb_strtolower($playback['title']))){
        throw new RuntimeException('Stonefellow returned a different track.',502);
    }
    return $playback;
}

function vp3_stoneward_catalog_queue_v1(PDO $pdo,int $owner,string $id,?string $seedTrackId=null,?callable $request=null): array
{
    if($seedTrackId!==null&&!vp3_stoneward_track_id_v1($seedTrackId)){
        throw new InvalidArgumentException('Choose a track from this Stonefellow artist site.');
    }
    [$origin,$data]=vp3_stoneward_playback_response_v1($pdo,$owner,$id,
        $seedTrackId===null?[]:['seedTrackId'=>$seedTrackId],VP3_STONEWARD_QUEUE_V1,$request);
    $rows=$data['tracks']??null;
    if(!is_array($rows)||!array_is_list($rows)||count($rows)>3){
        throw new RuntimeException('Stonefellow returned an unverifiable queue.',502);
    }
    $queue=[];$seen=[];
    foreach($rows as $row){
        $playback=vp3_stoneward_playback_track_v1($origin,$row);
        $trackId=$row['trackId'];
        if($trackId===$seedTrackId||isset($seen[$trackId]))throw new RuntimeException('Stonefellow repeated a track in the queue.',502);
        $seen[$trackId]=true;
        $queue[]=$playback;
    }
    return $queue;
}

function vp3_stoneward_history_v1(
    PDO $pdo, int $owner, string $id, int $days = 7, int $limit = 60, ?callable $request = null
): array {
    if ($days < 1 || $days > 31 || $limit < 1 || $limit > 100) {
        throw new InvalidArgumentException('Choose a history window within 31 days and a limit of up to 100 plays.');
    }
    $row = vp3_stoneward_connection_v1($pdo, $owner, $id);
    $request = vp3_stoneward_verified_request_v1($row, $request);
    $response = $request(['action'=>VP3_STONEWARD_HISTORY_SCOPE_V1, 'days'=>$days, 'limit'=>$limit], vp3_stoneward_token_v1($row));
    if (vp3_stoneward_connection_v1($pdo, $owner, $id) !== $row) {
        throw new RuntimeException('The Stonefellow connection changed during the read.', 409);
    }
    $asOf = vp3_stoneward_utc_v1($response['asOf'] ?? null);
    $plays = $response['data']['plays'] ?? null;
    $truncated = $response['data']['truncated'] ?? null;
    if (!vp3_stoneward_grant_response_v1($response, $row) || $asOf === null || abs(time() - $asOf->getTimestamp()) > 300
        || !is_array($plays) || !array_is_list($plays) || count($plays) > $limit || !is_bool($truncated)) {
        throw new RuntimeException('Stonefellow returned unverifiable listening history.', 502);
    }
    $earliest = $asOf->getTimestamp() - $days * 86400;
    $verified = [];
    foreach ($plays as $play) {
        $playId = $play['id'] ?? null;
        $trackId = $play['trackId'] ?? null;
        $title = vp3_stoneward_text_v1($play['title'] ?? null, 200);
        $artist = $play['artist'] ?? null;
        $playedAt = vp3_stoneward_utc_v1($play['playedAt'] ?? null);
        $seconds = $play['listenedSeconds'] ?? null;
        if (!is_array($play) || !is_string($playId) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/iD', $playId) || isset($verified[$playId])
            || !is_string($trackId) || !preg_match('/^[a-z0-9_-]{1,180}$/iD', $trackId)
            || $title === '' || !is_string($artist) || ($artist !== '' && vp3_stoneward_text_v1($artist, 160) === '')
            || $playedAt === null || $playedAt->getTimestamp() < $earliest || $playedAt > $asOf
            || !is_int($seconds) || $seconds < 0) {
            throw new RuntimeException('Stonefellow returned an invalid listening record.', 502);
        }
        $verified[$playId] = [
            'id'=>$playId, 'title'=>$title, 'artist'=>trim($artist),
            'playedAt'=>$playedAt->format('Y-m-d\TH:i:s\Z'), 'listenedSeconds'=>$seconds, 'trackId'=>$trackId,
        ];
    }
    return ['asOf'=>$asOf->format('Y-m-d\TH:i:s\Z'), 'data'=>['plays'=>array_values($verified),'truncated'=>$truncated]];
}
