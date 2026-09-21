import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(new URL('../'+p, import.meta.url),'utf8');
const operations = read('includes/cognitive-operations-v2300.php');
const feed = read('includes/cognitive-feed-v530.php');
const bootstrap = read('includes/bootstrap.php');
const chatJs = read('chat-cognitive-feed-v530.js');
const spec = read('docs/VP3_COGNITIVE_OPERATIONS_V2300.md');

const checks = [
  ['projection-only core', /mode'=>'projection_only'/.test(operations)],
  ['no v23 persistence', !/\b(?:INSERT|UPDATE|DELETE|CREATE TABLE|ALTER TABLE)\b/i.test(operations)],
  ['no independent ranking', /independent_v2300_ranking'=>false/.test(operations)],
  ['ranking score stays server-private', /unset\(\$item\['_rank_score'\]\)/.test(operations) && !/'priority_score'=>/.test(operations)],
  ['existing execution authority', /existing_execution_runtimes/.test(operations)],
  ['model cannot execute', /model_may_execute'=>false/.test(operations)],
  ['no automatic external writes', /automatic_external_writes'=>false/.test(operations)],
  ['feed exposes operations', /vp3_cognitive_operations_compose_v2300/.test(feed)],
  ['bootstrap loads operations before feed', bootstrap.indexOf("cognitive-operations-v2300.php") > -1 && bootstrap.indexOf("cognitive-operations-v2300.php") < bootstrap.indexOf("cognitive-feed-v530.php")],
  ['chat renders operations strip', /vp3-cognitive-operations-strip/.test(chatJs)],
  ['all systems listening contract', /All Systems Listening/.test(spec) && /existing Agent Event Infrastructure/.test(spec)],
  ['primary Chat canvas preserved', /Agent Chat remains the primary human operating surface/.test(spec)],
  ['no second brain invariant', /no duplicate Brain/.test(spec)],
  ['no duplicate queue invariant', /no duplicate scheduler\/worker\/queue/.test(spec)],
  ['existing approval invariant', /no bypass of tool\/workflow\/browser\/transaction approvals/.test(spec)],
];

for (const [name, ok] of checks) {
  assert.equal(ok, true, name);
  console.log('PASS', name);
}
console.log(`Cognitive Operations v23.00 contract: ${checks.length}/${checks.length} passed`);
