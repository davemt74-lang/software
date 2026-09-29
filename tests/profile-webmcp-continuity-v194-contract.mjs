import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const continuity=read('includes/profile-webmcp-continuity-v194.php');
const agent=read('includes/agent-profile-webmcp-v193.php');
const profile=read('profile.php');
const runtime=read('profile-webmcp-v100.js');
const css=read('profile.css');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(continuity,/VP3_PROFILE_WEBMCP_CONTINUITY_V194/);
assert.match(continuity,/VP3_PROFILE_WEBMCP_RESUME_TTL_V194=600/);
assert.match(continuity,/VP3_PROFILE_WEBMCP_RESUME_MAX_V194=12/);
assert.match(continuity,/bin2hex\(random_bytes\(16\)\)/,'resume token must be opaque 128-bit hex');
assert.match(continuity,/\^\[a-f0-9\]\{32\}\$/);
assert.match(continuity,/\$_SESSION\['vp3_profile_webmcp_resume_v194'\]/);
assert.match(continuity,/unset\(\$rows\[\$token\]\)/,'resume token must be consumed once');
assert.match(continuity,/\$viewerId<1\|\|\$viewerId!==\$profileUserId/,'consume must bind exact authenticated owner');
assert.match(continuity,/hash_equals\(\(string\)\(\$profile\['username'\]/,'consume must bind exact Profile username');
assert.match(continuity,/vp3_profile_webmcp_resolve_capabilities_v190\(\$pdo,\$profile,\$viewer,\['surface'=>'native_profile'\]\)/,'consume must re-resolve native capabilities');
assert.match(continuity,/isset\(\$allowed\[\$name\]\)/,'resume tools must be revalidated against current allowed tools');
assert.match(continuity,/'auto_execute_consequential'=>false/);

assert.match(agent,/profile-webmcp-continuity-v194\.php/);
assert.match(agent,/vp3_profile_webmcp_resume_issue_v194/);
assert.match(agent,/vp3_profile_webmcp_resume_validate_v194/);
assert.match(continuity,/webmcp_resume=/,'resume URL must be minted only by the continuity helper');
assert.match(agent,/'resume_expires_at'/);

assert.match(profile,/profile-webmcp-continuity-v194\.php/);
assert.match(profile,/vp3_profile_webmcp_resume_consume_v194/);
assert.match(profile,/Referrer-Policy: no-referrer/);
assert.match(profile,/Cache-Control: private, no-store/);
assert.match(profile,/resume:<\?= json_encode\(\$webmcpResume/);
assert.match(profile,/data-profile-webmcp-resume/);
assert.match(profile,/vp3:webmcp-resume-continue/);
assert.match(profile,/vp3:webmcp-resume-result/);

assert.match(runtime,/async resume\(resume\)/);
assert.match(runtime,/vp3:webmcp-resume/);
assert.match(runtime,/vp3:webmcp-resume-continue/);
assert.match(runtime,/vp3:webmcp-resume-result/);
assert.match(runtime,/vp3\.intent\.resolve/,'resume must refresh intent with read-only resolver');
for(const name of [
  'vp3.profile.get',
  'vp3.booking.options.list',
  'vp3.commerce.products.list',
  'vp3.campaigns.list',
  'vp3.rewards.wallet.get',
  'vp3.loyalty.status.get'
]) assert.match(runtime,new RegExp(name.replaceAll('.','\\.')));
const safeBlock=runtime.match(/const safeZeroInput=new Set\(\[[\s\S]*?\]\);/)?.[0]||'';
assert.ok(safeBlock,'safe zero-input resume allowlist must be inspectable');
assert.doesNotMatch(safeBlock,/\.confirm['"]/,'resume Continue must not allow confirm tools');
assert.doesNotMatch(safeBlock,/\.prepare['"]/,'resume Continue must not allow prepare tools');
assert.match(runtime,/searchParams\.delete\('webmcp_resume'\)/,'opaque token must be removed from browser URL');

assert.match(css,/Profile WebMCP v1\.94 cross-surface continuity/);
assert.match(css,/profile-webmcp-resume/);

assert.match(workflow,/profile-webmcp-continuity-v194\.php/);
assert.match(workflow,/profile-webmcp-continuity-v194-contract\.mjs/);
assert.match(workflow,/profile-webmcp-continuity-v194-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-continuity-v194-contract\.mjs/);
assert.match(recovery,/profile-webmcp-continuity-v194-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-continuity-v194\.php/);

console.log('PROFILE_WEBMCP_CONTINUITY_V194_CONTRACT=PASS');
