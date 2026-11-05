import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {once} from 'node:events';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const php = process.env.VP3_PHP_BINARY || 'php';
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-initiative-http-'));
const quote = 'The controlled trial measured twenty hours of cold fermentation.';
const capture = '30000000-0000-4000-8000-000000000001';
const sourceJpeg = 'data:image/jpeg;base64,' + fs.readFileSync(path.join(root, 'tests/fixtures/tv-frame.b64'), 'utf8').trim();
const env = {...process.env, VP3_CLOUD_SCHEDULE_DSN: 'sqlite:' + path.join(tmp, 'state.sqlite')};
const phpString = JSON.stringify;

for (const folder of ['api', 'includes', 'sessions']) fs.mkdirSync(path.join(tmp, folder));
fs.copyFileSync(path.join(root, 'api/initiative-workbench-v1.php'), path.join(tmp, 'api/initiative-workbench-v1.php'));
for (const name of ['initiative-workbench-v1.php', 'initiative-work-mission-v1.php', 'initiative-work-screen-v1.php']) {
  fs.writeFileSync(path.join(tmp, 'includes', name), '<?php require_once ' + phpString(path.join(root, 'includes', name)) + ';');
}

function runPHP(name, source) {
  const file = path.join(tmp, name);
  fs.writeFileSync(file, source);
  const result = spawnSync(php, [file], {env, encoding: 'utf8'});
  assert.equal(result.status, 0, result.stderr + result.stdout);
  return result.stdout;
}

const mission = runPHP('seed.php', `<?php
require ${phpString(path.join(root, 'tests/fixtures/cloud-mission-schedules-v1.php'))};
require ${phpString(path.join(root, 'includes/agent-screen-sessions-v1.php'))};
$user=['id'=>1];
$pdo=db();
$pdo->exec("CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);
    INSERT INTO chat_conversations VALUES(11,1,7),(12,1,7),(13,1,NULL),(22,2,8);
    CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT);
    INSERT INTO user_agents VALUES(7,1,1,'Owner Agent'),(8,2,1,'Other Agent');
    CREATE TABLE user_media_assets(id INTEGER PRIMARY KEY); CREATE TABLE video_editor_projects(id INTEGER PRIMARY KEY)");
$mission=cloud_mission_prepare_v1($pdo,$user,['objective'=>'Compare cold fermentation trials','thread_id'=>11,'request_id'=>'workbench-http-mission']);
$draft=$mission['chat_task']['draft'];
$draft['assignments'][0]=array_replace($draft['assignments'][0],['tools'=>['web.read'],'max_calls'=>1,
    'web'=>['query'=>'','domains'=>['research.example.org'],'max_pages'=>1,'urls'=>['https://research.example.org/study']]]);
cloud_mission_configure_v1($pdo,$user,['mission_id'=>$mission['id'],'request_id'=>cloud_mission_uuid_v1('workbench-http-review'),
    'expected_revision'=>0,'confirmed'=>true,'assignments'=>$draft]);
cloud_mission_service_v1($pdo,$user,'tools.start',['mission_id'=>$mission['id'],'expected_revision'=>1,'confirmed'=>true]);
$transport=['get'=>static fn()=>['status'=>200,'content_type'=>'text/plain','body'=>str_repeat(${phpString(quote + ' ')},6)]];
cloud_mission_tick_v1($pdo,$user,$mission['id'],null,$transport);
$mission=cloud_mission_tick_v1($pdo,$user,$mission['id'],null,$transport);
if($mission['status']!=='completed')throw new RuntimeException('Mission seed did not complete.');
screen_sessions_schema_v1($pdo);
$capture=${phpString(capture)};
$session='30000000-0000-4000-8000-000000000002';
$at=gmdate('Y-m-d\\TH:i:s\\Z');
$pdo->exec("INSERT INTO artist_transcript_folders_v177(id,created_by_user_id,folder_name) VALUES(99,1,'Private screen')");
$pdo->prepare("INSERT INTO knowledge_items(id,created_by_user_id,folder_id,knowledge_scope,file_type,file_name)
    VALUES(99,1,99,'personal','personal_note',?)")->execute(['personal-'.sha1('screen-capture:'.$capture).'.txt']);
$pdo->prepare("INSERT INTO agent_screen_sessions_v1(owner_user_id,session_id,folder_id,agent_id,title,status,created_at,updated_at)
    VALUES(1,?,99,7,'Private saved capture','ended',?,?)")->execute([$session,$at,$at]);
$pdo->prepare("INSERT INTO agent_screen_captures_v1(owner_user_id,session_id,capture_id,kind,captured_at,knowledge_id,image_data,body_json)
    VALUES(1,?,?,'screen',?,99,?,'{}')")->execute([$session,$capture,$at,${phpString(sourceJpeg)}]);
echo $mission['id'];
`).trim();

fs.writeFileSync(path.join(tmp, 'includes/bootstrap.php'), `<?php
declare(strict_types=1);
// The endpoint, Chat boundary, lanes and workflow ledger are production code.
// Sessions, provider configuration and model output are isolated fixtures.
class WorkbenchHTTPPDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace([' FOR UPDATE','INSERT IGNORE'],['','INSERT OR IGNORE'],$query),$options);
    }
}
function db(): PDO {
    static $pdo;
    if(!$pdo){
        $pdo=new WorkbenchHTTPPDO(getenv('VP3_CLOUD_SCHEDULE_DSN'),null,null,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));
    }
    return $pdo;
}
function table_exists(string $name): bool {
    $q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return (bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool {
    return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);
}
function current_user(): ?array {
    if(!isset($_SESSION['user_id']))return null;
    $q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([(int)$_SESSION['user_id']]);return $q->fetch()?:null;
}
function csrf_token(): string {return (string)($_SESSION['csrf']??'');}
function has_permission(string $permission,array $user): bool {return !empty($user['is_active']);}
function personal_capability_has_v242(string $permission,array $user): bool {return has_permission($permission,$user);}
function subscription_has_entitlement(array $user,string $permission): bool {return has_permission($permission,$user);}
function setting(string $name,mixed $default=null): mixed {return $default;}
function ai_provider_enabled(string $provider): bool {return $provider==='openai';}
function ai_provider_api_key(string $provider): string {return 'fixture-no-provider-call';}
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $aid,string $surface): array {
    return ['route'=>'vp3_cloud','cloud'=>['ready'=>true]];
}
function ai_generate_chat_response(string $prompt,array $history,array $context,array $user,string $scope,array $control): array {
    $quote=${phpString(quote)};
    if((int)$user['id']!==1||count($context)!==1||!str_contains($context[0]['text'],$quote))throw new RuntimeException('Wrong evidence.');
    file_put_contents(dirname(__DIR__).'/inference-count','1',FILE_APPEND|LOCK_EX);
    return ['ok'=>true,'answer'=>json_encode(['findings'=>[['source_id'=>$context[0]['source'],'quote'=>$quote]],
        'recommendation'=>'Consider repeating the trial.','uncertainty'=>'Only one saved source was reviewed.',
        'next_step'=>'Review the tested recipe and temperatures.'],JSON_THROW_ON_ERROR)];
}
require_once ${phpString(path.join(root, 'includes/knowledge.php'))};
require_once ${phpString(path.join(root, 'includes/knowledge-retrieval-v162.php'))};
require_once ${phpString(path.join(root, 'includes/user-agent-system-v236.php'))};
require_once ${phpString(path.join(root, 'includes/agent-chat-boundary-v380.php'))};
require_once ${phpString(path.join(root, 'includes/agent-capability-chat-v1.php'))};
require_once ${phpString(path.join(root, 'includes/media-studio-v86.php'))};
session_start();
`);

const owner = {cookie: 'PHPSESSID=fixture-owner', csrf: 'owner-csrf', key: '1', agent: 7, conversation: 11};
const ownerOtherSession = {...owner, cookie: 'PHPSESSID=fixture-owner-other'};
const other = {cookie: 'PHPSESSID=fixture-other', csrf: 'other-csrf', key: '2', agent: 8, conversation: 22};
for (const account of [owner, ownerOtherSession, other]) {
  const id = account.cookie.split('=')[1];
  fs.writeFileSync(path.join(tmp, 'sessions', 'sess_' + id),
    `user_id|i:${account.key};csrf|s:${account.csrf.length}:"${account.csrf}";`);
}

const reserve = net.createServer();
await new Promise(resolve => reserve.listen(0, '127.0.0.1', resolve));
const port = reserve.address().port;
await new Promise(resolve => reserve.close(resolve));
const child = spawn(php, ['-d', 'session.save_path=' + path.join(tmp, 'sessions'), '-S', `127.0.0.1:${port}`, '-t', tmp],
  {env, stdio: ['ignore', 'ignore', 'pipe']});
let logs = '';
child.stderr.on('data', chunk => {logs += chunk;});
const endpoint = `http://127.0.0.1:${port}/api/initiative-workbench-v1.php`;
const seenExit = once(child, 'exit');

async function post(action, tool, fields = {}, account = owner) {
  const body = {action, tool, owner_key: account?.key ?? '1', csrf_token: account?.csrf ?? owner.csrf,
    agent_id: account?.agent ?? owner.agent, conversation_id: account?.conversation ?? owner.conversation, ...fields};
  const response = await fetch(endpoint, {method: 'POST', headers: {'Content-Type': 'application/json', Cookie: account?.cookie ?? ''},
    body: JSON.stringify(body)});
  const raw = await response.text();
  return {status: response.status, raw, data: JSON.parse(raw)};
}

function accepted(response) {
  assert.equal(response.status, 200, response.raw);
  assert.equal(response.data.ok, true);
  return response.data;
}

function rejected(response, status) {
  assert.equal(response.status, status, response.raw);
  assert.equal(response.data.ok, false);
  assert.equal(response.data.output, undefined);
}

function executeSQL(sql) {
  return runPHP('state.php', `<?php $pdo=new PDO(getenv('VP3_CLOUD_SCHEDULE_DSN')); echo $pdo->query(${phpString(sql)})->fetchColumn();`).trim();
}

let checks = 0;
async function check(label, run) {
  await run();
  console.log('PASS ' + label);
  checks++;
}

try {
  let ready;
  for (let attempt = 0; attempt < 50 && !ready; attempt++) {
    try {ready = await fetch(endpoint);} catch {await new Promise(resolve => setTimeout(resolve, 50));}
  }
  assert.equal(ready?.status, 405);
  assert.equal(ready.headers.get('cache-control'), 'private, no-store');

  await check('POST requires session, current account key, and CSRF', async () => {
    rejected(await post('sources', 'mission', {}, null), 403);
    rejected(await post('sources', 'mission', {owner_key: '1'}, other), 409);
    rejected(await post('sources', 'mission', {csrf_token: 'wrong'}), 419);
  });

  await check('owner, Agent, and exact conversation fence Mission source access', async () => {
    const own = accepted(await post('sources', 'mission'));
    assert.deepEqual(own.sources, [{source_id: mission, source_title: 'Compare cold fermentation trials'}]);
    assert.deepEqual(accepted(await post('sources', 'mission', {}, other)).sources, []);
    assert.deepEqual(accepted(await post('sources', 'mission', {conversation_id: 12})).sources, []);
    rejected(await post('preview', 'mission', {source_id: mission}, other), 404);
    rejected(await post('preview', 'mission', {source_id: mission, conversation_id: 12}), 404);
    rejected(await post('preview', 'mission', {source_id: mission, agent_id: 8}), 403);
    rejected(await post('preview', 'mission', {source_id: mission, agent_id: 0}), 403);
    rejected(await post('preview', 'mission', {source_id: mission, conversation_id: 22}), 403);
  });

  const proposedMission = {source_id: mission};
  const firstTab = accepted(await post('preview', 'mission', proposedMission));
  const secondTab = accepted(await post('preview', 'mission', proposedMission));
  const confirm = {...proposedMission, confirmed: true, review_token: firstTab.review_token};
  await check('preview exposes only review metadata, and rejected requests perform no inference', async () => {
    assert.match(firstTab.review_token, /^\d+\.[a-f0-9]{64}$/);
    assert.equal(firstTab.preview.revision, secondTab.preview.revision);
    assert.deepEqual(firstTab.preview.details.coverage, {captured_sources: 1, read_sources: 1});
    assert.doesNotMatch(JSON.stringify(firstTab), /twenty hours/);
    rejected(await post('create', 'mission', {...confirm, confirmed: false}), 422);
    rejected(await post('create', 'mission', {...confirm, review_token: undefined}), 422);
    const changedToken = firstTab.review_token.slice(0, -1) + (firstTab.review_token.endsWith('0') ? '1' : '0');
    rejected(await post('create', 'mission', {...confirm, review_token: changedToken}), 409);
    rejected(await post('create', 'mission', confirm, ownerOtherSession), 409);
    assert.equal(fs.existsSync(path.join(tmp, 'inference-count')), false);
  });

  await check('two confirmed tabs replay the same durable Mission output and provider call', async () => {
    const [first, second] = await Promise.all([
      post('create', 'mission', confirm),
      post('create', 'mission', {...confirm, review_token: secondTab.review_token}),
    ]);
    const a = accepted(first).output;
    const b = accepted(second).output;
    assert.match(a.request_id, /^[a-f0-9]{64}$/);
    assert.deepEqual(a, b);
    assert.equal(a.findings[0].quote, quote);
    assert.equal(fs.readFileSync(path.join(tmp, 'inference-count'), 'utf8'), '1');
    assert.equal(executeSQL('SELECT COUNT(*) FROM agent_initiative_mission_briefs_v1'), '1');
    rejected(await post('create', 'mission', confirm, other), 404);
  });

  await check('new Mission evidence invalidates an earlier confirmation', async () => {
    executeSQL("UPDATE agent_cloud_mission_web_v1 SET evidence_hash='revised'");
    rejected(await post('create', 'mission', confirm), 409);
    const revised = accepted(await post('preview', 'mission', proposedMission));
    assert.notEqual(revised.preview.revision, firstTab.preview.revision);
    assert.equal(fs.readFileSync(path.join(tmp, 'inference-count'), 'utf8'), '1');
  });

  await check('Screen preview is private and confirmation binds capture, revision, mode and instruction', async () => {
    const fields = {source_id: capture, mode: 'cleanup', instruction: 'Reduce compression artifacts.'};
    const list = accepted(await post('sources', 'screen'));
    assert.deepEqual(list.sources.map(source => source.source_id), [capture]);
    assert.deepEqual(accepted(await post('sources', 'screen', {}, other)).sources, []);
    assert.doesNotMatch(JSON.stringify(list), /data:image|image_data/);
    const review = accepted(await post('preview', 'screen', fields));
    assert.equal(review.preview.details.image_data, sourceJpeg);
    rejected(await post('preview', 'screen', fields, other), 404);
    const approved = {...fields, confirmed: true, review_token: review.review_token};
    rejected(await post('create', 'screen', {...approved, mode: 'redraw'}), 409);
    rejected(await post('create', 'screen', {...approved, instruction: 'Make it different.'}), 409);
    executeSQL('UPDATE agent_screen_captures_v1 SET revision=revision+1');
    rejected(await post('create', 'screen', approved), 409);
    assert.equal(executeSQL("SELECT COUNT(*) FROM agent_workflow_runs WHERE source_kind='image_generation'"), '0');
    const current = accepted(await post('preview', 'screen', fields));
    const create = {...approved, review_token: current.review_token};
    const queued = await post('create', 'screen', create);
    const out = accepted(queued).output;
    assert.equal(out.generation.status, 'queued');
    assert.equal(out.generation.provider_started, false);
    assert.deepEqual(out.canvas_media.generation_ids, [out.generation.id]);
    assert.doesNotMatch(queued.raw, /data:image|image_data|b64_json/);
    assert.deepEqual(accepted(await post('create', 'screen', create)).output, out);
    assert.equal(executeSQL("SELECT COUNT(*) FROM agent_workflow_runs WHERE source_kind='image_generation'"), '1');
  });

  console.log(`INITIATIVE_WORKBENCH_API=PASS (${checks} cases)`);
} catch (error) {
  console.error(logs.slice(-5000));
  throw error;
} finally {
  child.kill();
  await seenExit;
  fs.rmSync(tmp, {recursive: true, force: true});
}
