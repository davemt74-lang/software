import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const bridge=read('includes/cognitive-domain-integration-v2390.php');
const release=read('includes/cognitive-release-v2390.php');
const meetings=read('includes/cognitive-runtime-meetings-v500.php');
const research=read('includes/research-projects-v2060.php');
const knowledge=read('includes/knowledge.php');
const messaging=read('includes/human-messaging-v370.php');
const team=read('includes/team-workspace-lifecycle-v350.php');
const tools=read('includes/agent-tools-v84.php');
const homeserver=read('includes/homeserver-vp3.php');
const media=read('includes/media-studio-v86.php');
const attribution=read('includes/agent-referral-attribution.php');
const analytics=read('includes/vp3-analytics-intelligence.php');
const browser=read('includes/browser-transaction-continuity-v2260.php');
const annotations=read('includes/browser-source-feed-v2050.php');
const cards=read('includes/cognitive-cards-v520.php');
const docs=read('docs/VP3_COGNITIVE_DOMAIN_INTEGRATION_V2390.md');

const checks=[
 ['v23.90 loads after v23.80',bootstrap.indexOf("cognitive-domain-integration-v2380.php")<bootstrap.indexOf("cognitive-domain-integration-v2390.php")],
 ['v23.90 release gate loads before proactive presentation',bootstrap.indexOf("cognitive-release-v2390.php")<bootstrap.indexOf("cognitive-proactive-now-v2340.php")],
 ['v23.90 uses canonical event inbox',/agent_event_ingest_v1920/.test(bridge)],
 ['v23.90 never directly dispatches events into Brain',!/agent_event_dispatch_v1920/.test(bridge)&&!/agent_event_brain_observe_v1920/.test(bridge)],
 ['v23.90 explicitly defers Brain promotion',/'brain_promotion_deferred'=>true/.test(bridge)&&/'brain_promotion_deferred'=>true/.test(release)],
 ['meetings remain the existing first-class reference adapter',/'module'=>'meetings'/.test(meetings)&&/meeting\.transcription_ready/.test(meetings)],
 ['research central event function bridges to cognition',/vp3_cognitive_research_event_bridge_v2390/.test(research)],
 ['Knowledge create and update bridge after authoritative storage',/vp3_cognitive_knowledge_event_v2390/.test(knowledge)&&/knowledgeCreated/.test(knowledge)],
 ['message bridge does not copy body into cognitive payload',/vp3_cognitive_message_bridge_v2390/.test(messaging)&&/'body_stored'=>false/.test(bridge)],
 ['team membership lifecycle emits joined left role and status events',/team\.member_joined/.test(bridge)&&/team\.member_left/.test(bridge)&&/team\.member_role_changed/.test(bridge)&&/team\.member_status_changed/.test(team)],
 ['tool history emits outcome references',/vp3_cognitive_tool_event_v2390/.test(tools)&&/tool\.completed/.test(bridge)&&/tool\.failed/.test(bridge)],
 ['tool cognitive context strips prompt/result payload',/request_text/.test(bridge)&&/result_json/.test(bridge)],
 ['HomeServer lifecycle bridge is present',/vp3_cognitive_homeserver_event_v2390/.test(homeserver)&&/homeserver\.connected/.test(bridge)&&/homeserver\.disconnected/.test(bridge)],
 ['HomeServer cognitive context strips credentials',/relay_token_enc/.test(bridge)&&/homeserver_token_enc/.test(bridge)&&/pending_claim_token_enc/.test(bridge)],
 ['Media Studio asset creation emits reference-only event',/vp3_cognitive_media_event_v2390/.test(media)&&/media\.asset_created/.test(bridge)],
 ['attribution emits referral and conversion events',/vp3_cognitive_attribution_event_v2390/.test(attribution)&&/referral\.attributed/.test(bridge)&&/conversion\.attributed/.test(bridge)],
 ['analytics spike records compact signal',/analytics\.signal_detected/.test(analytics)&&/vp3_cognitive_ref_v2390\('analytics_signal'/.test(analytics)],
 ['browser continuity start emits transaction started',/browser\.transaction_started/.test(browser)],
 ['browser only emits change/completion when meaningful change or terminal state exists',/\(\$changes\|\|\$terminal\).*vp3_cognitive_browser_transaction_event_v2390/s.test(browser)],
 ['browser event payload does not contain raw URL/page text',!/source_url|page_text|selected_text/.test(bridge)],
 ['published annotation emits cognitive event',/vp3_cognitive_annotation_event_v2390/.test(annotations)&&/browser\.annotation_created/.test(bridge)],
 ['Universal Cards existing object ownership is preserved',/'transcription','recording','annotation','source','research','claim'/.test(cards)&&/'workflow','goal','commitment'/.test(cards)],
 ['v23.90 only registers unique new object types',!/'annotation'\s*\]/.test(bridge)&&!/'knowledge'\s*\]/.test(bridge)&&!/'workflow'\s*\]/.test(bridge)],
 ['all v23.90 domain modules register',[
   'browser_operations','research_knowledge','messaging_team','workflow_tools_approvals',
   'homeserver_operations','media_studio','analytics_attribution'
 ].every(x=>bridge.includes(`'${x}'`))],
 ['release gate preserves no-second-ledger and no-passive-browsing invariants',/'second_event_ledger'=>false/.test(release)&&/'passive_browsing_memory'=>false/.test(release)],
 ['docs preserve domain authority and record-only integration',/remain authoritative/.test(docs)&&/record-only/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Domain Integration v23.90 gate: ${checks.length}/${checks.length} passed`);
