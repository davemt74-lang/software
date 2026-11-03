<?php
declare(strict_types=1);

/** Existing Analytics storage only. Users, Agents and conversations belong to the caller's fixture. */
function analytics_agent_fixture_v1(PDO $pdo,string $mode=''): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$ignore=$mysql?'INSERT IGNORE':'INSERT OR IGNORE';$auto=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY';
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_radar_properties(id INTEGER PRIMARY KEY,owner_user_id INTEGER,property_type VARCHAR(20),label VARCHAR(190),domain VARCHAR(190),public_key VARCHAR(40),secret_hash VARCHAR(64),verification_token VARCHAR(64),verified_at DATETIME,is_active INTEGER,created_at DATETIME,updated_at DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_radar_sessions(id INTEGER PRIMARY KEY,property_id INTEGER,owner_user_id INTEGER,agent_contact_id INTEGER,session_key VARCHAR(64),visitor_type VARCHAR(40),entry_path VARCHAR(500),exit_path VARCHAR(500),referrer_host VARCHAR(190),request_count BIGINT,page_view_count BIGINT,event_count BIGINT,started_at DATETIME,last_seen_at DATETIME,ended_at DATETIME,metadata_json TEXT)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_radar_events(id $auto,owner_user_id INTEGER,property_id INTEGER,session_id INTEGER,agent_contact_id INTEGER,event_type VARCHAR(80),severity VARCHAR(20),path VARCHAR(500),method VARCHAR(12),status_code INTEGER,significance_score INTEGER,risk_score INTEGER,summary VARCHAR(500),details_json TEXT,occurred_at DATETIME)");
    $pdo->exec('CREATE TABLE IF NOT EXISTS profile_visit_sessions(id INTEGER PRIMARY KEY,owner_user_id INTEGER,visitor_user_id INTEGER,session_key VARCHAR(64),identity_disclosed INTEGER,view_count INTEGER,first_seen_at DATETIME,last_seen_at DATETIME,last_message_at DATETIME,created_at DATETIME,updated_at DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS profile_events(id INTEGER PRIMARY KEY,owner_user_id INTEGER,profile_session_id INTEGER,visitor_user_id INTEGER,profile_agent_id INTEGER,event_type VARCHAR(50),priority INTEGER,dedupe_key VARCHAR(64),metadata_json TEXT,created_at DATETIME)');
    $at=(string)$pdo->query($mysql?'SELECT NOW()':"SELECT datetime('now')")->fetchColumn();$clock=new DateTimeImmutable($at,new DateTimeZone('UTC'));$prior=$clock->setTime(0,0)->modify('-1 second')->format('Y-m-d H:i:s');$old=$clock->modify('-100 days')->format('Y-m-d H:i:s');
    $q=$pdo->prepare("$ignore INTO vp3_radar_properties(id,owner_user_id,property_type,label,domain,public_key,secret_hash,verification_token,verified_at,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach([[11,1,'external','Fixture Kitchen Site','kitchen.example',1],[12,1,'external','Fixture Workshop Site','workshop.example',0],[13,1,'native','VP3 Profile','vp3.example',0],[21,2,'external','Foreign Private Site','foreign.example',1]] as [$id,$owner,$type,$label,$domain,$active])$q->execute([$id,$owner,$type,$label,$domain,'SECRET public key','SECRET secret hash','SECRET verification token',$at,$active,$at,$at]);
    $q=$pdo->prepare("$ignore INTO vp3_radar_sessions(id,property_id,owner_user_id,agent_contact_id,session_key,visitor_type,entry_path,exit_path,referrer_host,request_count,page_view_count,event_count,started_at,last_seen_at,metadata_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach([[101,11,1,null,'human','search.example'],[102,11,1,31,'ai_crawler','agent.example'],[103,12,1,null,'human','news.example'],[104,13,1,32,'ai_user_agent',''],[105,21,2,null,'human','foreign.example'],[106,21,1,null,'human','foreign.example'],[107,11,2,null,'human','foreign.example']] as [$id,$property,$owner,$contact,$type,$ref])$q->execute([$id,$property,$owner,$contact,'SECRET browser session',$type,'/SECRET/private','/SECRET/private',$ref,9999,9999,9999,$old,$at,'{"token":"SECRET session token","ip":"192.0.2.50"}']);
    $q=$pdo->prepare("$ignore INTO vp3_radar_events(id,owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $browser=static fn($event,$device='Desktop',$family='Chrome')=>json_encode(['collector'=>'browser_analytics','event_name'=>$event,'device_type'=>$device,'browser_family'=>$family,'label'=>'SECRET private event label','token'=>'SECRET analytics token'],JSON_THROW_ON_ERROR);
    foreach([
        [201,1,11,101,null,'analytics_page_view','/home',$browser('page_view'),$at],
        [202,1,11,101,null,'analytics_page_view','/products',$browser('page_view'),$at],
        [203,1,11,101,null,'analytics_event','/checkout',$browser('purchase'),$at],
        [204,1,11,102,31,'external_agent_request','/api/log','{}',$at],
        [205,1,11,102,31,'external_agent_page_view','/catalog','{}',$at],
        [206,1,12,103,null,'analytics_page_view','/welcome',$browser('page_view','Mobile','Safari'),$at],
        [207,1,13,104,32,'agent_profile_view','/profile/owner','{}',$at],
        [208,2,21,105,null,'analytics_page_view','/foreign',$browser('page_view'),$at],
        [209,1,21,106,null,'analytics_page_view','/foreign',$browser('page_view'),$at],
        [210,2,11,107,null,'analytics_page_view','/foreign',$browser('page_view'),$at],
        [211,1,11,101,null,'analytics_event','/old',$browser('purchase'),$old],
        [212,1,11,101,null,'analytics_page_view','/yesterday',$browser('page_view','Tablet','Firefox'),$prior],
    ] as [$id,$owner,$property,$session,$contact,$type,$path,$details,$time])$q->execute([$id,$owner,$property,$session,$contact,$type,'low',$path,'GET',200,20,0,'SECRET raw event summary',$details,$time]);
    $q=$pdo->prepare("$ignore INTO profile_visit_sessions(id,owner_user_id,visitor_user_id,session_key,identity_disclosed,view_count,first_seen_at,last_seen_at,last_message_at,created_at,updated_at) VALUES(?,?,NULL,?,0,5000,?,?,?,?,?)");
    foreach([[301,1],[302,2]] as [$id,$owner])$q->execute([$id,$owner,'SECRET long lived cookie hash',$old,$at,$at,$old,$at]);
    $q=$pdo->prepare("$ignore INTO profile_events(id,owner_user_id,profile_session_id,visitor_user_id,profile_agent_id,event_type,priority,dedupe_key,metadata_json,created_at) VALUES(?,?,?,NULL,NULL,?,10,?,?,?)");
    foreach([[401,1,301,'profile_view',$at],[402,1,301,'profile_view',$prior],[403,1,301,'message_received',$at],[404,2,302,'profile_view',$at],[405,1,302,'profile_view',$at]] as [$id,$owner,$session,$type,$time])$q->execute([$id,$owner,$session,$type,'fixture-'.$id,'{"contact_ref":"SECRET","entry_path":"/SECRET/private"}',$time]);
    $mode=$mode!==''?$mode:($_SERVER['HTTP_X_FIXTURE_ANALYTICS_MODE']??'');
    if($mode==='empty'){$pdo->exec('DELETE FROM vp3_radar_events WHERE owner_user_id=1');$pdo->exec('DELETE FROM vp3_radar_sessions WHERE owner_user_id=1');$pdo->exec('DELETE FROM profile_events WHERE owner_user_id=1');}
    if($mode==='bad_json')$pdo->exec("UPDATE vp3_radar_events SET details_json='SECRET invalid json' WHERE id=203");
    if($mode==='missing_schema')$pdo->exec('DROP TABLE vp3_radar_events');
}
