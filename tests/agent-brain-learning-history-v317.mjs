import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const helper = read('includes/agent-learning-history-v317.php');
const endpoint = read('api/agent-learning-history-v317.php');
const ui = read('chat-brain-learning-history-v317.js');
const css = read('chat-brain-learning-history-v317.css');
const activity = read('agent-activity-v94.js');
const chat = read('chat.php');
const mainSidebar = read('includes/main-sidebar.php');
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

// Keep the backend/audit projection, but do not expose a duplicate/incomplete
// explainability tab until real production prioritization data is available.
assert.match(ui, /VP3_BRAIN_LEARNING_HISTORY/);
assert.match(ui, /enabled:false/, 'Brain Learning tab must remain hidden for now');
assert.doesNotMatch(ui, /notificationTab\s*=\s*['"]learning['"]|Brain Learning History|Current Source Weights|Recommendation Audit/, 'hidden runtime must not create/render the Brain Learning tab');
assert.doesNotMatch(ui, /cleanupMainSidebar|data-chat-view-target="player"|data-chat-view-target="saved"|data-chat-view-target="playlists"|data-chat-profile-link="my_team"|chatMyTeam/, 'Brain Learning must not mutate Main Feed navigation');
assert.doesNotMatch(activity, /chat-brain-learning-history-v317\.js|data-brain-learning-history-v317/, 'Agent Activity must not load Brain Learning as a compatibility layer');
assert.doesNotMatch(activity, /chat-sidebar-nav|insertAdjacentElement|knowledge\.php/, 'Agent Activity must not mutate the canonical sidebar');

assert.ok(chat.includes("$html = str_replace('agent-activity-v94.js?v=101', 'agent-activity-v94.js?v=' . $activityBuild, $html);"), 'Main Feed must cache-bust the simplified Agent Activity runtime');
assert.match(chat, /require __DIR__ \. '\/includes\/main-sidebar\.php'/, 'Main Feed must render the canonical shared sidebar');
assert.match(chat, /\$mainSidebarHistoryRows = isset\(\$recent\)/, 'Main Feed must pass recent conversation history to the canonical sidebar');
assert.doesNotMatch(chat, /chatMyTeamSidebarLink|data-chat-my-team|data-chat-view-target="\(\?:player\|saved\|playlists\)"/, 'Main Feed wrapper must not patch individual navigation items');
assert.match(mainSidebar, /data-agent-user-footer/, 'canonical shared sidebar must move secondary workspaces into the bottom user menu');
assert.match(mainSidebar, /member_navigation_menu_links\(\$mainSidebarUser\)/, 'canonical shared sidebar must source the bottom user menu from member navigation');
assert.match(nav, /'team_workspaces','Team Workspaces'/, 'Team Workspaces must remain available in canonical member navigation when permitted');
assert.doesNotMatch(mainSidebar, /<strong>My Team<\/strong>|<strong>Player<\/strong>|<strong>Saved Songs<\/strong>|<strong>My Playlists<\/strong>/, 'canonical Agent primary navigation must not expose secondary or retired navigation');
assert.match(chat, /data-brain-learning-history-v317 src=/, 'Main Feed may retain the inert Brain Learning asset without exposing a tab');

assert.match(css, /chat-learning-metrics/, 'Learning History styling may remain available for a future explainability UI');
assert.match(css, /@media\(max-width:520px\)/, 'retained Learning History styling must remain narrow-screen capable');
assert.ok(bootstrap.includes("require_once __DIR__.'/agent-learning-history-v317.php';"), 'bootstrap must retain the canonical Learning History projection');
assert.doesNotMatch(nav, /'my_team','My Team'/, 'legacy My Team must not be duplicated in canonical member navigation');

console.log('AGENT_BRAIN_LEARNING_HISTORY_V317=PASS');