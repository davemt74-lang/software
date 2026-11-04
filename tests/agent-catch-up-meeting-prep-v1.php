<?php
declare(strict_types=1);

function url(string $path=''): string{return '/vp3'.$path;}
function has_permission(string $key,array $user): bool{return !in_array($key,$user['denied']??[],true);}
function vp3_participant_scope_v1(): ?array{return $GLOBALS['participant_scope']??null;}
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
function setting(string $key,mixed $default=''): mixed{return $default;}
function check_meeting_prep(bool $ok,string $name): void
{
    if(!$ok)throw new RuntimeException($name);
    echo 'PASS '.$name."\n";
}

require_once __DIR__.'/../includes/agent-chat-boundary-v380.php';
require_once __DIR__.'/../includes/agent-scheduling-v430.php';
require_once __DIR__.'/../includes/calendar-schedule-awareness-v1330.php';
require_once __DIR__.'/../includes/agent-catch-up-v1.php';
require_once __DIR__.'/../includes/cognitive-presentation-v510.php';
require_once __DIR__.'/../includes/video-meetings-cross-meeting-continuity-guard-v18210.php';

$pdo=$GLOBALS['pdo']=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);
    INSERT INTO users VALUES(1,1),(2,1);
    CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name TEXT,is_active INTEGER);
    INSERT INTO user_agents VALUES(7,1,'Work Agent',1),(8,1,'Other Agent',1);
    CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title TEXT,status TEXT,all_day INTEGER,start_at_utc TEXT,end_at_utc TEXT,
        timezone TEXT DEFAULT 'UTC',source TEXT DEFAULT 'automation',source_reference TEXT DEFAULT '',description TEXT DEFAULT '',location TEXT DEFAULT '');
    CREATE TABLE video_meetings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,organizer_agent_id INTEGER,public_id TEXT,room_name TEXT,agent_mode TEXT,
        calendar_event_id INTEGER,status TEXT,title TEXT,start_at_utc TEXT,end_at_utc TEXT);
    CREATE TABLE video_meeting_participants(id INTEGER PRIMARY KEY,meeting_id INTEGER,user_id INTEGER,role TEXT,invitation_status TEXT,invite_token TEXT);
    CREATE TABLE video_meeting_transcript_segments(id INTEGER PRIMARY KEY);
    CREATE TABLE video_meeting_artifacts(id INTEGER PRIMARY KEY);
    CREATE TABLE video_meeting_notes(id INTEGER PRIMARY KEY);
    CREATE TABLE video_meeting_objectives(id INTEGER PRIMARY KEY);
    CREATE TABLE video_meeting_intelligence_state(id INTEGER PRIMARY KEY,last_live_source_hash TEXT,final_source_hash TEXT,handoff_source_hash TEXT);
    CREATE TABLE video_meeting_agenda_items(id INTEGER PRIMARY KEY,meeting_id INTEGER,owner_user_id INTEGER,item_text TEXT,item_type TEXT,source_kind TEXT,
        source_meeting_id INTEGER,source_hash TEXT,source_index_hash TEXT,status TEXT,priority TEXT,action_kind TEXT,approval_state TEXT,sort_order INTEGER,fingerprint TEXT,updated_at TEXT);
    CREATE TABLE video_meeting_continuity_links(id INTEGER PRIMARY KEY,owner_user_id INTEGER,source_meeting_id INTEGER,source_agenda_item_id INTEGER,
        target_meeting_id INTEGER,target_agenda_item_id INTEGER,status TEXT,lineage_hash TEXT,updated_at TEXT);
    CREATE TABLE video_meeting_continuity_events(id INTEGER PRIMARY KEY);
    CREATE TABLE video_meeting_action_executions(id INTEGER PRIMARY KEY,owner_user_id INTEGER,agenda_item_id INTEGER,meeting_id INTEGER,status TEXT,updated_at TEXT);
    CREATE TABLE video_meeting_followthrough_closures(id INTEGER PRIMARY KEY,owner_user_id INTEGER,execution_id INTEGER,status TEXT,verified_at TEXT);");
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$at=static fn(string $offset):string=>$now->modify($offset)->format('Y-m-d H:i:s');
$fixture=static function()use($pdo,$at): array{
    $prior=str_repeat('a',32);$upcoming=str_repeat('b',32);
    $start=$at('+10 minutes');$end=$at('+40 minutes');$updated=$at('-1 hour');
    $lineage=video_meeting_continuity_lineage_hash_v18210(1,11,2);
    $hash=hash('sha256','continuity-source|1|11');
    $pdo->prepare("INSERT INTO user_calendar_events(id,owner_user_id,title,status,all_day,start_at_utc,end_at_utc)
        VALUES(55,1,'Partner meeting','active',0,?,?)")->execute([$start,$end]);
    $q=$pdo->prepare("INSERT INTO video_meetings VALUES(?,1,NULL,?,'room','notes',?,?,?,?,?)");
    $q->execute([1,$prior,null,'ended','Earlier partner meeting',$at('-1 day'),$at('-23 hours')]);
    $q->execute([2,$upcoming,55,'scheduled','Partner meeting',$start,$end]);
    $pdo->exec("INSERT INTO video_meeting_participants VALUES(1,1,1,'organizer','accepted','private'),
        (2,2,1,'organizer','accepted','private')");
    $q=$pdo->prepare("INSERT INTO video_meeting_agenda_items VALUES(?,?,1,?,'follow_up',?,?,?,'','open','high','','none',1,'',?)");
    $q->execute([11,1,'Earlier approved follow-up','manual',null,'',$updated]);
    $q->execute([12,2,'Confirm the delivery plan','continuity',1,$hash,$updated]);
    $pdo->prepare("INSERT INTO video_meeting_continuity_links VALUES(21,1,1,11,2,12,'carried_forward',?,?)")->execute([$lineage,$updated]);
    return [$prior,$upcoming,$lineage];
};
[$prior,$upcoming,$lineage]=$fixture();
$user=['id'=>1,'is_active'=>1];
$system=user_agent_principal_v236($user,null);
$read=static fn():array=>vp3_catch_up_meeting_prep_v1($pdo,$user,$system,$now);
$pdo->exec('PRAGMA query_only=ON');
[$coverage,$items]=$read();
check_meeting_prep($coverage==='ready'&&count($items)===1&&$items[0]['id']==='meeting-prep:'.$lineage
    &&$items[0]['starts_at']===$now->modify('+10 minutes')->format(DATE_ATOM)&&$items[0]['status']==='open'
    &&str_contains($items[0]['reason'],'Confirm the delivery plan')&&!str_contains($items[0]['reason'],'Earlier approved'),
    'exact upcoming calendar and organizer-carried agenda context form one read-only candidate');
check_meeting_prep($items[0]['url']==='/vp3/meeting.php?meeting='.$upcoming
    &&$items[0]['source_review_url']==='/vp3/meeting.php?meeting='.$prior
    &&!str_contains(json_encode($items,JSON_THROW_ON_ERROR),'private'),
    'review links identify exact current and source meetings without invitation secrets');
$brief=vp3_agent_catch_up_v1($pdo,$user,'office');
check_meeting_prep(array_column($brief['items'],'id')===['meeting-prep:'.$lineage]
    &&!array_key_exists('_calendar_event_id',$brief['items'][0]),
    'the specific prep item replaces the generic start for the same event occurrence');
$returnState=['last_meaningful_at'=>$at('-31 minutes')];
check_meeting_prep(vp3_cognitive_presentation_greeting_preview_v510($returnState,$brief,false,$now)['decision']==='would_mention',
    'the same top item supplies a quiet-session greeting preview');
$oldBrief=vp3_agent_catch_up_v1($pdo,$user,'office',0,false);
check_meeting_prep(array_column($oldBrief['items'],'id')===['calendar:event:55']&&!array_key_exists('meeting_prep',$oldBrief['coverage']),
    'old unbound reads retain their original calendar projection');
check_meeting_prep(vp3_catch_up_meeting_prep_v1($pdo,$user+['denied'=>['account.access']],$system,$now)===['forbidden',[]]
    &&vp3_catch_up_meeting_prep_v1($pdo,['id'=>2],$system,$now)===['forbidden',[]]
    &&vp3_catch_up_meeting_prep_v1($pdo,['id'=>2],user_agent_principal_v236(['id'=>2],null),$now)===['ready',[]],
    'owner permission and account switching stay isolated');
$GLOBALS['participant_scope']=['viewer_user_id'=>1];
check_meeting_prep($read()===['forbidden',[]],'participant Chat does not inherit organizer context');
unset($GLOBALS['participant_scope']);
$pdo->exec('PRAGMA query_only=OFF');

$omit=static function(string $change,string $name)use($pdo,$read):void{
    $pdo->beginTransaction();
    try{$pdo->exec($change);check_meeting_prep($read()===['ready',[]],$name);}
    finally{$pdo->rollBack();}
};
$omit("UPDATE user_calendar_events SET status='cancelled'",'cancelled calendar state hides prep');
$omit("UPDATE user_calendar_events SET end_at_utc='".$at('+45 minutes')."'",'calendar and video meeting must agree on exact occurrence');
$omit("UPDATE video_meetings SET status='ended' WHERE id=2",'started or finished meetings do not count as upcoming prep');
$omit("UPDATE video_meeting_participants SET invitation_status='revoked' WHERE meeting_id=1",'revoked source review access hides the evidence');
$omit("UPDATE video_meeting_continuity_links SET status='resolved_elsewhere'",'resolved or superseded links stop appearing');
$pdo->beginTransaction();
try{
    $pdo->exec("UPDATE video_meeting_continuity_links SET status='resolved_elsewhere'");
    $resolved=vp3_agent_catch_up_v1($pdo,$user,'office');
    check_meeting_prep(vp3_cognitive_presentation_greeting_preview_v510($returnState,$resolved,false,$now)['decision']==='quiet',
        'resolving the linked follow-up makes the next greeting preview quiet');
}finally{$pdo->rollBack();}
$omit("UPDATE video_meeting_agenda_items SET status='skipped' WHERE id=12",'skipped target agenda copies stop appearing');
$omit("UPDATE video_meeting_agenda_items SET source_hash='forged' WHERE id=12",'matching names do not repair broken continuity lineage');
$omit("INSERT INTO video_meeting_action_executions VALUES(31,1,12,2,'completed','".$at('-2 minutes')."');
    INSERT INTO video_meeting_followthrough_closures VALUES(32,1,31,'verified','".$at('-1 minute')."')",
    'verified closure anywhere in the exact thread hides prep');
$omit("INSERT INTO video_meeting_action_executions VALUES(31,1,12,2,'needs_review','".$at('-2 minutes')."')",
    'a continuity thread with its own canonical action is not repeated as prep');
$omit("UPDATE video_meetings SET organizer_agent_id=7 WHERE id=2",'system Chat cannot borrow a named Agent meeting');
$pdo->exec('UPDATE video_meetings SET organizer_agent_id=7');
$named=static fn(int $id):array=>vp3_catch_up_meeting_prep_v1($pdo,$user,user_agent_principal_v236($user,user_agent_get_v236($pdo,1,$id)),$now);
check_meeting_prep(count($named(7)[1])===1&&$named(8)===['ready',[]],'a named Agent must own both linked meetings');
$pdo->exec('UPDATE video_meetings SET organizer_agent_id=8 WHERE id=1');
check_meeting_prep($named(7)===['ready',[]],'an otherwise valid target cannot reveal another Agent’s source');
$pdo->exec('UPDATE video_meetings SET organizer_agent_id=NULL');
check_meeting_prep(vp3_catch_up_meeting_prep_v1($pdo,$user,$system,$now->modify('-21 minutes'))===['ready',[]]
    &&vp3_catch_up_meeting_prep_v1($pdo,$user,$system,$now->modify('+10 minutes'))===['ready',[]],
    'prep is eligible only during the 30 minutes before start');

$pdo->prepare("INSERT INTO user_calendar_events(id,owner_user_id,title,status,all_day,start_at_utc,end_at_utc) VALUES(56,1,'Another event','active',0,?,?)")
    ->execute([$at('+5 minutes'),$at('+25 minutes')]);
$brief=vp3_agent_catch_up_v1($pdo,$user,'office');
check_meeting_prep(str_starts_with($brief['items'][0]['id'],'calendar-conflict:')&&$brief['items'][1]['id']==='meeting-prep:'.$lineage,
    'exact prep does not suppress verified calendar conflicts');
