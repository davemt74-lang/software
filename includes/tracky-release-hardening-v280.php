<?php
declare(strict_types=1);

const VP3_TRACKY_RELEASE_HARDENING_PROTOCOL_V280='physical_federation_v280_release_hardening.v1';

function tracky_v280_release_invariants(): array
{
    return [
      'origin-homeserver-is-authoritative',
      'cloud-is-request-relay-and-read-only-mirror',
      'agent-may-propose-never-execute',
      'reconnect-is-not-recovery',
      'command-success-is-not-recovery',
      'only-authoritative-reconciliation-restores-current',
      'stale-state-never-promotes-to-current',
      'authority-transfer-requires-epoch-advance',
      'old-authority-cannot-write-after-transfer',
      'conflicting-authority-claims-fail-closed',
      'revocation-wins-over-cached-grants',
      'revoked-device-cannot-regain-trust-from-replay',
      'governed-operation-idempotency-is-durable',
      'terminal-operation-state-is-immutable',
      'migration-053-is-repeat-safe',
      'production-packages-exclude-local-secrets-and-runtime-data',
    ];
}

function tracky_v280_release_public_capability(): array
{
    $reconciliation=tracky_v278_reconciliation_public_capability();
    $access=tracky_v280_access_public_capability();
    $health=tracky_v280_fah_public_capability();
    $fleet=tracky_v280_ffh_public_capability();
    $operations=tracky_v280_fgo_public_capability();
    $checks=[
      'origin_authority_only'=>(string)($reconciliation['authority_assignment']??'')==='origin_only',
      'cloud_relay_mirror_only'=>(string)($reconciliation['cloud_role']??'')==='relay_and_mirror_only',
      'conflicts_fail_closed'=>(string)($reconciliation['same_revision_conflicts']??'')==='fail_closed',
      'connectivity_not_recovery'=>!empty($health['connectivity_returned_is_not_recovery']),
      'authoritative_reconciliation_required'=>!empty($health['recovery_requires_authoritative_reconciliation'])&&!empty($operations['completion_requires_authoritative_reconciliation']),
      'revocation_wins'=>!empty($access['revocation_wins'])&&!empty($operations['revocation_wins']),
      'stale_grants_cannot_restore_access'=>isset($access['stale_remote_grant_can_restore_access'])&&$access['stale_remote_grant_can_restore_access']===false,
      'cloud_execution_blocked'=>isset($operations['cloud_execution_allowed'])&&$operations['cloud_execution_allowed']===false,
      'agent_execution_blocked'=>isset($operations['agent_execution_allowed'])&&$operations['agent_execution_allowed']===false,
      'authority_epoch_required'=>!empty($operations['authority_transfer_requires_epoch_advance']),
      'diagnostics_observational'=>isset($fleet['authority_mutation'])&&$fleet['authority_mutation']===false&&!empty($fleet['diagnostics_never_promote_federation_freshness']),
    ];
    return [
      'version'=>'2.80',
      'protocol'=>VP3_TRACKY_RELEASE_HARDENING_PROTOCOL_V280,
      'final_section'=>10,
      'golden_scenarios'=>24,
      'homeserver_schema_version'=>53,
      'invariants'=>tracky_v280_release_invariants(),
      'checks'=>$checks,
      'release_ready'=>!in_array(false,$checks,true),
      'cloud_read_only'=>true,
      'remote_command_execution'=>false,
      'authority_mutation'=>false,
      'cloud_can_mark_recovered'=>false,
      'cloud_can_assign_authority'=>false,
      'agent_execution_allowed'=>false,
      'split_brain_allowed'=>false,
      'stale_current_promotion_allowed'=>false,
      'revocation_resurrection_allowed'=>false,
      'recovery_authority'=>'section7_authoritative_reconciliation',
    ];
}
