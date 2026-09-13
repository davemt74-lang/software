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
$folders=personal_knowledge_folders($pdo,$user);
$folderFilterRaw=trim((string)($_GET['folder']??'all'));
$folderFilter='all';
if($folderFilterRaw==='unfiled')$folderFilter='unfiled';
elseif(ctype_digit($folderFilterRaw)&&personal_knowledge_folder($pdo,$user,(int)$folderFilterRaw))$folderFilter=(string)(int)$folderFilterRaw;

function personal_knowledge_item_for_owner(PDO $pdo,int $id,int $owner): ?array
{
    if($id<1||$owner<1)return null;
    $stmt=$pdo->prepare("SELECT * FROM knowledge_items WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal' LIMIT 1");
    $stmt->execute([$id,$owner]);
    return $stmt->fetch()?:null;
}

function personal_knowledge_artist_listening_source(array $item): array
{
    $content=(string)($item['content_text']??'');$description=(string)($item['description']??'');
    if(!str_contains($content,'Source: Artist Listening')&&!str_contains($description,'Source: Artist Listening')&&!str_contains($description,'transcription intelligence'))return ['is_source'=>false,'url'=>''];
    $sourceUrl='';
    if(preg_match('/^Evidence:\s*(\S+)/mi',$content,$m)){
        $candidate=html_entity_decode(trim((string)$m[1]),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $path=(string)(parse_url($candidate,PHP_URL_PATH)??'');
        if(str_ends_with($path,'/artist-listening-evidence.php')||$path==='artist-listening-evidence.php')$sourceUrl=$candidate;
    }
    return ['is_source'=>true,'url'=>$sourceUrl];
}

function personal_knowledge_redirect_target(string $folderFilter,int $editId=0): string
{
    $params=[];
    if($folderFilter!=='all')$params['folder']=$folderFilter;
    if($editId>0)$params['edit']=$editId;
    $query=$params?'?'.http_build_query($params):'';
    return '/knowledge.php'.$query;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!$canManage){http_response_code(403);exit('Personal Knowledge management is unavailable for this account.');}
    if(!verify_csrf()){
        flash('knowledge_error','Session expired. Try again.');
        redirect(url(personal_knowledge_redirect_target($folderFilter)));
    }
    $action=(string)($_POST['action']??'save');
    try{
        if($action==='create_folder'){
            $folder=personal_knowledge_create_folder($pdo,$user,(string)($_POST['folder_name']??''));
            flash('knowledge_notice','Folder created.');
            redirect(url('/knowledge.php?folder='.(int)$folder['id'].'#knowledge-form'));
        }
        if($action==='delete'){
            $id=max(0,(int)($_POST['id']??0));
            $item=personal_knowledge_item_for_owner($pdo,$id,$uid);
            if(!$item)throw new RuntimeException('Personal knowledge item not found.');
            if(!empty($item['file_path']))delete_local_upload((string)$item['file_path']);
            $pdo->prepare("DELETE FROM knowledge_items WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal'")->execute([$id,$uid]);
            flash('knowledge_notice','Personal knowledge deleted.');
            redirect(url(personal_knowledge_redirect_target($folderFilter)));
        }

        $id=max(0,(int)($_POST['id']??0));
        $before=$id>0?personal_knowledge_item_for_owner($pdo,$id,$uid):null;
        if($id>0&&!$before)throw new RuntimeException('Personal knowledge item not found.');
        $title=trim((string)($_POST['title']??''));
        $description=trim((string)($_POST['description']??''));
        $content=trim((string)($_POST['content_text']??''));
        $requestedFolderId=max(0,(int)($_POST['folder_id']??0));
        $resolvedFolderId=personal_knowledge_resolve_folder_id($pdo,$user,$requestedFolderId);
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
            $stmt=$pdo->prepare("UPDATE knowledge_items SET folder_id=?,title=?,description=?,file_name=?,file_path=?,file_type=?,mime_type=?,file_size=?,content_text=?,visibility='private',is_published=0,knowledge_scope='personal' WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal'");
            $stmt->execute([$resolvedFolderId,$title,$description,$fileName,$filePath,$fileType,$mimeType,$fileSize,$content,$id,$uid]);
            reindex_knowledge_item($id,$content);
            if(function_exists('shared_knowledge_index_sync_item_v236'))shared_knowledge_index_sync_item_v236($pdo,$id);
            flash('knowledge_notice','Personal knowledge updated.');
        }else{
            $stmt=$pdo->prepare("INSERT INTO knowledge_items (track_id,folder_id,title,description,file_name,file_path,file_type,mime_type,file_size,content_text,visibility,is_published,created_by_user_id,knowledge_scope) VALUES (NULL,?,?,?,?,?,?,?,?,?,'private',0,?,'personal')");
            $stmt->execute([$resolvedFolderId,$title,$description,$fileName,$filePath,$fileType,$mimeType,$fileSize,$content,$uid]);
            $id=(int)$pdo->lastInsertId();
            reindex_knowledge_item($id,$content);
            if(function_exists('shared_knowledge_index_sync_item_v236'))shared_knowledge_index_sync_item_v236($pdo,$id);
            flash('knowledge_notice','Personal knowledge added.');
        }
        $nextFolder=$resolvedFolderId!==null?(string)$resolvedFolderId:'unfiled';
        redirect(url(personal_knowledge_redirect_target($nextFolder,$id).'#knowledge-form'));
    }catch(Throwable $e){
        flash('knowledge_error',$e->getMessage());
        $target=personal_knowledge_redirect_target($folderFilter,$editId);
        redirect(url($target.($editId>0?'#knowledge-form':'')));
    }
}

if($editId>0)$editing=personal_knowledge_item_for_owner($pdo,$editId,$uid);
$where="i.created_by_user_id=? AND i.knowledge_scope='personal'";
$params=[$uid];
if($folderFilter==='unfiled')$where.=' AND i.folder_id IS NULL';
elseif(ctype_digit($folderFilter)){$where.=' AND i.folder_id=?';$params[]=(int)$folderFilter;}
$stmt=$pdo->prepare("SELECT i.*,f.folder_name,(SELECT COUNT(*) FROM knowledge_chunks c WHERE c.knowledge_id=i.id) chunk_count FROM knowledge_items i LEFT JOIN artist_transcript_folders_v177 f ON f.id=i.folder_id AND f.created_by_user_id=i.created_by_user_id WHERE {$where} ORDER BY i.updated_at DESC,i.id DESC");
$stmt->execute($params);$items=$stmt->fetchAll()?:[];
$countStmt=$pdo->prepare("SELECT folder_id,COUNT(*) item_count FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal' GROUP BY folder_id");
$countStmt->execute([$uid]);$folderCounts=[];foreach($countStmt->fetchAll()?:[] as $row)$folderCounts[(int)($row['folder_id']??0)]=(int)$row['item_count'];
$total=array_sum($folderCounts);$chunks=array_sum(array_map(static fn($i)=>(int)($i['chunk_count']??0),$items));
$profileUrl=member_navigation_profile_url($user);
$profileAgentAllowed=personal_capability_has_v242('profile_agent.access',$user);
$selectedFolderId=$editing?max(0,(int)($editing['folder_id']??0)):(ctype_digit($folderFilter)?(int)$folderFilter:0);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#ffffff"><title>My Knowledge | <?= e(system_agent_name()) ?></title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/personal-knowledge.css?v=personal-knowledge-v313-20260913')) ?>"><style>
.personal-knowledge-source{display:inline-flex;align-items:center;gap:6px;margin-top:7px;padding:5px 8px;border:1px solid #dedede;border-radius:999px;background:#fafafa;color:#333;font-size:10px;font-weight:750;text-decoration:none}.personal-knowledge-source:hover{background:#f1f1f1}.personal-knowledge-folderbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 18px}.personal-knowledge-folderbar a{display:inline-flex;gap:7px;align-items:center;padding:8px 11px;border:1px solid #ddd;border-radius:10px;text-decoration:none;color:#222;background:#fff;font-size:12px}.personal-knowledge-folderbar a.active{border-color:#111;background:#111;color:#fff}.personal-knowledge-folderbar small{opacity:.7}.personal-knowledge-new-folder{display:flex;gap:7px;margin-left:auto}.personal-knowledge-new-folder input{min-width:150px;padding:8px 10px;border:1px solid #ddd;border-radius:9px}.personal-knowledge-new-folder button{padding:8px 11px;border:1px solid #222;border-radius:9px;background:#fff;cursor:pointer}.personal-knowledge-folder-chip{display:inline-flex;padding:3px 7px;border-radius:999px;background:#f0f0f0;font-size:10px;font-weight:700}
</style></head>
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
<div class="personal-knowledge-hero"><div><small>Personal Agent Data</small><h1>Your Knowledge Base</h1><p>Upload documents or media, write private notes, and organize everything with the same folders used by your transcription and music workflows.</p></div><div class="personal-knowledge-stats"><div class="personal-knowledge-stat"><strong><?= $total ?></strong><span>Items</span></div><div class="personal-knowledge-stat"><strong><?= $chunks ?></strong><span>Visible chunks</span></div></div></div>
<?php if($notice):?><div class="personal-knowledge-notice"><?= e($notice) ?></div><?php endif;?><?php if($error):?><div class="personal-knowledge-notice error"><?= e($error) ?></div><?php endif;?>
<nav class="personal-knowledge-folderbar" aria-label="Knowledge folders">
<a class="<?= $folderFilter==='all'?'active':'' ?>" href="<?= e(url('/knowledge.php')) ?>">All <small><?= $total ?></small></a>
<a class="<?= $folderFilter==='unfiled'?'active':'' ?>" href="<?= e(url('/knowledge.php?folder=unfiled')) ?>">Unfiled <small><?= $folderCounts[0]??0 ?></small></a>
<?php foreach($folders as $folder):$fid=(int)$folder['id'];?><a class="<?= $folderFilter===(string)$fid?'active':'' ?>" href="<?= e(url('/knowledge.php?folder='.$fid)) ?>">▱ <?= e((string)$folder['folder_name']) ?> <small><?= $folderCounts[$fid]??0 ?></small></a><?php endforeach;?>
<?php if($canManage):?><form class="personal-knowledge-new-folder" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create_folder"><input name="folder_name" maxlength="80" placeholder="New folder" aria-label="New folder name"><button type="submit">+ Folder</button></form><?php endif;?>
</nav>
<div class="personal-knowledge-layout"><section class="personal-knowledge-panel"><div class="personal-knowledge-panel-head"><div><h2><?= $folderFilter==='all'?'Personal Knowledge':($folderFilter==='unfiled'?'Unfiled':e((string)(personal_knowledge_folder($pdo,$user,(int)$folderFilter)['folder_name']??'Folder'))) ?></h2><p>Only records owned by <?= e((string)$user['display_name']) ?> are shown here.</p></div></div><div class="personal-knowledge-list">
<?php foreach($items as $item):$artistSource=personal_knowledge_artist_listening_source($item);?><article id="knowledge-<?= (int)$item['id'] ?>" class="personal-knowledge-row"><div><h3><?= e((string)$item['title']) ?></h3><p><?= e(mb_strimwidth(trim((string)$item['description'])!==''?(string)$item['description']:(string)$item['content_text'],0,220,'…')) ?></p><div class="personal-knowledge-meta"><span><?= e(strtoupper((string)$item['file_type'])) ?></span><span><?= (int)$item['chunk_count'] ?> chunks</span><span class="personal-knowledge-folder-chip"><?= e((string)($item['folder_name']??'Unfiled')) ?></span><span>Private to you</span><?php if($artistSource['is_source']):?><span>Source: Artist Listening</span><?php endif;?></div><?php if($artistSource['is_source']&&$artistSource['url']!==''):?><a class="personal-knowledge-source" href="<?= e($artistSource['url']) ?>">Source: Artist Listening · Open evidence</a><?php endif;?><details><summary>View knowledge</summary><p><?= nl2br(e((string)$item['content_text'])) ?></p></details></div><div class="personal-knowledge-actions"><?php if(!empty($item['file_path'])):?><a href="<?= e(url('/knowledge-file.php?id='.(int)$item['id'])) ?>" target="_blank">Open file</a><?php endif;?><?php if($canManage):?><a href="<?= e(url('/knowledge.php?'.http_build_query(array_filter(['folder'=>$folderFilter!=='all'?$folderFilter:null,'edit'=>(int)$item['id']],static fn($v)=>$v!==null)).'#knowledge-form')) ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this personal knowledge item?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="danger" type="submit">Delete</button></form><?php endif;?></div></article><?php endforeach;?>
<?php if(!$items):?><div class="personal-knowledge-empty">No personal knowledge in this folder yet.<?= $canManage?' Add a note, document, media file, transcript or reference for your Agent.':'' ?></div><?php endif;?></div></section>
<aside class="personal-knowledge-panel" id="knowledge-form"><?php if($canManage):?><div class="personal-knowledge-panel-head"><div><h2><?= $editing?'Edit Knowledge':'Add Knowledge' ?></h2><p><?= $editing?'Update this private record or move it to another folder.':'Create or upload a private record and choose its folder.' ?></p></div><?php if($editing):?><a href="<?= e(url(personal_knowledge_redirect_target($folderFilter).'#knowledge-form')) ?>">New</a><?php endif;?></div><form class="personal-knowledge-form" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>"><label class="personal-knowledge-field"><span>Title</span><input name="title" maxlength="190" required value="<?= e((string)($editing['title']??'')) ?>" placeholder="What should your Agent know?"></label><label class="personal-knowledge-field"><span>Folder</span><select name="folder_id"><option value="0"<?= $selectedFolderId===0?' selected':'' ?>>Unfiled</option><?php foreach($folders as $folder):$fid=(int)$folder['id'];?><option value="<?= $fid ?>"<?= $selectedFolderId===$fid?' selected':'' ?>><?= e((string)$folder['folder_name']) ?></option><?php endforeach;?></select><small>Uses the same folders as My Transcriptions and music organization.</small></label><label class="personal-knowledge-field"><span>Description</span><input name="description" maxlength="2000" value="<?= e((string)($editing['description']??'')) ?>" placeholder="Optional context"></label><label class="personal-knowledge-field"><span>Knowledge text</span><textarea name="content_text" placeholder="Notes, facts, preferences, transcript, reference material…"><?= e((string)($editing['content_text']??'')) ?></textarea></label><label class="personal-knowledge-field"><span>Attach a document or media file</span><input name="knowledge_file" type="file" accept=".txt,.md,.csv,.json,.html,.htm,.xml,.doc,.docx,.pdf,.mp3,.m4a,.wav,.ogg"><small>TXT, Markdown, CSV, JSON, HTML, DOC/DOCX, PDF or audio · max 50 MB.<?php if(!empty($editing['file_name'])):?> Current: <?= e((string)$editing['file_name']) ?>.<?php endif;?></small></label><div class="personal-knowledge-form-actions"><button class="primary" type="submit"><?= $editing?'Save Knowledge':'Add to My Knowledge' ?></button><?php if($editing):?><a href="<?= e(url(personal_knowledge_redirect_target($folderFilter))) ?>">Cancel</a><?php endif;?></div></form><?php else:?><div class="personal-knowledge-panel-head"><div><h2>Read-only access</h2><p>You can view and use your Personal Knowledge, but this account type cannot create, edit or delete records.</p></div></div><?php endif;?></aside></div>
<div class="personal-knowledge-privacy"><strong>Ownership boundary:</strong> folders and records are owner-scoped. Local HomeServer folder paths remain HomeServer-only; this Cloud view stores only the shared folder identifier for Cloud knowledge items.</div>
</div></section></main></div><script src="<?= e(url('/member-shell-v77.js?v=universal-member-header-20260905')) ?>"></script></body></html>
