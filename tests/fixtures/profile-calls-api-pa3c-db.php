<?php
declare(strict_types=1);
$action=$argv[1]??'';$config=$argv[2]??throw new RuntimeException('Fixture configuration required.');
if($action==='cleanup'){
    if(is_file($config)){$cfg=json_decode((string)file_get_contents($config),true,512,JSON_THROW_ON_ERROR);if(isset($cfg['database'])){
        if(!preg_match('/^vp3_profile_handoff_pa3_[a-f0-9]{12}$/D',$cfg['database']))throw new RuntimeException('Invalid isolated database.');
        (new PDO($cfg['admin_dsn'],$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]))->exec('DROP DATABASE IF EXISTS `'.$cfg['database'].'`');
    }}exit;
}
putenv('VP3_PA3_CONFIG='.$config);require __DIR__.'/profile-calls-pa3c.php';
if($action==='init'){pa3c_reset();echo db()->getAttribute(PDO::ATTR_DRIVER_NAME);exit;}
if($action==='state'){
    $out=[];foreach(['profile_agent_conversations','profile_agent_messages','profile_agent_handoff_turns_pa3','profile_agent_handoff_conversations_pa3','profile_calls_pa3c','profile_call_slots_pa3c','profile_call_operations_pa3c','profile_call_deliveries_pa3c','agent_workflow_runs','agent_workflow_receipts','chat_messages'] as $table)$out[$table]=db()->query('SELECT * FROM '.$table)->fetchAll();
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
if($action==='second_thread'){
    pa3_login(2);$_COOKIE=['sf_profile_visitor'=>str_repeat('b',64),'sf_profile_conversation'=>str_repeat('b',64)];
    $ctx=vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'fixture-owner'),current_user());
    $row=vp3_profile_handoff_atomic_pa3(db(),function()use($ctx){$ctx=vp3_profile_agent_public_live_v1(db(),$ctx,true);vp3_profile_agent_public_admit_v1(db(),$ctx);return vp3_profile_agent_public_create_bound_v1(db(),$ctx);});
    echo (int)$row['id'];exit;
}
if($action==='restore_profile'){db()->exec('UPDATE user_profiles SET profile_agent_enabled=1 WHERE user_id=1');exit;}
if($action==='restore_account'){db()->exec('UPDATE users SET is_active=1 WHERE id=2');exit;}
if($action==='age_presence'){db()->exec('UPDATE profile_agent_handoff_presence_pa3 SET expires_at_ms=1');exit;}
throw new RuntimeException('Unsupported fixture command.');
