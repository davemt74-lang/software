<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user=current_user();
if(!$user){
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Sign in to continue.']);
    exit;
}

$create=[
    'track'=>has_permission('tracks.manage',$user),
    'album'=>has_permission('albums.manage',$user),
    'event'=>has_permission('shows.manage',$user),
    'knowledge'=>has_permission('knowledge.manage',$user),
    'user'=>has_permission('users.manage',$user),
    'playlist'=>permission_v105_has('playlists.manage',$user),
    'merch'=>has_permission('merch.manage',$user),
    'post'=>has_permission('posts.manage',$user),
    'photo'=>has_permission('photos.manage',$user),
];

$artistProfileUrl=artist_workspace_v181_profile_url_for_user($user);
if($artistProfileUrl==='' && (int)($user['id']??0)>0){
    $pdo=db();
    if($pdo && artist_workspace_v181_schema_ready($pdo)){
        try{
            $workspace=artist_workspace_v181_lookup_public($pdo,'',(int)$user['id']);
            if($workspace) $artistProfileUrl=artist_workspace_v181_profile_url($workspace);
        }catch(Throwable $e){
            $artistProfileUrl='';
        }
    }
}

echo json_encode([
    'ok'=>true,
    'create'=>$create,
    'playlists_manage'=>$create['playlist'],
    'artist_listening_access'=>has_permission('artist_listening.access',$user),
    'artist_profile_url'=>$artistProfileUrl,
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
