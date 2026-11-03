<?php
declare(strict_types=1);
require (string)getenv('VP3_PA4_ROOT').'/tests/fixtures/profile-speech-provider-pa4a.php';
require_once (string)getenv('VP3_PA4_ROOT').'/includes/auth.php';
require_once (string)getenv('VP3_PA4_ROOT').'/includes/permissions.php';
db()->exec('DROP TABLE users');db()->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name VARCHAR(190),email VARCHAR(190),role VARCHAR(40),is_active INTEGER,avatar_path VARCHAR(255),password_hash VARCHAR(255),last_login_at DATETIME)');
db()->exec("INSERT INTO users(id,display_name,email,role,is_active,avatar_path,password_hash) VALUES(1,'Admin','admin@example.test','admin',1,'','fixture-admin-password'),(2,'Member','member@example.test','fan',1,'','fixture-member-password')");
db()->exec('CREATE TABLE permissions(permission_key VARCHAR(190) PRIMARY KEY)');db()->exec('CREATE TABLE role_permissions(role VARCHAR(40),permission_key VARCHAR(190))');
foreach(['owner_user_id BIGINT NOT NULL DEFAULT 1','input_characters INTEGER NOT NULL DEFAULT 0','provider_character_cost DECIMAL(20,6) NULL','delivery_state VARCHAR(20) NOT NULL DEFAULT \'available\'','last_reason VARCHAR(48) NOT NULL DEFAULT \'\'','created_at_ms BIGINT NOT NULL DEFAULT 0'] as $column)db()->exec('ALTER TABLE profile_speech_attempts_pa4a ADD COLUMN '.$column);
function e(mixed $value): string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $path): string{return $path;}
function redirect(string $path): never{header('Location: '.$path);exit;}
function flash(string $key,?string $message=null): ?string{if($message!==null)$_SESSION['flash'][$key]=$message;return null;}
function csrf_token(): string{return (string)($_SESSION['csrf_token']??'');}
function csrf_field(): string{return '<input type="hidden" name="csrf_token" value="'.e(csrf_token()).'">';}
function verify_csrf(): bool{return is_string($_POST['csrf_token']??null)&&hash_equals(csrf_token(),$_POST['csrf_token']);}
function vp3_profile_speech_schema_ready_pa4a(PDO $pdo): bool{return ($_SERVER['HTTP_X_FIXTURE_SCHEMA']??'1')==='1';}
if(($_SERVER['HTTP_X_FIXTURE_CONFIG']??'')==='model')sp_setting('ai_elevenlabs_model_id','bad/model SECRET_INVALID_MODEL');
if(($_SERVER['HTTP_X_FIXTURE_CONFIG']??'')==='format')putenv('ELEVENLABS_OUTPUT_FORMAT=pcm_44100');
@mkdir(STONEFELLOW_ROOT.'/includes',0700);file_put_contents(STONEFELLOW_ROOT.'/includes/header.php','<html><body>');file_put_contents(STONEFELLOW_ROOT.'/includes/footer.php','</body></html>');
$GLOBALS['spHook']=static function(array $request): ?array{
    $mode=$_SERVER['HTTP_X_FIXTURE_PROVIDER_MODE']??'';
    if($request['method']==='GET'&&str_contains($request['url'],'/v1/voices/')){
        if($mode==='private')return sp_response(sp_voice('PublicVoiceGood001',['is_owner'=>true]));
        if($mode==='changed_id')return sp_response(sp_voice('ForeignVoice000001'));
        if($mode==='error')return ['status'=>503,'headers'=>[],'body'=>'SECRET_PROVIDER_DIAGNOSTIC','complete'=>true];
        if($mode==='revoke_admin')db()->exec("UPDATE users SET role='fan' WHERE id=1");
    }
    return null;
};
session_start();$_SESSION['csrf_token']='profile-speech-admin-csrf';$id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);
if($id>0){$q=db()->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$id]);$_SESSION['user_id']=$id;$_SESSION['auth_password_fingerprint']=auth_password_fingerprint($id,(string)$q->fetchColumn());}else unset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']);reset_current_user_cache();
