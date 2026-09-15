import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');
const core = read('includes/video-meetings-v1800.php');
const security = read('includes/video-meetings-security-v1800.php');
const home = read('includes/video-meetings-homeserver-v1801.php');
const calendarBridge = read('includes/video-meetings-calendar-v1801.php');
const agent = read('includes/video-meetings-agent-v1800.php');
const bridge = read('includes/video-meetings-transcription-v1800.php');
const reconcile = read('includes/video-meetings-reconcile-v1800.php');
const tokenApi = read('api/video-meeting-token.php');
const presenceApi = read('api/video-meeting-presence.php');
const transcriptApi = read('api/video-meeting-transcript.php');
const workerApi = read('api/video-meeting-worker.php');
const intelligenceApi = read('api/artist-listening-intelligence-v300.php');
const longTranscriptApi = read('api/artist-listening-long-v237.php');
const lifecycle = read('includes/agent-appointment-lifecycle-v700.php');
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
assert.ok(meetings.includes('remains notes-first'));
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

// Security-sensitive outbound links use a configured canonical origin rather
// than trusting an arbitrary Host header on production requests.
assert.ok(security.includes('function video_meeting_public_origin_v1801'));
assert.ok(security.includes("$config['site']['base_url']"));
assert.ok(security.includes('Configure site.base_url before sending VP3 meeting invitations.'));
assert.ok(security.includes('video_meeting_secure_invite_url_v1801'));
assert.ok(ics.includes('video_meeting_secure_invite_url_v1801'));

// LiveKit credentials remain server-side and Agent dispatch is explicit/idempotent.
assert.ok(core.includes('video_meeting_livekit_participant_token_v1800'));
assert.ok(agent.includes('AgentDispatchService'));
assert.ok(agent.includes("'ListDispatch'"));
assert.ok(agent.includes("'CreateDispatch'"));
assert.ok(agent.includes("'agent_name'=>$worker"));
assert.ok(agent.includes('video_meeting_agent_dispatch_metadata_v1800'));
assert.ok(agent.includes("'room_name'=>(string)$meeting['room_name']"), 'worker dispatch must bind metadata to the exact LiveKit room');
assert.ok(!agent.includes("'owner_user_id'=>(int)$meeting['owner_user_id']"), 'LiveKit dispatch must not receive internal owner IDs');
assert.ok(!agent.includes("'organizer_agent_id'=>(int)($meeting['organizer_agent_id']??0)"), 'LiveKit dispatch must not receive internal Agent IDs');
assert.ok(!meetingJs.includes('api_secret'));
assert.ok(!meetingJs.includes('worker_secret'));

// Paid appointments never become a side door around Commerce checkout and
// reconciliation fails closed when Commerce authorization state is uncertain.
assert.ok(reconcile.includes('video_meeting_booking_payment_pending_v1800'));
assert.ok(reconcile.includes("==='awaiting_payment'"));
assert.ok(reconcile.includes("throw new RuntimeException('Paid appointment status could not be verified.'"));
assert.ok(tokenApi.includes("==='awaiting_payment'"));
assert.ok(tokenApi.includes('Complete the appointment payment before joining this meeting.'));
assert.ok(reconcile.includes("$_SESSION['vp3_video_meeting_reconcile_at']"), 'background reconciliation must be throttled on ordinary page traffic');

// Worker callback uses a separate server-to-server secret, exact room binding
// and final-only ingest.
assert.ok(config.includes("'worker_secret'"));
assert.ok(bridge.includes('VP3_MEETING_WORKER_SECRET'));
assert.ok(workerApi.includes('HTTP_AUTHORIZATION'), 'worker callback must read the Authorization header from the PHP server environment');
assert.ok(workerApi.includes("/^Bearer\\s+(.+)$/i"), 'worker callback must require Bearer authentication');
assert.ok(workerApi.includes('hash_equals($secret,$provided)'));
assert.ok(workerApi.includes("hash_equals((string)$meeting['room_name'],$roomName)"), 'worker callback must be bound to the exact LiveKit room');
assert.ok(workerApi.includes("empty($input['is_final'])"));
assert.ok(workerApi.includes('video_meeting_transcription_append_v1800'));
assert.ok(workerApi.includes("time()-$endedAt>300"), 'late final STT needs a bounded post-end flush grace');
assert.ok(workerApi.includes("['cancelled','processed']"), 'cancelled/processed meetings must remain closed to transcript writes');

// Meeting transcript is projected into the existing VP3 transcription system,
// not a second AI-summary architecture. Normal ingest must mirror only the new
// segment so long meetings stay O(1) per final STT callback.
assert.ok(bridge.includes('artist_transcript_sessions_v172'));
assert.ok(bridge.includes('artist_transcript_segments_v172'));
assert.ok(bridge.includes('video_meeting_transcription_links'));
assert.ok(bridge.includes('transcription_app_registry_public_v307'));
assert.ok(bridge.includes('video_meeting_transcription_mirror_segment_v1801'));
assert.ok(bridge.includes("$mirror=video_meeting_transcription_mirror_segment_v1801($pdo,$meeting,$segmentId)"));
assert.ok(!bridge.includes('video_meeting_artifacts'), 'canonical transcription bridge must not write a parallel analysis store');
assert.ok(upgrade.includes('video_meeting_transcription_schema_ready_v1800()'));
assert.ok(upgrade.includes('video_meeting_transcription_ensure_schema_v1800($pdo)'));
assert.ok(tokenApi.includes('video_meeting_transcription_ensure_session_v1800'));
assert.ok(presenceApi.includes('video_meeting_transcription_finalize_v1800'));

// Member attendee calendars reuse the canonical provider/token stack while the
// meeting layer owns only its provider event IDs. Guest bearer links must never
// be copied to Google/Microsoft; connected members receive their signed-in VP3
// public-id URL, and provider-side invite/conference duplication stays disabled.
assert.ok(lifecycle.includes("require_once __DIR__.'/video-meetings-calendar-v1801.php'"));
assert.ok(calendarBridge.includes('CREATE TABLE IF NOT EXISTS video_meeting_external_calendar_links'));
assert.ok(calendarBridge.includes("require_once __DIR__.'/agent-calendar-sync-v500.php'"));
assert.ok(calendarBridge.includes('agent_calendar_sync_connections_v500($pdo,$userId)'));
assert.ok(calendarBridge.includes('agent_calendar_sync_api_v500'));
assert.ok(calendarBridge.includes("if($participantId<1||$userId<1||(string)($participant['role']??'')!=='attendee')"), 'guest and organizer rows must not be projected as member attendee calendar events');
assert.ok(calendarBridge.includes("'/meeting.php?meeting='.rawurlencode((string)$meeting['public_id'])"), 'member calendar event must use signed-in public-id meeting URL');
assert.ok(!calendarBridge.includes('invite_token'), 'external calendar bridge must never expose invite bearer tokens');
assert.ok(calendarBridge.includes('?sendUpdates=none'), 'member event projection must not cause duplicate Google invitations');
assert.ok(!calendarBridge.includes('conferenceData'), 'member event projection must not create a competing Google Meet room');
assert.ok(!calendarBridge.includes("'attendees'"), 'member event projection must not generate provider attendee invitations');
assert.ok(meetings.includes('video_meeting_external_calendar_sync_participant_v1801'));
assert.ok(reconcile.includes('video_meeting_external_calendar_sync_members_v1801'));
assert.ok(reconcile.includes('video_meeting_cancel_for_booking_v1800'));
assert.ok(bridge.includes('video_meeting_external_calendar_schema_ready_v1801($pdo)'), 'Video Meetings readiness must own the member-calendar schema');
assert.ok(bridge.includes('video_meeting_external_calendar_ensure_schema_v1801($pdo)'), 'Video Meetings upgrade must install the member-calendar schema');

// Live room consumes transcript incrementally without interrupting media. The
// guest bearer token must not be repeated in a polling URL/query string.
assert.ok(meeting.includes("'transcriptEndpoint'=>url('/api/video-meeting-transcript.php')"));
assert.ok(meeting.includes('video-meetings-transcript-v1800.css'));
assert.ok(transcriptApi.includes("REQUEST_METHOD']!=='POST'"));
assert.ok(transcriptApi.includes('video_meeting_transcription_segments_v1800'));
assert.ok(meetingJs.includes('pollTranscript'));
assert.ok(meetingJs.includes('lastTranscriptId'));
assert.ok(meetingJs.includes('post(boot.transcriptEndpoint'));
assert.ok(!meetingJs.includes("boot.transcriptEndpoint+'?'"), 'transcript bearer capability must not be sent in query-string polling');
assert.ok(meetingJs.includes('textContent=String(segment.transcript_text'));
assert.ok(meetingJs.includes("await post(boot.presenceEndpoint,{action:'join'})"), 'VP3 presence must succeed before the lobby is dismissed');

// Real LiveKit Agent worker subscribes to participant audio, binds itself to
// the dispatched room and gives final STT a bounded drain on disconnect.
assert.ok(pythonWorker.includes('@server.rtc_session(agent_name=AGENT_NAME)'));
assert.ok(pythonWorker.includes('AutoSubscribe.AUDIO_ONLY'));
assert.ok(pythonWorker.includes('rtc.AudioStream(track)'));
assert.ok(pythonWorker.includes('stt.SpeechEventType.FINAL_TRANSCRIPT'));
assert.ok(pythonWorker.includes('source_key = hashlib.sha256'));
assert.ok(pythonWorker.includes('"room_name": room_name'));
assert.ok(pythonWorker.includes('ctx.room.name != expected_room_name'));
assert.ok(pythonWorker.includes('FINAL_DRAIN_SECONDS'));
assert.ok(pythonWorker.includes('asyncio.wait'));
assert.ok(pythonWorker.includes('/api/video-meeting-worker.php'));
assert.ok(workerReadme.includes('notes-first'));

// HomeServer remains optional, but its existing compute/privacy boundary is
// authoritative for live STT and for later AI Summary/plugin processing. A
// status view may use cached policy, while every cloud-AI execution refreshes
// policy immediately and fails closed if meeting ownership cannot be verified.
assert.ok(home.includes('vp3_agent_runtime_preference_v420'));
assert.ok(home.includes('homeserver_scope_v026_blocks_cloud'));
assert.ok(home.includes("$state['requested_compute']==='homeserver_only'"));
assert.ok(home.includes("'meeting.transcription.stream','transcription.stream','transcription.start'"));
assert.ok(home.includes('homeserver_capability_v033_registry'));
assert.ok(home.includes('function video_meeting_transcription_ai_policy_v1801'));
assert.ok(home.includes("capture_mode']??'')!=='vp3_video_meeting'"), 'ordinary transcripts must remain outside meeting policy');
assert.ok(home.includes("'meeting_transcript_owner_mismatch'"));
assert.ok(home.includes("'meeting_binding_unavailable'"));
assert.ok(home.includes('video_meeting_transcription_ai_policy_v1801($pdo,$user,$session,true)'), 'cloud-AI guard must refresh privacy policy immediately before execution');
assert.ok(home.includes('VP3 Cloud AI Summary is disabled for this transcript.'));
assert.ok(agent.includes('video_meeting_cloud_transcription_allowed_v1801'));
assert.ok(agent.includes('homeserver_private_processing_required'));
assert.ok(meeting.includes('HomeServer privacy/compute policy prevents VP3 from dispatching the cloud transcription worker'));
assert.ok(tokenApi.includes("'processing_route'=>$processingRoute"));

// The existing transcription/AI Summary endpoints are the enforcement point;
// Meetings does not create a second summarization stack. Both current plugin
// analysis and the legacy long-transcript AI route must honor meeting privacy.
assert.ok(intelligenceApi.includes("require_once dirname(__DIR__) . '/includes/video-meetings-homeserver-v1801.php'"));
assert.ok(intelligenceApi.includes('video_meeting_transcription_ai_policy_v1801($pdo,$user,$session)'));
assert.ok(intelligenceApi.includes('transcription_app_ids_v306'));
assert.ok(intelligenceApi.includes("$registry[$requestedApp]['execution']??''"));
assert.ok(intelligenceApi.includes('video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$session)'));
assert.ok(intelligenceApi.includes("'meeting_processing_policy'=>$meetingAiPolicyPublic"));
assert.ok(longTranscriptApi.includes('video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$session)'));
assert.ok(longTranscriptApi.includes("$action === 'analyze_page'"));
assert.ok(longTranscriptApi.includes("$action === 'analyze_master'"));
assert.ok(longTranscriptApi.includes("'meeting_processing_policy'=>"));

// Existing product surfaces are extended rather than duplicated.
assert.ok(schedulingType.includes("'vp3_video'=>'VP3 Video Meeting'"));
assert.ok(scheduling.includes('vp3_video'));
assert.ok(calendar.includes('Join VP3 Meeting'));
assert.ok(nav.includes("'meetings.php'=>'meetings'"));
assert.ok(nav.includes("url('/meetings.php')"));

console.log('Video Meetings v18.0/18.1 hardening contract passed.');
