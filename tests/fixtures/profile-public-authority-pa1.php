<?php
declare(strict_types=1);

/** Canonical existing Profile tables; no production schema changes are needed. */
function profile_public_authority_fixture_pa1(PDO $pdo): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $auto=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $messageId=$mysql?'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT':$auto;
    $long=$mysql?'LONGTEXT':'TEXT';
    $schemas=[
        'users'=>"id INTEGER PRIMARY KEY,display_name VARCHAR(190),email VARCHAR(190),role VARCHAR(40),is_active INTEGER,avatar_path VARCHAR(255),password_hash VARCHAR(255),last_login_at DATETIME",
        'user_agents'=>"id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name VARCHAR(190),is_active INTEGER",
        'user_profiles'=>"user_id INTEGER PRIMARY KEY,username VARCHAR(80),bio TEXT,is_public INTEGER,profile_agent_enabled INTEGER,profile_agent_id INTEGER,profile_agent_greeting TEXT,profile_agent_instructions TEXT,share_visit_identity INTEGER",
        'profile_visit_sessions'=>"id $auto,owner_user_id INTEGER,visitor_user_id INTEGER,session_key VARCHAR(64),identity_disclosed INTEGER DEFAULT 0,view_count INTEGER DEFAULT 0,first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,last_message_at DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(owner_user_id,session_key)",
        'profile_agent_conversations'=>"id $auto,owner_user_id INTEGER,profile_agent_id INTEGER,profile_session_id INTEGER,visitor_user_id INTEGER,status VARCHAR(30) DEFAULT 'open',last_summary VARCHAR(1000) DEFAULT '',started_at DATETIME DEFAULT CURRENT_TIMESTAMP,last_message_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'profile_agent_messages'=>"id $messageId,conversation_id INTEGER,sender_type VARCHAR(20),sender_user_id INTEGER,message TEXT,context_json $long,created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'profile_events'=>"id $auto,owner_user_id INTEGER,profile_session_id INTEGER,visitor_user_id INTEGER,profile_agent_id INTEGER,event_type VARCHAR(50),priority INTEGER,dedupe_key VARCHAR(64) UNIQUE,metadata_json $long,created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'agent_attention_items'=>"id $auto,owner_user_id INTEGER,source_event_id INTEGER UNIQUE,source_conversation_id INTEGER,profile_agent_id INTEGER,visitor_user_id INTEGER,attention_type VARCHAR(50),priority INTEGER,headline VARCHAR(190),summary TEXT,assessment TEXT,recommended_response TEXT,actions_json $long,context_json $long,status VARCHAR(20) DEFAULT 'pending',snoozed_until DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'notifications'=>"id $auto,user_id INTEGER,type VARCHAR(80),title VARCHAR(190),body TEXT,target_url VARCHAR(500),source_type VARCHAR(80),source_id INTEGER,is_read INTEGER DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'knowledge_items'=>"id INTEGER PRIMARY KEY,title VARCHAR(190),description TEXT,content_text TEXT,visibility VARCHAR(40),is_published INTEGER,created_by_user_id INTEGER,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'knowledge_chunks'=>"id INTEGER PRIMARY KEY,knowledge_id INTEGER,chunk_index INTEGER,chunk_text TEXT",
        'user_data_policies'=>"owner_user_id INTEGER,resource_type VARCHAR(100),resource_id VARCHAR(100),audience_scope VARCHAR(40),owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER",
        'user_agent_data_rules'=>"agent_id INTEGER,resource_type VARCHAR(100),resource_id VARCHAR(100),access_mode VARCHAR(40)",
        'user_relationships'=>"owner_user_id INTEGER,related_user_id INTEGER,relationship_scope VARCHAR(40),status VARCHAR(40)",
        'agent_memory_items'=>"id INTEGER PRIMARY KEY,user_id INTEGER,is_active INTEGER,memory_type VARCHAR(80),subject TEXT,memory_text TEXT,metadata_json $long,last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'role_permissions'=>"role VARCHAR(80),permission_key VARCHAR(100)",
        'vp3_radar_properties'=>"id INTEGER PRIMARY KEY,owner_user_id INTEGER,property_type VARCHAR(20),label VARCHAR(190),domain VARCHAR(190),public_key VARCHAR(40),secret_hash VARCHAR(64),verification_token VARCHAR(64),verified_at DATETIME,is_active INTEGER,created_at DATETIME,updated_at DATETIME",
    ];
    foreach($schemas as $table=>$columns)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.$columns.')');
    foreach(array_reverse(array_keys($schemas)) as $table)$pdo->exec('DELETE FROM '.$table);
    $pdo->exec("INSERT INTO users(id,display_name,email,role,is_active,avatar_path,password_hash) VALUES(1,'Fixture Owner','owner@example.test','member',1,'','owner-password'),(2,'Visitor A','a@example.test','member',1,'','visitor-a-password'),(3,'Visitor B','b@example.test','member',1,'','visitor-b-password'),(4,'Other Owner','other@example.test','member',1,'','other-password')");
    $pdo->exec("INSERT INTO user_agents VALUES(7,1,'Fixture Public Guide',1),(8,1,'Other Guide',1),(9,4,'Foreign Guide',1)");
    $pdo->exec("INSERT INTO user_profiles VALUES(1,'fixture-owner','Fixture public biography',1,1,7,'','Use approved facts',1),(2,'visitor-a','',1,0,NULL,'','',1),(3,'visitor-b','',1,0,NULL,'','',1),(4,'other-owner','',1,1,9,'','',1)");
    $pdo->exec("INSERT INTO knowledge_items(id,title,description,content_text,visibility,is_published,created_by_user_id) VALUES(101,'Fixture hours','','Public hours are Monday','public',1,1),(102,'Secret hours','','PRIVATE_KNOWLEDGE_SECRET','private',0,1),(103,'Foreign hours','','FOREIGN_KNOWLEDGE_SECRET','public',1,4)");
    $pdo->exec("INSERT INTO knowledge_chunks VALUES(201,101,0,'Public hours are Monday'),(202,102,0,'PRIVATE_KNOWLEDGE_SECRET'),(203,103,0,'FOREIGN_KNOWLEDGE_SECRET')");
    $pdo->exec("INSERT INTO user_data_policies VALUES(1,'knowledge','*','inherit',1,1,0),(1,'homeserver_knowledge','*','public',1,1,0)");
    $pdo->exec("INSERT INTO agent_memory_items(id,user_id,is_active,memory_type,subject,memory_text,metadata_json) VALUES(301,1,1,'transcript_analysis','Fixture meeting','Fixture shared memory','{}')");
    $pdo->exec("INSERT INTO role_permissions VALUES('member','profile_agent.access'),('member','profile_chat.access'),('member','agent_brain.access')");
    $q=$pdo->prepare("INSERT INTO vp3_radar_properties(id,owner_user_id,property_type,label,domain,public_key,verification_token,is_active) VALUES(44,1,'external','Fixture Site','example.test',?,?,1)");
    $q->execute([str_repeat('c',40),str_repeat('d',64)]);
}
