<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_RELEASE_V196='profile-webmcp-release-v196-20260929';
const VP3_PROFILE_WEBMCP_RELEASE_CONTRACT_V196='vp3.profile.webmcp.release.v1';

function vp3_profile_webmcp_release_descriptor_v196(): array
{
    return [
        'contract'=>VP3_PROFILE_WEBMCP_RELEASE_CONTRACT_V196,
        'version'=>VP3_PROFILE_WEBMCP_RELEASE_V196,
        'surfaces'=>[
            'native_profile'=>[
                'execution_allowed'=>true,
                'transaction_authority'=>'profile_webmcp_router',
                'confirmation_authority'=>'profile_webmcp_confirmation_ledger',
            ],
            'external_site'=>[
                'execution_allowed'=>true,
                'transaction_authority'=>'profile_webmcp_router',
                'confirmation_authority'=>'profile_webmcp_confirmation_ledger',
            ],
            'agent_brain'=>[
                'execution_allowed'=>false,
                'transaction_authority'=>'none',
                'confirmation_authority'=>'none',
            ],
        ],
        'compatibility'=>[
            'contract'=>'vp3.profile.webmcp.compatibility.v1',
            'deprecated_execution_path'=>'canonical_router',
            'disabled_execution_allowed'=>false,
        ],
        'negotiation'=>[
            'contract'=>'vp3.profile.webmcp.negotiation.v1',
            'required_for_future_major_versions'=>true,
            'consequential_downgrade_allowed'=>false,
        ],
        'continuity'=>[
            'resume_contract'=>'vp3.webmcp.resume.v1',
            'return_contract'=>'vp3.webmcp.return.v1',
            'return_single_use'=>true,
            'sensitive_payload_return'=>false,
        ],
    ];
}

function vp3_profile_webmcp_release_surface_v196(string $surface): array
{
    $descriptor=vp3_profile_webmcp_release_descriptor_v196();
    $row=$descriptor['surfaces'][$surface]??null;
    if(!is_array($row))throw new RuntimeException('Unsupported Profile WebMCP release surface.');
    return $row;
}

function vp3_profile_webmcp_release_catalog_audit_v196(array $catalog,array $registry,array $externalPolicy): array
{
    $errors=[];
    $toolNames=array_keys($catalog);
    if(count($toolNames)!==count(array_unique($toolNames)))$errors[]='duplicate_tool_name';

    foreach($catalog as $name=>$tool){
        $capability=(string)($tool['capability']??'');
        if($capability===''||!isset($registry[$capability]))$errors[]='unknown_capability:'.$name;
        $annotations=is_array($tool['annotations']??null)?$tool['annotations']:[];
        if(!array_key_exists('readOnlyHint',$annotations)||!array_key_exists('consequentialHint',$annotations)){
            $errors[]='missing_annotations:'.$name;
        }
        $schema=is_array($tool['input_schema']??null)?$tool['input_schema']:[];
        if(($schema['type']??'')!=='object')$errors[]='invalid_input_schema:'.$name;
    }

    $external=[];
    foreach($externalPolicy as $group=>$names){
        if(!is_array($names)){$errors[]='invalid_external_policy_group:'.$group;continue;}
        foreach($names as $name){
            $name=(string)$name;
            if(!isset($catalog[$name]))$errors[]='external_unknown_tool:'.$name;
            if(isset($external[$name]))$errors[]='external_duplicate_tool:'.$name;
            $external[$name]=true;
        }
    }

    sort($errors);
    return [
        'ok'=>$errors===[],
        'contract'=>VP3_PROFILE_WEBMCP_RELEASE_CONTRACT_V196,
        'release_version'=>VP3_PROFILE_WEBMCP_RELEASE_V196,
        'tool_count'=>count($catalog),
        'capability_count'=>count($registry),
        'external_tool_count'=>count($external),
        'errors'=>$errors,
    ];
}

function vp3_profile_webmcp_release_audit_v196(): array
{
    if(!function_exists('vp3_profile_webmcp_tool_catalog_v100')
        ||!function_exists('vp3_profile_webmcp_capability_registry_v190')
        ||!function_exists('vp3_profile_webmcp_external_tool_policy_v190')){
        return [
            'ok'=>false,
            'contract'=>VP3_PROFILE_WEBMCP_RELEASE_CONTRACT_V196,
            'release_version'=>VP3_PROFILE_WEBMCP_RELEASE_V196,
            'errors'=>['release_dependencies_unavailable'],
        ];
    }
    return vp3_profile_webmcp_release_catalog_audit_v196(
        vp3_profile_webmcp_tool_catalog_v100(),
        vp3_profile_webmcp_capability_registry_v190(),
        vp3_profile_webmcp_external_tool_policy_v190()
    );
}
