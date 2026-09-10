<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();
$user=current_user();$pdo=db();
if(!$pdo){http_response_code(503);exit('Database unavailable.');}
if(!music_workspace_resources_v330_schema_ready($pdo)){flash('error','Run the VP3 database upgrade to enable workspace-owned Music resources.');redirect(url('/upgrade.php'));}
artist_music_v185_ensure_schema($pdo);artist_media_v182_ensure_schema($pdo);
$requested=max(0,(int)($_GET['workspace']??$_POST['workspace_id']??0));
$workspace=music_workspace_resources_v330_resolve_active($pdo,$user,$requested);
if(!$workspace){http_response_code(403);exit('No accessible Music Workspace was found.');}
$workspaceId=(int)$workspace['id'];$role=music_workspace_resources_v330_member_role($pdo,$workspaceId,(int)$user['id']);
$canTracks=music_workspace_resources_v330_can_manage($pdo,$workspaceId,'tracks',$user);
$canAlbums=music_workspace_resources_v330_can_manage($pdo,$workspaceId,'albums',$user);
$canProduction=music_workspace_resources_v330_can_manage($pdo,$workspaceId,'production',$user)||$canTracks;
$tab=(string)($_GET['tab']??'tracks');if(!in_array($tab,['tracks','albums'],true))$tab='tracks';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');redirect(url('/music-library.php?workspace='.$workspaceId));}
    $kind=(string)($_POST['kind']??'track');$action=(string)($_POST['action']??'save');$id=max(0,(int)($_POST['id']??0));
    try{
        if($kind==='track'){
            if(!$canTracks)throw new RuntimeException('Track catalog management is not available to your workspace role.');
            $existing=$id>0?artist_music_v185_track($pdo,$workspaceId,$id):null;
            if($id>0&&!$existing)throw new RuntimeException('Track was not found in this Music Workspace.');
            if($action==='delete'){
                $sourceId=(int)($existing['source_track_id']??0);
                $pdo->beginTransaction();
                try{
                    $pdo->prepare('DELETE FROM artist_catalog_tracks_v181 WHERE id=? AND workspace_id=?')->execute([$id,$workspaceId]);
                    if($sourceId>0&&table_exists('tracks'))$pdo->prepare('UPDATE tracks SET is_published=0 WHERE id=? AND workspace_id=?')->execute([$sourceId,$workspaceId]);
                    $pdo->commit();
                }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
                artist_music_v185_delete_owned_audio($workspaceId,(string)($existing['audio_path']??''));
                flash('notice',$sourceId>0?'Track removed from the Music Library; its Studio project/history was preserved.':'Track deleted.');
                redirect(url('/music-library.php?workspace='.$workspaceId.'&tab=tracks'));
            }
            if($action!=='save')throw new RuntimeException('Unknown track action.');
            $title=trim((string)($_POST['title']??''));if($title==='')throw new RuntimeException('Track title is required.');
            $description=trim((string)($_POST['description']??''));$genre=trim((string)($_POST['genre']??''));
            $albumId=artist_music_v185_validate_album($pdo,$workspaceId,max(0,(int)($_POST['album_id']??0)));
            $trackNumber=max(0,(int)($_POST['track_number']??0));$duration=max(0,(int)($_POST['duration_seconds']??0));
            $visibility=trim((string)($_POST['visibility']??'members'));if(!valid_visibility($visibility))throw new RuntimeException('Choose a valid visibility.');
            $published=isset($_POST['is_published'])?1:0;$albumTitle='';if($albumId>0){$a=artist_music_v185_album($pdo,$workspaceId,$albumId);$albumTitle=(string)($a['title']??'');}
            $oldAudio=(string)($existing['audio_path']??'');$newAudio=artist_music_v185_store_audio($_FILES['audio_file']??[],$workspaceId);$audio=$newAudio!==''?$newAudio:$oldAudio;
            if($audio==='')throw new RuntimeException('Upload an MP3, M4A, WAV, or OGG audio file.');
            try{
                if($id>0){$stmt=$pdo->prepare('UPDATE artist_catalog_tracks_v181 SET title=?,album=?,album_id=?,description=?,genre=?,duration_seconds=?,track_number=?,audio_path=?,visibility=?,is_published=? WHERE id=? AND workspace_id=?');$stmt->execute([$title,$albumTitle,$albumId?:null,$description,$genre,$duration?:null,$trackNumber,$audio,$visibility,$published,$id,$workspaceId]);}
                else{$stmt=$pdo->prepare("INSERT INTO artist_catalog_tracks_v181 (workspace_id,title,album,album_id,description,genre,duration_seconds,track_number,audio_path,cover_path,visibility,is_published) VALUES (?,?,?,?,?,?,?,?,?,'',?,?)");$stmt->execute([$workspaceId,$title,$albumTitle,$albumId?:null,$description,$genre,$duration?:null,$trackNumber,$audio,$visibility,$published]);$id=(int)$pdo->lastInsertId();}
            }catch(Throwable $e){if($newAudio!=='')artist_music_v185_delete_owned_audio($workspaceId,$newAudio);throw $e;}
            if($newAudio!==''&&$oldAudio!=='')artist_music_v185_delete_owned_audio($workspaceId,$oldAudio);
            // Existing production backing is refreshed immediately; backing for a
            // new catalog track is created lazily when Studio is opened.
            if((int)($existing['source_track_id']??0)>0)music_workspace_resources_v330_ensure_production_track($pdo,$workspaceId,$id,$user);
            flash('notice',$published?'Track published.':'Track draft saved.');redirect(url('/music-library.php?workspace='.$workspaceId.'&tab=tracks'));
        }
        if($kind==='album'){
            if(!$canAlbums)throw new RuntimeException('Album management is not available to your workspace role.');
            $existing=$id>0?artist_music_v185_album($pdo,$workspaceId,$id):null;if($id>0&&!$existing)throw new RuntimeException('Album was not found in this Music Workspace.');
            if($action==='delete'){
                $pdo->beginTransaction();try{$pdo->prepare("UPDATE artist_catalog_tracks_v181 SET album_id=NULL,album='' WHERE workspace_id=? AND album_id=?")->execute([$workspaceId,$id]);$pdo->prepare('DELETE FROM artist_catalog_albums_v181 WHERE id=? AND workspace_id=?')->execute([$id,$workspaceId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
                flash('notice','Album removed; tracks and any Studio projects were preserved.');redirect(url('/music-library.php?workspace='.$workspaceId.'&tab=albums'));
            }
            if($action!=='save')throw new RuntimeException('Unknown album action.');
            $title=trim((string)($_POST['title']??''));if($title==='')throw new RuntimeException('Album title is required.');
            $release=trim((string)($_POST['release_date']??''));if($release!==''&&(!($d=DateTime::createFromFormat('Y-m-d',$release))||$d->format('Y-m-d')!==$release))throw new RuntimeException('Choose a valid release date.');
            $description=trim((string)($_POST['description']??''));$visibility=trim((string)($_POST['visibility']??'members'));if(!valid_visibility($visibility))throw new RuntimeException('Choose a valid visibility.');$published=isset($_POST['is_published'])?1:0;
            if($id>0){$stmt=$pdo->prepare('UPDATE artist_catalog_albums_v181 SET title=?,release_date=?,description=?,visibility=?,is_published=? WHERE id=? AND workspace_id=?');$stmt->execute([$title,$release?:null,$description,$visibility,$published,$id,$workspaceId]);$pdo->prepare('UPDATE artist_catalog_tracks_v181 SET album=? WHERE workspace_id=? AND album_id=?')->execute([$title,$workspaceId,$id]);if((int)($existing['source_album_id']??0)>0)music_workspace_resources_v330_ensure_source_album($pdo,$workspaceId,$id);}
            else{$stmt=$pdo->prepare("INSERT INTO artist_catalog_albums_v181 (workspace_id,title,release_date,description,cover_path,visibility,is_published) VALUES (?,?,?,?,'',?,?)");$stmt->execute([$workspaceId,$title,$release?:null,$description,$visibility,$published]);}
            flash('notice',$published?'Album published.':'Album draft saved.');redirect(url('/music-library.php?workspace='.$workspaceId.'&tab=albums'));
        }
        throw new RuntimeException('Unknown Music Library action.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect(url('/music-library.php?workspace='.$workspaceId.'&tab='.($kind==='album'?'albums':'tracks')));}
}

$workspaces=music_workspace_resources_v330_accessible_workspaces($pdo,$user);
$albums=artist_music_v185_albums($pdo,$workspaceId,true);$tracks=artist_music_v185_tracks($pdo,$workspaceId,true);
if($role==='producer'){
    $uid=(int)$user['id'];$tracks=array_values(array_filter($tracks,static function(array $track)use($pdo,$uid,$workspaceId):bool{$sourceId=(int)($track['source_track_id']??0);if($sourceId<1)return false;$s=$pdo->prepare('SELECT 1 FROM tracks WHERE id=? AND workspace_id=? AND producer_user_id=? LIMIT 1');$s->execute([$sourceId,$workspaceId,$uid]);return (bool)$s->fetchColumn();}));
}
$editId=max(0,(int)($_GET['edit']??0));$editing=$editId>0?($tab==='albums'?artist_music_v185_album($pdo,$workspaceId,$editId):artist_music_v185_track($pdo,$workspaceId,$editId)):null;$showForm=isset($_GET['new'])||$editing;
$memberHeaderUser=$user;$memberHeaderTitle='Music Library';$memberHeaderSubtitle=(string)$workspace['workspace_name'].' · '.ucfirst($role?:'member');$memberHeaderActions='';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>VP3 | Music Library</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><style>
.ml-main{background:#f7f7f8;min-width:0}.ml-wrap{max-width:1180px;margin:auto;padding:28px 24px 70px}.ml-bar,.ml-tabs,.ml-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.ml-bar{justify-content:space-between;margin-bottom:18px}.ml-bar select,.ml-form input,.ml-form textarea,.ml-form select{border:1px solid #dfe1e4;border-radius:10px;background:#fff;padding:10px;color:#202226}.ml-tabs{margin:12px 0 18px}.ml-btn{display:inline-flex;text-decoration:none;border:1px solid #dadde1;border-radius:10px;background:#fff;color:#202226;padding:9px 12px;font-weight:750;cursor:pointer}.ml-btn.primary,.ml-btn.active{background:#202226;color:#fff;border-color:#202226}.ml-grid{display:grid;gap:10px}.ml-row{background:#fff;border:1px solid #e1e3e6;border-radius:14px;padding:14px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px}.ml-row h3{margin:0 0 4px}.ml-row p{margin:0;color:#74777e;font-size:13px}.ml-form{margin-top:20px;background:#fff;border:1px solid #e1e3e6;border-radius:16px;padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:13px}.ml-form label{display:grid;gap:6px;font-size:12px;font-weight:750}.ml-form .wide{grid-column:1/-1}.ml-form textarea{min-height:90px}.ml-empty{padding:28px;background:#fff;border:1px dashed #d7d9dd;border-radius:14px;color:#74777e}@media(max-width:720px){.ml-row,.ml-form{grid-template-columns:1fr}.ml-form .wide{grid-column:auto}}
</style></head><body><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='music_workspace';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main ml-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="ml-wrap"><div class="ml-bar"><form method="get"><label>Workspace <select name="workspace" onchange="this.form.submit()"><?php foreach($workspaces as $w): ?><option value="<?= (int)$w['id'] ?>" <?= (int)$w['id']===$workspaceId?'selected':'' ?>><?= e((string)$w['workspace_name']) ?><?= !empty($w['access_role'])?' · '.e((string)$w['access_role']):'' ?></option><?php endforeach; ?></select></label><input type="hidden" name="tab" value="<?= e($tab) ?>"></form><div class="ml-actions"><a class="ml-btn" href="<?= e(url('/music-workspace.php?workspace='.$workspaceId)) ?>">Workspace Home</a><a class="ml-btn" href="<?= e(url('/messages.php')) ?>">Team Messages</a></div></div><div class="ml-tabs"><a class="ml-btn <?= $tab==='tracks'?'active':'' ?>" href="<?= e(url('/music-library.php?workspace='.$workspaceId.'&tab=tracks')) ?>">Tracks <?= count($tracks) ?></a><?php if($role!=='producer'): ?><a class="ml-btn <?= $tab==='albums'?'active':'' ?>" href="<?= e(url('/music-library.php?workspace='.$workspaceId.'&tab=albums')) ?>">Albums <?= count($albums) ?></a><?php endif; ?><?php if(($tab==='tracks'&&$canTracks)||($tab==='albums'&&$canAlbums)): ?><a class="ml-btn primary" href="<?= e(url('/music-library.php?workspace='.$workspaceId.'&tab='.$tab.'&new=1#editor')) ?>">+ Add <?= $tab==='tracks'?'Track':'Album' ?></a><?php endif; ?></div>
<div class="ml-grid"><?php if($tab==='tracks'): foreach($tracks as $track): ?><article class="ml-row"><div><h3><?= e((string)$track['title']) ?></h3><p><?= !empty($track['is_published'])?'Published':'Draft' ?> · <?= e((string)($track['album_title']?:'Single / Unassigned')) ?><?= !empty($track['source_track_id'])?' · Studio linked':'' ?></p></div><div class="ml-actions"><?php if($canProduction||$role==='producer'): ?><a class="ml-btn primary" href="<?= e(url('/music-studio.php?workspace='.$workspaceId.'&track='.(int)$track['id'])) ?>">Open Studio</a><?php endif; ?><?php if($canTracks): ?><a class="ml-btn" href="<?= e(url('/music-library.php?workspace='.$workspaceId.'&tab=tracks&edit='.(int)$track['id'].'#editor')) ?>">Edit</a><?php endif; ?></div></article><?php endforeach; if(!$tracks): ?><div class="ml-empty">No tracks are available in this workspace yet.</div><?php endif; else: foreach($albums as $album): ?><article class="ml-row"><div><h3><?= e((string)$album['title']) ?></h3><p><?= !empty($album['is_published'])?'Published':'Draft' ?> · <?= (int)$album['track_count'] ?> track<?= (int)$album['track_count']===1?'':'s' ?></p></div><div class="ml-actions"><?php if($canAlbums): ?><a class="ml-btn" href="<?= e(url('/music-library.php?workspace='.$workspaceId.'&tab=albums&edit='.(int)$album['id'].'#editor')) ?>">Edit</a><?php endif; ?></div></article><?php endforeach; if(!$albums): ?><div class="ml-empty">No albums are available in this workspace yet.</div><?php endif; endif; ?></div>
<?php if($showForm&&(($tab==='tracks'&&$canTracks)||($tab==='albums'&&$canAlbums))): ?><form id="editor" method="post" enctype="multipart/form-data" class="ml-form"><?= csrf_field() ?><input type="hidden" name="workspace_id" value="<?= $workspaceId ?>"><input type="hidden" name="kind" value="<?= $tab==='tracks'?'track':'album' ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>"><label><span>Title</span><input name="title" required maxlength="190" value="<?= e((string)($editing['title']??'')) ?>"></label><?php if($tab==='tracks'): ?><label><span>Album</span><select name="album_id"><option value="0">Single / Unassigned</option><?php foreach($albums as $album): ?><option value="<?= (int)$album['id'] ?>" <?= (int)($editing['album_id']??0)===(int)$album['id']?'selected':'' ?>><?= e((string)$album['title']) ?></option><?php endforeach; ?></select></label><label><span>Track #</span><input type="number" min="0" name="track_number" value="<?= (int)($editing['track_number']??0) ?>"></label><label><span>Duration seconds</span><input type="number" min="0" name="duration_seconds" value="<?= (int)($editing['duration_seconds']??0) ?>"></label><label><span>Genre</span><input name="genre" value="<?= e((string)($editing['genre']??'')) ?>"></label><label><span>Audio <?= $editing?'(leave blank to keep current)':'' ?></span><input type="file" name="audio_file" accept="audio/*" <?= $editing?'':'required' ?>></label><?php else: ?><label><span>Release date</span><input type="date" name="release_date" value="<?= e((string)($editing['release_date']??'')) ?>"></label><?php endif; ?><label><span>Visibility</span><select name="visibility"><?php foreach(visibility_options() as $value=>$label): ?><option value="<?= e($value) ?>" <?= (string)($editing['visibility']??'members')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label><span><input type="checkbox" name="is_published" value="1" <?= !empty($editing['is_published'])?'checked':'' ?>> Published</span></label><label class="wide"><span>Description</span><textarea name="description"><?= e((string)($editing['description']??'')) ?></textarea></label><div class="wide ml-actions"><button class="ml-btn primary" type="submit">Save <?= $tab==='tracks'?'track':'album' ?></button><?php if($editing): ?><button class="ml-btn" type="submit" name="action" value="delete" onclick="return confirm('Remove this <?= $tab ?>? Production history will be preserved where applicable.')">Remove</button><?php endif; ?><a class="ml-btn" href="<?= e(url('/music-library.php?workspace='.$workspaceId.'&tab='.$tab)) ?>">Cancel</a></div></form><?php endif; ?></div></main></div><script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script></body></html>