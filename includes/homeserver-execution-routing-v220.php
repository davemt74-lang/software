<?php
declare(strict_types=1);

/**
 * VP3 Cloud / HomeServer v2.2 execution routing.
 *
 * This layer is deliberately small: it does not create a second tool engine.
 * It reports which execution authority is currently available and funnels
 * explicitly HomeServer-bound operations through the existing paired HTTPS
 * relay. Existing Cloud and HomeServer tool policies remain authoritative.
 */
const VP3_HOMESERVER_EXECUTION_V220='vp3-homeserver-execution-v220-20260924';

function homeserver_execution_v220_registry(int $userId,bool $force=false): array
{
    static $cache=[];
    if($userId<1)return ['available'=>false,'connected'=>false,'version'=>'','capabilities'=>[],'reason'=>'signed_out'];
    if(!$force&&isset($cache[$userId]))return $cache[$userId];
    $status=function_exists('homeserver_https_v1300_status')?homeserver_https_v1300_status($userId):null;
    if(!$status||empty($status['paired'])||empty($status['connected'])){
        return $cache[$userId]=[
          'available'=>false,'connected'=>false,'version'=>'','capabilities'=>[],
          'reason'=>!empty($status['paired'])?'homeserver_offline':'homeserver_not_paired',
        ];
    }
    try{
        $caps=homeserver_https_v1300_remote_operation($userId,'capabilities',[]);
        if(!is_array($caps))$caps=[];
        return $cache[$userId]=[
          'available'=>true,'connected'=>true,
          'version'=>(string)($caps['version']??''),
          'capabilities'=>$caps,
          'reason'=>'ready',
        ];
    }catch(Throwable $e){
        return $cache[$userId]=[
          'available'=>false,'connected'=>false,'version'=>'','capabilities'=>[],
          'reason'=>'capability_probe_failed',
        ];
    }
}

function homeserver_execution_v220_projection(int $userId): array
{
    $registry=homeserver_execution_v220_registry($userId);
    $caps=is_array($registry['capabilities']??null)?$registry['capabilities']:[];
    $inference=is_array($caps['inference']??null)?$caps['inference']:[];
    $files=is_array($caps['files']??null)?$caps['files']:[];
    $knowledge=is_array($caps['knowledge']??null)?$caps['knowledge']:[];
    $shared=is_array($caps['shared_agent_context']??null)?$caps['shared_agent_context']:[];
    return [
      'build'=>VP3_HOMESERVER_EXECUTION_V220,
      'connected'=>!empty($registry['connected']),
      'available'=>!empty($registry['available']),
      'homeserver_version'=>(string)($registry['version']??''),
      'reason'=>(string)($registry['reason']??'unknown'),
      'routes'=>[
        'agent_compute'=>!empty($inference['available'])?'homeserver':'cloud',
        'local_files'=>!empty($files)?'homeserver':'unavailable',
        'local_knowledge'=>!empty($knowledge)?'homeserver':'unavailable',
        'shared_context'=>!empty($shared)?'federated':'cloud',
        'local_tools'=>!empty($caps['action_policy'])?'homeserver':'unavailable',
        'local_voice'=>!empty($caps['local_voice'])?'homeserver':'unavailable',
        'devices'=>!empty($caps['vp3_os_room_device_automation'])?'homeserver':'unavailable',
      ],
      'inference'=>$inference,
      'files'=>$files,
      'knowledge'=>$knowledge,
      'shared_agent_context'=>$shared,
      'recent_receipts'=>function_exists('homeserver_execution_v230_recent')?homeserver_execution_v230_recent($userId,8):[],
    ];
}

function homeserver_execution_v220_can_route(int $userId,string $operation): bool
{
    $operation=trim($operation);
    if($operation==='')return false;
    $registry=homeserver_execution_v220_registry($userId);
    if(empty($registry['available']))return false;
    $safe=[
      'agent.chat','capabilities','capability.registry','knowledge.search',
      'files.list','files.read','tools.list','tool.execute','tools.execute',
      'tasks.list','notifications.list','shared.context.exchange','system.ping',
      'speech.transcribe','speech.synthesize',
      'action.list','action.status','action.approve','action.deny',
    ];
    return in_array($operation,$safe,true);
}

function homeserver_execution_v220_execute(int $userId,string $operation,array $payload=[]): array
{
    if(!homeserver_execution_v220_can_route($userId,$operation)){
        throw new RuntimeException('The requested HomeServer capability is unavailable.');
    }
    return homeserver_https_v1300_remote_operation($userId,$operation,$payload);
}
