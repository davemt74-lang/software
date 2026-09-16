import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read=p=>readFileSync(p,'utf8');
const master=read('includes/video-meetings-memory-v18120.php');
const p1=read('includes/video-meetings-memory-v18120-part1.php');
const p2=read('includes/video-meetings-memory-v18120-part2.php');
const p3=read('includes/video-meetings-memory-v18120-part3.php');
const memory=[master,p1,p2,p3].join('\n');
const api=read('api/video-meeting-memory.php');
const ui=read('video-meetings-memory-v18120.js');
const bridge=read('video-meetings-intelligence-v1820.js');
const meeting=read('meeting.php');
const chat=read('api/chat-v236.php');

// Phase 18.12 reuses canonical meeting artifacts and adds no parallel schema.
assert.ok(master.includes("VP3_VIDEO_MEETINGS_MEMORY_APP_V18120='meeting_memory_v18120'"));
assert.ok(master.includes("VP3_VIDEO_MEETINGS_MEMORY_ARTIFACT_V18120='meeting_memory_index'"));
assert.ok(memory.includes('video_meeting_artifacts'));
assert.ok(!memory.includes('CREATE TABLE'));
assert.ok(!memory.includes('ALTER TABLE'));

// Search is organizer-owner scoped, finalized-only, bounded and deterministic.
assert.ok(p3.includes("WHERE owner_user_id=? AND status IN ('ended','processed')"));
assert.ok(p3.includes('VP3_VIDEO_MEETINGS_MEMORY_SCAN_LIMIT_V18120'));
assert.ok(p3.includes('VP3_VIDEO_MEETINGS_MEMORY_RESULT_LIMIT_V18120'));
assert.ok(p3.includes('VP3_VIDEO_MEETINGS_MEMORY_CURSOR_LIMIT_V18120'));
assert.ok(p3.includes('usort($matches'));
assert.ok(p3.includes("'last month'"));
assert.ok(p3.includes("'last week'"));
assert.ok(p3.includes("'this week'"));
assert.ok(p3.includes("'this month'"));
for(const intent of ['decision','followthrough','discussion','participant'])assert.ok(p3.includes(`'${intent}'`),`missing search intent ${intent}`);

// Only current finalized source hashes can become memory.
assert.ok(p1.includes("['ended','processed']"));
assert.ok(p1.includes("$state['final_source_hash']"));
assert.ok(p1.includes('hash_equals($finalHash,$sourceHash)'));
assert.ok(p1.includes('final_analysis_at'));
assert.ok(p1.includes('Finalize and review the current meeting intelligence before it can become searchable memory.'));
assert.ok(p1.includes('video_meeting_intelligence_source_v1820'));
assert.ok(p1.includes('video_meeting_intelligence_state_row_v1820'));

// Historical private search consumes only an already-sanitized cached 18.9 artifact.
assert.ok(p1.includes('video_meeting_intelligence_hybrid_artifact_v1890'));
assert.ok(p1.includes('video_meeting_intelligence_hybrid_snapshot_v1890'));
assert.ok(!p1.includes('video_meeting_intelligence_public_state_v1890'));
assert.ok(!memory.includes('homeserver_capability_v033_registry'));
assert.ok(!memory.includes('homeserver_vp3_remote_operation'));
assert.ok(!memory.includes('homeserver_agent_v018_credentials'));

// Indexed material is semantic reviewed memory, never transcript/private transport data.
assert.ok(p2.includes('SELECT display_name FROM video_meeting_participants'));
assert.ok(!p2.includes('SELECT email'));
for(const category of ['summary','decision','action','question','risk','topic','objective','participant','followthrough_verified','followthrough_pending','followthrough_failed'])assert.ok(memory.includes(`'${category}'`),`missing memory category ${category}`);
for(const forbidden of ['raw_transcript','transcript_text','source_excerpt','relay_credentials','provider_secret','note_text','followup_body','follow_up_draft'])assert.ok(!memory.includes(forbidden),`private field leaked into memory module: ${forbidden}`);
assert.ok(p3.includes("'raw_transcript_indexed'=>false"));
assert.ok(p3.includes("'private_notes_indexed'=>false"));
assert.ok(p3.includes("'participant_email_indexed'=>false"));
assert.ok(p3.includes("'homeserver_private_context_indexed'=>false"));

// Every result remains source-backed and exposes provenance and a review link.
for(const key of ['source_hash','final_source_hash','index_hash','artifact_id','review_path'])assert.ok(memory.includes(`'${key}'`),`missing provenance ${key}`);
assert.ok(p3.includes('video_meeting_memory_chat_sources_v18120'));
assert.ok(p3.includes("'source'=>'video_meeting_memory:'"));
assert.ok(p3.includes("'url'=>url($path)"));
assert.ok(p3.includes('Do not claim a pending item was completed.'));

// API is authenticated, CSRF protected, POST data only, owner-bound for refresh.
assert.ok(api.includes('current_user()'));
assert.ok(api.includes('hash_equals(csrf_token(),$csrf)'));
assert.ok(api.includes("$action==='search'"));
assert.ok(api.includes("$action==='refresh'"));
assert.ok(api.includes("(int)($meeting['owner_user_id']??0)!==$userId"));
assert.ok(!api.includes('$_GET'));

// Existing Meeting workspace owns the UI; no new dashboard/page and safe DOM rendering only.
assert.ok(meeting.includes("'memoryEndpoint'=>url('/api/video-meeting-memory.php')"));
assert.equal((meeting.match(/video-meetings-intelligence-v1820\.js\?v=18120/g)||[]).length,2);
assert.ok(bridge.includes('video-meetings-memory-v18120.js?v=18120'));
assert.ok(bridge.includes('VP3MeetingMemory18120'));
assert.ok(ui.includes("pane.id='meetingPane-memory'"));
assert.ok(ui.includes("tab.dataset.pane='memory'"));
assert.ok(ui.includes("textContent"));
assert.ok(!ui.includes('innerHTML'));
assert.ok(!ui.includes('dashboard'));
assert.ok(ui.includes('safeHref'));

// Agent Chat preserves the sanitized memory after surface enrichment on both routes and publishes citations.
assert.ok(chat.includes("require_once dirname(__DIR__) . '/includes/video-meetings-memory-v18120.php'"));
assert.ok(chat.includes('video_meeting_memory_agent_context_v18120($pdo,$userId,$query)'));
assert.ok(chat.includes("$rawAgentContext['meeting_memory']=$meetingMemory"));
const enrichPos=chat.indexOf("$agentContext=agent_surface_v131_enrich($user,'chat',$rawAgentContext)");
const preservePos=chat.indexOf("$agentContext['meeting_memory']=$meetingMemory",enrichPos);
assert.ok(enrichPos>=0&&preservePos>enrichPos,'meeting memory must be preserved after Agent surface enrichment');
assert.ok(chat.includes('video_meeting_memory_chat_sources_v18120($meetingMemory)'));
assert.ok(chat.includes('$publicSources=array_merge($publicSources,$toolSources,$meetingSources)'));
assert.ok(chat.includes('homeserver_agent_v025_chat($user,$query,$conversationId,$history,$principal,$activeAgent,$agentContext'));
assert.ok(chat.includes('knowledge_retrieval_v162_generate_answer($query,$history,$user,$principal,$agentContext'));

// 18.12 retrieval itself is read-only apart from its bounded index artifact upsert.
for(const forbidden of ['agent_brain_archive_and_parse','crm_v180_activity(','crm_v180_create_task(','user_calendar_automation_create_event_v1300(','agent_tool_execute_query','mail('])assert.ok(!memory.includes(forbidden),`meeting memory must not execute ${forbidden}`);
assert.ok(memory.includes('ON DUPLICATE KEY UPDATE result_json=VALUES(result_json)'));

console.log('Phase 18.12 Meeting Search & Memory contract passed.');
