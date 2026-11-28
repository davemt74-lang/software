<?php
declare(strict_types=1);
require __DIR__.'/fixtures/music-history-v1.php';
require dirname(__DIR__).'/includes/music-history-chat-v1.php';
$checks=0;
function history_intent_equal(mixed $actual,mixed $expected,string $message): void{
    ++$GLOBALS['checks'];if($actual!==$expected)throw new RuntimeException($message.' expected '.json_encode($expected).', got '.json_encode($actual));
}
function history_intent_ok(bool $value,string $message): void{history_intent_equal($value,true,$message);}
function history_intent_reject(callable $call,int $code,string $message): void{
    try{$call();}catch(RuntimeException $error){history_intent_equal((int)$error->getCode(),$code,$message);return;}throw new RuntimeException($message.' did not reject');
}
$now=strtotime('2026-11-27T12:00:00Z');
foreach([
    ['Show my listening history','both',''],
    ['What did I listen to yesterday?','both',''],
    ['What did I watch yesterday?','youtube',''],
    ['Show my VP3 history','vp3',''],
    ['Show my listening history on YouTube','youtube',''],
    ['Show my music history without YouTube','vp3',''],
    ['Find songs by Nina in my listening history','both','Nina'],
    ['Show my listening history for "History"','both','History'],
    ['Show my listening history for "YouTube Yesterday" from VP3','vp3','YouTube Yesterday'],
    ['Show my listening history for "Do not play"','both','Do not play'],
] as [$query,$source,$search]){
    $plan=vp3_music_history_chat_plan_v1($query,'UTC',$now);
    history_intent_equal($plan['intent']??null,'query',$query);
    history_intent_equal($plan['filters']['source'],$source,$query.' source');
    history_intent_equal($plan['filters']['query'],$search,$query.' literal text');
}
foreach(['Play "History"','Show "Listening History" by Artist','Do not show my listening history',"Don't play my history",'Explain listening history',
    'How does listening history work?','What is music history?','Search a music history dataset','Show browser history','Show my purchase history',
    'Show the history of jazz','Find current weather','Can you play music on my home server?','Clear my listening history'] as $query){
    history_intent_equal(vp3_music_history_chat_plan_v1($query,'UTC',$now),null,'Unrelated/negated/literal request: '.$query);
}
history_intent_equal(vp3_music_chat_plan_v1('Play "History"')['query'],'History','MC1 literal title remains a title');
$day=vp3_music_history_chat_plan_v1('What did I listen to yesterday?','Pacific/Auckland',strtotime('2026-11-26T12:00:00Z'));
history_intent_equal([$day['filters']['from'],$day['filters']['to']],['2026-11-26','2026-11-26'],'Yesterday follows the user local date');
$week=vp3_music_history_chat_plan_v1('Show my listening history last week','UTC',$now);
history_intent_equal([$week['filters']['from'],$week['filters']['to']],['2026-11-16','2026-11-22'],'Last week is the previous Monday–Sunday');
$range=vp3_music_history_chat_plan_v1('Show VP3 history from 2026-01-02 to 2026-01-05','UTC',$now);
history_intent_equal([$range['filters']['from'],$range['filters']['to']],['2026-01-02','2026-01-05'],'Explicit inclusive dates survive');
foreach([
    ['2026-03-08T16:00:00Z',11,'2026-03-07T18:00:00-05:00','2026-03-08T06:00:00-04:00'],
    ['2026-11-01T17:00:00Z',13,'2026-10-31T18:00:00-04:00','2026-11-01T06:00:00-05:00'],
] as [$clock,$hours,$from,$until]){
    $night=vp3_music_history_chat_plan_v1('What did I listen to last night?','America/New_York',strtotime($clock));
    history_intent_equal($night['filters']['from_at'],$from,'Night starts at previous local18:00');
    history_intent_equal($night['filters']['until_at'],$until,'Night ends at local06:00');
    history_intent_equal(strtotime($until)-strtotime($from),$hours*3600,'DST elapsed duration is explicit');
    history_intent_ok(!isset($night['filters']['from'],$night['filters']['to']),'Night is an exact exclusive range, not a whole date');
}
$early=vp3_music_history_chat_plan_v1('Show music history last night','America/New_York',strtotime('2026-03-08T07:00:00Z'));
history_intent_equal(strtotime($early['filters']['until_at']),strtotime('2026-03-08T07:00:00Z'),'Future night end clamps to current time');
history_intent_reject(static fn()=>vp3_music_history_chat_plan_v1('Show music history','Not/AZone',$now),422,'Invalid timezone rejects');
foreach([['Play the second one','play',2],['Resume the third entry','resume',3],['Replay that one','play',1]] as [$query,$operation,$index]){
    $plan=vp3_music_history_chat_plan_v1($query);history_intent_equal($plan['intent'],'selection',$query);
    history_intent_equal($plan['operation'],$operation,$query.' action');history_intent_equal($plan['selection']['index'],$index,$query.' ordinal');
}

class HistoryIntentHookPDO extends PDO{
    public ?Closure $historyHook=null;
    public function prepare(string $query,array $options=[]): PDOStatement|false{
        if($this->historyHook!==null)($this->historyHook)($this,$query);return parent::prepare($query,$options);
    }
}
$pdo=new HistoryIntentHookPDO('sqlite::memory:');music_chat_fixture_bootstrap_v1($pdo);music_chat_fixture_schema_v1($pdo);music_chat_fixture_seed_v1($pdo);
music_history_fixture_schema_v1($pdo);$seed=music_history_fixture_seed_v1($pdo);$user=current_user();$principal=['agent_id'=>7];
$before=(int)$pdo->query('SELECT COUNT(*) FROM track_play_sessions')->fetchColumn();
$tool=vp3_music_history_chat_execute_v1($pdo,$user,$principal,42,102,'Show my listening history');
history_intent_equal(count($tool['music_history_v1']['items']),6,'Shared retained history reader supplies all six distinct receipts');
$message=music_chat_fixture_message_v1($pdo);$tool=vp3_music_history_chat_bind_reply_v1($pdo,$user,$principal,42,$message,$tool);
$page=$tool['music_history_v1'];history_intent_equal($page['source_message_id'],$message,'Query binds actual assistant message');
history_intent_equal($page['items'][0]['source_id'],$page['items'][3]['source_id'],'Repeated same-song receipts retain separate positions');
history_intent_ok($page['items'][0]['entry_ref']!==$page['items'][3]['entry_ref'],'Repeated plays retain different opaque refs');
$raw=(string)$pdo->query('SELECT context_json FROM chat_messages WHERE id='.$message)->fetchColumn();
foreach(['History Moonlight','YouTube Song','resume_seconds','youtube_video','thumbnail_url'] as $forbidden)history_intent_ok(!str_contains($raw,$forbidden),'Context does not retain '.$forbidden);
$count=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
$restored=vp3_music_history_chat_read_v1($pdo,$user,7,42,$message,$page['revision']);
history_intent_equal(array_column($restored['items'],'entry_ref'),array_column($page['items'],'entry_ref'),'Quiet restore preserves exact displayed order');
history_intent_equal((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn(),$count,'Restore never inserts a Chat turn');
history_intent_equal((int)$pdo->query('SELECT COUNT(*) FROM track_play_sessions')->fetchColumn(),$before,'Query/restore never manufacture listening');
$pdo->historyHook=static function(PDO $db,string $sql):void{
    if(str_starts_with($sql,'SELECT p.*,r.payload_json')){$db->historyHook=null;$db->exec("UPDATE agent_connection_youtube_consent_v1 SET accepted_at=accepted_at+1 WHERE owner_user_id=11");}
};
history_intent_reject(static fn()=>vp3_music_history_chat_read_v1($pdo,$user,7,42,$message,$page['revision']),409,'Consent epoch change during exact restore rejects stale metadata');
$pdo->exec('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=accepted_at-1 WHERE owner_user_id=11');
$pdo->historyHook=static function(PDO $db,string $sql):void{
    if(str_starts_with($sql,'SELECT p.*,r.payload_json')){$db->historyHook=null;$db->exec("UPDATE tracks SET visibility='private',owner_user_id=22,title='MIDRESTORE SECRET' WHERE id=71");}
};
$fresh=vp3_music_history_chat_read_v1($pdo,$user,7,42,$message,$page['revision']);
history_intent_equal($fresh['items'][0]['title'],'Unavailable VP3 song','Late native revocation replaces an earlier projected title');
history_intent_ok(!str_contains(json_encode($fresh),'History Moonlight')&&!str_contains(json_encode($fresh),'MIDRESTORE SECRET'),'Restore exposes neither old nor revoked native metadata');
$pdo->exec("UPDATE tracks SET visibility='public',owner_user_id=11,title='History Moonlight' WHERE id=71");
$clarify=vp3_music_history_chat_execute_v1($pdo,$user,$principal,42,102,'Play that one');
history_intent_equal($clarify['tool_receipt']['status'],'clarification_required','Ambiguous deictic retains exact history context');
history_intent_equal($clarify['music_history_followup_v1']['source_message_id'],$message,'Clarification keeps original binding');
$selection=vp3_music_history_chat_execute_v1($pdo,$user,$principal,42,102,'Play the second one',['music_request_id'=>'history-intent-second']);
history_intent_equal($selection['music_chat_v1']['items'][0]['source'],'youtube','Ordinal uses actual mixed-source second receipt');
$stored=$pdo->prepare('SELECT meta_json FROM music_chat_results_v1 WHERE result_id=?');$stored->execute([$selection['music_chat_v1']['result_id']]);$meta=json_decode($stored->fetchColumn(),true);
history_intent_equal($meta['history_ref'],$page['items'][1]['entry_ref'],'MC1 stores exact selected receipt reference');
history_intent_ok(!isset($meta['resume_seconds'],$meta['position_seconds']),'No copied resume position is trusted or persisted');
$updated=vp3_music_history_chat_query_v1($pdo,$user,7,42,['source'=>'vp3','limit'=>2],$message,$page['revision']);
history_intent_equal($updated['assistant_message_id'],$message,'Current explicit filter action updates the same bound card');
history_intent_reject(static fn()=>vp3_music_history_chat_read_v1($pdo,$user,7,42,$message,$page['revision']),409,'Stale card revision cannot select a new ordinal');
history_intent_reject(static fn()=>vp3_music_history_chat_query_v1($pdo,$user,7,42,['source'=>'youtube'],$message,$page['revision']),409,'Stale filter CAS is rejected');
history_intent_equal(array_column(vp3_music_history_chat_read_v1($pdo,$user,7,42,$message,$updated['music_history_v1']['revision'])['items'],'entry_ref'),
    array_column($updated['music_history_v1']['items'],'entry_ref'),'Rejected CAS preserves visible ordering');
history_intent_reject(static fn()=>vp3_music_history_chat_read_v1($pdo,$user,7,43,$message,$updated['music_history_v1']['revision']),404,'History snapshot cannot cross conversations');
echo 'MUSIC_HISTORY_INTENT_V1=PASS checks='.$checks.PHP_EOL;
