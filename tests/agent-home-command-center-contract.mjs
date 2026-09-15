import fs from 'node:fs';
import assert from 'node:assert/strict';

const home = fs.readFileSync('home.php', 'utf8');
const chat = fs.readFileSync('chat.php', 'utf8');
const intelligence = fs.readFileSync('includes/agent-chat-intelligence-v171.php', 'utf8');
const sidebar = fs.readFileSync('includes/main-sidebar.php', 'utf8');
const css = fs.readFileSync('chat-agent-intelligence-v171.css', 'utf8');
const navigation = fs.readFileSync('includes/member-navigation.php', 'utf8');
const login = fs.readFileSync('login.php', 'utf8');

assert.match(home, /require __DIR__ \. '\/includes\/bootstrap\.php'/, 'Home compatibility route must boot the canonical VP3 runtime');
assert.match(home, /require_login\(\)/, 'Home compatibility route must remain authenticated');
assert.match(home, /has_permission\('chat\.access', \$user\)/, 'Home compatibility route must verify Agent Chat access');
assert.match(home, /redirect\(url\(\$target\)\)/, 'Home compatibility route must redirect chat-capable users into Agent Chat');
assert.match(home, /has_permission\('account\.access', \$user\).*account\.php/s, 'non-chat account users must retain a safe account fallback');
assert.doesNotMatch(home, /agent-home-canvas|agent-home-grid|agent-home-hero/, 'Home must no longer render a parallel command-center dashboard');

assert.match(chat, /agent-chat-intelligence-v171\.php/, 'Agent Chat must own the integrated intelligence layer');
assert.match(chat, /vp3_agent_chat_intelligence_model_v171/, 'Agent Chat must build intelligence from canonical VP3 state');
assert.match(chat, /vp3_agent_chat_intelligence_render_v171/, 'Agent Chat must render intelligence into the main canvas');
assert.match(intelligence, /agent_proactive_v123_suggestions/, 'Integrated intelligence must reuse the existing Agent Brain ranking engine');

assert.ok(navigation.includes("'home.php'=>'home'"), 'Home route key remains for compatibility with existing links');
assert.ok(navigation.includes("$add($links,'home','Home',url('/home.php'),'primary')"), 'Home compatibility destination remains registered until callers migrate');
assert.match(css, /\.workspace-main-sidebar \[data-vp3-nav-key=\"home\"\]\{display:none!important\}/, 'Agent Chat must suppress the duplicate Home destination while preserving compatibility navigation source');
assert.match(sidebar, /\$mainSidebarPrimaryOrder = \['home','chat','profile_agent'/, 'Compatibility key ordering remains stable for retained shell contracts');
assert.match(login, /vp3_funnel_finish_auth\(login_destination\(\), current_user\(\)\)/, 'login must retain funnel return-to continuity');

console.log('Agent Home compatibility + Chat integration contract passed.');
