<?php
function system_app_agent_fixture_v1(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_system_app_catalog(id INTEGER PRIMARY KEY,app_key TEXT,name TEXT,category TEXT,description TEXT,current_version TEXT,acquisition_mode TEXT,required_entitlement TEXT,homeserver_catalog_key TEXT,is_active INTEGER,sort_order INTEGER,metadata_json TEXT);
      INSERT OR IGNORE INTO vp3_system_app_catalog VALUES(1,'vp3.notes','VP3 Notes','Tools','','1.0.0','included',NULL,'notes',1,1,'{}'),(2,'vp3.feed','VP3 Feed','Tools','','0.0.1','included',NULL,'feed',1,2,'{}'),(3,'vp3.inventory','VP3 Inventory','Tools','','1.0.0','included',NULL,'inventory',1,3,'{}'),(4,'vp3.checklists','VP3 Checklists','Tools','','1.0.0','included',NULL,'checklists',1,4,'{}');
      CREATE TABLE IF NOT EXISTS vp3_system_app_ownership(id INTEGER PRIMARY KEY,user_id INTEGER,app_id INTEGER,status TEXT,source_kind TEXT,acquired_at TEXT,revoked_at TEXT);INSERT OR IGNORE INTO vp3_system_app_ownership VALUES(1,1,1,'active','fixture','',''),(2,1,2,'active','fixture','',''),(3,2,3,'active','fixture','',''),(4,1,4,'active','fixture','','');
      CREATE TABLE IF NOT EXISTS vp3_system_app_events(id INTEGER PRIMARY KEY);
      CREATE TABLE IF NOT EXISTS vp3_system_app_installations(id INTEGER PRIMARY KEY,user_id INTEGER,app_id INTEGER,observed_state TEXT,is_installed INTEGER,is_current INTEGER,update_available INTEGER,installed_version TEXT,homeserver_catalog_version TEXT,package_sha256 TEXT,last_remote_sync_at TEXT,last_install_at TEXT,last_error_message TEXT);
      INSERT OR IGNORE INTO vp3_system_app_installations VALUES(1,1,1,'running',1,1,0,'1.0.0','1.0.0',NULL,'2020-01-01 00:00:00',NULL,'SECRET stored native path'),(2,1,2,'running',1,0,1,'0.0.1','1.0.0',NULL,'2020-01-01 00:00:00',NULL,''),(3,2,3,'running',1,1,0,'1.0.0','1.0.0',NULL,'2030-01-01 00:00:00',NULL,'SECRET foreign installation');
      CREATE TABLE IF NOT EXISTS cloud_hosting_sites(id INTEGER PRIMARY KEY,user_id INTEGER,site_key TEXT,display_name TEXT,requested_hostname TEXT,canonical_hostname TEXT,desired_state TEXT,observed_state TEXT,desired_revision INTEGER,route_state TEXT,tls_state TEXT);
      INSERT OR IGNORE INTO cloud_hosting_sites VALUES(9,2,'foreign-site','SECRET foreign site','secret.example','secret.example','active','active',1,'active','active'),(10,1,'owned-site','Owned site','owned.example','owned.example','active','active',1,'pending','pending');
      CREATE TABLE IF NOT EXISTS vp3_system_app_hosting_bindings(id INTEGER PRIMARY KEY,user_id INTEGER,app_id INTEGER,site_id INTEGER,status TEXT,bound_at TEXT);INSERT OR IGNORE INTO vp3_system_app_hosting_bindings VALUES(1,1,1,9,'active',''),(2,1,2,10,'active','');
      CREATE TABLE IF NOT EXISTS homeserver_connections(user_id INTEGER PRIMARY KEY,status TEXT,last_seen_at TEXT,relay_token_enc TEXT,homeserver_token_enc TEXT);INSERT OR IGNORE INTO homeserver_connections VALUES(1,'connected','2020-01-01 00:00:00','SECRET token','SECRET token');");
}
function system_app_agent_remote_v1(int $uid,string $op,array $input=[]): array
{
    $GLOBALS['system_app_calls'][]=[$uid,$op,$input];$mode=$GLOBALS['system_app_mode']??($_SERVER['HTTP_X_FIXTURE_SYSTEM_APP_MODE']??'success');
    if($op!=='apps.system.permissions.status')throw new RuntimeException('Unexpected system app fixture operation.');
    if($mode==='offline')throw new RuntimeException('SECRET remote service failure');
    if($mode==='denied')throw new RuntimeException('SECRET remote denied',403);
    if(in_array($mode,['revoke','swap','ownership'],true)){
        $p=db();$guard=property_exists($p,'readGuard')?$p->readGuard:null;if($guard!==null)$p->readGuard=false;
        $p->exec(match($mode){'revoke'=>'UPDATE users SET is_active=0 WHERE id=1','swap'=>'UPDATE chat_conversations SET user_agent_id=8 WHERE id=1',default=>"UPDATE vp3_system_app_ownership SET status='revoked' WHERE user_id=1 AND app_id=1"});
        if($guard!==null)$p->readGuard=$guard;
    }
    $rows=$mode==='empty'?[]:[['permission'=>'hardware.camera','allowed'=>false],['permission'=>'network.external','allowed'=>true]];
    $r=['ok'=>true,'app_key'=>$mode==='identity'?'foreign-app':$input['app_key'],'permissions'=>$rows,'declared_count'=>count($rows),'allowed_count'=>$rows?1:0,'denied_count'=>$rows?1:0,'private_path'=>'SECRET permission path'];
    if($mode==='malformed')unset($r['permissions']);if($mode==='counts')$r['allowed_count']=999;if($mode==='row')$r['permissions'][0]['allowed']='yes';if($mode==='duplicate')$r['permissions'][1]['permission']=$r['permissions'][0]['permission'];
    return $r;
}
