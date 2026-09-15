import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(path, 'utf8');
const lifecycle = read('includes/agent-appointment-lifecycle-v700.php');
const intelligence = read('includes/video-meetings-intelligence-v1820.php');
const api = read('api/video-meeting-intelligence.php');
const presence = read('api/video-meeting-presence.php');
const meeting = read('meeting.php');
const meetings = read('meetings.php');
const mediaJs = read('video-meetings-v1800.js');
const intelJs = read('video-meetings-intelligence-v1820.js');
const upgrade = read('upgrade.php');
const homeserver = read('includes/video-meetings-homeserver-v1801.php');
const transcriptionApi = read('api/artist-listening-intelligence-v300.php');

// Phase 18.2 extends the existing appointment/video meeting runtime rather than
// introducing a second boot path.
assert.ok(lifecycle.includes("require_once __DIR__.'/video-meetings-intelligence-v1820.php'"));
assert.ok(intelligence.includes("const VP3_VIDEO_MEETINGS_INTELLIGENCE_V1820='video-meetings-intelligence-v1820-20260915'"));

// Durable Meeting Intelligence owns only orchestration/private organizer state.
// Canonical analysis remains in the existing Transcription Intelligence store.
for (const table of ['video_meeting_intelligence_state','video_meeting_notes','video_meeting_objectives']) {
  assert.ok(intelligence.includes(`CREATE TABLE IF NOT EXISTS ${table}`), `missing ${table}`);
}
assert.ok(!intelligence.includes('INSERT INTO video_meeting_artifacts'), 'Phase 18.2 must not create a parallel meeting analysis store');
assert.ok(!intelligence.includes('UPDATE video_meeting_artifacts'), 'Phase 18.2 must not mutate the legacy parallel artifact store');
assert.ok(intelligence.includes('artist_listening_v172_segments'));
assert.ok(intelligence.includes('artist_listening_transcript_page_map'));
assert.ok(intelligence.includes('artist_listening_v237_analysis_status'));
assert.ok(intelligence.includes('transcription_app_modules_v306'));
assert.ok(intelligence.includes("$result('summary_output')"));
assert.ok(intelligence.includes("$result('action_plan')"));
assert.ok(intelligence.includes("$result('decisions')"));
assert.ok(intelligence.includes("$result('qa')"));
assert.ok(intelligence.includes("$result('crm')"));

// The organizer's HomeServer/cloud processing policy remains authoritative.
assert.ok(intelligence.includes('video_meeting_transcription_ai_policy_v1801'));
assert.ok(homeserver.includes('video_meeting_transcription_assert_cloud_ai_v1801'));
assert.ok(transcriptionApi.includes('video_meeting_transcription_assert_cloud_ai_v1801'));
assert.ok(intelJs.includes("policy.cloud_ai_allowed!==true"));
assert.ok(intelJs.includes("policy.requested_compute==='homeserver_only'"));
assert.ok(!intelJs.includes('api.openai.com'));
assert.ok(!intelJs.includes('api.anthropic.com'));

// Private meeting intelligence is owner-only at the API boundary, not merely
// hidden by browser UI. Every mutation also carries canonical meeting access + CSRF.
assert.ok(api.includes("REQUEST_METHOD']!=='POST'"));
assert.ok(api.includes('current_user()'));
assert.ok(api.includes('verify_csrf()'));
assert.ok(api.includes('video_meeting_secure_access_v1800'));
assert.ok(api.includes('video_meeting_intelligence_owner_allowed_v1820'));
assert.ok(api.includes('Meeting Intelligence is private to the organizer.'));
assert.ok(meeting.includes('data-meeting-private'));
assert.ok(intelJs.includes("if(!boot.isOrganizer)"));

// Rolling/final analysis reuses the canonical Transcription Intelligence API
// and a deliberate plugin set. CRM remains a final/review-time advisory input.
assert.ok(meeting.includes("'transcriptionIntelligenceEndpoint'=>url('/api/artist-listening-intelligence-v300.php')"));
assert.ok(intelJs.includes('boot.transcriptionIntelligenceEndpoint'));
for (const id of ['basic','actions','decisions','qa','followup','risks','topics']) {
  assert.ok(api.includes(`'${id}'`), `missing rolling app ${id}`);
}
for (const id of ['requirements','crm']) assert.ok(api.includes(`'${id}'`), `missing final app ${id}`);
assert.ok(intelJs.includes("mode==='final'?'manual':'live'"));
assert.ok(intelJs.includes("web_research:false"), 'automatic meeting intelligence must not leak meeting content into web research');
assert.ok(intelJs.includes("action:'analyze'"));
assert.ok(intelJs.includes("action:'record_analysis'"));

// Live intelligence is driven by new canonical transcript segments, while room
// end remains fast and only marks durable post-meeting work for follow-up.
assert.ok(mediaJs.includes("vp3:meeting-transcript-updated"));
assert.ok(mediaJs.includes("vp3:meeting-ended"));
assert.ok(intelJs.includes("window.addEventListener('vp3:meeting-transcript-updated'"));
assert.ok(intelJs.includes("window.addEventListener('vp3:meeting-ended'"));
assert.ok(presence.includes('video_meeting_intelligence_mark_ended_v1820'));
assert.ok(!presence.includes("action:'analyze'"), 'presence endpoint must not block room termination on cloud AI');

// Private notes/objectives are explicitly organizer-scoped and do not silently
// turn AI output into durable CRM/Knowledge/Task mutations.
assert.ok(intelligence.includes('Only the organizer can edit private meeting notes.'));
assert.ok(intelligence.includes('Only the organizer can manage meeting objectives.'));
assert.ok(meeting.includes('meetingPrivateNotes'));
assert.ok(meeting.includes('Private organizer notes'));
assert.ok(meeting.includes('meetingObjectiveInput'));
assert.ok(meeting.includes('meetingIntelligenceCrm'));
assert.ok(meeting.includes('CRM recommendations stay advisory'));

// Pre-meeting prep comes from the existing scheduling/lifecycle brief, and the
// post-meeting summary is handed back to the existing Agent Chat command center.
assert.ok(intelligence.includes('agent_appointment_lifecycle_brief_v700'));
assert.ok(intelligence.includes('agent_chat_v101_append_ecosystem_message'));
assert.ok(intelligence.includes("'skip_brain_archive'=>true"), 'generated handoff must not create an Agent Brain feedback loop');
assert.ok(intelligence.includes("'source'=>'video_meeting_intelligence'"));
assert.ok(intelligence.includes('Review the linked meeting intelligence before promoting items into CRM, Tasks, Knowledge or external follow-up.'));
assert.ok(intelligence.includes('already_published'));

// The existing meeting surface becomes the live and post-meeting intelligence
// workspace; meeting history exposes review without adding another dashboard.
for (const pane of ['summary','actions','decisions','questions','objectives','notes','crm','activity']) {
  assert.ok(meeting.includes(`data-pane="${pane}"`), `missing ${pane} meeting pane`);
}
assert.ok(meeting.includes('$reviewMode'));
assert.ok(meetings.includes('Review Meeting Intelligence'));
assert.ok(intelJs.includes('setupReviewTabs'));
assert.ok(intelJs.includes('meetingFullIntelligenceLinkSecondary'));
assert.ok(intelJs.includes('textContent='), 'meeting intelligence rendering must use safe text nodes');

// Spoken/Active Agent participation remains outside this phase.
assert.ok(intelligence.includes("'active_agent_available'=>false"));
assert.ok(meetings.includes('Spoken Agent participation stays disabled'));

// upgrade.php must make the new capability installable and readiness-gated.
assert.ok(upgrade.includes('video_meeting_intelligence_schema_ready_v1820()'));
assert.ok(upgrade.includes('video_meeting_intelligence_ensure_schema_v1820($pdo)'));

console.log('Video Meetings Phase 18.2 Meeting Intelligence contract passed.');
