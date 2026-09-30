import fs from 'node:fs';
import assert from 'node:assert/strict';
const root=new URL('../',import.meta.url);
const health=fs.readFileSync(new URL('includes/system-apps-v170.php',root),'utf8');
const install=fs.readFileSync(new URL('includes/system-apps-v110.php',root),'utf8');
const activity=fs.readFileSync(new URL('includes/system-apps-v150.php',root),'utf8');
const brain=fs.readFileSync(new URL('includes/agent-brain-context-v142.php',root),'utf8');
const router=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');
const api=fs.readFileSync(new URL('api/system-apps-v100.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');

assert.match(health,/vp3\.system-app-health\.v1/);
assert.match(health,/function vp3_system_apps_health_snapshot_v170/);
assert.match(health,/function vp3_system_apps_health_context_items_v170/);
assert.match(health,/function vp3_system_apps_health_query_v170/);
assert.match(health,/vp3_cloud_hosting_diagnostics_v230_summary/);
assert.match(health,/transition_health_events'\s*=>\s*true/);
assert.match(health,/proactive_update_events'\s*=>\s*true/);
assert.match(health,/proactive_error_recovery_events'\s*=>\s*true/);
assert.match(health,/diagnostics_read_only'\s*=>\s*true/);
for(const event of ['app.update.available','app.update.cleared','app.runtime.state_changed','app.health.problem','app.health.recovered']){
  assert.ok(install.includes("'"+event+"'")||activity.includes("'"+event+"'"),'missing health event '+event);
}
assert.match(brain,/vp3_system_apps_health_context_items_v170/);
assert.match(router,/vp3_system_apps_health_query_v170/);
assert.ok(router.indexOf('vp3_system_apps_health_query_v170') < router.indexOf('vp3_system_apps_agent_action_query_v160'),'diagnostics must route before commands');
assert.match(api,/vp3_system_apps_capability_v170/);
assert.match(bootstrap,/system-apps-v170\.php/);
console.log('System Apps Agent Integration Section 3 health and diagnostics contract: PASS');
