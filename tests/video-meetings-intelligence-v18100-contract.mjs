import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read=p=>readFileSync(p,'utf8');
const follow=[read('includes/video-meetings-followthrough-v18100.php'),...Array.from({length:6},(_,i)=>read(`includes/video-meetings-followthrough-v18100-part${i+1}.php`))].join('\n');
const api=read('api/video-meeting-intelligence.php');
const handoff=read('includes/video-meetings-intelligence-handoff-v1890.php');
const ui=read('video-meetings-intelligence-v1820.js')+'\n'+read('video-meetings-followthrough-v18100.js');

assert.ok(follow.includes("const VP3_VIDEO_MEETING_FOLLOWTHROUGH_V18100='video-meetings-followthrough-v18100-20260915'"));
assert.ok(follow.includes("VP3_VIDEO_MEETING_FOLLOWTHROUGH_APP_V18100='meeting_followthrough_v18100'"));
assert.ok(follow.includes("VP3_VIDEO_MEETING_FOLLOWTHROUGH_ARTIFACT_V18100='post_meeting_action_queue'"));
assert.ok(!follow.includes('CREATE TABLE'), '18.10 must not create parallel domain tables');
for(const canonical of ['agent_memory_items','agent_brain_v122_upsert_system_memory','user_calendar_automation_create_event_v1300','crm_v180_activity','crm_v180_create_task','agent_meeting_workflow_create_run_v1410','agent_meeting_workflow_publish_followup_v1410']) assert.ok(follow.includes(canonical),`missing canonical writer ${canonical}`);

assert.ok(follow.includes('video_meeting_followthrough_require_final_v18100'));
assert.ok(follow.includes("['ended','processed']"));
assert.ok(follow.includes("!hash_equals((string)$state['final_source_hash'],$hash)"));
assert.ok(follow.includes('video_meeting_intelligence_public_state_v1890'));
assert.ok(follow.includes('video_meeting_artifacts'));
assert.ok(follow.includes('source_hash'));

for(const status of ['suggested','approved','executed','verified','failed'])assert.ok(follow.includes(`'${status}'`),`missing lifecycle ${status}`);
assert.ok(follow.includes("if($status!=='approved')"));
assert.ok(api.includes("$action==='queue_approve'"));
assert.ok(api.includes("$action==='queue_execute'"));
assert.ok(api.includes("$action==='queue_update'"));
assert.ok(api.includes("$action==='queue_refresh'"));

assert.ok(follow.includes("'meeting-followthrough:'"));
assert.ok(follow.includes("sha1('meeting-followthrough|"));
assert.ok(follow.includes("'source_kind'=>'video_meeting_intelligence'"));
assert.ok(follow.includes("'review_state'=>'approved'"));

assert.ok(follow.includes('Choose an explicit CRM lead before approval.'));
assert.ok(follow.includes('SELECT id FROM crm_leads WHERE id=?'));
assert.ok(!follow.includes('crm_v180_upsert_contact('));
assert.ok(!follow.includes('crm_v180_create_demo_lead('));

assert.ok(follow.includes('agent_appointment_lifecycle_save_followup_v700'));
assert.ok(follow.includes("'requires_approval'=>true"));
assert.ok(follow.includes("'scheduling.followup.send'"));
assert.ok(!follow.includes('agent_appointment_lifecycle_send_followup_v700('));
assert.ok(!follow.includes('agent_meeting_workflow_execute_followups_v1410('));
assert.ok(!follow.includes('mail('));

assert.ok(follow.includes('user_agents_list_v236'));
assert.ok(follow.includes("filter_var($owner,FILTER_VALIDATE_EMAIL)"));
assert.ok(follow.includes("'assigned_agent_id'"));

assert.ok(follow.includes("$snapshot=is_array($public['snapshot']??null)?$public['snapshot']:[]"));
for(const forbidden of ['relay_credentials','provider_secret','source_excerpt','raw_transcript'])assert.ok(!follow.includes(forbidden),`private field leaked: ${forbidden}`);

assert.ok(handoff.includes('video_meeting_followthrough_queue_context_v18100'));
assert.ok(handoff.includes("'followthrough_pending'"));
assert.ok(handoff.includes('Post-Meeting Action Queue'));
assert.ok(handoff.includes("'skip_brain_archive'=>true"));

assert.ok(ui.includes("button.dataset.pane='followthrough'"));
assert.ok(ui.includes("pane.id='meetingPane-followthrough'"));
assert.ok(ui.includes('Post-Meeting Action Queue'));
assert.ok(ui.includes("queueRequest('queue_approve'"));
assert.ok(ui.includes('intelligence(action,extra)'));
assert.ok(!ui.includes('followthrough.php'));

console.log('Phase 18.10 Post-Meeting Follow-Through contract passed.');
