<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/cognitive-runtime-v500.php';
require_once __DIR__.'/../includes/cognitive-domain-manifest-v2370.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/campaigns-rewards-platform-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-domain-v100.php';

function cr_v100_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}

cr_v100_assert(campaigns_rewards_slug_v100(' Summer VIP Reward! ')==='summer-vip-reward','public slugs normalize deterministically');
$code=campaigns_rewards_claim_code_v100();
cr_v100_assert(strlen($code)===10&&preg_match('/^[A-HJ-NP-Z2-9]+$/',$code)===1,'legacy display codes use the unambiguous random alphabet');
$secret=campaigns_rewards_secret_v100();
cr_v100_assert(strlen($secret)>=22&&campaigns_rewards_secret_hash_v100($secret)===hash('sha256',$secret),'Reward and Merchant secrets are hashable one-way credentials');
cr_v100_assert(array_keys(campaigns_rewards_team_categories_v100())===['basic','merchant','both'],'Team categories remain Basic Merchant and Both');

$registry=vp3_cognitive_domain_registry_v2600();$campaign=$registry['domains']['campaigns_rewards']??[];
cr_v100_assert(str_starts_with((string)($campaign['implementation_status']??''),'integrated-v1.'),'v26.00 declares Campaigns & Rewards integrated V1 family');
cr_v100_assert(in_array('campaign.conversion_attributed',$campaign['events']??[],true),'v26.00 carries canonical Campaign conversion attribution');
cr_v100_assert(in_array('claim.accepted',$campaign['events']??[],true),'v26.00 carries accepted Claim outcomes');
cr_v100_assert(vp3_cognitive_domain_event_class_v2600('campaigns_rewards','claim.rejected')==='failure_recovery','Claim rejection remains a recovery event');
cr_v100_assert(vp3_cognitive_domain_event_class_v2600('campaigns_rewards','campaign.conversion_attributed')==='outcome','Campaign conversion attribution remains an outcome event');
cr_v100_assert(($campaign['event_ingress']??'')==='agent_event_inbox_v1920','Campaigns uses canonical v19.20 event ingress');
cr_v100_assert(!empty($campaign['presentation_firewall_required']),'Campaigns requires the v25.90 presentation firewall');
cr_v100_assert(in_array('reward_issuance',$campaign['objects']??[],true)&&in_array('claim_code',$campaign['objects']??[],true),'V26 registry exposes canonical Reward Issuance and Claim Code objects');

$ref=campaigns_rewards_ref_v100('campaign','cmp-public','public');
cr_v100_assert(($ref['domain']??'')==='campaigns_rewards'&&($ref['type']??'')==='campaign','Campaign references normalize through v26.00');
cr_v100_assert(campaigns_rewards_datetime_v100('')===null,'empty scheduling boundary remains unset');
$modules=array_column(vp3_cognitive_registry_public_v500()['modules']??[],'module');
cr_v100_assert(in_array('campaigns_rewards',$modules,true),'Campaigns & Rewards remains registered in canonical v5.00 cognitive modules');
echo "CAMPAIGNS_REWARDS_V100=PASS\n";
