<?php
declare(strict_types=1);
$GLOBALS['v247_version']='2.4';
$GLOBALS['v247_reconciling']=false;
function homeserver_execution_v220_registry(int $userId,bool $force=false): array {
  return ['available'=>true,'connected'=>true,'version'=>$GLOBALS['v247_version'],'capabilities'=>[
    'version'=>$GLOBALS['v247_version'],
    'unified_execution'=>['version'=>'2.3','cloud_routeable'=>true,'approval_boundaries_preserved'=>true,'operations'=>['agent.chat','agent.infer.local','capabilities','capability.registry','knowledge.search','files.list','files.read','tools.list','tool.execute','tasks.list','notifications.list','shared.context.exchange','system.ping','speech.status','speech.transcribe','speech.synthesize','action.list','action.status','action.approve','action.deny'],'profile_safe_local_inference'=>['operation'=>'agent.infer.local','stateless'=>true,'local_only'=>true,'tools_enabled'=>false,'caller_supplied_context_only'=>true]],
    'inference'=>['available'=>true],
    'files'=>['read_only_api'=>true,'write_policy_gated'=>true,'arbitrary_paths'=>false],
    'knowledge'=>['local_index'=>true,'citation_safe_search'=>true,'remote_operation'=>'knowledge.search'],
    'local_voice'=>['local_only'=>true,'operations'=>['speech.status','speech.transcribe','speech.synthesize']],
    'vp3_os_room_device_automation'=>['device_state_read'=>true,'governed_device_actions'=>true,'ambient_direct_execution'=>false],
    'shared_agent_context'=>['mode'=>'federated','authoritative_sources_preserved'=>true,'round_trip_operation'=>'system.ping'],
    'federated_data_continuity'=>['version'=>'2.4','native_source_remains_authoritative'=>true,'remote_records_are_mirrors'=>true,'no_cross_database_id_writes'=>true,'conflict_resolution'=>'authority_wins'],
    'contacts_continuity'=>['version'=>'2.4'],
    'knowledge_continuity'=>['version'=>'2.4'],
    'task_calendar_continuity'=>['version'=>'2.4'],
    'file_document_continuity'=>['version'=>'2.4','canonical_identity'=>true,'governed_writes'=>true,'local_owner_approval_only'=>true,'opaque_refs'=>true,'absolute_paths_exposed'=>false],
    'agent_brain_memory_continuity'=>['version'=>'2.4','canonical_identity'=>true,'provenance_preserved'=>true,'governed_writes'=>true,'optimistic_concurrency'=>true,'idempotent_mutations'=>true,'memory_key_scopes_enforced'=>true,'candidate_promotion_preserved'=>true],
    'disconnect_reconnect_reconciliation'=>['version'=>'2.4','contract'=>'v246','native_source_remains_authoritative'=>true,'absence_tombstones_full_snapshots_only'=>true,'filtered_snapshots_never_delete'=>true,'reconnect_requires_reconciliation'=>true,'revision_integrity'=>true,'duplicate_authority_keys_rejected'=>true,'durable_reconciliation_runs'=>true],
    'v24_release_acceptance'=>['version'=>'2.4','contract'=>'v247','minimum_schema_version'=>38,'retains_v23_unified_execution'=>true,'windows_portable_exe'=>true,'windows_installer'=>true,'upgrade_preserves_private_data'=>true,'release_manifest'=>'vp3-os-release-v1'],
  ]];
}
function homeserver_voice_v234_status(int $userId,bool $force=false): array {return ['available'=>true,'transcription_available'=>true];}
function homeserver_execution_v230_policy(string $operation,array $payload=[]): array {
 if($operation==='agent.chat')return ['fallback_allowed'=>true,'write_or_physical'=>false];
 if($operation==='speech.synthesize')return ['fallback_allowed'=>false,'write_or_physical'=>false];
 return ['fallback_allowed'=>false,'write_or_physical'=>$operation==='tool.execute'];
}
function homeserver_execution_v230_schema_ready(): bool{return true;}
function homeserver_execution_v230_recent(int $userId,int $limit=8): array{return [];}
function homeserver_reconciliation_v246_state(int $userId): array{return ['needs_reconciliation'=>!empty($GLOBALS['v247_reconciling'])];}
require dirname(__DIR__).'/includes/homeserver-acceptance-v236.php';
require dirname(__DIR__).'/includes/homeserver-acceptance-v247.php';
function v247_assert(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$ok=homeserver_acceptance_v247_run(['id'=>7]);
v247_assert($ok['version']==='2.4','version');
v247_assert($ok['production_ready']===true,'ready');
v247_assert(($ok['summary']['blocked']??-1)===0,'zero blockers');
$GLOBALS['v247_reconciling']=true;
$stale=homeserver_acceptance_v247_run(['id'=>7]);
v247_assert($stale['production_ready']===false,'reconciling blocks');
$GLOBALS['v247_reconciling']=false;$GLOBALS['v247_version']='2.3';
$old=homeserver_acceptance_v247_run(['id'=>7]);
v247_assert($old['production_ready']===false,'old version blocks');
print("HomeServer v2.4 Cloud final release acceptance runtime: PASS\n");
