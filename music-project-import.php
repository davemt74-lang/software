<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();
require_once __DIR__.'/includes/stem-import-media.php';
$user=current_user();$pdo=db();
if(!$pdo){http_response_code(503);exit('Database unavailable.');}
$workspaceId=max(0,(int)($_GET['workspace']??0));$catalogId=max(0,(int)($_GET['track']??0));
if($workspaceId<1||$catalogId<1){http_response_code(400);exit('Choose a saved Music Library track first.');}
try{
    $track=music_workspace_resources_v331_prepare_studio_track($pdo,$workspaceId,$catalogId,$user);
    if(!can_manage_track_production($track,$user))throw new RuntimeException('Production access is required.');
}catch(Throwable $e){http_response_code(403);exit('This track is not available for project import.');}
$sourceId=(int)$track['id'];$project=stem_project_for_track($sourceId);$stems=stems_for_track($sourceId);
$checks=[];
foreach($stems as $stem){
    $resolved=stem_media_v229_resolve_path(STONEFELLOW_ROOT,(string)$stem['file_path']);
    $inspection=!empty($resolved['ok'])?stem_media_v229_inspect_file((string)$resolved['path']):['ok'=>false];
    $checks[]=['name'=>(string)$stem['file_name'],'ok'=>!empty($inspection['ok'])];
}
$rppReady=false;
if($project){
    $base=realpath(STONEFELLOW_ROOT.'/uploads/projects');
    $rpp=realpath(STONEFELLOW_ROOT.'/'.ltrim((string)($project['rpp_file_path']??''),'/'));
    $rppReady=$base&&$rpp&&str_starts_with($rpp,rtrim($base,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)&&is_file($rpp)&&is_readable($rpp)&&filesize($rpp)>0;
}
$back=url('/music-library.php?workspace='.$workspaceId.'&edit='.$catalogId.'#editor');
$self=url('/music-project-import.php?workspace='.$workspaceId.'&track='.$catalogId);
$studio=url('/admin/stems.php?track='.$sourceId.'&return='.rawurlencode($self));
$memberHeaderUser=$user;$memberHeaderTitle='REAPER project';$memberHeaderSubtitle=(string)$track['title'];$memberHeaderActions='';
$config=['endpoint'=>url('/api/stem-direct-v79.php'),'trackId'=>$sourceId,'csrf'=>csrf_token(),'chunkBytes'=>stem_chunk_bytes(),'maxBytes'=>stem_max_package_bytes(),'track'=>$track,'hasExisting'=>(bool)$project,'reviewBeforeSave'=>true,'requireProject'=>true,'successUrl'=>$self];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>VP3 | REAPER project</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><style>
.project-main{background:#f7f7f8;min-width:0}.project-wrap{max-width:960px;margin:auto;padding:24px 20px 70px}.project-card{background:white;border:1px solid #dfe1e4;border-radius:16px;padding:22px;margin:16px 0}.project-card h2{margin-top:0}.project-actions{display:flex;flex-wrap:wrap;gap:10px;margin:16px 0}.project-btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 15px;border:1px solid #ccc;border-radius:9px;background:white;color:#202226;text-decoration:none;font:inherit;cursor:pointer}.project-btn.primary{background:#202226;color:white}.project-card input[type=file]{max-width:100%}.project-card p,.project-card li{line-height:1.6;overflow-wrap:anywhere}.project-progress{height:8px;background:#eee;border-radius:8px;overflow:hidden;margin:12px 0}.project-progress span{display:block;background:#25785b;height:100%;width:0}.project-status{white-space:pre-wrap;overflow-wrap:anywhere}.project-status.error{color:#a32323}.project-card small{color:#60646a}.project-files{padding-left:20px}@media(max-width:600px){.project-wrap{padding:12px 12px 65px}.project-card{padding:16px}.project-actions>*{flex:1 1 150px}}
</style></head><body><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='music_workspace';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main project-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="project-wrap"><div class="project-actions"><a class="project-btn" href="<?= e($back) ?>">Back to song</a><a class="project-btn" href="<?= e($studio) ?>">Open Stem Studio</a></div>
<section class="project-card"><h2><?= e((string)$track['title']) ?></h2><p>Attach a REAPER project to this existing song. Its listening audio, artwork and visibility stay as saved.</p>
<?php if($project): ?><p><strong>Saved project:</strong> <?= e((string)$project['project_name']) ?> · <?= count($stems) ?> active stems<?= !empty($project['tempo_bpm'])?' · '.e((string)$project['tempo_bpm']).' BPM':'' ?> · <?= e((string)($project['time_signature']??'')) ?></p><p>REAPER project file: <?= $rppReady?'Available':'Missing or unreadable — import a complete package' ?></p><p>Last imported: <?= e((string)($project['imported_at']??'')) ?></p><ul class="project-files"><?php foreach($checks as $check): ?><li><?= e($check['name']) ?> — <?= $check['ok']?'Audio available':'Missing or unreadable audio — restore this file or import a complete package' ?></li><?php endforeach; ?></ul><?php else: ?><p>No REAPER project has been imported for this song yet.</p><?php endif; ?></section>
<section class="project-card" aria-labelledby="project-upload-title"><h2 id="project-upload-title"><?= $project?'Replace REAPER project':'Add REAPER project' ?></h2><p>Choose one ZIP containing the <strong>.rpp project and exported MP3 or WAV stems</strong>. Export or consolidate the stems in REAPER first; an RPP alone does not contain the recordings. MP3 stems are preferred when both formats are present.</p><p>The browser extracts the ZIP and uploads the project and stems in small chunks. Review the parsed instruments, tempo, plugins and stem mapping, then choose <strong>Save Import</strong>. Cancel Import keeps the current project.</p>
<label for="stemZipInput">REAPER project ZIP</label><br><input id="stemZipInput" type="file" accept=".zip,application/zip"><div class="project-actions"><button type="button" id="stemUploadButton" class="project-btn primary">Upload and review ZIP</button><button type="button" id="stemUploadCancel" class="project-btn" hidden>Cancel upload</button></div>
<div id="stemUploadProgress" hidden><div class="project-progress"><span id="stemUploadProgressBar"></span></div><strong id="stemUploadProgressText"></strong></div><p id="stemUploadStatus" class="project-status" role="status" aria-live="polite"></p><small>Maximum package: <?= number_format(stem_max_package_bytes()/1048576) ?> MB, up to 96 stems. Original REAPER plugins are described and mapped where supported; they are not installed in the browser.</small></section>
<section class="project-card"><h2>Talk about the project</h2><p>After saving, ask Longfellow: “Tell me about the REAPER project for <?= e((string)$track['title']) ?>.” Imported project details are available through the existing production tools, and the import appears in Agent Brain activity.</p><a class="project-btn" href="<?= e(url('/chat.php')) ?>">Open Chat</a></section>
</div></main></div><script>window.STONEFELLOW_BROWSER_ZIP=<?= json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES) ?>;</script><script src="<?= e(url('/admin/stem-import-review-v79.js?v=library-review-v1')) ?>"></script><script src="<?= e(url('/admin/stem-browser-zip-v79.js?v=library-review-v1')) ?>"></script><script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script></body></html>
