import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const actions = read('includes/transcription-intelligence-actions.php');
const deepItems = read('includes/transcription-deeper-items.php');
const items = read('includes/transcription-intelligence-items.php');
const api = read('api/artist-listening-intelligence-v300.php');
const client = read('artist-listening-ai.js');
const chat = read('includes/agent-chat-continuity-v101.php');
const lifecycle = read('includes/agent-memory-lifecycle-v123.php');
const crm = read('includes/crm-v180.php');
const baseline = read('tools/run_recovery_baseline.py');

/* Operational writes are explicit and require human acceptance. */
assert.match(actions,/function transcription_intelligence_require_accepted_v303/);
assert.match(actions,/review_state.*accepted/s,'operational actions must require an accepted intelligence item');
assert.match(actions,/Accept or edit this intelligence item before taking an operational action/);
assert.ok(api.includes("$action==='item_action'"),'stable transcription API must expose one explicit item-action endpoint');
assert.match(api,/transcription_deeper_execute_action_v307/,'v307 adapter must own the stable item-action route');
assert.match(deepItems,/transcription_intelligence_require_accepted_v303/,'v307 adapter must preserve the v303 acceptance gate');
assert.doesNotMatch(api,/item_action.*analyze/s,'analysis itself must not implicitly execute operational actions');

/* Action surface is bounded and uses existing product systems. */
for (const action of ['main_chat','agent_brain','agent_task','personal_knowledge','project_note','crm_note','crm_task']) assert.ok(actions.includes(`'${action}'`), `${action} must be an explicit supported action`);
assert.match(actions,/agent_chat_v101_append_ecosystem_message/,'Main Chat action must use the canonical chat bridge');
assert.match(chat,/INSERT INTO chat_messages/,'canonical chat bridge must remain the persisted canvas writer');
assert.match(chat,/agent_brain_archive_and_parse/,'canonical chat bridge must continue archiving operational messages into Agent Brain');
assert.doesNotMatch(actions,/CREATE TABLE|ALTER TABLE/,'operational intelligence must not introduce a parallel action schema');
assert.doesNotMatch(deepItems,/CREATE TABLE|ALTER TABLE/,'v307 action adaptation must not introduce a parallel action schema');

/* Agent follow-ups use the existing Agent Brain task/commitment lifecycle. */
assert.match(actions,/memory_type,subject,memory_text,memory_hash/);
assert.match(actions,/\$kind=transcription_intelligence_task_kind_v303/);
assert.match(actions,/\? 'commitment' : 'task'/,'decisions/commitments must create commitment memories; other promoted items create tasks');
assert.match(actions,/'task_status'=>\$priorStatus/,'task refreshes must preserve the existing lifecycle status');
assert.match(actions,/'source_kind'=>'transcription_intelligence'/);
assert.match(actions,/created_from_reviewed_item'=>true/);
assert.match(actions,/UPDATE agent_memory_items SET subject=\?,memory_text=\?/,'editing a reviewed source item must refresh an existing promoted Agent task in place');
assert.match(actions,/updated_from_reviewed_item/,'updated task provenance must be explicit');
assert.match(lifecycle,/\['open','in_progress','waiting','completed','cancelled'\]/,'promoted transcription tasks must participate in the existing task lifecycle');
assert.match(lifecycle,/memory_type IN \('commitment','task'\)/);

/* Per-item Brain and Knowledge promotions are deterministic and source-aware. */
assert.match(actions,/agent_brain_v122_upsert_system_memory/);
assert.match(actions,/'transcript_intelligence'/);
assert.match(actions,/'transcription-item:'\.\$itemId/);
assert.match(actions,/personal_knowledge_store/);
assert.match(actions,/'transcription-intelligence-item:'\.\$itemId/);
assert.match(actions,/'evidence_refs'/);
assert.match(actions,/'review_state'=>'accepted'/);
assert.match(actions,/transcription_app_permissions_v300\(\$user\)/,'operational Brain/Knowledge availability must reuse canonical transcription write permissions');
assert.match(deepItems,/in_array\(\$action,\['agent_brain','personal_knowledge'\],true\)/,'v307 must preflight both long-term knowledge destinations');

/* Project notes only target an explicitly linked writable track. */
assert.match(actions,/project_track_id/);
assert.match(actions,/artist_listening_v172_track_allowed/);
assert.match(actions,/track_notes\.manage/);
assert.match(actions,/INSERT INTO track_notes/);
assert.match(actions,/\$actionKey=transcription_intelligence_action_key_v303\(\$action,\$action === 'project_note' \? 0 : \$targetId\)/,'v303 project-note receipt identity must remain stable');
assert.match(actions,/\$action !== 'project_note' \|\| \(int\)\(\$existing\['target_id'\] \?\? 0\) === \$targetId/,'a project-note receipt is reusable only for the currently linked track');
assert.doesNotMatch(actions,/INSERT INTO tracks/,'transcription intelligence must not create projects/tracks implicitly');

/* CRM writes revalidate explicit matched CRM context and reuse canonical CRM APIs. */
assert.match(actions,/transcription_app_crm_context_v301/);
assert.match(actions,/transcription_intelligence_validate_crm_target_v303/);
assert.match(actions,/Choose an explicitly matched CRM lead for this transcript/);
assert.match(actions,/crm_v180_activity/,'CRM Note must use canonical CRM activity history');
assert.match(actions,/crm_v180_create_task/,'CRM Task must use canonical CRM task creation');
assert.match(crm,/CRM tasks can only be assigned to an Admin account/,'canonical CRM assignment guard must remain in force');
assert.match(actions,/'assigned_user_id'=>0/,'transcript CRM follow-ups must not assume the acting manager is an assignable Admin');
assert.doesNotMatch(actions,/crm_v180_upsert_contact|crm_v180_create_demo_lead/,'transcript actions must never create or infer CRM contacts/leads');

/* Receipts make actions idempotent and survive equivalent plugin reruns. */
assert.match(actions,/function transcription_intelligence_action_key_v303/);
assert.match(actions,/function transcription_intelligence_existing_receipt_v303/);
assert.match(actions,/if \(\$existing &&/,'existing action receipt must short-circuit duplicate writes only after target revalidation');
assert.match(actions,/function transcription_intelligence_record_receipt_v303/);
assert.match(actions,/\$actions\[\$actionKey\]/);
assert.match(items,/'actions'=>is_array\(\$actions\) \? \$actions : \[\]/,'review index must preserve action receipts across reruns');
assert.match(items,/\$prior\['actions'\]/,'module normalization must restore prior receipts');
assert.match(items,/if \(\$previousText !== \$text\) \{[\s\S]*unset\(\$item\['actions'\]\)/,'editing actionable text must invalidate stale action receipts');
assert.match(items,/in_array\(\(string\)\$key,\['source_fingerprint','edited_text','actions','relations'\],true\)/,'internal action receipts and relation adjacency must not pollute compiled Brain/Knowledge report text');
assert.match(deepItems,/function transcription_deeper_record_receipt_v307/,'v307 must preserve receipts on extension-aware sections');

/* Accepted items expose one compact operational menu inside the current canonical controller. */
assert.match(client,/const BUILD = 'transcription-deeper-v307-20260907'/);
assert.match(client,/item\.review_state !== 'accepted'/,'operational menu must be hidden until human acceptance');
assert.match(client,/class="sf-listening-ai-operational"/);
assert.match(client,/data-listening-ai-item-action=/);
assert.match(client,/data-listening-ai-action-item=/);
assert.match(client,/data-listening-ai-action-target=/);
assert.match(client,/sf-listening-ai-action-receipt/,'completed operations must render as receipts/badges');
assert.match(client,/request\('item_action'/);
assert.match(client,/performItemAction:/,'public AI controller API must expose explicit item actions');
assert.match(client,/state\.operations = data\.operations \|\| state\.operations/,'server permissions/targets must own available operations');
assert.match(client,/buildRelations:/,'v305 relationship controls must coexist in the same canonical controller');

/* Canonical frontend ownership remains singular. */
assert.doesNotMatch(client,/document\.addEventListener\('click'/,'no delegated document click owner is allowed');
assert.doesNotMatch(client,/MutationObserver/,'AI Summary must not add a runtime observer/fallback');
assert.doesNotMatch(client,/MediaRecorder/,'AI Summary must not own recording');
assert.doesNotMatch(client,/INSERT INTO|UPDATE crm_/,'browser must never own persistence');
assert.match(baseline,/tests\/transcription-intelligence-actions\.mjs/);

console.log('VP3 transcription intelligence operational actions contract: PASS');
