<?php
declare(strict_types=1);

/**
 * Disposable HTTP/bootstrap boundary. Music storage, intent selection, action
 * signing, source authority, native media/receipts and YouTube history are the
 * production implementations. External YouTube search is explicitly simulated.
 */
require_once __DIR__.'/music-chat-library-v1.php';
require_once dirname(__DIR__,2).'/includes/music-chat-v1.php';
require_once dirname(__DIR__,2).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__,2).'/includes/music-listening-v1.php';
require_once dirname(__DIR__,2).'/includes/notifications.php';

class MusicChatHttpPDO extends PDO
{
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $query=str_replace([' FOR UPDATE','INSERT IGNORE INTO','COALESCE(user_id,0)=?','DATE_SUB(NOW(), INTERVAL ? DAY)'],
            ['','INSERT OR IGNORE INTO','COALESCE(user_id,0)=CAST(? AS INTEGER)',"datetime(NOW(), '-' || ? || ' days')"],$query);
        return parent::prepare($query,$options);
    }
}

function music_chat_http_bootstrap_v1(): void
{
    $pdo=new MusicChatHttpPDO('sqlite:'.STONEFELLOW_ROOT.'/data.sqlite');
    $pdo->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));
    $GLOBALS['config']=['site'=>['base_path'=>'','base_url'=>is_file(STONEFELLOW_ROOT.'/fixture-origin.txt')
        ?trim((string)file_get_contents(STONEFELLOW_ROOT.'/fixture-origin.txt')):'http://127.0.0.1'],'uploads'=>[]];
    music_chat_fixture_bootstrap_v1($pdo,(int)($_SERVER['HTTP_X_USER']??11));
    if(PHP_SAPI!=='cli'){
        $raw=json_decode((string)file_get_contents('php://input'),true);
        $input=is_array($raw)?$raw:$_POST;
        $log=['path'=>parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),'action'=>$input['action']??$_GET['action']??'',
            'index'=>$input['index']??null,'result_id'=>$input['result_id']??null,'playlist_id'=>$input['playlist_id']??null,
            'automatic'=>isset($input['auto_action_id']),'user'=>(int)($_SERVER['HTTP_X_USER']??11)];
        file_put_contents(STONEFELLOW_ROOT.'/fixture-requests.jsonl',json_encode($log,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    }
}

function music_chat_http_install_v1(): void
{
    $pdo=db();
    music_chat_fixture_schema_v1($pdo);
    music_chat_fixture_seed_v1($pdo);
    // Existing native ledger tables. The additive production receipt migration
    // owns all versioned session/event columns and acceptance rules.
    $pdo->exec("CREATE TABLE track_play_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,session_token CHAR(64) UNIQUE,track_id INTEGER NOT NULL,user_id INTEGER,
        listener_hash CHAR(64) NOT NULL,device_type VARCHAR(30) DEFAULT 'unknown',referrer_host VARCHAR(190) DEFAULT '',
        source_context VARCHAR(30) DEFAULT 'player',duration_seconds REAL DEFAULT 0,last_position_seconds REAL DEFAULT 0,
        max_position_seconds REAL DEFAULT 0,listener_city VARCHAR(120) DEFAULT '',listener_region VARCHAR(120) DEFAULT '',
        listener_country VARCHAR(80) DEFAULT '',listener_latitude REAL,listener_longitude REAL,
        started_at TEXT DEFAULT CURRENT_TIMESTAMP,last_event_at TEXT DEFAULT CURRENT_TIMESTAMP,ended_at TEXT,
        listened_seconds REAL DEFAULT 0,completion_percent REAL DEFAULT 0,qualified_play INTEGER DEFAULT 0,completed INTEGER DEFAULT 0,
        FOREIGN KEY(track_id) REFERENCES tracks(id),FOREIGN KEY(user_id) REFERENCES users(id));
        CREATE TABLE track_play_events (id INTEGER PRIMARY KEY AUTOINCREMENT,session_id INTEGER NOT NULL,event_type VARCHAR(30),
            position_seconds REAL DEFAULT 0,listened_delta_seconds REAL DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(session_id) REFERENCES track_play_sessions(id));
        CREATE TABLE track_favorites(user_id INTEGER,track_id INTEGER,PRIMARY KEY(user_id,track_id));
        CREATE TABLE playlists(id INTEGER PRIMARY KEY,owner_user_id INTEGER,artist_workspace_id INTEGER,title TEXT,description TEXT,visibility TEXT,updated_at TEXT);
        CREATE TABLE playlist_tracks(playlist_id INTEGER,track_id INTEGER,sort_order INTEGER DEFAULT 0,added_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,title TEXT,body TEXT,target_url TEXT,
            source_type TEXT,source_id INTEGER,is_read INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE user_data_policies(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,
            audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
        CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT,
            PRIMARY KEY(agent_id,resource_type,resource_id))");
    music_listening_v1_install($pdo);
    file_put_contents(STONEFELLOW_ROOT.'/fixture-state.json','{}');
}

function music_chat_http_reply_v1(array $data,int $status=200): never
{
    header('Content-Type: application/json');header('Cache-Control: no-store');
    http_response_code($status);echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;
}

function music_chat_http_input_v1(): array
{
    $input=json_decode((string)file_get_contents('php://input'),true);
    return is_array($input)?$input:$_POST;
}

function music_chat_http_selection_v1(array $input): array
{
    $pdo=db();$user=current_user();$agent=(int)($input['agent_id']??7);$cid=(int)($input['conversation_id']??42);
    if(!$user)throw new RuntimeException('Sign in.',401);
    $items=$input['items']??[['source'=>'vp3','source_id'=>'41'],music_chat_fixture_video_v1(),['source'=>'vp3','source_id'=>'42']];
    foreach($items as &$item){
        if(($item['source']??'')==='youtube'&&!isset($item['youtube_video'])){
            $id=(string)($item['source_id']??'vp300000001');
            $item+=['expires_at'=>time()+600,'youtube_video'=>['video_id'=>$id,'title'=>$item['title']??'YouTube Song 1',
                'channel_title'=>'Fixture Channel','thumbnail_url'=>'https://i.ytimg.com/vi/'.$id.'/mqdefault.jpg',
                'made_for_kids'=>false,'music_category'=>true]];
        }
    }unset($item);
    $key=(string)($input['request_key']??bin2hex(random_bytes(16)));
    $meta=['intent'=>$input['intent']??(!empty($input['playlist'])?'create_playlist':(!empty($input['autoplay'])?'play':'show')),
        'source'=>$input['source']??'both','requested_count'=>$input['requested_count']??max(1,count($items)),
        'query'=>$input['query']??'fixture music','title'=>$input['title']??'Night Walk',
        'coverage'=>[],'autoplay'=>($input['autoplay']??false)===true,'create_playlist'=>($input['playlist']??false)===true];
    $hash=$input['request_hash']??hash('sha256',json_encode([$input['items']??null,$meta],JSON_THROW_ON_ERROR));
    $payload=vp3_music_chat_result_store_v1($pdo,$user,$agent,$cid,$key,$hash,$items,$meta);
    $q=$pdo->prepare('SELECT assistant_message_id FROM music_chat_results_v1 WHERE result_id=?');$q->execute([$payload['result_id']]);
    $message=(int)$q->fetchColumn();
    if($message<1){$message=music_chat_fixture_message_v1($pdo,$cid);$payload=vp3_music_chat_result_bind_v1($pdo,$user,$agent,$cid,$payload['result_id'],$message);}
    $pdo->prepare('UPDATE chat_messages SET message=?,context_json=? WHERE id=?')->execute(['Your music selection is ready.',
        json_encode(['music_chat_v1'=>vp3_music_chat_result_reference_v1($payload)],JSON_THROW_ON_ERROR),$message]);
    return ['ok'=>true,'music_chat_v1'=>$payload,'assistant_message_id'=>$message];
}

function music_chat_http_provider_v1(string $url,array $headers): array
{
    $state=json_decode((string)file_get_contents(STONEFELLOW_ROOT.'/fixture-state.json'),true)?:[];
    if(($state['provider']??'')==='quota')return ['status'=>403,'json'=>['error'=>['errors'=>[['reason'=>'quotaExceeded']]]]];
    if(($state['provider']??'')==='unavailable')throw new RuntimeException('Simulated YouTube transport failure.');
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    $items=[];
    if(str_contains((string)parse_url($url,PHP_URL_PATH),'/search')){
        for($i=1;$i<=min(25,(int)($query['maxResults']??5));++$i){
            $video=music_chat_fixture_video_v1($i)['youtube_video'];
            $items[]=['id'=>['kind'=>'youtube#video','videoId'=>$video['video_id']],
                'snippet'=>['title'=>$video['title'],'channelTitle'=>$video['channel_title'],'thumbnails'=>['medium'=>['url'=>$video['thumbnail_url']]]]];
        }
    }else foreach(explode(',',(string)($query['id']??'')) as $id){
        if($id==='')continue;
        $number=(int)substr($id,3);
        $items[]=['id'=>$id,'snippet'=>['title'=>'YouTube Song '.$number,'channelTitle'=>'Fixture Channel','categoryId'=>'10',
                'thumbnails'=>['medium'=>['url'=>'https://i.ytimg.com/vi/'.$id.'/mqdefault.jpg']]],
            'status'=>['privacyStatus'=>'public','embeddable'=>true,'madeForKids'=>false]];
    }
    return ['status'=>200,'json'=>['items'=>$items]];
}

function music_chat_http_route_v1(): never
{
    try{
        $input=music_chat_http_input_v1();$action=$input['action']??$_GET['action']??'';
        if($action==='session')music_chat_http_reply_v1(['ok'=>true,'csrf_token'=>csrf_token()]);
        if($action==='seed')music_chat_http_reply_v1(music_chat_http_selection_v1($input));
        if($action==='control'){
            $state=json_decode((string)file_get_contents(STONEFELLOW_ROOT.'/fixture-state.json'),true)?:[];
            foreach(['provider'] as $field)if(isset($input[$field]))$state[$field]=$input[$field];
            foreach(['user_active'=>['users','is_active',11],'account_allowed'=>['users','account_allowed',11],
                'chat_allowed'=>['users','chat_allowed',11],'agent_active'=>['user_agents','is_active',7],
                'track_published'=>['tracks','is_published',(int)($input['track_id']??41)]] as $field=>[$table,$column,$id]){
                if(array_key_exists($field,$input))db()->prepare('UPDATE '.$table.' SET '.$column.'=? WHERE id=?')->execute([(int)(bool)$input[$field],$id]);
            }
            if(array_key_exists('consent',$input))agent_connection_youtube_set_consent_v1(db(),11,(bool)$input['consent']);
            file_put_contents(STONEFELLOW_ROOT.'/fixture-state.json',json_encode($state,JSON_THROW_ON_ERROR));
            music_chat_http_reply_v1(['ok'=>true,'state'=>$state]);
        }
        music_chat_http_reply_v1(['ok'=>false,'error'=>'Unknown fixture action.'],404);
    }catch(Throwable $error){music_chat_http_reply_v1(['ok'=>false,'error'=>$error->getMessage()],
        in_array($error->getCode(),[401,403,404,409,410,422,503],true)?$error->getCode():500);}
}

/** Actual bounded intent/store/bind path. This fixture is not an upstream LLM test. */
function music_chat_http_chat_v1(): never
{
    try{
        $input=music_chat_http_input_v1();$user=current_user();
        if(!$user)throw new RuntimeException('Sign in.',401);
        if(!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))throw new RuntimeException('Session expired.',419);
        $pdo=db();$cid=(int)($input['conversation_id']??42);$agent=(int)($input['agent_id']??$input['agent_context']['user_agent_id']??7);
        [$user,$principal]=vp3_media_playback_user_v1($pdo,$user,$agent,$cid);
        switch($input['action']??''){
            case 'load':case 'messages_after':
                $q=$pdo->prepare('SELECT id,role,message,context_json,created_at FROM chat_messages WHERE conversation_id=? ORDER BY id');$q->execute([$cid]);
                music_chat_http_reply_v1(['ok'=>true,'conversation'=>['id'=>$cid],'conversation_id'=>$cid,'messages'=>$q->fetchAll()]);
            case 'list':music_chat_http_reply_v1(['ok'=>true,'conversations'=>[]]);
            case 'send':
                $query=(string)($input['message']??'');
                $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,?,'user',?)")->execute([$cid,(int)$user['id'],$query]);
                $userMessage=(int)$pdo->lastInsertId();
                $tool=vp3_music_chat_execute_v1($pdo,$user,$principal,$cid,$userMessage,$query,$input,null,'music_chat_http_provider_v1');
                if($tool===null)$tool=['answer'=>'Ask for a song or a playlist.'];
                $message=(int)($tool['music_chat_v1']['source_message_id']??0);
                if($message<1){
                    $message=music_chat_fixture_message_v1($pdo,$cid);
                    $tool=vp3_music_chat_bind_reply_v1($pdo,$user,$principal,$cid,$message,$tool);
                }
                $context=isset($tool['music_chat_v1'])?['music_chat_v1'=>vp3_music_chat_result_reference_v1($tool['music_chat_v1'])]:[];
                $pdo->prepare('UPDATE chat_messages SET message=?,context_json=? WHERE id=?')->execute([$tool['answer'],json_encode($context,JSON_THROW_ON_ERROR),$message]);
                music_chat_http_reply_v1(['ok'=>true,'conversation_id'=>$cid,'agent_id'=>$agent,'user_message_id'=>$userMessage,'assistant_message_id'=>$message]+$tool);
            default:music_chat_http_reply_v1(['ok'=>true,'items'=>[],'updates'=>[],'conversations'=>[]]);
        }
    }catch(Throwable $error){music_chat_http_reply_v1(['ok'=>false,'error'=>$error->getMessage()],
        in_array($error->getCode(),[401,403,404,409,410,419,422,503],true)?$error->getCode():500);}
}

function music_chat_http_page_v1(): void
{
    $template=(string)file_get_contents(STONEFELLOW_ROOT.'/chat-legacy-v108.php');
    $start=strpos($template,'<section'."\n".'        class="chat-now-playing"');
    $end=strpos($template,'<form class="chat-composer"');
    $nowPlaying=$start!==false&&$end!==false?substr($template,$start,$end-$start):'';
    $cid=(int)($_GET['conversation_id']??42);$agent=(int)($_GET['agent_id']??7);$owner=(int)($_SERVER['HTTP_X_USER']??11);
    $config=['userId'=>$owner,'agentId'=>$agent,'endpoint'=>'/api/chat.php','playbackEndpoint'=>'/api/playback.php',
        'libraryEndpoint'=>'/api/player-library-v76.php','csrf'=>csrf_token(),'initialConversationId'=>$cid,'initialView'=>'chat'];
    $music=['endpoint'=>'/api/music-chat-v1.php','csrf'=>csrf_token(),'userId'=>$owner,'agentId'=>$agent];
    $youtube=['endpoint'=>'/api/chat-youtube-playback-v1.php','csrf'=>csrf_token(),
        'captureKey'=>vp3_youtube_capture_key_v1(db(),$owner),'resumeCapture'=>true,'watchCapture'=>true,'connections'=>'/connections.php'];
    header('Content-Type: text/html; charset=UTF-8');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Music Chat acceptance fixture</title><link rel="stylesheet" href="/chat.css"><link rel="stylesheet" href="/chat-youtube-v1.css"><link rel="stylesheet" href="/assets/css/music-chat-v1.css">
    <style>body{margin:0;background:#f4f6f9;font-family:Arial,sans-serif}.fixture-top{padding:18px 20px;border-bottom:1px solid #d9e1e8;background:#fff;font-weight:700}.chat-app{min-height:100vh;grid-template-columns:minmax(0,1fr)}.chat-main{min-width:0}#chatForm{display:flex;gap:8px;padding:12px}#chatInput{flex:1;min-width:0;min-height:48px}#sendChatButton{min-height:48px}.fixture-label{font-size:12px;color:#526174;font-weight:400}</style></head><body>
    <nav hidden aria-label="Fixture chat history"><button id="newChatButton" type="button">New chat</button><div id="chatHistory" class="chat-history"></div></nav>
    <div class="chat-app"><main class="chat-main"><header class="fixture-top">Media &amp; Entertainment <span class="fixture-label">VP3 + YouTube</span></header>
    <section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section>
    <div class="chat-composer-shell" id="chatComposerShell"><?= $nowPlaying ?><form class="chat-composer" id="chatForm"><textarea id="chatInput" aria-label="Message"></textarea><button id="sendChatButton">Send</button></form></div>
    </main></div><div id="chatQueueDrawer" hidden><div id="chatQueueList"></div></div>
    <script>window.STONEFELLOW_CHAT=<?= json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP) ?>;window.STONEFELLOW_PLAYER={tracks:{},albums:{},playlists:{}};
    window.VP3_MUSIC_CHAT_CONFIG_V1=<?= json_encode($music,JSON_HEX_TAG|JSON_HEX_AMP) ?>;window.VP3_YOUTUBE_PLAYBACK_V1=<?= json_encode($youtube,JSON_HEX_TAG|JSON_HEX_AMP) ?>;</script>
    <script src="/assets/js/music-listening-v1.js"></script><script src="/chat-youtube-v1.js"></script><script src="/assets/js/music-chat-v1.js"></script><script src="/chat.js"></script>
    </body></html><?php
}
