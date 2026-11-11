import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import fs from 'node:fs/promises';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const source = fileURLToPath(new URL('../', import.meta.url));
const root = await fs.mkdtemp(path.join(os.tmpdir(), 'vp3-coding-settings-api-'));
const probe = net.createServer();
await new Promise(resolve => probe.listen(0, '127.0.0.1', resolve));
const port = probe.address().port;
await new Promise(resolve => probe.close(resolve));
const base = `http://127.0.0.1:${port}`;
const endpoint = 'api/coding-canvas-settings-v1.php';
await fs.mkdir(path.join(root, 'api'));
await fs.mkdir(path.join(root, 'includes'));
await fs.copyFile(path.join(source, endpoint), path.join(root, endpoint));
await fs.copyFile(path.join(source, 'tests/fixtures/coding-canvas-settings-http-bootstrap.php'), path.join(root, 'includes/bootstrap.php'));
await fs.writeFile(path.join(root, 'includes/coding-canvas-model-v1.php'), '<?php require_once getenv("VP3_CODING_SETTINGS_TEST_SOURCE")."/includes/coding-canvas-model-v1.php";');
await fs.writeFile(path.join(root, 'ready.txt'), 'ready');
let logs = '';
const server = spawn(process.env.VP3_PHP_BINARY || 'php', ['-S', `127.0.0.1:${port}`, '-t', root], {
  env:{...process.env, VP3_CODING_SETTINGS_TEST_SOURCE:source}, stdio:['ignore', 'ignore', 'pipe']
});
server.stderr.on('data', chunk => { logs += chunk; });

const haiku = {provider:'anthropic', model:'claude-haiku-5-5'};
const sonnet = {provider:'anthropic', model:'claude-sonnet-5'};
const haikuOption = {...haiku, label:'Claude Haiku 5.5'};
const sonnetOption = {...sonnet, label:'Claude Sonnet 5'};
const valid = {model:haiku, csrf_token:'coding-settings-fixture'};

async function call(status, {method='GET', headers={}, body}={}) {
  const response = await fetch(`${base}/${endpoint}`, {method, headers:{...headers,
    ...(method === 'GET' ? {'X-Fixture-Read-Only':'1'} : {'Content-Type':'application/json'})},
    ...(body === undefined ? {} : {body:JSON.stringify(body)})});
  const text = await response.text();
  assert.equal(response.status, status, text);
  assert.equal(response.headers.get('cache-control'), 'no-store');
  const data = JSON.parse(text);
  assert.equal(data.ok, status === 200, text);
  assert.doesNotMatch(text, /fixture-coding-system-credential|sbx1:|gcm1:/);
  return data;
}

try {
  for (let attempt=0;;attempt++) {
    try { if ((await fetch(base+'/ready.txt')).ok) break; } catch {}
    if (attempt > 60) throw new Error('PHP Coding Agent settings fixture did not start.');
    await new Promise(resolve => setTimeout(resolve, 50));
  }

  const automatic = await call(200);
  assert.deepEqual(automatic, {ok:true, state:{funding:'system', selected:null, default:sonnetOption,
    effective:sonnetOption, options:[haikuOption, sonnetOption], unavailable:''}, balance:{unlimited:true}});
  const saved = await call(200, {method:'POST', body:valid});
  assert.deepEqual(saved.state, {...automatic.state, selected:haiku, effective:haikuOption});
  assert.deepEqual(await call(200), saved, 'An independent HTTP request loads the exact saved model.');
  assert.deepEqual(await call(200, {headers:{'X-Fixture-User':'2'}}), automatic, 'Another member starts with Automatic.');

  for (const model of ['420', 'claude-haiku-4-5']) {
    await call(403, {method:'POST', body:{...valid, model:{provider:'anthropic', model}}});
  }
  await call(419, {method:'POST', body:{...valid, csrf_token:'wrong-token'}});
  await call(422, {method:'POST', body:{...valid, model:{...haiku, label:'Injected label'}}});
  await call(401, {headers:{'X-Fixture-Anonymous':'1'}});
  assert.deepEqual(await call(200), saved, 'Rejected writes leave the saved model intact.');

  assert.deepEqual(await call(200, {method:'POST', body:{...valid, model:null}}), automatic);
  assert.deepEqual(await call(200), automatic, 'Reset persists and uses the admin’s Automatic model.');
  console.log('Coding Agent settings HTTP passed for approved models, Automatic, exact member selection and reload, and credential privacy.');
} catch (error) {
  console.error(logs.slice(-5000));
  throw error;
} finally {
  server.kill('SIGTERM');
  if (server.exitCode === null) await new Promise(resolve => server.once('exit', resolve));
  await fs.rm(root, {recursive:true, force:true});
}
