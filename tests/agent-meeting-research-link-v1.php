<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/participant-updates-v1.php';
require_once __DIR__.'/../includes/user-agent-system-v236.php';
require_once __DIR__.'/../includes/agent-chat-boundary-v380.php';
require_once __DIR__.'/../includes/agent-meeting-research-link-v1.php';
require_once __DIR__.'/fixtures/meeting-prep-calendar-fixture.php';

function db(): PDO { return $GLOBALS['pdo']; }
function url(string $path=''): string { return '/vp3'.$path; }
function setting(string $key,mixed $fallback=null): mixed { return $fallback; }
function has_permission(string $permission,array $user): bool { return true; }
function table_exists(string $table): bool
{
    $query=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $query->execute([$table]);
    return $query->fetchColumn()!==false;
}
function column_exists(string $table,string $column): bool
{
    return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
}
function meeting_research_check_v1(bool $condition,string $name): void
{
    if (!$condition) throw new RuntimeException($name);
    echo 'PASS '.$name.PHP_EOL;
}

$pdo=$GLOBALS['pdo']=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER); INSERT INTO users VALUES(1,1),(2,1);
    CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name TEXT,is_active INTEGER);
    INSERT INTO user_agents VALUES(7,1,'Work Agent',1),(8,1,'Other Agent',1);
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);
    INSERT INTO chat_conversations VALUES(11,1,7),(12,1,8),(13,1,7),(20,2,NULL);");
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$ids=vp3_test_meeting_prep_seed_v1($pdo,$now,7);
$user=['id'=>1,'is_active'=>1];
$scope=['user'=>$user,'principal'=>vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380($pdo,$user,7)),
    'agent_id'=>7,'conversation_id'=>11];

$pdo->exec('PRAGMA query_only=ON');
meeting_research_check_v1(vp3_meeting_research_session_v1($pdo,$scope)===7922
    &&vp3_meeting_research_session_v1($pdo,$scope,$ids['prior_meeting_id'])===$ids['transcript_id']
    &&vp3_meeting_research_session_v1($pdo,$scope,$ids['meeting_id'])===null,
    'the last completed recording and explicit native meeting selectors resolve exactly');
$target=vp3_meeting_research_target_v1($pdo,$scope,$ids['transcript_id']);
$view=vp3_meeting_research_target_current_v1($pdo,$scope,$target);
meeting_research_check_v1($target['meeting_id']===$ids['prior_meeting_id']&&$target['agenda_item_id']===null
    &&$view===['title'=>'Delivery review','url'=>'/vp3/meeting.php?meeting='.$ids['prior_public_id'].'&review=1'],
    'the default stays on the recording’s meeting and invents no agenda item');
$direct=vp3_meeting_research_target_v1($pdo,$scope,$ids['transcript_id'],7941);
$carried=vp3_meeting_research_target_v1($pdo,$scope,$ids['transcript_id'],$ids['target_agenda_id']);
$carriedView=vp3_meeting_research_target_current_v1($pdo,$scope,$carried);
meeting_research_check_v1($direct['meeting_id']===$ids['prior_meeting_id']&&$direct['agenda_item_id']===7941
    &&$carried['meeting_id']===$ids['meeting_id']&&$carried['source_meeting_id']===$ids['prior_meeting_id']
    &&$carried['calendar_event_id']===$ids['calendar_id']
    &&$carriedView['url']==='/vp3/meeting.php?meeting='.$ids['meeting_public_id'].'&review=1&view=agenda#agenda-item-'.$ids['target_agenda_id']
    &&$carriedView['commitment']==='Assign an owner for the revised delivery plan',
    'explicit direct and carried-forward agenda references resolve without changing the commitment');
meeting_research_check_v1(vp3_meeting_research_target_v1($pdo,$scope,7922,$ids['target_agenda_id'])===null
    &&vp3_meeting_research_target_v1($pdo,$scope,$ids['transcript_id'],7943)===null,
    'an unrelated recording or nearby agenda row cannot substitute for exact continuity');
meeting_research_check_v1(vp3_meeting_research_target_current_v1($pdo,array_replace($scope,['conversation_id'=>13]),$carried)===null
    &&vp3_meeting_research_target_current_v1($pdo,$scope,array_replace($carried,['meeting_id'=>7913]))===null,
    'the saved association cannot cross Chat or replace its meeting identity');
$agent8=array_replace($scope,['agent_id'=>8,'conversation_id'=>12,
    'principal'=>vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380($pdo,$user,8))]);
$user2=['id'=>2,'is_active'=>1];
$other=['user'=>$user2,'principal'=>vp3_agent_chat_principal_v380($user2,null),'agent_id'=>0,'conversation_id'=>20];
meeting_research_check_v1(vp3_meeting_research_target_v1($pdo,$agent8,$ids['transcript_id'])===null
    &&vp3_meeting_research_target_v1($pdo,$other,$ids['transcript_id'])===null,
    'another Agent or owner cannot access this meeting research link');

$pdo->exec('PRAGMA query_only=OFF');
$pdo->exec("INSERT INTO video_meetings(id,owner_user_id,organizer_agent_id,public_id,room_name,agent_mode,status,title,start_at_utc,end_at_utc)
    SELECT 7914,owner_user_id,organizer_agent_id,'44444444444444444444444444444444','fixture-unrecorded','notes','ended','Unrecorded meeting',
        datetime(end_at_utc,'+15 minutes'),datetime(end_at_utc,'+45 minutes') FROM video_meetings WHERE id=7913");
meeting_research_check_v1(vp3_meeting_research_session_v1($pdo,$scope)===null
    &&vp3_meeting_research_session_v1($pdo,$scope,7913)===7922,
    'an unrecorded most recent meeting does not select an older recording');
$pdo->prepare("INSERT INTO user_agent_data_rules VALUES(7,'recording',?,'deny')")->execute([(string)$ids['transcript_id']]);
meeting_research_check_v1(vp3_meeting_research_target_current_v1($pdo,$scope,$target)===null,
    'revoked recording access withdraws the link');
$pdo->exec("DELETE FROM user_agent_data_rules; UPDATE video_meeting_agenda_items SET status='decision' WHERE id=7942;");
meeting_research_check_v1(vp3_meeting_research_target_current_v1($pdo,$scope,$carried)===null
    &&vp3_meeting_research_target_current_v1($pdo,$scope,$target)!==null,
    'closing the specific agenda item withdraws its association only');
$pdo->exec("UPDATE video_meeting_agenda_items SET status='open',item_text='Another commitment' WHERE id=7942;");
meeting_research_check_v1(vp3_meeting_research_target_current_v1($pdo,$scope,$carried)===null,
    'an edited agenda commitment requires a fresh association');
$pdo->exec("UPDATE video_meeting_participants SET invitation_status='revoked' WHERE meeting_id=7911;");
meeting_research_check_v1(vp3_meeting_research_target_current_v1($pdo,$scope,$target)===null,
    'lost organizer authority withdraws the previous association');
$pdo->exec("UPDATE video_meeting_participants SET invitation_status='accepted' WHERE meeting_id=7911;
    DELETE FROM video_meeting_transcription_links WHERE meeting_id=7911;");
meeting_research_check_v1(vp3_meeting_research_target_current_v1($pdo,$scope,$target)===null,
    'recording metadata alone cannot prove a canonical meeting link');
