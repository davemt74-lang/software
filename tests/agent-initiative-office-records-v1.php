<?php
declare(strict_types=1);

// Actual SQLite ledgers and production domain readers and shared scope gate.
// The active Initiative policy check is fixture-controlled; the full canonical
// policy/worker/receipt loop is exercised by the separate bridge integration.
function db(): PDO{return $GLOBALS['office_pdo'];}
function url(string $path=''): string{return '/vp3'.$path;}
function setting(string $key,mixed $fallback=null): mixed{return $fallback;}
function table_exists(string $table): bool
{
    $q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return $q->fetchColumn()!==false;
}
function column_exists(string $table,string $column): bool{return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);}
function has_permission(string $permission,array $user): bool{return !in_array($permission,$GLOBALS['office_denied'][(int)$user['id']]??[],true);}
function personal_capability_has_v242(string $permission,array $user): bool{return has_permission($permission,$user);}
function office_load_production_function(string $file,string $name): void
{
    $tokens=token_get_all(file_get_contents(dirname(__DIR__).'/includes/'.$file));
    for($i=0;$i<count($tokens);$i++){
        if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
        $j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;
        if(!is_array($tokens[$j])||$tokens[$j][0]!==T_STRING||$tokens[$j][1]!==$name)continue;
        $body='';$depth=0;$opened=false;
        for($j=$i;$j<count($tokens);$j++){
            $part=is_array($tokens[$j])?$tokens[$j][1]:$tokens[$j];$body.=$part;
            if($part==='{'){$opened=true;$depth++;}
            elseif($part==='}'&&--$depth===0&&$opened){eval($body);return;}
        }
    }
    throw new RuntimeException('Missing production function '.$name);
}
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';
require_once dirname(__DIR__).'/includes/user-calendar-v1300.php';
require_once dirname(__DIR__).'/includes/agent-scheduling-v430.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/agent-initiative-office-records-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-capabilities-v1.php';
office_load_production_function('agent-capability-chat-v1.php','vp3_capability_live_user_v1');
office_load_production_function('agent-initiative-tools-v1.php','vp3_initiative_tools_scope_v1');
function vp3_initiative_access_v1(PDO $pdo,array $user,array $binding): array
{
    if(($binding['enabled']??null)!==true)throw new RuntimeException('Initiative policy paused.',403);
    return $user;
}

$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$GLOBALS['office_pdo']=$pdo;
$pdo->sqliteCreateFunction('DATABASE',static fn():string=>'main');
$pdo->exec("ATTACH DATABASE ':memory:' AS information_schema; CREATE TABLE information_schema.statistics(table_schema TEXT,table_name TEXT,index_name TEXT)");
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,role TEXT);INSERT INTO users VALUES(1,1,'member'),(2,1,'member');
CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT);
INSERT INTO user_agents VALUES(11,1,1,'First'),(12,1,1,'Second'),(21,2,1,'Other');
CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);INSERT INTO chat_conversations VALUES(10,1,NULL),(11,1,11),(12,1,12),(20,2,NULL);
CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);
CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,memory_scope_version INTEGER,memory_type TEXT,subject TEXT,memory_text TEXT,metadata_json TEXT,is_active INTEGER,memory_hash TEXT,confidence REAL,occurrence_count INTEGER,last_seen_at TEXT);
CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_id,user_agent_id,memory_type);
CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_id,user_agent_id,occurrence_count);
CREATE TABLE agent_chat_archive(id INTEGER,user_id INTEGER,user_agent_id INTEGER,created_at TEXT);
CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_id,user_agent_id,created_at);
CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title TEXT,description TEXT,location TEXT,start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,all_day INTEGER,recurrence TEXT,agent_notifications INTEGER,source TEXT,source_reference TEXT,status TEXT,created_by_agent_id INTEGER,updated_at TEXT);
INSERT INTO user_calendar_events VALUES(101,1,'Project review','Discuss the unresolved product launch decision.','Office','2026-11-06 14:00:00','2026-11-06 15:00:00','UTC',0,'none',1,'user','','active',NULL,'2026-11-05'),
(102,2,'Other private event','Do not disclose','Secret','2026-11-06 14:00:00','2026-11-06 15:00:00','UTC',0,'none',0,'user','','active',NULL,'2026-11-05'),
(103,1,'Weekly planning','Review recurring open work.','','2026-10-30 13:00:00','2026-10-30 14:00:00','UTC',0,'weekly',1,'user','','active',NULL,'2026-11-05');");
$pdo->exec("INSERT INTO information_schema.statistics SELECT 'main',tbl_name,name FROM sqlite_master WHERE type='index'");
reminder_schema_v1($pdo);
$task=$pdo->prepare('INSERT INTO agent_memory_items VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
$meta=['task_status'=>'open','due_at'=>'2026-11-06','source_kind'=>'explicit_user','source_label'=>'Owner instruction'];
foreach([[1,1,null,'Owner task','Follow up with the product decisions.'],[2,2,null,'Other private task','Private other owner text'],[3,1,11,'First Agent task','Prepare a focused question for this Agent.'],[4,1,12,'Second Agent task','Private second Agent text']] as [$id,$owner,$aid,$title,$text])
    $task->execute([$id,$owner,$aid,410,'task',$title,$text,json_encode($meta),1,'hash-'.$id,.8,1,'2026-11-05 12:00:00']);
$reminder=$pdo->prepare("INSERT INTO agent_reminders_v1(id,owner_user_id,title,timezone,start_local,recurrence,delivery,next_at,status,conversation_id,revision,created_at,updated_at) VALUES(?,?,'Call follow-up','UTC','2026-11-06T14:00:00','daily','both','2026-11-06T14:00:00Z','active',10,1,'2026-11-05T12:00:00Z','2026-11-05T12:00:00Z')");
$reminder->execute([201,1]);$reminder->execute([202,2]);
$pdo->exec("INSERT INTO agent_reminder_occurrences_v1(id,reminder_id,owner_user_id,scheduled_at,wake_at,status) VALUES(301,201,1,'2026-11-05T14:00:00Z','2026-11-05T14:00:00Z','pending')");
$checks=0;
function office_check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function office_throws(callable $work,int $code,string $label): void{try{$work();}catch(Throwable $e){office_check($e->getCode()===$code,$label);return;}throw new RuntimeException($label.' did not fail');}
function office_scope(int $agent=0,int $owner=1): array
{
    $u=['id'=>$owner,'is_active'=>1];$a=$agent?user_agent_get_v236(db(),$owner,$agent):null;
    return ['user'=>$u,'principal'=>vp3_agent_chat_principal_v380($u,$a),'agent_id'=>$agent,'conversation_id'=>$owner===2?20:($agent?:10)];
}
function office_binding(array $scope): array{return ['enabled'=>true,'agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id']];}
function office_evidence(array $scope,?array $refs=null,?DateTimeImmutable $now=null): array
{
    $coverage=[];$rows=vp3_initiative_office_capabilities_evidence_v1(db(),$scope,office_binding($scope),$now??new DateTimeImmutable('2026-11-06T12:00:00Z'),$refs,$coverage);return [$rows,$coverage];
}
function office_read(array $scope,string $tool,string $ref,array $evidence,?array $refs=null): array
{
    return vp3_initiative_office_capabilities_read_v1(db(),$scope,office_binding($scope),$tool,['primary'=>$ref,'refs'=>$refs??[$ref]],$evidence);
}
$scope=office_scope();$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
[$all,$coverage]=office_evidence($scope);
office_check(isset($all['task:1'],$all['reminder:201'],$all['calendar:event:101'],$all['calendar:event:103@20261106T130000Z']),'real canonical task, reminder, upcoming event and exact weekly occurrence become useful Office sources');
office_check(!isset($all['task:2'],$all['task:3'],$all['task:4'],$all['reminder:202'],$all['calendar:event:102']),'discovery excludes other owners and other Agent namespaces');
office_check($all['task:1']['office_tool']==='tasks.read'&&$all['reminder:201']['office_tool']==='reminders.read'&&$all['calendar:event:101']['office_tool']==='calendar.read','each canonical ref declares its exact read tool');
$t=office_read($scope,'tasks.read','task:1',$all);$r=office_read($scope,'reminders.read','reminder:201',$all);$c=office_read($scope,'calendar.read','calendar:event:101',$all);
office_check(str_contains($t['records'][0]['text'],'Follow up with the product decisions.')&&str_contains($t['records'][0]['text'],'explicit_user'),'task read returns actual saved task content and provenance');
office_check(str_contains($r['records'][0]['text'],'2026-11-05T14:00:00Z')&&str_contains($r['records'][0]['text'],'pending_occurrences'),'reminder read returns the actual pending occurrence without delivery');
office_check(str_contains($c['records'][0]['text'],'Discuss the unresolved product launch decision.')&&str_contains($c['records'][0]['text'],'Office'),'calendar read returns owned event description and location');
$weeklyRef='calendar:event:103@20261106T130000Z';
$weekly=office_read($scope,'calendar.read',$weeklyRef,$all);
office_check(str_contains($weekly['records'][0]['text'],'"start_at_utc":"2026-11-06 13:00:00"')
    &&str_contains($weekly['records'][0]['text'],'"series_start_at_utc":"2026-10-30 13:00:00"'),'weekly detail binds the actual occurrence and distinguishes its stored series anchor');
$core=user_calendar_owned_events_v1340($pdo,1,'2026-11-06 12:00:00','2026-11-13 12:00:00');$coreRefs=[];
foreach($core as $event)$coreRefs[]='calendar:'.($event['occurrence_id']??('event:'.$event['id']));
office_check(in_array($weeklyRef,$coreRefs,true),'weekly source has exactly the existing Calendar occurrence ref instead of a competing series item');
[$invalid]=office_evidence($scope,['calendar:event:103','calendar:event:103@20261107T130000Z','calendar:event:101@20261106T140000Z','calendar:event:103@20260230T130000Z']);
office_check($invalid===[],'plain weekly series IDs, non-occurrences, one-time aliases and malformed UTC dates cannot create duplicate Calendar work');
[$outside]=office_evidence($scope,[$weeklyRef],new DateTimeImmutable('2027-01-15T12:00:00Z'));
office_check($outside[$weeklyRef]['revision']===$all[$weeklyRef]['revision'],'an exact selected occurrence revalidates stably outside the discovery horizon');
office_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'all Office discovery and detailed reads perform zero writes, scheduler ticks, leases or task reconciliation');
[$same]=office_evidence($scope,null,new DateTimeImmutable('2026-11-06T12:05:00Z'));
office_check(array_column($same,'revision','ref')===array_column($all,'revision','ref'),'source revisions remain stable without semantic changes or dynamic time fields');
$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=['user_id'=>99,'user_agent_id'=>123];
office_evidence($scope);office_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===['user_id'=>99,'user_agent_id'=>123],'task discovery restores the caller memory namespace');unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);
$pdo->exec("UPDATE agent_memory_items SET confidence=.1,occurrence_count=9,last_seen_at='2026-11-06 12:01:00' WHERE id=1");
[$same]=office_evidence($scope,['task:1']);office_check($same['task:1']['revision']===$all['task:1']['revision'],'task reinforcement and last-seen telemetry do not churn semantic revisions');
$pdo->exec("UPDATE agent_reminders_v1 SET next_at='2026-11-07T14:00:00Z',revision=99 WHERE id=201;UPDATE agent_reminder_occurrences_v1 SET lease_token='abcd',lease_until='2026-11-06T13:00:00Z',notified_at='2026-11-05T14:01:00Z' WHERE id=301");
[$same]=office_evidence($scope,['reminder:201']);office_check($same['reminder:201']['revision']===$all['reminder:201']['revision'],'same pending reminder survives scheduler advancement and notification lease telemetry');
office_check(office_read($scope,'tasks.read','task:1',$all,['task:1','calendar:event:101'])['sources'][0]['source']==='task:1','a contextual calendar ref cannot make a task tool read another tool family');
office_throws(fn()=>office_read($scope,'calendar.read','task:1',$all),409,'a mismatched tool cannot consume the exact task card');
office_throws(fn()=>office_read($scope,'tasks.read','task:1',$all,['task:1','task:1']),0,'duplicate selected Office refs are rejected');
office_throws(fn()=>office_read($scope,'calendar.manage','calendar:event:101',$all),0,'unknown write operations are rejected before effects');
$pdo->exec("UPDATE agent_memory_items SET memory_text='The owner changed the task body.' WHERE id=1");
office_throws(fn()=>office_read($scope,'tasks.read','task:1',$all),409,'a task edit invalidates the original exact source before its details are returned');
[$fresh]=office_evidence($scope,['task:1']);office_check($fresh['task:1']['revision']!==$all['task:1']['revision'],'the edited task gets a fresh semantic revision');
$pdo->exec("UPDATE user_calendar_events SET description='Revised calendar subject.' WHERE id=101");
office_throws(fn()=>office_read($scope,'calendar.read','calendar:event:101',$all),409,'calendar detail edits invalidate queued private preparation');
$pdo->exec("UPDATE agent_reminder_occurrences_v1 SET wake_at='2026-11-06T16:00:00Z' WHERE id=301");
office_throws(fn()=>office_read($scope,'reminders.read','reminder:201',$all),409,'actual occurrence snooze changes invalidate old reminder results');
[$foreign,$foreignCoverage]=office_evidence($scope,['task:2','task:3','calendar:event:102','reminder:202']);
office_check($foreign===[],'exact selected refs cannot retrieve another owner or Agent outside the discovery window');
$named=office_scope(11);[$namedEvidence]=office_evidence($named);
office_check(isset($namedEvidence['task:3'])&&!isset($namedEvidence['task:1'],$namedEvidence['task:4']),'named Agent reads only its canonical task namespace');
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(11,'knowledge','brain:3','deny')");
[$denied,$deniedCoverage]=office_evidence($named,['task:3']);office_check($denied===[],'current per-Agent task data-rule denial removes the source');
office_throws(fn()=>office_read($named,'tasks.read','task:3',$namedEvidence),409,'revoked per-Agent task access invalidates a previously prepared read');
$pdo->exec("DELETE FROM user_agent_data_rules WHERE agent_id=11");
$GLOBALS['office_denied'][1]=['agent_brain.access'];
[$denied,$deniedCoverage]=office_evidence($scope);
office_check(!isset($denied['task:1'])&&$deniedCoverage['office_tasks']==='forbidden'&&isset($denied['calendar:event:101'],$denied['reminder:201']),'Brain task permission is separate from permitted Calendar and Reminder reads');
office_throws(fn()=>office_read($scope,'tasks.read','task:1',$fresh),403,'task personal-permission revocation prevents detailed reading');unset($GLOBALS['office_denied'][1]);
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');office_throws(fn()=>office_evidence($scope),403,'account deactivation is freshly checked');$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=11');office_throws(fn()=>office_evidence($named),403,'Agent retirement cannot fall back to the System namespace');$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=11');
$bad=$scope;$bad['conversation_id']=20;office_throws(fn()=>office_evidence($bad),403,'an account-switched foreign conversation is denied');
$bad=$scope;$bad['principal']['viewer_user_id']=2;office_throws(fn()=>office_evidence($bad),403,'a foreign principal cannot be replaced with owner authority');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>0,'participant'=>9];office_throws(fn()=>office_evidence($scope),403,'participant conversations cannot read private autonomous Office context');unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$pdo->exec("UPDATE agent_memory_items SET metadata_json='{".'"task_status":"completed","due_at":"2026-11-06"'."}' WHERE id=1");
[$closed]=office_evidence($scope,['task:1']);office_check($closed===[],'completed task sources leave the useful open-work context');
$pdo->exec('DELETE FROM agent_reminders_v1 WHERE id=201');office_throws(fn()=>office_read($scope,'reminders.read','reminder:201',$all),409,'deleted reminder cannot be replayed from saved metadata');
$pdo->exec("UPDATE user_calendar_events SET status='cancelled' WHERE id=101");office_throws(fn()=>office_read($scope,'calendar.read','calendar:event:101',$all),409,'cancelled calendar events invalidate saved detail results');
for($i=1000;$i<1110;$i++)$task->execute([$i,1,null,410,'task','Bounded '.$i,'Private follow-up '.str_repeat('🌵',1000),json_encode($meta),1,'hash-'.$i,.8,1,'2026-11-06 11:00:00']);
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();[$bounded,$boundedCoverage]=office_evidence($scope);
office_check(count(array_filter($bounded,static fn(array $row):bool=>$row['office_tool']==='tasks.read'))===4&&$boundedCoverage['office_tasks']==='limited','task discovery has a four-item result budget and explicit limited coverage');
[$selected]=office_evidence($scope,['task:1000']);office_check(isset($selected['task:1000']),'exact task source outside the latest discovery window can still be revalidated');
$large=office_read($scope,'tasks.read','task:1000',$selected);office_check($large['coverage']['task:1000']==='limited'&&strlen($large['records'][0]['text'])<8000,'multibyte task content is byte bounded and reports truncation');
$old=$selected['task:1000']['revision'];$pdo->exec("UPDATE agent_memory_items SET memory_text=memory_text||' changed outside preview' WHERE id=1000");[$changed]=office_evidence($scope,['task:1000']);office_check($changed['task:1000']['revision']!==$old,'edits beyond the displayed preview still invalidate source proofs');
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$paused=['enabled'=>false,'agent_id'=>0,'conversation_id'=>10];$verifyCoverage=[];
office_throws(function()use($pdo,$scope,$paused,&$verifyCoverage):void{vp3_initiative_office_capabilities_evidence_v1($pdo,$scope,$paused,new DateTimeImmutable('2026-11-06T12:00:00Z'),['calendar:event:103@20261106T130000Z'],$verifyCoverage);},403,'paused Initiative cannot admit new Office work');
$verify=$scope;$verify['verification_only']=true;
$verified=vp3_initiative_office_capabilities_evidence_v1($pdo,$verify,$paused,new DateTimeImmutable('2026-11-06T12:00:00Z'),['calendar:event:103@20261106T130000Z'],$verifyCoverage);
office_check(isset($verified['calendar:event:103@20261106T130000Z'])&&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'read-only saved-result verification remains available after pause and performs zero writes');
$pdo->exec("UPDATE user_calendar_events SET start_at_utc='2026-10-30 14:00:00',end_at_utc='2026-10-30 15:00:00' WHERE id=103");
office_throws(fn()=>office_read($scope,'calendar.read',$weeklyRef,$all),409,'a real weekly-series time change invalidates its old exact occurrence');
$pdo->exec("INSERT INTO user_calendar_events VALUES(104,1,'Weekly local time','Maintain the local wall time.','','2026-10-30 13:00:00','2026-10-30 14:00:00','America/New_York',0,'weekly',0,'user','','active',NULL,'2026-11-05')");
[$dst]=office_evidence($scope,['calendar:event:104@20261106T140000Z']);
$dstRead=office_read($scope,'calendar.read','calendar:event:104@20261106T140000Z',$dst);
office_check(str_contains($dstRead['records'][0]['text'],'"start_at_utc":"2026-11-06 14:00:00"')&&str_contains($dstRead['records'][0]['text'],'America/New_York'),'weekly selected read reuses canonical DST-aware expansion instead of assuming UTC plus seven days');
$pdo->exec('ALTER TABLE user_calendar_events RENAME TO calendar_unavailable');
[$unavailable,$unavailableCoverage]=office_evidence($scope,['calendar:event:103@20261106T130000Z']);
office_check($unavailable===[]&&$unavailableCoverage['office_calendar']==='unavailable','missing Calendar storage is unavailable rather than a successful empty read');
$pdo->exec('ALTER TABLE calendar_unavailable RENAME TO user_calendar_events');
$event=$pdo->prepare("INSERT INTO user_calendar_events VALUES(?,1,?,'Calendar context','','2026-11-06 15:00:00','2026-11-06 16:00:00','UTC',0,'none',0,'user','','active',NULL,'2026-11-05')");
for($i=1000;$i<1060;$i++)$event->execute([$i,'Calendar '.$i]);
[$calendarBounded,$calendarCoverage]=office_evidence($scope);
office_check(count(array_filter($calendarBounded,static fn(array $row):bool=>$row['office_tool']==='calendar.read'))===4&&$calendarCoverage['office_calendar']==='limited','Calendar discovery is row bounded and honestly reports partial event coverage');
[$calendarSelected]=office_evidence($scope,['calendar:event:1059']);
office_check(isset($calendarSelected['calendar:event:1059']),'exact Calendar source outside the candidate page can still be revalidated');
$pdo->exec("UPDATE agent_memory_items SET metadata_json='{invalid' WHERE id=1109");
[$partial,$partialCoverage]=office_evidence($scope);
office_check(count(array_filter($partial,static fn(array $row):bool=>$row['office_tool']==='tasks.read'))===4&&!isset($partial['task:1109'])&&$partialCoverage['office_tasks']==='limited','one malformed task is excluded without hiding other verified work or claiming complete task coverage');
[$corrupt,$corruptCoverage]=office_evidence($scope,['task:1109']);
office_check($corrupt===[]&&$corruptCoverage['office_tasks']==='unavailable','exact selected malformed metadata is unavailable rather than a successful empty read');
office_throws(fn()=>office_read($scope,'tasks.read','task:1109',[$bounded['task:1109']['ref']=>$bounded['task:1109']]),503,'a saved exact task proof cannot return malformed source metadata');

$pdo->exec("UPDATE user_calendar_events SET status='active' WHERE id=101;
INSERT INTO user_calendar_events VALUES(105,1,'All-day note','','','2026-11-06 00:00:00','2026-11-07 00:00:00','UTC',1,'none',0,'user','','active',NULL,'2026-11-05');
CREATE TABLE video_meetings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,booking_id INTEGER,calendar_event_id INTEGER,public_id TEXT,room_name TEXT,agent_mode TEXT,start_at_utc TEXT,status TEXT);
CREATE TABLE video_meeting_participants(id INTEGER,invite_token TEXT);
CREATE TABLE video_meeting_transcript_segments(id INTEGER);
CREATE TABLE video_meeting_artifacts(id INTEGER);
INSERT INTO video_meetings VALUES(901,1,NULL,101,'0123456789abcdef0123456789abcdef','','notes','2026-11-06 14:00:00','scheduled'),
    (902,2,NULL,103,'abcdef0123456789abcdef0123456789','','notes','2026-11-06 14:00:00','scheduled');
CREATE TABLE video_meeting_agenda_items(id INTEGER PRIMARY KEY,meeting_id INTEGER,owner_user_id INTEGER,item_text TEXT,item_type TEXT,source_kind TEXT,
    source_meeting_id INTEGER,source_hash TEXT,source_index_hash TEXT,source_review_path TEXT,status TEXT,priority TEXT,action_kind TEXT,
    approval_state TEXT,sort_order INTEGER,fingerprint TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE video_meeting_action_executions(id INTEGER PRIMARY KEY,agenda_item_id INTEGER,meeting_id INTEGER,owner_user_id INTEGER,action_kind TEXT,status TEXT,
    draft_json TEXT,draft_hash TEXT,idempotency_key TEXT,workflow_run_id INTEGER,calendar_event_id INTEGER,crm_activity_id INTEGER,crm_lead_id INTEGER,email_recipient TEXT,error_class TEXT,result_json TEXT,executed_at TEXT);
CREATE TABLE video_meeting_action_events(id INTEGER);");
$agenda=$pdo->prepare("INSERT INTO video_meeting_agenda_items(id,meeting_id,owner_user_id,item_text,item_type,status,action_kind,sort_order) VALUES(?,?,?,?,?,?,?,?)");
$agenda->execute([1,901,1,'Review the launch order','discussion','open','',10]);
$agenda->execute([2,901,1,'Send the agenda','follow_up','follow_up','email',20]);
$agenda->execute([3,901,1,'Confirm delivery with the vendor','follow_up','follow_up','email',30]);
$agenda->execute([4,901,2,'Foreign agenda item','discussion','open','',40]);
$agenda->execute([5,902,2,'Foreign meeting plan','discussion','open','',50]);
$agenda->execute([6,901,1,'Send the venue directions','follow_up','follow_up','email',60]);
$pdo->exec("INSERT INTO video_meeting_action_executions(id,agenda_item_id,meeting_id,owner_user_id,action_kind,status) VALUES
    (1,2,901,1,'email','completed'),(2,3,901,1,'email','failed');");
$pdo->prepare("INSERT INTO video_meeting_action_executions(id,agenda_item_id,meeting_id,owner_user_id,action_kind,status,result_json,executed_at)
    VALUES(3,6,901,1,'email','executed',?,'2026-11-06 11:00:00')")->execute(['{"delivery":"accepted_by_mail_transport"}']);
$meetingTask=['source_kind'=>'video_meeting_intelligence','video_meeting_id'=>901,'review_state'=>'approved','task_status'=>'open'];
$task->execute([2000,1,null,410,'task','Review the slide deck','',json_encode($meetingTask),1,'prep-open',.8,1,'2026-11-05 12:00:00']);
$task->execute([2001,1,null,410,'task','Draft the handout','',json_encode(array_replace($meetingTask,['task_status'=>'completed'])),1,'prep-done',.8,1,'2026-11-05 12:00:00']);
$task->execute([2002,1,11,410,'task','Different Agent prep','',json_encode($meetingTask),1,'prep-other',.8,1,'2026-11-05 12:00:00']);
$now=new DateTimeImmutable('2026-11-06T12:00:00Z');
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$pdo->exec('PRAGMA query_only=ON');
$today=array_column(vp3_calendar_prep_today_v1($pdo,$scope['user'],0,10,$now),null,'id');
$prep=$today[101];
office_check($prep['agenda_status']==='available'&&$prep['meeting_url']==='/vp3/meeting.php?meeting=0123456789abcdef0123456789abcdef',
    'today’s prep locates the exact owned meeting and its saved review link');
office_check($prep['open_items']===['Agenda · Review the launch order','Review the slide deck']
    &&$prep['completed_items']===['Send the agenda','Send the venue directions (mail transport accepted)','Draft the handout']&&$prep['unknown_items']===['Confirm delivery with the vendor'],
    'prep distinguishes open agenda and linked tasks, recorded completion, and uncertain delivery');
office_check(!reset($today)['all_day']&&!isset($today[105]),'the next timed events lead the bounded current-day prep list');
office_check($today[103]['agenda_status']==='unknown'&&$today[103]['meeting_url']===''&&!str_contains(json_encode($today),'Foreign'),
    'recurring occurrences cannot inherit an unrelated or foreign meeting agenda');
office_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'calendar prep works in query-only mode without completing or reconciling tasks');
$pdo->exec('PRAGMA query_only=OFF');
office_throws(fn()=>vp3_calendar_prep_today_v1($pdo,$scope['user'],0,20,$now),403,'calendar prep refuses a different owner’s conversation');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>0,'participant'=>9];
office_throws(fn()=>vp3_calendar_prep_today_v1($pdo,$scope['user'],0,10,$now),403,'calendar prep refuses a participant context');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
[$prepared]=office_evidence($scope,['calendar:event:101'],$now);
$preparedRead=office_read($scope,'calendar.read','calendar:event:101',$prepared);
office_check(str_contains($preparedRead['records'][0]['text'],'Draft the handout')&&$preparedRead['sources'][0]['url']===$prep['meeting_url'],
    'the existing Initiative calendar executor receives the same verified prep and an exact source link');
$pdo->exec("UPDATE agent_memory_items SET metadata_json=JSON_SET(metadata_json,'$.task_status','completed') WHERE id=2000");
office_throws(fn()=>office_read($scope,'calendar.read','calendar:event:101',$prepared),409,'a real linked prep completion invalidates prior Initiative source evidence');
office_check(vp3_calendar_prep_chat_intent_v1("What have I done and what's left for today's event?")
    &&vp3_calendar_prep_chat_intent_v1('What do I need to do for my meeting today?')
    &&!vp3_calendar_prep_chat_intent_v1('Add an event to my calendar today')
    &&!vp3_calendar_prep_chat_intent_v1('What is left to prepare for tomorrow’s event?'),
    'the prep intent accepts today’s questions and leaves mutations and other dates with their own tools');
echo 'AGENT_INITIATIVE_OFFICE_RECORDS_V1=PASS ('.$checks.' cases)'.PHP_EOL;
