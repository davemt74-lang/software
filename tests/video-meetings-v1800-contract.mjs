import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');
const core = read('includes/video-meetings-v1800.php');
const security = read('includes/video-meetings-security-v1800.php');
const agent = read('includes/video-meetings-agent-v1800.php');
const bridge = read('includes/video-meetings-transcription-v1800.php');
const reconcile = read('includes/video-meetings-reconcile-v1800.php');
const tokenApi = read('api/video-meeting-token.php');
const presenceApi = read('api/video-meeting-presence.php');
const transcriptApi = read('api/video-meeting-transcript.php');
const workerApi = read('api/video-meeting-worker.php');
const meeting = read('meeting.php');
const meetings = read('meetings.php');
const meetingJs = read('video-meetings-v1800.js');
const ics = read('meeting-ics.php');
const schedulingType = read('scheduling-type.php');
const scheduling = read('scheduling.php');
const calendar = read('calendar.php');
const nav = read('includes/member-navigation.php');
const upgrade = read('upgrade.php');
const config = read('config-example.php');
const pythonWorker = read('workers/vp3-meeting-agent/agent.py');
const workerReadme = read('workers/vp3-meeting-agent/README.md');

// Durable VP3 ownership and privacy defaults.
for (const table of ['video_meetings','video_meeting_participants','video_meeting_transcript_segments']) {
  assert.ok(core.includes(`CREATE TABLE IF NOT EXISTS ${table}`), `missing durable ${table} schema`);
}
assert.match(core, /recording_enabled TINYINT\(1\) NOT NULL DEFAULT 0/);
assert.ok(core.includes('bin2hex(random_bytes(16))'), 'meeting public id must be opaque/random');
assert.ok(core.includes('bin2hex(random_bytes(32))'), 'invite capability must use a high-entropy token');
assert.ok(meetings.includes("'recording_enabled'=>false"), 'recording must remain off by default');
assert.ok(meetings.includes('Phase 18.0 is notes-first'));
assert.ok(meetings.includes("$agentMode=(string)($_POST['agent_mode']??'notes')==='off'?'off':'notes'"), 'server must constrain Phase 18 Agent mode to notes/off');
assert.ok(!meetings.includes("'assistant'=>'Assistant'"), 'spoken Assistant mode must not be advertised before voice policy ships');

// One access boundary for browser, API and calendar surfaces. A member-bound
// invitation requires that exact signed-in VP3 account; guest links remain
// bearer capabilities. The organizer is rebound to the organizer participant.
assert.ok(security.includes('function video_meeting_secure_access_v1800'));
for (const [name, source] of [['meeting room',meeting],['token API',tokenApi],['presence API',presenceApi],['ICS',ics]]) {
  assert.ok(source.includes('video_meeting_secure_access_v1800'), `${name} must enforce member-bound invitations`);
}
assert.ok(security.includes('video_meeting_member_binding_allowed_v1800'));
assert.ok(security.includes('if(!$user)return false'), 'member-bound invitation must not work logged out');
assert.ok(security.includes("role='organizer'"), 'owner access must resolve back to organizer participant');
assert.ok(security.includes('This invitation is bound to your VP3 account'), 'member invitation copy must disclose its sign-in requirement');
assert.ok(security.includes('Keep the link private because it grants meeting access'), 'guest invitation must disclose bearer capability risk');
assert.ok(meetings.includes('video_meeting_secure_invitation_email_v1800'));
assert.ok(security.includes('video_meeting_agent_worker_ready_v1800'));
assert.ok(meetings.includes('transcription worker setup required'), 'UI must distinguish media readiness from worker readiness');

// LiveKit credentials remain server-side and Agent dispatch is explicit/idempotent.
assert.ok(core.includes('video_meeting_livekit_participant_token_v1800'));
assert.ok(agent.includes('AgentDispatchService'));
assert.ok(agent.includes("'ListDispatch'"));
assert.ok(agent.includes("'CreateDispatch'"));
assert.ok(agent.includes("'agent_name'=>$worker"));
assert.ok(agent.includes('video_meeting_agent_dispatch_metadata_v1800'));
assert.ok(!meetingJs.includes('api_secret'));
assert.ok(!meetingJs.includes('worker_secret'));

// Paid appointments never become a side door around Commerce checkout.
assert.ok(reconcile.includes('video_meeting_booking_payment_pending_v1800'));
assert.ok(reconcile.includes("==='awaiting_payment'"));
assert.ok(tokenApi.includes("==='awaiting_payment'"));
assert.ok(tokenApi.includes('Complete the appointment payment before joining this meeting.'));

// Worker callback uses a separate server-to-server secret and final-only ingest.
assert.ok(config.includes("'worker_secret'"));
assert.ok(bridge.includes('VP3_MEETING_WORKER_SECRET'));
assert.ok(workerApi.includes('Authorization'));
assert.ok(workerApi.includes('hash_equals($secret,$provided)'));
assert.ok(workerApi.includes("empty($input['is_final'])"));
assert.ok(workerApi.includes('video_meeting_transcription_append_v1800'));
assert.ok(workerApi.includes("time()-$endedAt>300"), 'late final STT needs a bounded post-end flush grace');
assert.ok(workerApi.includes("['cancelled','processed']"), 'cancelled/processed meetings must remain closed to transcript writes');

// Meeting transcript is projected into the existing VP3 transcription system,
// not a second AI-summary architecture.
assert.ok(bridge.includes('artist_transcript_sessions_v172'));
assert.ok(bridge.includes('artist_transcript_segments_v172'));
assert.ok(bridge.includes('video_meeting_transcription_links'));
assert.ok(bridge.includes('transcription_app_registry_public_v307'));
assert.ok(!bridge.includes('video_meeting_artifacts'), 'canonical transcription bridge must not write a parallel analysis store');
assert.ok(upgrade.includes('video_meeting_transcription_schema_ready_v1800()'));
assert.ok(upgrade.includes('video_meeting_transcription_ensure_schema_v1800($pdo)'));
assert.ok(tokenApi.includes('video_meeting_transcription_ensure_session_v1800'));
assert.ok(presenceApi.includes('video_meeting_transcription_finalize_v1800'));

// Live room consumes transcript incrementally without interrupting media.
assert.ok(meeting.includes("'transcriptEndpoint'=>url('/api/video-meeting-transcript.php')"));
assert.ok(meeting.includes('video-meetings-transcript-v1800.css'));
assert.ok(transcriptApi.includes('video_meeting_transcription_segments_v1800'));
assert.ok(meetingJs.includes('pollTranscript'));
assert.ok(meetingJs.includes('lastTranscriptId'));
assert.ok(meetingJs.includes('textContent=String(segment.transcript_text'));

// Real LiveKit Agent worker subscribes to participant audio and posts only final
// transcript events back through the authenticated callback.
assert.ok(pythonWorker.includes('@server.rtc_session(agent_name=AGENT_NAME)'));
assert.ok(pythonWorker.includes('AutoSubscribe.AUDIO_ONLY'));
assert.ok(pythonWorker.includes('rtc.AudioStream(track)'));
assert.ok(pythonWorker.includes('stt.SpeechEventType.FINAL_TRANSCRIPT'));
assert.ok(pythonWorker.includes('source_key = hashlib.sha256'));
assert.ok(pythonWorker.includes('/api/video-meeting-worker.php'));
assert.ok(workerReadme.includes('notes-first'));

// Existing product surfaces are extended rather than duplicated.
assert.ok(schedulingType.includes("'vp3_video'=>'VP3 Video Meeting'"));
assert.ok(scheduling.includes('vp3_video'));
assert.ok(calendar.includes('Join VP3 Meeting'));
assert.ok(nav.includes("'meetings.php'=>'meetings'"));
assert.ok(nav.includes("url('/meetings.php')"));

console.log('Video Meetings v18.0 contract passed.');
