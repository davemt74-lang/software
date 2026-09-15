import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const page = read('profile-commerce-products.php');
const shellCss = read('profile-commerce-products-shell-v1310.css');
const sidebar = read('includes/main-sidebar.php');
const navigation = read('includes/member-navigation.php');

assert.match(page, /class="chat-main pc-main"/, 'Profile Commerce must use the fixed member shell main region');
assert.match(page, /class="pc-canvas"/, 'Profile Commerce must expose a dedicated content canvas');
assert.match(page, /workspace-sidebar-v82\.php/, 'Profile Commerce must use the canonical VP3 sidebar');

assert.match(shellCss, /\.pc-main\s*\{[^}]*min-height:0!important;[^}]*grid-template-rows:58px minmax\(0,1fr\)!important;[^}]*overflow:hidden/s, 'Profile Commerce main must be constrained to the member shell');
assert.match(shellCss, /\.pc-main>\.pc-canvas\s*\{[^}]*min-height:0;[^}]*overflow-x:hidden;[^}]*overflow-y:auto/s, 'Profile Commerce canvas must own vertical scrolling');
assert.match(shellCss, /-webkit-overflow-scrolling:touch/, 'Profile Commerce scroll region must retain touch momentum scrolling');

assert.match(sidebar, /profile-commerce-products-shell-v1310\.css/, 'Canonical sidebar must load the Profile Commerce shell fix');

// Entitlement/schema authority lives in canonical member navigation. The consolidated
// sidebar consumes those permitted links instead of duplicating Commerce gates.
assert.match(navigation, /agent_commerce_schema_ready_v800\(\)/, 'Profile Commerce must remain gated by Commerce schema readiness');
assert.match(navigation, /\$add\(\$links,'profile_commerce','Profile Commerce',url\('\/profile-commerce-products\.php'\),'agent'\)/, 'Canonical member navigation must route Profile Commerce products');
assert.match(sidebar, /\$mainSidebarPrimaryOrder = \['home','chat','profile_agent','messages','contacts','knowledge','transcriptions','calendar','scheduling','profile_commerce','team'\]/, 'Profile Commerce must remain a canonical primary destination after Agent Home');
assert.match(sidebar, /'profile_commerce'=>'Products'/, 'Primary sidebar must label the Profile Commerce workspace Products');
assert.match(sidebar, /member_navigation_menu_links\(\$mainSidebarUser\)/, 'Sidebar must consume canonical permission-aware navigation');
assert.match(sidebar, /\$mainSidebarPrimaryKeys = array_fill_keys\(\$mainSidebarPrimaryOrder, true\)/, 'Profile Commerce must be excluded from duplicate footer navigation through the primary key set');
assert.match(sidebar, /data-vp3-nav-key=/, 'Canonical sidebar links must expose keyed active-state metadata');
assert.doesNotMatch(sidebar, /\$mainSidebarProductsActive/, 'Legacy Profile Commerce-specific active-state boolean must not return');

console.log('Profile Commerce products shell v13.10 contract OK');
