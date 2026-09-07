import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const radar = read('includes/agent-radar-foundation.php');
const subscription = read('includes/subscription-schema.php');
const bootstrap = read('includes/bootstrap.php');
const upgrade = read('upgrade.php');

for (const table of [
  'vp3_radar_properties',
  'vp3_agent_registry',
  'vp3_agent_contacts',
  'vp3_radar_sessions',
  'vp3_radar_events',
  'vp3_agent_policies',
]) {
  assert.ok(radar.includes(table), `Agent Radar foundation must own ${table}`);
}

assert.ok(radar.includes("const VP3_RADAR_SITE_CAPABILITY = 'analytics.sites'"), 'external-site tracking must use a package limit capability');
assert.ok(radar.includes("const VP3_AGENT_MESSAGING_CAPABILITY = 'agent.messaging'"), 'agent-to-agent messaging must use a package capability');
assert.ok(radar.includes('subscription_entitlement_limit'), 'external property capacity must come from the canonical package entitlement runtime');
assert.ok(radar.includes('subscription_has_entitlement'), 'agent messaging access must use the canonical entitlement runtime');
assert.ok(!radar.includes('ai_token_credits') && !radar.includes('ai_usage_ledger'), 'Radar foundation must not create a second AI token/accounting system');

assert.ok(subscription.includes("'analytics.sites'"), 'Admin package catalog must expose the external tracked-site limit');
assert.ok(subscription.includes("'agent.messaging'"), 'Admin package catalog must expose agent-to-agent messaging');

assert.ok(radar.includes('ChatGPT-User') && radar.includes('Claude-User') && radar.includes('Perplexity-User'), 'known user-directed agent signatures must be seeded');
assert.ok(radar.includes('OAI-SearchBot') && radar.includes('GPTBot') && radar.includes('ClaudeBot'), 'search/crawler identities must be separately classified');
assert.ok(radar.includes('verification_status') && radar.includes('confidence_score'), 'recognized identity must remain distinct from verification/confidence');
assert.ok(radar.includes('trust_score') && radar.includes('risk_score') && radar.includes('value_score') && radar.includes('cost_score'), 'CRM agent contacts need relationship intelligence scores');
assert.ok(radar.includes('relationship_status') && radar.includes('inferred_intent'), 'agent contacts need durable relationship and intent fields');

assert.ok(radar.includes("return 'critical'") && radar.includes("return 'high'"), 'risk scoring must normalize high and critical severity');
assert.ok(radar.includes('vp3_radar_recent_high_risk'), 'Agent Chat/notifications need a deterministic high-risk read path');
assert.ok(radar.includes('vp3_agent_policies'), 'Gateway policy storage must be established in the foundation');
assert.ok(radar.includes("action VARCHAR(30) NOT NULL DEFAULT 'monitor'"), 'new policy records must default safely to monitor rather than silent allow/block');

assert.ok(!/\bip_address\b|\braw_ip\b|VARCHAR\([^)]*\).*\bip\b/i.test(radar), 'Radar storage must not persist a raw IP address field');
assert.ok(!radar.includes('ai_openai_response(') && !radar.includes('ai_stream_') && !radar.includes('curl_exec('), 'foundation collection/scoring must not invoke an LLM or remote provider');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-radar-foundation.php';"), 'Agent Radar foundation must load through the canonical bootstrap');
assert.ok(upgrade.includes('vp3_radar_schema_ready()'), 'database upgrade readiness must include Agent Radar');
assert.ok(upgrade.includes('vp3_radar_ensure_schema();'), 'database upgrade must install Agent Radar schema');

console.log('AGENT_RADAR_FOUNDATION_CONTRACT=PASS');
