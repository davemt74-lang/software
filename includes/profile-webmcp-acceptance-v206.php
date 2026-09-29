<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_ACCEPTANCE_V206='profile-webmcp-acceptance-v206-20260929';
const VP3_PROFILE_WEBMCP_ACCEPTANCE_CONTRACT_V206='vp3.profile.webmcp.acceptance.v1';

function vp3_profile_webmcp_acceptance_check_v206(string $id,bool $ok,string $detail=''): array
{
    return [
        'id'=>$id,
        'ok'=>$ok,
        'status'=>$ok?'pass':'fail',
        'detail'=>mb_strimwidth(trim($detail),0,240,''),
    ];
}

function vp3_profile_webmcp_acceptance_v206(): array
{
    $checks=[];

    $release=function_exists('vp3_profile_webmcp_release_audit_v196')
        ?vp3_profile_webmcp_release_audit_v196()
        :['ok'=>false,'errors'=>['release_audit_unavailable']];
    $checks[]=vp3_profile_webmcp_acceptance_check_v206(
        'release_catalog',
        !empty($release['ok']),
        implode(',',array_map('strval',(array)($release['errors']??[])))
    );

    $descriptor=function_exists('vp3_profile_webmcp_release_descriptor_v196')
        ?vp3_profile_webmcp_release_descriptor_v196()
        :[];
    $native=(array)($descriptor['surfaces']['native_profile']??[]);
    $external=(array)($descriptor['surfaces']['external_site']??[]);
    $agent=(array)($descriptor['surfaces']['agent_brain']??[]);
    $checks[]=vp3_profile_webmcp_acceptance_check_v206(
        'surface_authority',
        !empty($native['execution_allowed'])
            &&!empty($external['execution_allowed'])
            &&empty($agent['execution_allowed'])
            &&($agent['transaction_authority']??'')==='none'
    );

    $nativeNegotiation=function_exists('vp3_profile_webmcp_negotiate_v200')
        ?vp3_profile_webmcp_negotiate_v200('native_profile',[
            'manifest_versions'=>[VP3_PROFILE_WEBMCP_MANIFEST_V100],
            'release_versions'=>[VP3_PROFILE_WEBMCP_RELEASE_V196],
            'runtime_build'=>VP3_PROFILE_WEBMCP_NATIVE_RUNTIME_V200,
            'negotiation_contract'=>VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200,
        ])
        :['compatible'=>false];
    $externalNegotiation=function_exists('vp3_profile_webmcp_negotiate_v200')
        ?vp3_profile_webmcp_negotiate_v200('external_site',[
            'manifest_versions'=>[VP3_PROFILE_WEBMCP_MANIFEST_V100],
            'release_versions'=>[VP3_PROFILE_WEBMCP_RELEASE_V196],
            'runtime_build'=>VP3_PROFILE_WEBMCP_EXTERNAL_RUNTIME_V200,
            'negotiation_contract'=>VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200,
        ])
        :['compatible'=>false];
    $badNegotiation=function_exists('vp3_profile_webmcp_negotiate_v200')
        ?vp3_profile_webmcp_negotiate_v200('native_profile',[
            'manifest_versions'=>['vp3.profile.webmcp.v999'],
        ])
        :['compatible'=>true];
    $checks[]=vp3_profile_webmcp_acceptance_check_v206(
        'version_negotiation',
        !empty($nativeNegotiation['compatible'])
            &&!empty($externalNegotiation['compatible'])
            &&empty($badNegotiation['compatible'])
            &&empty($nativeNegotiation['downgrade_applied'])
            &&empty($externalNegotiation['downgrade_applied'])
    );

    $catalog=function_exists('vp3_profile_webmcp_tool_catalog_v100')
        ?vp3_profile_webmcp_tool_catalog_v100()
        :[];
    $compat=function_exists('vp3_profile_webmcp_compatibility_registry_v203')
        ?vp3_profile_webmcp_compatibility_registry_v203($catalog)
        :[];
    $lifecycleOk=$catalog!==[]&&count($compat)===count($catalog);
    foreach($compat as $name=>$row){
        if(($row['status']??'')==='disabled'
            && function_exists('vp3_profile_webmcp_tool_available_v203')
            && vp3_profile_webmcp_tool_available_v203((string)$name,'')){
            $lifecycleOk=false;
            break;
        }
    }
    $checks[]=vp3_profile_webmcp_acceptance_check_v206('tool_lifecycle',$lifecycleOk);

    $checks[]=vp3_profile_webmcp_acceptance_check_v206(
        'continuity',
        function_exists('vp3_profile_webmcp_resume_issue_v194')
            &&function_exists('vp3_profile_webmcp_resume_consume_v194')
            &&function_exists('vp3_profile_webmcp_return_consume_v195')
            &&(($descriptor['continuity']['return_single_use']??false)===true)
            &&(($descriptor['continuity']['sensitive_payload_return']??true)===false)
    );

    $checks[]=vp3_profile_webmcp_acceptance_check_v206(
        'connected_sites',
        function_exists('vp3_profile_webmcp_sites_admin_v204')
            &&function_exists('vp3_profile_webmcp_site_status_v204')
            &&(($descriptor['connected_sites']['origin_mismatch_payload_retained']??true)===false)
    );

    $checks[]=vp3_profile_webmcp_acceptance_check_v206(
        'observability',
        function_exists('vp3_profile_webmcp_observability_rows_v205')
            &&function_exists('vp3_profile_webmcp_latency_summary_v205')
            &&(($descriptor['observability']['cross_surface_correlation']??false)===true)
            &&(($descriptor['observability']['sensitive_input_logging']??true)===false)
    );

    $checks[]=vp3_profile_webmcp_acceptance_check_v206(
        'health_admin',
        function_exists('vp3_profile_webmcp_health_v201')
            &&function_exists('vp3_profile_webmcp_admin_overview_v202')
    );

    $failed=array_values(array_filter($checks,static fn(array $row): bool=>empty($row['ok'])));
    return [
        'contract'=>VP3_PROFILE_WEBMCP_ACCEPTANCE_CONTRACT_V206,
        'version'=>VP3_PROFILE_WEBMCP_ACCEPTANCE_V206,
        'status'=>$failed===[]?'ready':'blocked',
        'ready'=>$failed===[],
        'score_total'=>count($checks),
        'score_passed'=>count($checks)-count($failed),
        'checks'=>$checks,
        'failed_checks'=>array_values(array_map(static fn(array $row): string=>(string)$row['id'],$failed)),
        'contains_sensitive_payload'=>false,
    ];
}
