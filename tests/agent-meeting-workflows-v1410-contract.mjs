import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(path, 'utf8');
const meeting = read('includes/agent-meeting-workflows-v1410.php');
const lifecycle = read('includes/agent-appointment-lifecycle-v700.php');
const delivery = read('includes/agent-appointment-lifecycle-v700-part7.php');
const housekeeping = read('includes/agent-appointment-lifecycle-v700-part8.php');
const controller = read('includes/appointment-lifecycle-controller-v700.php');

assert.match(lifecycle, /agent-meeting-workflows-v1410\.php/, 'Appointment lifecycle must load Phase 14.1 orchestration');
assert.match(meeting, /require_once __DIR__\.'\/agent-workflow-runs-v1400\.php'/, 'Phase 14.1 must reuse the canonical Phase 14 ledger');
assert.match(meeting, /workflow_type[^\n]*meeting_prep|agent_meeting_workflow_create_run_v1410\([^;]*'meeting_prep'/s, 'Meeting prep must be a durable workflow run');
assert.match(meeting, /prepare-brief[\s\S]*publish-chat/, 'Prep must prepare the brief before Chat-canvas publication');
assert.match(meeting, /agent_workflow_claim_next_action_v1400/, 'Meeting execution must use the canonical Phase 14 executor claim');
assert.match(meeting, /agent_workflow_record_action_result_v1400/, 'Meeting execution must record observable Phase 14 results');
assert.match(meeting, /agent_meeting_workflow_active_action_v1410/, 'Phase 14.1 must detect interrupted active actions instead of leaving runs permanently stuck');

assert.match(meeting, /agent_chat_v101_append_ecosystem_message\(\$user,\$message,\$context\)/, 'Prep reports must use the canonical Agent Chat append path');
assert.match(meeting, /Agent Chat is unavailable, so the meeting prep report cannot be delivered\./, 'Prep must fail rather than silently skip Agent Chat delivery');
assert.match(meeting, /meeting_prep_chat_published/, 'Prep Chat publication must be audited in the workflow event ledger');
assert.match(meeting, /meeting_prep_report[^\n]*true/, 'Agent Chat context must mark meeting-prep reports explicitly');
assert.match(meeting, /skip_brain_archive[^\n]*true/, 'Derived meeting reports must not be re-ingested as new Brain memory');
assert.match(meeting, /execute_prep_action_v1410[\s\S]*active_action_v1410/, 'Read-only prep must be resumable after an interrupted request');
assert.match(delivery, /if\(\$key==='agent_prep'\)[\s\S]*agent_meeting_workflow_prepare_v1410\(\$pdo,\$booking,false\)/, 'Timed meeting prep must go through Phase 14.1 and Agent Chat');
assert.match(controller, /action==='prepare_brief'[\s\S]*agent_meeting_workflow_prepare_v1410\(\$pdo,\$booking,true\)/, 'Manual prep refreshes must also report to Agent Chat');
assert.doesNotMatch(controller, /action==='prepare_brief'[\s\S]{0,250}agent_appointment_lifecycle_prepare_brief_v700\(\$pdo,\$booking\)/, 'Manual prep may not bypass the Chat-canvas contract');

assert.match(meeting, /meeting_followup/, 'Completed meetings must create follow-up workflows');
assert.match(meeting, /send-followup:/, 'Approved follow-up delivery must identify the canonical follow-up record');
assert.match(meeting, /'requires_approval'=>true/, 'External follow-up sending must be approval-gated');
assert.match(meeting, /scheduling\.followup\.send/, 'Follow-up execution must route through the scheduling capability boundary');
assert.match(meeting, /agent_appointment_lifecycle_send_followup_v700/, 'Phase 14.1 must reuse Scheduling as the authoritative send path');
assert.match(meeting, /This message has not been sent\./, 'Agent Chat must clearly disclose that a follow-up draft is pending approval');
assert.match(meeting, /status IN \('approved','executing'\)/, 'Housekeeping may execute only approved follow-up runs');
assert.match(meeting, /ambiguous_delivery/, 'An interrupted external send with unknown outcome must fail closed instead of being automatically resent');
assert.match(meeting, /\$wasInterrupted&&\(string\)\$followup\['message_status'\\]!=='sent'/, 'Only database-confirmed sent follow-ups may auto-close after interruption');
assert.match(housekeeping, /agent_meeting_workflow_housekeeping_v1410/, 'Lifecycle housekeeping must reconcile drafts and execute approved follow-ups');
assert.match(housekeeping, /register_shutdown_function[\s\S]*agent_meeting_workflow_housekeeping_v1410/, 'Approval POSTs must get an immediate shutdown execution pass');

console.log('Agent Meeting Workflows v14.10 contract: OK');