import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const master = read('includes/video-meetings-actions-v18150.php');
const p1 = read('includes/video-meetings-actions-v18150-part1.php');
const p2 = read('includes/video-meetings-actions-v18150-part2.php');
const p3 = read('includes/video-meetings-actions-v18150-part3.php');
const api = read('api/video-meeting-actions.php');
const agendaApi = read('api/video-meeting-agenda.php');
const ui = read('video-meetings-actions-v18150.js');
const automationUi = read('video-meetings-automation-v18130.js');
const memory = read('includes/video-meetings-memory-v18120-part3.php');
const upgrade = read('upgrade.php');

assert.match(master, /VP3_VIDEO_MEETINGS_ACTIONS_V18150/);
for (const part of ['part1.php','part2.php','part3.php']) assert.ok(master.includes(`video-meetings-actions-v18150-${part}`));
for (const dep of ['video-meetings-agenda-v18140.php','agent-work-control-v173.php','user-calendar-v1300.php','crm-v180.php','agent-appointment-lifecycle-v700.php']) assert.ok(master.includes(dep), `missing canonical dependency ${dep}`);

for (const table of ['video_meeting_action_executions','video_meeting_action_events']) assert.ok(p1.includes(table), `missing ${table}`);
assert.ok(p1.includes('uq_video_meeting_action_item'), 'one execution record per agenda item is required');
assert.ok(p1.includes('uq_video_meeting_action_idempotency'), 'idempotency key must be unique per owner');
assert.ok(p1.includes('ON DELETE RESTRICT'), 'agenda execution lineage must not be cascade-deleted');
assert.ok(p1.includes("role='attendee'"), 'email candidates must be meeting attendees');
assert.ok(p1.includes('Email actions can only be sent to an attendee on this meeting.'), 'email recipient must be server-validated against meeting attendees');
assert.ok(p1.includes('video_meeting_action_guard_agenda_mutation_v18150'), 'agenda mutation audit guard is required');

for (const canonical of ['agent_workflow_create_from_priority_v1400','user_calendar_automation_create_event_v1300','crm_v180_activity','agent_appointment_lifecycle_email_v700']) assert.ok(p2.includes(canonical), `missing canonical execution route ${canonical}`);
assert.ok(p2.includes("'source'=>'meeting_agenda_external'"), 'Task action must enter the external-action risk path');
assert.ok(p2.includes("empty($run['requires_approval'])"), 'Task action must verify canonical approval is attached');
assert.ok(p2.includes('meeting_action_reference'), 'CRM execution must carry a durable meeting-action reference');
assert.ok(p2.includes("user_calendar_automation_create_event_v1300($pdo,$user") && p2.includes("],$ref);"), 'calendar execution must pass a stable source reference into canonical calendar automation');
assert.ok(p2.includes('cannot be edited or retried automatically'), 'uncertain email delivery must block edit/reapproval paths');

assert.ok(p3.includes("status='executing'"), 'execution needs a durable single-start state');
assert.ok(p3.includes('This Meeting Action has already started. It will not be executed twice.'), 'duplicate execution must be rejected');
assert.ok(p3.includes("'delivery_uncertain'"), 'uncertain email delivery must be represented explicitly');
assert.ok(p3.includes('cannot be retried automatically'), 'uncertain email delivery must block automatic retry');
assert.ok(p3.includes('cannot be discarded'), 'uncertain email delivery must remain in the audit history');
assert.ok(p3.includes("(string)$run['status']!=='completed'"), 'Task completion must follow canonical workflow completion');
assert.ok(p3.includes('execution remains executed because the canonical work item was created successfully'), 'downstream Task failure must not rewrite successful Task creation as an execution failure');

assert.ok(api.includes("REQUEST_METHOD") && api.includes("'POST'"), 'Meeting Actions API must be POST-only');
assert.ok(api.includes('hash_equals(csrf_token(),$csrf)'), 'Meeting Actions API must enforce CSRF');
assert.ok(api.includes("(int)($meeting['owner_user_id']??0)!==$userId"), 'Meeting Actions API must be organizer-scoped');
for (const action of ['prepare','save_draft','approve','execute','reopen','retry','complete','discard']) assert.ok(api.includes(`$action==='${action}'`), `API missing ${action}`);
assert.ok(!api.includes('$_GET'), 'Meeting Actions API must not accept query-string execution input');

assert.ok(agendaApi.includes('video_meeting_action_guard_agenda_mutation_v18150'), 'Agenda API must protect 18.15 execution history');
for (const op of ["'update'","'delete'","'action_state'"]) assert.ok(agendaApi.includes(op), `Agenda API guard coverage missing ${op}`);

assert.ok(ui.includes('Meeting Action Execution'), 'Actions tab must remain inside Meeting Intelligence');
assert.ok(ui.includes('Save & approve execution'), 'UI must separate drafting from approval');
assert.ok(ui.includes('Execute approved action'), 'UI must require an explicit execute step');
assert.ok(ui.includes('Automatic edit, retry, approval, and discard are blocked'), 'UI must explain uncertain email safety');
assert.ok(!ui.includes('innerHTML'), 'Actions UI must not use innerHTML for server-supplied content');
assert.ok(automationUi.includes('loadActionsController'), 'existing organizer Meeting Intelligence controller must load 18.15 Actions');
assert.ok(automationUi.includes("dataset.vp3MeetingActions='18150'"), '18.15 loader marker is required');

assert.ok(memory.includes('video_meeting_memory_action_history_v18150'), 'Agent Chat Meeting Memory needs sanitized execution history');
assert.ok(memory.includes("e.status IN ('executed','failed','completed')"), 'Agent Chat history must use durable execution states');
assert.ok(memory.includes('intentionally omits email bodies and recipient addresses'), 'Agent Chat privacy instruction must describe sanitization');
const historySql = memory.match(/function video_meeting_memory_action_history_v18150[\s\S]*?function video_meeting_memory_agent_context_v18120/)?.[0] || '';
assert.ok(historySql && !historySql.includes('email_recipient'), 'sanitized Agent Chat action history must not read recipient addresses');
assert.ok(historySql && !historySql.includes('draft_json'), 'sanitized Agent Chat action history must not read action draft bodies');

assert.ok(upgrade.includes("video-meetings-actions-v18150.php"), 'upgrade must load Phase 18.15 schema helper');
assert.ok(upgrade.includes('video_meeting_action_schema_ready_v18150()'), 'upgrade completeness must require Phase 18.15 schema');
assert.ok(upgrade.includes('video_meeting_action_ensure_schema_v18150($pdo)'), 'upgrade must install Phase 18.15 schema');

console.log('Phase 18.15 Meeting Action Execution contract passed.');
