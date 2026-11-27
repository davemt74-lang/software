<?php
declare(strict_types=1);

// Disposable database/auth adapters only. Principal boundaries, canonical track
// lookup, media bytes/MIME, YouTube storage, transactions and music services are real.
if(!defined('STONEFELLOW_ROOT'))define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-music-fixture-'.getmypid());
if(!function_exists('db')){function db(): ?PDO{return $GLOBALS['music_chat_fixture_pdo']??null;}}
if(!function_exists('current_user')){function current_user(): ?array{
    $pdo=db();if(!$pdo)return null;
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([(int)($GLOBALS['music_chat_fixture_user_id']??11)]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}}
if(!function_exists('has_permission')){function has_permission(string $permission,?array $user=null): bool{
    $user??=current_user();
    return $user&&!empty($user['is_active'])&&match($permission){
        'account.access'=>!empty($user['account_allowed']),
        'chat.access'=>!empty($user['chat_allowed']),
        default=>true,
    };
}}
if(!function_exists('table_exists')){function table_exists(string $table): bool{
    if(!preg_match('/\A[a-zA-Z0-9_]+\z/',$table))return false;
    try{return db()?->query('SELECT 1 FROM '.$table.' WHERE 1=0')!==false;}catch(PDOException){return false;}
}}
if(!function_exists('column_exists')){function column_exists(string $table,string $column): bool{
    if(!preg_match('/\A[a-zA-Z0-9_]+\z/',$table.$column))return false;
    try{return db()?->query('SELECT '.$column.' FROM '.$table.' WHERE 1=0')!==false;}catch(PDOException){return false;}
}}
if(!function_exists('can_view_visibility')){function can_view_visibility(string $visibility,?array $user=null): bool{
    return $visibility==='public'||($visibility==='members'&&($user??current_user())!==null);
}}
if(!function_exists('can_view_track')){function can_view_track(array $track,?array $user=null): bool{
    return can_view_visibility((string)($track['visibility']??'private'),$user)
        ||(int)($track['owner_user_id']??0)===(int)(($user??current_user())['id']??0);
}}
if(!function_exists('can_manage_track_production')){function can_manage_track_production(array $track,?array $user=null): bool{
    return (int)($track['owner_user_id']??0)===(int)(($user??current_user())['id']??0);
}}

require_once dirname(__DIR__,2).'/includes/functions.php';
require_once dirname(__DIR__,2).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__,2).'/includes/artist-workspace-v181.php';
require_once dirname(__DIR__,2).'/includes/participant-updates-v1.php';
require_once dirname(__DIR__,2).'/includes/music-chat-library-v1.php';

function music_chat_fixture_bootstrap_v1(PDO $pdo,int $userId=11): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    else{$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA busy_timeout=15000');}
    $GLOBALS['music_chat_fixture_pdo']=$pdo;$GLOBALS['music_chat_fixture_user_id']=$userId;
    $_SESSION['vp3_media_playback_secret_v1']??=str_repeat('a',64);
    $GLOBALS['config']['connections']['youtube']['api_key']='fixture-youtube-api-key-123456';
    if(!is_dir(STONEFELLOW_ROOT))mkdir(STONEFELLOW_ROOT,0700,true);
    if(!is_file(STONEFELLOW_ROOT.'/native.wav')){
        $samples=str_repeat(chr(128),480000);
        file_put_contents(STONEFELLOW_ROOT.'/native.wav','RIFF'.pack('V',36+strlen($samples)).'WAVEfmt '
            .pack('VvvVVvv',16,1,1,8000,8000,1,8).'data'.pack('V',strlen($samples)).$samples);
    }
}

function music_chat_fixture_schema_v1(PDO $pdo,bool $music=true): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $messageId=$mysql?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tables=[
        'users'=>'id INT UNSIGNED NOT NULL PRIMARY KEY,is_active INTEGER NOT NULL,role VARCHAR(30),display_name VARCHAR(120),chat_allowed INTEGER NOT NULL DEFAULT 1,account_allowed INTEGER NOT NULL DEFAULT 1',
        'settings'=>'setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT',
        'user_agents'=>'id BIGINT UNSIGNED NOT NULL PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,is_active INTEGER NOT NULL,display_name VARCHAR(120),agent_role VARCHAR(40)',
        'chat_conversations'=>'id BIGINT UNSIGNED NOT NULL PRIMARY KEY,user_id INT UNSIGNED NOT NULL,user_agent_id BIGINT UNSIGNED NULL,title VARCHAR(200),created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP',
        'chat_messages'=>'id '.$messageId.',conversation_id BIGINT UNSIGNED NOT NULL,user_id INT UNSIGNED NULL,role VARCHAR(30) NOT NULL,message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
        'tracks'=>'id INT UNSIGNED NOT NULL PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,workspace_id INT UNSIGNED NULL,title VARCHAR(190),album VARCHAR(190),genre VARCHAR(120),mood VARCHAR(120),audio_path VARCHAR(255),duration VARCHAR(20),is_published INTEGER NOT NULL,visibility VARCHAR(30),sort_order INTEGER NOT NULL DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP',
        'artist_workspaces_v181'=>'id INT UNSIGNED NOT NULL PRIMARY KEY,artist_user_id INT UNSIGNED NOT NULL',
        'user_profiles'=>'user_id INT UNSIGNED NOT NULL PRIMARY KEY,username VARCHAR(120),is_public INTEGER NOT NULL',
    ];
    foreach($tables as $name=>$columns)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$name.' ('.$columns.')'.$tail);
    agent_connection_youtube_consent_schema_v1($pdo);
    agent_connection_youtube_results_schema_v1($pdo);
    vp3_youtube_playback_install_v1($pdo);
    agent_connection_youtube_quota_schema_v1($pdo);
    if($music)vp3_music_chat_schema_v1($pdo);
}

function music_chat_fixture_seed_v1(PDO $pdo): void
{
    $pdo->exec("INSERT INTO users(id,is_active,role,display_name) VALUES(11,1,'member','Fixture Artist'),(22,1,'member','Foreign Artist');");
    $pdo->exec("INSERT INTO user_agents(id,owner_user_id,is_active,display_name,agent_role) VALUES(7,11,1,'Music Agent','personal'),(8,11,1,'Other Agent','personal'),(9,22,1,'Foreign Agent','personal')");
    $pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(42,11,7,'Music Chat'),(43,11,7,'Sibling Chat'),(44,11,8,'Other Agent Chat'),(45,11,NULL,'System Chat'),(52,22,9,'Foreign Chat')");
    $pdo->exec("INSERT INTO chat_messages(id,conversation_id,user_id,role,message) VALUES(101,42,NULL,'assistant','Music is ready'),(102,42,11,'user','Find music'),(103,43,NULL,'assistant','Sibling music'),(201,52,NULL,'assistant','Foreign music')");
    $pdo->exec("INSERT INTO artist_workspaces_v181 VALUES(14,11),(15,22)");
    $pdo->exec("INSERT INTO user_profiles VALUES(11,'fixture-artist',1),(22,'foreign-artist',0)");
    $q=$pdo->prepare('INSERT INTO tracks(id,owner_user_id,workspace_id,title,album,genre,mood,audio_path,duration,is_published,visibility) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([41,11,14,'Native Midnight','Night Walk','Ambient','calm','native.wav','1:00',1,'public']);
    $q->execute([42,11,14,'First Light','Night Walk','Ambient','calm','native.wav','1:00',1,'public']);
    $q->execute([43,22,15,'PRIVATE FOREIGN TITLE','Hidden Album','Ambient','calm','native.wav','1:00',1,'private']);
    $q->execute([44,11,14,'Unpublished Draft','Draft Album','Ambient','calm','native.wav','1:00',0,'private']);
    for($id=45;$id<=70;++$id)$q->execute([$id,11,14,'Fixture Song '.$id,'Catalog','Rock','upbeat','native.wav','1:00',1,'public']);
    $pdo->prepare('INSERT INTO agent_connection_youtube_consent_v1(owner_user_id,policy_version,accepted_at) VALUES(?,?,?)')->execute([11,VP3_YOUTUBE_CONSENT_POLICY_V1,time()]);
    $pdo->prepare('INSERT INTO vp3_youtube_history_settings_v1(owner_user_id,capture_key) VALUES(?,?)')->execute([11,str_repeat('c',32)]);
}

function music_chat_fixture_message_v1(PDO $pdo,int $cid=42,string $role='assistant'): int
{
    $pdo->prepare('INSERT INTO chat_messages(conversation_id,role,message) VALUES(?,?,?)')->execute([$cid,$role,'Preparing your music selection.']);
    return (int)$pdo->lastInsertId();
}

function music_chat_fixture_video_v1(int $n=1): array
{
    $id='vp3'.str_pad((string)$n,8,'0',STR_PAD_LEFT);
    return ['source'=>'youtube','source_id'=>$id,'expires_at'=>time()+600,
        'youtube_video'=>['video_id'=>$id,'title'=>'YouTube Song '.$n,'channel_title'=>'Fixture Channel',
            'thumbnail_url'=>'https://i.ytimg.com/vi/'.$id.'/mqdefault.jpg','made_for_kids'=>false,'music_category'=>true]];
}

function music_chat_fixture_provider_v1(array $ids,?callable $after=null): callable
{
    return static function(string $url,array $headers)use($ids,$after):array{
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);$requested=explode(',',(string)($query['id']??''));
        $items=[];
        foreach($requested as $id)if(in_array($id,$ids,true))$items[]=['id'=>$id,
            'snippet'=>['title'=>'Refreshed '.$id,'channelTitle'=>'Verified Channel','categoryId'=>'10'],
            'status'=>['privacyStatus'=>'public','embeddable'=>true,'madeForKids'=>false]];
        if($after)$after();
        return ['status'=>200,'json'=>['items'=>$items]];
    };
}
