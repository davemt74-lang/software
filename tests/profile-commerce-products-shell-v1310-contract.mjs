import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const page = read('profile-commerce-products.php');
const shellCss = read('profile-commerce-products-shell-v1310.css');
const sidebar = read('includes/main-sidebar.php');

assert.match(page, /class="chat-main pc-main"/, 'Profile Commerce must use the fixed member shell main region');
assert.match(page, /class="pc-canvas"/, 'Profile Commerce must expose a dedicated content canvas');
assert.match(page, /workspace-sidebar-v82\.php/, 'Profile Commerce must use the canonical VP3 sidebar');

assert.match(shellCss, /\.pc-main\s*\{[^}]*min-height:0!important;[^}]*grid-template-rows:58px minmax\(0,1fr\)!important;[^}]*overflow:hidden/s, 'Profile Commerce main must be constrained to the member shell');
assert.match(shellCss, /\.pc-main>\.pc-canvas\s*\{[^}]*min-height:0;[^}]*overflow-x:hidden;[^}]*overflow-y:auto/s, 'Profile Commerce canvas must own vertical scrolling');
assert.match(shellCss, /-webkit-overflow-scrolling:touch/, 'Profile Commerce scroll region must retain touch momentum scrolling');

assert.match(sidebar, /profile-commerce-products-shell-v1310\.css/, 'Canonical sidebar must load the Profile Commerce shell fix');
assert.match(sidebar, /agent_commerce_schema_ready_v800\(\)/, 'My Products link must only appear when Commerce schema is ready');
assert.match(sidebar, /href="<\?= e\(url\('\/profile-commerce-products\.php'\)\) \?>"/, 'Primary sidebar must link to Profile Commerce products');
assert.match(sidebar, /<strong>My Products<\/strong>/, 'Primary sidebar must label the products workspace My Products');
assert.match(sidebar, /\$mainSidebarProductsActive/, 'My Products must have an explicit active state');
assert.match(sidebar, /'profile_commerce'=>true/, 'Profile Commerce must be removed from the duplicate footer link set once promoted to primary navigation');

console.log('Profile Commerce products shell v13.10 contract OK');
