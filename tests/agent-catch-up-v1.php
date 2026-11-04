<?php
declare(strict_types=1);

function url(string $path=''): string{return '/vp3'.$path;}
function has_permission(string $permission,array $user): bool{return !in_array($permission,$user['denied']??[],true);}
function personal_capability_has_v242(string $permission,array $user): bool{return has_permission($permission,$user);}
function user_agent_get_v236(PDO $pdo,int $owner,int $agent): ?array{return $owner===1&&$agent===7?['id'=>7,'is_active'=>1]:null;}
function user_agent_principal_v236(array $user,?array $agent): array{return ['owner_user_id'=>$user['id'],'kind'=>$agent?'user_agent':'system','agent_id'=>$agent['id']??0];}
function vp3_participant_scope_v1(): ?array{return $GLOBALS['participant_scope']??null;}
function vp3_participant_resource_v1(PDO $pdo,array $user,array $principal,string $kind,int $id): bool{return $kind!=='task'||$id!==77;}
function user_calendar_schema_ready_v1300(PDO $pdo): bool{return true;}
function user_calendar_default_timezone_v1300(PDO $pdo,array $user): string{return 'America/Phoenix';}
function user_calendar_timezone_v1300(string $zone,string $fallback): string{return $zone;}
function user_calendar_events_v1300(PDO $pdo,array $user,string $from,string $to,bool $includeHomeServer=true): array
{
    $GLOBALS['briefCalendarIncludesHomeServer']=$includeHomeServer;
    return $GLOBALS['calendar_rows'][$user['id']]??[];
}
function agent_commerce_schema_ready_v800(PDO $pdo): bool{return table_exists('agent_commerce_orders_v800');}
function campaigns_rewards_journey_operations_schema_ready_v124(PDO $pdo): bool{return table_exists('campaign_journey_instances');}
function campaigns_rewards_v110_schema_ready(PDO $pdo): bool{return table_exists('reward_issuances');}
function campaigns_rewards_accessible_merchants_v100(PDO $pdo,array $user): array{return $user['id']===1?[['id'=>10,'status'=>'active','sandbox_mode'=>0],['id'=>20,'status'=>'active','sandbox_mode'=>0]]:[];}
function campaigns_rewards_platform_can_v100(PDO $pdo,int $merchant,int $uid,string $capability): bool{return $merchant===10&&$uid===1&&$capability==='campaigns.view';}
function campaigns_rewards_owner_plugin_enabled_v100(PDO $pdo,int $owner): bool{return $owner===1;}
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool
{
    $q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);
    return (bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool
{
    return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
}
function fixture_memory_scope(PDO $pdo): void
{
    $pdo->sqliteCreateFunction('DATABASE',static fn()=>'main');
    $pdo->exec("ALTER TABLE agent_memory_items ADD COLUMN memory_scope_version INTEGER NOT NULL DEFAULT 410;
        CREATE TABLE agent_chat_archive(user_agent_id INTEGER,created_at TEXT);
        CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);
        CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,last_seen_at);
        CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id,created_at);
        ATTACH DATABASE ':memory:' AS information_schema;
        CREATE TABLE information_schema.statistics AS SELECT 'main' AS table_schema,tbl_name AS table_name,name AS index_name
            FROM main.sqlite_master WHERE type='index'");
}
function expect(bool $condition,string $name): void
{
    if(!$condition)throw new RuntimeException($name);
    echo 'PASS '.$name."\n";
}
function ids(array $result): array{return array_column($result['items'],'id');}
function blocked(callable $read): bool{try{$read();return false;}catch(RuntimeException $e){return true;}}

$root=dirname(__DIR__);
require $root.'/includes/agent-chat-boundary-v380.php';
require $root.'/includes/agent-reminders-v1.php';
require $root.'/includes/calendar-schedule-awareness-v1330.php';
require $root.'/includes/agent-commerce-v800-part2.php';
require_once $root.'/includes/agent-capability-chat-v1.php';
require_once $root.'/includes/knowledge-retrieval-v162.php';
require_once $root.'/includes/agent-memory-scope-v410.php';
require_once $root.'/includes/video-meetings-v1800.php';
require_once $root.'/includes/cognitive-presentation-firewall-v2590.php';
require_once $root.'/includes/cognitive-presentation-v510.php';

$pdo=$GLOBALS['pdo']=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);
    INSERT INTO users VALUES(1,1),(2,1),(3,0);
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);
    INSERT INTO chat_conversations VALUES(50,1,NULL),(51,2,NULL);
    CREATE TABLE video_meetings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,public_id TEXT,room_name TEXT,agent_mode TEXT);
    INSERT INTO video_meetings(id,owner_user_id,public_id) VALUES(42,1,'meeting-42');
    CREATE TABLE video_meeting_participants(invite_token TEXT);
    CREATE TABLE video_meeting_transcript_segments(id INTEGER);
    CREATE TABLE video_meeting_artifacts(id INTEGER);
    CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,subject TEXT,metadata_json TEXT,is_active INTEGER,memory_type TEXT,last_seen_at TEXT);
    CREATE TABLE agent_commerce_orders_v800(id INTEGER PRIMARY KEY,owner_user_id INTEGER,workspace_owner_user_id INTEGER,order_number TEXT,payment_status TEXT,order_status TEXT,amount_paid_cents INTEGER,amount_refunded_cents INTEGER,metadata_json TEXT,created_at TEXT,updated_at TEXT);
    CREATE TABLE merchant_accounts(id INTEGER PRIMARY KEY,owner_user_id INTEGER,status TEXT,sandbox_mode INTEGER);
    INSERT INTO merchant_accounts VALUES(10,1,'active',0),(20,2,'active',0),(30,1,'active',1);
    CREATE TABLE campaigns(id INTEGER PRIMARY KEY,name TEXT,merchant_id INTEGER,status TEXT,environment TEXT);
    INSERT INTO campaigns VALUES(10,'A live campaign',10,'active','production'),(20,'Denied campaign',20,'active','production'),(30,'Test campaign',10,'active','sandbox'),(40,'Ended campaign',10,'completed','production');
    CREATE TABLE campaign_journeys(id INTEGER PRIMARY KEY,campaign_id INTEGER);
    INSERT INTO campaign_journeys VALUES(100,10),(200,20),(300,30),(400,40);
    CREATE TABLE campaign_journey_instances(id INTEGER PRIMARY KEY,campaign_id INTEGER,journey_id INTEGER,status TEXT,last_activity_at TEXT);
    INSERT INTO campaign_journey_instances VALUES(1,10,100,'needs_attention','2026-01-01 10:00:00'),(2,10,100,'needs_attention','2026-01-01 11:00:00'),(3,20,200,'needs_attention','2026-01-01 09:00:00'),(4,30,300,'needs_attention','2026-01-01 09:00:00'),(5,40,400,'needs_attention','2026-01-01 09:00:00');
    CREATE TABLE reward_products(id INTEGER PRIMARY KEY,name TEXT);
    INSERT INTO reward_products VALUES(1,'My reward');
    CREATE TABLE crm_contacts(id INTEGER PRIMARY KEY);
    INSERT INTO crm_contacts VALUES(1);
    CREATE TABLE reward_issuances(id INTEGER PRIMARY KEY,recipient_user_id INTEGER,recipient_contact_id INTEGER,reward_product_id INTEGER,campaign_id INTEGER,merchant_id INTEGER,status TEXT,remaining_quantity INTEGER,expires_at TEXT)");
reminder_schema_v1($pdo);

$user=['id'=>1];$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$overdue=$now->modify('-2 minutes');$future=$now->modify('+1 hour');$sqlTime=$now->format('Y-m-d H:i:s');
$GLOBALS['calendar_rows'][1]=array_map(static fn(array $row):array=>[
    'id'=>$row[0],'title'=>$row[1],'status'=>'active','kind'=>'event','occurrence_id'=>'event:'.$row[0],
    'start_at_utc'=>$now->modify($row[2])->format('Y-m-d H:i:s'),
    'end_at_utc'=>$now->modify($row[3])->format('Y-m-d H:i:s'),
],[[1,'Client call','+10 minutes','+50 minutes'],[2,'Team check-in','+20 minutes','+40 minutes']]);
$q=$pdo->prepare('INSERT INTO agent_reminders_v1(id,owner_user_id,title,timezone,start_local,recurrence,delivery,next_at,status,conversation_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
foreach([[1,1,'Due reminder',$overdue,'active',50],[2,1,'Snoozed reminder',$overdue,'active',50],[3,2,'Other reminder',$overdue,'active',51],[4,1,'Cancelled reminder',$overdue,'cancelled',50]] as [$id,$owner,$title,$at,$status,$conversation]){
    $q->execute([$id,$owner,$title,'UTC',$at->format('Y-m-d H:i:s'),'once','chat',reminder_utc_v1($at),$status,$conversation,reminder_utc_v1($now),reminder_utc_v1($now)]);
}
$pdo->prepare("INSERT INTO agent_reminder_occurrences_v1(reminder_id,owner_user_id,scheduled_at,wake_at,status) VALUES(2,1,?,?,'pending')")->execute([reminder_utc_v1($overdue),reminder_utc_v1($future)]);
$q=$pdo->prepare('INSERT INTO agent_memory_items VALUES(?,?,?,?,?,1,\'task\',?)');
$task=['task_status'=>'open','due_at'=>reminder_utc_v1($overdue)];
foreach([[1,1,null,'Due task',$task],[2,1,7,'Named Agent task',$task],[3,1,null,'No explicit due date',['task_status'=>'open']],[4,1,null,'Completed task',array_replace($task,['task_status'=>'completed'])],[5,2,null,'Other owner task',$task],[6,1,null,'Meeting action',['task_status'=>'open','source_kind'=>'video_meeting_intelligence','review_state'=>'approved','video_meeting_id'=>42,'due_date'=>$now->setTimezone(new DateTimeZone('America/Phoenix'))->format('Y-m-d')]],[77,1,7,'Task policy denied',$task]] as [$id,$owner,$agent,$title,$meta]){
    $q->execute([$id,$owner,$agent,$title,json_encode($meta,JSON_THROW_ON_ERROR),$sqlTime]);
}
fixture_memory_scope($pdo);
$profile=['source'=>'profile_commerce_v900'];$refund=$profile+['customer_refund_request'=>['status'=>'pending','requested_at'=>reminder_utc_v1($overdue),'reason'=>'PRIVATE REFUND TEXT']];
$q=$pdo->prepare('INSERT INTO agent_commerce_orders_v800 VALUES(?,?,?,?,?,?,?,?,?,?,?)');
foreach([[1,1,null,'PAID-1','paid','payment_review',1000,0,$refund],[2,1,null,'PAID-2','paid','payment_review',1000,0,$profile],[3,2,1,'FOREIGN-3','paid','payment_review',1000,0,$refund],[4,1,null,'SETTLED-4','refunded','refunded',1000,1000,$refund],[5,1,null,'UNVERIFIED-5','awaiting_payment','payment_review',0,0,$profile],[6,1,null,'BOOKING-6','paid','payment_review',1000,0,[]]] as $row){
    $row[8]=json_encode($row[8],JSON_THROW_ON_ERROR);$q->execute([...$row,$sqlTime,$sqlTime]);
}
$q=$pdo->prepare('INSERT INTO reward_issuances VALUES(?,?,1,1,?,?,?,?,?)');
foreach([[1,1,10,10,'issued',1,$future],[2,2,10,10,'issued',1,$future],[3,1,10,10,'claimed',1,$future],[4,1,10,10,'issued',1,$overdue],[5,1,20,20,'issued',1,$future],[6,1,30,10,'issued',1,$future]] as [$id,$uid,$campaign,$merchant,$state,$quantity,$expires]){
    $q->execute([$id,$uid,$campaign,$merchant,$state,$quantity,$expires->format('Y-m-d H:i:s')]);
}

$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$brief=vp3_agent_catch_up_v1($pdo,$user);
expect(count($brief['items'])===3&&str_starts_with($brief['items'][0]['id'],'calendar-conflict:')&&array_slice(ids($brief),1)===['reminder:1','order:1'],'calendar, reminder and seller priorities share one three-item budget');
expect($GLOBALS['briefCalendarIncludesHomeServer']===false,'calendar briefing excludes paired remote calls and observation writes');
expect($brief['items'][1]['url']==='/vp3/chat.php?agent=system&conversation_id=50','reminder links to an owned system Chat thread');
expect(!str_contains(json_encode($brief,JSON_THROW_ON_ERROR),'PRIVATE REFUND TEXT'),'customer free-text refund reasons remain private');
expect(ids($brief)===ids(vp3_agent_catch_up_v1($pdo,$user)),'stable current-source item identifiers and ordering');
$commerce=vp3_agent_catch_up_v1($pdo,$user,'commerce');
expect(ids($commerce)===['order:1','order:2','campaign-journey:100'],'Commerce uses owner state, dedupes the same order and groups a production journey');
expect(!isset($commerce['coverage']['calendar']),'unselected sources are not in coverage');
expect(ids(['items'=>vp3_catch_up_rewards_v1($pdo,$user,$now)[1]])===['reward:1'],'only current, unexpired holder rewards from enabled merchants are surfaced');
$taskRows=vp3_catch_up_tasks_v1($pdo,$user,user_agent_principal_v236($user,null),$now)[1];
$taskLinks=array_column($taskRows,'url','id');
expect(count($taskLinks)===2&&isset($taskLinks['task:1'])&&$taskLinks['task:6']==='/vp3/meeting.php?meeting=meeting-42&review=1','due open tasks and approved follow-ups reuse canonical records and source links');
$named=vp3_agent_catch_up_v1($pdo,$user,'commerce',7);
expect($named['items']===[]&&array_unique(array_values($named['coverage']))===['forbidden'],'named Agent Commerce scope is explicitly blocked');
$namedTaskRows=vp3_catch_up_tasks_v1($pdo,$user,user_agent_principal_v236($user,['id'=>7]),$now)[1];
expect(array_column($namedTaskRows,'id')===['task:2'],'named Agent tasks honor scope and per-record policy');
expect(blocked(fn()=>vp3_agent_catch_up_v1($pdo,$user,'all',55))&&blocked(fn()=>vp3_agent_catch_up_v1($pdo,['id'=>3])),'foreign Agent and inactive owner fail closed');
$GLOBALS['participant_scope']=['participant'=>1];
expect(blocked(fn()=>vp3_agent_catch_up_v1($pdo,$user)),'participant view cannot inherit owner briefing access');
unset($GLOBALS['participant_scope']);
expect((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'brief reads do not change any source, receipt or delivery record');
$pdo->exec('INSERT INTO chat_conversations VALUES(52,1,7); UPDATE agent_reminders_v1 SET conversation_id=52 WHERE id=1');
$namedReminders=vp3_catch_up_reminders_v1($pdo,$user,user_agent_principal_v236($user,['id'=>7]),$now)[1];
expect($namedReminders[0]['url']==='/vp3/chat.php?agent=7&conversation_id=52','named Agent reminder links use the Chat Agent selector');
$pdo->exec('DROP TABLE reward_issuances');
expect(vp3_agent_catch_up_v1($pdo,$user,'commerce')['coverage']['rewards']==='unavailable','missing source remains explicitly unavailable');

// A fresh owner workspace with the same records the domain services actually read.
require $root.'/tests/fixtures/profile-public-authority-pa1.php';
require $root.'/tests/fixtures/workflow-agent-schema-v1.php';
require_once $root.'/includes/cloud-missions-v1.php';
$pdo=$GLOBALS['pdo']=new class('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]) extends PDO{
    public function prepare(string $query,array $options=[]): PDOStatement|false{return parent::prepare(str_replace('INSERT IGNORE','INSERT OR IGNORE',$query),$options);}
};
workflow_agent_fixture_v1($pdo);
$pdo->exec('DROP TABLE users'); // Replace the workflow fixture's minimal identity rows with the Profile fixture's full owner records.
profile_public_authority_fixture_pa1($pdo);
cloud_mission_schema_v1($pdo);
reminder_schema_v1($pdo);
vp3_profile_call_ensure_schema_pa3c($pdo);
vp3_profile_call_activity_ensure_schema_pa3c($pdo);
$pdo->exec("ALTER TABLE agent_memory_items ADD COLUMN user_agent_id INTEGER;
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);
    INSERT INTO chat_conversations VALUES(50,1,NULL);
    CREATE TABLE agent_workflow_run_dependencies(id INTEGER PRIMARY KEY,owner_user_id INTEGER,run_id INTEGER,depends_on_run_id INTEGER);
    INSERT INTO agent_workflow_run_dependencies VALUES(1,1,100,101);
    CREATE TABLE artist_transcript_sessions_v172(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,conversation_id INTEGER,status TEXT,metadata_json TEXT,discarded_at TEXT);
    INSERT INTO profile_visit_sessions(id,owner_user_id,session_key) VALUES(31,1,'private-browser-session');
    INSERT INTO profile_agent_conversations(id,owner_user_id,profile_agent_id,profile_session_id) VALUES(32,1,7,31)");
fixture_memory_scope($pdo);
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$sqlTime=$now->format('Y-m-d H:i:s');
$mission=cloud_mission_uuid_v1('catch-up-fixture-blocked-mission');
$pdo->prepare("INSERT INTO agent_cloud_missions_v1(mission_id,owner_user_id,agent_id,request_id,request_hash,run_id,config_json,created_at)
    VALUES(?,1,0,'catch-up-fixture','fixture',100,'{}',?)")->execute([$mission,$sqlTime]);
$pdo->prepare("INSERT INTO agent_workflow_runs(id,owner_user_id,agent_id,source_kind,source_key,execution_target,title,status,updated_at)
    VALUES(100,1,NULL,'cloud_mission',?,'cloud','Prepare launch research','approved',?),
          (101,1,NULL,'agent_next_action','','cloud','Prerequisite workflow','executing',?)")->execute([$mission,$sqlTime,$sqlTime]);

$credential=str_repeat('a',64);
$pdo->beginTransaction();
vp3_profile_agent_conversation_bind_v1($pdo,['id'=>32,'owner_user_id'=>1,'profile_agent_id'=>7,'profile_session_id'=>31],
    ['contract'=>'vp3.profile-conversation-authority.v1','transport'=>'native','owner_user_id'=>1,'visitor_user_id'=>0,'credential_hash'=>$credential]);
$pdo->commit();
$authority=vp3_profile_call_context_pa3c($pdo,1,32)['snapshot'];
$callPublicId=str_repeat('b',32);
$callTime=$now->modify('-1 hour')->getTimestamp()*1000;
$pdo->prepare("INSERT INTO profile_calls_pa3c(id,owner_user_id,profile_agent_id,profile_session_id,conversation_id,credential_hash,authority_json,public_id,
    room_name,owner_identity,visitor_identity,provider_snapshot,provider_config_fingerprint,mode,offered_by,state,assisted,
    offer_expires_at_ms,accepted_at_ms,created_at_ms,updated_at_ms)
    VALUES(33,1,7,31,32,?,?,?,'private-fixture-room','private-owner','private-visitor','private-provider','fixture','audio','visitor','ended',1,?,?,?,?)")
    ->execute([$credential,json_encode($authority,JSON_THROW_ON_ERROR),$callPublicId,$callTime+60000,$callTime+10000,$callTime,$callTime+600000]);
$pdo->prepare('INSERT INTO profile_call_assistance_pa3c(call_id,transcript_session_id) VALUES(?,34)')->execute([$callPublicId]);
$pdo->prepare("INSERT INTO artist_transcript_sessions_v172 VALUES(34,1,50,'draft',?,NULL)")
    ->execute([json_encode(['source'=>'profile_call_pa3c','profile_call_id'=>$callPublicId],JSON_THROW_ON_ERROR)]);
$q=$pdo->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,subject,metadata_json,is_active,memory_type,last_seen_at) VALUES(?,1,NULL,?,?,1,'task',?)");
foreach([
    [101,'Send the agreed quote',['source_kind'=>'transcription_intelligence','source_session_id'=>34,'created_from_reviewed_item'=>true,'task_status'=>'open','due_at'=>reminder_utc_v1($now->modify('-2 hours'))]],
    [102,'Review the draft',['task_status'=>'open','due_at'=>reminder_utc_v1($now->modify('-1 hour'))]],
] as [$id,$title,$metadata])$q->execute([$id,$title,json_encode($metadata,JSON_THROW_ON_ERROR),$sqlTime]);
$calendarStart=static function(string $offset)use($now): void{
    $start=$now->modify($offset);
    $GLOBALS['calendar_rows'][1]=[['id'=>55,'title'=>'Partner meeting','status'=>'active','kind'=>'event','occurrence_id'=>'event:55',
        'start_at_utc'=>$start->format('Y-m-d H:i:s'),'end_at_utc'=>$start->modify('+30 minutes')->format('Y-m-d H:i:s')]];
};
$calendarStart('+10 minutes');
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$pdo->exec('PRAGMA query_only=ON');
$brief=vp3_agent_catch_up_v1($pdo,$user,'office');
expect(ids($brief)===['calendar:event:55','mission:'.$mission,'task:101'],'a meeting about to start, blocked Mission and reviewed Profile call share the same ranking');
expect(array_unique(array_values($brief['coverage']))===['ready'],'all office sources can assemble context in a read-only database');
expect($brief['items'][1]['status']==='blocked'&&$brief['items'][1]['url']==='/vp3/chat.php?agent=system&mission='.$mission,
    'the blocked Mission points to its exact canonical owner Chat assignment');
expect($brief['items'][2]['source_label']==='Profile call follow-up'&&$brief['items'][2]['url']==='/vp3/artist-listening.php?session=34'
    &&!str_contains(json_encode($brief,JSON_THROW_ON_ERROR),'private-'),'the reviewed task links to the exact owned recording without private call bindings');
$attention=vp3_cognitive_presentation_working_attention_v510($pdo,$user,0);
expect(($attention['type']??null)==='working_attention'&&$attention['title']===$brief['items'][0]['title']
    &&$attention['summary']===$brief['items'][0]['reason']&&$attention['href']===$brief['items'][0]['url']
    &&!array_key_exists('priority',$attention)&&!array_key_exists('time',$attention),
    'the owner attention presentation picks the same winning evidence without exposing internal ranking');
$chat=vp3_capability_chat_v1($pdo,$user,user_agent_principal_v236($user,null),'What should I focus on right now?',[],null,[],
    static fn()=>throw new RuntimeException('An explicit catch-up request must not invoke inference.'));
expect($chat['handled']&&array_column($chat['sources'],'url')===array_column($brief['items'],'url')
    &&$chat['tool_receipt']['capability']==='brief.attention.read'&&!$chat['tool_receipt']['changes_applied'],
    'the explicit Chat request uses the same ordered evidence and stays read only');
$calendarStart('+60 minutes');
$brief=vp3_agent_catch_up_v1($pdo,$user,'office');
expect(ids($brief)===['mission:'.$mission,'task:101','task:102'],'the Mission leads outside the meeting attention window and the call follow-up consumes only one slot');
expect(vp3_agent_catch_up_v1($pdo,['id'=>2],'office')['items']===[],'account switching does not reuse the first owner’s situation');
expect((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'cross-source context does not acknowledge, schedule or mutate records');
$pdo->exec('PRAGMA query_only=OFF');
$pdo->exec('DROP TABLE profile_calls_pa3c');
$calendarStart('+10 minutes');
$brief=vp3_agent_catch_up_v1($pdo,$user,'office');
expect($brief['coverage']['profile_calls']==='unavailable'&&$brief['coverage']['missions']==='ready'&&$brief['coverage']['calendar']==='ready'
    &&ids($brief)===['calendar:event:55','mission:'.$mission,'task:101']&&$brief['items'][2]['source_label']==='Agent Brain tasks',
    'a Profile call source outage preserves the meeting, Mission and independently authorized due task');
echo "AGENT_CATCH_UP_V1=PASS\n";
