import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const service=read('includes/video-meetings-plan-action-handoff-v18190.php');
const guard=read('includes/video-meetings-plan-action-guard-v18190.php');
const api=read('api/video-meeting-plan-action-handoff.php');
const actionsApi=read('api/video-meeting-actions.php');
const planningUi=read('video-meetings-adaptive-planning-v18180.js');
const ui=read('video-meetings-plan-action-handoff-v18190.js');
const upgrade=read('upgrade.php');

for(const table of ['video_meeting_plan_action_handoffs','video_meeting_plan_action_handoff_events'])assert.ok(service.includes(table),`missing ${table}`);
for(const field of ['plan_snapshot_json','plan_snapshot_hash','action_draft_hash','status'])assert.ok(service.includes(field),`missing ${field}`);
assert.ok(service.includes("readiness']!=='ready'"),'handoff must require a ready plan');
assert.ok(service.includes('video_meeting_action_require_eligible_v18150'),'handoff must preserve 18.15 agenda eligibility');
assert.ok(service.includes('video_meeting_action_prepare_v18150'),'handoff must reuse canonical action preparation');
assert.ok(service.includes('video_meeting_action_save_draft_v18150'),'handoff must reuse canonical action draft validation/saving');
assert.ok(service.includes("['needs_review','failed']"),'handoff must not overwrite approved/executing/executed actions');
assert.ok(service.includes('delivery_uncertain'),'uncertain email must not be refreshed');
assert.ok(service.includes('idempotent')&&service.includes('hash_equals((string)$prior[\'plan_snapshot_hash\'],$snapshotHash)'),'same-plan handoff must be idempotent');
assert.ok(service.includes('[VP3_PLAN_CONTEXT_V18190]')&&service.includes('video_meeting_plan_action_replace_context_v18190'),'plan context must be replaceable rather than repeatedly appended');
assert.ok(service.includes("if($kind==='task')")&&service.includes("if($kind==='calendar')")&&service.includes("if($kind==='crm')")&&service.includes("if($kind==='email')"),'all supported action kinds need explicit mapping');
assert.ok(service.includes("$draft['date']=$dt->format('Y-m-d')")&&service.includes("$draft['start_time']=$dt->format('H:i')"),'calendar handoff must use the explicit plan target');
assert.ok(!service.includes("$draft['recipient_email']=")&&!service.includes("$draft['lead_id']="),'handoff must not infer CRM or email recipients');
assert.ok(service.includes("'explicit_handoff'=>true")&&service.includes("'auto_approval'=>false")&&service.includes("'auto_execution'=>false")&&service.includes("'recipient_inference'=>false")&&service.includes("'idempotent_same_plan'=>true"),'handoff policy boundary missing');

assert.ok(guard.includes('hash_equals')&&guard.includes('plan_snapshot_hash'),'guard must compare current plan with immutable handoff snapshot');
assert.ok(guard.includes("status='stale'"),'plan drift must become stale');
assert.ok(guard.includes('Refresh the action from the current plan'),'stale handoff must block downstream action');
assert.ok(actionsApi.includes("in_array($action,['approve','execute','retry'],true)"),'approve, execute, and retry must all pass the drift guard');
assert.ok(actionsApi.includes('video_meeting_plan_action_guard_execution_v18190'),'Meeting Actions API must enforce plan drift before approval/execution/retry');

assert.ok(api.includes('REQUEST_METHOD')&&api.includes("'POST'"),'handoff API must be POST-only');
assert.ok(api.includes('hash_equals(csrf_token(),$csrf)'),'handoff API must enforce CSRF');
assert.ok(api.includes("(int)($meeting['owner_user_id']??0)!==$userId"),'handoff API must be organizer-scoped');
for(const action of ['state','handoff'])assert.ok(api.includes(`$action==='${action}'`),`missing handoff API action ${action}`);
assert.ok(!api.includes('$_GET'),'handoff API must not accept GET mutations');

assert.ok(planningUi.includes("dataset.agendaItemId=String(item.id||0)"),'Plan cards need stable agenda-item identity');
assert.ok(planningUi.includes('loadPlanActionHandoffController'),'18.18 must load 18.19 controls');
assert.ok(ui.includes('Use this plan for action')&&ui.includes('Refresh action from plan'),'explicit handoff controls missing');
assert.ok(ui.includes('Approval, retry, and execution are blocked'),'stale warning missing');
assert.ok(ui.includes('execution history')&&ui.includes('uncertain'),'terminal/uncertain lifecycle guidance missing');
assert.ok(ui.includes('Nothing was approved or executed.'),'UI must state non-execution boundary');
assert.ok(!ui.includes('innerHTML'),'handoff UI must render server content safely');

assert.ok(upgrade.includes('video-meetings-plan-action-handoff-v18190.php'),'upgrade must load 18.19');
assert.ok(upgrade.includes('video_meeting_plan_action_schema_ready_v18190()'),'upgrade completeness must require 18.19 schema');
assert.ok(upgrade.includes('video_meeting_plan_action_ensure_schema_v18190($pdo)'),'upgrade must install 18.19 schema');
assert.ok(upgrade.includes('plan-to-action handoff snapshots'),'upgrade preservation copy must mention handoff snapshots');

for(const writer of ['user_calendar_automation_create_event_v1300','crm_v180_activity(','agent_workflow_create_from_priority_v1400','agent_appointment_lifecycle_email_v700(','mail('])assert.ok(!service.includes(writer),`handoff must not execute downstream writer ${writer}`);
console.log('Phase 18.19 Plan-to-Action Handoff & Drift Control contract passed.');
