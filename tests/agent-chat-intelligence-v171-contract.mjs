import fs from 'node:fs';
import assert from 'node:assert/strict';

const chat = fs.readFileSync('chat.php', 'utf8');
const home = fs.readFileSync('home.php', 'utf8');
const intelligence = fs.readFileSync('includes/agent-chat-intelligence-v171.php', 'utf8');
const css = fs.readFileSync('chat-agent-intelligence-v171.css', 'utf8');
const js = fs.readFileSync('chat-agent-intelligence-v171.js', 'utf8');
const sidebar = fs.readFileSync('includes/main-sidebar.php', 'utf8');

assert.match(chat, /\$agentIntelligenceBuild = 'agent-chat-intelligence-v171-20260914'/, 'Chat must expose an explicit intelligence build');
assert.match(chat, /X-VP3-Agent-Intelligence/, 'Chat must expose the intelligence runtime header');
assert.match(chat, /require_once __DIR__ \. '\/includes\/agent-chat-intelligence-v171\.php'/, 'Chat must load the integrated intelligence provider');
assert.match(chat, /agentIntelligenceHtml .*chatWelcome/s, 'Agent Brief must be inserted into the canonical Chat thread before the welcome turn');
assert.match(chat, /chat-agent-intelligence-v171\.css/, 'Chat must load the intelligence presentation layer');
assert.match(chat, /chat-agent-intelligence-v171\.js/, 'Chat must load the intelligence interaction runtime');
assert.match(chat, /data-agent-intelligence-build=/, 'Chat build marker must include the integrated intelligence version');

assert.match(intelligence, /agent_proactive_v123_suggestions\(/, 'Agent Brief must reuse the evidence-first proactive ranking engine');
assert.match(intelligence, /user_calendar_events_v1300\(/, 'Agent Brief must reuse the canonical Calendar');
assert.match(intelligence, /\['start_at_utc'\]/, 'Calendar rendering must use the canonical UTC event field');
assert.match(intelligence, /notification_recent\(\$user, 4\)/, 'Agent Brief must reuse account notifications');
assert.match(intelligence, /notification_unread_count\(\$user\)/, 'Agent Brief must reuse unread notification state');
assert.match(intelligence, /agent_workflow_runs WHERE owner_user_id=\? AND status IN \('approval_pending','failed'\)/, 'workflow attention must remain owner-scoped');
assert.match(intelligence, /created_by_user_id=\? AND knowledge_scope='personal'/, 'Knowledge count must remain owner-scoped and personal');
assert.match(intelligence, /homeserver_vp3_connection\(\$uid\)/, 'HomeServer summary must use cached VP3 connection state');
assert.doesNotMatch(intelligence, /homeserver_vp3_status\(/, 'server render must not force a HomeServer relay refresh');
assert.doesNotMatch(intelligence, /native_path|folder_path|filesystem_path/i, 'Agent Brief must never expose HomeServer filesystem paths');
assert.match(intelligence, /data-agent-intelligence-prompt=/, 'recommendations must flow back through Agent Chat prompts');
assert.match(intelligence, /\/agent-workflows\.php/, 'pending approvals and failed work must link to the existing Agent Work Queue');
assert.match(intelligence, /\/calendar\.php/, 'schedule detail must link to the existing Calendar');
assert.match(intelligence, /\/knowledge\.php/, 'Knowledge detail must link to the existing Knowledge workspace');

assert.match(js, /form\.requestSubmit\(\)/, 'one-click advisory actions must use the canonical Chat composer submission path');
assert.match(js, /localStorage\.setItem/, 'brief collapse preference must persist locally');
assert.match(js, /newChat.*setCollapsed\(false/s, 'a fresh Chat must restore the brief when the user has not pinned it closed');
assert.match(js, /MutationObserver/, 'HomeServer display may observe the existing canonical status control');
assert.match(js, /pagehide/, 'observer cleanup must occur on page exit');
assert.doesNotMatch(js, /fetch\(/, 'intelligence runtime must not create a second HomeServer polling path');
assert.doesNotMatch(js, /setInterval/, 'intelligence runtime must not add background polling');

assert.match(css, /chat-agent-intelligence/, 'integrated intelligence must have a dedicated canvas treatment');
assert.match(css, /@media\(max-width:760px\)/, 'Agent Brief must support the member mobile breakpoint');
assert.match(css, /prefers-reduced-motion:reduce/, 'Agent Brief must respect reduced motion');
assert.match(css, /data-collapsed="true"/, 'Agent Brief must support compact in-conversation state');

assert.match(home, /redirect\(url\(\$target\)\)/, 'legacy Home must route into Agent Chat');
assert.match(css, /\.workspace-main-sidebar \[data-vp3-nav-key=\"home\"\]\{display:none!important\}/, 'Agent Chat must hide the duplicate Home navigation row without breaking retained shell contracts');

console.log('Agent Chat Intelligence v17.1 contract passed.');
