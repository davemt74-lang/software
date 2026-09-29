import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const layer=read('includes/profile-webmcp-v100.php');
const api=read('api/profile-webmcp-v100.php');
const runtime=read('profile-webmcp-v100.js');
const profile=read('profile.php');
const publicWorkflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');
const ciGovernance=read('tests/ci-workflow-consolidation.mjs');
const archivedWorkflow=read('.github/workflow-archive/profile-webmcp-v100.yml');
const agentService=read('includes/profile-agent-public-service-v110.php');
const profileAgentApi=read('api/profile-agent.php');

assert.match(layer,/VP3_PROFILE_WEBMCP_MANIFEST_V100\s*=\s*['"]vp3\.profile\.webmcp\.v1['"]/);
assert.match(layer,/vp3_profile_webmcp_tool_catalog_v100/);
assert.match(layer,/vp3\.profile\.capabilities\.get/);
assert.match(layer,/vp3\.profile\.get/);
assert.match(layer,/vp3\.intent\.resolve/);
assert.match(layer,/readOnlyHint.*true/s);
assert.match(layer,/consequentialHint.*false/s);
assert.match(layer,/vp3_profile_webmcp_public_profile_v100/);
assert.doesNotMatch(layer,/['"]email['"]\s*=>|['"]contact_email['"]\s*=>/,'public projection must not deliberately expose email fields');
assert.match(layer,/profile_agent\.access/,'Profile Agent availability must preserve profile-agent entitlement');
assert.match(layer,/profile_chat\.access/,'Profile Agent availability must preserve chat entitlement');
assert.match(layer,/\$viewerId<1 \|\| \$viewerId!==\$ownerUserId/,'owner must not be offered visitor Profile Agent tools');
assert.match(layer,/profile_commerce_products_for_profile_v900\(\$pdo, \$profile, true, 1\)/,'commerce capability must use public projection');
assert.match(layer,/campaigns_rewards_profile_campaigns_v100/,'campaign capability must use profile campaign projection');
assert.match(layer,/vp3_profile_webmcp_native_origin_allowed_v100/);
assert.match(layer,/vp3_profile_webmcp_session_proof_valid_v100/);
assert.match(layer,/profile_visitor_discloses_identity/,'visitor_profile_known must honor existing disclosure policy');
assert.match(layer,/visitor_profile_known'\s*=>\s*\$identityDisclosed/,'authentication alone must not disclose visitor identity');
assert.match(layer,/execution_performed'\s*=>\s*false/,'intent resolution must be planning-only');

assert.match(api,/HTTP_X_VP3_WEBMCP_SESSION/,'gateway must use an ambient session proof outside tool input');
assert.match(api,/SESSION_PROOF_REQUIRED/);
assert.match(api,/ORIGIN_DENIED/);
assert.match(api,/RATE_LIMITED/);
assert.match(api,/MANIFEST_VERSION_UNSUPPORTED/);
assert.match(api,/CAPABILITY_UNAVAILABLE/);
assert.match(api,/profile-agent-public-service-v110\.php/,'WebMCP gateway must load shared Profile Agent service');
assert.match(api,/vp3_profile_agent_public_message_service_v110/,'WebMCP gateway must route Agent messages through shared service');
assert.match(api,/is_public/,'gateway must refuse non-public profiles');
assert.match(api,/Profile WebMCP manifest failed/,'manifest failures must be caught and fail closed');
assert.match(api,/Profile capabilities are temporarily unavailable/,'manifest failures must not expose internals');
assert.match(api,/vp3_profile_webmcp_scheduling_confirm_v150/,'native gateway may execute scheduling only through governed prepare-confirm adapter');
assert.doesNotMatch(api,/mark_paid|create_checkout|refund|claim_from_tray/,'native gateway must not bypass canonical payment, commerce, refund, or reward authority');

assert.match(runtime,/documentObject\?\.modelContext\?\.registerTool/);
assert.match(runtime,/VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100/);
assert.match(runtime,/Object\.freeze/);
assert.match(runtime,/vp3\.agent\.message\.send/,'browser trusted catalog must include Profile Agent messaging');
assert.match(runtime,/X-VP3-WebMCP-Session/);
assert.match(runtime,/credentials:'same-origin'/);
assert.match(runtime,/options\?\.signal\?\.aborted/,'pre-cancelled calls must fail before network dispatch');
assert.match(runtime,/controller\.abort\(\)/,'dynamic tools must unregister with AbortSignal');
assert.doesNotMatch(runtime,/manifest\.tools|tool\.description\s*=\s*manifest/,'server manifest must not define arbitrary tool schemas/descriptions');

assert.match(profile,/require_once __DIR__ \. '\/includes\/profile-webmcp-v100\.php'/);
assert.match(profile,/vp3_profile_webmcp_manifest_v100/);
assert.match(profile,/VP3_PROFILE_WEBMCP/);
assert.match(profile,/profile-webmcp-v100\.js/);
assert.match(profile,/Cache-Control: private, no-store/,'session-bound proof pages must not be shared-cacheable');
assert.match(profile,/Vary: Cookie/,'session-bound proof pages must vary by cookie');

assert.match(publicWorkflow,/matrix:\s*[\s\S]*php:\s*\[['"]8\.1['"],['"]8\.3['"]\]/,'governed public CI must retain PHP 8.1 and 8.3');
assert.match(publicWorkflow,/Profile WebMCP PHP security contract/);
assert.match(publicWorkflow,/php tests\/profile-webmcp-v100\.php/);
assert.match(publicWorkflow,/node tests\/profile-webmcp-v100-contract\.mjs/);
assert.match(publicWorkflow,/node tests\/profile-webmcp-v100-runtime\.mjs/);
assert.match(publicWorkflow,/Profile WebMCP production package smoke/);
assert.match(publicWorkflow,/test -f \/tmp\/vp3-webmcp-package\/includes\/profile-webmcp-v100\.php/);
assert.match(publicWorkflow,/test -f \/tmp\/vp3-webmcp-package\/api\/profile-webmcp-v100\.php/);
assert.match(publicWorkflow,/test -f \/tmp\/vp3-webmcp-package\/profile-webmcp-v100\.js/);

assert.match(recovery,/tests\/profile-webmcp-v100-contract\.mjs/,'Recovery Baseline must retain WebMCP static contract');
assert.match(recovery,/tests\/profile-webmcp-v100-runtime\.mjs/,'Recovery Baseline must retain WebMCP runtime contract');
assert.match(recovery,/tests\/profile-webmcp-v100\.php/,'Recovery Baseline must retain WebMCP PHP contract');
assert.match(recovery,/tests\/profile-webmcp-agent-v110\.php/,'Recovery Baseline must retain Profile Agent WebMCP PHP contract');
assert.match(recovery,/tests\/profile-webmcp-agent-v110-contract\.mjs/,'Recovery Baseline must retain Profile Agent WebMCP static contract');
assert.match(recovery,/tests\/profile-webmcp-agent-v110-runtime\.mjs/,'Recovery Baseline must retain Profile Agent WebMCP runtime contract');

assert.match(ciGovernance,/active\.length,12/,'consolidated active workflow count must remain 12');
assert.doesNotMatch(ciGovernance,/profile-webmcp-v100\.yml/,'WebMCP must not create a thirteenth active workflow');
assert.match(archivedWorkflow,/name: Profile WebMCP v1\.00/,'standalone WebMCP workflow should remain archived as release history');

console.log('PROFILE_WEBMCP_V100_CONTRACT=PASS');
