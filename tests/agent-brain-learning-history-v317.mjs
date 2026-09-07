import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const helper = read('includes/agent-learning-history-v317.php');
const endpoint = read('api/agent-learning-history-v317.php');
const ui = read('chat-brain-learning-history-v317.js');
const css = read('chat-brain-learning-history-v317.css');
const activity = read('agent-activity-v94.js');
const bootstrap = read('includes/bootstrap.php');
const nav = read('includes/member-navigation.php');

assert.doesNotThrow(() => new Function(ui), 'Brain Learning History runtime must be valid JavaScript');
assert.match(helper, /agent_proactive_events/, 'Learning History must project the canonical proactive-event ledger');
assert.match(helper, /agent_action_v124_outcome_factor/, 'Learning History must reuse canonical v313 outcome weighting');
assert.match(helper, /agent_action_v124_source_feedback/, 'current source weights must come from canonical source feedback');
assert.match(helper, /VP3_AGENT_LEARNING_HISTORY_WINDOW_DAYS_V317=60/, 'historical weight snapshots must use the same 60-day learning window');
assert.match(helper, /factor_before/, 'audit rows must expose source weight before the outcome');
assert.match(helper, /factor_after/, 'audit rows must expose source weight after the outcome');
assert.match(helper, /factor_delta/, 'audit rows must expose the learned weight change');
assert.ok(helper.includes("'outcome'=>(string)($exposure['stage']??'shown')==='acted'?'acted':'awaiting'"), 'open recommendation cycles must remain visible before final closure');
assert.doesNotMatch(helper, /CREATE TABLE|ALTER TABLE/, 'Learning History must not create parallel persistence');

assert.match(endpoint, /personal_capability_has_v242\('agent_brain\.access'/, 'Learning History endpoint must enforce Agent Brain entitlement');
assert.match(endpoint, /agent_learning_history_v317_state\(\$user,100\)/, 'endpoint must expose the owner-scoped canonical projection');
assert.doesNotMatch(endpoint, /INSERT INTO|UPDATE |DELETE FROM|CREATE TABLE|ALTER TABLE/, 'Learning History endpoint must remain read-only');

assert.match(ui, /data\.notificationTab = 'learning'/, 'Activity Center must add a Brain Learning tab');
assert.match(ui, /Brain Learning History/, 'Learning tab must identify the audit clearly');
assert.match(ui, /Current Source Weights/, 'Learning tab must show current learned source weighting');
assert.match(ui, /Recommendation Audit/, 'Learning tab must show surfaced recommendation outcomes');
assert.match(ui, /factor_before/, 'Learning UI must render before/after weighting');
assert.match(ui, /factor_after/, 'Learning UI must render before/after weighting');
assert.match(ui, /target\.origin === window\.location\.origin/, 'evidence links must remain same-origin');
assert.match(ui, /data-chat-view-target="player"/, 'Main Feed enhancement must remove Player navigation');
assert.match(ui, /data-chat-view-target="saved"/, 'Main Feed enhancement must remove Saved Songs navigation');
assert.match(ui, /data-chat-view-target="playlists"/, 'Main Feed enhancement must remove My Playlists navigation');
assert.match(ui, /data-chat-profile-link="my_team"/, 'Main Feed My Team link must come from canonical authorized member navigation');
assert.match(activity, /chat-brain-learning-history-v317\.js\?v=317-20260907/, 'Main Feed activity runtime must load the Brain Learning enhancement');
assert.match(activity, /data-brain-learning-history-v317/, 'Learning enhancement must be single-owner loaded');
assert.match(css, /chat-learning-metrics/, 'Learning History must have dedicated light UI styling');
assert.match(css, /@media\(max-width:520px\)/, 'Learning History must remain usable on narrow screens');
assert.ok(bootstrap.includes("require_once __DIR__.'/agent-learning-history-v317.php';"), 'bootstrap must load the canonical Learning History projection');
assert.match(nav, /'my_team','My Team',url\('\/admin\/team\.php'\)/, 'canonical member navigation must own My Team');

console.log('AGENT_BRAIN_LEARNING_HISTORY_V317=PASS');
