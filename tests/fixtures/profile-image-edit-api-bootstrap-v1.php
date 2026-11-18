<?php
declare(strict_types=1);
define('STONEFELLOW_ROOT',dirname((string)getenv('VP3_PA3_CONFIG')).'/private-site');
define('VP3_PROFILE_IMAGE_TEST_HELPERS_ONLY',true);
require (string)getenv('VP3_PA3_ROOT').'/tests/profile-image-edit-v1.php';
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->sqliteCreateFunction('GREATEST',static fn(...$values)=>max($values));
session_start();$fixtureUser=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);
if($fixtureUser>0){$row=pa3_owner($fixtureUser);$_SESSION['user_id']=$fixtureUser;$_SESSION['auth_password_fingerprint']=auth_password_fingerprint($fixtureUser,(string)$row['password_hash']);}
else unset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']);
reset_current_user_cache();
function agent_brain_schema_ready(): bool{return false;}
function personal_profile_migrate_legacy_artist_v242(PDO $pdo,array $user): void{}
