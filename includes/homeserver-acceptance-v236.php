<?php
declare(strict_types=1);

/**
 * HomeServer v2.3 Section 6 — unified release acceptance from VP3 Cloud.
 *
 * Zero-token and read-only: this verifies capability, routing, privacy,
 * governance, receipt-schema and product-version contracts without executing a
 * model prompt, mutating HomeServer data, or approving a physical action.
 */
const VP3_HOMESERVER_ACCEPTANCE_V236='vp3-homeserver-acceptance-v236-20260925';

function homeserver_acceptance_v236_check(
    string $key,string $label,string $status,string $detail,array $meta=[]
): array {
    if(!in_array($status,['ready','warning','blocked','not_applicable'],true))$status='blocked';
    $safe=[];
    foreach(['version','count','available','connected','local_only','stateless','tools_enabled','caller_supplied_context_only','fallback_allowed','write_or_physical','governed','direct_execution','schema_ready'] as $name){
        if(!array_key_exists($name,$meta))continue;
        $value=$meta[$name];
        if(is_bool($value)||is_int($value)||is_float($value)||$value===null)$safe[$name]=$value;
        elseif(is_string($value))$safe[$name]=mb_strimwidth(trim($value),0,160,'');
    }
    return [
      'key'=>preg_replace('/[^a-z0-9_]/','',strtolower($key))?:'check',
      'label'=>mb_strimwidth($label,0,90,''),
      'status'=>$status,
      'detail'=>mb_strimwidth($detail,0,260,''),
      'meta'=>$safe,
    ];
}

function homeserver_acceptance_v236_run(array $user): array
{
    $started=microtime(true);
    $userId=(int)($user['id']??0);
    if($userId<1)throw new RuntimeException('Sign in to test HomeServer.');

    $registry=function_exists('homeserver_execution_v220_registry')
      ?homeserver_execution_v220_registry($userId,true)
      :['available'=>false,'connected'=>false,'version'=>'','capabilities'=>[],'reason'=>'unsupported'];
    $caps=is_array($registry['capabilities']??null)?$registry['capabilities']:[];
    $checks=[];

    $connected=!empty($registry['available'])&&!empty($registry['connected']);
    $checks[]=homeserver_acceptance_v236_check(
      'connection','Cloud ↔ HomeServer',$connected?'ready':'blocked',
      $connected?'Authenticated HomeServer capability round trip succeeded.':'HomeServer is not paired and connected through the canonical execution route.',
      ['available'=>!empty($registry['available']),'connected'=>!empty($registry['connected'])]
    );

    $version=trim((string)($registry['version']??$caps['version']??''));
    $versionReady=$version!==''&&version_compare($version,'2.3','>=');
    $checks[]=homeserver_acceptance_v236_check(
      'product_version','HomeServer product version',$versionReady?'ready':'blocked',
      $versionReady?'HomeServer reports product version '.$version.'.':'HomeServer 2.3 or newer is required for this release contract.',
      ['version'=>$version]
    );

    $unified=is_array($caps['unified_execution']??null)?$caps['unified_execution']:[];
    $requiredOps=[
      'agent.chat','agent.infer.local','capabilities','capability.registry',
      'knowledge.search','files.list','files.read','tools.list','tool.execute',
      'tasks.list','notifications.list','shared.context.exchange','system.ping',
      'speech.status','speech.transcribe','speech.synthesize',
      'action.list','action.status','action.approve','action.deny',
    ];
    $ops=array_values(array_filter((array)($unified['operations']??[]),'is_string'));
    $missing=array_values(array_diff($requiredOps,$ops));
    $unifiedReady=(string)($unified['version']??'')==='2.3'
      &&!empty($unified['cloud_routeable'])
      &&!empty($unified['approval_boundaries_preserved'])
      &&count($missing)===0;
    $checks[]=homeserver_acceptance_v236_check(
      'unified_execution','Unified execution',$unifiedReady?'ready':'blocked',
      $unifiedReady?'All required v2.3 Cloud↔HomeServer execution operations are advertised.':'The paired HomeServer does not advertise the complete v2.3 unified execution contract.',
      ['version'=>(string)($unified['version']??''),'count'=>count($ops)]
    );

    $inference=is_array($caps['inference']??null)?$caps['inference']:[];
    $computeReady=!empty($inference['available']);
    $checks[]=homeserver_acceptance_v236_check(
      'compute','Agent compute',$computeReady?'ready':'warning',
      $computeReady?'A HomeServer inference route is currently available.':'Unified compute is installed, but no HomeServer inference provider is currently ready.',
      ['available'=>$computeReady]
    );

    $files=is_array($caps['files']??null)?$caps['files']:[];
    $filesReady=!empty($files['read_only_api'])&&!empty($files['write_policy_gated'])&&empty($files['arbitrary_paths']);
    $checks[]=homeserver_acceptance_v236_check(
      'files','Local files',$filesReady?'ready':'blocked',
      $filesReady?'Local file reads are bounded and file writes remain policy-gated.':'The v2.3 bounded local-file contract is incomplete.'
    );

    $knowledge=is_array($caps['knowledge']??null)?$caps['knowledge']:[];
    $knowledgeReady=!empty($knowledge['local_index'])&&!empty($knowledge['citation_safe_search'])&&(string)($knowledge['remote_operation']??'')==='knowledge.search';
    $checks[]=homeserver_acceptance_v236_check(
      'knowledge','Local Knowledge',$knowledgeReady?'ready':'blocked',
      $knowledgeReady?'Local Knowledge search is indexed and citation-safe.':'The v2.3 local Knowledge contract is incomplete.'
    );

    $voiceCap=is_array($caps['local_voice']??null)?$caps['local_voice']:[];
    $voiceOps=array_values(array_filter((array)($voiceCap['operations']??[]),'is_string'));
    $voiceContract=!empty($voiceCap['local_only'])
      &&count(array_diff(['speech.status','speech.transcribe','speech.synthesize'],$voiceOps))===0;
    $voiceStatus=function_exists('homeserver_voice_v234_status')
      ?homeserver_voice_v234_status($userId,true)
      :['available'=>false,'transcription_available'=>false];
    $voiceReady=$voiceContract&&!empty($voiceStatus['available'])&&!empty($voiceStatus['transcription_available']);
    $checks[]=homeserver_acceptance_v236_check(
      'voice','Local voice',$voiceReady?'ready':($voiceContract?'warning':'blocked'),
      $voiceReady?'HomeServer Piper speech and Whisper transcription are locally ready.':($voiceContract?'The v2.3 local voice contract is present, but one or more local voice runtimes are not installed or ready.':'The v2.3 local voice execution contract is incomplete.'),
      ['available'=>!empty($voiceStatus['available']),'local_only'=>!empty($voiceCap['local_only'])]
    );

    $device=is_array($caps['vp3_os_room_device_automation']??null)?$caps['vp3_os_room_device_automation']:[];
    $deviceReady=!empty($device['device_state_read'])&&!empty($device['governed_device_actions'])&&empty($device['ambient_direct_execution']);
    $checks[]=homeserver_acceptance_v236_check(
      'devices','Rooms & devices',$deviceReady?'ready':'blocked',
      $deviceReady?'Device state is readable and physical commands remain governed.':'The physical-device governance boundary is incomplete.',
      ['governed'=>!empty($device['governed_device_actions']),'direct_execution'=>!empty($device['ambient_direct_execution'])]
    );

    $profile=is_array($unified['profile_safe_local_inference']??null)?$unified['profile_safe_local_inference']:[];
    $profileReady=(string)($profile['operation']??'')==='agent.infer.local'
      &&!empty($profile['stateless'])
      &&!empty($profile['local_only'])
      &&empty($profile['tools_enabled'])
      &&!empty($profile['caller_supplied_context_only']);
    $checks[]=homeserver_acceptance_v236_check(
      'profile_agent_privacy','Profile Agent privacy',$profileReady?'ready':'blocked',
      $profileReady?'Profile Agent local inference is stateless, local-only, tool-free, and restricted to caller-approved context.':'The Profile Agent privacy-safe local inference contract is incomplete.',
      [
        'stateless'=>!empty($profile['stateless']),
        'local_only'=>!empty($profile['local_only']),
        'tools_enabled'=>!empty($profile['tools_enabled']),
        'caller_supplied_context_only'=>!empty($profile['caller_supplied_context_only']),
      ]
    );

    $shared=is_array($caps['shared_agent_context']??null)?$caps['shared_agent_context']:[];
    $sharedReady=(string)($shared['mode']??'')==='federated'
      &&!empty($shared['authoritative_sources_preserved'])
      &&(string)($shared['round_trip_operation']??'')==='system.ping';
    $checks[]=homeserver_acceptance_v236_check(
      'shared_agent','Shared Agent continuity',$sharedReady?'ready':'blocked',
      $sharedReady?'Cloud and HomeServer preserve one federated Agent context with reconnect round-trip support.':'The shared Agent continuity contract is incomplete.'
    );

    $computePolicy=function_exists('homeserver_execution_v230_policy')?homeserver_execution_v230_policy('agent.chat',[]):[];
    $voicePolicy=function_exists('homeserver_execution_v230_policy')?homeserver_execution_v230_policy('speech.synthesize',[]):[];
    $devicePolicy=function_exists('homeserver_execution_v230_policy')?homeserver_execution_v230_policy('tool.execute',['tool_key'=>'devices.command']):[];
    $fallbackReady=!empty($computePolicy['fallback_allowed'])
      &&empty($voicePolicy['fallback_allowed'])
      &&empty($voicePolicy['write_or_physical'])
      &&empty($devicePolicy['fallback_allowed'])
      &&!empty($devicePolicy['write_or_physical']);
    $checks[]=homeserver_acceptance_v236_check(
      'fallback_governance','Fallback & action boundaries',$fallbackReady?'ready':'blocked',
      $fallbackReady?'Compute may fall back safely; local voice does not masquerade as a write; physical device commands cannot Cloud-fallback.':'One or more v2.3 fallback/action classifications are unsafe.',
      ['fallback_allowed'=>!empty($computePolicy['fallback_allowed']),'write_or_physical'=>!empty($devicePolicy['write_or_physical'])]
    );

    $schemaReady=function_exists('homeserver_execution_v230_schema_ready')&&homeserver_execution_v230_schema_ready();
    $recent=$schemaReady&&function_exists('homeserver_execution_v230_recent')?homeserver_execution_v230_recent($userId,8):[];
    $checks[]=homeserver_acceptance_v236_check(
      'receipts','Execution receipts',$schemaReady?'ready':'blocked',
      $schemaReady?'Canonical v2.3 execution receipt storage is ready; recent receipts remain sanitized metadata only.':'The v2.3 execution receipt schema is not ready.',
      ['schema_ready'=>$schemaReady,'count'=>is_array($recent)?count($recent):0]
    );

    $blocked=count(array_filter($checks,static fn(array $row):bool=>($row['status']??'')==='blocked'));
    $warnings=count(array_filter($checks,static fn(array $row):bool=>($row['status']??'')==='warning'));
    $ready=count(array_filter($checks,static fn(array $row):bool=>($row['status']??'')==='ready'));

    return [
      'version'=>'2.3',
      'build'=>VP3_HOMESERVER_ACCEPTANCE_V236,
      'tested_at'=>gmdate('c'),
      'probe_kind'=>'zero_token_read_only_release_acceptance',
      'token_spend'=>0,
      'write_actions_executed'=>0,
      'physical_actions_executed'=>0,
      'production_ready'=>$blocked===0,
      'summary'=>['ready'=>$ready,'warnings'=>$warnings,'blocked'=>$blocked,'total'=>count($checks)],
      'duration_ms'=>max(0,(int)round((microtime(true)-$started)*1000)),
      'checks'=>$checks,
    ];
}
