import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const adapter=read('includes/profile-webmcp-campaigns-v170.php');
const catalog=read('includes/profile-webmcp-v100.php');
const nativeApi=read('api/profile-webmcp-v100.php');
const externalLayer=read('includes/profile-webmcp-external-v120.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const nativeRuntime=read('profile-webmcp-v100.js');
const externalRuntime=read('profile-webmcp-external-v120.js');
const bootstrap=read('includes/bootstrap.php');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

for(const name of ['vp3.campaigns.list','vp3.campaign.get','vp3.campaign.eligibility.get']){
  const re=new RegExp(name.replaceAll('.','\\.'));
  assert.match(adapter,re,'server adapter '+name);
  assert.match(nativeRuntime,re,'native runtime '+name);
  assert.match(externalRuntime,re,'external runtime '+name);
}
assert.match(adapter,/campaigns_rewards_profile_campaigns_v100/,'list must use canonical profile Campaign projection');
assert.match(adapter,/campaigns_rewards_campaign_by_slug_v100\(\$pdo,\$slug,true\)/,'detail must require canonical public campaign lookup');
assert.match(adapter,/profile_user_id.*profile\['user_id'\]/s,'campaign must stay bound to exact public Profile owner');
assert.match(adapter,/campaigns_rewards_campaign_type_behavior_v118/,'requirements must come from canonical Campaign type behavior');
assert.match(adapter,/campaigns_rewards_rewards_v100\(\$pdo,\(int\)\$campaign\['id'\],true\)/,'rewards must use public-only canonical projection');
assert.match(adapter,/personalized_eligibility_evaluated.*false/s,'read-only eligibility must not pretend to evaluate private targeting');
assert.match(adapter,/private_targeting_exposed.*false/s,'private targeting must be explicitly excluded');
assert.doesNotMatch(adapter,/campaign_decisions|crm_contacts|crm_segment_members|campaign_agent_recommendations|holdout_percent|conflict_group/,'discovery adapter must not query private targeting or decision internals');
assert.doesNotMatch(adapter,/inventory_limit|remaining_quantity|internal_cost_minor|recipient_contact_id/,'public discovery must not expose internal Reward inventory or recipient fields');

assert.match(catalog,/vp3_profile_webmcp_campaigns_tool_catalog_v170/,'main catalog must merge Campaigns adapter');
assert.match(nativeApi,/profile-webmcp-campaigns-v170\.php/);
assert.match(externalApi,/profile-webmcp-campaigns-v170\.php/);
assert.match(nativeApi,/vp3_profile_webmcp_campaigns_list_v170/);
assert.match(externalApi,/vp3_profile_webmcp_campaign_eligibility_v170/);
assert.match(externalLayer,/campaigns_enabled'\s*=>\s*\$campaignsEnabled/);
assert.match(externalRuntime,/campaigns_enabled===true/);
assert.match(externalRuntime,/credentials:'omit'/);
assert.doesNotMatch(externalRuntime,/Authorization|document\.cookie|localStorage|sessionStorage/,'Campaign discovery must remain bearer/cookie/storage free on connected sites');
assert.match(bootstrap,/profile-webmcp-campaigns-v170\.php/);
assert.match(workflow,/profile-webmcp-campaigns-v170\.php/);
assert.match(workflow,/profile-webmcp-campaigns-v170-contract\.mjs/);
assert.match(workflow,/profile-webmcp-campaigns-v170-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-campaigns-v170\.php/);
assert.match(recovery,/profile-webmcp-campaigns-v170-contract\.mjs/);
assert.match(recovery,/profile-webmcp-campaigns-v170-runtime\.mjs/);
console.log('PROFILE_WEBMCP_CAMPAIGNS_V170_CONTRACT=PASS');
