import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read=p=>readFileSync(p,'utf8');
const agenda=read('includes/video-meetings-agenda-v18140.php');
const api=read('api/video-meeting-agenda.php');
const ui=read('video-meetings-agenda-v18140.js');
const prepUi=read('video-meetings-automation-v18130.js');
const memory=read('includes/video-meetings-memory-v18120-part3.php');
const upgrade=read('upgrade.php');

// 18.14 extends 18.13 prep and creates one canonical organizer-owned agenda store.
assert.ok(agenda.includes("require_once __DIR__.'/video-meetings-automation-v18130.php'"));
assert.ok(agenda.includes("const VP3_VIDEO_MEETINGS_AGENDA_V18140"));
assert.ok(agenda.includes('VP3_VIDEO_MEETINGS_AGENDA_ITEM_LIMIT_V18140=80'));
assert.ok(agenda.includes('CREATE TABLE IF NOT EXISTS video_meeting_agenda_items'));
for(const key of ['meeting_id','owner_user_id','item_text','item_type','source_kind','source_meeting_id','source_hash','source_index_hash','source_review_path','status','priority','action_kind','approval_state','sort_order','fingerprint'])assert.ok(agenda.includes(key),`missing agenda field ${key}`);
assert.ok(agenda.includes('UNIQUE KEY uniq_video_meeting_agenda_fingerprint'));
assert.ok(agenda.includes("(int)($meeting['owner_user_id']??0)!==$ownerUserId"));
assert.ok(agenda.includes("SELECT COUNT(*) FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=?"));
assert.ok(agenda.includes('This meeting already has the maximum number of agenda items.'));

// Suggestions are deterministic projections of 18.13 finalized prep and current meeting objectives.
assert.ok(agenda.includes('video_meeting_automation_prep_v18130'));
assert.ok(agenda.includes("$prep['unresolved_commitments']"));
assert.ok(agenda.includes("$prep['historical_decisions']"));
assert.ok(agenda.includes("$prep['relevant_context']"));
assert.ok(agenda.includes('video_meeting_intelligence_objectives_v1820'));
for(const key of ['brief_text','suggestions','suggested_questions'])assert.ok(agenda.includes(`'${key}'`));
assert.ok(agenda.includes('source_hash'));
assert.ok(agenda.includes('source_index_hash'));
assert.ok(agenda.includes('source_review_path'));
assert.ok(agenda.includes('video_meeting_agenda_promote_suggestion_v18140'));
assert.ok(agenda.includes("$suggestion=$state['suggestions'][$suggestionIndex]??null"));

// Live agenda state is bounded, explicit and durable under edits/reordering.
for(const status of ['open','discussed','decision','follow_up','skipped'])assert.ok(agenda.includes(`'${status}'`));
for(const priority of ['low','normal','high'])assert.ok(agenda.includes(`'${priority}'`));
assert.ok(agenda.includes('video_meeting_agenda_reorder_v18140'));
assert.ok(agenda.includes('video_meeting_agenda_promote_prep_v18140'));
assert.ok(agenda.includes('video_meeting_agenda_accept_suggestions_v18140'));
assert.ok(agenda.includes('fingerprint=?,updated_at=NOW()'));
assert.ok(agenda.includes('An equivalent agenda item already exists.'));
assert.ok(agenda.includes('Agenda order is stale. Reload the agenda and try again.'));

// Action orchestration records approval only; it never executes external side effects.
for(const kind of ['task','calendar','crm','email'])assert.ok(agenda.includes(`'${kind}'`));
assert.ok(agenda.includes("'approved_for_agent_review'"));
assert.ok(agenda.includes("'execution'=>'agent_review_only'"));
assert.ok(agenda.includes("'side_effects_executed'=>false"));
assert.ok(agenda.includes("SET item_type='follow_up',status='follow_up',action_kind=?,approval_state=?"));
assert.ok(agenda.includes("if((string)($row['approval_state']??'none')!=='none'){$type='follow_up';$status='follow_up';}"));
const forbiddenExecutors=['agent_brain_archive_and_parse','crm_v180_activity(','crm_v180_create_task(','user_calendar_automation_create_event_v1300(','agent_tool_execute_query','mail(','create_notification(','homeserver_vp3_remote_operation'];
for(const forbidden of forbiddenExecutors){assert.ok(!agenda.includes(forbidden),`agenda service must not execute ${forbidden}`);assert.ok(!api.includes(forbidden),`agenda API must not execute ${forbidden}`);}
assert.ok(!agenda.includes('transcript_text'));
assert.ok(!agenda.includes("['note_text']"));
assert.ok(agenda.includes("'raw_transcript_read'=>false"));
assert.ok(agenda.includes("'private_notes_read'=>false"));
assert.ok(agenda.includes("'participant_email_read'=>false"));
assert.ok(agenda.includes("'homeserver_historical_probe'=>false"));

// API is POST-only, authenticated, CSRF protected and owner-bound.
assert.ok(api.includes("strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'"));
assert.ok(api.includes("header('Allow: POST')"));
assert.ok(api.includes('current_user()'));
assert.ok(api.includes('hash_equals(csrf_token(),$csrf)'));
assert.ok(api.includes("(int)($meeting['owner_user_id']??0)!==$userId"));
assert.ok(!api.includes('$_GET'));
for(const action of ['state','add','promote_prep','promote_suggestion','accept_suggestions','update','reorder','delete','action_state'])assert.ok(api.includes(`$action==='${action}'`),`missing API action ${action}`);
assert.ok(api.includes('video_meeting_agenda_promote_suggestion_v18140'));
assert.ok(api.includes("'side_effects_executed'=>false"));

// Existing Meeting Intelligence owns the surface; 18.13 loads 18.14 rather than creating a dashboard.
assert.ok(prepUi.includes('loadAgendaController'));
assert.ok(prepUi.includes('video-meetings-agenda-v18140.js?v=18140'));
assert.ok(prepUi.includes("dataset.vp3MeetingAgenda='18140'"));
assert.ok(ui.includes("tab.dataset.pane='agenda'"));
assert.ok(ui.includes("pane.id='meetingPane-agenda'"));
assert.ok(ui.includes('Automatic meeting brief'));
assert.ok(ui.includes('Suggested agenda'));
assert.ok(ui.includes('Live agenda'));
assert.ok(ui.includes('Suggested questions'));
assert.ok(ui.includes('Add to agenda'));
assert.ok(ui.includes('Approve for Agent review'));
assert.ok(ui.includes('No external action was executed'));
assert.ok(ui.includes("post('promote_suggestion',{suggestion_index:index})"));
assert.ok(ui.includes("post('promote_prep',{source_bucket:bucket,source_index:index})"));
assert.ok(ui.includes('attachPrepPromotion'));
assert.ok(ui.includes("['open','discussed','decision','follow_up','skipped']"));
assert.ok(ui.includes('safeHref'));
assert.ok(ui.includes('textContent'));
assert.ok(!ui.includes('innerHTML'));
assert.ok(!ui.toLowerCase().includes('dashboard'));

// Agent Chat receives upcoming organizer-owned agenda state through the already-integrated meeting_memory context.
assert.ok(memory.includes('video_meeting_memory_agenda_query_relevant_v18140'));
assert.ok(memory.includes('video_meeting_memory_upcoming_agenda_v18140'));
assert.ok(memory.includes("'upcoming_agendas'=>$agendas"));
assert.ok(memory.includes("'agenda_version'=>'v18.14'"));
assert.ok(memory.includes('Do not claim a pending item was completed.'));
assert.ok(memory.includes('approved_for_agent_review is approved for Agent review only'));
assert.ok(memory.includes("'source'=>'video_meeting_agenda:'"));
assert.ok(!memory.includes('JOIN video_meeting_notes'));
assert.ok(!memory.includes('transcript_text'));

// Canonical database upgrade installs and verifies the new agenda store.
assert.ok(upgrade.includes("require_once __DIR__ . '/includes/video-meetings-agenda-v18140.php'"));
assert.ok(upgrade.includes('video_meeting_agenda_schema_ready_v18140()'));
assert.ok(upgrade.includes('video_meeting_agenda_ensure_schema_v18140($pdo)'));

console.log('Phase 18.14 Meeting Agenda & Action Orchestration contract passed.');
