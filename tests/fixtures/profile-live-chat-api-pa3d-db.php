<?php
declare(strict_types=1);

/** SQL setup/inspection only: all HTTP authorization and transitions use production endpoints. */
$config=$argv[2]??throw new RuntimeException('Fixture configuration is required.');
if(!is_file($config))throw new RuntimeException('Initialize the PA3 fixture first.');
putenv('VP3_PA3_CONFIG='.$config);
require __DIR__.'/profile-handoff-pa3.php';
$action=$argv[1]??'';
if($action==='settings'){
    $mode=$argv[3]??'online';$social=(int)($argv[4]??1);
    $pdo->prepare('DELETE FROM team_user_presence WHERE user_id=?')->execute([1]);
    $pdo->prepare('INSERT INTO team_user_presence(user_id,presence_mode,social_chat_enabled) VALUES(?,?,?)')->execute([1,$mode,$social]);
    exit;
}
if($action==='age'){$pdo->exec("UPDATE profile_agent_messages SET created_at='2001-01-01 00:00:00'");exit;}
foreach(['settings'=>'team_user_presence','handoff'=>'profile_agent_handoff_deliveries_pa3'] as $name=>$table){
    if($action==='hide_'.$name){$pdo->exec('ALTER TABLE '.$table.' RENAME TO '.$table.'_pa3d_hidden');exit;}
    if($action==='restore_'.$name){$pdo->exec('ALTER TABLE '.$table.'_pa3d_hidden RENAME TO '.$table);exit;}
}
if($action==='snapshot'){
    $tables=['profile_agent_conversations','profile_agent_messages','profile_events','agent_attention_items','notifications',
        'profile_agent_handoff_conversations_pa3','profile_agent_handoff_turns_pa3','profile_agent_handoff_deliveries_pa3',
        'profile_agent_handoff_operations_pa3','profile_agent_handoff_presence_pa3','profile_agent_handoff_owners_pa3',
        'agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts','chat_messages','team_user_presence'];
    $out=['rows'=>[],'schema'=>[]];
    foreach($tables as $table)if(table_exists($table)){
        $rows=$pdo->query('SELECT * FROM '.$table)->fetchAll();
        usort($rows,static fn($a,$b)=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));
        $out['rows'][$table]=$rows;
    }
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $out['schema']=$pdo->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")->fetchAll();
    }else{
        $names=$pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN);
        foreach($names as $name)$out['schema'][$name]=preg_replace('/ AUTO_INCREMENT=\d+\b/','',$pdo->query('SHOW CREATE TABLE `'.str_replace('`','``',$name).'`')->fetch(PDO::FETCH_NUM)[1]);
    }
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
throw new RuntimeException('Unsupported fixture command.');
