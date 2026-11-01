<?php
function app_operation_fixture_v1(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_system_app_agent_actions(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT,user_id INTEGER,conversation_id INTEGER,app_id INTEGER,site_id INTEGER,action_type TEXT,payload_json TEXT,preview_json TEXT,confirmation_token_hash TEXT,idempotency_key TEXT,status TEXT,execution_token TEXT DEFAULT '',execution_expires_at TEXT,expires_at TEXT,confirmed_at TEXT,completed_at TEXT,result_json TEXT,error_message TEXT DEFAULT '',created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    // Full canonical persistence columns; the read fixture intentionally uses a minimal event table.
    if(!column_exists('vp3_system_app_events','user_id')){
        $pdo->exec('DROP TABLE vp3_system_app_events');
        $pdo->exec('CREATE TABLE vp3_system_app_events(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,app_id INTEGER,event_type TEXT,actor_type TEXT,actor_key TEXT,metadata_json TEXT)');
    }
    foreach(['last_error_code TEXT','remote_json TEXT','updated_at TEXT'] as $column)if(!column_exists('vp3_system_app_installations',strtok($column,' ')))$pdo->exec('ALTER TABLE vp3_system_app_installations ADD COLUMN '.$column);
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS app_install_owner_v1 ON vp3_system_app_installations(user_id,app_id)');
}
function app_operation_remote_v1(int $uid,string $op,array $payload=[]): array
{
    $GLOBALS['operation_calls'][]=[$uid,$op,$payload];$mode=$GLOBALS['operation_mode']??($_SERVER['HTTP_X_FIXTURE_OPERATION_MODE']??'success');
    if($mode==='offline')throw new RuntimeException('SECRET remote unavailable');
    if($mode==='denied')throw new RuntimeException('SECRET remote denied',403);
    if($mode==='revoke')db()->exec('UPDATE users SET is_active=0 WHERE id=1');
    if($mode==='swap')db()->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=1');
    if($mode==='ownership')db()->exec("UPDATE vp3_system_app_ownership SET status='revoked' WHERE user_id=1 AND app_id=1");
    if($op==='apps.manager.status')return ['items'=>[['app_key'=>'user.notes','name'=>'Fixture Notes','lifecycle_state'=>'running','installed'=>true,'available'=>true,'update_available'=>false,'hosted'=>false,'shared'=>false]],'counts'=>['installed'=>1,'available'=>1,'updates'=>0,'running'=>1,'hosted'=>0]];
    if($op==='apps.control.status'){
        $r=['app_key'=>$payload['app_key'],'compatibility'=>['compatible'=>true],'manifest'=>['actions'=>[['key'=>'notes.read']]],'settings'=>['schema'=>['fields'=>[]],'values'=>['SECRET native path'],'secret_values_exposed'=>false],'hosting'=>['count'=>0],'private'=>'SECRET source text'];
        if($mode==='missing')unset($r['manifest']);if($mode==='identity')$r['app_key']='foreign.app';if($mode==='duplicate')$r['manifest']['actions'][]=['key'=>'notes.read'];if($mode==='badbool')$r['compatibility']['compatible']='yes';
        return $r;
    }
    if(in_array($op,['apps.system.permissions.status','apps.system.permissions.set'],true)){
        $allowed=$op==='apps.system.permissions.set'?($payload['allowed']??false):false;
        return ['app_key'=>$payload['app_key'],'permissions'=>[['permission'=>'hardware.camera','allowed'=>$allowed]],'declared_count'=>1,'allowed_count'=>$allowed?1:0,'denied_count'=>$allowed?0:1];
    }
    if($op==='apps.system.rollback')return ['changed'=>true,'rollback'=>['release'=>['release_id'=>'release-previous','version'=>'1.0.0']]];
    if($op==='apps.system.reconcile'&&$mode==='reconcile_missing')return ['items'=>[['package'=>['key'=>$payload['app_keys'][0]]]]];
    if($op==='apps.system.reconcile')return ['items'=>[['package'=>['key'=>$payload['app_keys'][0],'installed_version'=>'1.0.0'],'installed'=>true,'current'=>true,'update_available'=>false,'state'=>'running']]];
    if($op==='apps.system.release.status'){

        $r=['app_key'=>$payload['app_key'],'available_version'=>'1.0.0','release_channel'=>'stable','package_sha256'=>str_repeat('a',64),'runtime'=>['active_release_id'=>'release-old','previous_release_id'=>'release-previous'],'rollback_available'=>true,'rollback_safe'=>true,'data'=>['schema_version'=>'1'],'private'=>'SECRET release path'];
        if($mode==='missing')unset($r['rollback_safe']);if($mode==='identity')$r['app_key']='foreign.app';if($mode==='badbool')$r['rollback_available']='yes';
        return $r;
    }
    if($op==='apps.system.install'||$op==='apps.system.status'){
        $r=['contract'=>'vp3.system-app-installation.v1','app_key'=>$payload['app_key'],'catalog_version'=>'1.0.0','package'=>['key'=>$payload['app_key'],'installed_version'=>'1.0.0','version'=>'1.0.0','package_sha256'=>str_repeat('a',64)],'installed'=>true,'current'=>true,'update_available'=>false,'state'=>'running','changed'=>true,'verification'=>['healthy'=>true],'rolled_back'=>false];
        if($mode==='missing')unset($r['installed']);if($mode==='version_missing')unset($r['package']['installed_version']);if($mode==='contradictory')$r['package']['installed']=false;if($mode==='identity')$r['package']['key']='foreign.app';if($mode==='rollback')$r['rolled_back']=true;
        return $r;
    }
    throw new RuntimeException('Unexpected operation fixture '.$op);
}
