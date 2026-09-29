<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_COMPATIBILITY_V203='profile-webmcp-compatibility-v203-20260929';
const VP3_PROFILE_WEBMCP_COMPATIBILITY_CONTRACT_V203='vp3.profile.webmcp.compatibility.v1';
const VP3_PROFILE_WEBMCP_TOOL_STATES_V203=['active','deprecated','sunset_pending','disabled'];

function vp3_profile_webmcp_introduced_version_v203(string $tool): string
{
    return match(true){
        str_starts_with($tool,'vp3.rewards.'),$tool==='vp3.reward.get',str_starts_with($tool,'vp3.loyalty.')=>'profile-webmcp-rewards-v180',
        str_starts_with($tool,'vp3.campaign')=>'profile-webmcp-campaigns-v170',
        str_starts_with($tool,'vp3.commerce.')=>'profile-webmcp-commerce-v160',
        str_starts_with($tool,'vp3.booking.')=>'profile-webmcp-scheduling-v150',
        str_starts_with($tool,'vp3.agent.')=>'profile-webmcp-agent-v110',
        default=>'profile-webmcp-v100',
    };
}

function vp3_profile_webmcp_compatibility_overrides_v203(): array
{
    // Future migrations are declared here. Existing production tools remain active.
    // A deprecated or sunset_pending tool remains routed through the canonical
    // dispatcher until its state is explicitly changed to disabled.
    return [];
}

function vp3_profile_webmcp_compatibility_registry_v203(?array $catalog=null,?array $overrides=null): array
{
    if($catalog===null)$catalog=function_exists('vp3_profile_webmcp_tool_catalog_v100')?vp3_profile_webmcp_tool_catalog_v100():[];
    if($overrides===null)$overrides=vp3_profile_webmcp_compatibility_overrides_v203();
    $registry=[];
    foreach($catalog as $name=>$tool){
        $name=(string)$name;
        if($name==='')continue;
        $override=is_array($overrides[$name]??null)?$overrides[$name]:[];
        $status=(string)($override['status']??'active');
        if(!in_array($status,VP3_PROFILE_WEBMCP_TOOL_STATES_V203,true))$status='disabled';
        $replacement=trim((string)($override['replacement_tool']??''));
        if($replacement!==''&&!isset($catalog[$replacement]))$replacement='';
        $sunset=trim((string)($override['sunset_at']??''));
        if($sunset!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$sunset))$sunset='';
        $registry[$name]=[
            'tool'=>$name,
            'status'=>$status,
            'introduced_version'=>(string)($override['introduced_version']??vp3_profile_webmcp_introduced_version_v203($name)),
            'minimum_manifest_version'=>(string)($override['minimum_manifest_version']??(defined('VP3_PROFILE_WEBMCP_MANIFEST_V100')?VP3_PROFILE_WEBMCP_MANIFEST_V100:'vp3.profile.webmcp.v1')),
            'minimum_release_version'=>(string)($override['minimum_release_version']??(defined('VP3_PROFILE_WEBMCP_RELEASE_V196')?VP3_PROFILE_WEBMCP_RELEASE_V196:'profile-webmcp-release-v196-20260929')),
            'minimum_negotiation_contract'=>(string)($override['minimum_negotiation_contract']??(defined('VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200')?VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200:'vp3.profile.webmcp.negotiation.v1')),
            'replacement_tool'=>$replacement,
            'sunset_at'=>$sunset,
            'execution_path'=>'canonical_router',
            'confirmation_policy'=>!empty($tool['annotations']['consequentialHint'])?'explicit_confirmation_and_idempotency':'canonical_tool_policy',
        ];
    }
    ksort($registry);
    return $registry;
}

function vp3_profile_webmcp_apply_compatibility_v203(array $tools,?array $registry=null): array
{
    $registry=$registry??vp3_profile_webmcp_compatibility_registry_v203();
    $out=[];
    foreach($tools as $tool){
        $tool=(string)$tool;
        $row=$registry[$tool]??null;
        if(!is_array($row)||($row['status']??'disabled')==='disabled')continue;
        $out[]=$tool;
    }
    return array_values(array_unique($out));
}

function vp3_profile_webmcp_tool_available_v203(string $tool): bool
{
    $row=vp3_profile_webmcp_compatibility_registry_v203()[$tool]??null;
    return is_array($row)&&($row['status']??'disabled')!=='disabled';
}

function vp3_profile_webmcp_compatibility_public_v203(array $allowedTools=[]): array
{
    $registry=vp3_profile_webmcp_compatibility_registry_v203();
    $allowed=array_fill_keys(array_map('strval',$allowedTools),true);
    $states=['active'=>0,'deprecated'=>0,'sunset_pending'=>0,'disabled'=>0];
    $tools=[];
    foreach($registry as $name=>$row){
        $status=(string)$row['status'];$states[$status]=($states[$status]??0)+1;
        if($allowedTools!==[]&&!isset($allowed[$name]))continue;
        $tools[$name]=$row;
    }
    return [
        'contract'=>VP3_PROFILE_WEBMCP_COMPATIBILITY_CONTRACT_V203,
        'version'=>VP3_PROFILE_WEBMCP_COMPATIBILITY_V203,
        'states'=>$states,
        'tools'=>$tools,
        'deprecated_tools'=>array_values(array_keys(array_filter($tools,static fn(array $row):bool=>in_array($row['status'],['deprecated','sunset_pending'],true)))),
        'disabled_tools'=>array_values(array_keys(array_filter($registry,static fn(array $row):bool=>$row['status']==='disabled'))),
        'deprecated_execution_path'=>'canonical_router',
        'disabled_execution_allowed'=>false,
    ];
}
