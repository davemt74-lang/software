<?php
declare(strict_types=1);
require (string)getenv('VP3_PA3_ROOT').'/tests/fixtures/profile-visitor-speech-pa4a.php';
session_start();$fixtureUser=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);
if($fixtureUser>0){$row=pa3_owner($fixtureUser);$_SESSION['user_id']=$fixtureUser;$_SESSION['auth_password_fingerprint']=auth_password_fingerprint($fixtureUser,(string)$row['password_hash']);}
else unset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']);reset_current_user_cache();
if(isset($_SERVER['HTTP_X_FIXTURE_NOW']))$GLOBALS['pa3Now']=(int)$_SERVER['HTTP_X_FIXTURE_NOW'];
$GLOBALS['psReady']=($_SERVER['HTTP_X_FIXTURE_READY']??'1')==='1';$GLOBALS['psFingerprint']=hash('sha256','speech-config');$GLOBALS['psVoices']=ps_voices();$GLOBALS['psProviderCalls']=[];
$GLOBALS['psProviderMode']=match($_SERVER['HTTP_X_FIXTURE_PROVIDER_MODE']??''){'unknown'=>'outcome_unverified','rejected'=>'rejected',default=>'ready'};
function ps_http_log(string $operation,array $data=[]): void
{file_put_contents((string)getenv('VP3_PA4A_PROVIDER_LOG'),json_encode(['operation'=>$operation,'session_active'=>session_status()===PHP_SESSION_ACTIVE]+$data,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);}
function ps_http_session_change(bool $rotate=false): void
{
    $prior=$_SESSION;$original=session_id();session_start();
    if($rotate){session_regenerate_id(true);$row=pa3_owner(3);$_SESSION['user_id']=3;$_SESSION['auth_password_fingerprint']=auth_password_fingerprint(3,(string)$row['password_hash']);file_put_contents((string)getenv('VP3_PA4A_NEW_SESSION'),session_id(),LOCK_EX);}
    else unset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']);
    session_write_close();header_remove('Set-Cookie');session_id($original);$_SESSION=$prior;reset_current_user_cache();ini_set('session.use_strict_mode','1');
}
$GLOBALS['psCatalogHook']=static function(): void{
    ps_http_log('metadata');$mode=$_SERVER['HTTP_X_FIXTURE_PROVIDER_MODE']??'';
    if($mode==='logout_metadata'&&!isset($GLOBALS['psSessionChanged'])){$GLOBALS['psSessionChanged']=true;usleep(50000);ps_http_session_change();}
};
$GLOBALS['psProviderHook']=static function(array $request): void{
    ps_http_log('synthesize',['attempt_id'=>(int)$request['attempt_id'],'text'=>$request['text'],'voice_id'=>$request['voice_id']]);
    $mode=$_SERVER['HTTP_X_FIXTURE_PROVIDER_MODE']??'';
    if($mode==='throw')throw new RuntimeException('SECRET provider transport diagnostic');
    if($mode==='disable_profile')db()->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1');
    if($mode==='disable_agent')db()->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
    if($mode==='block_visitor')db()->exec('INSERT INTO user_blocks(blocker_user_id,blocked_user_id) VALUES(1,2)');
    if($mode==='logout_audio')ps_http_session_change();
    if($mode==='rotate_audio')ps_http_session_change(true);
};
// Provider storage is mocked here; the provider suite verifies actual private files,
// MP3 integrity, hashes, retention and no-I/O replay independently.
function vp3_profile_speech_audio_read_pa4a(array $attempt): string
{
    if((int)($attempt['audio_expires_at_ms']??0)<=vp3_profile_handoff_now_pa3())vp3_profile_speech_error_pa4a('PROFILE_SPEECH_AUDIO_EXPIRED',409);
    $audio=ps_audio();if(($attempt['audio_sha256']??null)!==hash('sha256',$audio)||(int)($attempt['audio_bytes']??0)!==strlen($audio))throw new RuntimeException('Fixture artifact proof mismatch.');
    ps_http_log('audio_read',['attempt_id'=>(int)$attempt['id']]);
    if(($_SERVER['HTTP_X_FIXTURE_PROVIDER_MODE']??'')==='logout_cached')ps_http_session_change();return $audio;
}
