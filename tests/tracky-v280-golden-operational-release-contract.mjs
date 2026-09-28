import fs from 'node:fs';
import assert from 'node:assert/strict';

const inc=fs.readFileSync('includes/tracky-release-hardening-v280.php','utf8');
const api=fs.readFileSync('api/tracky-release-v280.php','utf8');
const loader=fs.readFileSync('includes/tracky-cloud-v270.php','utf8');
const page=fs.readFileSync('tracky.php','utf8');
const packageWorkflow=fs.readFileSync('.github/workflows/production-deploy-package.yml','utf8');
const recovery=fs.readFileSync('tools/run_recovery_baseline.py','utf8');

assert.match(inc,/physical_federation_v280_release_hardening\.v1/);
assert.match(inc,/'final_section'=>10/);
assert.match(inc,/'golden_scenarios'=>24/);
assert.match(inc,/'homeserver_schema_version'=>53/);
assert.match(inc,/'release_ready'=>!in_array\(false,\$checks,true\)/);
assert.match(inc,/'cloud_read_only'=>true/);
assert.match(inc,/'remote_command_execution'=>false/);
assert.match(inc,/'authority_mutation'=>false/);
assert.match(inc,/'cloud_can_mark_recovered'=>false/);
assert.match(inc,/'cloud_can_assign_authority'=>false/);
assert.match(inc,/'agent_execution_allowed'=>false/);
assert.match(inc,/'split_brain_allowed'=>false/);
assert.match(inc,/revocation-wins-over-cached-grants/);
assert.match(inc,/production-packages-exclude-local-secrets-and-runtime-data/);

assert.match(api,/\$_SERVER\['REQUEST_METHOD'\]!=='GET'/);
assert.doesNotMatch(api,/tracky_v280_fgo_create_request|execute|approve|grant_permission|assign_authority/);
assert.match(loader,/tracky-release-hardening-v280\.php/);
assert.match(page,/V2\.80 Release Hardening/);
assert.match(page,/24 golden operational scenarios/);
assert.match(packageWorkflow,/includes\/tracky-release-hardening-v280\.php/);
assert.match(packageWorkflow,/api\/tracky-release-v280\.php/);assert.match(packageWorkflow,/tracky_recovery_authority/);
assert.match(recovery,/tracky-v280-golden-operational-release-contract\.mjs/);
console.log('TRACKY_V280_GOLDEN_OPERATIONAL_RELEASE_CONTRACT=PASS');
