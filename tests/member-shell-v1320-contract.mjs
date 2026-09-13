import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const navigation = read('includes/member-navigation.php');
const sidebar = read('includes/main-sidebar.php');
const memberHeader = read('includes/member-header.php');
const memberShell = read('member-shell-v77.js');
const products = read('profile-commerce-products.php');
const homeserverPage = read('settings-homeserver.php');
const homeserverCss = read('homeserver-settings-v1200.css');

assert.match(navigation, /\$add\(\$links,'calendar','My Calendar',url\('\/calendar\.php'\),'agent'\)/, 'My Calendar must be an account-level navigation destination');
const calendarIndex = navigation.indexOf("$add($links,'calendar','My Calendar'");
const schedulingGateIndex = navigation.indexOf("agent_scheduling_schema_ready_v430");
assert.ok(calendarIndex > 0 && schedulingGateIndex > calendarIndex, 'My Calendar must not depend on legacy scheduling schema readiness');

assert.match(navigation, /\$add\(\$links,'knowledge','My Knowledge',url\('\/knowledge\.php'\),'identity'\)/, 'My Knowledge must be available from the canonical user menu');
assert.match(navigation, /\$add\(\$links,'memory','My Memory',url\('\/memory\.php'\),'identity'\)/, 'My Memory must be available from the canonical user menu');
assert.doesNotMatch(sidebar, /'knowledge'=>true/, 'My Knowledge must not be filtered out of the sidebar user dropdown');
assert.doesNotMatch(sidebar, /href="<\?= e\(url\('\/knowledge\.php'\)\) \?>"[^>]*><span>◆<\/span><strong>Knowledge<\/strong>/, 'Knowledge must not remain in the primary sidebar');
assert.doesNotMatch(sidebar, /href="<\?= e\(url\('\/memory\.php'\)\) \?>"[^>]*><span>◉<\/span><strong>Memory<\/strong>/, 'Memory must not remain in the primary sidebar');

assert.match(sidebar, /'calendar'=>true/, 'My Calendar must be promoted out of the footer menu');
assert.match(sidebar, /href="<\?= e\(url\('\/calendar\.php'\)\) \?>"[^>]*><span>▣<\/span><strong>My Calendar<\/strong>/, 'Primary sidebar must visibly expose My Calendar');
assert.match(sidebar, /\$mainSidebarCalendarActive/, 'My Calendar must have a canonical active state');
assert.doesNotMatch(sidebar, /href="<\?= e\(url\('\/approvals\.php'\)\) \?>"/, 'Approvals must not remain in the primary sidebar');
assert.doesNotMatch(sidebar, /<strong>Approvals<\/strong>/, 'Approvals label must be removed from the primary sidebar');

assert.match(products, /includes\/member-header\.php/, 'Profile Commerce products must render the shared member header');
assert.match(memberHeader, /data-member-shell-runtime[^>]*member-shell-v77\.js/, 'Shared member header must load the runtime that owns its avatar dropdown');
assert.match(memberShell, /window\.__VP3_MEMBER_SHELL_V77__/, 'Member shell runtime must guard against duplicate execution');
assert.match(memberShell, /profileButton\?\.addEventListener\('click'/, 'Member shell runtime must wire the profile dropdown button');
assert.match(memberShell, /profileDropdown\.hidden = !opening/, 'Member shell runtime must toggle the profile dropdown');

assert.match(homeserverPage, /class="chat-main account-chat-main"/, 'HomeServer settings must use the fixed account shell');
assert.match(homeserverPage, /class="hs-settings"/, 'HomeServer settings must expose its dedicated scroll surface');
assert.match(homeserverCss, /\.account-chat-main\{[^}]*grid-template-rows:58px minmax\(0,1fr\);[^}]*overflow:hidden/s, 'HomeServer main shell must constrain the viewport rows');
assert.match(homeserverCss, /\.account-chat-main>\.hs-settings\{[^}]*min-height:0;[^}]*overflow-x:hidden;[^}]*overflow-y:auto/s, 'HomeServer settings surface must own vertical scrolling');
assert.match(homeserverCss, /-webkit-overflow-scrolling:touch/, 'HomeServer scrolling must retain touch momentum behavior');

console.log('Member Shell v13.20 contract OK');
