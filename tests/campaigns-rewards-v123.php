<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-runtime-v500.php';
require_once __DIR__.'/../includes/cognitive-domain-manifest-v2370.php';
require_once __DIR__.'/../includes/campaigns-rewards-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-types-v118.php';
require_once __DIR__.'/../includes/campaigns-rewards-platform-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-schema-v123.php';
require_once __DIR__.'/../includes/campaigns-rewards-domain-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-v110.php';
require_once __DIR__.'/../includes/campaigns-rewards-v118.php';
require_once __DIR__.'/../includes/campaigns-rewards-v119.php';
require_once __DIR__.'/../includes/campaigns-rewards-v120.php';
require_once __DIR__.'/../includes/campaigns-rewards-v121.php';
require_once __DIR__.'/../includes/campaigns-rewards-v122.php';
require_once __DIR__.'/../includes/campaigns-rewards-v123.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v123.php';

function cr_v123_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}

$graph=['schema'=>'campaign-journey-graph-v1','journey_key'=>'welcome','nodes'=>[
 ['message_id'=>11,'message_key'=>'welcome--entry--default','message_version_no'=>2,'channel'=>'email','subject'=>'Hi','template'=>['kind'=>'journey_node','journey_key'=>'welcome','step_key'=>'entry','variant_key'=>'default','variant_weight'=>100,'node_type'=>'message','step_order'=>10,'entry_node'=>true,'trigger_event'=>'newsletter_signup','next_step_key'=>'done']],
 ['message_id'=>12,'message_key'=>'welcome--done--default','message_version_no'=>1,'channel'=>'orchestration','subject'=>'Exit','template'=>['kind'=>'journey_node','journey_key'=>'welcome','step_key'=>'done','variant_key'=>'default','variant_weight'=>100,'node_type'=>'exit','step_order'=>20,'entry_node'=>false,'trigger_event'=>'newsletter_signup']],
]];
$groups=campaigns_rewards_graph_groups_v123($graph);
cr_v123_assert(isset($groups['entry'],$groups['done'])&&count($groups['entry'])===1,'release graph groups exact pinned message versions by logical step');
$node=campaigns_rewards_graph_hydrate_node_v123($graph['nodes'][0]);
cr_v123_assert($node['id']===11&&$node['version_no']===2&&$node['template']['next_step_key']==='done','release snapshot hydrates an executable pinned node');

$diff=campaigns_rewards_journey_diff_v123(['graph'=>$graph],['graph'=>array_merge($graph,['nodes'=>[
 array_merge($graph['nodes'][0],['message_id'=>21,'message_version_no'=>3]),$graph['nodes'][1],
]])]);
cr_v123_assert($diff['changed']===['welcome--entry--default']&&$diff['has_changes']===true,'draft/live comparison detects exact node-version changes');

$manifest=campaigns_rewards_release_manifest_v123();$inv=$manifest['invariants']??[];
cr_v123_assert(($manifest['release']??'')==='Campaigns & Rewards V1.23','release manifest identifies V1.23');
cr_v123_assert(($inv['migration_required']??false)===true&&($inv['new_tables']??0)===3,'V1.23 explicitly requires the three-table release migration');
cr_v123_assert(($inv['published_versions_immutable']??false)===true&&($inv['atomic_graph_publish']??false)===true,'immutable atomic release authority is explicit');
cr_v123_assert(($inv['inflight_version_pinning']??false)===true&&($inv['new_entrants_use_current_published']??false)===true,'in-flight and new-entry version rules are explicit');
cr_v123_assert(($inv['rollback_creates_new_release']??false)===true&&($inv['agent_cannot_publish_or_rollback']??false)===true,'rollback and Agent governance are explicit');

$domain=vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[];
cr_v123_assert(in_array(($domain['implementation_status']??''),['integrated-v1.23','integrated-v1.24','integrated-v1.25'],true),'cognitive domain declares integrated V1.23');
foreach(['campaign.journey_draft_changed','campaign.journey_release_validated','campaign.journey_published','campaign.journey_publish_scheduled','campaign.journey_version_started'] as $event)
    cr_v123_assert(in_array($event,$domain['events']??[],true),"cognitive domain registers {$event}");

cr_v123_assert(VP3_CAMPAIGNS_REWARDS_SCHEMA_V123==='vp3-campaigns-rewards-schema-v123-20260923','V1.23 schema build is pinned');
cr_v123_assert(VP3_CAMPAIGNS_REWARDS_V123==='vp3-campaigns-rewards-v123-20260923','V1.23 runtime build is pinned');
cr_v123_assert(VP3_CAMPAIGNS_REWARDS_RELEASE_V123==='vp3-campaigns-rewards-release-v123-20260923','V1.23 release build is pinned');
echo "CAMPAIGNS_REWARDS_V123=PASS\n";
