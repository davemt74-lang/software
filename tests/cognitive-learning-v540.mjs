import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const learning=read('includes/cognitive-learning-v540.php');
const api=read('api/cognitive-learning-v540.php');
const feed=read('includes/cognitive-feed-v530.php');
const feedApi=read('api/cognitive-feed-v530.php');
const feedJs=read('chat-cognitive-feed-v530.js');
const cardsJs=read('chat-cognitive-cards-v520.js');
const chat=read('chat.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');

assert.match(learning,/VP3_COGNITIVE_LEARNING_V540='vp3-cognitive-learning-v540-20260918'/);
for(const table of ['cognitive_feedback_events_v540','cognitive_outcomes_v540','cognitive_learning_profiles_v540','cognitive_item_lifecycle_v540'])
  assert.match(learning,new RegExp('CREATE TABLE IF NOT EXISTS '+table));

assert.match(learning,/presentation_hides/);
assert.match(learning,/relevance_factor DECIMAL\(6,4\)/);
assert.match(learning,/max\(\.90,min\(1\.10,\$factor\)\)/);
assert.match(learning,/max\(-8\.0,min\(8\.0,\$adjust\)\)/);
assert.match(learning,/if\(!in_array\(\$section,\['priorities','opportunities','recent'\],true\)\)return \$candidate/);
assert.doesNotMatch(learning,/title VARCHAR|message TEXT|transcript|content_text|body LONGTEXT/i);
assert.match(learning,/Learning can adjust relevance only; permissions, approvals, tool execution, and canonical object state remain authoritative elsewhere/);

for(const fn of ['vp3_cognitive_learning_observe_candidates_v540','vp3_cognitive_learning_adjust_candidate_v540','vp3_cognitive_learning_feedback_v540','vp3_cognitive_learning_reconcile_v540','vp3_cognitive_learning_record_outcome_v540','vp3_cognitive_learning_explain_v540'])
  assert.ok(learning.includes('function '+fn),'missing '+fn);

assert.match(api,/has_permission\('chat\.access',\$user\)/);
assert.match(api,/hash_equals\(csrf_token\(\)/);
assert.match(api,/vp3_cognitive_feed_find_candidate_v530/);
assert.match(api,/hash_equals\(\(string\)\$candidate\['fingerprint'\],\$fingerprint\)/);
assert.match(api,/action==='explain'/);
assert.match(api,/action==='feedback'/);

assert.match(feed,/vp3_cognitive_learning_observe_candidates_v540/);
assert.match(feed,/vp3_cognitive_learning_adjust_candidate_v540/);
assert.match(feed,/unset\(\$item\['score'\]\)/);
assert.match(feed,/unset\(\$item\['learning_adjustment'\]\)/);
assert.match(feedApi,/vp3_cognitive_learning_feedback_v540\(\$pdo,\$user,\$namespace,\$candidate,'hidden'/);
assert.match(learning,/'hidden'=>'presentation_hides'/);

assert.match(cardsJs,/vp3:cognitive-card-action/);
assert.match(feedJs,/learningEndpoint/);
assert.match(feedJs,/event_type:'shown'/);
assert.match(feedJs,/event_type:accepted\?'acted':'engaged'/);
assert.match(feedJs,/Why\?/);
assert.match(feedJs,/action:'explain'/);

assert.match(bootstrap,/cognitive-learning-v540\.php/);
assert.match(chat,/cognitive-learning-v540-20260918/);
assert.match(chat,/learningEndpoint.*cognitive-learning-v540\.php/s);
assert.match(upgrade,/vp3_cognitive_learning_schema_ready_v540/);
assert.match(upgrade,/vp3_cognitive_learning_ensure_schema_v540/);

console.log('VP3 Cognitive Outcomes & Learning v5.40 contract passed.');
