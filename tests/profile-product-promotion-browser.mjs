import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {createRequire} from 'node:module';
import {spawn, spawnSync} from 'node:child_process';

const {chromium} = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const root = process.cwd();
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-profile-promotion-'));
const php = process.env.VP3_PHP_BINARY || 'php';
const key = 'a'.repeat(32);
const imagePath = '/profile-promotion-image.php?key=' + key;
const productPath = '/fixture-owner/product/blue-mug';
const listingPhotoPath = '/profile-product-photo.php?username=fixture-owner&product=blue-mug&listing=31';
const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
const artwork = path.join(tmp, 'private/user-media/1/promotion.png');
const originalPath = path.join(path.dirname(artwork), 'original.png');
const original = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAGUlEQVR4nGMwSFjwnxLMMGrAqAGjBgwXAwBoAi8f/niiOAAAAABJRU5ErkJggg==', 'base64');
const env = {...process.env, VP3_PA3_ROOT:root, VP3_PA3_CONFIG:path.join(tmp, 'config.json')};

fs.mkdirSync(path.dirname(artwork), {recursive:true});
fs.writeFileSync(artwork, image);
fs.writeFileSync(originalPath, original);
fs.mkdirSync(path.join(tmp, 'includes'));
fs.mkdirSync(path.join(tmp, 'api'));
for (const file of fs.readdirSync('includes')) {
  if (file !== 'bootstrap.php') fs.symlinkSync(path.resolve('includes', file), path.join(tmp, 'includes', file));
}
for (const file of ['profile.php','profile-commerce-product.php','profile-promotion-image.php','profile-product-photo.php','media-library-file-v86.php']) {
  fs.copyFileSync(file, path.join(tmp, file));
}
for (const file of ['profile.css','profile-cleanup-v174.css','profile-conversion-v175.css','profile-agent.js','profile-calls-pa3c.css','profile-calls-pa3c.js','profile-speech-pa4a.css','voice-lease-v122.js','profile-conversation-checkout-pa5.js','profile-webmcp-v100.js']) {
  fs.symlinkSync(path.resolve(file), path.join(tmp, file));
}
fs.copyFileSync('api/profile-agent.php', path.join(tmp, 'api/profile-agent.php'));
fs.writeFileSync(path.join(tmp, 'includes/bootstrap.php'), `<?php
define('STONEFELLOW_ROOT',dirname(__DIR__));
require getenv('VP3_PA3_ROOT').'/tests/fixtures/profile-handoff-pa3.php';
require_once getenv('VP3_PA3_ROOT').'/includes/agent-commerce-v800.php';
require_once getenv('VP3_PA3_ROOT').'/includes/media-studio-v86.php';
if(PHP_SAPI!=='cli')session_start();
function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function agent_brain_schema_ready():bool{return false;}
`);
fs.writeFileSync(path.join(tmp, 'router.php'), `<?php
$route=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if(str_starts_with($route,'/private/')){http_response_code(403);return;}
if($route==='/fixture-owner'){$_GET['username']='fixture-owner';require __DIR__.'/profile.php';return;}
if($route==='/fixture-owner/product/blue-mug'){$_GET['username']='fixture-owner';$_GET['product']='blue-mug';require __DIR__.'/profile-commerce-product.php';return;}
return false;
`);
fs.writeFileSync(path.join(tmp, 'fixture.php'), `<?php
require __DIR__.'/includes/bootstrap.php';
$command=$argv[1]??'';
if($command==='cleanup'){
    $cfg=$GLOBALS['pa3Config'];
    if(isset($cfg['database'])&&preg_match('/^vp3_profile_handoff_pa3_[a-f0-9]{12}$/D',$cfg['database'])){
        (new PDO($cfg['admin_dsn'],$cfg['user'],$cfg['password']))->exec('DROP DATABASE IF EXISTS \`'.$cfg['database'].'\`');
    }
    exit;
}
if($command==='init'){
    pa3_reset();
    agent_commerce_ensure_schema_v800($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_promotions_v1 (id CHAR(32) PRIMARY KEY, owner_user_id INT, product_id BIGINT, draft_key CHAR(64), agent_id BIGINT, conversation_id BIGINT, source_asset_id BIGINT, source_sha256 CHAR(64), settings_json TEXT, asset_id BIGINT, asset_sha256 CHAR(64), public_key CHAR(32), public_product_url VARCHAR(500), public_cta VARCHAR(40), status VARCHAR(20), updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE user_media_assets (id INT PRIMARY KEY,user_id INT,media_type VARCHAR(10),title VARCHAR(80),mime_type VARCHAR(40),file_path VARCHAR(300),file_size INT,duration_seconds INT,source VARCHAR(40),metadata_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $size=filesize(__DIR__.'/private/user-media/1/promotion.png');
    $source=__DIR__.'/private/user-media/1/original.png';
    $pdo->prepare("INSERT INTO agent_commerce_products_v800 (id,owner_user_id,product_key,title,description,metadata_json) VALUES(31,1,'blue-mug','Blue mug workshop','A hand-finished blue mug demonstration.',?)")->execute([json_encode(['profile_visibility'=>'public','profile_slug'=>'blue-mug','source_asset_id'=>'51','source_content_sha256'=>hash_file('sha256',$source),'public_listing_photo'=>true])]);
    $pdo->prepare("INSERT INTO user_media_assets(id,user_id,media_type,title,mime_type,file_path,file_size,source) VALUES (51,1,'photo','Private original','image/png','private/user-media/1/original.png',?,'upload'),(52,1,'photo','Exact design','image/png','private/user-media/1/promotion.png',?,'promotion_design')")->execute([filesize($source),$size]);
    $pdo->prepare("INSERT INTO agent_commerce_promotions_v1(id,owner_user_id,product_id,draft_key,agent_id,conversation_id,source_asset_id,source_sha256,settings_json,asset_id,asset_sha256,public_key,public_product_url,public_cta,status)
        VALUES(?,1,31,?,7,11,51,?,?,52,?,?,?,'View workshop','published')")->execute([
          str_repeat('b',32),str_repeat('c',64),hash_file('sha256',$source),json_encode(['headline'=>'Mug Morning','copy'=>'Learn how the blue glaze comes together.','prompt'=>'PRIVATE_PROMPT']),
          hash_file('sha256',__DIR__.'/private/user-media/1/promotion.png'),str_repeat('a',32),'/fixture-owner/product/blue-mug'
        ]);
    exit;
}
if(in_array($command,['hide-listing','show-listing','hide-photo','show-photo'],true)){
    $meta=json_decode((string)$pdo->query('SELECT metadata_json FROM agent_commerce_products_v800 WHERE id=31')->fetchColumn(),true,16,JSON_THROW_ON_ERROR);
    if(str_ends_with($command,'-listing'))$meta['profile_visibility']=$command==='hide-listing'?'hidden':'public';
    else $meta['public_listing_photo']=$command==='show-photo';
    $pdo->prepare('UPDATE agent_commerce_products_v800 SET metadata_json=? WHERE id=31')->execute([json_encode($meta)]);
    exit;
}
if($command==='foreign-photo'||$command==='owned-photo'){
    $pdo->prepare('UPDATE user_media_assets SET user_id=? WHERE id=51')->execute([$command==='owned-photo'?1:2]);
    exit;
}
if($command==='pause'){
    $pdo->exec("UPDATE agent_commerce_promotions_v1 SET status='paused' WHERE owner_user_id=1");
    exit;
}
if($command==='hide-profile'||$command==='show-profile'){
    $pdo->prepare('UPDATE user_profiles SET is_public=? WHERE user_id=1')->execute([$command==='show-profile'?1:0]);
    exit;
}
throw new RuntimeException('Unknown test command');
`);
const invoke = action => {
  const result = spawnSync(php, [path.join(tmp, 'fixture.php'), action], {env, encoding:'utf8'});
  assert.equal(result.status, 0, result.stderr || result.stdout);
};
const reservation = net.createServer();
await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
const port = reservation.address().port;
await new Promise(resolve => reservation.close(resolve));
const base = `http://127.0.0.1:${port}`;
let server, browser, logs = '';
try {
  invoke('init');
  server = spawn(php, ['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp,path.join(tmp,'router.php')], {env, stdio:['ignore','ignore','pipe']});
  server.stderr.on('data', data => logs += data);
  let ready = false;
  for (let i=0;i<80;i++) {
    try { const res=await fetch(base + imagePath); if (res.ok) {ready=true;break;} } catch {}
    await new Promise(resolve => setTimeout(resolve, 30));
  }
  assert.ok(ready, logs);
  const response = await fetch(base + imagePath);
  assert.deepEqual(Buffer.from(await response.arrayBuffer()), image);
  assert.equal(response.headers.get('content-type'), 'image/png');
  assert.match(response.headers.get('cache-control'), /no-store/);
  assert.equal((await fetch(base + imagePath,{method:'HEAD'})).headers.get('content-length'), String(image.length));
  assert.equal((await fetch(base + imagePath,{method:'POST'})).status, 405);
  assert.equal((await fetch(base + '/profile-promotion-image.php?key[]=abc')).status, 404);
  assert.equal((await fetch(base + '/profile-promotion-image.php?key=' + 'b'.repeat(32))).status, 404);
  assert.equal((await fetch(base + '/private/user-media/1/original.png')).status, 403);
  assert.equal((await fetch(base + '/media-library-file-v86.php?id=51')).status, 403);
  assert.equal((await fetch(base + '/media-library-file-v86.php?id=52')).status, 403);
  console.log('PASS anonymous visitor gets approved PNG bytes through the public key only');

  const listingPhoto = await fetch(base + listingPhotoPath);
  assert.equal(listingPhoto.status, 200);
  assert.deepEqual(Buffer.from(await listingPhoto.arrayBuffer()), original);
  assert.equal(listingPhoto.headers.get('content-type'), 'image/png');
  assert.match(listingPhoto.headers.get('cache-control'), /no-store/);
  assert.equal((await fetch(base + listingPhotoPath,{method:'HEAD'})).headers.get('content-length'), String(original.length));
  assert.equal((await fetch(base + listingPhotoPath,{method:'POST'})).status, 405);
  assert.equal((await fetch(base + listingPhotoPath.replace('listing=31','listing=51'))).status, 404);
  invoke('hide-photo');
  assert.equal((await fetch(base + listingPhotoPath)).status, 404);
  assert.ok(!(await (await fetch(base + productPath)).text()).includes('pcp-photo"><img'));
  invoke('show-photo');
  invoke('foreign-photo');
  assert.equal((await fetch(base + listingPhotoPath)).status, 404);
  invoke('owned-photo');
  const tamperedOriginal = Buffer.from(original);
  tamperedOriginal[tamperedOriginal.length-1] ^= 1;
  fs.writeFileSync(originalPath, tamperedOriginal);
  assert.equal((await fetch(base + listingPhotoPath)).status, 404);
  fs.writeFileSync(originalPath, original);
  console.log('PASS listing photo requires explicit approval, current ownership and exact original bytes');

  invoke('hide-listing');
  assert.equal((await fetch(base + imagePath)).status, 404);
  assert.equal((await fetch(base + listingPhotoPath)).status, 404);
  assert.equal((await fetch(base + productPath)).status, 404);
  invoke('show-listing');
  invoke('hide-profile');
  assert.equal((await fetch(base + imagePath)).status, 404);
  assert.equal((await fetch(base + listingPhotoPath)).status, 404);
  assert.equal((await fetch(base + '/fixture-owner')).status, 404);
  invoke('show-profile');
  const tampered = Buffer.from(image);
  tampered[tampered.length-1] ^= 1;
  fs.writeFileSync(artwork, tampered);
  assert.equal((await fetch(base + imagePath)).status, 404);
  fs.writeFileSync(artwork, image);
  console.log('PASS public image authority follows the exact listing and exact approved bytes');

  browser = await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
  const page = await browser.newPage({viewport:{width:390,height:740}});
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(base + '/fixture-owner#product-promotion-' + key);
  assert.equal(await page.getByRole('tab',{name:'Products'}).getAttribute('aria-selected'), 'true');
  const profileCard = page.locator('.profile-product-promotions [data-profile-product-promotion]');
  await profileCard.getByRole('img',{name:/Mug Morning/}).waitFor();
  assert.equal(await profileCard.getByRole('link',{name:/View workshop/}).getAttribute('href'), productPath);
  assert.equal(await profileCard.locator('img').getAttribute('src'), imagePath);
  assert.ok(await page.waitForFunction(() => document.querySelector('.profile-product-promotions img').naturalWidth > 0));
  await page.locator('[data-open-profile-agent]').click();
  const visitorForm = page.getByRole('textbox',{name:'Message Profile Agent'});
  const agentCard = page.locator('[data-profile-agent-thread] .profile-agent-message.agent [data-profile-product-promotion]');
  await visitorForm.fill('Could you share your public hours?');
  await page.getByRole('button',{name:'Send',exact:true}).click();
  await page.getByText('PA3 approved public answer',{exact:true}).waitFor({timeout:18000});
  assert.equal(await agentCard.count(), 0);
  await new Promise(resolve => setTimeout(resolve, 2100));
  await visitorForm.fill('Can you tell me about the blue mug workshop?');
  await page.getByRole('button',{name:'Send',exact:true}).click();
  await agentCard.getByRole('link',{name:/View workshop/}).waitFor({timeout:18000});
  assert.equal(await agentCard.locator('img').getAttribute('src'), base + imagePath);
  assert.ok(!(await page.content()).includes('PRIVATE_PROMPT'));
  console.log('PASS actual mobile profile and durable Profile Agent turn present the same promotion');
  await agentCard.getByRole('link',{name:/View workshop/}).click();
  await page.waitForURL(base + productPath);
  await page.getByRole('heading',{name:'Blue mug workshop'}).waitFor();
  assert.equal(await page.getByRole('img',{name:'Blue mug workshop'}).getAttribute('src'), listingPhotoPath);
  await page.waitForFunction(() => document.querySelector('.pcp-photo img').naturalWidth === 16);
  console.log('PASS the Profile Agent card opens the canonical public product listing');

  await page.goto(base + '/fixture-owner');
  await page.locator('[data-open-profile-agent]').click();
  await agentCard.waitFor();
  invoke('pause');
  assert.equal((await fetch(base + imagePath)).status, 404);
  const retainedPhoto = await fetch(base + listingPhotoPath);
  assert.equal(retainedPhoto.status, 200);
  assert.deepEqual(Buffer.from(await retainedPhoto.arrayBuffer()), original);
  await agentCard.waitFor({state:'detached',timeout:15000});
  await page.reload();
  await page.getByRole('tab',{name:'Products'}).click();
  assert.equal(await profileCard.count(), 0);
  assert.equal(await page.getByRole('link',{name:/Learn more/}).getAttribute('href'), productPath);
  assert.deepEqual(errors, []);
  console.log('PASS pause revokes the old image URL and removes chat and profile cards while leaving the listing');
  console.log('PROFILE_PRODUCT_PROMOTION_BROWSER=PASS');
} catch (error) {
  if (logs) console.error(logs.slice(-5500));
  throw error;
} finally {
  if (browser) await browser.close();
  if (server) {
    server.kill();
    await new Promise(resolve => server.exitCode !== null ? resolve() : server.once('exit',resolve));
  }
  try { if (fs.existsSync(env.VP3_PA3_CONFIG)) invoke('cleanup'); }
  finally { fs.rmSync(tmp,{recursive:true,force:true}); }
}
