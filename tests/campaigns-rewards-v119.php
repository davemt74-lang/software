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
require_once __DIR__.'/../includes/campaigns-rewards-v119.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v118.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v119.php';

function cr_v119_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}

$triggers=campaigns_rewards_automation_triggers_v119();
foreach(['birthday_trigger','crm_lapse','purchase_completed','referral_qualified','winner_selected','attendance_confirmed','proof_approved','loyalty_milestone'] as $key){
    cr_v119_assert(isset($triggers[$key]),"trigger {$key} is registered");
}
$audiences=campaigns_rewards_automation_audiences_v119();
foreach(['all_contacts','inactive_customers','birthday_window','saved_segment','event_contact'] as $key){
    cr_v119_assert(isset($audiences[$key]),"audience {$key} is registered");
}
cr_v119_assert(campaigns_rewards_automation_default_trigger_v119('birthday_vip')==='birthday_trigger','Birthday/VIP maps to scheduled birthday trigger');
cr_v119_assert(campaigns_rewards_automation_default_trigger_v119('win_back')==='crm_lapse','Win Back maps to CRM lapse trigger');
cr_v119_assert(campaigns_rewards_automation_default_trigger_v119('post_purchase')==='purchase_completed','Post Purchase maps to paid Commerce trigger');
cr_v119_assert(campaigns_rewards_automation_default_audience_v119('post_purchase')==='event_contact','Post Purchase defaults to event-contact audience');

$manifest=campaigns_rewards_release_manifest_v119();$inv=$manifest['invariants']??[];
cr_v119_assert(($manifest['release']??'')==='Campaigns & Rewards V1.19','release manifest identifies V1.19');
cr_v119_assert(($inv['no_second_scheduler']??false)===true&&($inv['cli_due_runner_only']??false)===true,'V1.19 does not create a second scheduler');
cr_v119_assert(($inv['event_contact_scoped']??false)===true,'event automations are contact scoped');
cr_v119_assert(($inv['automation_uses_canonical_reward_issuance']??false)===true,'automation preserves canonical Reward Issuance');
cr_v119_assert(($inv['lifecycle_recommendations_require_human_decision']??false)===true,'lifecycle recommendations require human decision');
cr_v119_assert(($inv['payment_authority_added']??true)===false&&($inv['parallel_crm_added']??true)===false,'V1.19 adds neither payment authority nor parallel CRM');

$domain=vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[];
cr_v119_assert(in_array(($domain['implementation_status']??''),['integrated-v1.19','integrated-v1.20','integrated-v1.21','integrated-v1.22'],true),'cognitive domain declares integrated V1.19');
foreach(['campaign.automation_saved','campaign.automation_executed','campaign.recommendation_proposed'] as $event){
    cr_v119_assert(in_array($event,$domain['events']??[],true),"cognitive domain registers {$event}");
}

cr_v119_assert(VP3_CAMPAIGNS_REWARDS_V119==='vp3-campaigns-rewards-v119-20260923','V1.19 runtime build is pinned');
cr_v119_assert(VP3_CAMPAIGNS_REWARDS_RELEASE_V119==='vp3-campaigns-rewards-release-v119-20260923','V1.19 release build is pinned');

echo "CAMPAIGNS_REWARDS_V119=PASS\n";
