<?php
declare(strict_types=1);

// Real settings, crypto, owner/clone SQL and private files. Only upstream HTTP is simulated.
$spRoot=sys_get_temp_dir().'/vp3-profile-speech-provider-'.bin2hex(random_bytes(8));mkdir($spRoot.'/private',0700,true);
define('STONEFELLOW_ROOT',$spRoot);file_put_contents($spRoot.'/private/ai-key.php','<?php return '.var_export(base64_encode(random_bytes(32)),true).';');chmod($spRoot.'/private/ai-key.php',0600);
register_shutdown_function(static function()use($spRoot){if(!is_dir($spRoot))return;$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($spRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($iterator as $file){if($file->isDir()&&!$file->isLink())@rmdir($file->getPathname());else @unlink($file->getPathname());}@rmdir($spRoot);});
$dsn=getenv('VP3_TEST_MYSQL_DSN');
if($dsn){
    $user=getenv('VP3_TEST_MYSQL_USER')?:'root';$pass=getenv('VP3_TEST_MYSQL_PASS')?:'root';$admin=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$database='vp3_speech_provider_'.bin2hex(random_bytes(6));$admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');register_shutdown_function(static fn()=>$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
    $dsn=preg_match('/dbname=[^;]+/',$dsn)?preg_replace('/dbname=[^;]+/','dbname='.$database,$dsn):rtrim($dsn,';').';dbname='.$database;$pdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}else $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach([
    'CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NOT NULL)',
    'CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER NOT NULL)',
    'CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER NOT NULL,is_active INTEGER NOT NULL,voice_enabled INTEGER NOT NULL)',
    'CREATE TABLE user_agent_voice_preferences(user_id INTEGER PRIMARY KEY,system_voice_id VARCHAR(128),system_voice_name VARCHAR(120))',
    'CREATE TABLE studio_participants(id INTEGER PRIMARY KEY,owner_user_id INTEGER,linked_user_id INTEGER,relationship_scope VARCHAR(20),cloning_consent INTEGER)',
    'CREATE TABLE studio_participant_voices(id INTEGER PRIMARY KEY,owner_user_id INTEGER,participant_id INTEGER,provider VARCHAR(20),clone_provider_voice_id VARCHAR(128),clone_enabled INTEGER,clone_verified INTEGER,consent_snapshot_at VARCHAR(30),revoked_at VARCHAR(30))',
    'CREATE TABLE profile_speech_attempts_pa4a(id INTEGER PRIMARY KEY,state VARCHAR(30),provider_state VARCHAR(30),audio_key CHAR(64),audio_bytes BIGINT,audio_sha256 CHAR(64),audio_expires_at_ms BIGINT,audio_removed_at_ms BIGINT)',
] as $sql)$pdo->exec($sql);
$pdo->exec("INSERT INTO users VALUES(1,1),(2,1)");$pdo->exec('INSERT INTO user_agents VALUES(11,1,1,0),(12,1,1,0),(21,2,1,1)');$pdo->exec("INSERT INTO user_agent_voice_preferences VALUES(1,'OwnerSystemVoice001','Owner system'),(2,'ForeignSystemVoice01','Foreign system')");
$pdo->exec("INSERT INTO studio_participants VALUES(101,1,1,'self',1),(202,2,2,'self',1),(103,1,2,'friend',1)");$pdo->exec("INSERT INTO studio_participant_voices VALUES(1,1,101,'elevenlabs','OwnerCloneVoice001',1,1,'2026-11-03 00:00:00',NULL),(2,2,202,'elevenlabs','ForeignCloneVoice01',1,1,'2026-11-03 00:00:00',NULL),(3,1,103,'elevenlabs','FriendCloneVoice001',1,1,'2026-11-03 00:00:00',NULL)");
function db(): PDO{return $GLOBALS['pdo'];}
function setting(string $key,mixed $fallback=''): mixed{$q=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute([$key]);$value=$q->fetchColumn();return $value===false?$fallback:$value;}
function sp_setting(string $key,string $value): void{$q=db()->prepare('DELETE FROM settings WHERE setting_key=?');$q->execute([$key]);$q=db()->prepare('INSERT INTO settings VALUES(?,?)');$q->execute([$key,$value]);}
require_once dirname(__DIR__,2).'/includes/ai-settings.php';
require_once (getenv('VP3_SPEECH_PROVIDER_FIXTURE_FILE')?:dirname(__DIR__,2).'/includes/profile-speech-elevenlabs-pa4a.php');
function sp_response(array $value,int $status=200): array{return ['status'=>$status,'headers'=>['content-type'=>'application/json'],'body'=>json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'complete'=>true];}
function sp_mp3(): string{return str_repeat("\xff\xfb\x90\x00".str_repeat("\0",413),4);}
function sp_voice(string $id='PublicVoiceGood001',array $replace=[]): array
{
    return $replace+['voice_id'=>$id,'name'=>'Public Visitor Voice','category'=>'professional','labels'=>['gender'=>'female'],'is_owner'=>false,'is_legacy'=>false,'safety_control'=>'NONE','sharing'=>['status'=>'enabled','enabled_in_library'=>true,'review_status'=>'allowed','public_owner_id'=>'PublicOwnerOrigin01','original_voice_id'=>'OriginalPublic001','disable_at_unix'=>null]];
}
function sp_http(string $method,string $url,array $headers,string $body,int $timeout,int $limit): array
{
    $record=compact('method','url','headers','body','timeout','limit');$GLOBALS['spCalls'][]=$record;
    if($hook=$GLOBALS['spHook']??null){$changed=$hook($record);if($changed!==null)return $changed;}
    if($method==='GET'&&str_contains($url,'/v2/voices?'))return sp_response(['voices'=>$GLOBALS['spDefaults'],'has_more'=>false,'next_page_token'=>null,'total_count'=>count($GLOBALS['spDefaults'])]);
    if($method==='GET'&&preg_match('#/v1/voices/([A-Za-z0-9_-]+)$#',$url,$m)){
        foreach($GLOBALS['spDefaults'] as $voice)if($voice['voice_id']===$m[1])return sp_response($voice);
        if($m[1]==='OwnerCloneVoice001')return sp_response(['voice_id'=>$m[1],'name'=>'Owner clone','category'=>'cloned','is_owner'=>true,'is_legacy'=>false]);
        return sp_response(sp_voice($m[1]));
    }
    if($method==='POST'&&str_contains($url,'/v1/text-to-speech/'))return ['status'=>200,'headers'=>['content-type'=>'audio/mpeg','request-id'=>'request-fixture-001','x-trace-id'=>'trace-fixture-001','character-cost'=>'12.5'],'body'=>sp_mp3(),'complete'=>true];
    throw new LogicException('Unexpected fixed provider request.');
}
function sp_reset(): void
{
    db()->exec('DELETE FROM settings');db()->exec('DELETE FROM profile_speech_attempts_pa4a');db()->exec('UPDATE users SET is_active=1');db()->exec('UPDATE user_agents SET is_active=1,voice_enabled=0');db()->exec('UPDATE studio_participants SET cloning_consent=1');db()->exec('UPDATE studio_participant_voices SET clone_enabled=1,clone_verified=1,revoked_at=NULL');
    putenv('ELEVENLABS_API_KEY=SECRET_ENVIRONMENT_NOT_SELECTED');putenv('ELEVENLABS_VOICE_ID');putenv('ELEVENLABS_MODEL_ID');putenv('ELEVENLABS_OUTPUT_FORMAT');
    sp_setting('ai_elevenlabs_api_key',ai_encrypt_secret('SECRET_SAVED_ELEVENLABS_KEY'));sp_setting('ai_elevenlabs_voice_id','NarratorVoice000001');sp_setting('ai_elevenlabs_model_id','eleven_flash_v2_5');
    $GLOBALS['spDefaults']=[
        ['voice_id'=>'DefaultMaleVoice001','name'=>'Male system voice','category'=>'premade','labels'=>['gender'=>'male'],'is_owner'=>false,'is_legacy'=>false],
        ['voice_id'=>'DefaultFemaleVoice1','name'=>'Female system voice','category'=>'premade','labels'=>['gender'=>'female'],'is_owner'=>false,'is_legacy'=>false],
        ['voice_id'=>'DefaultUnknownVoice','name'=>'Unclassified system','category'=>'premade','labels'=>[],'is_owner'=>false,'is_legacy'=>false],
        ['voice_id'=>'OwnerSystemVoice001','name'=>'Owner system','category'=>'premade','labels'=>['gender'=>'male'],'is_owner'=>false,'is_legacy'=>false],
    ];$GLOBALS['spCalls']=[];$GLOBALS['spHook']=null;unset($_SESSION['vp3_default_voice_catalog'],$GLOBALS['vp3ProfileSpeechMetadataDeadlinePa4a']);
}
function sp_request(array $replace=[]): array
{
    $config=vp3_profile_speech_config_pa4a();$catalog=vp3_profile_speech_catalog_pa4a('sp_http');$voice=null;foreach($catalog['voices'] as $row)if($row['voice_id']==='DefaultMaleVoice001')$voice=$row;
    if(!$voice)throw new LogicException('Fixture default voice missing.');
    return $replace+['attempt_id'=>1,'owner_user_id'=>1,'chat_agent_id'=>11,'profile_agent_id'=>12,'text'=>'A saved public message.','input_characters'=>23,'voice_id'=>$voice['voice_id'],'origin_hash'=>$voice['origin_hash'],'provenance'=>$voice['provenance'],'model_id'=>$config['model_id'],'output_format'=>$config['output_format'],'config_fingerprint'=>$config['config_fingerprint'],'attempt'=>['id'=>1,'owner_user_id'=>1,'chat_agent_id'=>11,'profile_agent_id'=>12]];
}
sp_reset();
