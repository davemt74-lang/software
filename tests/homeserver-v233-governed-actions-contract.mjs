import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const actions=read('includes/homeserver-governed-actions-v233.php');
const routing=read('includes/homeserver-execution-routing-v220.php');
const execution=read('includes/homeserver-local-execution-v230.php');
const approvals=read('includes/homeserver-approvals-v028.php');
const api=read('api/homeserver-governed-actions-v233.php');
const bootstrap=read('includes/bootstrap.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['governed action layer loads after compute',bootstrap.indexOf('homeserver-compute-v232.php')<bootstrap.indexOf('homeserver-governed-actions-v233.php')],
 ['allowlist contains memory task file and physical-device writes',["memory.write","tasks.create","files.update","files.delete","devices.command"].every(x=>actions.includes("'"+x+"'"))],
 ['file mutations and physical device commands are local-owner approval only',/files\.update'.*local_owner/s.test(actions)&&/files\.delete'.*local_owner/s.test(actions)&&/devices\.command'.*local_owner/s.test(actions)],
 ['memory/task actions preserve federated review support',/memory\.write'.*federated/s.test(actions)&&/tasks\.create'.*federated/s.test(actions)],
 ['action proposals execute only through canonical HomeServer tool.execute',/homeserver_execution_v230_execute\(\$userId,'tool\.execute'/.test(actions)],
 ['governance list status approve and deny are explicitly routeable',["action.list","action.status","action.approve","action.deny"].every(x=>routing.includes("'"+x+"'"))],
 ['governance operations have their own execution domain',/str_starts_with\(\$op,'action\.'\).*governance/.test(execution)],
 ['writes remain ineligible for Cloud fallback',/write_or_physical/.test(execution)&&/fallbackAllowed=in_array/.test(execution)&&!/files\.update'.*fallbackAllowed=true/s.test(execution)],
 ['local-owner actions are blocked before remote approve',/local_owner_only/.test(actions)&&/local_owner_required/.test(actions)&&/action requires approval from local HomeServer owner control/.test(actions)],
 ['existing approval federation prefers HTTPS when paired',/homeserver_https_v1300_status/.test(approvals)&&/homeserver_governed_v233_list/.test(approvals)&&/homeserver_governed_v233_review/.test(approvals)],
 ['pairing permission set includes local file and device scopes',["files.read","files.write","devices.read","devices.control"].every(x=>approvals.includes("'"+x+"'"))],
 ['existing HTTPS pairings use owner-approved permission upgrade rather than silent grants',/homeserver_https_v1300_remote_operation\(\$userId,'pair\.request'/.test(approvals)&&/homeserver_https_v1300_remote_operation\(\$userId,'pair\.status'/.test(approvals)&&/pending_claim_token_enc/.test(approvals)],
 ['legacy custom-relay approval path remains available',/homeserver_vp3_remote_operation/.test(approvals)&&/homeserver_approvals_v028_credentials/.test(approvals)],
 ['governed action API requires login and CSRF',/require_login\(\)/.test(api)&&/verify_csrf\(\)/.test(api)],
 ['browser API cannot accept arbitrary tool names without server allowlist',/homeserver_governed_v233_request/.test(api)&&/not allowlisted for governed execution/.test(actions)],
 ['runtime journey gates Section 4 contract and unit tests',/homeserver-v233-governed-actions-contract\.mjs/.test(workflow)&&/homeserver-v233-governed-actions-unit\.php/.test(workflow)],
];
for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.3 Section 4 governed actions contract: ${checks.length}/${checks.length} passed`);
