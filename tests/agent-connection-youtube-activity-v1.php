<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-youtube-activity-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-artist-v1.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function url(string $path): string { return $path; }
function has_permission(string $permission, array $user): bool
{
    return $permission === 'account.access' && !empty($user['is_active']);
}
function youtube_activity_check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(128) PRIMARY KEY, setting_value TEXT)');
$pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, is_active INT NOT NULL)');
$pdo->exec('INSERT INTO users VALUES (1,1),(2,1)');
$pdo->exec('CREATE TABLE chat_conversations (id INTEGER PRIMARY KEY, user_id INTEGER, user_agent_id INTEGER, title TEXT)');
$pdo->exec('INSERT INTO chat_conversations (id,user_id,user_agent_id) VALUES (11,1,NULL),(12,1,7),(13,1,7),(14,1,8),(21,2,7)');
$pdo->exec('CREATE TABLE chat_messages (id INTEGER PRIMARY KEY, conversation_id INTEGER, role TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("INSERT INTO chat_messages (id,conversation_id,role) VALUES
    (101,11,'assistant'),(102,12,'assistant'),(103,13,'assistant'),(104,14,'assistant'),(105,21,'assistant'),
    (106,12,'assistant'),(107,12,'user')");
$owner = ['id'=>1,'is_active'=>1];
$other = ['id'=>2,'is_active'=>1];
$now = time();
$checks = 0;
youtube_activity_check(vp3_youtube_activity_v1($pdo, $owner, 7) === null, 'An install without the YouTube schema has no private History projection');
agent_connection_youtube_consent_schema_v1($pdo);
agent_connection_youtube_results_schema_v1($pdo);
$consent = $pdo->prepare('INSERT INTO agent_connection_youtube_consent_v1 VALUES (?, ?, ?)');
$consent->execute([1, VP3_YOUTUBE_CONSENT_POLICY_V1, $now]);
$consent->execute([2, VP3_YOUTUBE_CONSENT_POLICY_V1, $now]);
$videoId = 'abc_def-123';
foreach ([101=>[11,1,'System'],102=>[12,1,'Chosen Agent'],103=>[13,1,'Same Agent elsewhere'],
          104=>[14,1,'Another Agent'],105=>[21,2,'Another owner'],106=>[12,1,'Expired']] as $message=>[$conversation,$uid,$title]) {
    agent_connection_youtube_results_save_v1($pdo, $uid, $conversation, $message, [
        'query'=>$title.' search','expires_at'=>$message === 106 ? $now-5 : $now+3600,
        'videos'=>[
            ['title'=>$title,'channel_title'=>'Example channel','video_id'=>$videoId,'made_for_kids'=>false,'thumbnail_url'=>'secret fixture'],
            ['title'=>$title.' unplayed','channel_title'=>'Another channel','video_id'=>'def_ghi-456','made_for_kids'=>false],
        ],
    ]);
}
// Even a corrupt legacy result on a user-authored turn cannot enter the projection.
$pdo->prepare('INSERT INTO agent_connection_youtube_results_v1 VALUES (107,?,?)')->execute([
    $now+3600,json_encode(['videos'=>[['title'=>'User-authored fake result']]], JSON_THROW_ON_ERROR),
]);
$history = vp3_youtube_activity_v1($pdo, $owner, 7);
youtube_activity_check(array_column($history['searches'], 'query') === ['Same Agent elsewhere search','Chosen Agent search']
    && $history['plays'] === [] && !$history['playback_ready'], 'Searches stay visible across that Agent’s Chats before playback upgrade and do not count as plays');
youtube_activity_check($history['searches'][1]['videos'][0] === ['title'=>'Chosen Agent','channel'=>'Example channel']
    && $history['searches'][1]['chat_url'] === '/chat.php?agent=7&conversation_id=12',
    'The result projection contains display text and the exact local Chat source, without cache internals');
youtube_activity_check(array_column(vp3_youtube_activity_v1($pdo, $owner, 0)['searches'], 'query') === ['System search']
    && array_column(vp3_youtube_activity_v1($pdo, $owner, 8)['searches'], 'query') === ['Another Agent search']
    && array_column(vp3_youtube_activity_v1($pdo, $other, 7)['searches'], 'query') === ['Another owner search'],
    'System, named Agent, and owner scopes stay separate');

$pdo->beginTransaction();
try {
    $source = $pdo->prepare("INSERT INTO chat_messages (id,conversation_id,role,created_at) VALUES (?,?,'assistant',?)");
    foreach ([108=>[12,1,'Similar',0],109=>[13,1,'Similar',0],110=>[14,1,'Other Agent',0],
        111=>[21,2,'Other owner',0],112=>[12,1,'Old',86401]] as $id=>[$cid,$uid,$artist,$age]) {
        $source->execute([$id,$cid,gmdate('Y-m-d H:i:s',$now-$age)]);
        agent_connection_youtube_results_save_v1($pdo,$uid,$cid,$id,[
            'query'=>'Artists similar to '.$artist,'suggestion_kind'=>'similar','taste_topic'=>$artist,'expires_at'=>$now+3600,
            'videos'=>[['title'=>'Provider metadata','video_id'=>$videoId]],
        ]);
    }
    $scope=['user'=>$owner,'agent_id'=>7];
    $at=new DateTimeImmutable('@'.$now);
    $coverage=[];
    youtube_activity_check(vp3_initiative_artist_evidence_v1($pdo,$scope,$at,null,$coverage)===[],
        'YouTube history becomes background research evidence only after a separate opt-in');
    vp3_youtube_artist_research_save_v1($pdo,1,true);
    $interest=vp3_initiative_artist_evidence_v1($pdo,$scope,$at,null,$coverage);
    $ref=array_key_first($interest);
    youtube_activity_check($coverage['artist_research']==='ready'&&array_column($interest,'title')===['Similar']
        &&$interest[$ref]['url']==='/chat.php?agent=7&conversation_id=13'
        &&!str_contains(json_encode($interest,JSON_THROW_ON_ERROR),'Provider metadata'),
        'Initiative reads only recent owner-entered artist interests and collapses repeated searches across that Agent’s Chats');
    youtube_activity_check(vp3_initiative_artist_evidence_v1($pdo,$scope,$at,[$ref],$coverage)===$interest
        &&vp3_initiative_artist_evidence_v1($pdo,$scope,$at,['calendar:1'],$coverage)===[],
        'Selected artist evidence rechecks the exact interest and skips unrelated capability reads');
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_id'=>3];
    youtube_activity_check(vp3_initiative_artist_evidence_v1($pdo,$scope,$at,null,$coverage)===[],
        'Participant scope cannot turn private artist history into background research');
    unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
    $pdo->exec('DELETE FROM agent_connection_youtube_consent_v1 WHERE owner_user_id=1');
    youtube_activity_check(vp3_initiative_artist_evidence_v1($pdo,$scope,$at,[$ref],$coverage)===[],
        'Revoking the YouTube skill removes its Initiative research evidence');
} finally {
    $pdo->rollBack();
}

vp3_youtube_playback_install_v1($pdo);
$history = vp3_youtube_activity_v1($pdo, $owner, 7);
youtube_activity_check($history['playback_ready'] && !$history['capture_enabled'] && $history['plays'] === [],
    'Having search results and an installed playback ledger does not opt the owner into capture');
vp3_youtube_history_change_v1($pdo, 1, 'enable_history');
vp3_youtube_history_change_v1($pdo, 2, 'enable_history');
foreach ([101=>1,102=>1,103=>1,104=>1,105=>2] as $message=>$uid) {
    $key = vp3_youtube_capture_key_v1($pdo, $uid);
    $play = sprintf('00000000-0000-4000-8000-%012d', $message);
    if (!vp3_youtube_history_record_v1($pdo, $uid, $message, $videoId, $play, $key)) {
        throw new RuntimeException('Could not seed confirmed fixture playback.');
    }
}
$history = vp3_youtube_activity_v1($pdo, $owner, 7);
youtube_activity_check($history['capture_enabled'] && array_column($history['plays'], 'title') === ['Same Agent elsewhere','Chosen Agent']
    && $history['plays'][1]['played_at'] >= $now && $history['plays'][1]['chat_url'] === '/chat.php?agent=7&conversation_id=12'
    && !str_contains(json_encode($history['plays'], JSON_THROW_ON_ERROR), 'unplayed'),
    'Only confirmed starts appear in Played in VP3, with time and source Chat');
youtube_activity_check(array_column(vp3_youtube_history_recent_v1($pdo, 1, 7, 12), 'title') === ['Chosen Agent']
    && vp3_youtube_history_recent_v1($pdo, 1, 7, 14) === []
    && vp3_youtube_history_recent_v1($pdo, 1, 7, 21) === [],
    'The shared playback reader can narrow further to the exact owner, Agent, and Chat');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1'] = ['participant_id'=>3];
youtube_activity_check(vp3_youtube_activity_v1($pdo, $owner, 7) === null, 'A participant cannot read the owner’s YouTube projection');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$pdo->exec("INSERT INTO settings VALUES ('connections_youtube_enabled','0')");
youtube_activity_check(vp3_youtube_activity_v1($pdo, $owner, 7) === null
    && vp3_youtube_activity_v1($pdo, ['id'=>1,'is_active'=>0], 7) === null,
    'Provider and current account restrictions both hide private YouTube History');
$pdo->exec('DELETE FROM settings');
vp3_youtube_history_change_v1($pdo, 1, 'pause_history');
$history = vp3_youtube_activity_v1($pdo, $owner, 7);
youtube_activity_check(!$history['capture_enabled'] && count($history['plays']) === 2,
    'Pausing capture preserves recent existing starts for the owner');
vp3_youtube_history_change_v1($pdo, 1, 'remove_history', sprintf('00000000-0000-4000-8000-%012d',102));
$pdo->exec('UPDATE agent_connection_youtube_results_v1 SET expires_at=0 WHERE message_id=103');
$history = vp3_youtube_activity_v1($pdo, $owner, 7);
youtube_activity_check($history['plays'] === [] && array_column($history['searches'], 'query') === ['Chosen Agent search'],
    'Removed plays and expired result records disappear independently');
$pdo->exec('DELETE FROM agent_connection_youtube_consent_v1 WHERE owner_user_id=1');
youtube_activity_check(vp3_youtube_activity_v1($pdo, $owner, 7) === null
    && array_column(vp3_youtube_activity_v1($pdo, $other, 7)['plays'], 'title') === ['Another owner'],
    'Revocation hides both ledgers without affecting the other member');
echo "YOUTUBE_ACTIVITY_V1=PASS ({$checks} checks)".PHP_EOL;
