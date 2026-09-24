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
require_once __DIR__.'/../includes/campaigns-rewards-schema-v126.php';
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
require_once __DIR__.'/../includes/campaigns-rewards-v126.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v126.php';

function cr_v126_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}

cr_v126_assert(campaigns_rewards_v126_rate(2,8)===25.0,'observed rate helper is deterministic');
cr_v126_assert(campaigns_rewards_v126_rate(0,0)===0.0,'observed rate helper safely handles empty samples');
cr_v126_assert(campaigns_rewards_outcome_types_v126()===['viewed','converted','reward_claimed','journey_completed'],'verified outcome types are explicit and bounded');

$manifest=campaigns_rewards_release_manifest_v126();$inv=$manifest['invariants']??[];
cr_v126_assert(($manifest['release']??'')==='Campaigns & Rewards V1.26','release manifest identifies V1.26');
cr_v126_assert(($inv['migration_required']??false)===true&&($inv['new_tables']??0)===2,'V1.26 adds exactly two evidence tables');
cr_v126_assert(($inv['decision_outcomes_append_only']??false)===true&&($inv['optimization_snapshots_append_only']??false)===true,'outcomes and optimization snapshots are append-only');
cr_v126_assert(($inv['no_parallel_conversion_ledger']??false)===true&&($inv['outcomes_derive_from_canonical_records']??false)===true,'verified outcomes remain derived evidence over canonical authorities');
cr_v126_assert(($inv['lifecycle_is_observed_not_overwritten']??false)===true&&($inv['fatigue_is_diagnostic_only']??false)===true,'lifecycle and fatigue intelligence cannot mutate CRM or delivery policy');
cr_v126_assert(($inv['recommendations_require_human_review']??false)===true&&($inv['agent_auto_apply']??true)===false,'optimization recommendations stay human-governed');
cr_v126_assert(($inv['no_autonomous_campaign_mutation']??false)===true,'V1.26 cannot autonomously mutate live Campaign state');

$domain=vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[];
cr_v126_assert(($domain['implementation_status']??'')==='integrated-v1.26','cognitive domain declares integrated V1.26');
foreach(['campaign_decision_outcomes','campaign_optimization_snapshots'] as $table)cr_v126_assert(in_array($table,(array)($domain['authority']??[]),true),"cognitive domain registers {$table}");
foreach(['campaign.decision_outcome_recorded','campaign.optimization_snapshot_recorded','campaign.optimization_recommendation_proposed'] as $event)cr_v126_assert(in_array($event,$domain['events']??[],true),"cognitive domain registers {$event}");

cr_v126_assert(VP3_CAMPAIGNS_REWARDS_SCHEMA_V126==='vp3-campaigns-rewards-schema-v126-20260923','V1.26 schema build is pinned');
cr_v126_assert(VP3_CAMPAIGNS_REWARDS_V126==='vp3-campaigns-rewards-v126-20260923','V1.26 runtime build is pinned');
cr_v126_assert(VP3_CAMPAIGNS_REWARDS_RELEASE_V126==='vp3-campaigns-rewards-release-v126-20260923','V1.26 release build is pinned');
echo "CAMPAIGNS_REWARDS_V126=PASS\n";
