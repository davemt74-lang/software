<?php
declare(strict_types=1);

/** Disposable native MariaDB fixture; production meeting/Calendar SQL runs
 * unchanged. No credentials, providers, inference or mail service are used. */
$dsn=(string)(getenv('VP3_TEST_MYSQL_DSN')?:getenv('VP3_MYSQL_TEST_DSN'));
if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('Run with an explicit disposable VP3_TEST_MYSQL_DSN.');
$login=(string)(getenv('VP3_TEST_MYSQL_USER')?:getenv('VP3_MYSQL_TEST_USER')?:'root');$password=(string)(getenv('VP3_TEST_MYSQL_PASS')?:getenv('VP3_MYSQL_TEST_PASSWORD'));
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$admin=new PDO($dsn,$login,$password,$options);$database='meeting_chat_'.bin2hex(random_bytes(6));
$admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
$pdo=$GLOBALS['pdo']=new PDO(preg_replace('/;dbname=[^;]*/','',$dsn).';dbname='.$database,$login,$password,$options);
function db(): PDO { return $GLOBALS['pdo']; }
function url(string $path=''): string { return '/vp3'.$path; }
function setting(string $key,mixed $fallback=null): mixed { return $fallback; }
function has_permission(string $permission,array $user): bool { return !in_array($permission,$GLOBALS['denied']??[],true); }
function create_notification(...$args): void { $GLOBALS['notifications']=($GLOBALS['notifications']??0)+1; }
function table_exists(string $table): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return (bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return (bool)$q->fetchColumn();
}
require_once __DIR__.'/../includes/participant-updates-v1.php';
require_once __DIR__.'/../includes/user-agent-system-v236.php';
require_once __DIR__.'/../includes/agent-chat-boundary-v380.php';
require_once __DIR__.'/../includes/agent-scheduling-v430.php';
require_once __DIR__.'/../includes/agent-capability-chat-v1.php';
require_once __DIR__.'/../includes/agent-meeting-management-v1.php';

function meeting_management_check(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']=($GLOBALS['checks']??0)+1;echo 'PASS '.$label.PHP_EOL;
}
function meeting_management_reject(callable $call,string $label): void
{
    try { $call(); } catch (RuntimeException|LogicException $e) { if ($e instanceof PDOException) throw $e;meeting_management_check(true,$label);return; }
    throw new RuntimeException('Expected rejection: '.$label);
}

try {
    $pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active TINYINT NOT NULL,email VARCHAR(190),display_name VARCHAR(190));
        INSERT INTO users VALUES(1,1,'owner@example.test','Owner'),(2,1,'member@example.test','Member'),(3,1,'foreign@example.test','Foreign');
        CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED,is_active TINYINT,display_name VARCHAR(190));
        INSERT INTO user_agents VALUES(7,1,1,'Work'),(8,1,1,'Other'),(9,3,1,'Foreign');
        CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT UNSIGNED NULL);
        INSERT INTO chat_conversations VALUES(11,1,7),(12,1,8),(13,3,9),(14,1,NULL);
        CREATE TABLE agent_scheduling_bookings(id BIGINT UNSIGNED PRIMARY KEY);
        INSERT INTO agent_scheduling_bookings VALUES(45);
        CREATE TABLE crm_contacts(id BIGINT UNSIGNED PRIMARY KEY,email_normalized VARCHAR(190));
        CREATE TABLE video_meeting_intelligence_state(id INT PRIMARY KEY,last_live_source_hash TEXT,final_source_hash TEXT,handoff_source_hash TEXT);
        CREATE TABLE video_meeting_notes(id INT PRIMARY KEY);
        CREATE TABLE video_meeting_objectives(id INT PRIMARY KEY);");
    user_calendar_ensure_schema_v1300($pdo);
    video_meeting_ensure_schema_v1800($pdo);
    video_meeting_manual_ensure_schema_v1830($pdo);
    video_meeting_agenda_ensure_schema_v18140($pdo);
    $now=new DateTimeImmutable('2030-11-10T10:00:00Z');
    $user=video_meeting_user_v1800($pdo,1);
    $meeting=video_meeting_create_v1800($pdo,$user,['title'=>'Delivery review','description'=>'Review dates',
        'start_at_utc'=>'2030-11-11 15:00:00','end_at_utc'=>'2030-11-11 15:30:00','timezone'=>'America/New_York','organizer_agent_id'=>7]);
    video_meeting_set_guest_access_mode_v1830($pdo,$meeting,'invite_only');
    $member=video_meeting_add_participant_v1800($pdo,$meeting,['user_id'=>2,'role'=>'attendee'],false);
    $baseNotifications=$GLOBALS['notifications']??0;
    $id=(int)$meeting['id'];$eventId=(int)$meeting['calendar_event_id'];
    $prepare=static fn(array $request):array=>vp3_meeting_management_prepare_v1(db(),$user,7,11,$request,null,$now);
    $apply=static function(array $plan,?callable $authority=null) use($user,$now):array {
        $pdo=db();$pdo->beginTransaction();
        try { $result=vp3_meeting_management_apply_v1($pdo,$user,7,11,$plan,$authority,$now);$pdo->commit();return $result; }
        catch (Throwable $e) { if ($pdo->inTransaction())$pdo->rollBack();throw $e; }
    };
    $agenda=['action'=>'agenda_add','meeting_id'=>$id,'item_text'=>'Confirm the Friday owner','item_type'=>'decision','priority'=>'high'];
    $plan=$prepare($agenda);
    meeting_management_check(!$pdo->inTransaction()&&(int)$pdo->query('SELECT COUNT(*) FROM video_meeting_agenda_items')->fetchColumn()===0
        &&$plan['review']['outbound_notices']===false&&$plan['details'][0]['value']==='Delivery review (#'.$id.')',
        'review is read-only, precise and explicit about local effects');
    meeting_management_reject(static fn()=>vp3_meeting_management_apply_v1($pdo,$user,7,11,$plan,null,$now),'apply requires the durable review transaction');
    $saved=$apply($plan);
    meeting_management_check($saved['verified']===true&&$saved['agenda_item_id']>0&&$saved['outbound_notices_sent']===false,
        'approved agenda text is inserted through the native agenda kernel and read back');
    $again=$apply($prepare($agenda));
    meeting_management_check($again['agenda_item_id']===$saved['agenda_item_id']&&$again['already_present']===true
        &&(int)$pdo->query('SELECT COUNT(*) FROM video_meeting_agenda_items')->fetchColumn()===1,'same reviewed agenda text does not create a duplicate');
    meeting_management_check(vp3_meeting_management_inspect_v1($pdo,$user,7,11,$plan,$saved)===$saved,'current outcome projection reads without reapplying');
    $pdo->prepare('UPDATE video_meeting_agenda_items SET item_text=? WHERE id=?')->execute(['Edited by owner',$saved['agenda_item_id']]);
    meeting_management_reject(static fn()=>vp3_meeting_management_inspect_v1($pdo,$user,7,11,$plan,$saved),'an edited agenda outcome is not silently restored');
    $pdo->prepare('UPDATE video_meeting_agenda_items SET item_text=? WHERE id=?')->execute([$agenda['item_text'],$saved['agenda_item_id']]);
    meeting_management_reject(static fn()=>$apply($prepare(array_replace($agenda,['priority'=>'low']))),'duplicate text cannot overwrite existing agenda settings');
    meeting_management_reject(static fn()=>vp3_meeting_management_prepare_v1($pdo,$user,8,12,$agenda,null,$now),'another owned Agent cannot mutate this meeting');
    meeting_management_reject(static fn()=>vp3_meeting_management_prepare_v1($pdo,$user,7,12,$agenda,null,$now),'a mismatched Chat namespace is denied');
    meeting_management_reject(static fn()=>vp3_meeting_management_prepare_v1($pdo,['id'=>3],9,13,$agenda,null,$now),'another account cannot mutate this meeting');
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['viewer_user_id'=>1];
    meeting_management_reject(static fn()=>$prepare($agenda),'participant views cannot invoke owner meeting controls');
    unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
    $GLOBALS['denied']=['account.access'];meeting_management_reject(static fn()=>$prepare($agenda),'revoked account permission is denied');unset($GLOBALS['denied']);
    $pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');meeting_management_reject(static fn()=>$prepare($agenda),'inactive Agent is denied');$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
    $pdo->prepare("UPDATE video_meeting_participants SET invitation_status='revoked' WHERE meeting_id=? AND role='organizer'")->execute([$id]);
    meeting_management_reject(static fn()=>$prepare($agenda),'revoked organizer is denied');
    $pdo->prepare("UPDATE video_meeting_participants SET invitation_status='accepted' WHERE meeting_id=? AND role='organizer'")->execute([$id]);
    $pdo->prepare('UPDATE video_meetings SET booking_id=45 WHERE id=?')->execute([$id]);meeting_management_reject(static fn()=>$prepare($agenda),'booking-backed meetings stay in Scheduling');$pdo->prepare('UPDATE video_meetings SET booking_id=NULL WHERE id=?')->execute([$id]);
    $pdo->prepare("UPDATE user_calendar_events SET recurrence='weekly' WHERE id=?")->execute([$eventId]);meeting_management_reject(static fn()=>$prepare($agenda),'weekly Calendar series cannot impersonate one meeting');$pdo->prepare("UPDATE user_calendar_events SET recurrence='none' WHERE id=?")->execute([$eventId]);
    $pdo->prepare("UPDATE video_meetings SET status='live' WHERE id=?")->execute([$id]);meeting_management_reject(static fn()=>$prepare($agenda),'live meetings use their live controls');$pdo->prepare("UPDATE video_meetings SET status='scheduled' WHERE id=?")->execute([$id]);

    $change=['action'=>'reschedule','meeting_id'=>$id,'date'=>'2030-11-12','start_time'=>'11:00','end_date'=>'2030-11-12','end_time'=>'11:45','timezone'=>'America/New_York'];
    $timePlan=$prepare($change);
    meeting_management_check($timePlan['parameters']['start_at_utc']==='2030-11-12 16:00:00'&&$timePlan['parameters']['end_at_utc']==='2030-11-12 16:45:00',
        'review preserves exact local time and converts it using the named timezone');
    meeting_management_reject(static fn()=>$prepare($change+['notify'=>true]),'change notices are rejected before any local mutation');
    meeting_management_reject(static fn()=>$prepare(array_replace($change,['timezone'=>'Wrong/Timezone'])),'invalid timezone never falls back silently');
    meeting_management_reject(static fn()=>$prepare(array_replace($change,['date'=>'2031-03-09','start_time'=>'02:30','end_date'=>'2031-03-09','end_time'=>'03:30'])),'spring DST gap is rejected');
    meeting_management_reject(static fn()=>$prepare(array_replace($change,['date'=>'2031-11-02','start_time'=>'01:30','end_date'=>'2031-11-02','end_time'=>'02:30'])),'autumn DST fold is rejected instead of choosing an instant');
    meeting_management_reject(static fn()=>$prepare(array_replace($change,['end_time'=>'10:00'])),'end before start is rejected');
    meeting_management_reject(static fn()=>$prepare(array_replace($change,['end_date'=>'2030-11-14'])),'meeting longer than the native 24-hour limit is rejected');
    meeting_management_reject(static fn()=>$prepare(array_replace($change,['date'=>'2030-11-09','end_date'=>'2030-11-09'])),'past meeting time is rejected');
    $tampered=$timePlan;$tampered['parameters']['start_time']='13:00';meeting_management_reject(static fn()=>$apply($tampered),'a changed review cannot be applied');
    $pdo->prepare('UPDATE user_calendar_events SET description=? WHERE id=?')->execute(['Owner changed the notes',$eventId]);
    meeting_management_reject(static fn()=>$apply($timePlan),'a native Calendar edit invalidates the old review');
    $timePlan=$prepare($change);$timeSaved=$apply($timePlan);
    $ownerEvent=user_calendar_event_v1300($pdo,1,$eventId);$memberEvent=user_calendar_event_v1300($pdo,2,(int)$member['calendar_event_id']);
    meeting_management_check($timeSaved['verified']===true&&$ownerEvent['start_at_utc']==='2030-11-12 16:00:00'
        &&$ownerEvent['description']==='Owner changed the notes'&&$memberEvent['start_at_utc']===$ownerEvent['start_at_utc']
        &&$memberEvent['end_at_utc']===$ownerEvent['end_at_utc']&&$memberEvent['timezone']==='America/New_York',
        'native meeting update preserves notes and atomically synchronizes organizer and participant calendars');
    meeting_management_check(vp3_meeting_management_inspect_v1($pdo,$user,7,11,$timePlan,$timeSaved)===$timeSaved,'time change receipt is checked against current canonical rows');
    $unavailablePlan=$prepare(array_replace($change,['date'=>'2030-11-13','end_date'=>'2030-11-13']));
    $pdo->exec("CREATE TRIGGER block_participant_projection BEFORE UPDATE ON user_calendar_events FOR EACH ROW BEGIN IF OLD.owner_user_id=2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture projection failure'; END IF; END");
    meeting_management_reject(static fn()=>$apply($unavailablePlan),'failed participant projection aborts the whole meeting change');
    $pdo->exec('DROP TRIGGER block_participant_projection');
    meeting_management_check(video_meeting_row_v1800($pdo,$id)['start_at_utc']==='2030-11-12 16:00:00'
        &&user_calendar_event_v1300($pdo,1,$eventId)['start_at_utc']==='2030-11-12 16:00:00','projection failure rolls back owner Calendar and meeting together');
    $cancelPlan=$prepare(['action'=>'cancel','meeting_id'=>$id]);
    $calls=0;meeting_management_reject(static fn()=>$apply($cancelPlan,static function()use(&$calls,$pdo,$id):void {
        if (++$calls===2)$pdo->prepare("UPDATE video_meeting_participants SET invitation_status='revoked' WHERE meeting_id=? AND role='organizer'")->execute([$id]);
    }),'organizer revocation during final authority recheck prevents cancellation');
    meeting_management_check(video_meeting_row_v1800($pdo,$id)['status']==='scheduled','denied final recheck leaves meeting active');
    $cancelPlan=$prepare(['action'=>'cancel','meeting_id'=>$id]);$cancelSaved=$apply($cancelPlan);
    meeting_management_check($cancelSaved['verified']===true&&video_meeting_row_v1800($pdo,$id)['status']==='cancelled'
        &&user_calendar_event_v1300($pdo,1,$eventId)['status']==='cancelled'
        &&user_calendar_event_v1300($pdo,2,(int)$member['calendar_event_id'])['status']==='cancelled',
        'cancellation updates canonical meeting and all linked VP3 calendars atomically');
    meeting_management_check(vp3_meeting_management_inspect_v1($pdo,$user,7,11,$cancelPlan,$cancelSaved)===$cancelSaved,'cancellation outcome can be read without reopening or reapplying');
    meeting_management_check(($GLOBALS['notifications']??0)===$baseNotifications&&$cancelSaved['after_commit']===null,
        'Chat meeting actions invoke no email, member notice or provider-calendar lifecycle');
    echo 'MEETING_MANAGEMENT_MYSQL=PASS ('.$GLOBALS['checks'].' native service checks)'.PHP_EOL;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');
}
