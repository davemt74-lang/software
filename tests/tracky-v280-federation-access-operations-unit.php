<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function fa_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function fa_expect(bool $v,string $m): void { if(!$v)fa_fail($m); }
function fa_same(mixed $a,mixed $e,string $m): void { if($a!==$e)fa_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$person='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

$revocations=[
 'grant:'.$home.'|'.$office.'|semantic_world_read'=>[
   'key'=>'grant:'.$home.'|'.$office.'|semantic_world_read','revision'=>5,'revocation_epoch'=>8,'reason'=>'revoked during partition','at'=>0
 ],
 'consent:'.$home.'|'.$person.'|person_recognition'=>[
   'key'=>'consent:'.$home.'|'.$person.'|person_recognition','revision'=>5,'revocation_epoch'=>9,'reason'=>'recognition revoked','at'=>0
 ],
];

$grant=tracky_v280_access_effective_grant([
 'source_site_id'=>$home,'destination_site_id'=>$office,'scope'=>'semantic_world_read',
 'status'=>'granted','revision'=>4,'reason'=>'stale mirrored grant',
],$revocations);
fa_same($grant['status'],'revoked','stale grant status');
fa_expect($grant['effective_allowed']===false,'stale grant remained allowed');
fa_expect($grant['stale_grant_suppressed']===true,'stale grant suppression missing');
fa_same($grant['revocation_epoch'],8,'grant revocation epoch');

$consent=tracky_v280_access_effective_consent([
 'site_id'=>$home,'canonical_identity_id'=>$person,'scope'=>'person_recognition',
 'status'=>'granted','revision'=>4,
],$revocations,2000);
fa_same($consent['status'],'revoked','stale recognition consent status');
fa_expect($consent['effective_allowed']===false,'revoked recognition remained allowed');
fa_expect($consent['stale_grant_suppressed']===true,'stale recognition suppression missing');

$expired=tracky_v280_access_effective_consent([
 'site_id'=>$home,'canonical_identity_id'=>$person,'scope'=>'voice_matching',
 'status'=>'granted','revision'=>3,'expires_at_ms'=>1500,
],[],2000);
fa_same($expired['status'],'expired','expired consent lifecycle');
fa_expect($expired['effective_allowed']===false,'expired consent remained allowed');

fa_same(tracky_v280_access_category_state([
 ['effective_allowed'=>true,'status'=>'granted'],
 ['effective_allowed'=>false,'status'=>'not_granted'],
]),'limited','limited category state');
fa_same(tracky_v280_access_category_state([
 ['effective_allowed'=>false,'status'=>'revoked'],
]),'revoked','revoked category state');

$cap=tracky_v280_access_public_capability();
fa_expect($cap['revocation_wins']===true,'revocation-wins capability');
fa_expect($cap['stale_remote_grant_can_restore_access']===false,'stale grant resurrection capability');
fa_expect($cap['cloud_read_only']===true,'Cloud read-only capability');
fa_expect($cap['cloud_can_grant']===false,'Cloud grant capability');
fa_expect($cap['cloud_can_revoke']===false,'Cloud revoke capability');
fa_expect($cap['cloud_can_change_consent']===false,'Cloud consent capability');
fa_expect($cap['authority_mutation']===false,'Cloud authority mutation');

echo "TRACKY_V280_FEDERATION_ACCESS_OPERATIONS_UNIT=PASS\n";
