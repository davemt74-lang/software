import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const registry=read('includes/cognitive-domain-registry-v2600.php');
const release=read('includes/cognitive-release-v2600.php');
const current=read('includes/cognitive-current-state-v2590.php');
const manifest=read('includes/cognitive-domain-manifest-v2370.php');
const runtime=read('includes/cognitive-runtime-v500.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_DOMAIN_REGISTRY_V2600.md');
const pluginRegistry=read('includes/plugin-registry-v320.php');

const checks=[
 ['v26.00 loads after v25.90 current state/firewall',bootstrap.indexOf("cognitive-presentation-firewall-v2590.php")<bootstrap.indexOf("cognitive-domain-registry-v2600.php")],
 ['v26.00 release gate loads after v25.90 gate',bootstrap.indexOf("cognitive-release-v2590.php")<bootstrap.indexOf("cognitive-release-v2600.php")],
 ['v26.00 creates no durable tables',!/CREATE TABLE|ALTER TABLE|DELETE FROM/i.test(registry)&&!/CREATE TABLE|ALTER TABLE|DELETE FROM/i.test(release)],
 ['v5.00 runtime module registry remains explicit authority',/runtime_module_registry.*cognitive-runtime-v500/.test(registry)&&/v500_runtime_module_registry_remains_authority/.test(release)&&/function vp3_cognitive_register_module_v500/.test(runtime)],
 ['v23.70 manifest remains source inventory',/source_manifest.*cognitive-domain-manifest-v2370/.test(registry)&&/v2370_domain_inventory_remains_source_manifest/.test(release)&&/vp3_cognitive_domain_manifest_v2370/.test(manifest)],
 ['v19.20 canonical event ingress is reused',/agent_event_ingest_v1920/.test(registry)&&/v1920_event_inbox_remains_ingress_authority/.test(release)],
 ['unknown events quarantine before ingress',/quarantined_before_ingress/.test(registry)&&/unknown_or_malformed_domain_events_quarantined_before_ingress/.test(release)],
 ['domain references are bounded and normalized',/VP3_COGNITIVE_DOMAIN_MAX_REFS_V2600=16/.test(registry)&&/vp3_cognitive_domain_entity_ref_v2600/.test(registry)&&/vp3_cognitive_object_ref_v500/.test(registry)],
 ['event classification contract exists',/approval_required/.test(registry)&&/failure_recovery/.test(registry)&&/completion/.test(registry)&&/outcome/.test(registry)],
 ['Campaigns & Rewards is first reference contract',/function vp3_cognitive_campaigns_rewards_contract_v2600/.test(registry)&&/campaign\.conversion_attributed/.test(registry)&&/claim\.accepted/.test(registry)&&/claim\.rejected/.test(registry)],
 ['Campaigns & Rewards V1.19+ fulfills the reference contract',/'implementation_status'=>'integrated-v1\.(?:19|20|21|22)'/.test(registry)&&/'merchant_accounts'/.test(registry)&&/'reward_issuances'/.test(registry)&&/'reward_claims'/.test(registry)&&/'campaign_automation_rules'/.test(registry)&&/'plugin_catalog_registered'=>true/.test(registry)],
 ['Campaigns & Rewards is registered only after V1 implementation',/campaigns_rewards/.test(pluginRegistry)&&/Merchant accounts, campaigns, rewards/.test(pluginRegistry)],
 ['CRM and Team are related authorities not duplicated campaign objects',/'related_objects'=>\['contact','team_member','profile'\]/.test(registry)&&/crm_or_team_records_duplicated_for_campaigns/.test(release)],
 ['v25.90 current-state mapper consults v26.00 registry',/vp3_cognitive_current_state_domain_v2600/.test(current)],
 ['v25.90 safe ref allowlist includes campaign identities',/merchant_id/.test(current)&&/campaign_id/.test(current)&&/reward_id/.test(current)&&/claim_id/.test(current)&&/customer_id/.test(current)],
 ['presentation firewall remains mandatory per domain',/presentation_firewall_required/.test(registry)&&/cognitive_presentation_firewall_v2590/.test(registry)],
 ['domain health is diagnostic only',/function vp3_cognitive_domain_health_v2600/.test(registry)&&/'authority'=>'diagnostic_only'/.test(registry)],
 ['v26.00 CI runs PHP and Node gates',/cognitive-domain-registry-v2600\.php/.test(workflow)&&/cognitive-domain-registry-v2600\.mjs/.test(workflow)],
 ['Recovery Baseline retains v26.00 gates',/cognitive-domain-registry-v2600\.php/.test(recovery)&&/cognitive-domain-registry-v2600\.mjs/.test(recovery)],
 ['production package retains v26.00 runtime files in later releases',/cognitive-domain-registry-v2600\.php/.test(packageWorkflow)&&/cognitive-release-v2600\.php/.test(packageWorkflow)],
 ['docs define reference-domain and authority boundaries',/reference domain/i.test(docs)&&/no new event ledger/i.test(docs)&&/Campaigns & Rewards/i.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Cognitive Domain Registry v26.00 gate: '+checks.length+'/'+checks.length+' passed');
