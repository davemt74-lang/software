import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const core=read('includes/campaigns-rewards-v100.php');
const schema=read('includes/campaigns-rewards-platform-v100.php');
const runtime=read('includes/campaigns-rewards-domain-v100.php');
const release=read('includes/campaigns-rewards-release-v100.php');
const crm=read('includes/crm-v180.php');
const registry=read('includes/plugin-registry-v320.php');
const lifecycle=read('includes/plugin-lifecycle-v360.php');
const plugins=read('plugins.php');
const team=read('team.php');
const teamLifecycle=read('includes/team-workspace-lifecycle-v350.php');
const profile=read('profile.php');
const nav=read('includes/member-navigation.php');
const bootstrap=read('includes/bootstrap.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const domain=read('includes/cognitive-domain-registry-v2600.php');
const v26release=read('includes/cognitive-release-v2600.php');
const campaignPage=read('campaign.php');
const claimPage=read('campaign-claim.php');
const walletPage=read('rewards-wallet.php');
const dashboard=read('campaigns.php');
const htaccess=read('.htaccess');
const recovery=read('tools/run_recovery_baseline.py');
const workflow=read('.github/workflows/team-workspaces-v350.yml');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/CAMPAIGNS_REWARDS_V100.md');

const canonicalTables=[
 'merchant_accounts','merchant_profiles','merchant_roles','merchant_role_capabilities','merchant_members','merchant_member_access_sources',
 'merchant_locations','crm_merchant_relationships','campaign_types','campaigns','campaign_versions','campaign_enrollments','campaign_cases',
 'campaign_landing_pages','campaign_profile_publications','reward_types','reward_products','campaign_reward_sets','campaign_reward_set_items',
 'reward_issuances','merchant_claim_codes','merchant_claim_code_campaigns','reward_claims','reward_claim_attempts',
 'reward_inventory_balances','reward_inventory_ledger','loyalty_programs','loyalty_accounts','loyalty_ledger',
 'campaign_activity_events','campaign_idempotency_keys','campaign_reconciliation_runs','campaign_reconciliation_findings','reward_liability_ledger'
];
const prototypeTables=[
 'campaign_merchant_accounts_v100','campaign_merchant_members_v100','campaign_merchant_locations_v100',
 'campaigns_v100','campaign_rewards_v100','campaign_customers_v100','campaign_reward_claims_v100',
 'campaign_activity_v100','campaign_team_scopes_v100','campaign_team_invite_scopes_v100'
];

const checks=[
 ['plugin is registered in canonical catalog',/campaigns_rewards/.test(registry)&&/Campaigns & Rewards/.test(registry)],
 ['plugin uses generic v3.60 lifecycle',/vp3_plugin_set_enabled_v360\(\$pdo,\$user,'campaigns_rewards',true\)/.test(plugins)&&/vp3_plugin_set_enabled_v360\(\$pdo,\$user,'campaigns_rewards',false\)/.test(plugins)],
 ['collaborators can receive contextual capability without their own install',/campaigns_rewards_accessible_merchants_v100/.test(lifecycle)&&/\$contextual=\$workspaceCount>0/.test(lifecycle)],

 ['bootstrap loads v26 registry before Campaigns and canonical schema before domain runtime',bootstrap.indexOf('cognitive-domain-registry-v2600.php')<bootstrap.indexOf('campaigns-rewards-v100.php')&&bootstrap.indexOf('campaigns-rewards-platform-v100.php')<bootstrap.indexOf('campaigns-rewards-domain-v100.php')],
 ['Campaigns release gate loads after canonical runtime',bootstrap.indexOf('campaigns-rewards-domain-v100.php')<bootstrap.indexOf('campaigns-rewards-release-v100.php')],
 ['canonical schema owns all required V1 business families',canonicalTables.every(t=>schema.includes('CREATE TABLE IF NOT EXISTS '+t))],
 ['prototype Campaigns tables are no longer installed',prototypeTables.every(t=>!schema.includes('CREATE TABLE IF NOT EXISTS '+t)&&!core.includes('CREATE TABLE IF NOT EXISTS '+t))],
 ['compatibility schema hook delegates to canonical platform schema',/campaigns_rewards_platform_ensure_schema_v100\(\$pdo\)/.test(core)&&!/CREATE TABLE IF NOT EXISTS campaign_/i.test(core)],

 ['Merchant is separate from VP3 identity',/CREATE TABLE IF NOT EXISTS merchant_accounts/.test(schema)&&/owner_user_id/.test(schema)&&/merchant_entity_separate_from_login'=>true/.test(release)],
 ['Merchant must retain an active Owner',/must always have at least one active Owner/i.test(runtime)&&/last_active_owner_protected'=>true/.test(release)],
 ['Merchant roles use granular capabilities',/merchant_role_capabilities/.test(schema)&&/claims\.process/.test(schema)&&/campaigns\.publish/.test(schema)&&/merchant\.settings\.manage/.test(schema)],
 ['direct Team and Owner access sources are explicit and independent',/merchant_member_access_sources/.test(schema)&&/source_type.*owner.*direct.*team/s.test(runtime)&&/direct_and_team_access_sources_independent'=>true/.test(release)],
 ['effective Merchant projection prioritizes Owner then Direct then Team',/FIELD\(source_type,'owner','direct','team'\)/.test(runtime)&&/merchant_team/.test(runtime)],
 ['Merchant Team role is bounded read access',/Merchant Team/.test(runtime)&&/claims\.process/.test(schema)&&!/merchant_team'[\s\S]{0,500}claims\.process/.test(runtime)],
 ['Team lifecycle remains canonical',/workspace_memberships_v350/.test(release)&&!/CREATE TABLE IF NOT EXISTS workspace_memberships_v350/i.test(schema)],
 ['Team UI still exposes Basic Merchant and Both scope',/name="team_category"/.test(team)&&/name="merchant_account_id"/.test(team)&&/'basic'=>'Basic Team'/.test(core)&&/'merchant'=>'Merchant Team'/.test(core)&&/'both'=>'Both'/.test(core)],
 ['Team invitation and lifecycle hooks retain Campaigns scope synchronization',/campaigns_rewards_apply_invite_scope_v100/.test(teamLifecycle)&&/campaigns_rewards_team_membership_status_v100/.test(teamLifecycle)],

 ['Core CRM supports owner-scoped Contact identity',/owner_user_id/.test(crm)&&/crm_v180_contact_for_owner/.test(crm)&&/crm_v180_contacts_for_owner/.test(crm)],
 ['Campaign acquisition resolves Core CRM rather than a parallel customer table',/crm_v180_upsert_contact/.test(runtime)&&/crm_merchant_relationships/.test(schema)&&!/CREATE TABLE IF NOT EXISTS campaign_customers_v100/.test(schema+core)],
 ['Merchant relationship stores Merchant-specific customer state',/customer_status/.test(schema)&&/loyalty_status/.test(schema)&&/acquisition_source/.test(schema)],

 ['Campaign Types are seeded and extensible',/signup/.test(schema)&&/make_good/.test(schema)&&/loyalty/.test(schema)&&/referral/.test(schema)&&/win_back/.test(schema)],
 ['Campaign lifecycle includes draft scheduled active paused completed archived',/\$allowed=\['draft','scheduled','active','paused','completed','archived'\]/.test(runtime)],
 ['Campaign versions freeze snapshots',/CREATE TABLE IF NOT EXISTS campaign_versions/.test(schema)&&/campaign_snapshot_json/.test(schema)&&/reward_snapshot_json/.test(schema)&&/INSERT INTO campaign_versions/.test(runtime)],
 ['public Campaign lookup requires active production published landing',/c\.status='active'/.test(core)&&/c\.environment='production'/.test(core)&&/lp\.is_published=1/.test(core)],
 ['Profile Campaigns projection is active production and profile-public only',/campaigns_rewards_profile_campaigns_platform_v100/.test(runtime)&&/lp\.visibility='profile_public'/.test(runtime)&&/\$profileTabs\['campaigns'\]='Campaigns'/.test(profile)],

 ['Reward Products are reusable and attached through Reward Sets',/CREATE TABLE IF NOT EXISTS reward_products/.test(schema)&&/campaign_reward_sets/.test(schema)&&/campaign_reward_set_items/.test(schema)&&/campaigns_rewards_attach_reward_v100/.test(runtime)],
 ['Reward Issuance is the customer entitlement authority',/CREATE TABLE IF NOT EXISTS reward_issuances/.test(schema)&&/terms_snapshot_json/.test(schema)&&/campaign_version_id/.test(schema)],
 ['Wallet is a projection over Issuance and Claim state',/function campaigns_rewards_wallet_v100/.test(runtime)&&/reward_issuances/.test(runtime)&&/reward_claims/.test(runtime)&&/wallet_is_projection_not_second_ledger'=>true/.test(release)],
 ['Wallet authority can rotate and reveal a fresh one-time Reward credential',/campaigns_rewards_rotate_reward_credential_v100/.test(runtime)&&/credential_hash=\?/.test(runtime)&&/reward-inbox\.php/.test(walletPage)],
 ['Reward Credential plaintext is not a persistence column',/credential_hash/.test(schema)&&/credential_last4/.test(schema)&&!/credential\s+(?:VARCHAR|TEXT|LONGTEXT)/i.test(schema)],
 ['Merchant Claim Code plaintext is not a persistence column',/code_hash/.test(schema)&&/code_last4/.test(schema)&&!/claim_code\s+(?:VARCHAR|TEXT|LONGTEXT)/i.test(schema)],

 ['public signup is limited to supported active production Campaign Types',/supports_public_signup/.test(runtime)&&/actorType==='public'/.test(runtime)&&/environment.*production/.test(runtime)],
 ['public signup resolves CRM relationship Enrollment and Reward Issuance',/campaigns_rewards_customer_upsert_v100/.test(campaignPage)&&/campaigns_rewards_public_enroll_v100/.test(campaignPage)&&/campaigns_rewards_issue_reward_v100/.test(campaignPage)],
 ['public signup retains honeypot rate limiting and CSRF',/name="website"/.test(campaignPage)&&/campaigns_rewards_public_claim_rate_limit_v100/.test(campaignPage)&&/csrf_field/.test(campaignPage)],
 ['issuance is idempotent and bounded by campaign contact budget and inventory limits',/campaigns_rewards_idempotency_begin_v100/.test(runtime)&&/max_rewards/.test(runtime)&&/per_contact_limit/.test(runtime)&&/budget_minor/.test(runtime)&&/inventory_mode/.test(runtime)],
 ['tracked inventory reserves on issuance and consumes on Claim',/reserved=reserved\+\?/.test(runtime)&&/on_hand=on_hand-\?,reserved=reserved-\?/.test(runtime)&&/movement_type/.test(schema)],

 ['Claim processing is online-only and requires authenticated operator',/Authoritative V1 Claim processing is online-only/.test(runtime)&&/authenticated Merchant operator is required/.test(runtime)],
 ['Claim processing requires Reward Credential and Merchant Claim Code hashes',/rewardHash=campaigns_rewards_secret_hash_v100/.test(runtime)&&/claimHash=campaigns_rewards_secret_hash_v100/.test(runtime)],
 ['Claim processing capability-gates the signed-in operator',/campaigns_rewards_platform_assert_can_v100\(\$pdo,\$merchantId,\$actorUserId,'claims\.process'\)/.test(runtime)],
 ['Claim Terminal binds mutation to selected Merchant before redemption',/expected_merchant_id/.test(claimPage)&&/Reward Credential belongs to a different Merchant/.test(runtime)],
 ['Claim Code supports location campaign value and usage restrictions',/daily_claim_limit/.test(runtime)&&/total_claim_limit/.test(runtime)&&/max_value_minor/.test(runtime)&&/merchant_claim_code_campaigns/.test(runtime)],
 ['legacy one-code validation and redemption are disabled',/Legacy one-code validation is disabled/.test(core)&&/Legacy one-code redemption is disabled/.test(core)],
 ['public claim-code rewrite is removed',!/\^campaign-claim\//.test(htaccess)&&/campaign\.php\?slug/.test(htaccess)],
 ['accepted Claim writes production liability and conversion attribution',/reward_liability_ledger/.test(runtime)&&/campaign\.conversion_attributed/.test(runtime)&&/claim\.accepted/.test(runtime)],

 ['Make Good is first-class Case Enrollment and Reward issuance',/function campaigns_rewards_make_good_v100/.test(runtime)&&/campaign_cases/.test(runtime)&&/campaigns_rewards_enroll_contact_v100/.test(runtime)&&/campaigns_rewards_issue_reward_v100/.test(runtime)],
 ['Loyalty uses account plus append-only ledger',/loyalty_accounts/.test(schema)&&/loyalty_ledger/.test(schema)&&/campaigns_rewards_loyalty_adjust_v100/.test(runtime)],
 ['sandbox events cannot enter production cognitive ingestion',/if\(\$environment==='production'&&function_exists\('vp3_cognitive_domain_ingest_v2600'\)\)/.test(runtime)],
 ['reconciliation records findings without replaying side effects',/campaign_reconciliation_findings/.test(runtime)&&/'side_effects_replayed'=>false/.test(runtime)],
 ['domain activity has durable idempotency and liability foundations',/campaign_idempotency_keys/.test(schema)&&/reward_liability_ledger/.test(schema)&&/campaign_activity_events/.test(schema)],

 ['Campaigns registers through canonical v5.00 cognitive module registry',/campaigns_rewards_register_cognitive_module_v100/.test(core)&&/vp3_cognitive_register_module_v500/.test(core)],
 ['v26 declares canonical Campaigns authority objects',/'implementation_status'=>'integrated-v1\.10'/.test(domain)&&/'reward_issuance'/.test(domain)&&/'claim_code'/.test(domain)],
 ['v26 uses canonical ingress current-state attention and presentation authorities',/'event_ingress'=>'agent_event_inbox_v1920'/.test(domain)&&/'attention_policy'=>'cognitive_attention_v2410'/.test(domain)&&/'current_state'=>'cognitive_current_state_v2590'/.test(domain)&&/'presentation'=>'cognitive_presentation_firewall_v2590'/.test(domain)],
 ['v26 still prohibits duplicate CRM Team cognitive authorities',/crm_or_team_records_duplicated_for_campaigns'=>false/.test(v26release)&&/second_event_ledger'=>false/.test(v26release)],
 ['canonical cognitive context does not expose credentials or CRM PII',!/credential_hash/.test((runtime.match(/function campaigns_rewards_cognitive_context_canonical_v100[\s\S]*?function campaigns_rewards_cognitive_relationships_canonical_v100/)||[''])[0])&&!/email|phone/.test((runtime.match(/function campaigns_rewards_cognitive_context_canonical_v100[\s\S]*?function campaigns_rewards_cognitive_relationships_canonical_v100/)||[''])[0])],

 ['Campaigns dashboard retains Merchant Location Campaign and Team controls',/merchant_update/.test(dashboard)&&/location_save/.test(dashboard)&&/campaign_save/.test(dashboard)&&/merchant_member_save/.test(dashboard)&&!/claim_code_create/.test(dashboard)&&!/make_good/.test(dashboard)],
 ['member navigation exposes separate Campaigns Rewards and Claim Terminal without Wallet sidebar item',/'campaigns','Campaigns'/.test(nav)&&/'rewards','Rewards'/.test(nav)&&/Claim Terminal/.test(nav)&&!/\$add\(\$links,'reward_wallet','Reward Wallet'/.test(nav)],
 ['Claim Terminal is authenticated',/require_login\(\)/.test(claimPage)&&/claims\.process/.test(claimPage)],
 ['legacy Reward Wallet route is authenticated and redirects to dedicated Reward Inbox',/require_login\(\)/.test(walletPage)&&/reward-inbox\.php/.test(walletPage)],

 ['fresh setup installs canonical Campaigns platform schema after CRM',setup.indexOf('crm_v180_ensure_schema')<setup.indexOf('campaigns_rewards_platform_ensure_schema_v100')&&/campaigns_rewards_platform_ensure_schema_v100\(\$pdo\)/.test(setup)],
 ['upgrade readiness includes canonical Campaigns platform schema',/campaigns_rewards_platform_schema_ready_v100/.test(upgrade)&&/campaigns_rewards_platform_ensure_schema_v100/.test(upgrade)],
 ['fresh/partial upgrade creates Reward Issuance before any compatibility ALTER',schema.indexOf('CREATE TABLE IF NOT EXISTS reward_issuances')>=0&&schema.indexOf('ALTER TABLE reward_issuances ADD COLUMN inventory_balance_id')>schema.indexOf('CREATE TABLE IF NOT EXISTS reward_issuances')],
 ['Recovery Baseline includes Campaigns PHP and Node contracts',/campaigns-rewards-v100\.php/.test(recovery)&&/campaigns-rewards-v100\.mjs/.test(recovery)],
 ['consolidated Team workflow runs and lints canonical Campaigns V1',/Campaigns & Rewards V1 contract/.test(workflow)&&/campaigns-rewards-platform-v100\.php/.test(workflow)&&/campaigns-rewards-domain-v100\.php/.test(workflow)&&/rewards-wallet\.php/.test(workflow)],
 ['production package retains canonical Campaigns runtime and user surfaces in later releases',/campaigns-rewards-platform-v100\.php/.test(packageWorkflow)&&/campaigns-rewards-domain-v100\.php/.test(packageWorkflow)&&/campaigns\.php/.test(packageWorkflow)&&/campaign\.php/.test(packageWorkflow)&&/campaign-claim\.php/.test(packageWorkflow)&&/rewards-wallet\.php/.test(packageWorkflow)],
 ['release manifest records hash-only three-factor and non-destructive lifecycle invariants',/reward_credentials_plaintext_persisted'=>false/.test(release)&&/merchant_claim_codes_plaintext_persisted'=>false/.test(release)&&/claim_requires_reward_credential_merchant_code_and_authorized_operator'=>true/.test(release)&&/plugin_disable_deletes_business_data'=>false/.test(release)],
 ['documentation preserves Merchant CRM Team Reward Claim cognition and disable boundaries',/separate business entity/i.test(docs)&&/Core CRM remains authoritative/i.test(docs)&&/Canonical VP3 Team membership remains/i.test(docs)&&/Reward Issuance/i.test(docs)&&/three factors/i.test(docs)&&/canonical event inbox/i.test(docs)&&/Disabling Campaigns & Rewards/i.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.00 canonical contract: '+checks.length+'/'+checks.length+' passed');
