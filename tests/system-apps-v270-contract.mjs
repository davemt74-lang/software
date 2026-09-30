import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const layer=fs.readFileSync(new URL('includes/system-apps-v270.php',root),'utf8');
const bridge=fs.readFileSync(new URL('includes/system-apps-v110.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const router=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');

assert.match(layer,/vp3.media-server-cloud.v1/);
assert.match(layer,/function vp3_media_server_status_v270/);
assert.match(layer,/function vp3_media_server_search_v270/);
assert.match(layer,/function vp3_media_server_agent_query_v270/);
assert.match(layer,/media_server_write_actions's*=>s*'homeserver_confirmation_only'/);
assert.match(layer,/media_server_source_paths_exposed's*=>s*false/);
assert.match(layer,/media_server_source_bytes_exposed's*=>s*false/);
assert.match(bridge,/apps.media.status/);
assert.match(bridge,/apps.media.search/);
assert.match(bootstrap,/system-apps-v270.php/);
assert.match(router,/vp3_media_server_agent_query_v270/);

console.log('System Apps Section 14 VP3 Media Server Cloud contract: PASS');
