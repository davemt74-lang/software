import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const agent=read('includes/cloud-hosting-agent-v130.php');
const tools=read('includes/agent-tools-v84.php');
const brain=read('includes/agent-brain-v82.php');
const bootstrap=read('includes/bootstrap.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const workflow=read('.github/workflows/cloud-hosting-v100.yml');

assert.match(agent,/CREATE TABLE IF NOT EXISTS cloud_hosting_agent_actions/);
assert.match(agent,/confirmation_token_hash CHAR\(64\)/);
assert.match(agent,/expires_at DATETIME NOT NULL/);
assert.match(agent,/execution_token CHAR\(32\)/);
assert.match(agent,/execution_expires_at DATETIME/);
assert.match(agent,/DATE_ADD\(UTC_TIMESTAMP\(\),INTERVAL 5 MINUTE\)/);
assert.match(agent,/This Hosting action is already in progress/);
assert.match(agent,/status='executing'/);
assert.match(agent,/DATE_ADD\(UTC_TIMESTAMP\(\),INTERVAL 10 MINUTE\)/);
assert.match(agent,/hash\('sha256',\$code\)/);
assert.doesNotMatch(agent,/confirmation_token\s+VARCHAR|confirmation_code\s+VARCHAR/);
assert.match(agent,/site\.create/);
assert.match(agent,/site\.state/);
assert.match(agent,/route\.provision/);
assert.match(agent,/site\.reconcile/);
assert.match(agent,/deployment\.rollback/);
assert.match(agent,/requires_confirmation'\s*=>\s*true/);
assert.match(agent,/confirm\\s\+hosting/);
assert.match(agent,/Hosting confirmation code is invalid or expired/);
assert.match(agent,/status='completed'/);
assert.match(agent,/vp3_cloud_hosting_v120_public_remote/);
assert.match(agent,/_creation_key/);
assert.match(agent,/agent:/);
assert.match(agent,/vp3_cloud_hosting_v110_provision_dns/);
assert.match(agent,/vp3_cloud_hosting_v120_rollback/);
assert.match(agent,/vp3_cloud_hosting_v120_reconcile_site/);
assert.match(agent,/hosting\.dashboard/);
assert.match(agent,/CPANEL|cPanel/);
assert.match(agent,/Never request or reveal|Never request/i);
assert.doesNotMatch(agent,/hosting_cpanel_api_token/);
assert.doesNotMatch(agent,/route_token_enc/);

assert.match(tools,/vp3_cloud_hosting_agent_v130_query/);
assert.match(brain,/vp3_cloud_hosting_agent_v130_prompt/);
assert.match(brain,/key'=>'cloud_hosting'/);
assert.match(bootstrap,/cloud-hosting-agent-v130\.php/);
assert.match(setup,/vp3_cloud_hosting_agent_v130_ensure_schema\(\$pdo\)/);
assert.match(upgrade,/vp3_cloud_hosting_agent_v130_schema_ready\(\)/);
assert.match(upgrade,/vp3_cloud_hosting_agent_v130_ensure_schema\(\$pdo\)/);

assert.match(workflow,/cloud-hosting-agent-v130\.php/);
assert.match(workflow,/cloud-hosting-v130-mysql\.php/);
assert.match(workflow,/cloud-hosting-v130-contract\.mjs/);

console.log('Cloud Hosting V1 Section 4 Agent tools contract: PASS');
