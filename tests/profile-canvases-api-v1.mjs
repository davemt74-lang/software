import assert from 'node:assert/strict';
import {execFileSync, spawn} from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';

const php = process.env.VP3_PHP_BINARY || 'php';
const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-profile-canvases-api-'));
fs.mkdirSync(path.join(fixture, 'includes'));
fs.mkdirSync(path.join(fixture, 'api'));
fs.copyFileSync('api/profile-canvases-v1.php', path.join(fixture, 'api/profile-canvases-v1.php'));
fs.symlinkSync(path.resolve('includes/profile-canvases-v1.php'), path.join(fixture, 'includes/profile-canvases-v1.php'));

// Keep the actual shared Canvas scope boundary. Its unrelated worker graph is
// isolated; the lower-level session/principal services use a deterministic DB.
const jobsSource = fs.readFileSync('includes/coding-canvas-jobs-v1.php', 'utf8');
const scopeStart = jobsSource.indexOf('function vp3_coding_canvas_scope_v1(');
const scopeEnd = jobsSource.indexOf('function vp3_coding_canvas_job_row_v1(', scopeStart);
assert.ok(scopeStart > 0 && scopeEnd > scopeStart, 'the canonical Canvas scope function must be present');
fs.writeFileSync(path.join(fixture, 'includes/coding-canvas-jobs-v1.php'), '<?php\ndeclare(strict_types=1);\n' + jobsSource.slice(scopeStart, scopeEnd));
const bootstrap = path.join(fixture, 'includes/bootstrap.php');
fs.writeFileSync(bootstrap, `<?php
declare(strict_types=1);
define('FIXTURE_ROOT',dirname(__DIR__));
function db(): ?PDO {
    if (($_SERVER['HTTP_X_FIXTURE_DB']??'')==='missing') return null;
    if (($_SERVER['HTTP_X_FIXTURE_DB']??'')==='failure') throw new PDOException('secret-sql-fixture-description');
    static $pdo;
    if (!$pdo) {
        $pdo=new PDO('sqlite:'.FIXTURE_ROOT.'/canvases.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=ON');
    }
    return $pdo;
}
function current_user(): ?array {
    $id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);
    return $id>0?['id'=>$id,'is_active'=>1]:null;
}
function has_permission(string $permission,array $user): bool {
    return $permission==='chat.access' && ($_SERVER['HTTP_X_FIXTURE_DENIED']??'')!=='chat.access';
}
function personal_capability_has_v242(string $permission,array $user): bool {
    return $permission!==($_SERVER['HTTP_X_FIXTURE_DENIED']??'');
}
function personal_knowledge_available(array $user): bool { return ($_SERVER['HTTP_X_FIXTURE_DENIED']??'')!=='personal_knowledge.access'; }
function csrf_token(): string { return 'profile-canvas-fixture-csrf'; }
function url(string $path): string { return '/vp3'.$path; }
function table_exists(string $table): bool {
    try { db()->query('SELECT 1 FROM '.$table.' WHERE 1=0'); return true; } catch(PDOException) { return false; }
}
function column_exists(string $table,string $column): bool {
    try { db()->query('SELECT '.$column.' FROM '.$table.' WHERE 1=0'); return true; } catch(PDOException) { return false; }
}
function vp3_participant_scope_v1(): ?array { return !empty($_SERVER['HTTP_X_FIXTURE_PARTICIPANT'])?['owner_user_id'=>1]:null; }
function user_agent_get_v236(PDO $pdo,int $owner,int $agent): ?array {
    $q=$pdo->prepare('SELECT * FROM user_agents WHERE id=? AND user_id=?');$q->execute([$agent,$owner]);return $q->fetch()?:null;
}
function user_agent_principal_v236(array $user,?array $agent): array {
    return ['user_id'=>(int)$user['id'],'agent_id'=>(int)($agent['id']??0)];
}
function knowledge_retrieval_v162_owner_session(array $user,array $principal): bool {
    return (int)$user['id']===$principal['user_id'] && empty($_SERVER['HTTP_X_FIXTURE_FOREIGN_SESSION']);
}
function vp3_capability_live_user_v1(PDO $pdo,array $user,array $principal,array $context,mixed $unused): array {
    file_put_contents(FIXTURE_ROOT.'/scope-calls.jsonl',json_encode([$user,$principal,$context])."\\n",FILE_APPEND);
    if (!empty($_SERVER['HTTP_X_FIXTURE_SCOPE_FAILURE'])) throw new RuntimeException('secret-private-scope-detail',500);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1');$q->execute([(int)$user['id']]);$live=$q->fetch();
    if (!$live) throw new RuntimeException('Live account is unavailable.',403);
    $cid=$context['conversation_id'];
    if ($cid>0) {
        $q=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND COALESCE(user_agent_id,0)=?');
        foreach([$cid,$principal['user_id'],$principal['agent_id']] as $i=>$value)$q->bindValue($i+1,$value,PDO::PARAM_INT);
        $q->execute();
        if (!$q->fetchColumn()) throw new RuntimeException('Conversation does not belong to this Agent.',403);
    }
    return $live;
}
`);
execFileSync(php, ['-r', `
require $argv[1];require_once dirname($argv[1]).'/profile-canvases-v1.php';
$p=db();
$p->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER NOT NULL); INSERT INTO users VALUES(1,1),(2,1)');
$p->exec('CREATE TABLE user_profiles(user_id INTEGER PRIMARY KEY,username TEXT,is_public INTEGER NOT NULL)');
$p->exec("INSERT INTO user_profiles VALUES(1,'owner-one',1),(2,'owner-two',1)");
$p->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,user_id INTEGER,is_active INTEGER); INSERT INTO user_agents VALUES(11,1,1),(12,1,1),(21,2,1)');
$p->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER); INSERT INTO chat_conversations VALUES(11,1,11),(12,1,12),(21,2,21)');
$p->exec('CREATE TABLE artist_transcript_folders_v177(id INTEGER PRIMARY KEY,created_by_user_id INTEGER)');
$p->exec('INSERT INTO artist_transcript_folders_v177 VALUES(1,1),(2,2)');
$p->exec('CREATE TABLE knowledge_items(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,folder_id INTEGER,knowledge_scope TEXT,visibility TEXT,is_published INTEGER,content_text TEXT)');
$p->exec("INSERT INTO knowledge_items VALUES(1,1,1,'personal','private',0,'private owner app data'),(2,2,2,'personal','private',0,'private foreign app data')");
vp3_coding_canvas_schema_v1($p);vp3_coding_canvas_publishing_install_v1($p);vp3_profile_canvases_install_v1($p);
$insert=$p->prepare('INSERT INTO vp3_coding_canvases_v1(id,owner_user_id,agent_id,conversation_id,folder_id,title,state_item_id,active_revision,draft_revision) VALUES(?,?,?,?,?,?,?,1,2)');
$pub=$p->prepare('INSERT INTO vp3_coding_canvas_publications_v1(canvas_id,owner_user_id,slug,generation,revision,title,snapshot_json,snapshot_hash) VALUES(?,?,?,1,1,?,?,?)');
$snapshot=json_encode(['commands'=>[],'artifact'=>['html'=>'<h1>Published</h1>','css'=>'','js'=>'']]);
foreach([1,2] as $owner){
    $id='c0000000-0000-4000-8000-'.str_pad((string)$owner,12,'0',STR_PAD_LEFT);
    $agent=$owner===1?11:21;
    $insert->execute([$id,$owner,$agent,$agent,$owner,'Private draft '.$owner,$owner]);
    $pub->execute([$id,$owner,'canvas-'.$owner,'Public Canvas '.$owner,$snapshot,hash('sha256',$snapshot)]);
}
`, bootstrap]);

const listener = net.createServer();
await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
const port = listener.address().port;
await new Promise(resolve => listener.close(resolve));
const server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', fixture], {stdio:['ignore','ignore','pipe']});
let logs = '';
server.stderr.on('data', data => { logs += data; });
const endpoint = `http://127.0.0.1:${port}/api/profile-canvases-v1.php`;
const owner = {'X-Fixture-User':'1'};
const canvasId = 'c0000000-0000-4000-8000-000000000001';
const statusBody = {action:'status',csrf_token:'profile-canvas-fixture-csrf',agent_id:11,conversation_id:11,canvas_id:canvasId};
let checks = 0;
const pass = label => { checks++; console.log(`PASS ${label}`); };
function runPhp(code) { return execFileSync(php,['-r',`require $argv[1];${code}`,bootstrap],{encoding:'utf8'}).trim(); }
function snapshot() {
  return runPhp(`$result=[];foreach(['users','user_profiles','user_agents','chat_conversations','vp3_coding_canvases_v1','vp3_coding_canvas_publications_v1','vp3_profile_canvases_v1','knowledge_items'] as $table)$result[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();echo json_encode($result);`);
}
function scopeCalls() {
  const file = path.join(fixture,'scope-calls.jsonl');
  return fs.existsSync(file) ? fs.readFileSync(file,'utf8').trim().split('\n').filter(Boolean).map(row=>JSON.parse(row)) : [];
}
async function request(body=statusBody,headers=owner,method='POST') {
  const response = await fetch(endpoint,{method,headers:{...headers,...(body!==null?{'Content-Type':'application/json'}:{})},
    ...(body!==null?{body:typeof body==='string'?body:JSON.stringify(body)}:{})});
  const text = await response.text();
  assert.ok(text.length<1200,'HTTP responses must not expose source, private rows or exception traces');
  return {status:response.status,data:JSON.parse(text),response,text};
}
async function denied(status,body,headers,label,method='POST') {
  const result=await request(body,headers,method);
  assert.equal(result.status,status,result.text);assert.equal(result.data.ok,false);
  assert.deepEqual(Object.keys(result.data).sort(),['error','ok']);
  pass(label);return result;
}
try {
  for(let attempt=0;attempt<60;attempt++){
    try { await fetch(endpoint);break; } catch(error){
      if(attempt===59)throw error;
      await new Promise(resolve=>setTimeout(resolve,25));
    }
  }
  const original = snapshot();
  const first = await request({...statusBody,listed:true});
  assert.equal(first.status,200,first.text);assert.equal(first.data.ok,true);
  assert.deepEqual(first.data.profile_canvas,{listed:false,url:null,version:runPhp(`echo hash('sha256','profile-canvas:'.${JSON.stringify(canvasId)}.':0');`)});
  assert.equal(first.data.eligibility.can_list,true);
  assert.equal(first.response.headers.get('cache-control'),'no-store');
  assert.equal(first.response.headers.get('x-content-type-options'),'nosniff');
  assert.match(first.response.headers.get('content-type'),/^application\/json/);
  assert.equal(snapshot(),original);
  assert.deepEqual(scopeCalls().at(-1),[{id:1,is_active:1},{user_id:1,agent_id:11},{conversation_id:11}]);
  pass('authenticated status is read-only, private, and uses the exact owner/Agent/conversation boundary');
  await denied(405,null,owner,'GET cannot read or change the POST-only endpoint','GET');
  await denied(405,null,owner,'PUT cannot change Profile listing choices','PUT');
  await denied(401,statusBody,{},'signed-out requests cannot read owner settings');
  const beforeInvalid=scopeCalls().length;
  await denied(419,{...statusBody,csrf_token:'wrong'},owner,'a current owner still requires matching CSRF');
  await denied(419,{...statusBody,csrf_token:null},owner,'a missing CSRF token is rejected');
  await denied(422,'[]',owner,'JSON arrays are not action objects');
  await denied(422,'{broken',owner,'malformed JSON is rejected');
  await denied(413,JSON.stringify({...statusBody,extra:'x'.repeat(4096)}),owner,'oversize bodies fail before parsing or entering scope');
  for(const [field,value] of [['agent_id','11'],['conversation_id','11'],['agent_id',-1],['conversation_id',-1],['canvas_id',1],['action','publish']]){
    await denied(422,{...statusBody,[field]:value},owner,`invalid ${field} is not coerced into a different operation`);
  }
  assert.equal(scopeCalls().length,beforeInvalid);
  pass('invalid method/body/CSRF requests never reach the shared Canvas owner scope');
  await denied(403,statusBody,{...owner,'X-Fixture-Participant':'1'},'a participant cannot use the owner publication controls');
  await denied(403,statusBody,{...owner,'X-Fixture-Denied':'chat.access'},'revoked Chat permission blocks status');
  await denied(403,statusBody,{...owner,'X-Fixture-Denied':'personal_knowledge.access'},'revoked private Knowledge access blocks status');
  await denied(403,statusBody,{...owner,'X-Fixture-Foreign-Session':'1'},'a foreign owner session cannot use the current principal');
  await denied(403,{...statusBody,conversation_id:21},owner,'a conversation belonging to another owner is rejected');
  await denied(403,{...statusBody,conversation_id:12},owner,'a conversation belonging to another Agent is rejected');
  await denied(403,{...statusBody,agent_id:21},owner,'a foreign Agent cannot be substituted');
  await denied(404,{...statusBody,agent_id:12,conversation_id:12},owner,'a valid second Agent cannot claim the first Agent’s Canvas');
  await denied(404,{...statusBody,agent_id:21,conversation_id:21},{'X-Fixture-User':'2'},'another signed-in owner cannot inspect these settings');
  const publicationVersion=runPhp(`echo hash('sha256','canvas-publication:'.${JSON.stringify(canvasId)}.':1');`);
  const setBody={...statusBody,action:'set',listed:true,expected_revision:first.data.profile_canvas.version,expected_publication:publicationVersion};
  for(const [field,value] of [['listed',1],['expected_revision',null],['expected_publication',null]]){
    await denied(422,{...setBody,[field]:value},owner,`set requires a correctly typed ${field}`);
  }
  await denied(422,{...setBody,expected_revision:'invalid'},owner,'malformed revision proof cannot mutate the listing');
  await denied(409,{...setBody,expected_revision:'a'.repeat(64)},owner,'the exact reviewed listing version is forwarded');
  await denied(409,{...setBody,expected_publication:'b'.repeat(64)},owner,'the exact reviewed publication version is forwarded');
  await denied(403,setBody,{...owner,'X-Fixture-Denied':'personal_knowledge.manage'},'adding a Profile Canvas requires publication management capability');
  assert.equal(snapshot(),original);
  pass('all rejected requests preserve publications, listing preferences and private app data');
  const added=await request({...setBody,username:'owner-two',url:'https://invalid.example/'});
  assert.equal(added.status,200,added.text);assert.equal(added.data.profile_canvas.listed,true);
  assert.equal(added.data.profile_canvas.url,'/vp3/owner-one/canvas/canvas-1');
  assert.notEqual(added.data.profile_canvas.version,first.data.profile_canvas.version);
  const listedSnapshot=snapshot();
  const state=await request(statusBody);
  assert.deepEqual(state.data,added.data);assert.equal(snapshot(),listedSnapshot);
  pass('valid set persists the exact owned Canvas and derives its Profile URL from trusted records');
  const retry=await request(setBody);
  assert.equal(retry.status,200,retry.text);assert.deepEqual(retry.data,added.data);assert.equal(snapshot(),listedSnapshot);
  pass('an immediate retry is idempotent without advancing the stored listing version');
  const remove=await request({...setBody,listed:false,expected_revision:added.data.profile_canvas.version},{...owner,'X-Fixture-Denied':'personal_knowledge.manage'});
  assert.equal(remove.status,200,remove.text);assert.equal(remove.data.profile_canvas.listed,false);assert.equal(remove.data.profile_canvas.url,null);
  await denied(409,setBody,owner,'an obsolete add cannot undo a newer explicit removal');
  pass('an owner can withdraw a listing after losing permission to publish new content');
  for(const failure of ['missing','failure']){
    const result=await denied(503,statusBody,{...owner,'X-Fixture-Db':failure},'unavailable storage produces a bounded public error');
    assert.ok(!result.text.includes('secret-sql')&&!result.text.includes(fixture));
  }
  const unexpected=await denied(503,statusBody,{...owner,'X-Fixture-Scope-Failure':'1'},'unexpected dependency errors do not expose private details');
  assert.ok(!unexpected.text.includes('secret-private')&&!unexpected.text.includes(fixture));
  runPhp("db()->exec('UPDATE users SET is_active=0 WHERE id=1');");
  await denied(403,statusBody,owner,'a deactivated account cannot reuse a stale signed-in identity');
  assert.equal(runPhp("echo db()->query('SELECT content_text FROM knowledge_items WHERE id=1')->fetchColumn();"),'private owner app data');
  assert.equal(runPhp("echo db()->query('SELECT COUNT(*) FROM vp3_coding_canvas_publications_v1 WHERE revision=1 AND generation=1')->fetchColumn();"),'2');
  pass('Profile listing changes never modify the public publication revision or private app data');
  console.log(`PASS ${checks} Profile Canvas HTTP checks`);
} catch(error) {
  console.error(logs);throw error;
} finally {
  if(server.exitCode===null){
    const exited=new Promise(resolve=>server.once('exit',resolve));
    server.kill('SIGTERM');await exited;
  }
  fs.rmSync(fixture,{recursive:true,force:true});
}
