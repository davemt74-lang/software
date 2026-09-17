import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const core=read('includes/video-meetings-closure-recurring-continuity-v18220.php');
const guard=read('includes/video-meetings-closure-recurring-continuity-guard-v18220.php');
const api=read('api/video-meeting-closure.php');
const ui=read('video-meetings-closure-recurring-continuity-v18220.js');
const loader=read('video-meetings-cross-meeting-continuity-v18210.js');
const memory=read('includes/video-meetings-memory-v18120-part3.php');
const upgrade=read('upgrade.php');

assert.ok(core.includes("VP3_VIDEO_MEETINGS_CLOSURE_V18220='video-meetings-closure-recurring-continuity-v18220-20260917'"));
assert.ok(core.includes('video-meetings-cross-meeting-continuity-guard-v18210.php'),'18.22 must build on 18.21 full-thread lineage');
for(const table of ['video_meeting_closure_snapshots','video_meeting_closure_handoffs','video_meeting_closure_events'])assert.ok(core.includes(table),`missing closure table ${table}`);
for(const field of ['revision','snapshot_hash','snapshot_json','closed_at','reopened_at'])assert.ok(core.includes(field),`missing immutable closure field ${field}`);
assert.ok(core.includes('uq_video_meeting_closure_revision'),'closure revisions must be unique per meeting');
assert.ok(core.includes('uq_video_meeting_closure_handoff'),'next-meeting handoffs must be idempotent');
assert.ok(core.includes("in_array($meetingStatus,['ended','processed'],true)"),'meeting must end before closure');
assert.ok(core.includes("if($latest&&(string)$latest['status']==='closed')"),'repeated close must be idempotent');
assert.ok(core.includes("$revision=$latest?((int)$latest['revision']+1):1"),'reclose after reopen must create a new immutable revision');
assert.ok(core.includes("status='reopened'"),'reopen must preserve the frozen snapshot instead of deleting it');
assert.ok(core.includes('The frozen closure snapshot was preserved.'),'reopen audit must state preservation');
assert.ok(core.includes("$eligible=$status==='open'"),'only unresolved items may be offered for next-meeting carry');
for(const state of ['verified','action_active','carried_forward','resolved_elsewhere','superseded'])assert.ok(core.includes(`'${state}'`)||core.includes(`=== '${state}'`),`missing closure status ${state}`);
assert.ok(core.includes("DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 DAY)"),'next-meeting search must be bounded');
assert.ok(core.includes("$normalized===$sourceTitle")&&core.includes("'recurring_match'=>$score>=100"),'same-title recurring detection must be advisory and deterministic');
assert.ok(core.includes("'automatic_carry_forward'=>false")&&core.includes("'explicit_next_meeting_selection'=>true"),'next-meeting carry must be explicit');
for(const policy of ['automatic_execution','automatic_task_creation','automatic_calendar_creation','automatic_crm_write','automatic_email_send'])assert.ok(core.includes(`'${policy}'=>false`),`missing no-side-effect policy ${policy}`);
for(const privacy of ['participant_scoring','participant_email_read','raw_transcript_read','private_notes_read','homeserver_historical_probe'])assert.ok(core.includes(`'${privacy}'=>false`),`missing privacy boundary ${privacy}`);

assert.ok(core.includes('video_meeting_continuity_thread_item_ids_v18210'),'handoff must inspect the full continuity thread');
assert.ok(core.includes("c.status='verified'"),'verified intended outcomes must block another handoff');
assert.ok(core.includes('This continuity thread already has Meeting Action history'),'existing actions must block duplicate next-meeting paths');
assert.ok(core.includes('video_meeting_continuity_carry_forward_v18210'),'18.22 must reuse canonical 18.21 continuity creation');
assert.ok(core.includes("SET status='superseded'")&&core.includes('target_agenda_item_id=?'),'successful A→B→C carry must supersede the prior active incoming link');
assert.ok(core.includes('Organizer explicitly carried one unresolved closure item into the selected next meeting. No external action was created or executed.'),'handoff audit must preserve the no-execution boundary');
assert.ok(guard.includes('video_meeting_closure_active_thread_target_v18220'),'parallel future continuity branches must be detected');
assert.ok(guard.includes("l.status='carried_forward' AND l.target_meeting_id<>?"),'active thread detection must exclude only the current meeting');
assert.ok(guard.includes('Resolve or supersede that link before carrying it to another next meeting.'),'branch conflict must require explicit organizer resolution');
assert.ok(guard.includes('video_meeting_continuity_existing_target_link_v18210'),'exact source→target retry must be recoverable');
assert.ok(guard.includes('repair any missing audit row'),'retry path must document handoff repair semantics');

assert.ok(api.includes("REQUEST_METHOD")&&api.includes("'POST'"),'18.22 API must be POST-only');
assert.ok(api.includes('hash_equals(csrf_token(),$csrf)'),'18.22 API must enforce CSRF');
assert.ok(api.includes("(int)($meeting['owner_user_id']??0)!==$userId"),'18.22 API must be organizer-scoped');
for(const action of ['state','close','reopen','carry_to_next'])assert.ok(api.includes(`$action==='${action}'`),`missing closure API action ${action}`);
assert.ok(api.includes('video_meeting_closure_guarded_carry_to_next_v18220'),'public next-meeting handoff must use the branch guard');
assert.ok(!api.includes('$_GET'),'closure mutations must not accept query-string state');

assert.ok(ui.includes('Meeting closure & next meeting'),'existing Meeting workspace must expose closure UI');
assert.ok(ui.includes('Close meeting review'),'closure must be explicit');
assert.ok(ui.includes('Reopen review'),'organizer must be able to reopen review without deleting history');
assert.ok(ui.includes('Close new revision'),'reclosed reviews must create a new revision');
assert.ok(ui.includes('Carry to next meeting'),'each unresolved handoff must be explicit');
assert.ok(ui.includes('Recurring match · '),'advisory recurring meeting matches must be visible');
assert.ok(ui.includes('No external action was created or executed.'),'UI must preserve no-execution semantics');
assert.ok(ui.includes('vp3:meeting-closure-changed'),'closure updates must publish a workspace event');
assert.ok(!ui.includes('innerHTML'),'closure UI must render safely');
assert.ok(loader.includes('loadClosureController'),'18.21 controller must load 18.22');
assert.ok(loader.includes("dataset.vp3MeetingClosure='18220'"),'18.22 loader marker is required');
assert.ok(loader.includes('video-meetings-closure-recurring-continuity-v18220.js?v=18220'),'18.22 client must be version pinned');

assert.ok(memory.includes('video_meeting_memory_closure_query_relevant_v18220'),'Agent Chat must recognize closure/next-meeting queries');
assert.ok(memory.includes('video_meeting_memory_closure_context_v18220'),'Agent Chat must receive bounded closure context');
assert.ok(memory.includes('LIMIT 12'),'closure Agent context must be bounded');
assert.ok(memory.includes("'closure_version'=>'v18.22'"),'Meeting Memory must identify the closure version');
assert.ok(memory.includes("'meeting_closure'=>$closures"),'Meeting Memory must expose closure state read-only');
assert.ok(memory.includes('a closed snapshot freezes what was reviewed'),'Agent instructions must explain frozen-review semantics');
assert.ok(memory.includes('next-meeting handoff means the organizer explicitly carried context forward'),'Agent instructions must not confuse carry-forward with execution');
assert.ok(memory.includes('Continuity, closure and next-meeting mutations require explicit organizer action in the Meeting workspace.'),'Agent Chat must remain read-only for closure mutations');
assert.ok(memory.includes("'source'=>'video_meeting_closure:'")&&memory.includes("'source'=>'video_meeting_closure_handoff:'"),'closure context must publish citations');

assert.ok(upgrade.includes('video-meetings-closure-recurring-continuity-v18220.php'),'upgrade must load 18.22');
assert.ok(upgrade.includes('video_meeting_closure_schema_ready_v18220()'),'upgrade completeness must require 18.22');
assert.ok(upgrade.includes('video_meeting_closure_ensure_schema_v18220($pdo)'),'upgrade must install 18.22');
assert.ok(upgrade.includes('immutable meeting closure snapshots/next-meeting handoffs'),'upgrade preservation copy must protect 18.22 history');

for(const writer of ['user_calendar_automation_create_event_v1300','crm_v180_activity(','crm_v180_create_task(','agent_workflow_create_from_priority_v1400','agent_appointment_lifecycle_email_v700(','mail(']){
  assert.ok(!core.includes(writer)&&!guard.includes(writer),`meeting closure must not execute downstream writer ${writer}`);
}

console.log('Phase 18.22 Meeting Closure & Recurring Continuity contract passed.');
