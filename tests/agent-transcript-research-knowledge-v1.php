<?php
declare(strict_types=1);

function db(): PDO { return $GLOBALS['pdo']; }
function table_exists(string $table): bool
{
    $stmt = db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}
function column_exists(string $table, string $column): bool
{
    return in_array($column, array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(), 'name'), true);
}
function personal_capability_has_strict_v1(string $permission, array $user): bool
{
    return $permission === 'personal_knowledge.access' && empty($GLOBALS['knowledgeDenied']);
}
function personal_capability_has_v242(string $permission, array $user): bool { return true; }
function vp3_initiative_policy_v1(array $user, int $agentId): array { return $GLOBALS['livePolicy']; }
function url(string $path): string { return $path; }
function check(bool $valid, string $message): void
{
    if (!$valid) throw new RuntimeException($message);
}

require_once dirname(__DIR__).'/includes/agent-transcript-research-knowledge-v1.php';
require_once dirname(__DIR__).'/includes/transcript-knowledge-highlight-v1.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE knowledge_items(id INTEGER PRIMARY KEY, created_by_user_id INTEGER, knowledge_scope TEXT, file_type TEXT, file_name TEXT, folder_id INTEGER,
        track_id INTEGER, title TEXT, description TEXT, file_path TEXT, mime_type TEXT, file_size INTEGER, content_text TEXT, visibility TEXT, is_published INTEGER);
    CREATE TABLE knowledge_chunks(knowledge_id INTEGER,chunk_index INTEGER,chunk_text TEXT);
    CREATE TABLE artist_transcript_folders_v177(id INTEGER PRIMARY KEY, created_by_user_id INTEGER, folder_name TEXT, sort_order INTEGER, created_at TEXT, updated_at TEXT);
    CREATE TABLE user_data_policies(owner_user_id INTEGER, resource_type TEXT, resource_id TEXT, audience_scope TEXT, owner_agents_allowed INTEGER, profile_agent_allowed INTEGER, stonefellow_shared INTEGER);
    CREATE TABLE user_agent_data_rules(agent_id INTEGER, resource_type TEXT, resource_id TEXT, access_mode TEXT);
    INSERT INTO artist_transcript_folders_v177 VALUES (10,1,'Suppliers',0,'',''),(20,1,'Archive',0,'',''),(30,2,'Other owner',0,'','');");
$insert = $pdo->prepare("INSERT INTO knowledge_items(id,created_by_user_id,knowledge_scope,file_type,file_name,folder_id) VALUES (?, ?, 'personal', 'personal_note', ?, ?)");
foreach ([[1,1,'artist-listening-transcript:81',10], [2,1,'artist-listening-analysis:81',null],
    [3,1,'transcription-intelligence-item:ti_receipt',10], [4,2,'artist-listening-transcript:82',30],
    [5,1,'artist-listening-analysis:82',30]] as [$id, $owner, $key, $folderId]) {
    $insert->execute([$id, $owner, personal_knowledge_note_marker($key), $folderId]);
}

$scope = ['user'=>['id'=>1], 'principal'=>['kind'=>'user_agent', 'agent_id'=>17, 'owner_user_id'=>1, 'viewer_user_id'=>1],
    'agent_id'=>17, 'conversation_id'=>170];
$binding = ['owner_user_id'=>1, 'agent_id'=>17, 'conversation_id'=>170, 'knowledge_scope'=>['mode'=>'all', 'folder_id'=>0]];
$item = ['plugin'=>'qa', 'section'=>'unanswered_questions', 'item_id'=>'ti_receipt', 'actions'=>['personal_knowledge'=>['record_id'=>3,
    'source'=>['session_id'=>81, 'plugin_id'=>'qa', 'section_key'=>'unanswered_questions', 'item_id'=>'ti_receipt']]]];
$ids = static fn(array $links): array => array_column($links, 'knowledge_id');

// A projection works in an existing transaction and performs no writes.
$pdo->exec('PRAGMA query_only=ON');
$pdo->beginTransaction();
$links = vp3_transcript_research_knowledge_v1($pdo, $scope, $binding, 81, $item);
check($ids($links) === [1,2,3], 'Whole transcript, report and exact reviewed finding should link independently.');
check($links[0]['folder_name'] === 'Suppliers' && $links[1]['url'] === '/knowledge.php?folder=unfiled&edit=2#knowledge-form', 'Links resolve live folders and unfiled reports.');
check(vp3_transcript_research_knowledge_v1($pdo, $scope, $binding, 82, $item) === [], 'Other owners, foreign folders and other recordings must never leak through Knowledge metadata.');
$pdo->rollBack();
$pdo->exec('PRAGMA query_only=OFF');

$binding['knowledge_scope'] = ['mode'=>'folder', 'folder_id'=>10];
check($ids(vp3_transcript_research_knowledge_v1($pdo, $scope, $binding, 81, $item)) === [1,3], 'Selection must restrict links to the exact folder.');
$pdo->exec("UPDATE knowledge_items SET folder_id=20 WHERE id=1;
    INSERT INTO user_agent_data_rules VALUES (17,'knowledge','3','deny');");
check(vp3_transcript_research_knowledge_v1($pdo, $scope, $binding, 81, $item) === [], 'Moved items and denied items must disappear from the selected folder.');
$binding['knowledge_scope'] = ['mode'=>'all', 'folder_id'=>0];
check($ids(vp3_transcript_research_knowledge_v1($pdo, $scope, $binding, 81, $item)) === [1,2], 'All-folder selection still enforces the live item ACL.');
$pdo->exec('DELETE FROM user_agent_data_rules');
$item['actions']['personal_knowledge']['record_id'] = 2;
check($ids(vp3_transcript_research_knowledge_v1($pdo, $scope, $binding, 81, $item)) === [1,2], 'A receipt pointing to a different marker cannot add a finding.');

$scope['verification_only'] = true;
$GLOBALS['livePolicy'] = ['knowledge_scope'=>['mode'=>'off', 'folder_id'=>0]];
check(vp3_transcript_research_knowledge_v1($pdo, $scope, $binding, 81) === [], 'Old proof reads must respect a changed Knowledge selection.');
$GLOBALS['livePolicy'] = $binding;
$GLOBALS['knowledgeDenied'] = true;
check(vp3_transcript_research_knowledge_v1($pdo, $scope, $binding, 81) === [], 'Current personal Knowledge access is mandatory.');

$GLOBALS['knowledgeDenied'] = false;
unset($scope['verification_only']);
$session = ['id'=>81, 'status'=>'draft'];
$segments = [
    ['id'=>100, 'segment_index'=>0, 'segment_type'=>'transcript', 'transcript_text'=>'😀 Quote', 'started_ms'=>0],
    ['id'=>101, 'segment_index'=>1, 'segment_type'=>'transcript', 'transcript_text'=>'Quote', 'started_ms'=>500],
];
$first = vp3_transcript_knowledge_highlight_v1($session, $segments, 1, 0, 3, 'Quote');
$second = vp3_transcript_knowledge_highlight_v1($session, $segments, 1, 0, 9, 'Quote');
$turn = vp3_transcript_knowledge_highlight_v1($session, $segments, 1, 101, 0, 'Quote');
check($first['segment_ids'] === [100] && $second['segment_ids'] === [101] && $first['key'] !== $second['key'], 'UTF-16 offsets must disambiguate repeated text.');
check($turn['key'] === $second['key'] && str_ends_with($turn['source_url'], '#transcript-segment-101'), 'A speaker turn and prose must resolve the same saved range.');
try {
    vp3_transcript_knowledge_highlight_v1($session, $segments, 1, 0, 4, 'Quote');
    throw new LogicException('An unverified highlight was accepted.');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'does not match'), 'Unsaved or wrong-offset selection must fail.');
}
$user = $scope['user'];
$firstId = personal_knowledge_store($user, $first['key'], 'First highlight', $first['text'], '', 10);
check(personal_knowledge_store($user, $first['key'], 'First highlight', $first['text'], '', 20) === $firstId, 'Saving the same exact highlight again moves the same Knowledge item.');
$secondId = personal_knowledge_store($user, $second['key'], 'Second highlight', $second['text'], '', 20);
$binding['knowledge_scope'] = ['mode'=>'folder', 'folder_id'=>20];
$links = vp3_transcript_research_knowledge_v1($pdo, $scope, $binding, 81);
check($ids($links) === [1,$secondId,$firstId] && array_column($links, 'kind') === ['transcript','highlight','highlight'], 'Verified highlights appear as aliases in the current owner folder.');
check(!str_contains(json_encode($links, JSON_THROW_ON_ERROR), 'Quote'), 'Research aliases contain metadata only.');

echo "AGENT_TRANSCRIPT_RESEARCH_KNOWLEDGE_V1=PASS\n";
