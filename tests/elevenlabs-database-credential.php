<?php
declare(strict_types=1);
namespace ElevenLabsCredentialRegression;

// Execute production credential lookup and the complete voice endpoint in
// fresh PHP processes. Only authentication/bootstrap and outbound HTTP are
// fixtures. Settings use real SQLite, encryption and the installation key file.
$child=($argv[1]??'')==='--request';
$root=$child?$argv[2]:sys_get_temp_dir().'/vp3-eleven-key-'.bin2hex(random_bytes(6));
\define('STONEFELLOW_ROOT',$root);
$repo=dirname(__DIR__);
if(!$child){mkdir($root.'/private',0700,true);}
class FixturePDO extends \PDO {
    public function query(string $query,?int $fetchMode=null,mixed ...$args):\PDOStatement|false {
        if($query==="SHOW TABLES LIKE 'user_llm_credentials'")$query="SELECT name FROM sqlite_master WHERE type='table' AND name='user_llm_credentials'";
        return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args);
    }
}
$pdo=new FixturePDO('sqlite:'.$root.'/fixture.db',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
$requests=[];$checks=0;$environmentKey='fixture-environment-key';
$responseStatus=200;$responseBody='';$scenario=[];
foreach(['CURLOPT_POST','CURLOPT_HTTPGET','CURLOPT_RETURNTRANSFER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS','CURLOPT_FOLLOWLOCATION','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_BUFFERSIZE','CURLOPT_WRITEFUNCTION','CURLOPT_HEADERFUNCTION','CURLINFO_HTTP_CODE'] as $index=>$name){
    if(!\defined($name))\define($name,51000+$index);
}
function check(bool $ok,string $label):void{++$GLOBALS['checks'];if(!$ok)throw new \RuntimeException($label);}
function function_exists(string $name):bool{return \function_exists(__NAMESPACE__.'\\'.$name)||\function_exists($name);}
function getenv(string $name):string|false{return $name==='ELEVENLABS_API_KEY'?$GLOBALS['environmentKey']:false;}
function setting(string $name,mixed $fallback=''):mixed{
    $s=$GLOBALS['pdo']->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$s->execute([$name]);
    $v=$s->fetchColumn();return $v===false?$fallback:$v;
}
function save_setting(string $name,string $value):void{
    $GLOBALS['pdo']->prepare('INSERT INTO settings VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value')->execute([$name,$value]);
}
function db():\PDO{return $GLOBALS['pdo'];}
function current_user():?array{return !empty($GLOBALS['scenario']['unauthenticated'])?null:['id'=>7];}
function has_permission(string $name,?array $user=null):bool{return true;}
function chat_settings_agent_voice_enabled_v237(\PDO $pdo,array $user):bool{return true;}
function csrf_token():string{return 'fixture-csrf';}
function file_get_contents(string $path,mixed ...$args):string|false{
    return $path==='php://input'?json_encode(['action'=>'warm','csrf_token'=>!empty($GLOBALS['scenario']['bad_csrf'])?'wrong':'fixture-csrf']):\file_get_contents($path,...$args);
}
function curl_init(string $endpoint):object{return (object)['endpoint'=>$endpoint,'options'=>[]];}
function curl_setopt_array(object $ch,array $options):bool{$ch->options=$options;return true;}
function curl_setopt(object $ch,int $key,mixed $value):bool{$ch->options[$key]=$value;return true;}
function curl_getinfo(object $ch,int $key):int{return $GLOBALS['responseStatus'];}
function curl_error(object $ch):string{return '';}
function curl_close(object $ch):void{}
function curl_exec(object $ch):string|bool{
    $GLOBALS['requests'][]=['endpoint'=>$ch->endpoint,'options'=>$ch->options];
    if(isset($GLOBALS['scenario']['trace'])){
        // Fixture keys never leave this temporary directory; no credentials
        // are printed by the test or returned in a production JSON response.
        \file_put_contents($GLOBALS['scenario']['trace'],json_encode(['endpoint'=>$ch->endpoint,'headers'=>$ch->options[CURLOPT_HTTPHEADER]??[],'payload'=>$ch->options[CURLOPT_POSTFIELDS]??null]));
    }
    $raw=$GLOBALS['responseBody'];$status=$GLOBALS['responseStatus'];
    if(isset($ch->options[CURLOPT_WRITEFUNCTION])){
        ($ch->options[CURLOPT_HEADERFUNCTION])($ch,"HTTP/2 $status\r\n");
        ($ch->options[CURLOPT_HEADERFUNCTION])($ch,'Content-Type: '.($status===200?'audio/mpeg':'application/json')."\r\n");
        ($ch->options[CURLOPT_WRITEFUNCTION])($ch,$raw);return true;
    }
    return $raw;
}
function load_source(string $source):void{
    $source=substr($source,strpos($source,'declare(strict_types=1);')+strlen('declare(strict_types=1);'));
    eval('namespace '.__NAMESPACE__.'; use \\Throwable; use \\RuntimeException;'.$source);
}
load_source(\file_get_contents($repo.'/includes/ai-settings.php'));
$voiceSource=\file_get_contents($repo.'/api/agent-voice-v117.php');
$voiceSource=str_replace("require dirname(__DIR__) . '/includes/bootstrap.php';",'',$voiceSource);

if($child){
    $scenario=json_decode($argv[3],true,512,JSON_THROW_ON_ERROR);
    $responseStatus=$scenario['status']??200;
    $responseBody=$scenario['body']??json_encode(['voice_id'=>'fixture-voice','name'=>'Fixture Voice']);
    $_SERVER['REQUEST_METHOD']=($scenario['mode']??'warm')==='stream'?'GET':'POST';
    $_GET=[];$_SESSION=[];
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $token=str_repeat('a',48);$_GET['token']=$token;
        $_SESSION['stonefellow_voice_v117'][$token]=['user_id'=>7,'text'=>'Speak this fixture.','voice_id'=>'fixture-voice','model_id'=>'eleven_flash_v2_5','output_format'=>'mp3_22050_32','expires'=>time()+60];
    }
    load_source($voiceSource);exit;
}

function request(array $scenario):array{
    $trace=$GLOBALS['root'].'/trace-'.bin2hex(random_bytes(4)).'.json';$scenario['trace']=$trace;
    $cmd=[PHP_BINARY];
    if($ini=php_ini_loaded_file()){$cmd[]='-c';$cmd[]=$ini;}
    array_push($cmd,__FILE__,'--request',$GLOBALS['root'],json_encode($scenario));
    $p=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new \RuntimeException('Could not start voice request fixture.');
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($p)===0,'Voice request executes without a PHP fatal error: '.$err);
    $sent=is_file($trace)?json_decode(\file_get_contents($trace),true):null;
    return [$out,$sent];
}

try{
    $pdo->exec('CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT NOT NULL)');
    $key='fixture-database-key';$cipher=ai_encrypt_secret($key);
    save_setting('ai_elevenlabs_api_key',$cipher);save_setting('ai_elevenlabs_voice_id','fixture-voice');
    $master=\file_get_contents(ai_master_key_file());
    check(ai_elevenlabs_credential()===[$key,'saved'],'Saved database key wins over a conflicting environment key');
    $environmentKey='fixture-other-environment-key';
    check(ai_elevenlabs_credential()===[$key,'saved'],'Changing environment configuration cannot replace the saved key');
    [$raw,$sent]=request([]);$warm=json_decode($raw,true);
    check(in_array('xi-api-key: '.$key,$sent['headers']??[],true),'Actual menu readiness request sends the decrypted database key');
    check(($warm['credential_state']??'')==='saved'&&!empty($warm['ready']),'Menu reports the database credential source');
    check(!str_contains($raw,$key)&&!str_contains($raw,'fixture-environment-key'),'Menu response does not disclose either key');
    [$audio,$sent]=request(['mode'=>'stream','body'=>'ID3-fixture-audio']);
    check($audio==='ID3-fixture-audio','Actual speech endpoint forwards the successful audio bytes');
    check(in_array('xi-api-key: '.$key,$sent['headers']??[],true),'Actual TTS stream sends the same saved database key');
    check(str_contains($sent['endpoint']??'','/text-to-speech/fixture-voice/stream'),'Speech uses the configured voice and streaming endpoint');
    check((json_decode($sent['payload']??'',true)['model_id']??'')==='eleven_flash_v2_5','Speech preserves the saved model');
    foreach([
        ['missing_permissions','The API key is missing the permission voices_read to execute this operation.',true,''],
        ['missing_permissions','The API key is missing the permission text_to_speech to execute this operation.',false,'permission'],
        ['quota_exceeded','Quota exhausted.',false,'usage allowance'],
        ['invalid_api_key','Invalid key.',false,'rejected the API key'],
        ['voice_not_found','Voice missing.',false,'voice was not found'],
        ['unusual_activity','Restricted.',false,'security checks'],
        ['unknown_failure','fixture-private-value',false,'did not identify an invalid API key'],
    ] as [$code,$message,$ready,$expected]){
        $body=json_encode(['detail'=>['status'=>$code,'message'=>$message]]);
        [$raw,$sent]=request(['status'=>401,'body'=>$body]);$data=json_decode($raw,true);
        check(($data['ready']??null)===$ready,'Menu classifies provider code '.$code.' without relying on HTTP 401 alone');
        if($ready){
            check(($data['verified']??null)===false&&($data['readiness_authority']??'')==='tts-stream','Metadata-only permission denial allows a TTS attempt without claiming verification');
        }else{
            check(str_contains($data['error']??'',$expected),'Menu gives the specific safe failure for '.$code);
        }
        check(!str_contains($raw,'fixture-private-value'),'Provider body is not exposed in menu errors');
        if($code==='missing_permissions'||$code==='quota_exceeded'||$code==='invalid_api_key'){
            [$raw,$sent]=request(['mode'=>'stream','status'=>401,'body'=>$body]);$data=json_decode($raw,true);
            check(empty($data['ok'])&&str_contains($data['error']??'',$expected?:'permission'),'Real TTS rejection retains the correct classification');
        }
    }
    foreach([['unauthenticated'=>true],['bad_csrf'=>true]] as $blocked){
        [$raw,$sent]=request($blocked);check($sent===null&&empty(json_decode($raw,true)['ok']),'Permission and CSRF failures never send credentials upstream');
    }
    save_setting('ai_elevenlabs_api_key','sbx1:invalid');
    check(ai_elevenlabs_credential()===['','key_mismatch'],'Unreadable saved value cannot silently fall back to the environment key');
    [$raw,$sent]=request([]);check($sent===null&&(json_decode($raw,true)['credential_state']??'')==='key_mismatch','Unreadable database key produces no upstream request');
    save_setting('ai_elevenlabs_api_key',$cipher);unlink(ai_master_key_file());
    [$raw,$sent]=request([]);check($sent===null&&(json_decode($raw,true)['credential_state']??'')==='missing_key','Missing installation key is distinct from a provider authentication rejection');
    \file_put_contents(ai_master_key_file(),$master);
    save_setting('ai_elevenlabs_api_key','');
    check(ai_elevenlabs_credential()===[$environmentKey,'environment'],'Environment-only installations retain their existing fallback');
    $environmentKey='';check(ai_elevenlabs_credential()===['','missing'],'No configured key remains a configuration failure');
    save_setting('ai_elevenlabs_api_key',$cipher);
    check(\file_get_contents(ai_master_key_file())===$master&&setting('ai_elevenlabs_api_key')===$cipher,'Lookup and provider failures leave encryption key and saved ciphertext unchanged');
    foreach(['api/agent-voice-v102.php','api/extension-agent-voice-v2140.php','api/studio-voice-profile.php','api/studio-participants.php'] as $path){
        $source=\file_get_contents($repo.'/'.$path);
        check(str_contains($source,'ai_elevenlabs_credential()')&&!str_contains($source,"getenv('ELEVENLABS_API_KEY')"),'Credential consumer shares the canonical database-first resolver: '.$path);
    }
    echo 'ELEVENLABS_DATABASE_CREDENTIAL=PASS ('.$checks." assertions; real SQLite/crypto and production endpoint; outbound HTTP mocked)\n";
}finally{
    $paths=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
    foreach($paths as $path){if($path->isDir())rmdir($path->getPathname());else unlink($path->getPathname());}
    rmdir($root);
}
