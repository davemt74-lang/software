import assert from 'node:assert/strict';
import {execFileSync, spawn} from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';

const php = process.env.VP3_PHP_BINARY || 'php';
const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-profile-image-settings-'));
fs.mkdirSync(path.join(fixture, 'includes'));
fs.mkdirSync(path.join(fixture, 'api'));
fs.copyFileSync('api/profile-image-settings-v1.php', path.join(fixture, 'api/profile-image-settings-v1.php'));
fs.symlinkSync(path.resolve('includes/profile-image-catalog-v1.php'), path.join(fixture, 'includes/profile-image-catalog-v1.php'));
fs.symlinkSync(path.resolve('includes/profile-image-edit-v1.php'), path.join(fixture, 'includes/profile-image-edit-v1.php'));
const bootstrap = path.join(fixture, 'includes/bootstrap.php');
fs.writeFileSync(bootstrap, `<?php
define('STONEFELLOW_ROOT',dirname(__DIR__));
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo=new PDO('sqlite:'.STONEFELLOW_ROOT.'/catalog.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=ON');
    }
    return $pdo;
}
function current_user(): ?array {
    $id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);
    return $id>0?['id'=>$id,'is_active'=>1]:null;
}
function has_permission(string $permission,array $user): bool {
    return in_array($permission,['chat.access','profile_agent.access'],true)
        && $permission!==($_SERVER['HTTP_X_FIXTURE_DENIED']??'');
}
function personal_capability_has_v242(string $permission,array $user): bool { return has_permission($permission,$user); }
function csrf_token(): string { return 'image-fixture-csrf'; }
function url(string $path): string { return $path; }
require_once __DIR__.'/profile-image-catalog-v1.php';
`);
execFileSync(php, ['-r', `
require $argv[1];
db()->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER NOT NULL); INSERT INTO users VALUES(1,1),(2,1)');
db()->exec('CREATE TABLE user_media_assets(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,media_type TEXT,title TEXT,mime_type TEXT,file_path TEXT,file_size INTEGER,source TEXT,updated_at TEXT)');
vp3_media_collections_install_v1(db());vp3_profile_image_catalog_install_v1(db());
vp3_media_collection_create_v1(db(),1,'Owner catalog');vp3_media_collection_create_v1(db(),2,'Foreign catalog');
$chunk=static fn($type,$bytes)=>pack('N',strlen($bytes)).$type.$bytes.pack('N',crc32($type.$bytes));
$bytes="\\x89PNG\\r\\n\\x1a\\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,6,0,0,0)).$chunk('IDAT',gzcompress("\\0\\x40\\x60\\x80\\xff")).$chunk('IEND','');
foreach ([1,2] as $owner) {
    mkdir(STONEFELLOW_ROOT.'/private/user-media/'.$owner,0700,true);
    $file='private/user-media/'.$owner.'/photo.png';file_put_contents(STONEFELLOW_ROOT.'/'.$file,$bytes);
    db()->prepare('INSERT INTO user_media_assets VALUES(?,?,?,?,?,?,?,?,?)')->execute([$owner,$owner,'photo','Private image '.$owner,'image/png',$file,strlen($bytes),'upload','2026-11-18']);
    vp3_media_collection_set_asset_v1(db(),$owner,$owner,$owner,true);
}
`, bootstrap]);
const listener = net.createServer();
await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
const port = listener.address().port;
await new Promise(resolve => listener.close(resolve));
const server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', fixture], {stdio:['ignore','ignore','pipe']});
let logs = '';
server.stderr.on('data', data => { logs += data; });
const endpoint = `http://127.0.0.1:${port}/api/profile-image-settings-v1.php`;
const owner = {'X-Fixture-User':'1','X-VP3-Media-Owner':'1'};
let checks = 0;
const pass = label => { checks++;console.log(`PASS ${label}`); };
async function request(method='GET', body=null, headers=owner, query='') {
  const response = await fetch(endpoint + query, {method, headers:{...headers, ...(body!==null?{'Content-Type':'application/json'}:{})},
    ...(body!==null?{body:typeof body==='string'?body:JSON.stringify(body)}:{})});
  return {status:response.status, data:await response.json(), response};
}
async function denied(status, method, body, headers, label, query='') {
  const result = await request(method, body, headers, query);
  assert.equal(result.status, status, JSON.stringify(result.data));
  assert.equal(result.data.ok, false);
  pass(label);
}
const settings = {action:'save_settings',expected_revision:0,csrf_token:'image-fixture-csrf',settings:{enabled:true,daily_limit:10,visitor_daily_limit:3}};
const catalog = {action:'save_catalog',expected_revision:0,csrf_token:'image-fixture-csrf',collection_id:1,approved:true,
  style_instructions:'Warm muted light.',default_price_cents:0,references:[{asset_id:1,price_override_cents:null}]};
try {
  for (let attempt=0;attempt<60;attempt++) {
    try { await fetch(endpoint);break; } catch (error) {
      if (attempt===59) throw error;
      await new Promise(resolve => setTimeout(resolve,25));
    }
  }
  const state = await request();
  assert.equal(state.status,200);assert.equal(state.data.ok,true);assert.equal(state.data.settings.enabled,false);
  assert.equal(state.data.collections.length,1);assert.equal(state.data.assets.length,1);
  assert.equal(state.response.headers.get('cache-control'),'no-store');
  assert.equal(state.response.headers.get('x-content-type-options'),'nosniff');
  assert.ok(!JSON.stringify(state.data).includes('Foreign catalog'));
  pass('authenticated owner GET exposes only their settings and no-store private asset metadata');
  await denied(403,'GET',null,{},'a signed-out visitor cannot read owner settings');
  await denied(403,'GET',null,{'X-Fixture-User':'1'},'a missing owner binding is denied');
  await denied(403,'GET',null,{...owner,'X-Fixture-User':'2'},'an account switch cannot retain another owner editor binding');
  await denied(403,'GET',null,{...owner,'X-Fixture-Denied':'chat.access'},'revoked Chat permission blocks owner settings');
  await denied(403,'GET',null,{...owner,'X-Fixture-Denied':'profile_agent.access'},'revoked Profile permission blocks owner settings');
  await denied(404,'GET',null,owner,'an exact foreign collection cannot be selected','?collection_id=2');
  await denied(422,'GET',null,owner,'invalid collection identifiers are not coerced','?collection_id=1e0');
  await denied(405,'PUT',null,owner,'unsupported HTTP methods cannot mutate settings');
  await denied(419,'POST',{...settings,csrf_token:'wrong'},owner,'a valid owner session still requires CSRF for changes');
  await denied(419,'POST',{...settings,csrf_token:null},owner,'missing CSRF is denied');
  await denied(422,'POST','[]',owner,'top-level JSON arrays are not action objects');
  await denied(422,'POST','{bad',owner,'malformed JSON is rejected');
  await denied(413,'POST',JSON.stringify({...settings,extra:'x'.repeat(17000)}),owner,'oversize settings bodies are rejected before parsing');
  assert.equal((await request()).data.settings.revision,0);
  pass('rejected requests leave the disabled settings unchanged');
  const enabled = await request('POST',settings);
  assert.equal(enabled.status,200);assert.equal(enabled.data.settings.enabled,true);assert.equal(enabled.data.settings.revision,1);
  pass('an exact owner, CSRF and revision can enable the bounded free image allowance');
  await denied(409,'POST',settings,owner,'replaying an obsolete settings revision cannot overwrite current choices');
  const saved = await request('POST',catalog);
  assert.equal(saved.status,200,JSON.stringify(saved.data));
  assert.equal(saved.data.collections[0].references.length,1);assert.equal(saved.data.collections[0].references[0].price_override_cents,null);
  pass('the HTTP API saves one exact owned exemplar and preserves price inheritance');
  await denied(404,'POST',{...catalog,expected_revision:1,references:[{asset_id:2,price_override_cents:0}]},owner,
    'a forged foreign exemplar cannot be approved through the actual HTTP API');
  const priced = await request('POST',{...catalog,expected_revision:1,default_price_cents:150,references:[{asset_id:1,price_override_cents:0}]});
  assert.equal(priced.status,200);assert.equal(priced.data.collections[0].default_price_cents,150);
  assert.equal(priced.data.collections[0].references[0].effective_price_cents,0);
  pass('normal catalog pricing can be saved while an explicit zero image remains free');
  execFileSync(php,['-r',"require $argv[1];db()->exec('UPDATE users SET is_active=0 WHERE id=1');",bootstrap]);
  await denied(403,'POST',{...settings,expected_revision:1},owner,'live account deactivation blocks a formerly valid editor session');
  console.log(`PASS ${checks} Profile image settings HTTP checks`);
} catch (error) {
  console.error(logs);throw error;
} finally {
  const exited = new Promise(resolve => server.once('exit',resolve));
  server.kill('SIGTERM');await exited;
  fs.rmSync(fixture,{recursive:true,force:true});
}
