import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const layer=read('includes/profile-webmcp-v100.php');
const api=read('api/profile-webmcp-v100.php');
const runtime=read('profile-webmcp-v100.js');
const profile=read('profile.php');
const workflow=read('.github/workflows/profile-webmcp-v100.yml');

assert.match(layer,/VP3_PROFILE_WEBMCP_MANIFEST_V100\s*=\s*['"]vp3\.profile\.webmcp\.v1['"]/);
assert.match(layer,/vp3_profile_webmcp_tool_catalog_v100/);
assert.match(layer,/vp3\.profile\.capabilities\.get/);
assert.match(layer,/vp3\.profile\.get/);
assert.match(layer,/vp3\.intent\.resolve/);
assert.match(layer,/readOnlyHint.*true/s);
assert.match(layer,/consequentialHint.*false/s);
assert.match(layer,/vp3_profile_webmcp_public_profile_v100/);
assert.doesNotMatch(layer,/contact_email.*=>|email.*=>/,'public projection must not deliberately expose email fields');
assert.match(layer,/profile_chat\.access/,'Profile Agent availability must preserve owner entitlement');
assert.match(layer,/profile_commerce_products_for_profile_v900\(\$pdo, \$profile, true, 1\)/,'commerce capability must use public projection');
assert.match(layer,/campaigns_rewards_profile_campaigns_v100/,'campaign capability must use profile campaign projection');
assert.match(layer,/vp3_profile_webmcp_native_origin_allowed_v100/);
assert.match(layer,/vp3_profile_webmcp_session_proof_valid_v100/);
assert.match(layer,/execution_performed'\s*=>\s*false/,'intent resolution must be planning-only');

assert.match(api,/HTTP_X_VP3_WEBMCP_SESSION/,'gateway must use an ambient session proof outside tool input');
assert.match(api,/SESSION_PROOF_REQUIRED/);
assert.match(api,/ORIGIN_DENIED/);
assert.match(api,/RATE_LIMITED/);
assert.match(api,/MANIFEST_VERSION_UNSUPPORTED/);
assert.match(api,/CAPABILITY_UNAVAILABLE/);
assert.match(api,/is_public/,'gateway must refuse non-public profiles');
assert.doesNotMatch(api,/mark_paid|create_checkout|refund|claim_from_tray|reschedule/,'Section 1 gateway must not execute domain transactions');

assert.match(runtime,/documentObject\?\.modelContext\?\.registerTool/);
assert.match(runtime,/VP3_PROFILE_WEBMCP_TOOL_CATALOG_V100/);
assert.match(runtime,/Object\.freeze/);
assert.match(runtime,/X-VP3-WebMCP-Session/);
assert.match(runtime,/credentials:'same-origin'/);
assert.match(runtime,/controller\.abort\(\)/,'dynamic tools must unregister with AbortSignal');
assert.doesNotMatch(runtime,/manifest\.tools|tool\.description\s*=\s*manifest/,'server manifest must not define arbitrary tool schemas/descriptions');

assert.match(profile,/require_once __DIR__ \. '\/includes\/profile-webmcp-v100\.php'/);
assert.match(profile,/vp3_profile_webmcp_manifest_v100/);
assert.match(profile,/VP3_PROFILE_WEBMCP/);
assert.match(profile,/profile-webmcp-v100\.js/);

assert.match(workflow,/php-version:\s*\$\{\{ matrix\.php \}\}/);
assert.match(workflow,/8\.1/);
assert.match(workflow,/8\.3/);
assert.match(workflow,/profile-webmcp-v100-runtime\.mjs/);
assert.match(workflow,/profile-webmcp-v100\.php/);
assert.match(workflow,/package-smoke/);

console.log('PROFILE_WEBMCP_V100_CONTRACT=PASS');
