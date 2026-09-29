<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_NEGOTIATION_V200='profile-webmcp-negotiation-v200-20260929';
const VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200='vp3.profile.webmcp.negotiation.v1';
const VP3_PROFILE_WEBMCP_NATIVE_RUNTIME_V200='profile-webmcp-runtime-v100-20260928';
const VP3_PROFILE_WEBMCP_EXTERNAL_RUNTIME_V200='profile-webmcp-external-v120-20260928';

function vp3_profile_webmcp_protocol_descriptor_v200(string $surface): array
{
    if(!in_array($surface,['native_profile','external_site','agent_brain'],true)){
        throw new RuntimeException('Unsupported Profile WebMCP negotiation surface.');
    }
    $runtime=match($surface){
        'native_profile'=>VP3_PROFILE_WEBMCP_NATIVE_RUNTIME_V200,
        'external_site'=>VP3_PROFILE_WEBMCP_EXTERNAL_RUNTIME_V200,
        default=>'agent-brain-server',
    };
    return [
        'contract'=>VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200,
        'version'=>VP3_PROFILE_WEBMCP_NEGOTIATION_V200,
        'surface'=>$surface,
        'supported_manifest_versions'=>[VP3_PROFILE_WEBMCP_MANIFEST_V100],
        'supported_release_versions'=>[VP3_PROFILE_WEBMCP_RELEASE_V196],
        'current_runtime_build'=>$runtime,
        'consequential_protection'=>'explicit_confirmation_and_idempotency_required',
        'downgrade_consequential_protection'=>false,
        'legacy_v1_compatible'=>true,
    ];
}

function vp3_profile_webmcp_version_list_v200(mixed $value): array
{
    if(is_string($value))$value=preg_split('/[\s,]+/',trim($value))?:[];
    if(!is_array($value))return [];
    $out=[];
    foreach($value as $item){
        $item=trim((string)$item);
        if($item===''||strlen($item)>160)continue;
        $out[$item]=true;
        if(count($out)>=12)break;
    }
    return array_keys($out);
}

function vp3_profile_webmcp_client_versions_v200(array $input): array
{
    $raw=is_array($input['client_versions']??null)?$input['client_versions']:[];
    return [
        'manifest_versions'=>vp3_profile_webmcp_version_list_v200($raw['manifest_versions']??[]),
        'release_versions'=>vp3_profile_webmcp_version_list_v200($raw['release_versions']??[]),
        'runtime_build'=>mb_strimwidth(trim((string)($raw['runtime_build']??'')),0,160,''),
        'negotiation_contract'=>mb_strimwidth(trim((string)($raw['negotiation_contract']??'')),0,160,''),
    ];
}

function vp3_profile_webmcp_negotiate_v200(string $surface,array $client=[]): array
{
    $server=vp3_profile_webmcp_protocol_descriptor_v200($surface);
    $manifests=vp3_profile_webmcp_version_list_v200($client['manifest_versions']??[]);
    $releases=vp3_profile_webmcp_version_list_v200($client['release_versions']??[]);
    $runtime=trim((string)($client['runtime_build']??''));
    $contract=trim((string)($client['negotiation_contract']??''));

    $explicit=$manifests!==[]||$releases!==[]||$runtime!==''||$contract!=='';
    if(!$explicit){
        return [
            'compatible'=>true,
            'contract'=>VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200,
            'mode'=>'legacy_v1',
            'surface'=>$surface,
            'manifest_version'=>VP3_PROFILE_WEBMCP_MANIFEST_V100,
            'release_version'=>VP3_PROFILE_WEBMCP_RELEASE_V196,
            'runtime_build'=>(string)$server['current_runtime_build'],
            'consequential_protection'=>(string)$server['consequential_protection'],
            'downgrade_applied'=>false,
            'reason'=>'legacy_v1_compatible',
        ];
    }

    if($contract!==''&&!hash_equals(VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200,$contract)){
        return vp3_profile_webmcp_incompatible_v200($surface,$server,'negotiation_contract_unsupported');
    }
    if($manifests!==[]&&!in_array(VP3_PROFILE_WEBMCP_MANIFEST_V100,$manifests,true)){
        return vp3_profile_webmcp_incompatible_v200($surface,$server,'manifest_version_unsupported');
    }
    if($releases!==[]&&!in_array(VP3_PROFILE_WEBMCP_RELEASE_V196,$releases,true)){
        return vp3_profile_webmcp_incompatible_v200($surface,$server,'release_version_unsupported');
    }
    if($runtime!==''&&!hash_equals((string)$server['current_runtime_build'],$runtime)){
        return vp3_profile_webmcp_incompatible_v200($surface,$server,'runtime_build_unsupported');
    }

    return [
        'compatible'=>true,
        'contract'=>VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200,
        'mode'=>'negotiated',
        'surface'=>$surface,
        'manifest_version'=>VP3_PROFILE_WEBMCP_MANIFEST_V100,
        'release_version'=>VP3_PROFILE_WEBMCP_RELEASE_V196,
        'runtime_build'=>(string)$server['current_runtime_build'],
        'consequential_protection'=>(string)$server['consequential_protection'],
        'downgrade_applied'=>false,
        'reason'=>'exact_compatible',
    ];
}

function vp3_profile_webmcp_incompatible_v200(string $surface,array $server,string $reason): array
{
    return [
        'compatible'=>false,
        'contract'=>VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200,
        'mode'=>'incompatible',
        'surface'=>$surface,
        'manifest_version'=>'',
        'release_version'=>'',
        'runtime_build'=>'',
        'consequential_protection'=>(string)$server['consequential_protection'],
        'downgrade_applied'=>false,
        'reason'=>$reason,
        'server'=>[
            'manifest_versions'=>$server['supported_manifest_versions'],
            'release_versions'=>$server['supported_release_versions'],
            'runtime_build'=>$server['current_runtime_build'],
        ],
    ];
}

function vp3_profile_webmcp_negotiation_error_v200(array $negotiation): array
{
    return [
        'error'=>[
            'code'=>'WEBMCP_VERSION_INCOMPATIBLE',
            'message'=>'This WebMCP runtime is not compatible with the current Profile WebMCP release.',
            'retryable'=>false,
        ],
        'negotiation'=>$negotiation,
    ];
}
