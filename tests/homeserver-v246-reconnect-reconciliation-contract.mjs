import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const federation=read('includes/homeserver-federated-data-v240.php');
const reconcile=read('includes/homeserver-reconciliation-v246.php');
const shared=read('includes/homeserver-shared-agent-v210.php');
const brain=read('includes/agent-brain-context-v142.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const settings=read('homeserver-settings-v1210.js');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');
const statusBlock=shared.slice(shared.indexOf('function homeserver_shared_v210_reconcile_status'));

const checks=[
 ['Section 7 reconciliation loads after federation and before shared context',
  bootstrap.includes('homeserver-reconciliation-v246.php') &&
  bootstrap.indexOf('homeserver-federated-data-v240.php') < bootstrap.indexOf('homeserver-reconciliation-v246.php') &&
  bootstrap.indexOf('homeserver-reconciliation-v246.php') < bootstrap.indexOf('homeserver-shared-agent-v210.php')],
 ['fresh setup installs reconciliation schema',
  /homeserver_reconciliation_v246_ensure_schema\(\$pdo\)/.test(setup)],
 ['upgrade readiness and execution require reconciliation schema',
  /homeserver_reconciliation_v246_schema_ready\(\)/.test(upgrade) &&
  /homeserver_reconciliation_v246_ensure_schema\(\$pdo\)/.test(upgrade)],
 ['federation preserves native record revisions',
  /suppliedRevision/.test(federation) && /Federated record revision must be a SHA-256 value/.test(federation)],
 ['federation exposes mirror tombstone helper',
  /function homeserver_federated_v240_mark_tombstone/.test(federation) &&
  /tombstoned=1/.test(federation)],
 ['Section 7 has durable reconciliation state and run ledgers',
  /homeserver_reconciliation_state_v246/.test(reconcile) &&
  /homeserver_reconciliation_runs_v246/.test(reconcile)],
 ['reconciliation distinguishes full and filtered snapshots',
  /snapshot_mode/.test(reconcile) && /full/.test(reconcile) && /filtered/.test(reconcile)],
 ['snapshot coverage is explicit and validated',
  /covered_datasets/.test(reconcile) && /coverage is empty/.test(reconcile) &&
  /missing covered datasets/.test(reconcile)],
 ['snapshot rows are prevalidated before mirror mutation',
  reconcile.indexOf('Prevalidate every row before touching mirrors') >= 0 &&
  reconcile.indexOf('Prevalidate every row before touching mirrors') < reconcile.indexOf('homeserver_federated_v240_observe')],
 ['duplicate authority keys and mixed authorities fail closed',
  /duplicate authority keys/.test(reconcile) && /mixed native authorities/.test(reconcile)],
 ['filtered snapshots never tombstone absence',
  /if\(\$mode==='full'\)/.test(reconcile) &&
  /homeserver_federated_v240_mark_tombstone/.test(reconcile)],
 ['successful full reconciliation clears required state',
  /needs_reconciliation=0/.test(reconcile) && /last_reconciled_at/.test(reconcile)],
 ['failed reconciliation remains required and records bounded error',
  /needs_reconciliation=1/.test(reconcile) && /status='failed'/.test(reconcile)],
 ['automatic retry is bounded',
  /homeserver_reconciliation_v246_should_retry/.test(reconcile) &&
  /minimumSeconds=30/.test(reconcile) &&
  /max\(10,min\(300,\$minimumSeconds\)\)/.test(reconcile)],
 ['shared snapshots declare full/filtered mode and explicit coverage',
  /'snapshot_mode'=>\$query===''\?'full':'filtered'/.test(shared) &&
  /'covered_datasets'=>\['memory','knowledge','contacts','tasks','calendar','files','notifications'\]/.test(shared)],
 ['reconnect forces full reconciliation before filtered context',
  /homeserver_shared_v210_reconcile_full/.test(shared) &&
  /if\(!empty\(\$reconciliation\['needs_reconciliation'\]\)\)/.test(shared)],
 ['bidirectional full reconciliation confirmation is required',
  /HomeServer did not confirm full Cloud reconciliation/.test(shared) &&
  /Cloud did not complete HomeServer reconciliation/.test(shared)],
 ['reconciliation failure suppresses stale HomeServer context',
  /return \$cache\[\$cacheKey\]=null/.test(shared) &&
  /homeserver\.reconciliation_failed/.test(shared)],
 ['reconnect event precedes full reconcile and continuity-restored notification',
  statusBlock.indexOf("HomeServer reconnected — reconciling") >= 0 &&
  statusBlock.indexOf("HomeServer reconnected — reconciling") < statusBlock.indexOf('homeserver_shared_v210_reconcile_full($userId)') &&
  shared.indexOf('homeserver_shared_v210_reconcile_full($userId)') < shared.indexOf('HomeServer continuity restored')],
 ['Agent Brain receives reconciliation currentness without raw error text',
  /homeserver:reconciliation/.test(brain) &&
  /HomeServer-backed context should be treated as stale/.test(brain) &&
  !/last_error.*\$context\[\]/.test(brain)],
 ['HomeServer status notifications are voice eligible',
  /\$type==='homeserver_connection_update'/.test(presentation)],
 ['Settings shows reconciling state and waits for continuity completion',
  /reconciling/.test(settings) &&
  /continuity reconciled successfully/.test(settings) &&
  /reconciliation pending/.test(settings)],
 ['Section 7 runtime journey gates are wired',
  /homeserver-v246-reconnect-reconciliation-contract\.mjs/.test(workflow) &&
  /homeserver-v246-reconnect-reconciliation-unit\.php/.test(workflow)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.4 Section 7 reconnect reconciliation contract: ${checks.length}/${checks.length} passed`);
