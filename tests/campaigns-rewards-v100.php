<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/cognitive-runtime-v500.php';
require_once __DIR__.'/../includes/cognitive-domain-manifest-v2370.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/campaigns-rewards-v100.php';
function cr_v100_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}
cr_v100_assert(campaigns_rewards_slug_v100(' Summer VIP Reward! ')==='summer-vip-reward','public slugs normalize deterministically');
$code=campaigns_rewards_claim_code_v100();
cr_v100_assert(strlen($code)===10&&preg_match('/^[A-HJ-NP-Z2-9]+$/',$code)===1,'claim codes use an unambiguous random alphabet');
cr_v100_assert(array_keys(campaigns_rewards_team_categories_v100())===['basic','merchant','both'],'Team categories are Basic, Merchant and Both');
$registry=vp3_cognitive_domain_registry_v2600();$campaign=$registry['domains']['campaigns_rewards']??[];
cr_v100_assert(isset($campaign['events'])&&in_array('campaign.conversion',$campaign['events'],true),'v26.00 retains campaign conversion event');
cr_v100_assert(in_array('reward.claimed',$campaign['events']??[],true),'v26.00 retains reward claim outcome event');
cr_v100_assert(vp3_cognitive_domain_event_class_v2600('campaigns_rewards','claim.failed')==='failure_recovery','claim failure remains a recovery event');
cr_v100_assert(vp3_cognitive_domain_event_class_v2600('campaigns_rewards','campaign.conversion')==='outcome','campaign conversion remains an outcome event');
$ref=campaigns_rewards_ref_v100('campaign','cmp-public','public');
cr_v100_assert(($ref['domain']??'')==='campaigns_rewards'&&($ref['type']??'')==='campaign','campaign references are normalized through v26.00');
cr_v100_assert(campaigns_rewards_datetime_v100('')===null,'empty scheduling boundary remains unset');
echo "CAMPAIGNS_REWARDS_V100=PASS\n";
