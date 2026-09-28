<?php
declare(strict_types=1);

/**
 * Tracky V2.78 Section 9 — failure, partition and reconciliation contract.
 *
 * Cloud exposes mirror cursors and relays semantic snapshots. It never promotes
 * itself or another peer to physical-world authority and never declares a
 * destination current; the destination HomeServer performs reconciliation.
 */
const VP3_TRACKY_FEDERATION_RECONCILIATION_V278='vp3-tracky-federation-reconciliation-v278-20260927';
const VP3_TRACKY_FEDERATION_RECONCILIATION_PROTOCOL_V278='physical_federation_reconciliation.v1';

function tracky_v278_reconciliation_payload(array $remoteCursors): array
{
    $normalized=[];
    $seen=[];
    foreach(array_slice($remoteCursors,0,128) as $cursor){
        if(!is_array($cursor))continue;
        $site=tracky_v278_world_uuid($cursor['site_id']??'','reconciliation site id');
        if(isset($seen[$site]))continue;
        $seen[$site]=true;
        $normalized[]=[
          'site_id'=>$site,
          'revision'=>max(0,(int)($cursor['revision']??0)),
          'fingerprint'=>tracky_v278_sync_text($cursor['fingerprint']??'',128),
          'authority_epoch'=>max(0,(int)($cursor['authority_epoch']??0)),
        ];
    }
    return [
      'protocol'=>VP3_TRACKY_FEDERATION_RECONCILIATION_PROTOCOL_V278,
      'schema_version'=>1,
      'remote_cursors'=>$normalized,
      'semantic_only'=>true,
      'authority_assignment'=>'origin_only',
      'cloud_role'=>'relay_and_mirror_only',
      'destination_decides_freshness'=>true,
      'same_revision_conflicts'=>'fail_closed',
    ];
}

function tracky_v278_reconciliation_public_capability(): array
{
    return [
      'version'=>'2.78',
      'protocol'=>VP3_TRACKY_FEDERATION_RECONCILIATION_PROTOCOL_V278,
      'peer_states'=>['unknown','current','suspect','partitioned','reconciling','stale','failed'],
      'stale_data_labeled'=>true,
      'full_snapshot_on_gap'=>true,
      'authority_epoch_revalidation'=>true,
      'same_revision_conflicts'=>'fail_closed',
      'semantic_only'=>true,
      'authority_assignment'=>'origin_only',
      'cloud_role'=>'relay_and_mirror_only',
      'cloud_can_mark_destination_current'=>false,
      'cloud_can_assign_authority'=>false,
      'boundaries'=>[
        'origin-site-authority-only',
        'partition-never-promotes-remote-or-cloud-authority',
        'stale-data-must-be-labeled',
        'revision-gap-requires-authoritative-reconciliation',
        'authority-epoch-change-requires-revalidation',
        'same-revision-fingerprint-conflict-fails-closed',
        'reconciliation-is-semantic-only',
        'cloud-remains-relay-and-mirror-only',
      ],
    ];
}
