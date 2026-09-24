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
require_once __DIR__.'/../includes/campaigns-rewards-v120.php';
require_once __DIR__.'/../includes/campaigns-rewards-v121.php';
require_once __DIR__.'/../includes/campaigns-rewards-v122.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v122.php';

function cr_v122_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}

$templates=campaigns_rewards_journey_template_catalog_v122();
cr_v122_assert(isset($templates['signup_welcome'],$templates['post_purchase'],$templates['win_back'],$templates['reward_expiration'],$templates['referral_followup']),'V1.22 ships five governed journey templates');
foreach($templates as $key=>$template)cr_v122_assert(!empty($template['nodes'])&&isset($template['trigger']),"template {$key} has trigger and nodes");

$settings=campaigns_rewards_journey_optimization_settings_v122([
 'optimize_send_time'=>1,'optimization_min_samples'=>3,'frequency_cap_24h'=>2,'frequency_cap_7d'=>6,'fatigue_window_days'=>10,'fatigue_max_messages'=>8
]);
cr_v122_assert($settings['optimize_send_time']===true&&$settings['optimization_min_samples']===5,'optimization settings enforce opt-in and minimum evidence floor');
cr_v122_assert($settings['frequency_cap_24h']===2&&$settings['frequency_cap_7d']===6,'frequency caps normalize');

$manifest=campaigns_rewards_release_manifest_v122();$inv=$manifest['invariants']??[];
cr_v122_assert(($manifest['release']??'')==='Campaigns & Rewards V1.22','release manifest identifies V1.22');
cr_v122_assert(($inv['no_new_tables']??false)===true&&($inv['no_second_analytics_ledger']??false)===true&&($inv['no_autonomous_learner']??false)===true,'V1.22 preserves canonical authority');
cr_v122_assert(($inv['simulation_is_dry_run']??false)===true&&($inv['templates_create_drafts_only']??false)===true,'simulation and templates are non-authoritative');
cr_v122_assert(($inv['agent_recommendations_require_human_review']??false)===true&&($inv['recommendations_never_auto_apply']??false)===true,'Agent optimization remains human reviewed');

$domain=vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[];
cr_v122_assert(in_array(($domain['implementation_status']??''),['integrated-v1.22','integrated-v1.23','integrated-v1.24','integrated-v1.25'],true),'cognitive domain declares integrated V1.22');
foreach(['campaign.journey_template_applied','campaign.journey_simulated','campaign.journey_frequency_deferred','campaign.journey_send_time_optimized','campaign.journey_recommendation_proposed','campaign.journey_recommendation_reviewed'] as $event)
    cr_v122_assert(in_array($event,$domain['events']??[],true),"cognitive domain registers {$event}");

cr_v122_assert(VP3_CAMPAIGNS_REWARDS_V122==='vp3-campaigns-rewards-v122-20260923','V1.22 runtime build is pinned');
cr_v122_assert(VP3_CAMPAIGNS_REWARDS_RELEASE_V122==='vp3-campaigns-rewards-release-v122-20260923','V1.22 release build is pinned');
echo "CAMPAIGNS_REWARDS_V122=PASS\n";
