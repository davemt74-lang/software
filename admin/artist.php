<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$user=current_user();
if(!$user || !user_has_role('artist',$user)){http_response_code(403);exit('Artist workspace access is required.');}
$pdo=db();if(!$pdo){http_response_code(503);exit('Database unavailable.');}
artist_workspace_v181_ensure_schema($pdo);
artist_media_v182_ensure_schema($pdo);
$workspace=artist_workspace_v181_for_user($pdo,$user);
$workspaceId=(int)$workspace['id'];

$collections=[
 'tracks'=>['table'=>'artist_catalog_tracks_v181','label'=>'Tracks','published'=>true,'visibility'=>true,'permission'=>'tracks.manage'],
 'albums'=>['table'=>'artist_catalog_albums_v181','label'=>'Albums','published'=>true,'visibility'=>true,'permission'=>'albums.manage'],
 'shows'=>['table'=>'artist_catalog_shows_v181','label'=>'Shows','published'=>true,'visibility'=>false,'permission'=>'shows.manage'],
 'photos'=>['table'=>'artist_catalog_photos_v181','label'=>'Photos','published'=>true,'visibility'=>true,'permission'=>'photos.manage'],
 'merch'=>['table'=>'artist_catalog_merch_v181','label'=>'Merch','published'=>true,'visibility'=>true,'permission'=>'merch.manage'],
 'posts'=>['table'=>'artist_posts_v181','label'=>'Posts','published'=>true,'visibility'=>true,'permission'=>'posts.manage'],
 'releases'=>['table'=>'artist_release_plans_v181','label'=>'Release Plans','published'=>false,'visibility'=>false,'permission'=>'release.manage'],
];
$collections=array_filter($collections,static fn(array $meta):bool => $meta['permission']==='release.manage'?permission_v105_has($meta['permission'],$user):has_permission($meta['permission'],$user));
if(!$collections){http_response_code(403);exit('Artist workspace access is required.');}
$active=in_array((string)($_GET['collection']??'tracks'),array_keys($collections),true)?(string)$_GET['collection']:'tracks';
if(!isset($collections[$active]))$active=(string)array_key_first($collections);
if($_SERVER['REQUEST_METHOD']==='GET' && $active==='photos')redirect(url('/admin/artist-media.php'));

function artist_v181_row(PDO $pdo,string $table,int $id,int $wid): ?array
{
    $s=$pdo->prepare("SELECT * FROM {$table} WHERE id=? AND workspace_id=? LIMIT 1");
    $s->execute([$id,$wid]);
    return $s->fetch()?:null;
}
function artist_v181_datetime_value(?string $value): ?string
{
    if(!$value)return null;
    $time=strtotime($value);
    return $time===false?null:date('Y-m-d H:i:s',$time);
}
function artist_v181_remove_owned_image(int $workspaceId,string $storedPath): void
{
    $path=artist_workspace_v181_owned_image_path($workspaceId,$storedPath);
    if($path)@unlink($path);
}
function artist_v181_copy_library_photo(PDO $pdo,int $workspaceId,int $photoId,string $kind): string
{
    if($photoId<1)return '';
    return artist_media_v182_copy_photo_to_profile($pdo,$workspaceId,$photoId,$kind);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');redirect(url('/admin/artist.php'));}
    $action=(string)($_POST['action']??'save');
    if($action==='save_profile'){
        $newProfile='';$newCover='';
        try{
            $slug=artist_workspace_v181_slug((string)($_POST['profile_slug']??''));
            if($slug==='')throw new RuntimeException('Profile slug is required.');
            $check=$pdo->prepare('SELECT 1 FROM artist_workspaces_v181 WHERE profile_slug=? AND id<>? LIMIT 1');
            $check->execute([$slug,$workspaceId]);
            if($check->fetchColumn())throw new RuntimeException('That profile slug is already in use.');
            $links=[];
            foreach(['website_url','instagram_url','tiktok_url','youtube_url','spotify_url','apple_music_url'] as $field)$links[$field]=artist_workspace_v181_validate_external_url((string)($_POST[$field]??''));
            $profilePath=(string)($workspace['profile_image_path']??'');$coverPath=(string)($workspace['cover_image_path']??'');
            $newProfile=artist_workspace_v181_store_profile_image($_FILES['profile_image']??[],$workspaceId,'profile');
            $newCover=artist_workspace_v181_store_profile_image($_FILES['cover_image']??[],$workspaceId,'cover');
            if($newProfile==='' && (int)($_POST['profile_photo_id']??0)>0)$newProfile=artist_v181_copy_library_photo($pdo,$workspaceId,(int)$_POST['profile_photo_id'],'profile');
            if($newCover==='' && (int)($_POST['cover_photo_id']??0)>0)$newCover=artist_v181_copy_library_photo($pdo,$workspaceId,(int)$_POST['cover_photo_id'],'cover');
            if($newProfile!=='')$profilePath=$newProfile;if($newCover!=='')$coverPath=$newCover;
            $stmt=$pdo->prepare('UPDATE artist_workspaces_v181 SET profile_slug=?,bio=?,profile_image_path=?,cover_image_path=?,website_url=?,instagram_url=?,tiktok_url=?,youtube_url=?,spotify_url=?,apple_music_url=? WHERE id=? AND artist_user_id=?');
            $stmt->execute([$slug,trim((string)($_POST['bio']??'')),$profilePath,$coverPath,$links['website_url'],$links['instagram_url'],$links['tiktok_url'],$links['youtube_url'],$links['spotify_url'],$links['apple_music_url'],$workspaceId,(int)$user['id']]);
            if($newProfile!=='' && !empty($workspace['profile_image_path']))artist_v181_remove_owned_image($workspaceId,(string)$workspace['profile_image_path']);
            if($newCover!=='' && !empty($workspace['cover_image_path']))artist_v181_remove_owned_image($workspaceId,(string)$workspace['cover_image_path']);
            flash('notice','Artist profile settings saved.');
        }catch(Throwable $e){if($newProfile!=='')artist_v181_remove_owned_image($workspaceId,$newProfile);if($newCover!=='')artist_v181_remove_owned_image($workspaceId,$newCover);flash('error',$e->getMessage());}
        redirect(url('/admin/artist.php#profile-settings'));
    }

    $collection=(string)($_POST['collection']??$active);
    if($collection==='photos'){flash('error','Use the Artist Media Library to manage photos.');redirect(url('/admin/artist-media.php'));}
    if(!isset($collections[$collection])){flash('error','Unknown collection.');redirect(url('/admin/artist.php'));}
    $table=$collections[$collection]['table'];$id=(int)($_POST['id']??0);
    try{
        if($id>0&&!artist_v181_row($pdo,$table,$id,$workspaceId))throw new RuntimeException('Workspace record not found.');
        if($action==='delete'){$pdo->prepare("DELETE FROM {$table} WHERE id=? AND workspace_id=?")->execute([$id,$workspaceId]);flash('notice','Private record deleted.');}
        else{
            $title=trim((string)($_POST['title']??''));$visibility=trim((string)($_POST['visibility']??'members'));$published=isset($_POST['is_published'])?1:0;
            if($collection!=='shows'&&$title==='')throw new RuntimeException('Title is required.');
            if($collections[$collection]['visibility']&&!valid_visibility($visibility))throw new RuntimeException('Choose a valid visibility group.');
            if($collection==='tracks'){
                $v=[$title,trim((string)($_POST['album']??'')),trim((string)($_POST['audio_path']??'')),trim((string)($_POST['cover_path']??'')),$visibility,$published];
                if($id)$pdo->prepare("UPDATE {$table} SET title=?,album=?,audio_path=?,cover_path=?,visibility=?,is_published=? WHERE id=? AND workspace_id=?")->execute([...$v,$id,$workspaceId]);else $pdo->prepare("INSERT INTO {$table} (workspace_id,title,album,audio_path,cover_path,visibility,is_published) VALUES (?,?,?,?,?,?,?)")->execute([$workspaceId,...$v]);
            }elseif($collection==='merch'){
                $product=artist_workspace_v181_validate_external_url((string)($_POST['product_url']??''));$v=[$title,trim((string)($_POST['description']??'')),max(0,(int)round((float)($_POST['price']??0)*100)),trim((string)($_POST['image_path']??'')),$product,$visibility,$published];
                if($id)$pdo->prepare("UPDATE {$table} SET title=?,description=?,price_cents=?,image_path=?,product_url=?,visibility=?,is_published=? WHERE id=? AND workspace_id=?")->execute([...$v,$id,$workspaceId]);else $pdo->prepare("INSERT INTO {$table} (workspace_id,title,description,price_cents,image_path,product_url,visibility,is_published) VALUES (?,?,?,?,?,?,?,?)")->execute([$workspaceId,...$v]);
            }elseif($collection==='shows'){
                $venue=trim((string)($_POST['venue']??''));$date=artist_v181_datetime_value((string)($_POST['show_date']??''));$ticket=artist_workspace_v181_validate_external_url((string)($_POST['ticket_url']??''));if(!$venue||!$date)throw new RuntimeException('Venue and show date are required.');$v=[$date,$venue,trim((string)($_POST['city']??'')),trim((string)($_POST['region']??'')),trim((string)($_POST['notes']??'')),$ticket,$published];
                if($id)$pdo->prepare("UPDATE {$table} SET show_date=?,venue=?,city=?,region=?,notes=?,ticket_url=?,is_published=? WHERE id=? AND workspace_id=?")->execute([...$v,$id,$workspaceId]);else $pdo->prepare("INSERT INTO {$table} (workspace_id,show_date,venue,city,region,notes,ticket_url,is_published) VALUES (?,?,?,?,?,?,?,?)")->execute([$workspaceId,...$v]);
            }elseif($collection==='albums'){
                $release=trim((string)($_POST['release_date']??''));$v=[$title,$release?:null,trim((string)($_POST['description']??'')),trim((string)($_POST['cover_path']??'')),$visibility,$published];
                if($id)$pdo->prepare("UPDATE {$table} SET title=?,release_date=?,description=?,cover_path=?,visibility=?,is_published=? WHERE id=? AND workspace_id=?")->execute([...$v,$id,$workspaceId]);else $pdo->prepare("INSERT INTO {$table} (workspace_id,title,release_date,description,cover_path,visibility,is_published) VALUES (?,?,?,?,?,?,?)")->execute([$workspaceId,...$v]);
            }elseif($collection==='posts'){
                $media=artist_workspace_v181_validate_external_url((string)($_POST['media_url']??''));$v=[$title,trim((string)($_POST['body']??'')),trim((string)($_POST['post_type']??'update'))?:'update',trim((string)($_POST['image_path']??'')),$media,$visibility,$published,$published?date('Y-m-d H:i:s'):null];
                if($id)$pdo->prepare("UPDATE {$table} SET title=?,body=?,post_type=?,image_path=?,media_url=?,visibility=?,is_published=?,published_at=? WHERE id=? AND workspace_id=?")->execute([...$v,$id,$workspaceId]);else $pdo->prepare("INSERT INTO {$table} (workspace_id,title,body,post_type,image_path,media_url,visibility,is_published,published_at) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$workspaceId,...$v]);
            }elseif($collection==='releases'){
                $v=[$title,trim((string)($_POST['release_type']??'single'))?:'single',trim((string)($_POST['status']??'planning'))?:'planning',trim((string)($_POST['priority']??'normal'))?:'normal',artist_v181_datetime_value((string)($_POST['target_date']??'')),trim((string)($_POST['agent_goal']??'')),trim((string)($_POST['notes']??''))];
                if($id)$pdo->prepare("UPDATE {$table} SET title=?,release_type=?,status=?,priority=?,target_date=?,agent_goal=?,notes=? WHERE id=? AND workspace_id=?")->execute([...$v,$id,$workspaceId]);else $pdo->prepare("INSERT INTO {$table} (workspace_id,title,release_type,status,priority,target_date,agent_goal,notes) VALUES (?,?,?,?,?,?,?,?)")->execute([$workspaceId,...$v]);
            }
            flash('notice','Saved to your private artist workspace.');
        }
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect(url('/admin/artist.php?collection='.$collection));
}

$workspace=artist_workspace_v181_for_user($pdo,$user);$publicProfileUrl=artist_workspace_v181_profile_url($workspace);
$profileIdentity=trim((string)$workspace['profile_slug'])!==''?'artist='.rawurlencode((string)$workspace['profile_slug']):'user_id='.(int)$workspace['artist_user_id'];
$profileImage=!empty($workspace['profile_image_path'])?url('/artist-profile-image.php?'.$profileIdentity.'&type=profile'):'';$coverImage=!empty($workspace['cover_image_path'])?url('/artist-profile-image.php?'.$profileIdentity.'&type=cover'):'';
$profilePhotoOptions=artist_media_v182_picker($pdo,$workspaceId,60);
$editing=(int)($_GET['edit']??0)>0?artist_v181_row($pdo,$collections[$active]['table'],(int)$_GET['edit'],$workspaceId):null;
$s=$pdo->prepare("SELECT * FROM {$collections[$active]['table']} WHERE workspace_id=? ORDER BY updated_at DESC,id DESC");$s->execute([$workspaceId]);$rows=$s->fetchAll();
$counts=[];foreach($collections as $key=>$meta){$s=$pdo->prepare("SELECT COUNT(*) FROM {$meta['table']} WHERE workspace_id=?");$s->execute([$workspaceId]);$counts[$key]=(int)$s->fetchColumn();}
$adminTitle='Artist Admin';$adminActive='artist-workspace';require __DIR__.'/_header.php';
?>
<div class="panel" id="profile-settings">
  <div class="content-library-heading"><div><p class="muted">Public identity</p><h2>Artist Profile</h2><p class="muted">Control what visitors see on your public artist page.</p></div><a class="btn primary" href="<?= e($publicProfileUrl) ?>" target="_blank" rel="noopener">View Artist Profile ↗</a></div>
  <div style="display:flex;gap:14px;align-items:center;margin:18px 0;flex-wrap:wrap"><?php if($profileImage): ?><img src="<?= e($profileImage) ?>" alt="" style="width:84px;height:84px;border-radius:50%;object-fit:cover"><?php endif; ?><?php if($coverImage): ?><img src="<?= e($coverImage) ?>" alt="" style="width:180px;height:84px;border-radius:8px;object-fit:cover"><?php endif; ?></div>
  <label><span>Public Artist Profile URL</span><div style="display:flex;gap:8px"><input id="artistPublicUrl" readonly value="<?= e($publicProfileUrl) ?>"><button class="btn" type="button" onclick="const i=document.getElementById('artistPublicUrl');const v=new URL(i.value,window.location.origin).href;navigator.clipboard&&navigator.clipboard.writeText(v)">Copy</button></div></label>
  <form method="post" enctype="multipart/form-data" class="form-grid" style="margin-top:18px"><?= csrf_field() ?><input type="hidden" name="action" value="save_profile">
    <label><span>Profile slug</span><input name="profile_slug" required pattern="[A-Za-z0-9-]+" value="<?= e((string)$workspace['profile_slug']) ?>"></label>
    <?php foreach(['profile'=>'Profile image','cover'=>'Cover image'] as $kind=>$label): ?>
      <div class="wide" style="border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:14px"><strong><?= e($label) ?></strong><p class="muted" style="margin:5px 0 10px">Upload a new image or choose one from My Photos. A new upload takes priority.</p><input name="<?= $kind ?>_image" type="file" accept="image/jpeg,image/png,image/webp">
      <?php if($profilePhotoOptions): ?><details style="margin-top:12px"><summary style="cursor:pointer">Choose from My Photos</summary><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-top:12px"><label style="display:block;border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:8px"><input type="radio" name="<?= $kind ?>_photo_id" value="0" checked> Keep current</label><?php foreach($profilePhotoOptions as $photo): ?><label style="display:block;border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:8px;cursor:pointer"><input type="radio" name="<?= $kind ?>_photo_id" value="<?= (int)$photo['id'] ?>"><img src="<?= e(url('/content-image.php?type=artist_photo&id='.(int)$photo['id'])) ?>" alt="<?= e((string)($photo['alt_text']??'')) ?>" loading="lazy" style="width:100%;aspect-ratio:<?= $kind==='cover'?'16/9':'1/1' ?>;object-fit:cover;border-radius:6px;margin:6px 0"><span style="display:block;font-size:12px"><?= e((string)$photo['title']) ?></span><small class="muted"><?= !empty($photo['is_published'])?'Published':'Draft' ?> · <?= e((string)$photo['visibility']) ?></small></label><?php endforeach; ?></div></details><?php else: ?><p class="muted" style="margin-top:10px">No photos in your artist workspace yet. <a href="<?= e(url('/admin/artist-media.php?new=1')) ?>">Upload one.</a></p><?php endif; ?></div>
    <?php endforeach; ?>
    <label class="wide"><span>Bio</span><textarea name="bio" rows="5" maxlength="5000"><?= e((string)($workspace['bio']??'')) ?></textarea></label>
    <?php foreach(['website_url'=>'Website URL','instagram_url'=>'Instagram URL','tiktok_url'=>'TikTok URL','youtube_url'=>'YouTube URL','spotify_url'=>'Spotify URL','apple_music_url'=>'Apple Music URL'] as $field=>$label): ?><label><span><?= e($label) ?></span><input name="<?= e($field) ?>" type="url" inputmode="url" value="<?= e((string)($workspace[$field]??'')) ?>"></label><?php endforeach; ?>
    <div class="wide"><button class="btn primary" type="submit">Save Profile</button> <a class="btn" href="<?= e(url('/admin/artist-media.php')) ?>">Media Library</a></div>
  </form>
</div>
<div class="panel"><p class="muted">Private artist workspace</p><h2><?= e($workspace['workspace_name']) ?></h2><p class="muted">Changes here affect only your artist workspace, never Stonefellow’s shared catalog.</p></div>
<div class="grid"><?php foreach($collections as $key=>$meta): $metricUrl=$key==='photos'?url('/admin/artist-media.php'):url('/admin/artist.php?collection='.$key); ?><a class="metric" href="<?= e($metricUrl) ?>"><strong><?= $counts[$key] ?></strong><span><?= e($meta['label']) ?></span></a><?php endforeach; ?></div>
<div class="panel"><div class="content-library-heading"><div><p class="muted">Private catalog</p><h2><?= e($collections[$active]['label']) ?></h2></div><a class="btn primary" href="<?= e(url('/admin/artist.php?collection='.$active.'&edit=new')) ?>">+ Add</a></div>
<?php if($editing||(string)($_GET['edit']??'')==='new'): ?><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="collection" value="<?= e($active) ?>"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>"><?php if($active!=='shows'): ?><label><span>Title</span><input name="title" required value="<?= e((string)($editing['title']??'')) ?>"></label><?php endif; ?>
<?php if($active==='tracks'): ?><label><span>Album</span><input name="album" value="<?= e((string)($editing['album']??'')) ?>"></label><label><span>Audio path</span><input name="audio_path" value="<?= e((string)($editing['audio_path']??'')) ?>"></label><label><span>Cover path</span><input name="cover_path" value="<?= e((string)($editing['cover_path']??'')) ?>"></label>
<?php elseif($active==='merch'): ?><label><span>Price</span><input name="price" type="number" min="0" step=".01" value="<?= e(number_format(((int)($editing['price_cents']??0))/100,2,'.','')) ?>"></label><label><span>Product URL</span><input name="product_url" type="url" value="<?= e((string)($editing['product_url']??'')) ?>"></label><label><span>Image path</span><input name="image_path" value="<?= e((string)($editing['image_path']??'')) ?>"></label><label class="wide"><span>Description</span><textarea name="description" rows="3"><?= e((string)($editing['description']??'')) ?></textarea></label>
<?php elseif($active==='shows'): ?><label><span>Show date</span><input name="show_date" type="datetime-local" required value="<?= e(!empty($editing['show_date'])?date('Y-m-d\TH:i',strtotime((string)$editing['show_date'])):'') ?>"></label><label><span>Venue</span><input name="venue" required value="<?= e((string)($editing['venue']??'')) ?>"></label><label><span>City</span><input name="city" value="<?= e((string)($editing['city']??'')) ?>"></label><label><span>State / region</span><input name="region" value="<?= e((string)($editing['region']??'')) ?>"></label><label class="wide"><span>Ticket URL</span><input name="ticket_url" type="url" value="<?= e((string)($editing['ticket_url']??'')) ?>"></label><label class="wide"><span>Notes</span><textarea name="notes" rows="3"><?= e((string)($editing['notes']??'')) ?></textarea></label>
<?php elseif($active==='albums'): ?><label><span>Release date</span><input name="release_date" type="date" value="<?= e((string)($editing['release_date']??'')) ?>"></label><label><span>Cover path</span><input name="cover_path" value="<?= e((string)($editing['cover_path']??'')) ?>"></label><label class="wide"><span>Description</span><textarea name="description" rows="3"><?= e((string)($editing['description']??'')) ?></textarea></label>
<?php elseif($active==='posts'): ?><label><span>Post type</span><select name="post_type"><option value="update">Update</option><option value="announcement" <?= (string)($editing['post_type']??'')==='announcement'?'selected':'' ?>>Announcement</option></select></label><label><span>Image path</span><input name="image_path" value="<?= e((string)($editing['image_path']??'')) ?>"></label><label><span>Media URL</span><input name="media_url" type="url" value="<?= e((string)($editing['media_url']??'')) ?>"></label><label class="wide"><span>Post</span><textarea name="body" rows="5"><?= e((string)($editing['body']??'')) ?></textarea></label>
<?php elseif($active==='releases'): ?><label><span>Release type</span><select name="release_type"><option value="single">Single</option><option value="album" <?= (string)($editing['release_type']??'')==='album'?'selected':'' ?>>Album</option><option value="campaign" <?= (string)($editing['release_type']??'')==='campaign'?'selected':'' ?>>Campaign</option></select></label><label><span>Status</span><select name="status"><option value="planning">Planning</option><option value="active" <?= (string)($editing['status']??'')==='active'?'selected':'' ?>>Active</option><option value="complete" <?= (string)($editing['status']??'')==='complete'?'selected':'' ?>>Complete</option></select></label><label><span>Priority</span><select name="priority"><option value="normal">Normal</option><option value="high" <?= (string)($editing['priority']??'')==='high'?'selected':'' ?>>High</option></select></label><label><span>Target date</span><input name="target_date" type="datetime-local" value="<?= e(!empty($editing['target_date'])?date('Y-m-d\TH:i',strtotime((string)$editing['target_date'])):'') ?>"></label><label class="wide"><span>Agent goal</span><textarea name="agent_goal" rows="3"><?= e((string)($editing['agent_goal']??'')) ?></textarea></label><label class="wide"><span>Notes</span><textarea name="notes" rows="3"><?= e((string)($editing['notes']??'')) ?></textarea></label><?php endif; ?>
<?php if($collections[$active]['visibility']): ?><label><span>Visibility</span><select name="visibility"><?php foreach(visibility_options() as $value=>$label): ?><option value="<?= e($value) ?>" <?= (string)($editing['visibility']??'members')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><?php endif; ?><?php if($collections[$active]['published']): ?><label><span><input type="checkbox" name="is_published" value="1" <?= !empty($editing['is_published'])?'checked':'' ?>> Published</span></label><?php endif; ?><div class="wide"><button class="btn primary">Save</button><a class="btn" href="<?= e(url('/admin/artist.php?collection='.$active)) ?>">Cancel</a></div></form><?php endif; ?>
<div class="content-list"><?php foreach($rows as $row): $rowTitle=$active==='shows'?((string)$row['venue'].' · '.date('M j, Y',strtotime((string)$row['show_date']))):(string)$row['title'];$rowMeta=$active==='shows'?(!empty($row['is_published'])?'Published':'Draft'):($collections[$active]['visibility']?(string)$row['visibility'].' · ':'').(!empty($row['is_published'])?'Published':'Draft'); ?><div class="content-row"><div><strong><?= e($rowTitle) ?></strong><br><small><?= e($rowMeta) ?></small></div><div><a class="btn" href="<?= e(url('/admin/artist.php?collection='.$active.'&edit='.(int)$row['id'])) ?>">Edit</a><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="collection" value="<?= e($active) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn">Delete</button></form></div></div><?php endforeach;if(!$rows): ?><p class="muted">No private <?= strtolower($collections[$active]['label']) ?> yet.</p><?php endif; ?></div></div>
<?php require __DIR__.'/_footer.php'; ?>
