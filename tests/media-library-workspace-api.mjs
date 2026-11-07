import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { crc32, deflateSync } from 'node:zlib';

const php = process.env.VP3_PHP_BINARY || 'php';
const root = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-media-library-'));
const bootstrap = path.join(root, 'includes/bootstrap.php');
const route = '/api/media-library-v86.php';

function png(width, height, pixel) {
  const chunk = (name, bytes) => {
    const type = Buffer.from(name);
    const header = Buffer.alloc(4);
    const checksum = Buffer.alloc(4);
    header.writeUInt32BE(bytes.length);
    checksum.writeUInt32BE(crc32(Buffer.concat([type, bytes])));
    return Buffer.concat([header, type, bytes, checksum]);
  };
  const dimensions = Buffer.alloc(13);
  dimensions.writeUInt32BE(width, 0);
  dimensions.writeUInt32BE(height, 4);
  dimensions[8] = 8;
  dimensions[9] = 6;
  const row = Buffer.from([0, ...Array(width).fill(pixel).flat()]);
  return Buffer.concat([
    Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
    chunk('IHDR', dimensions),
    chunk('IDAT', deflateSync(Buffer.concat(Array(height).fill(row)))),
    chunk('IEND', Buffer.alloc(0))
  ]);
}

const original = png(2, 3, [37, 97, 179, 255]);
const edited = png(3, 2, [37, 97, 179, 100]);

fs.mkdirSync(path.join(root, 'api'));
fs.mkdirSync(path.join(root, 'includes'));
for (const file of ['api/media-library-v86.php', 'includes/media-studio-v86.php', 'includes/agent-image-provider-v1.php', 'media-library-file-v86.php', 'media-library.php']) {
  fs.copyFileSync(file, path.join(root, file));
}
for (const file of ['main-sidebar.php', 'member-header.php']) {
  fs.writeFileSync(path.join(root, 'includes', file), '<?php // Shared shell covered by the browser suite.');
}

fs.writeFileSync(bootstrap, `<?php
define('STONEFELLOW_ROOT', dirname(__DIR__));
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('sqlite:'.STONEFELLOW_ROOT.'/media.sqlite', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->sqliteCreateFunction('NOW', fn()=>gmdate('Y-m-d H:i:s'));
    }
    return $pdo;
}
function table_exists($name): bool {
    $stmt=db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$name]);
    return (bool)$stmt->fetchColumn();
}
function current_user(): ?array {
    $id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);
    return $id>0?['id'=>$id,'is_active'=>1]:null;
}
function has_permission($permission, $user): bool {
    return $permission==='chat.access' && !isset($_SERVER['HTTP_X_FIXTURE_DENIED']);
}
function csrf_token(): string { return 'fixture'; }
function url($path): string { return $path; }
function redirect($path): never { header('Location: '.$path, true, 302); exit; }
function e($text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function system_agent_name(): string { return 'Fixture Agent'; }
require __DIR__.'/media-studio-v86.php';
`);

const seed = [
  [1, 1, 'photo', 'Blue mug 100%_!', 'image/png', 'upload'],
  [2, 1, 'photo', 'Blue mug 100XA!', 'image/png', 'capture'],
  [3, 1, 'photo', 'Generated blue mug', 'image/png', 'openai_image'],
  [4, 1, 'photo', 'Earlier edit', 'image/png', 'image_editor'],
  [5, 1, 'video', 'Mug making video', 'video/mp4', 'capture'],
  [6, 1, 'audio', 'Mug recording', 'audio/mpeg', 'capture'],
  [7, 1, 'photo', 'Missing photo', 'image/png', 'upload'],
  ...Array.from({ length: 25 }, (_, i) => [8 + i, 1, 'photo', `Photo ${8 + i}`, 'image/png', 'upload']),
  [40, 2, 'photo', 'Blue mug 100%_!', 'image/png', 'upload']
];
for (const owner of [1, 2]) {
  const dir = path.join(root, 'private/user-media', String(owner));
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(path.join(dir, 'fixture.png'), original);
}
execFileSync(php, ['-r', `
require $argv[1];
db()->exec('CREATE TABLE user_media_assets (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,media_type TEXT,title TEXT,mime_type TEXT,file_path TEXT,file_size INTEGER,duration_seconds REAL,source TEXT,metadata_json TEXT,created_at TEXT,updated_at TEXT); CREATE TABLE video_editor_projects(id INTEGER PRIMARY KEY)');
$insert=db()->prepare('INSERT INTO user_media_assets VALUES(?,?,?,?,?,?,?,0,?,NULL,NOW(),NOW())');
foreach(json_decode($argv[2],true) as [$id,$owner,$type,$title,$mime,$source]) {
    $file='private/user-media/'.$owner.'/'.($id===7?'missing.png':'fixture.png');
    $insert->execute([$id,$owner,$type,$title,$mime,$file,(int)$argv[3],$source]);
}
`, bootstrap, JSON.stringify(seed), String(original.length)]);

const socket = net.createServer();
await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', root], { stdio: ['ignore', 'ignore', 'pipe'] });
let logs = '';
server.stderr.on('data', data => { logs += data; });
const base = `http://127.0.0.1:${port}`;
const owner = { 'X-Fixture-User': '1', 'X-VP3-Media-Owner': '1' };
const foreign = { 'X-Fixture-User': '2', 'X-VP3-Media-Owner': '2' };

async function read(params = {}, headers = owner, action = 'browse') {
  const response = await fetch(`${base}${route}?${new URLSearchParams({ action, ...params })}`, { headers });
  return { response, data: await response.json() };
}

async function post(action, data = {}, csrf = 'fixture', headers = owner) {
  const body = new FormData();
  for (const [key, value] of Object.entries({ action, csrf_token: csrf, ...data })) body.append(key, value);
  const response = await fetch(`${base}${route}`, { method: 'POST', headers, body });
  return { response, data: await response.json() };
}

async function upload(title, bytes, source = 'upload', metadata = {}) {
  const { data: begin } = await post('begin', { title, source, media_type: 'photo', mime_type: 'image/png', original_name: 'fixture.png' });
  assert.equal(begin.ok, true, JSON.stringify(begin));
  const { data: chunk } = await post('chunk', { token: begin.token, index: '0', total_chunks: '1', chunk: new Blob([bytes], { type: 'image/png' }) });
  assert.equal(chunk.ok, true, JSON.stringify(chunk));
  return post('finish', { token: begin.token, metadata_json: JSON.stringify(metadata) });
}

const ids = result => result.data.assets.map(asset => asset.id);
const ownedBytes = async asset => {
  const response = await fetch(base + asset.url, { headers: owner });
  assert.equal(response.status, 200);
  assert.match(response.headers.get('content-type'), /^image\/png/);
  return Buffer.from(await response.arrayBuffer());
};

try {
  let ready = false;
  for (let attempt = 0; attempt < 100; attempt++) {
    try { await fetch(base + '/ready'); ready = true; break; } catch { await new Promise(resolve => setTimeout(resolve, 30)); }
  }
  assert.ok(ready, 'PHP fixture did not start');

  const page = base + '/media-library.php';
  const signIn = await fetch(page, { redirect: 'manual' });
  assert.equal(signIn.status, 302);
  assert.equal(signIn.headers.get('location'), '/login.php');
  assert.equal((await fetch(page, { headers: { ...owner, 'X-Fixture-Denied': '1' } })).status, 403);
  const workspace = await fetch(page, { headers: owner });
  assert.equal(workspace.status, 200);
  assert.match(workspace.headers.get('cache-control'), /no-store/);
  assert.match(await workspace.text(), /data-media-endpoint="\/api\/media-library-v86\.php"/);

  assert.equal((await read({}, {})).response.status, 403);
  assert.equal((await read({}, { ...owner, 'X-Fixture-Denied': '1' })).response.status, 403);
  assert.equal((await read({}, { ...owner, 'X-Fixture-User': '2' })).response.status, 403);
  assert.equal((await read({}, { 'X-Fixture-User': '1' })).response.status, 403);
  assert.equal((await post('begin', {}, 'fixture', { ...owner, 'X-Fixture-User': '2' })).response.status, 403);
  assert.deepEqual(ids(await read({}, foreign)), [40]);
  assert.deepEqual(ids(await read({ type: 'photo', source: 'generated' })), [3]);
  assert.deepEqual(ids(await read({ type: 'photo', source: 'edited' })), [4]);
  assert.deepEqual(ids(await read({ type: 'video', source: 'uploaded' })), [5]);
  assert.deepEqual(ids(await read({ type: 'audio' })), [6]);
  assert.deepEqual(ids(await read({ type: 'audio', source: 'generated' })), []);
  const search = await read({ q: '100%_!' });
  assert.deepEqual(ids(search), [1]);
  const sourceAsset = search.data.assets[0];
  for (const key of ['user_id', 'file_path', 'metadata_json']) assert.equal(Object.hasOwn(sourceAsset, key), false);
  assert.equal(sourceAsset.available, true);
  const missing = (await read({ q: 'Missing photo' })).data.assets[0];
  assert.equal(missing.available, false);
  assert.equal((await fetch(base + missing.url, { headers: owner })).status, 404);
  assert.deepEqual(await ownedBytes(sourceAsset), original);
  assert.equal((await fetch(base + sourceAsset.url, { headers: foreign })).status, 404);
  assert.equal((await fetch(base + sourceAsset.url)).status, 403);

  const first = await read();
  assert.deepEqual(ids(first), Array.from({ length: 24 }, (_, i) => 32 - i));
  assert.equal(first.data.next_before, 9);
  assert.equal((await post('begin', { media_type: 'photo', mime_type: 'image/png' }, 'wrong')).response.status, 419);
  const fresh = await upload('New photo', original);
  assert.equal(fresh.data.ok, true);
  assert.equal((await read()).data.assets[0].id, fresh.data.asset.id);
  const second = await read({ before: first.data.next_before });
  assert.deepEqual(ids(second), Array.from({ length: 8 }, (_, i) => 8 - i));
  assert.ok(!second.data.next_before);

  const copy = await upload('Mug edited copy', edited, 'image_editor', { source_asset_id: 1, width: 99, height: 99 });
  assert.equal(copy.data.ok, true, JSON.stringify(copy.data));
  const saved = copy.data.asset;
  assert.equal(saved.source, 'image_editor');
  assert.notEqual(saved.id, sourceAsset.id);
  assert.deepEqual(await ownedBytes(saved), edited);
  assert.deepEqual(await ownedBytes(sourceAsset), original);
  const metadata = JSON.parse(execFileSync(php, ['-r', `
require $argv[1];
$row=media_studio_asset((int)$argv[2],['id'=>1]);
echo $row['metadata_json'];
`, bootstrap, String(saved.id)], { encoding: 'utf8' }));
  assert.equal(metadata.source_asset_id, 1);
  assert.equal(metadata.width, 3);
  assert.equal(metadata.height, 2);
  assert.deepEqual(ids(await read({ source: 'edited' })), [saved.id, 4]);
  assert.equal((await read({ source: 'uploaded', q: 'Mug edited copy' })).data.assets.length, 0);

  for (const [kind, source] of [
    ['image_editor', {}], ['IMAGE_EDITOR', {}], ['image_editor', { source_asset_id: 40 }],
    ['image_editor', { source_asset_id: 5 }], ['image_editor', { source_asset_id: 7 }]
  ]) {
    const rejected = await upload('Invalid edited copy', edited, kind, source);
    assert.equal(rejected.response.status, 400);
    assert.equal(rejected.data.ok, false);
  }
  const spoofed = await upload('Invalid edited copy', Buffer.from('not a png'), 'image_editor', { source_asset_id: 1 });
  assert.equal(spoofed.response.status, 400);
  assert.equal(spoofed.data.ok, false);
  assert.deepEqual(ids(await read({ q: 'Invalid edited copy' })), []);
  for (const source of ['openai_image', 'OpenAI_Image']) {
    const relabeled = await upload('Untrusted generated source', original, source);
    assert.equal(relabeled.data.ok, true);
    assert.equal(relabeled.data.asset.source, 'upload');
  }
  assert.deepEqual(ids(await read({ source: 'generated' })), [3]);

  const list = await read({}, { 'X-Fixture-User': '1' }, 'list');
  assert.equal(list.data.ok, true);
  assert.equal(list.data.assets.length, 36);
  assert.ok(!ids(list).includes(40));
  execFileSync(php, ['-r', `require $argv[1];
db()->exec("CREATE TABLE agent_commerce_promotions_v1 (id TEXT PRIMARY KEY, owner_user_id INTEGER, status TEXT)");
$q=db()->prepare("INSERT INTO agent_commerce_promotions_v1 VALUES(?,?,?)");
$q->execute([str_repeat('a',32),1,'designing']);
$q->execute([str_repeat('b',32),2,'designing']);
$q->execute([str_repeat('c',32),1,'published']);`, bootstrap]);
  for (const creative_id of ['b'.repeat(32), 'c'.repeat(32), 'not-a-variant']) {
    assert.equal((await upload('Unapproved promotion upload', edited, 'promotion_design', {creative_id})).response.status, 400);
  }
  assert.equal((await upload('Truncated promotion', edited.subarray(0,33), 'promotion_design',
    {creative_id:'a'.repeat(32)})).response.status, 400);
  const design = await upload('Promotion artwork', edited, 'promotion_design', {creative_id:'a'.repeat(32)});
  assert.equal(design.data.asset.source, 'promotion_design');
  assert.deepEqual(await ownedBytes(design.data.asset), edited);
  assert.equal((await fetch(base + design.data.asset.url, {headers:foreign})).status, 404);
  console.log('MEDIA_LIBRARY_WORKSPACE_API=PASS (page authorization, owner scope, literal filters, paging, private bytes, edited-copy lineage, upload validation)');
} catch (error) {
  console.error(logs.slice(-3000));
  throw error;
} finally {
  server.kill();
  await new Promise(resolve => server.exitCode !== null || server.signalCode !== null ? resolve() : server.once('exit', resolve));
  fs.rmSync(root, { recursive: true, force: true });
}
