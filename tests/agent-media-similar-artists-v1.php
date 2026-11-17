<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-media-similar-artists-v1.php';
require_once dirname(__DIR__).'/includes/agent-media-entertainment-youtube-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-youtube-playlist-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function url(string $path): string { return $path; }
function similar_check(bool $passed,string $label): void
{
    if(!$passed)throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}

$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
if($dsn===''){
    $pdo=new PDO('sqlite::memory:',null,null,$options);
    $pdo->exec('PRAGMA foreign_keys=ON');
}else{
    if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('Use the native MySQL fixture DSN.');
    $dsn=(string)preg_replace('/;dbname=[^;]*/','',$dsn);
    $login=getenv('VP3_TEST_MYSQL_USER')?:'root';$password=(string)getenv('VP3_TEST_MYSQL_PASS');
    $server=new PDO($dsn,$login,$password,$options);
    $database='vp3_similar_artists_'.bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static fn()=>$server->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
    $pdo=new PDO(rtrim($dsn,';').';dbname='.$database,$login,$password,$options+[PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("SET time_zone='+00:00'");
}
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(128) PRIMARY KEY,setting_value TEXT)');
$pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY)');
$pdo->exec('INSERT INTO users VALUES (1),(2)');
$pdo->exec('CREATE TABLE chat_conversations (id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,title TEXT)');
$pdo->exec("INSERT INTO chat_conversations VALUES (11,1,NULL,'Music'),(22,2,NULL,'Other owner')");
$pdo->exec('CREATE TABLE chat_messages (id BIGINT UNSIGNED PRIMARY KEY,conversation_id INTEGER,role VARCHAR(16),created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("INSERT INTO chat_messages(id,conversation_id,role) VALUES (101,11,'assistant'),(102,11,'assistant')");
agent_connection_youtube_consent_schema_v1($pdo);
agent_connection_youtube_results_schema_v1($pdo);
agent_connection_youtube_quota_schema_v1($pdo);
agent_connection_youtube_set_consent_v1($pdo,1,true);
vp3_youtube_research_save_v1($pdo,1,'artist',true);
putenv('VP3_YOUTUBE_API_KEY=fixture-youtube-similar-artists-key-123');

$video=static function(string $id,string $artist,string $channelSuffix=' - Topic',bool $music=true):array{
    return ['video_id'=>$id,'title'=>$artist.' - Evening lights','channel_title'=>$artist.$channelSuffix,'thumbnail_url'=>'',
        'made_for_kids'=>false,'music_category'=>$music,'public_context'=>[
            'title'=>$artist.' - Evening lights','channel'=>$artist.$channelSuffix,'description'=>'','music'=>$music]];
};
$seed=$video('seed0000001','Paper Lanterns');
$moon=$video('moon0000001','Moon Harbor');
$soft=$video('soft0000001','Soft Current','VEVO');
$sable=$video('sable000001','Sable','');
$repeat=$video('moon0000002','Moon-Harbor',' Official Artist Channel');
$review=$video('review00001','Paper Lanterns');
$review['public_context']['channel']='The Record Reviewer';
$nonMusic=$video('talk0000001','A podcast',' Official',false);
$kids=$video('kids0000001','Kids band');$kids['made_for_kids']=true;
$unverified=$moon;unset($unverified['public_context']);
$artistNames=static fn(array $videos):array=>array_column(vp3_media_similar_artist_results_v1('Paper Lanterns',$videos),'artist');
similar_check($artistNames([$seed,$moon,$repeat,$soft,$sable])===['Moon Harbor','Soft Current','Sable'],
    'Attribution accepts Topic, VEVO and matching title/channel credits, removes the seed and duplicate artists, and caps at three');
similar_check(vp3_media_similar_artist_results_v1('Paper-Lanterns',[$seed])===[],
    'Seed matching ignores spacing, punctuation and case');
similar_check($artistNames([$review,$nonMusic,$kids,$unverified])===[],
    'Review channels, non-music, child-directed and unverified videos do not become artist recommendations');
similar_check(vp3_media_similar_artist_results_v1('https://example.com',[$moon])===[]
    &&vp3_media_similar_artist_results_v1('Paper Lanterns',[null,'bad',['video_id'=>'moon0000001','public_context'=>'bad']])===[],
    'Invalid topics and malformed saved metadata fail closed without warnings');
similar_check(vp3_media_similar_artist_summary_v1([])===''
    &&str_contains(vp3_media_similar_artist_summary_v1(vp3_media_similar_artist_results_v1('Paper Lanterns',[$moon])),'comparison is a suggestion'),
    'Empty results produce no invented names and wording distinguishes search suggestions from proven musical similarity');

$providerVideos=[$seed,$moon,$soft];$requests=[];$authorized=0;
$http=static function(string $url,array $headers)use(&$requests,&$providerVideos):array{
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    $requests[]=$query;
    similar_check(!isset($query['key'])&&!str_contains($url,'fixture-youtube'),'Provider credentials remain in server headers');
    if(str_starts_with($url,VP3_YOUTUBE_SEARCH_ENDPOINT_V1.'?')){
        similar_check($query['q']==='music artists similar to Paper Lanterns'&&(int)$query['maxResults']===6,
            'Artist discovery performs one bounded search with the exact authorized seed');
        return ['status'=>200,'json'=>['items'=>array_map(static fn(array $v):array=>[
            'id'=>['kind'=>'youtube#video','videoId'=>$v['video_id']],
            'snippet'=>['title'=>$v['title'],'channelTitle'=>$v['channel_title']]],$providerVideos)]];
    }
    return ['status'=>200,'json'=>['items'=>array_map(static fn(array $v):array=>[
        'id'=>$v['video_id'],'snippet'=>['title'=>$v['title'],'channelTitle'=>$v['channel_title'],'categoryId'=>'10'],
        'status'=>['embeddable'=>true,'privacyStatus'=>'public','madeForKids'=>false]],$providerVideos)]];
};
$authorize=static function()use(&$authorized):void{++$authorized;};
$similar=vp3_media_similar_artist_search_v1($pdo,1,'Paper Lanterns',$authorize,$http);
similar_check($similar!==null&&count($requests)===2&&$authorized===2
    &&array_column($similar['similar_artists'],'artist')===['Moon Harbor','Soft Current']
    &&array_column($similar['videos'],'video_id')===[$moon['video_id'],$soft['video_id']],
    'The current grant is checked before and after provider work and every named artist has its exact public playable video');
similar_check((int)$pdo->query('SELECT attempts FROM agent_connection_youtube_usage_v1 WHERE owner_user_id=1')->fetchColumn()===1
    &&(int)$pdo->query('SELECT successes FROM agent_connection_youtube_usage_v1 WHERE owner_user_id=1')->fetchColumn()===1,
    'The new discovery search uses existing per-owner and site quota accounting');

$base=['query'=>'Paper Lanterns','suggestion_kind'=>'playlist','initiative_suggestion'=>true,'expires_at'=>time()+3600,
    'videos'=>[$seed,$video('seed0000002','Paper Lanterns'),$video('seed0000003','Paper Lanterns'),$video('seed0000004','Paper Lanterns')]];
$combined=vp3_media_similar_artist_attach_v1($base,$similar);
similar_check(count($combined['videos'])===5&&array_slice(array_column($combined['videos'],'video_id'),-2)===[$moon['video_id'],$soft['video_id']]
    &&$combined['expires_at']===$base['expires_at'],
    'Related tracks are inside the same bounded playable payload as the base playlist and never outlive its cache');
vp3_initiative_youtube_playlist_store_v1($pdo,1,501,$combined);
vp3_initiative_youtube_playlist_store_v1($pdo,1,502,$similar);
similar_check(vp3_initiative_youtube_playlist_load_v1($pdo,1,501)===$combined
    &&vp3_initiative_youtube_playlist_load_v1($pdo,1,502)===$similar
    &&vp3_initiative_youtube_playlist_load_v1($pdo,2,501)===null,
    'Pending combined and standalone recommendations round-trip with exact IDs and owner isolation');

agent_connection_youtube_results_save_v1($pdo,1,11,101,$combined);
$stored=agent_connection_youtube_result_records_v1($pdo,1,0,11,1,[101])[0]['results'];
similar_check($stored===$combined&&array_column(agent_connection_youtube_ordered_videos_v1($stored['videos']),'video_id')
    ===array_column($combined['videos'],'video_id'),
    'Chat persistence and ordinal recall retain every related track in the canonical result list');
$direct=$similar;unset($direct['initiative_suggestion']);
agent_connection_youtube_results_save_v1($pdo,1,11,102,$direct);
$finding=vp3_media_youtube_saved_searches_v1($pdo,1,0,11,[102],time())['items']['media:youtube:102'];
similar_check($finding['kind']==='similar_artists'&&str_contains($finding['summary'],'Moon Harbor')
    &&str_contains($finding['summary'],'Soft Current')&&$finding['playback']['selected_video_id']===$moon['video_id'],
    'Media findings include the actual related names and select a related track for the existing Chat action');

vp3_youtube_research_save_v1($pdo,1,'artist',false);
$baseOnly=vp3_initiative_youtube_playlist_load_v1($pdo,1,501);
$before=count($requests);
similar_check($baseOnly!==null&&count($baseOnly['videos'])===3&&!isset($baseOnly['similar_artists'])
    &&vp3_initiative_youtube_playlist_load_v1($pdo,1,502)===null
    &&vp3_media_similar_artist_search_v1($pdo,1,'Paper Lanterns',$authorize,$http)===null&&count($requests)===$before,
    'Revoking artist research removes pending related tracks while retaining the original playlist and prevents fresh searches');
vp3_youtube_research_save_v1($pdo,1,'artist',true);
$failed=static fn(string $url,array $headers):array=>['status'=>503,'json'=>[]];
similar_check(vp3_media_similar_artist_search_v1($pdo,1,'Paper Lanterns',$authorize,$failed)===null,
    'A provider failure leaves optional recommendations empty without failing the sourced report');
$providerVideos=[$seed];
similar_check(vp3_media_similar_artist_search_v1($pdo,1,'Paper Lanterns',$authorize,$http)===null,
    'A seed-only provider response does not fabricate similar artists');
$before=count($requests);
similar_check(vp3_media_similar_artist_search_v1($pdo,2,'Paper Lanterns',$authorize,$http)===null&&count($requests)===$before,
    'An owner without YouTube consent cannot trigger the additional public search');
$pdo->exec('UPDATE agent_connection_youtube_usage_v1 SET attempts='.VP3_YOUTUBE_MEMBER_SEARCH_LIMIT_V1.' WHERE owner_user_id=1');
similar_check(vp3_media_similar_artist_search_v1($pdo,1,'Paper Lanterns',$authorize,$http)===null&&count($requests)===$before,
    'An exhausted daily quota stops the provider call');
echo "MEDIA_SIMILAR_ARTISTS_V1=PASS\n";
