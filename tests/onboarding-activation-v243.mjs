import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const intelligence=read('includes/onboarding-intelligence.php');
const onboarding=read('includes/chat-onboarding-v241.php');
const cards=read('includes/cognitive-cards-v520.php');
const cardJs=read('chat-cognitive-cards-v520.js');
const feed=read('includes/cognitive-feed-v530.php');
const feedApi=read('api/cognitive-feed-v530.php');
const feedJs=read('chat-cognitive-feed-v530.js');
const chat=read('chat.php');

assert.match(intelligence,/onboarding-intelligence-20260921-v4/);
assert.match(intelligence,/function onboarding_intelligence_activation_interest/);
assert.match(intelligence,/function onboarding_intelligence_activation_action/);
assert.match(intelligence,/activation\.defer\./);
assert.match(intelligence,/activation\.dismiss\./);
assert.match(intelligence,/\$publicFunnel=is_array\(\$mergedDraft\['public_funnel'\]/,'activation persistence must read the canonical nested public funnel draft');
assert.match(intelligence,/\$mergedInterests\['activation\.'\.\$attributionKey\]=\$value/,'origin/source attribution must survive onboarding draft cleanup');
assert.match(intelligence,/\$days=max\(1,min\(30,\$deferDays\)\)/,'defer window must be bounded');
assert.match(intelligence,/elseif\(\$action==='dismiss'\)[\s\S]*\$patch\[\$interest\]=false/,'not interested must turn off the workflow interest');
assert.match(intelligence,/elseif\(\$action==='restore'\)[\s\S]*\$patch\[\$interest\]=true/,'restore must reselect the workflow');
assert.doesNotMatch(intelligence,/ADD COLUMN .*activation/i,'activation must reuse existing onboarding preference storage');

assert.match(onboarding,/function chat_onboarding_v241_activation_state/);
assert.match(onboarding,/\$configured=!empty\(\$item\['configured'\]\)/,'activation completion must derive from authoritative workspace state');
assert.match(onboarding,/elseif\(\$deferred\)\$status='deferred'/);
assert.match(onboarding,/else\$status='pending'/);
assert.match(onboarding,/next_action/);
assert.match(onboarding,/activation_percent/);
assert.match(onboarding,/usage_milestones/);
assert.match(onboarding,/attribution/);
assert.match(onboarding,/\$interests\['activation\.origin'\]/,'activation attribution must read the durable origin');
assert.match(onboarding,/\$interests\['activation\.source'\]/,'activation attribution must read the durable current source');
assert.match(onboarding,/\$publicFunnel=is_array\(\$draft\['public_funnel'\]/,'activation state must retain compatibility with the canonical nested funnel draft');
for(const signal of ['First Browser Companion connected','First VP3 meeting created','First calendar event added','First booking received','First product published','First Team relationship created','HomeServer paired']) assert.ok(onboarding.includes(signal),`missing activation milestone ${signal}`);
assert.match(onboarding,/Your next selected VP3 setup step is/,'Agent Chat must answer next setup from activation state');
assert.match(onboarding,/Open VP3 Setup/);

assert.match(cards,/'onboarding_setup'/,'Universal Cards must register setup card type');
assert.match(cards,/\(\$row\['activation_status'\]\?\?'\'\)!=='pending'/,'setup card authorization must fail closed when current state is no longer pending');
assert.match(cards,/\$state=chat_onboarding_v241_state\(\$pdo,\$user\)/,'setup card must re-resolve canonical onboarding state');
assert.match(cards,/\$out\['subtitle'\]='Getting started'/);
assert.match(cards,/Optional setup/);
assert.match(cards,/parse_url\(\$target\)/,'setup links must be normalized before reaching the internal-only card renderer');
assert.match(cards,/str_starts_with\(\$target,'\/'\)/,'setup card actions must remain internal routes');
assert.match(cardJs,/onboarding_setup:'→'/);

assert.match(feed,/VP3_COGNITIVE_FEED_V530='vp3-cognitive-feed-v531-20260921'/);
assert.match(feed,/function vp3_cognitive_feed_activation_candidates_v530/);
assert.match(feed,/'activation:'\.\(string\)\$key,'setup'/);
assert.match(feed,/vp3_cognitive_feed_request_v530\('onboarding_setup'/);
assert.match(feed,/'setup'=>\['label'=>'Getting started'/);
assert.match(feed,/\$caps=\['attention'=>4,'next_up'=>2,'setup'=>2/,'setup lane must be bounded');
assert.match(feed,/if\(\$section!=='setup'\)foreach\(\$items as \$selected\)\$selectedForQueue\[\]=\$selected/,'setup cards must not become operational priority queue work');

for(const action of ['activation_defer','activation_dismiss','activation_restore']) assert.ok(feedApi.includes(action),`feed API missing ${action}`);
assert.match(feedApi,/hash_equals\(csrf_token\(\)/,'activation mutations must retain CSRF protection');
assert.match(feedApi,/activation_item_changed/,'stale setup state must be rejected');
assert.match(feedApi,/onboarding_intelligence_activation_action/);

assert.doesNotThrow(()=>new Function(cardJs),'Universal Card JS must parse');
assert.doesNotThrow(()=>new Function(feedJs),'Cognitive Feed JS must parse');
assert.match(feedJs,/Do later/);
assert.match(feedJs,/Not interested/);
assert.match(feedJs,/activation_defer/);
assert.match(feedJs,/activation_dismiss/);
assert.match(feedJs,/defer_days:3/);
assert.match(feedJs,/if\(cardType!=='onboarding_setup'\)actions\.append\(why,hide\)/,'setup cards must use defer/dismiss rather than generic hide');
assert.doesNotMatch(feedJs,/requestSubmit\([\s\S]{0,600}activation_defer/,'activation preference actions must not submit Chat prompts');

assert.match(chat,/\$cognitiveCardsBuild = 'cognitive-cards-v521-activation-20260921'/);
assert.match(chat,/\$cognitiveFeedBuild = 'cognitive-feed-v531-activation-20260921'/);

console.log('Onboarding Activation + Agent Follow-Through v2.43 contract: PASS');
