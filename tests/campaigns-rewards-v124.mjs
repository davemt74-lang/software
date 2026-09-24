import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const schema=read('includes/campaigns-rewards-schema-v124.php');
const runtime=read('includes/campaigns-rewards-v124.php');
const v123=read('includes/campaigns-rewards-v123.php');
const release=read('includes/campaigns-rewards-release-v124.php');
const upgrade=read('upgrade.php');
const setup=read('setup.php');
const bootstrap=read('includes/bootstrap.php');
const campaigns=read('campaigns.php');
const registry=read('includes/cognitive-domain-registry-v2600.php');
const cron=read('cron/campaigns-rewards-v124.php');
const migration=read('upgrade-campaigns-rewards-v124.sql');
const transcription=read('includes/transcription-workflow-config.php');
const transcriptionPrompt=read('includes/transcription-apps-wave2.php');
const docs=read('docs/CAMPAIGNS_REWARDS_V124.md');
const workflow=read('.github/workflows/team-workspaces-v350.yml');
const packageFlow=read('.github/workflows/production-deploy-package.yml');

const checks=[
 ['V1.24 adds one canonical Journey Instance table',schema.includes('CREATE TABLE IF NOT EXISTS campaign_journey_instances')&&release.includes("'new_tables'=>1")],
 ['instance schema pins Campaign Journey Version CRM contact trigger and deterministic key',
  ['campaign_id','journey_id','journey_version_id','contact_id','instance_key','trigger_event','status','current_step_key'].every(x=>schema.includes(x))],
 ['migration has non-null auto increment primary key and no reserved rank column',
  migration.includes('id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY')&&!/\brank\s+INT/i.test(migration)],
 ['upgrade and fresh setup install the V1.24 instance authority',
  upgrade.includes('campaigns_rewards_journey_operations_ensure_schema_v124($pdo)')&&upgrade.includes('campaigns_rewards_journey_operations_schema_ready_v124()')&&setup.includes('campaigns_rewards_journey_operations_ensure_schema_v124($pdo)')],
 ['V1.23 deliveries create and reference canonical V1.24 instances when schema is ready',
  v123.includes('campaigns_rewards_journey_instance_start_v124')&&v123.includes("'journey_instance_id'=>$journeyInstanceId")],
 ['pre-V1.24 V1.23 deliveries remain operable through instance key compatibility',
  runtime.includes('campaigns_rewards_delivery_belongs_to_instance_v124')&&runtime.includes("journey_instance_key")],
 ['instance dispatch stops paused and cancelled instances without creating another queue',
  runtime.includes("['paused','cancelled']")&&runtime.includes("'instance_'.$instance['status']")&&release.includes("'no_second_delivery_queue'=>true")],
 ['human operations expose pause resume cancel retry and skip',
  runtime.includes("['pause','resume','cancel']")&&runtime.includes('campaigns_rewards_instance_retry_delivery_v124')&&runtime.includes('campaigns_rewards_instance_skip_delivery_v124')&&runtime.includes("'campaigns.publish'")],
 ['compatible step move stays inside pinned Journey Version',
  runtime.includes('campaigns_rewards_instance_move_step_v124')&&runtime.includes("campaigns_rewards_journey_version_v123($pdo,(int)$instance['journey_version_id'])")&&runtime.includes('Target step does not exist in the pinned Journey Version')],
 ['emergency stop separates new-enrollment pause from in-flight policy',
  runtime.includes('campaigns_rewards_journey_emergency_stop_v124')&&runtime.includes("['continue','pause','cancel']")&&runtime.includes("enrollment_status='paused'")],
 ['contact timeline derives from canonical deliveries and remains read-only',
  runtime.includes('campaigns_rewards_journey_instance_timeline_v124')&&runtime.includes('campaign_deliveries')&&!runtime.slice(runtime.indexOf('function campaigns_rewards_journey_instance_timeline_v124'),runtime.indexOf('function campaigns_rewards_journey_incidents_v124')).includes('UPDATE campaign_deliveries')],
 ['operational SLA detection covers stale queue retry and dead-letter signals',
  runtime.includes("'queue_age'")&&runtime.includes("'retry_age'")&&runtime.includes("'dead_letter_spike'")&&runtime.includes("'long_pause'")],
 ['Agent operations incidents are proposals with no auto apply',
  runtime.includes("'source'=>'v124_operations'")&&runtime.includes("'auto_apply'=>false")&&runtime.includes("'campaign.journey_incident_proposed'")],
 ['controlled rollout is publication metadata and affects new-entry version selection only',
  v123.includes("'rollout_percent'")&&runtime.includes('campaigns_rewards_journey_entry_version_v124')&&runtime.includes("action IN ('publish','scheduled_publish','rollback')")],
 ['in-flight migration synchronizes canonical instance version without changing historical sent rows',
  v123.includes('campaign_journey_instances SET journey_version_id=?')&&release.includes("'sent_history_immutable'=>true")],
 ['Campaign workspace exposes operations incidents timeline recovery move emergency stop and rollout',
  campaigns.includes('Live Journey Operations & Recovery')&&campaigns.includes('Contact Journey Timeline')&&campaigns.includes('journey_instance_retry')&&campaigns.includes('journey_instance_move')&&campaigns.includes('journey_emergency_stop')&&campaigns.includes('rollout_percent')],
 ['cognitive registry declares V1.24 instance authority and human-operation events',
  (registry.includes("'implementation_status'=>'integrated-v1.24'")||(registry.includes("'implementation_status'=>'integrated-v1.25'")||registry.includes("'implementation_status'=>'integrated-v1.26'")))&&registry.includes("'campaign_journey_instances'")&&registry.includes("'campaign.journey_emergency_stopped'")],
 ['bootstrap loads V1.24 schema runtime and release after their V1.23 predecessors',
  bootstrap.indexOf("campaigns-rewards-schema-v123.php")<bootstrap.indexOf("campaigns-rewards-schema-v124.php")&&bootstrap.indexOf("campaigns-rewards-v123.php")<bootstrap.indexOf("campaigns-rewards-v124.php")&&bootstrap.indexOf("campaigns-rewards-release-v123.php")<bootstrap.indexOf("campaigns-rewards-release-v124.php")],
 ['V1.24 runner is CLI only and requires operations schema readiness',
  cron.includes("PHP_SAPI!=='cli'")&&cron.includes('campaigns_rewards_journey_operations_schema_ready_v124')&&cron.includes('campaigns_rewards_run_due_v124')],
 ['transcription manual Analyze accepts saved transcript when page-analysis cache is absent',
  transcription.includes("'source'=>'saved_transcript'")&&!transcription.includes('There is not enough saved transcript analysis to run the selected plugins yet.')],
 ['transcription prompt explicitly treats saved raw transcript as primary transcript evidence',
  transcriptionPrompt.includes('LIVE TRANSCRIPT PAGE EVIDENCE')&&transcriptionPrompt.includes('saved raw transcript text')],
 ['consolidated CI runs V1.24 and transcription saved-source contracts',
  workflow.includes('Campaigns & Rewards V1.24 contract')&&workflow.includes('tests/campaigns-rewards-v124.mjs')&&workflow.includes('tests/transcription-analysis-source-v308-contract.mjs')],
 ['production package identifies V1.24 and retains migration operations runtime cron and transcription fix',
  (packageFlow.includes('"Campaigns & Rewards V1.24"')||packageFlow.includes('"Campaigns & Rewards V1.25"'))&&packageFlow.includes('campaigns-rewards-schema-v124.php')&&packageFlow.includes('campaigns-rewards-v124.php')&&packageFlow.includes('cron/campaigns-rewards-v124.php')&&packageFlow.includes('upgrade-campaigns-rewards-v124.sql')&&packageFlow.includes('transcription-workflow-config.php')],
 ['documentation covers canonical instance authority controlled rollout operations and transcription repair',
  docs.includes('campaign_journey_instances')&&docs.includes('Controlled rollout of new Journey releases')&&docs.includes('Contact Journey Timeline')&&docs.includes('Transcription Analyze fix')],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.24 contract: '+checks.length+'/'+checks.length+' passed');
