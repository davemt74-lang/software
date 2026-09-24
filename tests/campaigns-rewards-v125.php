<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-runtime-v500.php';
require_once __DIR__.'/../includes/cognitive-domain-manifest-v2370.php';
require_once __DIR__.'/../includes/campaigns-rewards-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-types-v118.php';
require_once __DIR__.'/../includes/campaigns-rewards-platform-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-schema-v123.php';
require_once __DIR__.'/../includes/campaigns-rewards-schema-v124.php';
require_once __DIR__.'/../includes/campaigns-rewards-schema-v125.php';
require_once __DIR__.'/../includes/campaigns-rewards-domain-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-v110.php';
require_once __DIR__.'/../includes/campaigns-rewards-v118.php';
require_once __DIR__.'/../includes/campaigns-rewards-v119.php';
require_once __DIR__.'/../includes/campaigns-rewards-v120.php';
require_once __DIR__.'/../includes/campaigns-rewards-v121.php';
require_once __DIR__.'/../includes/campaigns-rewards-v122.php';
require_once __DIR__.'/../includes/campaigns-rewards-v123.php';
require_once __DIR__.'/../includes/campaigns-rewards-v124.php';
require_once __DIR__.'/../includes/campaigns-rewards-v125.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v125.php';

function cr_v125_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}

$settings=campaigns_rewards_decision_settings_v125([
 'decision_field'=>'loyalty.points','decision_operator'=>'gte','decision_value'=>'500',
 'holdout_percent'=>15,'conflict_group'=>'retention-offers','conflict_window_hours'=>72,
 'decision_priority'=>250,'offer_mode'=>'attached_best','offer_action'=>'issue','personalization_enabled'=>1,
]);
cr_v125_assert($settings['decision_field']==='loyalty.points'&&$settings['decision_operator']==='gte','decision rules normalize supported fields/operators');
cr_v125_assert($settings['holdout_percent']===15&&$settings['conflict_group']==='retention-offers','holdout and conflict controls are versionable node settings');
cr_v125_assert($settings['offer_mode']==='attached_best'&&$settings['offer_action']==='issue','dynamic offer mode/action are explicit');

cr_v125_assert(campaigns_rewards_decision_compare_v125(600,'gte','500')===true,'numeric decision comparison works');
cr_v125_assert(campaigns_rewards_decision_compare_v125(['vip','local'],'contains','vip')===true,'list membership comparison works');
cr_v125_assert(campaigns_rewards_decision_compare_v125('Phoenix VIP','contains','vip')===true,'string contains comparison is case-insensitive');
cr_v125_assert(campaigns_rewards_decision_compare_v125('', 'falsy','')===true,'falsy decision comparison works');

$tokens=[];campaigns_rewards_flatten_tokens_v125(['loyalty'=>['tier'=>'gold','points'=>750]],'',$tokens);
cr_v125_assert(($tokens['{{loyalty.tier}}']??'')==='gold'&&($tokens['{{loyalty.points}}']??'')==='750','decision context flattens to dynamic tokens');
$rendered=campaigns_rewards_render_personalized_v125('Hi {{loyalty.tier}} {{#if loyalty.points}}points{{/if}}',$tokens,['loyalty'=>['tier'=>'gold','points'=>750]]);
cr_v125_assert($rendered==='Hi gold points','dynamic tokens and conditional blocks render deterministically');

$manifest=campaigns_rewards_release_manifest_v125();$inv=$manifest['invariants']??[];
cr_v125_assert(($manifest['release']??'')==='Campaigns & Rewards V1.25','release manifest identifies V1.25');
cr_v125_assert(($inv['migration_required']??false)===true&&($inv['new_tables']??0)===1,'V1.25 adds exactly one Decision ledger table');
cr_v125_assert(($inv['decision_ledger_append_only']??false)===true&&($inv['rules_versioned_with_journey_release']??false)===true,'decision evidence is append-only and rules are release-versioned');
cr_v125_assert(($inv['dynamic_reward_uses_attached_rewards_only']??false)===true&&($inv['reward_issuance_still_canonical']??false)===true,'dynamic offers preserve Reward authority');
cr_v125_assert(($inv['agent_recommendations_no_auto_apply']??false)===true,'Agent decision recommendations remain human-reviewed');

$domain=vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[];
cr_v125_assert(in_array(($domain['implementation_status']??''),['integrated-v1.25','integrated-v1.26'],true),'cognitive domain remains compatible with V1.25 authority');
cr_v125_assert(in_array('campaign_decisions',(array)($domain['authority']??[]),true),'Campaign Decision ledger is registered as canonical authority');
foreach(['campaign.decision_recorded','campaign.decision_recommendation_proposed'] as $event)cr_v125_assert(in_array($event,$domain['events']??[],true),"cognitive domain registers {$event}");

cr_v125_assert(VP3_CAMPAIGNS_REWARDS_SCHEMA_V125==='vp3-campaigns-rewards-schema-v125-20260923','V1.25 schema build is pinned');
cr_v125_assert(VP3_CAMPAIGNS_REWARDS_V125==='vp3-campaigns-rewards-v125-20260923','V1.25 runtime build is pinned');
cr_v125_assert(VP3_CAMPAIGNS_REWARDS_RELEASE_V125==='vp3-campaigns-rewards-release-v125-20260923','V1.25 release build is pinned');
echo "CAMPAIGNS_REWARDS_V125=PASS\n";
