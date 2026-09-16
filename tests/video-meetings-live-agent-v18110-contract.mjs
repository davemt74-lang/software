import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read=p=>readFileSync(p,'utf8');
const live=[read('includes/video-meetings-live-agent-v18110.php'),...Array.from({length:4},(_,i)=>read(`includes/video-meetings-live-agent-v18110-part${i+1}.php`))].join('\n');
const api=read('api/video-meeting-live-agent.php');
const ui=read('video-meetings-live-agent-v18110.js');
const bridge=read('video-meetings-followthrough-v18100.js');
const media=read('includes/video-meetings-agent-v1800.php');

// Phase 18.11 extends existing meeting/artifact state; no second chat/domain schema.
assert.ok(live.includes("VP3_VIDEO_MEETING_LIVE_AGENT_APP_V18110='meeting_live_agent_v18110'"));
assert.ok(live.includes("VP3_VIDEO_MEETING_LIVE_AGENT_ARTIFACT_V18110='live_agent_session'"));
assert.ok(live.includes('video_meeting_artifacts'));
assert.ok(!live.includes('CREATE TABLE'));
assert.ok(!live.includes('chat_conversations'));
assert.ok(!live.includes('chat_messages'));

// Organizer control is enforced server-side with canonical meeting access + CSRF.
assert.ok(api.includes("REQUEST_METHOD']??'')!=='POST'"));
assert.ok(api.includes('current_user()'));
assert.ok(api.includes('verify_csrf()'));
assert.ok(api.includes('video_meeting_secure_access_v1800'));
assert.ok(api.includes("empty($access['is_organizer'])"));
assert.ok(api.includes('video_meeting_live_agent_owner_allowed_v18110'));
for(const action of ['state','start','stop','ask'])assert.ok(api.includes(`$action==='${action}'`),`missing API action ${action}`);
assert.ok(live.includes('Only the meeting organizer can start the live Agent.'));
assert.ok(live.includes('Only the meeting organizer can stop the live Agent.'));
assert.ok(live.includes('Only the meeting organizer can direct the live Agent.'));

// One live turn uses the canonical Agent runtime/inference and is fail-closed.
assert.ok(live.includes('vp3_agent_runtime_plan_v420'));
assert.ok(live.includes('vp3_agent_runtime_public_plan_v420'));
assert.ok(live.includes('vp3_agent_runtime_block_message_v420'));
assert.ok(live.includes('agent_surface_v131_enrich'));
assert.ok(live.includes('chat_generate_answer_v105'));
assert.ok(live.includes("'direct_actions_disabled'=>true"));
assert.ok(live.includes('Post-Meeting Action Queue'));
assert.ok(!live.includes('agent_tool_execute_query'));
assert.ok(!live.includes('agent_brain_archive_and_parse'));
assert.ok(!live.includes('crm_v180_activity('));
assert.ok(!live.includes('crm_v180_create_task('));
assert.ok(!live.includes('user_calendar_automation_create_event_v1300('));
assert.ok(!live.includes('mail('));

// Stable session/turn identity and retries cannot duplicate a live turn.
assert.ok(live.includes("'session_id'=>'mla-'"));
assert.ok(live.includes("hash_equals((string)($existing['client_turn_id']??''),$clientTurnId)"));
assert.ok(live.includes("'id'=>'mlat-'"));
assert.ok(live.includes("$sessionId.'|'.$clientTurnId"));
assert.ok(live.includes("'idempotent'=>true"));

// Live context consumes only reviewed/sanitized Meeting Intelligence projections.
assert.ok(live.includes('video_meeting_intelligence_public_state_v1890'));
for(const allowed of ['summary','decisions','actions','questions','objectives','source_hash','word_count'])assert.ok(live.includes(`'${allowed}'`));
for(const forbidden of ['relay_credentials','api_secret','provider_secret','raw_transcript','source_excerpt'])assert.ok(!live.includes(forbidden),`private field leaked: ${forbidden}`);

// HomeServer-only turns remain ephemeral: VP3 Cloud stores the turn identity only.
assert.ok(live.includes("'private_compute'=>$privateCompute"));
assert.ok(live.includes("$plan['effective_preference']??'')==='homeserver_only'"));
assert.ok(live.includes("$persisted['question']=''"));
assert.ok(live.includes("$persisted['answer']=''"));
assert.ok(live.includes("$persisted['sources']=[]"));
assert.ok(ui.includes('private · ephemeral'));
assert.ok(ui.includes('HomeServer-only replies remain in this browser session'));

// Existing LiveKit worker is reused, but speech is not falsely advertised.
assert.ok(media.includes('AgentDispatchService'));
assert.ok(live.includes('video_meeting_livekit_agent_dispatch_v1800'));
assert.ok(live.includes("'spoken_available'=>false"));
assert.ok(live.includes("'spoken_output_available'=>false"));
assert.ok(live.includes('Stopping live participation therefore stops Agent turns only'));

// Existing meeting surface gets the controls; there is no new dashboard/page.
for(const label of ['Live Agent','Start Agent','Stop Agent','Ask Agent','spoken output unavailable'])assert.ok(ui.includes(label),`missing UI ${label}`);
assert.ok(ui.includes("pane.id='meetingPane-liveagent'"));
assert.ok(ui.includes("button.dataset.pane='liveagent'"));
assert.ok(bridge.includes('video-meetings-live-agent-v18110.js?v=18110'));
assert.ok(bridge.includes('VP3MeetingLiveAgent18110'));
assert.ok(!ui.includes('api.openai.com'));
assert.ok(!ui.includes('api.anthropic.com'));
assert.ok(!ui.includes('live-agent.php?dashboard'));

console.log('Phase 18.11 Live Meeting Agent contract passed.');
