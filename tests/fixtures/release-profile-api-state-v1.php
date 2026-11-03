<?php
declare(strict_types=1);
// Isolated integration fixture only; this file is never a deployed route.
require __DIR__.'/includes/bootstrap.php';
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
header('Content-Type: application/json');
$pdo=db();$action=$_GET['action']??'';
$profileTables=['user_profiles','agent_scheduling_schedules','agent_scheduling_event_types','profile_webmcp_actions','agent_scheduling_bookings'];
$tables=['artist_workspaces_v181','artist_team_members','user_plugin_installations','release_plans','release_items'];
if($action==='reset_releases'){
    foreach(array_reverse($tables) as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
    unset($_SESSION['music_workspace_id']);
}
if($action==='select_workspace')$_SESSION['music_workspace_id']=(int)($_GET['id']??0);
if($action==='clear_workspace')unset($_SESSION['music_workspace_id']);
if($action==='bad_release')$pdo->exec("UPDATE release_plans SET status='SECRET_INVALID' WHERE id=101");
if($action==='move_conversation')$pdo->prepare('UPDATE chat_conversations SET user_agent_id=8 WHERE id=?')->execute([(int)($_GET['cid']??0)]);
if($action==='restore_conversation')$pdo->prepare('UPDATE chat_conversations SET user_agent_id=7 WHERE id=?')->execute([(int)($_GET['cid']??0)]);
if($action==='reset_profile'){
    foreach($profileTables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
    unset($_SESSION['vp3_profile_webmcp_resume_v194'],$_SESSION['vp3_profile_webmcp_action_context_v195'],$_SESSION['vp3_profile_webmcp_last_return_v195']);
}
$returned=null;
if(in_array($action,['browser_return','prepared_return','recorded_return'],true)){
    require_once __DIR__.'/includes/profile-webmcp-continuity-v194.php';
    require_once __DIR__.'/includes/agent-profile-webmcp-outcomes-v1.php';
    $user=current_user();$profile=vp3_profile_webmcp_owner_profile_v1($pdo,(int)$user['id']);
    $resume=end($_SESSION['vp3_profile_webmcp_resume_v194']);
    $context=vp3_profile_webmcp_context_for_native_v1($pdo,$profile,$user,['context_id'=>$resume['action_context_id'],'return_token'=>$resume['return_token']]);
    if($action==='browser_return')vp3_profile_webmcp_action_context_note_v195($profile,$user,$context['context_id'],$resume['return_token'],'vp3.booking.confirm','completed','success',true,$pdo);
    else{
        require_once __DIR__.'/includes/profile-webmcp-outcomes-v1.php';
        foreach(['profile_webmcp_actions','agent_scheduling_bookings','agent_commerce_orders_v800','merchant_accounts','campaigns','campaign_enrollments','crm_contacts','reward_issuances','reward_transfers'] as $table)if(table_exists($table))$pdo->exec('DELETE FROM '.$table);
        $cases=profile_webmcp_outcome_fixture_v1($pdo);$case=$cases['booking.create'];
        $pdo->exec('UPDATE profile_webmcp_actions SET created_at=UTC_TIMESTAMP()');
        $patch=vp3_profile_webmcp_server_evidence_v1($pdo,$profile,$context,$case['transport'],$case['spec'][0],[],$case['prepare']);
        $context=vp3_profile_webmcp_context_store_server_v1($pdo,$profile,$user,$context,$patch);
        if($action==='recorded_return'){
            $hash=hash('sha256','1|booking.create|'.$case['args']['idempotency_key']);
            vp3_profile_webmcp_action_commit_v150($pdo,$case['action']['id'],$hash,$case['spec'][2],$case['resultId'],$case['result']);
            $patch=vp3_profile_webmcp_server_evidence_v1($pdo,$profile,$context,$case['transport'],$case['spec'][1],$case['args'],$case['result']);
            vp3_profile_webmcp_context_store_server_v1($pdo,$profile,$user,$context,$patch);
        }
    }
    $returned=vp3_profile_webmcp_return_consume_v195($profile,$user,$resume['return_token'],$pdo);
}
if($action==='damage_profile_receipt')$pdo->exec("UPDATE profile_webmcp_actions SET result_json='SECRET_MALFORMED' WHERE operation='booking.create'");
$state=[];$counts=[];
foreach($tables as $table){$state[$table]=table_exists($table)?$pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC):null;$counts[$table]=$state[$table]===null?null:count($state[$table]);}
$q=$pdo->prepare('SELECT type,name,sql FROM sqlite_master WHERE tbl_name IN ('.implode(',',array_fill(0,count($tables),'?')).') ORDER BY name');$q->execute($tables);$state['schema']=$q->fetchAll(PDO::FETCH_ASSOC);
$created=table_exists('release_plans')?$pdo->query('SELECT id,workspace_id,title,target_date FROM release_plans WHERE id>201 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC):[];
$turns=$pdo->query("SELECT id,conversation_id,context_json FROM chat_messages WHERE role='user' AND context_json IS NOT NULL ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$profileState=[];foreach($profileTables as $table)$profileState[$table]=table_exists($table)?$pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC):null;
echo json_encode(['fingerprint'=>hash('sha256',json_encode($state,JSON_THROW_ON_ERROR)),'counts'=>$counts,'created'=>$created,'turns'=>$turns,'active_workspace'=>$_SESSION['music_workspace_id']??null,
    'profile_fingerprint'=>hash('sha256',json_encode($profileState,JSON_THROW_ON_ERROR)),'resume_count'=>count($_SESSION['vp3_profile_webmcp_resume_v194']??[]),'context_count'=>count($_SESSION['vp3_profile_webmcp_action_context_v195']??[]),'returned'=>$returned],JSON_THROW_ON_ERROR);
