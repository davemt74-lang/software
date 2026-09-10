<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();
$user=current_user();$pdo=db();
if(!$pdo){http_response_code(503);exit('Database unavailable.');}
if(!music_workspace_resources_v330_schema_ready($pdo)){flash('error','Music Workspace ownership upgrade is required.');redirect(url('/upgrade.php'));}
$requestedWorkspace=max(0,(int)($_GET['workspace']??0));
$workspace=music_workspace_resources_v330_resolve_active($pdo,$user,$requestedWorkspace);
if(!$workspace){http_response_code(403);exit('No accessible Music Workspace was found.');}
$workspaceId=(int)$workspace['id'];
$catalogTrackId=max(0,(int)($_GET['track']??0));
if($catalogTrackId<1){flash('error','Choose a Music Workspace track first.');redirect(url('/music-library.php?workspace='.$workspaceId));}
try{
    $source=music_workspace_resources_v330_ensure_production_track($pdo,$workspaceId,$catalogTrackId,$user);
    $sourceId=(int)($source['id']??0);
    if($sourceId<1)throw new RuntimeException('Production track could not be prepared.');
    redirect(url('/admin/stems.php?track='.$sourceId.'&return='.rawurlencode(url('/music-library.php?workspace='.$workspaceId))));
}catch(Throwable $e){
    flash('error',$e->getMessage());
    redirect(url('/music-library.php?workspace='.$workspaceId));
}
