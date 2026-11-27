<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||$argc!==2)exit(1);
$root=$argv[1];
require $root.'/includes/bootstrap.php';
require_once $root.'/includes/phone-agent-workspace-v1.php';
require_once $root.'/includes/coding-canvas-store-v1.php';
$pdo=db();
$pdo->exec("CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name TEXT,is_active INTEGER);
    CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
    CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);
    CREATE TABLE artist_transcript_folders_v177(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,folder_name TEXT,sort_order INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(created_by_user_id,folder_name));
    CREATE TABLE knowledge_items(id INTEGER PRIMARY KEY,title TEXT,description TEXT,file_name TEXT,file_type TEXT,mime_type TEXT,file_size INTEGER,content_text TEXT,visibility TEXT,is_published INTEGER,created_by_user_id INTEGER,knowledge_scope TEXT,folder_id INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
vp3_phone_agent_workspace_install_v1($pdo);vp3_coding_canvas_schema_v1($pdo);
$owner=$pdo->query('SELECT * FROM users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$canvas='10000000-0000-4000-8000-000000000001';
vp3_coding_canvas_save_draft_v1($pdo,$owner,0,8,$canvas,'Phone checklist',[
    'html'=>'<h1>Phone checklist</h1><p id="result">Private saved canvas arrived.</p>','css'=>'body{font-family:system-ui}','js'=>''],[]);
echo json_encode(['canvas_id'=>$canvas,'revision'=>1,'conversation_id'=>8],JSON_THROW_ON_ERROR);
