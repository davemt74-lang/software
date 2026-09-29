<?php
declare(strict_types=1);

require_once __DIR__.'/profile-webmcp-v100.php';

const VP3_AGENT_PROFILE_WEBMCP_V193='agent-profile-webmcp-v193-20260929';

function vp3_agent_profile_webmcp_intent_v193(string $query): bool
{
    $query=mb_strtolower(trim($query));
    if($query==='')return false;
    $profileSpecific=(bool)preg_match('/\b(?:my\s+(?:public\s+)?profile|public\s+profile|profile\s+agent|profile\s+webmcp|webmcp|on\s+my\s+profile|through\s+my\s+profile|via\s+my\s+profile)\b/i',$query);
    if(!$profileSpecific)return false;
    return (bool)preg_match('/\b(?:what|which|can|could|show|list|use|available|capabilit|book|booking|schedule|buy|purchase|checkout|campaign|offer|reward|loyalty|message|contact|agent|chat|profile|webmcp)\b/i',$query);
}

function vp3_agent_profile_webmcp_plan_v193(PDO $pdo,string $query,array $user): ?array
{
    $userId=(int)($user['id']??0);
    if($userId<1||!vp3_agent_profile_webmcp_intent_v193($query))return null;
    if(!function_exists('profile_for_user'))return null;

    $profile=profile_for_user($pdo,$userId,false);
    if(!$profile||empty($profile['username']))return [
        'handled'=>true,
        'answer'=>'Your VP3 Profile is not configured yet, so Profile WebMCP has no public surface to plan against.',
        'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],
        'profile_webmcp_plan'=>[
            'version'=>VP3_AGENT_PROFILE_WEBMCP_V193,
            'profile_user_id'=>$userId,
            'profile_username'=>'',
            'execution_allowed'=>false,
            'status'=>'profile_not_configured',
            'recommended_capabilities'=>[],
            'recommended_tools'=>[],
        ],
    ];

    if(empty($profile['is_public'])||empty($profile['is_active'])){
        return [
            'handled'=>true,
            'answer'=>'Your VP3 Profile is not currently public and active, so Profile WebMCP execution is unavailable.',
            'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],
            'profile_webmcp_plan'=>[
                'version'=>VP3_AGENT_PROFILE_WEBMCP_V193,
                'profile_user_id'=>$userId,
                'profile_username'=>(string)$profile['username'],
                'execution_allowed'=>false,
                'status'=>'profile_unavailable',
                'recommended_capabilities'=>[],
                'recommended_tools'=>[],
            ],
        ];
    }

    $resolution=vp3_profile_webmcp_resolve_capabilities_v190($pdo,$profile,$user,['surface'=>'agent_brain']);
    $manifest=[
        'manifest_version'=>VP3_PROFILE_WEBMCP_MANIFEST_V100,
        'surface'=>'agent_brain',
        'profile_username'=>(string)$profile['username'],
        'capabilities'=>$resolution['capabilities'],
        'allowed_tools'=>$resolution['allowed_tools'],
    ];
    $intent=vp3_profile_webmcp_resolve_intent_v100($query,$manifest);
    $catalog=vp3_profile_webmcp_tool_catalog_v100();
    $capSet=array_fill_keys($intent['recommended_capabilities']??[],true);
    $recommended=[];
    foreach($resolution['allowed_tools'] as $toolName){
        $tool=$catalog[$toolName]??null;
        if(!is_array($tool))continue;
        $capability=(string)($tool['capability']??'');
        if(!isset($capSet[$capability]))continue;
        $recommended[]=[
            'name'=>$toolName,
            'capability'=>$capability,
            'read_only'=>!empty($tool['annotations']['readOnlyHint']),
            'consequential'=>!empty($tool['annotations']['consequentialHint']),
        ];
    }

    $domains=array_values(array_unique(array_map(
        static fn(array $row):string=>(string)$row['capability'],
        $recommended
    )));
    $domainText=$domains?implode(', ',$domains):'profile';
    $profileUrl=profile_public_url((string)$profile['username']);

    return [
        'handled'=>true,
        'answer'=>'Your public Profile can currently handle '.$domainText.' through Profile WebMCP. I can plan the next step here, but execution stays on the signed Profile surface so confirmations and idempotency remain governed.',
        'stem_media'=>[],'media'=>[],
        'actions'=>[[
            'type'=>'open_url',
            'label'=>'Open my public Profile',
            'url'=>$profileUrl,
        ]],
        'sources'=>[[
            'source'=>'profile:webmcp-capabilities',
            'title'=>'Profile WebMCP capability plan',
            'url'=>$profileUrl,
        ]],
        'profile_webmcp_plan'=>[
            'version'=>VP3_AGENT_PROFILE_WEBMCP_V193,
            'resolver_version'=>(string)$resolution['resolver_version'],
            'profile_user_id'=>$userId,
            'profile_username'=>(string)$profile['username'],
            'public_url'=>$profileUrl,
            'surface'=>'agent_brain',
            'status'=>'ready',
            'execution_allowed'=>false,
            'recommended_capabilities'=>array_values($intent['recommended_capabilities']??[]),
            'recommended_tools'=>$recommended,
            'handoff'=>[
                'surface'=>'native_profile',
                'url'=>$profileUrl,
                'requires_signed_profile_surface'=>true,
            ],
        ],
    ];
}

function vp3_agent_profile_webmcp_authorize_plan_v193(PDO $pdo,array $plan,array $user): ?array
{
    $userId=(int)($user['id']??0);
    if($userId<1||(int)($plan['profile_user_id']??0)!==$userId)return null;
    if(!function_exists('profile_for_user'))return null;
    $profile=profile_for_user($pdo,$userId,false);
    if(!$profile||empty($profile['username'])||empty($profile['is_public'])||empty($profile['is_active']))return null;
    if(!hash_equals((string)$profile['username'],(string)($plan['profile_username']??'')))return null;

    $resolution=vp3_profile_webmcp_resolve_capabilities_v190($pdo,$profile,$user,['surface'=>'agent_brain']);
    $allowed=array_fill_keys($resolution['allowed_tools'],true);
    $catalog=vp3_profile_webmcp_tool_catalog_v100();
    $tools=[];
    foreach((array)($plan['recommended_tools']??[]) as $row){
        if(!is_array($row))continue;
        $name=(string)($row['name']??'');
        if($name===''||!isset($allowed[$name])||!isset($catalog[$name]))continue;
        $tool=$catalog[$name];
        $tools[]=[
            'name'=>$name,
            'capability'=>(string)($tool['capability']??''),
            'read_only'=>!empty($tool['annotations']['readOnlyHint']),
            'consequential'=>!empty($tool['annotations']['consequentialHint']),
        ];
    }

    $capabilities=[];
    foreach((array)($plan['recommended_capabilities']??[]) as $capability){
        $capability=(string)$capability;
        if($capability!==''&&!empty($resolution['capabilities'][$capability]))$capabilities[$capability]=true;
    }
    $url=profile_public_url((string)$profile['username']);

    return [
        'version'=>VP3_AGENT_PROFILE_WEBMCP_V193,
        'resolver_version'=>(string)$resolution['resolver_version'],
        'profile_user_id'=>$userId,
        'profile_username'=>(string)$profile['username'],
        'public_url'=>$url,
        'surface'=>'agent_brain',
        'status'=>'ready',
        'execution_allowed'=>false,
        'recommended_capabilities'=>array_values(array_keys($capabilities)),
        'recommended_tools'=>$tools,
        'handoff'=>[
            'surface'=>'native_profile',
            'url'=>$url,
            'requires_signed_profile_surface'=>true,
        ],
    ];
}
