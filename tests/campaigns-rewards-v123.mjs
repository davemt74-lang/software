import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const schema=read('includes/campaigns-rewards-schema-v123.php');
const runtime=read('includes/campaigns-rewards-v123.php');
const release=read('includes/campaigns-rewards-release-v123.php');
const migration=read('upgrade-campaigns-rewards-v123.sql');
const upgrade=read('upgrade.php');
const setup=read('setup.php');
const bootstrap=read('includes/bootstrap.php');
const v118=read('includes/campaigns-rewards-v118.php');
const v119=read('includes/campaigns-rewards-v119.php');
const campaigns=read('campaigns.php');
const builder=read('campaign-journey-builder-v123.js');
const builderCss=read('campaign-journey-builder-v123.css');
const registry=read('includes/cognitive-domain-registry-v2600.php');
const cron=read('cron/campaigns-rewards-v123.php');
const docs=read('docs/CAMPAIGNS_REWARDS_V123.md');
const workflow=read('.github/workflows/team-workspaces-v350.yml');
const packageFlow=read('.github/workflows/production-deploy-package.yml');

const checks=[
 ['V1.23 owns exactly three new release tables',
  ['campaign_journeys','campaign_journey_versions','campaign_journey_publications'].every(t=>schema.includes('CREATE TABLE IF NOT EXISTS '+t))&&release.includes("'new_tables'=>3")],
 ['SQL migration uses non-null auto-increment primary keys and avoids reserved rank columns',
  migration.includes('id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY')&&!/\brank\s+INT/i.test(migration)],
 ['upgrade and fresh setup both install the V1.23 release schema',
  upgrade.includes('campaigns_rewards_journey_release_ensure_schema_v123($pdo)')&&upgrade.includes('campaigns_rewards_journey_release_schema_ready_v123()')&&setup.includes('campaigns_rewards_journey_release_ensure_schema_v123($pdo)')],
 ['legacy graph adoption is idempotent and backfills published/draft snapshots',
  schema.includes('campaigns_rewards_journey_release_backfill_v123')&&schema.includes("if($find->fetchColumn())continue")&&schema.includes("'V1.23 legacy journey adoption'")&&schema.includes("'Migrated V1.22 draft'")],
 ['node edits write new draft message versions without superseding published nodes',
  runtime.includes("VALUES (?,?,?,?,?,?,'draft',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")&&runtime.includes("if($previous&&(string)$previous['status']==='draft')")&&!runtime.includes("UPDATE campaign_messages SET status='superseded',updated_at=UTC_TIMESTAMP() WHERE id=? AND status<>'superseded'")],
 ['draft and live are separate first-class Journey pointers',
  schema.includes('current_draft_version_id')&&schema.includes('current_published_version_id')&&runtime.includes('campaigns_rewards_ensure_draft_version_v123')],
 ['atomic publish validates full graph and advances the Journey pointer inside one transaction',
  runtime.includes('campaigns_rewards_validate_graph_v123')&&runtime.includes('$pdo->beginTransaction()')&&runtime.includes("SET status='published'")&&runtime.includes('current_published_version_id=?')],
 ['graph validation covers entries targets A/B reachability cycles and exit paths',
  runtime.includes("'entry_count'")&&runtime.includes("'missing_target'")&&runtime.includes("'variant_weight_total'")&&runtime.includes("'unreachable_node'")&&runtime.includes("'cycle_detected'")&&runtime.includes("'no_exit_path'")],
 ['provider readiness is part of release preflight without becoming provider authority',
  runtime.includes('campaigns_rewards_provider_readiness_v123')&&runtime.includes("'provider_not_ready'")&&release.includes("'provider_readiness_is_preflight_warning'=>true")],
 ['new Journey instances pin immutable journey/version identifiers',
  runtime.includes("'runtime'=>'v1.23'")&&runtime.includes("'journey_version_id'=>(int)$version['id']")&&runtime.includes("'journey_version_no'=>(int)$version['version_no']")],
 ['next-step routing resolves from the pinned Journey Version graph',
  runtime.includes('campaigns_rewards_enqueue_next_step_v123')&&runtime.includes("campaigns_rewards_journey_version_v123($pdo,max(0,(int)($delivery['metadata']['journey_version_id']")],
 ['public participation and V1.19 automation prefer V1.23 only when schema is ready',
  v118.includes('campaigns_rewards_journey_enqueue_v123')&&v119.includes('campaigns_rewards_journey_enqueue_v123')&&v118.includes('campaigns_rewards_journey_release_schema_ready_v123($pdo)')&&v119.includes('campaigns_rewards_journey_release_schema_ready_v123($pdo)')],
 ['publish supports continue migrate-pending and exit-remaining in-flight policies',
  runtime.includes("['continue','migrate_pending','exit_remaining']")&&runtime.includes('campaigns_rewards_inflight_plan_v123')&&runtime.includes("'publisher_exit_remaining'")],
 ['safe migration preflights logical step availability before pending deliveries mutate',
  runtime.includes("Cannot migrate in-flight step")&&runtime.indexOf('campaigns_rewards_inflight_plan_v123')<runtime.indexOf('$pdo->beginTransaction()')],
 ['scheduled publishing freezes a release and CLI publishes due versions before automation',
  runtime.includes("status='scheduled'")&&runtime.includes('campaigns_rewards_publish_due_v123')&&runtime.indexOf('$publishing=campaigns_rewards_publish_due_v123')<runtime.indexOf('$automation=')],
 ['rollback creates a new version rather than moving the live pointer backward',
  runtime.includes('function campaigns_rewards_rollback_journey_v123')&&runtime.includes("VALUES (?,?,?,'draft'")&&release.includes("'rollback_creates_new_release'=>true")],
 ['pause enrollment is independent from in-flight execution',
  runtime.includes('campaigns_rewards_set_journey_enrollment_v123')&&schema.includes("enrollment_status VARCHAR(30) NOT NULL DEFAULT 'open'")&&release.includes("'pause_new_enrollment_does_not_stop_inflight'=>true")],
 ['archive and clone preserve historical release identity',
  runtime.includes('campaigns_rewards_archive_journey_v123')&&runtime.includes('campaigns_rewards_clone_journey_v123')&&runtime.includes("'Cloned journey'")],
 ['visual builder renders canonical snapshot nodes and normal/true/false connectors',
  campaigns.includes('data-journey-builder-v123')&&builder.includes("node.node_type === 'decision'")&&builder.includes("link(from,targetFor(node.true_next_step_key),'true'")&&builderCss.includes('.cr-journey-link-v123')],
 ['Campaign release console exposes Draft/Live diff validation publish scheduling rollback enrollment and health',
  campaigns.includes('Draft → Live comparison')&&campaigns.includes('Pre-publish suite')&&campaigns.includes('Publish / schedule release')&&campaigns.includes('Rollback as new release')&&campaigns.includes('Set enrollment')&&campaigns.includes('Live health')],
 ['direct managed-node activation is blocked in favor of atomic publish',
  campaigns.includes('V1.23 journey nodes are activated only through atomic Journey Publish')&&campaigns.includes('Draft-managed by Journey Publish')],
 ['release simulation remains delivery-free and version-specific',
  runtime.includes('campaigns_rewards_simulate_version_v123')&&release.includes("'visual_builder_is_release_snapshot_driven'=>true")&&docs.includes('inserting Campaign deliveries')],
 ['release health derives from canonical campaign_deliveries',
  runtime.includes('campaigns_rewards_journey_release_health_v123')&&runtime.includes('FROM campaign_deliveries')&&release.includes("'release_health_derived_from_canonical_deliveries'=>true")],
 ['Cognitive domain advances to V1.23 release lifecycle',
  (registry.includes("'implementation_status'=>'integrated-v1.23'")||(registry.includes("'implementation_status'=>'integrated-v1.24'")||registry.includes("'implementation_status'=>'integrated-v1.25'")))&&registry.includes("'campaign.journey_published'")&&registry.includes("'campaign.journey_version_started'")],
 ['bootstrap loads schema before V1.23 runtime and release after runtime',
  bootstrap.indexOf("campaigns-rewards-schema-v123.php")<bootstrap.indexOf("campaigns-rewards-v123.php")&&bootstrap.indexOf("campaigns-rewards-v123.php")<bootstrap.indexOf("campaigns-rewards-release-v123.php")],
 ['V1.23 cron is CLI-only and requires migration readiness',
  cron.includes("PHP_SAPI!=='cli'")&&cron.includes('campaigns_rewards_journey_release_schema_ready_v123')&&cron.includes('campaigns_rewards_run_due_v123')],
 ['existing consolidated CI runs V1.23 contracts and migration syntax',
  workflow.includes('Campaigns & Rewards V1.23 contract')&&workflow.includes('tests/campaigns-rewards-v123.mjs')&&workflow.includes('campaigns-rewards-schema-v123.php')],
 ['production package advances to V1.23 and includes schema runtime release cron migration and builder',
  (packageFlow.includes('"Campaigns & Rewards V1.23"')||(packageFlow.includes('"Campaigns & Rewards V1.24"')||packageFlow.includes('"Campaigns & Rewards V1.25"')))&&packageFlow.includes('campaigns-rewards-schema-v123.php')&&packageFlow.includes('campaigns-rewards-v123.php')&&packageFlow.includes('cron/campaigns-rewards-v123.php')&&packageFlow.includes('upgrade-campaigns-rewards-v123.sql')&&packageFlow.includes('campaign-journey-builder-v123.js')],
 ['documentation describes migration immutable versions pinned execution and rollback semantics',
  docs.includes('requires the new idempotent migration')&&docs.includes('Published Journey Versions are never edited in place')&&docs.includes('journey_version_id')&&docs.includes('creates a **new Journey Version**')],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.23 contract: '+checks.length+'/'+checks.length+' passed');
