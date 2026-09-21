import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const queue=read('includes/cognitive-priority-queue-v2310.php');
const operations=read('includes/cognitive-operations-v2300.php');
const feed=read('includes/cognitive-feed-v530.php');
const work=read('includes/agent-chat-intelligence-v171.php');
const chatJs=read('chat-cognitive-feed-v530.js');
const chatCss=read('chat-cognitive-feed-v530.css');
const chat=read('chat.php');
const browserApi=read('api/extension-cognitive-now-v2120.php');
const bootstrap=read('includes/bootstrap.php');
const spec=read('docs/VP3_COGNITIVE_PRIORITY_QUEUE_V2310.md');

const checks=[
  ['projection only', /mode'=>'selected_feed_projection'/.test(queue)],
  ['no persistence', !/\b(?:INSERT|UPDATE|DELETE|CREATE TABLE|ALTER TABLE)\b/i.test(queue)],
  ['bounded to 12', /VP3_COGNITIVE_PRIORITY_QUEUE_MAX_ITEMS_V2310=12/.test(queue)],
  ['six unified lanes', /needs_attention.*next_up.*priorities.*opportunities.*waiting.*recent_changes/.test(queue)],
  ['workflow lane mapping', /'approval','blocked','failed_retry'=>'needs_attention'/.test(queue) && /'paused'=>'waiting'/.test(queue)],
  ['canonical work queue reused', /agent-chat-intelligence-v171\.php/.test(queue) && /vp3_agent_work_queue_model_v172/.test(feed)],
  ['phase19 authority preserved', /phase_19_existing_runtime/.test(queue)],
  ['no automatic external writes', /automatic_external_writes'=>false/.test(queue)],
  ['no approval bypass', /approval_bypass'=>false/.test(queue)],
  ['scores stay private', /unset\(\$item\['_feed_score'\],\$item\['_work_priority'\]/.test(queue)],
  ['universal cards title projection', /vp3_cognitive_render_card_v500/.test(queue)],
  ['feed emits queue', /vp3_cognitive_priority_queue_compose_v2310/.test(feed) && /'priority_queue'=>\$priorityQueue/.test(feed)],
  ['workflow provider covers paused/blocked/retry', /work_queue_lane/.test(feed) && /failed_retry/.test(feed) && /paused/.test(feed) && /blocked/.test(feed)],
  ['chat renders one queue projection', /vp3-cognitive-priority-queue-v2310/.test(chatJs)],
  ['queue rows focus existing cards', /scrollIntoView/.test(chatJs) && /data-feed-item-key/.test(chatJs)],
  ['chat queue styling present', /vp3-cognitive-priority-queue-v2310/.test(chatCss)],
  ['asset cache bumped', /priority-v2310/.test(chat)],
  ['browser api carries same queue', /'priority_queue'=>is_array\(\$feed\['priority_queue'\]/.test(browserApi)],
  ['bootstrap order', bootstrap.indexOf('cognitive-operations-v2300.php')<bootstrap.indexOf('cognitive-priority-queue-v2310.php') && bootstrap.indexOf('cognitive-priority-queue-v2310.php')<bootstrap.indexOf('cognitive-feed-v530.php')],
  ['v23.00 remains intact', /VP3_COGNITIVE_OPERATIONS_V2300/.test(operations)],
  ['no duplicate feed invariant', /does not create a second item surface/.test(spec)],
  ['work controls remain canonical', /Agent Work Control v17\.3/.test(spec) && /Phase 19 remains/.test(spec)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Priority Queue v23.10 contract: ${checks.length}/${checks.length} passed`);
