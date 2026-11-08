<?php
declare(strict_types=1);

const VP3_GMAIL_API_V1 = 'https://gmail.googleapis.com/gmail/v1/users/me';
const VP3_GMAIL_READ_SCOPE_V1 = 'https://www.googleapis.com/auth/gmail.readonly';
const VP3_GMAIL_SEND_SCOPE_V1 = 'https://www.googleapis.com/auth/gmail.send';

class AgentConnectionGmailExceptionV1 extends RuntimeException
{
    public function __construct(string $message, int $status = 0, public readonly string $providerError = '')
    {
        parent::__construct($message, $status);
    }
}

/** Gmail may have accepted a message even when VP3 cannot verify the receipt. */
final class AgentConnectionGmailSendUnknownV1 extends AgentConnectionGmailExceptionV1 {}

function agent_connection_gmail_enabled_v1(): bool
{
    if (!function_exists('db')) return true;
    $pdo = db();
    if (!$pdo) return false;
    try {
        return $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'connections_gmail_enabled'")->fetchColumn() !== '0';
    } catch (PDOException $e) {
        return false;
    }
}

/** Server-only settings. Keep the credential out of API responses and logs. */
function agent_connection_gmail_config_v1(): array
{
    global $config;
    if (function_exists('db') && ($pdo = db())) {
        try {
            $saved = $pdo->query("SELECT setting_key, setting_value FROM settings
                WHERE setting_key IN ('connections_gmail_client_id', 'connections_gmail_client_secret')")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            return ['client_id' => '', 'client_secret' => '', 'source' => 'none'];
        }
        if ($saved) {
            return [
                'client_id' => trim((string)($saved['connections_gmail_client_id'] ?? '')),
                'client_secret' => function_exists('ai_decrypt_secret')
                    ? ai_decrypt_secret((string)($saved['connections_gmail_client_secret'] ?? '')) : '',
                'source' => 'saved',
            ];
        }
    }
    $gmail = $config['connections']['gmail'] ?? [];
    $clientId = trim((string)(getenv('VP3_GMAIL_CLIENT_ID') ?: ($gmail['client_id'] ?? '')));
    $secret = trim((string)(getenv('VP3_GMAIL_CLIENT_SECRET') ?: ($gmail['client_secret'] ?? '')));
    return ['client_id' => $clientId, 'client_secret' => $secret, 'source' => $clientId !== '' || $secret !== '' ? 'config' : 'none'];
}

function agent_connection_gmail_ready_v1(): bool
{
    $config = agent_connection_gmail_config_v1();
    return agent_connection_gmail_enabled_v1()
        && $config['client_id'] !== '' && $config['client_secret'] !== '' && function_exists('curl_init');
}

function agent_connection_gmail_client_v1(): array
{
    $config = agent_connection_gmail_config_v1();
    if (!agent_connection_gmail_enabled_v1() || $config['client_id'] === '' || $config['client_secret'] === '') {
        throw new AgentConnectionGmailExceptionV1('Gmail is not enabled and configured for this installation.');
    }
    return ['client_id' => $config['client_id'], 'client_secret' => $config['client_secret']];
}

/** The supplied callback comes from trusted installation configuration. */
function agent_connection_gmail_oauth_url_v1(string $redirectUri, string $state, string $challenge): string
{
    $config = agent_connection_gmail_client_v1();
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $config['client_id'],
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email ' . VP3_GMAIL_READ_SCOPE_V1 . ' ' . VP3_GMAIL_SEND_SCOPE_V1,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986);
}

function agent_connection_gmail_http_v1(string $method, string $url, array $headers, ?array $body, bool $form): array
{
    if (!function_exists('curl_init')) {
        throw new AgentConnectionGmailExceptionV1('Gmail requires the PHP cURL extension.');
    }
    $curl = curl_init($url);
    $raw = '';
    $options = [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '',
        CURLOPT_NETRC => CURL_NETRC_IGNORED,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERAGENT => 'VP3-Connections/1',
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$raw): int {
            if (strlen($raw) + strlen($chunk) > 2097152) return 0;
            $raw .= $chunk;
            return strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $options[CURLOPT_PROTOCOLS_STR] = 'HTTPS';
    } else {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json');
        $options[CURLOPT_POSTFIELDS] = $form
            ? http_build_query($body, '', '&', PHP_QUERY_RFC3986)
            : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    try {
        if (!curl_setopt_array($curl, $options) || curl_exec($curl) === false) {
            throw new AgentConnectionGmailExceptionV1('Gmail could not complete the provider request.');
        }
        return ['status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'json' => json_decode($raw, true)];
    } finally {
        curl_close($curl);
    }
}

function agent_connection_gmail_request_v1(
    string $method, string $url, string $accessToken, ?array $body, bool $form, ?callable $http
): array {
    $headers = ['Accept: application/json'];
    if ($accessToken !== '') {
        if (!agent_connection_gmail_valid_token_v1($accessToken)) {
            throw new AgentConnectionGmailExceptionV1('The Gmail access token is invalid.', 401);
        }
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }
    $response = ($http ?? 'agent_connection_gmail_http_v1')($method, $url, $headers, $body, $form);
    $status = (int)($response['status'] ?? 0);
    $json = $response['json'] ?? null;
    if ($status < 200 || $status >= 300) {
        $code = is_array($json) ? ($json['error'] ?? '') : '';
        $known = in_array($code, ['invalid_grant', 'invalid_client', 'invalid_scope', 'unauthorized_client'], true) ? $code : '';
        $message = match ($status) {
            401 => 'Gmail authorization expired or was revoked.',
            403 => 'Gmail denied this request. Check the connected account permissions.',
            404 => 'Gmail could not find the requested item.',
            429 => 'Gmail is limiting requests. Try again later.',
            default => 'Gmail could not complete the provider request.',
        };
        throw new AgentConnectionGmailExceptionV1($message, $status, $known);
    }
    if (!is_array($json) && !str_ends_with($url, '/revoke')) {
        throw new AgentConnectionGmailExceptionV1('Gmail returned an unreadable provider response.');
    }
    return is_array($json) ? $json : [];
}

function agent_connection_gmail_valid_token_v1(string $token): bool
{
    return preg_match('/\A[!-~]{1,8192}\z/D', $token) === 1;
}

/** Exchange a code and verify the Google subject and the mailbox belong to that grant. */
function agent_connection_gmail_exchange_v1(string $redirectUri, string $code, string $verifier, ?callable $http = null): array
{
    return agent_connection_gmail_code_grant_v1($redirectUri, $code, $verifier, $http);
}

/** GIS popup authorization uses the page origin for the server-side code exchange. */
function agent_connection_gmail_exchange_popup_v1(string $origin, string $code, ?callable $http = null): array
{
    return agent_connection_gmail_code_grant_v1($origin, $code, null, $http);
}

function agent_connection_gmail_code_grant_v1(string $redirectUri, string $code, ?string $verifier, ?callable $http): array
{
    $parameters = agent_connection_gmail_client_v1() + ['code' => $code];
    if ($verifier !== null) $parameters['code_verifier'] = $verifier;
    $parameters += ['redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code'];
    $token = agent_connection_gmail_request_v1('POST', 'https://oauth2.googleapis.com/token', '', $parameters, true, $http);
    $grant = agent_connection_gmail_grant_v1($token);
    if (!in_array(VP3_GMAIL_READ_SCOPE_V1, $grant['scopes'], true)
        || !in_array(VP3_GMAIL_SEND_SCOPE_V1, $grant['scopes'], true)) {
        throw new AgentConnectionGmailExceptionV1('Approve Gmail read and send access to connect this mailbox.');
    }
    if ($verifier !== null && $grant['refresh_token'] === '') {
        throw new AgentConnectionGmailExceptionV1('Google did not grant offline Gmail access. Reconnect and approve access.');
    }
    $identity = agent_connection_gmail_request_v1('GET', 'https://openidconnect.googleapis.com/v1/userinfo', $grant['access_token'], null, false, $http);
    $mailbox = agent_connection_gmail_request_v1('GET', VP3_GMAIL_API_V1 . '/profile', $grant['access_token'], null, false, $http);
    $subject = (string)($identity['sub'] ?? '');
    $email = (string)($mailbox['emailAddress'] ?? '');
    if (preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $subject) !== 1
        || ($identity['email_verified'] ?? false) !== true
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strcasecmp((string)($identity['email'] ?? ''), $email) !== 0) {
        throw new AgentConnectionGmailExceptionV1('Google did not verify the Gmail account identity.');
    }
    return $grant + ['external_account_id' => $subject, 'account_email' => $email];
}

function agent_connection_gmail_grant_v1(array $token): array
{
    $access = (string)($token['access_token'] ?? '');
    $refresh = (string)($token['refresh_token'] ?? '');
    $expiresIn = filter_var($token['expires_in'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!agent_connection_gmail_valid_token_v1($access) || $expiresIn === false
        || ($refresh !== '' && !agent_connection_gmail_valid_token_v1($refresh))) {
        throw new AgentConnectionGmailExceptionV1('Google returned an invalid Gmail credential.');
    }
    return [
        'access_token' => $access,
        'refresh_token' => $refresh,
        'expires_in' => min($expiresIn, 86400),
        'scopes' => preg_split('/\s+/', trim((string)($token['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [],
    ];
}

function agent_connection_gmail_refresh_v1(string $refreshToken, ?callable $http = null): array
{
    if (!agent_connection_gmail_valid_token_v1($refreshToken)) {
        throw new AgentConnectionGmailExceptionV1('Reconnect Gmail to renew the connection.', 401);
    }
    $token = agent_connection_gmail_request_v1('POST', 'https://oauth2.googleapis.com/token', '', agent_connection_gmail_client_v1() + [
        'refresh_token' => $refreshToken, 'grant_type' => 'refresh_token',
    ], true, $http);
    $grant = agent_connection_gmail_grant_v1($token);
    if ($grant['scopes'] && (!in_array(VP3_GMAIL_READ_SCOPE_V1, $grant['scopes'], true)
        || !in_array(VP3_GMAIL_SEND_SCOPE_V1, $grant['scopes'], true))) {
        throw new AgentConnectionGmailExceptionV1('Gmail access has changed. Reconnect Gmail to restore permissions.', 401);
    }
    return $grant;
}

function agent_connection_gmail_revoke_v1(string $token, ?callable $http = null): void
{
    if (agent_connection_gmail_valid_token_v1($token)) {
        agent_connection_gmail_request_v1('POST', 'https://oauth2.googleapis.com/revoke', '', ['token' => $token], true, $http);
    }
}

function agent_connection_gmail_api_v1(
    string $accessToken, string $path, ?array $body = null, ?callable $http = null
): array {
    if (!agent_connection_gmail_enabled_v1()) {
        throw new AgentConnectionGmailExceptionV1('Gmail is paused for this installation.', 403);
    }
    if (!agent_connection_gmail_valid_token_v1($accessToken)) {
        throw new AgentConnectionGmailExceptionV1('The Gmail access token is invalid.', 401);
    }
    return agent_connection_gmail_request_v1($body === null ? 'GET' : 'POST', VP3_GMAIL_API_V1 . $path, $accessToken, $body, false, $http);
}

function agent_connection_gmail_id_v1(mixed $value): string
{
    return is_string($value) && preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $value) === 1 ? $value : '';
}

function agent_connection_gmail_metadata_query_v1(): string
{
    $headers = ['From', 'To', 'Subject', 'Message-ID', 'In-Reply-To', 'References', 'Authentication-Results'];
    return 'format=metadata&metadataHeaders=' . implode('&metadataHeaders=', $headers);
}

function agent_connection_gmail_message_v1(array $message): array
{
    $id = agent_connection_gmail_id_v1($message['id'] ?? '');
    $thread = agent_connection_gmail_id_v1($message['threadId'] ?? '');
    if ($id === '' || $thread === '') {
        throw new AgentConnectionGmailExceptionV1('Gmail returned invalid message identifiers.');
    }
    $headers = [];
    foreach ((array)($message['payload']['headers'] ?? []) as $header) {
        if (!is_array($header)) continue;
        $name = strtolower((string)($header['name'] ?? ''));
        $headers[$name] ??= trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string)($header['value'] ?? '')) ?? '');
    }
    return [
        'message_id' => $id,
        'thread_id' => $thread,
        'labels' => array_values(array_filter((array)($message['labelIds'] ?? []), 'is_string')),
        'from' => mb_substr($headers['from'] ?? '', 0, 500),
        'to' => mb_substr($headers['to'] ?? '', 0, 500),
        'subject' => mb_substr($headers['subject'] ?? '', 0, 500),
        'rfc_message_id' => mb_substr($headers['message-id'] ?? '', 0, 500),
        'in_reply_to' => mb_substr($headers['in-reply-to'] ?? '', 0, 2000),
        'references' => mb_substr($headers['references'] ?? '', 0, 4000),
        'authentication_results' => mb_substr($headers['authentication-results'] ?? '', 0, 2000),
        'received_at_ms' => (int)($message['internalDate'] ?? 0),
        'snippet' => mb_substr((string)($message['snippet'] ?? ''), 0, 500),
    ];
}

function agent_connection_gmail_get_v1(string $accessToken, string $id, bool $full, ?callable $http): array
{
    if (agent_connection_gmail_id_v1($id) === '') {
        throw new AgentConnectionGmailExceptionV1('Choose a valid Gmail message.', 400);
    }
    $message = agent_connection_gmail_api_v1(
        $accessToken, '/messages/' . $id . '?' . ($full ? 'format=full' : agent_connection_gmail_metadata_query_v1()), null, $http
    );
    if (($message['id'] ?? null) !== $id) {
        throw new AgentConnectionGmailExceptionV1('Gmail returned a different message.');
    }
    return $message;
}

function agent_connection_gmail_search_v1(string $accessToken, string $query, int $limit = 5, ?callable $http = null): array
{
    $limit = max(1, min(10, $limit));
    $query = http_build_query(['q' => mb_substr(trim($query), 0, 500), 'maxResults' => $limit], '', '&', PHP_QUERY_RFC3986);
    $listed = agent_connection_gmail_api_v1($accessToken, '/messages?' . $query, null, $http);
    $messages = [];
    foreach (array_slice((array)($listed['messages'] ?? []), 0, $limit) as $row) {
        $id = agent_connection_gmail_id_v1($row['id'] ?? '');
        if ($id === '') continue;
        try {
            $messages[] = agent_connection_gmail_message_v1(agent_connection_gmail_get_v1($accessToken, $id, false, $http));
        } catch (AgentConnectionGmailExceptionV1 $e) {
            if ($e->getCode() !== 404) throw $e; // A message can be deleted between list and get.
        }
    }
    return $messages;
}

/** A bare mailbox that can be placed safely inside a quoted Gmail search term. */
function agent_connection_gmail_sender_v1(string $input): string
{
    $sender = strtolower(trim($input));
    return strlen($sender) <= 254 && strpbrk($sender,"\"\\ \t\n\r") === false
        && filter_var($sender,FILTER_VALIDATE_EMAIL) ? $sender : '';
}

/** Return one mailbox from a bare address or a single RFC 5322 display name and address. */
function agent_connection_gmail_address_v1(mixed $header): string
{
    if (!is_string($header) || strpbrk($header,"\r\n") !== false) return '';
    $value = trim($header);
    if (preg_match('/\A[^<>]*<([^<>]+)>\z/D',$value,$matches)) $value = trim($matches[1]);
    return strlen($value)<=254 && filter_var($value,FILTER_VALIDATE_EMAIL) ? strtolower($value) : '';
}

function agent_connection_gmail_search_sender_v1(string $accessToken, string $sender, int $limit=5, ?callable $http=null, int $afterMs=0): array
{
    if ($sender === '' || agent_connection_gmail_sender_v1($sender) !== $sender) {
        throw new AgentConnectionGmailExceptionV1('Enter one exact sender email address.',400);
    }
    $query = 'from:"'.$sender.'" -in:sent -in:drafts';
    // Gmail filters dates in whole seconds. Verify the exact millisecond cutoff locally.
    if ($afterMs > 0) $query .= ' after:'.max(1,intdiv($afterMs,1000)-1);
    $matches = array_filter(agent_connection_gmail_search_v1($accessToken,$query,10,$http),
        static function (array $message) use ($sender,$afterMs): bool {
            if (agent_connection_gmail_address_v1($message['from']) !== $sender
                || array_intersect(['SENT','DRAFT','TRASH','SPAM'],$message['labels'])) return false;
            if ($afterMs === 0) return true;
            $auth = $message['authentication_results'];
            return $message['received_at_ms'] > $afterMs && $message['received_at_ms'] <= (int)floor(microtime(true)*1000)
                && !(preg_match('/\Amx\.google\.com\s*;/i',$auth) && preg_match('/;\s*dmarc\s*=\s*fail\b/i',$auth));
        });
    if ($afterMs > 0) {
        usort($matches,static fn(array $a,array $b): int =>
            [$a['received_at_ms'],$a['message_id']] <=> [$b['received_at_ms'],$b['message_id']]);
    }
    return array_slice($matches,0,max(1,min(10,$limit)));
}

function agent_connection_gmail_body_parts_v1(array $part, array &$plain, array &$html, int $depth = 0): void
{
    $mime = strtolower((string)($part['mimeType'] ?? ''));
    if ($depth > 15 || !empty($part['filename']) || $mime === 'message/rfc822') return;
    $encoded = (string)($part['body']['data'] ?? '');
    if ($encoded !== '' && in_array($mime, ['text/plain', 'text/html'], true)) {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded !== false) {
            if (!mb_check_encoding($decoded, 'UTF-8')) $decoded = mb_scrub($decoded, 'UTF-8');
            if ($mime === 'text/plain') $plain[] = $decoded;
            else $html[] = $decoded;
        }
    }
    foreach ((array)($part['parts'] ?? []) as $child) {
        if (is_array($child)) agent_connection_gmail_body_parts_v1($child, $plain, $html, $depth + 1);
    }
}

function agent_connection_gmail_read_v1(string $accessToken, string $messageId, ?callable $http = null): array
{
    $message = agent_connection_gmail_get_v1($accessToken, $messageId, true, $http);
    $plain = $html = [];
    agent_connection_gmail_body_parts_v1((array)($message['payload'] ?? []), $plain, $html);
    $body = implode("\n", $plain);
    if (trim($body) === '' && $html) {
        $body = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1\s*>~is', '', implode("\n", $html)) ?? '';
        $body = preg_replace('~<br\s*/?>|</(?:p|div|li|tr|h[1-6])\s*>~i', "\n", $body) ?? '';
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $body = trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $body) ?? '');
    $result = agent_connection_gmail_message_v1($message);
    return $result + ['body' => mb_substr($body === '' ? $result['snippet'] : $body, 0, 8000)];
}

function agent_connection_gmail_thread_v1(string $accessToken, string $threadId, ?callable $http = null): array
{
    if (agent_connection_gmail_id_v1($threadId) === '') {
        throw new AgentConnectionGmailExceptionV1('Choose a valid Gmail thread.', 400);
    }
    $thread = agent_connection_gmail_api_v1($accessToken, '/threads/' . $threadId . '?' . agent_connection_gmail_metadata_query_v1(), null, $http);
    if (($thread['id'] ?? null) !== $threadId || !is_array($thread['messages'] ?? null)) {
        throw new AgentConnectionGmailExceptionV1('Gmail returned an invalid thread.');
    }
    $messages = [];
    foreach ($thread['messages'] as $message) {
        if (!is_array($message) || ($message['threadId'] ?? null) !== $threadId) {
            throw new AgentConnectionGmailExceptionV1('Gmail returned a message from a different thread.');
        }
        $messages[] = agent_connection_gmail_message_v1($message);
    }
    return $messages;
}

function agent_connection_gmail_rfc_message_id_v1(string $operationKey): string
{
    if ($operationKey === '') throw new InvalidArgumentException('A send operation key is required.');
    return '<vp3-' . hash('sha256', $operationKey) . '@vp3.me>';
}

function agent_connection_gmail_send_v1(
    string $accessToken, string $from, string $to, string $subject, string $body, string $operationKey, ?callable $http = null
): array {
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)
        || preg_match('/[\x00-\x1f\x7f]/', $from . $to . $subject) !== 0
        || trim($subject) === '' || strlen($subject) > 980 || !mb_check_encoding($subject, 'UTF-8')
        || trim($body) === '' || strlen($body) > 80000 || !mb_check_encoding($body, 'UTF-8')) {
        throw new AgentConnectionGmailExceptionV1('Enter a valid recipient, subject, and message.', 400);
    }
    $rfcId = agent_connection_gmail_rfc_message_id_v1($operationKey);
    $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n", strlen('Subject: '));
    $normalizedBody = preg_replace('/\r\n|\r|\n/', "\r\n", $body) ?? $body;
    $mime = implode("\r\n", [
        'Date: ' . gmdate(DATE_RFC2822), 'From: ' . $from, 'To: ' . $to, 'Subject: ' . $subject, 'Message-ID: ' . $rfcId,
        'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64',
        '', chunk_split(base64_encode($normalizedBody)),
    ]);
    $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
    try {
        $sent = agent_connection_gmail_api_v1($accessToken, '/messages/send', ['raw' => $raw], $http);
        $receipt = agent_connection_gmail_message_v1($sent);
    } catch (AgentConnectionGmailExceptionV1 $e) {
        $status = $e->getCode();
        if ($status >= 400 && $status < 500 && $status !== 408) throw $e;
        throw new AgentConnectionGmailSendUnknownV1('Gmail send could not be confirmed. Check the original send before trying again.', $status);
    }
    return ['message_id' => $receipt['message_id'], 'thread_id' => $receipt['thread_id'], 'rfc_message_id' => $rfcId];
}

function agent_connection_gmail_find_sent_v1(string $accessToken, string $operationKey, ?callable $http = null): ?array
{
    $rfcId = agent_connection_gmail_rfc_message_id_v1($operationKey);
    $query = http_build_query(['q' => 'rfc822msgid:' . $rfcId, 'maxResults' => 10], '', '&', PHP_QUERY_RFC3986);
    $listed = agent_connection_gmail_api_v1($accessToken, '/messages?labelIds=SENT&' . $query, null, $http);
    $found = null;
    foreach ((array)($listed['messages'] ?? []) as $row) {
        $id = agent_connection_gmail_id_v1($row['id'] ?? '');
        if ($id === '') continue;
        try {
            $message = agent_connection_gmail_message_v1(agent_connection_gmail_get_v1($accessToken, $id, false, $http));
        } catch (AgentConnectionGmailExceptionV1 $e) {
            if ($e->getCode() !== 404) throw $e;
            continue;
        }
        if (!in_array('SENT', $message['labels'], true) || $message['rfc_message_id'] !== $rfcId) continue;
        if ($found !== null && $found['message_id'] !== $message['message_id']) {
            throw new AgentConnectionGmailSendUnknownV1('Gmail returned multiple receipts for this send.');
        }
        $found = ['message_id' => $message['message_id'], 'thread_id' => $message['thread_id'], 'rfc_message_id' => $rfcId];
    }
    if (!empty($listed['nextPageToken'])) {
        throw new AgentConnectionGmailSendUnknownV1('Gmail returned too many results to verify this send.');
    }
    return $found;
}
