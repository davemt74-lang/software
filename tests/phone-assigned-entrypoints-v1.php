<?php
declare(strict_types=1);

// Run the real member routes without HTTP, a database driver, or carrier access.
// Only their includes are fixtures; the route files are copied byte for byte.
$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/vp3-phone-assigned-entrypoints-'.bin2hex(random_bytes(12));
$checks=0;
// PHP_BINARY omits launcher flags used by portable PHP installations. Load only
// PDO in each child; the spy PDO needs no driver or application configuration.
$phpCommand=[PHP_BINARY,'-n'];
$pdoExtension=rtrim((string)ini_get('extension_dir'),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'pdo.'.PHP_SHLIB_SUFFIX;
if (is_file($pdoExtension)) array_push($phpCommand,'-d','extension='.$pdoExtension);

function phone_entrypoint_check_v1(bool $condition,string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL '.$message);
    $checks++;
}

function phone_entrypoint_cleanup_v1(string $directory): void
{
    if (!is_dir($directory)) return;
    foreach (new FilesystemIterator($directory,FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir()&&!$entry->isLink()) phone_entrypoint_cleanup_v1($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($directory);
}

try {
    mkdir($fixture,0700);
    mkdir($fixture.'/includes',0700);
    mkdir($fixture.'/api',0700);

    file_put_contents($fixture.'/includes/bootstrap.php', <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['phone_entrypoint_state']=['availability_checks'=>0,'protected_calls'=>[],'route_error'=>null];
$GLOBALS['phone_entrypoint_user']=[
    'id'=>123,'email'=>'registered@example.test','role'=>'member','status'=>'active','is_admin'=>0,'is_active'=>1,
];
function phone_entrypoint_unexpected_v1(string $operation): never
{
    $GLOBALS['phone_entrypoint_state']['protected_calls'][]=$operation;
    throw new RuntimeException('Protected operation ran before assigned-number denial: '.$operation);
}
final class PhoneEntrypointSpyPDO extends PDO
{
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {phone_entrypoint_unexpected_v1('PDO::prepare');}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {phone_entrypoint_unexpected_v1('PDO::query');}
    public function exec(string $statement): int|false
    {phone_entrypoint_unexpected_v1('PDO::exec');}
}
$GLOBALS['phone_entrypoint_pdo']=new PhoneEntrypointSpyPDO();
function current_user(): array {return $GLOBALS['phone_entrypoint_user'];}
function db(): PDO {return $GLOBALS['phone_entrypoint_pdo'];}
function has_permission(string $permission,?array $user=null): bool
{return in_array($permission,['account.access','chat.access'],true)&&($user['id']??0)===123;}
function require_permission(string $permission): void
{if (!has_permission($permission,current_user())) throw new RuntimeException('Fixture permission denied.');}
function verify_csrf(): bool {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function csrf_token(): string {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function flash(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function redirect(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function url(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_schema_ready_v100(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_member_state_v100(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_twilio_state_v100(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_twilio_search_v100(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_request_number_v100(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_sms_schema_ready_v110(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_sms_threads_v110(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_sms_messages_v110(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_sms_mark_read_v110(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_sms_outbound_ready_v120(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_sms_draft_v120(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_sms_authorize_send_v120(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_sms_monthly_quota_v120(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_sms_draft_owned_v120(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_sms_live_enabled_v120(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_voice_schema_ready_v130(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_voice_calls_v130(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_voice_ready_v140(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_twilio_credentials_v100(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function vp3_phone_recording_audio_any_v1(mixed ...$arguments): never {phone_entrypoint_unexpected_v1(__FUNCTION__);}
function artist_recording_playback_v243_prepare_transport(): void {}
PHP
    );
    file_put_contents($fixture.'/includes/phone-workspace-v1.php', <<<'PHP'
<?php
declare(strict_types=1);
function vp3_phone_workspace_available_v1(PDO $pdo,array $user): bool
{
    $GLOBALS['phone_entrypoint_state']['availability_checks']++;
    if ($pdo!==db()||$user!==current_user()
        ||!has_permission('account.access',$user)||!has_permission('chat.access',$user)) {
        throw new RuntimeException('Gate did not receive the authenticated fixture owner and PDO.');
    }
    return false;
}
PHP
    );
    foreach (['phone-cloud-v100.php','phone-sms-v110.php','phone-sms-outbound-v120.php',
        'phone-voice-v130.php','phone-voice-conversation-v140.php','phone-recording-pipeline-v1.php',
        'artist-recording-playback-v243.php'] as $include) {
        file_put_contents($fixture.'/includes/'.$include,"<?php\n// Protected functions are spies in the fixture bootstrap.\n");
    }

    file_put_contents($fixture.'/runner.php', <<<'PHP'
<?php
declare(strict_types=1);
$request=json_decode(base64_decode($argv[2],true),true,512,JSON_THROW_ON_ERROR);
$_SERVER['REQUEST_METHOD']=$request['method'];
$_SERVER['REQUEST_URI']='/'.$argv[1];
$_SERVER['CONTENT_TYPE']=str_starts_with($argv[1],'api/')?'application/json':'application/x-www-form-urlencoded';
$_GET=$request['query'];
$_POST=$request['payload'];
$_REQUEST=$_POST+$_GET;
$_SESSION=['user_id'=>123,'csrf_token'=>'assigned-entrypoint-fixture-csrf'];
ob_start();
register_shutdown_function(static function(): void {
    $body=ob_get_clean();
    echo json_encode([
        'status'=>http_response_code(),'body'=>$body,
        'state'=>$GLOBALS['phone_entrypoint_state']??null,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
});
try {require __DIR__.'/'.$argv[1];}
catch (Throwable $error) {
    $GLOBALS['phone_entrypoint_state']['route_error']=$error::class.': '.$error->getMessage();
    http_response_code(500);
}
PHP
    );

    $routes=[
        'phone.php'=>[],
        'phone-calls.php'=>['call'=>7],
        'phone-inbox.php'=>['thread'=>7,'draft'=>9],
        'api/phone-cloud-v100.php'=>[],
        'api/phone-sms-v110.php'=>['thread_id'=>7],
        'api/phone-sms-outbound-v120.php'=>['draft_id'=>9],
        'api/phone-voice-audio-v130.php'=>['id'=>7],
        'api/phone-recording-audio-v1.php'=>['id'=>'job-7'],
    ];
    $cases=[];
    foreach ($routes as $route=>$query) {
        if (!copy($root.'/'.$route,$fixture.'/'.$route)) throw new RuntimeException('Cannot copy route '.$route);
        phone_entrypoint_check_v1(hash_file('sha256',$root.'/'.$route)===hash_file('sha256',$fixture.'/'.$route),
            $route.' fixture executes the unchanged route');
        $cases[]=[$route,['method'=>'GET','query'=>$query,'payload'=>[]]];
    }
    $postCases=[
        ['phone.php',['action'=>'search','area_code'=>'602']],
        ['phone.php',['action'=>'request','agent_id'=>5,'number'=>'+16025550123']],
        ['phone-inbox.php',['action'=>'mark_read','thread_id'=>7]],
        ['phone-inbox.php',['action'=>'draft','phone_number_id'=>3,'recipient'=>'+16025550123',
            'body'=>'Fixture message','consent_source'=>'Explicit fixture recipient consent']],
        ['phone-inbox.php',['action'=>'send','draft_id'=>9,'confirmation'=>'SEND +16025550123']],
        ['api/phone-cloud-v100.php',['action'=>'search','area_code'=>'602']],
        ['api/phone-cloud-v100.php',['action'=>'request_number','agent_id'=>5,'number'=>'+16025550123']],
        ['api/phone-sms-v110.php',['action'=>'mark_read','thread_id'=>7]],
        ['api/phone-sms-outbound-v120.php',['action'=>'draft','phone_number_id'=>3,'recipient'=>'+16025550123',
            'body'=>'Fixture message','consent_source'=>'Explicit fixture recipient consent']],
        ['api/phone-sms-outbound-v120.php',['action'=>'send','draft_id'=>9,'confirmation'=>'SEND +16025550123']],
    ];
    foreach ($postCases as [$route,$payload]) {
        $payload['csrf_token']='assigned-entrypoint-fixture-csrf';
        $cases[]=[$route,['method'=>'POST','query'=>[],'payload'=>$payload]];
    }

    foreach ($cases as [$route,$request]) {
        $label=$route.' '.$request['method'].(isset($request['payload']['action'])?' '.$request['payload']['action']:'');
        $process=proc_open(array_merge($phpCommand,[
            '-d','date.timezone=UTC','-d','allow_url_fopen=0',
            '-d','disable_functions=curl_init,curl_exec,fsockopen,pfsockopen,stream_socket_client',
            $fixture.'/runner.php',$route,base64_encode(json_encode($request,JSON_THROW_ON_ERROR)),
        ]),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture);
        if (!is_resource($process)) throw new RuntimeException('Cannot start route fixture '.$label);
        // CLI has no HTTP request body; denial must happen before input parsing.
        fwrite($pipes[0],json_encode($request['payload'],JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
        $exitCode=proc_close($process);
        phone_entrypoint_check_v1($exitCode===0&&$stderr==='',$label.' clean process: '.$stderr);
        $response=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
        phone_entrypoint_check_v1(($response['state']['route_error']??null)===null,
            $label.' no route exception: '.($response['state']['route_error']??''));
        phone_entrypoint_check_v1(($response['state']['availability_checks']??0)===1,$label.' checks assignment');
        phone_entrypoint_check_v1(($response['state']['protected_calls']??null)===[],
            $label.' performs no action, history read, SMS send, or carrier/audio work');
        phone_entrypoint_check_v1($response['status']===403,$label.' returns HTTP 403');
        $body=$response['body'];
        if ($route==='api/phone-recording-audio-v1.php') {
            phone_entrypoint_check_v1($body==='',$label.' binary audio denial has no response body');
        } elseif (str_starts_with($route,'api/')) {
            $payload=json_decode($body,true,512,JSON_THROW_ON_ERROR);
            phone_entrypoint_check_v1(($payload['ok']??null)===false
                &&is_string($payload['error']??null)&&trim($payload['error'])!=='',$label.' returns a JSON error');
        } else {
            phone_entrypoint_check_v1(is_string($body)&&trim($body)!==''&&strip_tags($body)===$body
                &&json_decode($body,true)===null,$label.' returns a plain message');
        }
    }
    echo 'PHONE_ASSIGNED_ENTRYPOINTS_V1=PASS ('.count($cases).' requests, '.$checks.' checks)'.PHP_EOL;
} finally {
    phone_entrypoint_cleanup_v1($fixture);
}
