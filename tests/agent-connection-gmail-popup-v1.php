<?php
declare(strict_types=1);

// Exercise the production endpoint, view and session checks with an isolated
// SQLite connection adapter and fake Google exchange. No outbound OAuth calls.
$repo = dirname(__DIR__);
$root = sys_get_temp_dir().'/vp3-gmail-popup-'.bin2hex(random_bytes(8));
mkdir($root.'/includes', 0700, true);
mkdir($root.'/sessions', 0700);

function popup_check(bool $passed, string $message): void
{
    if (!$passed) throw new RuntimeException($message);
    $GLOBALS['checks']++;
}

function popup_request(string $path, ?array $data = null, string $cookie = '', array $headers = []): array
{
    global $address;
    $options = ['method'=>$data === null ? 'GET' : 'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>5];
    if ($cookie !== '') $headers['Cookie'] = $cookie;
    if ($data !== null) {
        $headers += ['Content-Type'=>'application/x-www-form-urlencoded'];
        $options['content'] = http_build_query($data);
    }
    $options['header'] = implode("\r\n", array_map(static fn($key,$value)=>$key.': '.$value, array_keys($headers), $headers));
    $body = file_get_contents('http://'.$address.$path, false, stream_context_create(['http'=>$options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
    return [(int)($status[1] ?? 0),(string)$body,implode("\n", $http_response_header ?? [])];
}

function popup_session(int $owner, string $cookie = '', bool $expire = false): array
{
    [$status,$body,$headers] = popup_request('/fixture-session.php', ['owner'=>$owner,'expire'=>$expire ? '1' : '0'], $cookie);
    if ($status !== 200) throw new RuntimeException('Could not initialize fixture session.');
    if ($cookie === '') {
        if (!preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/mi', $headers, $match)) throw new RuntimeException('Missing fixture cookie.');
        $cookie = $match[1];
    }
    return [$cookie,json_decode($body,true,512,JSON_THROW_ON_ERROR)];
}

$process = null;
$checks = 0;
try {
    foreach (['connection-gmail-popup.php','includes/agent-connection-gmail-popup-v1.php','includes/agent-connection-gmail-popup-view-v1.php'] as $file) {
        if (!copy($repo.'/'.$file, $root.'/'.$file)) throw new RuntimeException('Missing popup source file.');
    }
    $bootstrap = <<<'PHP'
<?php
declare(strict_types=1);
define('STONEFELLOW_ROOT', dirname(__DIR__));
session_save_path(STONEFELLOW_ROOT.'/sessions');
session_start();
function db(): PDO
{
    static $pdo;
    return $pdo ??= new PDO('sqlite:'.STONEFELLOW_ROOT.'/fixture.db', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function current_user(): ?array
{
    return !empty($_SESSION['fixture_owner']) ? ['id'=>(int)$_SESSION['fixture_owner'],'is_active'=>1] : null;
}
function has_permission(string $permission, ?array $user = null): bool
{
    return $permission === 'account.access' && !empty(($user ?? current_user())['id']);
}
function require_permission(string $permission): void
{
    if (!has_permission($permission)) { http_response_code(403); exit; }
}
PHP;
    file_put_contents($root.'/includes/bootstrap.php', $bootstrap."\nrequire_once ".var_export($repo.'/includes/functions.php',true).";\n");
    file_put_contents($root.'/fixture-session.php', <<<'PHP'
<?php
require __DIR__.'/includes/bootstrap.php';
$_SESSION['fixture_owner'] = (int)$_POST['owner'];
if (($_POST['expire'] ?? '') === '1') $_SESSION['connection_gmail_popup_v1']['expires'] = time()-1;
header('Content-Type: application/json');
echo json_encode(['csrf'=>csrf_token(),'attempt'=>$_SESSION['connection_gmail_popup_v1']['attempt'] ?? null],JSON_THROW_ON_ERROR);
PHP);
    file_put_contents($root.'/includes/agent-connections-v1.php', <<<'PHP'
<?php
declare(strict_types=1);
const VP3_GMAIL_READ_SCOPE_V1 = 'https://www.googleapis.com/auth/gmail.readonly';
const VP3_GMAIL_SEND_SCOPE_V1 = 'https://www.googleapis.com/auth/gmail.send';
class AgentConnectionExceptionV1 extends RuntimeException {}
class AgentConnectionGmailExceptionV1 extends RuntimeException {}
function agent_connections_schema_ready_v1(PDO $pdo): bool { return setting('ready','1') === '1'; }
function agent_connections_callback_v1(): string { return setting('callback','https://vp3.example.test/connection-oauth.php'); }
function agent_connections_gmail_popup_origin_v1(?string $callback = null): string
{
    $parts = parse_url($callback ?? agent_connections_callback_v1());
    return $parts['scheme'].'://'.$parts['host'];
}
function agent_connection_gmail_config_v1(): array
{
    return ['client_id'=>setting('client','popup-fixture.apps.googleusercontent.com'),'client_secret'=>'fixture-secret-never-public'];
}
function agent_connections_client_fingerprint_v1(): string
{
    return hash('sha256', agent_connection_gmail_config_v1()['client_id']);
}
function agent_connections_find_v1(PDO $pdo, int $owner, int|string $selector, bool $lock = false): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM grants WHERE owner_user_id=?');
    $stmt->execute([$owner]);
    $row = $stmt->fetch();
    return $row && ($selector === 'gmail' || (int)$row['id'] === $selector) ? $row : null;
}
function agent_connections_view_v1(array $row): array
{
    return ['id'=>(int)$row['id'],'status'=>$row['status'],'revision'=>hash('sha256',$row['authorization_key'])];
}
function agent_connection_gmail_exchange_popup_v1(string $origin, string $code): array
{
    db()->prepare('INSERT INTO exchange_calls VALUES (?,?)')->execute([$origin,$code]);
    if ($code === 'fixture-error') throw new AgentConnectionGmailExceptionV1('private Google error payload');
    return ['external_account_id'=>'popup-google-owner','account_email'=>'popup@example.test','access_token'=>'fixture-access-never-public',
        'refresh_token'=>$code === 'fixture-offline' ? '' : 'fixture-refresh-never-public','expires_in'=>3600];
}
function agent_connections_store_gmail_v1(int $owner, array $token, array $policy, string $expectedKey, string $fingerprint,
    ?string $callback = null, bool $allowExistingRefresh = false): void
{
    $row = agent_connections_find_v1(db(),$owner,'gmail');
    if ($expectedKey !== ($row['authorization_key'] ?? '') || $fingerprint !== agent_connections_client_fingerprint_v1()
        || $callback !== agent_connections_callback_v1()) throw new AgentConnectionExceptionV1('Fixture grant or setup changed.',409);
    if ($token['refresh_token'] === '') throw new AgentConnectionExceptionV1('Private refresh detail.',428);
    db()->prepare('INSERT INTO stores VALUES (?,?,?)')->execute([$owner,json_encode($policy,JSON_THROW_ON_ERROR),(int)$allowExistingRefresh]);
    db()->prepare("UPDATE grants SET authorization_key='updated-after-popup' WHERE owner_user_id=?")->execute([$owner]);
}
PHP);
    file_put_contents($root.'/includes/agent-connection-gmail-v1.php', "<?php require_once __DIR__.'/agent-connections-v1.php';");
    $pdo = new PDO('sqlite:'.$root.'/fixture.db', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE settings(setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL);
        CREATE TABLE grants(id INTEGER, owner_user_id INTEGER PRIMARY KEY, status TEXT, authorization_key TEXT);
        INSERT INTO grants VALUES (13,1,'connected','fixture-grant-1'),(24,2,'connected','fixture-grant-2');
        CREATE TABLE exchange_calls(origin TEXT, code TEXT);
        CREATE TABLE stores(owner_user_id INTEGER, policy TEXT, reused_refresh_enabled INTEGER);");
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    if ($socket === false) throw new RuntimeException('Could not reserve local test port.');
    $address = stream_socket_get_name($socket,false);
    fclose($socket);
    $command = [PHP_BINARY];
    if (php_ini_loaded_file()) array_push($command,'-c',php_ini_loaded_file());
    array_push($command,'-S',$address,'-t',$root);
    $process = proc_open($command, [0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start localhost fixture.');
    fclose($pipes[0]);
    for ($i=0;$i<100;$i++) {
        $client = @stream_socket_client('tcp://'.$address, $errno, $errstr, 0.1);
        if ($client !== false) { fclose($client); break; }
        usleep(10000);
    }

    $path = '/connection-gmail-popup.php';
    $origin = 'https://vp3.example.test';
    [$cookie,$session] = popup_session(1);
    $prepare = ['action'=>'prepare','provider'=>'gmail','csrf_token'=>$session['csrf'],'connection_id'=>'13',
        'revision'=>hash('sha256','fixture-grant-1'),'allow_read'=>'1','allow_send'=>'0','allow_followups'=>'1'];
    $ajax = ['Origin'=>$origin,'X-Requested-With'=>'XmlHttpRequest','Content-Type'=>'application/x-www-form-urlencoded; charset=UTF-8'];
    $exchanges = static fn()=>(int)$pdo->query('SELECT COUNT(*) FROM exchange_calls')->fetchColumn();
    $result = static fn(array $response)=>json_decode($response[1],true)['result'] ?? null;

    [$status,$html] = popup_request($path, null, $cookie);
    popup_check($status === 405 && !str_contains($html,'accounts.google.com/gsi/client'), 'GET must not open the popup or start an attempt.');
    [$status] = popup_request($path, array_replace($prepare,['csrf_token'=>'invalid']), $cookie);
    popup_check($status !== 200 && popup_session(1,$cookie)[1]['attempt'] === null, 'Prepare requires the current session CSRF token.');
    [$status] = popup_request($path, array_replace($prepare,['revision'=>str_repeat('0',64)]), $cookie);
    popup_check($status !== 200 && popup_session(1,$cookie)[1]['attempt'] === null, 'Prepare rejects a stale connection revision.');

    [$status,$html,$headers] = popup_request($path, $prepare, $cookie);
    $attempt = popup_session(1,$cookie)[1]['attempt'];
    popup_check($status === 200 && is_string($attempt) && preg_match('/\A[a-f0-9]{64}\z/D',$attempt) === 1,
        'Prepare renders the popup with a one-use session attempt.');
    popup_check(str_contains($html,$attempt) && str_contains($html,$origin) && str_contains($html,'popup-fixture.apps.googleusercontent.com')
        && !str_contains($html,'fixture-secret-never-public') && !str_contains($html,'fixture-grant-1'),
        'The launch page exposes only public OAuth configuration and the session attempt.');
    popup_check(str_contains(strtolower($headers),'cache-control: no-store')
        && str_contains(strtolower($headers),'cross-origin-opener-policy: same-origin-allow-popups')
        && str_contains($headers,"script-src 'self' https://accounts.google.com/gsi/client;")
        && str_contains($headers,"frame-ancestors 'none'"),
        'Launch responses are noncacheable and restrict scripts and framing while allowing the Google popup opener.');
    $finish = ['action'=>'finish','attempt'=>$attempt,'csrf_token'=>$session['csrf'],'code'=>'fixture-oauth-code'];
    $reject = static function (array $data, string $client, array $headers, string $label, int $expected = 400) use ($path,$exchanges,$result): void {
        $before = $exchanges();
        $response = popup_request($path,$data,$client,$headers);
        $safeResult = match ($expected) { 409=>'conflict',403=>'unavailable',default=>'invalid' };
        popup_check($response[0] === $expected && $result($response) === $safeResult && $exchanges() === $before, $label);
    };
    $reject($finish,'',$ajax,'Finish requires an authenticated member.',403);
    $reject($finish,$cookie,array_replace($ajax,['Origin'=>$origin.'.attacker.test','Host'=>'vp3.example.test.attacker.test']),
        'The configured origin is required even if the request Host is forged.');
    $reject($finish,$cookie,array_diff_key($ajax,['Origin'=>true]),'Finish requires a browser origin.');
    $reject($finish,$cookie,array_diff_key($ajax,['X-Requested-With'=>true]),'Finish requires the documented custom header.');
    $reject($finish,$cookie,$ajax+['Sec-Fetch-Site'=>'cross-site'],'Cross-site browser requests cannot finish a popup.');
    [$status,,$headers] = popup_request($path,$finish,$cookie,array_replace($ajax,['Content-Type'=>'text/plain']));
    popup_check(($status >= 400 || ($status === 302 && str_contains($headers,'result=invalid'))) && $exchanges() === 0,
        'Finish requires a form-encoded POST.');
    [$status] = popup_request($path.'?code=fixture-query-value',$finish,$cookie,$ajax);
    popup_check($status >= 400 && $exchanges() === 0,'Codes in the query string cannot reach Google.');
    $reject(array_replace($finish,['csrf_token'=>'wrong']),$cookie,$ajax,'Finish requires the same session CSRF token.');
    $reject(array_replace($finish,['attempt'=>str_repeat('f',64)]),$cookie,$ajax,'Finish requires the exact pending attempt.');
    $reject(array_replace($finish,['code'=>['not-a-code']]),$cookie,$ajax,'Finish rejects a malformed authorization code.');
    $reject($finish+['allow_send'=>'1'],$cookie,$ajax,'Finish rejects client changes to the captured policy.');
    [$otherCookie,$otherSession] = popup_session(1);
    $reject(array_replace($finish,['csrf_token'=>$otherSession['csrf']]),$otherCookie,$ajax,'The same VP3 owner cannot move an attempt to a different session.');
    popup_session(2,$cookie);
    $reject($finish,$cookie,$ajax,'An attempt cannot be used after a session switches to another VP3 owner.');
    popup_session(1,$cookie);

    $response = popup_request($path,$finish,$cookie,$ajax);
    popup_check($response[0] === 200 && $result($response) === 'connected' && $exchanges() === 1,
        'The valid same-origin finish consumes the attempt and exchanges exactly once.');
    popup_check(str_contains(strtolower($response[2]),'content-type: application/json')
        && !str_contains($response[1],$finish['code']) && !str_contains($response[1],'never-public'),
        'The finish response omits the code and provider credentials.');
    $stored = $pdo->query('SELECT * FROM stores')->fetch();
    popup_check($pdo->query('SELECT origin FROM exchange_calls')->fetchColumn() === $origin
        && (int)$stored['owner_user_id'] === 1 && (int)$stored['reused_refresh_enabled'] === 1
        && json_decode($stored['policy'],true) === ['read'=>true,'send'=>false,'followups'=>true],
        'Exchange and storage use the configured origin, original owner and captured checkbox policy.');
    $reject($finish,$cookie,$ajax,'A successful exchange cannot be replayed.');

    $prepare['revision'] = hash('sha256','updated-after-popup');
    $launch = static function () use ($path,$prepare,$cookie,$finish): array {
        [$status] = popup_request($path,$prepare,$cookie);
        if ($status !== 200) throw new RuntimeException('Could not prepare another popup attempt.');
        return array_replace($finish,['attempt'=>popup_session(1,$cookie)[1]['attempt']]);
    };
    $expired = $launch();
    popup_session(1,$cookie,true);
    $reject($expired,$cookie,$ajax,'An expired attempt never reaches Google.');
    $stale = $launch();
    $newer = $launch();
    $reject($stale,$cookie,$ajax,'A new popup launch supersedes the older attempt.');
    $pdo->exec("UPDATE grants SET authorization_key='changed-before-finish' WHERE owner_user_id=1");
    $reject($newer,$cookie,$ajax,'A connection changed after launch is rejected before provider access.',409);
    $pdo->exec("UPDATE grants SET authorization_key='updated-after-popup' WHERE owner_user_id=1");
    $staleSetup = $launch();
    $pdo->exec("INSERT INTO settings VALUES ('client','replacement-client.apps.googleusercontent.com')");
    $reject($staleSetup,$cookie,$ajax,'A changed OAuth client invalidates the pending attempt.',409);
    $pdo->exec("DELETE FROM settings WHERE setting_key='client'");

    $failure = array_replace($launch(),['code'=>'fixture-error']);
    $response = popup_request($path,$failure,$cookie,$ajax);
    popup_check($response[0] >= 400 && $result($response) === 'denied' && !str_contains($response[1],'private Google error payload'),
        'Google exchange failures have a safe public result.');
    $reject($failure,$cookie,$ajax,'An attempted provider exchange consumes the attempt even on failure.');
    $offline = array_replace($launch(),['code'=>'fixture-offline']);
    $response = popup_request($path,$offline,$cookie,$ajax);
    popup_check($response[0] >= 400 && $result($response) === 'offline' && !str_contains($response[1],'Private refresh detail'),
        'Missing offline access has a specific result without private provider detail.');
    popup_check((int)$pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn() === 1,'Rejected or failed attempts never modify the saved grant.');

    [$newCookie,$newSession] = popup_session(3);
    $fresh = ['action'=>'prepare','provider'=>'gmail','csrf_token'=>$newSession['csrf'],'allow_send'=>'1','allow_followups'=>'1'];
    [$status] = popup_request($path,$fresh,$newCookie);
    popup_check($status === 200,'A member without a prior Gmail grant can prepare a popup.');
    $newAttempt = popup_session(3,$newCookie)[1]['attempt'];
    $response = popup_request($path,array_replace($finish,['attempt'=>$newAttempt,'csrf_token'=>$newSession['csrf']]),$newCookie,$ajax);
    $stored = $pdo->query('SELECT * FROM stores WHERE owner_user_id=3')->fetch();
    popup_check($response[0] === 200 && $result($response) === 'connected' && $stored
        && json_decode($stored['policy'],true) === ['read'=>false,'send'=>true,'followups'=>false],
        'A first grant saves its own policy and cannot enable follow-ups without read access.');

    echo 'AGENT_CONNECTION_GMAIL_POPUP_V1=PASS ('.$checks.' assertions; real endpoint and session handling over HTTP)'.PHP_EOL;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($root);
}
