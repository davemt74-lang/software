import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const layer=fs.readFileSync(new URL('includes/system-apps-v320.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const router=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');

for(const fn of [
  'vp3_download_manager_status_v320',
  'vp3_download_manager_list_v320',
  'vp3_download_manager_brain_context_v320',
  'vp3_download_manager_agent_query_v320',
  'vp3_system_apps_capability_v320',
]){
  assert.match(layer,new RegExp('function '+fn+'\\b'));
}
assert.match(layer,/vp3\.download-manager-cloud\.v1/);
assert.match(layer,/downloads\.brain-context/);
assert.match(layer,/source_urls_exposed'\s*=>\s*false/);
assert.match(layer,/filesystem_paths_exposed'\s*=>\s*false/);
assert.match(layer,/homeserver_execution_authority'\s*=>\s*true/);
assert.match(layer,/cloud_execution_authority'\s*=>\s*false/);
assert.match(layer,/HomeServer Agent control\/approval path/);
assert.match(bootstrap,/system-apps-v320\.php/);
assert.match(router,/vp3_download_manager_agent_query_v320/);

console.log('System Apps Section 19 Download Manager contract: PASS');
