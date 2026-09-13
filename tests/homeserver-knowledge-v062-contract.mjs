import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const service = read('includes/homeserver-knowledge-v062.php');
const api = read('api/homeserver-knowledge-v062.php');
const page = read('local-knowledge.php');
const js = read('homeserver-knowledge-v062.js');
const nav = read('includes/member-navigation.php');

const requiredOps = [
  'knowledge.collections.list',
  'knowledge.folders.list',
  'knowledge.folder.map',
  'knowledge.folder.unmap',
  'knowledge.item.write',
];
for (const op of requiredOps) {
  assert.ok(service.includes(`'${op}'`), `Cloud service must pin ${op}`);
}
assert.match(service, /VP3_HOMESERVER_KNOWLEDGE_SOURCE_SHA\s*=\s*'8e99d762aa39f8ce6165013c635175249e37f4d3'/, 'Cloud contract must pin the verified HomeServer v0.62 main SHA');
assert.match(service, /if \(\$operation !== 'knowledge\.folder\.map'\)/, 'Long-running relay path must be limited to the native folder picker operation');
assert.match(service, /VP3_HOMESERVER_KNOWLEDGE_MAP_TIMEOUT\s*=\s*660/, 'Native folder picker must preserve HomeServer v0.62 long-poll budget');
assert.match(service, /homeserver_cloud_v1200_relay_security\(\)/, 'Long-running folder picker relay must reuse the Cloud relay security guard');
assert.match(service, /'operation'\s*=>\s*\$operation[\s\S]*'bearer_token'\s*=>\s*\$homeServerToken/, 'Knowledge bridge must keep paired-app bearer server-side');
assert.match(service, /array_merge\(homeserver_cloud_v1200_permissions\(\), \['knowledge\.write'\]\)/, 'Write permission upgrade must preserve existing VP3 permissions and add only knowledge.write');

assert.doesNotMatch(api, /\$_(?:POST|GET|REQUEST)\[['"](?:path|native_path|absolute_path|filesystem_path)['"]\]/, 'Browser API must never accept a native filesystem location');
assert.doesNotMatch(page, /name=["'](?:path|native_path|absolute_path|filesystem_path)["']/, 'Local Knowledge UI must never contain a native filesystem location field');
assert.doesNotMatch(js, /(?:native_path|absolute_path|filesystem_path)\s*:/, 'Browser bundle must never send native filesystem location metadata');
assert.match(page, /Choosing a folder always happens on the paired HomeServer machine/, 'UI must explain the local picker privacy boundary');
assert.match(page, /Never sent to VP3 Cloud/, 'UI must explicitly state that the native folder location stays private');
assert.match(page, /Cloud duplicate<\/strong><span>Not created by this workspace/, 'UI must distinguish HomeServer knowledge from Cloud storage');

assert.match(api, /require_login\(\)/, 'Knowledge API must require authentication');
assert.match(api, /personal_capability_has_v242\('personal_knowledge\.access'/, 'Knowledge API must enforce account access');
assert.match(api, /personal_capability_has_v242\('personal_knowledge\.manage'/, 'Knowledge mutations must enforce manage access');
assert.match(api, /verify_csrf\(\)/, 'Knowledge mutations must require CSRF');
assert.match(api, /\$action === 'map_folder'/, 'Folder mapping must be a named browser action');
assert.match(api, /\$action === 'unmap_folder'/, 'Folder unmapping must be a named browser action');
assert.match(api, /\$action === 'write_item'/, 'Knowledge write must be a named browser action');
assert.match(api, /\$action === 'request_write_permission'/, 'Write permission upgrade must be explicit');
assert.doesNotMatch(api, /\$_POST\[['"]operation['"]\]/, 'Browser must not choose arbitrary HomeServer remote operations');
assert.match(api, /'error'\s*=>\s*'Local Knowledge could not complete that request\.'/s, 'Unexpected API failures must be generic');
assert.doesNotMatch(api, /catch \(Throwable \$e\)[\s\S]{0,220}\$e->getMessage\(\)/, 'Unexpected API failures must not expose runtime details');

assert.match(service, /function homeserver_knowledge_v062_collection/, 'Collections must use a safe projection');
assert.match(service, /function homeserver_knowledge_v062_mapping/, 'Folder mappings must use a safe projection');
assert.match(service, /'absolute_paths_exposed'\s*=>\s*false/, 'Cloud snapshot must retain HomeServer path-redaction invariant');
assert.match(service, /'cloud_stores_native_paths'\s*=>\s*false/, 'Cloud snapshot must explicitly reject native path storage');
assert.match(service, /'full_documents_returned'\s*=>\s*false/, 'Cloud projection must preserve full-document boundary');
assert.match(service, /\^source-\\d\{1,18\}\$/, 'Folder mapping IDs must be validated as opaque HomeServer IDs');
assert.doesNotMatch(service, /\$payload\[['"](?:path|native_path|absolute_path|filesystem_path)['"]\]|['"](?:path|native_path|absolute_path|filesystem_path)['"]\s*=>\s*\$/, 'Cloud service must never build or consume a native folder location');

assert.match(page, /data-local-knowledge/, 'Local Knowledge page must mount the browser controller');
assert.match(page, /knowledge\.write/, 'Local Knowledge page must explain the explicit write permission');
assert.match(page, /HomeServer Settings/, 'Local Knowledge page must provide a recovery path to HomeServer settings');
assert.match(js, /permission_required/, 'Browser must surface one-time permission upgrade when write access is missing');
assert.match(js, /source-\\d\{1,18\}/, 'Browser must validate opaque mapping IDs before deletion');
assert.match(nav, /'local_knowledge','Local Knowledge',url\('\/local-knowledge\.php'\),'identity'/, 'Local Knowledge must be discoverable from canonical member navigation');

console.log('HomeServer Knowledge v0.62 Cloud contract passed');
