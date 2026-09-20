import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const core=read('includes/video-meetings-cross-meeting-continuity-v18210.php');
const guard=read('includes/video-meetings-cross-meeting-continuity-guard-v18210.php');
const api=read('api/video-meeting-continuity.php');
const prepApi=read('api/video-meeting-automation.php');
const ui=read('video-meetings-cross-meeting-continuity-v18210.js');
const verificationUi=read('video-meetings-followthrough-verification-v18200.js');
const memory=read('includes/video-meetings-memory-v18120-part3.php');
const chat=[read('api/chat-v236.php'),read('includes/agent-chat-runtime-v2160.php')].join('\n');
const upgrade=read('upgrade.php');

assert.ok(core.includes("VP3_VIDEO_MEETINGS_CONTINUITY_V18210='video-meetings-cross-meeting-continuity-v18210-20260917'"));
assert.ok(core.includes('video-meetings-followthrough-verification-v18200.php'),'18.21 must build on verified outcome closure');
for(const table of ['video_meeting_continuity_links','video_meeting_continuity_events'])assert.ok(core.includes(table),`missing continuity table ${table}`);
for(const field of ['source_meeting_id','source_agenda_item_id','source_execution_id','source_closure_id','target_meeting_id','target_agenda_item_id','status','lineage_hash'])assert.ok(core.includes(field),`missing continuity field ${field}`);
assert.ok(core.includes('uq_video_meeting_continuity_source_target'),'source → target meeting lineage must be unique');
assert.ok(core.includes('uq_video_meeting_continuity_lineage'),'lineage hash must be unique per owner');
assert.ok(core.includes("m.status IN ('ended','processed')"),'only finalized prior meetings may become continuity sources');
assert.ok(core.includes("a.item_type IN ('follow_up','decision')")&&core.includes("a.status IN ('follow_up','decision')"),'continuity candidates must be commitments or decisions');
assert.ok(core.includes("$closureVerified=(string)($row['closure_status']??'')==='verified'"),'verified intended outcomes must be recognized');
assert.ok(core.includes("$canCarry=!$linked&&!$closureVerified&&!$hasExecution"),'direct action history or verified closure must block carry-forward');
assert.ok(core.includes('already has Meeting Action history'),'duplicate action prevention must be explicit');
assert.ok(core.includes("'resolved_elsewhere'"),'verified prior outcomes need a resolved continuity state');
assert.ok(core.includes("['carried_forward','resolved_elsewhere','superseded']"),'organizer continuity states must be bounded');
assert.ok(core.includes("'source_kind='continuity'")||core.includes("'continuity'"),'carried context must be represented as continuity agenda lineage');
assert.ok(core.includes('No external action was created or executed.'),'carry-forward must remain context-only');
assert.ok(core.includes("'explicit_carry_forward'=>true")&&core.includes("'duplicate_action_prevention'=>true"),'continuity policy must require explicit carry-forward and duplicate prevention');
for(const policy of ['automatic_execution','automatic_task_creation','automatic_calendar_creation','automatic_crm_write','automatic_email_send'])assert.ok(core.includes(`'${policy}'=>false`),`missing no-side-effect policy ${policy}`);
for(const privacy of ['participant_scoring','participant_email_read','raw_transcript_read','private_notes_read','homeserver_historical_probe'])assert.ok(core.includes(`'${privacy}'=>false`),`missing privacy boundary ${privacy}`);

assert.ok(guard.includes('video_meeting_continuity_related_thread_v18210')||guard.includes('video_meeting_continuity_thread_item_ids_v18210'),'descendant continuity lineage must be checked');
assert.ok(guard.includes("l.status IN ('carried_forward','resolved_elsewhere')")||guard.includes("l.status='carried_forward'"),'active/resolved descendant links must be visible to the guard');
assert.ok(guard.includes("c.status='verified'")||guard.includes("closure_status']??'')==='verified'"),'descendant verified closure must block duplicate carry');
assert.ok(guard.includes("(int)($related['execution_id']??0)>0")||guard.includes("(string)$signal['kind']==='action'"),'descendant action history must block duplicate carry');
assert.ok(guard.includes("'kind'=>'active'")&&guard.includes('This continuity thread is already active in'),'an already-active descendant thread must block a second active carry');
assert.ok(guard.includes("(string)$e->getCode()==='23000'"),'same-target carry races must be idempotently recovered');
assert.ok(guard.includes('video_meeting_continuity_existing_target_link_v18210'),'repeated same-target carry must be idempotent');

assert.ok(api.includes('REQUEST_METHOD')&&api.includes("'POST'"),'18.21 API must be POST-only');
assert.ok(api.includes('hash_equals(csrf_token(),$csrf)'),'18.21 API must enforce CSRF');
assert.ok(api.includes("(int)($meeting['owner_user_id']??0)!==$userId"),'18.21 API must be organizer-scoped');
for(const action of ['state','carry_forward','set_status'])assert.ok(api.includes(`$action==='${action}'`),`missing continuity API action ${action}`);
assert.ok(api.includes('video_meeting_continuity_guarded_state_v18210'),'public state must use descendant guards');
assert.ok(api.includes('video_meeting_continuity_guarded_carry_forward_v18210'),'public carry-forward must use descendant guards');
assert.ok(!api.includes('$_GET'),'continuity mutations must not accept query-string state');

assert.ok(prepApi.includes('video-meetings-cross-meeting-continuity-guard-v18210.php'),'Prep API must load guarded continuity');
assert.ok(prepApi.includes("$prep['continuity']=")&&prepApi.includes('video_meeting_continuity_guarded_state_v18210'),'Prep must surface guarded continuity state');
assert.ok(core.includes("'agent_context'=>"),'continuity must expose bounded Prep Agent context');

// Main Agent Chat consumes continuity through the existing read-only Meeting Memory context, never through a new writer/tool route.
assert.ok(memory.includes('video_meeting_memory_continuity_query_relevant_v18210'),'Meeting Memory must recognize continuity queries');
assert.ok(memory.includes('video_meeting_memory_continuity_context_v18210'),'Meeting Memory must provide bounded continuity context');
assert.ok(memory.includes('LIMIT 10'),'Agent Chat continuity context must be bounded');
assert.ok(memory.includes("'continuity_version'=>'v18.21'"),'Agent context must identify the continuity version');
assert.ok(memory.includes("'meeting_continuity'=>$continuity"),'Agent context must expose continuity lineage');
assert.ok(memory.includes('carried_forward means the prior thread was linked into another meeting, not that it was completed'),'Agent instructions must preserve semantic status boundaries');
assert.ok(memory.includes('Continuity, closure and next-meeting mutations require explicit organizer action in the Meeting workspace.')||memory.includes('Continuity status changes and carry-forward actions require explicit organizer action in the Meeting workspace.'),'Agent Chat must remain read-only for continuity mutations');
assert.ok(memory.includes("'source'=>'video_meeting_continuity:'"),'continuity context must publish meeting citations');
assert.ok(chat.includes('video_meeting_memory_agent_context_v18120($pdo,$userId,$query)'),'Agent Chat must keep using canonical Meeting Memory context');
for(const forbidden of ['email_recipient','note_text','transcript_text','follow_up_draft','provider_secret'])assert.ok(!memory.includes(forbidden),`Agent Chat continuity context must not depend on private field ${forbidden}`);
assert.ok(!memory.includes('SELECT email'),'Meeting Memory continuity must not read participant email');
assert.ok(!memory.includes('raw_transcript_read'),'Meeting Memory continuity must not read raw transcript content');
assert.ok(!memory.includes('private_notes_read'),'Meeting Memory continuity must not read private notes');

assert.ok(ui.includes('Cross-meeting continuity'),'Prep must visibly expose continuity');
assert.ok(ui.includes('Carry forward to agenda'),'carry-forward must be explicit');
assert.ok(ui.includes("['carried_forward','resolved_elsewhere','superseded']"),'UI must expose bounded continuity states');
assert.ok(ui.includes('never creates or executes a Task, Calendar event, CRM activity, or Email'),'UI must explain the no-execution boundary');
assert.ok(ui.includes('vp3:meeting-continuity-changed'),'continuity updates must publish a workspace event');
assert.ok(!ui.includes('innerHTML'),'continuity UI must render safely');
assert.ok(verificationUi.includes('loadContinuityController'),'18.20 controller must load 18.21');
assert.ok(verificationUi.includes("dataset.vp3MeetingContinuity='18210'"),'18.21 loader marker is required');
assert.ok(verificationUi.includes('video-meetings-cross-meeting-continuity-v18210.js?v=18210'),'18.21 client must be version-pinned');

assert.ok(upgrade.includes('video-meetings-cross-meeting-continuity-v18210.php'),'upgrade must load 18.21');
assert.ok(upgrade.includes('video_meeting_continuity_schema_ready_v18210()'),'upgrade completeness must require 18.21 schema');
assert.ok(upgrade.includes('video_meeting_continuity_ensure_schema_v18210($pdo)'),'upgrade must install 18.21 schema');
assert.ok(upgrade.includes('cross-meeting continuity lineage'),'upgrade preservation copy must protect continuity lineage');

for(const writer of ['user_calendar_automation_create_event_v1300','crm_v180_activity(','agent_workflow_create_from_priority_v1400','agent_appointment_lifecycle_email_v700(','mail(']){
  assert.ok(!core.includes(writer)&&!guard.includes(writer)&&!memory.includes(writer),`continuity must not execute downstream writer ${writer}`);
}

console.log('Phase 18.21 Cross-Meeting Commitment Intelligence & Continuity contract passed.');