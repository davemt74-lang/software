import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const bootstrap = read('includes/bootstrap.php');
const bridge = read('includes/agent-chat-activity.php');
const notifications = read('includes/notifications.php');
const attentionApi = read('api/chat-notifications-brain-v240.php');
const attentionJs = read('chat-notifications-drawer-v240.js');

assert.match(bootstrap, /agent-chat-activity\.php/, 'bootstrap must load the canonical Agent Chat activity bridge');

assert.match(bridge, /agent_tool_history/, 'tool history remains an authoritative Agent Chat activity source');
assert.match(bridge, /agent_edit_events/, 'Stem Editor agent edits must feed Agent Chat activity');
assert.match(bridge, /editor_kind='stem'/, 'Stem integration must be scoped to Stem Editor events');
assert.match(bridge, /source_kind='agent'/, 'manual Stem edits must not flood the Agent operational inbox');
assert.match(bridge, /artist_transcript_master_analysis_v237/, 'completed transcription AI summaries must feed Agent Chat');
assert.match(bridge, /memory_type='transcript_analysis'/, 'transcription saves to Agent Brain must feed Agent Chat');
assert.match(bridge, /Personal Artist Listening analysis%/, 'transcription saves to My Knowledge must feed Agent Chat');
assert.match(bridge, /agent_proactive_events/, 'proactive agent opportunities must feed Agent Chat');
assert.match(bridge, /agent_chat_activity_tool_is_meaningful/, 'read-only tool noise must be filtered before Chat promotion');
assert.doesNotMatch(bridge, /CREATE TABLE|ALTER TABLE/, 'activity bridge must reuse existing ledgers instead of creating a parallel store');

assert.match(notifications, /str_starts_with\(\$type, 'agent_activity_'\)/, 'canonical operational activity notifications must require Chat attention');
assert.match(notifications, /agent_chat_activity_reconcile\(\$user\)/, 'notification reads must reconcile existing subsystem ledgers');
assert.match(notifications, /NOT EXISTS[\s\S]*\$\.attention\.notification_id/, 'bootstrap must select durable unsurfaced items, not a temporary unread window');
assert.doesNotMatch(notifications, /created_at>=DATE_SUB\(NOW\(\),INTERVAL 10 MINUTE\)/, 'Agent Chat bootstrap must not discard attention after ten minutes');
assert.match(notifications, /\?string \$createdAt = null/, 'notifications must preserve source event time when bridging activity');

assert.match(attentionApi, /\$notification\['created_at'\]/, 'Chat persistence must preserve the source notification timestamp');
assert.match(attentionApi, /'actions'=>\$actions/, 'operational messages must retain actionable Chat context');
assert.match(attentionJs, /async function presentAttention\(item, speak = true\)/, 'attention presentation must separate persistence from optional speech');
assert.match(attentionJs, /for \(let index = 0; index < items\.length; index \+= 1\)/, 'bootstrap must reconcile the complete returned durable backlog');
assert.match(attentionJs, /const speak = !bootstrap \|\| index === items\.length - 1/, 'initial backlog must only verbalize its newest item');
assert.doesNotMatch(attentionJs, /const selected = bootstrap && items\.length \? \[items\[items\.length - 1\]\] : items/, 'old newest-only bootstrap behavior must stay removed');

console.log('agent-chat-operational-activity contract: PASS');
