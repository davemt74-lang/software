import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const onboarding=read('includes/onboarding-intelligence.php');
const domain=read('includes/chat-onboarding-v241.php');
const api=read('api/chat-onboarding-v241.php');
const ui=read('chat-agent-identity-v236.js');
const css=read('chat-agent-identity-v236.css');
const chat=read('chat.php');
const funnel=read('includes/vp3-funnel.php');
const settings=read('includes/chat-settings-v237.php');
const settingsApi=read('api/chat-settings-v237.php');
const settingsUi=read('chat-settings-v237.js');
const memberNav=read('includes/member-navigation.php');
const memberVoice=read('member-agent-voice-menu.js');
const memberMenu=read('includes/member-user-menu.php');
const notificationSettings=read('notification-settings.php');
const chatVoice=read('chat-voice.js');
const notificationVoice=read('chat-notifications-drawer-v240.js');
const cognitive=read('includes/cognitive-presentation-v510.php');
const extensionVoice=read('includes/extension-notifications-v2140.php');
const tts=read('api/agent-voice-v117.php');

assert.doesNotThrow(()=>new Function(ui),'guided onboarding JS must parse');
assert.doesNotThrow(()=>new Function(settingsUi),'Chat Settings JS must parse');
assert.doesNotThrow(()=>new Function(memberVoice),'member Agent Voice menu JS must parse');
assert.doesNotThrow(()=>new Function(chatVoice),'Chat voice runtime must parse');
assert.doesNotThrow(()=>new Function(notificationVoice),'notification runtime must parse');

assert.match(onboarding,/onboarding-intelligence-20260921-v3/);
assert.match(onboarding,/['"]workspace['"]/,'workspace is a canonical persisted onboarding step');
assert.doesNotMatch(onboarding,/ADD COLUMN .*workspace/i,'current-system onboarding must reuse existing preference storage');

for(const key of ['browser','transcription','meetings','calendar','booking','commerce','analytics','teams','homeserver']){
  assert.match(domain,new RegExp(`'${key}'\\s*=>`),`inventory missing ${key}`);
}
for(const route of ['/connected-browsers.php','/artist-listening.php','/meetings.php','/calendar.php','/scheduling.php','/commerce.php','/profile-agent.php#analytics','/team.php','/settings-homeserver.php']){
  assert.ok(domain.includes(route),`inventory setup route missing ${route}`);
}
assert.match(domain,/optional systems|optional tools|required setup/i,'optional systems must stay outside required completion');
assert.match(ui,/Connect the parts of VP3 you want to use/);
assert.match(ui,/workflow_interests/);
assert.match(ui,/Optional by design/);
assert.match(ui,/cfg\.forceOnboarding/);
assert.match(chat,/\$setupRequested = \(string\)\(\$_GET\['setup'\]/);
assert.match(chat,/forceOnboarding:/);
assert.match(ui,/VP3 Setup/);

for(const interest of ['workflow.browser','workflow.transcription','workflow.meetings','workflow.calendar','workflow.booking','workflow.commerce','workflow.analytics','workflow.teams','workflow.homeserver']){
  assert.ok(funnel.includes(interest),`public acquisition must seed ${interest}`);
}
assert.match(funnel,/str_starts_with\(\$key,'workflow\.'\)/,'workflow interests must persist even though they are not plan entitlements');

assert.match(settings,/function chat_settings_agent_voice_allowed_v237/);
assert.match(settings,/function chat_settings_agent_voice_enabled_v237/);
assert.match(settings,/subscription_has_entitlement\(\$user,'voice\.access'\)/);
assert.match(settingsApi,/agent_voice_allowed/);
assert.match(memberNav,/chat_settings_agent_voice_enabled_v237/);
assert.match(cognitive,/chat_settings_agent_voice_enabled_v237/);
assert.match(extensionVoice,/chat_settings_agent_voice_enabled_v237/);
assert.match(tts,/chat_settings_agent_voice_enabled_v237/);

assert.match(api,/chat_settings_save_agent_voice_v237\(\$pdo,\$user,\$voice==='on'\)/,'onboarding voice choice must write the canonical master');
assert.match(api,/'voice_enabled' => 0/,'new personal Agent must not treat global voice as clone/source selection');
assert.match(api,/'voice_enabled' => !empty\(\$agent\['voice_enabled'\]\) \? 1 : 0/,'existing voice-source selection must survive onboarding edits');
assert.match(api,/'agent_voice_enabled' => \$voiceEnabled \? 1 : 0/,'final onboarding save must keep canonical Agent Voice synchronized');

assert.match(chat,/agentVoiceEnabled:/);
assert.match(chat,/chatSettingsEndpoint:/);
assert.match(chatVoice,/let agentVoiceMaster=identityCfg\.agentVoiceEnabled!==false/);
assert.match(chatVoice,/ensureAgentVoiceMaster/);
assert.match(chatVoice,/save_agent_voice/);
assert.match(chatVoice,/stonefellow:agent-voice/);
assert.match(chatVoice,/if\(!enabled&&voiceOn\)disableVoice/,'master off must stop active Voice Conversation');
assert.match(memberMenu,/data-user-id=/,'header voice sync must be scoped to the signed-in user');
assert.match(memberVoice,/vp3:agent-voice-sync:/,'header must publish user-scoped cross-tab voice state');
assert.match(memberVoice,/publishAgentVoice/,'header must broadcast canonical voice changes');
assert.match(chatVoice,/AGENT_VOICE_SYNC_KEY/,'Chat voice must observe cross-tab Agent Voice shutdown');
assert.match(chatVoice,/sync\?\.enabled===false[\s\S]*disableVoice/,'cross-tab signal may revoke listening immediately');
assert.match(chatVoice,/if\(!agentVoiceMaster\)/,'Voice Conversation must fail closed when master is off');

assert.match(notificationVoice,/if \(wasVoice\) \{[\s\S]*setVoiceMode\(true\)/,'notification speech may only restore an already-active mic session');
assert.doesNotMatch(notificationVoice,/responseTemporaryVoice/,'notification speech must not mint temporary mic authority');
assert.doesNotMatch(notificationVoice,/setVoiceMode\(true\)[\s\S]{0,240}responseWindowActive = true/,'notification speech must not open a fresh listening window');

assert.match(settingsUi,/Master switch for spoken Agent responses and notification announcements/);
assert.match(notificationSettings,/name="agent_voice_enabled"/,'Notification Settings must expose the canonical Agent Voice master');
assert.match(notificationSettings,/does not turn on your microphone/,'Notification Settings must distinguish speech from microphone listening');
assert.match(notificationSettings,/chat_settings_save_agent_voice_v237/,'Notification Settings must save through the canonical voice authority');
assert.match(memberVoice,/Master switch for spoken Agent responses and notification announcements/);
assert.match(ui,/Turn on Agent Voice/);
assert.match(ui,/Voice Conversation and spoken notifications stay off/);

console.log('Agent onboarding current systems + Agent Voice authority v2.42 contract: PASS');
