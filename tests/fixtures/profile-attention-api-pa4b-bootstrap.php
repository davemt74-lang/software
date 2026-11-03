<?php
declare(strict_types=1);
require (string)getenv('VP3_PA3_ROOT').'/tests/fixtures/profile-attention-pa4b.php';
session_start();$fixtureUser=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);
if($fixtureUser>0){$row=pa3_owner($fixtureUser);$_SESSION['user_id']=$fixtureUser;$_SESSION['auth_password_fingerprint']=auth_password_fingerprint($fixtureUser,(string)$row['password_hash']);}
else unset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']);reset_current_user_cache();
if(isset($_SERVER['HTTP_X_FIXTURE_NOW']))$GLOBALS['pa3Now']=(int)$_SERVER['HTTP_X_FIXTURE_NOW'];
if(($_SERVER['HTTP_X_FIXTURE_FAILURE']??'')==='count')db()->failSql='SELECT COUNT(*) FROM notifications';
if(($_SERVER['HTTP_X_FIXTURE_FAILURE']??'')==='receipt')db()->failSql='INSERT INTO profile_attention_operations_pa4b';
if(($_SERVER['HTTP_X_FIXTURE_FAILURE']??'')==='late_agent')db()->afterSql=static function(string $sql,PA3PDO $p):void{if(str_starts_with($sql,'INSERT INTO chat_messages')){$p->afterSql=null;$p->exec('UPDATE user_agents SET is_active=0 WHERE id=7');}};
function e(mixed $value): string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf_field(): string{return '<input type="hidden" name="csrf_token" value="'.e(csrf_token()).'">';}
function flash(string $key,?string $message=null): ?string{if($message!==null){$_SESSION['flash'][$key]=$message;return null;}$value=$_SESSION['flash'][$key]??null;unset($_SESSION['flash'][$key]);return is_string($value)?$value:null;}
function redirect(string $target): never{header('Location: '.$target);exit;}
