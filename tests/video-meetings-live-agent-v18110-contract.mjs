import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read=p=>readFileSync(p,'utf8');
const live=[read('includes/video-meetings-live-agent-v18110.php'),...Array.from({length:4},(_,i)=>read(`includes/video-meetings-live-agent-v18110-part${i+1}.php`))].join('\n');
const api=read('api/video-meeting-live-agent.php');
const ui=read('video-meetings-live-agent-v18110.js');
const bridge=read('video-meetings-followthrough-v18100.js');
const media=read('includes/video-meetings-agent-v1800.php');
const homeserverBrainApi=read('homeserver-v1890/app/brain_api.py');
const homeserverChat=read('homeserver-v1890/app/services/context_chat.py');

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
assert.ok(live.includes('Meeting Agent mode is off for this meeting.'));

// Every live turn is rebound to the canonical runtime plan immediately before inference.
assert.ok(live.includes('vp3_agent_runtime_plan_v420'));
assert.ok(live.includes('vp3_agent_runtime_public_plan_v420'));
assert.ok(live.includes('vp3_agent_runtime_block_message_v420'));
assert.ok(live.includes("if($route==='homeserver')"));
assert.ok(live.includes("elseif($route==='vp3_cloud')"));
assert.ok(live.includes("in_array($provider,['openai','anthropic'],true)"));
assert.ok(live.includes('ai_provider_ready($provider)'));
assert.ok(live.includes('chat_remote_answer($question,$history,$context,$user)'));
assert.ok(live.includes('chat_context($question,$user)'));
assert.ok(live.includes('user_agent_get_v236'));
assert.ok(live.includes("'source'=>'agent:identity'"));
assert.ok(live.includes("'source'=>'video_meeting:'"));
assert.ok(!live.includes('chat_generate_answer_v105'));
assert.ok(!live.includes('agent_surface_v131_enrich'));

// Meeting privacy wins over account/Agent preference. Private meetings execute
// through existing HomeServer agent.chat with cloud delegation and fallback off.
assert.ok(live.includes('video_meeting_live_agent_homeserver_executor_ready_v18110'));
assert.ok(live.includes('homeserver_agent_v018_credentials'));
assert.ok(live.includes('homeserver_vp3_remote_operation'));
assert.ok(live.includes("'agent.chat'"));
assert.ok(live.includes("'cloud_allowed'=>$cloudAllowed"));
assert.ok(live.includes("'read_only'=>true"));
assert.ok(live.includes("if(empty($result['read_only']))"));
assert.ok(live.includes("$tools['call_count']"));
assert.ok(live.includes("$tools['action_request_ids']"));
assert.ok(live.includes('HomeServer did not confirm server-enforced read-only live Agent mode.'));
assert.ok(live.includes('HomeServer reported tool or action activity during a read-only live Agent turn.'));
assert.ok(live.includes("if(!$cloudAllowed&&$compute==='vp3_cloud')"));
assert.ok(live.includes("$plan['route']=$ready?'homeserver':'blocked'"));
for(const reason of ['meeting_policy_unresolved','meeting_homeserver_unavailable','homeserver_live_agent_executor_unavailable','meeting_policy_unavailable'])assert.ok(live.includes(reason),`missing route guard ${reason}`);
assert.ok(live.includes("$plan['allow_vp3_fallback']=false"));
assert.ok(live.includes("$plan['fallback_target']='none'"));
assert.ok(live.includes("$plan['homeserver_cloud_allowed']=false"));
assert.ok(live.includes("'homeserver_live_generation_supported'=>true"));

// The pinned HomeServer implementation must enforce the same contract server-side.
assert.ok(homeserverBrainApi.includes('read_only: bool = False'));
assert.ok(homeserverBrainApi.includes('if payload.read_only:'));
assert.ok(homeserverBrainApi.includes('read_only=True'));
assert.ok(homeserverChat.includes('def _model_tool_permissions(read_only: bool, permissions: set[str])'));
assert.ok(homeserverChat.includes('return set() if read_only else set(permissions)'));
assert.ok(homeserverChat.includes('granted_permissions=_model_tool_permissions(read_only, canonical.model_tool_permissions)'));
assert.ok(homeserverChat.includes('owner=bool(owner_tools and not read_only)'));
assert.ok(homeserverChat.includes('tool_state["read_only"] = bool(read_only)'));
assert.ok(homeserverChat.includes('"read_only": bool(read_only)'));

// HomeServer turn content is browser-ephemeral: Cloud stores opaque continuity
// and idempotency receipts only, never the private question/answer text.
assert.ok(live.includes("'receipts'=>[]"));
assert.ok(live.includes("'homeserver_conversation_id'=>''"));
assert.ok(live.includes("'ephemeral'=>$isHome"));
assert.ok(live.includes("if($isHome)"));
assert.ok(live.includes("$state['receipts']=array_slice($receipts,-80)"));
assert.ok(live.includes("$state['homeserver_conversation_id']=$remoteId"));
assert.ok(live.includes("'homeserver_turns_ephemeral'=>true"));
assert.ok(ui.includes('ephemeralTurns=[]'));
assert.ok(ui.includes("data.turn?.ephemeral"));
assert.ok(ui.includes('private · not stored in VP3 Cloud'));
assert.ok(ui.includes('private turns stay on this page only'));

// Live turns are advisory/read-only; execution stays in the reviewed 18.10 queue.
assert.ok(live.includes("'no_direct_side_effects'=>true"));
assert.ok(live.includes('Post-Meeting Action Queue'));
for(const forbidden of ['agent_tool_execute_query','agent_brain_archive_and_parse','crm_v180_activity(','crm_v180_create_task(','user_calendar_automation_create_event_v1300(','mail(','agent_proactive_objective_record_shown_v178'])assert.ok(!live.includes(forbidden),`live turn must not execute ${forbidden}`);

// Stable session/turn identity and retries cannot duplicate either durable or private turns.
assert.ok(live.includes("'session_id'=>'mla-'"));
assert.ok(live.includes("hash_equals((string)($existing['client_turn_id']??''),$clientTurnId)"));
assert.ok(live.includes('video_meeting_live_agent_private_receipt_v18110'));
assert.ok(live.includes("hash_equals((string)($receipt['client_turn_id']??''),$clientTurnId)"));
assert.ok(live.includes("'id'=>'mlat-'"));
assert.ok(live.includes("$sessionId.'|'.$clientTurnId"));
assert.ok(live.includes("'idempotent'=>true"));

// Live context consumes the sanitized Meeting Intelligence projection, never raw private transport/provider fields.
assert.ok(live.includes('video_meeting_intelligence_public_state_v1890'));
for(const allowed of ['summary','decisions','actions','questions','objectives','source_hash','word_count'])assert.ok(live.includes(`'${allowed}'`));
for(const forbidden of ['relay_credentials','api_secret','provider_secret','raw_transcript','source_excerpt'])assert.ok(!live.includes(forbidden),`private field leaked: ${forbidden}`);
assert.ok(live.includes('Current sanitized live meeting and Meeting Intelligence context.'));

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