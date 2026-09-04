import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=path=>fs.readFileSync(path,'utf8');
const adminTheme=read('admin/admin-tech.css');
const adminJs=read('admin/admin.js');
const adminHeader=read('admin/_header.php');
const canonicalTheme=read('stonefellow-ui.css');
const headerTheme=read('chat-header-ui.css');
const memberShell=read('member-shell-v77.js');
const workspaceSidebar=read('includes/workspace-sidebar-v82.php');
const teamChatBootstrap=read('team-chat-admin-v109.js');
const voiceTheme=read('voice-profile.css');
const profileDashboard=read('profile-dashboard.css');
const profile=read('profile.php');
const dashboardJs=read('profile-dashboard.js');

// Main Admin: white/grayscale technical system is explicit and loaded before first paint.
assert.match(adminTheme,/--admin-bg:#f4f5f7/);
assert.match(adminTheme,/--admin-panel:#ffffff/);
assert.match(adminTheme,/--admin-ink:#111318/);
assert.match(adminTheme,/border-radius:2px!important/);
assert.match(adminTheme,/filter:grayscale\(1\)/);
assert.match(adminTheme,/\.admin-sidebar\{[\s\S]*background:#fff!important/);
assert.match(adminTheme,/\.metric,\.panel[\s\S]*background:#fff!important/);
assert.match(adminTheme,/\.btn\.primary\{background:#111318!important;color:#fff!important/);
assert.match(adminHeader,/theme-color" content="#f4f5f7"/);
assert.match(adminHeader,/data-admin-tech-theme/);
assert.match(adminHeader,/admin-tech\.css\?v=admin-tech-20260903/);
assert.match(adminJs,/admin-tech\.css\?v=admin-tech-20260903/);
assert.match(adminJs,/data-admin-tech-theme|dataset\.adminTechTheme/);

// One canonical authenticated theme owns Chat + user management styling.
assert.match(canonicalTheme,/Stonefellow canonical authenticated UI/);
assert.match(canonicalTheme,/--sf-bg:#f4f5f7/);
assert.match(canonicalTheme,/--sf-surface:#ffffff/);
assert.match(canonicalTheme,/--sf-ink:#111318/);
assert.match(canonicalTheme,/--bg:var\(--sf-bg\)/,'legacy Chat variables resolve to the canonical white theme');
assert.match(canonicalTheme,/\.chat-sidebar\{[\s\S]*background:var\(--sf-surface\)!important/);
assert.match(canonicalTheme,/\.chat-main\{background:var\(--sf-bg\)!important/);
assert.match(canonicalTheme,/\.chat-composer\{[\s\S]*background:#fff!important/);
assert.match(canonicalTheme,/\.account-canvas-hero,\.account-panel/,'Account styling moved into the canonical theme');
assert.match(canonicalTheme,/filter:grayscale\(1\)/,'canonical theme enforces grayscale icon treatment');
assert.match(headerTheme,/@import url\('\.\/stonefellow-ui\.css\?v=white-tech-20260904'\)/,'shared header imports canonical theme once');
assert.match(workspaceSidebar,/chat-header-ui\.css\?v=white-tech-20260904/,'all shared user workspaces load the canonical theme through the shared shell');
assert.doesNotMatch(workspaceSidebar,/account-tech\.css/,'workspace shell no longer stacks Account-only theme CSS');
assert.doesNotMatch(memberShell,/account-tech\.css/,'member shell no longer injects Account-only theme CSS');
assert.match(memberShell,/const build='white-tech-20260904'/);
assert.doesNotMatch(memberShell,/chat-tech\.css/);

// Admin + Artist Admin never create the Team Chat right rail/windows.
assert.match(teamChatBootstrap,/if \(\/\\\/admin\(\?:\\\/\|\$\)\/i\.test\(window\.location\.pathname\)\)/,'Team Chat bootstrap has an admin-path hard stop');
assert.match(teamChatBootstrap,/proof\.configSource = 'disabled-admin'/);
assert.match(teamChatBootstrap,/proof\.adminDisabled = true/);
assert.match(teamChatBootstrap,/return;[\s\S]*const explicit = window\.STONEFELLOW_TEAM_CHAT_ADMIN/,'admin guard exits before Team Chat source/runtime creation');
assert.match(teamChatBootstrap,/document\.body\?\.classList\.remove\('sf-team-rail-active'\)/,'stale admin rail layout state is cleared');

// Voice Profile shares the management visual language.
assert.match(voiceTheme,/\.voice-profile-app \.chat-sidebar\{background:#fff/);
assert.match(voiceTheme,/\.voice-card\{background:#fff;border:1px solid #d8dde3;border-radius:3px/);
assert.match(voiceTheme,/\.voice-primary-button\{background:#111318;color:#fff/);
assert.match(voiceTheme,/filter:grayscale\(1\)/);

// Profile Agent management must not reintroduce the old dark/brown card palette.
assert.match(profileDashboard,/\.sf-profile-card\{border:1px solid #d8dde3;border-radius:3px/);
assert.match(profileDashboard,/background:#fff/);
assert.doesNotMatch(profileDashboard,/#11100f|#151412|#2d2926|#6c4e32/i);

// Owner preview uses anonymous/public catalog visibility, cannot create visitor conversations, and dashboard escaping is complete.
assert.match(profile,/\$catalogViewer=\$preview\?null:\$viewer/);
assert.match(profile,/profile_public_catalog\(\$pdo,\$profile,\$catalogViewer\)/);
assert.match(profile,/\$profileToken=\$agent&&!\$preview\?/);
assert.match(profile,/if\(\$agent&&!\$preview\)/);
assert.match(profile,/Conversation sending is disabled here/);
assert.match(dashboardJs,/'\"':'&quot;'/);
assert.doesNotMatch(dashboardJs,/'\"':'&quot'[,}]/);

// Stable voice/listening runtime files are intentionally not theme dependencies.
assert.doesNotMatch(adminJs,/chat-voice\.js|artist-listening/);
assert.doesNotMatch(memberShell,/chat-voice\.js|artist-listening/);
assert.doesNotMatch(canonicalTheme,/chat-voice\.js|artist-listening/);

console.log('MANAGEMENT_TECH_THEME=PASS');