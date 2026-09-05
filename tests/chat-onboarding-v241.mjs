import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');

const ui = read('chat-agent-identity-v236.js');
const css = read('chat-agent-identity-v236.css');
const api = read('api/chat-onboarding-v241.php');
const domain = read('includes/chat-onboarding-v241.php');
const bootstrap = read('includes/bootstrap.php');
const releaseChat = read('includes/release-chat-v105.php');
const textChat = read('api/chat-v236.php');
const voiceChat = read('api/chat-stream-v121.php');
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

// The HTTP endpoint owns validation/atomic writes. Read-only setup/capability
// state lives in a shared domain service so Agent Chat can reuse it without AI.
assert.match(api, /chat_onboarding_v241_state/);
assert.match(api, /beginTransaction\(\)/);
assert.match(api, /rollBack\(\)/);
assert.match(api, /user_agent_create_v236/);
assert.match(api, /profile_save/);
assert.match(api, /chat_settings_save_v237/);
assert.match(api, /profile_configure_agent/);
assert.match(api, /user_agent_dismiss_onboarding_v236/);
assert.match(bootstrap, /chat-onboarding-v241\.php/);
assert.match(domain, /function chat_onboarding_v241_state/);
assert.match(domain, /profile_runtime_owner_state/);
assert.match(domain, /chat_settings_get_v237/);
assert.match(domain, /studio_voice_profile_state/);
assert.match(domain, /function chat_onboarding_v241_capabilities/);
assert.match(domain, /'configured'/);
assert.match(domain, /'available'/);
assert.match(domain, /'required_setup_complete'/);
assert.match(domain, /function chat_onboarding_v241_tool/);
assert.match(domain, /account:onboarding-state/);
assert.match(domain, /profile agent/i);
assert.match(domain, /voice\\s\+clone/);
assert.match(domain, /setup status/);
assert.match(domain, /what\\s\+setup\\s\+/);
assert.match(domain, /public\|visible\|private/);

// Both text and streaming Chat already enter release_v105_chat_tool before any
// model generation. That shared synchronous gate must delegate setup/capability
// intents first, and only continue to release/AI work when it did not handle one.
const capabilityGate = releaseChat.indexOf('chat_onboarding_v241_tool');
const releaseGate = releaseChat.indexOf('release_v105_schema_ready');
assert.ok(capabilityGate >= 0 && releaseGate > capabilityGate, 'Capability state must short-circuit before release work');
assert.match(releaseChat, /if \(!empty\(\$accountState\['handled'\]\)\) return \$accountState/);
assert.match(releaseChat, /function chat_account_state_intent_v241/);
assert.match(textChat, /release_v105_chat_tool/);
assert.match(voiceChat, /release_v105_chat_tool/);
assert.match(textChat, /empty\(\$toolResult\['handled'\]\)[\s\S]*chat_generate_answer_policy_v236/);
assert.match(voiceChat, /if\(!empty\(\$toolResult\['handled'\]\)\)[\s\S]*else\{[\s\S]*ai_v121_stream_chat_response/);

// Execute the real PHP intent guard, rather than only checking its source.
// Personal saved-state questions must short-circuit; explanatory questions must not.
const intentProbe = spawnSync('php', ['-r', String.raw`
require 'includes/release-chat-v105.php';
$cases = [
    ['is my Profile Agent enabled?', true],
    ['do I have a voice clone?', true],
    ['what setup am I missing?', true],
    ['what do I still need to set up?', true],
    ['is my profile public?', true],
    ['is my profile private?', true],
    ['is my Profile Agent on?', true],
    ['what is a Profile Agent?', false],
    ['how does a voice clone work on mobile?', false],
    ['explain social chat', false],
];
foreach ($cases as [$query, $expected]) {
    $actual = chat_account_state_intent_v241($query);
    if ($actual !== $expected) {
        fwrite(STDERR, $query . ' expected ' . ($expected ? 'true' : 'false') . ' got ' . ($actual ? 'true' : 'false') . PHP_EOL);
        exit(1);
    }
}
echo "ACCOUNT_STATE_INTENT_V241=PASS\n";
`], {cwd:root, encoding:'utf8'});
assert.equal(intentProbe.status, 0, intentProbe.stderr || 'Account-state intent routing probe failed');
assert.match(intentProbe.stdout, /ACCOUNT_STATE_INTENT_V241=PASS/);

for (const deterministic of [api, domain]) {
  assert.equal(deterministic.includes('chat_remote_answer'), false, 'Deterministic onboarding/state code must not invoke remote LLM chat');
  assert.equal(deterministic.includes('chat_local_answer'), false, 'Deterministic onboarding/state code must not invoke chat answer generation');
  assert.equal(/openai|anthropic|gemini/i.test(deterministic), false, 'Deterministic onboarding/state code must not call an LLM provider');
}

assert.match(htaccess, /chat-agent-identity-v236/);
assert.match(htaccess, /no-cache, must-revalidate/);

console.log('chat-onboarding-v241 contract: PASS');
