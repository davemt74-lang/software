<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (!is_logged_in()) redirect(url('/login.php'));
$user=current_user();
$pdo=db();
if(!$user||!$pdo)redirect(url('/login.php'));
if(!personal_capability_has_v242('personal_knowledge.access',$user)){
    http_response_code(403);exit('Personal Knowledge access is unavailable for this account.');
}
$canManage=personal_capability_has_v242('personal_knowledge.manage',$user);
if(!personal_capability_schema_ready_v242($pdo))redirect(url('/upgrade.php'));

$uid=(int)$user['id'];
$notice=flash('knowledge_notice');
$error=flash('knowledge_error');
$editId=$canManage?max(0,(int)($_GET['edit']??0)):0;
$editing=null;

function personal_knowledge_item_for_owner(PDO $pdo,int $id,int $owner): ?array
{
    if($id<1||$owner<1)return null;
    $stmt=$pdo->prepare("SELECT * FROM knowledge_items WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal' LIMIT 1");
    $stmt->execute([$id,$owner]);
    return $stmt->fetch()?:null;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!$canManage){http_response_code(403);exit('Personal Knowledge management is unavailable for this account.');}
    if(!verify_csrf()){
        flash('knowledge_error','Session expired. Try again.');
        redirect(url('/knowledge.php'));
    }
    $action=(string)($_POST['action']??'save');
    try{
        if($action==='delete'){
            $id=max(0,(int)($_POST['id']??0));
            $item=personal_knowledge_item_for_owner($pdo,$id,$uid);
            if(!$item)throw new RuntimeException('Personal knowledge item not found.');
            if(!empty($item['file_path']))delete_local_upload((string)$item['file_path']);
            $pdo->prepare("DELETE FROM knowledge_items WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal'")->execute([$id,$uid]);
            flash('knowledge_notice','Personal knowledge deleted.');
            redirect(url('/knowledge.php'));
        }

        $id=max(0,(int)($_POST['id']??0));
        $before=$id>0?personal_knowledge_item_for_owner($pdo,$id,$uid):null;
        if($id>0&&!$before)throw new RuntimeException('Personal knowledge item not found.');
        $title=trim((string)($_POST['title']??''));
        $description=trim((string)($_POST['description']??''));
        $content=trim((string)($_POST['content_text']??''));
        if($title==='')throw new RuntimeException('A title is required.');
        if(mb_strlen($title)>190)throw new RuntimeException('Keep the title under 190 characters.');

        $filePath=(string)($before['file_path']??'');
        $fileName=(string)($before['file_name']??'');
        $fileType=(string)($before['file_type']??'text');
        $mimeType=(string)($before['mime_type']??'text/plain');
        $fileSize=(int)($before['file_size']??0);
        $extracted='';
        $upload=$_FILES['knowledge_file']??[];
        if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            if(($upload['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('Knowledge file upload failed.');
            if((int)($upload['size']??0)>50*1024*1024)throw new RuntimeException('Knowledge files are limited to 50 MB.');
            $extension=strtolower(pathinfo((string)$upload['name'],PATHINFO_EXTENSION));
            $allowed=['txt','md','csv','json','html','htm','xml','doc','docx','pdf','mp3','m4a','wav','ogg'];
            if(!in_array($extension,$allowed,true))throw new RuntimeException('Unsupported file type.');
            $targetDir=STONEFELLOW_ROOT.'/uploads/knowledge';
            if(!is_dir($targetDir)&&!mkdir($targetDir,0755,true)&&!is_dir($targetDir))throw new RuntimeException('Could not create knowledge upload directory.');
            $newName=bin2hex(random_bytes(16)).'.'.$extension;
            $absolute=$targetDir.'/'.$newName;
            if(!move_uploaded_file((string)$upload['tmp_name'],$absolute))throw new RuntimeException('Could not save the knowledge file.');
            if($filePath!=='')delete_local_upload($filePath);
            $filePath='/uploads/knowledge/'.$newName;
            $fileName=basename((string)$upload['name']);
            $fileType=$extension;
            $fileSize=(int)$upload['size'];
            if(function_exists('finfo_open')){
                $finfo=finfo_open(FILEINFO_MIME_TYPE);
                if($finfo){$detected=finfo_file($finfo,$absolute);if(is_string($detected))$mimeType=$detected;finfo_close($finfo);}
            }
            $extracted=knowledge_extract_file_text($absolute,$extension);
        }
        if($extracted!=='')$content=trim($content."\n\n".$extracted);
        if($id>0&&$content===''&&$before)$content=(string)$before['content_text'];
        if($content==='')throw new RuntimeException('Add knowledge text or upload a file that contains extractable text.');

        if($id>0){
            $stmt=$pdo->prepare("UPDATE knowledge_items SET title=?,description=?,file_name=?,file_path=?,file_type=?,mime_type=?,file_size=?,content_text=?,visibility='private',is_published=0,knowledge_scope='personal' WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal'");
            $stmt->execute([$title,$description,$fileName,$filePath,$fileType,$mimeType,$fileSize,$content,$id,$uid]);
            reindex_knowledge_item($id,$content);
            if(function_exists('shared_knowledge_index_sync_item_v236'))shared_knowledge_index_sync_item_v236($pdo,$id);
            flash('knowledge_notice','Personal knowledge updated.');
        }else{
            $stmt=$pdo->prepare("INSERT INTO knowledge_items (track_id,title,description,file_name,file_path,file_type,mime_type,file_size,content_text,visibility,is_published,created_by_user_id,knowledge_scope) VALUES (NULL,?,?,?,?,?,?,?,?,'private',0,?,'personal')");
            $stmt->execute([$title,$description,$fileName,$filePath,$fileType,$mimeType,$fileSize,$content,$uid]);
            $id=(int)$pdo->lastInsertId();
            reindex_knowledge_item($id,$content);
            flash('knowledge_notice','Personal knowledge added.');
        }
        redirect(url('/knowledge.php?edit='.$id));
    }catch(Throwable $e){
        flash('knowledge_error',$e->getMessage());
        $target='/knowledge.php'.($editId>0?'?edit='.$editId:'');
        redirect(url($target));
    }
}

if($editId>0)$editing=personal_knowledge_item_for_owner($pdo,$editId,$uid);
$stmt=$pdo->prepare("SELECT i.*,(SELECT COUNT(*) FROM knowledge_chunks c WHERE c.knowledge_id=i.id) chunk_count FROM knowledge_items i WHERE i.created_by_user_id=? AND i.knowledge_scope='personal' ORDER BY i.updated_at DESC,i.id DESC");
$stmt->execute([$uid]);$items=$stmt->fetchAll()?:[];
$total=count($items);$chunks=array_sum(array_map(static fn($i)=>(int)($i['chunk_count']??0),$items));
$profileUrl=member_navigation_profile_url($user);
$profileAgentAllowed=personal_capability_has_v242('profile_agent.access',$user);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#ffffff"><title>My Knowledge | <?= e(system_agent_name()) ?></title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/personal-knowledge.css?v=personal-knowledge-v242-20260905')) ?>"></head>
<body><div class="chat-app personal-knowledge-app">
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
<div class="personal-knowledge-hero"><div><small>Personal Agent Data</small><h1>Your Knowledge Base</h1><p>These records belong to your account only. Your private Agent can use them when Personal Knowledge is enabled. Sharing with your Profile Agent, connections or Stonefellow is controlled separately through your data-access settings.</p></div><div class="personal-knowledge-stats"><div class="personal-knowledge-stat"><strong><?= $total ?></strong><span>Items</span></div><div class="personal-knowledge-stat"><strong><?= $chunks ?></strong><span>Indexed chunks</span></div></div></div>
<?php if($notice):?><div class="personal-knowledge-notice"><?= e($notice) ?></div><?php endif;?><?php if($error):?><div class="personal-knowledge-notice error"><?= e($error) ?></div><?php endif;?>
<div class="personal-knowledge-layout"><section class="personal-knowledge-panel"><div class="personal-knowledge-panel-head"><div><h2>Personal Knowledge</h2><p>Only records owned by <?= e((string)$user['display_name']) ?> are shown here.</p></div></div><div class="personal-knowledge-list">
<?php foreach($items as $item):?><article class="personal-knowledge-row"><div><h3><?= e((string)$item['title']) ?></h3><p><?= e(mb_strimwidth(trim((string)$item['description'])!==''?(string)$item['description']:(string)$item['content_text'],0,220,'…')) ?></p><div class="personal-knowledge-meta"><span><?= e(strtoupper((string)$item['file_type'])) ?></span><span><?= (int)$item['chunk_count'] ?> chunks</span><span>Private to you</span></div><details><summary>View knowledge</summary><p><?= nl2br(e((string)$item['content_text'])) ?></p></details></div><div class="personal-knowledge-actions"><?php if(!empty($item['file_path'])):?><a href="<?= e(url('/knowledge-file.php?id='.(int)$item['id'])) ?>" target="_blank">Open file</a><?php endif;?><?php if($canManage):?><a href="<?= e(url('/knowledge.php?edit='.(int)$item['id'].'#knowledge-form')) ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this personal knowledge item?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="danger" type="submit">Delete</button></form><?php endif;?></div></article><?php endforeach;?>
<?php if(!$items):?><div class="personal-knowledge-empty">No personal knowledge yet.<?= $canManage?' Add a note, document, transcript or reference for your Agent.':'' ?></div><?php endif;?></div></section>
<aside class="personal-knowledge-panel" id="knowledge-form"><?php if($canManage):?><div class="personal-knowledge-panel-head"><div><h2><?= $editing?'Edit Knowledge':'Add Knowledge' ?></h2><p><?= $editing?'Update this private record.':'Create a private record for your Agent.' ?></p></div><?php if($editing):?><a href="<?= e(url('/knowledge.php#knowledge-form')) ?>">New</a><?php endif;?></div><form class="personal-knowledge-form" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>"><label class="personal-knowledge-field"><span>Title</span><input name="title" maxlength="190" required value="<?= e((string)($editing['title']??'')) ?>" placeholder="What should your Agent know?"></label><label class="personal-knowledge-field"><span>Description</span><input name="description" maxlength="2000" value="<?= e((string)($editing['description']??'')) ?>" placeholder="Optional context"></label><label class="personal-knowledge-field"><span>Knowledge text</span><textarea name="content_text" placeholder="Notes, facts, preferences, transcript, reference material…"><?= e((string)($editing['content_text']??'')) ?></textarea></label><label class="personal-knowledge-field"><span>Attach a file</span><input name="knowledge_file" type="file" accept=".txt,.md,.csv,.json,.html,.htm,.xml,.doc,.docx,.pdf,.mp3,.m4a,.wav,.ogg"><small>TXT, Markdown, CSV, JSON, HTML, DOC/DOCX, PDF or audio · max 50 MB.<?php if(!empty($editing['file_name'])):?> Current: <?= e((string)$editing['file_name']) ?>.<?php endif;?></small></label><div class="personal-knowledge-form-actions"><button class="primary" type="submit"><?= $editing?'Save Knowledge':'Add to My Knowledge' ?></button><?php if($editing):?><a href="<?= e(url('/knowledge.php')) ?>">Cancel</a><?php endif;?></div></form><?php else:?><div class="personal-knowledge-panel-head"><div><h2>Read-only access</h2><p>You can view and use your Personal Knowledge, but this account type cannot create, edit or delete records.</p></div></div><?php endif;?></aside></div>
<div class="personal-knowledge-privacy"><strong>Ownership boundary:</strong> this page never lists or edits another member's records and it never manages the system Knowledge Base. Admin system knowledge remains under Admin → Knowledge. Profile Agent and network sharing are separate policy decisions.</div>
</div></section></main></div><script src="<?= e(url('/member-shell-v77.js?v=universal-member-header-20260905')) ?>"></script></body></html>
