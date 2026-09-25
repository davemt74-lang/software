<?php
declare(strict_types=1);

$GLOBALS['v236_version']='2.3';
$GLOBALS['v236_voice_ready']=true;
$GLOBALS['v236_unsafe_device_fallback']=false;
$GLOBALS['v236_profile_safe']=true;
$GLOBALS['v236_schema_ready']=true;

function homeserver_execution_v220_registry(int $userId,bool $force=false): array
{
    $profile=$GLOBALS['v236_profile_safe']
      ?[
        'operation'=>'agent.infer.local',
        'stateless'=>true,
        'local_only'=>true,
        'tools_enabled'=>false,
        'caller_supplied_context_only'=>true,
      ]
      :[
        'operation'=>'agent.chat',
        'stateless'=>false,
        'local_only'=>false,
        'tools_enabled'=>true,
        'caller_supplied_context_only'=>false,
      ];
    return [
      'available'=>$userId===7,
      'connected'=>$userId===7,
      'version'=>$GLOBALS['v236_version'],
      'reason'=>'ready',
      'capabilities'=>[
        'version'=>$GLOBALS['v236_version'],
        'unified_execution'=>[
          'version'=>'2.3',
          'cloud_routeable'=>true,
          'approval_boundaries_preserved'=>true,
          'operations'=>[
            'agent.chat','agent.infer.local','capabilities','capability.registry',
            'knowledge.search','files.list','files.read','tools.list','tool.execute',
            'tasks.list','notifications.list','shared.context.exchange','system.ping',
            'speech.status','speech.transcribe','speech.synthesize',
            'action.list','action.status','action.approve','action.deny',
          ],
          'profile_safe_local_inference'=>$profile,
        ],
        'inference'=>['available'=>true],
        'files'=>[
          'read_only_api'=>true,'write_policy_gated'=>true,'arbitrary_paths'=>false,
        ],
        'knowledge'=>[
          'local_index'=>true,'citation_safe_search'=>true,'remote_operation'=>'knowledge.search',
        ],
        'local_voice'=>[
          'local_only'=>true,
          'operations'=>['speech.status','speech.transcribe','speech.synthesize'],
        ],
        'vp3_os_room_device_automation'=>[
          'device_state_read'=>true,'governed_device_actions'=>true,'ambient_direct_execution'=>false,
        ],
        'shared_agent_context'=>[
          'mode'=>'federated','authoritative_sources_preserved'=>true,'round_trip_operation'=>'system.ping',
        ],
        'private_secret'=>'MUST-NOT-LEAK',
      ],
    ];
}

function homeserver_voice_v234_status(int $userId,bool $force=false): array
{
    return [
      'available'=>!empty($GLOBALS['v236_voice_ready']),
      'transcription_available'=>!empty($GLOBALS['v236_voice_ready']),
    ];
}

function homeserver_execution_v230_policy(string $operation,array $payload=[]): array
{
    if($operation==='agent.chat')return ['fallback_allowed'=>true,'write_or_physical'=>false];
    if($operation==='speech.synthesize')return ['fallback_allowed'=>false,'write_or_physical'=>false];
    if($operation==='tool.execute'){
        return [
          'fallback_allowed'=>!empty($GLOBALS['v236_unsafe_device_fallback']),
          'write_or_physical'=>true,
        ];
    }
    return ['fallback_allowed'=>false,'write_or_physical'=>false];
}

function homeserver_execution_v230_schema_ready(): bool
{
    return !empty($GLOBALS['v236_schema_ready']);
}

function homeserver_execution_v230_recent(int $userId,int $limit=8): array
{
    return [['operation'=>'agent.chat','route'=>'homeserver','status'=>'completed','result_meta'=>['provider'=>'ollama']]];
}

require dirname(__DIR__).'/includes/homeserver-acceptance-v236.php';

function v236_check(array $result,string $key): array
{
    foreach($result['checks']??[] as $check)if(($check['key']??'')===$key)return $check;
    return [];
}

function v236_assert(bool $ok,string $message): void
{
    if(!$ok){
        fwrite(STDERR,"FAIL: {$message}\n");
        exit(1);
    }
}

$healthy=homeserver_acceptance_v236_run(['id'=>7]);
v236_assert($healthy['version']==='2.3','acceptance version');
v236_assert($healthy['probe_kind']==='zero_token_read_only_release_acceptance','probe kind');
v236_assert($healthy['token_spend']===0,'zero token spend');
v236_assert($healthy['write_actions_executed']===0,'zero write actions');
v236_assert($healthy['physical_actions_executed']===0,'zero physical actions');
v236_assert($healthy['production_ready']===true,'healthy release must be ready');
v236_assert(($healthy['summary']['blocked']??-1)===0,'healthy release has no blockers');
foreach(['connection','product_version','unified_execution','compute','files','knowledge','voice','devices','profile_agent_privacy','shared_agent','fallback_governance','receipts'] as $key){
    v236_assert((v236_check($healthy,$key)['status']??'')==='ready',"{$key} ready");
}
$encoded=json_encode($healthy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
v236_assert(is_string($encoded)&&!str_contains($encoded,'MUST-NOT-LEAK'),'acceptance output excludes private capability fields');

$GLOBALS['v236_voice_ready']=false;
$voiceOptional=homeserver_acceptance_v236_run(['id'=>7]);
v236_assert($voiceOptional['production_ready']===true,'uninstalled local voice runtime is a warning, not architecture blocker');
v236_assert((v236_check($voiceOptional,'voice')['status']??'')==='warning','voice runtime warning');

$GLOBALS['v236_voice_ready']=true;
$GLOBALS['v236_version']='2.2';
$downgrade=homeserver_acceptance_v236_run(['id'=>7]);
v236_assert($downgrade['production_ready']===false,'pre-2.3 HomeServer must fail release acceptance');
v236_assert((v236_check($downgrade,'product_version')['status']??'')==='blocked','product version blocked');

$GLOBALS['v236_version']='2.3';
$GLOBALS['v236_unsafe_device_fallback']=true;
$unsafe=homeserver_acceptance_v236_run(['id'=>7]);
v236_assert($unsafe['production_ready']===false,'unsafe physical fallback must fail release acceptance');
v236_assert((v236_check($unsafe,'fallback_governance')['status']??'')==='blocked','fallback governance blocked');

$GLOBALS['v236_unsafe_device_fallback']=false;
$GLOBALS['v236_profile_safe']=false;
$profileUnsafe=homeserver_acceptance_v236_run(['id'=>7]);
v236_assert($profileUnsafe['production_ready']===false,'unsafe Profile Agent contract must fail release acceptance');
v236_assert((v236_check($profileUnsafe,'profile_agent_privacy')['status']??'')==='blocked','Profile Agent privacy blocked');

$GLOBALS['v236_profile_safe']=true;
$GLOBALS['v236_schema_ready']=false;
$noReceipts=homeserver_acceptance_v236_run(['id'=>7]);
v236_assert($noReceipts['production_ready']===false,'missing receipt schema must fail release acceptance');
v236_assert((v236_check($noReceipts,'receipts')['status']??'')==='blocked','receipt schema blocked');

print("HomeServer v2.3 Cloud unified release acceptance runtime: PASS\n");
