<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_EXTERNAL_V120='profile-webmcp-external-v120-20260928';

function vp3_profile_webmcp_external_tool_names_v120(bool $statefulChat=false,bool $scheduling=false): array
{
    $tools=[
        'vp3.profile.capabilities.get',
        'vp3.profile.get',
        'vp3.intent.resolve',
        'vp3.agent.get',
    ];
    if($statefulChat){
        $tools[]='vp3.agent.chat.start';
        $tools[]='vp3.agent.conversation.get';
        $tools[]='vp3.agent.message.send';
        $tools[]='vp3.agent.owner_handoff.request';
    }
    if($scheduling){
        foreach([
            'vp3.booking.options.list','vp3.booking.availability.list','vp3.booking.prepare','vp3.booking.confirm',
            'vp3.booking.get','vp3.booking.reschedule.prepare','vp3.booking.reschedule.confirm',
            'vp3.booking.cancel.prepare','vp3.booking.cancel.confirm'
        ] as $tool)$tools[]=$tool;
    }
    return $tools;
}

function vp3_profile_webmcp_external_origin_v120(array $property,string $origin): string
{
    $origin=trim($origin);
    if($origin==='')throw new RuntimeException('Connected-site Origin is required.');
    $parts=parse_url($origin);
    if(!is_array($parts))throw new RuntimeException('Connected-site Origin is invalid.');
    $scheme=strtolower((string)($parts['scheme']??''));
    $host=strtolower(rtrim((string)($parts['host']??''),'.'));
    if(!in_array($scheme,['http','https'],true)||$host===''||isset($parts['user'])||isset($parts['pass'])){
        throw new RuntimeException('Connected-site Origin is invalid.');
    }
    $registered=(string)($property['domain']??'');
    if(!vp3_radar_external_origin_allowed($registered,$host)){
        throw new RuntimeException('Connected-site Origin is not authorized.');
    }
    $port=(int)($parts['port']??0);
    if($port<0||$port>65535)throw new RuntimeException('Connected-site Origin is invalid.');
    return $scheme.'://'.$host.($port>0?(':'.$port):'');
}

function vp3_profile_webmcp_external_profile_v120(PDO $pdo,array $property): array
{
    $owner=(int)($property['owner_user_id']??0);
    if($owner<1)throw new RuntimeException('Connected-site owner is invalid.');
    $profile=profile_for_user($pdo,$owner,false);
    if(!$profile||empty($profile['is_active'])||empty($profile['is_public'])||empty($profile['username'])){
        throw new RuntimeException('The connected VP3 profile is not public.');
    }
    return $profile;
}

function vp3_profile_webmcp_external_agent_v120(PDO $pdo,array $profile): ?array
{
    $owner=(int)($profile['user_id']??0);
    $ownerUser=$owner>0?profile_user_row($pdo,$owner):null;
    if(!$ownerUser
       || !personal_capability_has_v242('profile_agent.access',$ownerUser)
       || !personal_capability_has_v242('profile_chat.access',$ownerUser)){
        return null;
    }
    $agent=profile_active_agent($pdo,$profile);
    if(!$agent)return null;
    return [
        'id'=>(int)$agent['id'],
        'name'=>(string)($agent['display_name']??''),
        'system_name'=>system_agent_name(),
        'greeting'=>trim((string)($profile['profile_agent_greeting']??'')),
    ];
}

function vp3_profile_webmcp_external_manifest_v120(PDO $pdo,array $property,array $profile,bool $statefulChat=false,bool $scheduling=false): array
{
    $capabilities=vp3_profile_webmcp_capabilities_v100($pdo,$profile,null);
    $chatEnabled=$statefulChat&&!empty($capabilities['profile_agent']);
    $schedulingEnabled=$scheduling&&!empty($capabilities['booking']);
    $catalog=vp3_profile_webmcp_tool_catalog_v100();
    $external=array_flip(vp3_profile_webmcp_external_tool_names_v120($chatEnabled,$schedulingEnabled));
    $allowed=[];
    foreach($catalog as $name=>$tool){
        if(!isset($external[$name]))continue;
        $capability=(string)($tool['capability']??'');
        if(($capabilities[$capability]??false)===true)$allowed[]=$name;
    }
    sort($allowed);
    return [
        'manifest_version'=>VP3_PROFILE_WEBMCP_MANIFEST_V100,
        'surface'=>'external_site',
        'property_id'=>(int)$property['id'],
        'property_domain'=>(string)$property['domain'],
        'profile_username'=>(string)$profile['username'],
        'capabilities'=>$capabilities,
        'allowed_tools'=>$allowed,
        'session'=>[
            'authenticated'=>false,
            'visitor_profile_known'=>false,
        ],
        'external'=>[
            'read_only'=>!$chatEnabled&&!$schedulingEnabled,
            'stateful_profile_agent'=>$chatEnabled,
            'transactional_actions'=>$schedulingEnabled,
            'scheduling_enabled'=>$schedulingEnabled,
            'chat_grant_required'=>$chatEnabled,
        ],
    ];
}

function vp3_profile_webmcp_external_enrich_site_state_v120(PDO $pdo,array $user,array $state): array
{
    if(empty($state['sites'])||!is_array($state['sites']))return $state;
    foreach($state['sites'] as &$site){
        $site['webmcp_enabled']=!empty($site['is_active']);
        $site['webmcp_manifest_version']=VP3_PROFILE_WEBMCP_MANIFEST_V100;
        $site['webmcp_read_only']=true;
        $site['webmcp_chat_enabled']=false;
        $site['webmcp_scheduling_enabled']=false;
        $site['webmcp_tool_count']=0;
        $site['webmcp_runtime_url']=url('/profile-webmcp-external-v120.js?v=profile-webmcp-external-v120-20260928');
        $site['webmcp_gateway_url']=url('/api/profile-webmcp-external-v120.php?key='.rawurlencode((string)($site['public_key']??'')));
        if(empty($site['is_active']))continue;
        try{
            $profile=vp3_profile_webmcp_external_profile_v120($pdo,$site+['owner_user_id'=>(int)$user['id']]);
            $manifest=vp3_profile_webmcp_external_manifest_v120($pdo,$site+['owner_user_id'=>(int)$user['id']],$profile,true,true);
            $site['webmcp_tool_count']=count($manifest['allowed_tools']);
            $site['webmcp_read_only']=!empty($manifest['external']['read_only']);
            $site['webmcp_chat_enabled']=!empty($manifest['external']['stateful_profile_agent']);
            $site['webmcp_scheduling_enabled']=!empty($manifest['external']['scheduling_enabled']);
        }catch(Throwable $e){
            $site['webmcp_enabled']=false;
        }
    }
    unset($site);
    return $state;
}
