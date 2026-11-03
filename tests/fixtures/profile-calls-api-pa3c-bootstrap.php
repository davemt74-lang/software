<?php
declare(strict_types=1);
require (string)getenv('VP3_PA3_ROOT').'/tests/fixtures/profile-calls-pa3c.php';
session_start();
$fixtureUser=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);
if($fixtureUser>0){$row=pa3_owner($fixtureUser);$_SESSION['user_id']=$fixtureUser;$_SESSION['auth_password_fingerprint']=auth_password_fingerprint($fixtureUser,(string)$row['password_hash']);}
else unset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']);
reset_current_user_cache();
if(isset($_SERVER['HTTP_X_FIXTURE_NOW']))$GLOBALS['pa3Now']=(int)$_SERVER['HTTP_X_FIXTURE_NOW'];
$GLOBALS['pa3cReady']=($_SERVER['HTTP_X_FIXTURE_READY']??'1')==='1';
$GLOBALS['pa3cFingerprint']=hash('sha256','original-provider');
$GLOBALS['pa3cProviderCalls']=[];$GLOBALS['pa3cProviderHook']=null;$GLOBALS['pa3cProviderMode']='';
$roomsPath=(string)getenv('VP3_PA3C_ROOMS');$GLOBALS['pa3cRooms']=is_file($roomsPath)?json_decode((string)file_get_contents($roomsPath),true,512,JSON_THROW_ON_ERROR):[];
$pdo->failSql=match($_SERVER['HTTP_X_FIXTURE_FAULT']??''){'offer'=>'INSERT INTO profile_calls_pa3c(','operation'=>'INSERT INTO profile_call_operations_pa3c',default=>''};
function vp3_profile_call_livekit_provider_pa3c(string $operation,array $binding,array $options=[]): array
{
    $log=(string)getenv('VP3_PA3C_PROVIDER_LOG');
    file_put_contents($log,json_encode(['operation'=>$operation,'call_id'=>$binding['call_id'],'session_active'=>session_status()===PHP_SESSION_ACTIVE],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    $mode=$_SERVER['HTTP_X_FIXTURE_PROVIDER_MODE']??'';
    if($mode==='error')throw new RuntimeException('SECRET provider SQLSTATE diagnostic');
    $result=pa3c_provider($operation,$binding,$options);
    file_put_contents((string)getenv('VP3_PA3C_ROOMS'),json_encode($GLOBALS['pa3cRooms'],JSON_THROW_ON_ERROR),LOCK_EX);
    if($mode==='disable_profile')db()->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1');
    if($mode==='disable_visitor')db()->exec('UPDATE users SET is_active=0 WHERE id=2');
    if($mode==='logout_after_token'&&$operation==='token'){
        // Simulate a concurrent browser logout while the original request has
        // released its session lock. Preserve its old in-memory snapshot.
        $prior=$_SESSION;session_start();unset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']);session_write_close();$_SESSION=$prior;reset_current_user_cache();
    }
    if($mode==='switch_session_after_token'&&$operation==='token'){
        // A different browser request has switched accounts and sent its new
        // cookie already. This delayed request still carries the deleted ID.
        $prior=$_SESSION;$original=session_id();session_start();session_regenerate_id(true);
        $user=pa3_owner(3);$_SESSION['user_id']=3;$_SESSION['auth_password_fingerprint']=auth_password_fingerprint(3,(string)$user['password_hash']);
        $newId=session_id();session_write_close();file_put_contents((string)getenv('VP3_PA3C_NEW_SESSION'),$newId,LOCK_EX);
        // Do not count the simulated other request's headers as this response.
        header_remove('Set-Cookie');session_id($original);$_SESSION=$prior;reset_current_user_cache();ini_set('session.use_strict_mode','1');
    }
    return $result;
}
