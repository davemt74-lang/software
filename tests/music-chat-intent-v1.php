<?php
declare(strict_types=1);

require __DIR__.'/fixtures/music-chat-library-v1.php';
require dirname(__DIR__).'/includes/music-chat-v1.php';

$checks=0;
function music_intent_assert(bool $ok,string $message): void
{
    ++$GLOBALS['checks'];
    if(!$ok)throw new RuntimeException($message);
}
function music_intent_equal(mixed $actual,mixed $expected,string $message): void
{
    music_intent_assert($actual===$expected,$message.'; expected '.json_encode($expected).', got '.json_encode($actual));
}
function music_intent_reject(callable $call,int $status,string $message): void
{
    try{$call();}catch(RuntimeException $e){music_intent_equal((int)$e->getCode(),$status,$message);return;}
    throw new RuntimeException($message.' did not reject');
}

// The actual shared parser runs with the production mood rules loaded by native playback.
foreach([
    ['Play just one acoustic song','play',1,'both',true,'acoustic'],
    ['Show me one chill song','show',1,'both',false,'chill'],
    ['Show me three songs on YouTube','show',3,'youtube',false,''],
    ['Create a six-song driving playlist','create_playlist',6,'both',false,'driving'],
    ['Create a playlist of acoustic songs and play it','create_playlist',8,'both',true,'acoustic'],
    ['Create and play a playlist of acoustic songs','create_playlist',8,'both',true,'acoustic'],
    ['Create a playlist but do not play it','create_playlist',8,'both',false,''],
    ['Play one song, not a playlist','play',1,'both',true,''],
    ["Play one song but don't create a playlist",'play',1,'both',true,''],
    ['Please show me a calm song from VP3.','show',1,'vp3',false,'calm'],
    ['Give me two calm songs from both YouTube and VP3','show',2,'both',false,'calm'],
    ['Suggest 25 songs from YouTube','suggest',25,'youtube',false,''],
    ['Create a ten-song playlist from VP3 called "Night Drive"','create_playlist',10,'vp3',false,''],
    ['Play a song without YouTube','play',1,'vp3',true,''],
    ['Show one song from YouTube only','show',1,'youtube',false,''],
    ['Play "The Song Remains the Same" by Led Zeppelin','play',1,'both',true,'The Song Remains the Same'],
    ['Play "Song 2" by Blur','play',1,'both',true,'Song 2'],
    ['Play "Music" by Madonna','play',1,'both',true,'Music'],
    ['Play "Video Games" by Lana Del Rey','play',1,'both',true,'Video Games'],
    ['Play "Playlist" by Artist Name','play',1,'both',true,'Playlist'],
    ['Play "YouTube" by Artist Name','play',1,'both',true,'YouTube'],
    ['Play "YouTube" by Artist Name from VP3','play',1,'vp3',true,'YouTube'],
    ['Play "Twenty Songs" by Artist Name','play',1,'both',true,'Twenty Songs'],
    ['Play "HomeServer" by Artist Name from VP3','play',1,'vp3',true,'HomeServer'],
    ['Play "Home Server" by Artist Name from VP3','play',1,'vp3',true,'Home Server'],
    ['Play "Music Server" by Artist Name on YouTube','play',1,'youtube',true,'Music Server'],
    ['Play "Media Server" by Artist Name','play',1,'both',true,'Media Server'],
    ['Play "The Next One" by Artist Name','play',1,'both',true,'The Next One'],
    ['Play "Previous Track" by Artist Name on YouTube','play',1,'youtube',true,'Previous Track'],
    ['Show me 2 songs but not from YouTube','show',2,'vp3',false,''],
    ['Show me 2 songs not on VP3','show',2,'youtube',false,''],
    ['Play one song from VP3 and not YouTube','play',1,'vp3',true,''],
    ['Queue one calm song from VP3','queue_append',1,'vp3',true,'calm'],
    ['Play "Native Midnight" next','play_next',1,'both',true,'Native Midnight'],
    ['Play one calm song next','play_next',1,'both',true,'calm'],
] as [$request,$intent,$count,$source,$autoplay,$query]){
    $plan=vp3_music_chat_plan_v1($request);
    music_intent_assert($plan!==null,'Recognized music request: '.$request);
    foreach(['intent'=>$intent,'requested_count'=>$count,'source'=>$source,'autoplay'=>$autoplay,'query'=>$query] as $key=>$expected)
        music_intent_equal($plan[$key],$expected,$request.' / '.$key);
}
foreach([
    'What do you think of jazz music?',
    'Do not play anything',
    "Don't create a playlist",
    'Explain how song royalties work',
    'Show me how to create a playlist',
    "Create a playlist but don't save it",
    'Find a video about pizza on YouTube',
    'Play chess',
    'What did I play yesterday?',
    'Show my listening history',
    '/dj start',
    'Resume my last track',
    'Search local Music Server library',
    'Search local Media Server library',
    'Search my HomeServer music library for jazz',
    'Can you play music on HomeServer?',
    'Can you play music on my home server?',
    'Search my home server music library for jazz',
    'Play the next one',
    'Play the next',
    'Show me the next result',
    'Play the next song',
    'Play previous one',
    'Play the previous track',
    'Play the next YouTube song',
] as $request)music_intent_equal(vp3_music_chat_plan_v1($request),null,'Unrelated discussion, negation, video, or history stays on its route: '.$request);
music_intent_equal(vp3_music_chat_plan_v1('Play the second one')['selection']['index'],2,'Ordinal is global result position');
music_intent_equal(vp3_music_chat_plan_v1('Play that playlist')['intent'],'play_playlist','Saved playlist followup is explicit');
music_intent_equal(vp3_music_chat_plan_v1('Play Night Drive playlist')['playlist_name'],'Night Drive','Named saved playlist lookup is exact');
music_intent_equal(vp3_music_chat_plan_v1('Create a ten-song playlist called "Night Drive"')['playlist_title'],'Night Drive','Named create preserves title');
music_intent_equal(vp3_music_chat_plan_v1('Show 26 songs')['error'],'count_out_of_range','Unsupported count is visible');
music_intent_equal(vp3_music_chat_plan_v1('Play one song on Spotify')['error'],'unsupported_source','Unsupported provider never silently substitutes');
foreach(['Play one song from YouTube but not from YouTube','Show two songs without YouTube and without VP3',
    'Play the second one from VP3 but not on VP3'] as $request){
    $conflict=vp3_music_chat_plan_v1($request);
    music_intent_equal($conflict['error'],'conflicting_source','Conflicting source restrictions require clarification: '.$request);
    music_intent_assert(!$conflict['autoplay'],'Conflicting source restrictions cannot authorize playback');
}
$quotedList=vp3_music_chat_plan_v1('Create a two-song playlist from VP3 called "YouTube Video Games"');
music_intent_equal($quotedList['playlist_title'],'YouTube Video Games','Quoted saved-list name remains literal');
music_intent_equal($quotedList['source'],'vp3','Quoted saved-list name cannot select another source');
music_intent_equal(vp3_music_chat_plan_v1('Play "YouTube Video Games" playlist from VP3')['playlist_name'],'YouTube Video Games','Quoted saved-playlist lookup preserves its complete name');
music_intent_assert(!track_is_playlist_request('one calm song'),'A mood does not create a playlist');
music_intent_equal(vp3_music_chat_plan_v1('Find Native Midnight')['requires_match'],true,'Bare find requires actual native music match');
music_intent_equal(vp3_music_chat_plan_v1('Add this to my Night Drive playlist')['intent'],'add_to_playlist','Selected song addition is a specific playlist mutation');
music_intent_equal(vp3_music_chat_plan_v1('Add the second one to my Night Drive playlist')['selection']['index'],2,'Playlist add keeps displayed ordinal');
music_intent_equal(vp3_music_chat_plan_v1('Add that one to the queue')['intent'],'queue_append','Queue addition differs from saved playlist creation');
music_intent_equal(vp3_music_chat_plan_v1('Play the second one next')['intent'],'play_next','Play-next has an explicit queue action');
music_intent_equal(vp3_music_chat_plan_v1('Create that playlist and play it')['selection'],['all'=>true],'Saving displayed songs does not rerun a search');

$pdo=new PDO('sqlite::memory:');
music_chat_fixture_bootstrap_v1($pdo);
music_chat_fixture_schema_v1($pdo);
music_chat_fixture_seed_v1($pdo);
$pdo->exec('CREATE TABLE user_data_policies(id INTEGER PRIMARY KEY,owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER)');
$pdo->exec('CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT)');
$user=current_user();
[$user,$principal]=vp3_media_playback_user_v1($pdo,$user,7,42);

// Real provider adapters with deterministic HTTP boundaries. Request bounds and details
// are executed, not reproduced by a fake parser or source resolver.
$providerCalls=[];$providerFlags=[];
$provider=static function(string $url,array $headers)use(&$providerCalls,&$providerFlags):array{
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    $providerCalls[]=['path'=>parse_url($url,PHP_URL_PATH),'query'=>$query,'headers'=>$headers];
    $ids=[];
    if(str_contains($url,'/search?')){
        $count=(int)$query['maxResults'];
        for($n=1;$n<=$count;++$n)$ids[]='msc'.str_pad((string)$n,8,'0',STR_PAD_LEFT);
    }else $ids=explode(',',(string)($query['id']??''));
    $items=[];
    foreach($ids as $id){
        $n=(int)substr($id,3);
        $snippet=['title'=>'Calm Audio '.$n,'channelTitle'=>'Verified Artist','categoryId'=>'10',
            'description'=>'Public music description','thumbnails'=>['medium'=>['url'=>'https://i.ytimg.com/vi/'.$id.'/mqdefault.jpg']]];
        if(str_contains($url,'/search?'))$items[]=['id'=>['kind'=>'youtube#video','videoId'=>$id],'snippet'=>$snippet];
        else{
            if(($providerFlags[$n]??'')==='missing')continue;
            $status=['privacyStatus'=>'public','embeddable'=>true,'madeForKids'=>false];
            if(($providerFlags[$n]??'')==='private')$status['privacyStatus']='private';
            if(($providerFlags[$n]??'')==='blocked')$status['embeddable']=false;
            if(($providerFlags[$n]??'')==='kids')$status['madeForKids']=true;
            if(($providerFlags[$n]??'')==='not_music')$snippet['categoryId']='22';
            $items[]=['id'=>$id,'snippet'=>$snippet,'status'=>$status];
        }
    }
    return ['status'=>200,'json'=>['kind'=>str_contains($url,'/search?')?'youtube#searchListResponse':'youtube#videoListResponse','items'=>$items]];
};
foreach([10,25] as $count){
    $providerCalls=[];
    $videos=agent_connection_youtube_music_search_v1('calm music',$count,$provider);
    music_intent_equal(count($videos),$count,'Music provider supports '.$count.' actual verified results');
    music_intent_equal(count($providerCalls),2,'Music lookup performs one search and one exact details request');
    music_intent_equal((int)$providerCalls[0]['query']['maxResults'],$count,'Requested music provider count is not silently capped at eight');
    music_intent_equal($providerCalls[0]['query']['videoCategoryId'],'10','Music search is bounded to music category');
    music_intent_equal(count(explode(',',$providerCalls[1]['query']['id'])),$count,'Every selected provider ID is status checked');
}
$providerCalls=[];
$ordinary=agent_connection_youtube_search_v1('pizza videos',25,$provider);
music_intent_equal(count($ordinary),8,'Ordinary YouTube video search still caps at eight');
music_intent_equal(count($providerCalls),1,'Ordinary search call pattern remains unchanged');
music_intent_assert(!isset($providerCalls[0]['query']['videoCategoryId']),'Ordinary video searches remain general');
$providerFlags=[2=>'private',3=>'blocked',4=>'kids',5=>'not_music',6=>'missing'];
$verified=agent_connection_youtube_music_search_v1('calm music',10,$provider);
music_intent_equal(array_column($verified,'video_id'),['msc00000001','msc00000007','msc00000008','msc00000009','msc00000010'],'Missing, private, nonembeddable, kids and nonmusic videos cannot become song results');
$providerFlags=[];
music_intent_reject(static fn()=>agent_connection_youtube_music_search_v1('calm',10,static function(string $url)use($provider):array{
    return str_contains($url,'/videos?')?['status'=>503,'json'=>['error'=>['message'=>'PRIVATE PROVIDER FAILURE']]]:$provider($url,[]);
}),502,'Failure of exact status check does not return unverified search hits');

$sequence=0;
$execute=static function(string $request,?string $key=null,?callable $http=null)use($pdo,$user,$principal,&$sequence,$provider):array{
    $key??='intent-request-'.(++$sequence);
    $userMessage=music_chat_fixture_message_v1($pdo,42,'user');
    $tool=vp3_music_chat_execute_v1($pdo,$user,$principal,42,$userMessage,$request,['music_request_id'=>$key],null,$http??$provider);
    if($tool===null)throw new RuntimeException('Expected actual music orchestration: '.$request);
    return $tool;
};
$bind=static function(array $tool)use($pdo,$user,$principal):array{
    $message=music_chat_fixture_message_v1($pdo);
    $tool=vp3_music_chat_bind_reply_v1($pdo,$user,$principal,42,$message,$tool);
    $pdo->prepare('UPDATE chat_messages SET message=?,context_json=? WHERE id=?')->execute([$tool['answer'],
        json_encode(['music_chat_v1'=>vp3_music_chat_result_reference_v1($tool['music_chat_v1'])],JSON_THROW_ON_ERROR),$message]);
    return $tool;
};
$saveClarification=static function(array $tool)use($pdo):void{
    music_intent_assert(isset($tool['music_chat_followup_v1']),'Clarification preserves the displayed selection reference');
    $message=music_chat_fixture_message_v1($pdo);
    $pdo->prepare('UPDATE chat_messages SET message=?,context_json=? WHERE id=?')->execute([$tool['answer'],
        json_encode(['music_chat_followup_v1'=>$tool['music_chat_followup_v1']],JSON_THROW_ON_ERROR),$message]);
};

$single=$bind($execute('Show me one calm song from VP3'));
music_intent_equal(count($single['music_chat_v1']['items']),1,'One request produces one actual card total');
music_intent_equal($single['music_chat_v1']['items'][0]['source'],'vp3','Native source restriction holds');
music_intent_assert(!isset($single['music_chat_v1']['autoplay']),'Showing a song never starts playback');
music_intent_equal((int)$pdo->query('SELECT COUNT(*) FROM music_chat_playlists_v1')->fetchColumn(),0,'A song search never creates a playlist');

$oneBoth=$bind($execute('Play just one calm song'));
music_intent_equal(count($oneBoth['music_chat_v1']['items']),1,'Combined providers enforce one global card');
music_intent_assert(isset($oneBoth['music_chat_v1']['autoplay']['action_id']),'Explicit play issues a bound one-use action');
music_intent_equal(count($oneBoth['music_chat_v1']['coverage']['sources']??array_filter($oneBoth['music_chat_v1']['coverage'],'is_array')),2,'Combined request records coverage for exactly two sources');

$providerCalls=[];
$pending=$execute('Create a ten-song playlist from YouTube called "Night Drive"','stable-playlist-request');
music_intent_equal(count($pending['music_chat_v1']['items']),10,'Actual orchestration preserves ten verified YouTube songs');
music_intent_equal((int)$pdo->query('SELECT COUNT(*) FROM music_chat_playlists_v1')->fetchColumn(),0,'No success or playlist exists before binding actual assistant turn');
$saved=$bind($pending);
music_intent_assert(str_starts_with($saved['answer'],'Created “Night Drive”'),'Playlist success comes after durable bind');
music_intent_equal($saved['tool_receipt']['capability'],'music.playlists.manage','Playlist creation has a source-specific mutation receipt');
music_intent_assert($saved['tool_receipt']['changes_applied']&&$saved['tool_receipt']['status']==='completed','Only bound saved playlist confirms a completed change');
music_intent_assert(str_contains(vp3_tool_diagnosis_v1(['receipt'=>$saved['tool_receipt']]),'does not confirm that a song played'),'Saved playlist diagnosis never invents a listen');
music_intent_equal($saved['music_chat_v1']['playlist']['visibility'],'private','Chat playlist is actually private');
music_intent_assert(!isset($saved['music_chat_v1']['autoplay']),'Create playlist alone does not play');
$calls=count($providerCalls);
$retry=$execute('Create a ten-song playlist from YouTube called "Night Drive"','stable-playlist-request');
music_intent_equal($retry['music_chat_v1']['result_id'],$saved['music_chat_v1']['result_id'],'Same request replays original selection');
music_intent_equal(count($providerCalls),$calls,'Replay does not spend another provider request');
$retry=$bind($retry);
music_intent_assert($retry['music_chat_v1']['replayed']&&!isset($retry['music_chat_v1']['autoplay']),'Replay cannot issue another automatic action');
music_intent_assert(!$retry['tool_receipt']['changes_applied']&&$retry['tool_receipt']['status']==='read_complete','Playlist retry receipt confirms existing record without another mutation');
music_intent_equal((int)$pdo->query('SELECT COUNT(*) FROM music_chat_playlists_v1')->fetchColumn(),1,'Retry creates no second playlist');
music_intent_reject(static fn()=>$execute('Create a six-song playlist from YouTube called "Night Drive"','stable-playlist-request'),409,'Request identity cannot be reused with changed content');

$providerCalls=[];
$followup=$bind($execute('Play the second one'));
music_intent_equal($followup['music_chat_v1']['items'][0]['source_id'],$saved['music_chat_v1']['items'][1]['source_id'],'Followup binds exact saved ordinal');
music_intent_equal(count($providerCalls),0,'Result selection never performs a new search');
music_intent_assert(isset($followup['music_chat_v1']['autoplay']),'Explicit selected result play is executable');

$beforeLists=(int)$pdo->query('SELECT COUNT(*) FROM music_chat_playlists_v1')->fetchColumn();
$named=$bind($execute('Play Night Drive playlist'));
music_intent_equal($named['music_chat_v1']['playlist']['id'],$saved['music_chat_v1']['playlist']['id'],'Named playback references the actual saved private playlist');
music_intent_equal(array_column($named['music_chat_v1']['items'],'item_id'),array_column($saved['music_chat_v1']['items'],'item_id'),'Saved playlist playback preserves exact source item identities');
music_intent_assert(isset($named['music_chat_v1']['autoplay']),'Named playlist request has an actual automatic action');
$that=$bind($execute('Play that playlist'));
music_intent_equal($that['music_chat_v1']['playlist']['id'],$saved['music_chat_v1']['playlist']['id'],'Deictic playlist followup retains real saved playlist identity');
music_intent_equal((int)$pdo->query('SELECT COUNT(*) FROM music_chat_playlists_v1')->fetchColumn(),$beforeLists,'Playing saved playlists never creates another saved list');
$showNamed=$bind($execute('Show Night Drive playlist'));
music_intent_assert(!isset($showNamed['music_chat_v1']['autoplay']),'Showing a saved playlist never starts playback');

$maximum=$bind($execute('Create a 25-song playlist from YouTube called "Maximum Mix"'));
music_intent_equal(count($maximum['music_chat_v1']['items']),25,'Full orchestration and durable save preserve all 25 songs');
$cached=$pdo->prepare('SELECT payload_json FROM agent_connection_youtube_results_v1 WHERE message_id=?');
$cached->execute([$maximum['music_chat_v1']['source_message_id']]);
music_intent_equal(count(json_decode((string)$cached->fetchColumn(),true,32,JSON_THROW_ON_ERROR)['videos']),25,'Assistant-bound YouTube provenance retains 25 exact provider IDs');

$selectedNative=$bind($execute('Show "Native Midnight" by Fixture Artist from VP3'));
$pendingAdd=$execute('Add this to my Night Drive playlist','selected-song-add');
music_intent_equal(count(vp3_music_chat_playlist_get_v1($pdo,$user,7,42,$saved['music_chat_v1']['playlist']['id'])['items']),10,'Chat add does not claim a change before the assistant turn is bound');
$added=$bind($pendingAdd);
music_intent_equal($added['music_chat_v1']['playlist']['id'],$saved['music_chat_v1']['playlist']['id'],'Chat adds to the same saved playlist');
music_intent_equal(count($added['music_chat_v1']['items']),11,'Exact selected native song joins the existing YouTube list');
music_intent_equal($added['music_chat_v1']['items'][10]['source_id'],'41','Chat add retains exact selected song identity');
music_intent_equal($added['tool_receipt']['operation'],'add','Verified add has the correct mutation receipt');
music_intent_assert($added['tool_receipt']['changes_applied'],'New song addition reports its actual change');
music_intent_assert(!isset($added['music_chat_v1']['autoplay']),'Playlist add never starts music');
$addReplay=$bind($execute('Add this to my Night Drive playlist','selected-song-add'));
music_intent_equal(count($addReplay['music_chat_v1']['items']),11,'Request retry does not append twice');
music_intent_assert(!$addReplay['tool_receipt']['changes_applied'],'Retried add does not claim another mutation');
$bind($execute('Show "Native Midnight" by Fixture Artist from VP3'));
$alreadyAdded=$bind($execute('Add this to my Night Drive playlist'));
music_intent_assert(!$alreadyAdded['tool_receipt']['changes_applied'],'An already-present selection is not a new change even with a new request');
$chosen=$bind($execute('Show "First Light" from VP3'));
$missingTarget=$execute('Add this to my Missing Target playlist');
$saveClarification($missingTarget);
$correctedTarget=$bind($execute('Add this to my Night Drive playlist'));
music_intent_equal($correctedTarget['tool_receipt']['operation'],'add','Corrected playlist name still adds the previously selected song');
music_intent_assert(in_array($chosen['music_chat_v1']['items'][0]['source_id'],array_column($correctedTarget['music_chat_v1']['items'],'source_id'),true),'Corrected target retains exact selected source identity');

$displayed=$bind($execute('Show me two calm songs from VP3'));
$notYetSaved=$execute('Play that playlist');
$saveClarification($notYetSaved);
$savedDisplayed=$bind($execute('Create that playlist and play it'));
music_intent_equal(array_column($savedDisplayed['music_chat_v1']['items'],'source_id'),array_column($displayed['music_chat_v1']['items'],'source_id'),'Create that playlist saves the displayed selection in order');
music_intent_assert(isset($savedDisplayed['music_chat_v1']['autoplay']),'Create and play issues one accepted playback action after saving');
$queued=$bind($execute('Queue the second one'));
music_intent_equal($queued['music_chat_v1']['intent'],'queue_append','Exact selected queue request retains its command');
music_intent_equal($queued['music_chat_v1']['items'][0]['source_id'],$displayed['music_chat_v1']['items'][1]['source_id'],'Queue addition uses displayed global ordinal');
music_intent_assert(isset($queued['music_chat_v1']['autoplay']),'Live queue update carries one-use claim for client execution');

// Numbering belongs to the displayed cross-source list, including unavailable entries.
$nativeById=array_column(vp3_music_chat_native_items_v1($pdo,$user,$principal),null,'source_id');
$mixedItems=[$nativeById['41'],music_chat_fixture_video_v1(1),$nativeById['42']];
$mixed=vp3_music_chat_result_store_v1($pdo,$user,7,42,'mixed-ordinal-request',hash('sha256','mixed-ordinal-request'),$mixedItems,
    ['intent'=>'show','source'=>'both','requested_count'=>3,'query'=>'mixed test']);
$mixed=$bind(vp3_music_chat_reply_v1($mixed));
$wrongSource=$execute('Play the second one from VP3');
music_intent_assert(!isset($wrongSource['music_chat_v1'])&&str_contains($wrongSource['answer'],'from YouTube'),'Source restriction cannot reinterpret global ordinal');
$pdo->exec('UPDATE tracks SET is_published=0 WHERE id=41');
$stillSecond=$bind($execute('Play the second one from YouTube'));
music_intent_equal($stillSecond['music_chat_v1']['items'][0]['source_id'],'vp300000001','Unrelated unavailable first entry does not shift or block second result');
$pdo->exec('UPDATE tracks SET is_published=1 WHERE id=41');
music_chat_fixture_message_v1($pdo);
music_intent_equal(vp3_music_chat_execute_v1($pdo,$user,$principal,42,999,'Play the second one',['music_request_id'=>'after-unrelated-answer'],null,$provider),null,'Deictic followup cannot bind to an older selection after an unrelated answer');

$exact=$bind($execute('Play "Native Midnight" by Fixture Artist from VP3'));
music_intent_equal($exact['music_chat_v1']['items'][0]['source_id'],'41','Exact title and artist resolve canonical current track ID');
music_intent_assert(isset($exact['music_chat_v1']['autoplay']),'Exact authorized recording can play');
$none=$bind($execute('Play "No Such Recording" by Fixture Artist from VP3'));
music_intent_equal($none['music_chat_v1']['items'],[],'Exact missing track does not substitute unrelated native songs');
music_intent_equal($none['music_chat_v1']['status'],'no_matches','Exact no-match status is persisted');
music_intent_assert(!isset($none['music_chat_v1']['autoplay']),'No-match response has no action');

$beforeQuotedLists=(int)$pdo->query('SELECT COUNT(*) FROM music_chat_playlists_v1')->fetchColumn();
$pdo->prepare('UPDATE tracks SET title=? WHERE id=41')->execute(['Playlist']);
$literalSong=$bind($execute('Play "Playlist" by Fixture Artist from VP3'));
music_intent_equal(count($literalSong['music_chat_v1']['items']),1,'Quoted command-like song title returns one song');
music_intent_equal($literalSong['music_chat_v1']['intent'],'play','Quoted playlist title is a song play intent');
music_intent_equal((int)$pdo->query('SELECT COUNT(*) FROM music_chat_playlists_v1')->fetchColumn(),$beforeQuotedLists,'Quoted song title cannot create a saved list');
$pdo->prepare('UPDATE tracks SET title=? WHERE id=41')->execute(['Native Midnight']);

$otherTitle='Native Midnight';$otherArtist='Different Artist';
$sameTitleProvider=static function(string $url,array $headers)use(&$otherTitle,&$otherArtist):array{
    $id='msc00000099';$snippet=['title'=>$otherTitle,'channelTitle'=>$otherArtist,'categoryId'=>'10',
        'description'=>'Verified music fixture','thumbnails'=>['medium'=>['url'=>'https://i.ytimg.com/vi/'.$id.'/mqdefault.jpg']]];
    $item=str_contains($url,'/search?')?['id'=>['kind'=>'youtube#video','videoId'=>$id],'snippet'=>$snippet]
        :['id'=>$id,'snippet'=>$snippet,'status'=>['privacyStatus'=>'public','embeddable'=>true,'madeForKids'=>false]];
    return ['status'=>200,'json'=>['items'=>[$item]]];
};
$twoArtists=$bind($execute('Play "Native Midnight"',null,$sameTitleProvider));
music_intent_equal($twoArtists['music_chat_v1']['status'],'ambiguous','Same title by different artists across sources requires clarification');
music_intent_assert(!isset($twoArtists['music_chat_v1']['autoplay']),'Cross-source ambiguity cannot start the arbitrary preferred source');
$otherArtist='Fixture Artist';
$sameRecording=$bind($execute('Play "Native Midnight"',null,$sameTitleProvider));
music_intent_equal(count($sameRecording['music_chat_v1']['items']),1,'Same title and artist through both sources still produces one result total');
music_intent_equal($sameRecording['music_chat_v1']['status'],'ready','Equivalent cross-source title and artist do not force a duplicate choice');
$otherTitle='Native Midnight (Live)';
$twoVersions=$bind($execute('Play "Native Midnight"',null,$sameTitleProvider));
music_intent_equal($twoVersions['music_chat_v1']['status'],'ambiguous','A distinct cross-source version requires clarification');

$pdo->prepare('UPDATE tracks SET title=? WHERE id=42')->execute(['Native Midnight']);
$ambiguous=$bind($execute('Play "Native Midnight" from VP3'));
music_intent_equal($ambiguous['music_chat_v1']['status'],'ambiguous','Two recordings with same title require artist/version clarification');
music_intent_equal($ambiguous['music_chat_v1']['items'],[],'Ambiguity never selects arbitrary ID');
$pdo->prepare('UPDATE tracks SET title=? WHERE id=42')->execute(['First Light']);

agent_connection_youtube_set_consent_v1($pdo,11,false);
$providerCalls=[];
$disabled=$bind($execute('Play one song from YouTube'));
music_intent_equal($disabled['music_chat_v1']['status'],'unavailable','Disabled selected provider is visible');
music_intent_equal($disabled['music_chat_v1']['items'],[],'Disabled YouTube does not substitute native source');
music_intent_equal(count($providerCalls),0,'No provider lookup occurs without consent');
$available=$bind($execute('Show one calm song from both VP3 and YouTube'));
music_intent_equal(count($available['music_chat_v1']['items']),1,'Both-source request can show current native result when YouTube disabled');
music_intent_assert(str_contains($available['answer'],'YouTube was unavailable'),'Partial provider coverage is explicit');
agent_connection_youtube_set_consent_v1($pdo,11,true);

$providerCalls=[];
music_intent_reject(static fn()=>$execute('Play one calm song from YouTube',null,static function(string $url,array $headers)use($provider,$pdo):array{
    $result=$provider($url,$headers);
    if(str_contains($url,'/videos?'))$pdo->exec('UPDATE users SET account_allowed=0 WHERE id=11');
    return $result;
}),403,'Revoked account during provider request cannot publish or start music');
$pdo->exec('UPDATE users SET account_allowed=1 WHERE id=11');

$shortfall=$bind($execute('Create an eight-song calm playlist from VP3 called "Calm Shortfall"'));
music_intent_equal($shortfall['music_chat_v1']['requested_count'],8,'Bound saved playlist retains original requested count');
music_intent_equal(count($shortfall['music_chat_v1']['items']),2,'Saved playlist reports actual matching catalog size');
music_intent_assert(str_contains($shortfall['answer'],'2 of the 8'),'Saved playlist answer makes verified shortfall explicit');

$foreign=vp3_music_chat_native_items_v1($pdo,$user,$principal);
music_intent_assert(!in_array('43',array_column($foreign,'source_id'),true),'Canonical catalog excludes foreign private track');
music_intent_assert(!str_contains(json_encode($foreign),'PRIVATE FOREIGN TITLE'),'Foreign private metadata is never in source results');

// Installing code before the additive upgrade must not intercept nonmusic Chat.
$withoutSchema=new PDO('sqlite::memory:');
music_chat_fixture_bootstrap_v1($withoutSchema);music_chat_fixture_schema_v1($withoutSchema,false);music_chat_fixture_seed_v1($withoutSchema);
$withoutSchema->exec('CREATE TABLE user_data_policies(id INTEGER PRIMARY KEY,owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER)');
$withoutSchema->exec('CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT)');
$withoutSchemaUser=current_user();
[$withoutSchemaUser,$withoutSchemaPrincipal]=vp3_media_playback_user_v1($withoutSchema,$withoutSchemaUser,7,42);
foreach(['Find my invoices','Show my tasks','Find PRIVATE FOREIGN TITLE'] as $request){
    music_intent_equal(vp3_music_chat_execute_v1($withoutSchema,$withoutSchemaUser,$withoutSchemaPrincipal,42,999,$request),null,
        'Missing music schema leaves an unmatched or unauthorized bare request to its normal Chat tool: '.$request);
}
foreach(['Find Native Midnight','Show me one calm song from VP3'] as $request){
    $upgrade=vp3_music_chat_execute_v1($withoutSchema,$withoutSchemaUser,$withoutSchemaPrincipal,42,999,$request);
    music_intent_assert(!empty($upgrade['music_chat_handled_v1'])&&str_contains($upgrade['answer'],'database upgrade'),
        'Recognized or explicit music still reports the required upgrade: '.$request);
}
$legacyCard=music_chat_fixture_message_v1($withoutSchema,42,'assistant');
$withoutSchema->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([
    json_encode(['youtube_results_ref_v1'=>101,'youtube_result_video_v1'=>'abcdefghijl'],JSON_THROW_ON_ERROR),$legacyCard]);
foreach(['Play the third one','Play that one','Show that playlist'] as $request){
    music_intent_equal(vp3_music_chat_execute_v1($withoutSchema,$withoutSchemaUser,$withoutSchemaPrincipal,42,999,$request),null,
        'Before the music upgrade, the current legacy YouTube card keeps its selection route: '.$request);
}
music_intent_assert(!table_exists('music_chat_results_v1'),'Missing-schema routing checks never create the additive music schema');
vp3_music_chat_schema_v1($withoutSchema);
$partialTurn=music_chat_fixture_message_v1($withoutSchema,42,'user');
$partialTool=vp3_music_chat_execute_v1($withoutSchema,$withoutSchemaUser,$withoutSchemaPrincipal,42,$partialTurn,'Show me one calm song from VP3');
$partialMessage=music_chat_fixture_message_v1($withoutSchema,42,'assistant');
$partialTool=vp3_music_chat_bind_reply_v1($withoutSchema,$withoutSchemaUser,$withoutSchemaPrincipal,42,$partialMessage,$partialTool);
$withoutSchema->exec('DROP TABLE music_chat_play_actions_v1');
music_intent_assert(!vp3_music_chat_ready_v1($withoutSchema),'Partial prerelease schema is not reported ready');
foreach([
    ['music_chat_v1'=>vp3_music_chat_result_reference_v1($partialTool['music_chat_v1'])],
    ['music_chat_followup_v1'=>vp3_music_chat_result_reference_v1($partialTool['music_chat_v1'])],
    [],
] as $context){
    $withoutSchema->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([json_encode($context,JSON_THROW_ON_ERROR),$partialMessage]);
    $upgrade=vp3_music_chat_execute_v1($withoutSchema,$withoutSchemaUser,$withoutSchemaPrincipal,42,999,'Play the first one');
    music_intent_assert(!empty($upgrade['music_chat_handled_v1'])&&str_contains($upgrade['answer'],'database upgrade'),
        'Partial schema preserves a current MC1 result, clarification or bound prerelease row instead of rerouting it');
}
$newLegacyCard=music_chat_fixture_message_v1($withoutSchema,42,'assistant');
$withoutSchema->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([
    json_encode(['youtube_results_ref_v1'=>101,'youtube_result_video_v1'=>'abcdefghijl'],JSON_THROW_ON_ERROR),$newLegacyCard]);
music_intent_equal(vp3_music_chat_execute_v1($withoutSchema,$withoutSchemaUser,$withoutSchemaPrincipal,42,999,'Play the third one'),null,
    'A newer legacy YouTube card supersedes an older MC1 selection even during a partial upgrade');
music_chat_fixture_bootstrap_v1($pdo);

echo 'MUSIC_CHAT_INTENT_V1=PASS '.$checks." actual parser, provider and SQLite orchestration checks\n";
