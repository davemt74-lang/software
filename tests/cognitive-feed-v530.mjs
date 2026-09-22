import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const feed=read('includes/cognitive-feed-v530.php');
const api=read('api/cognitive-feed-v530.php');
const cards=read('includes/cognitive-cards-v520.php');
const cardJs=read('chat-cognitive-cards-v520.js');
const js=read('chat-cognitive-feed-v530.js');
const css=read('chat-cognitive-feed-v530.css');
const chat=read('chat.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const runtime=read('includes/cognitive-runtime-v500.php');
const presentation=read('includes/cognitive-presentation-v510.php');

assert.match(feed,/VP3_COGNITIVE_FEED_V530='vp3-cognitive-feed-v530-20260918'/);
assert.match(feed,/VP3_COGNITIVE_FEED_MAX_ITEMS_V530=12/);
assert.match(feed,/CREATE TABLE IF NOT EXISTS cognitive_feed_item_state_v530/);
assert.match(feed,/PRIMARY KEY \(owner_user_id,agent_namespace,item_key\)/,'feed state must be user + Agent scoped');
assert.match(feed,/hidden_fingerprint CHAR\(64\)/,'hide state must be state-fingerprint based');
assert.doesNotMatch(feed,/title VARCHAR|body TEXT|summary TEXT|content_text|message TEXT/i,'feed state must not copy canonical content');

for(const fn of [
  'vp3_cognitive_feed_observation_candidates_v530',
  'vp3_cognitive_feed_meeting_candidates_v530',
  'vp3_cognitive_feed_calendar_candidates_v530',
  'vp3_cognitive_feed_workflow_candidates_v530',
  'vp3_cognitive_feed_goal_candidates_v530',
  'vp3_cognitive_feed_brain_candidates_v530',
  'vp3_cognitive_feed_notification_candidates_v530',
  'vp3_cognitive_feed_activation_candidates_v530',
  'vp3_cognitive_feed_merge_candidates_v530',
  'vp3_cognitive_feed_compose_v530'
]) assert.match(feed,new RegExp('function '+fn.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')),'missing feed function '+fn);

assert.match(feed,/vp3_cognitive_presentation_decide_v500\(/,'Cognitive observations must pass presentation arbitration');
assert.match(feed,/vp3_cognitive_authorize_ref_v500\(/,'every feed card ref must be authorized before composition');
assert.match(feed,/vp3_cognitive_validate_card_request_v500\(/,'feed may only use registered card requests');
assert.match(feed,/vp3_cognitive_feed_card_group_v530/,'feed must correlate by canonical card object');
assert.match(feed,/if\(!isset\(\$groups\[\$group\]\)\)/,'feed must collapse duplicate canonical groups');
assert.match(feed,/video_meeting_for_calendar_event_v1800/,'calendar event with Meeting must dedupe to Meeting');
assert.match(feed,/video_meeting_for_booking_v1800/,'booking with Meeting must dedupe to Meeting');
assert.match(feed,/time_bucket/,'scheduled items must resurface as time relevance changes');
assert.match(feed,/\$caps=\['attention'=>4,'next_up'=>2,'setup'=>2,'priorities'=>3,'opportunities'=>2,'recent'=>1\]/,'feed must enforce bounded attention and setup budgets');
assert.match(feed,/'setup'=>\['label'=>'Getting started'/,'feed must expose a dedicated setup lane');
assert.match(feed,/if\(\$section!==\'setup\'\)foreach\(\$items as \$selected\)\$selectedForQueue\[\]=\$selected;/,'setup guidance must not enter the operational priority queue');
assert.match(feed,/unset\(\$item\['score'\]\)/,'internal ranking score must not be exposed to client');
assert.match(feed,/DATE_SUB\(UTC_TIMESTAMP\(\),INTERVAL 7 DAY\)/,'recent activity must be time bounded');
assert.match(feed,/status NOT IN \('completed','cancelled'\)|agent_workflow_active_summary_v1400/,'workflow candidates must use active state');
assert.match(feed,/video_meeting_upcoming_for_user_v1800/);
assert.match(feed,/user_calendar_events_v1300/);
assert.match(feed,/agent_goal_list_v1710/);
assert.match(feed,/agent_cognitive_loop_v310_priority_items/);

assert.doesNotMatch(feed,/chat_remote_answer|ai_generate|openai|anthropic|gemini|curl_exec/i,'feed composition must be deterministic/model-free');
assert.doesNotMatch(feed,/INSERT INTO chat_messages|UPDATE chat_messages|DELETE FROM chat_messages/i,'feed must never create or mutate Chat turns');
assert.doesNotMatch(feed,/agent_workflow_approve|agent_workflow_retry|agent_workflow_cancel|profile_commerce_refund|user_calendar_create/i,'feed composition must not execute domain actions');

assert.match(cards,/'brain_priority','feed_activity','onboarding_setup'/,'Universal Cards must register feed and onboarding projection cards');
assert.match(cards,/if\(\$type==='brain_priority'\)/);
assert.match(cards,/if\(\$type==='feed_activity'\)/);
assert.match(cards,/if\(\$type==='onboarding_setup'\)/,'setup cards must reauthorize against current onboarding state');
assert.match(cards,/vp3_cognitive_cards_notification_v520\(\$pdo,\$user,\(int\)\$id\)/,'activity card must resolve owner-scoped notification');
assert.match(cards,/agent_cognitive_loop_v310_priority_items\(\$user,10\)/,'Brain priority card must re-resolve current user Brain state');
assert.match(cardJs,/brain_priority:'P'/);
assert.match(cardJs,/feed_activity:'•'/);
assert.match(cardJs,/onboarding_setup:'→'/);

assert.match(api,/vp3_cognitive_agent_namespace_v500\(/,'API must bind exact Agent namespace');
assert.match(api,/vp3_cognitive_feed_compose_v530\(/);
assert.match(api,/hash_equals\(\(string\)\$candidate\['fingerprint'\],\$fingerprint\)/,'hide must reject stale item state');
assert.match(api,/vp3_cognitive_feed_hide_v530/);
assert.match(api,/vp3_cognitive_feed_restore_v530/);
assert.doesNotMatch(api,/notification.*is_read.*=|agent_workflow_approve|UPDATE cognitive_observations_v500/i,'feed hide/restore must not mutate canonical domain state');

assert.doesNotThrow(()=>new Function(js),'Cognitive Feed canvas must be valid JavaScript');
assert.doesNotMatch(js,/\.innerHTML\s*=|insertAdjacentHTML|document\.write/,'feed renderer must use DOM APIs, not injected HTML');
assert.match(js,/document\.getElementById\('chatWelcome'\)/,'feed must mount only in welcome/new-chat canvas');
assert.doesNotMatch(js,/thread\.appendChild|thread\.insertBefore|chat_messages|messageElement\(/,'feed shell must never append itself into the active Chat thread');
assert.match(js,/welcome\.insertBefore\(root,starters\)/);
assert.match(js,/VP3_COGNITIVE_CARDS_V520_RUNTIME/,'feed must reuse Universal Card renderer');
assert.match(js,/renderRequests\(\[item\.card_request\]/);
assert.match(js,/Hide this feed item until it changes/);
assert.match(js,/restore_all/);
assert.match(js,/!document\.hidden && !welcome\.hidden/,'feed polling must stop when active conversation hides welcome canvas');
assert.match(js,/MutationObserver/);
assert.match(js,/pagehide/);
assert.doesNotMatch(js,/requestSubmit\(|vp3:cognitive-card-tool-request|agent_workflow_approve/i,'feed shell itself must not execute actions or submit Chat prompts');

assert.match(css,/vp3-cognitive-feed-v530/);
assert.match(css,/grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/);
assert.match(css,/@media\(max-width:820px\)/);
assert.match(css,/@media\(max-width:520px\)/);
assert.match(css,/prefers-reduced-motion:reduce/);

assert.match(chat,/\$cognitiveFeedBuild = 'cognitive-feed-v530-20260918'/);
assert.match(chat,/\$cognitiveFeedAssetBuild = \$cognitiveFeedBuild \. '-activation-v243-orchestration-v560-priority-v2310-proactive-v2340-calibration-v2350'/,'activation must cache-bust feed assets without changing the stable v5.30 build id');
assert.match(chat,/api\/cognitive-feed-v530\.php/);
assert.match(chat,/chat-cognitive-feed-v530\.css/);
assert.match(chat,/chat-cognitive-feed-v530\.js/);
assert.match(chat,/\$cognitiveCardsRuntime[\s\S]*\$cognitiveFeedRuntime[\s\S]*\$cognitivePresentationPost/,'Universal Cards must load before feed, and presentation polling after it');
assert.match(chat,/data-cognitive-feed-build/);

assert.match(bootstrap,/cognitive-cards-v520\.php[\s\S]*cognitive-feed-v530\.php[\s\S]*cognitive-presentation-v510\.php/);
assert.match(upgrade,/vp3_cognitive_feed_schema_ready_v530\(\)/);
assert.match(upgrade,/vp3_cognitive_feed_ensure_schema_v530\(\$pdo\)/);
assert.match(upgrade,/Cognitive Feed Composition v5\.30/);

assert.match(runtime,/Background events|Cognitive Runtime|vp3_cognitive_presentation_decide_v500/);
assert.match(presentation,/legacy_chat_surface_disabled|vp3_cognitive_presentation_state_v510/);

console.log('VP3 Phase 11B.4 Cognitive Feed Composition v5.30: PASS');
