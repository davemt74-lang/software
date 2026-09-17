import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const master=read('includes/video-meetings-followthrough-verification-v18200.php');
const core=read('includes/video-meetings-followthrough-verification-v18200-part1.php');
const lifecycle=read('includes/video-meetings-followthrough-verification-v18200-part2.php');
const api=read('api/video-meeting-followthrough-verification.php');
const prepUi=read('video-meetings-automation-v18130.js');
const followUi=read('video-meetings-followthrough-intelligence-v18160.js');
const ui=read('video-meetings-followthrough-verification-v18200.js');
const learning=read('includes/video-meetings-outcome-learning-v18170-part1.php');
const upgrade=read('upgrade.php');

assert.ok(master.includes('video-meetings-plan-action-handoff-v18190.php'),'18.20 must build on Plan-to-Action lineage');
assert.ok(master.includes('video-meetings-followthrough-intelligence-v18160.php'),'18.20 must reuse canonical follow-through monitoring');
for(const table of ['video_meeting_followthrough_closures','video_meeting_followthrough_closure_events'])assert.ok(core.includes(table),`missing ${table}`);
for(const field of ['monitor_id','execution_id','plan_id','handoff_id','criteria_snapshot','criteria_hash','status','evidence_json','evidence_summary','evidence_source','agent_suggestion','organizer_note','verified_at'])assert.ok(core.includes(field),`missing closure field ${field}`);
assert.ok(core.includes('UNIQUE KEY uq_video_meeting_followthrough_closure_monitor'),'closure must be idempotent per follow-through monitor');
assert.ok(core.includes('UNIQUE KEY uq_video_meeting_followthrough_closure_execution'),'closure must be idempotent per execution');
assert.ok(core.includes('INSERT IGNORE INTO video_meeting_followthrough_closures')&&core.includes('$created=$insert->rowCount()===1'),'overlapping closure refreshes must create idempotently');
assert.ok(core.includes('if($created)video_meeting_followthrough_verification_event_v18200'),'only the winning insert may emit closure_created');
assert.ok(core.includes("'handoff_snapshot'"),'verification criteria must prefer the immutable handoff snapshot');
assert.ok(core.includes("'current_plan'"),'unhanded legacy actions may fall back to the current organizer plan');
assert.ok(core.includes("if($kind==='task')")&&core.includes("if($kind==='calendar')")&&core.includes("if($kind==='crm')")&&core.includes("if($kind==='email')"),'all canonical action kinds need verification evidence adapters');
assert.ok(core.includes("'review_for_verification'")&&core.includes('structured evidence alone does not prove'),'Agent guidance must remain advisory rather than auto-closing semantic criteria');
for(const state of ['waiting_for_verification','evidence_available','needs_attention'])assert.ok(core.includes(state)||lifecycle.includes(state),`missing closure state ${state}`);
assert.ok(lifecycle.includes("'canonical_completion_is_evidence_not_success'=>true"),'action completion must not equal outcome success');
assert.ok(lifecycle.includes("'explicit_organizer_closure'=>true"),'closure must require explicit organizer review');
assert.ok(lifecycle.includes("'canonical_evidence_required_before_closure'=>true"),'closure must require canonical evidence');
assert.ok(lifecycle.includes("if((string)$closure['status']!=='evidence_available')"),'server must reject closure without evidence_available state');
assert.ok(lifecycle.includes('$receipt->rowCount()!==1'),'closure confirmation must require a direct durable update receipt');
assert.ok(!lifecycle.includes("SELECT ROW_COUNT()"),'closure receipt must not depend on connection-level ROW_COUNT');
assert.ok(lifecycle.includes('video_meeting_action_complete_v18150'),'verified closure may synchronize canonical bookkeeping through the existing completion path');
assert.ok(lifecycle.includes('video_meeting_outcome_learning_rebuild_v18170'),'verified/reopened closure must immediately refresh outcome learning');

assert.ok(learning.includes('video_meeting_followthrough_closures'),'18.17 learning must consume Phase 18.20 closure state when available');
assert.ok(learning.includes("c.status='verified'"),'positive learning must require verified closure');
assert.ok(learning.includes("m.status IN ('blocked','overdue','dismissed')"),'operational friction must remain visible to learning');

assert.ok(api.includes('REQUEST_METHOD')&&api.includes("'POST'"),'verification API must be POST-only');
assert.ok(api.includes('hash_equals(csrf_token(),$csrf)'),'verification API must enforce CSRF');
assert.ok(api.includes("(int)($meeting['owner_user_id']??0)!==$userId"),'verification API must be organizer-scoped');
for(const action of ['state','confirm','reopen'])assert.ok(api.includes(`$action==='${action}'`),`missing verification API action ${action}`);
assert.ok(!api.includes('$_GET'),'verification API must not accept GET mutations');

assert.ok(prepUi.includes('Recent canonical completions')&&!prepUi.includes('Recent verified outcomes'),'Prep must not present Phase 18.16 canonical completion as intended-outcome verification');
assert.ok(followUi.includes('data-followthrough-monitor-id')||followUi.includes('dataset.followthroughMonitorId'),'18.16 cards need stable monitor identity for closure annotations');
assert.ok(followUi.includes('Confirm action resolved'),'operational confirmation must be distinguished from intended-outcome verification');
assert.ok(followUi.includes('loadVerificationController'),'18.16 Outcomes must load Phase 18.20');
assert.ok(ui.includes('Verify intended outcome'),'explicit intended-outcome closure control missing');
assert.ok(ui.includes('Agent guidance:'),'verification UI must surface advisory Agent assessment');
assert.ok(ui.includes('Success criterion:'),'verification UI must show the recorded success criterion');
assert.ok(!ui.includes('innerHTML'),'verification UI must render server content safely');

assert.ok(upgrade.includes('video-meetings-followthrough-verification-v18200.php'),'upgrade must load Phase 18.20');
assert.ok(upgrade.includes('video_meeting_followthrough_verification_schema_ready_v18200()'),'upgrade completeness must require Phase 18.20 schema');
assert.ok(upgrade.includes('video_meeting_followthrough_verification_ensure_schema_v18200($pdo)'),'upgrade must install Phase 18.20 schema');
assert.ok(upgrade.includes('intended-outcome verification closures'),'upgrade preservation copy must mention closure records');

for(const writer of ['user_calendar_automation_create_event_v1300','crm_v180_activity(','agent_workflow_create_from_priority_v1400','agent_appointment_lifecycle_email_v700(','mail(']){
  assert.ok(!core.includes(writer)&&!lifecycle.includes(writer),`verification must not execute downstream writer ${writer}`);
}
for(const forbidden of ['participant_email','raw_transcript','private_notes','homeserver_historical_probe'])assert.ok(lifecycle.includes(`'${forbidden}'`)||!core.includes(forbidden),`privacy boundary missing for ${forbidden}`);
console.log('Phase 18.20 Follow-Through Verification & Closure contract passed.');
