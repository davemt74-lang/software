<?php
function workflow_agent_fixture_v1(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT OR IGNORE INTO users VALUES(1,1),(2,1)');
    $text=['workflow_type','origin','source_kind','source_key','source_hash','dedupe_key','title','goal','decision_summary','status','risk_level','approval_status','execution_target','capability_key','last_error_class','started_at','approved_at','completed_at','cancelled_at','created_at','updated_at','next_attempt_at','lease_owner','lease_token','lease_expires_at','heartbeat_at','progress_message','pause_requested_at','paused_at','paused_from_status'];
    $numbers=['agent_id','requires_approval','current_action_id','attempt_count','progress_percent','max_attempts','retry_backoff_seconds','timeout_seconds','work_priority'];
    $columns=['id INTEGER PRIMARY KEY','owner_user_id INTEGER'];foreach($text as $key)$columns[]="$key TEXT DEFAULT ''";foreach($numbers as $key)$columns[]="$key INTEGER DEFAULT 0";
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_workflow_runs('.implode(',',$columns).')');
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_workflow_actions(id INTEGER PRIMARY KEY,run_id INTEGER,owner_user_id INTEGER,sequence_no INTEGER,status TEXT,execution_target TEXT,requires_approval INTEGER,available_at TEXT,heartbeat_at TEXT,progress_percent INTEGER,progress_message TEXT,max_attempts INTEGER,timeout_seconds INTEGER);
      CREATE TABLE IF NOT EXISTS agent_workflow_events(id INTEGER PRIMARY KEY AUTOINCREMENT,run_id INTEGER,owner_user_id INTEGER,event_type TEXT,from_status TEXT,to_status TEXT,actor_kind TEXT,summary TEXT,metadata_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
      CREATE TABLE IF NOT EXISTS agent_workflow_action_dependencies(id INTEGER PRIMARY KEY);
      CREATE TABLE IF NOT EXISTS agent_workflow_receipts(id INTEGER PRIMARY KEY,run_id INTEGER,owner_user_id INTEGER,action_id INTEGER,executor TEXT,worker_id TEXT,attempt_no INTEGER,status TEXT,summary TEXT,error_class TEXT,created_at TEXT)");
    $q=$pdo->prepare('INSERT OR IGNORE INTO agent_workflow_runs(id,owner_user_id,agent_id,title,status,work_priority) VALUES(?,?,?,?,?,50)');
    foreach([41,51,61] as $id){$q->execute([$id,1,7,'Fixture queued work','approved']);$q->execute([$id+1,1,7,'Fixture executing work','executing']);}
    $q->execute([43,2,9,'SECRET foreign work','approved']);
}
