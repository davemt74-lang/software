import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path,'utf8');

const chatVoice = read('chat-voice.js');
const premiumVoice = read('premium-voice-v117.js');
const voiceApi = read('api/agent-voice-v117.php');
const chatUi = read('chat.js');
const chatEngine = read('includes/chat-engine.php');
const agentPolicy = read('includes/chat-agent-policy-v236.php');
const agentRuntime = read('includes/agent-chat-runtime-v2160.php');
const presentation = read('chat-cognitive-presentation-v510.js');
const presentationCore = read('includes/cognitive-presentation-v510.php');
const presentationApi = read('api/cognitive-presentation-v510.php');
const notifications = read('chat-notifications-drawer-v240.js');
const memberMenu = read('member-agent-voice-menu.js');
const memberUserMenu = read('includes/member-user-menu.php');
const chat = read('chat.php');
const agentContext = read('agent-context-v131.js');
const memberHeader = read('includes/member-header.php');

for (const [name, source] of [
  ['chat-voice.js',chatVoice],
  ['premium-voice-v117.js',premiumVoice],
  ['chat.js',chatUi],
  ['chat-cognitive-presentation-v510.js',presentation],
  ['chat-notifications-drawer-v240.js',notifications],
  ['member-agent-voice-menu.js',memberMenu],
]) {
  assert.doesNotThrow(() => new Function(source), `${name} must remain valid JavaScript`);
}

assert.match(chatVoice,/const BUILD='chat-voice-proactive-v244-20260922'/);
assert.match(chatVoice,/function isStopControl\(/,'v2.44 must classify spoken stop/cancel as control');
assert.match(chatVoice,/function applyStopControl\(/,'v2.44 must own stop locally in the voice runtime');
assert.match(chatVoice,/if\(applyStopControl\(transcript,'transcript'\)\)return/,'stop must be consumed before transcript submission');
assert.match(chatVoice,/if\(applyStopControl\(transcript,`barge-\$\{reason\}`\)\)return/,'barge stop must be consumed before a new turn');
assert.match(chatVoice,/stonefellow:agent-stop/,'canonical stop event must be published');
assert.match(chatVoice,/stonefellow:agent-proactive-speech/,'proactive speech must attach to canonical barge-in state');
assert.doesNotMatch(chatVoice,/submitVoiceTranscript\(['"]stop['"]\)/,'stop must never be converted into a new Agent request');

assert.match(presentation,/activeVoiceThrough/);
assert.match(presentation,/suppressedVoiceThrough/);
assert.match(presentation,/voice_suppressed/,'interrupted proactive voice must persist suppression');
assert.match(presentation,/stonefellow:agent-stop/);
assert.match(presentation,/cancelSpeech/);
assert.match(presentation,/function renderBriefError\(/,'Agent Brief failures must render a retryable state instead of hanging on Loading');
assert.match(presentation,/data-agent-brief-retry/,'Agent Brief must expose a retry control after load failure');
assert.match(presentation,/void refresh\(true\)/,'opening Agent Brief must force a fresh state request');
assert.match(presentation,/AbortController/,'Agent Brief state fetch must have a bounded timeout');
assert.match(presentation,/function briefErrorMessage\(/,'Agent Brief must translate backend failures into user-facing guidance');
assert.match(presentation,/latest VP3 database upgrade/,'schema-not-ready failures must produce actionable upgrade guidance');
assert.match(chat,/data-agent-brief-content aria-live="polite" aria-busy="true"/,'Agent Brief loading and recovery state must be announced accessibly');
assert.match(presentationCore,/function vp3_cognitive_presentation_voice_suppressed_v510/);
assert.match(presentationApi,/\$action==='voice_suppressed'/);

assert.match(notifications,/function cancelSpeech\(\)/);
assert.match(notifications,/speechGeneration \+= 1/,'cancel must invalidate queued announcements');
assert.match(notifications,/activeSpeechCancel/,'active ElevenLabs or browser speech must be stoppable');
assert.match(notifications,/stonefellow:agent-proactive-speech/,'notification voice must publish speech lifecycle');
assert.doesNotMatch(notifications,/setVoiceMode\(false\)/,'proactive reports must not turn voice conversation off to speak');

const messageElementStart = chatUi.indexOf('function messageElement(');
const addMessageStart = chatUi.indexOf('function addMessage(',messageElementStart);
assert.ok(messageElementStart >= 0 && addMessageStart > messageElementStart,'Chat message renderer must be present');
const messageRenderer = chatUi.slice(messageElementStart,addMessageStart);
assert.doesNotMatch(messageRenderer,/message-sources/,'chat results must not render source labels/tags at the end');
assert.match(chatUi,/data-chat-prompt-action/,'fallback presentation must expose concrete prompt buttons');
assert.match(chatUi,/payload\.knowledge_scope=knowledgeScopeRuntime\.value\(\)/,'selected Knowledge scope must be attached by the canonical Chat send path');
assert.match(agentContext,/knowledge-agent-context-v2451-20260922/,'Knowledge selector must cache-bust the audited binding runtime');
assert.match(agentContext,/knowledgeScopeLoadPromise/,'Knowledge selector folder loading must be idempotent');
assert.doesNotMatch(agentContext,/installKnowledgeScopeFetch/,'Knowledge scope must not monkey-patch global fetch');
assert.match(agentContext,/dataset\.knowledgeScopeReady=finalize\?'1':'loading'/,'Knowledge selector must expose explicit loading and ready states');
assert.match(agentContext,/Saved folder · loading…/,'Knowledge selector must preserve a stored folder while discovery is pending');
assert.match(agentContext,/setKnowledgeScopeOptions\(\[\],\{finalize:false\}\)/,'Knowledge selector must not erase a stored folder during provisional hydration');
assert.match(agentContext,/Knowledge folders request timed out\./,'Knowledge folder discovery must fail visibly and retryably');

assert.match(chatEngine,/function chat_context_is_internal_source\(/);
assert.match(chatEngine,/agent-brain:/);
assert.match(chatEngine,/function chat_context_fallback_actions\(/);
assert.doesNotMatch(chatEngine,/Here’s what I found in the Stonefellow data available to your account:/,'raw retrieval dump must remain removed');
assert.match(agentPolicy,/chat_context_fallback_actions/);
assert.match(agentRuntime,/\$responseActions/);
assert.match(agentRuntime,/array_merge\(\$toolActions,\$responseActions\)/);

assert.match(premiumVoice,/voiceEndpoint\.searchParams\.set\('agent'/,'premium TTS must retain the selected Agent id');
assert.match(voiceApi,/function stonefellow_voice_v244_agent_selection/);
assert.match(voiceApi,/studio_participant_voices/,'Agent clone must resolve through the Voice Profile binding');
assert.match(voiceApi,/function stonefellow_voice_v244_verify/);
assert.match(voiceApi,/https:\/\/api\.elevenlabs\.io\/v1\/voices\//,'warm state must verify the configured voice with ElevenLabs');
assert.match(voiceApi,/'readiness_authority' => 'elevenlabs-get-voice'/);
assert.match(voiceApi,/'voice_id' => \$voiceId/,'voice tickets must bind the resolved Agent voice id');
assert.match(voiceApi,/'voice_source' => \$voiceSource/);
assert.doesNotMatch(voiceApi,/['"]api_key['"]\s*=>/i,'voice readiness must never return the ElevenLabs API key');

assert.match(memberUserMenu,/data-tts-endpoint/);
assert.match(memberMenu,/ElevenLabs Ready/);
assert.match(memberMenu,/ElevenLabs Offline/);
assert.match(memberMenu,/refreshTtsState/);
assert.match(memberMenu,/voice_source/);

assert.match(chat,/\$premiumVoiceBuild = 'premium-voice-agent-routing-v244-20260922'/);
assert.match(chat,/\$voiceAssetBuild = 'chat-voice-proactive-v244-20260922'/);
assert.match(chat,/\$voiceCacheBuild = 'chat-voice-proactive-v244-20260922-stop-control1'/);
assert.match(chat,/\$notificationDrawerBuild = 'chat-notifications-proactive-v244-20260922'/);
assert.match(chat,/\$controlBuild = 'chat-footer-runtime-v2451-20260922'/);
assert.match(chat,/\$cognitivePresentationBuild = 'cognitive-presentation-footer-v2451-20260922'/);
assert.match(memberHeader,/\$memberAgentVoiceMenuBuild = 'agent-voice-menu-v244-20260922'/);

console.log('Agent Chat v2.44 proactive interaction + ElevenLabs hardening contract: PASS');
