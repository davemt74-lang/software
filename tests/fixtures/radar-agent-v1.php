<?php
declare(strict_types=1);

/** Existing Radar storage only; the caller owns its user, Agent and conversation fixtures. */
function radar_agent_fixture_v1(PDO $pdo,string $mode=''): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$ignore=$mysql?'INSERT IGNORE':'INSERT OR IGNORE';$auto=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY';
    // These three definitions match the Analytics fixture so either lazy fixture can run first.
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_radar_properties(id INTEGER PRIMARY KEY,owner_user_id INTEGER,property_type VARCHAR(20),label VARCHAR(190),domain VARCHAR(190),public_key VARCHAR(40),secret_hash VARCHAR(64),verification_token VARCHAR(64),verified_at DATETIME,is_active INTEGER,created_at DATETIME,updated_at DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_radar_sessions(id INTEGER PRIMARY KEY,property_id INTEGER,owner_user_id INTEGER,agent_contact_id INTEGER,session_key VARCHAR(64),visitor_type VARCHAR(40),entry_path VARCHAR(500),exit_path VARCHAR(500),referrer_host VARCHAR(190),request_count BIGINT,page_view_count BIGINT,event_count BIGINT,started_at DATETIME,last_seen_at DATETIME,ended_at DATETIME,metadata_json TEXT)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_radar_events(id $auto,owner_user_id INTEGER,property_id INTEGER,session_id INTEGER,agent_contact_id INTEGER,event_type VARCHAR(80),severity VARCHAR(20),path VARCHAR(500),method VARCHAR(12),status_code INTEGER,significance_score INTEGER,risk_score INTEGER,summary VARCHAR(500),details_json TEXT,occurred_at DATETIME)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_agent_registry(id $auto,operator_name VARCHAR(120) DEFAULT '',agent_name VARCHAR(120),slug VARCHAR(160),visitor_class VARCHAR(40),purpose VARCHAR(190),user_agent_pattern VARCHAR(500),verification_method VARCHAR(40),metadata_json TEXT,is_active INTEGER DEFAULT 1)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_agent_contacts(id $auto,owner_user_id INTEGER,agent_registry_id INTEGER,identity_key VARCHAR(64),display_name VARCHAR(190),operator_name VARCHAR(120) DEFAULT '',contact_type VARCHAR(30) DEFAULT 'agent',visitor_class VARCHAR(40) DEFAULT 'unknown',verification_status VARCHAR(30) DEFAULT 'unknown',confidence_score INTEGER DEFAULT 0,trust_score INTEGER DEFAULT 0,risk_score INTEGER DEFAULT 0,engagement_score INTEGER DEFAULT 0,value_score INTEGER DEFAULT 0,cost_score INTEGER DEFAULT 0,relationship_status VARCHAR(30) DEFAULT 'new',inferred_intent VARCHAR(120) DEFAULT '',intent_confidence INTEGER DEFAULT 0,session_count BIGINT DEFAULT 0,request_count BIGINT DEFAULT 0,page_view_count BIGINT DEFAULT 0,referral_count BIGINT DEFAULT 0,conversion_count BIGINT DEFAULT 0,first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,metadata_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_agent_policies(id $auto,owner_user_id INTEGER,property_id INTEGER,agent_contact_id INTEGER,operator_name VARCHAR(120) DEFAULT '',visitor_class VARCHAR(40) DEFAULT '',path_pattern VARCHAR(500) DEFAULT '*',action VARCHAR(20),priority INTEGER DEFAULT 100,expires_at DATETIME,metadata_json LONGTEXT,is_active INTEGER DEFAULT 1,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_agent_access_requests(id $auto,owner_user_id INTEGER,property_id INTEGER,agent_contact_id INTEGER,capability VARCHAR(80),purpose TEXT,status VARCHAR(30),expires_at DATETIME,metadata_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $at=(string)$pdo->query($mysql?'SELECT NOW()':"SELECT datetime('now')")->fetchColumn();
    $q=$pdo->prepare("$ignore INTO vp3_radar_properties(id,owner_user_id,property_type,label,domain,public_key,secret_hash,verification_token,verified_at,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach([[31,1,'Fixture Radar Site','radar.example'],[41,2,'FOREIGN Radar Site','foreign-radar.example']] as [$id,$owner,$label,$domain])$q->execute([$id,$owner,'external',$label,$domain,'SECRET public key','SECRET hash','SECRET verification token',$at,1,$at,$at]);
    $q=$pdo->prepare("$ignore INTO vp3_agent_registry(id,operator_name,agent_name,slug,visitor_class,user_agent_pattern,verification_method,metadata_json) VALUES(?,?,?,?,?,?,?,?)");
    foreach([[101,'Fixture GPTBot','fixture-gptbot','ai_crawler'],[102,'Fixture ChatGPT-User','fixture-chatgpt-user','ai_user_agent']] as [$id,$name,$slug,$class])$q->execute([$id,'Fixture OpenAI',$name,$slug,$class,'SECRET full user agent','signature','{"token":"SECRET registry token"}']);
    $q=$pdo->prepare("$ignore INTO vp3_agent_contacts(id,owner_user_id,agent_registry_id,identity_key,display_name,operator_name,visitor_class,verification_status,risk_score,session_count,request_count,page_view_count,last_seen_at,metadata_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach([[101,1,101,'Fixture GPTBot','Fixture OpenAI','ai_crawler',85,17,45],[102,1,102,'Fixture ChatGPT-User','Fixture OpenAI','ai_user_agent',12,7,19],[201,2,101,'FOREIGN Private Bot','FOREIGN Operator','ai_crawler',99,999,999]] as [$id,$owner,$registry,$name,$operator,$class,$risk,$sessions,$views])$q->execute([$id,$owner,$registry,'SECRET identity '.$id,$name,$operator,$class,'signature',$risk,$sessions,500,$views,$at,'{"token":"SECRET contact token","raw_ip":"192.0.2.90"}']);
    $q=$pdo->prepare("$ignore INTO vp3_agent_policies(id,owner_user_id,property_id,agent_contact_id,operator_name,visitor_class,path_pattern,action,priority,metadata_json,is_active) VALUES(?,?,?,?,?,?,?,?,?,?,1)");
    foreach([
        [101,1,null,101,'','','*','monitor',10,null],
        [102,1,null,102,'','','*','limit',10,'{"requests_per_30m":30}'],
        [103,1,null,null,'Fixture OpenAI','','*','monitor',50,'{"source":"scoped_rule","scope_type":"operator","scope_value":"Fixture OpenAI"}'],
        [104,1,31,null,'','ai_search','*','allow',50,'{"source":"scoped_rule","scope_type":"class","scope_value":"ai_search"}'],
        [110,1,null,null,'','','*','monitor',200,'{"source":"access_profile","profile_slug":"monitor_all","profile_label":"Monitor All"}'],
        [201,2,null,201,'','','*','block',10,null],
    ] as $row)$q->execute($row);
    $mode=$mode!==''?$mode:($_SERVER['HTTP_X_FIXTURE_RADAR_MODE']??'');
    if($mode==='empty'){$pdo->exec('DELETE FROM vp3_agent_policies WHERE owner_user_id=1');$pdo->exec('DELETE FROM vp3_agent_contacts WHERE owner_user_id=1');}
    if($mode==='bad_metadata')$pdo->exec("UPDATE vp3_agent_policies SET metadata_json='SECRET invalid JSON' WHERE id=102");
    if($mode==='foreign_property')$pdo->exec('UPDATE vp3_agent_policies SET property_id=41 WHERE id=104');
    if($mode==='missing_schema')$pdo->exec('DROP TABLE vp3_agent_policies');
    if($mode==='unverified_write'){
        $pdo->exec('DROP TRIGGER IF EXISTS radar_fixture_outcome_v1');
        $pdo->exec($mysql?"CREATE TRIGGER radar_fixture_outcome_v1 BEFORE INSERT ON vp3_agent_policies FOR EACH ROW SET NEW.action='monitor'":"CREATE TRIGGER radar_fixture_outcome_v1 AFTER INSERT ON vp3_agent_policies BEGIN UPDATE vp3_agent_policies SET action='monitor' WHERE id=NEW.id; END");
    }
}
