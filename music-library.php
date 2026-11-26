<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/music-song-metadata-form-v1.php';
require_login();
$user=current_user();$pdo=db();
if(!$pdo){http_response_code(503);exit('Database unavailable.');}
if(!music_workspace_resources_v330_schema_ready($pdo)||!artist_music_v185_schema_ready()||!artist_media_v182_schema_ready()){
    http_response_code(503);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Music Library upgrade required</title></head><body><main><h1>Music Library needs a database upgrade</h1><p>An administrator must complete the VP3 database upgrade before this library can be used.</p><a href="<?= e(url('/upgrade.php')) ?>">Open database upgrade</a></main></body></html><?php
    exit;
}
$isPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
$requested=max(0,(int)($isPost?($_POST['workspace_id']??0):($_GET['workspace']??0)));
$workspace=music_workspace_resources_v330_resolve_active($pdo,$user,$requested);
if(!$workspace){http_response_code(403);exit('No accessible Music Workspace was found.');}
$workspaceId=(int)$workspace['id'];
$role=music_workspace_resources_v330_member_role($pdo,$workspaceId,(int)$user['id']);
$canTracks=music_workspace_resources_v330_can_manage($pdo,$workspaceId,'tracks',$user);
$canAlbums=music_workspace_resources_v330_can_manage($pdo,$workspaceId,'albums',$user);
$canMedia=music_workspace_resources_v330_can_manage($pdo,$workspaceId,'media',$user);
$canProduction=music_workspace_resources_v330_can_manage($pdo,$workspaceId,'production',$user)||$canTracks;
$tab=($isPost?($_POST['kind']??'track'):($_GET['tab']??'tracks'));
$tab=in_array($tab,['album','albums'],true)?'albums':'tracks';
if($role==='producer')$tab='tracks';
$archived=$canTracks&&$tab==='tracks'&&((string)($_GET['archived']??$_POST['view_archived']??'')==='1');
$error='';$submitted=null;
if($isPost){
    $submitted=$_POST;
    $kind=$tab==='albums'?'album':'track';
    $action=music_song_metadata_form_text_v1($_POST['action']??'save');
    try{
        if(!verify_csrf())throw new RuntimeException('Your session expired. Your fields are kept below; reload your account session before retrying.');
        if($requested<1)throw new RuntimeException('Choose a Music Workspace before saving.');
        if(($kind==='track'&&!$canTracks)||($kind==='album'&&!$canAlbums))throw new RuntimeException('Your workspace role cannot change this catalog item.');
        $savedId=music_catalog_write($pdo,$workspaceId,$user,$kind,$action,$_POST,$_FILES);
        if($kind==='track'&&$action==='save'&&($_POST['after_save']??'')==='reaper')redirect(url('/music-project-import.php?workspace='.$workspaceId.'&track='.$savedId));
        flash('notice',$action==='delete'?($kind==='track'?'Song archived; its identity and history are preserved.':'Album removed; its tracks remain available.'):($action==='restore'?'Song restored as an unpublished draft.':'Music item saved.'));
        $destination='/music-library.php?workspace='.$workspaceId.'&tab='.$tab;
        if($action!=='delete')$destination.='&edit='.$savedId.'#editor';
        redirect(url($destination));
    }catch(Throwable $e){
        if($e instanceof PDOException||$e instanceof Error){error_log('Music Library save failed: '.get_class($e).': '.$e->getMessage());$error='The song could not be saved. Your changes are kept below; try again after the service recovers.';}
        else $error=$e->getMessage();
    }
}
$workspaces=music_workspace_resources_v330_accessible_workspaces($pdo,$user);
$photos=$canTracks||$canAlbums?artist_media_v182_picker($pdo,$workspaceId,250):[];
$albums=artist_music_v185_albums($pdo,$workspaceId,true);
$tracks=artist_music_v185_tracks($pdo,$workspaceId,true,$archived);
if($archived)$tracks=array_values(array_filter($tracks,static fn(array $track):bool=>!empty($track['archived'])));
if($role==='producer'){
    $uid=(int)$user['id'];
    $tracks=array_values(array_filter($tracks,static function(array $track)use($pdo,$uid,$workspaceId):bool{
        $sourceId=(int)($track['source_track_id']??0);if($sourceId<1)return false;
        $s=$pdo->prepare('SELECT 1 FROM tracks WHERE id=? AND workspace_id=? AND producer_user_id=? LIMIT 1');$s->execute([$sourceId,$workspaceId,$uid]);return (bool)$s->fetchColumn();
    }));
}
$editId=max(0,(int)($submitted['id']??$_GET['edit']??0));
$editing=$editId>0?($tab==='albums'?artist_music_v185_album($pdo,$workspaceId,$editId):artist_music_v185_track($pdo,$workspaceId,$editId)):null;
if($editId>0&&!$editing&&$error==='')$error='This item is unavailable in the selected workspace.';
$canEdit=$tab==='tracks'?$canTracks:$canAlbums;
$showForm=$canEdit&&($editing!==null||($editId===0&&(isset($_GET['new'])||$submitted!==null)));
$values=$submitted??$editing??[];
$metadataState=$showForm&&$tab==='tracks'?music_song_metadata_form_state_v1($pdo,$workspaceId,$editId,$user,$editing??[],$submitted):null;
$revision=$submitted!==null?music_song_metadata_form_text_v1($submitted['revision']??''):($editing?music_catalog_revision($editing):'');
$notice=flash('notice');
$memberHeaderUser=$user;$memberHeaderTitle='Music Library';$memberHeaderSubtitle=(string)$workspace['workspace_name'].' · '.ucfirst($role?:'member');$memberHeaderActions='';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>VP3 | Music Library</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/css/music-song-metadata-form-v1.css?v=1')) ?>"><style>
.chat-main.ml-main{display:flex;flex-direction:column;background:#f7f7f8;min-width:0;min-height:0;overflow:hidden}.chat-main.ml-main>.ml-wrap{box-sizing:border-box;width:100%;min-width:0;min-height:0;height:auto;flex:1 1 auto;max-width:1180px;margin:0 auto;padding:28px 24px 70px;overflow-x:hidden;overflow-y:auto}.ml-bar,.ml-tabs,.ml-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.ml-bar{justify-content:space-between;margin-bottom:18px}.ml-bar select{border:1px solid #dfe1e4;border-radius:10px;background:#fff;padding:10px;color:#202226}.ml-tabs{margin:12px 0 18px}.ml-btn{display:inline-flex;text-decoration:none;border:1px solid #dadde1;border-radius:10px;background:#fff;color:#202226;padding:9px 12px;font-weight:750;cursor:pointer}.ml-btn.primary,.ml-btn.active{background:#202226;color:#fff;border-color:#202226}.ml-btn:disabled{opacity:.5;cursor:default}.ml-grid{display:grid;gap:10px}.ml-row{background:#fff;border:1px solid #e1e3e6;border-radius:14px;padding:14px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px}.ml-row h3{margin:0 0 4px}.ml-row p{margin:0;color:#74777e;font-size:13px}.ml-empty{padding:28px;background:#fff;border:1px dashed #d7d9dd;border-radius:14px;color:#74777e}@media(max-width:720px){.ml-row{grid-template-columns:1fr}.chat-main.ml-main>.ml-wrap{padding:20px 14px 60px}}
</style></head><body><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='music_workspace';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main ml-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="ml-wrap">
<div class="ml-bar"><form method="get"><label>Workspace <select name="workspace" onchange="this.form.submit()"><?php foreach($workspaces as $w): ?><option value="<?= (int)$w['id'] ?>" <?= (int)$w['id']===$workspaceId?'selected':'' ?>><?= e((string)$w['workspace_name']) ?><?= !empty($w['access_role'])?' · '.e((string)$w['access_role']):'' ?></option><?php endforeach; ?></select></label><input type="hidden" name="tab" value="<?= e($tab) ?>"></form><div class="ml-actions"><a class="ml-btn" href="<?= e(url('/music-workspace.php?workspace='.$workspaceId)) ?>">Workspace Home</a><a class="ml-btn" href="<?= e(url('/messages.php')) ?>">Team Messages</a><?php if($canTracks): ?><a class="ml-btn" href="<?= e(url('/music-file-repair.php?workspace='.$workspaceId)) ?>">Missing files / Re-upload</a><?php endif; ?></div></div>
<?php if($notice): ?><p class="song-meta-notice" role="status"><?= e($notice) ?></p><?php endif; ?>
<?php if($error!==''&&!$showForm): ?><div class="song-editor-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<nav class="ml-tabs" aria-label="Music catalog views"><a class="ml-btn <?= $tab==='tracks'&&!$archived?'active':'' ?>" href="<?= e(url('/music-library.php?workspace='.$workspaceId.'&tab=tracks')) ?>">Tracks</a><?php if($role!=='producer'): ?><a class="ml-btn <?= $tab==='albums'?'active':'' ?>" href="<?= e(url('/music-library.php?workspace='.$workspaceId.'&tab=albums')) ?>">Albums <?= count($albums) ?></a><?php endif; ?><?php if($canTracks): ?><a class="ml-btn <?= $archived?'active':'' ?>" href="<?= e(url('/music-library.php?workspace='.$workspaceId.'&tab=tracks&archived=1')) ?>">Archived songs</a><?php endif; ?><?php if($canEdit): ?><a class="ml-btn primary" href="<?= e(url('/music-library.php?workspace='.$workspaceId.'&tab='.$tab.'&new=1#editor')) ?>">+ Add <?= $tab==='tracks'?'Song':'Album' ?></a><?php endif; ?></nav>
<div class="ml-grid">
<?php if($tab==='tracks'): foreach($tracks as $track): ?>
<article class="ml-row"><div><h3><?= e((string)$track['title']) ?></h3><p><?= !empty($track['archived'])?'Archived':(!empty($track['is_published'])?'Published':'Draft') ?> · <?= e((string)(($track['album_title']??'')?:'Single / Unassigned')) ?><?= !empty($track['source_track_id'])?' · Studio linked':'' ?></p></div><div class="ml-actions">
<?php if(!$archived&&($canProduction||$role==='producer')): ?><a class="ml-btn primary" href="<?= e(url('/music-studio.php?workspace='.$workspaceId.'&track='.(int)$track['id'])) ?>">Open Studio</a><a class="ml-btn" href="<?= e(url('/music-project-import.php?workspace='.$workspaceId.'&track='.(int)$track['id'])) ?>">Import REAPER</a><?php endif; ?>
<?php if($canTracks): ?><?php if(!$archived): ?><a class="ml-btn" href="<?= e(url('/music-file-repair.php?workspace='.$workspaceId.'&kind=catalog&id='.(int)$track['id'].'#replace-audio')) ?>">Replace audio</a><?php endif; ?><a class="ml-btn" href="<?= e(url('/music-library.php?workspace='.$workspaceId.'&tab=tracks&edit='.(int)$track['id'].($archived?'&archived=1':'').'#editor')) ?>"><?= $archived?'Review / restore':'Edit song details' ?></a><?php endif; ?></div></article>
<?php endforeach; if(!$tracks): ?><div class="ml-empty"><?= $archived?'No archived songs in this workspace.':'No tracks are available in this workspace yet.' ?></div><?php endif; ?>
<?php else: foreach($albums as $album): ?><article class="ml-row"><div><h3><?= e((string)$album['title']) ?></h3><p><?= !empty($album['is_published'])?'Published':'Draft' ?> · <?= (int)$album['track_count'] ?> tracks</p></div><div class="ml-actions"><?php if($canAlbums): ?><a class="ml-btn" href="<?= e(url('/music-library.php?workspace='.$workspaceId.'&tab=albums&edit='.(int)$album['id'].'#editor')) ?>">Edit</a><?php endif; ?></div></article><?php endforeach; if(!$albums): ?><div class="ml-empty">No albums are available in this workspace yet.</div><?php endif; endif; ?>
</div>
<?php if($showForm)music_song_catalog_editor_v1([
    'kind'=>$tab==='tracks'?'track':'album','values'=>$values,'editing'=>$editing,'revision'=>$revision,
    'workspace_id'=>$workspaceId,'state'=>$metadataState,'albums'=>$albums,'photos'=>$photos,'error'=>$error,
    'can_media'=>$canMedia,'prefix'=>'editor','reselect_files'=>$isPost&&!empty($_FILES['audio_file']['name']),
    'action_url'=>url('/music-library.php?workspace='.$workspaceId.'&tab='.$tab),
    'cancel_url'=>url('/music-library.php?workspace='.$workspaceId.'&tab='.$tab.($archived?'&archived=1':'')),
    'reload_url'=>url('/music-library.php?workspace='.$workspaceId.'&tab='.$tab.'&edit='.$editId.'#editor'),
]); ?>
</div></main></div><script src="<?= e(url('/assets/js/music-song-metadata-form-v1.js?v=1')) ?>" defer></script></body></html>
