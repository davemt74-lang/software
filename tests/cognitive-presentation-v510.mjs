import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const core=read('includes/cognitive-presentation-v510.php');
const api=read('api/cognitive-presentation-v510.php');
const js=read('chat-cognitive-presentation-v510.js');
const css=read('chat-cognitive-presentation-v510.css');
const chat=read('chat.php');
const loop=read('includes/agent-cognitive-loop-v310.php');
const drawer=read('chat-notifications-drawer-v240.js');
const activityApi=read('api/chat-notifications-brain-v240.php');
const upgrade=read('upgrade.php');
const bootstrap=read('includes/bootstrap.php');

assert.match(core,/VP3_COGNITIVE_PRESENTATION_V510/);
assert.match(core,/VP3_COGNITIVE_DIGEST_NORMAL_IDLE_MINUTES_V510=60/);
assert.match(core,/VP3_COGNITIVE_DIGEST_ATTENTION_IDLE_MINUTES_V510=30/);
for(const table of ['cognitive_presentation_state_v510','cognitive_return_digests_v510']) assert.match(core,new RegExp(table));
assert.match(core,/notification_requires_attention/);
assert.match(core,/baseline_notification_id/);
assert.match(core,/last_voice_notification_id/);
assert.match(core,/vp3_cognitive_presentation_digest_items_v510/);
assert.match(core,/vp3_cognitive_presentation_voice_allowed_type_v510/);
assert.match(core,/chat_settings_get_v237/);
assert.match(core,/vp3_agent_chat_intelligence_model_v171/);
assert.match(core,/agent_cognitive_loop_v310_priority_items/,'Agent Brief must consume the canonical Brain priority state');
assert.match(core,/\$eligible=array_values\(array_filter/,'30-minute attention digest must not consume routine 60-minute updates');

assert.doesNotMatch(api,/observation_store|presentation_decide/);
assert.match(api,/digest_ack/);
assert.match(api,/voice_delivered/);
assert.match(api,/interaction/);

assert.doesNotThrow(()=>new Function(js));
assert.match(js,/data-agent-brief-prompt/);
assert.match(js,/While you were away/);
assert.match(js,/STONEFELLOW_NOTIFICATION_CENTER/);
assert.match(js,/voice_delivered/);
assert.match(js,/TRANSCRIPT_SUBMIT/);
assert.match(js,/setInterval\(refresh/);
assert.match(css,/chat-agent-status-dot\.active/);
assert.match(css,/vp3-return-digest/);
assert.match(css,/\.chat-agent-intelligence\{display:none!important\}/);

assert.match(chat,/chatAgentBriefButton/);
assert.match(chat,/chatAgentBriefPopover/);
assert.match(chat,/VP3_COGNITIVE_PRESENTATION_V510/);
assert.match(chat,/chat-cognitive-presentation-v510\.js/);
assert.doesNotMatch(chat,/\$agentIntelligenceHtml \. '<div class="message assistant" id="chatWelcome" hidden>'/);

assert.match(loop,/VP3_COGNITIVE_PRESENTATION_V510/);
assert.match(loop,/legacy_chat_surface_disabled/);
assert.match(drawer,/ownsAttention/);
assert.match(drawer,/openBrain/);
assert.match(drawer,/openHistory/);
assert.match(drawer,/announce/);
assert.match(activityApi,/vp3_cognitive_presentation_owns_attention_v510/);
assert.match(activityApi,/presentation_owner'=>'cognitive_runtime_v510/);
assert.match(bootstrap,/cognitive-presentation-v510\.php/);
assert.match(upgrade,/vp3_cognitive_presentation_schema_ready_v510\(\)/);
assert.match(upgrade,/vp3_cognitive_presentation_ensure_schema_v510\(\$pdo\)/);

console.log('VP3 Phase 11B.2 Cognitive Presentation + Agent Chat shell: PASS');