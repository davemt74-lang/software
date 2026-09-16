import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(p,'utf8');
const master=read('includes/video-meetings-followthrough-intelligence-v18160.php');
const p1=read('includes/video-meetings-followthrough-intelligence-v18160-part1.php');
const p2=read('includes/video-meetings-followthrough-intelligence-v18160-part2.php');
const api=read('api/video-meeting-followthrough-intelligence.php');
const prepApi=read('api/video-meeting-automation.php');
const ui=read('video-meetings-followthrough-intelligence-v18160.js');
const automationUi=read('video-meetings-automation-v18130.js');
const upgrade=read('upgrade.php');

assert.ok(master.includes('VP3_VIDEO_MEETINGS_FOLLOWTHROUGH_INTELLIGENCE_V18160'));
assert.ok(master.includes('video-meetings-actions-v18150.php'));
assert.ok(master.includes('video-meetings-followthrough-v18100.php'));
for(const part of ['part1.php','part2.php'])assert.ok(master.includes(`video-meetings-followthrough-intelligence-v18160-${part}`));

for(const table of ['video_meeting_followthrough_monitors','video_meeting_followthrough_events'])assert.ok(p1.includes(table),`missing ${table}`);
assert.ok(p1.includes('uq_video_meeting_followthrough_execution'),'one monitor per execution is required');
for(const state of ['watching','due_soon','overdue','blocked','verified','dismissed'])assert.ok(p1.includes(`'${state}'`),`missing monitor state ${state}`);
assert.ok(p1.includes('72*3600')&&p1.includes('48*3600'),'default monitoring windows must be explicit');
assert.ok(p1.includes('user_calendar_event_v1300'),'calendar verification must read canonical calendar state');
assert.ok(p1.includes('agent_workflow_row_v1400'),'Task verification must read canonical Agent workflow state');
assert.ok(p1.includes('SELECT id FROM crm_activities'),'CRM verification must read canonical CRM state');
assert.ok(p1.includes('video_meeting_action_complete_v18150'),'explicit resolution confirmation must reuse Phase 18.15 completion');
assert.ok(p1.includes('video_meeting_action_refresh_task_v18150'),'Task verification must synchronize completed canonical workflow state back to Phase 18.15');
assert.ok(p1.includes('blocked by its canonical result and cannot be confirmed resolved yet'),'blocked canonical outcomes must not be overridable through a crafted confirm request');
assert.ok(p1.includes('Reopen this follow-through monitor before confirming it resolved.'),'dismissed monitors must not trigger completion side effects');
assert.ok(p1.includes('Verified follow-through history cannot be dismissed.'),'verified audit history must remain durable');
assert.ok(p1.includes('Verified or dismissed follow-through items do not accept a new target.'),'closed monitor targets must be immutable');
for(const forbidden of ['user_calendar_automation_create_event_v1300','crm_v180_activity(','agent_workflow_create_from_priority_v1400','agent_appointment_lifecycle_email_v700(','mail('])assert.ok(!p1.includes(forbidden),`monitoring must not execute external writer ${forbidden}`);

assert.ok(p2.includes('SELECT display_name FROM video_meeting_participants'),'prep relevance may use participant display names');
assert.ok(!p2.includes('SELECT email')&&!p2.includes("['email']")&&!p2.includes('["email"]'),'Phase 18.16 prep relevance must not read participant email fields');
assert.ok(p2.includes("'participant_email_read'=>false"),'privacy metadata must explicitly state participant email is not read');
assert.ok(p2.includes("m.status IN ('blocked','overdue','due_soon','verified')"),'future prep must resurface attention/outcome states');
assert.ok(p2.includes('video_meeting_followthrough_name_overlap_v18160'),'future prep should prefer participant-relevant prior outcomes');
assert.ok(p2.includes('video_meeting_followthrough_title_overlap_v18160'),'future prep should use meaningful title overlap when participant names do not match');
assert.ok(!p2.includes('!$currentNames||'),'meetings without named participants must not receive every recent outcome');
assert.ok(p2.includes('raw_transcript_read')&&p2.includes('homeserver_historical_probe'),'privacy metadata is required');

assert.ok(api.includes('REQUEST_METHOD')&&api.includes("'POST'"),'18.16 API must be POST-only');
assert.ok(api.includes('hash_equals(csrf_token(),$csrf)'),'18.16 API must enforce CSRF');
assert.ok(api.includes("(int)($meeting['owner_user_id']??0)!==$userId"),'18.16 API must be organizer scoped');
for(const action of ['state','set_expected','confirm','dismiss','reopen'])assert.ok(api.includes(`$action==='${action}'`),`missing API action ${action}`);
assert.ok(!api.includes('$_GET'),'18.16 API must not accept query-string mutations');

assert.ok(prepApi.includes('video-meetings-followthrough-intelligence-v18160.php'),'Prep API must load 18.16');
assert.ok(prepApi.includes("$prep['followthrough_intelligence']"),'Prep API must attach 18.16 intelligence');
assert.ok(ui.includes("tab.dataset.pane='outcomes'"),'18.16 must remain inside Meeting Intelligence');
assert.ok(ui.includes("pane.id='meetingPane-outcomes'"));
assert.ok(ui.includes('Confirm resolved'));
assert.ok(ui.includes('Monitoring never executes external actions.'));
assert.ok(ui.includes("state?.meeting?.timezone||'UTC'"),'follow-through target editing must display the meeting timezone used by the server');
assert.ok(ui.includes("'Target ('+timezone+')'"),'target control must visibly label its timezone');
assert.ok(!ui.includes('innerHTML'),'18.16 UI must render server content safely');
assert.ok(automationUi.includes('meetingPrepFollowthrough'),'Prep UI must display 18.16 context');
assert.ok(automationUi.includes('loadFollowthroughController'),'organizer workspace must load 18.16 Outcomes');
assert.ok(automationUi.includes("dataset.vp3MeetingFollowthrough='18160'"),'18.16 loader marker is required');

assert.ok(upgrade.includes('video-meetings-followthrough-intelligence-v18160.php'),'upgrade must load 18.16');
assert.ok(upgrade.includes('video_meeting_followthrough_intelligence_schema_ready_v18160()'),'upgrade completeness must require 18.16 schema');
assert.ok(upgrade.includes('video_meeting_followthrough_intelligence_ensure_schema_v18160($pdo)'),'upgrade must install 18.16 schema');

console.log('Phase 18.16 Meeting Follow-Through Intelligence contract passed.');
