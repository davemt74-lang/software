import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const helper = fs.readFileSync(new URL('includes/agent-compute-v021.php', root), 'utf8');
const bootstrap = fs.readFileSync(new URL('includes/bootstrap.php', root), 'utf8');
const api = fs.readFileSync(new URL('api/user-agent-system-v236.php', root), 'utf8');
const ui = fs.readFileSync(new URL('account-agent-compute-v021.js', root), 'utf8');
const loader = fs.readFileSync(new URL('account-agent-settings-loader-v236.js', root), 'utf8');
const shell = fs.readFileSync(new URL('includes/workspace-sidebar-v82.php', root), 'utf8');
const css = fs.readFileSync(new URL('agent-compute-v021.css', root), 'utf8');

assert.match(bootstrap, /agent-compute-v021\.php/);
assert.match(helper, /function agent_compute_v021_reason/);
assert.match(helper, /function agent_compute_v021_state/);
assert.match(helper, /agent_compute_v020_state\(\$pdo, \$user\)/);
assert.match(helper, /route_test_supported/);
assert.match(helper, /function agent_compute_v021_route_test/);
assert.match(helper, /'inference\.status'/);
assert.match(helper, /microtime\(true\)/);
assert.match(helper, /'latency_ms'/);
assert.match(helper, /'token_spend'\s*=>\s*0/);
assert.match(helper, /'probe_kind'\s*=>\s*'read_only_status'/);
assert.match(helper, /homeserver_unavailable_fallback/);
assert.match(helper, /homeserver_required_unavailable/);
assert.match(helper, /vp3_cloud_unavailable/);
assert.match(helper, /array_replace\(\$base/);
assert.doesNotMatch(helper, /'agent\.chat'/);
assert.doesNotMatch(helper, /relay_token_enc|homeserver_token_enc|pending_claim_token_enc|bearer_token|raw_error/i);

assert.match(api, /agent_compute_v021_state\(\$pdo, \$user\)/);
assert.match(api, /test_compute_route/);
assert.match(api, /agent_compute_v021_route_test\(\$pdo, \$user\)/);
assert.match(api, /'route_test'\s*=>\s*\$routeTest/);

assert.match(ui, /Test route/);
assert.match(ui, /Read-only health check/);
assert.match(ui, /Why this route/);
assert.match(ui, /HomeServer latency/);
assert.match(ui, /fallback_used/);
assert.match(ui, /test_compute_route/);
assert.match(ui, /route_test/);
assert.match(ui, /latency_ms/);
assert.match(ui, /0 model tokens/);
assert.match(css, /\.sf-compute-health-v021/);
assert.match(css, /\.sf-compute-test-result/);
assert.match(loader, /agent-compute-v021\.css/);
assert.match(loader, /account-agent-compute-v021\.js/);
assert.match(loader, /agent-compute-v023-20260908/);
assert.match(shell, /agent-compute-v023-20260908/);

console.log('VP3 v0.21 compute control and health contract passed');
