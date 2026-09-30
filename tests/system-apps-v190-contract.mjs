import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const ux=fs.readFileSync(new URL('includes/system-apps-v190.php',root),'utf8');
const actions=fs.readFileSync(new URL('includes/system-apps-v160.php',root),'utf8');
const runtime=fs.readFileSync(new URL('includes/agent-chat-runtime-v2160.php',root),'utf8');
const chat=fs.readFileSync(new URL('chat.js',root),'utf8');
const css=fs.readFileSync(new URL('chat.css',root),'utf8');
const shell=fs.readFileSync(new URL('chat.php',root),'utf8');
const api=fs.readFileSync(new URL('api/system-apps-v100.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');

assert.match(ux,/vp3\.system-app-action-card\.v1/);
assert.match(ux,/function vp3_system_apps_action_card_v190/);
assert.match(ux,/button_confirmation'\s*=>\s*true/);
assert.match(ux,/persistent_action_cards'\s*=>\s*true/);
assert.match(ux,/reconcile_pending_ux'\s*=>\s*true/);
assert.match(ux,/typed_confirmation_fallback'\s*=>\s*true/);
assert.match(actions,/preview'=>\$confirmedPreview/);
assert.match(actions,/status'=>'failed'/);
assert.match(runtime,/'system_app_plan'=>\$systemAppPlan/);
assert.match(runtime,/'system_app_action_card'=>\$systemAppCard/);
assert.match(runtime,/'system_app_action_card'=>\$messageContext\['system_app_action_card'\]/);
assert.match(chat,/function systemAppActionCardHtml/);
assert.match(chat,/data-system-app-action-card/);
assert.match(chat,/data-system-app-action-id/);
assert.match(chat,/Executing…/);
assert.match(chat,/Confirmation required/);
assert.match(chat,/context\.system_app_action_card/);
assert.match(chat,/data\.system_app_action_card/);
assert.match(css,/chat-system-app-action-card/);
assert.match(css,/chat-system-app-action-button\.primary/);
assert.match(shell,/chat-system-app-action-ux-v190-20260930/);
assert.match(shell,/chat\.css\?v=206-source-light-20260905/);
assert.match(api,/vp3_system_apps_capability_v190/);
assert.match(bootstrap,/system-apps-v190\.php/);

console.log('System Apps Agent Integration Section 5 end-to-end action UX contract: PASS');
