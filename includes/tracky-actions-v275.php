<?php
declare(strict_types=1);

/**
 * Tracky V2.75 — governed physical action proposal bridge.
 *
 * Cloud may propose a bounded action through the canonical HomeServer relay.
 * OTRO remains the execution/approval authority. Cloud never approves a local
 * physical command and never receives direct device-driver authority.
 */
const VP3_TRACKY_ACTIONS_V275='vp3-tracky-actions-v275-20260926';
const VP3_TRACKY_ACTION_PROTOCOL_V275='physical_action.v1';

function tracky_v275_settings(PDO $pdo,int $userId): array
{
    $settings=function_exists('tracky_v272_settings')?tracky_v272_settings($pdo,$userId):[];
    return [
        'governed_action_proposals_enabled'=>!empty($settings['governed_action_proposals_enabled']),
    ];
}

function tracky_v275_capability(PDO $pdo,array $user,bool $force=false): array
{
    $join=tracky_v273_capabilities($pdo,$user,$force);
    $tracky=is_array($join['tracky']??null)?$join['tracky']:[];
    $actions=is_array($tracky['governed_actions']??null)?$tracky['governed_actions']:[];
    $supported=!empty($join['compatible'])
        && !empty($join['connected'])
        && !empty($actions['supported'])
        && (string)($actions['protocol']??'')===VP3_TRACKY_ACTION_PROTOCOL_V275;
    return [
        'supported'=>$supported,
        'reason'=>$supported?'ready':(!empty($actions)?'protocol_incompatible':'homeserver_action_capability_unavailable'),
        'join'=>$join,
        'actions'=>$actions,
        'remote_approval_allowed'=>false,
        'direct_execution'=>false,
    ];
}

function tracky_v275_action_id(string $prefix='tracky-action'): string
{
    if(function_exists('homeserver_https_v1300_uuid')){
        return $prefix.'-'.homeserver_https_v1300_uuid();
    }
    return $prefix.'-'.bin2hex(random_bytes(16));
}

function tracky_v275_validate_action_payload(array $payload): array
{
    $deviceKey=trim((string)($payload['device_key']??''));
    $command=trim((string)($payload['command']??''));
    $reason=trim((string)($payload['reason']??''));
    $mode=strtolower(trim((string)($payload['requested_mode']??'suggest_only')));
    $origin=strtolower(trim((string)($payload['origin_kind']??'agent_suggestion')));
    if(!preg_match('/^[a-z0-9][a-z0-9_.:-]{0,79}$/',$deviceKey))throw new RuntimeException('A valid HomeServer device key is required.');
    if(!preg_match('/^[a-z0-9_.:-]{1,40}$/',$command))throw new RuntimeException('A valid physical device command is required.');
    if($reason==='')throw new RuntimeException('A reason is required for a physical action proposal.');
    if(!in_array($mode,['suggest_only','request_approval'],true))throw new RuntimeException('Invalid Tracky action proposal mode.');
    if(!in_array($origin,['user_request','agent_suggestion'],true))throw new RuntimeException('Cloud cannot impersonate a local Tracky automation trigger.');
    $arguments=is_array($payload['arguments']??null)?$payload['arguments']:[];
    $encoded=json_encode($arguments,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($encoded===false||strlen($encoded)>16384)throw new RuntimeException('Tracky physical action arguments are invalid or too large.');
    return [
        'device_key'=>$deviceKey,
        'command'=>$command,
        'arguments'=>$arguments,
        'reason'=>mb_strimwidth($reason,0,1000,''),
        'requested_mode'=>$mode,
        'origin_kind'=>$origin,
        'source_event_id'=>mb_strimwidth(trim((string)($payload['source_event_id']??'')),0,128,''),
    ];
}

function tracky_v275_propose(PDO $pdo,array $user,array $payload): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in to propose a physical action.');
    $settings=tracky_v275_settings($pdo,$uid);
    if(empty($settings['governed_action_proposals_enabled'])){
        return [
            'ok'=>false,'status'=>'disabled','reason'=>'governed_action_proposals_not_enabled',
            'remote_approval_allowed'=>false,'direct_execution'=>false,
        ];
    }
    $capability=tracky_v275_capability($pdo,$user,true);
    if(empty($capability['supported'])){
        return [
            'ok'=>false,'status'=>'unavailable','reason'=>(string)$capability['reason'],
            'capability'=>$capability,'remote_approval_allowed'=>false,'direct_execution'=>false,
        ];
    }
    if(!empty($capability['join']['reconciliation']['needs_reconciliation'])){
        return [
            'ok'=>false,'status'=>'unavailable','reason'=>'homeserver_reconciliation_pending',
            'remote_approval_allowed'=>false,'direct_execution'=>false,
        ];
    }
    if(!function_exists('homeserver_execution_v220_can_route')
        ||!homeserver_execution_v220_can_route($uid,'physical_context.action.propose')){
        return [
            'ok'=>false,'status'=>'unavailable','reason'=>'physical_action_route_unavailable',
            'remote_approval_allowed'=>false,'direct_execution'=>false,
        ];
    }

    $safe=tracky_v275_validate_action_payload($payload);
    $intentId=trim((string)($payload['intent_id']??''));
    if($intentId==='')$intentId=tracky_v275_action_id('tracky-action');
    $correlationId=trim((string)($payload['correlation_id']??''));
    if($correlationId==='')$correlationId=tracky_v275_action_id('tracky-correlation');
    $siteId=tracky_v273_site_id($pdo,$user,(string)($payload['query']??''));

    try{
        $response=homeserver_execution_v220_execute($uid,'physical_context.action.propose',[
            'intent_id'=>$intentId,
            'correlation_id'=>$correlationId,
            'site_id'=>$siteId,
            'origin_kind'=>$safe['origin_kind'],
            'requested_mode'=>$safe['requested_mode'],
            'device_key'=>$safe['device_key'],
            'command'=>$safe['command'],
            'arguments'=>$safe['arguments'],
            'reason'=>$safe['reason'],
            'source_event_id'=>$safe['source_event_id'],
        ]);
    }catch(Throwable $e){
        return [
            'ok'=>false,'status'=>'failed','reason'=>'homeserver_action_proposal_failed',
            'intent_id'=>$intentId,'correlation_id'=>$correlationId,
            'error'=>mb_strimwidth($e->getMessage(),0,300,''),
            'remote_approval_allowed'=>false,'direct_execution'=>false,
        ];
    }

    $intent=is_array($response['intent']??null)?$response['intent']:[];
    $status=(string)($intent['status']??'failed');
    return [
        'ok'=>in_array($status,['suggested','requested'],true),
        'status'=>$status,
        'intent_id'=>(string)($intent['intent_id']??$intentId),
        'correlation_id'=>(string)($intent['correlation_id']??$correlationId),
        'effective_mode'=>(string)($intent['effective_mode']??''),
        'suggestion_id'=>(int)($intent['suggestion_id']??0),
        'action_request_id'=>(string)($intent['action_request_id']??''),
        'permission_upgrade_required'=>!empty($response['permission_upgrade_required'])||!empty($intent['permission_upgrade_required']),
        'local_owner_approval_required'=>!empty($response['local_owner_approval_required']),
        'remote_approval_allowed'=>false,
        'direct_execution'=>false,
        'homeserver'=>$response,
    ];
}

function tracky_v275_status(PDO $pdo,array $user,string $intentId): array
{
    $uid=(int)($user['id']??0);
    $intentId=trim($intentId);
    if($uid<1||!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$intentId))throw new RuntimeException('Invalid Tracky action intent.');
    if(!homeserver_execution_v220_can_route($uid,'physical_context.action.status')){
        throw new RuntimeException('HomeServer physical action status is unavailable.');
    }
    $response=homeserver_execution_v220_execute($uid,'physical_context.action.status',['intent_id'=>$intentId]);
    $intent=is_array($response['intent']??null)?$response['intent']:[];
    return [
        'ok'=>!empty($intent),
        'intent'=>$intent,
        'execution_authority'=>(string)($response['execution_authority']??'existing_homeserver_action_requests'),
        'remote_approval_allowed'=>false,
        'direct_execution'=>false,
    ];
}

function tracky_v275_result_note(array $result): string
{
    $status=(string)($result['status']??'');
    if($status==='suggested'){
        if(!empty($result['permission_upgrade_required'])){
            return ' I created a local HomeServer suggestion. VP3 does not currently have the separate device-control permission needed to create an approval request, and I did not widen that permission automatically.';
        }
        return ' I created a local HomeServer suggestion. No device command has executed.';
    }
    if($status==='requested'){
        return ' I created a HomeServer physical-action approval request. It still requires approval from local HomeServer owner control before any device command can execute.';
    }
    if(($result['reason']??'')==='homeserver_reconciliation_pending'){
        return ' I did not propose the physical action because HomeServer v2.4 reconciliation is still authoritative and incomplete.';
    }
    if(($result['reason']??'')==='governed_action_proposals_not_enabled'){
        return ' Tracky physical-action proposals are disabled for this account.';
    }
    return '';
}

function tracky_v275_cognitive_permission(
    PDO $pdo,array $user,string $agentNamespace,array $ref,string $operation='read'
): bool {
    $uid=(int)($user['id']??0);
    if($uid<1||!function_exists('tracky_agent_enabled_v271')||!tracky_agent_enabled_v271($pdo,$user))return false;
    if((string)($ref['type']??'')!=='physical_action_intent')return false;
    if($operation==='read')return true;
    if($operation!=='write')return false;
    return !empty(tracky_v275_settings($pdo,$uid)['governed_action_proposals_enabled']);
}

function tracky_v275_register_cognitive(): void
{
    if(!function_exists('vp3_cognitive_register_module_v500'))return;
    $registry=vp3_cognitive_registry_storage_v500();
    if(isset($registry['modules']['physical_action']))return;
    vp3_cognitive_register_module_v500([
        'module'=>'physical_action',
        'version'=>'tracky-v2.75',
        'objects'=>['physical_action_intent'],
        'events'=>[],
        'permission_resolver'=>'tracky_v275_cognitive_permission',
        'context_provider'=>null,
        'relationship_provider'=>null,
        'cards'=>[],
        'tools'=>[
            'tracky.propose_device_action'=>[
                'label'=>'Propose a governed physical device action',
                'kind'=>'write',
                'risk'=>'high',
                'requires_approval'=>true,
            ],
        ],
        'freshness_policy'=>[],
        'sensitivity_policy'=>[
            'owner_scoped'=>true,
            'proposal_only'=>true,
            'remote_approval_allowed'=>false,
            'direct_execution'=>false,
            'existing_homeserver_action_authority_only'=>true,
        ],
        'surfaces'=>['ask_user','chat_response'],
        'voice_safe'=>false,
    ]);
}

