<?php
declare(strict_types=1);

/** Keep artist accounts inside their private workspace managers. */
function artist_admin_routing_v185_apply(): void
{
    $user=current_user();
    if(!$user || !user_has_role('artist',$user) || !artist_workspace_v181_schema_ready()) return;
    $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??''));
    if(str_ends_with($script,'/admin/tracks.php')) redirect(url('/admin/artist-music.php?tab=tracks'));
    if(str_ends_with($script,'/admin/albums.php')) redirect(url(has_permission('albums.manage',$user)?'/admin/artist-music.php?tab=albums':'/admin/artist-music.php?tab=tracks'));
    if(str_ends_with($script,'/admin/artist.php')){
        $collection=(string)($_GET['collection']??'');
        if($collection==='tracks') redirect(url('/admin/artist-music.php?tab=tracks'));
        if($collection==='albums') redirect(url(has_permission('albums.manage',$user)?'/admin/artist-music.php?tab=albums':'/admin/artist-music.php?tab=tracks'));
    }
    if(str_ends_with($script,'/admin/artist-music.php') && (string)($_GET['tab']??'tracks')==='albums' && !has_permission('albums.manage',$user)) redirect(url('/admin/artist-music.php?tab=tracks'));
}
