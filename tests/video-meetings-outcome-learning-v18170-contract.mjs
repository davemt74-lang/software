import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const master=read('includes/video-meetings-outcome-learning-v18170.php');
const p1=read('includes/video-meetings-outcome-learning-v18170-part1.php');
const p2=read('includes/video-meetings-outcome-learning-v18170-part2.php');
const prepApi=read('api/video-meeting-automation.php');
const prepUi=read('video-meetings-automation-v18130.js');
const ui=read('video-meetings-outcome-learning-v18170.js');
const upgrade=read('upgrade.php');

assert.ok(master.includes('VP3_VIDEO_MEETINGS_OUTCOME_LEARNING_V18170'));
assert.ok(master.includes('VP3_VIDEO_MEETINGS_OUTCOME_MIN_EVIDENCE_V18170=3'),'minimum evidence threshold must be 3');
assert.ok(master.includes('video-meetings-followthrough-intelligence-v18160.php'),'18.17 must build on 18.16 canonical outcomes');
for(const part of ['part1.php','part2.php'])assert.ok(master.includes(`video-meetings-outcome-learning-v18170-${part}`));

assert.ok(p1.includes('video_meeting_outcome_patterns'),'durable aggregate pattern storage is required');
assert.ok(p1.includes('uq_video_meeting_outcome_pattern (owner_user_id,action_kind,source_kind,priority)'),'aggregate uniqueness must be owner + non-personal dimensions');
for(const column of ['observed_count','verified_count','blocked_count','overdue_count','dismissed_count','verified_rate_bps','friction_rate_bps'])assert.ok(p1.includes(column),`missing aggregate ${column}`);
assert.ok(p1.includes("m.status IN ('verified','blocked','overdue','dismissed')"),'learning must use resolved/attention outcome states only');
assert.ok(p1.includes('video_meeting_followthrough_reconcile_owner_v18160'),'learning must reconcile canonical follow-through before rebuilding');
assert.ok(p1.includes('video_meeting_outcome_learning_source_v18170'),'source dimensions must be normalized');
for(const source of ['manual','suggested','prep_unresolved_commitments','prep_historical_decisions','prep_relevant_context'])assert.ok(p1.includes(`'${source}'`),`missing bounded source ${source}`);
assert.ok(p1.includes("?'other'" )||p1.includes(":'other'"),'unknown source kinds must collapse to other');
assert.ok(p1.includes('video_meeting_outcome_learning_rebuild_due_v18170'),'aggregate rebuilds should be briefly cached');
assert.ok(p1.includes('maxAgeSeconds=60'),'default aggregate refresh window should be explicit');
assert.ok(p1.includes('observed<VP3_VIDEO_MEETINGS_OUTCOME_MIN_EVIDENCE_V18170'),'guidance must reject thin evidence');
assert.ok(p1.includes("$verified>=7500")&&p1.includes("$friction>=5000"),'reliable/friction thresholds must be explicit');

for(const forbidden of ['participant_id','display_name','participant_email','email_recipient','transcript','note_text','homeserver'])assert.ok(!p1.toLowerCase().includes(forbidden),`aggregate store must not depend on personal/private field ${forbidden}`);
for(const writer of ['user_calendar_automation_create_event_v1300','crm_v180_activity(','agent_workflow_create_from_priority_v1400','agent_appointment_lifecycle_email_v700(','mail('])assert.ok(!p1.includes(writer),`outcome learning must not execute external writer ${writer}`);

assert.ok(p2.includes('Meeting Outcome Learning is organizer-only.'),'meeting learning must enforce organizer ownership');
assert.ok(p2.includes('video_meeting_outcome_learning_source_v18170'),'current agenda lookup must use the same normalized source dimension');
assert.ok(p2.includes("'advisory_only'=>true"),'guidance must be advisory only');
assert.ok(p2.includes("'automatic_priority_changes'=>false"),'learning must not change agenda priority automatically');
assert.ok(p2.includes("'automatic_action_changes'=>false"),'learning must not change action type automatically');
assert.ok(p2.includes("'participant_scoring'=>false"),'participant scoring must be forbidden');
for(const privacy of ['participant_names_persisted','participant_email_read','participant_email_persisted','meeting_text_persisted','raw_transcript_read','private_notes_read','homeserver_historical_probe'])assert.ok(p2.includes(`'${privacy}'=>false`),`missing privacy boundary ${privacy}`);
assert.ok(p2.includes('Who owns the outcome, what is the target, and what evidence will verify completion?'),'friction learning should produce an actionable but advisory question');

assert.ok(prepApi.includes('video-meetings-outcome-learning-v18170.php'),'Prep API must load 18.17');
assert.ok(prepApi.includes("$prep['outcome_learning']=video_meeting_outcome_learning_for_meeting_v18170"),'Prep API must attach 18.17 guidance');
assert.ok(prepUi.includes('loadOutcomeLearningController'),'Meeting Intelligence must load the 18.17 UI');
assert.ok(prepUi.includes("dataset.vp3MeetingOutcomeLearning='18170'"),'18.17 loader marker is required');
assert.ok(ui.includes('Outcome learning'),'Prep must expose Outcome Learning');
assert.ok(ui.includes('Adaptive questions'),'Prep must expose adaptive questions');
assert.ok(ui.includes('meeting-outcome-learning-note'),'matching Agenda items should receive advisory annotations');
assert.ok(ui.includes('vp3:meeting-prep-ready')&&ui.includes('vp3:meeting-agenda-ready'),'learning must layer onto canonical Prep and Agenda events');
assert.ok(!ui.includes('fetch('),'18.17 UI should be read-only and reuse the canonical Prep payload');
assert.ok(!ui.includes('innerHTML'),'18.17 UI must render server content safely');

assert.ok(upgrade.includes('video-meetings-outcome-learning-v18170.php'),'upgrade must load 18.17');
assert.ok(upgrade.includes('video_meeting_outcome_learning_schema_ready_v18170()'),'upgrade completeness must require 18.17 schema');
assert.ok(upgrade.includes('video_meeting_outcome_learning_ensure_schema_v18170($pdo)'),'upgrade must install 18.17 schema');
assert.ok(upgrade.includes('aggregate outcome-learning patterns'),'upgrade preservation copy must include 18.17 aggregate patterns');

console.log('Phase 18.17 Meeting Outcome Learning & Adaptive Prep contract passed.');
