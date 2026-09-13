<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/shared-folders-v161.php';

if (!is_logged_in()) redirect(url('/login.php'));
$user = current_user();
$pdo = db();
if (!$user || !$pdo) redirect(url('/login.php'));
if (!personal_capability_has_v242('personal_knowledge.access', $user)) {
    http_response_code(403);
    exit('Personal Knowledge access is unavailable for this account.');
}
$canManage = personal_capability_has_v242('personal_knowledge.manage', $user);
if (!personal_capability_schema_ready_v242($pdo)) redirect(url('/upgrade.php'));

$uid = (int)$user['id'];
$notice = flash('knowledge_notice');
$error = flash('knowledge_error');
$editId = $canManage ? max(0, (int)($_GET['edit'] ?? 0)) : 0;
$editing = null;
$folders = shared_folders_v161_list($pdo, $user);
$folderFilterRaw = trim((string)($_GET['folder'] ?? 'all'));
$folderFilter = 'all';
if ($folderFilterRaw === 'unfiled') $folderFilter = 'unfiled';
elseif (ctype_digit($folderFilterRaw) && shared_folders_v161_get($pdo, $user, (int)$folderFilterRaw)) $folderFilter = (string)(int)$folderFilterRaw;
$typeFilter = strtolower(trim((string)($_GET['type'] ?? 'all')));
$allowedTypeFilters = ['all','documents','audio','transcription','music','notes'];
if (!in_array($typeFilter, $allowedTypeFilters, true)) $typeFilter = 'all';

function personal_knowledge_item_for_owner(PDO $pdo, int $id, int $owner): ?array
{
    if ($id < 1 || $owner < 1) return null;
    $stmt = $pdo->prepare("SELECT * FROM knowledge_items WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal' LIMIT 1");
    $stmt->execute([$id, $owner]);
    return $stmt->fetch() ?: null;
}

function personal_knowledge_artist_listening_source(array $item): array
{
    $content = (string)($item['content_text'] ?? '');
    $description = (string)($item['description'] ?? '');
    if (!str_contains($content, 'Source: Artist Listening') && !str_contains($description, 'Source: Artist Listening') && !str_contains($description, 'transcription intelligence')) {
        return ['is_source'=>false,'url'=>''];
    }
    $sourceUrl = '';
    if (preg_match('/^Evidence:\s*(\S+)/mi', $content, $m)) {
        $candidate = html_entity_decode(trim((string)$m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $path = (string)(parse_url($candidate, PHP_URL_PATH) ?? '');
        if (str_ends_with($path, '/artist-listening-evidence.php') || $path === 'artist-listening-evidence.php') $sourceUrl = $candidate;
    }
    return ['is_source'=>true,'url'=>$sourceUrl];
}

function personal_knowledge_kind(array $item): string
{
    $artist = personal_knowledge_artist_listening_source($item);
    if ($artist['is_source']) return 'Transcription';
    if ((int)($item['track_id'] ?? 0) > 0) return 'Music';
    $type = strtolower((string)($item['file_type'] ?? ''));
    if (in_array($type, ['mp3','m4a','wav','ogg'], true)) return 'Audio';
    if (in_array($type, ['txt','md','csv','json','html','htm','xml','doc','docx','pdf'], true)) return 'Document';
    return 'Note';
}

function personal_knowledge_redirect_target(string $folderFilter, string $typeFilter = 'all', int $editId = 0): string
{
    $params = [];
    if ($folderFilter !== 'all') $params['folder'] = $folderFilter;
    if ($typeFilter !== 'all') $params['type'] = $typeFilter;
    if ($editId > 0) $params['edit'] = $editId;
    return '/knowledge.php' . ($params ? '?' . http_build_query($params) : '');
}

function personal_knowledge_folder_label(?array $folder): string
{
    return $folder ? (string)$folder['folder_name'] : 'Unfiled';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        exit('Personal Knowledge management is unavailable for this account.');
    }
    if (!verify_csrf()) {
        flash('knowledge_error', 'Session expired. Try again.');
        redirect(url(personal_knowledge_redirect_target($folderFilter, $typeFilter)));
    }

    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'create_folder') {
            $folder = shared_folders_v161_create($pdo, $user, (string)($_POST['folder_name'] ?? ''));
            flash('knowledge_notice', 'Folder created.');
            redirect(url('/knowledge.php?folder=' . (int)$folder['id']));
        }

        if ($action === 'rename_folder') {
            $folderId = max(0, (int)($_POST['folder_id'] ?? 0));
            $folder = shared_folders_v161_rename($pdo, $user, $folderId, (string)($_POST['folder_name'] ?? ''));
            flash('knowledge_notice', 'Folder renamed to ' . (string)$folder['folder_name'] . '.');
            redirect(url(personal_knowledge_redirect_target((string)$folderId, $typeFilter)));
        }

        if ($action === 'delete_folder') {
            $folderId = max(0, (int)($_POST['folder_id'] ?? 0));
            $result = shared_folders_v161_delete($pdo, $user, $folderId);
            flash('knowledge_notice', 'Folder deleted. Its Knowledge and transcription items are now Unfiled.');
            redirect(url(personal_knowledge_redirect_target('unfiled', $typeFilter)));
        }

        if ($action === 'bulk_move') {
            $rawIds = is_array($_POST['item_ids'] ?? null) ? $_POST['item_ids'] : [];
            $ids = array_values(array_unique(array_filter(array_map(static fn($id): int => max(0, (int)$id), $rawIds))));
            if (!$ids) throw new RuntimeException('Select at least one Knowledge item to move.');
            if (count($ids) > 200) throw new RuntimeException('Move up to 200 Knowledge items at a time.');
            $requestedFolderId = max(0, (int)($_POST['folder_id'] ?? 0));
            $resolvedFolderId = personal_knowledge_resolve_folder_id($pdo, $user, $requestedFolderId);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = [$resolvedFolderId, $uid, ...$ids];
            $stmt = $pdo->prepare(
                "UPDATE knowledge_items SET folder_id=?
                 WHERE created_by_user_id=? AND knowledge_scope='personal' AND id IN ({$placeholders})"
            );
            $stmt->execute($params);
            $destination = $resolvedFolderId ? shared_folders_v161_get($pdo, $user, $resolvedFolderId) : null;
            flash('knowledge_notice', count($ids) . ' item' . (count($ids) === 1 ? '' : 's') . ' moved to ' . personal_knowledge_folder_label($destination) . '.');
            $nextFolder = $resolvedFolderId ? (string)$resolvedFolderId : 'unfiled';
            redirect(url(personal_knowledge_redirect_target($nextFolder, $typeFilter)));
        }

        if ($action === 'delete') {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $item = personal_knowledge_item_for_owner($pdo, $id, $uid);
            if (!$item) throw new RuntimeException('Personal knowledge item not found.');
            if (!empty($item['file_path'])) delete_local_upload((string)$item['file_path']);
            $pdo->prepare("DELETE FROM knowledge_items WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal'")->execute([$id, $uid]);
            flash('knowledge_notice', 'Personal knowledge deleted.');
            redirect(url(personal_knowledge_redirect_target($folderFilter, $typeFilter)));
        }

        $id = max(0, (int)($_POST['id'] ?? 0));
        $before = $id > 0 ? personal_knowledge_item_for_owner($pdo, $id, $uid) : null;
        if ($id > 0 && !$before) throw new RuntimeException('Personal knowledge item not found.');
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $content = trim((string)($_POST['content_text'] ?? ''));
        $requestedFolderId = max(0, (int)($_POST['folder_id'] ?? 0));
        $resolvedFolderId = personal_knowledge_resolve_folder_id($pdo, $user, $requestedFolderId);
        if ($title === '') throw new RuntimeException('A title is required.');
        if (mb_strlen($title) > 190) throw new RuntimeException('Keep the title under 190 characters.');

        $filePath = (string)($before['file_path'] ?? '');
        $fileName = (string)($before['file_name'] ?? '');
        $fileType = (string)($before['file_type'] ?? 'text');
        $mimeType = (string)($before['mime_type'] ?? 'text/plain');
        $fileSize = (int)($before['file_size'] ?? 0);
        $extracted = '';
        $upload = $_FILES['knowledge_file'] ?? [];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Knowledge file upload failed.');
            if ((int)($upload['size'] ?? 0) > 50 * 1024 * 1024) throw new RuntimeException('Knowledge files are limited to 50 MB.');
            $extension = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
            $allowed = ['txt','md','csv','json','html','htm','xml','doc','docx','pdf','mp3','m4a','wav','ogg'];
            if (!in_array($extension, $allowed, true)) throw new RuntimeException('Unsupported file type.');
            $targetDir = STONEFELLOW_ROOT . '/uploads/knowledge';
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) throw new RuntimeException('Could not create knowledge upload directory.');
            $newName = bin2hex(random_bytes(16)) . '.' . $extension;
            $absolute = $targetDir . '/' . $newName;
            if (!move_uploaded_file((string)$upload['tmp_name'], $absolute)) throw new RuntimeException('Could not save the knowledge file.');
            if ($filePath !== '') delete_local_upload($filePath);
            $filePath = '/uploads/knowledge/' . $newName;
            $fileName = basename((string)$upload['name']);
            $fileType = $extension;
            $fileSize = (int)$upload['size'];
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detected = finfo_file($finfo, $absolute);
                    if (is_string($detected)) $mimeType = $detected;
                    finfo_close($finfo);
                }
            }
            $extracted = knowledge_extract_file_text($absolute, $extension);
        }
        if ($extracted !== '') $content = trim($content . "\n\n" . $extracted);
        if ($id > 0 && $content === '' && $before) $content = (string)$before['content_text'];
        if ($content === '') throw new RuntimeException('Add knowledge text or upload a file that contains extractable text.');

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE knowledge_items SET folder_id=?,title=?,description=?,file_name=?,file_path=?,file_type=?,mime_type=?,file_size=?,content_text=?,visibility='private',is_published=0,knowledge_scope='personal' WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal'");
            $stmt->execute([$resolvedFolderId,$title,$description,$fileName,$filePath,$fileType,$mimeType,$fileSize,$content,$id,$uid]);
            reindex_knowledge_item($id, $content);
            if (function_exists('shared_knowledge_index_sync_item_v236')) shared_knowledge_index_sync_item_v236($pdo, $id);
            flash('knowledge_notice', 'Personal knowledge updated.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO knowledge_items (track_id,folder_id,title,description,file_name,file_path,file_type,mime_type,file_size,content_text,visibility,is_published,created_by_user_id,knowledge_scope) VALUES (NULL,?,?,?,?,?,?,?,?,?,'private',0,?,'personal')");
            $stmt->execute([$resolvedFolderId,$title,$description,$fileName,$filePath,$fileType,$mimeType,$fileSize,$content,$uid]);
            $id = (int)$pdo->lastInsertId();
            reindex_knowledge_item($id, $content);
            if (function_exists('shared_knowledge_index_sync_item_v236')) shared_knowledge_index_sync_item_v236($pdo, $id);
            flash('knowledge_notice', 'Personal knowledge added.');
        }
        $nextFolder = $resolvedFolderId !== null ? (string)$resolvedFolderId : 'unfiled';
        redirect(url(personal_knowledge_redirect_target($nextFolder, $typeFilter, $id) . '#knowledge-form'));
    } catch (Throwable $e) {
        flash('knowledge_error', $e->getMessage());
        $target = personal_knowledge_redirect_target($folderFilter, $typeFilter, $editId);
        redirect(url($target . ($editId > 0 ? '#knowledge-form' : '')));
    }
}

if ($editId > 0) $editing = personal_knowledge_item_for_owner($pdo, $editId, $uid);
$where = "i.created_by_user_id=? AND i.knowledge_scope='personal'";
$params = [$uid];
if ($folderFilter === 'unfiled') $where .= ' AND i.folder_id IS NULL';
elseif (ctype_digit($folderFilter)) {
    $where .= ' AND i.folder_id=?';
    $params[] = (int)$folderFilter;
}

if ($typeFilter === 'documents') {
    $where .= " AND i.file_type IN ('txt','md','csv','json','html','htm','xml','doc','docx','pdf')";
} elseif ($typeFilter === 'audio') {
    $where .= " AND i.file_type IN ('mp3','m4a','wav','ogg')";
} elseif ($typeFilter === 'transcription') {
    $where .= " AND (i.description LIKE '%transcription intelligence%' OR i.description LIKE '%Source: Artist Listening%' OR i.content_text LIKE '%Source: Artist Listening%')";
} elseif ($typeFilter === 'music') {
    $where .= ' AND i.track_id IS NOT NULL';
} elseif ($typeFilter === 'notes') {
    $where .= " AND i.track_id IS NULL AND (i.file_path='' OR i.file_path IS NULL) AND i.description NOT LIKE '%transcription intelligence%' AND i.content_text NOT LIKE '%Source: Artist Listening%'";
}

$stmt = $pdo->prepare("SELECT i.*,f.folder_name,t.title AS track_title,(SELECT COUNT(*) FROM knowledge_chunks c WHERE c.knowledge_id=i.id) chunk_count FROM knowledge_items i LEFT JOIN artist_transcript_folders_v177 f ON f.id=i.folder_id AND f.created_by_user_id=i.created_by_user_id LEFT JOIN tracks t ON t.id=i.track_id WHERE {$where} ORDER BY i.updated_at DESC,i.id DESC");
$stmt->execute($params);
$items = $stmt->fetchAll() ?: [];

$statsStmt = $pdo->prepare("SELECT folder_id,COUNT(*) item_count,MAX(updated_at) latest_at FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal' GROUP BY folder_id");
$statsStmt->execute([$uid]);
$folderStats = [];
foreach ($statsStmt->fetchAll() ?: [] as $row) {
    $folderStats[(int)($row['folder_id'] ?? 0)] = [
        'count'=>(int)$row['item_count'],
        'latest'=>(string)($row['latest_at'] ?? ''),
    ];
}
$total = array_sum(array_map(static fn(array $row): int => (int)$row['count'], $folderStats));
$chunkStmt = $pdo->prepare("SELECT COUNT(*) FROM knowledge_chunks c INNER JOIN knowledge_items i ON i.id=c.knowledge_id WHERE i.created_by_user_id=? AND i.knowledge_scope='personal'");
$chunkStmt->execute([$uid]);
$totalChunks = (int)$chunkStmt->fetchColumn();
$recentStmt = $pdo->prepare("SELECT COUNT(*) FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal' AND updated_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)");
$recentStmt->execute([$uid]);
$recentCount = (int)$recentStmt->fetchColumn();

$selectedFolder = ctype_digit($folderFilter) ? shared_folders_v161_get($pdo, $user, (int)$folderFilter) : null;
$profileUrl = member_navigation_profile_url($user);
$profileAgentAllowed = personal_capability_has_v242('profile_agent.access', $user);
$selectedFolderId = $editing ? max(0, (int)($editing['folder_id'] ?? 0)) : (ctype_digit($folderFilter) ? (int)$folderFilter : 0);
$currentSectionTitle = $folderFilter === 'all' ? 'All Knowledge' : ($folderFilter === 'unfiled' ? 'Unfiled' : personal_knowledge_folder_label($selectedFolder));
$typeLabels = ['all'=>'All types','documents'=>'Documents','audio'=>'Audio / Media','transcription'=>'Transcription Intelligence','music'=>'Music','notes'=>'Notes'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#ffffff">
<title>My Knowledge | <?= e(system_agent_name()) ?></title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/personal-knowledge.css?v=knowledge-library-v161-20260913')) ?>">
</head>
<body>
<div class="chat-app personal-knowledge-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='knowledge';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main personal-knowledge-main">
<?php
$memberHeaderUser=$user;
$memberHeaderTitle='My Knowledge';
$memberHeaderSubtitle='Private, owner-scoped memory for your Agent';
$memberHeaderActionParts=[];
if($profileUrl!=='')$memberHeaderActionParts[]='<a href="'.e($profileUrl).'">View Profile</a>';
if($profileAgentAllowed)$memberHeaderActionParts[]='<a href="'.e(url('/profile-agent.php')).'">Profile Agent</a>';
if($canManage)$memberHeaderActionParts[]='<a class="primary" href="'.e(url('/knowledge.php#knowledge-form')).'">+ Add Knowledge</a>';
$memberHeaderActions=implode('', $memberHeaderActionParts);
require __DIR__.'/includes/member-header.php';
?>
<section class="personal-knowledge-canvas"><div class="personal-knowledge-wrap">
    <div class="personal-knowledge-hero">
        <div><small>Personal Agent Data</small><h1>Your Knowledge Library</h1><p>Organize documents, media, notes, transcription intelligence and music context with the same shared folders used across VP3.</p></div>
        <div class="personal-knowledge-stats">
            <div class="personal-knowledge-stat"><strong><?= $total ?></strong><span>Items</span></div>
            <div class="personal-knowledge-stat"><strong><?= count($folders) ?></strong><span>Folders</span></div>
            <div class="personal-knowledge-stat"><strong><?= $recentCount ?></strong><span>Updated 7d</span></div>
            <div class="personal-knowledge-stat"><strong><?= $totalChunks ?></strong><span>Agent chunks</span></div>
        </div>
    </div>

    <?php if($notice):?><div class="personal-knowledge-notice"><?= e($notice) ?></div><?php endif;?>
    <?php if($error):?><div class="personal-knowledge-notice error"><?= e($error) ?></div><?php endif;?>

    <nav class="personal-knowledge-breadcrumbs" aria-label="Breadcrumb">
        <a href="<?= e(url('/knowledge.php')) ?>">My Knowledge</a>
        <?php if($folderFilter!=='all'):?><span>›</span><strong><?= e($currentSectionTitle) ?></strong><?php endif;?>
    </nav>

    <section class="personal-knowledge-folder-grid" aria-label="Knowledge folders">
        <article class="personal-knowledge-folder-card <?= $folderFilter==='all'?'active':'' ?>">
            <a href="<?= e(url('/knowledge.php'.($typeFilter!=='all'?'?type='.urlencode($typeFilter):''))) ?>"><span class="folder-mark">⌂</span><strong>All Knowledge</strong><small><?= $total ?> items</small><em>Everything your Agent can use here</em></a>
        </article>
        <article class="personal-knowledge-folder-card <?= $folderFilter==='unfiled'?'active':'' ?>" data-knowledge-folder-drop="0">
            <a href="<?= e(url(personal_knowledge_redirect_target('unfiled',$typeFilter))) ?>"><span class="folder-mark">▱</span><strong>Unfiled</strong><small><?= (int)($folderStats[0]['count']??0) ?> items</small><em>Drop items here to remove their folder</em></a>
        </article>
        <?php foreach($folders as $folder):$fid=(int)$folder['id'];$fstats=$folderStats[$fid]??['count'=>0,'latest'=>'']; ?>
        <article class="personal-knowledge-folder-card <?= $folderFilter===(string)$fid?'active':'' ?>" data-knowledge-folder-drop="<?= $fid ?>">
            <a href="<?= e(url(personal_knowledge_redirect_target((string)$fid,$typeFilter))) ?>"><span class="folder-mark">▱</span><strong><?= e((string)$folder['folder_name']) ?></strong><small><?= (int)$fstats['count'] ?> items</small><em><?= $fstats['latest']!==''?'Latest '.e(date('M j',strtotime((string)$fstats['latest']))):'No Knowledge saved yet' ?></em></a>
            <?php if($canManage):?><details class="personal-knowledge-folder-menu"><summary aria-label="Folder options">•••</summary><div>
                <form method="post" class="personal-knowledge-folder-rename"><?= csrf_field() ?><input type="hidden" name="action" value="rename_folder"><input type="hidden" name="folder_id" value="<?= $fid ?>"><input name="folder_name" maxlength="80" value="<?= e((string)$folder['folder_name']) ?>" aria-label="Rename folder"><button type="submit">Rename</button></form>
                <form method="post" onsubmit="return confirm('Delete this folder? Knowledge and transcriptions in it will move to Unfiled.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_folder"><input type="hidden" name="folder_id" value="<?= $fid ?>"><button class="danger" type="submit">Delete folder</button></form>
            </div></details><?php endif;?>
        </article>
        <?php endforeach;?>
        <?php if($canManage):?><article class="personal-knowledge-folder-card create"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create_folder"><span class="folder-mark">＋</span><strong>New folder</strong><input name="folder_name" maxlength="80" placeholder="Folder name" aria-label="New folder name" required><button type="submit">Create</button></form></article><?php endif;?>
    </section>

    <div class="personal-knowledge-toolbar">
        <nav class="personal-knowledge-type-filters" aria-label="Knowledge type filters">
            <?php foreach($typeLabels as $key=>$label):$target=personal_knowledge_redirect_target($folderFilter,$key);?><a class="<?= $typeFilter===$key?'active':'' ?>" href="<?= e(url($target)) ?>"><?= e($label) ?></a><?php endforeach;?>
        </nav>
        <?php if($canManage):?><form id="knowledge-bulk-form" class="personal-knowledge-bulk" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="bulk_move"><label><input type="checkbox" data-knowledge-select-all> All</label><span data-knowledge-selected-count>0 selected</span><select name="folder_id" data-vp3-shared-folder-picker aria-label="Move selected Knowledge to folder"><option value="0">Unfiled</option><?php foreach($folders as $folder):?><option value="<?= (int)$folder['id'] ?>"><?= e((string)$folder['folder_name']) ?></option><?php endforeach;?></select><button type="submit" data-knowledge-bulk-submit disabled>Move</button></form><?php endif;?>
    </div>

    <div class="personal-knowledge-layout">
        <section class="personal-knowledge-panel">
            <div class="personal-knowledge-panel-head"><div><h2><?= e($currentSectionTitle) ?></h2><p><?= e($typeLabels[$typeFilter]) ?> · <?= count($items) ?> visible</p></div></div>
            <div class="personal-knowledge-list">
                <?php foreach($items as $item):$artistSource=personal_knowledge_artist_listening_source($item);$kind=personal_knowledge_kind($item); ?>
                <article id="knowledge-<?= (int)$item['id'] ?>" class="personal-knowledge-row" data-knowledge-row="<?= (int)$item['id'] ?>" draggable="<?= $canManage?'true':'false' ?>">
                    <?php if($canManage):?><label class="personal-knowledge-select"><input type="checkbox" name="item_ids[]" value="<?= (int)$item['id'] ?>" form="knowledge-bulk-form" data-knowledge-select aria-label="Select <?= e((string)$item['title']) ?>"></label><?php endif;?>
                    <div class="personal-knowledge-copy">
                        <div class="personal-knowledge-titleline"><h3><?= e((string)$item['title']) ?></h3><span class="source cloud">Cloud</span><span class="source kind"><?= e($kind) ?></span></div>
                        <p><?= e(mb_strimwidth(trim((string)$item['description'])!==''?(string)$item['description']:(string)$item['content_text'],0,220,'…')) ?></p>
                        <div class="personal-knowledge-meta"><span><?= e(strtoupper((string)$item['file_type'])) ?></span><span><?= (int)$item['chunk_count'] ?> chunks</span><span class="personal-knowledge-folder-chip"><?= e((string)($item['folder_name']??'Unfiled')) ?></span><?php if(!empty($item['track_title'])):?><span>Music: <?= e((string)$item['track_title']) ?></span><?php endif;?><span>Updated <?= e(date('M j',strtotime((string)$item['updated_at']))) ?></span></div>
                        <?php if($artistSource['is_source']&&$artistSource['url']!==''):?><a class="personal-knowledge-source" href="<?= e($artistSource['url']) ?>">Source: Artist Listening · Open evidence</a><?php endif;?>
                        <details><summary>View knowledge</summary><p><?= nl2br(e((string)$item['content_text'])) ?></p></details>
                    </div>
                    <div class="personal-knowledge-actions">
                        <?php if(!empty($item['file_path'])):?><a href="<?= e(url('/knowledge-file.php?id='.(int)$item['id'])) ?>" target="_blank">Open file</a><?php endif;?>
                        <?php if($canManage):?><a href="<?= e(url(personal_knowledge_redirect_target($folderFilter,$typeFilter,(int)$item['id']).'#knowledge-form')) ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this personal knowledge item?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="danger" type="submit">Delete</button></form><?php endif;?>
                    </div>
                </article>
                <?php endforeach;?>
                <?php if(!$items):?><div class="personal-knowledge-empty">No Knowledge matches this folder and filter.<?= $canManage?' Add a note, document, media file, transcript summary or music reference.':'' ?></div><?php endif;?>
            </div>
        </section>

        <aside class="personal-knowledge-panel" id="knowledge-form">
            <?php if($canManage):?>
            <div class="personal-knowledge-panel-head"><div><h2><?= $editing?'Edit Knowledge':'Add Knowledge' ?></h2><p><?= $editing?'Update this private record or move it to another shared folder.':'Create or upload a private Cloud record and choose its shared folder.' ?></p></div><?php if($editing):?><a href="<?= e(url(personal_knowledge_redirect_target($folderFilter,$typeFilter).'#knowledge-form')) ?>">New</a><?php endif;?></div>
            <form class="personal-knowledge-form" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>">
                <label class="personal-knowledge-field"><span>Title</span><input name="title" maxlength="190" required value="<?= e((string)($editing['title']??'')) ?>" placeholder="What should your Agent know?"></label>
                <label class="personal-knowledge-field"><span>Folder</span><select name="folder_id" data-vp3-shared-folder-picker><option value="0"<?= $selectedFolderId===0?' selected':'' ?>>Unfiled</option><?php foreach($folders as $folder):$fid=(int)$folder['id'];?><option value="<?= $fid ?>"<?= $selectedFolderId===$fid?' selected':'' ?>><?= e((string)$folder['folder_name']) ?></option><?php endforeach;?></select><small>Same folder library used by My Transcriptions and music organization. Choose “+ New folder…” to create one without leaving this form.</small></label>
                <label class="personal-knowledge-field"><span>Description</span><input name="description" maxlength="2000" value="<?= e((string)($editing['description']??'')) ?>" placeholder="Optional context"></label>
                <label class="personal-knowledge-field"><span>Knowledge text</span><textarea name="content_text" placeholder="Notes, facts, preferences, transcript, reference material…"><?= e((string)($editing['content_text']??'')) ?></textarea></label>
                <label class="personal-knowledge-field"><span>Attach a document or media file</span><input name="knowledge_file" type="file" accept=".txt,.md,.csv,.json,.html,.htm,.xml,.doc,.docx,.pdf,.mp3,.m4a,.wav,.ogg"><small>TXT, Markdown, CSV, JSON, HTML, DOC/DOCX, PDF or audio · max 50 MB.<?php if(!empty($editing['file_name'])):?> Current: <?= e((string)$editing['file_name']) ?>.<?php endif;?></small></label>
                <div class="personal-knowledge-form-actions"><button class="primary" type="submit"><?= $editing?'Save Knowledge':'Add to My Knowledge' ?></button><?php if($editing):?><a href="<?= e(url(personal_knowledge_redirect_target($folderFilter,$typeFilter))) ?>">Cancel</a><?php endif;?></div>
            </form>
            <?php else:?><div class="personal-knowledge-panel-head"><div><h2>Read-only access</h2><p>You can view and use your Personal Knowledge, but this account type cannot create, edit or delete records.</p></div></div><?php endif;?>
        </aside>
    </div>

    <div class="personal-knowledge-privacy"><strong>Storage boundary:</strong> every item shown above is a private VP3 Cloud Knowledge record. HomeServer Local Knowledge remains a separate local source; native filesystem paths stay HomeServer-authoritative and are never copied into this Cloud library. Shared folders store only their owner-scoped folder identifier.</div>
</div></section>
</main></div>
<?php if($canManage):?><form id="knowledge-drag-form" method="post" hidden><?= csrf_field() ?><input type="hidden" name="action" value="bulk_move"><input type="hidden" name="folder_id" value="0"></form><?php endif;?>
<script>window.VP3_SHARED_FOLDER_PICKER_V161_CONFIG=<?= json_encode(['endpoint'=>url('/api/shared-folders-v161.php'),'csrf'=>csrf_token()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= e(url('/shared-folder-picker-v161.js?v=knowledge-library-v161-20260913')) ?>"></script>
<script src="<?= e(url('/knowledge-library-v161.js?v=knowledge-library-v161-20260913')) ?>"></script>
<script src="<?= e(url('/member-shell-v77.js?v=universal-member-header-20260905')) ?>"></script>
</body></html>
