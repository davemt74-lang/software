import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const bootstrap = read('includes/bootstrap.php');
const bridge = read('includes/agent-chat-activity.php');
const notifications = read('includes/notifications.php');
const activityApi = read('api/chat-notifications-brain-v240.php');
const activityJs = read('chat-notifications-drawer-v240.js');
const notificationsPage = read('notifications.php');
const memberHeader = read('includes/member-header.php');

assert.match(bootstrap, /agent-chat-activity\.php/, 'bootstrap must load the canonical operational activity reconciler');

assert.match(bridge, /agent_tool_history/, 'tool history remains an authoritative Agent Brain activity source');
assert.match(bridge, /agent_edit_events/, 'Stem Editor agent edits must feed Agent Brain activity');
assert.match(bridge, /editor_kind='stem'/, 'Stem integration must be scoped to Stem Editor events');
assert.match(bridge, /source_kind='agent'/, 'manual Stem edits must not flood Agent Brain operational activity');
assert.match(bridge, /artist_transcript_master_analysis_v237/, 'completed transcription AI summaries must feed Agent Brain activity');
assert.match(bridge, /memory_type='transcript_analysis'/, 'transcription saves to Agent Brain must feed operational activity');
assert.match(bridge, /Personal Artist Listening analysis%/, 'transcription saves to My Knowledge must feed operational activity');
assert.match(bridge, /agent_proactive_events/, 'proactive agent opportunities must feed Agent Brain activity');
assert.match(bridge, /agent_chat_activity_tool_is_meaningful/, 'read-only tool noise must be filtered before operational promotion');
assert.match(bridge, /skill\|workflow\|automation/, 'canonical tool ledger must recognize skill/workflow/automation execution');
assert.match(bridge, /agent_chat_activity_safe_target/, 'operational actions must validate target protocols');
assert.match(bridge, /NOT EXISTS[\s\S]*source_type='agent_tool_history'/, 'tool reconciliation must only promote unindexed source rows');
assert.match(bridge, /NOT EXISTS[\s\S]*source_type='agent_edit_event'/, 'Stem reconciliation must only promote unindexed source rows');
assert.match(bridge, /NOT EXISTS[\s\S]*source_type='transcript_analysis'/, 'transcription reconciliation must only promote unindexed source rows');
assert.doesNotMatch(bridge, /CREATE TABLE|ALTER TABLE/, 'operational routing must reuse existing ledgers and carrier rows');

assert.match(notifications, /function notification_is_agent_brain_activity/, 'Notifications must have one canonical Agent Brain classifier');
assert.match(notifications, /str_starts_with\(\$type, 'agent_activity_'\)/, 'all canonical agent_activity rows must classify as Agent Brain activity');
assert.match(notifications, /agent_tool_history[\s\S]*agent_edit_event[\s\S]*agent_memory_item[\s\S]*personal_knowledge_item[\s\S]*transcript_analysis[\s\S]*agent_proactive_event/, 'all operational source ledgers must classify into Agent Brain');
assert.match(notifications, /function notification_agent_brain_sql_predicate/, 'SQL filtering must share the Agent Brain boundary');
assert.match(notifications, /function notification_agent_brain_activity_after/, 'Agent Brain must expose a durable operational feed');
assert.match(notifications, /notification_agent_brain_sql_predicate\('n'\)[\s\S]*INTERVAL 14 DAY/, 'Agent Brain operational catch-up must remain bounded to 14 days');
assert.match(notifications, /function notification_unread_count[\s\S]*notification_system_sql_predicate/, 'bell unread count must exclude Agent Brain activity');
assert.match(notifications, /function notification_recent[\s\S]*notification_system_sql_predicate/, 'Notifications list must exclude Agent Brain activity');
assert.match(notifications, /function notification_requires_attention[\s\S]*notification_is_agent_brain_activity\(\$notification\)\) return false;/, 'Agent Brain activity must never enter Agent Chat attention');
assert.match(notifications, /n\.id>\?[\s\S]*notification_system_sql_predicate\('n'\)/, 'live attention polling must filter Brain activity before LIMIT');
assert.match(notifications, /INTERVAL 24 HOUR/, 'Agent Chat bootstrap attention must remain a bounded recent window');
assert.match(notifications, /function mark_all_notifications_read[\s\S]*notification_system_sql_predicate/, 'mark-all-read must affect only user-facing Notifications');
assert.match(notifications, /\?string \$createdAt = null/, 'carrier rows must preserve authoritative source timestamps');

assert.match(activityApi, /function chat_notifications_v240_brain_operations/, 'Activity Center API must expose Agent Brain operational rows');
assert.match(activityApi, /notification_agent_brain_activity_after\(\$user, 0, \$limit\)/, 'Activity Center must read the canonical Brain operational feed');
assert.match(activityApi, /'operations'=>\$brainAllowed \? chat_notifications_v240_brain_operations/, 'Agent Brain state payload must include operational activity');
assert.match(activityApi, /if \(!notification_requires_attention\(\$notification\)\)/, 'present_attention must recheck the canonical attention boundary server-side');
assert.doesNotMatch(activityApi, /Open Stem Editor|Open Transcription|Open My Knowledge/, 'operational source-specific actions must no longer be implemented in Agent Chat attention');

assert.match(activityJs, /data-notification-tab="notifications"/, 'Activity Center must retain the Notifications tab');
assert.match(activityJs, /data-notification-tab="brain"/, 'Activity Center must retain the Agent Brain tab');
assert.match(activityJs, /data-notification-tab="history"/, 'Activity Center must retain the History tab');
assert.match(activityJs, /const operations = Array\.isArray\(brain\.operations\)/, 'Agent Brain UI must consume operational rows');
assert.match(activityJs, /<strong>Operational Activity<\/strong>/, 'Agent Brain UI must render an explicit operational section');
assert.match(activityJs, /brainSourceLabel\(item\.source_type\)/, 'operational rows must show their human-readable source');
assert.match(activityJs, /Only genuine user-attention notifications reach this path/, 'Chat polling must document the new attention boundary');
assert.match(activityJs, /async function presentAttention\(item, speak = true\)/, 'genuine actionable notifications must retain Chat presentation and optional speech');

assert.match(notificationsPage, /WHERE user_id=\? AND '\s*\.\s*notification_system_sql_predicate\(\)/, 'standalone Notifications page must exclude Agent Brain activity');
assert.match(notificationsPage, /id=\? AND user_id=\? AND '\s*\.\s*notification_system_sql_predicate\(\)/, 'direct notification opens must not expose Agent Brain carrier rows');
assert.match(memberHeader, /activity-center-brain-routing-20260906/, 'member header must cache-bust the new Activity Center routing build');

console.log('agent-brain-notification-routing contract: PASS');
