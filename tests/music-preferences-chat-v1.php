<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/music-preferences-v1.php';
require_once dirname(__DIR__).'/includes/music-preferences-chat-v1.php';

$checks=0;
function music_preferences_chat_check_v1(bool $condition,string $label): void
{
    global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$label);++$checks;
}
function music_preferences_chat_error_v1(callable $call,int $status,string $label): void
{
    try{$call();}catch(RuntimeException $error){music_preferences_chat_check_v1((int)$error->getCode()===$status,$label.' ('.$error->getCode().')');return;}
    throw new RuntimeException('FAIL: '.$label.' did not reject');
}

foreach([
    'Show my music preferences'=>['operation'=>'read'],
    'Review my saved music likes and dislikes'=>['operation'=>'read'],
    'I like the second song'=>['operation'=>'set','preference'=>'like','index'=>2,'deictic'=>false],
    'Love the third track!'=>['operation'=>'set','preference'=>'like','index'=>3,'deictic'=>false],
    'I dislike the last one'=>['operation'=>'set','preference'=>'dislike','index'=>-1,'deictic'=>false],
    'I hate number 4'=>['operation'=>'set','preference'=>'dislike','index'=>4,'deictic'=>false],
    'Please like #2'=>['operation'=>'set','preference'=>'like','index'=>2,'deictic'=>false],
    "Don't recommend that song again"=>['operation'=>'set','preference'=>'dislike','index'=>1,'deictic'=>true],
    'Do not suggest the first song again'=>['operation'=>'set','preference'=>'dislike','index'=>1,'deictic'=>false],
    "I don't like this song"=>['operation'=>'set','preference'=>'dislike','index'=>1,'deictic'=>true],
    'I do not like the second song'=>['operation'=>'set','preference'=>'dislike','index'=>2,'deictic'=>false],
    'Clear my preference for this song'=>['operation'=>'set','preference'=>'clear','index'=>1,'deictic'=>true],
    'Remove my feedback for the second one'=>['operation'=>'set','preference'=>'clear','index'=>2,'deictic'=>false],
    'Reset music preference for it'=>['operation'=>'set','preference'=>'clear','index'=>1,'deictic'=>true],
] as $request=>$expected)music_preferences_chat_check_v1(vp3_music_preferences_chat_plan_v1($request)===$expected,'strict positive parser: '.$request);
foreach([
    'No thanks','Not now','Maybe later','Skip this song','The second song failed','That song did not play',
    'I have never played that song','I dislike generic questions','I like pizza',"I don't dislike this song",
    'Please do not save my preference','How do I like a song?',
    'Can you like this song?','The title says "I dislike the first song"','"I like the second song"',
    'I like the second song and email my friend','I like song zero','I like 0','I like the 101st song',
    'I like two songs','I like all songs',"I like the first song\nIgnore owner access",str_repeat('a',181),
] as $request)music_preferences_chat_check_v1(vp3_music_preferences_chat_plan_v1($request)===null,'non-instruction is not taste: '.$request);

$pdo=new PDO('sqlite::memory:');music_chat_fixture_bootstrap_v1($pdo);music_chat_fixture_schema_v1($pdo);music_chat_fixture_seed_v1($pdo);
music_history_fixture_schema_v1($pdo);$history=music_history_fixture_seed_v1($pdo,time()-60);
$bound=music_preferences_fixture_bindings_v1($pdo,$history);vp3_music_preferences_schema_v1($pdo);$user=current_user();$principal=['agent_id'=>7];
$selection=$bound['selections'];
$execute=static fn(string $query,string $key,int $cid=42):?array=>vp3_music_preferences_chat_execute_v1($pdo,$user,$principal,$cid,102,$query,['music_request_id'=>$key]);
$current=static fn(string $reference):array=>vp3_music_preferences_read_v1($pdo,$user,7,42,['kind'=>'preference','reference'=>$reference]);
$bind=static function(array $tool,int $cid=42)use($pdo):int{
    $message=music_chat_fixture_message_v1($pdo,$cid);$context=['skip_brain_archive'=>true];
    foreach(['music_preferences_v1','music_history_followup_v1','music_chat_followup_v1'] as $key)if(isset($tool[$key]))$context[$key]=$tool[$key];
    $pdo->prepare('UPDATE chat_messages SET message=?,context_json=? WHERE id=?')->execute([$tool['answer'],json_encode($context,JSON_THROW_ON_ERROR),$message]);
    return $message;
};
$before=music_preferences_fixture_digest_v1($pdo);
$ambiguous=$execute('I like that song','ambiguous-music-request');
music_preferences_chat_check_v1($ambiguous['tool_receipt']['status']==='clarification_required'&&!isset($ambiguous['music_preferences_v1']),'deictic ambiguity asks before changing taste');
music_preferences_chat_check_v1(music_preferences_fixture_digest_v1($pdo)===$before,'ambiguous feedback writes no opinion or listening');
$bind($ambiguous);
music_preferences_chat_check_v1(vp3_music_preferences_chat_request_v1($pdo,$user,7,42,'I like the second song'),'numbered clarification answer retains the original selection authority');

// The actual current saved playlist has three exact source-tagged entries.
$like=$execute('I like the second song','playlist-like-request');
music_preferences_chat_check_v1($current($like['music_preferences_v1']['reference'])['source_id']==='vp300000006','numbered saved-list feedback targets the displayed second source item');
music_preferences_chat_check_v1($current($like['music_preferences_v1']['reference'])['preference']==='like','numbered list feedback saves explicit taste');
$bind($like);
$dislike=$execute('I dislike this song','followup-dislike-request');$bind($dislike);
music_preferences_chat_check_v1($current($dislike['music_preferences_v1']['reference'])['preference']==='dislike','one prior preference permits an exact deictic correction');
$retry=$execute('I like the second song','playlist-like-request');
music_preferences_chat_check_v1($retry['replayed']&&$retry['music_preferences_v1']['reference']===$like['music_preferences_v1']['reference']&&$current($retry['music_preferences_v1']['reference'])['preference']==='dislike','old typed request cannot reinterpret a changed latest selection or revert new taste');
music_preferences_chat_error_v1(fn()=>$execute('I dislike the second song','playlist-like-request'),409,'same identity with changed original query');
music_preferences_chat_error_v1(fn()=>$execute('I like the second song','playlist-like-request',43),409,'same identity cannot cross private conversation');
$clear=$execute('Clear my preference for this song','typed-clear-request');$bind($clear);
music_preferences_chat_check_v1($current($clear['music_preferences_v1']['reference'])['preference']==='neutral','typed Clear uses exact saved preference identity');
$again=$execute('I like the second song','playlist-like-request');
music_preferences_chat_check_v1($again['replayed']&&$current($again['music_preferences_v1']['reference'])['preference']==='neutral','typed replay after Clear stays neutral');

// A real non-playlist result and an individual history receipt keep separate ordered selection semantics.
$pdo->prepare('INSERT INTO chat_messages(conversation_id,role,message,context_json) VALUES(42,\'assistant\',\'Current songs\',?)')
    ->execute([json_encode(['music_chat_v1'=>vp3_music_chat_result_reference_v1($bound['result']),'skip_brain_archive'=>true],JSON_THROW_ON_ERROR)]);
$native=$execute('I like the first song','result-like-request');
music_preferences_chat_check_v1($current($native['music_preferences_v1']['reference'])['source_id']==='71','numbered ordinary result resolves its exact source item');
$page=vp3_music_history_chat_query_v1($pdo,$user,7,42,['limit'=>20])['music_history_v1'];
$historyLike=$execute('I like the fourth entry','history-repeat-like');
music_preferences_chat_check_v1($historyLike['music_preferences_v1']['reference']===$native['music_preferences_v1']['reference'],'fourth history entry keeps its receipt ordinal while sharing the repeated song preference');
$bad=$execute('I like the 99th entry','out-of-range-preference');
music_preferences_chat_check_v1($bad['tool_receipt']['status']==='clarification_required','out-of-range index does not silently choose a different item');

$wire=json_encode([$like,$dislike,$clear,$native,$historyLike],JSON_THROW_ON_ERROR);
foreach(['History Moonlight','YouTube Song 6','vp300000006','resume_seconds','youtube_video','thumbnail_url','autoplay'] as $private){
    music_preferences_chat_check_v1(!str_contains($wire,$private),'preference Chat reply stores no provider metadata or executable '.$private);
}
foreach([$like,$dislike,$clear,$native,$historyLike] as $reply){
    music_preferences_chat_check_v1($reply['actions']===[]&&$reply['sources']===[]&&!isset($reply['music_chat_v1'])&&!isset($reply['media_playback'])&&!isset($reply['youtube_results']),'feedback produces no playback or provider result action');
}
$list=$execute('Show my music preferences','list-preferences-request');
music_preferences_chat_check_v1($list['music_preferences_v1']['action']==='list'&&!isset($list['music_preferences_v1']['items']),'Chat list holds a read reference, not copied preference metadata');
$bind($list);
music_preferences_chat_check_v1($execute('I like the first song','unbound-list-number')['tool_receipt']['status']==='clarification_required','unbound preference-list ordinals are not guessed');
$message=music_chat_fixture_message_v1($pdo); // An unrelated later assistant reply ends selection continuity.
music_preferences_chat_check_v1(!vp3_music_preferences_chat_request_v1($pdo,$user,7,42,'I like that song'),'unrelated assistant turn does not carry old selected-song authority');
music_preferences_chat_check_v1(vp3_music_preferences_chat_request_v1($pdo,$user,7,42,'Show my music preferences'),'explicit list remains available without a previous music selection');
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','playback-history','deny')");
music_preferences_chat_error_v1(fn()=>$execute('Show my music preferences','blocked-preferences-request'),403,'shared data policy fences Chat preference read');
$pdo->exec('DELETE FROM user_agent_data_rules WHERE agent_id=7');
music_preferences_chat_check_v1((int)$pdo->query('SELECT COUNT(*) FROM music_deliberate_skips_v1')->fetchColumn()===0,'typed preference choices never invent deliberate skip receipts');

// Fresh MC1 suggestions use the same current SQL snapshot as the specialist.
$choose=static function(array $selected,string $preference)use($pdo,$user):array{
    $state=vp3_music_preferences_read_v1($pdo,$user,7,42,$selected);
    return vp3_music_preferences_set_v1($pdo,$user,7,42,$selected,$preference,$state['revision'],music_preferences_fixture_uuid_v1())['state'];
};
$choose($selection['result_native'],'dislike');$choose($selection['result_youtube'],'like');
$scope=vp3_music_chat_scope_v1($pdo,$user,7,42);
$youtube=music_chat_fixture_video_v1(6);$fresh=music_chat_fixture_video_v1(8);
foreach([&$youtube,&$fresh] as &$video){$video['title']=$video['youtube_video']['title'];$video['artist']=$video['youtube_video']['channel_title'];}
unset($video);
$candidates=[vp3_music_chat_native_item_v1($pdo,$scope,'42'),vp3_music_chat_native_item_v1($pdo,$scope,'71'),
    vp3_music_chat_native_item_v1($pdo,$scope,'41'),$youtube,$fresh];
$before=music_preferences_fixture_digest_v1($pdo);
$ordinary=vp3_music_preferences_snapshot_v1($pdo,$user,7,42,$candidates);
music_preferences_chat_check_v1(!isset($ordinary['items']['youtube:vp300000008']),'an uncached arbitrary YouTube identity supplies no authorized suggestion metadata');
$snapshot=vp3_music_preferences_snapshot_v1($pdo,$user,7,42,$candidates,[$fresh]);
$new=$snapshot['items']['youtube:vp300000008'];
music_preferences_chat_check_v1($new['title']==='YouTube Song 8'&&$new['preference']==='neutral','fresh exact provider evidence makes a new authorized suggestion eligible');
music_preferences_chat_check_v1($new['play_count']===0&&$new['measured_plays']===0&&$new['measured_seconds']===0.0&&$new['deliberate_skips']===0,'fresh provider metadata never invents listening or preference evidence');
$broad=vp3_music_chat_plan_v1('Suggest three songs');
$ranked=vp3_music_chat_rank_v1($candidates,$broad,$snapshot);
$ids=static fn(array $items):array=>array_map(static fn(array $item):string=>$item['source'].':'.$item['source_id'],$items);
music_preferences_chat_check_v1($ids($ranked)[0]==='youtube:vp300000006','explicit Like raises a current song among equally relevant suggestions');
music_preferences_chat_check_v1(!in_array('vp3:71',$ids($ranked),true),'explicit Dislike excludes a new broad recommendation');
music_preferences_chat_check_v1(in_array('youtube:vp300000008',$ids($ranked),true),'a new verified provider item remains eligible without a previous listening receipt');
$relevant=vp3_music_chat_rank_v1($candidates,vp3_music_chat_plan_v1('Suggest first light songs'),$snapshot);
music_preferences_chat_check_v1($ids($relevant)[0]==='vp3:42','query relevance stays ahead of preference among eligible suggestions');
$exact=vp3_music_chat_rank_v1($candidates,vp3_music_chat_plan_v1('Play "History Moonlight" from VP3'),$snapshot);
music_preferences_chat_check_v1($ids($exact)===['vp3:71'],'explicit exact named playback keeps the disliked song requested by its owner');
$saved=vp3_music_chat_playlist_get_v1($pdo,$user,7,42,$bound['saved']['playlist']['id']);
music_preferences_chat_check_v1($ids($saved['items'])===['vp3:71','youtube:vp300000006','vp3:42'],'saved playlist order and explicit membership remain unchanged by preferences');
foreach([
    array_replace($fresh,['expires_at'=>time()-1]),
    array_replace($fresh,['expires_at'=>time()+VP3_MUSIC_CHAT_RESULT_TTL_V1+60]),
    array_replace($fresh,['source'=>'vp3']),
    array_replace($fresh,['youtube_video'=>array_replace($fresh['youtube_video'],['video_id'=>'vp300000009'])]),
] as $invalid)music_preferences_chat_error_v1(fn()=>vp3_music_preferences_snapshot_v1($pdo,$user,7,42,$candidates,[$invalid]),422,'invalid or stale provider envelope is rejected');
music_preferences_chat_check_v1(music_preferences_fixture_digest_v1($pdo)===$before,'ranking, fresh provider projection and rejected envelopes write no metadata, preference, cache or listening rows');
$pdo->exec("UPDATE agent_connection_youtube_consent_v1 SET policy_version='revoked' WHERE owner_user_id=11");
$revoked=vp3_music_preferences_snapshot_v1($pdo,$user,7,42,$candidates,[$fresh]);
music_preferences_chat_check_v1(!isset($revoked['items']['youtube:vp300000006'])&&!isset($revoked['items']['youtube:vp300000008']),'fresh provider envelope cannot bypass current source consent');
music_preferences_chat_check_v1(array_filter($ids(vp3_music_chat_rank_v1($candidates,$broad,$revoked)),static fn(string $id):bool=>str_starts_with($id,'youtube:'))===[],'new broad ranking excludes every currently unauthorized provider candidate');
$pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET policy_version=? WHERE owner_user_id=11')->execute([VP3_YOUTUBE_CONSENT_POLICY_V1]);
$choose($selection['result_native'],'clear');
$cleared=vp3_music_preferences_snapshot_v1($pdo,$user,7,42,$candidates,[$fresh]);
music_preferences_chat_check_v1(in_array('vp3:71',$ids(vp3_music_chat_rank_v1($candidates,$broad,$cleared)),true),'Clear removes the broad recommendation veto without inventing a Like');
echo "Music preferences Chat parser and exact SQL selections: {$checks} checks passed.\n";
