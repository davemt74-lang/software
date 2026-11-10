<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-activity-media-v1.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';

function db(): PDO {return $GLOBALS['pdo'];}
function url(string $path): string {return $path;}
function has_permission(string $permission,array $user): bool
{
    return !empty($user['is_active'])&&!empty($user[$permission==='chat.access'?'chat_access':'account_access']);
}
function table_exists(string $table): bool
{
    $query=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}
function user_agent_get_v236(PDO $pdo,int $owner,int $id): ?array
{
    $query=$pdo->prepare('SELECT * FROM user_agents WHERE id=? AND owner_user_id=?');
    $query->execute([$id,$owner]);
    return $query->fetch(PDO::FETCH_ASSOC)?:null;
}
function get_track_by_id(int $id): ?array
{
    $query=db()->prepare('SELECT * FROM fixture_tracks WHERE id=?');
    $query->execute([$id]);
    return $query->fetch(PDO::FETCH_ASSOC)?:null;
}
function can_view_track(array $track,?array $user=null): bool
{
    return $user&&($track['visibility']==='public'||(int)$track['owner_user_id']===(int)$user['id']);
}
function check(bool $ok,string $why): void
{
    if(!$ok)throw new RuntimeException($why);
    $GLOBALS['checks']++;
}
function section(array $projection,string $id): array
{
    return array_values(array_filter($projection['sections'],static fn(array $row):bool=>$row['id']===$id))[0]['items'];
}

$checks=0;
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active INT,chat_access INT,account_access INT);
    INSERT INTO users VALUES (1,1,1,1),(2,1,1,1),(3,0,1,1);
    CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INT,is_active INT);
    INSERT INTO user_agents VALUES (7,1,1),(8,1,1),(9,2,1),(10,1,0);
    CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INT,user_agent_id INT,title TEXT);
    INSERT INTO chat_conversations VALUES (11,1,7,"One"),(12,1,7,"Another"),(13,1,8,"Other Agent"),(14,1,NULL,"System"),(21,2,9,"Other owner");
    CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INT,role TEXT,created_at TEXT);
    CREATE TABLE fixture_tracks(id INTEGER PRIMARY KEY,title TEXT,album TEXT,owner_user_id INT,visibility TEXT);
    INSERT INTO fixture_tracks VALUES (1,"Public song","Album",2,"public"),(2,"My song","Private album",1,"private"),(3,"Other private song","Hidden",2,"private"),(4,"Older song","Album",1,"public"),(5,"Fifth song","Album",1,"public");');
$owner=['id'=>1,'is_active'=>1,'chat_access'=>1,'account_access'=>1];
$empty=vp3_agent_activity_media_v1($pdo,$owner,7);
check(section($empty,'youtube')===[]&&section($empty,'songs')===[]&&$empty['coverage']['songs']==='unavailable',
    'An older installation returns bounded empty media coverage');

agent_connection_youtube_consent_schema_v1($pdo);
agent_connection_youtube_results_schema_v1($pdo);
vp3_youtube_playback_install_v1($pdo);
$now=time();
$pdo->prepare('INSERT INTO agent_connection_youtube_consent_v1 VALUES (?,?,?)')->execute([1,VP3_YOUTUBE_CONSENT_POLICY_V1,$now]);
$pdo->prepare('INSERT INTO agent_connection_youtube_consent_v1 VALUES (?,?,?)')->execute([2,VP3_YOUTUBE_CONSENT_POLICY_V1,$now]);
foreach([101=>[11,'Played source',6,'assistant'],102=>[12,'Newest research',5,'assistant'],103=>[11,'Older research',20,'assistant'],
    104=>[11,'Expired',1,'assistant'],105=>[13,'Wrong Agent',0,'assistant'],106=>[21,'Wrong owner',0,'assistant'],
    107=>[14,'System source',0,'assistant'],108=>[12,'User forged source',0,'user']] as $mid=>[$cid,$query,$age,$role]){
    $pdo->prepare('INSERT INTO chat_messages VALUES (?,?,?,?)')->execute([$mid,$cid,$role,gmdate('Y-m-d H:i:s',$now-$age)]);
    $uid=$mid===106?2:1;
    $payload=['query'=>$query,'expires_at'=>$mid===104?$now-1:$now+3600,
        'videos'=>[
            ['title'=>$query.' video','video_id'=>'abc_def-123','channel_title'=>'Example channel','made_for_kids'=>false],
            ['title'=>$query.' second video','video_id'=>'def_ghi-456','channel_title'=>'Example channel','made_for_kids'=>false],
            ['title'=>$query.' third video','video_id'=>'ghi_jkl-789','channel_title'=>'Example channel','made_for_kids'=>false],
        ]];
    if($role==='assistant')agent_connection_youtube_results_save_v1($pdo,$uid,$cid,$mid,$payload);
    else $pdo->prepare('INSERT INTO agent_connection_youtube_results_v1 VALUES (?,?,?)')->execute([$mid,$now+3600,json_encode($payload)]);
}
$pdo->prepare('INSERT INTO vp3_youtube_playback_v1 VALUES (?,?,?,?,?)')->execute([1,'00000000-0000-4000-8000-000000000001',101,'abc_def-123',$now-10]);
$pdo->prepare('INSERT INTO vp3_youtube_playback_v1 VALUES (?,?,?,?,?)')->execute([1,'00000000-0000-4000-8000-000000000002',101,'def_ghi-456',$now-2]);
$pdo->prepare('INSERT INTO vp3_youtube_playback_v1 VALUES (?,?,?,?,?)')->execute([1,'00000000-0000-4000-8000-000000000003',101,'ghi_jkl-789',$now-1]);
$pdo->exec('CREATE TABLE track_play_sessions(id INTEGER PRIMARY KEY,user_id INT,track_id INT,started_at TEXT,last_position_seconds REAL,
    duration_seconds REAL,listened_seconds REAL,qualified_play INT,completed INT)');
foreach([1=>[1,1,30],2=>[1,2,25],3=>[1,3,10],4=>[1,4,60],5=>[1,5,0],6=>[2,3,90]] as $id=>[$uid,$track,$position]){
    $pdo->prepare('INSERT INTO track_play_sessions VALUES (?,?,?,?,?,120,30,0,0)')->execute([$id,$uid,$track,gmdate('Y-m-d H:i:s',$now-$id),$position]);
}
$projection=vp3_agent_activity_media_v1($pdo,$owner,7);
$youtube=section($projection,'youtube');
$songs=section($projection,'songs');
check(array_column($youtube,'title')===['Played source third video','Newest research','Played source']&&count($songs)===3,
    'The latest two searches survive the three-item budget even after three videos from their results were played');
check($youtube[0]['actions'][0]===['kind'=>'youtube','label'=>'Review video','href'=>'/chat.php?agent=7&conversation_id=11',
    'conversation_id'=>11,'message_id'=>101,'video_id'=>'ghi_jkl-789'],
    'A played video retains exact owner Agent Chat, message, and video references');
check($youtube[1]['actions'][0]['conversation_id']===12&&$youtube[1]['actions'][0]['message_id']===102,
    'Searches across the same Agent Chats retain their exact source message');
check(array_column($songs,'title')===['Public song','My song','Older song']
    &&$songs[0]['actions'][0]['track_id']===1&&$songs[0]['actions'][1]['href']==='/track.php?id=1'
    &&!str_contains(json_encode($songs),'conversation_id'),
    'Song history preserves canonical ownerwide accessible tracks without invented Chat provenance');
check(section(vp3_agent_activity_media_v1($pdo,$owner,8),'songs')===$songs
    &&array_column(section(vp3_agent_activity_media_v1($pdo,$owner,8),'youtube'),'title')===['Wrong Agent']
    &&array_column(section(vp3_agent_activity_media_v1($pdo,$owner,0),'youtube'),'title')===['System source'],
    'Songs remain ownerwide while named and system Agent YouTube histories stay separate');
foreach([9,10] as $agent){
    $blocked=vp3_agent_activity_media_v1($pdo,$owner,$agent);
    check(section($blocked,'youtube')===[]&&section($blocked,'songs')===[],'Foreign and inactive Agents cannot read private media');
}
$pdo->exec('UPDATE users SET chat_access=0 WHERE id=1');
check(section(vp3_agent_activity_media_v1($pdo,$owner,7),'songs')===[],'The reader reloads revoked account permissions');
$pdo->exec('UPDATE users SET chat_access=1 WHERE id=1');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_id'=>3];
check(section(vp3_agent_activity_media_v1($pdo,$owner,7),'songs')===[],'Participant scope excludes owner media');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$pdo->exec('DELETE FROM agent_connection_youtube_consent_v1 WHERE owner_user_id=1');
$revoked=vp3_agent_activity_media_v1($pdo,$owner,7);
check(section($revoked,'youtube')===[]&&section($revoked,'songs')===$songs,
    'YouTube consent revocation clears video history without hiding the permitted player library');
echo 'AGENT_ACTIVITY_MEDIA=PASS ('.$checks." assertions; actual SQLite media readers)\n";
