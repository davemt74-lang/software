import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const contacts=read('includes/homeserver-contacts-v241.php');
const shared=read('includes/homeserver-shared-agent-v210.php');
const governed=read('includes/homeserver-governed-actions-v233.php');
const routing=read('includes/homeserver-execution-routing-v220.php');
const execution=read('includes/homeserver-local-execution-v230.php');
const approvals=read('includes/homeserver-approvals-v028.php');
const legacy=read('includes/homeserver-vp3.php');
const bootstrap=read('includes/bootstrap.php');
const api=read('api/homeserver-contacts-v241.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['v2.4 contacts adapter loads before shared Agent snapshots',bootstrap.indexOf('homeserver-contacts-v241.php')<bootstrap.indexOf('homeserver-shared-agent-v210.php')],
 ['Cloud contact classes are explicit and authority-qualified',/profile_visitor/.test(contacts)&&/agent_radar/.test(contacts)&&/core_crm/.test(contacts)&&/address_book/.test(contacts)],
 ['Cloud native contact classes stay VP3 Cloud authoritative',/homeserver_federated_v240_envelope\(\s*'vp3_cloud','contacts'/.test(contacts)&&/cloud_native_crm/.test(contacts)&&/cloud_native_agent_radar/.test(contacts)],
 ['HomeServer contacts require address_book authority keys and canonical IDs',/address_book:/.test(contacts)&&/homeserver_federated_v240_canonical_id\('homeserver','contacts'/.test(contacts)],
 ['unified view combines Cloud-native and HomeServer-native contact projections',/homeserver_contacts_v241_cloud_contacts/.test(contacts)&&/homeserver_contacts_v241_homeserver_contacts/.test(contacts)&&/homeserver_contacts_v241_unified/.test(contacts)],
 ['HomeServer contact reads route through contacts.list only',/homeserver_execution_v230_execute\(\$userId,'contacts\.list'/.test(contacts)],
 ['HomeServer contact writes route through governed actions only',/homeserver_governed_v233_request\(\$userId,\$tool,\$payload\)/.test(contacts)&&!/homeserver_execution_v230_execute\(\$userId,'contacts\.(?:create|update|delete)'/.test(contacts)],
 ['governed catalog allowlists contact mutations as federated approvals',["contacts.create","contacts.update","contacts.delete"].every(x=>governed.includes("'"+x+"'"))&&/approval_mode'=>'federated'/.test(governed)],
 ['contacts.list is routeable and read-safe',routing.includes("'contacts.list'")&&execution.includes("'contacts.list'")&&/return 'contacts'/.test(execution)],
 ['contact write pairing scope is requested on modern and legacy pairing paths',approvals.includes("'contacts.write'")&&legacy.includes("'contacts.write'")],
 ['shared snapshot uses typed v2.4 contact records instead of legacy generic loops',/homeserver_contacts_v241_snapshot_records/.test(shared)&&!/profile_visitor_contact_list_v243\(\$pdo,\$userId,80\)/.test(shared)],
 ['contact mutation API requires account/chat access and CSRF',/account\.access/.test(api)&&/chat\.access/.test(api)&&/hash_equals\(csrf_token\(\)/.test(api)],
 ['contact mutation API accepts only create update delete through the adapter',/homeserver_contacts_v241_request_homeserver/.test(api)&&/\['update','delete'\]/.test(api)],
 ['runtime journey lints and executes Section 2 contract/unit tests',/homeserver-v241-contacts-continuity-contract\.mjs/.test(workflow)&&/homeserver-v241-contacts-continuity-unit\.php/.test(workflow)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.4 Section 2 contacts continuity contract: ${checks.length}/${checks.length} passed`);
