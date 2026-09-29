import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const diagnostics=read('includes/cloud-hosting-diagnostics-v230.php');
const agent=read('includes/cloud-hosting-agent-v130.php');
const page=read('hosting.php');
const css=read('cloud-hosting-v140.css');
const bootstrap=read('includes/bootstrap.php');
const workflow=read('.github/workflows/cloud-hosting-v100.yml');

assert.match(diagnostics,/VP3_CLOUD_HOSTING_DIAGNOSTICS_V230/);
assert.match(diagnostics,/hosting\.diagnostics\.summary/);
assert.match(diagnostics,/requests_total/);
assert.match(diagnostics,/client_error_total/);
assert.match(diagnostics,/server_error_total/);
assert.match(diagnostics,/php_failure_total/);
assert.match(diagnostics,/p95_duration_ms/);
assert.match(diagnostics,/storage_bytes/);
assert.match(diagnostics,/sqlite_bytes/);
assert.match(diagnostics,/preg_replace\('\/\[\?#\]\.\*\$\/'/);
assert.match(diagnostics,/homeserver_authoritative/);
assert.match(agent,/vp3_cloud_hosting_diagnostics_v230_summary/);
assert.match(agent,/p95_duration_ms/);
assert.match(agent,/PHP failures/);
assert.match(page,/Traffic & Runtime/);
assert.match(page,/Ask Agent Why/);
assert.match(css,/hosting-diagnostics-grid/);
assert.match(css,/hosting-request-log/);
assert.match(bootstrap,/cloud-hosting-diagnostics-v230\.php/);
assert.match(workflow,/cloud-hosting-diagnostics-v230\.php/);
assert.match(workflow,/cloud-hosting-v230-contract\.mjs/);
assert.match(workflow,/cloud-hosting-v230-mysql\.php/);

console.log('Cloud Hosting V2 Section 3 diagnostics contract: PASS');
