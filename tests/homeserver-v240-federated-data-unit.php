<?php
declare(strict_types=1);

function homeserver_execution_v220_can_route(int $userId,string $operation): bool
{
    return $userId===7&&$operation==='federation.registry';
}
function homeserver_execution_v220_execute(int $userId,string $operation,array $payload=[]): array
{
    return [
      'version'=>'2.4',
      'mode'=>'native_authority_mirrored_continuity',
      'datasets'=>['memory','knowledge','contacts','tasks','calendar','notifications','profile_context'],
      'rules'=>[
        'native_source_remains_authoritative'=>true,
        'remote_records_are_mirrors'=>true,
        'no_cross_database_id_writes'=>true,
        'canonical_identity'=>'sha256(authority_source|dataset|authority_key)',
        'conflict_resolution'=>'authority_wins',
      ],
    ];
}

require dirname(__DIR__).'/includes/homeserver-federated-data-v240.php';

function v240_assert(bool $ok,string $message): void
{
    if(!$ok){
        fwrite(STDERR,"FAIL: {$message}\n");
        exit(1);
    }
}

$cloud=homeserver_federated_v240_canonical_id('vp3_cloud','contacts','contacts:42');
$again=homeserver_federated_v240_canonical_id('vp3_cloud','contacts','contacts:42');
$home=homeserver_federated_v240_canonical_id('homeserver','contacts','contacts:42');
v240_assert($cloud===$again,'canonical identity must be stable');
v240_assert(str_starts_with($cloud,'fd24_'),'canonical identity prefix');
v240_assert($cloud!==$home,'authority source must separate identities');

$cloudEnvelope=homeserver_federated_v240_envelope(
    'vp3_cloud','contacts','contacts:42','Cloud contact','Cloud authoritative record','2026-09-25T12:00:00Z'
);
v240_assert($cloudEnvelope['canonical_id']===$cloud,'Cloud envelope canonical identity');
v240_assert($cloudEnvelope['mirror_only']===false,'Cloud-native record is not a mirror in Cloud');

$homeEnvelope=homeserver_federated_v240_envelope(
    'homeserver','tasks','7','Local task','HomeServer authoritative task',null
);
v240_assert($homeEnvelope['mirror_only']===true,'HomeServer-native record is mirror-only in Cloud');

$normalized=homeserver_federated_v240_normalize([
    'authority_source'=>'homeserver',
    'authority_key'=>'7',
    'canonical_id'=>$homeEnvelope['canonical_id'],
    'title'=>'Local task',
    'content'=>'HomeServer authoritative task',
],'homeserver','tasks',0);
v240_assert($normalized['canonical_id']===$homeEnvelope['canonical_id'],'valid supplied canonical identity is preserved');

$threw=false;
try{
    homeserver_federated_v240_normalize([
      'authority_source'=>'homeserver',
      'authority_key'=>'7',
      'canonical_id'=>'fd24_'.str_repeat('0',40),
      'title'=>'Tampered',
    ],'homeserver','tasks',0);
}catch(RuntimeException $e){$threw=str_contains($e->getMessage(),'canonical identity');}
v240_assert($threw,'tampered canonical identity must be rejected');

$remote=homeserver_federated_v240_remote_registry(7);
v240_assert(is_array($remote),'remote federation registry');
v240_assert(($remote['version']??'')==='2.4','remote registry version');
v240_assert(!empty($remote['rules']['native_source_remains_authoritative']),'authority rule');
v240_assert(!empty($remote['rules']['remote_records_are_mirrors']),'mirror rule');
v240_assert(!empty($remote['rules']['no_cross_database_id_writes']),'cross-database write rule');

v240_assert(homeserver_federated_v240_remote_registry(8)===null,'unrouteable user must not get a remote registry');

echo "HomeServer v2.4 Section 1 federated data runtime: PASS\n";
