<?php
declare(strict_types=1);
final class Section13PDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}
}
$pdo=new Section13PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $name): bool{$s=db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$name]);return (bool)$s->fetchColumn();}
function column_exists(string $table,string $column): bool {foreach(db()->query('PRAGMA table_info('.$table.')') as $r)if($r['name']===$column)return true;return false;}
function check13(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function agent_brain_v122_upsert_system_memory(...$args): void{$GLOBALS['observations'][]=$args[3];}
function vp3_cognitive_notification_event_v2380(...$args): void {throw new RuntimeException('optional projection unavailable');}
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY); INSERT INTO users VALUES (1),(2)');
$pdo->exec("CREATE TABLE notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,title TEXT,body TEXT,target_url TEXT,source_type TEXT,source_id INTEGER,is_read INTEGER DEFAULT 0,read_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE agent_event_inbox(id INTEGER PRIMARY KEY AUTOINCREMENT,event_uuid TEXT UNIQUE,owner_user_id INTEGER,source TEXT,event_type TEXT,schema_version INTEGER DEFAULT 1,external_event_id TEXT,dedupe_hash TEXT,verification_status TEXT,verification_key_id TEXT,processing_status TEXT DEFAULT 'accepted',occurred_at TEXT,received_at TEXT DEFAULT CURRENT_TIMESTAMP,processing_started_at TEXT,processed_at TEXT,replay_count INTEGER DEFAULT 0,correlation_id TEXT,causation_id TEXT,payload_json TEXT,linked_run_id INTEGER,last_error_code TEXT DEFAULT '',last_error_message TEXT DEFAULT '',created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(owner_user_id,dedupe_hash))");
require __DIR__.'/../includes/agent-event-infrastructure-v1920.php';require __DIR__.'/../includes/notifications.php';
$a=agent_event_ingest_v1920($pdo,1,'fixture','event',[],['external_event_id'=>'event-1']);$b=agent_event_ingest_v1920($pdo,1,'fixture','event',['changed'=>true],['external_event_id'=>'event-1']);check13(!$a['duplicate']&&$b['duplicate']&&$a['event']['id']===$b['event']['id'],'External event deduplication failed');
try {agent_event_ingest_v1920($pdo,1,'fixture','event',[],['external_event_id'=>str_repeat('x',191)]);throw new LogicException('Oversized identity accepted');}catch(RuntimeException $e){check13(str_contains($e->getMessage(),'too long'),'Unexpected oversized identity result');}
$wide=str_repeat('界',190);$wideEvent=agent_event_ingest_v1920($pdo,1,'fixture','event',[],['external_event_id'=>$wide]);check13($wideEvent['event']['external_event_id']===$wide,'Valid wide external identity changed');
foreach([false,true] as $lateError){
 $event=agent_event_ingest_v1920($pdo,1,'fixture','takeover',[],['external_event_id'=>'takeover-'.(int)$lateError]);$id=$event['event']['id'];$inside=false;$GLOBALS['observations']=[];
 agent_event_register_handler_v1920('fixture','takeover',function($row,$user)use($pdo,$id,&$inside,$lateError){
  if($inside)return ['summary'=>'Current worker result'];
  $inside=true;$pdo->prepare("UPDATE agent_event_inbox SET processing_started_at='2000-01-01 00:00:00' WHERE id=?")->execute([$id]);agent_event_dispatch_v1920($pdo,$user,$id,true);
  if($lateError)throw new RuntimeException('Old worker failed');return ['summary'=>'Old worker result'];
 });
 try{agent_event_dispatch_v1920($pdo,['id'=>1],$id);throw new LogicException('Old worker completion accepted');}catch(RuntimeException $e){}
 $row=agent_event_row_v1920($pdo,1,$id);check13($row['processing_status']==='processed'&&(int)$row['replay_count']===1&&$row['last_error_code']==='','Old worker overwrote recovered dispatch');check13(count($GLOBALS['observations'])===1&&str_contains($GLOBALS['observations'][0],'Current worker result'),'Stale Brain observation accepted');
 check13(agent_event_dispatch_v1920($pdo,['id'=>1],$id)['duplicate_dispatch'],'Completed event redispatched');
 try{agent_event_dispatch_v1920($pdo,['id'=>2],$id);throw new LogicException('Foreign owner dispatched event');}catch(RuntimeException $e){check13(str_contains($e->getMessage(),'not found'),'Ownership guard failed');}
}
$type=str_repeat('t',65);$source=str_repeat('s',100);create_notification(1,$type,'One','','',$source,10);create_notification(1,$type,'Two','','',$source,10);check13((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===1,'Normalized carrier duplicated');
create_notification(1,'homeserver_connection_update','Connected','','','homeserver',11);create_notification(1,'homeserver_needs_attention','Review','','','homeserver',12);create_notification(1,'user_notice','Notice','','','user',13);
$brainRows=$pdo->query('SELECT * FROM notifications WHERE '.notification_agent_brain_sql_predicate())->fetchAll();check13(count($brainRows)===2,'Brain SQL classification mismatch');foreach($brainRows as $r)check13(notification_is_agent_brain_activity($r)&&!notification_requires_attention($r),'Brain carrier entered owner attention');check13(notification_unread_count(['id'=>1])===2,'Operational carriers inflated user unread count');
$pdo->beginTransaction();$pdo->exec('INSERT INTO users VALUES (3)');$pdo->exec("CREATE TRIGGER fail_notification BEFORE INSERT ON notifications WHEN NEW.source_id=999 BEGIN SELECT RAISE(ABORT,'forced carrier failure'); END;");$before=(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();create_notification(1,'fixture','Fail','','','fixture',999);check13($pdo->inTransaction()&&(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===$before,'Failed carrier broke parent transaction');check13((int)$pdo->query('SELECT COUNT(*) FROM users WHERE id=3')->fetchColumn()===1,'Carrier rollback erased parent mutation');create_notification(1,'fixture','Valid','','','fixture',1000);$pdo->commit();check13((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===$before+1,'Optional projection failure discarded carrier');
echo "SECTION13_CLOUD_EVENTS_NOTIFICATIONS=PASS\n";
