import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root=process.cwd();
const workflowDir=path.join(root,'.github','workflows');
const archiveDir=path.join(root,'.github','workflow-archive');

const activeExpected=[
  "agent-event-infrastructure-v1920.yml",
  "browser-companion-transaction-control-v2280.yml",
  "client-release-intelligence-v100.yml",
  "cognitive-loop-release-v2360.yml",
  "homeserver-runtime-journey.yml",
  "package-entitlements-v340.yml",
  "profile-commerce-secure-file-v1130.yml",
  "public-funnel-onboarding-continuity.yml",
  "recovery-baseline.yml",
  "team-workspaces-v350.yml",
  "video-meetings-v18230.yml",
  "production-deploy-package.yml"
];

const yamlFiles=dir=>fs.readdirSync(dir).filter(name=>/\.ya?ml$/i.test(name)).sort();
const active=yamlFiles(workflowDir);
const archived=yamlFiles(archiveDir);

assert.deepEqual(active,[...activeExpected].sort(),'active GitHub workflow set drifted from the consolidated CI policy');
assert.equal(active.length,12,'normal CI should expose exactly 12 active workflow definitions');
assert.ok(archived.length>=138,'historical workflow archive unexpectedly lost phase definitions');

const deploy=fs.readFileSync(path.join(workflowDir,'production-deploy-package.yml'),'utf8');
assert.doesNotMatch(deploy,/\npull_request:/,'Production Deploy must not build artifacts for pull requests');
assert.match(deploy,/push:\s*\n\s*branches:\s*\[main\]/,'Production Deploy must run on merged main');

const browser=fs.readFileSync(path.join(workflowDir,'browser-companion-transaction-control-v2280.yml'),'utf8');
assert.doesNotMatch(browser,/\n\s{2}package:\s*\n/,'historical v22.80 workflow must not remain an artifact authority');
assert.match(browser,/browser-companion-transaction-release-gate-v2280\.mjs/,'Browser cumulative regression release gate must remain');

for(const name of archived){
  assert.ok(!active.includes(name),`historical workflow ${name} must not be both active and archived`);
}

console.log(`CI_WORKFLOW_CONSOLIDATION=PASS active=${active.length} archived=${archived.length}`);
