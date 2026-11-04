<?php
declare(strict_types=1);

function url(string $path=''): string{return '/vp3'.$path;}
function has_permission(string $permission,array $user): bool{return !in_array($permission,$user['denied']??[],true);}
function personal_capability_has_v242(string $permission,array $user): bool{return has_permission($permission,$user);}
function user_agent_get_v236(PDO $pdo,int $owner,int $agent): ?array{return $owner===1&&$agent===7?['id'=>7,'is_active'=>1]:null;}
function user_agent_principal_v236(array $user,?array $agent): array{return ['owner_user_id'=>$user['id'],'kind'=>$agent?'user_agent':'system','agent_id'=>$agent['id']??0];}
function vp3_capability_live_user_v1(PDO $pdo,array $user,array $principal,array $context,?callable $authority): array
{
    $q=$pdo->prepare('SELECT is_active FROM users WHERE id=?');$q->execute([$user['id']]);
    if(!$q->fetchColumn())throw new RuntimeException('Account disabled',403);
    return $user;
}
function vp3_participant_scope_v1(): ?array{return $GLOBALS['participant_scope']??null;}
function vp3_participant_resource_v1(PDO $pdo,array $user,array $principal,string $kind,int $id): bool{return true;}
function vp3_task_list_authorize_v1(PDO $pdo,array $user,array $principal,int $conversation): void{}
function vp3_task_list_task_allowed_v1(PDO $pdo,array $user,array $principal,int $id): bool{return $id!==77;}
function vp3_agent_memory_scope_schema_ready_v410(PDO $pdo): bool{return table_exists('agent_memory_items');}
function vp3_agent_memory_scope_sql_v410(?int $agent): array{return $agent?['user_agent_id=?',[$agent]]:['user_agent_id IS NULL',[]];}
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
function video_meeting_row_v1800(PDO $pdo,int $meeting): ?array{return $meeting===42?['owner_user_id'=>1,'public_id'=>'meeting-42']:null;}
function table_exists(string $table): bool
{
    $q=$GLOBALS['pdo']->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);
    return (bool)$q->fetchColumn();
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
require $root.'/includes/agent-catch-up-v1.php';

$pdo=$GLOBALS['pdo']=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);
    INSERT INTO users VALUES(1,1),(2,1),(3,0);
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);
    INSERT INTO chat_conversations VALUES(50,1,NULL),(51,2,NULL);
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
echo "AGENT_CATCH_UP_V1=PASS\n";
