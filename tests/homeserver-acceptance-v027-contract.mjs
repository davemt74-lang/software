import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root=path.resolve(import.meta.dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');

const bootstrap=read('includes/bootstrap.php');
const helper=read('includes/homeserver-acceptance-v027.php');
const api=read('api/homeserver-acceptance-v027.php');
const ui=read('account-homeserver-acceptance-v027.js');
const css=read('homeserver-acceptance-v027.css');
const loader=read('account-agent-settings-loader-v236.js');
const shell=read('includes/workspace-sidebar-v82.php');
const runner=read('tools/run_recovery_baseline.py');

assert.match(bootstrap,/homeserver-acceptance-v027\.php/,'bootstrap must load production acceptance probe');
assert.match(helper,/probe_kind'\s*=>\s*'read_only_acceptance'/,'probe must identify itself as read-only');
assert.match(helper,/token_spend'\s*=>\s*0/,'acceptance probe must spend zero model tokens');
assert.match(helper,/'inference\.status'/,'acceptance must test inference status');
assert.match(helper,/'tools\.list'/,'acceptance must test authenticated tool registry');
assert.match(helper,/'skills\.list'/,'acceptance must test skills registry');
assert.match(helper,/'plugins\.list'/,'acceptance must test plugin registry');
assert.doesNotMatch(helper,/'agent\.chat'|'memory\.read'|'knowledge\.search'/,'acceptance probe must not send prompts or retrieve private Memory/Knowledge contents');
assert.doesNotMatch(helper,/relay_token_enc|homeserver_token_enc|bearer_token|raw_error/i,'acceptance output must not expose credentials or raw errors');
assert.match(helper,/production_ready/,'acceptance probe must produce a production readiness verdict');
assert.match(helper,/latency_ms/,'acceptance probe must report bounded latency');

assert.match(api,/REQUEST_METHOD/,'acceptance API must enforce request method');
assert.match(api,/POST required/,'acceptance API must be POST-only');
assert.match(api,/hash_equals\(csrf_token\(\), \$csrf\)/,'acceptance API must enforce CSRF');
assert.match(api,/account\.access/,'acceptance API must require account permission');
assert.match(api,/chat\.access/,'acceptance API must require chat permission');
assert.match(api,/Cache-Control: no-store/,'acceptance API must not be cached');

assert.match(ui,/Production Acceptance/,'account UI must identify production acceptance');
assert.match(ui,/HomeServer connection test/,'account UI must provide one connection-test surface');
assert.match(ui,/Run connection test/,'acceptance must be user-triggered rather than automatic');
assert.match(ui,/Read-only · 0 model tokens/,'UI must explain zero-token behavior');
assert.match(ui,/No Agent prompt or private Memory\/Knowledge content is read/,'UI must explain privacy boundary');
assert.doesNotMatch(ui,/memory_key_prefixes|plugin_keys|relay-secret|home-secret|bearer_token/i,'browser UI must not render private scope values or credentials');
assert.match(css,/sf-hs-acceptance-grid/,'acceptance UI must have responsive result layout');
assert.match(css,/@media\(max-width:640px\)/,'acceptance UI must support mobile layout');

for(const marker of ['agent-compute-v023-20260908','agent-compute-v024-20260908','agent-compute-v026-scope-20260908','agent-compute-v027-acceptance-20260908']){
  assert.match(loader,new RegExp(marker.replaceAll('.','\\.')),'loader must preserve '+marker);
  assert.match(shell,new RegExp(marker.replaceAll('.','\\.')),'server loader URL must preserve '+marker);
}
assert.match(loader,/homeserver-acceptance-v027\.css/,'loader must include v0.27 acceptance CSS');
assert.match(loader,/account-homeserver-acceptance-v027\.js/,'loader must include v0.27 acceptance UI');
assert.match(runner,/tests\/homeserver-acceptance-v027\.php/,'Recovery Baseline must run v0.27 PHP regression');
assert.match(runner,/tests\/homeserver-acceptance-v027-contract\.mjs/,'Recovery Baseline must run v0.27 integration contract');

console.log('VP3 v0.27 HomeServer production acceptance contract passed');
