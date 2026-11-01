<?php
declare(strict_types=1);

/** PH3G: signed Cloud-to-HomeServer SIP handoff. Disabled until explicitly configured. */

function vp3_phone_stream_ready_v150(): bool
{
    return table_exists('phone_voice_stream_routes_v150') && table_exists('phone_voice_stream_claims_v150');
}

function vp3_phone_stream_ensure_v150(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_voice_stream_routes_v150 (
      phone_number_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      owner_user_id BIGINT UNSIGNED NOT NULL,
      cloud_agent_id BIGINT UNSIGNED NOT NULL,
      homeserver_agent_id BIGINT UNSIGNED NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_phone_stream_owner (owner_user_id,phone_number_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_voice_stream_claims_v150 (
      parent_call_sid VARCHAR(40) NOT NULL PRIMARY KEY,
      child_call_sid VARCHAR(40) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_phone_stream_child (child_call_sid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_phone_stream_sip_uri_v150(string $uri): string
{
    $uri=trim($uri);
    if (strlen($uri)>155 || !preg_match('~^sip:[a-zA-Z0-9_.+-]{1,72}@[a-z0-9-]+(?:\.[a-z0-9-]+)*\.sip\.livekit\.cloud;transport=tls$~D',$uri))
        throw new InvalidArgumentException('Use a fixed LiveKit SIP address with ;transport=tls and no query, password or arbitrary domain.');
    return $uri;
}

function vp3_phone_stream_secret_v150(): string
{
    return ai_decrypt_secret((string)setting('phone_voice_stream_worker_secret_v150',''));
}

function vp3_phone_stream_configured_v150(): bool
{
    try {
        vp3_phone_stream_sip_uri_v150((string)setting('phone_voice_stream_sip_uri_v150',''));
        return strlen(vp3_phone_stream_secret_v150())>=32;
    } catch (Throwable) { return false; }
}

/** A Cloud agent and a HomeServer secondary Agent have DIFFERENT identities. */
function vp3_phone_stream_route_v150(PDO $pdo,array $route): array|false
{
    if (!vp3_phone_stream_ready_v150() || !vp3_phone_stream_configured_v150()) return false;
    $q=$pdo->prepare('SELECT homeserver_agent_id FROM phone_voice_stream_routes_v150
      WHERE phone_number_id=? AND owner_user_id=? AND cloud_agent_id=? LIMIT 1');
    $q->execute([(int)$route['phone_number_id'],(int)$route['owner_user_id'],(int)$route['agent_id']]);
    $id=(int)$q->fetchColumn();
    return $id>0 ? ['homeserver_agent_id'=>$id] : false;
}

function vp3_phone_stream_set_v150(PDO $pdo,int $numberId,int $homeAgentId,bool $enabled): void
{
    if (!vp3_phone_stream_ready_v150() || !vp3_phone_voice_ready_v140())
        throw new RuntimeException('Run the PH3G database upgrade first.');
    $q=$pdo->prepare("SELECT owner_user_id,agent_id,voice_enabled FROM phone_cloud_numbers_v100
       WHERE id=? AND provider='twilio' LIMIT 1");
    $q->execute([$numberId]);$number=$q->fetch(PDO::FETCH_ASSOC);
    if (!$number) throw new RuntimeException('Phone number not found.');
    $owner=(int)$number['owner_user_id'];$cloudAgent=(int)$number['agent_id'];
    if ($enabled && ($homeAgentId<1 || $homeAgentId>2147483647
            || !(int)$number['voice_enabled'] || !vp3_phone_stream_configured_v150()
            || !vp3_phone_package_v100($pdo,$owner)['voice']
            || !vp3_phone_agent_owned_v100($pdo,$owner,$cloudAgent)))
        throw new RuntimeException('Configure a SIP trunk, independent worker secret, enabled Cloud number and an authorized HomeServer secondary agent first.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE phone_voice_calls_v130 SET state='completed'
          WHERE phone_number_id=? AND state IN ('conversation_active','streaming_active')")->execute([$numberId]);
        $pdo->prepare('DELETE FROM phone_voice_modes_v140 WHERE phone_number_id=?')->execute([$numberId]);
        $pdo->prepare('DELETE FROM phone_voice_stream_routes_v150 WHERE phone_number_id=?')->execute([$numberId]);
        if ($enabled) $pdo->prepare('INSERT INTO phone_voice_stream_routes_v150
          (phone_number_id,owner_user_id,cloud_agent_id,homeserver_agent_id) VALUES (?,?,?,?)')
          ->execute([$numberId,$owner,$cloudAgent,$homeAgentId]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function vp3_phone_stream_proof_v150(string $secret,string $parent,int $issued,array $route,int $homeAgent): string
{
    return hash_hmac('sha256',implode('|',[$parent,$issued,(int)$route['owner_user_id'],
        (int)$route['agent_id'],$homeAgent,(string)$route['caller_e164'],(string)$route['called_e164']]),$secret);
}

function vp3_phone_stream_dial_v150(string $parent,array $route,int $homeAgent,string $callback,int $issued): string
{
    if (!preg_match('/^CA[a-f0-9]{32}$/iD',$parent)) throw new InvalidArgumentException('Invalid parent call ID.');
    $uri=vp3_phone_stream_sip_uri_v150((string)setting('phone_voice_stream_sip_uri_v150',''));
    $secret=vp3_phone_stream_secret_v150();
    if (strlen($secret)<32) throw new RuntimeException('Phone streaming is not configured.');
    $proof=vp3_phone_stream_proof_v150($secret,$parent,$issued,$route,$homeAgent);
    $uri.='?x-vp3-parent='.$parent.'&x-vp3-issued='.$issued.'&x-vp3-proof='.$proof;
    if (strlen($uri)>255) throw new RuntimeException('SIP routing address exceeds Twilio limits.');
    $action=htmlspecialchars(vp3_phone_voice_sibling_url_v130($callback,'phone-voice-stream-complete-v150.php'),ENT_XML1|ENT_QUOTES,'UTF-8');
    $destination=htmlspecialchars($uri,ENT_XML1|ENT_QUOTES,'UTF-8');
    return '<?xml version="1.0" encoding="UTF-8"?><Response><Dial answerOnBridge="true" timeout="20" action="'.$action.'" method="POST"><Sip>'.$destination.'</Sip></Dial></Response>';
}

/** The worker proves possession of SIP-invite headers minted after signed Twilio ingress. */
function vp3_phone_stream_resolve_v150(PDO $pdo,array $body,string $secret,int $now): array
{
    foreach (['call_sid','parent_call_sid','from_number','to_number','issued','proof'] as $key)
        if (!isset($body[$key]) || !is_scalar($body[$key])) return ['ok'=>false];
    $child=(string)$body['call_sid'];$parent=(string)$body['parent_call_sid'];
    $issued=(string)$body['issued'];$proof=(string)$body['proof'];
    if (!preg_match('/^CA[a-f0-9]{32}$/iD',$child) || !preg_match('/^CA[a-f0-9]{32}$/iD',$parent)
        || !preg_match('/^[0-9]{10}$/D',$issued) || abs($now-(int)$issued)>180
        || !preg_match('/^[a-f0-9]{64}$/D',$proof) || strlen($secret)<32)
        return ['ok'=>false];
    $route=vp3_phone_voice_current_route_v131($pdo,$parent,1800,['streaming_active']);
    if (!$route['accepted']) return ['ok'=>false];
    $stream=vp3_phone_stream_route_v150($pdo,$route);
    if (!$stream) return ['ok'=>false];
    if (!hash_equals((string)$route['caller_e164'],(string)$body['from_number'])
        || !hash_equals((string)$route['called_e164'],(string)$body['to_number'])
        || $route['caller_e164']==='') return ['ok'=>false];
    $homeAgent=(int)$stream['homeserver_agent_id'];
    if (!hash_equals(vp3_phone_stream_proof_v150($secret,$parent,(int)$issued,$route,$homeAgent),$proof))
        return ['ok'=>false];
    try {
        // A different SIP child cannot reuse the same parent/nonce on another leg.
        try {
            $pdo->prepare('INSERT INTO phone_voice_stream_claims_v150 (parent_call_sid,child_call_sid)
              VALUES (?,?)')->execute([$parent,$child]);
        } catch (PDOException) {
            // The unique parent/child claim may already exist. Only its exact
            // original child can use it; an ambiguous database error fails below.
        }
        $q=$pdo->prepare('SELECT child_call_sid FROM phone_voice_stream_claims_v150 WHERE parent_call_sid=? LIMIT 1');
        $q->execute([$parent]);
        if (!hash_equals($child,(string)$q->fetchColumn())) return ['ok'=>false];
    } catch (Throwable) { return ['ok'=>false]; }
    return ['ok'=>true,'call_sid'=>$child,'platform'=>'vp3','agent_id'=>$homeAgent,
        'caller_verified'=>false,'allow_actions'=>false];
}

/** Recheck every spoken turn; a reassigned/disabled number is not a live grant. */
function vp3_phone_stream_active_v150(PDO $pdo,string $child,string $parent): bool
{
    if (!preg_match('/^CA[a-f0-9]{32}$/iD',$child)||!preg_match('/^CA[a-f0-9]{32}$/iD',$parent))
        return false;
    $route=vp3_phone_voice_current_route_v131($pdo,$parent,1800,['streaming_active']);
    if (!$route['accepted'] || !vp3_phone_stream_route_v150($pdo,$route)) return false;
    $q=$pdo->prepare('SELECT child_call_sid FROM phone_voice_stream_claims_v150 WHERE parent_call_sid=? LIMIT 1');
    $q->execute([$parent]);
    $recorded=$q->fetchColumn();
    return is_string($recorded) && hash_equals($recorded,$child);
}

function vp3_phone_stream_complete_v150(PDO $pdo,array $form,string $signature,string $callback,
    string $token,string $accountSid): array
{
    $url=vp3_phone_voice_sibling_url_v130($callback,'phone-voice-stream-complete-v150.php');
    $parent=vp3_phone_voice_check_event_v130($form,$signature,$url,$token,$accountSid);
    $route=vp3_phone_voice_current_route_v131($pdo,$parent,1800,['streaming_active']);
    if (!$route['accepted'] || !vp3_phone_stream_route_v150($pdo,$route)) return ['accepted'=>false];
    $status=(string)($form['DialCallStatus']??'');
    if (!in_array($status,['completed','busy','failed','no-answer','canceled'],true))
        return ['accepted'=>false];
    if ($status==='completed') {
        $pdo->prepare("UPDATE phone_voice_calls_v130 SET state='completed'
          WHERE provider='twilio' AND provider_call_sid=? AND state='streaming_active'")->execute([$parent]);
        return ['accepted'=>true,'twiml'=>'<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>'];
    }
    $pdo->prepare("UPDATE phone_voice_calls_v130 SET state='voicemail_fallback'
      WHERE provider='twilio' AND provider_call_sid=? AND state='streaming_active'")->execute([$parent]);
    return ['accepted'=>true,'twiml'=>vp3_phone_voice_twiml_v130('', $callback)];
}
