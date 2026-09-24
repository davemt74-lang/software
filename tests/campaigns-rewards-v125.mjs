import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const schema=read('includes/campaigns-rewards-schema-v125.php');
const runtime=read('includes/campaigns-rewards-v125.php');
const v123=read('includes/campaigns-rewards-v123.php');
const v124=read('includes/campaigns-rewards-v124.php');
const domain=read('includes/campaigns-rewards-domain-v100.php');
const release=read('includes/campaigns-rewards-release-v125.php');
const migration=read('upgrade-campaigns-rewards-v125.sql');
const upgrade=read('upgrade.php');
const setup=read('setup.php');
const bootstrap=read('includes/bootstrap.php');
const campaigns=read('campaigns.php');
const registry=read('includes/cognitive-domain-registry-v2600.php');
const cron=read('cron/campaigns-rewards-v125.php');
const docs=read('docs/CAMPAIGNS_REWARDS_V125.md');
const workflow=read('.github/workflows/team-workspaces-v350.yml');
const packageFlow=read('.github/workflows/production-deploy-package.yml');

const checks=[
 ['V1.25 adds one append-only Campaign Decision table',schema.includes('CREATE TABLE IF NOT EXISTS campaign_decisions')&&release.includes("'new_tables'=>1")&&release.includes("'decision_ledger_append_only'=>true")],
 ['Decision schema records release instance delivery contact rule context outcome and holdout evidence',
  ['journey_version_id','journey_instance_id','delivery_id','contact_id','decision_type','decision_key','context_hash','context_json','rules_json','outcome_json','is_holdout'].every(x=>schema.includes(x))],
 ['Decision migration has MySQL-safe non-null auto increment primary key and no reserved rank column',migration.includes('id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY')&&!/\brank\s+INT/i.test(migration)],
 ['upgrade and setup install the V1.25 Decision schema',upgrade.includes('campaigns_rewards_decision_ensure_schema_v125($pdo)')&&upgrade.includes('campaigns_rewards_decision_schema_ready_v125()')&&setup.includes('campaigns_rewards_decision_ensure_schema_v125($pdo)')],
 ['Journey node save freezes V1.25 rules into the existing versioned node template',v123.includes('campaigns_rewards_decision_settings_v125($input,$previousTemplate)')&&release.includes("'rules'=>'immutable Journey Version node template'")],
 ['entry decisions run before Journey Instance creation and can hold out or conflict-suppress',
  v123.indexOf('campaigns_rewards_entry_decision_v125')<v123.indexOf('campaigns_rewards_journey_instance_start_v124')&&runtime.includes("'reason'=>'holdout'")&&runtime.includes("'reason'=>'campaign_conflict'")],
 ['holdout assignment is deterministic for Campaign Version and contact',runtime.includes("hash('sha256',(int)$campaign['id'].'|'.(int)$version['id'].'|'.$contactId)")&&release.includes("'holdout_deterministic'=>true")],
 ['conflicts use prior admitted Decision evidence and never auto-preempt an active Journey',
  runtime.includes("decision_type='entry'")&&runtime.includes('conflicting_campaign_id')&&docs.includes('does **not** automatically preempt or cancel an already-admitted Journey')],
 ['rich decision fields cover CRM loyalty Reward Campaign Journey and trigger context',runtime.includes("'contact.lifecycle_stage'")&&runtime.includes("'loyalty.points'")&&runtime.includes("'rewards.active_count'")&&runtime.includes("'journey.prior_completed_count'")&&runtime.includes("'context.amount_paid_cents'")],
 ['Decision context requires canonical Merchant CRM relationship and avoids copied name/email/free-form metadata',
  runtime.includes("campaigns_rewards_message_contact_v120($pdo,$merchantId,$contactId)")&&!runtime.includes("'name'=>(string)($contact['name']")&&!runtime.includes("'relationship_metadata'")],
 ['V1.25 branch decisions execute and persist exact rule/outcome evidence',v123.includes('campaigns_rewards_condition_source_v125')&&v123.includes('campaigns_rewards_record_branch_v125')&&runtime.includes("'next_step_key'=>$next")],
 ['offer candidates resolve from published Campaign Version Reward snapshot',runtime.includes('reward_snapshot_json')&&runtime.includes("'campaign_version_id'")&&docs.includes("published Campaign Version's frozen Reward snapshot")],
 ['specific dynamic offers are release-validated as Campaign-attached Rewards',runtime.includes("'specific_offer_not_attached'")&&runtime.includes('campaigns_rewards_campaign_reward_ids_v118')],
 ['dynamic offer selection enforces claim limit inventory conditions and deterministic equal-priority selection',
  runtime.includes('claim_limit')&&runtime.includes('reward_inventory_balances')&&runtime.includes('conditions_json')&&runtime.includes("hash('sha256',$seed)")],
 ['dynamic Reward issue requires exact Decision authorization before canonical issuance',
  domain.includes("$decisionFlow=$actorType==='decision'")&&domain.includes('campaigns_rewards_decision_validate_issue_v125')&&runtime.includes("actor_type'=>'decision'")&&runtime.includes('campaigns_rewards_issue_reward_v100')],
 ['canonical Reward issuance still enforces Campaign snapshot budget inventory limits and liability',
  domain.includes('campaigns_rewards_version_reward_snapshot_v100')&&domain.includes('Campaign budget would be exceeded')&&domain.includes('reward_inventory_balances')&&domain.includes('reward_liability_ledger')],
 ['Decision ledger remains append-only when a selected offer is issued',!runtime.includes('UPDATE campaign_decisions')&&runtime.includes("UPDATE campaign_deliveries SET reward_issuance_id")],
 ['dynamic CRM/loyalty tokens require personalization opt-in while offer aliases remain available',runtime.includes("if(!empty($template['personalization_enabled']))$tokens=campaigns_rewards_flatten_tokens_v125")&&runtime.includes("'{{offer_name}}'")],
 ['release validation covers V1.25 offer placement specific Reward and A/B entry-control consistency',v123.includes('campaigns_rewards_validate_decision_graph_v125')&&runtime.includes("'offer_non_message'")&&runtime.includes("'specific_offer_not_attached'")&&runtime.includes("'entry_decision_variant_mismatch'")],
 ['V1.23 pre-publish simulation evaluates V1.25 branches and offer eligibility without Decision writes',v123.includes("function_exists('campaigns_rewards_condition_source_v125')")&&v123.includes("['offer_preview']=campaigns_rewards_select_offer_v125")],
 ['Decision Preview is dry-run and shows entry eligibility plus Journey simulation',runtime.includes('campaigns_rewards_preview_entry_v125')&&runtime.includes("campaigns_rewards_simulate_version_v123($pdo,$version,$contactId,$triggerContext,false)")&&campaigns.includes('Decision preview completed without sending, issuing, enrolling')],
 ['V1.24 Contact Journey Timeline now exposes exact V1.25 Decision evidence',v124.includes("table_exists('campaign_decisions')")&&campaigns.includes('Decision evidence')],
 ['manual Campaign dispatch preserves V1.24 instance controls and V1.22 deferral metrics',campaigns.includes('campaigns_rewards_dispatch_due_v125')&&runtime.includes('campaigns_rewards_dispatch_due_v124')&&v124.includes("'frequency_deferred'=>0")&&v124.includes("'optimized_deferred'=>0")],
 ['Agent decision intelligence proposes only human-review recommendations',runtime.includes("'auto_apply'=>false")&&runtime.includes("'campaign.decision_recommendation_proposed'")&&release.includes("'agent_recommendations_no_auto_apply'=>true")],
 ['cognitive registry advances to V1.25 Decision authority',registry.includes("'implementation_status'=>'integrated-v1.25'")&&registry.includes("'campaign_decisions'")&&registry.includes("'campaign.decision_recorded'")],
 ['bootstrap loads V1.25 schema/runtime/release after V1.24',bootstrap.indexOf("campaigns-rewards-schema-v124.php")<bootstrap.indexOf("campaigns-rewards-schema-v125.php")&&bootstrap.indexOf("campaigns-rewards-v124.php")<bootstrap.indexOf("campaigns-rewards-v125.php")&&bootstrap.indexOf("campaigns-rewards-release-v124.php")<bootstrap.indexOf("campaigns-rewards-release-v125.php")],
 ['V1.25 cron is CLI-only and requires Decision schema readiness',cron.includes("PHP_SAPI!=='cli'")&&cron.includes('campaigns_rewards_decision_schema_ready_v125')&&cron.includes('campaigns_rewards_run_due_v125')],
 ['Campaign workspace requires V1.25 migration and exposes node decisioning preview and ledger',campaigns.includes('Journey Personalization V1.25')&&campaigns.includes('V1.25 personalization & decisioning')&&campaigns.includes('Personalization & Offer Decisions')&&campaigns.includes('Recent decision ledger')],
 ['consolidated CI runs V1.25 contracts',workflow.includes('Campaigns & Rewards V1.25 contract')&&workflow.includes('tests/campaigns-rewards-v125.mjs')],
 ['production package identifies V1.25 and includes schema runtime release migration cron and docs',packageFlow.includes('"Campaigns & Rewards V1.25"')&&packageFlow.includes('campaigns-rewards-schema-v125.php')&&packageFlow.includes('campaigns-rewards-v125.php')&&packageFlow.includes('upgrade-campaigns-rewards-v125.sql')&&packageFlow.includes('cron/campaigns-rewards-v125.php')],
 ['documentation describes append-only evidence holdout conflict dynamic offers personalization preview and authority boundaries',docs.includes('append-only explanation ledger')&&docs.includes('Holdout / control groups')&&docs.includes('Dynamic Reward / offer selection')&&docs.includes('Preview / simulation')&&docs.includes('Authority boundaries')],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.25 contract: '+checks.length+'/'+checks.length+' passed');
