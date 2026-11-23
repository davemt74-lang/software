<?php
declare(strict_types=1);

/** Issue a short-lived coturn REST credential only to peers of a sharing session. */
function vp3_phone_camera_ice_v1(?array $camera): array
{
    if (!$camera || !in_array($camera['state'] ?? null,['sharing','answered'],true)) return [];
    $config = $GLOBALS['config']['phone_camera'] ?? [];
    $urls = $config['ice_urls'] ?? [];
    if (!is_array($urls)) return [];
    $secret = $config['turn_shared_secret'] ?? '';
    $session = $camera['session_id'] ?? '';
    if (!is_string($session) || !preg_match('/^[0-9a-f]{32}$/D',$session)) return [];
    $now = time();
    $until = min($now + VP3_PHONE_CAMERA_MAX_SECONDS_V1,(int)($camera['relay_expires_at'] ?? 0));
    if ($until <= $now) return [];
    $servers = [];
    foreach (array_unique(array_filter($urls,'is_string')) as $url) {
        if (!preg_match('~^(stun|stuns|turn|turns):(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?(?:\?transport=(udp|tcp))?$~iD',$url,$match)) continue;
        $server = ['urls'=>$url];
        if (in_array(strtolower($match[1]),['turn','turns'],true)) {
            if (!is_string($secret) || $secret === '') continue;
            $server['username'] = $until.':'.$session;
            $server['credential'] = base64_encode(hash_hmac('sha1',$server['username'],$secret,true));
        }
        $servers[] = $server;
    }
    return $servers;
}
