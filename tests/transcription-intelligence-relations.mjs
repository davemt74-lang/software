import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path,'utf8');
const relations = read('includes/transcription-intelligence-relations.php');
const items = read('includes/transcription-intelligence-items.php');
const api = read('api/artist-listening-intelligence-v300.php');
const client = read('artist-listening-ai.js');
const page = read('artist-listening.php');
const baseline = read('tools/run_recovery_baseline.py');

/* v305 is an extension of durable intelligence items, not a parallel graph database. */
assert.match(relations,/VP3_TRANSCRIPTION_INTELLIGENCE_RELATIONS_V305 = 'transcription-intelligence-relations-v305-20260906'/);
assert.match(relations,/VP3_TRANSCRIPTION_RELATION_MAX_ITEMS_V305 = 80/);
assert.match(relations,/VP3_TRANSCRIPTION_RELATION_MAX_EDGES_V305 = 60/);
assert.doesNotMatch(relations,/CREATE TABLE|ALTER TABLE|INSERT INTO|DELETE FROM/,'relationship graph must not create a parallel persistence layer');
assert.match(relations,/transcription_intelligence_persist_modules_v302/,'relationships must persist through the existing durable item master JSON');

/* Relationship vocabulary and IDs are bounded and deterministic. */
for (const type of ['supports','contradicts','depends_on','answers','follows_from','blocks','duplicates','related']) {
  assert.ok(relations.includes(`'${type}'`), `${type} relationship must remain supported`);
}
assert.match(relations,/function transcription_intelligence_relation_id_v305/);
assert.match(relations,/hash\('sha256',\$sourceId\.'\|'\.\$type\.'\|'\.\$targetId\)/,'relation IDs must be deterministic from endpoint IDs + type');
assert.match(relations,/transcription_intelligence_relation_symmetric_v305\(\$type\) && strcmp\(\$sourceId,\$targetId\)>0/,'symmetric relation IDs must normalize endpoint order');

/* The graph only considers current, non-rejected durable findings across plugins. */
assert.match(relations,/hash_equals\(\$currentHash,\(string\)\(\$module\['source_hash'\]\?\?''\)\)/,'catalog must require the current transcript source hash');
assert.match(relations,/\(string\)\(\$item\['review_state'\]\?\?''\)==='rejected'/,'rejected intelligence items must not enter the graph catalog');
assert.match(relations,/\(string\)\$byId\[\$source\]\['plugin_id'\]===\(string\)\$byId\[\$target\]\['plugin_id'\]/,'same-plugin edges must be rejected');
assert.match(relations,/Link ONLY items from different plugins/,'provider prompt must explicitly require cross-plugin links');
assert.match(relations,/do not add new facts, identities, deadlines or conclusions/,'relationship reasoning must not invent facts');
assert.match(relations,/Prefer a small high-value graph over dense weak links/,'graph prompt must prefer precision over density');

/* Every persisted edge is reciprocal, source-bound, evidence-aware and reviewable. */
assert.match(relations,/'source_hash'=>\$currentHash/);
assert.match(relations,/'evidence_refs'=>transcription_intelligence_relation_evidence_v305/);
assert.match(relations,/'direction'=>\$symmetric\?'peer':'outgoing'/);
assert.match(relations,/'direction'=>\$symmetric\?'peer':'incoming'/);
assert.match(relations,/'other_item_id'=>\$target/);
assert.match(relations,/'other_item_id'=>\$source/);
assert.match(relations,/function transcription_intelligence_review_relation_v305/);
assert.match(relations,/\['unreviewed','accepted','rejected'\]/,'relationship review states must stay bounded');
assert.match(relations,/transcription_intelligence_relation_review_index_v305/,'equivalent graph rebuilds must preserve prior review state by deterministic relation ID');

/* Stale/missing endpoints and edited/rejected findings invalidate reciprocal links. */
assert.match(relations,/function transcription_intelligence_prune_relations_v305/);
assert.match(relations,/!isset\(\$map\[\$otherId\]\)/,'pruning must remove links whose other endpoint disappeared');
assert.match(relations,/!hash_equals\(\$hash,\(string\)\$entry\['source_hash'\]\) \|\| !hash_equals\(\$hash,\(string\)\$map\[\$otherId\]\['source_hash'\]\)/,'pruning must require both endpoints to remain on the relation source hash');
assert.match(items,/\$reviewState === 'rejected'[\s\S]*transcription_intelligence_remove_item_relations_v305\(\$modules,\$itemId\)/,'rejecting an item must remove every reciprocal relation touching it');
assert.match(items,/if \(\$previousText !== \$text\) \{[\s\S]*transcription_intelligence_remove_item_relations_v305\(\$modules,\$itemId\)/,'editing item meaning must invalidate every relation touching it');
assert.match(items,/function_exists\('transcription_intelligence_prune_relations_v305'\)/,'item normalization must prune stale preserved adjacency after reruns');

/* Equivalent reruns preserve adjacency, but Brain/Knowledge exports do not expose graph internals. */
assert.match(items,/'relations'=>is_array\(\$relations\) \? \$relations : \[\]/,'review index must preserve relation adjacency across equivalent plugin reruns');
assert.match(items,/\$relations = is_array\(\$item\['relations'\]/,'item normalization must restore prior relation adjacency');
assert.match(items,/\['source_fingerprint','edited_text','actions','relations'\]/,'Brain/Knowledge export must strip relationship metadata');

/* Provider failures cannot wipe the previous valid graph. */
const missingGraphGuard = relations.indexOf("if (!array_key_exists('relations',$decoded) || !is_array($decoded['relations']))");
const invalidGraphGuard = relations.indexOf("if ($raw && !$relations)");
const clearGraph = relations.indexOf('transcription_intelligence_clear_relations_v305($modules);');
assert.ok(missingGraphGuard >= 0 && missingGraphGuard < clearGraph,'missing/malformed graph response must fail before any existing graph is cleared');
assert.ok(invalidGraphGuard >= 0 && invalidGraphGuard < clearGraph,'non-empty but fully invalid provider graph must fail before existing graph is cleared');
assert.match(relations,/Existing connections were not overwritten/,'provider failure must communicate non-destructive behavior');

/* Graph generation is explicit. Analyze never silently spends the extra relationship AI call. */
assert.match(api,/\$action === 'build_relations'/);
assert.match(api,/transcription_intelligence_build_relations_v305/);
assert.match(api,/\$action === 'review_relation'/);
const apiAnalyzeStart = api.indexOf("if ($action === 'analyze') {");
const apiAnalyzeEnd = api.indexOf("\n    $segments = artist_listening_v172_segments",apiAnalyzeStart);
const apiAnalyzeBlock = api.slice(apiAnalyzeStart,apiAnalyzeEnd);
assert.ok(apiAnalyzeStart >= 0 && apiAnalyzeEnd > apiAnalyzeStart,'Analyze API block must be identifiable');
assert.doesNotMatch(apiAnalyzeBlock,/build_relations|transcription_intelligence_build_relations_v305/,'Analyze API path must not automatically build the relationship graph');
const clientAnalyzeStart = client.indexOf("async function analyzeApps(appIds, mode = 'manual')");
const clientAnalyzeEnd = client.indexOf('\n  async function analyze(',clientAnalyzeStart);
const clientAnalyzeBlock = client.slice(clientAnalyzeStart,clientAnalyzeEnd);
assert.ok(clientAnalyzeStart >= 0 && clientAnalyzeEnd > clientAnalyzeStart,'Analyze client block must be identifiable');
assert.doesNotMatch(clientAnalyzeBlock,/buildRelations|build_relations/,'browser Analyze must not automatically build connections');
assert.match(client,/async function buildRelations\(\)/);
assert.match(client,/request\('build_relations'/);

/* Connections render inside the one canonical AI Summary controller. */
assert.match(client,/function relationTypeLabel\(type, direction\)/);
assert.match(client,/function relationsHtml\(item\)/);
assert.match(client,/Connections \$\{rows\.length\}/);
assert.match(client,/data-listening-ai-relation-review/);
assert.match(client,/async function reviewRelation\(relationId, reviewState\)/);
assert.match(client,/request\('review_relation'/);
assert.match(client,/data-listening-ai-evidence/,'relationship evidence must reuse canonical transcript evidence navigation');
assert.match(client,/buildRelations:async/);
assert.match(client,/reviewRelation:async/);
assert.match(page,/\.sf-listening-ai-relations\{/,'relationship UI must be styled in the canonical page');
assert.match(page,/artist-listening-ai\.js\?v=transcription-relations-v305-20260906/,'page must use v305 cache identity');
assert.match(client,/const BUILD = 'transcription-relations-v305-20260906'/);
assert.doesNotMatch(client,/MutationObserver|sfListeningTranscriptNav|MediaRecorder/,'relationship UI must not take transcript, recording or page-runtime ownership');

assert.match(api,/vp3-transcription-intelligence-v305-20260906/,'stable API build must advance to v305');
assert.match(api,/'source'=>'transcription-intelligence-v305'/,'Agent Brain provenance must advance with the v305 intelligence runtime');
assert.match(baseline,/tests\/transcription-intelligence-relations\.mjs/,'v305 relationship contract must run in Recovery Baseline');

console.log('VP3 transcription intelligence relationships contract: PASS');
