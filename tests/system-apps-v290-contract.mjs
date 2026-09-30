import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const layer=fs.readFileSync(new URL('includes/system-apps-v290.php',root),'utf8');
const bridge=fs.readFileSync(new URL('includes/system-apps-v110.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const router=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');

for(const fn of [
  'vp3_app_control_status_v290',
  'vp3_app_control_actions_v290',
  'vp3_app_control_settings_v290',
  'vp3_app_control_hosting_v290',
  'vp3_app_control_invoke_v290',
  'vp3_app_control_settings_set_v290',
  'vp3_app_control_agent_query_v290',
  'vp3_system_apps_capability_v290',
]){
  assert.match(layer,new RegExp('function '+fn+'\\b'));
}
for(const op of [
  'apps.control.status',
  'apps.control.actions',
  'apps.control.settings',
  'apps.control.hosting',
  'apps.control.invoke',
  'apps.control.settings.set',
]){
  assert.match(bridge,new RegExp(op.replaceAll('.','\\.')));
}
assert.match(layer,/universal_app_control_contract'\s*=>\s*'vp3\.app\.agent-control\.v3'/);
assert.match(layer,/cloud_execution_authority'\s*=>\s*false/);
assert.match(layer,/homeserver_execution_authority'\s*=>\s*true/);
assert.match(layer,/secret_values_exposed'\s*=>\s*false/);
assert.match(layer,/App settings changes require explicit confirmation/);
assert.match(bootstrap,/system-apps-v290\.php/);
assert.match(router,/vp3_app_control_agent_query_v290/);

console.log('System Apps Section 16 Universal App Control contract: PASS');
