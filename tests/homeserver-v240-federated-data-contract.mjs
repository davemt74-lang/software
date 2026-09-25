import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const fed=read('includes/homeserver-federated-data-v240.php');
const shared=read('includes/homeserver-shared-agent-v210.php');
const bootstrap=read('includes/bootstrap.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const routing=read('includes/homeserver-execution-routing-v220.php');
const execution=read('includes/homeserver-local-execution-v230.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['federated data version is 2.4',/VP3_HOMESERVER_FEDERATED_DATA_VERSION='2\.4'/.test(fed)],
 ['federation layer loads before shared Agent fabric',bootstrap.indexOf('homeserver-federated-data-v240.php')<bootstrap.indexOf('homeserver-shared-agent-v210.php')],
 ['setup and upgrade install the v2.4 federation schema',/homeserver_federated_v240_ensure_schema\(\$pdo\)/.test(setup)&&/homeserver_federated_v240_ensure_schema\(\$pdo\)/.test(upgrade)],
 ['upgrade readiness requires the federation schema',/homeserver_federated_v240_schema_ready\(\)/.test(upgrade)],
 ['federation metadata is account scoped and never a native record table',/user_id INT UNSIGNED NOT NULL/.test(fed)&&/homeserver_federated_records/.test(fed)&&!/INSERT INTO (?:knowledge_items|agent_memory_items|vp3_agent_contacts|notifications)/.test(fed)],
 ['canonical identity derives only from authority source dataset and authority key',/hash\('sha256',\$source\.'\|'\.\$name\.'\|'\.\$key\)/.test(fed)],
 ['native authority and mirror-only rules are explicit',/native_source_remains_authoritative/.test(fed)&&/remote_records_are_mirrors/.test(fed)&&/no_cross_database_id_writes/.test(fed)&&/authority_wins/.test(fed)],
 ['mirror ledger and per-dataset cursors are durable',/homeserver_federated_records/.test(fed)&&/homeserver_federated_cursors/.test(fed)&&/last_success_at/.test(fed)],
 ['shared Cloud snapshots carry canonical federation identity',/canonical_id/.test(shared)&&/authority_key/.test(shared)&&/record_revision/.test(shared)&&/federation_version/.test(shared)],
 ['shared exchange observes Cloud and HomeServer snapshots without native-table writes',/homeserver_federated_v240_observe_snapshot\(\$userId,\$cloud,'vp3_cloud'\)/.test(shared)&&/homeserver_federated_v240_observe_snapshot\(\$userId,\$home,'vp3_cloud'\)/.test(shared)],
 ['federation.registry is explicitly routeable and read-safe',routing.includes("'federation.registry'")&&execution.includes("'federation.registry'")&&/continuity/.test(execution)],
 ['Cloud can validate the HomeServer federation registry',/homeserver_federated_v240_remote_registry/.test(fed)&&/federation\.registry/.test(fed)],
 ['runtime journey lints and executes Section 1 contract/unit tests',/homeserver-v240-federated-data-contract\.mjs/.test(workflow)&&/homeserver-v240-federated-data-unit\.php/.test(workflow)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.4 Section 1 federated data contract: ${checks.length}/${checks.length} passed`);
