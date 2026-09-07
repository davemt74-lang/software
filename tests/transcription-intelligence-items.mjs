import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const items = read('includes/transcription-intelligence-items.php');
const api = read('api/artist-listening-intelligence-v300.php');
const client = read('artist-listening-ai.js');
const transcript = read('artist-listening-transcript.js');

/* Durable intelligence-object metadata. */
assert.match(items,/function transcription_intelligence_normalize_modules_v302/);
for (const field of ['item_id','plugin_id','section_key','source_fingerprint','review_state','reviewed_at','user_edited','evidence_refs']) {
  assert.ok(items.includes(`'${field}'`), `intelligence item metadata must include ${field}`);
}
assert.match(items,/ti_'\.substr\(\$fingerprint,0,20\)/,'item IDs must be deterministic from source fingerprints');
assert.match(items,/transcription_intelligence_review_index_v302/,'review state must be reusable across equivalent reruns');
assert.match(items,/\$priorReviewIndex\[\$fingerprint\]/,'normalization must reapply prior review state by stable source fingerprint');
assert.match(items,/edited_text/,'user edits must persist separately from the source fingerprint');
assert.match(items,/intelligence_items_version'\] = 302/,'persisted analysis must advertise the durable item contract');
assert.doesNotMatch(items,/CREATE TABLE|ALTER TABLE/,'item persistence must reuse the existing master-analysis JSON container');

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
for (const action of ['review_item','edit_item']) assert.ok(api.includes(`$action === '${action}'`) || api.includes("['review_item','edit_item']"), `${action} API action must exist`);
assert.match(api,/transcription_intelligence_review_item_v302/);
assert.match(api,/transcription_intelligence_edit_item_v302/);
assert.match(client,/data-listening-ai-review="accepted"/);
assert.match(client,/data-listening-ai-review="rejected"/);
assert.match(client,/data-listening-ai-edit=/);
assert.match(client,/data-listening-ai-edit-save=/);
assert.match(items,/review_state.*accepted.*rejected/s,'review state must be constrained to explicit human states');
assert.match(items,/\$item\['review_state'\] = 'accepted'/,'editing an item must explicitly accept the edited result');

/* Rejected intelligence cannot leak into Brain/Knowledge report exports. */
assert.match(items,/function transcription_intelligence_export_result_v302/);
assert.match(items,/\(\$item\['review_state'\] \?\? ''\) === 'rejected'/);
assert.match(api,/transcription_intelligence_report_text_v302/,'Brain/Knowledge export must use the review-aware compiler');
assert.match(api,/source'=>'transcription-intelligence-v302'/,'Agent Brain provenance must identify the v302 intelligence layer');

/* Canonical ownership stays singular. */
assert.match(client,/const BUILD = 'transcription-intelligence-v302-20260906'/);
assert.doesNotMatch(client,/document\.addEventListener\('click'/,'no delegated document click owner is allowed');
assert.doesNotMatch(client,/MutationObserver/,'AI controller must not observe/rewrite the page runtime');
assert.doesNotMatch(client,/MediaRecorder/,'AI controller must never own recording');

console.log('VP3 transcription intelligence items contract: PASS');
