import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');

const ui = read('chat-agent-identity-v236.js');
const css = read('chat-agent-identity-v236.css');
const api = read('api/chat-onboarding-v241.php');
const htaccess = read('.htaccess');

assert.doesNotThrow(() => new Function(ui), 'Agent onboarding runtime must remain valid JavaScript');

assert.match(ui, /chat-onboarding-v241\.php/);
assert.match(ui, /Choose your onboarding experience/);
assert.match(ui, /Turn on voice/);
assert.match(ui, /Continue with text/);
assert.match(ui, /StonefellowPremiumVoiceV122/);
assert.match(ui, /speechSynthesis/);
assert.match(ui, /Enable Profile Agent/);
assert.match(ui, /Show my profile/);
assert.match(ui, /Online user-to-user chat/);
assert.match(ui, /Incoming chat sound/);
assert.match(ui, /Make a Voice Clone/);
assert.match(ui, /window\.STONEFELLOW_ONBOARDING_STATE/);
assert.match(ui, /stonefellow:onboarding-state/);
assert.match(ui, /onboardingRequest\('finish'/);
assert.equal(ui.includes("settingsRequest('create_agent'"), false, 'Name step must not partially create an agent before final onboarding save');

assert.match(css, /\.chat-agent-name-card-v236\{[^}]*background:#fff/);
assert.match(css, /\.chat-agent-field-v241 input[^\{]*\{[^}]*background:#fff!important[^}]*color:#17191c!important/);
assert.equal(css.includes('background:#11100f'), false, 'Legacy black onboarding input must stay removed');
assert.equal(css.includes('color:#eee8e2'), false, 'Legacy dark-theme onboarding text must stay removed');

assert.match(api, /chat_onboarding_v241_state/);
assert.match(api, /profile_runtime_owner_state/);
assert.match(api, /chat_settings_get_v237/);
assert.match(api, /studio_voice_profile_state/);
assert.match(api, /beginTransaction\(\)/);
assert.match(api, /rollBack\(\)/);
assert.match(api, /user_agent_create_v236/);
assert.match(api, /profile_save/);
assert.match(api, /chat_settings_save_v237/);
assert.match(api, /profile_configure_agent/);
assert.match(api, /user_agent_dismiss_onboarding_v236/);
assert.equal(api.includes('chat_remote_answer'), false, 'Deterministic onboarding must not invoke remote LLM chat');
assert.equal(api.includes('chat_local_answer'), false, 'Deterministic onboarding must not invoke chat answer generation');
assert.equal(/openai|anthropic|gemini/i.test(api), false, 'Onboarding endpoint must not call an LLM provider');

assert.match(htaccess, /chat-agent-identity-v236/);
assert.match(htaccess, /no-cache, must-revalidate/);

console.log('chat-onboarding-v241 contract: PASS');
