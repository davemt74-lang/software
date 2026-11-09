<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-gmail-github-knowledge-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function url(string $path): string { return '/vp3'.$path; }
function has_permission(string $permission, array $user): bool { return false; }
function personal_capability_has_v242(string $capability, array $user): bool
{
    return in_array($capability,['personal_knowledge.access','personal_knowledge.manage'],true)
        && $capability!==($user['revoked']??'');
}
function table_exists(string $table): bool
{
    $query = db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}
function column_exists(string $table, string $column): bool
{
    return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);
}
function check(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}
function rejects(callable $operation, string $class, int $code, string $label): void
{
    try { $operation(); }
    catch (Throwable $error) { check($error instanceof $class && $error->getCode()===$code,$label); return; }
    throw new RuntimeException('Expected rejection: '.$label);
}

$pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys=ON;
    CREATE TABLE artist_transcript_folders_v177 (id INTEGER PRIMARY KEY AUTOINCREMENT,created_by_user_id INTEGER,folder_name TEXT,sort_order INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(created_by_user_id,folder_name));
    CREATE TABLE knowledge_items (id INTEGER PRIMARY KEY,track_id INTEGER,folder_id INTEGER REFERENCES artist_transcript_folders_v177(id) ON DELETE SET NULL,
        title TEXT,description TEXT,file_name TEXT,file_path TEXT,file_type TEXT,mime_type TEXT,file_size INTEGER,content_text TEXT,
        visibility TEXT,is_published INTEGER,created_by_user_id INTEGER,knowledge_scope TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE knowledge_chunks (id INTEGER PRIMARY KEY,knowledge_id INTEGER REFERENCES knowledge_items(id) ON DELETE CASCADE,chunk_index INTEGER,chunk_text TEXT);
    CREATE TABLE tracks (id INTEGER PRIMARY KEY,title TEXT);');
$checks = 0;
$owner = ['id'=>1];
$other = ['id'=>2];
$folder = (int)personal_knowledge_create_folder($pdo,$owner,'VP3')['id'];
$archive = (int)personal_knowledge_create_folder($pdo,$owner,'Owner archive')['id'];
$otherFolder = (int)personal_knowledge_create_folder($pdo,$other,'VP3')['id'];
$key = hash('sha256','verified message');
$record = ['from'=>'notifications@github.com','repository'=>'davemt74-lang/software','status'=>'failure','received_at'=>'2026-11-09 12:34:56',
    'url'=>'https://github.com/davemt74-lang/software/actions/runs/4321','subject'=>'GitHub Actions reported failure','body'=>'RAW_BODY_SENTINEL'];
$file = static fn(array $user, int $destination, string $messageKey, array $source): array => agent_commerce_atomic_pa2($pdo,
    static fn(): array => agent_connection_gmail_github_file_v1($pdo,$user,$destination,$messageKey,$source));

rejects(fn()=>agent_connection_gmail_github_file_v1($pdo,$owner,$folder,$key,$record),LogicException::class,0,'filing requires the active inbox transaction');
rejects(fn()=>$file($owner,$otherFolder,$key,$record),RuntimeException::class,409,'another owner’s folder cannot receive the note');
$saved = $file($owner,$folder,$key,$record);
$note = get_knowledge_item($saved['knowledge_id']);
check($saved['created'] && $saved['folder_id']===$folder && $saved['folder_name']==='VP3'
    && $saved['view_url']==='/vp3/knowledge.php?edit='.$saved['knowledge_id'].'#knowledge-form','first verified message opens the exact canonical Knowledge note');
check($note['knowledge_scope']==='personal' && $note['visibility']==='private' && (int)$note['is_published']===0
    && (int)$note['created_by_user_id']===1,'the note is private and belongs to the mailbox owner');
check(str_contains($note['title'],$record['repository']) && str_contains($note['content_text'],$record['url'])
    && str_contains($note['content_text'],$record['received_at']) && str_contains($note['content_text'],'Status reported by email: failure')
    && !str_contains($note['content_text'],'RAW_BODY_SENTINEL'),'the repository, original timestamp, reported result and source link are preserved as metadata');
check((int)search_knowledge('davemt74 software',$owner)[0]['id']===$saved['knowledge_id']
    && search_knowledge('davemt74 software',$other)===[]
    && search_knowledge('davemt74 software',$owner+['revoked'=>'personal_knowledge.access'])===[],'normal Knowledge retrieval indexes the note and keeps existing owner and capability boundaries');
$pdo->prepare('UPDATE knowledge_items SET title=?,folder_id=? WHERE id=?')->execute(['Reviewed by owner',$archive,$saved['knowledge_id']]);
$duplicate = $file($owner,$folder,$key,[...$record,'status'=>'success']);
check(!$duplicate['created'] && $duplicate['knowledge_id']===$saved['knowledge_id'] && $duplicate['folder_id']===$archive
    && get_knowledge_item($saved['knowledge_id'])['title']==='Reviewed by owner'
    && (int)$pdo->query('SELECT COUNT(*) FROM knowledge_chunks')->fetchColumn()===1,'a replay preserves the owner’s edit, move and existing index');
$secondOwner = $file($other,$otherFolder,$key,$record);
check($secondOwner['created'] && $secondOwner['knowledge_id']!==$saved['knowledge_id'],'identical message keys remain isolated by owner');
$fallback = $file($owner,$folder,hash('sha256','unknown repo'),[...$record,'repository'=>'','url'=>'','status'=>'unknown']);
check(str_contains(get_knowledge_item($fallback['knowledge_id'])['content_text'],'Repository unavailable'),'verified notification can be filed without inventing a repository or source URL');
foreach (['https://github.com.evil.test/davemt74-lang/software/actions','https://github.com/other/software/actions'] as $unsafe) {
    rejects(fn()=>$file($owner,$folder,$key,[...$record,'url'=>$unsafe]),InvalidArgumentException::class,0,'a mismatched or spoofed GitHub link is rejected');
}
rejects(fn()=>$file($owner,$folder,$key,[...$record,'from'=>'spoof@example.test']),InvalidArgumentException::class,0,'a spoofed sender is rejected');
rejects(fn()=>$file($owner+['revoked'=>'personal_knowledge.manage'],$folder,$key,$record),RuntimeException::class,403,'revoked Knowledge management stops filing');
$pdo->beginTransaction();
$rolled = agent_connection_gmail_github_file_v1($pdo,$owner,$folder,hash('sha256','rolled back'),$record);
$pdo->rollBack();
check(get_knowledge_item($rolled['knowledge_id'])===null && (int)$pdo->query('SELECT COUNT(*) FROM knowledge_chunks')->fetchColumn()===3,'the note and index roll back with the inbox transaction');
$pdo->prepare('DELETE FROM artist_transcript_folders_v177 WHERE id=?')->execute([$folder]);
personal_knowledge_create_folder($pdo,$owner,'VP3');
rejects(fn()=>$file($owner,$folder,hash('sha256','deleted folder'),$record),RuntimeException::class,409,'recreating the destination name does not authorize filing into a new folder');
echo 'GMAIL_GITHUB_KNOWLEDGE_V1=PASS ('.$checks.' cases)'.PHP_EOL;
