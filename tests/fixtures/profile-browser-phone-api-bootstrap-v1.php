<?php
declare(strict_types=1);
require (string)getenv('VP3_PA3_ROOT').'/tests/fixtures/profile-browser-phone-v1.php';
profile_phone_fixture_settings_v1();
session_start();
$fixtureUser=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);
if($fixtureUser>0){$row=pa3_owner($fixtureUser);$_SESSION['user_id']=$fixtureUser;$_SESSION['auth_password_fingerprint']=auth_password_fingerprint($fixtureUser,(string)$row['password_hash']);}
else unset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']);
reset_current_user_cache();
