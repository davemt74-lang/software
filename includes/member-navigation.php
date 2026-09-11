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

function member_navigation_package_permission(?array $user,string $permission,bool $legacyFallback): bool
{
    $user??=current_user();if(!$user)return false;
    if(function_exists('subscription_effective_permission'))return subscription_effective_permission($permission,$user);
    return $legacyFallback;
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

    $profileUrl=member_navigation_profile_url($user);if($profileUrl!=='')$add($links,'profile','View Profile',$profileUrl,'identity');
    if($accountAllowed){
        $add($links,'account','My Account',url('/account.php'),'identity');
        $add($links,'plugins','Plugins',url('/plugins.php'),'identity');
        $add($links,'messages','Messages',url('/messages.php'),'identity');
        $add($links,'subscription','Plan & Usage',url('/subscription.php'),'identity');
        if(function_exists('token_pack_schema_ready')&&token_pack_schema_ready())$add($links,'token_packs','Buy AI Tokens',url('/token-packs.php'),'identity');
        if($chatAllowed)$add($links,'ai_usage','AI Usage History',url('/ai-usage.php'),'identity');
        $add($links,'contacts','My Contacts',url('/contacts.php'),'identity');
    }
    if(member_navigation_entitled($user,'profile_agent.access',personal_capability_has_v242('profile_agent.access',$user)))$add($links,'profile_agent','Profile Agent',url('/profile-agent.php'),'identity');
    if($accountAllowed&&function_exists('agent_scheduling_schema_ready_v430')&&agent_scheduling_schema_ready_v430())$add($links,'scheduling','Scheduling',url('/scheduling.php'),'agent');
    if($accountAllowed&&function_exists('agent_appointment_lifecycle_schema_ready_v700')&&agent_appointment_lifecycle_schema_ready_v700())$add($links,'appointment_lifecycle','Appointment Lifecycle',url('/appointment-lifecycle.php'),'agent');
    if(member_navigation_entitled($user,'knowledge.access',personal_capability_has_v242('personal_knowledge.access',$user)))$add($links,'knowledge','My Knowledge',url('/knowledge.php'),'identity');
    if(member_navigation_entitled($user,'transcription.access',member_navigation_package_permission($user,'artist_listening.access',has_permission('artist_listening.access',$user))))$add($links,'transcriptions','My Transcriptions',url('/artist-listening.php'),'identity');
    if(member_navigation_entitled($user,'voice.access',personal_capability_has_v242('voice_profile.access',$user)))$add($links,'voice_profile','Voice Profile',url('/voice-profile.php'),'agent');

    $teamState=function_exists('team_subscription_state')?team_subscription_state($user):['authorized'=>false];
    if(!empty($teamState['authorized'])){
        $add($links,'team','My Team',url('/team.php'),'collaboration');
        if($accountAllowed&&function_exists('agent_team_scheduling_schema_ready_v600')&&agent_team_scheduling_schema_ready_v600())$add($links,'team_scheduling','Team Scheduling',url('/team-scheduling.php'),'collaboration');
    }

    $pdo=db();
    $musicEnabled=function_exists('music_workspace_enabled_v320')?music_workspace_enabled_v320($user):false;
    $musicWorkspaces=[];
    if($pdo&&function_exists('music_workspace_resources_v330_accessible_workspaces')){
        try{
            if(!function_exists('music_workspace_resources_v330_schema_ready')||music_workspace_resources_v330_schema_ready($pdo)){
                $musicWorkspaces=music_workspace_resources_v330_accessible_workspaces($pdo,$user);
            }
        }catch(Throwable $e){$musicWorkspaces=[];}
    }
    if($musicEnabled||$musicWorkspaces)$add($links,'music_workspace','Music Workspace',url('/music-workspace.php'),'creator');

    $memberships=[];if($pdo&&function_exists('artist_workspace_v104_memberships_for_user')){try{$memberships=artist_workspace_v104_memberships_for_user($pdo,(int)$user['id']);}catch(Throwable $e){}}
    if($memberships)$add($links,'team_workspaces','Team Workspaces',url('/admin/team-workspaces.php'),'creator');

    if(user_has_role('admin',$user))$add($links,'admin','Admin Dashboard',url('/admin/index.php'),'admin');
    $add($links,'logout','Log Out',url('/logout.php'),'session',true);
    return $links;
}
