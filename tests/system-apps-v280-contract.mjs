import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const layer=fs.readFileSync(new URL('includes/system-apps-v280.php',root),'utf8');
const bridge=fs.readFileSync(new URL('includes/system-apps-v110.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const router=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');

assert.match(layer,/vp3\.video-editor-cloud\.v1/);
assert.match(layer,/function vp3_video_editor_status_v280/);
assert.match(layer,/function vp3_video_editor_projects_v280/);
assert.match(layer,/function vp3_video_editor_agent_actions_v280/);
assert.match(layer,/function vp3_video_editor_invoke_v280/);
assert.match(layer,/function vp3_video_editor_agent_query_v280/);
assert.match(layer,/video_editor_agent_complete_control'\s*=>\s*true/);
assert.match(layer,/app_agent_manifest_control'\s*=>\s*true/);
assert.match(layer,/app_agent_destructive_confirmation'\s*=>\s*true/);
assert.match(layer,/homeserver_app_execution_authority'\s*=>\s*true/);
assert.match(bridge,/apps\.video\.status/);
assert.match(bridge,/apps\.video\.projects/);
assert.match(bridge,/apps\.video\.project/);
assert.match(bridge,/apps\.video\.agent\.actions/);
assert.match(bridge,/apps\.video\.agent\.invoke/);
assert.match(bootstrap,/system-apps-v280\.php/);
assert.match(router,/vp3_video_editor_agent_query_v280/);

console.log('System Apps Section 15 VP3 Video Editor + complete App Agent control contract: PASS');
