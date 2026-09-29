import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const negotiation=read('includes/profile-webmcp-negotiation-v200.php');
const nativeApi=read('api/profile-webmcp-v100.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const nativeRuntime=read('profile-webmcp-v100.js');
const externalRuntime=read('profile-webmcp-external-v120.js');
const nativeManifest=read('includes/profile-webmcp-v100.php');
const externalManifest=read('includes/profile-webmcp-external-v120.php');
const release=read('includes/profile-webmcp-release-v196.php');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(negotiation,/vp3\.profile\.webmcp\.negotiation\.v1/);
assert.match(negotiation,/legacy_v1_compatible'=>true/);
assert.match(negotiation,/explicit_confirmation_and_idempotency_required/);
assert.match(negotiation,/downgrade_consequential_protection'=>false/);
assert.match(negotiation,/WEBMCP_VERSION_INCOMPATIBLE/);

assert.match(nativeApi,/vp3_profile_webmcp_client_versions_v200/);
assert.match(nativeApi,/vp3_profile_webmcp_negotiate_v200\('native_profile'/);
assert.match(externalApi,/vp3_profile_webmcp_negotiate_v200\('external_site'/);
assert.match(externalApi,/manifest_versions/);
assert.match(externalApi,/runtime_build/);

assert.match(nativeRuntime,/client_versions/);
assert.match(nativeRuntime,/VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200/);
assert.match(externalRuntime,/client_versions/);
assert.match(externalRuntime,/negotiation_contract/);

assert.match(nativeManifest,/protocol/);
assert.match(externalManifest,/protocol/);
assert.match(release,/negotiation'[\s\S]*'contract'=>'vp3\.profile\.webmcp\.negotiation\.v1'/);
assert.match(workflow,/profile-webmcp-negotiation-v200/);
assert.match(recovery,/profile-webmcp-negotiation-v200/);

for(const source of [nativeApi,externalApi]){
  assert.doesNotMatch(source,/downgrade.*confirmation.*false/i);
}

console.log('PROFILE_WEBMCP_NEGOTIATION_V200_CONTRACT=PASS');
