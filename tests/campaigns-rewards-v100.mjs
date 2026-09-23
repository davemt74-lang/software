import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const core=read('includes/campaigns-rewards-v100.php');
const release=read('includes/campaigns-rewards-release-v100.php');
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
const dashboard=read('campaigns.php');
const htaccess=read('.htaccess');
const recovery=read('tools/run_recovery_baseline.py');
const workflow=read('.github/workflows/team-workspaces-v350.yml');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/CAMPAIGNS_REWARDS_V100.md');

const tables=[
 'campaign_merchant_accounts_v100','campaign_merchant_members_v100','campaign_merchant_locations_v100',
 'campaigns_v100','campaign_rewards_v100','campaign_customers_v100','campaign_reward_claims_v100',
 'campaign_activity_v100','campaign_team_scopes_v100','campaign_team_invite_scopes_v100'
];
const checks=[
 ['plugin is registered in canonical catalog',/campaigns_rewards/.test(registry)&&/Campaigns & Rewards/.test(registry)],
 ['plugin uses generic v3.60 enable-disable lifecycle',/vp3_plugin_set_enabled_v360\(\$pdo,\$user,'campaigns_rewards',true\)/.test(plugins)&&/vp3_plugin_set_enabled_v360\(\$pdo,\$user,'campaigns_rewards',false\)/.test(plugins)],
 ['plugin disable copy explicitly preserves business data',/does not delete merchant accounts, campaigns, rewards, customer links, claim history, Team scope or reporting data/i.test(plugins)],
 ['collaborators receive contextual Agent capability without their own install',/campaigns_rewards_accessible_merchants_v100/.test(lifecycle)&&/\$contextual=\$workspaceCount>0/.test(lifecycle)],
 ['bootstrap loads Campaigns after v26 domain registry',bootstrap.indexOf('cognitive-domain-registry-v2600.php')<bootstrap.indexOf('campaigns-rewards-v100.php')],
 ['release gate loads after v26 release gate',bootstrap.indexOf('cognitive-release-v2600.php')<bootstrap.indexOf('campaigns-rewards-release-v100.php')],
 ['V1 owns exactly the named merchant/campaign persistence families',tables.every(t=>core.includes('CREATE TABLE IF NOT EXISTS '+t))],
 ['Campaigns registers through the canonical v5.00 cognitive module registry',/campaigns_rewards_register_cognitive_module_v100/.test(core)&&/vp3_cognitive_register_module_v500/.test(core)&&/'module'=>'campaigns_rewards'/.test(core)],
 ['cognitive context excludes customer PII and claim codes',/customer_pii_exposed'=>false/.test(core)&&/claim_codes_exposed'=>false/.test(core)&&!/reward_claim'=>\[[^\]]*claim_code/s.test(core)&&!/campaign_customer'=>\[[^\]]*(?:email|phone|name)/s.test(core)],
 ['merchant business entity is separate from user identity',/campaign_merchant_accounts_v100/.test(core)&&/owner_user_id/.test(core)&&/profile_user_id/.test(core)&&/campaign_merchant_members_v100/.test(core)],
 ['multiple merchant owner/admin/member relationships are supported',/campaigns_rewards_merchant_roles_v100/.test(core)&&/'owner'=>'Owner'/.test(core)&&/'admin'=>'Admin'/.test(core)&&/'member'=>'Member'/.test(core)],
 ['last active merchant owner cannot be removed by role change',/must keep at least one active owner/.test(core)],
 ['CRM remains canonical and campaign customer stores a reference',/crm_v180_upsert_contact/.test(core)&&/crm_contact_id/.test(core)&&!/CREATE TABLE IF NOT EXISTS crm_contacts/i.test(core)],
 ['Team membership remains canonical and plugin stores scope metadata only',/campaign_team_scopes_v100/.test(core)&&!/CREATE TABLE IF NOT EXISTS workspace_memberships_v350/i.test(core)&&/workspace_memberships_v350/.test(release)],
 ['Team categories are Basic Merchant and Both',/'basic'=>'Basic Team'/.test(core)&&/'merchant'=>'Merchant Team'/.test(core)&&/'both'=>'Both'/.test(core)],
 ['Team UI exposes category and merchant selection only when plugin is active',/campaignsTeamEnabled/.test(team)&&/name="team_category"/.test(team)&&/name="merchant_account_id"/.test(team)],
 ['Team invitation acceptance applies stored merchant scope',/campaigns_rewards_apply_invite_scope_v100/.test(teamLifecycle)],
 ['Team suspension removal and resume propagate merchant member status',/campaigns_rewards_team_membership_status_v100/.test(teamLifecycle)],
 ['campaigns have draft active paused ended lifecycle',/campaign\.launched/.test(core)&&/campaign\.paused/.test(core)&&/campaign\.ended/.test(core)],
 ['public campaign lookup is active-window gated',/c\.status='active'/.test(core)&&/c\.starts_at IS NULL OR c\.starts_at<=UTC_TIMESTAMP/.test(core)&&/c\.ends_at IS NULL OR c\.ends_at>UTC_TIMESTAMP/.test(core)],
 ['Profile Campaigns tab includes only active profile-visible campaigns',/campaigns_rewards_profile_campaigns_v100/.test(profile)&&/\$profileTabs\['campaigns'\]='Campaigns'/.test(profile)&&/profile_visible=1/.test(core)],
 ['public landing includes merchant profile identity and claim form',/profile_username/.test(core)&&/profile_avatar_path/.test(core)&&/reward_public_id/.test(campaignPage)&&/csrf_field/.test(campaignPage)],
 ['public claim form has honeypot and server-side rate limit',/name="website"/.test(campaignPage)&&/campaigns_rewards_public_claim_rate_limit_v100/.test(core)],
 ['reward claiming locks reward before inventory checks',/FOR UPDATE/.test(core)&&/inventory_limit/.test(core)&&/per_customer_limit/.test(core)],
 ['claim codes are random and database-unique',/random_bytes/.test(core)&&/uq_campaign_claim_code/.test(core)&&/campaigns_rewards_claim_code_v100/.test(core)],
 ['merchant-only claim verification is authorization gated',/campaigns_rewards_can_manage_merchant_v100/.test(claimPage)&&/http_response_code\(403\)/.test(claimPage)],
 ['redemption emits reward claimed claim completed and campaign conversion',/\['reward\.claimed','claim\.completed','campaign\.conversion'\]/.test(core)],
 ['customer PII is not copied into cognitive event payloads',!/campaigns_rewards_emit_v100\([^;]+['\"](?:email|phone)['\"]\s*=>/s.test(core)],
 ['landing views are hour/session deduplicated',/campaign-view\|/.test(core)&&/gmdate\('YmdH'\)/.test(core)&&/dedupe_key/.test(core)],
 ['reporting includes campaigns views customers claims and redemption rate',/active_campaigns/.test(core)&&/landing_views/.test(core)&&/redemption_rate/.test(core)],
 ['dashboard exposes add and edit surfaces for merchants locations campaigns rewards members and claims',/merchant_update/.test(dashboard)&&/location_save/.test(dashboard)&&/campaign_save/.test(dashboard)&&/reward_save/.test(dashboard)&&/merchant_member_save/.test(dashboard)&&/claim_redeem/.test(dashboard)],
 ['member navigation exposes Campaigns only through canonical navigation',/campaigns\.php/.test(nav)&&/Campaigns & Rewards/.test(nav)],
 ['public rewrite routes are explicit and precede root profile catch-all',htaccess.indexOf('^campaign/')<htaccess.indexOf('profile-v900.php?username=$1')&&htaccess.indexOf('^campaign-claim/')<htaccess.indexOf('profile-v900.php?username=$1')],
 ['fresh setup installs Campaigns schema',/campaigns_rewards_ensure_schema_v100\(\$pdo\)/.test(setup)],
 ['upgrade readiness and installer include Campaigns schema',/campaigns_rewards_schema_ready_v100\(\)/.test(upgrade)&&/campaigns_rewards_ensure_schema_v100\(\)/.test(upgrade)],
 ['v26 domain contract is now integrated and plugin-backed',/'implementation_status'=>'integrated-v1\.00'/.test(domain)&&/'plugin_catalog_registered'=>true/.test(domain)&&/campaigns_v100/.test(domain)],
 ['v26 keeps CRM Team related objects instead of claiming them',/'related_objects'=>\['contact','team_member','profile'\]/.test(domain)&&/crm_or_team_records_duplicated_for_campaigns/.test(v26release)],
 ['Campaigns release gate records no duplicate CRM Team event or presentation authority',/'crm_contacts_duplicated'=>false/.test(release)&&/'canonical_team_memberships_duplicated'=>false/.test(release)&&/agent_event_inbox_v1920/.test(release)&&/cognitive_presentation_firewall_v2590/.test(release)],
 ['Recovery Baseline includes Campaigns PHP and Node contracts',/campaigns-rewards-v100\.php/.test(recovery)&&/campaigns-rewards-v100\.mjs/.test(recovery)],
 ['consolidated Team workflow runs Campaigns V1 gates without adding a workflow',/Campaigns & Rewards V1/.test(workflow)&&/campaigns-rewards-v100\.mjs/.test(workflow)&&/campaigns-rewards-v100\.php/.test(workflow)],
 ['production package declares V1 and requires all public/runtime surfaces',/Campaigns & Rewards V1\.00/.test(packageWorkflow)&&/campaigns-rewards-v100\.php/.test(packageWorkflow)&&/campaigns\.php/.test(packageWorkflow)&&/campaign\.php/.test(packageWorkflow)&&/campaign-claim\.php/.test(packageWorkflow)&&/campaigns-v100\.css/.test(packageWorkflow)],
 ['documentation preserves merchant identity CRM Team cognition and durable-disable boundaries',/separate business entity/i.test(docs)&&/CRM remains authoritative/i.test(docs)&&/Canonical Team membership remains/i.test(docs)&&/canonical event inbox/i.test(docs)&&/Disabling Campaigns & Rewards/i.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.00 contract: '+checks.length+'/'+checks.length+' passed');
