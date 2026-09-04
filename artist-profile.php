<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$pdo=db();
if(!$pdo||!profile_agent_schema_ready($pdo)){http_response_code(404);exit('Profile not available.');}
$slug=trim((string)($_GET['artist']??''));
$userId=max(0,(int)($_GET['user_id']??($_GET['artist_id']??0)));
$workspace=null;
if(function_exists('artist_workspace_v181_lookup_public')&&artist_workspace_v181_schema_ready($pdo)){
    $workspace=artist_workspace_v181_lookup_public($pdo,$slug,$userId);
}
if(!$workspace){http_response_code(404);exit('Profile not found.');}
$user=profile_user_row($pdo,(int)$workspace['artist_user_id']);
if(!$user){http_response_code(404);exit('Profile not found.');}
$profile=profile_migrate_artist_identity($pdo,$user);
if(empty($profile['username'])){http_response_code(404);exit('This profile needs a username before it can be published.');}
header('Location: '.profile_public_url((string)$profile['username']),true,301);
exit;
