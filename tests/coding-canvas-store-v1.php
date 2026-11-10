<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/coding-canvas-store-v1.php';
require_once __DIR__.'/../includes/shared-knowledge-index-v236.php';
require_once __DIR__.'/../includes/homeserver-shared-agent-v210.php';

$dsn = getenv('VP3_TEST_MYSQL_DSN');
$pdo = $dsn
    ? new PDO($dsn, getenv('VP3_TEST_MYSQL_USER'), getenv('VP3_TEST_MYSQL_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
    : new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($dsn) {
    $database = 'canvas_store_'.bin2hex(random_bytes(6));
    $pdo->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static fn() => $pdo->exec('DROP DATABASE '.$database));
    $pdo->exec('USE '.$database);
} else {
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->sqliteCreateFunction('NOW', static fn() => gmdate('Y-m-d H:i:s'));
}

function db(): PDO { return $GLOBALS['pdo']; }

function table_exists(string $table): bool
{
    global $pdo;
    try {
        $pdo->query('SELECT 1 FROM '.$table.' WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function column_exists(string $table, string $column): bool
{
    global $pdo;
    try {
        $pdo->query('SELECT '.$column.' FROM '.$table.' WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function has_permission(string $permission, array $user): bool { return $permission === 'chat.access'; }
function personal_capability_has_v242(string $permission, array $user): bool
{
    return $permission !== 'personal_knowledge.manage' || ($user['manage'] ?? true);
}
function personal_knowledge_available(array $user): bool { return table_exists('knowledge_items'); }
function vp3_participant_scope_v1(): ?array { return $GLOBALS['participant'] ?? null; }
function user_agent_get_v236(PDO $pdo, int $owner, int $agent): ?array
{
    return in_array([$owner,$agent], [[1,11],[1,12],[2,21]], true) ? ['id'=>$agent,'is_active'=>1] : null;
}

function canvas_check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function canvas_denied(int $code, callable $action): void
{
    try {
        $action();
    } catch (Throwable $error) {
        canvas_check($error->getCode() === $code, 'Expected '.$code.', got '.get_class($error).' '.$error->getCode().' '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected error '.$code);
}

$idType = $dsn ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$folderType = $dsn ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$pdo->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY)');
$pdo->exec('INSERT INTO users VALUES (1),(2)');
$pdo->exec('CREATE TABLE chat_conversations(id INT PRIMARY KEY,user_id INT,user_agent_id INT NULL)');
$pdo->exec('INSERT INTO chat_conversations VALUES (10,1,NULL),(11,1,11),(12,1,12),(21,2,21)');
$pdo->exec("CREATE TABLE artist_transcript_folders_v177 (
    id $folderType, created_by_user_id INT UNSIGNED NOT NULL, folder_name VARCHAR(80), sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (created_by_user_id,folder_name), FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE TABLE knowledge_items (
    id $idType, title VARCHAR(190), description TEXT, file_name VARCHAR(255), file_type VARCHAR(50),
    mime_type VARCHAR(120), file_size INT UNSIGNED, content_text LONGTEXT, visibility VARCHAR(30), is_published INT,
    created_by_user_id INT UNSIGNED, knowledge_scope VARCHAR(30), folder_id BIGINT UNSIGNED NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec('CREATE TABLE artist_transcript_sessions_v172(id INT PRIMARY KEY,created_by_user_id INT,metadata_json TEXT,last_activity_at DATETIME NULL)');
canvas_check(!vp3_coding_canvas_ready_v1($pdo), 'Missing Canvas schema is unavailable');
vp3_coding_canvas_schema_v1($pdo);
vp3_coding_canvas_schema_v1($pdo);
canvas_check(vp3_coding_canvas_ready_v1($pdo), 'Canvas schema upgrade is repeatable');

$user = ['id'=>1,'is_active'=>1];
$other = ['id'=>2,'is_active'=>1];
$canvasId = '6c000000-0000-4000-8000-000000000001';
$nextId = '6c000001-0000-4000-8000-000000000002';
$abortedId = '6c000002-0000-4000-8000-000000000003';
$artifact = ['html'=>'<p>First</p>','css'=>'p { color: red; }','js'=>''];
$repair = ['html'=>'<p>Fixed</p>','css'=>'p { color: blue; }','js'=>''];

canvas_denied(404, fn() => vp3_coding_canvas_save_draft_v1($pdo,$user,11,10,$canvasId,'Wrong origin',$artifact,[]));
canvas_denied(422, fn() => vp3_coding_canvas_save_draft_v1($pdo,$user,11,11,$canvasId,'Bad artifact',['html'=>'<script></script>','css'=>'','js'=>''],[]));
canvas_denied(403, fn() => vp3_coding_canvas_save_draft_v1($pdo,$user+['manage'=>false],11,11,$canvasId,'No write',$artifact,[]));
$GLOBALS['participant'] = ['kind'=>'visitor'];
canvas_denied(403, fn() => vp3_coding_canvas_list_v1($pdo,$user,11));
unset($GLOBALS['participant']);

$pdo->beginTransaction();
vp3_coding_canvas_save_draft_v1($pdo,$user,11,11,$abortedId,'Aborted',$artifact,[]);
$pdo->rollBack();
canvas_check((int)$pdo->query('SELECT COUNT(*) FROM artist_transcript_folders_v177')->fetchColumn()===0, 'A rolled-back run must leave no folder');
canvas_check((int)$pdo->query('SELECT COUNT(*) FROM knowledge_items')->fetchColumn()===0, 'A rolled-back run must leave no Knowledge documents');

$first = vp3_coding_canvas_save_draft_v1($pdo,$user,11,11,$canvasId,'Tracker',$artifact,[],101);
$folder = $first['canvas']['folder_id'];
$firstState = $first['state']['revision'];
canvas_check($first['revision']['artifact']===$artifact && $first['revision']['status']==='draft', 'New Canvas source saved as draft');
canvas_check($first['canvas']['active_revision']===null && $first['canvas']['draft_revision']===1, 'New Canvas has no applied code');
canvas_check(json_encode($first['state']['value'])==='{}', 'Empty app state is a JSON object');
canvas_check(shared_folders_v161_get($pdo,$user,$folder)['folder_name']==='Tracker', 'Canvas uses the actual owner Knowledge folder');
$items = $pdo->query("SELECT id FROM knowledge_items WHERE folder_id=$folder AND knowledge_scope='personal' AND visibility='private' AND is_published=0 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
canvas_check(count($items)===2, 'State and revision are private Knowledge documents');
canvas_check(shared_knowledge_index_item_v236($pdo,(int)$items[0])===null, 'App data is excluded from Agent Knowledge retrieval');
canvas_check(shared_knowledge_index_item_v236($pdo,(int)$items[1])===null, 'Source code is excluded from Agent Knowledge retrieval');
canvas_check(homeserver_shared_v210_cloud_snapshot(1)['datasets']['knowledge']===[], 'Code and app data stay out of HomeServer knowledge snapshots');
$pdo->exec("INSERT INTO knowledge_items (title,created_by_user_id,knowledge_scope,content_text) VALUES ('Ordinary note',1,'personal','Keep this ordinary note')");
$sharedNote = homeserver_shared_v210_cloud_snapshot(1)['datasets']['knowledge'];
canvas_check(count($sharedNote)===1 && str_contains($sharedNote[0]['content'], 'Keep this ordinary note'), 'Ordinary owner Knowledge still participates in HomeServer snapshots');
$pdo->exec("DELETE FROM knowledge_items WHERE title='Ordinary note'");

canvas_denied(409, fn() => vp3_coding_canvas_assert_knowledge_editable_v1($pdo,1,$items));
canvas_denied(409, fn() => shared_folders_v161_delete($pdo,$user,$folder));

$retry = vp3_coding_canvas_save_draft_v1($pdo,$user,11,11,$canvasId,'Tracker',$artifact,[],101);
canvas_check($retry['revision']['id']===1 && count($retry['revisions'])===1, 'Retrying one job must reuse the saved draft');
canvas_denied(404, fn() => vp3_coding_canvas_read_v1($pdo,$other,21,$canvasId));
canvas_denied(404, fn() => vp3_coding_canvas_read_v1($pdo,$user,12,$canvasId));
canvas_check(vp3_coding_canvas_list_v1($pdo,$user,12)===[], 'Saved app list is Agent-scoped');
canvas_denied(404, fn() => vp3_coding_canvas_save_draft_v1($pdo,$other,21,21,$canvasId,'Tracker',$artifact,[]));
canvas_denied(409, fn() => vp3_coding_canvas_activate_v1($pdo,$user,11,$canvasId,1,null));
canvas_denied(409, fn() => vp3_coding_canvas_save_state_v1($pdo,$user,11,$canvasId,1,$firstState,['count'=>1]));
canvas_denied(409, fn() => vp3_coding_canvas_mark_tested_v1($pdo,$user,11,$canvasId,1,str_repeat('0',64)));

$tested = vp3_coding_canvas_mark_tested_v1($pdo,$user,11,$canvasId,1,$firstState);
canvas_check($tested['revision']['test_passed'], 'A test records current app data');
$live = vp3_coding_canvas_activate_v1($pdo,$user,11,$canvasId,1,null);
canvas_check($live['canvas']['active_revision']===1 && $live['canvas']['draft_revision']===null, 'Apply promotes the tested version');
$stateRevision = $live['state']['revision'];
$state = vp3_coding_canvas_save_state_v1($pdo,$user,11,$canvasId,1,$stateRevision,(object)['count'=>1,'nested'=>(object)[]]);
canvas_check(json_encode($state['state']['value'])==='{"count":1,"nested":{}}', 'App data preserves nested JSON object shapes');
canvas_denied(409, fn() => vp3_coding_canvas_save_state_v1($pdo,$user,11,$canvasId,1,$stateRevision,['count'=>9]));
canvas_denied(422, fn() => vp3_coding_canvas_save_state_v1($pdo,$user,11,$canvasId,1,$state['state']['revision'],[1,2]));

$pdo->exec('DELETE FROM chat_conversations WHERE id=11');
$draft = vp3_coding_canvas_save_draft_v1($pdo,$user,11,11,$canvasId,'New title',$repair,['count'=>99],102,null,1);
canvas_check($draft['canvas']['active_revision']===1 && $draft['revision']['id']===2, 'Repair leaves the working version live');
canvas_check($draft['canvas']['folder_id']===$folder && $draft['state']['value']->count===1, 'Repair reuses folder and preserves app data after origin removal');
canvas_check(vp3_coding_canvas_read_v1($pdo,$user,11,$canvasId)['revision']['id']===1, 'Reopening loads active code');
canvas_denied(409, fn() => vp3_coding_canvas_save_draft_v1($pdo,$user,11,11,$canvasId,'Tracker',$repair,[],103,null,1));
vp3_coding_canvas_mark_tested_v1($pdo,$user,11,$canvasId,2,$draft['state']['revision']);
$newState = vp3_coding_canvas_save_state_v1($pdo,$user,11,$canvasId,1,$draft['state']['revision'],['count'=>2]);
canvas_denied(409, fn() => vp3_coding_canvas_activate_v1($pdo,$user,11,$canvasId,2,1));
vp3_coding_canvas_mark_tested_v1($pdo,$user,11,$canvasId,2,$newState['state']['revision']);
$applied = vp3_coding_canvas_activate_v1($pdo,$user,11,$canvasId,2,1);
canvas_check($applied['revision']['artifact']===$repair && $applied['state']['value']->count===2, 'Repair applies with current live data');
canvas_denied(409, fn() => vp3_coding_canvas_save_state_v1($pdo,$user,11,$canvasId,1,$applied['state']['revision'],['count'=>9]));
$rollback = vp3_coding_canvas_activate_v1($pdo,$user,11,$canvasId,1,2);
canvas_check($rollback['revision']['artifact']===$artifact && $rollback['state']['value']->count===2, 'Rollback selects prior working code and keeps current app data');

$pdo->exec('INSERT INTO chat_conversations VALUES (13,1,11)');
$next = vp3_coding_canvas_save_draft_v1($pdo,$user,11,13,$nextId,'Tracker',$artifact,[]);
$nextFolder = $next['canvas']['folder_id'];
canvas_check($nextFolder!==$folder && count(vp3_coding_canvas_list_v1($pdo,$user,11))===2, 'Equal titles have independent saved Canvases and folders');
$pdo->prepare("INSERT INTO knowledge_items (title,created_by_user_id,knowledge_scope,folder_id) VALUES ('Other document',1,'personal',?)")->execute([$nextFolder]);
$pdo->prepare('INSERT INTO artist_transcript_sessions_v172 (id,created_by_user_id,metadata_json) VALUES (1,1,?)')->execute([json_encode(['folder_id'=>$nextFolder])]);
vp3_coding_canvas_delete_v1($pdo,$user,11,$nextId,true);
canvas_check(shared_folders_v161_get($pdo,$user,$nextFolder)===null, 'Delete both removes the linked folder');
canvas_check((int)$pdo->query("SELECT COUNT(*) FROM knowledge_items WHERE title='Other document' AND folder_id IS NULL")->fetchColumn()===1, 'Folder deletion preserves unrelated documents');
canvas_check(json_decode($pdo->query('SELECT metadata_json FROM artist_transcript_sessions_v172')->fetchColumn(),true)['folder_id']===0, 'Folder deletion preserves unrelated transcriptions');
canvas_check((int)$pdo->query('SELECT COUNT(*) FROM vp3_coding_canvas_versions_v1')->fetchColumn()===2, 'Deleted Canvas revisions are removed');

vp3_coding_canvas_delete_v1($pdo,$user,11,$canvasId,false);
canvas_denied(404, fn() => vp3_coding_canvas_read_v1($pdo,$user,11,$canvasId));
canvas_check(shared_folders_v161_get($pdo,$user,$folder)!==null, 'Keep folder preserves its folder');
canvas_check((int)$pdo->query("SELECT COUNT(*) FROM knowledge_items WHERE folder_id=$folder")->fetchColumn()===3, 'Keep folder preserves private app data and revisions');
vp3_coding_canvas_assert_knowledge_editable_v1($pdo,1,$items);

echo 'Coding Canvas storage ('.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'): PASS'.PHP_EOL;
