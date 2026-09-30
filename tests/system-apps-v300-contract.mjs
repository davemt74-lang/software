import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const layer=fs.readFileSync(new URL('includes/system-apps-v300.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const router=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');

for(const fn of [
  'vp3_music_server_status_v300',
  'vp3_music_server_search_v300',
  'vp3_music_server_agent_query_v300',
  'vp3_music_server_search_terms_v300',
  'vp3_system_apps_capability_v300',
]){
  assert.match(layer,new RegExp('function '+fn+'\\b'));
}
assert.match(layer,/vp3\.music-server-cloud\.v1/);
assert.match(layer,/music_server_search'\s*=>\s*true/);
assert.match(layer,/mapped_media_sources'\s*=>\s*true/);
assert.match(layer,/homeserver_execution_authority'\s*=>\s*true/);
assert.match(layer,/cloud_execution_authority'\s*=>\s*false/);
assert.match(layer,/music\.search/);
assert.match(layer,/listen to\|music server\|music/);
assert.match(bootstrap,/system-apps-v300\.php/);
assert.match(router,/vp3_music_server_agent_query_v300/);

console.log('System Apps Section 17 Music Server contract: PASS');
