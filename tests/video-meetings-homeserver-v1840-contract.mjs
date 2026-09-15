import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');
const home = read('includes/video-meetings-homeserver-v1840.php');
const legacyHome = read('includes/video-meetings-homeserver-v1801.php');
const lifecycle = read('includes/agent-appointment-lifecycle-v700.php');
const tokenApi = read('api/video-meeting-token.php');
const meetingJs = read('video-meetings-v1800.js');
const registry = read('includes/homeserver-capability-registry-v033.php');

// Load the real sanitized capability registry, not the obsolete guessed path.
assert.ok(home.includes("homeserver-capability-registry-v033.php"));
assert.ok(!home.includes("homeserver-capability-v033.php"));
assert.ok(lifecycle.includes("require_once __DIR__.'/video-meetings-homeserver-v1840.php'"));
assert.ok(registry.includes('function homeserver_capability_v033_registry'));

// Metadata is not execution. HomeServer meeting readiness requires a concrete
// executor in addition to an authenticated advertised operation.
assert.ok(home.includes("'meeting.transcription.stream','transcription.stream','transcription.start'"));
assert.ok(home.includes("function_exists('video_meeting_homeserver_transcription_execute_v1840')"));
assert.ok(home.includes("'capability_advertised_unwired'"));
assert.ok(home.includes("'homeserver_advertised_unwired_cloud_fallback'"));
assert.ok(home.includes('generic\n    // relay transport'), 'contract must document why generic relay availability alone is insufficient');

// HomeServer-only/private policy fails closed when the concrete meeting runtime
// is absent. Automatic may use VP3 Cloud only when the v18.1 privacy policy
// already authorizes cloud processing.
assert.ok(home.includes("$requested==='homeserver_only'"));
assert.ok(home.includes("$state['route']='blocked';$state['status']='required_unavailable'"));
assert.ok(home.includes("if($cloudAllowed)"));
assert.ok(legacyHome.includes("video_meeting_transcription_ai_policy_v1801($pdo,$user,$session,true)"));
assert.ok(legacyHome.includes('VP3 Cloud AI Summary is disabled for this transcript.'));

// Terminal meeting states never advertise a new processing route.
for (const status of ['cancelled','ended','processed','no_show']) {
  assert.ok(home.includes(`'${status}'`), `terminal ${status} must be covered`);
}
assert.ok(home.includes("'reason_code']='meeting_terminal'"));

// Non-owner requests must never probe the organizer's private runtime.
assert.ok(home.includes('video_meeting_homeserver_owner_probe_allowed_v1840'));
assert.ok(home.includes('if(!$ownerProbe)'));
assert.ok(home.includes("$viewer['id']"));

// Browser status is explicit allow-list output. Do not add infrastructure or
// secret-bearing fields to this public object.
assert.ok(home.includes('function video_meeting_homeserver_public_status_v1840'));
for (const forbidden of ['relay_token','homeserver_token','device_id','endpoint','base_url','registry','credentials']) {
  const publicBlock = home.slice(home.indexOf('function video_meeting_homeserver_public_status_v1840'), home.indexOf('function video_meeting_homeserver_route_label_v1840'));
  assert.ok(!publicBlock.includes(`'${forbidden}'`), `public status must not expose ${forbidden}`);
}

// Join-token output uses only the sanitized v18.4 status and preserves the
// existing secure meeting access boundary and scalar route compatibility.
assert.ok(tokenApi.includes('video_meeting_secure_access_v1800'));
assert.ok(tokenApi.includes('video_meeting_homeserver_public_status_v1840'));
assert.ok(tokenApi.includes("'processing_route'=>$processingRoute"));
assert.ok(tokenApi.includes("'processing_status'=>$processingStatus"));
assert.ok(tokenApi.includes("!empty($access['is_organizer'])"));
assert.ok(!tokenApi.includes('homeserver_capability_v033_registry'));
assert.ok(!tokenApi.includes('homeserver_agent_v018_credentials'));

// The live Meeting UI consumes only the sanitized token response. Private
// processing failures remain distinct from LiveKit media connectivity.
assert.ok(meetingJs.includes('function handleProcessingStatus(status)'));
assert.ok(meetingJs.includes('auth?.meeting?.processing_status'));
assert.ok(meetingJs.includes('Private processing required · HomeServer meeting processing unavailable'));
assert.ok(meetingJs.includes('Private processing required · organizer resolves HomeServer readiness'));
assert.ok(!meetingJs.includes('relay_token'));
assert.ok(!meetingJs.includes('homeserver_token'));

console.log('Video Meetings Phase 18.4 HomeServer routing contract passed.');
