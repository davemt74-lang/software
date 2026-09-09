<?php
declare(strict_types=1);

/** Music Workspace is a commercial VP3 capability, not an account identity. */
const VP3_MUSIC_WORKSPACE_V320 = 'music-workspace-plugin-v320-20260909';

function music_workspace_capability_key_v320(): string{return 'music_workspace.access';}

function music_workspace_legacy_user_v320(?array $user=null): bool
{
    $user??=current_user();if(!$user)return false;if(user_has_role('admin',$user))return true;
    $pdo=db();$uid=(int)($user['id']??0);
    if($pdo&&$uid>0&&table_exists('artist_workspaces_v181')){$stmt=$pdo->prepare('SELECT 1 FROM artist_workspaces_v181 WHERE artist_user_id=? LIMIT 1');$stmt->execute([$uid]);if($stmt->fetchColumn())return true;}
    return user_has_role('artist',$user);
}

function music_workspace_access_v320(?array $user=null): bool
{
    $user??=current_user();if(!$user)return false;if(user_has_role('admin',$user))return true;
    if(!function_exists('subscription_schema_ready')||!subscription_schema_ready())return music_workspace_legacy_user_v320($user);
    $sub=subscription_current($user);if(!$sub)return false;
    if(subscription_has_entitlement($user,music_workspace_capability_key_v320()))return true;
    return subscription_has_entitlement($user,'legacy.permissions')&&music_workspace_legacy_user_v320($user);
}

function music_workspace_require_v320(?array $user=null): void
{
    if(!music_workspace_access_v320($user)){http_response_code(403);exit('Music Workspace is not included in your current VP3 package.');}
}

function music_workspace_status_v320(?array $user=null): array
{
    $user??=current_user();$sub=$user?subscription_current($user):null;
    return ['enabled'=>music_workspace_access_v320($user),'capability'=>music_workspace_capability_key_v320(),'legacy_grandfathered'=>$user&&$sub&&subscription_has_entitlement($user,'legacy.permissions')&&music_workspace_legacy_user_v320($user),'package'=>(string)($sub['package_name']??'No package')];
}
