import fs from 'node:fs';
import assert from 'node:assert/strict';

const chat = fs.readFileSync('chat.php', 'utf8');
const home = fs.readFileSync('home.php', 'utf8');
const intelligence = fs.readFileSync('includes/agent-chat-intelligence-v171.php', 'utf8');
const presentation = fs.readFileSync('includes/cognitive-presentation-v510.php', 'utf8');
const presentationJs = fs.readFileSync('chat-cognitive-presentation-v510.js', 'utf8');
const presentationCss = fs.readFileSync('chat-cognitive-presentation-v510.css', 'utf8');

assert.match(chat, /\$cognitivePresentationBuild = 'cognitive-presentation-v510-20260918'/, 'Chat must expose the Cognitive Presentation build');
assert.match(chat, /X-VP3-Agent-Intelligence/, 'Chat must retain the Agent intelligence runtime header');
assert.match(chat, /chatAgentBriefButton/, 'Agent Brief must move to the composer control');
assert.match(chat, /chatAgentBriefPopover/, 'Agent Brief must render in the footer popover');
assert.match(chat, /VP3_COGNITIVE_PRESENTATION_V510/, 'Chat must configure the Cognitive Presentation owner');
assert.match(chat, /chat-cognitive-presentation-v510\.css/, 'Chat must load the new presentation layer');
assert.match(chat, /chat-cognitive-presentation-v510\.js/, 'Chat must load the new presentation runtime');
assert.doesNotMatch(chat, /vp3_agent_chat_intelligence_render_v171\(/, 'Chat must not render the old large Agent Brief into the conversation');
assert.match(chat, /\$agentIntelligenceRuntime = '';/, 'legacy Agent Brief browser runtime must stay retired');

assert.match(presentation, /require_once __DIR__\.\'\/agent-chat-intelligence-v171\.php\'/, 'Cognitive Presentation must reuse the canonical intelligence provider');
assert.match(presentation, /vp3_agent_chat_intelligence_model_v171\(/, 'Cognitive Presentation must build Brief state from canonical VP3 intelligence');
assert.match(presentation, /agent_cognitive_loop_v310_priority_items\(/, 'Brief priority must come from canonical Agent Brain priority state');

assert.match(intelligence, /agent_proactive_v123_suggestions\(/, 'underlying intelligence provider must retain evidence-first proactive ranking');
assert.match(intelligence, /user_calendar_events_v1300\(/, 'underlying intelligence provider must reuse canonical Calendar');
assert.match(intelligence, /\['start_at_utc'\]/, 'Calendar state must use canonical UTC event field');
assert.match(intelligence, /notification_recent\(\$user, 4\)/, 'Brief state must reuse account notifications');
assert.match(intelligence, /notification_unread_count\(\$user\)/, 'Brief state must reuse unread notification state');
assert.match(intelligence, /agent_workflow_runs WHERE owner_user_id=\? AND status IN \('approval_pending','failed'\)/, 'workflow attention must remain owner-scoped');
assert.match(intelligence, /created_by_user_id=\? AND knowledge_scope='personal'/, 'Knowledge count must remain owner-scoped and personal');
assert.match(intelligence, /homeserver_vp3_connection\(\$uid\)/, 'HomeServer summary must use cached VP3 connection state');
assert.doesNotMatch(intelligence, /homeserver_vp3_status\(/, 'Brief state must not force a HomeServer relay refresh');
assert.doesNotMatch(intelligence, /native_path|folder_path|filesystem_path/i, 'Brief state must never expose HomeServer filesystem paths');

assert.doesNotThrow(() => new Function(presentationJs), 'Cognitive Presentation runtime must be valid JavaScript');
assert.match(presentationJs, /form\.requestSubmit\(\)/, 'one-click Brief actions must use the canonical Chat composer');
assert.match(presentationJs, /data-agent-brief-prompt/, 'Brief advisory actions must route back through Agent Chat');
assert.match(presentationJs, /setInterval\(refresh/, 'new presentation state uses the bounded 30-second runtime poll');
assert.match(presentationJs, /openBrain/, 'Brief must deep-link into Agent Brain');
assert.match(presentationJs, /openHistory/, 'Brief must deep-link into Agent History');

assert.match(presentationCss, /chat-agent-brief-popover/, 'new Brief must have a dedicated footer presentation');
assert.match(presentationCss, /chat-agent-status-dot\.active/, 'new Brief must expose active/inactive Agent state');
assert.match(presentationCss, /chat-agent-attention-badge/, 'attention count must be independent from Agent active state');
assert.match(presentationCss, /@media\(max-width:820px\)/, 'new Brief must support the member mobile breakpoint');
assert.match(presentationCss, /\.workspace-main-sidebar \[data-vp3-nav-key="home"\]\{display:none!important\}/, 'Chat must continue hiding the duplicate Home navigation row');

assert.match(home, /redirect\(url\(\$target\)\)/, 'legacy Home must route into Agent Chat');

console.log('Agent Chat Intelligence v17.1 compatibility with Cognitive Presentation v5.10 passed.');
