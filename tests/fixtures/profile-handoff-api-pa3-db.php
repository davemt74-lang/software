<?php
declare(strict_types=1);
$config=$argv[2]??throw new RuntimeException('Fixture configuration is required.');
if(($argv[1]??'')==='cleanup'){
    if(is_file($config)){
        $cfg=json_decode((string)file_get_contents($config),true,512,JSON_THROW_ON_ERROR);
        if(isset($cfg['database'])){
            if(!preg_match('/^vp3_profile_handoff_pa3_[a-f0-9]{12}$/D',$cfg['database']))throw new RuntimeException('Invalid fixture database.');
            (new PDO($cfg['admin_dsn'],$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]))->exec('DROP DATABASE IF EXISTS `'.$cfg['database'].'`');
        }
    }
    exit;
}
putenv('VP3_PA3_CONFIG='.$config);
require __DIR__.'/profile-handoff-pa3.php';
if(($argv[1]??'')==='init'){pa3_reset();echo $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);exit;}
if(($argv[1]??'')==='state'){
    $out=[];
    foreach(['profile_agent_conversations','profile_agent_messages','agent_attention_items','notifications','profile_agent_handoff_turns_pa3','profile_agent_handoff_deliveries_pa3','profile_agent_handoff_operations_pa3','agent_workflow_runs','agent_workflow_receipts','chat_messages'] as $table)$out[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
throw new RuntimeException('Unsupported fixture command.');
