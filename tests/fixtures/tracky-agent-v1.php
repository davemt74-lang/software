<?php
declare(strict_types=1);

/** Real bounded Tracky stores and canonical plugin opt-in; only external entitlement/transport are fixture boundaries. */
function tracky_agent_fixture_put_v1(PDO $pdo,string $table,array $row): void
{
    $keys=array_keys($row);$pdo->prepare('INSERT INTO '.$table.' ('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($row));
}

function tracky_agent_fixture_event_v1(PDO $pdo,int $id,string $entity,string $summary,int $age=30,array $overrides=[]): void
{
    $stamp=time()-$age;$event=array_replace(['event_id'=>'event-fixture-'.$id,'sequence'=>$id,'event_type'=>'object.observed','severity'=>'informational','confidence'=>0.92,'privacy_class'=>'cloud_derived','occurred_at'=>gmdate('Y-m-d\TH:i:s\Z',$stamp),'subject'=>['entity_id'=>$entity,'type'=>str_starts_with($entity,'person:')?'person':'object'],'room_id'=>'room:office','summary'=>$summary],$overrides);
    tracky_agent_fixture_put_v1($pdo,'tracky_cloud_events',['id'=>$id,'user_id'=>1,'site_id'=>'fixture-home','event_id'=>$event['event_id'],'sequence_no'=>$id,'event_type'=>$event['event_type'],'severity'=>$event['severity'],'confidence'=>$event['confidence'],'privacy_class'=>$event['privacy_class'],'occurred_at'=>gmdate('Y-m-d H:i:s',$stamp),'is_fresh'=>1,'event_json'=>json_encode($event,JSON_THROW_ON_ERROR)]);
}

function tracky_agent_fixture_v1(PDO $pdo): void
{
    vp3_tracky_dependencies_v1();
    $tables=[
      'user_plugin_installations'=>'user_id INTEGER,plugin_key VARCHAR(80),status VARCHAR(20),settings_json TEXT,PRIMARY KEY(user_id,plugin_key)',
      'homeserver_reconciliation_state_v246'=>'user_id INTEGER PRIMARY KEY,needs_reconciliation INTEGER,last_reconciled_at DATETIME,last_disconnect_at DATETIME',
      'tracky_cloud_sites'=>'user_id INTEGER,site_id VARCHAR(100),device_id VARCHAR(100),label VARCHAR(160),protocol_version VARCHAR(40),status VARCHAR(30),last_seen_at DATETIME,last_event_at DATETIME,health_json TEXT,capabilities_json TEXT,last_sequence INTEGER,sync_cursor VARCHAR(190),created_at DATETIME,PRIMARY KEY(user_id,site_id)',
      'tracky_cloud_world_state'=>'user_id INTEGER,site_id VARCHAR(100),relation_key VARCHAR(64),subject_id VARCHAR(128),predicate VARCHAR(80),object_id VARCHAR(128),value_json TEXT,confidence DECIMAL(6,5),temporal_state VARCHAR(30),source_event_id VARCHAR(128),sequence_no INTEGER,as_of DATETIME,PRIMARY KEY(user_id,site_id,relation_key)',
      'tracky_cloud_context'=>'user_id INTEGER,site_id VARCHAR(100),context_json TEXT,sequence_no INTEGER,observed_at DATETIME,updated_at DATETIME,PRIMARY KEY(user_id,site_id)',
      'tracky_cloud_events'=>'id INTEGER PRIMARY KEY,user_id INTEGER,site_id VARCHAR(100),event_id VARCHAR(128),sequence_no INTEGER,event_type VARCHAR(100),severity VARCHAR(30),confidence DECIMAL(6,5),privacy_class VARCHAR(40),occurred_at DATETIME,is_fresh INTEGER,event_json TEXT',
      'tracky_cloud_scene_share_order'=>'user_id INTEGER,site_id VARCHAR(100),accepted_revision INTEGER,fingerprint VARCHAR(64),summary_json TEXT,PRIMARY KEY(user_id,site_id)',
      'tracky_cloud_visual_status_order'=>'user_id INTEGER,site_id VARCHAR(100),accepted_revision INTEGER,accepted_state VARCHAR(40),accepted_at DATETIME,PRIMARY KEY(user_id,site_id)',
    ];
    foreach($tables as $name=>$schema)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$name.' ('.$schema.')');
    if((int)$pdo->query("SELECT COUNT(*) FROM tracky_cloud_sites WHERE user_id=1 AND site_id='fixture-home'")->fetchColumn()>0)return;
    foreach([1,2] as $uid){
        $q=$pdo->prepare('SELECT COUNT(*) FROM user_plugin_installations WHERE user_id=? AND plugin_key=?');$q->execute([$uid,'tracky']);
        if(!(int)$q->fetchColumn())tracky_agent_fixture_put_v1($pdo,'user_plugin_installations',['user_id'=>$uid,'plugin_key'=>'tracky','status'=>'enabled','settings_json'=>'{"chat_enabled":true,"active_perception_enabled":true,"auto_refresh_stale":true}']);
        $q=$pdo->prepare('SELECT COUNT(*) FROM homeserver_reconciliation_state_v246 WHERE user_id=?');$q->execute([$uid]);
        if(!(int)$q->fetchColumn())tracky_agent_fixture_put_v1($pdo,'homeserver_reconciliation_state_v246',['user_id'=>$uid,'needs_reconciliation'=>0,'last_reconciled_at'=>gmdate('Y-m-d H:i:s',time()-60),'last_disconnect_at'=>null]);
    }
    foreach([[1,'fixture-home','Fixture Home'],[2,'foreign-site','SECRET Foreign Site']] as [$uid,$id,$label])tracky_agent_fixture_put_v1($pdo,'tracky_cloud_sites',['user_id'=>$uid,'site_id'=>$id,'device_id'=>$id,'label'=>$label,'protocol_version'=>'physical_context.v1','status'=>'healthy','last_seen_at'=>gmdate('Y-m-d H:i:s'),'last_event_at'=>gmdate('Y-m-d H:i:s'),'health_json'=>'{"runtime":"healthy","camera":"healthy","raw_secret":"SECRET private native diagnostics"}','capabilities_json'=>'{}','last_sequence'=>10,'sync_cursor'=>'SECRET_PRIVATE_CURSOR','created_at'=>'2020-01-01 00:00:00']);
    foreach([
        ['keys','object:keys','room:office','current',30,'event-fixture-1'],
        ['wallet','object:wallet','room:garage','current',7200,'event-fixture-2'],
        ['alex','person:fixture_alex','room:office','inferred',30,'event-fixture-4'],
    ] as [$key,$subject,$object,$state,$age,$event])tracky_agent_fixture_put_v1($pdo,'tracky_cloud_world_state',['user_id'=>1,'site_id'=>'fixture-home','relation_key'=>$key,'subject_id'=>$subject,'predicate'=>str_starts_with($subject,'person:')?'present_in':'located_in','object_id'=>$object,'value_json'=>'{"raw_path":"SECRET /private/perception"}','confidence'=>0.92,'temporal_state'=>$state,'source_event_id'=>$event,'sequence_no'=>1,'as_of'=>gmdate('Y-m-d H:i:s',time()-$age)]);
    tracky_agent_fixture_put_v1($pdo,'tracky_cloud_world_state',['user_id'=>2,'site_id'=>'foreign-site','relation_key'=>'foreign','subject_id'=>'object:SECRET_foreign','predicate'=>'located_in','object_id'=>'room:SECRET_private','value_json'=>'{}','confidence'=>1,'temporal_state'=>'current','source_event_id'=>'event-foreign-1','sequence_no'=>1,'as_of'=>gmdate('Y-m-d H:i:s')]);
    tracky_agent_fixture_put_v1($pdo,'tracky_cloud_context',['user_id'=>1,'site_id'=>'fixture-home','context_json'=>'{"current_room":"Office","people_present":["Fixture Alex"],"environment_status":"Fixture quiet room","confidence":0.92,"raw_frames":"SECRET must not be projected"}','sequence_no'=>10,'observed_at'=>gmdate('Y-m-d H:i:s',time()-30),'updated_at'=>gmdate('Y-m-d H:i:s')]);
    tracky_agent_fixture_event_v1($pdo,1,'object:keys','Fixture keys observed in office',30);
    tracky_agent_fixture_event_v1($pdo,2,'object:wallet','Fixture wallet recorded in garage',7200);
    tracky_agent_fixture_event_v1($pdo,3,'sensor:fixture_health','Fixture recent health alert',20,['event_type'=>'camera.health_changed','severity'=>'system-health','privacy_class'=>'system_health']);
    tracky_agent_fixture_event_v1($pdo,4,'person:fixture_alex','Fixture inferred presence',30,['event_type'=>'person.observed']);
    $summary=['protocol'=>TRACKY_SCENE_V1G3D,'state'=>'available','reason'=>'recent_observation','observed_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-10),'room_id'=>'office','objects'=>['cup','phone'],'setting'=>'indoor','lighting'=>'bright','owner_corrections'=>[['object'=>'phone','present'=>false]]];
    tracky_agent_fixture_put_v1($pdo,'tracky_cloud_scene_share_order',['user_id'=>1,'site_id'=>'fixture-home','accepted_revision'=>1,'fingerprint'=>tracky_scene_digest_v1g3d($summary),'summary_json'=>json_encode($summary,JSON_THROW_ON_ERROR)]);
    tracky_agent_fixture_put_v1($pdo,'tracky_cloud_visual_status_order',['user_id'=>1,'site_id'=>'fixture-home','accepted_revision'=>1,'accepted_state'=>'owner_attributed_unverified','accepted_at'=>gmdate('Y-m-d H:i:s')]);
}

/** Optional endpoint scenario; setup mutations occur before the production read begins. */
function tracky_agent_fixture_mode_v1(PDO $pdo,string $mode): void
{
    if($mode==='disabled')$pdo->exec("UPDATE user_plugin_installations SET status='disabled' WHERE user_id=1 AND plugin_key='tracky'");
    if($mode==='missing_observation')$pdo->exec("UPDATE tracky_cloud_context SET observed_at=NULL,updated_at='2099-01-01 00:00:00' WHERE user_id=1");
    if($mode==='future')$pdo->exec("UPDATE tracky_cloud_context SET observed_at='2099-01-01 00:00:00' WHERE user_id=1");
    if($mode==='stale')$pdo->exec("UPDATE tracky_cloud_context SET observed_at='2000-01-01 00:00:00' WHERE user_id=1");
    if($mode==='reconciling')$pdo->exec('UPDATE homeserver_reconciliation_state_v246 SET needs_reconciliation=1 WHERE user_id=1');
    if($mode==='scene_revoked'){
        $summary=['protocol'=>TRACKY_SCENE_V1G3D,'state'=>'revoked','reason'=>'owner_revoked'];
        $pdo->prepare('UPDATE tracky_cloud_scene_share_order SET accepted_revision=2,fingerprint=?,summary_json=? WHERE user_id=1')->execute([tracky_scene_digest_v1g3d($summary),json_encode($summary,JSON_THROW_ON_ERROR)]);
    }
    if($mode==='multi_site'){
        $q=$pdo->query("SELECT * FROM tracky_cloud_sites WHERE user_id=1 AND site_id='fixture-home'");$site=$q->fetch(PDO::FETCH_ASSOC);$site['site_id']='fixture-studio';$site['device_id']='fixture-studio';$site['label']='Fixture Studio';tracky_agent_fixture_put_v1($pdo,'tracky_cloud_sites',$site);
    }
    if($mode==='empty')foreach(['tracky_cloud_context','tracky_cloud_world_state','tracky_cloud_events','tracky_cloud_scene_share_order'] as $table)$pdo->exec('DELETE FROM '.$table.' WHERE user_id=1');
}
