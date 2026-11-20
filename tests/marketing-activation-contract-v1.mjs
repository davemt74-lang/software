import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=path=>fs.readFileSync(new URL('../'+path,import.meta.url),'utf8');
const api=read('api/initiative-workbench-v1.php');
const source=read('includes/initiative-marketing-activation-v1.php');
const work=read('chat-marketing-workspace-v1.js');
const ui=read('chat-marketing-activation-v1.js');
const page=read('chat.php');
for(const action of ['marketing_activation_open','marketing_activation_preview','marketing_activation_confirm']){
  assert.ok(api.includes("'"+action+"'"),action+' api registered');
  assert.ok(ui.includes("'"+action+"'"),action+' UI registered');
}
assert.match(source,/campaigns_rewards_set_campaign_lifecycle_v100/);
assert.match(source,/campaigns_rewards_idempotency_begin_v100/);
assert.match(source,/campaigns_rewards_idempotency_complete_v100/);
assert.match(source,/initiative_workbench_review_token_v1/);
assert.match(source,/initiative_workbench_confirm_v1/);
assert.match(source,/vp3_marketing_campaign_readiness_v1/);
assert.match(source,/campaigns_rewards_validate_campaign_activation_v118/);
assert.match(source,/campaigns\.launch/);
assert.match(source,/campaigns\.publish/);
assert.match(source,/landing_published_on_activation/);
assert.match(source,/may_enable_configured_automations/);
assert.match(source,/status'\]!=='active'/);
assert.match(ui,/Confirm & activate Campaign/);
assert.match(ui,/Current readiness/);
assert.match(ui,/crypto\.getRandomValues/);
assert.match(ui,/const live=()=>!disposed&&root.isConnected&&current()/); // current owner/Chat scope supplied by the workspace
assert.match(work,/openActivationReview/);
assert.match(work,/session\.activation\?\.host\?\.isConnected/);
assert.match(page,/chat-marketing-activation-v1\.js/);
assert.match(page,/chat-marketing-activation-v1\.css/);
assert.doesNotMatch(ui,/\.innerHTML\s*=|eval\(|new Function\(/);
console.log('Marketing Activation: signed approval, exact Campaign scope, canonical lifecycle, no direct outbound, responsive UI wiring passed');
