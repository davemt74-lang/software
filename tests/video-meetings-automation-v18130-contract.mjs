import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read=p=>readFileSync(p,'utf8');
const automation=read('includes/video-meetings-automation-v18130.php');
const api=read('api/video-meeting-automation.php');
const ui=read('video-meetings-automation-v18130.js');
const bridge=read('video-meetings-intelligence-v1820.js');
const memory=[
  read('includes/video-meetings-memory-v18120.php'),
  read('includes/video-meetings-memory-v18120-part1.php'),
  read('includes/video-meetings-memory-v18120-part2.php'),
  read('includes/video-meetings-memory-v18120-part3.php'),
].join('\n');

// 18.13 extends finalized 18.12 memory and adds no parallel persistence/schema.
assert.ok(automation.includes("require_once __DIR__.'/video-meetings-memory-v18120.php'"));
assert.ok(automation.includes("'version'=>'v18.13'"));
assert.ok(automation.includes("'schema'=>'vp3.meeting.intelligence.automation.prep'"));
assert.ok(!automation.includes('CREATE TABLE'));
assert.ok(!automation.includes('ALTER TABLE'));
assert.ok(!automation.includes('INSERT INTO'));
assert.ok(!automation.includes('UPDATE '));
assert.ok(!automation.includes('DELETE FROM'));

// Prep is owner-only and built from the exact source-backed 18.12 search path.
assert.ok(automation.includes("(int)($meeting['owner_user_id']??0)!==$ownerUserId"));
assert.ok(automation.includes('video_meeting_memory_search_v18120'));
assert.ok(automation.includes("'decided '.$subject"));
assert.ok(automation.includes("'pending action item '.$subject"));
assert.ok(automation.includes("'discussed '.$subject"));
for(const key of ['historical_decisions','unresolved_commitments','relevant_context','sources'])assert.ok(automation.includes(`'${key}'`),`missing prep output ${key}`);
for(const key of ['source_hash','final_source_hash','index_hash','artifact_id','review_path'])assert.ok(automation.includes(`'${key}'`),`missing source provenance ${key}`);

// Historical prep excludes the target meeting itself, including completed/review mode.
assert.ok(automation.includes('int $excludeMeetingId=0'));
assert.ok(automation.includes('$meetingId===$excludeMeetingId'));
assert.ok(automation.includes('false,$meetingId'));
assert.ok(automation.includes('true,$meetingId'));
assert.ok(automation.includes('The current meeting is excluded from historical retrieval'));

// Unresolved work never gets upgraded into completed work by the prep layer.
assert.ok(automation.includes("$category==='followthrough_pending'"));
assert.ok(automation.includes("$category==='followthrough_verified'"));
assert.ok(automation.includes("['verified','completed','complete','done','closed','cancelled','canceled','failed']"));
assert.ok(automation.includes('pending commitments remain pending until separately verified'));

// Privacy boundaries match 18.12: participant names only; no emails, transcript, notes or historical HomeServer probing.
assert.ok(automation.includes('SELECT display_name FROM video_meeting_participants'));
assert.ok(!automation.includes('SELECT email'));
for(const forbidden of ['transcript_text','note_text','private_note','homeserver_vp3_remote_operation','homeserver_agent_v018_credentials','homeserver_capability_v033_registry'])assert.ok(!automation.includes(forbidden),`automation must not consume private field/route ${forbidden}`);
assert.ok(automation.includes("'raw_transcript_read'=>false"));
assert.ok(automation.includes("'private_notes_read'=>false"));
assert.ok(automation.includes("'participant_email_read'=>false"));
assert.ok(automation.includes("'homeserver_historical_probe'=>false"));
assert.ok(automation.includes("'side_effects_executed'=>false"));

// Automation is read-only: no task/CRM/calendar/mail/Agent Brain/tool execution.
for(const forbidden of ['agent_brain_archive_and_parse','crm_v180_activity(','crm_v180_create_task(','user_calendar_automation_create_event_v1300(','agent_tool_execute_query','mail(','create_notification(']){
  assert.ok(!automation.includes(forbidden),`meeting prep must not execute ${forbidden}`);
  assert.ok(!api.includes(forbidden),`meeting prep API must not execute ${forbidden}`);
}

// API is POST-only, authenticated, CSRF protected, owner-bound and body based.
assert.ok(api.includes("strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'"));
assert.ok(api.includes("header('Allow: POST')"));
assert.ok(api.includes('http_response_code(405)'));
assert.ok(api.includes('current_user()'));
assert.ok(api.includes('hash_equals(csrf_token(),$csrf)'));
assert.ok(api.includes("$action!=='prep'"));
assert.ok(api.includes("(int)($meeting['owner_user_id']??0)!==$userId"));
assert.ok(api.includes('video_meeting_automation_prep_v18130'));
assert.ok(!api.includes('$_GET'));

// Existing Meeting Intelligence workspace owns the UI; no new page/dashboard.
assert.ok(bridge.includes('loadAutomationController'));
assert.ok(bridge.includes('video-meetings-automation-v18130.js?v=18130'));
assert.ok(bridge.includes("dataset.vp3MeetingAutomation='18130'"));
assert.ok(ui.includes("pane.id='meetingPane-prep'"));
assert.ok(ui.includes("tab.dataset.pane='prep'"));
assert.ok(ui.includes("wrap.id='meetingPrepLobby'"));
assert.ok(ui.includes('Before you join'));
assert.ok(ui.includes('Unresolved commitments'));
assert.ok(ui.includes('Historical decisions'));
assert.ok(ui.includes('Relevant context'));
assert.ok(ui.includes('safeHref'));
assert.ok(ui.includes('textContent'));
assert.ok(!ui.includes('innerHTML'));
assert.ok(!ui.includes('dashboard'));

// Historical source layer itself must continue to enforce finalized current hashes.
assert.ok(memory.includes("$state['final_source_hash']"));
assert.ok(memory.includes('hash_equals($finalHash,$sourceHash)'));
assert.ok(memory.includes('video_meeting_intelligence_hybrid_artifact_v1890'));
assert.ok(!memory.includes('homeserver_vp3_remote_operation'));

console.log('Phase 18.13 Meeting Intelligence Automation contract passed.');
