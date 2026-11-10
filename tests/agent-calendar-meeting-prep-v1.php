<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/participant-updates-v1.php';
require_once __DIR__.'/../includes/user-agent-system-v236.php';
require_once __DIR__.'/../includes/agent-chat-boundary-v380.php';
require_once __DIR__.'/../includes/agent-scheduling-v430.php';
require_once __DIR__.'/../includes/user-calendar-v1300.php';
require_once __DIR__.'/../includes/agent-capability-chat-v1.php';
require_once __DIR__.'/fixtures/meeting-prep-calendar-fixture.php';

function db(): PDO { return $GLOBALS['pdo']; }
function url(string $path=''): string { return '/vp3'.$path; }
function setting(string $key,mixed $fallback=null): mixed { return $fallback; }
function has_permission(string $permission,array $user): bool { return !in_array($permission,$GLOBALS['denied']??[],true); }
function table_exists(string $table): bool
{
    $stmt=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);return $stmt->fetchColumn()!==false;
}
function column_exists(string $table,string $column): bool
{
    return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
}
function meeting_prep_check_v1(bool $condition,string $name): void
{
    if (!$condition) throw new RuntimeException($name);
    echo 'PASS '.$name.PHP_EOL;
}

$pdo=$GLOBALS['pdo']=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER); INSERT INTO users VALUES(1,1),(2,1);
    CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name TEXT,is_active INTEGER);
    INSERT INTO user_agents VALUES(7,1,'Work Agent',1),(8,1,'Other Agent',1);
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);
    INSERT INTO chat_conversations VALUES(11,1,7),(12,1,8),(20,2,NULL);");
$now=new DateTimeImmutable('2030-11-06T23:55:00Z');
$ids=vp3_test_meeting_prep_seed_v1($pdo,$now,7);
$user=['id'=>1,'is_active'=>1];
$read=static fn():?array=>vp3_calendar_prep_next_v1($pdo,$user,7,11,$now);
$pdo->exec('PRAGMA query_only=ON');
$event=$read();$quote='The revised delivery plan needs an owner by Friday.';
meeting_prep_check_v1($event!==null&&$event['id']===$ids['calendar_id']&&$event['meeting_id']===$ids['meeting_id']
    &&$event['starts_at']===$now->modify('+20 minutes')->format(DATE_ATOM),'next prep crosses local-day boundaries and identifies the exact Calendar and meeting');
meeting_prep_check_v1($event['actionable_items']===['Assign an owner for the revised delivery plan']
    &&in_array('Agenda · Review the revised dates',$event['open_items'],true),'a linked open commitment is distinct from ordinary agenda discussion');
$prior=$event['prior_transcript'];
meeting_prep_check_v1($prior['session_id']===$ids['transcript_id']&&$prior['meeting_id']===$ids['prior_meeting_id']
    &&$prior['passages'][0]===['segment_id'=>$ids['segment_id'],'started_ms'=>12000,'at'=>'00:12','speaker'=>'Alex','text'=>$quote,'excerpted'=>false]
    &&!str_contains(json_encode($prior,JSON_THROW_ON_ERROR),'Unlinked same-title')
    &&!str_contains(json_encode($prior,JSON_THROW_ON_ERROR),'Removed private'),'prior recorded speech follows exact lineage and excludes unlinked or removed segments');
$answer=vp3_calendar_prep_next_result_v1($event);
meeting_prep_check_v1(str_contains($answer['answer'],$quote)&&str_contains($answer['answer'],'segment #7931')
    &&$answer['sources'][0]['url']==='/vp3/meeting.php?meeting='.$ids['meeting_public_id']
    &&$answer['sources'][1]['url']==='/vp3/artist-listening.php?session=7921'
    &&array_keys($answer['meeting_prep'])===['id','kind','occurrence_id','revision']
    &&!str_contains(json_encode($answer['meeting_prep'],JSON_THROW_ON_ERROR),$quote),'prep response supplies exact source links and a content-free replay pointer');
meeting_prep_check_v1($answer['transcript_context']===vp3_transcript_attachment_v1($ids['transcript_id'])
    &&!str_contains(json_encode($answer['transcript_context'],JSON_THROW_ON_ERROR),$quote),
    'ordinary transcript follow-up uses an exact server attachment without retaining speech');
$replay=static fn():array=>vp3_calendar_prep_replay_v1($pdo,$user,7,11,$answer['meeting_prep'],null,$now);
meeting_prep_check_v1($replay()===$answer,'unchanged exact replay returns the same current authorized result');
$pdo->exec('PRAGMA query_only=OFF');

$stamp=static fn(string $offset):string=>$now->modify($offset)->format('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO user_calendar_events(id,owner_user_id,title,description,location,start_at_utc,end_at_utc,timezone,all_day,recurrence,source,source_reference,status)
    VALUES(7902,1,'Earlier meeting','','',?,?,'UTC',0,'none','automation','earlier','active')")
    ->execute([$stamp('+5 minutes'),$stamp('+15 minutes')]);
$pdo->prepare("INSERT INTO video_meetings(id,owner_user_id,organizer_agent_id,public_id,room_name,agent_mode,calendar_event_id,status,title,start_at_utc,end_at_utc)
    VALUES(7914,1,7,'44444444444444444444444444444444','room','notes',7902,'ready','Earlier meeting',?,?)")
    ->execute([$stamp('+5 minutes'),$stamp('+15 minutes')]);
$pdo->exec("INSERT INTO video_meeting_participants(meeting_id,user_id,role,invitation_status) VALUES(7914,1,'organizer','accepted')");
meeting_prep_check_v1($read()['meeting_id']===7914&&$replay()===$answer,'a newly earlier meeting changes new selection but never redirects an existing replay');
$pdo->exec("UPDATE video_meetings SET status='cancelled' WHERE id=7914");
$authorityChecks=0;
$rechecked=vp3_calendar_prep_replay_v1($pdo,$user,7,11,$answer['meeting_prep'],static function()use($pdo,&$authorityChecks):void{
    if (++$authorityChecks===2) $pdo->exec("UPDATE video_meeting_participants SET invitation_status='revoked' WHERE meeting_id=7911");
},$now);
meeting_prep_check_v1($authorityChecks===2&&$rechecked['sources']===[],'source access revoked during current-session revalidation prevents saved replay');
$pdo->exec("UPDATE video_meeting_participants SET invitation_status='accepted' WHERE meeting_id=7911");
$pdo->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='A corrected recording line.' WHERE id=7931");
meeting_prep_check_v1($replay()['sources']===[]&&str_contains($read()['prior_transcript']['passages'][0]['text'],'corrected'),
    'editing the exact recording invalidates old replay and a new read uses the correction');
$pdo->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='The revised delivery plan needs an owner by Friday.' WHERE id=7931");
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'recording','7921','deny')");
meeting_prep_check_v1($replay()['sources']===[]&&$read()['prior_transcript']===['status'=>'restricted'],
    'revoked recording access redacts excerpts and invalidates old replay');
meeting_prep_check_v1(!isset(vp3_calendar_prep_next_result_v1($read())['transcript_context'])
    &&!isset($replay()['transcript_context']),
    'revoked recording access cannot publish or restore a transcript attachment');
$pdo->exec('DELETE FROM user_agent_data_rules');
$pdo->exec("UPDATE video_meeting_participants SET invitation_status='revoked' WHERE meeting_id=7911");
meeting_prep_check_v1($read()['prior_transcript']===['status'=>'not_linked']&&$read()['actionable_items']===[]
    &&$replay()['sources']===[],'revoked source meeting access removes its quote and carried-forward item');
$pdo->exec("UPDATE video_meeting_participants SET invitation_status='accepted' WHERE meeting_id=7911");
$pdo->exec("UPDATE video_meeting_continuity_links SET status='resolved_elsewhere'");
meeting_prep_check_v1($read()['actionable_items']===[]&&$read()['prior_transcript']===['status'=>'not_linked'],
    'a resolved follow-up no longer becomes proactive evidence');
$pdo->exec("UPDATE video_meeting_continuity_links SET status='carried_forward'");
$pdo->exec("UPDATE video_meetings SET organizer_agent_id=8 WHERE id=7911");
meeting_prep_check_v1($read()['prior_transcript']===['status'=>'not_linked']
    &&vp3_calendar_prep_next_v1($pdo,$user,8,12,$now)===null,
    'target and prior meetings remain in their exact Agent namespace');
$pdo->exec("UPDATE video_meetings SET organizer_agent_id=7 WHERE id=7911");
$pdo->exec("UPDATE user_calendar_events SET recurrence='weekly' WHERE id=7901");
meeting_prep_check_v1($read()===null&&$replay()['sources']===[],'a weekly Calendar series cannot impersonate a video-meeting occurrence');
$pdo->exec("UPDATE user_calendar_events SET recurrence='none' WHERE id=7901");
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['viewer_user_id'=>1];
meeting_prep_check_v1($replay()['sources']===[],'participant scope cannot restore private meeting prep');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
meeting_prep_check_v1(vp3_calendar_prep_next_intent_v1('Help me prep for my next meeting')
    &&vp3_calendar_prep_next_intent_v1('What commitments are open for my next meeting?')
    &&vp3_calendar_prep_next_intent_v1('Could you prepare me for my next video meeting?')
    &&vp3_calendar_prep_next_intent_v1("What's on the agenda for my next meeting?")
    &&!vp3_calendar_prep_next_intent_v1('What needs my attention before my next meeting?')
    &&!vp3_calendar_prep_next_intent_v1('Reschedule my next meeting agenda')
    &&!vp3_calendar_prep_chat_intent_v1('Make me a task list for the prep for my next meeting')
    &&!vp3_calendar_prep_chat_intent_v1('Add a reminder to review the agenda for my next meeting')
    &&!vp3_calendar_prep_chat_intent_v1('What did the transcript say about prep for the next meeting?'),
    'explicit next-meeting reads route to prep while brief requests and writes keep their existing routes');
