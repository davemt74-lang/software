import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const promotion=read('includes/cognitive-memory-promotion-v2400.php');
const memory=read('includes/cognitive-memory-v570.php');
const brain=read('includes/agent-brain-v122.php');
const loop=read('includes/agent-cognitive-loop-v310.php');
const release=read('includes/cognitive-release-v2400.php');
const manifest=read('includes/cognitive-domain-manifest-v2370.php');
const upgrade=read('upgrade.php');
const setup=read('setup.php');
const docs=read('docs/VP3_COGNITIVE_MEMORY_PROMOTION_V2400.md');

const checks=[
 ['v24.00 loads after episodic Cognitive Memory',bootstrap.indexOf("cognitive-memory-v570.php")<bootstrap.indexOf("cognitive-memory-promotion-v2400.php")],
 ['promotion engine does not create another event ledger',!/CREATE TABLE IF NOT EXISTS agent_event/i.test(promotion)],
 ['promotion engine creates only promotion receipt storage',/cognitive_memory_promotion_receipts_v2400/.test(promotion)&&!/cognitive_memory_threads_v2400|cognitive_memory_occurrences_v2400/.test(promotion)],
 ['existing v5.70 remains episodic reference-first memory',/reference-first/.test(memory)&&/cognitive_memory_occurrences_v570/.test(memory)],
 ['existing agent_memory_items remains durable Brain store',/INSERT INTO agent_memory_items/.test(brain)&&/agent_memory_items/.test(promotion)],
 ['scanner requires record-only flag',/payload_json LIKE '%\\\"record_only\\\":true%'/.test(promotion)],
 ['scanner requires deferred-promotion flag',/payload_json LIKE '%\\\"brain_promotion_deferred\\\":true%'/.test(promotion)],
 ['routine events are not durable by default',/class'=>'transient'/.test(promotion)&&/routine_state/.test(promotion)],
 ['durable terminal policy includes paid orders and conversions',/order\.paid/.test(promotion)&&/conversion\.attributed/.test(promotion)&&/profile\.booking_converted/.test(promotion)],
 ['recurring patterns require threshold',/VP3_COGNITIVE_MEMORY_PROMOTION_RECUR_THRESHOLD_V2400=3/.test(promotion)&&/recurrence_below_threshold/.test(promotion)],
 ['recurrence window is 30 days',/VP3_COGNITIVE_MEMORY_PROMOTION_RECUR_DAYS_V2400=30/.test(promotion)],
 ['cross-object booking pattern is supported',/booking\.cancelled','booking\.rescheduled','refund\.requested/.test(promotion)&&/\$crossObject/.test(promotion)],
 ['episodes use existing v5.70 occurrence API',/vp3_cognitive_memory_occurrence_v570/.test(promotion)&&/vp3_cognitive_memory_thread_v570/.test(promotion)],
 ['promotion reauthorizes cognitive object reference',/vp3_cognitive_authorize_ref_v500/.test(promotion)&&/vp3_cognitive_validate_object_ref_v500/.test(promotion)],
 ['raw event payload is explicitly not copied into Brain metadata',/'raw_event_payload_copied'=>false/.test(promotion)],
 ['durable memory scope is forced to system',/user_agent_id IS NULL/.test(promotion)&&/vp3_agent_memory_scope_hash_v410\(0,/.test(promotion)],
 ['Brain subjects hash concrete object identity',/substr\(hash\('sha256',\(string\)\$ref\['id'\]\),0,24\)/.test(promotion)],
 ['promotion receipt is idempotent per source event',/UNIQUE KEY uq_cognitive_promotion_event_v2400/.test(promotion)&&/INSERT IGNORE INTO cognitive_memory_promotion_receipts_v2400/.test(promotion)],
 ['canonical cognitive loop invokes promotion scan',/vp3_cognitive_memory_promotion_scan_v2400/.test(loop)&&/'memory_promotion'=>\$memoryPromotion/.test(loop)],
 ['normal upgrade requires and installs v24.00 schema',/vp3_cognitive_memory_promotion_schema_ready_v2400/.test(upgrade)&&/vp3_cognitive_memory_promotion_ensure_schema_v2400/.test(upgrade)],
 ['fresh setup installs episodic and promotion schemas',/vp3_cognitive_memory_ensure_schema_v570/.test(setup)&&/vp3_cognitive_memory_promotion_ensure_schema_v2400/.test(setup)],
 ['manifest records promotion receipts as memory authority',/cognitive_memory_promotion_receipts_v2400/.test(manifest)&&/integrated-v24\.00/.test(manifest)],
 ['release gate preserves no-second-Brain invariant',/'second_brain'=>false/.test(release)&&/'second_event_ledger'=>false/.test(release)],
 ['release gate forbids routine auto-promotion',/'routine_events_auto_promoted'=>false/.test(release)],
 ['docs distinguish episodic from durable memory',/episodic layer/.test(docs)&&/durable semantic Brain store/.test(docs)],
 ['docs preserve deferred-event boundary',/record_only/.test(docs)&&/brain_promotion_deferred/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Memory Promotion v24.00 gate: ${checks.length}/${checks.length} passed`);
