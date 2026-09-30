import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const nav=fs.readFileSync(new URL('includes/member-navigation.php',root),'utf8');
const page=fs.readFileSync(new URL('tracky.php',root),'utf8');

assert.match(nav,/vp3_plugin_effective_state_v360\(\$pdo,\$user,'tracky'\)/);
assert.match(nav,/\$add\(\$links,'tracky'/);
assert.match(nav,/Tracky — Enable/);
assert.match(nav,/url\('\/tracky\.php'\)/);

assert.match(page,/TRACKY OVERVIEW/);
assert.match(page,/Open Physical World/);
assert.match(page,/Physical Network/);
assert.match(page,/Ask Agent/);
assert.match(page,/workspaceSidebarActive='tracky'/);
assert.match(page,/perception and physical authority on HomeServer/i);
assert.match(page,/governed semantic mirror/i);
assert.match(page,/\.tracky-main\{[^}]*min-height:0[^}]*overflow-y:auto[^}]*overflow-x:hidden/);

console.log('Tracky navigation and overview contract: PASS');
