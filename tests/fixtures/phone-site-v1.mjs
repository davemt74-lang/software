import { execFileSync } from 'node:child_process';
import { cpSync, mkdirSync, readdirSync, symlinkSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import path from 'node:path';

export async function localPort() {
  const listener = createServer();
  await new Promise((resolve, reject) => listener.once('error', reject).listen(0, '127.0.0.1', resolve));
  const { port } = listener.address();
  await new Promise(resolve => listener.close(resolve));
  return port;
}

export function preparePhoneSite(repo, site, sessions, php) {
  mkdirSync(site);
  mkdirSync(sessions);
  for (const folder of ['includes', 'api']) cpSync(path.join(repo, folder), path.join(site, folder), { recursive: true });
  for (const folder of ['assets', 'images']) symlinkSync(path.join(repo, folder), path.join(site, folder), 'dir');
  for (const entry of readdirSync(repo, { withFileTypes: true })) {
    if (entry.isFile() && entry.name !== 'config.php' && /\.(php|css|js|webmanifest)$/.test(entry.name)) {
      cpSync(path.join(repo, entry.name), path.join(site, entry.name));
    }
  }
  mkdirSync(path.join(site, 'private'));
  mkdirSync(path.join(site, 'uploads'));
  writeFileSync(path.join(site, 'config.php'), "<?php return ['db'=>['dsn'=>'sqlite:'.dirname(__DIR__).'/data.sqlite'],'site'=>['name'=>'VP3 Test','base_path'=>'']];");
  writeFileSync(path.join(site, 'health.txt'), 'ready');
  const fixture = path.join(repo, 'tests/phone-tracking-e2e-fixture.php');
  return JSON.parse(execFileSync(php, ['-d', 'session.save_path=' + sessions, fixture, site], { encoding: 'utf8' }));
}
