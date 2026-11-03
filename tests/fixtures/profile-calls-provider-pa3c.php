<?php
declare(strict_types=1);

// Isolated real settings SQL and actual installation-key encryption. Only the provider HTTP is simulated.
$pcRoot=sys_get_temp_dir().'/vp3-profile-call-provider-'.bin2hex(random_bytes(8));mkdir($pcRoot.'/private',0700,true);
define('STONEFELLOW_ROOT',$pcRoot);file_put_contents($pcRoot.'/private/ai-key.php','<?php return '.var_export(base64_encode(random_bytes(32)),true).';');
chmod($pcRoot.'/private/ai-key.php',0600);
register_shutdown_function(static function()use($pcRoot){@unlink($pcRoot.'/private/ai-key.php');@rmdir($pcRoot.'/private');@rmdir($pcRoot);});
$dsn=getenv('VP3_TEST_MYSQL_DSN');
if($dsn){
    $user=getenv('VP3_TEST_MYSQL_USER')?:'root';$pass=getenv('VP3_TEST_MYSQL_PASS')?:'root';$admin=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $database='vp3_call_provider_'.bin2hex(random_bytes(6));$admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static fn()=>$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
    $dsn=preg_match('/dbname=[^;]+/',$dsn)?preg_replace('/dbname=[^;]+/','dbname='.$database,$dsn):rtrim($dsn,';').';dbname='.$database;
    $pdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}else $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NOT NULL)');
function db(): PDO{return $GLOBALS['pdo'];}
function setting(string $key,mixed $fallback=''): mixed{$q=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute([$key]);$value=$q->fetchColumn();return $value===false?$fallback:$value;}
function vp3_profile_call_configuration_changed_pa3c(PDO $pdo): array{$GLOBALS['pcInvalidations']=($GLOBALS['pcInvalidations']??0)+1;return ['queued'=>0];}
require_once dirname(__DIR__,2).'/includes/profile-calls-livekit-pa3c.php';
$config=['livekit'=>['url'=>'wss://old-meetings.livekit.cloud','api_key'=>'MEETING_KEY_SENTINEL','api_secret'=>'MEETING_SECRET_SENTINEL_LONG']];
function pc_settings(array $extra=[]): array{return $extra+['source'=>'profile','enabled'=>'1','human_only_acknowledged'=>'1','url'=>'wss://profile-first.livekit.cloud','api_key'=>'API_PROFILE_FIRST','api_secret'=>'SECRET_PROFILE_FIRST_123456789'];}
function pc_response(mixed $value,int $status=200): array{return ['status'=>$status,'body'=>json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)];}
function pc_claims(string $token): array{$parts=explode('.',$token);return json_decode(base64_decode(strtr($parts[1],'-_','+/')),true,32,JSON_THROW_ON_ERROR);}
function pc_http(string $url,array $headers,string $body,int $timeout): array
{
    $payload=json_decode($body,true,32,JSON_THROW_ON_ERROR);$method=basename($url);$auth=substr($headers[0],22);$request=compact('url','headers','body','timeout','payload','method','auth');$GLOBALS['pcRequests'][]=$request;
    if($hook=$GLOBALS['pcHttpHook']??null){$changed=$hook($request);if($changed!==null)return $changed;}
    switch($method){
        case 'CreateRoom':$GLOBALS['pcRoom']=(object)['sid'=>'RM_profileFixture','name'=>$payload['name'],'maxParticipants'=>2,'metadata'=>$payload['metadata']];return pc_response($GLOBALS['pcRoom']);
        case 'ListRooms':return pc_response((object)['rooms'=>isset($GLOBALS['pcRoom'])?[$GLOBALS['pcRoom']]:[]]);
        case 'ListParticipants':return pc_response((object)['participants'=>$GLOBALS['pcParticipants']??[]]);
        case 'RemoveParticipant':return pc_response((object)[]);
        case 'DeleteRoom':unset($GLOBALS['pcRoom']);return pc_response((object)[]);
        default:throw new LogicException('Unexpected provider method');
    }
}
function pc_reset(): array
{
    db()->exec('DELETE FROM settings');$GLOBALS['pcHttpHook']=null;$GLOBALS['pcRequests']=[];$GLOBALS['pcParticipants']=[];$GLOBALS['pcInvalidations']=0;unset($GLOBALS['pcRoom']);
    vp3_profile_call_livekit_save_pa3c(db(),pc_settings());vp3_profile_call_livekit_verify_pa3c(db(),'pc_http');$snapshot=vp3_profile_call_livekit_snapshot_pa3c();$id=str_repeat('a',32);
    $GLOBALS['pcRequests']=[];
    return ['call_id'=>1,'public_id'=>$id,'room_name'=>'vp3-profile-'.$id,'owner_identity'=>'owner-'.$id,'visitor_identity'=>'visitor-'.$id,'mode'=>'audio','provider_config_fingerprint'=>$snapshot['config_fingerprint'],'provider_snapshot'=>$snapshot['encrypted'],'room_sid'=>'','revoke_before_sec'=>time()+1];
}
function pc_participant(array $binding,string $role='owner'): object
{
    return (object)['sid'=>'PA_'.$role.'Fixture','identity'=>$binding[$role.'_identity'],'state'=>'ACTIVE','permission'=>(object)['canPublish'=>true,'canSubscribe'=>true,'canPublishSources'=>['MICROPHONE']],'tracks'=>[(object)['sid'=>'TR_'.$role.'Audio','source'=>'MICROPHONE','type'=>'AUDIO']]];
}
function pc_options(array $extra=[]): array{$now=(int)floor(microtime(true)*1000);return $extra+['operation_deadline_ms'=>$now+60000,'role'=>'owner','join_intent_id'=>str_repeat('c',32),'microphone'=>true,'camera'=>false,'issued_at_ms'=>$now,'expires_at_ms'=>$now+60000];}
