<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/tracky-visual-status-order-v1f6.php';
require dirname(__DIR__).'/includes/agent-visual-onboarding-v120.php';
function gate(bool $ok,string $msg): void {if(!$ok)throw new RuntimeException($msg);}
function choose(?string $status,mixed $revision,string $stored='',int $previous=0): array {
    return tracky_v1f6_decide($status,$revision,$stored,$previous);
}
$active='owner_attributed_unverified';
gate(choose($active,1)['accepted'],'First ordered consent may be accepted');
gate(choose('revoked',2,$active,1)['accepted'],'Newer revocation wins');
gate(!choose($active,1,'revoked',2)['accepted'],'Old active request cannot undo revocation');
gate(choose($active,1,'revoked',2)['reason']==='stale_revision','Stale requests classified');
gate(!choose($active,2,'revoked',2)['accepted'],'Same revision cannot change consent');
gate(choose($active,2,'revoked',2)['reason']==='revision_conflict','Conflict classified');
gate(choose('revoked',2,'revoked',2)['accepted'],'Identical revocation retry is idempotent');
gate(choose($active,3,'revoked',2)['accepted'],'New consent requires a higher revision');
gate(!choose('revoked',2,$active,3)['accepted'],'Delayed old revocation cannot undo new consent');
gate(!choose($active,null,'',0)['accepted'],'Legacy unversioned active denied');
gate(choose('revoked',null,'',0)['accepted'],'Legacy revocation can close pre-upgrade status');
gate(!choose($active,null,'revoked',1)['accepted'],'Legacy active cannot override pre-upgrade revocation');
gate(!choose('revoked',null,$active,3)['accepted'],'Legacy revoke cannot overwrite versioned newer consent');
gate(choose(null,null,'revoked',3)['reason']==='not_reported','Generic site heartbeat cannot replace ordered state');
foreach([0,-1,true,1.1,'4',2147483648] as $bad){
    try {choose($active,$bad);throw new LogicException('Bad revision accepted');}
    catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
}
try {choose(null,4);throw new LogicException('Unpaired revision accepted');}
catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
$now=strtotime('2026-10-02T12:00:00Z');
$site=['site_id'=>'hs-order-01','device_id'=>'hs-order-01',
       'last_seen_at'=>'2026-10-02T11:59:30Z',
       'visual_owner_reported_at'=>'2026-10-02T11:59:00Z',
       'health'=>['visual_owner_association'=>$active]];
$state=vp3_visual_onboarding_association_v1f2([$site],$now);
gate($state['state']===$active,'Fresh ordered visual evidence visible');
gate($state['reported_at']===$site['visual_owner_reported_at'],'Report uses visual accepted time');
$stale=$site;$stale['visual_owner_reported_at']='2026-10-02T11:40:00Z';
gate(vp3_visual_onboarding_association_v1f2([$stale],$now)['state']==='unreported',
     'Unrelated fresh heartbeat cannot revive stale visual consent');
$missing=$site;$missing['visual_owner_reported_at']=null;
gate(vp3_visual_onboarding_association_v1f2([$missing],$now)['state']==='unreported',
     'Legacy unversioned site row with no visual timestamp is unreported');
$revoked=$site;$revoked['health']['visual_owner_association']='revoked';
gate(vp3_visual_onboarding_association_v1f2([$revoked],$now)['state']==='revoked',
     'Accepted current revocation is visible but never verified identity');
$skew=$site;$skew['visual_owner_reported_at']='2026-10-02T12:03:00Z';
gate(vp3_visual_onboarding_association_v1f2([$skew],$now)['state']==='unreported',
     'Future-dated visual status is never accepted as fresh');
echo "TRACKY_VISUAL_ORDER_V1F6: versioned revocation, stale/replay, legacy fail-close and isolated freshness PASS\n";
