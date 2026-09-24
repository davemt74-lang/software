<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-runtime-v500.php';
require_once __DIR__.'/../includes/cognitive-domain-manifest-v2370.php';
require_once __DIR__.'/../includes/campaigns-rewards-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-types-v118.php';
require_once __DIR__.'/../includes/campaigns-rewards-platform-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-domain-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-v110.php';
require_once __DIR__.'/../includes/campaigns-rewards-v118.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v118.php';

function cr_v118_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}
";}

$catalog=campaigns_rewards_campaign_type_catalog_v118();
cr_v118_assert(count($catalog)>=20,'expanded Campaign Type registry has at least twenty modular types');
cr_v118_assert(isset($catalog['signup'],$catalog['contest_giveaway'],$catalog['qr_reward_drop'],$catalog['referral'],$catalog['birthday_vip'],$catalog['local_event'],$catalog['training_education']),'core Microgifter-derived Campaign Types are registered');

$signup=$catalog['signup'];
cr_v118_assert(($signup['public_action']??'')==='newsletter_signup','Signup Reward uses newsletter signup public action');
cr_v118_assert(($signup['marketing']??'')==='required','Signup Reward requires marketing consent');
cr_v118_assert(($signup['reward_timing']??'')==='immediate'&&!empty($signup['requires_reward']),'Signup Reward immediately issues an attached Reward');

$event=$catalog['local_event'];
cr_v118_assert(($event['reward_timing']??'')==='after_verification','Local Event Reward is attendance/verification gated');
$training=$catalog['training_education'];
cr_v118_assert(($training['reward_timing']??'')==='after_verification','Training Reward is completion/verification gated');

$manifest=campaigns_rewards_release_manifest_v118();$inv=$manifest['invariants']??[];
cr_v118_assert(($manifest['release']??'')==='Campaigns & Rewards V1.18','release manifest identifies V1.18');
cr_v118_assert(($inv['campaign_types_modular_extensible']??false)===true,'Campaign Types remain modular and extensible');
cr_v118_assert(($inv['signup_reward_writes_core_crm']??false)===true&&($inv['parallel_crm_added']??true)===false,'Signup Reward writes Core CRM without a parallel CRM');
cr_v118_assert(($inv['campaign_activity_uses_existing_cognitive_domain']??false)===true&&($inv['parallel_agent_brain_added']??true)===false,'Campaign activity uses the existing Agent Brain/cognitive domain');
cr_v118_assert(($inv['payment_authority_added']??true)===false,'Campaigns does not gain payment authority');

$domain=vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[];
cr_v118_assert(in_array(($domain['implementation_status']??''),['integrated-v1.18','integrated-v1.19','integrated-v1.20','integrated-v1.21'],true),'cognitive domain is at least integrated V1.18');
foreach(['campaign.landing_viewed','campaign.newsletter_signup','campaign.referral_signup','campaign.event_rsvp','campaign.rewards_updated'] as $eventName){
    cr_v118_assert(in_array($eventName,$domain['events']??[],true),"cognitive domain registers {$eventName}");
}

cr_v118_assert(VP3_CAMPAIGNS_REWARDS_V118==='vp3-campaigns-rewards-v118-20260923','V1.18 runtime build is pinned');
cr_v118_assert(VP3_CAMPAIGNS_REWARDS_RELEASE_V118==='vp3-campaigns-rewards-release-v118-20260923','V1.18 release build is pinned');

echo "CAMPAIGNS_REWARDS_V118=PASS
";
