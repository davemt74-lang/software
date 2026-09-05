import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const intelligence = readFileSync('api/artist-listening-intelligence-v254.php', 'utf8');
const profileApi = readFileSync('api/profile-agent.php', 'utf8');
const bridge = readFileSync('includes/profile-agent-transcription-context.php', 'utf8');
const profileRuntime = readFileSync('includes/profile-agent.php', 'utf8');
const knowledgeCss = readFileSync('personal-knowledge.css', 'utf8');

// AI Summary must keep using the canonical owner stores that already exist.
assert.match(intelligence, /agent_brain_v122_upsert_system_memory\(\$user,'transcript_analysis','artist-listening:' \./, 'AI Summary must save into the canonical Agent Brain');
assert.match(intelligence, /personal_knowledge_store\(\$user,'artist-listening-analysis:' \./, 'AI Summary must save into canonical My Knowledge');

// My Knowledge itself already participates in the Profile Agent policy boundary.
assert.match(profileRuntime, /user_data_policy_can_use_v236\(\$pdo,\$principal,\$owner,'knowledge',\$rid,\$legacy\)/, 'Profile Agent My Knowledge retrieval must remain policy-gated');

// Agent Brain integration is intentionally limited to explicitly saved transcript analyses.
assert.match(bridge, /memory_type='transcript_analysis'/, 'Profile Agent bridge must not expose the entire Agent Brain');
assert.doesNotMatch(bridge, /agent_chat_archive|agent_edit_events|agent_tool_history/, 'Profile Agent bridge must not expose private Brain history ledgers');
assert.match(bridge, /user_data_policy_can_use_v236[\s\S]*?'knowledge'[\s\S]*?\$brainResourceId[\s\S]*?false/, 'Transcript Brain context must use the existing Knowledge Access permission boundary');
assert.ok(bridge.includes("'personal-' . sha1('artist-listening-analysis:' . $sessionId) . '.txt'"), 'Bridge must recognize the canonical deterministic My Knowledge copy');
assert.ok(bridge.includes("if ($knowledgeId > 0 && user_data_policy_can_use_v236("), 'Bridge must policy-check an existing My Knowledge copy before deduping');

// Public Profile Agent chat must explicitly add only the approved bridge context.
assert.match(profileApi, /profile-agent-transcription-context\.php/, 'Profile Agent API must load the transcription integration bridge');
assert.match(profileApi, /profile_agent_transcript_brain_context_v255\(\$pdo,\$ownerUser,\$agent,\$visitor,\$query,\$cid\)/, 'Profile Agent message path must append approved transcript Brain context');

// The fixed-height member shell must give My Knowledge an explicit scrolling content owner.
assert.match(knowledgeCss, /\.personal-knowledge-main\{[^}]*min-height:0[^}]*overflow:hidden/, 'My Knowledge main shell must constrain its grid row');
assert.match(knowledgeCss, /\.personal-knowledge-canvas\{[^}]*min-height:0[^}]*overflow-y:auto/, 'My Knowledge canvas must own vertical scrolling');

console.log('TRANSCRIPTION_AGENT_KNOWLEDGE_INTEGRATION=PASS');
