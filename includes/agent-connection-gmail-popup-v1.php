<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connections-v1.php';

const VP3_GMAIL_POPUP_SESSION_V1 = 'connection_gmail_popup_v1';

function agent_connection_gmail_popup_csrf_v1(array $post, array $session): bool
{
    $expected = $session['csrf_token'] ?? null;
    $supplied = $post['csrf_token'] ?? null;
    return is_string($expected) && $expected !== '' && is_string($supplied) && hash_equals($expected,$supplied);
}

/** Issue a short-lived attempt only after the member submits their Gmail preferences. */
function agent_connection_gmail_popup_prepare_v1(PDO $pdo, int $ownerId, array $post, array &$session, ?int $now = null): array
{
    if ($ownerId < 1 || ($post['action'] ?? '') !== 'prepare' || ($post['provider'] ?? '') !== 'gmail'
        || !agent_connection_gmail_popup_csrf_v1($post,$session)) {
        throw new AgentConnectionExceptionV1('The Gmail request could not be verified.',400);
    }
    $callback = agent_connections_callback_v1();
    $origin = agent_connections_gmail_popup_origin_v1($callback);
    $fingerprint = agent_connections_client_fingerprint_v1();
    $client = agent_connection_gmail_config_v1()['client_id'];
    if (!hash_equals($fingerprint,hash('sha256',$client))) {
        throw new AgentConnectionExceptionV1('Gmail setup changed during authorization.',409);
    }

    $current = agent_connections_find_v1($pdo,$ownerId,'gmail');
    $grantKey = (string)($current['authorization_key'] ?? '');
    if ($current && $current['status'] !== 'disconnected') {
        $revision = $post['revision'] ?? null;
        $id = filter_var($post['connection_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id !== (int)$current['id']
            || !is_string($revision) || !hash_equals(hash('sha256',$grantKey),$revision)) {
            throw new AgentConnectionExceptionV1('This connection changed. Open Gmail and try again.',409);
        }
    } elseif (isset($post['connection_id']) || isset($post['revision'])) {
        throw new AgentConnectionExceptionV1('This connection changed. Open Gmail and try again.',409);
    }

    $read = ($post['allow_read'] ?? '') === '1';
    $attempt = bin2hex(random_bytes(32));
    $session[VP3_GMAIL_POPUP_SESSION_V1] = [
        'attempt'=>$attempt,
        'owner_id'=>$ownerId,
        'expires'=>($now ?? time()) + 600,
        'callback'=>$callback,
        'origin'=>$origin,
        'fingerprint'=>$fingerprint,
        'authorization_key'=>$grantKey,
        'policy'=>[
            'read'=>$read,
            'send'=>($post['allow_send'] ?? '') === '1',
            'followups'=>$read && ($post['allow_followups'] ?? '') === '1',
        ],
    ];
    return [
        'client_id'=>$client,
        'origin'=>$origin,
        'scope'=>'openid email '.VP3_GMAIL_READ_SCOPE_V1.' '.VP3_GMAIL_SEND_SCOPE_V1,
        'attempt'=>$attempt,
        'csrf_token'=>$session['csrf_token'],
        'endpoint'=>url('/connection-gmail-popup.php'),
        'return_url'=>url('/connections.php?app=gmail#connections-app-gmail'),
    ];
}

/** Validate the browser request and consume its exact attempt before exchanging the code. */
function agent_connection_gmail_popup_finish_v1(PDO $pdo, int $ownerId, array $post, array $server, array &$session,
    ?callable $exchange = null, ?int $now = null): void
{
    $attempt = $session[VP3_GMAIL_POPUP_SESSION_V1] ?? null;
    $nonce = $post['attempt'] ?? null;
    $code = $post['code'] ?? null;
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST'
        || !preg_match('/\Aapplication\/x-www-form-urlencoded(?:\s*;\s*charset=UTF-8)?\z/iD',(string)($server['CONTENT_TYPE'] ?? ''))
        || ($server['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XmlHttpRequest'
        || array_diff(array_keys($post),['action','attempt','csrf_token','code'])
        || ($post['action'] ?? '') !== 'finish'
        || !agent_connection_gmail_popup_csrf_v1($post,$session)
        || $ownerId < 1 || !is_array($attempt) || $attempt['owner_id'] !== $ownerId
        || ($server['HTTP_ORIGIN'] ?? '') !== $attempt['origin']
        || (isset($server['HTTP_SEC_FETCH_SITE']) && $server['HTTP_SEC_FETCH_SITE'] !== 'same-origin')
        || $attempt['expires'] <= ($now ?? time())
        || !is_string($nonce) || !hash_equals($attempt['attempt'],$nonce)
        || !is_string($code) || !preg_match('/\A[!-~]{1,2048}\z/D',$code)) {
        throw new AgentConnectionExceptionV1('The Gmail request expired or could not be verified.',400);
    }
    unset($session[VP3_GMAIL_POPUP_SESSION_V1]);

    if ($attempt['callback'] !== agent_connections_callback_v1()
        || !hash_equals($attempt['fingerprint'],agent_connections_client_fingerprint_v1())) {
        throw new AgentConnectionExceptionV1('Gmail setup changed during authorization.',409);
    }
    $current = agent_connections_find_v1($pdo,$ownerId,'gmail');
    if (!hash_equals($attempt['authorization_key'],(string)($current['authorization_key'] ?? ''))) {
        throw new AgentConnectionExceptionV1('This connection changed. Open Gmail and try again.',409);
    }
    $token = ($exchange ?? 'agent_connection_gmail_exchange_popup_v1')($attempt['origin'],$code);
    agent_connections_store_gmail_v1($ownerId,$token,$attempt['policy'],$attempt['authorization_key'],$attempt['fingerprint'],$attempt['callback'],true);
}
