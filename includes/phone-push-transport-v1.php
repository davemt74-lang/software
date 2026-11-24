<?php
declare(strict_types=1);

/**
 * PS5C.4: one Web Push attempt, with no queue, audience or credential persistence.
 * The caller must recheck the current device/consent/subscription immediately
 * before calling and must not hold a database transaction during this request.
 * This file remains PHP 8.1 compatible; the maintained crypto worker needs 8.2+.
 */
require_once __DIR__.'/phone-push-subscriptions-v1.php';

function vp3_phone_push_transport_ready_v1(): array
{
    $out = ['available'=>false, 'code'=>'runtime_requires_php82', 'library_version'=>'11.0.0', 'minimum_php'=>'8.2.0'];
    // Do not load any PHP 8.2 vendor code on the PHP 8.1 web/API runtime.
    if (PHP_VERSION_ID < 80200) return $out;
    foreach (['curl', 'json', 'mbstring', 'openssl'] as $extension) {
        if (!extension_loaded($extension)) return array_replace($out, ['code'=>'runtime_extension_missing']);
    }
    foreach (['dns_get_record','curl_init','curl_exec','curl_setopt_array','curl_getinfo','curl_errno','curl_version',
        'openssl_pkey_derive','openssl_pkey_new','openssl_pkey_get_details','openssl_sign','openssl_verify',
        'openssl_get_curve_names','openssl_get_cipher_methods','hash_hmac_algos','hash_hmac','random_bytes','mb_strlen'] as $function) {
        if (!function_exists($function)) return array_replace($out, ['code'=>'runtime_function_missing']);
    }
    if (!in_array('prime256v1', openssl_get_curve_names() ?: [], true)
        || !in_array('aes-128-gcm', openssl_get_cipher_methods(), true)
        || !in_array('sha256', hash_hmac_algos(), true)
        || !in_array('https', curl_version()['protocols'] ?? [], true)) {
        return array_replace($out, ['code'=>'runtime_crypto_unavailable']);
    }
    $autoload = dirname(__DIR__).'/vendor/web-push-v1/vendor/autoload.php';
    if (!is_file($autoload)) return array_replace($out, ['code'=>'dependency_unavailable']);
    try {
        require_once $autoload;
        if (!class_exists(\Minishlink\WebPush\WebPush::class)
            || !class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)
            || !class_exists(\Psr\Log\NullLogger::class)
            || !class_exists(\Composer\InstalledVersions::class)
            || ltrim((string)\Composer\InstalledVersions::getPrettyVersion('minishlink/web-push'), 'v') !== '11.0.0'
            || realpath((new ReflectionClass(\Minishlink\WebPush\WebPush::class))->getFileName())
                !== realpath(dirname($autoload).'/minishlink/web-push/src/WebPush.php')) {
            return array_replace($out, ['code'=>'dependency_unavailable']);
        }
    } catch (Throwable) {
        return array_replace($out, ['code'=>'dependency_unavailable']);
    }
    return array_replace($out, ['available'=>true, 'code'=>'ready']);
}

/** Fixed, non-secret values only. Do not persist exception text/provider bodies. */
function vp3_phone_push_transport_result_v1(string $state, string $code, int $status=0, int $retryAfter=0, bool $dispatched=false): array
{
    return ['state'=>$state, 'code'=>$code, 'http_status'=>$status >= 100 && $status <= 599 ? $status : 0,
        'retry_after'=>$state === 'retry' ? max(1, min(3600, $retryAfter ?: 60)) : 0, 'dispatched'=>$dispatched];
}

/** Public contract: no custom HTTP headers, clients, redirects or route overrides. */
function vp3_phone_push_transport_send_v1(#[\SensitiveParameter] array $subscription, #[\SensitiveParameter] array $vapid, #[\SensitiveParameter] string $payload, array $options=[]): array
{
    return vp3_phone_push_transport_attempt_v1($subscription, $vapid, $payload, $options, 'vp3_phone_push_transport_exchange_v1');
}

/** @internal The injected exchange is for deterministic, credential-free tests. */
function vp3_phone_push_transport_attempt_v1(#[\SensitiveParameter] array $subscription, #[\SensitiveParameter] array $vapid, #[\SensitiveParameter] string $payload, array $options, callable $exchange): array
{
    $ready = vp3_phone_push_transport_ready_v1();
    if (!$ready['available']) return vp3_phone_push_transport_result_v1('failed', $ready['code']);
    try {
        $subscription = vp3_phone_push_parse_v1($subscription, time());
    } catch (Throwable) {
        return vp3_phone_push_transport_result_v1('failed', 'invalid_subscription');
    }
    if (!vp3_phone_push_transport_vapid_config_v1($vapid)) {
        return vp3_phone_push_transport_result_v1('failed', 'invalid_vapid');
    }
    if (strlen($payload) < 2 || strlen($payload) > 2048) return vp3_phone_push_transport_result_v1('failed', 'invalid_payload');
    try {
        if (!is_object(json_decode($payload, false, 12, JSON_THROW_ON_ERROR))) {
            return vp3_phone_push_transport_result_v1('failed', 'invalid_payload');
        }
    } catch (Throwable) {
        return vp3_phone_push_transport_result_v1('failed', 'invalid_payload');
    }
    $ttl = array_key_exists('ttl', $options) ? $options['ttl'] : 300;
    $urgency = array_key_exists('urgency', $options) ? $options['urgency'] : 'normal';
    $topic = $options['topic'] ?? null;
    if (array_diff(array_keys($options), ['ttl','urgency','topic']) || !is_int($ttl) || $ttl < 1 || $ttl > 3600
        || !is_string($urgency) || !in_array($urgency, ['very-low','low','normal','high'], true)
        || (array_key_exists('topic', $options) && (!is_string($topic) || preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $topic) !== 1))) {
        return vp3_phone_push_transport_result_v1('failed', 'invalid_options');
    }
    $origin = 'https://'.parse_url($subscription['endpoint'], PHP_URL_HOST);
    if (!is_string($vapid['authorization'] ?? null)
        || vp3_phone_push_transport_vapid_claims_v1($vapid, $origin, $vapid['authorization'], time()) === null) {
        return vp3_phone_push_transport_result_v1('failed', 'vapid_cache_unavailable');
    }
    // Created only after the runtime guard/autoloader, so requiring this helper on
    // PHP 8.1 never attempts to resolve a PHP 8.2 dependency or PSR interface.
    $client = new class($exchange, $subscription['endpoint']) implements \Psr\Http\Client\ClientInterface {
        public ?array $outcome = null;
        private bool $called = false;
        public function __construct(private $exchange, private string $endpoint) {}
        public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            if ($this->called || $request->getMethod() !== 'POST' || (string)$request->getUri() !== $this->endpoint) {
                $this->outcome = vp3_phone_push_transport_result_v1('failed', 'invalid_request');
            } else {
                $this->called = true;
                try {
                    $this->outcome = ($this->exchange)(['endpoint'=>$this->endpoint,
                        'headers'=>$request->getHeaders(), 'body'=>(string)$request->getBody()]);
                } catch (Throwable) {
                    // A thrown network callback cannot prove whether the service
                    // accepted the message. Never turn it into an automatic retry.
                    $this->outcome = vp3_phone_push_transport_result_v1('uncertain', 'transport_outcome_unknown', 0, 0, true);
                }
            }
            if ($this->outcome['http_status'] >= 100) return new \Nyholm\Psr7\Response($this->outcome['http_status']);
            throw new class($request) extends RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
                public function __construct(private \Psr\Http\Message\RequestInterface $request) { parent::__construct('push_transport_unavailable'); }
                public function getRequest(): \Psr\Http\Message\RequestInterface { return $this->request; }
            };
        }
    };
    try {
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $webPush = new class(['VAPID'=>['subject'=>$vapid['subject'], 'publicKey'=>$vapid['public_key'], 'privateKey'=>$vapid['private_key']]],
            ['TTL'=>$ttl, 'urgency'=>$urgency, 'topic'=>$topic, 'batchSize'=>1, 'requestConcurrency'=>1],
            $client, $factory, $origin, $vapid['authorization']) extends \Minishlink\WebPush\WebPush {
            public function __construct(array $auth, array $options, \Psr\Http\Client\ClientInterface $client,
                \Nyholm\Psr7\Factory\Psr17Factory $factory, private string $origin, private string $authorization)
            {
                parent::__construct($auth, $options, $client, $factory, $factory, null, new \Psr\Log\NullLogger());
            }
            protected function getVAPIDHeaders(string $audience, \Minishlink\WebPush\ContentEncoding $contentEncoding, array $vapid): ?array
            {
                if ($audience !== $this->origin || $contentEncoding !== \Minishlink\WebPush\ContentEncoding::aes128gcm) {
                    throw new RuntimeException('vapid_scope_invalid');
                }
                // Apple requires reuse across requests and cron processes. The
                // caller supplies a durable, encrypted-cache entry, verified
                // above. Do not silently generate a fresh JWT for each attempt.
                return ['Authorization'=>$this->authorization];
            }
        };
        $webPush->setAutomaticPadding(2049);
        $webPush->sendOneNotification(new \Minishlink\WebPush\Subscription($subscription['endpoint'],
            $subscription['keys']['p256dh'], $subscription['keys']['auth'], 'aes128gcm'), $payload);
    } catch (Throwable) {
        return $client->outcome ?? vp3_phone_push_transport_result_v1('failed', 'encryption_unavailable');
    }
    return $client->outcome ?? vp3_phone_push_transport_result_v1('failed', 'encryption_unavailable');
}

function vp3_phone_push_transport_vapid_config_v1(array $vapid): bool
{
    return !array_diff(array_keys($vapid), ['public_key','private_key','subject','authorization'])
        && vp3_phone_push_point_v1($vapid['public_key'] ?? null)
        && vp3_phone_push_decode_v1($vapid['private_key'] ?? null,32) !== null
        && vp3_phone_push_transport_subject_v1($vapid['subject'] ?? null);
}

/**
 * Local signing factory for the caller's durable encrypted VAPID cache. No DNS
 * or HTTP is performed here. Cache binding/lifetime/locking belong to the queue.
 * Return exact reusable headers with a 12-hour token; never call once per send.
 * @throws RuntimeException A fixed safe code; no credential/error text escapes.
 */
function vp3_phone_push_transport_vapid_header_v1(#[\SensitiveParameter] array $vapid, string $origin, int $now): array
{
    $ready = vp3_phone_push_transport_ready_v1();
    if (!$ready['available']) throw new RuntimeException($ready['code']);
    if (!vp3_phone_push_transport_vapid_config_v1($vapid)
        || $origin !== 'https://'.parse_url($origin,PHP_URL_HOST)
        || (!vp3_phone_push_endpoint_v1($origin.'/fcm/send/vapid') && !vp3_phone_push_endpoint_v1($origin.'/wpush/vapid'))
        || $now < 1 || abs(time()-$now) > 60) throw new RuntimeException('invalid_vapid');
    try {
        $validated = \Minishlink\WebPush\VAPID::validate(['subject'=>$vapid['subject'],
            'publicKey'=>$vapid['public_key'], 'privateKey'=>$vapid['private_key']]);
        $headers = \Minishlink\WebPush\VAPID::getVapidHeaders($origin, $validated['subject'],
            $validated['publicKey'], $validated['privateKey'], \Minishlink\WebPush\ContentEncoding::aes128gcm, $now+43200);
        $claims = vp3_phone_push_transport_vapid_claims_v1($vapid, $origin, $headers['Authorization'], $now);
        if ($claims === null) throw new RuntimeException('invalid_vapid');
        return ['authorization'=>$headers['Authorization'], 'issued_epoch'=>$now, 'expires_epoch'=>$claims['exp']];
    } catch (Throwable) { throw new RuntimeException('encryption_unavailable'); }
}

/** Verify cached JWTs with the maintained JOSE library, never custom signing. */
function vp3_phone_push_transport_vapid_claims_v1(array $vapid, string $origin, #[\SensitiveParameter] string $authorization, int $now): ?array
{
    if (strlen($authorization) > 1024 || preg_match('/^vapid t=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+), k=([A-Za-z0-9_-]+)$/D', $authorization, $match) !== 1
        || !is_string($vapid['public_key'] ?? null) || !hash_equals($vapid['public_key'], $match[2])) return null;
    try {
        $public = vp3_phone_push_decode_v1($vapid['public_key'],65);
        if ($public === null) return null;
        $jws = (new \Jose\Component\Signature\Serializer\CompactSerializer())->unserialize($match[1]);
        $header = $jws->getSignature(0)->getProtectedHeader();
        if (($header['alg'] ?? null) !== 'ES256' || ($header['typ'] ?? null) !== 'JWT' || count($header) !== 2) return null;
        $claims = json_decode($jws->getPayload() ?? '',true,8,JSON_THROW_ON_ERROR);
        if (!is_array($claims) || count($claims) !== 3 || ($claims['aud'] ?? null) !== $origin
            || ($claims['sub'] ?? null) !== ($vapid['subject'] ?? null) || !is_int($claims['exp'] ?? null)
            || $claims['exp'] <= $now+30 || $claims['exp'] > $now+86400) return null;
        $key = new \Jose\Component\Core\JWK(['kty'=>'EC','crv'=>'P-256',
            'x'=>vp3_phone_push_b64_v1(substr($public,1,32)), 'y'=>vp3_phone_push_b64_v1(substr($public,33,32))]);
        $verifier = new \Jose\Component\Signature\JWSVerifier(new \Jose\Component\Core\AlgorithmManager([new \Jose\Component\Signature\Algorithm\ES256()]));
        return $verifier->verifyWithKey($jws,$key,0) ? $claims : null;
    } catch (Throwable) { return null; }
}

function vp3_phone_push_transport_subject_v1(mixed $subject): bool
{
    if (!is_string($subject) || strlen($subject) > 254 || preg_match('/[^\x21-\x7e]/', $subject)) return false;
    if (str_starts_with($subject, 'mailto:')) return filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false;
    $url = parse_url($subject);
    return is_array($url) && ($url['scheme'] ?? '') === 'https'
        && !array_intersect(array_keys($url), ['user','pass','port','query','fragment'])
        && is_string($url['host'] ?? null) && strpos($url['host'], '.') !== false
        && filter_var($url['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
        && filter_var($url['host'], FILTER_VALIDATE_IP) === false
        && !str_ends_with(strtolower($url['host']), '.localhost');
}

/** Conservative public-unicast filter; private/tunnel/documentation IPs fail. */
function vp3_phone_push_transport_public_ip_v1(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    if (defined('FILTER_FLAG_GLOBAL_RANGE') && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) return false;
    $packed = inet_pton($ip);
    if ($packed === false) return false;
    if (strlen($packed) === 16) {
        if ((ord($packed[0]) & 0xe0) !== 0x20) return false; // only 2000::/3
        foreach (['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'] as $range) {
            if (vp3_phone_push_transport_in_range_v1($packed, $range)) return false;
        }
    } else {
        foreach (['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12',
            '192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','192.168.0.0/16','198.18.0.0/15',
            '198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $range) {
            if (vp3_phone_push_transport_in_range_v1($packed, $range)) return false;
        }
    }
    return true;
}

function vp3_phone_push_transport_in_range_v1(string $packed, string $cidr): bool
{
    [$network, $bits] = explode('/', $cidr);
    $network = inet_pton($network);
    if ($network === false || strlen($network) !== strlen($packed)) return false;
    $bytes = intdiv((int)$bits, 8); $remainder = (int)$bits % 8;
    return substr($packed, 0, $bytes) === substr($network, 0, $bytes)
        && ($remainder === 0 || ((ord($packed[$bytes]) ^ ord($network[$bytes])) & (255 << (8-$remainder))) === 0);
}

/** @internal Only the public send function is a caller-facing transport API. */
function vp3_phone_push_transport_exchange_v1(array $wire, ?callable $resolver=null, ?callable $executor=null): array
{
    $deadline = hrtime(true) + 8_000_000_000;
    $endpoint = vp3_phone_push_endpoint_v1($wire['endpoint'] ?? null);
    if ($endpoint === null || !vp3_phone_push_transport_wire_v1($wire)) {
        return vp3_phone_push_transport_result_v1('failed', 'invalid_request');
    }
    $host = (string)parse_url($endpoint, PHP_URL_HOST);
    try {
        $records = $resolver ? $resolver($host) : @dns_get_record($host, DNS_A | DNS_AAAA);
    } catch (Throwable) { $records = false; }
    if (!is_array($records) || !$records) return vp3_phone_push_transport_result_v1('retry', 'dns_unavailable');
    if (count($records) > 64) return vp3_phone_push_transport_result_v1('failed', 'dns_not_public');
    $addresses = [];
    foreach ($records as $record) {
        if (!is_array($record)) return vp3_phone_push_transport_result_v1('failed', 'dns_not_public');
        if (($record['type'] ?? null) === 'CNAME' && !isset($record['ip']) && !isset($record['ipv6'])) continue;
        $recordAddresses = array_intersect_key($record, ['ip'=>true,'ipv6'=>true]);
        if (!$recordAddresses) return vp3_phone_push_transport_result_v1('failed', 'dns_not_public');
        foreach ($recordAddresses as $address) {
            if (!is_string($address) || !vp3_phone_push_transport_public_ip_v1($address)) {
                return vp3_phone_push_transport_result_v1('failed', 'dns_not_public');
            }
            $addresses[] = $address;
        }
    }
    if (!$addresses) return vp3_phone_push_transport_result_v1('retry', 'dns_unavailable');
    $remainingMs = intdiv($deadline - hrtime(true), 1_000_000);
    if ($remainingMs < 1) return vp3_phone_push_transport_result_v1('retry', 'dns_timed_out');
    $ipv4 = array_values(array_filter($addresses, static fn(string $ip): bool => !str_contains($ip, ':')));
    $ip = $ipv4[0] ?? $addresses[0];
    $pin = $host.':443:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip);
    try {
        $observation = $executor ? $executor($wire, $pin, min(8000, $remainingMs))
            : vp3_phone_push_transport_curl_v1($wire, $pin, min(8000, $remainingMs));
    } catch (Throwable) {
        return vp3_phone_push_transport_result_v1('uncertain', 'transport_outcome_unknown', 0, 0, true);
    }
    $actualIp = $observation['primary_ip'] ?? '';
    $responded = ($observation['response_complete'] ?? false) === true || (int)($observation['http_status'] ?? 0) > 0;
    if (($responded && $actualIp === '') || ($actualIp !== '' && (!is_string($actualIp) || @inet_pton($actualIp) !== inet_pton($ip)))) {
        return vp3_phone_push_transport_result_v1('uncertain', 'transport_route_unverified', 0, 0, true);
    }
    return vp3_phone_push_transport_classify_v1($observation, time());
}

/** Validate exactly the library's one authenticated, encrypted POST shape. */
function vp3_phone_push_transport_wire_v1(array $wire): bool
{
    if (!is_string($wire['body'] ?? null) || strlen($wire['body']) < 103 || strlen($wire['body']) > 4096
        || !is_array($wire['headers'] ?? null)) return false;
    $headers = [];
    foreach ($wire['headers'] as $name=>$values) {
        if (!is_string($name) || !is_array($values) || count($values) !== 1 || !is_string($values[0] ?? null)
            || strlen($values[0]) > 1024 || preg_match('/[^\x20-\x7e]/', $values[0])) return false;
        $name = strtolower($name);
        if (isset($headers[$name]) || !in_array($name, ['host','content-type','content-encoding','content-length','authorization','ttl','urgency','topic'], true)) return false;
        $headers[$name] = $values[0];
    }
    return ($headers['host'] ?? '') === parse_url($wire['endpoint'] ?? '', PHP_URL_HOST)
        && ($headers['content-type'] ?? '') === 'application/octet-stream'
        && ($headers['content-encoding'] ?? '') === 'aes128gcm'
        && ($headers['content-length'] ?? '') === (string)strlen($wire['body'])
        && preg_match('/^vapid t=[A-Za-z0-9_.-]+, ?k=[A-Za-z0-9_-]+$/D', $headers['authorization'] ?? '') === 1
        && preg_match('/^[1-9][0-9]{0,3}$/D', $headers['ttl'] ?? '') === 1 && (int)$headers['ttl'] <= 3600
        && in_array($headers['urgency'] ?? '', ['very-low','low','normal','high'], true)
        && (!isset($headers['topic']) || preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $headers['topic']) === 1);
}

/** Same options are inspected by tests and used unchanged by the actual sender. */
function vp3_phone_push_transport_curl_options_v1(array $wire, string $pin, int $timeoutMs, callable $readHeaders, callable $readBody): array
{
    $headers = [];
    foreach ($wire['headers'] as $name=>$values) $headers[] = $name.': '.$values[0];
    $headers[] = 'Expect:';
    $options = [CURLOPT_URL=>$wire['endpoint'], CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$wire['body'],
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_USERAGENT=>'VP3-PhonePush/1.0', CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0, CURLOPT_FAILONERROR=>false,
        CURLOPT_PROXY=>'', CURLOPT_NOPROXY=>'*', CURLOPT_NETRC=>CURL_NETRC_IGNORED,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_RESOLVE=>[$pin],
        CURLOPT_CONNECTTIMEOUT_MS=>min(3000, $timeoutMs), CURLOPT_TIMEOUT_MS=>$timeoutMs,
        CURLOPT_NOSIGNAL=>true, CURLOPT_FRESH_CONNECT=>true, CURLOPT_FORBID_REUSE=>true,
        CURLOPT_MAXCONNECTS=>1, CURLOPT_HEADERFUNCTION=>$readHeaders, CURLOPT_WRITEFUNCTION=>$readBody];
    $options[defined('CURLOPT_PROTOCOLS_STR') ? constant('CURLOPT_PROTOCOLS_STR') : CURLOPT_PROTOCOLS] = defined('CURLOPT_PROTOCOLS_STR') ? 'https' : CURLPROTO_HTTPS;
    $options[defined('CURLOPT_REDIR_PROTOCOLS_STR') ? constant('CURLOPT_REDIR_PROTOCOLS_STR') : CURLOPT_REDIR_PROTOCOLS] = defined('CURLOPT_REDIR_PROTOCOLS_STR') ? 'https' : CURLPROTO_HTTPS;
    return $options;
}

/** Never retain a provider response body, endpoint, JWT or cURL error string. */
function vp3_phone_push_transport_curl_v1(array $wire, string $pin, int $timeoutMs): array
{
    $observation = ['http_status'=>0, 'curl_errno'=>0, 'request_size'=>0, 'uploaded_bytes'=>0,
        'pretransfer_us'=>0, 'response_complete'=>false, 'response_limit'=>false, 'retry_after'=>null, 'primary_ip'=>''];
    [$readHeaders, $readBody] = vp3_phone_push_transport_response_readers_v1($observation);
    $handle = curl_init();
    if ($handle === false) return $observation + ['setup_failed'=>true];
    try {
        if (!curl_setopt_array($handle, vp3_phone_push_transport_curl_options_v1($wire, $pin, $timeoutMs, $readHeaders, $readBody))) {
            return $observation + ['setup_failed'=>true];
        }
        $observation['response_complete'] = curl_exec($handle) === true;
        $observation['http_status'] = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $observation['curl_errno'] = curl_errno($handle);
        $observation['request_size'] = (int)curl_getinfo($handle, CURLINFO_REQUEST_SIZE);
        $observation['uploaded_bytes'] = (int)curl_getinfo($handle, CURLINFO_SIZE_UPLOAD_T);
        $observation['pretransfer_us'] = (int)curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME_T);
        $observation['primary_ip'] = (string)curl_getinfo($handle, CURLINFO_PRIMARY_IP);
        return $observation;
    } finally { curl_close($handle); }
}

/** @internal Response readers are independently exercised without live traffic. */
function vp3_phone_push_transport_response_readers_v1(array &$observation): array
{
    $headerBytes = 0; $bodyBytes = 0; $currentStatus = 0;
    $readHeaders = static function ($handle, string $line) use (&$observation, &$headerBytes, &$currentStatus): int {
        $headerBytes += strlen($line);
        if ($headerBytes > 16384) { $observation['response_limit'] = true; return 0; }
        if (preg_match('/^HTTP\/\S+ ([0-9]{3})(?: |\r?$)/', $line, $match)) {
            $currentStatus = (int)$match[1]; $observation['retry_after'] = null;
        } elseif ($currentStatus >= 200 && preg_match('/^retry-after:\s*([^\r\n]{1,128})\r?\n$/iD', $line, $match)) {
            $observation['retry_after'] = trim($match[1]);
        }
        return strlen($line);
    };
    $readBody = static function ($handle, string $chunk) use (&$observation, &$bodyBytes): int {
        $bodyBytes += strlen($chunk);
        if ($bodyBytes > 8192) { $observation['response_limit'] = true; return 0; }
        return strlen($chunk);
    };
    return [$readHeaders, $readBody];
}

function vp3_phone_push_transport_classify_v1(array $observation, int $now): array
{
    $status = (int)($observation['http_status'] ?? 0);
    $errno = (int)($observation['curl_errno'] ?? 0);
    if (($observation['setup_failed'] ?? false) === true) return vp3_phone_push_transport_result_v1('failed', 'transport_unavailable');
    if (($observation['response_limit'] ?? false) === true) return vp3_phone_push_transport_result_v1('uncertain', 'provider_response_too_large', $status, 0, true);
    if (($observation['response_complete'] ?? false) !== true || $errno !== 0) {
        $beforeRequest = ($observation['request_size'] ?? -1) === 0 && ($observation['uploaded_bytes'] ?? -1) === 0 && $status === 0;
        if ($beforeRequest && in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true)) {
            return vp3_phone_push_transport_result_v1('retry', 'connection_unavailable');
        }
        if ($beforeRequest && $errno === CURLE_OPERATION_TIMEDOUT && ($observation['pretransfer_us'] ?? -1) === 0) {
            return vp3_phone_push_transport_result_v1('retry', 'connection_timed_out');
        }
        if ($beforeRequest && in_array($errno, [CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CACERT], true)) {
            return vp3_phone_push_transport_result_v1('failed', 'tls_unavailable');
        }
        return vp3_phone_push_transport_result_v1('uncertain', 'transport_outcome_unknown', $status, 0, true);
    }
    if ($status >= 200 && $status <= 299) return vp3_phone_push_transport_result_v1('accepted', 'provider_accepted', $status, 0, true);
    if (in_array($status, [404,410], true)) return vp3_phone_push_transport_result_v1('expired', 'subscription_expired', $status, 0, true);
    if (in_array($status, [429,503], true)) return vp3_phone_push_transport_result_v1('retry', 'provider_busy', $status,
        vp3_phone_push_transport_retry_after_v1($observation['retry_after'] ?? null, $now), true);
    if ($status >= 300 && $status <= 399) return vp3_phone_push_transport_result_v1('failed', 'provider_redirect_blocked', $status, 0, true);
    if ($status === 408) return vp3_phone_push_transport_result_v1('uncertain', 'provider_outcome_unknown', $status, 0, true);
    if (in_array($status, [401,403], true)) return vp3_phone_push_transport_result_v1('failed', 'provider_auth_failed', $status, 0, true);
    if ($status >= 400 && $status <= 499) return vp3_phone_push_transport_result_v1('failed', 'provider_rejected', $status, 0, true);
    // Other server failures do not promise non-acceptance. Avoid duplicate sends.
    return vp3_phone_push_transport_result_v1('uncertain', 'provider_outcome_unknown', $status, 0, true);
}

function vp3_phone_push_transport_retry_after_v1(mixed $value, int $now): int
{
    if (!is_string($value) || strlen($value) > 128) return 60;
    if (preg_match('/^[0-9]{1,10}$/D', $value)) return max(1, min(3600, (int)$value));
    $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new DateTimeZone('UTC'));
    if ($date !== false && $date->format('D, d M Y H:i:s \G\M\T') === $value) return max(1, min(3600, $date->getTimestamp()-$now));
    return 60;
}
