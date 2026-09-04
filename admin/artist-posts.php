<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';

$user=current_user();
if(!$user || !user_has_role('artist',$user) || !has_permission('posts.manage',$user)){
    http_response_code(403);exit('Artist post access is required.');
}
$pdo=db();if(!$pdo){http_response_code(503);exit('Database unavailable.');}
artist_posts_v183_ensure_schema($pdo);
$workspace=artist_workspace_v181_for_user($pdo,$user);$workspaceId=(int)$workspace['id'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');redirect(url('/admin/artist-posts.php'));}
    $action=(string)($_POST['action']??'save');$id=(int)($_POST['id']??0);
    $existing=$id>0?artist_posts_v183_post($pdo,$workspaceId,$id):null;
    if($id>0 && !$existing){flash('error','Post not found in your artist workspace.');redirect(url('/admin/artist-posts.php'));}
    try{
        if($action==='delete'){
            $pdo->prepare('DELETE FROM artist_posts_v181 WHERE id=? AND workspace_id=?')->execute([$id,$workspaceId]);
            flash('notice','Post deleted.');redirect(url('/admin/artist-posts.php'));
        }
        if($action!=='save')throw new RuntimeException('Unknown post action.');
        $title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));
        $postType=trim((string)($_POST['post_type']??'update'));$visibility=trim((string)($_POST['visibility']??'public'));
        $mediaUrl=artist_workspace_v181_validate_external_url((string)($_POST['media_url']??''));
        $photoId=artist_posts_v183_validate_photo($pdo,$workspaceId,(int)($_POST['image_photo_id']??0));
        $published=isset($_POST['is_published'])?1:0;
        if($title==='')throw new RuntimeException('Post title is required.');
        if($body==='')throw new RuntimeException('Post text is required.');
        if(mb_strlen($title)>190)throw new RuntimeException('Post title is too long.');
        if(!in_array($postType,['update','announcement','release','behind-the-scenes'],true))throw new RuntimeException('Choose a valid post type.');
        if(!valid_visibility($visibility))throw new RuntimeException('Choose a valid visibility group.');
        $publishedAt=$published?(!empty($existing['published_at'])?(string)$existing['published_at']:date('Y-m-d H:i:s')):null;
        if($id>0){
            $stmt=$pdo->prepare('UPDATE artist_posts_v181 SET title=?,body=?,post_type=?,image_photo_id=?,media_url=?,visibility=?,is_published=?,published_at=? WHERE id=? AND workspace_id=?');
            $stmt->execute([$title,$body,$postType,$photoId?:null,$mediaUrl,$visibility,$published,$publishedAt,$id,$workspaceId]);
        }else{
            $stmt=$pdo->prepare("INSERT INTO artist_posts_v181 (workspace_id,title,body,post_type,image_photo_id,image_path,media_url,visibility,is_published,published_at) VALUES (?,?,?,?,?,'',?,?,?,?)");
            $stmt->execute([$workspaceId,$title,$body,$postType,$photoId?:null,$mediaUrl,$visibility,$published,$publishedAt]);
        }
        flash('notice',$published?'Post published.':'Draft saved.');
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect(url('/admin/artist-posts.php'));
}

$filter=(string)($_GET['filter']??'all');if(!in_array($filter,['all','published','draft'],true))$filter='all';
$editId=(int)($_GET['edit']??0);$editing=$editId>0?artist_posts_v183_post($pdo,$workspaceId,$editId):null;
$showForm=isset($_GET['new']) || $editing!==null;
$posts=artist_posts_v183_list($pdo,$workspaceId,$filter,250);$photos=artist_media_v182_picker($pdo,$workspaceId,120);
$countStmt=$pdo->prepare('SELECT SUM(is_published=1) AS published_count,SUM(is_published=0) AS draft_count FROM artist_posts_v181 WHERE workspace_id=?');$countStmt->execute([$workspaceId]);$counts=$countStmt->fetch()?:[];
$publishedCount=(int)($counts['published_count']??0);$draftCount=(int)($counts['draft_count']??0);
$adminTitle='Posts';$adminActive='posts';require __DIR__.'/_header.php';
?>
<style>
.artist-post-toolbar{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}.artist-post-filters{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}.artist-post-filters .active{box-shadow:inset 0 0 0 1px rgba(255,255,255,.55)}.artist-post-list{display:grid;gap:14px}.artist-post-card{border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:16px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px}.artist-post-card.has-image{grid-template-columns:120px minmax(0,1fr) auto}.artist-post-card>img{width:120px;height:100px;object-fit:cover;border-radius:10px}.artist-post-copy p{margin:8px 0;color:#aaa;line-height:1.5;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.artist-post-actions{display:flex;align-items:flex-start;gap:8px;flex-wrap:wrap}.artist-post-actions form{margin:0}.artist-post-preview{border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:18px}.artist-post-preview img{width:100%;max-height:320px;object-fit:cover;border-radius:10px;margin-bottom:14px}.artist-photo-picker{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;max-height:440px;overflow:auto;padding:4px}.artist-photo-choice{display:block;border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:8px;cursor:pointer}.artist-photo-choice:has(input:checked){border-color:#fff}.artist-photo-choice img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:7px;margin:6px 0}@media(max-width:720px){.artist-post-card,.artist-post-card.has-image{grid-template-columns:1fr}.artist-post-card>img{width:100%;height:auto;aspect-ratio:16/9}.artist-post-actions{justify-content:flex-start}}
</style>
<div class="panel">
  <div class="artist-post-toolbar"><div><p class="muted">Artist publishing</p><h2>Posts</h2><p class="muted">Publish updates to your public Artist Profile using images from your Media Library.</p></div><div class="actions"><a class="btn" href="<?= e(url('/admin/artist.php')) ?>">Artist Workspace</a><a class="btn primary" href="<?= e(url('/admin/artist-posts.php?new=1#post-form')) ?>">+ New Post</a></div></div>
  <div class="artist-post-filters"><a class="btn <?= $filter==='all'?'active':'' ?>" href="<?= e(url('/admin/artist-posts.php?filter=all')) ?>">All <?= $publishedCount+$draftCount ?></a><a class="btn <?= $filter==='published'?'active':'' ?>" href="<?= e(url('/admin/artist-posts.php?filter=published')) ?>">Published <?= $publishedCount ?></a><a class="btn <?= $filter==='draft'?'active':'' ?>" href="<?= e(url('/admin/artist-posts.php?filter=draft')) ?>">Drafts <?= $draftCount ?></a></div>
  <div class="artist-post-list">
  <?php foreach($posts as $post): $postImage=(int)($post['image_photo_id']??0)>0?url('/content-image.php?type=artist_photo&id='.(int)$post['image_photo_id']):''; ?>
    <article class="artist-post-card <?= $postImage?'has-image':'' ?>"><?php if($postImage): ?><img src="<?= e($postImage) ?>" alt="" loading="lazy"><?php endif; ?><div class="artist-post-copy"><small class="muted"><?= !empty($post['is_published'])?'Published':'Draft' ?> · <?= e(ucwords(str_replace('-',' ',(string)$post['post_type']))) ?> · <?= e(visibility_options()[$post['visibility']]??(string)$post['visibility']) ?></small><strong><?= e((string)$post['title']) ?></strong><p><?= e((string)$post['body']) ?></p><?php if(!empty($post['published_at'])): ?><small class="muted"><?= e(date('M j, Y g:i A',strtotime((string)$post['published_at']))) ?></small><?php endif; ?></div><div class="artist-post-actions"><a class="btn" href="<?= e(url('/admin/artist-posts.php?edit='.(int)$post['id'].'#post-form')) ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this post?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$post['id'] ?>"><button class="btn danger" type="submit">Delete</button></form></div></article>
  <?php endforeach; ?><?php if(!$posts): ?><p class="muted">No <?= e($filter==='all'?'posts':$filter.' posts') ?> yet.</p><?php endif; ?>
  </div>
</div>
<?php if($showForm): $selectedPhoto=(int)($editing['image_photo_id']??0); ?>
<div class="panel" id="post-form"><div class="content-library-heading"><div><p class="muted"><?= $editing?'Edit post':'New post' ?></p><h2><?= $editing?'Post Editor':'Create Post' ?></h2></div><a class="btn" href="<?= e(url('/admin/artist-posts.php')) ?>">Close</a></div>
<form method="post" class="form-grid" id="artistPostForm"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>">
<label><span>Title</span><input id="postTitle" name="title" maxlength="190" required value="<?= e((string)($editing['title']??'')) ?>"></label>
<label><span>Post type</span><select name="post_type"><?php foreach(['update'=>'Update','announcement'=>'Announcement','release'=>'Release','behind-the-scenes'=>'Behind the scenes'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= (string)($editing['post_type']??'update')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label class="wide"><span>Post</span><textarea id="postBody" name="body" rows="8" required><?= e((string)($editing['body']??'')) ?></textarea></label>
<label><span>External media URL</span><input name="media_url" type="url" inputmode="url" value="<?= e((string)($editing['media_url']??'')) ?>" placeholder="https://"></label>
<label><span>Visibility</span><select name="visibility"><?php foreach(visibility_options() as $value=>$label): ?><option value="<?= e($value) ?>" <?= (string)($editing['visibility']??'public')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<div class="wide"><strong>Post image</strong><p class="muted">Choose an image from your Media Library, or leave the post text-only.</p><div class="artist-photo-picker"><label class="artist-photo-choice"><input type="radio" name="image_photo_id" value="0" <?= $selectedPhoto===0?'checked':'' ?> data-post-photo=""><span>No image</span></label><?php foreach($photos as $photo): $src=url('/content-image.php?type=artist_photo&id='.(int)$photo['id']); ?><label class="artist-photo-choice"><input type="radio" name="image_photo_id" value="<?= (int)$photo['id'] ?>" <?= $selectedPhoto===(int)$photo['id']?'checked':'' ?> data-post-photo="<?= e($src) ?>"><img src="<?= e($src) ?>" alt="<?= e((string)($photo['alt_text']??'')) ?>" loading="lazy"><small><?= e((string)$photo['title']) ?></small></label><?php endforeach; ?></div><?php if(!$photos): ?><p class="muted"><a href="<?= e(url('/admin/artist-media.php?new=1')) ?>">Upload a photo to the Media Library</a> to add imagery.</p><?php endif; ?></div>
<label class="wide"><span><input type="checkbox" name="is_published" value="1" <?= !empty($editing['is_published'])?'checked':'' ?>> Publish on Artist Profile</span></label>
<div class="wide artist-post-preview"><p class="muted">Preview</p><img id="postPreviewImage" alt="" style="<?= $selectedPhoto>0?'':'display:none' ?>"<?php if($selectedPhoto>0): ?> src="<?= e(url('/content-image.php?type=artist_photo&id='.$selectedPhoto)) ?>"<?php endif; ?>><h3 id="postPreviewTitle"><?= e((string)($editing['title']??'Your post title')) ?></h3><p id="postPreviewBody"><?= nl2br(e((string)($editing['body']??'Your post will appear here.'))) ?></p></div>
<div class="wide actions"><button class="btn primary" type="submit"><?= !empty($editing['is_published'])?'Update Post':'Save Post' ?></button><a class="btn" href="<?= e(url('/admin/artist-posts.php')) ?>">Cancel</a></div>
</form></div>
<script>
(()=>{const t=document.getElementById('postTitle'),b=document.getElementById('postBody'),pt=document.getElementById('postPreviewTitle'),pb=document.getElementById('postPreviewBody'),pi=document.getElementById('postPreviewImage');const sync=()=>{if(pt)pt.textContent=t.value||'Your post title';if(pb)pb.textContent=b.value||'Your post will appear here.'};if(t)t.addEventListener('input',sync);if(b)b.addEventListener('input',sync);document.querySelectorAll('[data-post-photo]').forEach(r=>r.addEventListener('change',()=>{if(!r.checked||!pi)return;const src=r.dataset.postPhoto||'';if(src){pi.src=src;pi.style.display='block'}else{pi.removeAttribute('src');pi.style.display='none'}}))})();
</script>
<?php endif; ?>
<?php require __DIR__.'/_footer.php'; ?>
