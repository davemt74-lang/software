import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const adapter=read('includes/profile-webmcp-rewards-v180.php');
const layer=read('includes/profile-webmcp-v100.php');
const api=read('api/profile-webmcp-v100.php');
const router=read('includes/profile-webmcp-tool-router-v191.php');
const runtime=read('profile-webmcp-v100.js');
const externalLayer=read('includes/profile-webmcp-external-v120.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const externalRuntime=read('profile-webmcp-external-v120.js');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(adapter,/vp3\.loyalty\.status\.get/);
assert.match(adapter,/table_exists\('loyalty_accounts'\)/);
assert.match(adapter,/table_exists\('loyalty_programs'\)/);
assert.match(adapter,/table_exists\('loyalty_tiers'\)/);
assert.match(adapter,/table_exists\('loyalty_ledger'\)/);
assert.match(adapter,/SUM\(points_delta\)/,'points must come from canonical loyalty ledger');
assert.match(adapter,/la\.current_tier_id/,'tier must come from canonical loyalty account');
assert.match(adapter,/lt\.tier_key/);
assert.match(adapter,/cc\.vp3_user_id=\?/,'loyalty status must bind to signed-in VP3 user');
assert.match(adapter,/thresholds_calculated'\s*=>\s*false/);
assert.doesNotMatch(adapter,/points_to_next|next_tier|threshold_remaining|target_points/,'WebMCP must not invent loyalty thresholds');

assert.match(layer,/tool==='vp3\.loyalty\.status\.get'/);
assert.match(layer,/vp3_profile_webmcp_loyalty_schema_ready_v184/);
assert.match(router,/vp3_profile_webmcp_loyalty_status_v184\(\$pdo,\$viewer\)/);
assert.match(runtime,/vp3\.loyalty\.status\.get/);

assert.doesNotMatch(externalLayer,/vp3\.loyalty\.status\.get/);
assert.doesNotMatch(externalApi,/vp3_profile_webmcp_loyalty_status_v184/);
assert.doesNotMatch(externalRuntime,/vp3\.loyalty\.status\.get/);

assert.match(workflow,/profile-webmcp-rewards-v184-contract\.mjs/);
assert.match(workflow,/profile-webmcp-rewards-v184-runtime\.mjs/);
assert.match(workflow,/profile-webmcp-rewards-v184\.php/);
assert.match(recovery,/profile-webmcp-rewards-v184-contract\.mjs/);
assert.match(recovery,/profile-webmcp-rewards-v184-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-rewards-v184\.php/);
console.log('PROFILE_WEBMCP_REWARDS_V184_CONTRACT=PASS');
