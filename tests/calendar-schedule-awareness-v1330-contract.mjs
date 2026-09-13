import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(path, 'utf8');
const awareness = read('includes/calendar-schedule-awareness-v1330.php');
const calendar = read('includes/user-calendar-v1300.php');
const bootstrap = read('includes/bootstrap.php');
const proactive = read('includes/agent-proactive-operations-v036.php');
const proactivePipeline = read('includes/agent-proactive-v123.php');
const cognitive = read('includes/agent-cognitive-loop-v310.php');
const chat = read('api/chat-v236.php');

assert.match(awareness, /user_calendar_events_v1300\(\$pdo,\$user,/,
  'awareness must project from the canonical User Calendar instead of duplicating booking/event reads');
assert.match(calendar, /'kind'=>'booking'/,
  'canonical User Calendar projection must still include scheduling bookings');
assert.match(awareness, /'read_only'=>true/);
assert.match(awareness, /'availability_authority'=>false/,
  'derived open gaps must never become scheduling availability authority');
assert.match(awareness, /VP3_CALENDAR_SCHEDULE_AWARENESS_MAX_HORIZON_DAYS_V1330 = 14/);
assert.match(awareness, /VP3_CALENDAR_SCHEDULE_AWARENESS_MAX_EVENTS_V1330 = 160/);
assert.match(awareness, /user_calendar_default_timezone_v1300\(\$pdo,\$user\)/,
  'awareness must use the owner schedule timezone');
assert.doesNotMatch(awareness, /\b(?:INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|REPLACE|TRUNCATE)\s+(?:INTO|TABLE|FROM|user_calendar_events|agent_scheduling_)/i,
  'awareness layer must not write or define calendar/scheduling state');

const calendarLoad = bootstrap.indexOf("require_once __DIR__.'/user-calendar-v1300.php';");
const awarenessLoad = bootstrap.indexOf("require_once __DIR__.'/calendar-schedule-awareness-v1330.php';");
const proactiveLoad = bootstrap.indexOf("require_once __DIR__.'/agent-proactive-operations-v036.php';");
assert.ok(calendarLoad >= 0 && awarenessLoad > calendarLoad,
  'bootstrap must load canonical User Calendar before derived awareness');
assert.ok(proactiveLoad > awarenessLoad,
  'calendar awareness must be available before proactive/Brain providers load');

assert.match(proactive, /calendar_schedule_awareness_snapshot_v1330\(\$pdo, \$user\)/);
assert.match(proactive, /calendar_schedule_awareness_candidates_v1330\(\$snapshot\)/);
assert.match(proactive, /foreach \(agent_proactive_operations_v036_calendar_candidates\(\$pdo, \$user\)/,
  'calendar signals must enter the operational evidence provider');
assert.match(proactivePipeline, /agent_proactive_operations_v036_candidates\(\$pdo,\$user\)/,
  'the evidence-first proactive pipeline must consume the operational provider');
assert.match(cognitive, /agent_proactive_v123_suggestions\(\$user,'brain',\$context\)/,
  'the canonical cognitive loop must consume the evidence-first proactive pipeline');
assert.match(cognitive, /agent_action_v124_suppression\(\$candidate,\$uid\)/,
  'the cognitive loop must retain suppression authority over calendar-derived candidates');
assert.match(cognitive, /agent_action_v124_risk\(\$candidate\)/,
  'the cognitive loop must retain risk classification authority over calendar-derived candidates');
assert.match(cognitive, /agent_action_v124_plan\(\$candidate,\$event,\[\]\)/,
  'the cognitive loop must retain action-planning authority over calendar-derived candidates');

assert.match(chat, /calendar_schedule_awareness_snapshot_v1330\(\$pdo,\$user\)/,
  'chat must derive a fresh owner-scoped schedule snapshot');
assert.match(chat, /\$agentContext\['calendar_awareness'\]=\$calendarAwareness/,
  'HomeServer Agent context must receive the structured server-derived awareness snapshot');
assert.match(chat, /chat_v236_calendar_context_events\(\$calendarAwareness\)/,
  'Cloud Agent context must receive sanitized schedule events through the existing surface context');
assert.match(chat, /array_merge\(\$calendarContextEvents,\$clientEvents\)/,
  'server-derived schedule events must be placed before client-supplied surface events');

const nativeCalendarTool = chat.indexOf('user_calendar_agent_query_v1300($query,$user,$conversationId)');
const homeRoute = chat.indexOf('homeserver_agent_v025_chat(');
const cloudRoute = chat.indexOf('chat_generate_answer_policy_v236(');
assert.ok(nativeCalendarTool >= 0 && homeRoute > nativeCalendarTool && cloudRoute > nativeCalendarTool,
  'native calendar tools must still execute before HomeServer or Cloud model routing');

console.log('Calendar Schedule Awareness v13.30 contract: OK');
