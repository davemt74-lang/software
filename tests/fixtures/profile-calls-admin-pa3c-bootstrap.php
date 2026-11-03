<?php
declare(strict_types=1);
require (string)getenv('VP3_PA3_ROOT').'/tests/fixtures/profile-calls-provider-pa3c.php';
require_once (string)getenv('VP3_PA3_ROOT').'/includes/auth.php';
require_once (string)getenv('VP3_PA3_ROOT').'/includes/permissions.php';
// The settings page uses the real login/permission and encryption services;
// layout and the separately tested canonical call-closure service are isolated.
db()->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name VARCHAR(190),email VARCHAR(190),role VARCHAR(40),is_active INTEGER,avatar_path VARCHAR(255),password_hash VARCHAR(255),last_login_at DATETIME)');
db()->exec("INSERT INTO users(id,display_name,email,role,is_active,avatar_path,password_hash) VALUES(1,'Admin','admin@example.test','admin',1,'','fixture-admin-password'),(2,'Member','member@example.test','fan',1,'','fixture-member-password')");
db()->exec('CREATE TABLE permissions(permission_key VARCHAR(190) PRIMARY KEY)');db()->exec('CREATE TABLE role_permissions(role VARCHAR(40),permission_key VARCHAR(190))');
function e(mixed $value): string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $path): string{return $path;}
function redirect(string $path): never{header('Location: '.$path);exit;}
function flash(string $key,?string $message=null): ?string{if($message!==null){$_SESSION['flash'][$key]=$message;return null;}return null;}
function csrf_token(): string{return (string)($_SESSION['csrf_token']??'');}
function csrf_field(): string{return '<input type="hidden" name="csrf_token" value="'.e(csrf_token()).'">';}
function verify_csrf(): bool{return is_string($_POST['csrf_token']??null)&&hash_equals(csrf_token(),$_POST['csrf_token']);}
function vp3_profile_call_schema_ready_pa3c(PDO $pdo): bool{return ($_SERVER['HTTP_X_FIXTURE_SCHEMA']??'1')==='1';}
@mkdir(STONEFELLOW_ROOT.'/includes',0700);file_put_contents(STONEFELLOW_ROOT.'/includes/header.php','<html><body>');file_put_contents(STONEFELLOW_ROOT.'/includes/footer.php','</body></html>');
register_shutdown_function(static function(){@unlink(STONEFELLOW_ROOT.'/includes/header.php');@unlink(STONEFELLOW_ROOT.'/includes/footer.php');@rmdir(STONEFELLOW_ROOT.'/includes');@rmdir(STONEFELLOW_ROOT);});
pc_reset();$GLOBALS['pcInvalidations']=0;
session_start();$_SESSION['csrf_token']='profile-admin-fixture-csrf';$id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);
if($id>0){$q=db()->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$id]);$_SESSION['user_id']=$id;$_SESSION['auth_password_fingerprint']=auth_password_fingerprint($id,(string)$q->fetchColumn());}
else unset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']);reset_current_user_cache();
