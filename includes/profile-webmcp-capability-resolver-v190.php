<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_CAPABILITY_RESOLVER_V190='profile-webmcp-capability-resolver-v190-20260929';

function vp3_profile_webmcp_capability_registry_v190(): array
{
    return [
        'profile'=>['scope'=>'public','surfaces'=>['native_profile','external_site','agent_brain']],
        'profile_agent'=>['scope'=>'public_session','surfaces'=>['native_profile','external_site','agent_brain']],
        'booking'=>['scope'=>'public_transaction','surfaces'=>['native_profile','external_site','agent_brain']],
        'commerce'=>['scope'=>'public_transaction','surfaces'=>['native_profile','external_site','agent_brain']],
        'campaigns'=>['scope'=>'public_transaction','surfaces'=>['native_profile','external_site','agent_brain']],
        'rewards'=>['scope'=>'authenticated_viewer','surfaces'=>['native_profile','agent_brain']],
        'social'=>['scope'=>'authenticated_viewer','surfaces'=>['native_profile','agent_brain']],
        'messaging'=>['scope'=>'authenticated_viewer','surfaces'=>['native_profile','agent_brain']],
    ];
}

function vp3_profile_webmcp_external_tool_policy_v190(): array
{
    return [
        'always'=>[
            'vp3.profile.capabilities.get','vp3.profile.get','vp3.intent.resolve','vp3.agent.get',
        ],
        'profile_agent'=>[
            'vp3.agent.chat.start','vp3.agent.conversation.get','vp3.agent.message.send','vp3.agent.owner_handoff.request',
        ],
        'booking'=>[
            'vp3.booking.options.list','vp3.booking.availability.list','vp3.booking.prepare','vp3.booking.confirm',
            'vp3.booking.get','vp3.booking.reschedule.prepare','vp3.booking.reschedule.confirm',
            'vp3.booking.cancel.prepare','vp3.booking.cancel.confirm',
        ],
        'commerce'=>[
            'vp3.commerce.products.list','vp3.commerce.product.get','vp3.commerce.checkout.prepare','vp3.commerce.checkout.confirm',
            'vp3.commerce.order.get','vp3.commerce.receipt.get','vp3.commerce.delivery.get','vp3.commerce.refund.status',
            'vp3.commerce.refund.prepare','vp3.commerce.refund.confirm',
        ],
        'campaigns'=>[
            'vp3.campaigns.list','vp3.campaign.get','vp3.campaign.eligibility.get',
            'vp3.campaign.participation.prepare','vp3.campaign.participation.confirm','vp3.campaign.participation.get',
        ],
    ];
}

function vp3_profile_webmcp_detect_capabilities_v190(PDO $pdo,array $profile,?array $viewer): array
{
    $ownerUserId=(int)($profile['user_id']??0);
    $viewerId=(int)($viewer['id']??0);
    $ownerUser=function_exists('vp3_profile_webmcp_owner_user_v100')
        ?vp3_profile_webmcp_owner_user_v100($pdo,$profile)
        :(function_exists('profile_user_row')?profile_user_row($pdo,$ownerUserId):null);

    $profileAgent=false;
    try{
        $agent=function_exists('profile_active_agent')?profile_active_agent($pdo,$profile):null;
        $entitled=!$ownerUser||!function_exists('personal_capability_has_v242')
            ?true
            :(personal_capability_has_v242('profile_agent.access',$ownerUser)&&personal_capability_has_v242('profile_chat.access',$ownerUser));
        $profileAgent=(bool)$agent&&$entitled&&($viewerId<1||$viewerId!==$ownerUserId);
    }catch(Throwable $e){$profileAgent=false;}

    $booking=false;
    try{
        if(function_exists('agent_scheduling_public_schedule_v450')&&function_exists('agent_scheduling_public_events_v450')){
            $schedule=agent_scheduling_public_schedule_v450($pdo,$ownerUserId);
            $booking=is_array($schedule)&&count(agent_scheduling_public_events_v450($pdo,(int)$schedule['id']))>0;
        }
    }catch(Throwable $e){$booking=false;}

    $commerce=false;
    try{
        if(function_exists('profile_commerce_products_for_profile_v900')){
            $commerce=count(profile_commerce_products_for_profile_v900($pdo,$profile,true,1))>0;
        }
    }catch(Throwable $e){$commerce=false;}

    $campaigns=false;
    try{
        if(function_exists('campaigns_rewards_schema_ready_v100')&&campaigns_rewards_schema_ready_v100($pdo)
            &&function_exists('campaigns_rewards_profile_campaigns_v100')){
            $campaigns=count(campaigns_rewards_profile_campaigns_v100($pdo,$ownerUserId,1))>0;
        }
    }catch(Throwable $e){$campaigns=false;}

    $rewards=$viewerId>0&&function_exists('campaigns_rewards_reward_tray_v110');
    $social=false;$messaging=false;
    if($viewerId>0&&$viewerId!==$ownerUserId&&function_exists('vp3_social_schema_ready_v320')){
        try{
            $social=vp3_social_schema_ready_v320($pdo);
            if($social&&function_exists('vp3_social_dm_route_v320')){
                $messaging=vp3_social_dm_route_v320($pdo,$viewerId,$ownerUserId)!=='blocked';
            }
        }catch(Throwable $e){$social=false;$messaging=false;}
    }

    return [
        'profile'=>true,
        'profile_agent'=>$profileAgent,
        'booking'=>$booking,
        'commerce'=>$commerce,
        'campaigns'=>$campaigns,
        'rewards'=>$rewards,
        'social'=>$social,
        'messaging'=>$messaging,
    ];
}

function vp3_profile_webmcp_surface_candidate_tools_v190(string $surface,array $capabilities,array $context=[]): ?array
{
    if($surface==='native_profile'||$surface==='agent_brain')return null;
    if($surface!=='external_site')throw new RuntimeException('Unsupported Profile WebMCP surface.');

    $policy=vp3_profile_webmcp_external_tool_policy_v190();
    $allowed=$policy['always'];
    $features=is_array($context['features']??null)?$context['features']:[];
    $featureMap=[
        'profile_agent'=>'stateful_chat',
        'booking'=>'scheduling',
        'commerce'=>'commerce',
        'campaigns'=>'campaigns',
    ];
    foreach($featureMap as $capability=>$flag){
        if(!empty($features[$flag])&&!empty($capabilities[$capability])){
            foreach($policy[$capability]??[] as $tool)$allowed[]=$tool;
        }
    }
    return array_values(array_unique($allowed));
}

function vp3_profile_webmcp_resolve_capabilities_v190(
    PDO $pdo,array $profile,?array $viewer,array $context=[]
): array {
    $surface=(string)($context['surface']??'native_profile');
    $registry=vp3_profile_webmcp_capability_registry_v190();
    $provided=is_array($context['capabilities']??null)?$context['capabilities']:null;
    $capabilities=$provided??vp3_profile_webmcp_detect_capabilities_v190($pdo,$profile,$viewer);
    foreach($capabilities as $key=>$enabled){
        if(!isset($registry[$key])||!in_array($surface,$registry[$key]['surfaces']??[],true))$capabilities[$key]=false;
    }

    $catalog=function_exists('vp3_profile_webmcp_tool_catalog_v100')?vp3_profile_webmcp_tool_catalog_v100():[];
    $candidate=vp3_profile_webmcp_surface_candidate_tools_v190($surface,$capabilities,$context);
    $candidateSet=$candidate===null?null:array_flip($candidate);
    $allowed=[];
    foreach($catalog as $name=>$tool){
        if($candidateSet!==null&&!isset($candidateSet[$name]))continue;
        $capability=(string)($tool['capability']??'');
        if(empty($capabilities[$capability]))continue;
        if(function_exists('vp3_profile_webmcp_tool_runtime_ready_v150')&&!vp3_profile_webmcp_tool_runtime_ready_v150($pdo,$name))continue;
        $allowed[]=$name;
    }
    sort($allowed);

    $authenticated=(int)($viewer['id']??0)>0;
    $identityDisclosed=false;
    if($authenticated&&function_exists('profile_visitor_discloses_identity')){
        try{$identityDisclosed=profile_visitor_discloses_identity($pdo,$viewer);}catch(Throwable $e){$identityDisclosed=false;}
    }

    $enabledCapabilities=[];
    foreach($capabilities as $key=>$enabled)if($enabled)$enabledCapabilities[]=$key;
    sort($enabledCapabilities);

    return [
        'resolver_version'=>VP3_PROFILE_WEBMCP_CAPABILITY_RESOLVER_V190,
        'surface'=>$surface,
        'capabilities'=>$capabilities,
        'enabled_capabilities'=>$enabledCapabilities,
        'allowed_tools'=>$allowed,
        'session'=>[
            'authenticated'=>$authenticated,
            'visitor_profile_known'=>$identityDisclosed,
        ],
        'execution_allowed'=>$surface!=='agent_brain',
    ];
}

function vp3_profile_webmcp_agent_capability_context_v190(PDO $pdo,array $profile,?array $viewer): array
{
    $resolution=vp3_profile_webmcp_resolve_capabilities_v190($pdo,$profile,$viewer,['surface'=>'agent_brain']);
    $publicDomains=array_values(array_filter(
        $resolution['enabled_capabilities'],
        static fn(string $c):bool=>in_array($c,['profile','profile_agent','booking','commerce','campaigns'],true)
    ));
    return [
        'source'=>'profile:webmcp-capabilities',
        'title'=>'Available Profile capabilities',
        'text'=>'Available domains: '.($publicDomains?implode(', ',$publicDomains):'profile').'.',
        'capabilities'=>$publicDomains,
        'resolver_version'=>$resolution['resolver_version'],
        'execution_allowed'=>false,
    ];
}
