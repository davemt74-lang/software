<?php
declare(strict_types=1);

/**
 * Native VP3 profile Agent Radar collection.
 *
 * Known and clearly automated user agents are kept out of the human profile
 * visitor CRM. They are aggregated into owner-scoped Agent CRM contacts and
 * 30-minute Radar sessions instead. No raw IP address or raw User-Agent string
 * is persisted by this layer.
 */
const VP3_RADAR_NATIVE_SESSION_SECONDS = 1800;

function vp3_radar_request_user_agent(): string
{
    return mb_strimwidth(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 1000, '');
}

function vp3_radar_request_path(): string
{
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    return mb_strimwidth($path !== '' ? $path : '/', 0, 500, '');
}

function vp3_radar_request_referrer_host(): string
{
    $referrer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referrer === '') return '';
    $host = strtolower(trim((string)parse_url($referrer, PHP_URL_HOST)));
    $current = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    return ($host !== '' && $host !== $current) ? mb_strimwidth($host, 0, 190, '') : '';
}

function vp3_radar_looks_automated(string $userAgent): bool
{
    if ($userAgent === '') return false;
    return (bool)preg_match(
        '/(?:chatgpt-user|oai-searchbot|gptbot|claude-user|claudebot|claude-searchbot|perplexity-user|perplexitybot|\bbot\b|crawler|spider|slurp|scrapy|headless|python-requests|curl\/|wget\/|httpclient)/i',
        $userAgent
    );
}

function vp3_radar_native_property(PDO $pdo, int $ownerUserId): ?array
{
    if ($ownerUserId < 1 || !vp3_radar_schema_ready($pdo)) return null;
    $stmt = $pdo->prepare("SELECT * FROM vp3_radar_properties WHERE owner_user_id=? AND property_type='native' ORDER BY id LIMIT 1");
    $stmt->execute([$ownerUserId]);
    $row = $stmt->fetch();

    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? 'vp3.me')));
    $host = preg_replace('/:\d+$/', '', $host) ?: 'vp3.me';
    $host = mb_strimwidth($host, 0, 190, '');
    if ($row) {
        if ((string)$row['domain'] !== $host || empty($row['is_active'])) {
            $pdo->prepare("UPDATE vp3_radar_properties SET domain=?,label='VP3 Profile',is_active=1,verified_at=COALESCE(verified_at,NOW()) WHERE id=?")
                ->execute([$host, (int)$row['id']]);
            $row['domain'] = $host;
            $row['label'] = 'VP3 Profile';
            $row['is_active'] = 1;
        }
        return $row;
    }

    $publicKey = sha1('vp3-native-profile|' . $ownerUserId);
    try {
        $insert = $pdo->prepare("INSERT INTO vp3_radar_properties (owner_user_id,property_type,label,domain,public_key,verified_at,is_active) VALUES (?,'native','VP3 Profile',?,?,NOW(),1)");
        $insert->execute([$ownerUserId, $host, $publicKey]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') throw $e;
    }
    $stmt->execute([$ownerUserId]);
    return $stmt->fetch() ?: null;
}

function vp3_radar_native_identity(PDO $pdo, int $ownerUserId, string $userAgent): ?array
{
    if ($ownerUserId < 1 || $userAgent === '') return null;
    $registry = vp3_radar_match_agent($userAgent, $pdo);
    if (!$registry && !vp3_radar_looks_automated($userAgent)) return null;

    $visitorClass = $registry ? (string)$registry['visitor_class'] : 'automated_unknown';
    $displayName = $registry ? (string)$registry['agent_name'] : 'Unrecognized automated agent';
    $operator = $registry ? (string)$registry['operator_name'] : '';
    $verification = $registry ? 'known' : 'unknown';
    $confidence = $registry ? 75 : 40;
    $trust = $registry ? 55 : 20;
    $identityKey = vp3_radar_identity_key($ownerUserId, $registry ?: null, $userAgent);

    $find = $pdo->prepare('SELECT * FROM vp3_agent_contacts WHERE owner_user_id=? AND identity_key=? LIMIT 1');
    $find->execute([$ownerUserId, $identityKey]);
    $existing = $find->fetch();
    $isNew = !$existing;

    $stmt = $pdo->prepare("INSERT INTO vp3_agent_contacts
      (owner_user_id,agent_registry_id,identity_key,display_name,operator_name,contact_type,visitor_class,verification_status,confidence_score,trust_score,risk_score,engagement_score,value_score,cost_score,relationship_status,inferred_intent,intent_confidence,first_seen_at,last_seen_at)
      VALUES (?,?,?,?,?,'agent',?,?,?,?,0,0,0,0,'new','',0,NOW(),NOW())
      ON DUPLICATE KEY UPDATE
        agent_registry_id=VALUES(agent_registry_id),display_name=VALUES(display_name),operator_name=VALUES(operator_name),
        visitor_class=VALUES(visitor_class),verification_status=VALUES(verification_status),confidence_score=GREATEST(confidence_score,VALUES(confidence_score)),
        trust_score=GREATEST(trust_score,VALUES(trust_score)),last_seen_at=NOW(),id=LAST_INSERT_ID(id)");
    $stmt->execute([
        $ownerUserId,
        $registry ? (int)$registry['id'] : null,
        $identityKey,
        mb_strimwidth($displayName, 0, 190, ''),
        mb_strimwidth($operator, 0, 120, ''),
        mb_strimwidth($visitorClass, 0, 40, ''),
        $verification,
        $confidence,
        $trust,
    ]);
    $id = (int)$pdo->lastInsertId();
    if ($id < 1) {
        $find->execute([$ownerUserId, $identityKey]);
        $id = (int)($find->fetchColumn() ?: 0);
    }
    $get = $pdo->prepare('SELECT * FROM vp3_agent_contacts WHERE id=? AND owner_user_id=? LIMIT 1');
    $get->execute([$id, $ownerUserId]);
    $contact = $get->fetch();
    if (!$contact) return null;
    return ['contact'=>$contact,'registry'=>$registry ?: null,'is_new'=>$isNew];
}

function vp3_radar_native_session(PDO $pdo, array $property, array $contact): ?array
{
    $propertyId = (int)($property['id'] ?? 0);
    $ownerUserId = (int)($property['owner_user_id'] ?? 0);
    $contactId = (int)($contact['id'] ?? 0);
    if ($propertyId < 1 || $ownerUserId < 1 || $contactId < 1) return null;

    // We intentionally do not fingerprint agents. Requests from one aggregate
    // Agent CRM identity are grouped into coarse 30-minute activity sessions.
    $bucket = (int)floor(time() / VP3_RADAR_NATIVE_SESSION_SECONDS);
    $sessionKey = hash('sha256', $propertyId . '|' . $contactId . '|' . $bucket);
    $path = vp3_radar_request_path();
    $referrer = vp3_radar_request_referrer_host();

    $stmt = $pdo->prepare("INSERT INTO vp3_radar_sessions
      (property_id,owner_user_id,agent_contact_id,session_key,visitor_type,entry_path,exit_path,referrer_host,request_count,page_view_count,event_count,started_at,last_seen_at)
      VALUES (?,?,?,?,?,?,?,?,1,1,0,NOW(),NOW())
      ON DUPLICATE KEY UPDATE exit_path=VALUES(exit_path),referrer_host=CASE WHEN referrer_host='' THEN VALUES(referrer_host) ELSE referrer_host END,
        request_count=request_count+1,page_view_count=page_view_count+1,last_seen_at=NOW(),id=LAST_INSERT_ID(id)");
    $stmt->execute([
        $propertyId,$ownerUserId,$contactId,$sessionKey,
        mb_strimwidth((string)$contact['visitor_class'],0,40,''),$path,$path,$referrer,
    ]);
    $id = (int)$pdo->lastInsertId();
    $get = $pdo->prepare('SELECT * FROM vp3_radar_sessions WHERE id=? LIMIT 1');
    $get->execute([$id]);
    $session = $get->fetch();
    if (!$session) return null;
    $session['is_new'] = (int)$session['request_count'] === 1;
    return $session;
}

function vp3_radar_native_request_signals(array $session): array
{
    $uri = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));
    $query = strtolower((string)($_SERVER['QUERY_STRING'] ?? ''));
    $requestCount = max(1, (int)($session['request_count'] ?? 1));
    $startedAt = strtotime((string)($session['started_at'] ?? '')) ?: time();
    $minutes = max(1.0, (time() - $startedAt + 60) / 60);
    $rpm = (int)ceil($requestCount / $minutes);
    return [
        'verification_failed'=>false,
        'restricted_probe'=>(bool)preg_match('/(?:\/\.env|\/config(?:\.php)?|\/wp-admin|\/private\/|etc\/passwd|%2e%2e|\.\.\/)/i', $uri),
        'credential_probe'=>(bool)preg_match('/(?:password|passwd|api[_-]?key|secret|token)=/i', $query),
        'injection_pattern'=>(bool)preg_match('/(?:union(?:%20|\s)+select|<script|%3cscript|javascript:|sleep\(|benchmark\()/i', $uri . ' ' . $query),
        'failed_login_burst'=>false,
        'requests_per_minute'=>$rpm,
        'repeat_denied'=>false,
    ];
}

function vp3_radar_native_value_score(string $visitorClass, int $pageViews, int $sessions): int
{
    $base = match ($visitorClass) {
        'ai_user_agent' => 70,
        'ai_search' => 55,
        'ai_crawler' => 35,
        default => 20,
    };
    return min(100, $base + min(20, $pageViews * 2) + min(10, $sessions * 2));
}

function vp3_radar_native_event(PDO $pdo, array $property, array $contact, array $session, bool $isNewContact): ?array
{
    $signals = vp3_radar_native_request_signals($session);
    $risk = max((string)$contact['verification_status'] === 'known' ? 5 : 15, vp3_radar_risk_score($signals));
    $severity = vp3_radar_severity($risk);
    $visitorClass = (string)$contact['visitor_class'];
    $significance = match ($visitorClass) {
        'ai_user_agent' => 80,
        'ai_search' => 60,
        'ai_crawler' => 45,
        default => 35,
    };
    if ($isNewContact) $significance = min(100, $significance + 10);
    if ($risk >= 70) $significance = max($significance, 90);

    $path = vp3_radar_request_path();
    $operator = trim((string)$contact['operator_name']);
    $name = trim((string)$contact['display_name']);
    $summary = ($operator !== '' ? $operator . ' · ' : '') . $name . ' viewed the public VP3 profile.';
    $details = [
        'property_type'=>'native',
        'visitor_class'=>$visitorClass,
        'verification_status'=>(string)$contact['verification_status'],
        'confidence_score'=>(int)$contact['confidence_score'],
        'referrer_host'=>(string)($session['referrer_host'] ?? ''),
        'known_agent'=>(string)$contact['verification_status'] === 'known',
        'signals'=>$signals,
    ];
    $stmt = $pdo->prepare("INSERT INTO vp3_radar_events
      (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at)
      VALUES (?,?,?,?,'agent_profile_view',?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        (int)$property['owner_user_id'],(int)$property['id'],(int)$session['id'],(int)$contact['id'],$severity,$path,
        mb_strimwidth(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),0,12,''),200,$significance,$risk,
        mb_strimwidth($summary,0,500,'…'),json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    ]);
    $eventId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE vp3_radar_sessions SET event_count=event_count+1 WHERE id=?')->execute([(int)$session['id']]);

    $referral = trim((string)($session['referrer_host'] ?? '')) !== '' ? 1 : 0;
    $sessionIncrement = !empty($session['is_new']) ? 1 : 0;
    $pdo->prepare('UPDATE vp3_agent_contacts SET session_count=session_count+?,request_count=request_count+1,page_view_count=page_view_count+1,referral_count=referral_count+?,risk_score=GREATEST(risk_score,?),engagement_score=LEAST(100,20+(page_view_count+1)*5+(session_count+?)*8),value_score=?,cost_score=LEAST(100,5+(request_count+1)),last_seen_at=NOW() WHERE id=?')
        ->execute([$sessionIncrement,$referral,$risk,$sessionIncrement,vp3_radar_native_value_score($visitorClass,(int)$contact['page_view_count']+1,(int)$contact['session_count']+$sessionIncrement),(int)$contact['id']]);

    $get = $pdo->prepare('SELECT * FROM vp3_radar_events WHERE id=? LIMIT 1');
    $get->execute([$eventId]);
    return $get->fetch() ?: null;
}

function vp3_radar_owner_user(PDO $pdo, int $ownerUserId): ?array
{
    $stmt = $pdo->prepare('SELECT id,display_name,email,role,is_active,avatar_path FROM users WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([$ownerUserId]);
    return $stmt->fetch() ?: null;
}

function vp3_radar_sync_agent_memory(PDO $pdo, int $ownerUserId, array $contact, array $event): void
{
    if (!function_exists('agent_brain_v122_upsert_system_memory')) return;
    $user = vp3_radar_owner_user($pdo,$ownerUserId);
    if (!$user) return;

    $stmt=$pdo->prepare('SELECT * FROM vp3_agent_contacts WHERE id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([(int)$contact['id'],$ownerUserId]);
    $fresh=$stmt->fetch()?:$contact;
    $name=trim((string)$fresh['display_name'])?:'Automated agent';
    $operator=trim((string)$fresh['operator_name']);
    $identity=($operator!==''?$operator.' · ':'').$name;
    $text=$identity.' is an Agent Radar contact. Classification: '.str_replace('_',' ',(string)$fresh['visitor_class'])
        .'. Verification: '.(string)$fresh['verification_status'].' at '.(int)$fresh['confidence_score'].'% confidence.'
        .' Seen '.(int)$fresh['session_count'].' session'.((int)$fresh['session_count']===1?'':'s')
        .' and '.(int)$fresh['page_view_count'].' page view'.((int)$fresh['page_view_count']===1?'':'s')
        .'. Current trust '.(int)$fresh['trust_score'].'/100, risk '.(int)$fresh['risk_score'].'/100, value '.(int)$fresh['value_score'].'/100.'
        .' Last activity: '.(string)$event['occurred_at'].' on '.(string)$event['path'].'.';
    agent_brain_v122_upsert_system_memory(
        $user,'agent_radar','agent-radar-contact:'.(int)$fresh['id'],$text,
        [
            'agent_contact_id'=>(int)$fresh['id'],
            'operator_name'=>(string)$fresh['operator_name'],
            'agent_name'=>(string)$fresh['display_name'],
            'visitor_class'=>(string)$fresh['visitor_class'],
            'verification_status'=>(string)$fresh['verification_status'],
            'risk_score'=>(int)$fresh['risk_score'],
            'last_event_id'=>(int)$event['id'],
            'last_seen_at'=>(string)$fresh['last_seen_at'],
            'source'=>'agent_radar',
        ],
        (string)$fresh['verification_status']==='known' ? 0.82 : 0.58
    );
}

function vp3_radar_native_notify(PDO $pdo, array $profile, array $contact, ?array $registry, array $session, array $event): void
{
    $owner = (int)$profile['user_id'];
    $risk = (int)$event['risk_score'];
    $class = (string)$contact['visitor_class'];
    $name = trim((string)$contact['display_name']) ?: 'Automated agent';
    $operator = trim((string)$contact['operator_name']);
    $target = url('/profile-agent.php?tab=visitors');
    $sourceId = (int)$event['id'];

    if (!empty($session['is_new']) || $risk >= 70) vp3_radar_sync_agent_memory($pdo,$owner,$contact,$event);

    if ($risk >= 70) {
        create_notification(
            $owner,'radar_security_action','Agent Radar · High-risk automated activity',
            $name . ' produced a risk score of ' . $risk . '/100 on your public profile. Review the Radar activity before allowing any broader access.',
            $target,'radar_event',$sourceId
        );
        return;
    }

    if (empty($session['is_new'])) return;
    if (in_array($class, ['ai_user_agent','ai_search'], true)) {
        $body = ($operator !== '' ? $operator . ' · ' : '') . str_replace('_',' ',$class) . ' visited your public profile. ';
        $body .= $registry ? 'The signature is known, but has not been cryptographically verified.' : 'The traffic is automated but not yet identified.';
        create_notification($owner,'radar_agent_visit_needs_attention','Agent Radar · ' . $name . ' visited your profile',$body,$target,'radar_event',$sourceId);
        return;
    }

    // Routine crawler/automation activity belongs in the existing Agent Brain
    // operational feed rather than interrupting Main Feed on every session.
    create_notification(
        $owner,'agent_activity_radar_visit','Agent Radar · ' . $name,
        ($operator !== '' ? $operator . ' · ' : '') . str_replace('_',' ',$class) . ' activity recorded on your public profile.',
        $target,'radar_event',$sourceId
    );
}

/**
 * Returns true when the request is recognized as automated and therefore must
 * not also be written into the human profile visitor CRM.
 */
function vp3_radar_record_native_profile_request(PDO $pdo, array $profile, ?array $viewer=null): bool
{
    $owner = (int)($profile['user_id'] ?? 0);
    $userAgent = vp3_radar_request_user_agent();
    $looksAutomated = vp3_radar_looks_automated($userAgent);
    if ($owner < 1 || !$looksAutomated) return false;

    // Keep automated traffic out of human contacts even during a partially
    // upgraded deployment. Collection itself fails open and never breaks profile rendering.
    if (!vp3_radar_schema_ready($pdo)) return true;
    try {
        $identity = vp3_radar_native_identity($pdo,$owner,$userAgent);
        if (!$identity) return true;
        $property = vp3_radar_native_property($pdo,$owner);
        if (!$property) return true;
        $contact = $identity['contact'];
        $session = vp3_radar_native_session($pdo,$property,$contact);
        if (!$session) return true;
        $event = vp3_radar_native_event($pdo,$property,$contact,$session,(bool)$identity['is_new']);
        if ($event) vp3_radar_native_notify($pdo,$profile,$contact,$identity['registry'],$session,$event);
    } catch (Throwable $e) {
        error_log('Agent Radar native profile collection failed: ' . $e->getMessage());
    }
    return true;
}

function vp3_radar_agent_contacts(PDO $pdo, int $ownerUserId, int $limit=100): array
{
    if ($ownerUserId < 1 || !vp3_radar_schema_ready($pdo)) return [];
    $limit = max(1,min(250,$limit));
    $stmt = $pdo->prepare("SELECT c.*,r.slug AS registry_slug,r.purpose AS registry_purpose
      FROM vp3_agent_contacts c LEFT JOIN vp3_agent_registry r ON r.id=c.agent_registry_id
      WHERE c.owner_user_id=? ORDER BY c.last_seen_at DESC,c.id DESC LIMIT {$limit}");
    $stmt->execute([$ownerUserId]);
    return $stmt->fetchAll() ?: [];
}

function vp3_radar_owner_stats(PDO $pdo, int $ownerUserId): array
{
    if ($ownerUserId < 1 || !vp3_radar_schema_ready($pdo)) return ['agent_contacts'=>0,'events_24h'=>0,'high_risk_24h'=>0];
    $stmt = $pdo->prepare("SELECT
      (SELECT COUNT(*) FROM vp3_agent_contacts WHERE owner_user_id=?) AS agent_contacts,
      (SELECT COUNT(*) FROM vp3_radar_events WHERE owner_user_id=? AND occurred_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)) AS events_24h,
      (SELECT COUNT(*) FROM vp3_radar_events WHERE owner_user_id=? AND severity IN ('high','critical') AND occurred_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)) AS high_risk_24h");
    $stmt->execute([$ownerUserId,$ownerUserId,$ownerUserId]);
    $row = $stmt->fetch() ?: [];
    return ['agent_contacts'=>(int)($row['agent_contacts']??0),'events_24h'=>(int)($row['events_24h']??0),'high_risk_24h'=>(int)($row['high_risk_24h']??0)];
}

function vp3_radar_query_intent(string $query): bool
{
    $q = mb_strtolower($query);
    foreach (['agent radar','radar','agent visitor','ai visitor','bot','crawler','crawl','website traffic','profile traffic','who visited','visited my profile','security traffic','suspicious traffic'] as $needle) {
        if (str_contains($q,$needle)) return true;
    }
    return false;
}

function vp3_radar_agent_brain_context(PDO $pdo, int $ownerUserId, string $query, int $limit=12): ?array
{
    if ($ownerUserId < 1 || !vp3_radar_query_intent($query) || !vp3_radar_schema_ready($pdo)) return null;
    $limit=max(1,min(20,$limit));
    $stmt=$pdo->prepare("SELECT e.occurred_at,e.severity,e.path,e.significance_score,e.risk_score,c.display_name,c.operator_name,c.visitor_class,c.verification_status,c.session_count,c.page_view_count,c.last_seen_at
      FROM vp3_radar_events e INNER JOIN vp3_agent_contacts c ON c.id=e.agent_contact_id
      WHERE e.owner_user_id=? ORDER BY e.occurred_at DESC,e.id DESC LIMIT {$limit}");
    $stmt->execute([$ownerUserId]);
    $rows=$stmt->fetchAll()?:[];
    if(!$rows)return ['source'=>'agent-radar','title'=>'Agent Radar activity','text'=>'No automated agent activity has been recorded yet.'];
    $lines=[];
    foreach($rows as $row){
        $identity=trim((string)$row['operator_name']);
        $identity.=($identity!==''?' · ':'').trim((string)$row['display_name']);
        $lines[]='['.(string)$row['occurred_at'].'] '.$identity.' · '.str_replace('_',' ',(string)$row['visitor_class']).' · '.(string)$row['verification_status'].' · risk '.(int)$row['risk_score'].'/100 · '.(string)$row['path'];
    }
    return ['source'=>'agent-radar','title'=>'Recent Agent Radar activity','text'=>implode("\n",$lines)];
}