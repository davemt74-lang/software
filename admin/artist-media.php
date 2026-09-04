<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';

$user=current_user();
if(!$user || !user_has_role('artist',$user) || !has_permission('photos.manage',$user)){
    http_response_code(403);exit('Artist media access is required.');
}
$pdo=db();if(!$pdo){http_response_code(503);exit('Database unavailable.');}
artist_workspace_v181_ensure_schema($pdo);
artist_media_v182_ensure_schema($pdo);
$workspace=artist_workspace_v181_for_user($pdo,$user);
$workspaceId=(int)$workspace['id'];

function artist_media_admin_remove_profile_image(int $workspaceId,string $storedPath): void
{
    $path=artist_workspace_v181_owned_image_path($workspaceId,$storedPath);
    if($path) @unlink($path);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');redirect(url('/admin/artist-media.php'));}
    $action=(string)($_POST['action']??'save');
    $id=(int)($_POST['id']??0);
    $existing=$id>0?artist_media_v182_photo($pdo,$workspaceId,$id):null;
    if($id>0 && !$existing){flash('error','Photo not found in your artist workspace.');redirect(url('/admin/artist-media.php'));}

    try{
        if($action==='delete'){
            $pdo->prepare('DELETE FROM artist_catalog_photos_v181 WHERE id=? AND workspace_id=?')->execute([$id,$workspaceId]);
            artist_media_v182_delete_owned_photo($workspaceId,(string)($existing['image_path']??''));
            flash('notice','Photo deleted.');
            redirect(url('/admin/artist-media.php'));
        }

        if(in_array($action,['use_profile','use_cover'],true)){
            $kind=$action==='use_profile'?'profile':'cover';
            $column=$kind==='profile'?'profile_image_path':'cover_image_path';
            $newPath=artist_media_v182_copy_photo_to_profile($pdo,$workspaceId,$id,$kind);
            $oldPath=(string)($workspace[$column]??'');
            try{
                $stmt=$pdo->prepare("UPDATE artist_workspaces_v181 SET {$column}=? WHERE id=? AND artist_user_id=?");
                $stmt->execute([$newPath,$workspaceId,(int)$user['id']]);
            }catch(Throwable $e){artist_media_admin_remove_profile_image($workspaceId,$newPath);throw $e;}
            if($oldPath!=='')artist_media_admin_remove_profile_image($workspaceId,$oldPath);
            flash('notice',$kind==='profile'?'Profile image updated.':'Cover image updated.');
            redirect(url('/admin/artist-media.php'));
        }

        if($action!=='save') throw new RuntimeException('Unknown media action.');
        $title=trim((string)($_POST['title']??''));
        $caption=trim((string)($_POST['caption']??''));
        $alt=trim((string)($_POST['alt_text']??''));
        $visibility=trim((string)($_POST['visibility']??'members'));
        $sortOrder=(int)($_POST['sort_order']??0);
        $published=isset($_POST['is_published'])?1:0;
        if($title==='') throw new RuntimeException('Photo title is required.');
        if(!valid_visibility($visibility)) throw new RuntimeException('Choose a valid visibility group.');
        if(mb_strlen($title)>190) throw new RuntimeException('Photo title is too long.');
        if(mb_strlen($alt)>255) throw new RuntimeException('Alt text is too long.');

        $oldPath=(string)($existing['image_path']??'');
        $newPath=artist_media_v182_store_photo($_FILES['photo_file']??[],$workspaceId);
        $imagePath=$newPath!==''?$newPath:$oldPath;
        if($imagePath==='') throw new RuntimeException('Choose a JPG, PNG, or WebP image.');

        try{
            if($id>0){
                $stmt=$pdo->prepare('UPDATE artist_catalog_photos_v181 SET title=?,caption=?,alt_text=?,image_path=?,visibility=?,sort_order=?,is_published=? WHERE id=? AND workspace_id=?');
                $stmt->execute([$title,$caption,$alt,$imagePath,$visibility,$sortOrder,$published,$id,$workspaceId]);
            }else{
                $stmt=$pdo->prepare('INSERT INTO artist_catalog_photos_v181 (workspace_id,title,caption,alt_text,image_path,visibility,sort_order,is_published) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$workspaceId,$title,$caption,$alt,$imagePath,$visibility,$sortOrder,$published]);
            }
        }catch(Throwable $e){if($newPath!=='')artist_media_v182_delete_owned_photo($workspaceId,$newPath);throw $e;}
        if($newPath!=='' && $oldPath!=='')artist_media_v182_delete_owned_photo($workspaceId,$oldPath);
        flash('notice',$id>0?'Photo updated.':'Photo uploaded.');
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect(url('/admin/artist-media.php'));
}

$editId=(int)($_GET['edit']??0);
$editing=$editId>0?artist_media_v182_photo($pdo,$workspaceId,$editId):null;
$showForm=isset($_GET['new']) || $editing!==null;
$photos=artist_media_v182_picker($pdo,$workspaceId,250);
$adminTitle='Media Library';$adminActive='photos';require __DIR__.'/_header.php';
?>
<style>
.artist-media-toolbar{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}.artist-media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px;margin-top:20px}.artist-media-card{border:1px solid rgba(255,255,255,.1);border-radius:14px;overflow:hidden;background:rgba(255,255,255,.025)}.artist-media-thumb{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#111;cursor:zoom-in;border:0;padding:0}.artist-media-thumb img{width:100%;height:100%;object-fit:cover;display:block}.artist-media-body{padding:13px}.artist-media-body strong{display:block;margin:5px 0}.artist-media-body p{font-size:13px;line-height:1.45;min-height:38px}.artist-media-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}.artist-media-actions form{margin:0}.artist-dropzone{border:1px dashed rgba(255,255,255,.3);border-radius:12px;padding:22px;text-align:center;position:relative;transition:.15s}.artist-dropzone.drag{border-color:#fff;background:rgba(255,255,255,.06)}.artist-dropzone input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}.artist-upload-preview{max-width:240px;max-height:180px;margin:12px auto 0;border-radius:10px;display:none}.artist-lightbox{position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:9999;display:none;align-items:center;justify-content:center;padding:28px}.artist-lightbox.open{display:flex}.artist-lightbox img{max-width:95vw;max-height:90vh;border-radius:10px}.artist-lightbox button{position:absolute;top:18px;right:22px;background:transparent;border:0;color:#fff;font-size:34px;cursor:pointer}@media(max-width:650px){.artist-media-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.artist-media-body{padding:10px}.artist-media-actions .btn{font-size:11px;padding:7px}}
</style>
<div class="panel">
  <div class="artist-media-toolbar">
    <div><p class="muted">Private artist workspace</p><h2>Media Library</h2><p class="muted">Upload once, then reuse your photos across your artist profile and publishing tools.</p></div>
    <div class="actions"><a class="btn" href="<?= e(url('/admin/artist.php')) ?>">Artist Workspace</a><a class="btn primary" href="<?= e(url('/admin/artist-media.php?new=1#media-form')) ?>">+ Upload Photo</a></div>
  </div>
  <div class="artist-media-grid">
  <?php foreach($photos as $photo): $src=url('/content-image.php?type=artist_photo&id='.(int)$photo['id']); ?>
    <article class="artist-media-card">
      <button class="artist-media-thumb" type="button" data-lightbox="<?= e($src) ?>" aria-label="Preview <?= e((string)$photo['title']) ?>"><img src="<?= e($src) ?>" alt="<?= e((string)$photo['alt_text']) ?>" loading="lazy"></button>
      <div class="artist-media-body">
        <small class="muted"><?= !empty($photo['is_published'])?'Published':'Draft' ?> · <?= e(visibility_options()[$photo['visibility']]??(string)$photo['visibility']) ?></small>
        <strong><?= e((string)$photo['title']) ?></strong>
        <p class="muted"><?= e((string)($photo['caption']??'')) ?></p>
        <div class="artist-media-actions">
          <a class="btn" href="<?= e(url('/admin/artist-media.php?edit='.(int)$photo['id'].'#media-form')) ?>">Edit</a>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="use_profile"><input type="hidden" name="id" value="<?= (int)$photo['id'] ?>"><button class="btn" type="submit">Use as Profile</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="use_cover"><input type="hidden" name="id" value="<?= (int)$photo['id'] ?>"><button class="btn" type="submit">Use as Cover</button></form>
          <form method="post" onsubmit="return confirm('Delete this photo?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$photo['id'] ?>"><button class="btn danger" type="submit">Delete</button></form>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if(!$photos): ?><p class="muted">No photos yet. Upload your first artist image.</p><?php endif; ?>
  </div>
</div>

<?php if($showForm): ?>
<div class="panel" id="media-form">
  <div class="content-library-heading"><div><p class="muted"><?= $editing?'Edit photo':'New photo' ?></p><h2><?= $editing?'Photo Details':'Upload Photo' ?></h2></div><a class="btn" href="<?= e(url('/admin/artist-media.php')) ?>">Close</a></div>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>">
    <label><span>Title</span><input name="title" maxlength="190" required value="<?= e((string)($editing['title']??'')) ?>"></label>
    <label><span>Alt text</span><input name="alt_text" maxlength="255" value="<?= e((string)($editing['alt_text']??'')) ?>"></label>
    <label class="wide"><span>Caption</span><textarea name="caption" rows="3"><?= e((string)($editing['caption']??'')) ?></textarea></label>
    <div class="wide artist-dropzone" id="artistDropzone"><strong><?= $editing?'Drop a replacement image here':'Drop a photo here' ?></strong><p class="muted">or click to choose JPG, PNG or WebP</p><input id="artistPhotoFile" name="photo_file" type="file" accept="image/jpeg,image/png,image/webp" <?= $editing?'':'required' ?>><img id="artistUploadPreview" class="artist-upload-preview" alt="New image preview"></div>
    <?php if($editing): ?><div class="wide"><p class="muted">Current image</p><img src="<?= e(url('/content-image.php?type=artist_photo&id='.(int)$editing['id'])) ?>" alt="" style="max-width:260px;max-height:190px;object-fit:cover;border-radius:10px"></div><?php endif; ?>
    <label><span>Sort order</span><input name="sort_order" type="number" value="<?= (int)($editing['sort_order']??0) ?>"></label>
    <label><span>Visibility</span><select name="visibility"><?php foreach(visibility_options() as $value=>$label): ?><option value="<?= e($value) ?>" <?= (string)($editing['visibility']??'members')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label class="wide"><span><input type="checkbox" name="is_published" value="1" <?= !empty($editing['is_published'])?'checked':'' ?>> Published</span></label>
    <div class="wide actions"><button class="btn primary" type="submit"><?= $editing?'Save Photo':'Upload Photo' ?></button><a class="btn" href="<?= e(url('/admin/artist-media.php')) ?>">Cancel</a></div>
  </form>
</div>
<?php endif; ?>
<div class="artist-lightbox" id="artistLightbox" aria-hidden="true"><button type="button" aria-label="Close">×</button><img alt="Photo preview"></div>
<script>
(()=>{const input=document.getElementById('artistPhotoFile'),drop=document.getElementById('artistDropzone'),preview=document.getElementById('artistUploadPreview');if(input&&drop){['dragenter','dragover'].forEach(n=>drop.addEventListener(n,e=>{e.preventDefault();drop.classList.add('drag')}));['dragleave','drop'].forEach(n=>drop.addEventListener(n,e=>{e.preventDefault();drop.classList.remove('drag')}));const show=()=>{const f=input.files&&input.files[0];if(!f||!f.type.startsWith('image/'))return;preview.src=URL.createObjectURL(f);preview.style.display='block'};input.addEventListener('change',show);drop.addEventListener('drop',e=>{if(e.dataTransfer&&e.dataTransfer.files.length){input.files=e.dataTransfer.files;show()}})}const box=document.getElementById('artistLightbox'),img=box&&box.querySelector('img');document.querySelectorAll('[data-lightbox]').forEach(b=>b.addEventListener('click',()=>{img.src=b.dataset.lightbox;box.classList.add('open');box.setAttribute('aria-hidden','false')}));if(box){const close=()=>{box.classList.remove('open');box.setAttribute('aria-hidden','true');img.removeAttribute('src')};box.querySelector('button').addEventListener('click',close);box.addEventListener('click',e=>{if(e.target===box)close()});document.addEventListener('keydown',e=>{if(e.key==='Escape')close()})}})();
</script>
<?php require __DIR__.'/_footer.php'; ?>
