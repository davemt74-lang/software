<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/agent-connection-gmail-v1.php';

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    }
    return $pdo;
}

function ai_decrypt_secret(string $value): string
{
    return $value === 'encrypted-fixture' ? 'saved-fixture-secret' : '';
}

function check(bool $passed, string $message): void
{
    if (!$passed) throw new RuntimeException($message);
}

function provider_error(callable $run, int $status, bool $unknown = false): AgentConnectionGmailExceptionV1
{
    try {
        $run();
    } catch (AgentConnectionGmailExceptionV1 $e) {
        check($e->getCode() === $status, 'Unexpected provider status.');
        check(($e instanceof AgentConnectionGmailSendUnknownV1) === $unknown, 'Incorrect send certainty.');
        check(!str_contains($e->getMessage(), 'sensitive-fixture'), 'Raw provider data leaked into an error.');
        return $e;
    }
    throw new RuntimeException('Expected a provider exception.');
}

function encoded(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

$config = ['connections' => ['gmail' => ['client_id' => 'fixture-id', 'client_secret' => 'fixture-secret']]];
putenv('VP3_GMAIL_CLIENT_ID');
putenv('VP3_GMAIL_CLIENT_SECRET');
$redirect = 'https://vp3.example/connections-oauth.php';
$authorization = agent_connection_gmail_oauth_url_v1($redirect, 'fixture-state', str_repeat('a', 43));
parse_str((string)parse_url($authorization, PHP_URL_QUERY), $query);
check(parse_url($authorization, PHP_URL_HOST) === 'accounts.google.com'
    && $query['access_type'] === 'offline' && $query['state'] === 'fixture-state'
    && $query['code_challenge_method'] === 'S256' && $query['redirect_uri'] === $redirect,
    'OAuth state, callback, offline access or PKCE was missing.');
check(explode(' ', $query['scope']) === ['openid', 'email', VP3_GMAIL_READ_SCOPE_V1, VP3_GMAIL_SEND_SCOPE_V1],
    'Unexpected Gmail access requested.');

$account = agent_connection_gmail_exchange_v1($redirect, 'fixture-code', str_repeat('b', 43),
    static function ($method, $url, $headers, $body, $form) use ($redirect): array {
        if ($url === 'https://oauth2.googleapis.com/token') {
            check($method === 'POST' && $form && $body['code'] === 'fixture-code'
                && $body['redirect_uri'] === $redirect && $body['code_verifier'] === str_repeat('b', 43),
                'OAuth exchange omitted the original callback or verifier.');
            $json = [
                'access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh', 'expires_in' => 3600,
                'scope' => VP3_GMAIL_READ_SCOPE_V1 . ' ' . VP3_GMAIL_SEND_SCOPE_V1,
            ];
        } else {
            check($method === 'GET' && $body === null && in_array('Authorization: Bearer fixture-access', $headers, true),
                'Identity lookup did not use the exchanged grant.');
            $json = match ($url) {
                'https://openidconnect.googleapis.com/v1/userinfo' => [
                    'sub' => 'immutable-fixture-account', 'email' => 'owner@example.test', 'email_verified' => true,
                ],
                VP3_GMAIL_API_V1 . '/profile' => ['emailAddress' => 'owner@example.test'],
                default => throw new RuntimeException('Unexpected account endpoint.'),
            };
        }
        return ['status' => 200, 'json' => $json];
    }
);
check($account['external_account_id'] === 'immutable-fixture-account' && $account['account_email'] === 'owner@example.test',
    'Verified mailbox identity was not returned.');

$origin = 'https://vp3.example';
$popupTransport = static function (string $mailbox, string $scopes) use ($origin): callable {
    return static function ($method, $url, $headers, $body, $form) use ($origin, $mailbox, $scopes): array {
        if ($url === 'https://oauth2.googleapis.com/token') {
            check($method === 'POST' && $form && $headers === ['Accept: application/json']
                && $body === ['client_id'=>'fixture-id','client_secret'=>'fixture-secret','code'=>'fixture-popup-code',
                    'redirect_uri'=>$origin,'grant_type'=>'authorization_code'],
                'Popup code exchange must use the calling origin and Google’s code grant parameters.');
            $json = ['access_token'=>'fixture-popup-access','expires_in'=>3600,'scope'=>$scopes];
        } else {
            check($method === 'GET' && $body === null && in_array('Authorization: Bearer fixture-popup-access', $headers, true),
                'Popup identity lookup must use its exchanged access token.');
            $json = match ($url) {
                'https://openidconnect.googleapis.com/v1/userinfo' => [
                    'sub'=>'immutable-fixture-account','email'=>'owner@example.test','email_verified'=>true,
                ],
                VP3_GMAIL_API_V1.'/profile' => ['emailAddress'=>$mailbox],
                default => throw new RuntimeException('Unexpected popup identity endpoint.'),
            };
        }
        return ['status'=>200,'json'=>$json];
    };
};
$requiredScopes = VP3_GMAIL_READ_SCOPE_V1.' '.VP3_GMAIL_SEND_SCOPE_V1;
$popup = agent_connection_gmail_exchange_popup_v1($origin, 'fixture-popup-code', $popupTransport('owner@example.test', $requiredScopes));
check($popup['refresh_token'] === '' && $popup['external_account_id'] === $account['external_account_id']
    && $popup['account_email'] === $account['account_email'], 'The popup exchange must verify the mailbox even when Google omits refresh.');
provider_error(static fn()=>agent_connection_gmail_exchange_popup_v1($origin, 'fixture-popup-code',
    $popupTransport('other@example.test', $requiredScopes)), 0);
provider_error(static fn()=>agent_connection_gmail_exchange_popup_v1($origin, 'fixture-popup-code',
    $popupTransport('owner@example.test', VP3_GMAIL_READ_SCOPE_V1)), 0);
provider_error(static fn()=>agent_connection_gmail_exchange_v1($redirect, 'fixture-code', str_repeat('b', 43),
    static function ($method, $url) use ($requiredScopes): array {
        check($method === 'POST' && $url === 'https://oauth2.googleapis.com/token', 'Legacy exchange requires a new refresh token before profile lookup.');
        return ['status'=>200,'json'=>['access_token'=>'fixture-access','expires_in'=>3600,'scope'=>$requiredScopes]];
    }), 0);

$refresh = agent_connection_gmail_refresh_v1('fixture-refresh',
    static function ($method, $url, $headers, $body, $form): array {
        check($method === 'POST' && $form && $url === 'https://oauth2.googleapis.com/token'
            && $body['grant_type'] === 'refresh_token' && $body['refresh_token'] === 'fixture-refresh',
            'Refresh used the wrong endpoint or grant.');
        return ['status' => 200, 'json' => ['access_token' => 'fixture-next', 'expires_in' => 3599]];
    }
);
check($refresh['access_token'] === 'fixture-next' && $refresh['refresh_token'] === '', 'Refresh response normalization failed.');
$error = provider_error(static fn() => agent_connection_gmail_refresh_v1('fixture-refresh',
    static fn() => ['status' => 400, 'json' => ['error' => 'invalid_grant', 'error_description' => 'sensitive-fixture']]), 400);
check($error->providerError === 'invalid_grant', 'Revoked credentials could not be distinguished.');

db()->exec("INSERT INTO settings VALUES ('connections_gmail_client_id', 'saved-id'), ('connections_gmail_client_secret', 'encrypted-fixture')");
check(agent_connection_gmail_config_v1() === ['client_id' => 'saved-id', 'client_secret' => 'saved-fixture-secret', 'source' => 'saved'],
    'Configured Gmail credentials did not override the installation fallback.');
db()->exec("UPDATE settings SET setting_value = 'invalid-fixture' WHERE setting_key = 'connections_gmail_client_secret'");
check(!agent_connection_gmail_ready_v1() && agent_connection_gmail_config_v1()['client_secret'] === '',
    'Unreadable saved credentials silently switched the OAuth application.');
db()->exec("DELETE FROM settings; INSERT INTO settings VALUES ('connections_gmail_enabled', '0')");
provider_error(static fn() => agent_connection_gmail_search_v1('fixture-access', 'subject:reply', 1,
    static fn() => throw new RuntimeException('Paused Gmail contacted Google.')), 403);
agent_connection_gmail_revoke_v1('fixture-refresh', static function ($method, $url, $headers, $body, $form): array {
    check($method === 'POST' && $url === 'https://oauth2.googleapis.com/revoke' && $form
        && $body === ['token' => 'fixture-refresh'] && count($headers) === 1,
        'Revoke must use a form body and work while Gmail is paused.');
    return ['status' => 200, 'json' => null];
});
db()->exec('DELETE FROM settings');

$operation = 'fixture-operation';
$rfcId = agent_connection_gmail_rfc_message_id_v1($operation);
$network = 0;
$send = static fn(?callable $http = null, string $subject = 'Your café update') => agent_connection_gmail_send_v1(
    'fixture-access', 'owner@example.test', 'recipient@example.test', $subject, "One\nTwo", $operation, $http
);
$receipt = $send(static function ($method, $url, $headers, $body, $form) use (&$network, $rfcId): array {
    $network++;
    check($method === 'POST' && !$form && $url === VP3_GMAIL_API_V1 . '/messages/send'
        && in_array('Authorization: Bearer fixture-access', $headers, true), 'Send used the wrong endpoint or credentials.');
    $mime = base64_decode(strtr($body['raw'], '-_', '+/'), true);
    check(is_string($mime) && str_contains($mime, "\r\nMessage-ID: " . $rfcId . "\r\n")
        && str_contains($mime, "\r\nTo: recipient@example.test\r\n"), 'RFC MIME recipient or deterministic Message-ID is missing.');
    [$headers, $content] = explode("\r\n\r\n", $mime, 2);
    check(str_contains(mb_decode_mimeheader($headers), 'Subject: Your café update') && base64_decode($content) === "One\r\nTwo",
        'Subject or body encoding lost data.');
    return ['status' => 200, 'json' => ['id' => 'msg-1', 'threadId' => 'thread-1']];
});
check($network === 1 && $receipt === ['message_id' => 'msg-1', 'thread_id' => 'thread-1', 'rfc_message_id' => $rfcId],
    'A Gmail send must return both provider IDs and the original RFC ID.');
provider_error(static fn() => $send(static fn() => throw new RuntimeException('Header injection contacted Google.'), "Hello\r\nBcc: bad@example.test"), 400);
foreach ([401, 403, 429, 408, 503, 302, 200, 0] as $status) {
    $network = 0;
    $transport = static function () use (&$network, $status): array {
        $network++;
        return ['status' => $status, 'json' => ['error' => 'sensitive-fixture']];
    };
    provider_error(static fn() => $send($transport), $status === 200 ? 0 : $status, $status < 400 || $status >= 500 || $status === 408);
    check($network === 1, 'An uncertain send made another POST.');
}

$header = static fn($name, $value) => ['name' => $name, 'value' => $value];
$sent = ['id' => 'msg-1', 'threadId' => 'thread-1', 'labelIds' => ['SENT'], 'internalDate' => '1777777700000',
    'payload' => ['headers' => [$header('Message-ID', $rfcId)]]];
$reply = ['id' => 'msg-2', 'threadId' => 'thread-1', 'labelIds' => ['INBOX'], 'internalDate' => '1777777800000',
    'payload' => ['headers' => [$header('In-Reply-To', $rfcId), $header('From', 'Recipient <recipient@example.test>')]]];
$listed = static function ($method, $url) use ($rfcId, $sent, $reply): array {
    if (str_contains($url, '/messages?')) {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        check($method === 'GET' && $query['labelIds'] === 'SENT' && $query['q'] === 'rfc822msgid:' . $rfcId,
            'Reconciliation search was not limited to the exact message in Sent.');
        $json = ['messages' => [['id' => 'msg-2'], ['id' => 'msg-1']]];
    } elseif (str_contains($url, '/messages/msg-2?')) {
        $json = $reply;
        $json['payload']['headers'][] = ['name' => 'Message-ID', 'value' => $rfcId];
    } else {
        $json = $sent;
    }
    return ['status' => 200, 'json' => $json];
};
check(agent_connection_gmail_find_sent_v1('fixture-access', $operation, $listed) === $receipt,
    'An inbox message carrying the same RFC ID must not be reconciled as a send.');

$thread = agent_connection_gmail_thread_v1('fixture-access', 'thread-1',
    static fn() => ['status' => 200, 'json' => ['id' => 'thread-1', 'messages' => [$sent, $reply]]]);
check($thread[0]['rfc_message_id'] === $rfcId && $thread[1]['in_reply_to'] === $rfcId
    && $thread[1]['received_at_ms'] === 1777777800000, 'Reply metadata did not identify the exact original.');
provider_error(static fn() => agent_connection_gmail_thread_v1('fixture-access', 'thread-2',
    static fn() => ['status' => 200, 'json' => ['id' => 'thread-2', 'messages' => [$sent]]]), 0);

$reply['payload']['mimeType'] = 'multipart/mixed';
$reply['payload']['parts'] = [
    ['mimeType' => 'text/plain', 'filename' => 'attachment.txt', 'body' => ['data' => encoded('Private attachment.')]],
    ['mimeType' => 'text/html', 'body' => ['data' => encoded('<script>ignore()</script><p>One &amp; two.</p>')]],
];
$read = agent_connection_gmail_read_v1('fixture-access', 'msg-2', static fn() => ['status' => 200, 'json' => $reply]);
check($read['body'] === 'One & two.', 'Body text included active HTML or an attachment.');
$reply['payload']['headers'] = [
    $header('From','Sender <sender@example.test>'), $header('Reply-To','"Team, Replies" <replies@example.test>'),
    $header('Subject',mb_encode_mimeheader('Café delivery','UTF-8','B')),
    $header('Message-ID','<selected@example.test>'), $header('References',"<root@example.test>\r\n <parent@example.test>"),
];
$source = agent_connection_gmail_reply_source_v1('fixture-access','msg-2','owner@example.test',false,
    static function ($method,$url) use ($reply): array {
        check($method === 'GET' && str_starts_with($url,VP3_GMAIL_API_V1.'/messages/msg-2?format=metadata')
            && str_contains($url,'metadataHeaders=Reply-To'),'A manual reply must request only exact message metadata.');
        return ['status'=>200,'json'=>$reply];
    });
check($source['to'] === 'replies@example.test' && $source['subject'] === 'Café delivery'
    && $source['reply'] === ['message_id'=>'msg-2','thread_id'=>'thread-1','in_reply_to'=>'<selected@example.test>',
        'references'=>['<root@example.test>','<parent@example.test>','<selected@example.test>']]
    && !isset($source['body']), 'The exact reply address, subject, parent and full reference chain are verified.');
$sourceBody = agent_connection_gmail_reply_from_message_v1($reply,'owner@example.test',true);
check($sourceBody['body'] === 'One & two.', 'The isolated reply draft can use only the cleaned source body.');
$sendReply = static fn(callable $http, ?array $binding=null) => agent_connection_gmail_send_v1('fixture-access','owner@example.test',
    $source['to'],$source['subject'],'This is the reviewed reply.',$operation,$http,$binding ?? $source['reply']);
$sentReply = $sendReply(static function ($method,$url,$headers,$body): array {
    $mime = base64_decode(strtr($body['raw'], '-_', '+/'), true);
    check($method === 'POST' && $url === VP3_GMAIL_API_V1.'/messages/send' && $body['threadId'] === 'thread-1'
        && is_string($mime) && str_contains(mb_decode_mimeheader($mime), 'Subject: Café delivery')
        && str_contains($mime,"\r\nTo: replies@example.test\r\n")
        && str_contains($mime,"\r\nIn-Reply-To: <selected@example.test>\r\n")
        && str_contains($mime,"\r\nReferences: <root@example.test>\r\n <parent@example.test>\r\n <selected@example.test>\r\n"),
        'The Gmail reply must keep the matching subject and comply with RFC thread headers.');
    return ['status'=>200,'json'=>['id'=>'msg-3','threadId'=>'thread-1']];
});
check($sentReply['thread_id'] === 'thread-1', 'The provider verified the original thread.');
provider_error(static fn()=>$sendReply(static fn()=>['status'=>200,'json'=>['id'=>'msg-3','threadId'=>'other-thread']]),0,true);
provider_error(static fn()=>$sendReply(static fn()=>throw new RuntimeException('Invalid reply contacted Gmail.'),
    array_replace($source['reply'],['in_reply_to'=>"<selected@example.test>\r\nBcc: extra@example.test"])),409);
$multiple = $reply;
$multiple['payload']['headers'][1]['value'] = 'one@example.test, Second <two@example.test>';
provider_error(static fn()=>agent_connection_gmail_reply_from_message_v1($multiple,'owner@example.test'),409);
$blank = $reply;
$blank['payload']['headers'][2]['value'] = '';
check(agent_connection_gmail_reply_from_message_v1($blank,'owner@example.test')['subject'] === '', 'An empty original subject stays empty.');
echo "AGENT_CONNECTION_GMAIL_V1=PASS\n";
