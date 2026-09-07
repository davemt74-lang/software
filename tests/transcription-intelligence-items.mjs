import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const items = read('includes/transcription-intelligence-items.php');
const deepItems = read('includes/transcription-deeper-items.php');
const outputs = read('includes/transcription-intelligence-outputs.php');
const deep = read('includes/transcription-deeper-intelligence.php');
const api = read('api/artist-listening-intelligence-v300.php');
const client = read('artist-listening-ai.js');
const transcript = read('artist-listening-transcript.js');

/* Durable intelligence-object metadata. */
assert.match(items,/function transcription_intelligence_normalize_modules_v302/);
for (const field of ['item_id','plugin_id','section_key','source_fingerprint','review_state','reviewed_at','user_edited','evidence_refs']) assert.ok(items.includes(`'${field}'`), `intelligence item metadata must include ${field}`);
assert.match(items,/ti_'\.substr\(\$fingerprint,0,20\)/,'item IDs must be deterministic from source fingerprints');
assert.match(items,/transcription_intelligence_review_index_v302/,'review state must be reusable across equivalent reruns');
assert.match(items,/\$priorReviewIndex\[\$fingerprint\]/,'normalization must reapply prior review state by stable source fingerprint');
assert.match(items,/edited_text/,'user edits must persist separately from the source fingerprint');
assert.match(items,/intelligence_items_version'\] = 302/,'persisted analysis must advertise the durable item contract');
assert.match(items,/'actions'/,'durable items must preserve operational action receipts');
assert.match(items,/'relations'/,'durable items must preserve v305 relation adjacency across equivalent reruns');
assert.doesNotMatch(items,/CREATE TABLE|ALTER TABLE/,'item persistence must reuse the existing master-analysis JSON container');
assert.match(deepItems,/transcription_intelligence_normalize_modules_v302\(\$modules,\$priorReviewIndex\)/,'v307 extension adapter must run proven v302 normalization first');

/* Evidence stays bounded to transcript locations and is navigable by page. */
assert.match(items,/function transcription_intelligence_evidence_refs_v302/);
assert.match(items,/pages\?\\s\*#\?\\s\*\(\\d\+\)/,'evidence parser must recognize page references');
assert.match(client,/data-listening-ai-evidence=/,'each supported evidence reference must render as a navigation control');
assert.match(client,/STONEFELLOW_ARTIST_LISTENING_TRANSCRIPT\?\.api/,'AI controller must use the transcript controller API rather than own transcript navigation');
assert.match(client,/transcript\.goPage\(page\)/,'evidence navigation must open the referenced transcript page');
assert.match(client,/stonefellow:artist-listening-evidence-requested/,'evidence navigation must emit a bounded integration event');
assert.match(transcript,/goPage:page=>/,'canonical transcript controller must remain the page owner');
assert.doesNotMatch(client,/sfListeningTranscriptNav/,'AI controller must not reach into transcript navigation DOM');

/* One plugin can be rerun without changing the selected plugin set. */
assert.match(client,/function analyzeApps\(appIds, mode = 'manual'\)/);
assert.match(client,/data-listening-ai-rerun/,'active plugin tab must expose Run Again');
assert.match(client,/analyzeApps\(\[state\.activeApp\], 'manual'\)/,'Run Again must execute only the active plugin');
assert.match(client,/apps:requested/,'backend request must receive only the explicitly requested plugin IDs');
assert.match(client,/analyzePlugin:/,'public controller API must expose single-plugin analysis');

/* Review/edit actions persist server-side and remain human-controlled. */
for (const action of ['review_item','edit_item']) assert.ok(api.includes(action), `${action} API action must exist`);
assert.match(api,/transcription_deeper_review_item_v307/,'stable API must use extension-aware review for v307 sections');
assert.match(api,/transcription_deeper_edit_item_v307/,'stable API must use extension-aware edit for v307 sections');
assert.match(deepItems,/function transcription_deeper_review_item_v307/);
assert.match(deepItems,/function transcription_deeper_edit_item_v307/);
assert.match(client,/data-listening-ai-review="accepted"/);
assert.match(client,/data-listening-ai-review="rejected"/);
assert.match(client,/data-listening-ai-edit=/);
assert.match(client,/data-listening-ai-edit-save=/);
assert.match(items,/review_state.*accepted.*rejected/s,'base review state must remain constrained to explicit human states');
assert.match(deepItems,/\$item\['review_state'\]='accepted'/,'editing a v307 item must explicitly accept the edited result');
assert.match(deepItems,/\$reviewState==='rejected'[\s\S]*transcription_intelligence_remove_item_relations_v305/,'rejecting a v307 item must invalidate v305 relations touching it');
assert.match(deepItems,/\$previousText!==\$text[\s\S]*transcription_intelligence_remove_item_relations_v305/,'editing a v307 item must invalidate relations touching it');

/* Rejected intelligence and internal receipts/relations cannot leak into compiled Brain/Knowledge reports. */
assert.match(items,/function transcription_intelligence_export_result_v302/);
assert.match(items,/\(\$item\['review_state'\] \?\? ''\) === 'rejected'/);
assert.match(items,/\['source_fingerprint','edited_text','actions','relations'\]/,'internal metadata must stay out of compiled report text');
assert.match(api,/transcription_deeper_report_text_v307/,'Brain/Knowledge export must use the v307-filtered review-aware compiler');
assert.match(api,/source'=>'transcription-intelligence-v307'/,'whole-report Agent Brain provenance must identify the current server runtime');

/* Canonical ownership stays singular while v307 wraps v306 outputs through extension-aware items. */
assert.match(client,/const BUILD = 'transcription-deeper-v307-20260907'/);
assert.match(api,/transcription_app_analyze_v307\(/,'stable analysis must execute through v307');
assert.match(deep,/transcription_app_analyze_v306\(/,'v307 must preserve v306 output analysis underneath deeper enrichment');
assert.match(outputs,/transcription_app_analyze_v304\(/,'v306 must preserve v304 source analysis without replacing durable item semantics');
assert.match(client,/performItemAction:/,'controller API must expose explicit item actions');
assert.match(client,/buildRelations:/,'controller API must expose explicit v305 relationship building');
assert.match(outputs,/transcription_output_normalize_master_v306/,'v306 must normalize v302 items while preserving output modules');
assert.doesNotMatch(client,/document\.addEventListener\('click'/,'no delegated document click owner is allowed');
assert.doesNotMatch(client,/MutationObserver/,'AI controller must not observe/rewrite the page runtime');
assert.doesNotMatch(client,/MediaRecorder/,'AI controller must never own recording');

console.log('VP3 transcription intelligence items contract: PASS');
