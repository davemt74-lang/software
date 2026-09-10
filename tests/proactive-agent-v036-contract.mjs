import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const helper = read('includes/agent-proactive-operations-v036.php');
const proactive = read('includes/agent-proactive-v123.php');
const bootstrap = read('includes/bootstrap.php');
const cognitive = read('includes/agent-cognitive-loop-v310.php');
const notifications = read('includes/notifications.php');

assert.match(helper, /VP3_AGENT_PROACTIVE_OPERATIONS_V036/);
assert.match(helper, /function agent_proactive_operations_v036_candidates/);
assert.match(helper, /function agent_proactive_operations_v036_profile_candidate/);
assert.match(helper, /function agent_proactive_operations_v036_homeserver_state/);
assert.match(helper, /function agent_proactive_operations_v036_recent_cloud_fallbacks/);

// Section 7 must be an input to the existing evidence/cognitive pipeline, not a
// second dashboard, feed, notification queue, or execution path.
assert.match(proactive, /agent_proactive_operations_v036_candidates\(\$pdo,\$user\)/);
assert.match(proactive, /agent_proactive_v123_evidence_candidates/);
assert.match(cognitive, /agent_cognitive_loop_v310_base_candidates/);
assert.match(cognitive, /agent_chat_v101_append_ecosystem_message/);
assert.match(cognitive, /VP3_AGENT_COGNITIVE_LOOP_SURFACE_THRESHOLD_V310/);
assert.match(cognitive, /agent_action_v124_suppression/);
assert.match(cognitive, /agent_action_v124_mark_shown/);

const providerLoad = bootstrap.indexOf("require_once __DIR__.'/agent-proactive-operations-v036.php';");
const cognitiveLoad = bootstrap.indexOf("require_once __DIR__.'/agent-cognitive-loop-v310.php';");
assert.ok(providerLoad > 0 && cognitiveLoad > providerLoad, 'v0.36 provider must load before cognitive loop');

// Operational state is intentionally privacy-minimized.
assert.match(helper, /SELECT u\.display_name,u\.avatar_path,p\.username,p\.bio/);
assert.match(helper, /'username' => 'username'/);
assert.match(helper, /'display_name' => 'display name'/);
assert.match(helper, /'bio' => 'bio'/);
assert.match(helper, /'avatar_path' => 'profile image'/);
assert.match(helper, /SELECT status,last_seen_at,capabilities_json FROM homeserver_connections/);
assert.match(helper, /SELECT COUNT\(\*\) FROM ai_execution_ledger/);
// v4.20 uses the canonical actual route when available, but keeps source as the
// compatibility fallback on installations that have not completed the upgrade.
assert.match(helper, /created_at>=\?/);
assert.doesNotMatch(helper, /occurred_at>=\?/);
assert.match(helper, /column_exists\('ai_execution_ledger', 'actual_route'\)/);
assert.match(helper, /actual_route='vp3_cloud'/);
assert.match(helper, /source='vp3_cloud'/);
assert.match(helper, /fallback_used=1/);
assert.match(helper, /in_array\('agent\.chat', \$features, true\)/);

// Never make a relay/network request or pull HomeServer credentials/errors from
// a background proactive scan. Never bypass the canonical attention system.
for (const forbidden of [
  'curl_',
  'homeserver_vp3_remote_operation',
  'homeserver_vp3_relay_request',
  'homeserver_vp3_status(',
  'relay_token',
  'homeserver_token',
  'last_error',
  'create_notification(',
  'notification_attention_after(',
]) {
  assert.equal(helper.includes(forbidden), false, `forbidden proactive provider dependency: ${forbidden}`);
}

// CRM opportunity detail continues to come from the existing ecosystem scanner;
// v0.36 itself must not open lead/message bodies or invent a parallel CRM query.
for (const forbidden of [
  'FROM crm_',
  'FROM leads',
  'lead_message',
  'message_body',
  'email_body',
]) {
  assert.equal(helper.toLowerCase().includes(forbidden.toLowerCase()), false, `raw CRM dependency detected: ${forbidden}`);
}

// Ordinary Agent Brain activity remains excluded from direct attention rows;
// only the existing cognitive loop decides whether a ranked recommendation is
// important enough to surface in Main Feed.
assert.match(notifications, /notification_is_agent_brain_activity/);
assert.match(notifications, /if \(notification_is_agent_brain_activity\(\$notification\)\)/);

console.log('Proactive Agent Operations v0.36 contract: PASS through canonical v4.20 route telemetry');
