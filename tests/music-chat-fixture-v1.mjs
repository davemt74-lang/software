import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {execFileSync, spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Actual Music Chat/API/players with isolated auth and explicit external YouTube transport. */
export async function startMusicChatFixture() {
  const php = process.env.PHP_BINARY || 'php';
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-music-chat-'));
  let server, logs = '';
  const close = () => { server?.kill(); fs.rmSync(root, {recursive:true, force:true}); };
  try {
    for (const directory of ['includes', 'assets']) fs.cpSync(path.join(repository, directory), path.join(root, directory), {recursive:true});
    for (const file of ['api/music-chat-v1.php', 'api/player-library-v76.php', 'api/playback.php',
      'api/chat-youtube-playback-v1.php', 'media.php', 'chat.js', 'chat.css', 'chat-youtube-v1.js',
      'chat-youtube-v1.css', 'chat-legacy-v108.php', 'tests/fixtures/music-chat-library-v1.php',
      'tests/fixtures/music-chat-v1.php']) {
      fs.mkdirSync(path.dirname(path.join(root, file)), {recursive:true});
      fs.copyFileSync(path.join(repository, file), path.join(root, file));
    }
    fs.writeFileSync(path.join(root, 'includes/bootstrap.php'), `<?php
declare(strict_types=1);
define('STONEFELLOW_ROOT',dirname(__DIR__));
session_save_path(STONEFELLOW_ROOT);
session_start();
require STONEFELLOW_ROOT.'/tests/fixtures/music-chat-v1.php';
music_chat_http_bootstrap_v1();
`);
    const run = (code, ...args) => execFileSync(php, ['-r', 'require $argv[1];' + code,
      path.join(root, 'includes/bootstrap.php'), ...args], {encoding:'utf8', cwd:repository});
    run('music_chat_http_install_v1();');
    fs.writeFileSync(path.join(root, '_fixture.php'), `<?php
require __DIR__.'/includes/bootstrap.php';
music_chat_http_route_v1();
`);
    fs.writeFileSync(path.join(root, 'chat.php'), `<?php
require __DIR__.'/includes/bootstrap.php';
music_chat_http_page_v1();
`);
    fs.writeFileSync(path.join(root, 'api/chat.php'), `<?php
require dirname(__DIR__).'/includes/bootstrap.php';
music_chat_http_chat_v1();
`);
    const socket = net.createServer();
    await new Promise((resolve, reject) => { socket.once('error', reject); socket.listen(0, '127.0.0.1', resolve); });
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    const baseURL = `http://127.0.0.1:${port}`;
    fs.writeFileSync(path.join(root, 'fixture-origin.txt'), baseURL);
    server = spawn(php, ['-d', 'display_errors=stderr', '-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', root], {stdio:['ignore','pipe','pipe']});
    server.stderr.on('data', bytes => { logs += bytes; });
    server.stdout.on('data', bytes => { logs += bytes; });
    server.on('error', error => { logs += error.stack; });
    let ready = false;
    for (let attempt = 0; attempt < 60; attempt++) {
      try { const response = await fetch(baseURL + '/_fixture.php?action=session'); if (response.ok) { ready = true; break; } }
      catch {}
      if (server.exitCode !== null) break;
      await new Promise(resolve => setTimeout(resolve, 50));
    }
    assert.ok(ready, 'Music Chat PHP fixture failed to start: ' + logs);
    const json = async response => {
      const body = await response.text();
      try { return JSON.parse(body); }
      catch { throw new Error(`Invalid JSON (${response.status}): ${body}\n${logs}`); }
    };
    const auth = async (user = 11) => {
      const response = await fetch(baseURL + '/_fixture.php?action=session', {headers:{'X-User':String(user)}});
      const data = await json(response);
      const session = response.headers.get('set-cookie')?.split(';')[0];
      assert.ok(data.csrf_token && session, 'Could not initialize the fixture PHP session.');
      const listener = crypto.randomBytes(32).toString('hex');
      const cookie = `${session}; sf_listener=${listener}`;
      return {user, csrf:data.csrf_token, cookie, listener,
        headers:{'X-User':String(user), Cookie:cookie, Accept:'application/json'}};
    };
    const initial = await auth();
    const get = (route, {user = 11, auth:credentials = initial, headers = {}} = {}) => fetch(baseURL + route,
      {headers:{...credentials.headers, 'X-User':String(user), ...headers}});
    const postJson = (route, fields, {user = 11, auth:credentials = initial, token = credentials.csrf, headers = {}} = {}) => fetch(baseURL + route,
      {method:'POST', headers:{...credentials.headers, 'X-User':String(user), 'Content-Type':'application/json', ...headers},
        body:JSON.stringify({csrf_token:token, ...fields})});
    const request = (action, fields = {}, options = {}) => postJson('/api/music-chat-v1.php',
      {action, agent_id:7, conversation_id:42, ...fields}, options);
    const fixtureCall = async (action, fields = {}, options = {}) => {
      const response = await postJson('/_fixture.php', {action, ...fields}, options);
      const data = await json(response);
      assert.equal(response.status, 200, `${action}: ${JSON.stringify(data)}\n${logs}`);
      return data;
    };
    const readDb = (sql, parameters = []) => JSON.parse(run(
      '$q=db()->prepare($argv[2]);$q->execute(json_decode($argv[3],true,512,JSON_THROW_ON_ERROR));echo json_encode($q->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);', sql, JSON.stringify(parameters)));
    const requests = () => fs.existsSync(path.join(root, 'fixture-requests.jsonl'))
      ? fs.readFileSync(path.join(root, 'fixture-requests.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map(line => JSON.parse(line)) : [];
    return {root, baseURL, base:baseURL, php, run, readDb, json, auth, get, postJson, request, requests, close,
      seed:(fields = {}, options = {}) => fixtureCall('seed', fields, options),
      control:(fields = {}, options = {}) => fixtureCall('control', fields, options),
      csrf:initial.csrf, cookie:initial.cookie, listener:initial.listener, credentials:initial,
      get logs() { return logs; }};
  } catch (error) { close(); throw error; }
}

export const musicChatFixture = startMusicChatFixture;
