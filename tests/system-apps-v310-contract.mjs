import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const layer=fs.readFileSync(new URL('includes/system-apps-v310.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const router=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');

for(const fn of [
  'vp3_photo_library_status_v310',
  'vp3_photo_library_search_v310',
  'vp3_photo_library_search_terms_v310',
  'vp3_photo_library_agent_query_v310',
  'vp3_system_apps_capability_v310',
]){
  assert.match(layer,new RegExp('function '+fn+'\\b'));
}
assert.match(layer,/vp3\.photo-library-cloud\.v1/);
assert.match(layer,/photo_library_search'\s*=>\s*true/);
assert.match(layer,/photo_library_agent_read_projection'\s*=>\s*true/);
assert.match(layer,/mapped_media_sources'\s*=>\s*true/);
assert.match(layer,/face_recognition_enabled'\s*=>\s*false/);
assert.match(layer,/homeserver_execution_authority'\s*=>\s*true/);
assert.match(layer,/cloud_execution_authority'\s*=>\s*false/);
assert.match(layer,/photos\.search/);
assert.match(bootstrap,/system-apps-v310\.php/);
assert.match(router,/vp3_photo_library_agent_query_v310/);

console.log('System Apps Section 18 Photo Library contract: PASS');
