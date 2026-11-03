<?php
declare(strict_types=1);
require (string)getenv('VP3_PA3_ROOT').'/tests/fixtures/profile-handoff-pa3.php';
session_start();
$fixtureUser=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);
if($fixtureUser>0){$row=pa3_owner($fixtureUser);$_SESSION['user_id']=$fixtureUser;$_SESSION['auth_password_fingerprint']=auth_password_fingerprint($fixtureUser,(string)$row['password_hash']);}
else unset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']);
reset_current_user_cache();
if(isset($_SERVER['HTTP_X_FIXTURE_NOW']))$GLOBALS['pa3Now']=(int)$_SERVER['HTTP_X_FIXTURE_NOW'];
$pdo->failSql=match($_SERVER['HTTP_X_FIXTURE_FAULT']??''){
    'turn'=>'INSERT INTO profile_agent_handoff_turns_pa3',
    'owner_message'=>"INSERT INTO profile_agent_messages(conversation_id,sender_type,sender_user_id,message,context_json,created_at) VALUES(?,'owner'",
    'delivery'=>'INSERT INTO profile_agent_handoff_deliveries_pa3',
    default=>'',
};
function agent_brain_schema_ready(): bool{return false;}
