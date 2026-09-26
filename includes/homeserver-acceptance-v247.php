<?php
declare(strict_types=1);

/**
 * HomeServer v2.4 Section 8 — final release acceptance.
 *
 * Extends the retained v2.3 execution acceptance with v2.4 continuity,
 * federation, authority, privacy and reconnect-freshness requirements.
 */
const VP3_HOMESERVER_ACCEPTANCE_V247='vp3-homeserver-acceptance-v247-20260926';

function homeserver_acceptance_v247_check(string $key,string $label,string $status,string $detail,array $meta=[]): array
{
    return homeserver_acceptance_v236_check($key,$label,$status,$detail,$meta);
}

function homeserver_acceptance_v247_run(array $user): array
{
    $started=microtime(true);
    $base=homeserver_acceptance_v236_run($user);
    $checks=is_array($base['checks']??null)?$base['checks']:[];
    $userId=(int)($user['id']??0);
    $registry=homeserver_execution_v220_registry($userId,true);
    $caps=is_array($registry['capabilities']??null)?$registry['capabilities']:[];
    $version=trim((string)($registry['version']??$caps['version']??''));
    $versionReady=$version!==''&&version_compare($version,'2.4','>=');
    $checks[]=homeserver_acceptance_v247_check(
      'v24_product_version','HomeServer 2.4 product version',$versionReady?'ready':'blocked',
      $versionReady?'HomeServer reports v2.4 or newer.':'HomeServer v2.4 or newer is required.',
      ['version'=>$version]
    );

    $required=[
      'federated_data_continuity','contacts_continuity','knowledge_continuity',
      'task_calendar_continuity','file_document_continuity',
      'agent_brain_memory_continuity','disconnect_reconnect_reconciliation'
    ];
    foreach($required as $key){
        $section=is_array($caps[$key]??null)?$caps[$key]:[];
        $ready=(string)($section['version']??'')==='2.4';
        $checks[]=homeserver_acceptance_v247_check(
          $key,$key,$ready?'ready':'blocked',
          $ready?'The v2.4 continuity contract is advertised.':'The required v2.4 continuity contract is missing.',
          ['version'=>(string)($section['version']??'')]
        );
    }

    $federation=is_array($caps['federated_data_continuity']??null)?$caps['federated_data_continuity']:[];
    $authorityReady=!empty($federation['native_source_remains_authoritative'])
      &&!empty($federation['remote_records_are_mirrors'])
      &&!empty($federation['no_cross_database_id_writes'])
      &&(string)($federation['conflict_resolution']??'')==='authority_wins';
    $checks[]=homeserver_acceptance_v247_check(
      'native_authority','Native authority & canonical identity',$authorityReady?'ready':'blocked',
      $authorityReady?'Native stores remain authoritative and remote data remains mirrored metadata.':'The v2.4 native-authority boundary is incomplete.'
    );

    $memory=is_array($caps['agent_brain_memory_continuity']??null)?$caps['agent_brain_memory_continuity']:[];
    $memoryReady=!empty($memory['canonical_identity'])&&!empty($memory['provenance_preserved'])
      &&!empty($memory['governed_writes'])&&!empty($memory['optimistic_concurrency'])
      &&!empty($memory['idempotent_mutations'])&&!empty($memory['memory_key_scopes_enforced'])
      &&!empty($memory['candidate_promotion_preserved']);
    $checks[]=homeserver_acceptance_v247_check(
      'agent_brain_memory','Agent Brain & Memory continuity',$memoryReady?'ready':'blocked',
      $memoryReady?'Memory identity, provenance, scopes, promotion and governed mutations are preserved.':'Agent Brain/Memory v2.4 continuity is incomplete.'
    );

    $files=is_array($caps['file_document_continuity']??null)?$caps['file_document_continuity']:[];
    $filesReady=!empty($files['canonical_identity'])&&!empty($files['governed_writes'])
      &&!empty($files['local_owner_approval_only'])&&!empty($files['opaque_refs'])
      &&empty($files['absolute_paths_exposed']);
    $checks[]=homeserver_acceptance_v247_check(
      'file_document_privacy','Files & Documents privacy',$filesReady?'ready':'blocked',
      $filesReady?'File identity is canonical; mutations stay local-owner governed and filesystem paths remain private.':'The v2.4 file/document privacy boundary is incomplete.'
    );

    $reconcile=is_array($caps['disconnect_reconnect_reconciliation']??null)?$caps['disconnect_reconnect_reconciliation']:[];
    $contractReady=(string)($reconcile['contract']??'')==='v246'
      &&!empty($reconcile['native_source_remains_authoritative'])
      &&!empty($reconcile['absence_tombstones_full_snapshots_only'])
      &&!empty($reconcile['filtered_snapshots_never_delete'])
      &&!empty($reconcile['reconnect_requires_reconciliation'])
      &&!empty($reconcile['revision_integrity'])
      &&!empty($reconcile['duplicate_authority_keys_rejected'])
      &&!empty($reconcile['durable_reconciliation_runs']);
    $state=function_exists('homeserver_reconciliation_v246_state')
      ?homeserver_reconciliation_v246_state($userId):['needs_reconciliation'=>true];
    $fresh=!empty($registry['connected'])&&empty($state['needs_reconciliation']);
    $checks[]=homeserver_acceptance_v247_check(
      'reconciliation_contract','Disconnect/reconnect reconciliation',$contractReady?'ready':'blocked',
      $contractReady?'Full snapshots reconcile authority safely; filtered snapshots cannot infer deletion.':'The v2.4 reconciliation contract is incomplete.'
    );
    $checks[]=homeserver_acceptance_v247_check(
      'continuity_current','Continuity freshness',$fresh?'ready':'blocked',
      $fresh?'HomeServer is connected and its federated continuity is current.':'HomeServer data is disconnected, reconciling, stale or failed.',
      ['connected'=>!empty($registry['connected'])]
    );

    $release=is_array($caps['v24_release_acceptance']??null)?$caps['v24_release_acceptance']:[];
    $releaseReady=(string)($release['contract']??'')==='v247'
      &&(int)($release['minimum_schema_version']??0)>=38
      &&!empty($release['retains_v23_unified_execution'])
      &&!empty($release['windows_portable_exe'])
      &&!empty($release['windows_installer'])
      &&!empty($release['upgrade_preserves_private_data'])
      &&(string)($release['release_manifest']??'')==='vp3-os-release-v1';
    $checks[]=homeserver_acceptance_v247_check(
      'release_package_contract','Windows release package',$releaseReady?'ready':'blocked',
      $releaseReady?'v2.4 requires schema 38, portable EXE, installer, private-data-safe upgrades and the stable release manifest.':'The v2.4 package contract is incomplete.'
    );

    $blocked=count(array_filter($checks,static fn(array $row):bool=>($row['status']??'')==='blocked'));
    $warnings=count(array_filter($checks,static fn(array $row):bool=>($row['status']??'')==='warning'));
    $ready=count(array_filter($checks,static fn(array $row):bool=>($row['status']??'')==='ready'));

    return [
      'version'=>'2.4','build'=>VP3_HOMESERVER_ACCEPTANCE_V247,
      'tested_at'=>gmdate('c'),'probe_kind'=>'zero_token_read_only_release_acceptance',
      'token_spend'=>0,'write_actions_executed'=>0,'physical_actions_executed'=>0,
      'production_ready'=>$blocked===0,
      'summary'=>['ready'=>$ready,'warnings'=>$warnings,'blocked'=>$blocked,'total'=>count($checks)],
      'duration_ms'=>max(0,(int)round((microtime(true)-$started)*1000)),
      'checks'=>$checks,
    ];
}
