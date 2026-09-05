<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('knowledge.manage');

if (!access_schema_ready() || !personal_capability_schema_ready_v242()) {
    redirect(url('/upgrade.php'));
}

$pdo = db();
if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}

$editId = (int)($_GET['edit'] ?? 0);
$showNewForm = isset($_GET['new']);
$showForm = $showNewForm || $editId > 0;
$editing = null;

function admin_system_knowledge_item(PDO $pdo,int $id): ?array
{
    if($id<1)return null;
    $stmt=$pdo->prepare("SELECT * FROM knowledge_items WHERE id=? AND knowledge_scope='system' LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch()?:null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired. Try again.');
        redirect(url('/admin/knowledge.php'));
    }

    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $item = admin_system_knowledge_item($pdo,$id);
        if ($item) {
            delete_local_upload((string)($item['file_path'] ?? ''));
            $pdo->prepare("DELETE FROM knowledge_items WHERE id=? AND knowledge_scope='system'")->execute([$id]);
        }
        flash('notice', 'System knowledge item deleted.');
        redirect(url('/admin/knowledge.php'));
    }

    try {
        $id = (int)($_POST['id'] ?? 0);
        $before=$id>0?admin_system_knowledge_item($pdo,$id):null;
        if($id>0&&!$before)throw new RuntimeException('System knowledge item not found.');
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $manualText = trim((string)($_POST['content_text'] ?? ''));
        $visibility = trim((string)($_POST['visibility'] ?? 'members'));
        $published = isset($_POST['is_published']) ? 1 : 0;
        $trackId = (int)($_POST['track_id'] ?? 0);

        if ($title === '') throw new RuntimeException('A title is required.');
        if (!valid_visibility($visibility)) throw new RuntimeException('Invalid visibility selection.');

        $filePath = trim((string)($before['file_path'] ?? ''));
        $fileName = trim((string)($before['file_name'] ?? ''));
        $fileType = trim((string)($before['file_type'] ?? 'text'));
        $mimeType = trim((string)($before['mime_type'] ?? ''));
        $fileSize = (int)($before['file_size'] ?? 0);
        $extractedText = '';

        $upload = $_FILES['knowledge_file'] ?? [];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Knowledge file upload failed.');
            if ((int)$upload['size'] > 50 * 1024 * 1024) throw new RuntimeException('Knowledge files are limited to 50 MB.');

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
            $extractedText = knowledge_extract_file_text($absolute, $extension);
        }

        $content = trim($manualText);
        if ($extractedText !== '') $content = trim($content . "\n\n" . $extractedText);
        if ($id > 0 && $content === '' && $before) $content = (string)$before['content_text'];

        $currentUser = current_user();
        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE knowledge_items SET track_id=?,title=?,description=?,file_name=?,file_path=?,file_type=?,mime_type=?,file_size=?,content_text=?,visibility=?,is_published=?,knowledge_scope='system' WHERE id=? AND knowledge_scope='system'"
            );
            $stmt->execute([$trackId ?: null,$title,$description,$fileName,$filePath,$fileType,$mimeType,$fileSize,$content,$visibility,$published,$id]);
            reindex_knowledge_item($id, $content);
            flash('notice', 'System knowledge updated and reindexed.');
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO knowledge_items (track_id,title,description,file_name,file_path,file_type,mime_type,file_size,content_text,visibility,is_published,created_by_user_id,knowledge_scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'system')"
            );
            $stmt->execute([$trackId ?: null,$title,$description,$fileName,$filePath,$fileType,$mimeType,$fileSize,$content,$visibility,$published,(int)($currentUser['id'] ?? 0) ?: null]);
            $newId = (int)$pdo->lastInsertId();
            reindex_knowledge_item($newId, $content);
            flash('notice', 'System knowledge added and indexed.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(url('/admin/knowledge.php'));
}

if ($editId > 0) $editing = admin_system_knowledge_item($pdo,$editId);
$tracksForKnowledge = $pdo->query('SELECT id,title FROM tracks ORDER BY sort_order,id')->fetchAll();
$items = $pdo->query(
    "SELECT i.*,(SELECT COUNT(*) FROM knowledge_chunks c WHERE c.knowledge_id=i.id) AS chunk_count FROM knowledge_items i WHERE i.knowledge_scope='system' ORDER BY i.updated_at DESC"
)->fetchAll();

$adminTitle = 'System Knowledge';
$adminActive = 'knowledge';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div><span class="status">System Library</span><h2>Shared / System Knowledge</h2><p class="muted">Platform-wide published knowledge. Member-owned Personal Knowledge is isolated by user and managed from each member's My Knowledge workspace.</p></div>
    <a class="btn primary" href="<?= e(url('/admin/knowledge.php?new=1#knowledge-form')) ?>">+ Add System Knowledge</a>
  </div>
  <div class="table-wrap"><table><thead><tr><th>Item</th><th>Song</th><th>Type</th><th>Visibility</th><th>Indexed</th><th>Status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($items as $item): ?><tr><td><strong><?= e($item['title']) ?></strong><br><span class="muted"><?= e($item['file_name'] ?: 'Text knowledge') ?></span></td><td><?php $trackTitle='';if(!empty($item['track_id']))foreach($tracksForKnowledge as $trackOption)if((int)$trackOption['id']===(int)$item['track_id']){$trackTitle=(string)$trackOption['title'];break;}?><?= $trackTitle!==''?e($trackTitle):'<span class="muted">General</span>' ?></td><td><?= e(strtoupper((string)$item['file_type'])) ?></td><td><?= e(visibility_options()[$item['visibility']] ?? $item['visibility']) ?></td><td><?= (int)$item['chunk_count'] ?> chunks</td><td><span class="status"><?= (int)$item['is_published'] ? 'Published' : 'Draft' ?></span></td><td class="actions"><?php if(!empty($item['file_path'])):?><a class="btn" href="<?= e(url('/knowledge-file.php?id='.(int)$item['id'])) ?>" target="_blank">Open</a><?php endif;?><a class="btn" href="<?= e(url('/admin/knowledge.php?edit='.(int)$item['id'].'#knowledge-form')) ?>">Edit</a><form class="inline-form" method="post" onsubmit="return confirm('Delete this system knowledge item?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="btn danger" type="submit">Delete</button></form></td></tr><?php endforeach;?>
  <?php if(!$items):?><tr><td colspan="7" class="muted">No system knowledge has been added yet.</td></tr><?php endif;?></tbody></table></div>
</div>
<?php if($showForm): ?><div class="panel" id="knowledge-form"><div class="content-form-heading"><div><span class="status"><?= $editing?'Edit System Knowledge':'New System Knowledge' ?></span><h2><?= $editing?'Edit System Knowledge Item':'Add System Knowledge' ?></h2></div><a class="btn" href="<?= e(url('/admin/knowledge.php')) ?>">Close</a></div>
<form class="grid-form" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>"><div class="field"><label>Title</label><input name="title" maxlength="190" required value="<?= e($editing['title']??'') ?>"></div><div class="field"><label>Song / Track</label><select name="track_id"><option value="0">General Knowledge</option><?php foreach($tracksForKnowledge as $trackOption):?><option value="<?= (int)$trackOption['id'] ?>" <?= (int)($editing['track_id']??0)===(int)$trackOption['id']?'selected':'' ?>><?= e($trackOption['title']) ?></option><?php endforeach;?></select></div><div class="field"><label>Who Can View / Use in Chat?</label><select name="visibility"><?php foreach(visibility_options() as $value=>$label):?><option value="<?= e($value) ?>" <?= (($editing['visibility']??'members')===$value)?'selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></div><div class="field"><label>Status</label><label class="admin-inline-check"><input name="is_published" type="checkbox" <?= !isset($editing['is_published'])||(int)$editing['is_published']===1?'checked':'' ?>> Published / available to authorized chat</label></div><div class="field full"><label>Description</label><textarea name="description" style="min-height:90px"><?= e($editing['description']??'') ?></textarea></div><div class="field full"><label>File</label><input name="knowledge_file" type="file" accept=".txt,.md,.csv,.json,.html,.htm,.xml,.doc,.docx,.pdf,.mp3,.m4a,.wav,.ogg"><small>TXT, Markdown, CSV, JSON, HTML, DOC/DOCX, PDF and audio. Maximum 50 MB.</small><?php if(!empty($editing['file_name'])):?><small>Current: <?= e($editing['file_name']) ?></small><?php endif;?></div><div class="field full"><label>Knowledge Text / Transcript / Notes</label><textarea name="content_text" style="min-height:260px"><?= e($editing['content_text']??'') ?></textarea></div><div class="field full actions"><button class="btn primary" type="submit"><?= $editing?'Save & Reindex':'Add to System Knowledge' ?></button><a class="btn" href="<?= e(url('/admin/knowledge.php')) ?>">Cancel</a></div></form></div><?php endif;?>
<?php require __DIR__ . '/_footer.php'; ?>