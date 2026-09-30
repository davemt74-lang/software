import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const governance=fs.readFileSync(new URL('includes/system-apps-v210.php',root),'utf8');
const bridge=fs.readFileSync(new URL('includes/system-apps-v110.php',root),'utf8');
const actions=fs.readFileSync(new URL('includes/system-apps-v160.php',root),'utf8');
const cards=fs.readFileSync(new URL('includes/system-apps-v190.php',root),'utf8');
const router=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');
const page=fs.readFileSync(new URL('apps.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');

assert.match(governance,/vp3\.system-app-permission-governance\.v1/);
assert.match(governance,/function vp3_system_apps_permission_status_v210/);
assert.match(governance,/function vp3_system_apps_permission_set_v210/);
assert.match(governance,/function vp3_system_apps_permission_query_v210/);
assert.match(governance,/permission_expansion_requires_review/);
assert.match(governance,/silent_permission_expansion'\s*=>\s*false/);
assert.match(governance,/confirmed_agent_permission_changes'\s*=>\s*true/);
assert.match(bridge,/apps\.system\.permissions\.status/);
assert.match(bridge,/apps\.system\.permissions\.set/);
assert.match(actions,/'permission\.set'/);
assert.match(actions,/agent_permission_grant_revoke'\s*=>\s*true/);
assert.match(actions,/hardware\.camera/);
assert.match(actions,/network\.external/);
assert.match(cards,/'permission\.set'=>'Change permission'/);
assert.match(router,/vp3_system_apps_permission_query_v210/);
assert.match(page,/vp3_system_apps_permission_set_v210/);
assert.match(page,/New permissions introduced by an update default to denied and require review/);
assert.match(bootstrap,/system-apps-v210\.php/);

console.log('System Apps Section 8 Cloud permission capability governance: PASS');
