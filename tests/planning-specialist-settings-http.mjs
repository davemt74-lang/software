import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import fs from 'node:fs/promises';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const source = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const root = await fs.mkdtemp(path.join(os.tmpdir(), 'vp3-planning-settings-'));
const quoted = value => "'" + value.replaceAll("\\", "\\\\").replaceAll("'", "\\'") + "'";
const probe = net.createServer();
await new Promise(resolve => probe.listen(0, '127.0.0.1', resolve));
const port = probe.address().port;
await new Promise(resolve => probe.close(resolve));
await fs.mkdir(path.join(root, 'includes'));
await fs.copyFile(path.join(source, 'scheduling.php'), path.join(root, 'scheduling.php'));
await fs.writeFile(path.join(root, 'ready.txt'), 'ready');
await fs.writeFile(path.join(root, 'includes/agent-activity-scope-v1.php'),
  `<?php require_once ${quoted(path.join(source, 'includes/agent-activity-scope-v1.php'))};`);
await fs.writeFile(path.join(root, 'includes/bootstrap.php'), `<?php
require ${quoted(path.join(source, 'tests/fixtures/image-generation-v1.php'))};
require ${quoted(path.join(source, 'includes/agent-scheduling-v430.php'))};
require ${quoted(path.join(source, 'includes/agent-calendar-sync-v500.php'))};
class PlanningFixturePDO extends ImageFixturePDO
{
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        $sql = str_replace('ON DUPLICATE KEY UPDATE blocks_availability=VALUES(blocks_availability),writes_bookings=VALUES(writes_bookings)',
            'ON CONFLICT(schedule_id,connection_id) DO UPDATE SET blocks_availability=excluded.blocks_availability,writes_bookings=excluded.writes_bookings',$sql);
        return parent::prepare($sql,$options);
    }
}
$pdo = $GLOBALS['pdo'] = new PlanningFixturePDO(getenv('VP3_IMAGE_TEST_DSN'),null,null,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function e(mixed $value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="fixture">'; }
function verify_csrf(): bool { return ($_POST['csrf_token']??'')==='fixture'; }
function redirect(string $url): never { header('Location: '.$url); exit; }
if (isset($_SERVER['HTTP_X_FIXTURE_PARTICIPANT'])) $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant'=>8];
$pdo->sqliteCreateFunction('CURDATE',static fn()=>gmdate('Y-m-d'));
$pdo->exec("INSERT OR IGNORE INTO users(id,is_active) VALUES(1,1),(2,1),(3,1);
    INSERT OR IGNORE INTO chat_conversations VALUES(14,1,NULL),(15,3,NULL)");
if (!table_exists('agent_scheduling_schedules')) $pdo->exec("
    CREATE TABLE agent_scheduling_schedules(id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,name TEXT,
        timezone TEXT,is_active INTEGER,is_default INTEGER,public_enabled INTEGER);
    INSERT INTO agent_scheduling_schedules VALUES(21,1,7,'Owned schedule','UTC',1,0,0),
        (22,1,8,'Sibling schedule','UTC',1,1,0),(23,2,9,'Foreign schedule','UTC',1,1,0),(24,1,NULL,'Shared schedule','UTC',1,0,0);
    CREATE TABLE agent_scheduling_event_types(id INTEGER PRIMARY KEY,schedule_id INTEGER,slug TEXT,title TEXT,description TEXT,
        duration_minutes INTEGER,slot_interval_minutes INTEGER,location_type TEXT,location_value TEXT,buffer_before_minutes INTEGER,
        buffer_after_minutes INTEGER,minimum_notice_minutes INTEGER,booking_window_days INTEGER,max_bookings_per_day INTEGER,
        is_active INTEGER,sort_order INTEGER);
    INSERT INTO agent_scheduling_event_types VALUES(31,21,'intro','Introduction','',30,30,'virtual','',0,0,60,30,0,1,0);
    CREATE TABLE agent_scheduling_availability(id INTEGER PRIMARY KEY,schedule_id INTEGER,event_type_id INTEGER,weekday INTEGER,
        start_minute INTEGER,end_minute INTEGER,is_active INTEGER);
    CREATE TABLE agent_scheduling_overrides(id INTEGER PRIMARY KEY,schedule_id INTEGER,event_type_id INTEGER,override_date TEXT,
        is_available INTEGER,start_minute INTEGER,end_minute INTEGER,note TEXT);
    CREATE TABLE agent_scheduling_bookings(id INTEGER PRIMARY KEY,start_at_utc TEXT,public_token TEXT,
        buffer_before_minutes INTEGER,buffer_after_minutes INTEGER);
    CREATE TABLE agent_calendar_connections(id INTEGER PRIMARY KEY,owner_user_id INTEGER,provider TEXT,account_email TEXT,
        account_name TEXT,status TEXT,last_synced_at TEXT,last_error TEXT,access_token_ciphertext TEXT);
    INSERT INTO agent_calendar_connections VALUES(41,1,'google','owner@example.test','','connected',NULL,NULL,'private-token'),
        (42,2,'google','foreign@example.test','','connected',NULL,NULL,'foreign-token');
    CREATE TABLE agent_calendar_schedule_connections(schedule_id INTEGER,connection_id INTEGER,blocks_availability INTEGER,writes_bookings INTEGER,
        PRIMARY KEY(schedule_id,connection_id));
    CREATE TABLE agent_calendar_busy_blocks(start_at_utc TEXT);
    CREATE TABLE agent_calendar_booking_links(external_event_id TEXT)");
`);
const server = spawn(process.env.PHP_BINARY || 'php', ['-S', `127.0.0.1:${port}`, '-t', root], {
  env: { ...process.env, VP3_IMAGE_TEST_DSN: `sqlite:${path.join(root, 'fixture.sqlite')}` },
  stdio: ['ignore', 'ignore', 'pipe'],
});
let logs = '';
server.stderr.on('data', chunk => { logs += chunk; });
const base = `http://127.0.0.1:${port}`;
const view = '/scheduling.php?view=specialist-settings&agent=7&conversation_id=11&owner_user_id=1';

async function call(route, expected, { headers, form } = {}) {
  const response = await fetch(base + route, {
    headers, redirect: 'manual', ...(form ? { method: 'POST', body: new URLSearchParams(form) } : {}),
  });
  const body = await response.text();
  assert.equal(response.status, expected, logs.slice(-1000));
  return { response, body };
}

try {
  let ready = false;
  for (let i = 0; i < 60; i++) {
    try { if ((await fetch(base + '/ready.txt')).ok) { ready = true; break; } } catch {}
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  assert.ok(ready, logs);
  const { response, body } = await call(view, 200);
  assert.equal(response.headers.get('cache-control'), 'no-store', `${body.slice(0, 1000)}\n${logs.slice(-1000)}`);
  assert.equal(response.headers.get('content-security-policy'), "frame-ancestors 'self'");
  assert.match(body, /data-vp3-specialist-settings="planning" data-owner-user-id="1" data-agent-id="7" data-conversation-id="11"/);
  assert.match(body, /Owned schedule/);
  assert.match(body, /Shared schedule · Account-wide/);
  assert.doesNotMatch(body, /Sibling schedule|Foreign schedule|chat-sidebar|name="agent_id"|name="is_default"|>Bookings<|Upcoming bookings/);
  assert.match(body, /tab=calendars&amp;view=specialist-settings&amp;agent=7&amp;conversation_id=11&amp;owner_user_id=1/);
  await call(view, 401, { headers: { 'X-Fixture-Anonymous': '1' } });
  await call(view, 403, { headers: { 'X-Fixture-User': '2' } });
  await call(view.replace('owner_user_id=1', 'owner_user_id=2'), 403);
  await call(view, 403, { headers: { 'X-Fixture-Participant': '1' } });
  await call(view.replace('agent=7', 'agent=8'), 403);
  await call(view.replace('conversation_id=11', 'conversation_id=0'), 422);
  await call(view + '&schedule=22', 403);
  await call(view + '&schedule=23', 403);
  const system = await call(view.replace('agent=7&conversation_id=11', 'agent=0&conversation_id=14'), 200);
  assert.match(system.body, /data-agent-id="0"/);
  assert.doesNotMatch(system.body, /Owned schedule|Sibling schedule/);
  const empty = await call(view.replace('agent=7&conversation_id=11&owner_user_id=1', 'agent=0&conversation_id=15&owner_user_id=3'), 200,
    { headers: { 'X-Fixture-User': '3' } });
  assert.match(empty.body, /data-owner-user-id="3"/);
  assert.match(empty.body, /Set up a schedule/);

  const form = { csrf_token: 'fixture', action: 'save_schedule', schedule_id: '21', name: 'Updated schedule',
    timezone: 'America/Phoenix', agent_id: '8', is_default: '1', is_active: '1' };
  await call(view, 403, { form: { ...form, csrf_token: 'wrong' } });
  await call(view + '&schedule=22', 403, { form: { ...form, schedule_id: '22' } });
  await call(view + '&schedule=24', 403, { form });
  await call(view, 403, { form: { ...form, action: 'cancel_booking' } });
  const saved = await call(view, 302, { form });
  const location = saved.response.headers.get('location');
  assert.match(location, /schedule=21&tab=settings&view=specialist-settings&agent=7&conversation_id=11&owner_user_id=1/);
  const updated = await call(location, 200);
  assert.match(updated.body, /Updated schedule/);
  assert.match(updated.body, /Schedule settings saved/);
  assert.match(updated.body, /value="America\/Phoenix" selected/);
  assert.doesNotMatch(updated.body, /Sibling schedule/);

  const calendar = await call(view + '&tab=calendars', 200);
  assert.match(calendar.body, /owner@example\.test/);
  assert.doesNotMatch(calendar.body, /foreign@example\.test|private-token|foreign-token|>Sync now<|>Disconnect</);
  const calendarForm = { csrf_token: 'fixture', action: 'save_calendar_link', schedule_id: '21', connection_id: '41',
    blocks_availability: '1' };
  const calendarSaved = await call(view + '&tab=calendars', 302, { form: calendarForm });
  assert.match((await call(calendarSaved.response.headers.get('location'), 200)).body,
    /name="writes_bookings" value="1" ><span>Add VP3 bookings to this calendar/);
  assert.match((await call(view + '&tab=calendars', 200, { form: { ...calendarForm, connection_id: '42' } })).body,
    /Calendar connection not found/);
  const appointment = await call(view + '&tab=types', 200);
  assert.match(appointment.body, /Introduction/);
  const newTitle = await call(view + '&tab=types', 302, { form: { csrf_token: 'fixture', action: 'save_event_type',
    schedule_id: '21', event_type_id: '31', title: 'Updated introduction', location_type: 'phone' } });
  assert.match((await call(newTitle.response.headers.get('location'), 200)).body, /Updated introduction/);
  const hours = await call(view + '&tab=availability', 302, { form: { csrf_token: 'fixture', action: 'save_availability',
    schedule_id: '21', 'day[1][enabled]': '1', 'day[1][start]': '10:00', 'day[1][end]': '16:00' } });
  assert.match((await call(hours.response.headers.get('location'), 200)).body, /name="day\[1\]\[start\]" value="10:00"/);
  const override = await call(view + '&tab=availability', 302, { form: { csrf_token: 'fixture', action: 'save_override',
    schedule_id: '21', override_date: new Date(Date.now() + 86400000).toISOString().slice(0, 10), override_note: 'Closed for travel' } });
  assert.match((await call(override.response.headers.get('location'), 200)).body, /Closed for travel/);
  console.log('PLANNING_SPECIALIST_SETTINGS=PASS');
} finally {
  server.kill('SIGTERM');
  await fs.rm(root, { recursive: true, force: true });
}
