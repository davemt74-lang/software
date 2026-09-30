import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const layer=fs.readFileSync(new URL('includes/system-apps-v230.php',root),'utf8');
const bridge=fs.readFileSync(new URL('includes/system-apps-v110.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const router=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');

assert.match(layer,/vp3\.user-app-development-workspace\.v1/);
assert.match(layer,/function vp3_user_app_workspace_status_v230/);
assert.match(layer,/function vp3_user_app_workspace_query_v230/);
assert.match(layer,/cloud_source_repository'\s*=>\s*false/);
assert.match(layer,/source_content_exposed'\s*=>\s*false/);
assert.match(layer,/workspace_build_uses_canonical_release_engine'\s*=>\s*true/);
assert.match(layer,/workspace_preview_uses_canonical_runtime'\s*=>\s*true/);
assert.match(bridge,/apps\.user\.workspace\.status/);
assert.match(bootstrap,/system-apps-v230\.php/);
assert.match(router,/vp3_user_app_workspace_query_v230/);

console.log('System Apps Section 10 Cloud Development Workspace integration: PASS');
