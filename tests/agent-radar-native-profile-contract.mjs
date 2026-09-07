import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const native = read('includes/agent-radar-native-profile.php');
const foundation = read('includes/agent-radar-foundation.php');
const runtime = read('includes/profile-agent-runtime.php');
const bootstrap = read('includes/bootstrap.php');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-radar-native-profile.php';"), 'bootstrap must load the native Radar runtime');
assert.ok(runtime.includes('vp3_radar_record_native_profile_request($pdo,$profile,$visitor)'), 'canonical profile view runtime must invoke Radar before human visitor storage');
assert.ok(
  runtime.indexOf('vp3_radar_record_native_profile_request($pdo,$profile,$visitor)') < runtime.indexOf('profile_runtime_session($pdo,$owner,$visitor,true)'),
  'automated visitors must be intercepted before profile_visit_sessions is created'
);
assert.ok(runtime.includes("'agent_contacts'=>$agentContacts"), 'owner Profile Agent state must expose Agent CRM contacts separately from human contacts');
assert.ok(runtime.includes("'agent_events_24h'"), 'owner state must expose recent Radar volume');
assert.ok(runtime.includes("'agent_high_risk_24h'"), 'owner state must expose high-risk Radar volume');

for (const ua of ['chatgpt-user','oai-searchbot','gptbot','claude-user','claudebot','claude-searchbot','perplexity-user','perplexitybot']) {
  assert.ok(native.toLowerCase().includes(ua), `native detection must recognize ${ua}`);
}
assert.ok(native.includes("'automated_unknown'"), 'unregistered obvious automation must remain a distinct unknown-agent class');
assert.ok(native.includes("$verification = $registry ? 'known' : 'unknown'"), 'signature recognition must not be mislabeled as cryptographic verification');
assert.ok(native.includes('has not been cryptographically verified'), 'owner-facing alerts must explain the verification boundary');
assert.ok(native.includes('VP3_RADAR_NATIVE_SESSION_SECONDS = 1800'), 'agent activity must use coarse 30-minute sessions instead of fingerprinting');
assert.ok(native.includes("hash('sha256', $propertyId . '|' . $contactId . '|' . $bucket)"), 'session grouping must use aggregate contact/time data');
assert.ok(!native.includes('REMOTE_ADDR'), 'native Radar must not persist or depend on raw IP addresses');
assert.ok(!foundation.includes('ip_address'), 'Radar foundation must remain free of raw IP storage');

assert.ok(native.includes('vp3_agent_contacts'), 'native requests must aggregate into Agent CRM contacts');
assert.ok(native.includes('vp3_radar_sessions'), 'native requests must create Radar sessions');
assert.ok(native.includes('vp3_radar_events'), 'native requests must create normalized Radar events');
assert.ok(native.includes('session_count=session_count+?'), 'Agent CRM must aggregate session history on one identity');
assert.ok(native.includes('request_count=request_count+1'), 'Agent CRM must aggregate request history');
assert.ok(native.includes('page_view_count=page_view_count+1'), 'Agent CRM must aggregate page history');
assert.ok(native.includes('referral_count=referral_count+?'), 'Agent CRM must retain referral activity');
assert.ok(native.includes('engagement_score=LEAST(100'), 'Agent CRM must maintain engagement scoring');
assert.ok(native.includes('value_score=?'), 'Agent CRM must maintain value scoring');
assert.ok(native.includes('cost_score=LEAST(100'), 'Agent CRM must maintain cost scoring');
assert.ok(native.includes('risk_score=GREATEST'), 'Agent CRM must retain peak risk scoring');

assert.ok(native.includes('vp3_radar_risk_score($signals)'), 'native requests must use the canonical deterministic risk model');
assert.ok(native.includes("'radar_security_action'"), 'high-risk activity must enter the existing attention pipeline');
assert.ok(native.includes("'radar_agent_visit_needs_attention'"), 'meaningful AI/search visits must enter Main Feed attention');
assert.ok(native.includes("'agent_activity_radar_visit'"), 'routine crawler activity must use the existing Agent Brain operational feed');
assert.ok(native.includes('agent_brain_v122_upsert_system_memory'), 'Radar identities must become canonical Agent Brain system memory');
assert.ok(native.includes("'agent_radar'"), 'Radar Brain memory must use a distinct memory type');
assert.ok(!/ai_(?:openai|anthropic|gemini|provider|stream)/i.test(native), 'collection and scoring must not invoke an AI provider');

assert.ok(native.includes('if (!vp3_radar_schema_ready($pdo)) return true;'), 'automated traffic must stay out of human CRM even during a partial schema rollout');
assert.ok(native.includes("error_log('Agent Radar native profile collection failed:"), 'Radar collection must fail open without breaking the public profile');

console.log('AGENT_RADAR_NATIVE_PROFILE_CONTRACT=PASS');
