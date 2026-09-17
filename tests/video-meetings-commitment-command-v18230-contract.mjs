import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const core=read('includes/video-meetings-commitment-command-v18230.php');
const adapter=read('includes/video-meetings-commitment-command-v18230-chat.php');
const chat=read('api/chat-v236.php');

assert.ok(core.includes("VP3_VIDEO_MEETINGS_COMMITMENT_COMMAND_V18230='video-meetings-commitment-command-v18230-20260917'"),'18.23 version marker missing');
assert.ok(core.includes('video_meeting_commitment_command_query_relevant_v18230'),'commitment-oriented Agent queries must be recognized');
assert.ok(core.includes('video_meeting_commitment_command_state_v18230'),'commitment command state builder missing');

for(const table of ['video_meeting_agenda_items','video_meeting_followthrough_plans','video_meeting_plan_action_handoffs','video_meeting_action_executions','video_meeting_followthrough_monitors','video_meeting_followthrough_closures','video_meeting_continuity_links']){
  assert.ok(core.includes(table),`missing canonical lifecycle table ${table}`);
}
assert.ok(core.includes('m.owner_user_id=a.owner_user_id'),'meeting join must preserve organizer ownership');
assert.ok(core.includes('p.owner_user_id=a.owner_user_id'),'plan join must preserve organizer ownership');
assert.ok(core.includes('h.owner_user_id=a.owner_user_id'),'handoff join must preserve organizer ownership');
assert.ok(core.includes('e.owner_user_id=a.owner_user_id'),'execution join must preserve organizer ownership');
assert.ok(core.includes('fm.owner_user_id=a.owner_user_id'),'follow-through join must preserve organizer ownership');
assert.ok(core.includes('vc.owner_user_id=a.owner_user_id'),'verification join must preserve organizer ownership');
assert.ok(core.includes('cl2.owner_user_id=a.owner_user_id'),'continuity lookup must preserve organizer ownership');
assert.ok(core.includes('WHERE a.owner_user_id=? AND m.owner_user_id=?'),'public state must be organizer-scoped at the root query');

assert.ok(core.includes('video_meeting_plan_action_snapshot_hash_v18190'),'18.23 must calculate current plan snapshot drift');
assert.ok(core.includes('video_meeting_plan_action_bound_draft_hash_v18190'),'18.23 must calculate current plan-bound action draft drift');
assert.ok(!core.includes('video_meeting_plan_action_guard_execution_v18190('),'read model must not invoke the mutating stale-handoff guard');
assert.ok(core.includes("if($expected>0&&$now>=$expected)return 'overdue'"),'overdue state must be derived without monitor reconciliation');
assert.ok(core.includes("if($expected>0&&$expected-$now<=86400)return 'due_soon'"),'due-soon state must be derived without monitor reconciliation');

for(const bucket of ['verified','action_failed','followthrough_blocked','overdue','handoff_drift','plan_needs_definition','verification_needs_attention','verification_review','waiting_for_verification','action_needs_review','ready_for_handoff','carried_forward','active_followthrough','action_in_progress','active']){
  assert.ok(core.includes(`'${bucket}'`),`missing command bucket ${bucket}`);
}
assert.ok(core.includes("(string)($row['continuity_status']??'')==='carried_forward'"),'only active continuity may be classified or counted as carried forward');
assert.ok(core.includes("a.status<>'skipped'"),'skipped agenda items must not appear as active commitments');
assert.ok(core.includes("(a.item_type='follow_up' OR a.status='follow_up')"),'command must remain bounded to follow-up commitments');
assert.ok(core.includes('max(1,min(100,$limit))'),'command query must have a hard bounded result window');

for(const writer of ['INSERT INTO','UPDATE ','DELETE FROM','->exec(','video_meeting_continuity_carry_forward_v18210(','video_meeting_action_execute','video_meeting_followthrough_reconcile','video_meeting_followthrough_verification_refresh']){
  assert.ok(!core.includes(writer),`18.23 read model must not contain writer path ${writer}`);
}
for(const policyLike of ["'mutations'","'auto_reconcile'","'auto_execute'"]){
  assert.ok(!core.includes(policyLike),`18.23 output should not emit policy-like field ${policyLike}`);
}

assert.ok(adapter.includes('video_meeting_commitment_command_enrich_v18230'),'Agent Chat adapter must enrich meeting context');
assert.ok(adapter.includes("'commitment_command_version'"),'Agent Chat context must identify v18.23');
assert.ok(adapter.includes("'meeting_commitment_command'"),'Agent Chat context must expose command state');
assert.ok(adapter.includes('Treat command buckets as a read-only status summary'),'Agent instructions must explain read-only command semantics');
assert.ok(adapter.includes('carried_forward means continuity exists and does not prove completion'),'Agent must not confuse carry-forward with completion');
assert.ok(adapter.includes('Do not imply that viewing this command changed, reconciled, executed, verified, carried, or closed anything.'),'Agent must not imply side effects');
assert.ok(adapter.includes("'source'=>'video_meeting_commitment_command:'"),'commitment command must publish meeting citations');

assert.ok(chat.includes("video-meetings-commitment-command-v18230-chat.php"),'Agent Chat must load the 18.23 adapter');
assert.ok(chat.includes('video_meeting_commitment_command_enrich_v18230($pdo,$userId,$query,$meetingMemory)'),'Agent Chat must enrich the existing Meeting Memory context');
assert.ok(chat.includes('array_merge(video_meeting_memory_chat_sources_v18120($meetingMemory),video_meeting_commitment_command_chat_sources_v18230($meetingMemory))'),'Agent Chat must expose commitment-command citations alongside existing meeting sources');
assert.ok(chat.indexOf('video_meeting_commitment_command_enrich_v18230')<chat.indexOf("agent_surface_v131_enrich($user,'chat'"),'18.23 context must be present before Agent surface enrichment');

console.log('Phase 18.23 Meeting Commitment Command contract passed.');
