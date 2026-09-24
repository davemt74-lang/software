import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const schema=read('includes/campaigns-rewards-schema-v126.php');
const runtime=read('includes/campaigns-rewards-v126.php');
const release=read('includes/campaigns-rewards-release-v126.php');
const migration=read('upgrade-campaigns-rewards-v126.sql');
const upgrade=read('upgrade.php');
const setup=read('setup.php');
const bootstrap=read('includes/bootstrap.php');
const campaigns=read('campaigns.php');
const registry=read('includes/cognitive-domain-registry-v2600.php');
const cron=read('cron/campaigns-rewards-v126.php');
const docs=read('docs/CAMPAIGNS_REWARDS_V126.md');
const workflow=read('.github/workflows/team-workspaces-v350.yml');
const packageFlow=read('.github/workflows/production-deploy-package.yml');

const checks=[
 ['V1.26 adds exactly two evidence tables',
  schema.includes('CREATE TABLE IF NOT EXISTS campaign_decision_outcomes')&&schema.includes('CREATE TABLE IF NOT EXISTS campaign_optimization_snapshots')&&release.includes("'new_tables'=>2")],
 ['Decision outcomes preserve traceability to canonical Decision Journey delivery and Reward evidence',
  ['decision_id','contact_id','journey_id','journey_instance_id','delivery_id','reward_issuance_id','source_type','source_id','observed_at'].every(x=>schema.includes(x))],
 ['Outcome identity is immutable and idempotent per Decision/source observation',
  schema.includes('UNIQUE KEY uq_campaign_outcome_key (merchant_id,outcome_key)')&&runtime.includes("$key='v126:'.hash('sha256'")&&!runtime.includes('UPDATE campaign_decision_outcomes')],
 ['Optimization snapshots are append-only immutable evidence windows',
  schema.includes('evidence_hash CHAR(64) NOT NULL')&&schema.includes('metrics_json LONGTEXT NOT NULL')&&schema.includes('lifecycle_json LONGTEXT NOT NULL')&&!runtime.includes('UPDATE campaign_optimization_snapshots')],
 ['migration uses MySQL-safe primary keys and avoids reserved rank columns',
  (migration.match(/id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY/g)||[]).length===2&&!/\brank\s+INT/i.test(migration)],
 ['fresh setup and upgrade install V1.26 evidence schema',
  setup.includes('campaigns_rewards_optimization_ensure_schema_v126($pdo)')&&upgrade.includes('campaigns_rewards_optimization_ensure_schema_v126($pdo)')],
 ['bootstrap loads V1.26 schema runtime and release after V1.25',
  bootstrap.indexOf("campaigns-rewards-schema-v125.php")<bootstrap.indexOf("campaigns-rewards-schema-v126.php")&&bootstrap.indexOf("campaigns-rewards-v125.php")<bootstrap.indexOf("campaigns-rewards-v126.php")&&bootstrap.indexOf("campaigns-rewards-release-v125.php")<bootstrap.indexOf("campaigns-rewards-release-v126.php")],
 ['entry-to-instance inference is deliberately time-bounded to reduce false attribution',
  runtime.includes("started_at<=DATE_ADD(?,INTERVAL 10 MINUTE)")&&docs.includes('Attribution is deliberately conservative')],
 ['verified outcomes derive from canonical delivery claim and Journey Instance evidence',
  runtime.includes("attributed_claim_id")&&runtime.includes("campaign_journey_instances")&&runtime.includes("campaigns_rewards_delivery_v120")&&runtime.includes("campaigns_rewards_outcome_reward_economics_v126")],
 ['campaign totals deduplicate shared downstream evidence across multiple contributing Decisions',
  runtime.includes("COUNT(DISTINCT CONCAT(source_type,':',source_id))")&&runtime.includes('GROUP BY campaign_id,outcome_type,source_type,source_id')],
 ['holdouts are not misrepresented as causal lift without an external common outcome authority',
  release.includes("'holdout_reporting_is_observed_not_causal_without_external_outcomes'=>true")&&runtime.includes('not a causal holdout lift measure')&&docs.includes('not represented as causal lift')],
 ['lifecycle intelligence uses entry cohorts, admitted denominators, Decision-time context, and never updates CRM lifecycle',
  runtime.includes("decision_type='entry'")&&runtime.includes("'denominator'=>'admitted_entries'")&&runtime.includes("['contact']['lifecycle_stage']")&&runtime.includes("'source'=>'decision_time_context'")&&!runtime.includes('UPDATE crm_merchant_relationships')],
 ['dynamic offer outcomes require the exact direct delivery and never inherit all Journey deliveries',
  runtime.includes("decision['decision_type']??'')==='offer'")&&runtime.includes('if($direct<1)return []')&&runtime.includes('return $d?[$d]:[]')],
 ['fatigue intelligence is diagnostic and based on real sends views and conversions',
  runtime.includes('SUM(sent_at IS NOT NULL)')&&runtime.includes('SUM(viewed_at IS NOT NULL)')&&runtime.includes("'diagnostic_only'=>true")],
 ['offer effectiveness uses exact V1.25 selected Reward evidence and verified claims',
  runtime.includes("decision_type='offer'")&&runtime.includes("'reward_product_id'")&&runtime.includes("'claimed_cost_minor'")],
 ['Reward economics remain sourced from canonical issuance snapshots',
  runtime.includes('FROM reward_issuances WHERE id=?')&&runtime.includes("'internal_cost_minor'")&&release.includes("'rewards'=>'reward_issuances + reward_claims'")],
 ['optimization snapshots are content-addressed and reused within a day when evidence is unchanged',
  runtime.includes("hash('sha256',campaigns_rewards_json_v100($payload))")&&runtime.includes('evidence_hash=?')&&runtime.includes('INTERVAL 1 DAY')],
 ['recommendations use existing Campaign Agent recommendation authority',
  runtime.includes('INSERT INTO campaign_agent_recommendations')&&release.includes("'recommendations'=>'campaign_agent_recommendations'")],
 ['Agent optimization is review-only and cannot auto-apply live changes',
  runtime.includes("'requires_human_decision'=>true")&&runtime.includes("'auto_apply'=>false")&&runtime.includes("'live_campaign_mutation'=>false")&&release.includes("'no_autonomous_campaign_mutation'=>true")],
 ['human recommendation review records accepted/dismissed state without Campaign mutation',
  runtime.includes('campaigns_rewards_review_optimization_recommendation_v126')&&runtime.includes("in_array($decision,['accepted','dismissed'],true)")&&runtime.includes("'auto_applied'=>false")],
 ['Campaign workspace requires V1.26 migration and exposes lifecycle optimization evidence',
  campaigns.includes('Adaptive Campaign Optimization V1.26')&&campaigns.includes('Lifecycle Intelligence & Adaptive Optimization')&&campaigns.includes('Immutable evidence snapshots')&&campaigns.includes('Human-review recommendations')],
 ['manual Campaign dispatch now routes through V1.26 while preserving V1.25/V1.24 execution',
  campaigns.includes('campaigns_rewards_dispatch_due_v126')&&runtime.includes('campaigns_rewards_dispatch_due_v125')],
 ['V1.26 cron runs full prior runtime then outcome collection and governed optimization',
  cron.includes("PHP_SAPI!=='cli'")&&cron.includes('campaigns_rewards_optimization_schema_ready_v126')&&cron.includes('campaigns_rewards_run_due_v126')&&runtime.includes('campaigns_rewards_run_due_v125')],
 ['cognitive registry advances authority/events to V1.26',
  registry.includes("'implementation_status'=>'integrated-v1.26'")&&registry.includes("'campaign_decision_outcomes'")&&registry.includes("'campaign.optimization_snapshot_recorded'")],
 ['consolidated CI runs V1.26 PHP/JS contracts and lint',
  workflow.includes('Campaigns & Rewards V1.26 contract')&&workflow.includes('tests/campaigns-rewards-v126.mjs')&&workflow.includes('php -l includes/campaigns-rewards-v126.php')],
 ['production package identifies V1.26 and asserts schema runtime release migration and cron',
  packageFlow.includes('"VP3 Cloud Production"')&&packageFlow.includes('campaigns-rewards-schema-v126.php')&&packageFlow.includes('campaigns-rewards-v126.php')&&packageFlow.includes('upgrade-campaigns-rewards-v126.sql')&&packageFlow.includes('cron/campaigns-rewards-v126.php')],
 ['documentation states authority lifecycle fatigue offer holdout and deployment boundaries',
  docs.includes('Durable outcome attribution')&&docs.includes('Immutable optimization snapshots')&&docs.includes('Lifecycle intelligence')&&docs.includes('Fatigue and diminishing response')&&docs.includes('Holdouts and measurement language')&&docs.includes('Agent governance')],
];
for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.26 contract: '+checks.length+'/'+checks.length+' passed');
