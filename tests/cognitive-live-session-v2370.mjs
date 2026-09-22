import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');

const bootstrap=read('includes/bootstrap.php');
const activity=read('includes/agent-activity-v94.php');
const events=read('includes/agent-event-infrastructure-v1920.php');
const session=read('includes/cognitive-live-session-v2370.php');
const sessionAdapter=read('includes/cognitive-runtime-session-v2370.php');
const runtime=read('includes/cognitive-runtime-v500.php');
const manifest=read('includes/cognitive-domain-manifest-v2370.php');
const release=read('includes/cognitive-release-v2370.php');
const chatEngine=read('includes/chat-engine.php');
const chatRuntime=read('includes/agent-chat-runtime-v2160.php');
const chatPolicy=read('includes/chat-agent-policy-v236.php');
const aiRuntime=read('includes/ai-runtime-v100.php');
const upgrade=read('upgrade.php');
const setup=read('setup.php');
const docs=read('docs/VP3_COGNITIVE_LIVE_SESSION_V2370.md');

const domains=[
  'session','agent_chat','agent_brain_history','profile_agent','scheduling',
  'booking_appointments','calendar','commerce','crm_relationships','meetings',
  'browser','knowledge_research','workflows_tools_approvals','messaging_team',
  'media_studio','homeserver','analytics_attribution','notifications',
  'subscription_billing','client_release'
];

const eventFamilies=[
  'session.started','session.idle_started','chat.message_sent',
  'profile.booking_converted','booking.created','calendar.event_updated',
  'order.paid','refund.requested','lead.stage_changed','meeting.started',
  'browser.transaction_completed','research.source_added','workflow.completed',
  'approval.requested','team.message_received','transcription.completed',
  'homeserver.connected','conversion.attributed','notification.dismissed',
  'billing.payment_failed','release.failed'
];

const checks=[
  ['v23.70 does not create a second event ledger', /agent_event_inbox/.test(session) && !/CREATE TABLE IF NOT EXISTS\s+(?!agent_live_sessions_v2370|agent_live_session_segments_v2370)/.test(session)],
  ['canonical event infrastructure loads in bootstrap', bootstrap.includes("agent-event-infrastructure-v1920.php")],
  ['domain manifest loads in bootstrap', bootstrap.includes("cognitive-domain-manifest-v2370.php")],
  ['live session runtime loads in bootstrap', bootstrap.includes("cognitive-live-session-v2370.php")],
  ['session adapter loads after cognitive runtime', bootstrap.indexOf("cognitive-runtime-v500.php") < bootstrap.indexOf("cognitive-runtime-session-v2370.php")],
  ['v23.70 release gate loads before feed presentation', bootstrap.indexOf("cognitive-release-v2370.php") < bootstrap.indexOf("cognitive-feed-v530.php")],
  ['event inbox is independently installable', !/fk_agent_event_run/.test(events) && /fk_agent_event_owner/.test(events)],
  ['live session schema owns sessions and temporal segments', /agent_live_sessions_v2370/.test(session) && /agent_live_session_segments_v2370/.test(session)],
  ['live session tracks time totals', /active_seconds/.test(session) && /idle_seconds/.test(session) && /paused_seconds/.test(session)],
  ['live session tracks current focus and recent actions', /current_conversation_id/.test(session) && /current_project_ref/.test(session) && /current_task_ref/.test(session) && /last_actions_json/.test(session)],
  ['activity bridge updates canonical live session', /vp3_live_session_record_activity_v2370/.test(activity)],
  ['low-level session events record without Brain dispatch', /agent_event_ingest_v1920/.test(session) && !/agent_event_dispatch_v1920/.test(session) && !/agent_event_brain_observe_v1920/.test(session)],
  ['session is a first-class cognitive module', /'module'=>'live_session'/.test(sessionAdapter) && /'live_session','session_segment'/.test(sessionAdapter)],
  ['session module registers Chat and session events', /session\.started/.test(sessionAdapter) && /chat\.message_sent/.test(sessionAdapter) && /chat\.stop_requested/.test(sessionAdapter)],
  ['cognitive presentation uses real session idle state', /vp3_live_session_snapshot_v2370/.test(runtime) && /idleMinutes/.test(runtime) && /interruptible/.test(runtime)],
  ['canonical cognitive state exposes live session projection', /'live_session'=>\$liveSession/.test(runtime)],
  ['Agent Chat records user action', /'user','chat\.message_sent'/.test(chatRuntime)],
  ['Agent Chat records Agent completion', /'agent','chat\.response_completed'/.test(chatRuntime)],
  ['Agent Chat records handled tool completion', /'tool','tool\.completed'/.test(chatRuntime)],
  ['direct user action resumes idle session', /vp3_live_session_record_activity_v2370\(\$user,\(string\)\$context\['surface'\],'working'/.test(session)],
  ['session expiration uses meaningful activity not idle heartbeat', /vp3_live_session_last_meaningful_at_v2370/.test(session)],
  ['activity state merge preserves prior session fields', /array_replace\(\$stateJson/.test(session)],
  ['task project and goal changes are focus transitions', /\$focusChanged=/.test(session) && /current_goal_ref/.test(session)],
  ['Agent Chat synthesis receives compact live session context', /vp3_live_session_context_item_v2370/.test(chatPolicy)],
  ['live-session packet is explicitly internal only', /INTERNAL SESSION STATE/.test(session) && /INTERNAL SESSION STATE/.test(chatEngine)],
  ['response discipline treats live-session state as silent evidence', /live-session timing\/state/.test(aiRuntime) && /raw JSON/.test(aiRuntime)],
  ['public Chat sources use canonical internal-source filter', /chat_context_is_internal_source\(\$source\)/.test(chatRuntime)],
  ['compute routing is not a visible source chip', /compute-routing:/.test(chatEngine) && /array_filter\(\$publicSources/.test(chatRuntime)],
  ['presentation firewall blocks raw cross-surface context', /Active cross-surface Agent context/.test(chatEngine) && /Confidence-ranked Agent Brain memory/.test(chatEngine) && /Retrieved conversation history/.test(chatEngine)],
  ['presentation firewall applies after runtime routing', /presentation firewall replaced an internal-context echo/.test(chatRuntime)],
  ['internal Profile and Knowledge evidence are not public source labels', /profile:activity/.test(chatEngine) && /knowledge-v162/.test(chatEngine)],
  ['normal upgrade installs event and session schemas', /agent_event_ensure_schema_v1920\(\$pdo\)/.test(upgrade) && /vp3_live_session_ensure_schema_v2370\(\$pdo\)/.test(upgrade)],
  ['upgrade readiness requires event and session schemas', /agent_event_schema_ready_v1920\(\)/.test(upgrade) && /vp3_live_session_schema_ready_v2370\(\)/.test(upgrade)],
  ['fresh setup installs event and session schemas', /agent_event_ensure_schema_v1920\(\$pdo\)/.test(setup) && /vp3_live_session_ensure_schema_v2370\(\$pdo\)/.test(setup)],
  ['release gate preserves no-second-brain invariant', /'second_brain'=>false/.test(release) && /'second_event_ledger'=>false/.test(release)],
  ['release gate forbids raw internal context', /'raw_internal_context_user_facing'=>false/.test(release)],
  ['architecture docs explicitly keep existing Agent Chat', /second Agent Chat/.test(docs) && /existing Agent Chat/.test(docs)],
];

for(const domain of domains)checks.push([`manifest includes domain: ${domain}`,new RegExp(`'${domain}'\\s*=>`).test(manifest)]);
for(const event of eventFamilies)checks.push([`manifest includes event: ${event}`,manifest.includes(`'${event}'`)]);

for(const [name,ok] of checks){
  assert.equal(ok,true,name);
  console.log('PASS',name);
}

console.log(`Cognitive Live Session v23.70 gate: ${checks.length}/${checks.length} passed`);
