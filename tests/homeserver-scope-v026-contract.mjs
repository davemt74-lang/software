import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root=path.resolve(import.meta.dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');

const bootstrap=read('includes/bootstrap.php');
const scope=read('includes/homeserver-scope-v026.php');
const compute=read('includes/agent-compute-v023.php');
const api=read('api/user-agent-system-v236.php');
const ui=read('account-homeserver-capabilities-v024.js');
const loader=read('account-agent-settings-loader-v236.js');

assert.match(bootstrap,/homeserver-scope-v026\.php/,'bootstrap must load v0.26 scope resolver');
assert.match(scope,/app\.scopes\.v1/,'scope resolver must require the advertised scope feature');
assert.match(scope,/'tools\.list'/,'scope resolver must reuse authenticated tools.list handshake');
assert.match(scope,/homeserver_scope_v026_blocks_cloud/,'scope resolver must expose a cloud-block decision');
assert.match(scope,/memory_prefix_count/,'public scope must expose counts rather than private Memory prefixes');
assert.match(scope,/plugin_count/,'public scope must expose Plugin restriction counts');

assert.match(compute,/homeserver_scope_v026_fetch\(\$userId,false\)/,'effective compute policy must consult HomeServer scope');
assert.match(compute,/'homeserver_scope'/,'compute provenance must identify scope narrowing');
assert.match(compute,/'effective_preference'\]\s*=\s*'homeserver_only'/,'cloud-disabled scope must resolve to HomeServer-only');

assert.match(api,/homeserver_scope_v026_attach_state/,'Agent settings state must include HomeServer scope');
assert.match(api,/homeserver_scope_v026_fetch\(\(int\)\$user\['id'\], true\)/,'HomeServer refresh must force-refresh authenticated scope');
assert.match(ui,/VP3 Access Boundary/,'Agents & Data must show the VP3 wrapper boundary');
assert.match(ui,/HomeServer-enforced scope/,'scope UI must identify HomeServer as authority');
assert.match(ui,/memory_prefix_count/,'scope UI may show Memory restriction count');
assert.doesNotMatch(ui,/memory_key_prefixes/,'scope UI must not render private Memory prefix values');
assert.match(loader,/agent-compute-v026-scope-20260908/,'account loader must cache-bust v0.26 scope UI');

console.log('VP3 v0.26 HomeServer scope integration contract passed');
