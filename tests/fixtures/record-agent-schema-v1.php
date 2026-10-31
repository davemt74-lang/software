<?php
function record_agent_fixture_v1(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_chat_archive(id INTEGER PRIMARY KEY,user_agent_id INTEGER);CREATE INDEX IF NOT EXISTS idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id);
      CREATE INDEX IF NOT EXISTS idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);CREATE INDEX IF NOT EXISTS idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,occurrence_count);
      CREATE TABLE IF NOT EXISTS artist_transcript_folders_v177(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,folder_name TEXT);INSERT OR IGNORE INTO artist_transcript_folders_v177 VALUES(51,1,'Fixture collection');
      CREATE TABLE IF NOT EXISTS knowledge_items(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,knowledge_scope TEXT,folder_id INTEGER,title TEXT,updated_at TEXT);INSERT OR IGNORE INTO knowledge_items VALUES(51,1,'personal',51,'Fixture pizza Knowledge','2030-01-01'),(52,2,'personal',NULL,'SECRET foreign Knowledge','2030-01-01');
      CREATE TABLE IF NOT EXISTS knowledge_chunks(id INTEGER PRIMARY KEY,knowledge_id INTEGER,chunk_index INTEGER,chunk_text TEXT);INSERT OR IGNORE INTO knowledge_chunks VALUES(51,51,0,'Fixture pizza supplier Knowledge'),(52,52,0,'SECRET pizza Knowledge')");
    $q=$pdo->prepare('INSERT OR IGNORE INTO agent_memory_items(id,user_id,user_agent_id,memory_scope_version,is_active,memory_type,subject,memory_text,memory_hash,metadata_json,confidence,occurrence_count,last_seen_at) VALUES(?,?,?,410,1,?,?,?,?,?,.9,1,?)');
    $q->execute([201,1,7,'preference','Fixture pizza memory','Fixture pizza Brain record','fixture-memory-201','{}',gmdate('c')]);$q->execute([202,1,8,'preference','SECRET other Agent memory','SECRET pizza','fixture-memory-202','{}',gmdate('c')]);
    foreach([210,220,230] as $id){$q->execute([$id,1,7,'task','Fixture pizza task '.$id,'Review pizza supplier','fixture-task-'.$id,json_encode(['task_status'=>'open','due_at'=>'2030-01-01']),gmdate('c')]);$q->execute([$id+1,1,8,'task','SECRET other Agent task','SECRET task','fixture-task-'.($id+1),json_encode(['task_status'=>'open','due_at'=>'2030-01-01']),gmdate('c')]);}
}
