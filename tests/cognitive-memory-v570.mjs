import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const memory=read('includes/cognitive-memory-v570.php');
const feed=read('includes/cognitive-feed-v530.php');
const cardsPhp=read('includes/cognitive-cards-v520.php');
const cardsJs=read('chat-cognitive-cards-v520.js');
const chat=read('chat.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');

assert.match(memory,/VP3_COGNITIVE_MEMORY_V570='vp3-cognitive-memory-v570-20260919'/);
for(const table of ['cognitive_memory_threads_v570','cognitive_memory_occurrences_v570'])
  assert.match(memory,new RegExp('CREATE TABLE IF NOT EXISTS '+table));

const schemaBlocks=(memory.match(/CREATE TABLE IF NOT EXISTS cognitive_memory_(?:threads|occurrences)_v570[\s\S]*?ENGINE=InnoDB/g)||[]).join('\n');
assert.ok(schemaBlocks.length>0,'cognitive memory schema missing');
assert.doesNotMatch(schemaBlocks,/title_text|message_text|transcript|summary_text|reason_text|content_text|body_text|notes_text|decision_text/i,'memory persistence must remain reference-only');
assert.match(schemaBlocks,/signature_hash CHAR\(64\)/);
assert.match(schemaBlocks,/object_type VARCHAR\(80\)/);
assert.match(schemaBlocks,/object_id VARCHAR\(190\)/);
assert.match(schemaBlocks,/object_scope VARCHAR\(40\)/);
assert.match(schemaBlocks,/item_fingerprint CHAR\(64\)/);
assert.match(schemaBlocks,/evidence_ref_type VARCHAR\(80\)/);
assert.match(schemaBlocks,/evidence_ref_id VARCHAR\(190\)/);

for(const fn of [
  'vp3_cognitive_memory_observe_candidates_v570',
  'vp3_cognitive_memory_sync_outcomes_v570',
  'vp3_cognitive_memory_sync_orchestration_v570',
  'vp3_cognitive_memory_visible_stats_v570',
  'vp3_cognitive_memory_context_v570',
  'vp3_cognitive_memory_card_v570',
  'vp3_cognitive_memory_feed_candidates_v570',
  'vp3_cognitive_memory_permission_v570'
]) assert.ok(memory.includes('function '+fn),'missing '+fn);

assert.match(memory,/vp3_cognitive_memory_thread_key_v570\('object_continuity',''/,'exact-object continuity must not fragment by source module');
assert.match(memory,/\$source==='cognitive_observation'\?vp3_cognitive_memory_signature_v570/,'cross-object patterns must be grounded in cognitive observation evidence');
assert.match(memory,/\$knownReopened>0/,'known 11B.5 reopenings must seed continuity');
assert.match(memory,/LEFT JOIN cognitive_item_lifecycle_v540/,'outcomes must recover canonical object scope when available');
assert.match(memory,/resolved_scope/);

assert.match(memory,/vp3_cognitive_memory_authorized_occurrences_v570/);
assert.match(memory,/vp3_cognitive_authorize_ref_v500\(\$pdo,\$user,\$namespace,\$ref,'read'\)/);
assert.match(memory,/return \(int\)\$stats\['occurrence_count'\]>0/,'memory thread access must require a currently authorized source occurrence');
assert.match(memory,/\$stats=vp3_cognitive_memory_visible_stats_v570\(\$pdo,\$user,\$namespace,\$thread\)/);
assert.match(memory,/\$stats\['status'\]/,'display status must derive from currently authorized occurrences');
assert.match(memory,/\$stats\['last_seen_at'\]/,'display recency must derive from currently authorized occurrences');

assert.match(memory,/vp3_cognitive_context_for_ref_v500\(\$pdo,\$user,\$namespace,\$ref,\['memory_continuity'=>true\]\)/,'historical sources must be re-resolved at read time');
assert.match(memory,/vp3_cognitive_memory_compact_context_v570/,'re-resolved context must remain bounded');
assert.match(memory,/'storage_boundary'=>'reference_only'/);
assert.match(memory,/'authority'=>'memory_context_only'/);

assert.match(memory,/FROM cognitive_feedback_events_v540/,'behavioral continuity must derive from 11B.5 feedback rather than copy it');
assert.match(memory,/'Actions taken'/);
assert.match(memory,/FROM cognitive_outcomes_v540/,'outcome continuity must consume 11B.5 outcomes');
assert.match(memory,/cognitive_plan_step_events_v560/,'orchestration continuity must consume 11B.7 step events');
assert.doesNotMatch(memory,/execute_tool|tool_execute|run_tool|agent_tool_execute|agent_workflow_execute/i,'memory must never execute actions');

assert.match(feed,/vp3_cognitive_memory_sync_v570\(\$pdo,\$user,\$namespace,\$authorized\)/);
assert.match(feed,/vp3_cognitive_memory_feed_candidates_v570/);
assert.match(cardsPhp,/'memory_thread'=>.*what keeps coming up/s);
assert.match(cardsPhp,/cognitive_memory_threads_v570/);
assert.match(cardsPhp,/vp3_cognitive_authorize_ref_v500\(\$pdo,\$user,\$namespace,\$memoryRef,'read'\)/,'direct memory retrieval must permission-filter candidate threads');
assert.match(cardsPhp,/vp3_cognitive_memory_sync_v570\(\$pdo,\$user,\$namespace,\[\]\)/);
assert.match(cardsJs,/memory_thread:'⌁'/);

assert.match(bootstrap,/cognitive-orchestration-v560\.php[\s\S]*cognitive-memory-v570\.php[\s\S]*cognitive-feed-v530\.php/);
assert.match(chat,/\$cognitiveMemoryBuild = 'cognitive-memory-v570-20260919'/);
assert.match(chat,/data-cognitive-memory-build/);
assert.match(chat,/\$cognitiveCardsAssetBuild = \$cognitiveCardsBuild \. '-memory-v570'/);
assert.match(upgrade,/vp3_cognitive_memory_schema_ready_v570/);
assert.match(upgrade,/vp3_cognitive_memory_ensure_schema_v570/);
assert.match(upgrade,/Cognitive Memory & Cross-Time Continuity v5\.70/);

console.log('VP3 Cognitive Memory & Cross-Time Continuity v5.70 contract passed.');
