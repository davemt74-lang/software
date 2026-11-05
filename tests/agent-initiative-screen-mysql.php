<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/agent-memory-scope-v410.php';
require_once __DIR__.'/../includes/agent-initiative-screen-v1.php';

function has_permission(string $permission, array $user): bool { return $permission === 'chat.access'; }
function personal_knowledge_available(array $user): bool { return true; }
function url(string $path): string { return $path; }
function table_exists(string $table): bool
{
    $stmt = $GLOBALS['pdo']->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$dsn = (string)getenv('VP3_SECTION7_MYSQL_DSN');
if (!str_starts_with($dsn, 'mysql:')) throw new RuntimeException('An explicit MySQL screen evidence fixture DSN is required.');
$password = (string)getenv('VP3_SECTION7_MYSQL_PASSWORD');
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false];
$pdo = new PDO($dsn, 'root', $password, $options);
$database = 'initiative_screen_'.bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE `'.$database.'`');

try {
    $pdo->exec('USE `'.$database.'`');
    screen_sessions_schema_v1($pdo);
    screen_insights_schema_v1($pdo);
    $pdo->exec('CREATE TABLE artist_transcript_folders_v177 (
        id INT UNSIGNED PRIMARY KEY, created_by_user_id INT UNSIGNED NOT NULL, folder_name VARCHAR(80) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE knowledge_items (
        id BIGINT UNSIGNED PRIMARY KEY, created_by_user_id INT UNSIGNED NOT NULL, folder_id INT UNSIGNED NOT NULL,
        knowledge_scope VARCHAR(20) NOT NULL, file_type VARCHAR(40) NOT NULL, file_name VARCHAR(190) NOT NULL
    ) ENGINE=InnoDB');

    $now = new DateTimeImmutable('2030-01-03T12:00:00Z');
    $sessionId = static fn(int $id): string => sprintf('00000000-0000-4000-8000-%012d', $id);
    $owner = ['id'=>7];
    $otherOwner = ['id'=>8];
    $folder = $pdo->prepare('INSERT INTO artist_transcript_folders_v177 (id,created_by_user_id,folder_name) VALUES (?,?,?)');
    $session = $pdo->prepare("INSERT INTO agent_screen_sessions_v1
        (owner_user_id,session_id,folder_id,agent_id,title,status,created_at,updated_at) VALUES (?,?,?,?,?,'ended',?,?)");
    foreach ([[1,7,0], [2,7,0], [3,7,0], [4,8,0], [5,7,31]] as [$id,$uid,$agent]) {
        $folder->execute([$id,$uid,'Folder '.$id]);
        $session->execute([$uid,$sessionId($id),$id,$agent,'Session '.$id,'2030-01-03T09:00:00Z','2030-01-03T11:00:00Z']);
    }

    $note = $pdo->prepare("INSERT INTO knowledge_items (id,created_by_user_id,folder_id,knowledge_scope,file_type,file_name)
        VALUES (?,?,?,'personal','personal_note',?)");
    $capture = $pdo->prepare('INSERT INTO agent_screen_captures_v1
        (owner_user_id,session_id,capture_id,kind,captured_at,knowledge_id,image_data,body_json) VALUES (?,?,?,?,?,?,?,?)');
    $save = static function (int $id, int $session, int $uid, string $time, string $json, string $kind = 'screen')
        use ($sessionId,$note,$capture): void {
        $captureId = $sessionId($id);
        $note->execute([$id,$uid,$session,'personal-'.sha1('screen-capture:'.$captureId).'.txt']);
        $capture->execute([$uid,$sessionId($session),$captureId,$kind,'2030-01-03T'.$time.'Z',$id,'PRIVATE-PIXELS',$json]);
    };
    $ownerNote = "  Prepare the release\n  checklist.  ";
    $save(11,1,7,'10:01:00',json_encode([
        'note'=>$ownerNote, 'transcript'=>'PRIVATE-TRANSCRIPT',
        'observation'=>['summary'=>'PRIVATE-OBSERVATION','visible_text'=>'PRIVATE-VISIBLE-TEXT'],
    ], JSON_THROW_ON_ERROR));
    $save(12,1,7,'10:02:00','{"note":42}');
    $save(13,1,7,'10:03:00','{"note":true}');
    $save(14,1,7,'10:04:00','{"note":{"text":"PRIVATE-OBJECT"}}');
    $save(15,1,7,'10:05:00','{"note":null}');
    $save(16,1,7,'10:06:00','{"note":"   "}');
    $save(17,1,7,'10:07:00','{invalid');
    $save(18,1,7,'10:08:00','{"note":"PRIVATE-AUDIO-NOTE"}', 'tab_audio');
    $save(21,2,7,'10:09:00','{"note":"Second explicit note"}');
    $save(31,3,7,'10:10:00','{"observation":{"summary":"PRIVATE-UNASKED-OBSERVATION"}}');
    $save(41,4,8,'10:11:00','{"note":"Other owner note"}');
    $save(51,5,7,'10:12:00','{"note":"Other Agent note"}');

    $firstRef = 'screen-followup:'.$sessionId(1);
    $secondRef = 'screen-followup:'.$sessionId(2);
    $read = static fn(array $user, array $sessions = []): array => vp3_initiative_screen_evidence_v1($pdo,$user,$now,$sessions);
    $evidence = $read($owner);
    check(array_keys($evidence) === [$secondRef,$firstRef], 'Only owner System Agent screen sessions with explicit text notes are eligible.');
    check($evidence[$firstRef]['detail'] === 'Prepare the release checklist.', 'A newer JSON value, blank note, invalid JSON or audio note cannot replace the explicit screen note.');
    check($evidence[$firstRef]['url'] === '/knowledge.php?folder=1&screen_session='.$sessionId(1), 'The source link points to the exact owner Knowledge session.');
    check(!str_contains(json_encode($evidence,JSON_THROW_ON_ERROR),'PRIVATE-'), 'The projection must omit pixels, transcripts, and unrequested observations.');
    check(array_keys($read($otherOwner)) === ['screen-followup:'.$sessionId(4)], 'Other owners have independent evidence.');
    vp3_agent_memory_scope_set_current_v410(7,31);
    check($read($owner) === [], 'A custom Agent cannot use the System Agent screen source.');
    vp3_agent_memory_scope_set_current_v410(7,0);

    $pdo->beginTransaction();
    check($read($owner,[$sessionId(1)]) === [$firstRef=>$evidence[$firstRef]], 'A transactional exact-session read must return only the requested older session.');
    $writer = new PDO($dsn, 'root', $password, $options);
    $writer->exec('USE `'.$database.'`');
    $writer->exec('SET SESSION innodb_lock_wait_timeout=1');
    try {
        $writer->prepare('UPDATE agent_screen_captures_v1 SET revision=revision+1 WHERE owner_user_id=? AND capture_id=?')
            ->execute([7,$sessionId(11)]);
        throw new RuntimeException('The exact-session MySQL read did not lock its selected capture.');
    } catch (PDOException $e) {
        check((int)($e->errorInfo[1]??0) === 1205, 'Expected a MySQL row lock timeout from the competing capture update.');
    }
    $pdo->commit();

    $state = $pdo->prepare("INSERT INTO agent_screen_insight_state_v1
        (owner_user_id,agent_id,insight_key,fingerprint,decision,until_at) VALUES (7,0,?,?,'dismiss','')");
    $state->execute([hash('sha256','intent:'.$sessionId(1)),screen_insights_hash_v1([$sessionId(1),$ownerNote])]);
    check($read($owner,[$sessionId(1)]) === [] && array_keys($read($owner)) === [$secondRef], 'Dismissing one exact note suppresses only that session.');
    echo "PASS: MySQL saved screen evidence enforces text notes, owner and Agent isolation, exact-session locks, and dismissal.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('DROP DATABASE `'.$database.'`');
}
