<?php
declare(strict_types=1);

/** Music Workspace is an optional VP3 capability/plugin, not an account identity. */
const VP3_MUSIC_WORKSPACE_V320 = 'music-workspace-plugin-v320-20260909';
function music_workspace_plugin_key_v320(): string{return 'music_workspace';}
function music_workspace_capability_key_v320(): string{return 'music_workspace.access';}

function music_workspace_legacy_user_v320(?array $user=null): bool
{
    $user??=current_user();if(!$user)return false;if(user_has_role('admin',$user))return true;
    $pdo=db();$uid=(int)($user['id']??0);
    if($pdo&&$uid>0&&table_exists('artist_workspaces_v181')){$stmt=$pdo->prepare('SELECT 1 FROM artist_workspaces_v181 WHERE artist_user_id=? LIMIT 1');$stmt->execute([$uid]);if($stmt->fetchColumn())return true;}
    return user_has_role('artist',$user);
}

function music_workspace_entitled_v320(?array $user=null): bool
{
    $user??=current_user();if(!$user)return false;if(user_has_role('admin',$user))return true;
    if(!function_exists('subscription_schema_ready')||!subscription_schema_ready())return music_workspace_legacy_user_v320($user);
    $sub=subscription_current($user);if(!$sub)return false;
    if(subscription_has_entitlement($user,music_workspace_capability_key_v320()))return true;
    return subscription_has_entitlement($user,'legacy.permissions')&&music_workspace_legacy_user_v320($user);
}

function music_workspace_enabled_v320(?array $user=null): bool
{
    $user??=current_user();if(!$user)return false;if(user_has_role('admin',$user))return true;if(!music_workspace_entitled_v320($user))return false;
    $pdo=db();$uid=(int)($user['id']??0);if(!$pdo||$uid<1)return false;
    if(vp3_plugin_schema_ready_v320($pdo)){
        $row=vp3_plugin_installation_v320($pdo,$uid,music_workspace_plugin_key_v320());
        if($row)return $row['status']==='enabled';
    }
    // Existing music users remain enabled until they explicitly opt out; newly entitled members must opt in.
    return music_workspace_legacy_user_v320($user);
}

function music_workspace_ensure_owner_v320(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('Sign in to use Music Workspace.');
    if(!music_workspace_enabled_v320($user)&&!user_has_role('admin',$user))throw new RuntimeException('Music Workspace is not enabled for this VP3 account.');
    if(!function_exists('artist_workspace_v181_ensure_schema'))throw new RuntimeException('Music Workspace runtime is unavailable.');
    artist_workspace_v181_ensure_schema($pdo);
    $stmt=$pdo->prepare('SELECT * FROM artist_workspaces_v181 WHERE artist_user_id=? LIMIT 1');$stmt->execute([$uid]);$workspace=$stmt->fetch();if($workspace)return $workspace;
    $name=trim((string)($user['display_name']??''));if($name==='')$name='My Music Workspace';
    $base=function_exists('artist_workspace_v181_slug')?artist_workspace_v181_slug((string)($user['username']??$name)):'music-'.$uid;
    if($base==='')$base='music-'.$uid;$slug=$base;$n=1;
    $check=$pdo->prepare('SELECT 1 FROM artist_workspaces_v181 WHERE profile_slug=? LIMIT 1');
    while(true){$check->execute([$slug]);if(!$check->fetchColumn())break;$n++;$slug=substr($base,0,105).'-'.$n;}
    $pdo->prepare('INSERT INTO artist_workspaces_v181 (artist_user_id,workspace_name,profile_slug) VALUES (?,?,?)')->execute([$uid,$name,$slug]);
    $stmt->execute([$uid]);$workspace=$stmt->fetch();if(!$workspace)throw new RuntimeException('Music Workspace could not be created.');return $workspace;
}

function music_workspace_owner_authorized_v320(?array $user=null): bool
{
    $user??=current_user();if(!$user)return false;if(user_has_role('admin',$user))return true;
    return music_workspace_enabled_v320($user);
}

function music_workspace_owner_permissions_v320(): array
{
    // Deliberately excludes platform/admin powers such as admin.access,
    // messages.manage (contact-form inbox), users.manage, ai.manage and permissions.manage.
    return [
        'artist_listening.access',
        'producer.access',
        'team.manage',
        'listening.view',
        'track_notes.manage',
        'tracks.manage',
        'albums.manage',
        'shows.manage',
        'photos.manage',
        'merch.manage',
        'posts.manage',
        'profile.manage',
        'release.manage',
    ];
}

function music_workspace_owner_permission_v320(string $permission,?array $user=null): bool
{
    $user??=current_user();if(!$user)return false;if(user_has_role('admin',$user))return true;
    if(music_workspace_enabled_v320($user)&&in_array($permission,music_workspace_owner_permissions_v320(),true))return true;
    return $permission==='release.manage'?permission_v105_has($permission,$user):has_permission($permission,$user);
}

function music_workspace_set_enabled_v320(PDO $pdo,array $user,bool $enabled): array
{
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('Sign in to manage Music Workspace.');
    if($enabled&&!music_workspace_entitled_v320($user))throw new RuntimeException('Music Workspace is not included in your current VP3 package.');

    // Create any missing plugin schema before starting the state transaction because
    // MySQL DDL can implicitly commit. From this point forward, enable/disable state
    // and workspace creation succeed or fail together.
    if(!vp3_plugin_schema_ready_v320($pdo))vp3_plugin_ensure_schema_v320($pdo);
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $row=vp3_plugin_set_enabled_v320($pdo,$uid,music_workspace_plugin_key_v320(),$enabled);
        if($enabled)music_workspace_ensure_owner_v320($pdo,$user);
        if($ownsTransaction)$pdo->commit();
        return ['enabled'=>$row['status']==='enabled','entitled'=>music_workspace_entitled_v320($user),'preserves_data'=>true];
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function music_workspace_access_v320(?array $user=null): bool{return music_workspace_enabled_v320($user);}
function music_workspace_require_v320(?array $user=null): void{if(!music_workspace_enabled_v320($user)){http_response_code(403);exit('Music Workspace is not enabled for this VP3 account.');}}
function music_workspace_status_v320(?array $user=null): array
{
    $user??=current_user();$sub=$user?subscription_current($user):null;$pdo=db();$installation=$user&&$pdo?vp3_plugin_installation_v320($pdo,(int)$user['id'],music_workspace_plugin_key_v320()):null;
    return ['enabled'=>music_workspace_enabled_v320($user),'entitled'=>music_workspace_entitled_v320($user),'installed'=>(bool)$installation,'installation_status'=>(string)($installation['status']??''),'capability'=>music_workspace_capability_key_v320(),'legacy_grandfathered'=>$user&&$sub&&subscription_has_entitlement($user,'legacy.permissions')&&music_workspace_legacy_user_v320($user),'package'=>(string)($sub['package_name']??'No package'),'preserves_data_on_disable'=>true];
}
