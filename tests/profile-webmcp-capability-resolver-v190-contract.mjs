import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const resolver=read('includes/profile-webmcp-capability-resolver-v190.php');
const native=read('includes/profile-webmcp-v100.php');
const external=read('includes/profile-webmcp-external-v120.php');
const agent=read('includes/profile-agent-public-service-v110.php');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(resolver,/function vp3_profile_webmcp_capability_registry_v190/);
assert.match(resolver,/function vp3_profile_webmcp_detect_capabilities_v190/);
assert.match(resolver,/function vp3_profile_webmcp_resolve_capabilities_v190/);
assert.match(resolver,/function vp3_profile_webmcp_surface_candidate_tools_v190/);
assert.match(resolver,/function vp3_profile_webmcp_agent_capability_context_v190/);

for(const capability of ['profile','profile_agent','booking','commerce','campaigns','rewards','social','messaging']){
  assert.match(resolver,new RegExp("'"+capability+"'\\s*=>"));
}
assert.match(resolver,/'rewards'=>\['scope'=>'authenticated_viewer'/);
assert.match(resolver,/'rewards'=>\['scope'=>'authenticated_viewer','surfaces'=>\['native_profile','agent_brain'\]\]/);
assert.doesNotMatch(resolver,/'rewards'.*external_site/,'personal Rewards must not be allowed on external sites');
assert.doesNotMatch(resolver,/'social'.*external_site/,'personal Social must not be allowed on external sites');
assert.doesNotMatch(resolver,/'messaging'.*external_site/,'personal Messaging must not be allowed on external sites');

assert.match(native,/require_once __DIR__\.'\/profile-webmcp-capability-resolver-v190\.php'/);
assert.match(native,/return vp3_profile_webmcp_detect_capabilities_v190\(\$pdo,\$profile,\$viewer\)/);
assert.match(native,/vp3_profile_webmcp_resolve_capabilities_v190\(\$pdo,\$profile,\$viewer,\['surface'=>'native_profile'\]\)/);
assert.doesNotMatch(native,/count\(campaigns_rewards_profile_campaigns_v100\(\$pdo, \$ownerUserId, 1\)\)/,'native capability detection must not be duplicated');
assert.doesNotMatch(native,/personal_capability_has_v242\('profile_agent\.access'/,'native capability detection must not be duplicated');

assert.match(external,/vp3_profile_webmcp_surface_candidate_tools_v190/);
assert.match(external,/vp3_profile_webmcp_resolve_capabilities_v190/);
assert.doesNotMatch(external,/foreach\(\[\s*'vp3\.booking\.options\.list'/,'external tool policy must not be duplicated');

assert.match(agent,/vp3_profile_webmcp_agent_capability_context_v190/);
assert.match(resolver,/\['profile','profile_agent','booking','commerce','campaigns'\]/,'Agent Brain summary must be public-domain only');
assert.doesNotMatch(resolver,/\$publicDomains=.*rewards/s,'Agent Brain public summary must exclude personal Rewards');
assert.match(resolver,/'execution_allowed'=>false/);

assert.match(native,/requires_domain_adapter.*registeredCapabilities/s,'intent adapter readiness must derive from registered tools');
assert.doesNotMatch(native,/!in_array\(\$capability, \['profile','profile_agent','booking','commerce'\]/,'legacy hardcoded adapter list must be removed');

assert.match(workflow,/profile-webmcp-capability-resolver-v190\.php/);
assert.match(workflow,/profile-webmcp-capability-resolver-v190-contract\.mjs/);
assert.match(workflow,/profile-webmcp-capability-resolver-v190\.php/);
assert.match(recovery,/profile-webmcp-capability-resolver-v190-contract\.mjs/);
assert.match(recovery,/profile-webmcp-capability-resolver-v190\.php/);
console.log('PROFILE_WEBMCP_CAPABILITY_RESOLVER_V190_CONTRACT=PASS');
