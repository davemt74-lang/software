import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {copyFileSync,mkdirSync,mkdtempSync,readFileSync,rmSync,writeFileSync,existsSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

// Production authentication, GET dispatch, session release and live projection
// are exercised by two PHP workers. Only the old heavy Brain data projection is
// held at a controlled fixture gate; it cannot invent model or worker activity.
const source=fileURLToPath(new URL('../',import.meta.url));
const temp=mkdtempSync(join(tmpdir(),'vp3-mind-session-'));
const php=process.env.VP3_PHP_BINARY||'php',gate=join(temp,'brain-projection');
const probe=net.createServer();await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));
const port=probe.address().port;await new Promise(resolve=>probe.close(resolve));
const base='http://127.0.0.1:'+port;
mkdirSync(join(temp,'api'));mkdirSync(join(temp,'includes'));
copyFileSync(join(source,'api/agent-top-of-mind-v1.php'),join(temp,'api/agent-top-of-mind-v1.php'));
for(const name of ['agent-top-of-mind-v1','agent-eyes','agent-activity-situations-v1','agent-catch-up-v1']){
  writeFileSync(join(temp,'includes',name+'.php'),'<?php require_once '+JSON.stringify(join(source,'includes',name+'.php'))+';');
}
writeFileSync(join(temp,'includes/bootstrap.php'),'<?php session_start(); require '+JSON.stringify(join(source,'tests/fixtures/initiative-chat-loop-http.php'))+';');
const projection=`{
  $gate=(string)getenv('VP3_MIND_SESSION_GATE');
  file_put_contents($gate.'.entered','1');$deadline=microtime(true)+8;
  while(!file_exists($gate.'.release')&&microtime(true)<$deadline)usleep(10000);
  return ['ok'=>true,'fixture_session_active'=>session_status()===PHP_SESSION_ACTIVE,'brain'=>['enabled'=>false]];
}`;
const patcher=`$text=file_get_contents($argv[1]);$tokens=token_get_all($text);$offset=0;$found=false;$named=false;$start=null;$depth=0;
foreach($tokens as $token){$raw=is_array($token)?$token[1]:$token;
 if(is_array($token)&&$token[0]===T_FUNCTION){$found=true;$named=false;}
 elseif($found&&is_array($token)&&$token[0]===T_STRING){$named=$token[1]==='chat_notifications_v240_state';$found=false;}
 if($named){
  $opening=$raw==='{'||(is_array($token)&&in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true));
  if($opening){if($start===null)$start=$offset;++$depth;}
  elseif($raw==='}'&&$start!==null&&--$depth===0){echo substr($text,0,$start).$argv[2].substr($text,$offset+1);exit;}
 }
 $offset+=strlen($raw);
}fwrite(STDERR,'Heavy projection function was not found');exit(2);`;
const api=execFileSync(php,['-r',patcher,join(source,'api/chat-notifications-brain-v240.php'),projection],{encoding:'utf8'});
assert.ok(api.includes('session_write_close()'),'the production GET branch must release its session before projection');
writeFileSync(join(temp,'api/chat-notifications-brain-v240.php'),api);
writeFileSync(join(temp,'router.php'),[
  '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);',
  'if($path==="/health"){echo "ready";return;}',
  'if(in_array($path,["/api/chat-notifications-brain-v240.php","/api/agent-top-of-mind-v1.php"],true)){require __DIR__.$path;return;}',
  'require __DIR__."/includes/bootstrap.php";header("Content-Type:application/json");',
  'if($path==="/session"){echo json_encode(["binding"=>vp3_catch_up_page_binding_v1(current_user())]);return;}',
  'if($path==="/counts"){echo json_encode(["models"=>(int)db()->query("SELECT COUNT(*) FROM fixture_model_calls")->fetchColumn(),"runs"=>(int)db()->query("SELECT COUNT(*) FROM agent_workflow_runs")->fetchColumn()]);return;}',
  'http_response_code(404);echo "{}";'
].join('\n'));
let logs='',held;
const server=spawn(php,['-S','127.0.0.1:'+port,'-t',temp,join(temp,'router.php')],{
  env:{...process.env,PHP_CLI_SERVER_WORKERS:'2',VP3_IMAGE_TEST_DSN:'sqlite:'+join(temp,'working.sqlite'),VP3_MIND_SESSION_GATE:gate},
  detached:true,stdio:['ignore','pipe','pipe']
});
server.stdout.on('data',v=>{logs+=v;});server.stderr.on('data',v=>{logs+=v;});server.on('error',e=>{logs+=e.message;});
try{
  for(let n=0;;n++){
    try{if((await fetch(base+'/health')).ok)break;}catch{}
    if(n>60)throw new Error('PHP workers did not start: '+logs);
    await new Promise(r=>setTimeout(r,50));
  }
  const sessionResponse=await fetch(base+'/session'),session=await sessionResponse.json();
  const cookie=sessionResponse.headers.get('set-cookie').split(';')[0];
  const headers={Cookie:cookie,'X-VP3-Working-Attention':session.binding};
  const denied=await fetch(base+'/api/chat-notifications-brain-v240.php?action=state&agent_id=7',{
    headers:{...headers,'X-VP3-Working-Attention':'wrong-session'}
  });
  assert.equal(denied.status,403);assert.equal(existsSync(gate+'.entered'),false);
  held=fetch(base+'/api/chat-notifications-brain-v240.php?action=state&agent_id=7',{headers});
  for(let n=0;!existsSync(gate+'.entered');n++){
    if(n>150)throw new Error('Actual Brain GET did not enter its controlled heavy projection.');
    await new Promise(r=>setTimeout(r,20));
  }
  const started=Date.now();
  const feed=await fetch(base+'/api/agent-top-of-mind-v1.php?agent_id=7&conversation_id=11',{
    headers,signal:AbortSignal.timeout(2000)
  });
  assert.equal(feed.status,200);const state=await feed.json();
  assert.ok(Date.now()-started<2000);assert.equal(existsSync(gate+'.release'),false);
  assert.equal(state.ok,true);assert.equal(state.state.owner_user_id,1);assert.equal(state.state.agent_id,7);
  assert.equal(state.state.conversation_id,11);assert.equal(state.state.working_attention_binding,session.binding);
  writeFileSync(gate+'.release','1');
  const brain=await held;held=null;assert.equal(brain.status,200);
  assert.equal((await brain.json()).fixture_session_active,false);
  const counts=await fetch(base+'/counts',{headers}).then(r=>r.json());
  assert.deepEqual(counts,{models:0,runs:0});
  console.log('PASS actual authenticated Brain GET releases its session before held projection; same-session actual Top of Mind API responds from a second PHP worker with exact owner/Agent/Chat binding');
  console.log('PASS invalid page binding cannot reach the held projection; both GETs perform zero inference and create no work');
}catch(error){console.error(logs.slice(-7000));throw error;}
finally{
  writeFileSync(gate+'.release','1');if(held)await held.catch(()=>{});
  if(server.pid&&server.exitCode===null&&server.signalCode===null){try{process.kill(-server.pid,'SIGTERM');}catch{}await new Promise(r=>server.once('exit',r));}
  rmSync(temp,{recursive:true,force:true});
}
