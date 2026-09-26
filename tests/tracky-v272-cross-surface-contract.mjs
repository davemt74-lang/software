import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const proactive=read('includes/tracky-proactive-v272.php');
const agent=read('includes/tracky-agent-v271.php');
const cloud=read('includes/tracky-cloud-v270.php');
const feed=read('includes/cognitive-feed-v530.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const trackyPage=read('tracky.php');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(proactive,/VP3_TRACKY_PROACTIVE_CONTRACT_V272='tracky-cross-surface-v1'/);
assert.match(proactive,/function tracky_v272_settings_defaults/);
assert.match(proactive,/settings_json/);
assert.doesNotMatch(proactive,/CREATE TABLE|ALTER TABLE/,'V2.72 must reuse plugin settings and canonical ledgers');
assert.match(proactive,/'voice_enabled'=>false/);
assert.match(proactive,/'cross_plugin_access'=>false/);

assert.match(proactive,/vp3_cognitive_observation_store_v500/);
assert.match(proactive,/vp3_cognitive_attention_arbitrate_v2410/);
assert.match(proactive,/vp3_cognitive_presentation_record_v500/);
assert.match(proactive,/create_notification/);
assert.match(proactive,/'tracky_event'/);
assert.match(proactive,/mark_notification_read/);
assert.match(proactive,/state=\?,updated_at=UTC_TIMESTAMP\(\)/);
assert.match(proactive,/vp3_cognitive_attention_mark_released_v2410/);

assert.match(proactive,/function tracky_v272_observation_now_allowed/);
assert.match(proactive,/function tracky_v272_observation_group_key/);
assert.match(feed,/tracky_v272_observation_now_allowed/);
assert.match(feed,/tracky_v272_observation_group_key/);
assert.doesNotMatch(feed,/tracky_v272_feed_candidates/,'direct Tracky Now path would duplicate canonical observations');

assert.match(presentation,/source_type.*tracky_event/);
assert.match(presentation,/tracky_v272_notification_voice_allowed/);
assert.match(proactive,/function tracky_v272_notification_voice_allowed/);

assert.match(proactive,/function tracky_v272_trigger_catalog/);
assert.match(proactive,/function tracky_v272_trigger_payload/);
assert.match(proactive,/function tracky_v272_automation_triggers/);
assert.match(proactive,/'tracky\.object\.moved'/);
assert.match(proactive,/'tracky\.safety\.possible_hazard'/);
assert.doesNotMatch(proactive,/agent_workflow_execute|devices\.control|device\.control|unlock_device|execute_physical_action/,'V2.72 must publish triggers, not execute actions');
assert.match(proactive,/'proposed_action_ids'=>\[\]/);

assert.match(proactive,/function tracky_v272_plugin_can_read/);
assert.match(proactive,/function tracky_v272_plugin_context/);
assert.match(proactive,/cross_plugin_access/);
assert.match(proactive,/'raw_perception_exposed'=>false/);
assert.match(proactive,/'event_history_exposed'=>false/);
assert.match(proactive,/'read_only'=>true/);

assert.match(proactive,/function tracky_v272_alert_lifecycle/);
assert.match(proactive,/function tracky_v272_reconcile_prior_alerts/);
assert.match(proactive,/'resolved':'superseded'/);
assert.match(proactive,/function tracky_v272_simulator_scenarios/);
for(const scenario of ['arrival','departure','object_moved','camera_failure_and_recovery','possible_safety_condition','duplicate_event','outage_reconnect']){
  assert.ok(proactive.includes("'"+scenario+"'=>"),'missing simulator scenario '+scenario);
}

assert.match(agent,/physical_event/);
assert.match(agent,/tracky_v272_card/);
assert.match(agent,/require_once __DIR__\.'\/tracky-proactive-v272\.php'/);
assert.match(cloud,/tracky_v272_on_sync/);

assert.match(trackyPage,/save_surface_policy/);
assert.match(trackyPage,/now_enabled/);
assert.match(trackyPage,/chat_enabled/);
assert.match(trackyPage,/voice_enabled/);
assert.match(trackyPage,/automation_enabled/);
assert.match(trackyPage,/cross_plugin_access/);
assert.match(trackyPage,/Global Agent voice/);
assert.match(trackyPage,/V2\.72 publishes trigger metadata only/);
assert.match(trackyPage,/selectedEventCard/);

assert.match(packageWorkflow,/test -f _deploy\/includes\/tracky-proactive-v272\.php/);
assert.match(recovery,/tests\/tracky-v272-cross-surface-contract\.mjs/);
assert.match(recovery,/tests\/tracky-v272-cross-surface-unit\.php/);

console.log('TRACKY_V272_CROSS_SURFACE_CONTRACT=PASS');
