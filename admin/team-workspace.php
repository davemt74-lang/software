<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();

$pdo=db();$user=current_user();$userId=(int)($user['id']??0);$artistId=max(0,(int)($_GET['artist_id']??$_POST['artist_id']??0));
if(!$pdo||$userId<1||$artistId<1){http_response_code(400);exit('Choose an Artist workspace.');}
artist_workspace_v104_ensure_schema();artist_workspace_v181_ensure_schema($pdo);artist_media_v182_ensure_schema($pdo);

$artistStmt=$pdo->prepare("SELECT u.* FROM users u WHERE u.id=? AND (u.role='artist' OR EXISTS(SELECT 1 FROM user_account_types uat WHERE uat.user_id=u.id AND uat.role='artist')) LIMIT 1");
$artistStmt->execute([$artistId]);$artist=$artistStmt->fetch();
if(!$artist){http_response_code(404);exit('Artist workspace owner not found.');}

$isPlatformAdmin=subscription_is_internal_admin($user);
$isOwner=$artistId===$userId&&user_has_role('artist',$user);
$membership=artist_workspace_v104_membership($pdo,$artistId,$userId);
$teamRole=(string)($membership['team_role']??'');
if(!$isPlatformAdmin&&!$isOwner&&$teamRole!=='manager'){
    http_response_code(403);exit($teamRole==='producer'?'Producer access is limited to assigned production tracks.':'Manager access to this Artist workspace has not been granted.');
}

$workspaceName=trim((string)($artist['display_name']??''))?:'Artist';
$ensure=$pdo->prepare('INSERT INTO artist_workspaces_v181 (artist_user_id,workspace_name) VALUES (?,?) ON DUPLICATE KEY UPDATE workspace_name=IF(workspace_name="",VALUES(workspace_name),workspace_name)');
$ensure->execute([$artistId,$workspaceName]);
$workspaceStmt=$pdo->prepare('SELECT * FROM artist_workspaces_v181 WHERE artist_user_id=? LIMIT 1');$workspaceStmt->execute([$artistId]);$workspace=$workspaceStmt->fetch();
if(!$workspace){http_response_code(503);exit('Artist workspace could not be loaded.');}
$workspaceId=(int)$workspace['id'];

$sections=[
    'tracks'=>['label'=>'Tracks','table'=>'artist_catalog_tracks_v181','capability'=>'tracks'],
    'albums'=>['label'=>'Albums','table'=>'artist_catalog_albums_v181','capability'=>'albums'],
    'shows'=>['label'=>'Shows','table'=>'artist_catalog_shows_v181','capability'=>'shows'],
    'photos'=>['label'=>'Photos','table'=>'artist_catalog_photos_v181','capability'=>'photos'],
    'merch'=>['label'=>'Merch','table'=>'artist_catalog_merch_v181','capability'=>'merch'],
    'posts'=>['label'=>'Posts','table'=>'artist_posts_v181','capability'=>'posts'],
    'profile'=>['label'=>'Profile','table'=>'artist_workspaces_v181','capability'=>'profile'],
];
$section=(string)($_GET['section']??$_POST['section']??'tracks');if(!isset($sections[$section]))$section='tracks';
if(!$isPlatformAdmin&&!$isOwner&&!artist_workspace_v104_can_manage($artistId,(string)$sections[$section]['capability'],$user)){http_response_code(403);exit('Your Team role cannot manage this section.');}
$editId=max(0,(int)($_GET['edit']??0));

function team_workspace_v1_url(int $artistId,string $section,array $extra=[]): string
{
    return url('/admin/team-workspace.php?'.http_build_query(['artist_id'=>$artistId,'section'=>$section]+$extra));
}
function team_workspace_v1_visibility(string $value): string
{
    return valid_visibility($value)?$value:'members';
}
function team_workspace_v1_required(string $value,string $label): string
{
    $value=trim($value);if($value==='')throw new RuntimeException($label.' is required.');return $value;
}
function team_workspace_v1_datetime(string $value): string
{
    $value=trim($value);if($value==='')throw new RuntimeException('Date and time are required.');$ts=strtotime($value);if($ts===false)throw new RuntimeException('Enter a valid date and time.');return date('Y-m-d H:i:s',$ts);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');redirect(team_workspace_v1_url($artistId,$section));}
    $action=(string)($_POST['action']??'save');$id=max(0,(int)($_POST['id']??0));
    try{
        if($section==='profile'){
            if($action!=='save')throw new RuntimeException('Unknown profile action.');
            $name=team_workspace_v1_required((string)($_POST['workspace_name']??''),'Workspace name');
            $slug=artist_workspace_v181_slug((string)($_POST['profile_slug']??''));$bio=trim((string)($_POST['bio']??''));
            $website=artist_workspace_v181_validate_external_url((string)($_POST['website_url']??''));
            $instagram=artist_workspace_v181_validate_external_url((string)($_POST['instagram_url']??''));
            $tiktok=artist_workspace_v181_validate_external_url((string)($_POST['tiktok_url']??''));
            $youtube=artist_workspace_v181_validate_external_url((string)($_POST['youtube_url']??''));
            $spotify=artist_workspace_v181_validate_external_url((string)($_POST['spotify_url']??''));
            $apple=artist_workspace_v181_validate_external_url((string)($_POST['apple_music_url']??''));
            $stmt=$pdo->prepare('UPDATE artist_workspaces_v181 SET workspace_name=?,profile_slug=?,bio=?,website_url=?,instagram_url=?,tiktok_url=?,youtube_url=?,spotify_url=?,apple_music_url=? WHERE id=? AND artist_user_id=?');
            $stmt->execute([$name,$slug!==''?$slug:null,$bio,$website,$instagram,$tiktok,$youtube,$spotify,$apple,$workspaceId,$artistId]);
            flash('notice','Artist profile updated inside the Team workspace.');redirect(team_workspace_v1_url($artistId,'profile'));
        }

        if($action==='delete'){
            if($id<1)throw new RuntimeException('Choose a record to delete.');
            if($section==='photos'){
                $photo=artist_media_v182_photo($pdo,$workspaceId,$id);if(!$photo)throw new RuntimeException('Photo not found in this Artist workspace.');
                artist_media_v182_delete_owned_photo($workspaceId,(string)$photo['image_path']);
            }
            $table=(string)$sections[$section]['table'];$stmt=$pdo->prepare("DELETE FROM {$table} WHERE id=? AND workspace_id=?");$stmt->execute([$id,$workspaceId]);
            if($stmt->rowCount()<1)throw new RuntimeException('That record does not belong to this Artist workspace.');
            flash('notice',rtrim((string)$sections[$section]['label'],'s').' removed.');redirect(team_workspace_v1_url($artistId,$section));
        }

        $published=isset($_POST['is_published'])?1:0;$visibility=team_workspace_v1_visibility((string)($_POST['visibility']??'members'));
        if($section==='tracks'){
            $values=[team_workspace_v1_required((string)($_POST['title']??''),'Track title'),trim((string)($_POST['album']??'')),trim((string)($_POST['audio_path']??'')),trim((string)($_POST['cover_path']??'')),$visibility,$published];
            if($id>0){$stmt=$pdo->prepare('UPDATE artist_catalog_tracks_v181 SET title=?,album=?,audio_path=?,cover_path=?,visibility=?,is_published=? WHERE id=? AND workspace_id=?');$stmt->execute([...$values,$id,$workspaceId]);}
            else{$stmt=$pdo->prepare('INSERT INTO artist_catalog_tracks_v181 (workspace_id,title,album,audio_path,cover_path,visibility,is_published) VALUES (?,?,?,?,?,?,?)');$stmt->execute([$workspaceId,...$values]);}
        }elseif($section==='albums'){
            $release=trim((string)($_POST['release_date']??''));$values=[team_workspace_v1_required((string)($_POST['title']??''),'Album title'),$release!==''?$release:null,trim((string)($_POST['description']??'')),trim((string)($_POST['cover_path']??'')),$visibility,$published];
            if($id>0){$stmt=$pdo->prepare('UPDATE artist_catalog_albums_v181 SET title=?,release_date=?,description=?,cover_path=?,visibility=?,is_published=? WHERE id=? AND workspace_id=?');$stmt->execute([...$values,$id,$workspaceId]);}
            else{$stmt=$pdo->prepare('INSERT INTO artist_catalog_albums_v181 (workspace_id,title,release_date,description,cover_path,visibility,is_published) VALUES (?,?,?,?,?,?,?)');$stmt->execute([$workspaceId,...$values]);}
        }elseif($section==='shows'){
            $ticket=artist_workspace_v181_validate_external_url((string)($_POST['ticket_url']??''));$values=[team_workspace_v1_datetime((string)($_POST['show_date']??'')),team_workspace_v1_required((string)($_POST['venue']??''),'Venue'),trim((string)($_POST['city']??'')),trim((string)($_POST['region']??'')),trim((string)($_POST['notes']??'')),$ticket,$published];
            if($id>0){$stmt=$pdo->prepare('UPDATE artist_catalog_shows_v181 SET show_date=?,venue=?,city=?,region=?,notes=?,ticket_url=?,is_published=? WHERE id=? AND workspace_id=?');$stmt->execute([...$values,$id,$workspaceId]);}
            else{$stmt=$pdo->prepare('INSERT INTO artist_catalog_shows_v181 (workspace_id,show_date,venue,city,region,notes,ticket_url,is_published) VALUES (?,?,?,?,?,?,?,?)');$stmt->execute([$workspaceId,...$values]);}
        }elseif($section==='photos'){
            $existing='';if($id>0){$old=artist_media_v182_photo($pdo,$workspaceId,$id);if(!$old)throw new RuntimeException('Photo not found in this Artist workspace.');$existing=(string)$old['image_path'];}
            $uploaded=artist_media_v182_store_photo($_FILES['photo_file']??[],$workspaceId);$image=$uploaded!==''?$uploaded:$existing;if($image==='')throw new RuntimeException('Choose a photo.');
            $values=[team_workspace_v1_required((string)($_POST['title']??''),'Photo title'),trim((string)($_POST['caption']??'')),trim((string)($_POST['alt_text']??'')),$image,$visibility,(int)($_POST['sort_order']??0),$published];
            if($id>0){$stmt=$pdo->prepare('UPDATE artist_catalog_photos_v181 SET title=?,caption=?,alt_text=?,image_path=?,visibility=?,sort_order=?,is_published=? WHERE id=? AND workspace_id=?');$stmt->execute([...$values,$id,$workspaceId]);if($uploaded!==''&&$existing!==''&&$existing!==$uploaded)artist_media_v182_delete_owned_photo($workspaceId,$existing);}
            else{$stmt=$pdo->prepare('INSERT INTO artist_catalog_photos_v181 (workspace_id,title,caption,alt_text,image_path,visibility,sort_order,is_published) VALUES (?,?,?,?,?,?,?,?)');$stmt->execute([$workspaceId,...$values]);}
        }elseif($section==='merch'){
            $product=artist_workspace_v181_validate_external_url((string)($_POST['product_url']??''));$price=(int)round(max(0,(float)($_POST['price']??0))*100);$values=[team_workspace_v1_required((string)($_POST['title']??''),'Product title'),trim((string)($_POST['description']??'')),$price,trim((string)($_POST['image_path']??'')),$product,$visibility,$published];
            if($id>0){$stmt=$pdo->prepare('UPDATE artist_catalog_merch_v181 SET title=?,description=?,price_cents=?,image_path=?,product_url=?,visibility=?,is_published=? WHERE id=? AND workspace_id=?');$stmt->execute([...$values,$id,$workspaceId]);}
            else{$stmt=$pdo->prepare('INSERT INTO artist_catalog_merch_v181 (workspace_id,title,description,price_cents,image_path,product_url,visibility,is_published) VALUES (?,?,?,?,?,?,?,?)');$stmt->execute([$workspaceId,...$values]);}
        }elseif($section==='posts'){
            $media=artist_workspace_v181_validate_external_url((string)($_POST['media_url']??''));$type=trim((string)($_POST['post_type']??'update'));if(!in_array($type,['update','news','release','show','studio'],true))$type='update';$values=[team_workspace_v1_required((string)($_POST['title']??''),'Post title'),trim((string)($_POST['body']??'')),$type,trim((string)($_POST['image_path']??'')),$media,$visibility,$published,$published?date('Y-m-d H:i:s'):null];
            if($id>0){$stmt=$pdo->prepare('UPDATE artist_posts_v181 SET title=?,body=?,post_type=?,image_path=?,media_url=?,visibility=?,is_published=?,published_at=? WHERE id=? AND workspace_id=?');$stmt->execute([...$values,$id,$workspaceId]);}
            else{$stmt=$pdo->prepare('INSERT INTO artist_posts_v181 (workspace_id,title,body,post_type,image_path,media_url,visibility,is_published,published_at) VALUES (?,?,?,?,?,?,?,?,?)');$stmt->execute([$workspaceId,...$values]);}
        }else throw new RuntimeException('Unsupported Team workspace section.');
        if($id>0&&$stmt->rowCount()<1){$check=$pdo->prepare('SELECT 1 FROM '.$sections[$section]['table'].' WHERE id=? AND workspace_id=?');$check->execute([$id,$workspaceId]);if(!$check->fetchColumn())throw new RuntimeException('That record does not belong to this Artist workspace.');}
        flash('notice',rtrim((string)$sections[$section]['label'],'s').' saved.');redirect(team_workspace_v1_url($artistId,$section));
    }catch(Throwable $e){flash('error',$e->getMessage());redirect(team_workspace_v1_url($artistId,$section,$id>0?['edit'=>$id]:[]));}
}

$editing=null;$rows=[];
if($section==='profile'){$workspaceStmt->execute([$artistId]);$workspace=$workspaceStmt->fetch();}
else{
    $table=(string)$sections[$section]['table'];
    if($editId>0){$stmt=$pdo->prepare("SELECT * FROM {$table} WHERE id=? AND workspace_id=? LIMIT 1");$stmt->execute([$editId,$workspaceId]);$editing=$stmt->fetch()?:null;if(!$editing){flash('error','That record is outside this Artist workspace.');redirect(team_workspace_v1_url($artistId,$section));}}
    $order=$section==='shows'?'show_date DESC':($section==='photos'?'sort_order ASC,id DESC':'updated_at DESC,id DESC');
    $stmt=$pdo->prepare("SELECT * FROM {$table} WHERE workspace_id=? ORDER BY {$order} LIMIT 250");$stmt->execute([$workspaceId]);$rows=$stmt->fetchAll()?:[];
}

$adminTitle=$workspaceName.' · Manager Workspace';$adminActive='team-workspaces';require __DIR__.'/_header.php';
?>
<div class="panel">
  <div class="content-library-heading"><div><span class="status">Manager · Scoped Workspace</span><h2><?= e($workspaceName) ?></h2><p class="muted">Every read and write on this page is constrained to Artist workspace #<?= (int)$workspaceId ?>. Your Manager relationship does not grant global CMS access.</p></div><div class="actions"><a class="btn" href="<?= e(url('/admin/team-workspaces.php')) ?>">Switch Workspace</a><?php if($section!=='profile'):?><a class="btn primary" href="<?= e(team_workspace_v1_url($artistId,$section,['edit'=>0]).'#record-form') ?>">+ Add <?= e(rtrim((string)$sections[$section]['label'],'s')) ?></a><?php endif;?></div></div>
  <nav class="actions" style="margin:14px 0 20px;display:flex;gap:8px;flex-wrap:wrap"><?php foreach($sections as $key=>$meta):if(!$isPlatformAdmin&&!$isOwner&&!artist_workspace_v104_can_manage($artistId,(string)$meta['capability'],$user))continue;?><a class="btn <?= $section===$key?'primary':'' ?>" href="<?= e(team_workspace_v1_url($artistId,$key)) ?>"><?= e((string)$meta['label']) ?></a><?php endforeach;?></nav>
</div>

<?php if($section==='profile'):?>
<div class="panel" id="record-form"><div class="content-form-heading"><div><span class="status">Artist Identity</span><h2>Profile</h2></div></div><form class="grid-form" method="post"><?= csrf_field() ?><input type="hidden" name="artist_id" value="<?= $artistId ?>"><input type="hidden" name="section" value="profile"><input type="hidden" name="action" value="save"><div class="field"><label>Workspace Name</label><input name="workspace_name" required value="<?= e((string)$workspace['workspace_name']) ?>"></div><div class="field"><label>Profile Slug</label><input name="profile_slug" value="<?= e((string)($workspace['profile_slug']??'')) ?>"></div><div class="field full"><label>Bio</label><textarea name="bio" rows="7"><?= e((string)($workspace['bio']??'')) ?></textarea></div><?php foreach(['website_url'=>'Website','instagram_url'=>'Instagram','tiktok_url'=>'TikTok','youtube_url'=>'YouTube','spotify_url'=>'Spotify','apple_music_url'=>'Apple Music'] as $field=>$label):?><div class="field"><label><?= e($label) ?></label><input name="<?= e($field) ?>" type="url" value="<?= e((string)($workspace[$field]??'')) ?>"></div><?php endforeach;?><div class="field full actions"><button class="btn primary" type="submit">Save Profile</button></div></form></div>
<?php else:?>
<div class="panel"><div class="table-wrap"><table><thead><tr><th><?= e(rtrim((string)$sections[$section]['label'],'s')) ?></th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $row):$title=(string)($row['title']??$row['venue']??'Record');?><tr><td><strong><?= e($title) ?></strong><?php if($section==='shows'):?><br><span class="muted"><?= e((string)$row['show_date']) ?> · <?= e((string)$row['city']) ?></span><?php endif;?></td><td><span class="status"><?= !empty($row['is_published'])?'Published':'Draft' ?></span></td><td><?= e((string)($row['updated_at']??$row['created_at']??'')) ?></td><td class="actions"><a class="btn" href="<?= e(team_workspace_v1_url($artistId,$section,['edit'=>(int)$row['id']).'#record-form') ?>">Edit</a><form class="inline-form" method="post" onsubmit="return confirm('Remove this item from this Artist workspace?')"><?= csrf_field() ?><input type="hidden" name="artist_id" value="<?= $artistId ?>"><input type="hidden" name="section" value="<?= e($section) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn danger" type="submit">Delete</button></form></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="4" class="muted">No <?= e(strtolower((string)$sections[$section]['label'])) ?> yet.</td></tr><?php endif;?></tbody></table></div></div>

<div class="panel" id="record-form"><div class="content-form-heading"><div><span class="status"><?= $editing?'Edit':'New' ?></span><h2><?= e(rtrim((string)$sections[$section]['label'],'s')) ?></h2></div><?php if($editing):?><a class="btn" href="<?= e(team_workspace_v1_url($artistId,$section)) ?>">Close</a><?php endif;?></div><form class="grid-form" method="post" <?= $section==='photos'?'enctype="multipart/form-data"':'' ?>><?= csrf_field() ?><input type="hidden" name="artist_id" value="<?= $artistId ?>"><input type="hidden" name="section" value="<?= e($section) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>">
<?php if($section==='tracks'):?><div class="field"><label>Title</label><input name="title" required value="<?= e((string)($editing['title']??'')) ?>"></div><div class="field"><label>Album</label><input name="album" value="<?= e((string)($editing['album']??'')) ?>"></div><div class="field full"><label>Audio Path</label><input name="audio_path" value="<?= e((string)($editing['audio_path']??'')) ?>"></div><div class="field full"><label>Cover Path</label><input name="cover_path" value="<?= e((string)($editing['cover_path']??'')) ?>"></div>
<?php elseif($section==='albums'):?><div class="field"><label>Title</label><input name="title" required value="<?= e((string)($editing['title']??'')) ?>"></div><div class="field"><label>Release Date</label><input name="release_date" type="date" value="<?= e((string)($editing['release_date']??'')) ?>"></div><div class="field full"><label>Description</label><textarea name="description" rows="6"><?= e((string)($editing['description']??'')) ?></textarea></div><div class="field full"><label>Cover Path</label><input name="cover_path" value="<?= e((string)($editing['cover_path']??'')) ?>"></div>
<?php elseif($section==='shows'):?><div class="field"><label>Date & Time</label><input name="show_date" type="datetime-local" required value="<?= !empty($editing['show_date'])?e(date('Y-m-d\TH:i',strtotime((string)$editing['show_date']))):'' ?>"></div><div class="field"><label>Venue</label><input name="venue" required value="<?= e((string)($editing['venue']??'')) ?>"></div><div class="field"><label>City</label><input name="city" value="<?= e((string)($editing['city']??'')) ?>"></div><div class="field"><label>Region</label><input name="region" value="<?= e((string)($editing['region']??'')) ?>"></div><div class="field full"><label>Notes</label><textarea name="notes" rows="5"><?= e((string)($editing['notes']??'')) ?></textarea></div><div class="field full"><label>Ticket URL</label><input name="ticket_url" type="url" value="<?= e((string)($editing['ticket_url']??'')) ?>"></div>
<?php elseif($section==='photos'):?><div class="field"><label>Title</label><input name="title" required value="<?= e((string)($editing['title']??'')) ?>"></div><div class="field"><label>Sort Order</label><input name="sort_order" type="number" value="<?= e((string)($editing['sort_order']??0)) ?>"></div><div class="field full"><label>Caption</label><textarea name="caption" rows="4"><?= e((string)($editing['caption']??'')) ?></textarea></div><div class="field full"><label>Alt Text</label><input name="alt_text" maxlength="255" value="<?= e((string)($editing['alt_text']??'')) ?>"></div><div class="field full"><label><?= $editing?'Replace Photo (optional)':'Photo' ?></label><input name="photo_file" type="file" accept="image/jpeg,image/png,image/webp" <?= $editing?'':'required' ?>></div>
<?php elseif($section==='merch'):?><div class="field"><label>Title</label><input name="title" required value="<?= e((string)($editing['title']??'')) ?>"></div><div class="field"><label>Price</label><input name="price" type="number" min="0" step="0.01" value="<?= number_format(((int)($editing['price_cents']??0))/100,2,'.','') ?>"></div><div class="field full"><label>Description</label><textarea name="description" rows="5"><?= e((string)($editing['description']??'')) ?></textarea></div><div class="field"><label>Image Path</label><input name="image_path" value="<?= e((string)($editing['image_path']??'')) ?>"></div><div class="field"><label>Product URL</label><input name="product_url" type="url" value="<?= e((string)($editing['product_url']??'')) ?>"></div>
<?php elseif($section==='posts'):?><div class="field"><label>Title</label><input name="title" required value="<?= e((string)($editing['title']??'')) ?>"></div><div class="field"><label>Type</label><select name="post_type"><?php foreach(['update'=>'Update','news'=>'News','release'=>'Release','show'=>'Show','studio'=>'Studio'] as $value=>$label):?><option value="<?= e($value) ?>" <?= (string)($editing['post_type']??'update')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></div><div class="field full"><label>Body</label><textarea name="body" rows="8"><?= e((string)($editing['body']??'')) ?></textarea></div><div class="field"><label>Image Path</label><input name="image_path" value="<?= e((string)($editing['image_path']??'')) ?>"></div><div class="field"><label>Media URL</label><input name="media_url" type="url" value="<?= e((string)($editing['media_url']??'')) ?>"></div><?php endif;?>
<?php if(in_array($section,['tracks','albums','photos','merch','posts'],true)):?><div class="field"><label>Visibility</label><select name="visibility"><?php foreach(visibility_options() as $value=>$label):?><option value="<?= e($value) ?>" <?= (string)($editing['visibility']??'members')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></div><?php endif;?><div class="field"><label class="admin-inline-check"><input name="is_published" type="checkbox" <?= !isset($editing['is_published'])||(int)$editing['is_published']===1?'checked':'' ?>> Published</label></div><div class="field full actions"><button class="btn primary" type="submit">Save <?= e(rtrim((string)$sections[$section]['label'],'s')) ?></button></div></form></div>
<?php endif;?>
<?php require __DIR__.'/_footer.php'; ?>