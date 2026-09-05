import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');

const chat = read('chat.php');
const ui = read('chat-notifications-drawer-v240.js');
const css = read('chat-notifications-drawer-v240.css');
const api = read('api/chat-notifications-brain-v240.php');
const activity = read('includes/agent-activity-v94.php');
const brain = read('includes/agent-brain-v82.php');

assert.doesNotThrow(() => new Function(ui), 'Activity Center runtime must be valid JavaScript');
assert.match(chat, /\$notificationDrawerBuild = 'chat-notifications-brain-v240-20260905'/);
assert.match(chat, /window\.STONEFELLOW_NOTIFICATION_DRAWER=/);
assert.match(chat, /chat-notifications-drawer-v240\.css\?v=/);
assert.match(chat, /chat-notifications-drawer-v240\.js\?v=/);
assert.match(chat, /chat-notifications-brain-v240\.php/);

assert.match(ui, /data-notification-tab="notifications"/);
assert.match(ui, /data-notification-tab="brain"/);
assert.match(ui, /data-notification-tab="history"/);
assert.match(ui, /Notifications/);
assert.match(ui, /Agent Brain/);
assert.match(ui, /History/);
assert.match(ui, /oldDropdown\.remove\(\)/, 'Activity Center must retire the legacy notification dropdown');
assert.match(ui, /const replacement = oldButton\.cloneNode\(true\)/, 'Activity Center must strip the legacy bell listener before owning the button');
assert.match(ui, /actions\.insertBefore\(menu, profile\)/, 'Notification bell must be immediately left of the profile menu');
assert.match(ui, /new MutationObserver\(keepBellNextToProfile\)/, 'Bell placement must survive later header insertions');

assert.match(css, /z-index:20400/);
assert.match(css, /z-index:20410/);
assert.match(css, /height:100dvh/);
assert.match(css, /width:min\(600px,calc\(100vw - 36px\)\)/);
assert.match(css, /@media\(max-width:700px\)\{\.chat-notification-drawer\{width:100vw\}/);

assert.match(api, /notification_recent\(\$user, 25\)/);
assert.match(api, /notification_unread_count\(\$user\)/);
assert.match(api, /agent_brain_summary\(\$user\)/);
assert.match(api, /agent_activity_v94_snapshot\(\$user, 'chat', \[\]\)/);
assert.match(api, /FROM agent_activity_events/);
assert.match(api, /FROM agent_chat_archive/);
assert.match(api, /mark_notification_read/);
assert.match(api, /mark_all_notifications_read/);
assert.equal(api.includes('CREATE TABLE'), false, 'Activity Center must reuse existing Agent Brain/activity storage');
assert.match(activity, /table_exists\('agent_activity_events'\)/);
assert.match(brain, /agent_chat_archive/);
assert.match(brain, /agent_memory_items/);

console.log('chat-notifications-brain-v240 contract: PASS');
