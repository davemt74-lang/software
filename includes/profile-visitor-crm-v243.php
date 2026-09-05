<?php
declare(strict_types=1);

/**
 * Profile visitor / lightweight CRM identity layer.
 *
 * Guests receive a random first-party browser token. Only an owner-scoped SHA-256
 * derivative is stored in profile_visit_sessions; the raw token never enters the
 * database. This intentionally avoids IP/user-agent fingerprinting while giving
 * Profile Agent enough continuity to recognize repeat browser visits and chats.
 */
const STONEFELLOW_PROFILE_VISITOR_COOKIE_V243 = 'sf_profile_visitor';
const STONEFELLOW_PROFILE_VISITOR_COOKIE_MAX_AGE_V243 = 34560000; // 400 days.
const STONEFELLOW_PROFILE_VISITOR_REENTRY_SECONDS_V243 = 1800; // 30-minute visit boundary.

function profile_visitor_cookie_token_v243(int $ownerUserId): string
{
    $name = STONEFELLOW_PROFILE_VISITOR_COOKIE_V243;
    $token = strtolower(trim((string)($_COOKIE[$name] ?? '')));
    if ((bool)preg_match('/^(?:[a-f0-9]{48}|[a-f0-9]{64})$/D', $token)) return $token;

    // Preserve the current PHP-session visitor identity when possible so the
    // rollout does not split an active guest into two contact rows.
    if ($ownerUserId > 0 && function_exists('profile_session_token')) {
        $legacy = strtolower(trim((string)profile_session_token($ownerUserId)));
        if ((bool)preg_match('/^[a-f0-9]{48}$/D', $legacy)) $token = $legacy;
    }
    if ($token === '') $token = bin2hex(random_bytes(32));

    $_COOKIE[$name] = $token;
    if (!headers_sent()) {
        $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        setcookie($name, $token, [
            'expires' => time() + STONEFELLOW_PROFILE_VISITOR_COOKIE_MAX_AGE_V243,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    return $token;
}

function profile_visitor_session_hash_v243(int $ownerUserId): string
{
    // Keep the legacy hash shape so an active pre-v243 guest can retain its row
    // when the new long-lived cookie is seeded from its current session token.
    return hash('sha256', profile_visitor_cookie_token_v243($ownerUserId) . '|' . $ownerUserId);
}

function profile_visitor_contact_ref_v243(array $row): string
{
    $key = strtolower(trim((string)($row['session_key'] ?? '')));
    if (!(bool)preg_match('/^[a-f0-9]{64}$/D', $key)) return '';
    return 'G-' . strtoupper(substr($key, 0, 6));
}

function profile_visitor_session_visit_count_v243(PDO $pdo, int $ownerUserId, int $profileSessionId): int
{
    if ($ownerUserId < 1 || $profileSessionId < 1) return 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM profile_events WHERE owner_user_id=? AND profile_session_id=? AND event_type='profile_view'");
    $stmt->execute([$ownerUserId, $profileSessionId]);
    return (int)$stmt->fetchColumn();
}

function profile_visitor_request_context_v243(array $session): array
{
    $referrerHost = '';
    $referrer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referrer !== '') {
        $host = strtolower(trim((string)parse_url($referrer, PHP_URL_HOST)));
        $currentHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($host !== '' && $host !== $currentHost) $referrerHost = mb_strimwidth($host, 0, 190, '');
    }

    $path = trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    $out = [
        'contact_ref' => profile_visitor_contact_ref_v243($session),
        'page_view_count' => max(0, (int)($session['view_count'] ?? 0)),
        'entry_path' => mb_strimwidth($path, 0, 255, ''),
        'referrer_host' => $referrerHost,
    ];
    foreach (['utm_source','utm_medium','utm_campaign'] as $key) {
        $value = $_GET[$key] ?? '';
        $out[$key] = is_scalar($value) ? mb_strimwidth(trim((string)$value), 0, 120, '') : '';
    }
    return $out;
}

function profile_visitor_history_v243(PDO $pdo, int $ownerUserId, int $profileSessionId): ?array
{
    if ($ownerUserId < 1 || $profileSessionId < 1) return null;
    $stmt = $pdo->prepare(
        "SELECT s.*,
          (SELECT COUNT(*) FROM profile_events e0
             WHERE e0.owner_user_id=s.owner_user_id AND e0.profile_session_id=s.id AND e0.event_type='profile_view') AS visit_count,
          (SELECT COUNT(*) FROM profile_agent_conversations c
             WHERE c.owner_user_id=s.owner_user_id AND c.profile_session_id=s.id) AS conversation_count,
          (SELECT COUNT(*) FROM profile_agent_messages m
             INNER JOIN profile_agent_conversations c2 ON c2.id=m.conversation_id
             WHERE c2.owner_user_id=s.owner_user_id AND c2.profile_session_id=s.id AND m.sender_type='visitor') AS visitor_message_count,
          (SELECT m2.message FROM profile_agent_messages m2
             INNER JOIN profile_agent_conversations c3 ON c3.id=m2.conversation_id
             WHERE c3.owner_user_id=s.owner_user_id AND c3.profile_session_id=s.id AND m2.sender_type='visitor'
             ORDER BY m2.id DESC LIMIT 1) AS last_visitor_message,
          (SELECT c4.last_message_at FROM profile_agent_conversations c4
             WHERE c4.owner_user_id=s.owner_user_id AND c4.profile_session_id=s.id
             ORDER BY c4.last_message_at DESC,c4.id DESC LIMIT 1) AS last_conversation_at,
          (SELECT c5.status FROM profile_agent_conversations c5
             WHERE c5.owner_user_id=s.owner_user_id AND c5.profile_session_id=s.id
             ORDER BY c5.last_message_at DESC,c5.id DESC LIMIT 1) AS last_conversation_status
         FROM profile_visit_sessions s
         WHERE s.id=? AND s.owner_user_id=? LIMIT 1"
    );
    $stmt->execute([$profileSessionId, $ownerUserId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $pending = $pdo->prepare(
        "SELECT a.summary
         FROM agent_attention_items a
         INNER JOIN profile_events e ON e.id=a.source_event_id
         WHERE a.owner_user_id=? AND e.profile_session_id=?
           AND a.attention_type='needs_owner'
           AND a.status IN ('pending','seen','snoozed')
           AND (a.snoozed_until IS NULL OR a.snoozed_until<=NOW())
         ORDER BY a.id DESC LIMIT 1"
    );
    $pending->execute([$ownerUserId, $profileSessionId]);
    $row['pending_owner_summary'] = trim((string)($pending->fetchColumn() ?: ''));
    return $row;
}

function profile_visitor_owner_label_v243(PDO $pdo, int $ownerUserId, array $history): array
{
    $descriptor = function_exists('profile_runtime_visitor_descriptor')
        ? profile_runtime_visitor_descriptor($pdo, $ownerUserId, $history)
        : [
            'signed_in'=>(int)($history['visitor_user_id'] ?? 0) > 0,
            'identity_disclosed'=>!empty($history['identity_disclosed']),
            'visitor_label'=>(int)($history['visitor_user_id'] ?? 0) > 0 ? 'Signed-in member' : 'Guest visitor',
            'relationship_scope'=>'none',
        ];
    $contactRef = profile_visitor_contact_ref_v243($history);
    if (!empty($descriptor['identity_disclosed'])) {
        $label = trim((string)($descriptor['visitor_label'] ?? '')) ?: 'Signed-in member';
    } elseif (!empty($descriptor['signed_in'])) {
        $label = 'A signed-in member';
    } else {
        $label = $contactRef !== '' ? 'Guest ' . substr($contactRef, 2) : 'A guest visitor';
    }
    return [$label, $descriptor, $contactRef];
}

function profile_visitor_question_text_v243(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('/^Visitor asked:\s*[“\"](.+?)[”\"]$/us', $value, $m)) $value = trim((string)$m[1]);
    return mb_strimwidth($value, 0, 220, '…');
}

/**
 * Build the owner-side Agent Chat turn for a Profile Agent event using the
 * visitor's owner-scoped history. This is deterministic so notifications stay
 * useful even when no model call is available.
 */
function profile_visitor_attention_decision_v243(PDO $pdo, int $ownerUserId, array $notification): ?array
{
    if ($ownerUserId < 1) return null;
    $sourceType = strtolower(trim((string)($notification['source_type'] ?? '')));
    $eventId = max(0, (int)($notification['source_id'] ?? 0));
    if ($sourceType !== 'profile_event' || $eventId < 1) return null;

    $eventStmt = $pdo->prepare(
        'SELECT e.*,s.id AS session_id FROM profile_events e INNER JOIN profile_visit_sessions s ON s.id=e.profile_session_id WHERE e.id=? AND e.owner_user_id=? LIMIT 1'
    );
    $eventStmt->execute([$eventId, $ownerUserId]);
    $event = $eventStmt->fetch();
    if (!$event) return null;

    $history = profile_visitor_history_v243($pdo, $ownerUserId, (int)$event['session_id']);
    if (!$history) return null;
    [$label, $descriptor, $contactRef] = profile_visitor_owner_label_v243($pdo, $ownerUserId, $history);

    $eventType = strtolower(trim((string)$event['event_type']));
    $visits = max(0, (int)($history['visit_count'] ?? 0));
    $pageViews = max(0, (int)($history['view_count'] ?? 0));
    $conversations = max(0, (int)($history['conversation_count'] ?? 0));
    $messages = max(0, (int)($history['visitor_message_count'] ?? 0));
    $lastQuestion = profile_visitor_question_text_v243((string)($history['last_visitor_message'] ?? ''));
    $pendingQuestion = profile_visitor_question_text_v243((string)($history['pending_owner_summary'] ?? ''));
    $relationship = trim((string)($descriptor['relationship_scope'] ?? 'none'));
    $relationshipPhrase = $relationship !== '' && $relationship !== 'none'
        ? ' They are already in your ' . str_replace('_', ' ', $relationship) . ' relationship.'
        : '';

    $agentName = system_agent_name();
    $profileAgentId = max(0, (int)($event['profile_agent_id'] ?? 0));
    if ($profileAgentId > 0) {
        $agent = user_agent_get_v236($pdo, $ownerUserId, $profileAgentId);
        if ($agent) $agentName = trim((string)($agent['display_name'] ?? '')) ?: $agentName;
    }

    $prompt = 'What do you want to do?';
    if ($eventType === 'profile_view') {
        if ($pendingQuestion !== '') {
            $message = $label . ' is back on your profile. They are still waiting on your input after asking “' . $pendingQuestion . '”. ' . $prompt;
        } elseif ($conversations > 0) {
            $message = $label . ' is back on your profile. They have chatted with ' . $agentName . ' before.';
            if ($lastQuestion !== '') $message .= ' Last time they asked “' . $lastQuestion . '”.';
            $message .= $relationshipPhrase . ' Would you like me to pick the conversation back up?';
            $prompt = 'Would you like me to pick the conversation back up?';
        } elseif ($visits <= 1) {
            $message = $label . ' just landed on your profile. This looks like their first visit.' . $relationshipPhrase . ' Would you like me to say hello first?';
            $prompt = 'Would you like me to say hello first?';
        } else {
            $message = $label . ' is back on your profile. This is visit ' . $visits . '.' . $relationshipPhrase . ' Would you like me to say hello?';
            $prompt = 'Would you like me to say hello?';
        }
    } elseif ($eventType === 'conversation_started') {
        if ($conversations <= 1) {
            $message = $label . ' just started their first conversation with ' . $agentName . '. Would you like me to keep handling it, or do you want to join?';
        } else {
            $message = $label . ' started another conversation with ' . $agentName . '. This is conversation ' . $conversations . '.';
            if ($lastQuestion !== '') $message .= ' Their latest message is “' . $lastQuestion . '”.';
            $message .= ' ' . $prompt;
        }
    } elseif ($eventType === 'needs_owner') {
        $question = $pendingQuestion !== '' ? $pendingQuestion : $lastQuestion;
        $message = $label . ' needs your input';
        if ($question !== '') $message .= ' after asking “' . $question . '”';
        $message .= '. I do not have enough approved information to answer accurately. ' . $prompt;
    } else {
        return null;
    }

    return [
        'message'=>mb_strimwidth(trim($message), 0, 1200, '…'),
        'prompt'=>mb_strimwidth(trim($prompt), 0, 240, '…'),
        'contact'=>[
            'profile_session_id'=>(int)$history['id'],
            'contact_ref'=>$contactRef,
            'signed_in'=>!empty($descriptor['signed_in']),
            'identity_disclosed'=>!empty($descriptor['identity_disclosed']),
            'relationship_scope'=>$relationship !== '' ? $relationship : 'none',
            'visit_count'=>$visits,
            'page_view_count'=>$pageViews,
            'conversation_count'=>$conversations,
            'visitor_message_count'=>$messages,
            'first_seen_at'=>(string)($history['first_seen_at'] ?? ''),
            'last_seen_at'=>(string)($history['last_seen_at'] ?? ''),
            'last_message_at'=>(string)($history['last_message_at'] ?? ''),
        ],
    ];
}

function profile_visitor_contact_list_v243(PDO $pdo, int $ownerUserId, int $limit=100): array
{
    if ($ownerUserId < 1) return [];
    $limit = max(1, min(250, $limit));
    $stmt = $pdo->prepare(
        "SELECT s.*,
          COUNT(DISTINCT c.id) AS conversation_count,
          COUNT(DISTINCT CASE WHEN e.event_type='profile_view' THEN e.id END) AS visit_count,
          COUNT(DISTINCT CASE WHEN m.sender_type='visitor' THEN m.id END) AS visitor_message_count,
          MAX(c.last_message_at) AS conversation_last_at
         FROM profile_visit_sessions s
         LEFT JOIN profile_events e ON e.owner_user_id=s.owner_user_id AND e.profile_session_id=s.id
         LEFT JOIN profile_agent_conversations c ON c.owner_user_id=s.owner_user_id AND c.profile_session_id=s.id
         LEFT JOIN profile_agent_messages m ON m.conversation_id=c.id
         WHERE s.owner_user_id=? AND (s.view_count>0 OR c.id IS NOT NULL)
         GROUP BY s.id
         ORDER BY GREATEST(s.last_seen_at,COALESCE(MAX(c.last_message_at),s.last_seen_at)) DESC,s.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$ownerUserId]);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $descriptor = function_exists('profile_runtime_visitor_descriptor')
            ? profile_runtime_visitor_descriptor($pdo, $ownerUserId, $row)
            : [];
        $row = array_merge($row, $descriptor);
        $row['contact_id'] = (int)$row['id'];
        $row['contact_ref'] = profile_visitor_contact_ref_v243($row);
        $row['visit_count'] = (int)($row['visit_count'] ?? 0);
        $row['page_view_count'] = (int)($row['view_count'] ?? 0);
        $row['repeat_visitor'] = $row['visit_count'] > 1;
        $row['conversation_count'] = (int)($row['conversation_count'] ?? 0);
        $row['visitor_message_count'] = (int)($row['visitor_message_count'] ?? 0);
        $row['stage'] = $row['conversation_count'] > 0
            ? (!empty($row['signed_in']) ? 'member_engaged' : 'guest_engaged')
            : ($row['visit_count'] > 1 ? 'returning_visitor' : 'new_visitor');
        unset($row['id'], $row['session_key'], $row['visitor_user_id'], $row['view_count']);
    }
    unset($row);
    return $rows;
}
