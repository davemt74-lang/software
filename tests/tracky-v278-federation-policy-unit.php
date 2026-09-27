<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function fp_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function fp_expect(bool $v,string $m): void { if(!$v)fp_fail($m); }
function fp_same(mixed $a,mixed $e,string $m): void { if($a!==$e)fp_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }
function fp_throws(callable $fn,string $contains,string $m): void {
  try{$fn();}catch(Throwable $e){if($contains===''||str_contains($e->getMessage(),$contains))return;fp_fail($m.' wrong error='.$e->getMessage());}
  fp_fail($m.' did not throw');
}

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$node='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$person='44444444-4444-4444-8444-444444444444';

$projection=[
 'protocol'=>'physical_federation_policy.v1','schema_version'=>1,
 'revision'=>7,'revocation_epoch'=>2,
 'governing_site_id'=>$home,
 'governing_authority_device_id'=>$node,
 'governing_authority_epoch'=>4,
 'sites'=>[[
   'site_id'=>$home,'revision'=>2,'mode'=>'household',
   'allow_federation'=>true,'allow_remote_observation'=>false,
   'default_identity_visibility'=>'consented','allowed_peer_sites'=>[$office]
 ]],
 'grants'=>[
   ['source_site_id'=>$home,'destination_site_id'=>$office,'scope'=>'semantic_world_read','status'=>'granted','revision'=>1,'reason'=>'share objects'],
   ['source_site_id'=>$home,'destination_site_id'=>$office,'scope'=>'identity_continuity_read','status'=>'granted','revision'=>1,'reason'=>'continuity'],
 ],
 'consents'=>[[
   'site_id'=>$home,'canonical_identity_id'=>$person,'scope'=>'identity_linking',
   'status'=>'granted','revision'=>2,'source'=>'user','reason'=>'approved'
 ]],
 'revocations'=>[[
   'revocation_key'=>'grant:'.$home.'|'.$office.'|remote_observation',
   'governing_site_id'=>$home,'revision'=>2,'revocation_epoch'=>2,'reason'=>'privacy'
 ]],
 'semantic_only'=>true,'summary_only'=>true,
 'authority_assignment'=>'local_site_policy',
 'cloud_role'=>'mirror_relay_enforcer',
 'cloud_can_grant'=>false,'cloud_can_revoke'=>false,'cloud_can_change_consent'=>false,
 'raw_perception'=>false
];

$n=tracky_v278_policy_normalize($projection);
fp_same($n['protocol'],'physical_federation_policy.v1','protocol');
fp_same($n['governing_site_id'],$home,'governing site');
fp_same($n['governing_authority_device_id'],$node,'governing device');
fp_same($n['governing_authority_epoch'],4,'authority epoch');
fp_same($n['revision'],7,'revision');
fp_same($n['revocation_epoch'],2,'revocation epoch');
fp_expect($n['sites'][0]['allow_federation']===true,'federation flag');
fp_same($n['sites'][0]['allowed_peer_sites'],[$office],'allowed peers');
fp_same($n['grants'][0]['scope'],'semantic_world_read','world grant');
fp_same($n['consents'][0]['scope'],'identity_linking','identity consent');
fp_same($n['revocations'][0]['revocation_epoch'],2,'revocation record');
fp_expect($n['raw_perception']===false,'raw perception boundary lost');
fp_expect($n['cloud_can_grant']===false,'Cloud grant authority leaked');
fp_expect($n['cloud_can_revoke']===false,'Cloud revoke authority leaked');
fp_expect($n['cloud_can_change_consent']===false,'Cloud consent mutation authority leaked');

$bad=$projection;$bad['cloud_can_grant']=true;
fp_throws(fn()=>tracky_v278_policy_normalize($bad),'cannot mutate it','Cloud grant authority accepted');

$bad=$projection;$bad['authority_assignment']='cloud';
fp_throws(fn()=>tracky_v278_policy_normalize($bad),'authority must remain local','Cloud policy authority accepted');

$bad=$projection;$bad['raw_perception']=true;
fp_throws(fn()=>tracky_v278_policy_normalize($bad),'raw perception is forbidden','raw perception accepted');

$bad=$projection;$bad['grants'][0]['source_site_id']=$office;
fp_throws(fn()=>tracky_v278_policy_normalize($bad),'only grants governed by its local site','foreign grant accepted');

$bad=$projection;$bad['consents'][0]['site_id']=$office;
fp_throws(fn()=>tracky_v278_policy_normalize($bad),'only local-site consent decisions','foreign consent accepted');

$bad=$projection;$bad['sites'][0]['allowed_peer_sites']=['not-a-uuid'];
fp_throws(fn()=>tracky_v278_policy_normalize($bad),'must be a UUID','invalid peer id accepted');

$bad=$projection;$bad['camera_frame']='secret';
fp_throws(fn()=>tracky_v278_policy_normalize($bad),'local-only perception data','raw camera data accepted');

$cap=tracky_v278_policy_public_capability();
fp_same($cap['cloud_role'],'mirror_relay_enforcer','Cloud role');
fp_same($cap['authority_assignment'],'local_site_policy','policy authority');
fp_expect($cap['deny_by_default']===true,'deny-by-default missing');
fp_expect($cap['cloud_can_grant']===false,'Cloud grant capability leaked');
fp_expect($cap['cloud_can_revoke']===false,'Cloud revoke capability leaked');
fp_expect($cap['cloud_can_change_consent']===false,'Cloud consent capability leaked');
fp_expect(in_array('semantic_world_read',$cap['permission_scopes'],true),'world scope missing');
fp_expect(in_array('agent_context_read',$cap['permission_scopes'],true),'Agent context scope missing');
fp_expect(in_array('identity_linking',$cap['consent_scopes'],true),'identity consent scope missing');

$caps=tracky_cloud_v270_capabilities([
 'federation_policy'=>true,
 'federation_policy_protocol'=>'physical_federation_policy.v1'
]);
fp_expect($caps['federation_policy']===true,'policy capability missing');
fp_same($caps['federation_policy_protocol'],'physical_federation_policy.v1','policy protocol');

$health=tracky_cloud_v270_health(['federation_policy'=>'configured']);
fp_same($health['federation_policy'],'configured','policy health');

echo "TRACKY_V278_FEDERATION_POLICY_UNIT=PASS\n";
