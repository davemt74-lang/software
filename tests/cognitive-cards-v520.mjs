import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const contract=JSON.parse(read('contracts/cognitive/cognitive-runtime-v1/contract.json'));
const core=read('includes/cognitive-runtime-v500.php');
const meeting=read('includes/cognitive-runtime-meetings-v500.php');
const cards=read('includes/cognitive-cards-v520.php');
const api=read('api/cognitive-cards-v520.php');
const renderer=read('chat-cognitive-cards-v520.js');
const css=read('chat-cognitive-cards-v520.css');
const chat=read('chat.js');
const chatPage=read('chat.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const presentationJs=read('chat-cognitive-presentation-v510.js');
const bootstrap=read('includes/bootstrap.php');
const chatApi=[read('api/chat-v236.php'),read('includes/agent-chat-runtime-v2160.php')].join('\n');
const fallbackChatApi=read('api/chat.php');

assert.match(cards,/VP3_COGNITIVE_CARDS_V520='vp3-cognitive-cards-v520-20260918'/);
assert.match(cards,/module'=>'universal_cards'/);
assert.match(cards,/version'=>'universal-display-cards-v520'/);
assert.doesNotMatch(cards,/CREATE TABLE|ALTER TABLE|DROP TABLE/i,'Universal Cards must not create parallel storage');

const meetingTypes=['meeting','meeting_brief','meeting_summary','meeting_followup'];
const universalTypes=['transcription','recording','annotation','source','research','claim','team_activity','human_message','profile_agent_update','contact','commerce_order','commerce_customer','product','calendar_booking','workflow','goal','commitment','opportunity','risk','decision','knowledge','live_room','homeserver','browser_companion'];
for(const type of universalTypes) assert.ok(cards.includes("'"+type+"'"),'missing universal card type '+type);
for(const type of meetingTypes) assert.ok(meeting.includes("'"+type+"'"),'missing retained Meeting card type '+type);
assert.deepEqual([...universalTypes,...meetingTypes].sort(),[...contract.cards.initial_types].sort(),'11B.3 + retained Meeting adapter must cover complete initial card family');

for(const auth of ['artist_listening_v172_session','vp3_browser_share_resolve_v2020','vp3_browser_trust_source_access_v2080','vp3_research_project_require_v2060','vp3_browser_trust_claim_access_v2080','vp3_human_can_access_v370','profile_commerce_order_for_owner_v900','profile_commerce_owner_product_v900','user_calendar_event_v1300','agent_workflow_row_v1400','agent_goal_state_v1710','homeserver_vp3_connection','vp3_extension_devices_for_user_v2000']) assert.ok(cards.includes(auth),'missing canonical authorization/read path '+auth);

assert.match(cards,/operation!=='read'/,'Universal Cards must reject non-read authorization');
assert.match(cards,/created_by_user_id=\? AND knowledge_scope='personal'/,'Knowledge cards must remain personal-owner scoped');
assert.match(cards,/owner_user_id=\? AND agent_namespace=\?/,'observation cards must remain owner + Agent scoped');
assert.match(cards,/vp3_live_room_require_v2070\(\$pdo,\$id,\$uid,false\)/,'Live Room cards must reauthorize access');
assert.match(cards,/vp3_cognitive_cards_notification_request_v520/);
assert.match(cards,/function vp3_cognitive_cards_chat_intent_v520/);
assert.match(cards,/function vp3_cognitive_cards_chat_requests_v520/);
assert.match(cards,/profile_visit_sessions s[\s\S]*s\.owner_user_id=\? AND s\.id=\?/,'contact cards must use exact owner-scoped lookup');
assert.match(cards,/contact_id/,'direct contact intents must use canonical contact_id projection');
assert.match(cards,/Source titles are intentionally not read from mutable global Source metadata/,'Source card title must avoid cross-viewer mutable metadata');
assert.match(cards,/\$out\['title'\]=\(string\)\(\$row\['source_domain'\]/,'Source card heading must use safe domain state');
assert.match(cards,/booking:/,'calendar card refs must distinguish booking ids from event ids');
assert.match(cards,/event:/,'calendar card refs must distinguish event ids from booking ids');
assert.match(cards,/vp3_cognitive_register_module_v500\(/);
assert.match(cards,/'permission_resolver'=>'vp3_cognitive_cards_permission_v520'/);
assert.match(cards,/'context_provider'=>'vp3_cognitive_cards_context_v520'/);
assert.match(cards,/'cards'=>\$cards/);
assert.doesNotMatch(cards,/openai|anthropic|gemini|chat_remote_answer|curl_exec/i,'Universal Cards must be model-free');

for(const key of ['badges','facts','sections','media']) assert.ok(core.includes("'"+key+"'=>[]"),'missing normalized card field '+key);
assert.match(core,/array_slice\(\(array\)\(\$card\['badges'\]/);
assert.match(core,/array_slice\(\(array\)\(\$card\['facts'\]/);
assert.match(core,/array_slice\(\(array\)\(\$card\['sections'\]/);
assert.match(core,/array_slice\(\(array\)\(\$card\['media'\]/);
assert.match(core,/!str_starts_with\(\$url,'\/'\)\|\|str_starts_with\(\$url,'\/\/'\)/,'card media URLs must be internal only');
assert.match(core,/Cognitive card access denied/);
assert.match(core,/Unregistered cognitive card type/);
assert.match(core,/isset\(\$registry\['tools'\]\[\$tool\]\)/,'tool actions must remain registry-bound');

assert.match(api,/VP3_COGNITIVE_CARDS_BATCH_MAX_V520/);
assert.match(api,/vp3_cognitive_render_card_v500\(/,'batch API must use canonical card reauthorization');
assert.match(api,/available'=>false,'error'=>'unavailable'/,'one unavailable object must fail closed independently');
assert.doesNotMatch(api,/innerHTML|htmlspecialchars/i,'batch API must return structured data, not card HTML');
assert.doesNotMatch(api,/observation_store|presentation_decide|agent_workflow_approve|agent_workflow_retry|agent_workflow_cancel/i,'card API must not inject cognition or mutate workflows');

assert.doesNotThrow(()=>new Function(renderer),'Universal Card renderer must be valid JavaScript');
assert.doesNotMatch(renderer,/\.innerHTML\s*=|insertAdjacentHTML|document\.write/,'shared renderer must not inject card content as HTML');
assert.match(renderer,/document\.createElement/);
assert.match(renderer,/\.textContent\s*=/);
assert.match(renderer,/raw\.startsWith\('\/'\) && !raw\.startsWith\('\/\/'\)/,'browser renderer must enforce internal links');
assert.match(renderer,/form\.requestSubmit\(\)/,'prompt actions must use canonical Chat composer');
assert.match(renderer,/vp3:cognitive-card-tool-request/);
assert.doesNotMatch(renderer,/agent_workflow_approve|agent_workflow_retry|agent_workflow_cancel/i,'browser cards must not execute privileged tools directly');
assert.match(renderer,/renderRequests/);
assert.match(renderer,/availableIndices/);
assert.match(renderer,/MutationObserver/);
assert.match(renderer,/pagehide/);

assert.match(css,/vp3-cognitive-card/);
assert.match(css,/data-display-mode="compact"/);
assert.match(css,/data-display-mode="expanded"/);
assert.match(css,/@media\(max-width:760px\)/);
assert.match(css,/prefers-reduced-motion:reduce/);

assert.match(chat,/cards:Array\.isArray\(raw\.cards\)/);
assert.match(chat,/raw\.cognitive_cards/);
assert.match(chat,/data-cognitive-card-host/);
assert.match(chat,/_vp3CognitiveCardRequests/);
assert.match(chat,/context\.cards \|\| \[\]/,'history must restore cards');
assert.match(chat,/data\.cards \|\| data\.cognitive_cards \|\| \[\]/,'new Agent responses must render cards');

assert.match(chatPage,/\$cognitiveCardsBuild = 'cognitive-cards-v520-20260918'/);
assert.match(chatPage,/chat-cognitive-cards-v520\.css/);
assert.match(chatPage,/chat-cognitive-cards-v520\.js/);
assert.match(chatPage,/api\/cognitive-cards-v520\.php/);
assert.match(chatApi,/vp3_cognitive_cards_chat_requests_v520/,'canonical Agent Chat must attach deterministic direct card requests');
assert.match(chatApi,/'cards'=>\$cardRequests/,'canonical Agent Chat must persist card requests in context_json');
assert.match(chatApi,/'cards'=>\$messageContext\['cards'\]/,'canonical Agent Chat response must return cards');
assert.match(fallbackChatApi,/vp3_cognitive_cards_chat_requests_v520/,'fallback Chat must attach the same card requests');
assert.match(fallbackChatApi,/'cards'=>\$cardRequests/,'fallback Chat must persist card requests');
assert.match(fallbackChatApi,/'cards'=>\$messageContext\['cards'\]/,'fallback Chat response must return cards');
assert.ok(chatPage.indexOf('$cognitiveCardsRuntime') < chatPage.indexOf('$cognitivePresentationPost'),'cards runtime must load before return-digest presentation runtime');

assert.match(presentation,/card_request'=>function_exists\('vp3_cognitive_cards_notification_request_v520'\)/,'new digest groups must retain card requests');
assert.match(presentation,/vp3_cognitive_cards_notification_request_v520\(\$row\)/);
assert.match(presentationJs,/data-has-card="1"/);
assert.match(presentationJs,/availableIndices/);
assert.match(presentationJs,/fallback\.hidden = true/,'fallback hides only after corresponding card succeeds');
assert.doesNotMatch(css,/has-rendered-cards .*data-has-card/s,'one card success must not hide all fallbacks');

assert.match(bootstrap,/cognitive-runtime-meetings-v500\.php[\s\S]*cognitive-cards-v520\.php[\s\S]*cognitive-presentation-v510\.php/);
assert.equal(contract.cards.llm_generates_html,false);
assert.equal(contract.cards.render_reauthorizes,true);
assert.equal(contract.cards.actions_server_derived,true);

console.log('VP3 Phase 11B.3 Universal Display Cards v5.20: PASS');
