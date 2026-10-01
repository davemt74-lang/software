import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const layer=fs.readFileSync(new URL('includes/system-apps-v330.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const router=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');

for(const fn of [
  'vp3_media_processor_status_v330',
  'vp3_media_processor_jobs_v330',
  'vp3_media_processor_brain_context_v330',
  'vp3_media_processor_agent_query_v330',
  'vp3_system_apps_capability_v330',
]){
  assert.match(layer,new RegExp('function '+fn+'\\b'));
}
assert.match(layer,/vp3\.media-processor-cloud\.v1/);
assert.match(layer,/processor\.brain-context/);
assert.match(layer,/ffmpeg_managed_by_homeserver/);
assert.match(layer,/HomeServer Agent action path/);
assert.match(layer,/Cloud does not run FFmpeg/);
assert.match(layer,/source_paths_exposed'\s*=>\s*false/);
assert.match(layer,/output_paths_exposed'\s*=>\s*false/);
assert.match(layer,/media_processor_media_server_handoff'\s*=>\s*true/);
assert.match(layer,/media_processor_download_manager_handoff'\s*=>\s*true/);
assert.match(layer,/media_processor_video_editor_handoff'\s*=>\s*true/);
assert.match(layer,/media_processor_cloud_execution'\s*=>\s*false/);
assert.match(layer,/media_processor_homeserver_execution'\s*=>\s*true/);
assert.match(bootstrap,/system-apps-v330\.php/);
assert.match(router,/vp3_media_processor_agent_query_v330/);

console.log('System Apps Section 20 Media Processor contract: PASS');
