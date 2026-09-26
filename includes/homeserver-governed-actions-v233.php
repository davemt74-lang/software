<?php
declare(strict_types=1);

/**
 * HomeServer v2.3 Section 4 — governed write/action bridge.
 *
 * VP3 may propose actions, but HomeServer remains the execution authority.
 * The bridge never grants approval, weakens an owner policy, or falls back a
 * write/physical action to Cloud execution.
 */
const VP3_HOMESERVER_GOVERNED_ACTIONS_V233='vp3-homeserver-governed-actions-v233-20260925';

function homeserver_governed_v233_catalog(): array
{
    return [
      'memory.write'=>['domain'=>'memory','approval_mode'=>'federated','local_owner_only'=>false],
      'tasks.create'=>['domain'=>'tasks','approval_mode'=>'federated','local_owner_only'=>false],
      'tasks.update'=>['domain'=>'tasks','approval_mode'=>'federated','local_owner_only'=>false],
      'tasks.delete'=>['domain'=>'tasks','approval_mode'=>'federated','local_owner_only'=>false],
      'calendar.create'=>['domain'=>'calendar','approval_mode'=>'federated','local_owner_only'=>false],
      'calendar.update'=>['domain'=>'calendar','approval_mode'=>'federated','local_owner_only'=>false],
      'calendar.delete'=>['domain'=>'calendar','approval_mode'=>'federated','local_owner_only'=>false],
      'contacts.create'=>['domain'=>'contacts','approval_mode'=>'federated','local_owner_only'=>false],
      'contacts.update'=>['domain'=>'contacts','approval_mode'=>'federated','local_owner_only'=>false],
      'contacts.delete'=>['domain'=>'contacts','approval_mode'=>'federated','local_owner_only'=>false],
      'knowledge.create'=>['domain'=>'knowledge','approval_mode'=>'federated','local_owner_only'=>false],
      'knowledge.update'=>['domain'=>'knowledge','approval_mode'=>'federated','local_owner_only'=>false],
      'knowledge.delete'=>['domain'=>'knowledge','approval_mode'=>'federated','local_owner_only'=>false],
      'files.update'=>['domain'=>'files','approval_mode'=>'local_owner','local_owner_only'=>true],
      'files.delete'=>['domain'=>'files','approval_mode'=>'local_owner','local_owner_only'=>true],
      'devices.command'=>['domain'=>'devices','approval_mode'=>'local_owner','local_owner_only'=>true],
    ];
}

function homeserver_governed_v233_tool(string $toolKey): array
{
    $key=strtolower(trim($toolKey));
    $catalog=homeserver_governed_v233_catalog();
    if(!isset($catalog[$key]))throw new RuntimeException('This HomeServer action is not allowlisted for governed execution.');
    return ['key'=>$key]+$catalog[$key];
}

function homeserver_governed_v233_arguments(array $arguments): array
{
    try{$json=json_encode($arguments,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    catch(Throwable $e){throw new RuntimeException('HomeServer action arguments must be valid JSON values.');}
    if(strlen($json)>65536)throw new RuntimeException('HomeServer action arguments exceed the 64 KB bridge limit.');
    return $arguments;
}

function homeserver_governed_v233_unwrap(array $run): array
{
    return is_array($run['result']??null)?$run['result']:[];
}

function homeserver_governed_v233_request(int $userId,string $toolKey,array $arguments): array
{
    if($userId<1)throw new RuntimeException('Authentication required.');
    if(!function_exists('homeserver_execution_v230_execute'))throw new RuntimeException('HomeServer execution runtime is unavailable.');
    $tool=homeserver_governed_v233_tool($toolKey);
    $arguments=homeserver_governed_v233_arguments($arguments);

    // HomeServer's existing /tools/{tool}/execute endpoint resolves the
    // owner-defined action policy. Approval-required tools return a durable
    // action request; safe-automatic tools may execute immediately.
    $run=homeserver_execution_v230_execute($userId,'tool.execute',[
      'tool_key'=>$tool['key'],
      'arguments'=>$arguments,
    ]);
    $result=homeserver_governed_v233_unwrap($run);
    $inner=is_array($result['result']??null)?$result['result']:[];
    $approvalRequired=!empty($result['approval_required'])
      || (string)($inner['status']??'')==='pending'
      || !empty($inner['owner_approval_required']);
    $requestId=trim((string)($inner['request_id']??$result['request_id']??''));
    if($approvalRequired&&$requestId==='')throw new RuntimeException('HomeServer did not return a durable approval request.');

    return [
      'ok'=>true,
      'tool'=>$tool['key'],
      'domain'=>$tool['domain'],
      'status'=>$approvalRequired?'pending_approval':'executed',
      'approval_required'=>$approvalRequired,
      'approval_mode'=>$tool['approval_mode'],
      'local_owner_required'=>!empty($tool['local_owner_only']),
      'request_id'=>$requestId,
      'request'=>$approvalRequired?[
        'request_id'=>$requestId,
        'status'=>(string)($inner['status']??'pending'),
        'action'=>(string)($inner['action']??$tool['key']),
        'expires_at'=>(string)($inner['expires_at']??''),
      ]:null,
      'result'=>$approvalRequired?null:$result,
      'execution'=>is_array($run['execution']??null)?$run['execution']:null,
    ];
}

function homeserver_governed_v233_list(int $userId,string $status='pending',int $limit=100): array
{
    $allowed=['pending','executing','executed','denied','failed','expired'];
    if(!in_array($status,$allowed,true))$status='pending';
    $limit=max(1,min(200,$limit));
    $run=homeserver_execution_v230_execute($userId,'action.list',['status'=>$status,'limit'=>$limit]);
    $result=homeserver_governed_v233_unwrap($run);
    $items=is_array($result['items']??null)?array_values(array_filter($result['items'],'is_array')):[];
    return ['ok'=>true,'items'=>$items,'execution'=>$run['execution']??null];
}

function homeserver_governed_v233_status(int $userId,string $requestId): array
{
    $requestId=trim($requestId);
    if(!preg_match('/^[A-Za-z0-9._:-]{8,160}$/',$requestId))throw new RuntimeException('Invalid HomeServer action request.');
    $run=homeserver_execution_v230_execute($userId,'action.status',['request_id'=>$requestId]);
    $result=homeserver_governed_v233_unwrap($run);
    return ['ok'=>true,'request'=>is_array($result['request']??null)?$result['request']:$result,'execution'=>$run['execution']??null];
}

function homeserver_governed_v233_review(int $userId,string $requestId,string $decision): array
{
    $decision=strtolower(trim($decision));
    if(!in_array($decision,['approve','deny'],true))throw new RuntimeException('Invalid approval decision.');
    $status=homeserver_governed_v233_status($userId,$requestId);
    $request=is_array($status['request']??null)?$status['request']:[];
    $action=strtolower(trim((string)($request['action_key']??$request['action']??'')));
    $catalog=homeserver_governed_v233_catalog();

    // File mutations and physical commands are deliberately not remotely
    // approvable. They may be denied remotely, but approval must happen from
    // local HomeServer owner control.
    if($decision==='approve'&&isset($catalog[$action])&&!empty($catalog[$action]['local_owner_only'])){
        return [
          'ok'=>false,'local_owner_required'=>true,'request'=>$request,
          'error'=>'This action requires approval from local HomeServer owner control.',
          'execution'=>$status['execution']??null,
        ];
    }

    $operation=$decision==='approve'?'action.approve':'action.deny';
    $run=homeserver_execution_v230_execute($userId,$operation,['request_id'=>$requestId]);
    $result=homeserver_governed_v233_unwrap($run);
    return [
      'ok'=>true,'local_owner_required'=>false,
      'request'=>is_array($result['request']??null)?$result['request']:$result,
      'execution'=>$run['execution']??null,
    ];
}
