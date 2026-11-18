import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {once} from 'node:events';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {crc32, deflateSync} from 'node:zlib';

const root = fileURLToPath(new URL('../', import.meta.url));
const php = process.env.VP3_PHP_BINARY || 'php';
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-initiative-http-'));
const quote = 'The controlled trial measured twenty hours of cold fermentation.';
const capture = '30000000-0000-4000-8000-000000000001';
const sourceJpeg = 'data:image/jpeg;base64,' + fs.readFileSync(path.join(root, 'tests/fixtures/tv-frame.b64'), 'utf8').trim();
const photoBytes = Buffer.from(sourceJpeg.split(',')[1], 'base64');
const photoLimit = 4 * 1024 * 1024;
const env = {...process.env, VP3_CLOUD_SCHEDULE_DSN: 'sqlite:' + path.join(tmp, 'state.sqlite')};
const phpString = JSON.stringify;

for (const folder of ['api', 'includes', 'sessions']) fs.mkdirSync(path.join(tmp, folder));
for (const name of ['initiative-workbench-v1.php', 'media-library-v86.php']) {
  fs.copyFileSync(path.join(root, 'api', name), path.join(tmp, 'api', name));
}
fs.copyFileSync(path.join(root, 'media-library-file-v86.php'), path.join(tmp, 'media-library-file-v86.php'));
for (const name of ['initiative-workbench-v1.php', 'initiative-work-mission-v1.php', 'initiative-work-screen-v1.php',
  'initiative-work-product-v1.php', 'initiative-work-marketing-v1.php', 'initiative-marketing-plans-v1.php', 'media-collections-v1.php']) {
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

// Keep the real Commerce schema and SQL adapter in their own process so the
// Mission fixture's globals do not replace Commerce authorization or storage.
for (const ownerId of [1, 2]) {
  const folder = path.join(tmp, 'private/user-media', String(ownerId));
  fs.mkdirSync(folder, {recursive: true});
  fs.writeFileSync(path.join(folder, 'original.jpg'), photoBytes);
}
fs.writeFileSync(path.join(tmp, 'private/user-media/1/invalid.jpg'), 'invalid photo');
function png() {
  const chunk = (name, bytes) => {
    const type = Buffer.from(name), header = Buffer.alloc(4), checksum = Buffer.alloc(4);
    header.writeUInt32BE(bytes.length);
    checksum.writeUInt32BE(crc32(Buffer.concat([type, bytes])));
    return Buffer.concat([header, type, bytes, checksum]);
  };
  const dimensions = Buffer.alloc(13);
  dimensions.writeUInt32BE(32); dimensions.writeUInt32BE(32, 4);
  dimensions[8] = 8; dimensions[9] = 6;
  const row = Buffer.from([0, ...Array(32).fill([37, 97, 179, 255]).flat()]);
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', dimensions),
    chunk('IDAT', deflateSync(Buffer.concat(Array(32).fill(row)))), chunk('IEND', Buffer.alloc(0))]);
}
const pngBytes = png();
fs.writeFileSync(path.join(tmp, 'private/user-media/1/over-limit.png'), Buffer.concat([pngBytes, Buffer.alloc(photoLimit + 1 - pngBytes.length)]));
fs.writeFileSync(path.join(tmp, 'private/user-media/1/at-limit.png'), Buffer.concat([pngBytes, Buffer.alloc(photoLimit - pngBytes.length)]));
const mediaCollections = JSON.parse(runPHP('commerce-seed.php', `<?php
require ${phpString(path.join(root, 'tests/fixtures/commerce-recovery-pa2.php'))};
require ${phpString(path.join(root, 'includes/media-collections-v1.php'))};
$pdo=commerce_fixture_connect_pa2(['dsn'=>getenv('VP3_CLOUD_SCHEDULE_DSN'),'user'=>null,'pass'=>null,'driver'=>'sqlite']);
commerce_fixture_schema_pa2($pdo);
$pdo->exec("ALTER TABLE users ADD COLUMN display_name TEXT NOT NULL DEFAULT 'Owner';
    ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user';
    ALTER TABLE users ADD COLUMN avatar_path TEXT NOT NULL DEFAULT '';
    CREATE TABLE user_profiles(user_id INTEGER PRIMARY KEY,username TEXT,is_public INTEGER)");
$pdo->exec('DROP TABLE user_media_assets');
$pdo->exec('CREATE TABLE user_media_assets(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,media_type TEXT,title TEXT,
    mime_type TEXT,file_path TEXT,file_size INTEGER,duration_seconds INTEGER DEFAULT 0,source TEXT,metadata_json TEXT,
    created_at TEXT,updated_at TEXT)');
$insert=$pdo->prepare('INSERT INTO user_media_assets(id,user_id,media_type,title,mime_type,file_path,file_size,source,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?,?,?)');
$rows=[
    [1,1,'photo','Archived photo 100%_!','image/jpeg','1/original.jpg',${photoBytes.length},'openai_image'],
    [2,2,'photo','Foreign photo','image/jpeg','2/original.jpg',${photoBytes.length},'upload'],
    [3,1,'photo','Path escape','image/jpeg','2/original.jpg',${photoBytes.length},'upload'],
    [4,1,'video','Saved video','video/mp4','1/original.jpg',${photoBytes.length},'upload'],
    [5,1,'photo','Invalid photo','image/jpeg','1/invalid.jpg',13,'upload'],
    [6,1,'photo','Wrong MIME','image/webp','1/original.jpg',${photoBytes.length},'upload'],
    [7,1,'photo','Missing photo','image/jpeg','1/missing.jpg',${photoBytes.length},'upload'],
    [8,1,'photo','Oversized generated PNG','image/png','1/over-limit.png',100,'openai_image'],
    [9,1,'photo','Boundary generated PNG','image/png','1/at-limit.png',${photoLimit},'openai_image'],
    [10,1,'photo','Unsupported MIME','image/gif','1/original.jpg',${photoBytes.length},'upload'],
];
for($id=20;$id<=84;$id++)$rows[]=[$id,1,'photo','Recent photo '.$id,'image/jpeg','1/original.jpg',${photoBytes.length},'upload'];
foreach($rows as [$id,$owner,$type,$title,$mime,$file,$size,$source]){
    $insert->execute([$id,$owner,$type,$title,$mime,'private/user-media/'.$file,$size,$source,'2026-11-01 00:00:00','2026-11-01 00:00:00']);
}
vp3_media_collections_install_v1($pdo);
$archive=vp3_media_collection_create_v1($pdo,1,'Archive products');
$campaign=vp3_media_collection_create_v1($pdo,1,'Seasonal campaign');
$foreign=vp3_media_collection_create_v1($pdo,2,'Private other collection');
foreach([1,...range(20,50)] as $id)vp3_media_collection_set_asset_v1($pdo,1,$archive['id'],$id,true);
vp3_media_collection_set_asset_v1($pdo,1,$campaign['id'],1,true);
vp3_media_collection_set_asset_v1($pdo,2,$foreign['id'],2,true);
$product=$pdo->prepare("INSERT INTO agent_commerce_products_v800(id,owner_user_id,product_key,title,description,is_active,metadata_json)
    VALUES(?,?,?,'Saved mug','A blue ceramic mug.',0,'{}')");
$product->execute([90,1,'picker-owned-product']);$product->execute([91,2,'picker-foreign-product']);
echo json_encode(['archive'=>$archive['id'],'campaign'=>$campaign['id'],'foreign'=>$foreign['id']]);
`));

fs.writeFileSync(path.join(tmp, 'includes/bootstrap.php'), `<?php
declare(strict_types=1);
define('STONEFELLOW_ROOT',dirname(__DIR__));
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
function has_permission(string $permission,array $user): bool {
    return !empty($user['is_active']) && !in_array($permission,$_SESSION['denied_permissions']??[],true);
}
function personal_capability_has_v242(string $permission,array $user): bool {return has_permission($permission,$user);}
function subscription_has_entitlement(array $user,string $permission): bool {return has_permission($permission,$user);}
function setting(string $name,mixed $default=null): mixed {return $default;}
function url(string $path): string {return $path;}
function ai_provider_enabled(string $provider): bool {return $provider==='openai';}
function ai_provider_api_key(string $provider): string {return 'fixture-no-provider-call';}
function agent_commerce_provider_http_pa2(string $method,string $url,array $headers=[],mixed $body=null,bool $form=false): array {
    file_put_contents(dirname(__DIR__).'/unexpected-provider-call','1',FILE_APPEND|LOCK_EX);
    throw new RuntimeException('Workbench browsing and private drafts cannot contact a Commerce provider.');
}
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $aid,string $surface): array {
    return ['route'=>'vp3_cloud','cloud'=>['ready'=>true]];
}
function ai_generate_chat_response(string $prompt,array $history,array $context,array $user,string $scope,array $control,array $images=[]): array {
    if(in_array($control['protocol']??'', ['vp3.product-photo-draft.v1','vp3.product-text-draft.v1','vp3.product-marketing-draft.v1'],true)){
        if((int)$user['id']!==1||$context!==[])throw new RuntimeException('Wrong drafting owner or context.');
        $photo=($control['protocol']==='vp3.product-photo-draft.v1');
        if($images!==($photo?[${phpString(sourceJpeg)}]:[]))throw new RuntimeException('Drafting did not receive the exact selected photo.');
        file_put_contents(dirname(__DIR__).'/drafting-count',$control['protocol']."\\n",FILE_APPEND|LOCK_EX);
        return ['ok'=>true,'answer'=>$control['protocol']==='vp3.product-marketing-draft.v1'
            ?'{"headline":"Saved blue mug","copy":"A blue ceramic mug.","campaign_idea":"Introduce the mug in a product spotlight."}'
            :'{"title":"Saved blue mug","description":"A blue ceramic mug."}'];
    }
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
require_once ${phpString(path.join(root, 'includes/participant-updates-v1.php'))};
session_start();
if(!empty($_SESSION['participant']))$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_id'=>3];
`);

const owner = {cookie: 'PHPSESSID=fixture-owner', csrf: 'owner-csrf', key: '1', agent: 7, conversation: 11};
const ownerOtherSession = {...owner, cookie: 'PHPSESSID=fixture-owner-other'};
const other = {cookie: 'PHPSESSID=fixture-other', csrf: 'other-csrf', key: '2', agent: 8, conversation: 22};
const participant = {...owner, cookie: 'PHPSESSID=fixture-participant', extra: 'participant|b:1;'};
const forbiddenChat = {...owner, cookie: 'PHPSESSID=fixture-no-chat', extra: 'denied_permissions|a:1:{i:0;s:11:"chat.access";}'};
const forbiddenAccount = {...owner, cookie: 'PHPSESSID=fixture-no-account', extra: 'denied_permissions|a:1:{i:0;s:14:"account.access";}'};
for (const account of [owner, ownerOtherSession, other, participant, forbiddenChat, forbiddenAccount]) {
  const id = account.cookie.split('=')[1];
  fs.writeFileSync(path.join(tmp, 'sessions', 'sess_' + id),
    `user_id|i:${account.key};csrf|s:${account.csrf.length}:"${account.csrf}";${account.extra ?? ''}`);
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
const base = `http://127.0.0.1:${port}`;
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

async function browse(params = {}, account = owner, headers = {}) {
  const response = await fetch(base + '/api/media-library-v86.php?' + new URLSearchParams({action: 'browse', ...params}),
    {headers: {Cookie: account?.cookie ?? '', 'X-VP3-Media-Owner': account?.key ?? '1', ...headers}});
  const raw = await response.text();
  return {status: response.status, raw, data: JSON.parse(raw)};
}

function rejected(response, status) {
  assert.equal(response.status, status, response.raw);
  assert.equal(response.data.ok, false);
  assert.equal(response.data.output, undefined);
}

function executeSQL(sql) {
  return runPHP('state.php', `<?php $pdo=new PDO(getenv('VP3_CLOUD_SCHEDULE_DSN')); echo $pdo->query(${phpString(sql)})->fetchColumn();`).trim();
}

function mediaSnapshot() {
  return runPHP('media-state.php', `<?php
    $pdo=new PDO(getenv('VP3_CLOUD_SCHEDULE_DSN'),null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $state=[];
    foreach(['user_media_assets','vp3_media_collections_v1','vp3_media_collection_assets_v1','agent_commerce_products_v800',
        'agent_commerce_marketing_drafts_v1','agent_commerce_promotions_v1','agent_commerce_orders_v800',
        'agent_commerce_provider_connections_v800','agent_product_launches_v1',
        'agent_image_generations_v1','agent_image_generation_assets_v1'] as $table){
        $state[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();
    }
    $state['image_jobs']=$pdo->query("SELECT * FROM agent_workflow_runs WHERE source_kind='image_generation' ORDER BY id")->fetchAll();
    echo json_encode($state,JSON_THROW_ON_ERROR);
  `);
}

function draftingCalls() {
  const file = path.join(tmp, 'drafting-count');
  return fs.existsSync(file) ? fs.readFileSync(file, 'utf8').trim().split('\n') : [];
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

  const mediaBefore = mediaSnapshot();
  const oldPhoto = {source_id: '1'};
  const marketingPhoto = {...oldPhoto, product_id: 90};
  let productReview, marketingReview;
  await check('shared picker pages all owned photos and collections beyond the legacy latest 50', async () => {
    const legacy = accepted(await post('sources', 'product'));
    assert.equal(legacy.sources.length, 51);
    assert.equal(legacy.sources[0].source_id, 'description');
    assert.ok(!legacy.sources.some(source => source.source_id === '1'));
    const marketing = accepted(await post('sources', 'marketing'));
    assert.deepEqual(marketing.products.map(product => product.id), [90]);
    assert.ok(!marketing.sources.some(source => source.source_id === '1'));
    const selected = [];
    let before;
    do {
      const page = accepted(await browse({type: 'photo', ...(before ? {before} : {})}));
      assert.ok(page.assets.length <= 24);
      assert.ok(page.next_before === null || (Number.isInteger(page.next_before) && page.next_before > 0
        && page.next_before < (before ?? Infinity)), 'pagination must make forward progress');
      selected.push(...page.assets.map(asset => asset.id));
      before = page.next_before;
    } while (before);
    assert.equal(selected.length, 73);
    assert.equal(new Set(selected).size, selected.length);
    assert.equal(selected.at(-1), 1);
    assert.ok(!selected.includes(2));
    const first = accepted(await browse({collection_id: mediaCollections.archive, type: 'photo'}));
    assert.deepEqual(first.assets.map(asset => asset.id), Array.from({length: 24}, (_, i) => 50 - i));
    assert.equal(first.next_before, 27);
    const second = accepted(await browse({collection_id: mediaCollections.archive, type: 'photo', before: first.next_before}));
    assert.deepEqual(second.assets.map(asset => asset.id), [...Array.from({length: 7}, (_, i) => 26 - i), 1]);
    assert.equal(second.next_before, null);
    assert.deepEqual(first.collections, [
      {id: mediaCollections.archive, name: 'Archive products', count: 32},
      {id: mediaCollections.campaign, name: 'Seasonal campaign', count: 1},
    ]);
    const search = accepted(await browse({q: '100%_!', type: 'photo', source: 'generated', collection_id: mediaCollections.archive}));
    assert.deepEqual(search.assets.map(asset => asset.id), [1]);
    assert.deepEqual(search.assets[0].collection_ids, [mediaCollections.archive, mediaCollections.campaign]);
    assert.deepEqual(accepted(await browse({collection_id: mediaCollections.campaign})).assets, search.assets);
    assert.deepEqual(accepted(await browse({q: 'No matching archived photo'})).assets, []);
    assert.deepEqual(accepted(await browse({type: 'photo', source: 'generated'})).assets.map(asset => asset.id), [9, 8, 1]);
    for (const field of ['user_id', 'file_path', 'metadata_json']) assert.equal(Object.hasOwn(search.assets[0], field), false);
  });

  await check('exact Product and Marketing previews reuse an old library ID without uploads, jobs, drafts or inference', async () => {
    productReview = accepted(await post('preview', 'product', oldPhoto));
    marketingReview = accepted(await post('preview', 'marketing', marketingPhoto));
    for (const review of [productReview, marketingReview]) {
      assert.equal(review.preview.source_id, '1');
      assert.equal(review.preview.source_title, 'Archived photo 100%_!');
      assert.equal(review.preview.details.image_url, '/media-library-file-v86.php?id=1');
      assert.match(review.review_token, /^\d+\.[a-f0-9]{64}$/);
      assert.doesNotMatch(JSON.stringify(review), /data:image|base64|private\/user-media|file_path/);
    }
    const privateFile = base + productReview.preview.details.image_url;
    const bytes = await fetch(privateFile, {headers: {Cookie: owner.cookie}});
    assert.equal(bytes.status, 200);
    assert.deepEqual(Buffer.from(await bytes.arrayBuffer()), photoBytes);
    assert.equal((await fetch(privateFile, {headers: {Cookie: other.cookie}})).status, 404);
    assert.equal((await fetch(privateFile)).status, 403);
    assert.equal(mediaSnapshot(), mediaBefore);
    assert.deepEqual(draftingCalls(), []);
    assert.equal(fs.readFileSync(path.join(tmp, 'inference-count'), 'utf8'), '1');
    assert.equal(fs.existsSync(path.join(tmp, 'private/media-upload-v86')), false);
  });

  await check('Product and Marketing reject foreign, revoked, participant and invalid photo selections', async () => {
    rejected(await browse({}, null), 403);
    rejected(await browse({}, owner, {'X-VP3-Media-Owner': '2'}), 403);
    rejected(await browse({}, owner, {'X-VP3-Media-Owner': ''}), 403);
    rejected(await browse({}, forbiddenChat), 403);
    rejected(await browse({collection_id: mediaCollections.foreign}), 404);
    assert.deepEqual(accepted(await browse({}, other)).assets.map(asset => asset.id), [2]);
    for (const tool of ['product', 'marketing']) {
      const fields = tool === 'product' ? oldPhoto : marketingPhoto;
      rejected(await post('preview', tool, fields, null), 403);
      rejected(await post('preview', tool, fields, forbiddenChat), 403);
      rejected(await post('preview', tool, fields, forbiddenAccount), 404);
      rejected(await post('preview', tool, fields, participant), 404);
      rejected(await post('preview', tool, {...fields, owner_key: '2'}), 409);
      rejected(await post('preview', tool, {...fields, agent_id: 8}), 403);
      rejected(await post('preview', tool, {...fields, conversation_id: 22}), 403);
      for (const [source_id, status] of [['2', 404], ['3', 404], ['4', 404], ['5', 422], ['6', 422], ['7', 404], ['10', 422]]) {
        rejected(await post('preview', tool, {...fields, source_id}), status);
      }
    }
    rejected(await post('preview', 'marketing', {...marketingPhoto, product_id: 91}), 404);
    for (const title of ['Missing photo', 'Path escape']) {
      const asset = accepted(await browse({q: title})).assets[0];
      assert.equal(asset.available, false);
    }
    assert.equal(mediaSnapshot(), mediaBefore);
    assert.deepEqual(draftingCalls(), []);
  });

  await check('actual 4 MiB photo limit remains authoritative for generated PNGs in both workbenches', async () => {
    const oversized = accepted(await browse({q: 'Oversized generated PNG'})).assets[0];
    assert.equal(oversized.available, true);
    assert.equal(oversized.source, 'openai_image');
    assert.equal(oversized.mime_type, 'image/png');
    assert.equal(oversized.file_size, 100, 'saved size deliberately understates the actual file');
    for (const tool of ['product', 'marketing']) {
      const fields = tool === 'product' ? {} : {product_id: 90};
      rejected(await post('preview', tool, {...fields, source_id: '8'}), 422);
      const boundary = accepted(await post('preview', tool, {...fields, source_id: '9'}));
      assert.equal(boundary.preview.source_id, '9');
    }
    assert.equal(mediaSnapshot(), mediaBefore);
    assert.deepEqual(draftingCalls(), []);
  });

  await check('source bytes, selection, session, account and Chat changes invalidate reviewed reuse', async () => {
    for (const [tool, fields, review] of [['product', oldPhoto, productReview], ['marketing', marketingPhoto, marketingReview]]) {
      const approval = {...fields, confirmed: true, review_token: review.review_token};
      rejected(await post('create', tool, {...approval, confirmed: false}), 422);
      rejected(await post('create', tool, {...approval, source_id: '20'}), 409);
      rejected(await post('create', tool, {...approval, conversation_id: 12}), 409);
      rejected(await post('create', tool, approval, ownerOtherSession), 409);
      rejected(await post('create', tool, approval, participant), 404);
      executeSQL("UPDATE user_media_assets SET title='Changed after review' WHERE id=1");
      rejected(await post('create', tool, approval), 409);
      executeSQL("UPDATE user_media_assets SET title='Archived photo 100%_!' WHERE id=1");
      const originalFile = path.join(tmp, 'private/user-media/1/original.jpg');
      fs.writeFileSync(originalFile, Buffer.concat([photoBytes, Buffer.from('mutated')]));
      rejected(await post('create', tool, approval), 409);
      fs.writeFileSync(originalFile, photoBytes);
      fs.renameSync(originalFile, originalFile + '.unavailable');
      rejected(await post('create', tool, approval), 404);
      fs.renameSync(originalFile + '.unavailable', originalFile);
      executeSQL('UPDATE user_media_assets SET user_id=2 WHERE id=1');
      rejected(await post('create', tool, approval), 404);
      executeSQL('UPDATE user_media_assets SET user_id=1 WHERE id=1');
      executeSQL('UPDATE users SET is_active=0 WHERE id=1');
      rejected(await post('create', tool, approval), 403);
      executeSQL('UPDATE users SET is_active=1 WHERE id=1');
    }
    executeSQL("UPDATE agent_commerce_products_v800 SET title='Changed product' WHERE id=90");
    rejected(await post('create', 'marketing', {...marketingPhoto, confirmed: true, review_token: marketingReview.review_token}), 409);
    executeSQL("UPDATE agent_commerce_products_v800 SET title='Saved mug' WHERE id=90");
    assert.equal(mediaSnapshot(), mediaBefore);
    assert.deepEqual(draftingCalls(), []);
  });

  await check('confirmed reuse creates private Product and Marketing drafts while preserving one media asset in both collections', async () => {
    const before = JSON.parse(mediaBefore);
    const products = [];
    const drafts = [];
    for (const conversation_id of [11, 12]) {
      const fields = {...oldPhoto, conversation_id};
      const preview = accepted(await post('preview', 'product', fields));
      const approved = {...fields, confirmed: true, review_token: preview.review_token};
      const product = accepted(await post('create', 'product', approved)).output;
      assert.deepEqual(accepted(await post('create', 'product', approved)).output, product);
      assert.equal(product.source_id, '1');
      products.push(product.product_id);
      const marketingFields = {...marketingPhoto, conversation_id};
      const marketing = accepted(await post('preview', 'marketing', marketingFields));
      const marketingApproved = {...marketingFields, confirmed: true, review_token: marketing.review_token};
      const draft = accepted(await post('create', 'marketing', marketingApproved)).output;
      assert.deepEqual(accepted(await post('create', 'marketing', marketingApproved)).output, draft);
      assert.equal(draft.asset_id, 1);
      assert.equal(draft.product_id, 90);
      drafts.push(draft.draft_id);
    }
    assert.equal(new Set(products).size, 2);
    assert.equal(new Set(drafts).size, 2);
    assert.deepEqual(draftingCalls().sort(), [
      'vp3.product-marketing-draft.v1', 'vp3.product-marketing-draft.v1',
      'vp3.product-photo-draft.v1', 'vp3.product-photo-draft.v1',
    ]);
    const after = JSON.parse(mediaSnapshot());
    for (const table of ['user_media_assets', 'vp3_media_collections_v1', 'vp3_media_collection_assets_v1',
      'agent_commerce_promotions_v1', 'agent_commerce_orders_v800', 'agent_commerce_provider_connections_v800',
      'agent_image_generations_v1', 'agent_image_generation_assets_v1', 'image_jobs']) {
      assert.deepEqual(after[table], before[table], table + ' remains unchanged by source reuse');
    }
    assert.equal(after.agent_commerce_products_v800.length, before.agent_commerce_products_v800.length + 2);
    assert.equal(after.agent_commerce_marketing_drafts_v1.length, 2);
    for (const row of after.agent_commerce_products_v800.filter(product => products.includes(product.id))) {
      const metadata = JSON.parse(row.metadata_json);
      assert.equal(row.is_active, 0);
      assert.equal(row.price_cents, 0);
      assert.equal(metadata.profile_visibility, 'hidden');
      assert.equal(metadata.source_asset_id, '1');
      assert.equal(metadata.agent_id, 7);
      assert.ok([11, 12].includes(metadata.conversation_id));
    }
    assert.deepEqual(fs.readFileSync(path.join(tmp, 'private/user-media/1/original.jpg')), photoBytes);
    assert.equal(fs.existsSync(path.join(tmp, 'private/media-upload-v86')), false);
    assert.equal(fs.existsSync(path.join(tmp, 'unexpected-provider-call')), false);
  });

  await check('marketing plan reads resolve canonical bindings across the current Agent’s original Chats without side effects', async () => {
    const before = mediaSnapshot();
    const calls = draftingCalls();
    const data = accepted(await post('marketing_plans', 'marketing'));
    assert.equal(data.owner_key, '1');
    assert.equal(data.next_before, null);
    assert.equal(data.plans.length, 3);
    assert.deepEqual(data.plans.map(plan => plan.stage), ['listing_review', 'listing_review', 'listing_unavailable']);
    assert.deepEqual(data.plans.map(plan => plan.conversation_id), [12, 11, 11]);
    for (const plan of data.plans) {
      assert.equal(plan.agent_id, 7);
      assert.equal(plan.needs_attention, false);
      assert.equal(plan.resume.owner_user_id, 1);
      assert.equal(plan.resume.product_id, plan.product_id);
      assert.equal(plan.image.id, 1);
      assert.equal(plan.image.url, '/media-library-file-v86.php?id=1');
      assert.equal(plan.chat_url, '/chat.php?agent=7&conversation_id=' + plan.conversation_id + '&marketing_plan=' + plan.product_id);
      const fresh = accepted(await post('marketing_plan', 'marketing', {
        product_id: plan.product_id, source_id: '2', draft_id: 'f'.repeat(64), creative_id: 'f'.repeat(32),
      }));
      assert.deepEqual(fresh.plan, plan, 'client source/draft/creative claims cannot retarget a plan');
      assert.equal(fresh.review_token, undefined);
      assert.equal(fresh.output, undefined);
    }
    assert.deepEqual(accepted(await post('marketing_plans', 'marketing', {conversation_id: 12})).plans, data.plans);
    assert.doesNotMatch(JSON.stringify(data), /file_path|private\/user-media|base64|last_notice_key|next_check_at/);
    executeSQL("UPDATE user_media_assets SET mime_type='image/webp' WHERE id=1");
    const wrongFormat = accepted(await post('marketing_plan', 'marketing', {product_id: data.plans[0].product_id})).plan;
    assert.equal(wrongFormat.stage, 'needs_attention');
    assert.equal(wrongFormat.resume, null);
    assert.equal(wrongFormat.image, null);
    executeSQL("UPDATE user_media_assets SET mime_type='image/jpeg' WHERE id=1");
    const savedPhoto = path.join(tmp, 'private/user-media/1/original.jpg');
    fs.renameSync(savedPhoto, savedPhoto + '.missing');
    const stale = accepted(await post('marketing_plan', 'marketing', {product_id: data.plans[0].product_id})).plan;
    assert.equal(stale.stage, 'needs_attention');
    assert.equal(stale.needs_attention, true);
    assert.equal(stale.resume, null);
    assert.equal(stale.image, null);
    fs.renameSync(savedPhoto + '.missing', savedPhoto);
    assert.deepEqual(accepted(await post('marketing_plan', 'marketing', {product_id: data.plans[0].product_id})).plan, data.plans[0]);
    assert.equal(mediaSnapshot(), before);
    assert.deepEqual(draftingCalls(), calls);
    assert.equal(fs.existsSync(path.join(tmp, 'unexpected-provider-call')), false);
  });

  await check('marketing plan API applies session, account, Agent, Chat and participant authority to list and single reads', async () => {
    const before = mediaSnapshot();
    const product_id = accepted(await post('marketing_plans', 'marketing')).plans[0].product_id;
    for (const action of ['marketing_plans', 'marketing_plan']) {
      rejected(await post(action, 'marketing', {product_id}, null), 403);
      rejected(await post(action, 'marketing', {product_id}, forbiddenChat), 403);
      rejected(await post(action, 'marketing', {product_id}, forbiddenAccount), 404);
      rejected(await post(action, 'marketing', {product_id}, participant), 404);
      rejected(await post(action, 'marketing', {product_id, owner_key: '2'}), 409);
      rejected(await post(action, 'marketing', {product_id, csrf_token: 'wrong'}), 419);
      rejected(await post(action, 'marketing', {product_id, agent_id: 8}), 403);
      rejected(await post(action, 'marketing', {product_id, conversation_id: 22}), 403);
      rejected(await post(action, 'product', {product_id}), 422);
    }
    assert.deepEqual(accepted(await post('marketing_plans', 'marketing', {}, other)).plans, []);
    rejected(await post('marketing_plan', 'marketing', {product_id}, other), 404);
    assert.deepEqual(accepted(await post('marketing_plans', 'marketing', {agent_id: 0, conversation_id: 13})).plans, []);
    rejected(await post('marketing_plan', 'marketing', {product_id, agent_id: 0, conversation_id: 13}), 404);
    for (const value of [null, 0, -1, '90', 1.5, Number.MAX_SAFE_INTEGER + 1]) {
      rejected(await post('marketing_plan', 'marketing', {product_id: value}), 422);
      rejected(await post('marketing_plans', 'marketing', {before: value}), 422);
    }
    executeSQL('UPDATE chat_conversations SET user_agent_id=NULL WHERE id=12');
    rejected(await post('marketing_plan', 'marketing', {product_id}), 404);
    assert.ok(!accepted(await post('marketing_plans', 'marketing')).plans.some(plan => plan.product_id === product_id));
    executeSQL('UPDATE chat_conversations SET user_agent_id=7 WHERE id=12');
    assert.equal(mediaSnapshot(), before);
  });

  await check('marketing plans use bounded stable pagination and report missing storage without installing it', async () => {
    runPHP('plan-page-seed.php', `<?php
      require ${phpString(path.join(root, 'tests/fixtures/commerce-recovery-pa2.php'))};
      $pdo=commerce_fixture_connect_pa2(['dsn'=>getenv('VP3_CLOUD_SCHEDULE_DSN'),'user'=>null,'pass'=>null,'driver'=>'sqlite']);
      $meta=json_encode(['listing_draft'=>'product_text_v1','profile_visibility'=>'hidden','agent_id'=>7,'conversation_id'=>11]);
      $product=$pdo->prepare("INSERT INTO agent_commerce_products_v800(owner_user_id,product_key,title,is_active,metadata_json)
          VALUES(1,?,'Archived plan',0,?)");
      $launch=$pdo->prepare('INSERT INTO agent_product_launches_v1(product_id,owner_user_id,agent_id,conversation_id) VALUES(?,1,7,11)');
      for($index=0;$index<14;$index++){
          $product->execute(['plan-page-'.$index,$meta]);
          $launch->execute([(int)$pdo->lastInsertId()]);
      }
    `);
    const before = mediaSnapshot();
    const first = accepted(await post('marketing_plans', 'marketing'));
    assert.equal(first.plans.length, 12);
    assert.equal(first.next_before, first.plans.at(-1).product_id);
    const second = accepted(await post('marketing_plans', 'marketing', {before: first.next_before}));
    assert.equal(second.plans.length, 5);
    assert.equal(second.next_before, null);
    const ids = [...first.plans, ...second.plans].map(plan => plan.product_id);
    assert.equal(new Set(ids).size, 17);
    assert.deepEqual(ids, [...ids].sort((a, b) => b - a));
    assert.equal(mediaSnapshot(), before);
    executeSQL('ALTER TABLE agent_product_launches_v1 RENAME TO fixture_unavailable_launches');
    rejected(await post('marketing_plans', 'marketing'), 503);
    rejected(await post('marketing_plan', 'marketing', {product_id: ids[0]}), 503);
    assert.equal(executeSQL("SELECT COUNT(*) FROM sqlite_master WHERE name='agent_product_launches_v1'"), '0');
    executeSQL('ALTER TABLE fixture_unavailable_launches RENAME TO agent_product_launches_v1');
    assert.equal(mediaSnapshot(), before);
    assert.equal(fs.existsSync(path.join(tmp, 'unexpected-provider-call')), false);
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
