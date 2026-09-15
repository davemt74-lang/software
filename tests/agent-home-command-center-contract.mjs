import fs from 'node:fs';
import assert from 'node:assert/strict';

const home = fs.readFileSync('home.php', 'utf8');
const css = fs.readFileSync('agent-home-v194.css', 'utf8');
const js = fs.readFileSync('agent-home-v194.js', 'utf8');
const auth = fs.readFileSync('includes/auth.php', 'utf8');
const navigation = fs.readFileSync('includes/member-navigation.php', 'utf8');
const sidebar = fs.readFileSync('includes/main-sidebar.php', 'utf8');
const login = fs.readFileSync('login.php', 'utf8');

assert.match(home, /require __DIR__ \. '\/includes\/bootstrap\.php'/, 'Agent Home must boot the canonical VP3 runtime');
assert.match(home, /require_login\(\)/, 'Agent Home must require authentication');
assert.match(home, /\$workspaceSidebarActive='home'/, 'Agent Home must use canonical sidebar active state');
assert.match(home, /\$memberHeaderActiveKey = 'home'/, 'Agent Home must use the shared member header with the Home key');
assert.match(home, /includes\/workspace-sidebar-v82\.php/, 'Agent Home must consume the canonical sidebar wrapper');
assert.match(home, /includes\/member-header\.php/, 'Agent Home must consume the shared member header');

assert.match(home, /agent_memory_v123_tasks\(\$user, false\)/, 'Agent Home must reuse the existing Agent task lifecycle');
assert.match(home, /user_calendar_events_v1300\(/, 'Agent Home must reuse the existing VP3 Calendar');
assert.match(home, /notification_recent\(\$user, 4\)/, 'Agent Home must reuse account notifications');
assert.match(home, /notification_agent_brain_activity_after\(/, 'Agent Home must reuse durable Agent Brain activity');
assert.match(home, /homeserver_vp3_connection\(\$uid\)/, 'Agent Home server render must use cached HomeServer connection state');
assert.doesNotMatch(home, /homeserver_vp3_status\(/, 'Agent Home PHP render must not force a HomeServer relay refresh');
assert.match(home, /created_by_user_id=\? AND knowledge_scope='personal'/, 'Agent Home Knowledge queries must stay owner-scoped and personal');
assert.doesNotMatch(home, /native_path|folder_path|filesystem_path/i, 'Agent Home must not expose HomeServer filesystem paths');
assert.doesNotMatch(home, /\b(?:INSERT|UPDATE|DELETE|ALTER|CREATE TABLE)\b/i, 'Agent Home must remain a read-only command center');

assert.match(js, /homeserverEndpoint/, 'Agent Home JS must receive the existing HomeServer status endpoint');
assert.match(home, /\/api\/homeserver-status\.php/, 'Agent Home must use the established HomeServer status API');
assert.match(js, /AbortController/, 'HomeServer refresh must be bounded and abortable');
assert.match(js, /pagehide/, 'Agent Home must clean up in-flight HomeServer work');
assert.match(js, /Cached server-rendered state remains authoritative fallback/, 'HomeServer refresh must fail soft to cached state');
assert.doesNotMatch(js, /setInterval/, 'Agent Home must not add background polling');
assert.match(js, /setTimeout\(function\(\)\{controller\.abort\(\);\},7000\)/, 'HomeServer refresh must use a bounded seven-second timeout');

assert.ok(css.length > 1000, 'Agent Home must ship a dedicated responsive presentation layer');
assert.match(css, /@media\(max-width:760px\)/, 'Agent Home must support the member mobile breakpoint');
assert.match(css, /prefers-reduced-motion:reduce/, 'Agent Home must respect reduced motion');

assert.match(auth, /has_permission\('chat\.access', \$user\) \|\| has_permission\('account\.access', \$user\)\) return url\('\/home\.php'\)/, 'normal authenticated users must land on Agent Home');
assert.match(login, /vp3_funnel_finish_auth\(login_destination\(\), current_user\(\)\)/, 'login must retain funnel return-to continuity around the new destination');
assert.ok(navigation.includes("'home.php'=>'home'"), 'canonical active-key resolver must recognize Agent Home');
assert.ok(navigation.includes("$add($links,'home','Home',url('/home.php'),'primary')"), 'canonical navigation must expose Home as a primary destination');
assert.match(navigation, /'home','chat','profile_agent','voice_profile'=>'Agent'/, 'Home must belong to the Agent shell section');
assert.match(sidebar, /\$mainSidebarPrimaryOrder = \['home','chat','profile_agent'/, 'Home must lead the canonical primary navigation');
assert.ok(sidebar.includes("'home'=>'Home'"), 'sidebar must label the Home destination');
assert.ok(sidebar.includes("'home'=>'⌂'"), 'sidebar must give Home a stable icon');
assert.match(sidebar, /class="chat-brand" href="<\?= e\(url\('\/home\.php'\)\) \?>" aria-label="VP3 Home"/, 'VP3 sidebar brand must return to Agent Home');

console.log('Agent Home command center contract passed.');
