<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-runtime-v500.php';
require_once __DIR__.'/../includes/cognitive-domain-manifest-v2370.php';
require_once __DIR__.'/../includes/campaigns-rewards-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-types-v118.php';
require_once __DIR__.'/../includes/campaigns-rewards-platform-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-schema-v123.php';
require_once __DIR__.'/../includes/campaigns-rewards-schema-v124.php';
require_once __DIR__.'/../includes/campaigns-rewards-domain-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-v110.php';
require_once __DIR__.'/../includes/campaigns-rewards-v118.php';
require_once __DIR__.'/../includes/campaigns-rewards-v119.php';
require_once __DIR__.'/../includes/campaigns-rewards-v120.php';
require_once __DIR__.'/../includes/campaigns-rewards-v121.php';
require_once __DIR__.'/../includes/campaigns-rewards-v122.php';
require_once __DIR__.'/../includes/campaigns-rewards-v123.php';
require_once __DIR__.'/../includes/campaigns-rewards-v124.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v124.php';

function cr_v124_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}

$manifest=campaigns_rewards_release_manifest_v124();$inv=$manifest['invariants']??[];
cr_v124_assert(($manifest['release']??'')==='Campaigns & Rewards V1.24','release manifest identifies V1.24');
cr_v124_assert(($inv['migration_required']??false)===true&&($inv['new_tables']??0)===1,'V1.24 adds exactly one canonical Journey Instance table');
cr_v124_assert(($inv['no_second_delivery_queue']??false)===true&&($inv['no_second_event_ledger']??false)===true,'V1.24 preserves canonical delivery and event ledgers');
cr_v124_assert(($inv['instances_pin_journey_version']??false)===true&&($inv['existing_instances_remain_pinned']??false)===true,'instance release pinning is explicit');
cr_v124_assert(($inv['pause_resume_cancel_human_only']??false)===true&&($inv['skip_retry_human_only']??false)===true&&($inv['compatible_step_move_human_only']??false)===true,'operator recovery remains human authorized');
cr_v124_assert(($inv['controlled_new_entry_rollout']??false)===true,'controlled new-entry rollout is declared');
cr_v124_assert(($inv['transcription_saved_source_fix_included']??false)===true,'release includes saved-transcript Analyze repair');

$domain=vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[];
cr_v124_assert(($domain['implementation_status']??'')==='integrated-v1.24','cognitive domain declares integrated V1.24');
cr_v124_assert(in_array('campaign_journey_instances',(array)($domain['authority']??[]),true),'Journey Instance is canonical Campaign authority');
foreach([
 'campaign.journey_instance_pause','campaign.journey_instance_resume','campaign.journey_instance_cancel',
 'campaign.journey_instance_node_skipped','campaign.journey_instance_step_moved','campaign.journey_emergency_stopped','campaign.journey_incident_proposed'
] as $event)cr_v124_assert(in_array($event,$domain['events']??[],true),"cognitive domain registers {$event}");

cr_v124_assert(VP3_CAMPAIGNS_REWARDS_SCHEMA_V124==='vp3-campaigns-rewards-schema-v124-20260923','V1.24 schema build is pinned');
cr_v124_assert(VP3_CAMPAIGNS_REWARDS_V124==='vp3-campaigns-rewards-v124-20260923','V1.24 runtime build is pinned');
cr_v124_assert(VP3_CAMPAIGNS_REWARDS_RELEASE_V124==='vp3-campaigns-rewards-release-v124-20260923','V1.24 release build is pinned');
echo "CAMPAIGNS_REWARDS_V124=PASS\n";
