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
const intelligence = read('includes/onboarding-intelligence.php');
const bootstrap = read('includes/bootstrap.php');
const releaseChat = read('includes/release-chat-v105.php');
const textChat = read('api/chat-v236.php');
const voiceChat = read('api/chat-stream-v121.php');
const upgrade = read('upgrade.php');
const migration = read('sql/onboarding-trial-intelligence.sql');
const htaccess = read('.htaccess');

assert.doesNotThrow(() => new Function(ui), 'Agent onboarding runtime must remain valid JavaScript');
assert.match(ui, /chat-onboarding-v241\.php/);
assert.match(ui, /Choose your onboarding experience/);
assert.match(ui, /Turn on voice/);
assert.match(ui, /Keep voice off/);
assert.match(ui, /Name your agent/);
assert.ok(ui.indexOf("key:'voice'") < ui.indexOf("key:'agent'"), 'Voice must remain the first onboarding question and agent name second');
assert.match(ui, /StonefellowPremiumVoiceV122/);
assert.match(ui, /speechSynthesis/);
assert.match(ui, /Enable Profile Agent/);
assert.match(ui, /Show my profile/);
assert.match(ui, /Online user-to-user chat/);
assert.match(ui, /Incoming chat sound/);
assert.match(ui, /Make a Voice Clone/);
assert.match(ui, /window\.STONEFELLOW_ONBOARDING_STATE/);
assert.match(ui, /stonefellow:onboarding-state/);
assert.match(ui, /onboardingRequest\('save_progress'/);
assert.match(ui, /serverIntelligence\(\)\.draft/);
assert.match(ui, /intelligence\?\.current_step/);
assert.match(ui, /renderTrialNotice/);
assert.match(ui, /ack_trial_notice/);
assert.match(ui, /package_recommendation/);
assert.match(ui, /onboardingRequest\('finish'/);
assert.equal(ui.includes("settingsRequest('create_agent'"), false, 'Name step must not partially create an agent before final onboarding save');

assert.match(css, /\.chat-agent-name-card-v236\{[^}]*background:#fff/);
assert.match(css, /\.chat-agent-field-v241 input[^\{]*\{[^}]*background:#fff!important[^}]*color:#17191c!important/);
assert.equal(css.includes('background:#11100f'), false, 'Legacy black onboarding input must stay removed');
assert.equal(css.includes('color:#eee8e2'), false, 'Legacy dark-theme onboarding text must stay removed');

assert.match(api, /chat_onboarding_v241_full_state/);
assert.match(api, /onboarding_intelligence_ensure_schema/);
assert.match(api, /save_progress/);
assert.match(api, /ack_trial_notice/);
assert.match(api, /beginTransaction\(\)/);
assert.match(api, /rollBack\(\)/);
assert.match(api, /user_agent_create_v236/);
assert.match(api, /'voice_enabled' => \$voiceEnabled \? 1 : 0/);
assert.match(api, /profile_save/);
assert.match(api, /chat_settings_save_v237/);
assert.match(api, /profile_configure_agent/);
assert.match(api, /onboarding_intelligence_mark_complete/);
assert.match(bootstrap, /chat-onboarding-v241\.php/);
assert.match(domain, /onboarding-intelligence\.php/);
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
assert.match(domain, /voice clone/i);
assert.match(domain, /stem editor/i);
assert.match(domain, /video editor/i);
assert.match(domain, /subscription_current/);
assert.match(domain, /subscription_ai_balance/);
assert.match(domain, /subscription_has_entitlement/);
assert.match(domain, /package_recommendation/);
assert.match(domain, /lowest available package that matches what you are using/);
assert.match(domain, /completion_percent/);
assert.match(domain, /locked/);
assert.match(domain, /do not count against completion/i);
assert.match(domain, /View Plan & AI Usage|View Plans/);

for (const column of ['voice_preference','onboarding_step','onboarding_draft_json','feature_interest_json','last_trial_notice_threshold','last_trial_notice_at']) {
  assert.match(intelligence, new RegExp(column), `persistent onboarding storage must include ${column}`);
  assert.match(migration, new RegExp(column), `manual migration must include ${column}`);
}
assert.match(intelligence, /function onboarding_intelligence_trial_notice/);
assert.match(intelligence, /\$days<=3\?3/);
assert.match(intelligence, /\$days<=7\?7/);
assert.match(intelligence, /Your trial ends/);
assert.match(intelligence, /subscription_ai_balance/);
assert.match(intelligence, /function onboarding_intelligence_package_recommendation/);
assert.match(intelligence, /ai_usage_ledger/);
assert.match(intelligence, /DATE_SUB\(NOW\(\),INTERVAL 90 DAY\)/);
assert.match(intelligence, /artist_team_members/);
assert.match(intelligence, /subscription_packages\(true\)/);
assert.match(intelligence, /onboarding_intelligence_package_supports/);
assert.match(upgrade, /onboarding_intelligence_schema_ready/);
assert.match(upgrade, /onboarding_intelligence_ensure_schema/);

const capabilityGate = releaseChat.indexOf('chat_onboarding_v241_tool');
const releaseGate = releaseChat.indexOf('release_v105_schema_ready');
assert.ok(capabilityGate >= 0 && releaseGate > capabilityGate, 'Capability state must short-circuit before release work');
assert.match(releaseChat, /if \(!empty\(\$accountState\['handled'\]\)\) return \$accountState/);
assert.match(releaseChat, /function chat_account_state_intent_v241/);
assert.match(textChat, /release_v105_chat_tool/);
assert.match(voiceChat, /release_v105_chat_tool/);
assert.match(textChat, /empty\(\$toolResult\['handled'\]\)[\s\S]*chat_generate_answer_policy_v236/);
assert.match(voiceChat, /if\(!empty\(\$toolResult\['handled'\]\)\)[\s\S]*else\{[\s\S]*ai_v121_stream_chat_response/);

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
    ['what plan should I use?', true],
    ['recommend a plan for me', true],
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

for (const deterministic of [api, domain, intelligence]) {
  assert.equal(deterministic.includes('chat_remote_answer'), false, 'Deterministic onboarding/state code must not invoke remote LLM chat');
  assert.equal(deterministic.includes('chat_local_answer'), false, 'Deterministic onboarding/state code must not invoke chat answer generation');
  assert.equal(/openai|anthropic|gemini/i.test(deterministic), false, 'Deterministic onboarding/state code must not call an LLM provider');
}

assert.match(htaccess, /chat-agent-identity-v236/);
assert.match(htaccess, /no-cache, must-revalidate/);
console.log('chat-onboarding-v241 persistent trial-intelligence contract: PASS');