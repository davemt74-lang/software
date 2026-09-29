<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_COMPAT_V203='profile-webmcp-compat-v203-20260929';
const VP3_PROFILE_WEBMCP_COMPAT_CONTRACT_V203='vp3.profile.webmcp.compatibility.v1';
const VP3_PROFILE_WEBMCP_TOOL_STATES_V203=['active','deprecated','sunset_pending','disabled'];

function vp3_profile_webmcp_tool_registry_v203(array $catalog): array
{
    $overrides=[
        // Explicit lifecycle overrides live here. Unlisted tools remain active.
    ];
    $out=[];
    foreach($catalog as $name=>$tool){
        $row=is_array($overrides[$name]??null)?$overrides[$name]:[];
        $state=(string)($row['state']??'active');
        if(!in_array($state,VP3_PROFILE_WEBMCP_TOOL_STATES_V203,true))$state='disabled';
        $out[$name]=[
            'tool'=>$name,
            'state'=>$state,
            'introduced_release'=>(string)($row['introduced_release']??VP3_PROFILE_WEBMCP_RELEASE_V196),
            'replacement_tool'=>(string)($row['replacement_tool']??''),
            'sunset_at'=>(string)($row['sunset_at']??''),
            'minimum_runtime_build'=>(string)($row['minimum_runtime_build']??''),
        ];
    }
    return $out;
}

function vp3_profile_webmcp_tool_lifecycle_v203(string $tool,array $catalog): array
{
    $registry=vp3_profile_webmcp_tool_registry_v203($catalog);
    return $registry[$tool]??[
        'tool'=>$tool,'state'=>'disabled','introduced_release'=>'','replacement_tool'=>'','sunset_at'=>'','minimum_runtime_build'=>''
    ];
}

function vp3_profile_webmcp_tool_available_v203(string $tool,array $catalog,?int $now=null): bool
{
    $row=vp3_profile_webmcp_tool_lifecycle_v203($tool,$catalog);
    if(($row['state']??'disabled')==='disabled')return false;
    $sunset=trim((string)($row['sunset_at']??''));
    if($sunset!==''){
        $ts=strtotime($sunset);
        if($ts!==false&&$ts<=($now??time()))return false;
    }
    return isset($catalog[$tool]);
}

function vp3_profile_webmcp_tool_lifecycle_public_v203(array $row): array
{
    return [
        'contract'=>VP3_PROFILE_WEBMCP_COMPAT_CONTRACT_V203,
        'version'=>VP3_PROFILE_WEBMCP_COMPAT_V203,
        'tool'=>(string)($row['tool']??''),
        'state'=>(string)($row['state']??'disabled'),
        'introduced_release'=>(string)($row['introduced_release']??''),
        'replacement_tool'=>(string)($row['replacement_tool']??''),
        'sunset_at'=>(string)($row['sunset_at']??''),
        'minimum_runtime_build'=>(string)($row['minimum_runtime_build']??''),
    ];
}

function vp3_profile_webmcp_tool_registry_summary_v203(array $catalog): array
{
    $counts=['active'=>0,'deprecated'=>0,'sunset_pending'=>0,'disabled'=>0];
    $tools=[];
    foreach(vp3_profile_webmcp_tool_registry_v203($catalog) as $row){
        $state=(string)($row['state']??'disabled');
        if(isset($counts[$state]))$counts[$state]++;
        $tools[]=vp3_profile_webmcp_tool_lifecycle_public_v203($row);
    }
    return [
        'contract'=>VP3_PROFILE_WEBMCP_COMPAT_CONTRACT_V203,
        'version'=>VP3_PROFILE_WEBMCP_COMPAT_V203,
        'counts'=>$counts,
        'tools'=>$tools,
    ];
}
