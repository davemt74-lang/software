<?php
declare(strict_types=1);

// Reuse the actual capture, Knowledge, transcription and SQLite fixture. The
// additional tables below exercise the production Chat boundary and publisher.
require __DIR__.'/agent-screen-sessions-v1.php';
require dirname(__DIR__).'/includes/agent-screen-chat-report-v1.php';
$baselineChecks=$checks;
$pdo->exec("CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER);
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT);
    INSERT INTO user_agents VALUES(4,1,1),(9,2,1);
    INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES(7,1,4),(8,1,4),(9,1,NULL),(17,2,9)");
function user_agent_get_v236(PDO $pdo,int $uid,int $aid): ?array
{
    $q=$pdo->prepare('SELECT * FROM user_agents WHERE id=? AND owner_user_id=?');$q->execute([$aid,$uid]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function vp3_participant_scope_v1(): ?array{return $GLOBALS['reportParticipant']??null;}
$uuid=static fn(int $n):string=>sprintf('00000000-0000-4000-8000-%012d',$n);
$newSession=static function(int $n,bool $report=true)use($pdo,$user,$uuid):array{
    return screen_session_start_v1($pdo,$user,['session_id'=>$uuid($n),'title'=>'Video research','conversation_id'=>7,'chat_report'=>$report]);
};
$audio=static function(string $sid,int $n,string $text,int $offset,array $events=[])use($pdo,$user,$uuid):array{
    return screen_capture_store_v1($pdo,$user,$sid,$uuid($n),[
        'captured_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+$offset),'surface'=>'browser','duration_seconds'=>15,
        'transcript'=>$text,'audio_events'=>$events,
    ],'tab_audio');
};
$count=static fn():int=>(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();

check(screen_chat_report_projection_v1($pdo,$user,4,7,1)['report']===null,'a missing report schema is a read-only unavailable projection');
$empty=$newSession(1001);
check($count()===0,'starting an opted-in share binds its destination without posting a Chat message');
check(screen_chat_report_binding_v1($pdo,$user,$empty['session_id'])['message_id']===null,'report binding has no message before capture and stop');
check(screen_session_end_v1($pdo,$user,$empty['session_id'])===['ended'=>true]&&$count()===0,'an empty session does not manufacture a report');

$s=$newSession(1002);
$a=[];
for($i=0;$i<4;$i++)$a[]=$audio($s['session_id'],1100+$i,'Spoken excerpt '.$i,-60+$i*10,
    $i===3?[['label'=>'music'],['label'=>'music'],['label'=>'<img src=x>']]:[]);
rejects(fn()=>screen_chat_report_share_v1($pdo,$user,$s['session_id'],7),'an active session cannot be manually published',409);
$ended=screen_session_end_v1($pdo,$user,$s['session_id']);$mid=$ended['report']['message_id'];
check($mid>0&&$ended['report']['conversation_id']===7&&$count()===1,'ending the share posts one canonical assistant Chat message');
$stored=$pdo->query('SELECT * FROM chat_messages WHERE id='.$mid)->fetch(PDO::FETCH_ASSOC);
$context=json_decode($stored['context_json'],true,16,JSON_THROW_ON_ERROR);
check($stored['role']==='assistant'&&$stored['user_id']===null&&$stored['message']===VP3_SCREEN_CHAT_REPORT_TEXT_V1,
    'the report uses the ordinary assistant message contract');
check($context===['source'=>'agent_screen_insights','skip_brain_archive'=>true]&&!str_contains($stored['context_json'],'Spoken excerpt'),
    'Chat stores only its report marker and does not make a stale copy of private transcript text');
check(screen_session_end_v1($pdo,$user,$s['session_id'])['report']['message_id']===$mid&&$count()===1,'repeated stop does not duplicate the report');
check(screen_chat_report_share_v1($pdo,$user,$s['session_id'],7)['report']['message_id']===$mid&&$count()===1,
    'manual publish after automatic publication reuses the same saved Chat message');
rejects(fn()=>screen_chat_report_share_v1($pdo,$user,$s['session_id'],8),'a published report cannot be moved to another Chat',409);

$before=(int)$pdo->query('SELECT COUNT(*) FROM knowledge_items')->fetchColumn();
$view=screen_chat_report_projection_v1($pdo,$user,4,7,$mid);
check($view['report']['session_id']===$s['session_id']&&$view['report']['message_id']===$mid,'projection preserves the exact saved session and Chat identity');
check(array_column($view['report']['audio']['segments'],'text')===['Spoken excerpt 1','Spoken excerpt 2','Spoken excerpt 3'],
    'only the latest three speech excerpts are shown in chronological order');
check(array_column($view['report']['audio']['segments'],'capture_id')===[$uuid(1101),$uuid(1102),$uuid(1103)],
    'audio excerpts retain their authoritative capture identities for later review invalidation');
check($view['report']['audio']['segments'][2]['sounds']===['music'],'sound labels are normalized and deduplicated without accepting markup');
check(str_contains($view['report']['audio']['knowledge_url'],'screen_session='.$s['session_id']),
    'audio excerpts link to their original Knowledge session');
check((int)$pdo->query('SELECT COUNT(*) FROM knowledge_items')->fetchColumn()===$before&&$count()===1,'report reads create no messages or Knowledge duplicates');

foreach([[$other,4,7],[$user,0,7],[$user,4,8],[$user,0,9],[['id'=>1,'denied'=>true],4,7]] as [$viewer,$aid,$conversation]){
    $missing=screen_chat_report_projection_v1($pdo,$viewer,$aid,$conversation,$mid);
    check($missing['report']===null&&!str_contains(json_encode($missing),'Spoken excerpt'),'foreign, wrong-Agent, wrong-Chat and revoked-access reads expose no transcript');
}
$GLOBALS['reportParticipant']=['participant'=>true];
check(screen_chat_report_projection_v1($pdo,$user,4,7,$mid)['report']===null,'participant context cannot project the owner private report');
unset($GLOBALS['reportParticipant']);
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=4');
check(screen_chat_report_projection_v1($pdo,$user,4,7,$mid)['report']===null,'inactive Agent access revokes the report projection');
$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=4');

$oldCard=$view['report']['cards'][0];
screen_insights_review_capture_v1($pdo,$user,['capture_id'=>$uuid(1103),'revision'=>1,'state'=>'outdated','note'=>'Source changed']);
$fresh=screen_chat_report_projection_v1($pdo,$user,4,7,$mid);
check(!str_contains(json_encode($fresh),'Spoken excerpt 3')&&array_column($fresh['report']['audio']['segments'],'text')===['Spoken excerpt 0','Spoken excerpt 1','Spoken excerpt 2'],
    'withdrawing a source removes its speech and sound labels from the same saved message');
rejects(fn()=>screen_insights_current_v1($pdo,$user,4,['conversation_id'=>7,'report_message_id'=>$mid,
    'key'=>$oldCard['key'],'review_hash'=>$oldCard['review_hash']]),'actions against the previous source revision are rejected',409);
foreach([1100,1101,1102] as $id)screen_insights_review_capture_v1($pdo,$user,['capture_id'=>$uuid($id),'revision'=>1,'state'=>'incorrect','note'=>'Withdrawn']);
$withdrawn=screen_chat_report_projection_v1($pdo,$user,4,7,$mid);
check($withdrawn['report']===null&&str_contains($withdrawn['answer'],'no current observations')&&$count()===1,
    'withdrawing every capture retains the ordinary Chat row with an unavailable-content projection');

$muted=$newSession(1003);
$audio($muted['session_id'],1200,'Explicitly published later',-20);
check(screen_session_end_v1($pdo,$user,$muted['session_id'],false)===['ended'=>true]&&$count()===1,
    'disabling publication at stop suppresses the automatic Chat post');
$manual=screen_chat_report_share_v1($pdo,$user,$muted['session_id'],7);
check($manual['report']['message_id']>0&&$count()===2,'an explicit later post publishes the opted-out session once');
screen_session_delete_v1($pdo,$user,$muted['session_id'],true);
$deleted=screen_chat_report_projection_v1($pdo,$user,4,7,$manual['report']['message_id']);
check($deleted['report']===null&&!str_contains(json_encode($deleted),'Explicitly published')&&$count()===2,
    'source deletion removes access without deleting or duplicating the canonical Chat row');

$sound=$newSession(1004);
$audio($sound['session_id'],1300,'',-10,[['label'=>'laughter']]);
$soundMessage=screen_session_end_v1($pdo,$user,$sound['session_id'])['report']['message_id'];
$soundView=screen_chat_report_projection_v1($pdo,$user,4,7,$soundMessage);
check($soundView['report']['audio']['segments'][0]['text']===''&&$soundView['report']['audio']['segments'][0]['sounds']===['laughter'],
    'a sound-only session provides labelled sounds without inventing a speech transcript');
$soundHistory=screen_session_timeline_v1($pdo,$user,$sound['session_id']);
check(count($soundHistory['captures'])===1&&$soundHistory['captures'][0]['body']['transcript']===''
    &&$soundHistory['captures'][0]['body']['audio_events'][0]['label']==='laughter',
    'reopening the saved session retains sound-only evidence in its timeline');
rejects(fn()=>screen_session_timeline_v1($pdo,$other,$sound['session_id']),'another owner cannot reopen the audio timeline',404);
screen_insights_review_capture_v1($pdo,$user,['capture_id'=>$uuid(1300),'revision'=>1,'state'=>'incorrect','note'=>'Withdraw sound']);
check(screen_session_timeline_v1($pdo,$user,$sound['session_id'])['captures']===[]
    &&screen_chat_report_projection_v1($pdo,$user,4,7,$soundMessage)['report']===null,
    'withdrawn sound-only captures disappear from both saved timeline and Chat projection');
screen_insights_review_capture_v1($pdo,$user,['capture_id'=>$uuid(1300),'revision'=>2,'state'=>'active','note'=>'Reviewed sound']);
check(count(screen_session_timeline_v1($pdo,$user,$sound['session_id'])['captures'])===1,
    'restoring a reviewed sound capture restores the same authoritative timeline row');

$system=screen_session_start_v1($pdo,$user,['session_id'=>$uuid(1005),'title'=>'Main Agent video']);
$audio($system['session_id'],1400,'Main Agent transcript',-5);
screen_session_end_v1($pdo,$user,$system['session_id']);
$systemMessage=screen_chat_report_share_v1($pdo,$user,$system['session_id'],9)['report']['message_id'];
$systemView=screen_chat_report_projection_v1($pdo,$user,0,9,$systemMessage);
check($systemView['report']['agent_id']===0&&$systemView['report']['audio']['segments'][0]['text']==='Main Agent transcript',
    'the main System Agent uses the NULL Agent Chat namespace and receives its own transcript');
check(screen_chat_report_projection_v1($pdo,$user,4,7,$systemMessage)['report']===null,
    'a named Agent cannot receive a System Agent report');

$row=['session_id'=>$sound['session_id'],'capture_id'=>$uuid(1300),'kind'=>'tab_audio','captured_at'=>gmdate('Y-m-d\TH:i:s\Z'),
    'review_state'=>'active','folder_id'=>$sound['folder_id'],'body'=>['transcript'=>str_repeat('é',1200),'audio_events'=>[null,'bad',['label'=>'music']]]];
set_error_handler(static function(int $severity,string $message):never{throw new ErrorException($message,0,$severity);});
try{
    $bounded=screen_chat_report_audio_v1($sound['session_id'],[null,'bad',['kind'=>'tab_audio'],
        [...$row,'session_id'=>$uuid(1999)],[...$row,'review_state'=>'incorrect'],[...$row,'body'=>'bad'],
        [...$row,'captured_at'=>'bad'],[...$row,'capture_id'=>'bad'],$row]);
    check(count($bounded['segments'])===1&&mb_strlen($bounded['segments'][0]['text'])===1000&&$bounded['segments'][0]['sounds']===['music'],
        'malformed, foreign-session, withdrawn and invalid-time rows are ignored while transcript text stays bounded');
}finally{restore_error_handler();}
echo 'AGENT_SCREEN_CHAT_REPORT_V1=PASS ('.($checks-$baselineChecks).' additional cases)' . "\n";
