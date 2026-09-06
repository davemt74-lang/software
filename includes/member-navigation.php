<?php
declare(strict_types=1);

function member_navigation_profile_url(?array $user = null): string
{
    $user ??= current_user();
    if (!$user || (int)($user['id'] ?? 0) < 1) return '';
    $pdo=db();
    if($pdo&&function_exists('profile_agent_schema_ready')&&profile_agent_schema_ready($pdo)){
        try{
            $profile=profile_migrate_artist_identity($pdo,$user);$username=trim((string)($profile['username']??''));
            if($username!==''&&!empty($profile['is_active'])){$profileUrl=profile_public_url($username);if(empty($profile['is_public']))$profileUrl.=(str_contains($profileUrl,'?')?'&':'?').'preview=1';return $profileUrl;}
        }catch(Throwable $e){}
    }
    if(function_exists('artist_workspace_v181_profile_url_for_user')){try{return artist_workspace_v181_profile_url_for_user($user);}catch(Throwable $e){return '';}}
    return '';
}

function member_agent_voice_enabled(?array $user = null): bool
{
    $user??=current_user();if(!$user||(int)($user['id']??0)<1)return true;$pdo=db();if(!$pdo||!function_exists('chat_settings_get_v237'))return true;
    try{$settings=chat_settings_get_v237($pdo,(int)$user['id']);return ($settings['agent_voice_enabled']??true)!==false;}catch(Throwable $e){return true;}
}

function member_navigation_entitled(?array $user,string $capability,bool $legacyFallback=true): bool
{
    $user??=current_user();if(!$user)return false;
    if(user_has_role('admin',$user))return true;
    if(!function_exists('subscription_schema_ready')||!subscription_schema_ready())return $legacyFallback;
    $sub=subscription_current($user);if(!$sub)return false;
    if(subscription_has_entitlement($user,'legacy.permissions'))return $legacyFallback;
    return subscription_has_entitlement($user,$capability);
}

/**
 * Packages are a commercial ceiling, never an authorization source.
 * The persisted permission must already be allowed before a package can expose it.
 */
function member_navigation_package_permission(?array $user,string $permission,bool $legacyFallback): bool
{
    $user??=current_user();if(!$user)return false;
    $authorized=has_permission($permission,$user);
    if(!$authorized)return false;
    if(user_has_role('admin',$user))return true;
    if($permission==='account.access')return true;
    if(!function_exists('subscription_schema_ready')||!subscription_schema_ready())return $legacyFallback;
    $sub=subscription_current($user);if(!$sub)return false;
    if(subscription_has_entitlement($user,'legacy.permissions'))return $legacyFallback;
    return subscription_package_grants_permission($user,$permission);
}

function member_agent_voice_toggle_html(?array $user = null): string
{
    $user??=current_user();
    if(!$user||!member_navigation_package_permission($user,'chat.access',has_permission('chat.access',$user))||!member_navigation_entitled($user,'voice.access',true))return '';
    $checked=member_agent_voice_enabled($user)?' checked':'';
    return '<label class="member-agent-voice-toggle" title="Speak proactive and Profile Agent messages"><span class="member-agent-voice-label">Agent Voice</span><input type="checkbox" data-agent-voice-toggle aria-label="Agent Voice"'.$checked.'><span class="member-agent-voice-switch" aria-hidden="true"><span></span></span></label>';
}

function member_navigation_menu_links(?array $user = null): array
{
    $user??=current_user();if(!$user)return [];$links=[];
    $add=static function(array &$target,string $key,string $label,string $href,string $group,bool $danger=false):void{if($href==='')return;$target[]=['key'=>$key,'label'=>$label,'url'=>$href,'group'=>$group,'danger'=>$danger];};

    $chatAllowed=member_navigation_package_permission($user,'chat.access',has_permission('chat.access',$user))&&member_navigation_entitled($user,'main_ai.access',true);
    $accountAllowed=member_navigation_package_permission($user,'account.access',has_permission('account.access',$user));
    if($chatAllowed)$add($links,'chat','Main Feed',url('/chat.php'),'primary');

    $profileUrl = member_navigation_profile_url($user);if($profileUrl!=='')$add($links,'profile','View Profile',$profileUrl,'identity');
    if($accountAllowed){$add($links,'account','My Account',url('/account.php'),'identity');$add($links,'subscription','Plan & Usage',url('/subscription.php'),'identity');$add($links,'contacts','My Contacts',url('/contacts.php'),'identity');}
    if(member_navigation_entitled($user,'profile_agent.access',personal_capability_has_v242('profile_agent.access',$user)))$add($links,'profile_agent','Profile Agent',url('/profile-agent.php'),'identity');
    if(member_navigation_entitled($user,'knowledge.access',personal_capability_has_v242('personal_knowledge.access',$user)))$add($links,'knowledge','My Knowledge',url('/knowledge.php'),'identity');
    if(member_navigation_entitled($user,'transcription.access',has_permission('artist_listening.access',$user)))$add($links,'transcriptions','My Transcriptions',url('/artist-listening.php'),'identity');
    if(member_navigation_entitled($user,'voice.access',personal_capability_has_v242('voice_profile.access',$user)))$add($links,'voice_profile','Voice Profile',url('/voice-profile.php'),'agent');

    $artistWorkspaceAllowed=user_has_role('artist',$user)&&(
        member_navigation_package_permission($user,'tracks.manage',has_permission('tracks.manage',$user))||
        member_navigation_package_permission($user,'albums.manage',has_permission('albums.manage',$user))||
        member_navigation_package_permission($user,'shows.manage',has_permission('shows.manage',$user))||
        member_navigation_package_permission($user,'photos.manage',has_permission('photos.manage',$user))||
        member_navigation_package_permission($user,'merch.manage',has_permission('merch.manage',$user))||
        member_navigation_package_permission($user,'posts.manage',has_permission('posts.manage',$user))||
        permission_v105_has('release.manage',$user)
    );
    if($artistWorkspaceAllowed)$add($links,'artist_workspace','Artist Workspace',url('/admin/artist.php'),'creator');

    $memberships=[];$pdo=db();if($pdo&&function_exists('artist_workspace_v104_memberships_for_user')){try{$memberships=artist_workspace_v104_memberships_for_user($pdo,(int)$user['id']);}catch(Throwable $e){}}
    if($memberships)$add($links,'team_workspaces','Team Workspaces',url('/admin/team-workspaces.php'),'creator');

    $adminAllowed=member_navigation_package_permission($user,'admin.access',has_permission('admin.access',$user));
    if($adminAllowed)$add($links,'admin','Admin Dashboard',url('/admin/index.php'),'admin');
    $add($links,'logout','Log Out',url('/logout.php'),'session',true);
    return $links;
}
