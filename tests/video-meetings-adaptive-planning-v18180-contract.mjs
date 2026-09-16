import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const master=read('includes/video-meetings-adaptive-planning-v18180.php');
const p1=read('includes/video-meetings-adaptive-planning-v18180-part1.php');
const p2=read('includes/video-meetings-adaptive-planning-v18180-part2.php');
const api=read('api/video-meeting-adaptive-planning.php');
const learningUi=read('video-meetings-outcome-learning-v18170.js');
const ui=read('video-meetings-adaptive-planning-v18180.js');
const upgrade=read('upgrade.php');

assert.ok(master.includes('VP3_VIDEO_MEETINGS_ADAPTIVE_PLANNING_V18180'));
assert.ok(master.includes('video-meetings-outcome-learning-v18170.php'),'18.18 must build on 18.17');
for(const part of ['part1.php','part2.php'])assert.ok(master.includes(`video-meetings-adaptive-planning-v18180-${part}`));

for(const table of ['video_meeting_followthrough_plans','video_meeting_followthrough_plan_events'])assert.ok(p1.includes(table),`missing ${table}`);
assert.ok(p1.includes('uq_video_meeting_followthrough_plan_item (agenda_item_id)'),'one current plan per agenda item is required');
for(const field of ['owner_label','target_at','target_timezone','verification_criteria','readiness'])assert.ok(p1.includes(field),`missing plan field ${field}`);
assert.ok(p1.includes("?'ready':'needs_definition'"),'readiness must require all planning fields');
assert.ok(p1.includes("status']!=='follow_up'")&&p1.includes("item_type']!=='follow_up'"),'planning must be limited to follow-up agenda items');
assert.ok(p1.includes('video_meeting_agenda_owner_guard_v18140'),'planning must inherit organizer ownership guard');
assert.ok(p1.includes('DateTimeZone')&&p1.includes('target_timezone'),'target handling must be timezone explicit');
assert.ok(p1.includes("event_v18180($pdo,$plan,'reset'"),'clearing a plan must preserve an audit event');
assert.ok(!p1.includes('DELETE FROM video_meeting_followthrough_plans WHERE agenda_item_id'),'clear must reset in place rather than erase plan audit history');

assert.ok(p2.includes('video_meeting_outcome_learning_for_meeting_v18170'),'18.18 advice must consume 18.17 aggregate learning');
assert.ok(p2.includes('owner')&&p2.includes('target')&&p2.includes('verification criteria'),'planning advice must identify missing readiness fields');
assert.ok(p2.includes("tone==='friction'")&&p2.includes("tone==='reliable'"),'adaptive advice must distinguish learned outcome patterns');
assert.ok(p2.includes("'advisory_learning_only'=>true"),'outcome learning must remain advisory');
for(const flag of ['auto_assign_owner','auto_change_priority','auto_change_action','external_side_effects'])assert.ok(p2.includes(`'${flag}'=>false`),`missing non-automation policy ${flag}`);
for(const privacy of ['participant_lookup','participant_scoring','participant_email_read','raw_transcript_read','private_notes_read','homeserver_historical_probe'])assert.ok(p2.includes(`'${privacy}'=>false`),`missing privacy boundary ${privacy}`);
for(const forbidden of ['video_meeting_participants','participant_id','display_name','email_recipient','note_text','transcript_text'])assert.ok(!(`${p1}\n${p2}`).toLowerCase().includes(forbidden),`planning service must not depend on ${forbidden}`);
for(const writer of ['user_calendar_automation_create_event_v1300','crm_v180_activity(','agent_workflow_create_from_priority_v1400','agent_appointment_lifecycle_email_v700(','mail('])assert.ok(!(`${p1}\n${p2}`).includes(writer),`planning must not execute external writer ${writer}`);

assert.ok(api.includes('REQUEST_METHOD')&&api.includes("'POST'"),'18.18 API must be POST-only');
assert.ok(api.includes('hash_equals(csrf_token(),$csrf)'),'18.18 API must enforce CSRF');
assert.ok(api.includes("(int)($meeting['owner_user_id']??0)!==$userId"),'18.18 API must be organizer scoped');
for(const action of ['state','save','clear'])assert.ok(api.includes(`$action==='${action}'`),`missing API action ${action}`);
assert.ok(!api.includes('$_GET'),'18.18 API must not accept query-string mutations');

assert.ok(learningUi.includes('loadAdaptivePlanningController'),'18.17 UI must load 18.18 after outcome learning');
assert.ok(learningUi.includes("dataset.vp3MeetingAdaptivePlanning='18180'"),'18.18 loader marker is required');
assert.ok(ui.includes("tab.dataset.pane='plan'"),'18.18 must stay inside Meeting Intelligence');
assert.ok(ui.includes("pane.id='meetingPane-plan'"));
for(const text of ['Owner','Verification criteria','Save plan','Clear plan'])assert.ok(ui.includes(text),`18.18 UI missing ${text}`);
assert.ok(ui.includes('datetime-local')&&ui.includes('state?.meeting?.timezone'),'target editor must expose the meeting timezone');
assert.ok(ui.includes('never auto-assigns a person')&&ui.includes('never executes an external action'),'UI must state advisory/execution boundaries');
assert.ok(!ui.includes('innerHTML'),'18.18 UI must render server content safely');

assert.ok(upgrade.includes('video-meetings-adaptive-planning-v18180.php'),'upgrade must load 18.18');
assert.ok(upgrade.includes('video_meeting_adaptive_planning_schema_ready_v18180()'),'upgrade completeness must require 18.18 schema');
assert.ok(upgrade.includes('video_meeting_adaptive_planning_ensure_schema_v18180($pdo)'),'upgrade must install 18.18 schema');
assert.ok(upgrade.includes('follow-through planning metadata'),'upgrade preservation copy must mention 18.18 plans');

console.log('Phase 18.18 Adaptive Meeting Planning contract passed.');
