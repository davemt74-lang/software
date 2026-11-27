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
if(($argv[1]??'')==='init'){
    pa3_reset();
    // The owner inbox reply returns the real dashboard aggregate as well as its
    // receipt. Extend this HTTP fixture's agent rows for that existing read.
    foreach([
        'agent_key'=>"VARCHAR(80) NOT NULL DEFAULT 'fixture'",
        'agent_role'=>"VARCHAR(80) NOT NULL DEFAULT 'assistant'",
        'engine_key'=>"VARCHAR(80) NOT NULL DEFAULT 'default'",
        'instructions'=>'TEXT', 'is_default'=>'INTEGER NOT NULL DEFAULT 0',
        'is_profile_agent'=>'INTEGER NOT NULL DEFAULT 0',
        'voice_enabled'=>'INTEGER NOT NULL DEFAULT 0',
        'created_at'=>'DATETIME', 'updated_at'=>'DATETIME',
    ] as $column=>$definition)if(!column_exists('user_agents',$column))$pdo->exec('ALTER TABLE user_agents ADD COLUMN '.$column.' '.$definition);
    // Exercise real owner Knowledge preview/save on the same answered request.
    // Install only the canonical storage needed by the ordinary note writer.
    require_once dirname(__DIR__,2).'/includes/profile-agent-knowledge-v1.php';
    foreach([
        'track_id'=>'INTEGER NULL','folder_id'=>'INTEGER NULL',
        'knowledge_scope'=>"VARCHAR(20) NOT NULL DEFAULT 'personal'",
        'file_name'=>"VARCHAR(255) NOT NULL DEFAULT ''",'file_path'=>"VARCHAR(500) NOT NULL DEFAULT ''",
        'file_type'=>"VARCHAR(40) NOT NULL DEFAULT 'personal_note'",'mime_type'=>"VARCHAR(100) NOT NULL DEFAULT 'text/plain'",
        'file_size'=>'INTEGER NOT NULL DEFAULT 0',
    ] as $column=>$definition)if(!column_exists('knowledge_items',$column))$pdo->exec('ALTER TABLE knowledge_items ADD COLUMN '.$column.' '.$definition);
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql){$pdo->exec('ALTER TABLE knowledge_items MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');$pdo->exec('ALTER TABLE knowledge_chunks MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');}
    $auto=$mysql?'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE artist_transcript_folders_v177(id $auto,created_by_user_id INTEGER NOT NULL,folder_name VARCHAR(80),sort_order INTEGER NOT NULL DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("INSERT INTO artist_transcript_folders_v177(id,created_by_user_id,folder_name) VALUES(10,1,'Owner answers'),(20,4,'Foreign private folder')");
    $pdo->exec("INSERT INTO role_permissions VALUES('member','personal_knowledge.access'),('member','personal_knowledge.manage')");
    shared_knowledge_index_ensure_schema_v236($pdo);
    vp3_profile_knowledge_install_v1($pdo);
    echo $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);exit;
}
if(($argv[1]??'')==='state'){
    $out=[];
    foreach(['profile_agent_conversations','profile_agent_messages','agent_attention_items','notifications','profile_agent_handoff_turns_pa3','profile_agent_handoff_deliveries_pa3','profile_agent_handoff_operations_pa3','profile_agent_response_settings_v1','profile_agent_owner_requests_v1','agent_workflow_runs','agent_workflow_receipts','chat_messages','knowledge_items','knowledge_chunks','profile_agent_knowledge_saves_v1','user_data_policies','user_agent_data_rules','role_permissions'] as $table)$out[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
throw new RuntimeException('Unsupported fixture command.');
