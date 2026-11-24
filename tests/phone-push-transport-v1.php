<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/phone-push-transport-v1.php';

$checks = 0;
function transport_check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('Phone push transport: '.$message);
    ++$checks;
}
function transport_fixture(): array
{
    // Publicly published synthetic keys: RFC 8291, section 5 / appendix A.
    $subscription = ['endpoint'=>'https://web.push.apple.com/QSyntheticTransportFixture', 'expirationTime'=>null,
        'keys'=>['p256dh'=>'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'auth'=>'BTBZMqHH6r4Tts7J_aSIgg']];
    $vapid = ['public_key'=>'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8',
        'private_key'=>'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw', 'subject'=>'mailto:push@example.test'];
    $cached = vp3_phone_push_transport_vapid_header_v1($vapid, 'https://web.push.apple.com', time());
    $vapid['authorization'] = $cached['authorization'];
    $payload = json_encode(['v'=>1, 'event_ref'=>str_repeat('a',32), 'receipt_token'=>str_repeat('b',43)], JSON_THROW_ON_ERROR);
    return [$subscription, $vapid, $payload, ['ttl'=>300, 'topic'=>str_repeat('b',32), 'urgency'=>'normal']];
}
function transport_capture(array $fixture): array
{
    $wire = null;
    $result = vp3_phone_push_transport_attempt_v1(...[...$fixture, static function (array $request) use (&$wire): array {
        $wire = $request;
        return vp3_phone_push_transport_result_v1('accepted', 'provider_accepted', 201, 0, true);
    }]);
    if ($result['state'] !== 'accepted' || !is_array($wire)) throw new RuntimeException('Synthetic encryption fixture failed: '.$result['code']);
    return $wire;
}
function transport_observation(int $status=201, array $replace=[]): array
{
    return array_replace(['http_status'=>$status, 'curl_errno'=>0, 'request_size'=>500, 'uploaded_bytes'=>2151,
        'pretransfer_us'=>1000, 'response_complete'=>true, 'response_limit'=>false, 'retry_after'=>null,
        'primary_ip'=>'17.188.164.65'], $replace);
}

$ready = vp3_phone_push_transport_ready_v1();
if (PHP_VERSION_ID < 80200) {
    transport_check($ready['available'] === false && $ready['code'] === 'runtime_requires_php82', 'PHP 8.1 reports its worker requirement');
    transport_check(!class_exists('Minishlink\\WebPush\\WebPush', false), 'PHP 8.1 does not load the crypto library');
    transport_check(!array_filter(get_included_files(), static fn(string $path): bool => str_contains($path, '/vendor/web-push-v1/')), 'PHP 8.1 does not include the vendor autoloader');
    $unsupported = vp3_phone_push_transport_send_v1([], [], '{}');
    transport_check($unsupported['state'] === 'failed' && $unsupported['code'] === 'runtime_requires_php82' && !$unsupported['dispatched'], 'unsupported runtime never attempts delivery');
    $factoryBlocked = false;
    try { vp3_phone_push_transport_vapid_header_v1([], 'https://web.push.apple.com', time()); }
    catch (RuntimeException $error) { $factoryBlocked = $error->getMessage() === 'runtime_requires_php82'; }
    transport_check($factoryBlocked && !class_exists('Minishlink\\WebPush\\WebPush', false), 'unsupported runtime never loads the token signing factory');
    echo 'Phone push transport PHP 8.1 guard: '.$checks." checks passed\n";
    exit(0);
}
if (!$ready['available']) throw new RuntimeException('Transport test runtime is incomplete: '.$ready['code']);
$fixture = transport_fixture();
if (($argv[1] ?? '') === '--crypto-fixture') {
    $requests = [];
    for ($i=0; $i<2; ++$i) {
        $wire = transport_capture($fixture);
        $wire['body'] = base64_encode($wire['body']);
        $requests[] = $wire;
    }
    echo json_encode(['requests'=>$requests, 'payload'=>$fixture[2], 'subscription'=>$fixture[0],
        'vapid_public'=>$fixture[1]['public_key'], 'subject'=>$fixture[1]['subject'],
        'user_private'=>'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94'], JSON_THROW_ON_ERROR);
    exit(0);
}
transport_check($ready['code'] === 'ready' && $ready['library_version'] === '11.0.0', 'maintained pinned library is available');
$bundle = rtrim(getenv('VP3_PHONE_PUSH_VERIFY_BUNDLE') ?: __DIR__.'/../vendor/web-push-v1', '/').'/';
$manifest = json_decode(file_get_contents($bundle.'MANIFEST.json'), true, 64, JSON_THROW_ON_ERROR);
$lock = json_decode(file_get_contents($bundle.'composer.lock'), true, 64, JSON_THROW_ON_ERROR);
transport_check($manifest['schema'] === 1 && $manifest['minimum_worker_php'] === '8.2.0' && count($manifest['packages']) === 16
    && ($lock['packages-dev'] ?? []) === [], 'production manifest has exactly the locked non-development dependencies');
transport_check(hash_equals($manifest['composer_lock_sha256'], hash_file('sha256', $bundle.'composer.lock')), 'Composer lock matches the reviewed manifest');
$badFiles = [];
foreach ($manifest['runtime_files'] as $entry) {
    $path = $entry['path'];
    if (str_contains($path, '..') || str_starts_with($path, '/') || !is_file($bundle.$path)
        || !hash_equals($entry['sha256'], hash_file('sha256', $bundle.$path))) $badFiles[] = $path;
}
transport_check(count($manifest['runtime_files']) > 600 && !$badFiles, 'every bundled production file matches its SHA-256');
$licenses = file_get_contents($bundle.'THIRD-PARTY-LICENSES.txt');
$lockedVersions = array_column($lock['packages'], 'version', 'name');
foreach ($manifest['packages'] as $package) {
    transport_check(($lockedVersions[$package['name']] ?? null) === $package['version'] && $package['license'] === ['MIT']
        && preg_match('/^[a-f0-9]{40}$/D', $package['source']['reference']) === 1
        && str_contains($licenses, file_get_contents($bundle.$package['license_file'])), 'locked source and complete retained license: '.$package['name']);
}
transport_check(str_contains($licenses, file_get_contents($bundle.'vendor/composer/LICENSE')), 'Composer autoloader license is retained');

// Compare the actual library output with the published RFC 8291 ciphertext,
// rather than duplicating the encryption implementation in a PHP test.
$serverRaw = vp3_phone_push_decode_v1($fixture[1]['public_key'],65);
$jwk = new \Jose\Component\Core\JWK(['kty'=>'EC', 'crv'=>'P-256', 'd'=>$fixture[1]['private_key'],
    'x'=>vp3_phone_push_b64_v1(substr($serverRaw,1,32)), 'y'=>vp3_phone_push_b64_v1(substr($serverRaw,33,32))]);
$cipher = \Minishlink\WebPush\Encryption::deterministicEncrypt('When I grow up, I want to be a watermelon'."\x02",
    $fixture[0]['keys']['p256dh'], $fixture[0]['keys']['auth'], \Minishlink\WebPush\ContentEncoding::aes128gcm,
    [$jwk], vp3_phone_push_decode_v1('DGv6ra1nlYgDCS1FRnbzlw',16));
transport_check(vp3_phone_push_b64_v1($cipher['cipherText']) === '8pfeW0KbunFT06SuDKoJH9Ql87S1QUrdirN6GcG7sFz1y1sqLgVi1VhjVkHsUoEsbI_0LpXMuGvnzQ', 'RFC 8291 published ciphertext matches');
transport_check(vp3_phone_push_b64_v1(\Minishlink\WebPush\Encryption::getContentCodingHeader($cipher['salt'], $cipher['localPublicKey'], \Minishlink\WebPush\ContentEncoding::aes128gcm))
    === 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8', 'RFC 8291 record header matches');

$wire = transport_capture($fixture);
transport_check(vp3_phone_push_transport_wire_v1($wire), 'library creates a valid bounded authenticated aes128gcm POST');
transport_check(strlen($wire['body']) === 2151 && !str_contains($wire['body'], $fixture[2]), 'payload is encrypted with fixed 2049-byte padding');
$headers = array_change_key_case($wire['headers']);
transport_check($headers['ttl'] === ['300'] && $headers['topic'] === [str_repeat('b',32)] && $headers['urgency'] === ['normal'], 'public lowercase options map to the protocol');
transport_check($headers['authorization'] === [$fixture[1]['authorization']], 'the exact durable cached authorization is reused');
$secondWire = transport_capture($fixture);
transport_check(array_change_key_case($secondWire['headers'])['authorization'] === $headers['authorization'], 'another send does not re-sign or refresh its cached JWT');
$claims = vp3_phone_push_transport_vapid_claims_v1($fixture[1], 'https://web.push.apple.com', $fixture[1]['authorization'], time());
transport_check(is_array($claims) && $claims['exp'] > time()+43100 && $claims['exp'] <= time()+43200, 'factory produces a signed twelve-hour cache entry');
transport_check(vp3_phone_push_transport_vapid_claims_v1($fixture[1], 'https://web.push.apple.com', $fixture[1]['authorization'], $claims['exp']) === null, 'expired cached JWT is rejected');

$calls = 0;
$unexpected = static function () use (&$calls): array { ++$calls; throw new RuntimeException('must not dispatch'); };
foreach ([['ttl'=>0], ['ttl'=>3601], ['ttl'=>'300'], ['ttl'=>null], ['topic'=>str_repeat('x',33)], ['topic'=>'bad topic'], ['topic'=>null],
    ['urgency'=>'instant'], ['urgency'=>null], ['headers'=>['Cookie'=>'secret']], ['client'=>null], ['url'=>'https://127.0.0.1/']] as $options) {
    $invalid = vp3_phone_push_transport_attempt_v1($fixture[0], $fixture[1], $fixture[2], $options, $unexpected);
    transport_check($invalid['code'] === 'invalid_options' && !$invalid['dispatched'], 'unsafe option is refused');
}
foreach (['[]', 'null', '{', "{\"x\":\"\xff\"}", str_repeat('x',2049)] as $payload) {
    $invalid = vp3_phone_push_transport_attempt_v1($fixture[0], $fixture[1], $payload, [], $unexpected);
    transport_check($invalid['code'] === 'invalid_payload' && !$invalid['dispatched'], 'invalid or oversized payload is refused');
}
foreach (['http://web.push.apple.com/token', 'https://127.0.0.1/token', 'https://example.test/push',
    'https://web.push.apple.com:443/token', 'https://web.push.apple.com/token?secret=x', 'https://web.push.apple.com/token#fragment'] as $endpoint) {
    $subscription = $fixture[0]; $subscription['endpoint'] = $endpoint;
    $invalid = vp3_phone_push_transport_attempt_v1($subscription, $fixture[1], $fixture[2], [], $unexpected);
    transport_check($invalid['code'] === 'invalid_subscription' && !$invalid['dispatched'], 'unrecognized endpoint cannot dispatch');
}
foreach (['file:///tmp/private', 'https://localhost/', "mailto:user@example.test\r\nX: secret"] as $subject) {
    $vapid = $fixture[1]; $vapid['subject'] = $subject;
    $invalid = vp3_phone_push_transport_attempt_v1($fixture[0], $vapid, $fixture[2], [], $unexpected);
    transport_check($invalid['code'] === 'invalid_vapid', 'unsafe VAPID subject cannot dispatch');
}
transport_check($calls === 0, 'input rejection performs no exchange');

$missingCache = $fixture[1]; unset($missingCache['authorization']);
$cacheResult = vp3_phone_push_transport_attempt_v1($fixture[0], $missingCache, $fixture[2], [], $unexpected);
transport_check($cacheResult['code'] === 'vapid_cache_unavailable' && !$cacheResult['dispatched'], 'missing cache cannot silently fall back to per-send signing');
$wrongAudience = $fixture[0]; $wrongAudience['endpoint'] = 'https://fcm.googleapis.com/fcm/send/SyntheticFixture';
$cacheResult = vp3_phone_push_transport_attempt_v1($wrongAudience, $fixture[1], $fixture[2], [], $unexpected);
transport_check($cacheResult['code'] === 'vapid_cache_unavailable', 'cached JWT is bound to the exact provider origin');
$wrongSubject = $fixture[1]; $wrongSubject['subject'] = 'mailto:changed@example.test';
$cacheResult = vp3_phone_push_transport_attempt_v1($fixture[0], $wrongSubject, $fixture[2], [], $unexpected);
transport_check($cacheResult['code'] === 'vapid_cache_unavailable', 'cached JWT is bound to the current configured subject');
preg_match('/^vapid t=([^,]+), k=/', $fixture[1]['authorization'], $parts);
$jwtParts = explode('.', $parts[1]);
$jwtParts[2][0] = $jwtParts[2][0] === 'A' ? 'B' : 'A';
$tampered = $fixture[1]; $tampered['authorization'] = 'vapid t='.implode('.',$jwtParts).', k='.$tampered['public_key'];
$cacheResult = vp3_phone_push_transport_attempt_v1($fixture[0], $tampered, $fixture[2], [], $unexpected);
transport_check($cacheResult['code'] === 'vapid_cache_unavailable', 'tampered cached signature cannot dispatch');
$otherKey = vp3_phone_push_keypair_v1() + ['subject'=>$fixture[1]['subject']];
$otherKey['authorization'] = str_replace(', k='.$fixture[1]['public_key'], ', k='.$otherKey['public_key'], $fixture[1]['authorization']);
$cacheResult = vp3_phone_push_transport_attempt_v1($fixture[0], $otherKey, $fixture[2], [], $unexpected);
transport_check($cacheResult['code'] === 'vapid_cache_unavailable', 'matching header key text cannot bypass actual signature verification');
transport_check($calls === 0, 'cache validation failures perform no exchange');

foreach (['127.0.0.1','10.0.0.1','100.64.0.1','169.254.169.254','172.16.0.1','192.168.0.1','192.0.2.1',
    '192.88.99.1','198.18.0.1','198.51.100.1','203.0.113.1','224.0.0.1','240.0.0.1','0.0.0.0','::1','::ffff:127.0.0.1',
    'fd00::1','fe80::1','2001:db8::1','2001::1','2002:7f00:1::','3fff::1','64:ff9b::7f00:1'] as $ip) {
    transport_check(!vp3_phone_push_transport_public_ip_v1($ip), 'non-public address blocked: '.$ip);
    $blocked = vp3_phone_push_transport_exchange_v1($wire, static fn(): array => [['ip'=>'17.188.164.65'], ['ip'=>$ip]], $unexpected);
    transport_check($blocked['code'] === 'dns_not_public' && !$blocked['dispatched'], 'mixed public/private DNS refuses all dispatch');
}
foreach (['17.188.164.65', '142.250.191.74', '2607:f8b0:4007:80f::200a'] as $ip) {
    transport_check(vp3_phone_push_transport_public_ip_v1($ip), 'public provider-style address is supported');
}
$emptyDns = vp3_phone_push_transport_exchange_v1($wire, static fn(): array => [], $unexpected);
transport_check($emptyDns['state'] === 'retry' && !$emptyDns['dispatched'], 'DNS absence is a safe retry before sending');
$badCname = vp3_phone_push_transport_exchange_v1($wire, static fn(): array => [['type'=>'CNAME','target'=>'local.test','ip'=>'127.0.0.1']], $unexpected);
transport_check($badCname['code'] === 'dns_not_public', 'CNAME annotation cannot hide a private address');
transport_check($calls === 0, 'blocked DNS performs no HTTPS call');

$resolutions = 0; $attempts = 0;
$redirect = vp3_phone_push_transport_exchange_v1($wire,
    static function (string $host) use (&$resolutions): array {
        ++$resolutions;
        transport_check($host === 'web.push.apple.com', 'DNS is requested only for the validated service host');
        return [['type'=>'CNAME','target'=>'example.cdn.test'], ['ipv6'=>'2607:f8b0:4007:80f::200a'], ['ip'=>'17.188.164.65']];
    }, static function (array $request, string $pin, int $timeout) use (&$attempts): array {
        ++$attempts;
        transport_check($pin === 'web.push.apple.com:443:17.188.164.65', 'DNS answer is pinned while retaining TLS hostname');
        transport_check($timeout > 0 && $timeout <= 8000, 'request timeout is bounded');
        $options = vp3_phone_push_transport_curl_options_v1($request, $pin, $timeout, static fn()=>0, static fn()=>0);
        transport_check($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_MAXREDIRS] === 0, 'actual cURL policy forbids redirects');
        transport_check($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'actual cURL policy verifies TLS certificate and hostname');
        transport_check($options[CURLOPT_PROXY] === '' && $options[CURLOPT_NOPROXY] === '*' && $options[CURLOPT_NETRC] === CURL_NETRC_IGNORED, 'actual cURL policy ignores environment proxy and netrc credentials');
        transport_check($options[CURLOPT_RESOLVE] === [$pin] && $options[CURLOPT_CONNECTTIMEOUT_MS] <= 3000, 'actual cURL policy pins the checked IP and bounds connection time');
        $protocolKey = defined('CURLOPT_PROTOCOLS_STR') ? constant('CURLOPT_PROTOCOLS_STR') : CURLOPT_PROTOCOLS;
        transport_check($options[$protocolKey] === (defined('CURLOPT_PROTOCOLS_STR') ? 'https' : CURLPROTO_HTTPS), 'actual cURL policy permits HTTPS only');
        return transport_observation(302, ['location'=>'https://127.0.0.1/private']);
    });
transport_check($redirect['code'] === 'provider_redirect_blocked' && $attempts === 1 && $resolutions === 1, 'a redirect is terminal, with no second lookup or request');
$changedRoute = vp3_phone_push_transport_exchange_v1($wire, static fn(): array=>[['ip'=>'17.188.164.65']],
    static fn(): array=>transport_observation(201, ['primary_ip'=>'127.0.0.1']));
transport_check($changedRoute['state'] === 'uncertain' && $changedRoute['code'] === 'transport_route_unverified', 'an unexpected connection route never reports success');

foreach ([200=>'accepted',201=>'accepted',202=>'accepted',204=>'accepted',404=>'expired',410=>'expired',429=>'retry',503=>'retry',
    301=>'failed',307=>'failed',400=>'failed',401=>'failed',403=>'failed',408=>'uncertain',413=>'failed',500=>'uncertain',502=>'uncertain',504=>'uncertain'] as $status=>$state) {
    $result = vp3_phone_push_transport_classify_v1(transport_observation($status), 1700000000);
    transport_check($result['state'] === $state && $result['http_status'] === $status && $result['dispatched'] === true, 'provider status '.$status.' maps safely');
}
$preSend = ['response_complete'=>false, 'request_size'=>0, 'uploaded_bytes'=>0, 'pretransfer_us'=>0];
foreach ([CURLE_COULDNT_RESOLVE_HOST=>'retry', CURLE_COULDNT_CONNECT=>'retry', CURLE_OPERATION_TIMEDOUT=>'retry', CURLE_SSL_CACERT=>'failed'] as $errno=>$state) {
    $result = vp3_phone_push_transport_classify_v1(transport_observation(0, $preSend + ['curl_errno'=>$errno]), 1700000000);
    transport_check($result['state'] === $state && !$result['dispatched'], 'proven pre-send failure is distinct from an uncertain upload');
}
$timeout = vp3_phone_push_transport_classify_v1(transport_observation(0, ['response_complete'=>false, 'curl_errno'=>CURLE_OPERATION_TIMEDOUT]), 1700000000);
transport_check($timeout['state'] === 'uncertain' && $timeout['dispatched'] && $timeout['retry_after'] === 0, 'timeout after upload cannot replay automatically');
$oversize = vp3_phone_push_transport_classify_v1(transport_observation(201, ['response_limit'=>true, 'response_complete'=>false, 'curl_errno'=>CURLE_WRITE_ERROR]), 1700000000);
transport_check($oversize['state'] === 'uncertain' && $oversize['retry_after'] === 0, 'bounded response abort never claims accepted or schedules replay');
$thrown = vp3_phone_push_transport_attempt_v1(...[...$fixture, static function(): never { throw new RuntimeException('secret endpoint and authorization'); }]);
transport_check($thrown['state'] === 'uncertain' && !str_contains(json_encode($thrown), 'secret'), 'unknown failures expose only fixed non-secret codes');

$response = [];
[$readHeaders, $readBody] = vp3_phone_push_transport_response_readers_v1($response);
$readHeaders(null, "HTTP/1.1 100 Continue\r\n");
$readHeaders(null, "Retry-After: 123\r\n");
transport_check(($response['retry_after'] ?? null) === null, 'interim response headers are not used');
$readHeaders(null, "HTTP/2 503\r\n");
$readHeaders(null, "Retry-After: 120\r\n");
transport_check($response['retry_after'] === '120', 'final HTTP/2 retry header is recognized');
transport_check($readBody(null, str_repeat('s',8192)) === 8192 && $readBody(null, 's') === 0 && $response['response_limit'], 'actual response reader stops at the 8 KiB body limit');
transport_check(!str_contains(json_encode($response), str_repeat('s',20)), 'response bodies are never retained');
$response = [];
[$readHeaders, $readBody] = vp3_phone_push_transport_response_readers_v1($response);
transport_check($readHeaders(null, str_repeat('h',16384)) === 16384 && $readHeaders(null, 'h') === 0 && $response['response_limit'], 'actual header reader stops at the 16 KiB header limit');

foreach ([['120',120],['0',1],['9999999999',3600],['-1',60],['1.5',60],['tomorrow',60],['Tue, 14 Nov 2023 22:15:20 GMT',120],
    ['Tue, 14 Nov 2023 22:12:00 GMT',1],[null,60]] as [$input,$expected]) {
    transport_check(vp3_phone_push_transport_retry_after_v1($input,1700000000) === $expected, 'Retry-After is parsed and bounded');
}
echo 'Phone push transport: '.$checks." checks passed\n";
