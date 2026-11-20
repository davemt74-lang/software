import assert from 'node:assert/strict';
import fs from 'node:fs';
const load=f=>fs.readFileSync(new URL('../'+f,import.meta.url),'utf8');
const api=load('api/initiative-workbench-v1.php'),model=load('includes/initiative-marketing-editor-v1.php'),
  ui=load('chat-marketing-editor-v1.js'),ws=load('chat-marketing-workspace-v1.js'),chat=load('chat.php');
for(const action of ['marketing_editor_options','marketing_editor_open','marketing_editor_preview','marketing_editor_save']){
  assert.ok(api.includes("'"+action+"'"));
  assert.ok(ui.includes("'"+action+"'"));
}
assert.match(api,/vp3_marketing_editor_action_v1/);
assert.match(model,/campaigns_rewards_save_campaign_v100/);
assert.match(model,/campaigns_rewards_idempotency_begin_v100/);
assert.match(model,/initiative_workbench_confirm_v1/);
assert.match(model,/initiative_workbench_review_token_v1/);
assert.match(model,/status'\]!=='draft'/);
assert.match(model,/profile_visible'=>false/);
assert.match(model,/vp3_coordination_marketing_owner_v1/);
assert.match(ui,/Confirm & save private draft/);
assert.match(ui,/approval\.key!==requestKey/);
assert.match(ui,/editor\.isConnected&&current\(\)/);
assert.match(ui,/crypto\.getRandomValues/);
assert.match(ui,/starts_at/);
assert.match(ws,/openDraftEditor/);
assert.match(ws,/campaign\.status==='draft'/);
assert.match(chat,/chat-marketing-editor-v1\.js/);
assert.match(chat,/chat-marketing-editor-v1\.css/);
assert.doesNotMatch(ui,/\.innerHTML\s*=|eval\(|new Function\(/);
console.log('Marketing Campaign Editor: API boundaries, explicit review, idempotent draft save and UI wiring verified');
