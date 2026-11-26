<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/music-song-metadata-form-v1.php';
require_login();
$user=current_user();$pdo=db();
if(!$pdo){http_response_code(503);exit('Database unavailable.');}
if(!music_workspace_resources_v330_schema_ready($pdo)||!artist_music_v185_schema_ready()||!artist_media_v182_schema_ready()){
    http_response_code(503);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Music Library upgrade required</title></head><body><main><h1>Music Library needs a database upgrade</h1><p>An administrator must complete the VP3 database upgrade before songs can be edited.</p><a href="<?= e(url('/upgrade.php')) ?>">Open database upgrade</a></main></body></html><?php
    exit;
}
$isPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
$requested=max(0,(int)($isPost?($_POST['workspace_id']??0):($_GET['workspace']??0)));
$workspace=music_workspace_resources_v330_resolve_active($pdo,$user,$requested);
if(!$workspace){http_response_code(403);exit('No accessible Music Workspace was found.');}
$workspaceId=(int)$workspace['id'];
$canManageTracks=music_workspace_resources_v330_can_manage($pdo,$workspaceId,'tracks',$user);
$canManageAlbums=music_workspace_resources_v330_can_manage($pdo,$workspaceId,'albums',$user);
$canMedia=music_workspace_resources_v330_can_manage($pdo,$workspaceId,'media',$user);
$tab=$isPost?($_POST['kind']??'track'):($_GET['tab']??'tracks');
$tab=in_array($tab,['album','albums'],true)?'albums':'tracks';
if(($tab==='albums'&&!$canManageAlbums)||($tab==='tracks'&&!$canManageTracks)){http_response_code(403);exit('Your workspace role cannot manage this catalog.');}
$archived=$tab==='tracks'&&((string)($_GET['archived']??$_POST['view_archived']??'')==='1');
$error='';$submitted=null;
if($isPost){
    $submitted=$_POST;$kind=$tab==='tracks'?'track':'album';$action=music_song_metadata_form_text_v1($_POST['action']??'save');
    try{
        if(!verify_csrf())throw new RuntimeException('Your session expired. Your fields are kept below; reload your account session before retrying.');
        if($requested<1)throw new RuntimeException('Choose a Music Workspace before saving.');
        $savedId=music_catalog_write($pdo,$workspaceId,$user,$kind,$action,$_POST,$_FILES);
        if($kind==='track'&&$action==='save'&&($_POST['after_save']??'')==='reaper')redirect(url('/music-project-import.php?workspace='.$workspaceId.'&track='.$savedId));
        flash('notice',$action==='delete'?($kind==='track'?'Song archived; its identity and history are preserved.':'Album removed; its tracks remain available.'):($action==='restore'?'Song restored as an unpublished draft.':'Music item saved.'));
        $destination='/admin/artist-music.php?workspace='.$workspaceId.'&tab='.$tab;
        if($action!=='delete')$destination.='&edit='.$savedId.'#music-form';
        redirect(url($destination));
    }catch(Throwable $e){
        if($e instanceof PDOException||$e instanceof Error){error_log('Artist music save failed: '.get_class($e).': '.$e->getMessage());$error='The music item could not be saved. Your changes are kept below; try again after the service recovers.';}
        else $error=$e->getMessage();
    }
}
$albums=artist_music_v185_albums($pdo,$workspaceId,true);
$tracks=artist_music_v185_tracks($pdo,$workspaceId,true,$archived);
if($archived)$tracks=array_values(array_filter($tracks,static fn(array $track):bool=>!empty($track['archived'])));
$photos=artist_media_v182_picker($pdo,$workspaceId,250);
$editId=max(0,(int)($submitted['id']??$_GET['edit']??0));
$editing=$editId>0?($tab==='albums'?artist_music_v185_album($pdo,$workspaceId,$editId):artist_music_v185_track($pdo,$workspaceId,$editId)):null;
if($editId>0&&!$editing&&$error==='')$error='This item is unavailable in the selected workspace.';
$showForm=$editing!==null||($editId===0&&(isset($_GET['new'])||$submitted!==null));
$values=$submitted??$editing??[];
$metadataState=$showForm&&$tab==='tracks'?music_song_metadata_form_state_v1($pdo,$workspaceId,$editId,$user,$editing??[],$submitted):null;
$revision=$submitted!==null?music_song_metadata_form_text_v1($submitted['revision']??''):($editing?music_catalog_revision($editing):'');
$base='/admin/artist-music.php?workspace='.$workspaceId;
$adminTitle='Music';$adminActive='music';require __DIR__.'/_header.php';
?>
<link rel="stylesheet" href="<?= e(url('/assets/css/music-song-metadata-form-v1.css?v=1')) ?>">
<style>
.music-tabs,.music-actions{display:flex;gap:8px;flex-wrap:wrap}.music-tabs{margin:18px 0}.music-tabs .active{box-shadow:inset 0 0 0 1px currentColor}.music-list{display:grid;gap:12px}.music-row{display:grid;grid-template-columns:72px minmax(0,1fr) auto;gap:14px;align-items:center;padding:12px;border:1px solid #dce1e9;border-radius:12px}.music-cover{width:72px;height:72px;border-radius:8px;object-fit:cover;background:#e6e9ee}.music-copy strong{display:block}.music-copy small{color:#667080}.music-row-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.music-row-actions audio{max-width:290px}.music-row-actions .song-editor-help{max-width:220px}@media(max-width:700px){.music-row{grid-template-columns:58px minmax(0,1fr)}.music-cover{width:58px;height:58px}.music-row-actions{grid-column:1/-1}.music-row-actions audio{width:100%;max-width:none}}
</style>
<div class="panel">
<div class="content-library-heading"><div><p class="muted"><?= e((string)$workspace['workspace_name']) ?></p><h2>Music Library</h2><p class="muted">Manage song details, drafts, albums and public credits in this Music Workspace.</p></div><div class="music-actions"><a class="btn" href="<?= e(url('/music-library.php?workspace='.$workspaceId)) ?>">Music Workspace library</a><a class="btn" href="<?= e(artist_workspace_v181_profile_url($workspace)) ?>" target="_blank" rel="noopener">View Artist Profile ↗</a></div></div>
<nav class="music-tabs" aria-label="Music catalog views">
<?php if($canManageTracks): ?><a class="btn <?= $tab==='tracks'&&!$archived?'active':'' ?>" href="<?= e(url($base.'&tab=tracks')) ?>">Tracks</a><a class="btn <?= $archived?'active':'' ?>" href="<?= e(url($base.'&tab=tracks&archived=1')) ?>">Archived songs</a><a class="btn" href="<?= e(url('/music-file-repair.php?workspace='.$workspaceId)) ?>">Missing files / Re-upload</a><?php endif; ?>
<?php if($canManageAlbums): ?><a class="btn <?= $tab==='albums'?'active':'' ?>" href="<?= e(url($base.'&tab=albums')) ?>">Albums <?= count($albums) ?></a><?php endif; ?>
<?php if($canMedia): ?><a class="btn" href="<?= e(url('/admin/artist-media.php?workspace='.$workspaceId)) ?>">Artwork &amp; Media</a><?php endif; ?>
</nav>
<?php if($error!==''&&!$showForm): ?><div class="song-editor-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="content-library-heading"><h3><?= $archived?'Archived songs':($tab==='tracks'?'Songs':'Albums') ?></h3><a class="btn primary" href="<?= e(url($base.'&tab='.$tab.'&new=1#music-form')) ?>">+ Add <?= $tab==='tracks'?'Song':'Album' ?></a></div>
<div class="music-list">
<?php if($tab==='tracks'): foreach($tracks as $track): ?>
<article class="music-row"><img class="music-cover" src="<?= e(url('/artist-music-image.php?type=track&id='.(int)$track['id'])) ?>" alt="" onerror="this.style.visibility='hidden'"><div class="music-copy"><strong><?= e((string)$track['title']) ?></strong><small><?= !empty($track['archived'])?'Archived':(!empty($track['is_published'])?'Published':'Draft') ?> · <?= e((string)(($track['album_title']??'')?:'Single / Unassigned')) ?><?php if((string)($track['genre']??'')!==''): ?> · <?= e((string)$track['genre']) ?><?php endif; ?></small></div><div class="music-row-actions">
<?php if(!$archived): ?><?php if((string)($track['audio_path']??'')!==''): ?><audio controls preload="none" src="<?= e(url('/artist-track-audio.php?track='.(int)$track['id'])) ?>"></audio><?php else: ?><span class="song-editor-help">Audio has not been added yet.</span><?php endif; ?><a class="btn" href="<?= e(url('/music-file-repair.php?workspace='.$workspaceId.'&kind=catalog&id='.(int)$track['id'].'#replace-audio')) ?>">Replace audio</a><a class="btn" href="<?= e(url('/music-project-import.php?workspace='.$workspaceId.'&track='.(int)$track['id'])) ?>">Import REAPER</a><?php endif; ?>
<a class="btn" href="<?= e(url($base.'&tab=tracks&edit='.(int)$track['id'].($archived?'&archived=1':'').'#music-form')) ?>"><?= $archived?'Review / restore':'Edit song details' ?></a></div></article>
<?php endforeach; if(!$tracks): ?><p class="muted"><?= $archived?'No archived songs in this workspace.':'No songs in this workspace yet.' ?></p><?php endif; ?>
<?php else: foreach($albums as $album): ?><article class="music-row"><img class="music-cover" src="<?= e(url('/artist-music-image.php?type=album&id='.(int)$album['id'])) ?>" alt="" onerror="this.style.visibility='hidden'"><div class="music-copy"><strong><?= e((string)$album['title']) ?></strong><small><?= !empty($album['is_published'])?'Published':'Draft' ?> · <?= (int)$album['track_count'] ?> tracks<?php if(!empty($album['release_date'])): ?> · <?= e((string)$album['release_date']) ?><?php endif; ?></small></div><div class="music-row-actions"><a class="btn" href="<?= e(url($base.'&tab=albums&edit='.(int)$album['id'].'#music-form')) ?>">Edit</a></div></article><?php endforeach; if(!$albums): ?><p class="muted">No albums in this workspace yet.</p><?php endif; endif; ?>
</div></div>
<?php if($showForm)music_song_catalog_editor_v1([
    'kind'=>$tab==='tracks'?'track':'album','values'=>$values,'editing'=>$editing,'revision'=>$revision,
    'workspace_id'=>$workspaceId,'state'=>$metadataState,'albums'=>$albums,'photos'=>$photos,'error'=>$error,
    'can_media'=>$canMedia,'allow_album_delete'=>true,'prefix'=>'music-form','reselect_files'=>$isPost&&!empty($_FILES['audio_file']['name']),
    'action_url'=>url($base.'&tab='.$tab),'cancel_url'=>url($base.'&tab='.$tab.($archived?'&archived=1':'')),
    'reload_url'=>url($base.'&tab='.$tab.'&edit='.$editId.'#music-form'),
]); ?>
<script src="<?= e(url('/assets/js/music-song-metadata-form-v1.js?v=1')) ?>" defer></script>
<?php require __DIR__.'/_footer.php'; ?>
