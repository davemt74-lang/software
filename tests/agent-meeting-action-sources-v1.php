<?php
declare(strict_types=1);

/** Real canonical meeting, recording, Brain and reminder tables. Only host account lookups are projected. */
class MeetingActionPDO extends PDO
{
    public bool $rejectProvenance=false;
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        if($this->rejectProvenance&&str_contains($sql,'UPDATE agent_memory_items SET metadata_json='))throw new PDOException('Injected provenance failure');
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            try{return parent::prepare($sql,$options);}catch(PDOException $error){
                preg_match("/Table '([a-z0-9_]+\.[a-z0-9_]+)' doesn't exist/i",$error->getMessage(),$missing);
                if($missing)throw new RuntimeException('Controlled source table missing: '.$missing[1],0,$error);throw $error;
            }
        }
        $sql=str_replace(' FOR UPDATE','',$sql);
        if(str_contains($sql,'information_schema.statistics'))$sql="SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?";
        $sql=preg_replace('/ON DUPLICATE KEY UPDATE/i','ON CONFLICT DO UPDATE SET',$sql);
        $sql=preg_replace('/VALUES\((\w+)\)/i','excluded.$1',$sql);
        return parent::prepare($sql,$options);
    }
    public function exec(string $statement): int|false
    {
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            // Portable controlled seed tables; production writes still use native PDO and SQL unchanged.
            $statement=str_replace('users(id INTEGER PRIMARY KEY','users(id INT UNSIGNED PRIMARY KEY',$statement);
            $statement=str_replace(['INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY','memory_hash TEXT','memory_type TEXT'],
                ['BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY','BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY','memory_hash VARCHAR(64)','memory_type VARCHAR(40)'],$statement);
        }
        try{return parent::exec($statement);}catch(PDOException $error){
            preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)? ([a-z0-9_]+)/i',$statement,$tables);
            throw new RuntimeException('Controlled seed schema failed: '.implode(',',$tables[1]).' (driver '.(int)($error->errorInfo[1]??0).').',0,$error);
        }
    }
}
function db(): PDO{return $GLOBALS['pdo'];}
function url(string $path=''): string{return '/vp3'.$path;}
function setting(string $key,mixed $fallback=null): mixed{return $key==='personal_capabilities_seed_v242'?'1':$fallback;}
function table_exists(string $table): bool{$q=db()->prepare(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?':"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();}
function column_exists(string $table,string $column): bool{
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$q=db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return(bool)$q->fetchColumn();}
    return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);
}
function permissions_schema_ready(): bool{return table_exists('role_permissions');}
function user_roles(): array{return ['member'=>'Member','admin'=>'Admin'];}
function valid_role(string $role): bool{return isset(user_roles()[$role]);}
function user_roles_for_user(?array $user=null): array{$q=db()->prepare('SELECT role FROM users WHERE id=? AND is_active=1');$q->execute([(int)($user['id']??0)]);$role=$q->fetchColumn();return $role?[$role]:[];}
function user_has_role(string $role,?array $user=null): bool{return in_array($role,user_roles_for_user($user),true);}
function has_permission(string $permission,?array $user=null): bool{$q=db()->prepare('SELECT 1 FROM role_permissions WHERE role=? AND permission_key=?');foreach(user_roles_for_user($user) as $role){$q->execute([$role,$permission]);if($q->fetchColumn())return true;}return false;}
function agent_brain_schema_ready(): bool{return table_exists('agent_memory_items');}
function agent_brain_normalize(string $value): string{return mb_strtolower(trim($value));}
require_once __DIR__.'/../includes/participant-updates-v1.php';
require_once __DIR__.'/../includes/user-agent-system-v236.php';
require_once __DIR__.'/../includes/knowledge-retrieval-v162.php';
require_once __DIR__.'/../includes/agent-memory-scope-v410.php';
require_once __DIR__.'/../includes/agent-brain-v122.php';
require_once __DIR__.'/../includes/agent-memory-lifecycle-v123.php';
require_once __DIR__.'/../includes/agent-task-lifecycle-v123.php';
require_once __DIR__.'/../includes/agent-chat-boundary-v380.php';
require_once __DIR__.'/../includes/agent-scheduling-v430.php';
require_once __DIR__.'/../includes/user-calendar-v1300.php';
require_once __DIR__.'/../includes/agent-capability-chat-v1.php';
require_once __DIR__.'/../includes/agent-meeting-action-sources-v1.php';
require_once __DIR__.'/fixtures/meeting-prep-calendar-fixture.php';

$nativeDsn=(string)getenv('VP3_MEETING_ACTION_MYSQL_DSN');$native=$nativeDsn!=='';
if($native){
    if(!str_starts_with($nativeDsn,'mysql:'))throw new RuntimeException('An explicit disposable native MySQL DSN is required.');
    $nativeAdmin=new PDO($nativeDsn,(string)getenv('VP3_MEETING_ACTION_MYSQL_USER'),(string)getenv('VP3_MEETING_ACTION_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $nativeDatabase='meeting_sources_'.bin2hex(random_bytes(6));$nativeAdmin->exec('CREATE DATABASE `'.$nativeDatabase.'`');
    register_shutdown_function(static function()use($nativeAdmin,$nativeDatabase):void{$nativeAdmin->exec('DROP DATABASE `'.$nativeDatabase.'`');});
    $pdo=$GLOBALS['pdo']=new MeetingActionPDO(preg_replace('/;dbname=[^;]*/','',$nativeDsn).';dbname='.$nativeDatabase,
        (string)getenv('VP3_MEETING_ACTION_MYSQL_USER'),(string)getenv('VP3_MEETING_ACTION_MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}else{
    $pdo=$GLOBALS['pdo']=new MeetingActionPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('LAST_INSERT_ID',static fn($n)=>$n);
}
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,role TEXT);INSERT INTO users VALUES(1,1,'member'),(2,1,'member');
CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT);INSERT INTO user_agents VALUES(7,1,1,'Work Agent'),(8,1,1,'Other Agent');
CREATE TABLE role_permissions(role TEXT,permission_key TEXT);INSERT INTO role_permissions VALUES('member','account.access'),('member','chat.access'),('member','agent_brain.access'),('member','artist_listening.access');
CREATE TABLE permissions(permission_key TEXT);INSERT INTO permissions VALUES('agent_brain.access');
CREATE TABLE settings(setting_key TEXT,setting_value TEXT);INSERT INTO settings VALUES('personal_capabilities_seed_v242','1');
CREATE TABLE user_account_types(user_id INTEGER,role TEXT,assigned_explicitly_at TEXT);
CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);INSERT INTO chat_conversations VALUES(10,1,7),(11,1,7),(12,1,8),(20,2,NULL);
CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,role TEXT,message TEXT,context_json TEXT);
CREATE TABLE agent_chat_archive(id INTEGER,user_agent_id INTEGER);CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id);
CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,subject TEXT,memory_text TEXT,memory_hash TEXT,memory_scope_version INTEGER,source_archive_id INTEGER,confidence REAL,occurrence_count INTEGER,first_seen_at TEXT,last_seen_at TEXT,is_active INTEGER,metadata_json TEXT,UNIQUE(user_id,memory_hash));
CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,occurrence_count)");
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$ids=vp3_test_meeting_prep_seed_v1($pdo,$now,7);vp3_task_lists_schema_v1($pdo);reminder_schema_v1($pdo);
$user=['id'=>1,'is_active'=>1,'role'=>'member'];$principal=vp3_agent_chat_principal_v380($user,['id'=>7,'owner_user_id'=>1,'display_name'=>'Work Agent']);
$binding=['owner_user_id'=>1,'agent_id'=>7,'conversation_id'=>10];$checks=0;
function meeting_action_check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function meeting_action_throws(callable $work,int $code,string $label): void{try{$work();}catch(Throwable $e){meeting_action_check((int)$e->getCode()===$code,$label.' ('.$e->getMessage().')');return;}throw new RuntimeException($label.' did not fail');}
function meeting_action_apply(array $plan,string $id): array{db()->beginTransaction();try{$r=vp3_meeting_action_apply_v1(db(),$GLOBALS['user'],$GLOBALS['principal'],$GLOBALS['binding'],$plan,$id);db()->commit();return $r;}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}}
function meeting_action_plan(array $source,string $adapter,array $arguments,string $ref='agenda:7942'): array{return vp3_meeting_action_normalize_v1(db(),$GLOBALS['user'],$GLOBALS['principal'],$GLOBALS['binding'],$source,['adapter'=>$adapter,'arguments'=>$arguments,'finding_ref'=>$ref]);}
function meeting_action_store_prep(int $id,int $cid,array $event,string $role='assistant'): void{
    $marker=array_intersect_key($event,array_flip(['id','kind','occurrence_id','revision']));
    db()->prepare('INSERT INTO chat_messages VALUES(?,?,?,?,?)')->execute([$id,$cid,$role,'Private meeting prep was displayed for this turn.',json_encode(['meeting_prep_v1'=>['conversation_id'=>$cid,'evidence'=>$marker]],JSON_THROW_ON_ERROR)]);
}
function meeting_action_readonly(callable $read): mixed{
    if($GLOBALS['native'])db()->exec('SET TRANSACTION READ ONLY');
    db()->beginTransaction();try{$result=$read();db()->rollBack();return $result;}catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
}
$event=vp3_calendar_prep_next_v1($pdo,$user,7,10,$now);meeting_action_store_prep(100,10,$event);
$before=$native?0:(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$read=meeting_action_readonly(fn()=>vp3_meeting_action_source_v1($pdo,$user,7,10,[],$now));$source=$read['source'];
meeting_action_check($read['status']==='selected'&&$source['meeting_id']===$ids['meeting_id']&&$source['origin_message_id']===100,'latest prep resolves exact meeting and server-written source message');
meeting_action_check($native||(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'source selection is read-only');
meeting_action_check(!str_contains(json_encode($source,JSON_THROW_ON_ERROR),'delivery plan')&&!str_contains(json_encode($source,JSON_THROW_ON_ERROR),'Friday'),'durable source pointer contains neither private finding text nor recorded speech');
$actionable=array_values(array_filter($read['findings'],static fn(array $f):bool=>$f['actionable']));
meeting_action_check(count($actionable)===1&&$actionable[0]['ref']==='agenda:7942'&&array_column($read['findings'],'ref')===['meeting:7912','agenda:7942','transcript:7921:segment:7931'],'source choices distinguish exact actionable commitment, meeting and recorded passage');
meeting_action_check($read['meeting']['start_at_utc']===$now->modify('+20 minutes')->format('Y-m-d H:i:s')&&$read['meeting']['timezone']==='UTC','parser receives exact native meeting time and timezone');
$prior=vp3_meeting_action_source_v1($pdo,$user,7,11,[],$now);
meeting_action_check($prior['source']['origin_conversation_id']===10&&$prior['source']['conversation_id']===11,'same-Agent prior Chat source retains both origin and requesting Chat');
meeting_action_store_prep(101,11,$event);$prior=vp3_meeting_action_source_v1($pdo,$user,7,11,[],$now);
meeting_action_check($prior['source']['origin_message_id']===101,'a valid prep in the current Chat takes precedence over older Chat origins');
meeting_action_store_prep(102,10,$event,'user');
meeting_action_check(vp3_meeting_action_source_v1($pdo,$user,7,10,[],$now)['source']['origin_message_id']===100,'browser-written user content never supplies an assistant source marker');
meeting_action_check(vp3_meeting_action_source_v1($pdo,$user,8,12,[],$now)['status']==='unavailable','another Agent cannot acquire this source through prior Chat discovery');
meeting_action_throws(fn()=>vp3_meeting_action_source_v1($pdo,$user,7,20,[],$now),403,'another owner Chat is denied');
$explicit=vp3_meeting_action_source_v1($pdo,$user,7,10,['meeting_id'=>7912],$now);
meeting_action_check($explicit['status']==='selected'&&$explicit['source']['origin_message_id']===0,'an explicit owned meeting ID does not fabricate a Chat origin');
$title=vp3_meeting_action_source_v1($pdo,$user,7,10,['title'=>'Delivery review'],$now);
meeting_action_check($title['status']==='selected'&&$title['source']['meeting_id']===7912,'exact title excludes ended same-title meetings');
$pdo->exec("INSERT INTO user_calendar_events SELECT 7902,owner_user_id,title,description,location,start_at_utc,end_at_utc,timezone,all_day,recurrence,agent_notifications,source,'duplicate',status,created_by_agent_id FROM user_calendar_events WHERE id=7901;
INSERT INTO video_meetings SELECT 7914,owner_user_id,organizer_agent_id,'44444444444444444444444444444444',room_name,agent_mode,booking_id,7902,status,title,start_at_utc,end_at_utc FROM video_meetings WHERE id=7912;
INSERT INTO video_meeting_participants(meeting_id,user_id,role,invitation_status) VALUES(7914,1,'organizer','accepted')");
meeting_action_check(vp3_meeting_action_source_v1($pdo,$user,7,10,['title'=>'Delivery review'],$now)['status']==='ambiguous','two active exact titles require user choice');
meeting_action_check(vp3_meeting_action_source_current_v1($pdo,$user,7,10,$source,$now)['source']===$source,'new similarly named meetings cannot redirect a frozen source');
$plan=meeting_action_plan($source,'task.create',['title'=>'Assign the revised delivery plan owner']);
meeting_action_check($plan['review_required']===true&&!table_exists('agent_initiative_context_v1'),'direct action review is independent of Initiative installation or enablement');
$created=meeting_action_apply($plan,'meeting-task-test-1');$taskId=$created['office_action']['task_id'];
$metadata=json_decode((string)$pdo->query('SELECT metadata_json FROM agent_memory_items WHERE id='.$taskId)->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
meeting_action_check($created['verified']&&$created['tool_receipt']['record_id']===$taskId&&$metadata['meeting_source_v1']['source']===$source&&$metadata['meeting_source_v1']['finding_ref']==='agenda:7942','canonical Brain task is saved and read back with exact meeting and finding provenance');
$before=$native?0:(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$inspection=meeting_action_readonly(fn()=>vp3_meeting_action_inspect_v1($pdo,$user,$principal,$binding,$plan,$created));
meeting_action_check($inspection['verified']&&($native||(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before),'committed task receipt replay performs no writes');
meeting_action_throws(fn()=>meeting_action_apply($plan,'meeting-task-test-1'),409,'duplicate task apply cannot upsert or reopen saved work');
meeting_action_check((int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===1,'duplicate task retry creates no second canonical record');
$reminderPlan=meeting_action_plan($source,'reminder.create',['schedule'=>['title'=>'Assign the revised delivery plan owner','timezone'=>'UTC','start_local'=>$now->modify('+10 minutes')->format('Y-m-d\TH:i:s'),'delivery'=>'chat']]);
$reminder=meeting_action_apply($reminderPlan,'meeting-reminder-test-1');$rid=$reminder['tool_receipt']['record_id'];
meeting_action_check($reminder['verified']&&(int)$pdo->query('SELECT conversation_id FROM agent_reminders_v1 WHERE id='.$rid)->fetchColumn()===10,'meeting reminder uses canonical reminder schedule and original Chat delivery');
$receipt=json_decode((string)$pdo->query('SELECT result_json FROM agent_reminder_turns_v1')->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
meeting_action_check($receipt['meeting_source_v1']['source']===$source&&$receipt['meeting_source_v1']['finding_revision']===$reminderPlan['finding_revision'],'canonical reminder turn ledger retains exact meeting and finding provenance');
$again=meeting_action_apply($reminderPlan,'meeting-reminder-test-1');
meeting_action_check($again['tool_receipt']['record_id']===$rid&&(int)$pdo->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn()===1,'same reminder action identity reuses its durable native receipt');
meeting_action_throws(fn()=>meeting_action_plan($source,'task.create',['title'=>'Wrong source'],'agenda:7943'),403,'ordinary discussion agenda outside prep actionable findings cannot be substituted');
$forged=$created;$forged['meeting_source_v1']['finding_ref']='agenda:7943';
meeting_action_throws(fn()=>vp3_meeting_action_inspect_v1($pdo,$user,$principal,$binding,$plan,$forged),409,'receipt cannot substitute another finding');
$pdo->rejectProvenance=true;meeting_action_throws(fn()=>meeting_action_apply($plan,'meeting-task-rollback'),0,'provenance failure prevents committing an untraceable task');$pdo->rejectProvenance=false;
meeting_action_check((int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===1,'failed provenance write rolls back canonical task creation');
$pdo->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='Corrected source speech.' WHERE id=7931");
meeting_action_throws(fn()=>meeting_action_apply($plan,'meeting-task-stale'),409,'correcting recorded evidence invalidates pending action review');
meeting_action_check(vp3_meeting_action_inspect_v1($pdo,$user,$principal,$binding,$plan,$created)['verified']
    &&vp3_meeting_action_inspect_v1($pdo,$user,$principal,$binding,$reminderPlan,$reminder)['verified'],
    'ordinary source corrections do not erase independently verified completed task and reminder receipts');
$pdo->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='The revised delivery plan needs an owner by Friday.' WHERE id=7931");
$pdo->exec("UPDATE video_meetings SET status='live' WHERE id=7912");
meeting_action_check(vp3_meeting_action_inspect_v1($pdo,$user,$principal,$binding,$plan,$created)['verified']
    &&vp3_meeting_action_inspect_v1($pdo,$user,$principal,$binding,$reminderPlan,$reminder)['verified'],
    'a meeting start leaves canonical completed receipts readable as historical saved effects');
meeting_action_throws(fn()=>meeting_action_apply($plan,'meeting-task-started'),409,'a started meeting still invalidates an unapproved frozen review');
$pdo->exec("UPDATE video_meetings SET status='scheduled' WHERE id=7912");
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'recording','7921','deny')");
meeting_action_throws(fn()=>meeting_action_apply($plan,'meeting-task-revoked'),409,'source recording access revoked after review blocks the mutation');
meeting_action_throws(fn()=>vp3_meeting_action_inspect_v1($pdo,$user,$principal,$binding,$plan,$created),404,'completed task provenance retains current original-recording permission checks');
meeting_action_throws(fn()=>vp3_meeting_action_inspect_v1($pdo,$user,$principal,$binding,$reminderPlan,$reminder),404,'completed reminder provenance retains current original-recording permission checks');
$pdo->exec('DELETE FROM user_agent_data_rules');
$pdo->exec("UPDATE video_meeting_participants SET invitation_status='revoked' WHERE meeting_id=7911");
meeting_action_throws(fn()=>meeting_action_apply($plan,'meeting-task-lineage-revoked'),409,'source meeting participant revocation invalidates carried-forward finding');
meeting_action_throws(fn()=>vp3_meeting_action_inspect_v1($pdo,$user,$principal,$binding,$plan,$created),403,'historical receipt cannot bypass original source-meeting revocation');
$pdo->exec("UPDATE video_meeting_participants SET invitation_status='accepted' WHERE meeting_id=7911");
$pdo->exec("DELETE FROM role_permissions WHERE permission_key='agent_brain.access'");
meeting_action_throws(fn()=>meeting_action_apply($plan,'meeting-task-brain-revoked'),403,'task permission revocation cannot fall back to a default grant');
$pdo->exec("INSERT INTO role_permissions VALUES('member','agent_brain.access')");
meeting_action_throws(fn()=>vp3_meeting_action_source_current_v1($pdo,$user,7,11,$source,$now),403,'source bound to one request Chat cannot be transplanted to another');
$pdo->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=10');
meeting_action_throws(fn()=>vp3_meeting_action_source_current_v1($pdo,$user,7,10,$source,$now),403,'origin Chat Agent reassignment invalidates source handoff');
echo 'MEETING_ACTION_SOURCES=PASS ('.$checks.' checks; exact source, canonical writes, provenance, readback, retry and revocation)'.PHP_EOL;
