<?php
declare(strict_types=1);

// Disposable account/media fixtures; the preference writer, SQL reader, ranking,
// specialist projection, exact playback resolver and Agent context are shipped code.
require_once __DIR__.'/fixtures/music-history-v1.php';
require_once dirname(__DIR__).'/includes/music-preferences-v1.php';
require_once dirname(__DIR__).'/includes/agent-media-preferences-v1.php';
require_once dirname(__DIR__).'/includes/agent-media-entertainment-v1.php';
require_once dirname(__DIR__).'/includes/agent-media-entertainment-youtube-v1.php';
require_once dirname(__DIR__).'/includes/agent-media-youtube-play-analysis-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-opportunity-sources-v1.php';

function music_preference_specialist_check_v1(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    $GLOBALS['music_preference_specialist_checks_v1']=($GLOBALS['music_preference_specialist_checks_v1']??0)+1;
}

function music_preference_specialist_error_v1(callable $work,int $status,string $message): void
{
    try{$work();}catch(RuntimeException $error){music_preference_specialist_check_v1($error->getCode()===$status,$message.' ('.$error->getCode().')');return;}
    throw new RuntimeException('FAIL: '.$message.' did not reject');
}

function music_preference_specialist_result_v1(PDO $pdo,array $items,string $query='Fixture music'): array
{
    $key='specialist-result-'.bin2hex(random_bytes(8));
    $stored=vp3_music_chat_result_store_v1($pdo,current_user(),7,42,$key,hash('sha256',$key),$items,
        ['intent'=>'show','source'=>'both','requested_count'=>count($items),'query'=>$query,'create_playlist'=>false]);
    $message=music_chat_fixture_message_v1($pdo);
    $bound=vp3_music_chat_result_bind_v1($pdo,current_user(),7,42,$stored['result_id'],$message);
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([
        json_encode(['music_chat_v1'=>vp3_music_chat_result_reference_v1($bound),'skip_brain_archive'=>true],JSON_THROW_ON_ERROR),$message]);
    return $bound;
}

function music_preference_specialist_rows_v1(PDO $pdo): array
{
    $out=[];
    foreach(['music_preferences_v1','music_preference_requests_v1','music_deliberate_skips_v1','track_play_sessions',
        'music_listening_sessions_v1','vp3_youtube_playback_v1','vp3_youtube_playlist_plays_v1','chat_messages'] as $table){
        $out[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    }
    return $out;
}

$pdo=new PDO('sqlite::memory:');
music_chat_fixture_bootstrap_v1($pdo);music_chat_fixture_schema_v1($pdo);music_chat_fixture_seed_v1($pdo);
music_history_fixture_schema_v1($pdo);$history=music_history_fixture_seed_v1($pdo);
vp3_music_preferences_schema_v1($pdo);
$user=current_user();$scope=vp3_agent_activity_scope_v1($pdo,$user,7,42);
$principal=$scope['principal'];$binding=['owner_user_id'=>11,'agent_id'=>7,'conversation_id'=>42];
$native=music_preference_specialist_result_v1($pdo,[['source'=>'vp3','source_id'=>'42'],['source'=>'vp3','source_id'=>'71']]);
$selection=static fn(int $index):array=>['kind'=>'result','result_id'=>$native['result_id'],'revision'=>$native['revision'],'index'=>$index];
$choose=static fn(string $taste=''):array=>vp3_media_native_choices_v1($pdo,$user,$principal,$taste,50);
$ids=static fn(array $tracks):array=>array_map('intval',array_column($tracks,'id'));
$source=static fn(int $track):array=>vp3_media_sources_v1($pdo,$scope,$binding,['media:track:'.$track],new DateTimeImmutable(),true)['items'];

$baseline=$choose();
music_preference_specialist_check_v1($ids($baseline)[0]===71,'real measured history ranks ahead of starts and neutral catalog order');
music_preference_specialist_check_v1(!in_array(43,$ids($baseline),true)&&!in_array(44,$ids($baseline),true),'private foreign and unpublished songs never enter playable suggestions');

$like=vp3_music_preferences_set_v1($pdo,$user,7,42,$selection(0),'like',0,'specialist-like-42');
music_preference_specialist_check_v1($ids($choose())[0]===42,'an explicit Like outranks measured listening for another recording');
music_preference_specialist_check_v1($ids($choose('Rock'))[0]!==42,'the owner query keeps matching catalog relevance before personal ranking');
vp3_media_offers_save_v1($pdo,11,true);
$liked=$source(42)['media:track:42'];
music_preference_specialist_check_v1(str_contains($liked['summary'],'explicitly liked')&&str_contains($liked['question'],'First Light'),
    'the shared specialist report explains the real preference and names its offered song');
music_preference_specialist_check_v1($liked['music_preference']['preference']==='like'&&$liked['revision']!==$liked['playback_revision'],
    'coordination evidence includes preference state while retaining a separate canonical playback revision');
$action=vp3_media_native_pick_action_v1($pdo,$user,7,42,42,$liked['playback_revision']);
$resolved=vp3_media_playback_resolve_v1($pdo,$user,$action);
music_preference_specialist_check_v1(($resolved['tracks'][0]['id']??null)===42,'the preference-bound recommendation still resolves through the exact native Player contract');
music_preference_specialist_check_v1(vp3_media_native_pick_action_v1($pdo,$user,7,42,42,$liked['revision'])===null,
    'the coordination revision cannot impersonate a canonical playback revision');

$brain=vp3_media_preferences_brain_context_v1($pdo,$user,7,42,'What music do I like?');
$brainData=json_decode($brain[0]['text'],true,32,JSON_THROW_ON_ERROR);
$brainItems=array_column($brainData['items'],null,'source_id');
music_preference_specialist_check_v1($brainItems['42']['preference']==='like'&&$brainItems['42']['title']==='First Light',
    'Main Agent context has the same current explicit choice and authorized title');
music_preference_specialist_check_v1($brainData['coverage']['window_days']===30&&$brainData['coverage']['history_limit']===200
    &&str_contains($brainData['rules'],'not dislikes')&&$brainData['source_text_is_untrusted_data']===true,
    'shared Agent context discloses bounded evidence and prevents failed or unplayed items becoming taste');
music_preference_specialist_check_v1(vp3_media_preferences_brain_context_v1($pdo,$user,7,42,'Explain an invoice')===[],
    'unrelated foreground questions do not read private music preferences');

vp3_music_preferences_set_v1($pdo,$user,7,42,$selection(0),'dislike',1,'specialist-dislike-42');
music_preference_specialist_check_v1(!in_array(42,$ids($choose()),true)&&$source(42)===[],
    'Dislike removes catalog recommendations and invalidates an exact previously offered source');
$explicit=vp3_music_chat_resolve_v1($pdo,$user,7,42,['result_id'=>$native['result_id'],'revision'=>$native['revision'],'index'=>0]);
music_preference_specialist_check_v1(($explicit['media_playback']['track_id']??null)===42,
    'an exact owner playback selection remains available even when recommendation feedback is Dislike');
$clear=vp3_music_preferences_set_v1($pdo,$user,7,42,$selection(0),'clear',2,'specialist-clear-42');
$cleared=$source(42)['media:track:42'];
music_preference_specialist_check_v1($clear['state']['preference']==='neutral'&&in_array(42,$ids($choose()),true)
    &&$cleared['revision']!==$liked['revision'],'Clear restores neutral eligibility without reviving the older Like or its offered revision');
music_preference_specialist_check_v1(!isset(vp3_media_preferences_read_v1($pdo,$user,7,42)['items']['vp3:42'])
    &&!str_contains(json_encode(vp3_media_preferences_brain_context_v1($pdo,$user,7,42,'Music preferences'),JSON_THROW_ON_ERROR),'First Light'),
    'a cleared unplayed choice contributes no identity or title to spontaneous Agent preference context');

// Old zero-second starts include playback failures and must not lower a song.
$tracks=[];foreach([45,46] as $id)$tracks[$id]=music_media_track($id,$user);
$beforeShort=$ids(player_for_you($user,$tracks,2));
for($i=0;$i<5;++$i)music_history_fixture_native_v1($pdo,45,time()-100-$i,11,0);
$afterShort=$ids(player_for_you($user,$tracks,2));
music_preference_specialist_check_v1($beforeShort===[45,46]&&$afterShort===$beforeShort,
    'the existing Player no longer treats zero-second or failed starts as implicit skips');
$snap=vp3_media_preferences_read_v1($pdo,$user,7,42,[['source'=>'vp3','source_id'=>'45']]);
music_preference_specialist_check_v1($snap['items']['vp3:45']['play_count']===5&&$snap['items']['vp3:45']['deliberate_skips']===0
    &&$snap['items']['vp3:45']['preference']==='neutral','unplayed starts remain facts, not negative preference evidence');
$pdo->prepare('UPDATE music_listening_sessions_v1 SET last_received_ms=? WHERE session_id=?')
    ->execute([(time()-1)*1000,$history['native']]);
vp3_music_preferences_skip_v1($pdo,$user,7,42,$selection(1),['kind'=>'n','id'=>(string)$history['native']],'specialist-next-71');
$skip=vp3_media_preferences_read_v1($pdo,$user,7,42,[['source'=>'vp3','source_id'=>'71']]);
music_preference_specialist_check_v1($skip['items']['vp3:71']['deliberate_skips']===1&&$skip['items']['vp3:71']['preference']==='neutral'
    &&str_contains($skip['items']['vp3:71']['reason'],'not a dislike'),'acknowledged deliberate Next remains a separate weak signal');
vp3_music_preferences_set_v1($pdo,$user,7,42,$selection(1),'like',0,'specialist-like-71');
music_preference_specialist_check_v1($ids($choose())[0]===71,'a deliberate skip cannot override the explicit Like for that recording');

$youtube=music_preference_specialist_result_v1($pdo,[music_chat_fixture_video_v1(7),music_chat_fixture_video_v1(8)],'Fixture Music');
$youtubeSelection=static fn(int $index):array=>['kind'=>'result','result_id'=>$youtube['result_id'],'revision'=>$youtube['revision'],'index'=>$index];
vp3_music_preferences_set_v1($pdo,$user,7,42,$youtubeSelection(0),'dislike',0,'specialist-dislike-yt7');
vp3_music_preferences_set_v1($pdo,$user,7,42,$youtubeSelection(1),'like',0,'specialist-like-yt8');
$ytRead=static fn():array=>vp3_media_sources_v1($pdo,$scope,$binding,['media:youtube:'.$youtube['source_message_id']],new DateTimeImmutable(),true)['items'];
$yt=$ytRead();$ytItem=$yt['media:youtube:'.$youtube['source_message_id']]??[];
music_preference_specialist_check_v1(($ytItem['playback']['selected_video_id']??null)==='vp300000008',
    'saved YouTube recommendations select the liked exact video and exclude the disliked result');
music_preference_specialist_check_v1(($ytItem['music_preference']['preference']??null)==='like'
    &&str_contains($ytItem['summary']??'','explicitly liked'),'YouTube specialist explanations use the same current preference reader');
if(!vp3_youtube_history_record_v1($pdo,11,$youtube['source_message_id'],'vp300000008',
    '33333333-3333-4333-8333-333333333333',str_repeat('c',32)))throw new RuntimeException('Fixture play rejected.');
$novelty=music_preference_specialist_result_v1($pdo,[music_chat_fixture_video_v1(9),music_chat_fixture_video_v1(8)],'Fixture Music');
$rankedSeen=vp3_media_sources_v1($pdo,$scope,$binding,['media:youtube:'.$novelty['source_message_id']],new DateTimeImmutable(),true)['items'];
music_preference_specialist_check_v1(($rankedSeen['media:youtube:'.$novelty['source_message_id']]['playback']['selected_video_id']??null)==='vp300000008',
    'a currently liked YouTube video outranks an unplayed neutral result instead of letting novelty override feedback');
vp3_music_preferences_set_v1($pdo,$user,7,42,$youtubeSelection(1),'dislike',1,'specialist-dislike-yt8');
music_preference_specialist_check_v1($ytRead()===[],'a source containing only disliked videos produces no recommendation');
// The legacy played-video specialist consumes the ordinary YouTube return marker.
foreach([$history['message'],$youtube['source_message_id']] as $message){
    $q=$pdo->prepare('SELECT context_json FROM chat_messages WHERE id=?');$q->execute([$message]);
    $context=json_decode($q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    unset($context['skip_brain_archive']);$context['youtube_search_results_v1']=true;
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([json_encode($context,JSON_THROW_ON_ERROR),$message]);
}
$analysis=vp3_media_youtube_play_analysis_v1($pdo,$scope,new DateTimeImmutable());
music_preference_specialist_check_v1(count($analysis)===1&&$analysis[0]['id']!=='youtube-play-analysis:33333333-3333-4333-8333-333333333333',
    'play-history analysis considers the next eligible item after a disliked recent play');
vp3_music_preferences_set_v1($pdo,$user,7,42,$youtubeSelection(1),'clear',2,'specialist-clear-yt8');
music_preference_specialist_check_v1(($ytRead()['media:youtube:'.$youtube['source_message_id']]['playback']['selected_video_id']??null)==='vp300000008',
    'clearing YouTube feedback restores the same currently authorized video without searching');

$before=music_preference_specialist_rows_v1($pdo);
$choose();$source(71);$ytRead();vp3_media_preferences_brain_context_v1($pdo,$user,7,42,'Suggest music');
$songs=vp3_initiative_opportunity_songs_v1($pdo,$user,$principal,['71']);
music_preference_specialist_check_v1(str_contains($songs[1]['song-history:71']['detail'],'explicitly liked'),
    'Brainstorming opportunity evidence includes the current owner choice alongside actual listening facts');
music_preference_specialist_check_v1(music_preference_specialist_rows_v1($pdo)===$before,
    'ranking, specialist reports, opportunity evidence and Main Agent context do not write preferences, listening or Chat');

$pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','playback-history','deny')");
music_preference_specialist_check_v1(vp3_media_preferences_brain_context_v1($pdo,$user,7,42,'What music do I like?')===[],
    'revoked Agent history policy removes private preference context');
music_preference_specialist_error_v1(fn()=>vp3_media_preferences_read_v1($pdo,$user,7,42),403,'the bridge does not fall back to private evidence after a policy denial');
$pdo->exec('DELETE FROM user_agent_data_rules WHERE agent_id=7');
$consentAt=(int)$pdo->query('SELECT accepted_at FROM agent_connection_youtube_consent_v1 WHERE owner_user_id=11')->fetchColumn();
$pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=? WHERE owner_user_id=11')->execute([time()-1]);
$renewed=vp3_media_preferences_read_v1($pdo,$user,7,42);
music_preference_specialist_check_v1(!isset($renewed['items']['youtube:vp300000007'])
    &&!str_contains(json_encode(vp3_media_preferences_brain_context_v1($pdo,$user,7,42,'Music preferences'),JSON_THROW_ON_ERROR),'YouTube Song 7'),
    'renewed consent does not resurrect an unplayed previous-epoch preference identity or title through its still-live cache');
$passed=vp3_media_preferences_read_v1($pdo,$user,7,42,[['source'=>'youtube','source_id'=>'vp300000007']]);
music_preference_specialist_check_v1(($passed['items']['youtube:vp300000007']['preference']??null)==='neutral',
    'an explicitly passed currently authorized provider candidate remains neutral after the consent epoch changes');
$pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=? WHERE owner_user_id=11')->execute([$consentAt]);
$pdo->exec('UPDATE tracks SET owner_user_id=22,visibility=\'private\' WHERE id=71');
music_preference_specialist_check_v1(!in_array(71,$ids($choose()),true)&&$source(71)===[],
    'current source visibility removes a liked native recording from suggestions');
music_preference_specialist_check_v1(!str_contains(json_encode(vp3_media_preferences_brain_context_v1($pdo,$user,7,42,'Music preferences'),JSON_THROW_ON_ERROR),'History Moonlight'),
    'current source visibility also removes its private title from shared Agent context');
$pdo->exec('UPDATE tracks SET owner_user_id=11,visibility=\'public\' WHERE id=71');
$pdo->prepare('UPDATE agent_connection_youtube_results_v1 SET expires_at=? WHERE message_id=?')
    ->execute([time()-1,$youtube['source_message_id']]);
music_preference_specialist_check_v1($ytRead()===[],'expired provider metadata cannot remain a saved YouTube recommendation');
$pdo->exec("UPDATE agent_connection_youtube_consent_v1 SET policy_version='revoked' WHERE owner_user_id=11");
$afterConsent=vp3_media_preferences_brain_context_v1($pdo,$user,7,42,'What YouTube music do I like?');
music_preference_specialist_check_v1(!str_contains(json_encode($afterConsent,JSON_THROW_ON_ERROR),'YouTube Song'),
    'YouTube optout removes cached titles and preference context');

$GLOBALS['music_chat_fixture_user_id']=22;
$foreign=vp3_media_preferences_read_v1($pdo,current_user(),9,52,[['source'=>'vp3','source_id'=>'71']]);
music_preference_specialist_check_v1(($foreign['items']['vp3:71']['preference']??null)==='neutral',
    'another owner does not inherit the first owner’s Like for a public recording');
$GLOBALS['music_chat_fixture_user_id']=11;
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>11];
music_preference_specialist_check_v1(vp3_media_preferences_brain_context_v1($pdo,$user,7,42,'Music preferences')===[],
    'participant context cannot read owner music taste');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);

$many=music_preference_specialist_result_v1($pdo,array_map(static fn(int $id):array=>['source'=>'vp3','source_id'=>(string)$id],range(45,57)));
foreach(range(0,12) as $index)vp3_music_preferences_set_v1($pdo,$user,7,42,
    ['kind'=>'result','result_id'=>$many['result_id'],'revision'=>$many['revision'],'index'=>$index],
    'like',0,'specialist-context-limit-'.$index);
$bounded=json_decode(vp3_media_preferences_brain_context_v1($pdo,$user,7,42,'Music preferences')[0]['text'],true,32,JSON_THROW_ON_ERROR);
music_preference_specialist_check_v1(count($bounded['items'])===12&&$bounded['coverage']['context_truncated']===true
    &&$bounded['coverage']['context_available_items']>12&&str_contains($bounded['rules'],'bounded selection'),
    'the Main Agent receives an explicitly bounded selection instead of mistaking twelve items for the complete profile');

echo 'MUSIC_PREFERENCES_SPECIALIST_V1=PASS '.$GLOBALS['music_preference_specialist_checks_v1']." checks (actual SQLite service, ranking, specialist sources and Agent context)\n";
