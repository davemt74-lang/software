import {spawn,execFile} from 'node:child_process';
import {copyFileSync,mkdirSync,mkdtempSync,readFileSync,rmSync,writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {promisify} from 'node:util';

/** Persistent isolated SQL fixture serving unchanged production Profile APIs. */
export async function startProfileWorkspaceServer(){
  const root=fileURLToPath(new URL('../../',import.meta.url));
  const temp=mkdtempSync(join(tmpdir(),'vp3-profile-workspace-'));
  const quote=value=>"'"+value.replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
  const php=process.env.VP3_PHP_BINARY||'php';
  const {VP3_TEST_MYSQL_DSN,VP3_PA3_ROOT,VP3_PA3_CONFIG,...baseEnvironment}=process.env;
  const env={...baseEnvironment,VP3_PA3_CONFIG:join(temp,'config.json')};
  mkdirSync(join(temp,'api'));mkdirSync(join(temp,'includes'));
  for(const file of ['profile-specialist-workspace-v1.php','agent-activity-hub-v1.php']){
    copyFileSync(join(root,'api',file),join(temp,'api',file));
    writeFileSync(join(temp,'includes',file),'<?php require_once '+quote(join(root,'includes',file))+';');
  }
  writeFileSync(join(temp,'fixture.php'),`<?php
    function vp3_profile_owner_draft_model_v1(PDO $pdo,array $scope,array $wire,string $prompt,Closure $dispatch):array{
      if(!$dispatch())return ['ok'=>false];
      file_put_contents(${quote(join(temp,'provider-dispatches'))},$wire['prepare_request_id']."\\n",FILE_APPEND);
      return ['ok'=>true,'answer'=>json_encode(['body'=>'Here is a private draft for this exact visitor.'],JSON_THROW_ON_ERROR)];
    }
    require ${quote(join(root,'tests/fixtures/profile-specialist-workspace-v1.php'))};
    require ${quote(join(root,'tests/fixtures/profile-specialist-analytics-v1.php'))};
    function pws_server_seed():array{
      $seed=pws_reset();
      profile_specialist_analytics_fixture_v1(db());
      db()->prepare("UPDATE profile_agent_messages SET created_at=? WHERE sender_type='visitor'")->execute([gmdate('Y-m-d H:i:s',time()-10)]);
      pfs_advance(5000);
      $offer=pa3c_offer('visitor',pa3c_context(2,'d'));
      vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input($offer,'accept'));
      vp3_profile_call_work_pa3c(db(),(int)$offer['call_id'],'pa3c_provider');
      pa3_login(1);
      $seed['call_id']=(int)$offer['call_id'];$seed['call_conversation_id']=(int)$offer['conversation_id'];
      $seed['clock']=$GLOBALS['pa3Now'];
      file_put_contents(${quote(join(temp,'seed.json'))},json_encode($seed,JSON_THROW_ON_ERROR));
      file_put_contents(${quote(join(temp,'provider-dispatches'))},'');
      return $seed;
    }
  `);
  writeFileSync(join(temp,'init.php'),`<?php require __DIR__.'/fixture.php';echo json_encode(pws_server_seed(),JSON_THROW_ON_ERROR);`);
  let seed;
  try {seed=JSON.parse((await promisify(execFile)(php,[join(temp,'init.php')],{env,maxBuffer:2*1024*1024})).stdout);}
  catch(error){rmSync(temp,{recursive:true,force:true});throw error;}
  writeFileSync(join(temp,'includes/bootstrap.php'),`<?php
    session_start();require __DIR__.'/../fixture.php';
    $seed=json_decode(file_get_contents(${quote(join(temp,'seed.json'))}),true,512,JSON_THROW_ON_ERROR);
    $GLOBALS['pa3Now']=$seed['clock'];pfs_clock();
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
      db()->sqliteCreateFunction('DATABASE',static fn()=>'main');
      db()->exec("ATTACH DATABASE ':memory:' AS information_schema");
      db()->exec("CREATE TABLE information_schema.statistics AS SELECT 'main' table_schema,tbl_name table_name,name index_name FROM main.sqlite_master WHERE type='index'");
    }
    function pws_server_fingerprint():array{
      $out=[];
      foreach(db()->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table){
        if(preg_match('/^[a-z0-9_]+$/D',$table))$out[$table]=hash('sha256',json_encode(db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC)));
      }
      return $out;
    }
    if(($_SERVER['REQUEST_METHOD']??'')==='GET'&&str_starts_with(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/api/')){
      $before=pws_server_fingerprint();$providerBefore=file_get_contents(${quote(join(temp,'provider-dispatches'))});
      db()->afterSql=static function(string $sql):void{
        if(!preg_match('/^\\s*(SELECT|PRAGMA)\\b/i',$sql)){
          file_put_contents(${quote(join(temp,'unexpected-write'))},'Workspace GET attempted a storage write.');
          throw new RuntimeException('Fixture rejected a read-side write.');
        }
      };
      register_shutdown_function(static function()use($before,$providerBefore):void{
        if($before!==pws_server_fingerprint()||$providerBefore!==file_get_contents(${quote(join(temp,'provider-dispatches'))}))
          file_put_contents(${quote(join(temp,'unexpected-write'))},'Workspace GET changed storage or dispatched inference.');
      });
    }
  `);
  const assets=['agent-eyes-core.js','agent-orb.js','agent-orb.css','agent-activity-hub-v1.js','agent-activity-hub-v1.css',
    'agent-top-of-mind-v1.js','agent-top-of-mind-v1.css','chat-specialist-rail-v1.js','chat-specialist-rail-v1.css',
    'profile-agent-analytics.js','profile-agent-analytics.css','profile-specialist-workspace-v1.js','profile-specialist-workspace-v1.css',
    'profile-activity-chat.js','profile-activity-chat.css','profile-calls-pa3c.js','profile-calls-pa3c.css','profile-call-activity-pa3c.js','profile-call-activity-pa3c.css'];
  const page=`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Profile workspace acceptance</title>
    <style>body{margin:0;font:15px/1.5 system-ui;background:#f6f8fb;color:#202b3b}main{max-width:760px;padding:24px;margin:auto}button{font:inherit}#sfOnlineRailV109{position:fixed;right:12px;top:120px}.chat-topbar-actions{display:flex;gap:8px}</style>
    ${assets.filter(file=>file.endsWith('.css')).map(file=>`<link rel="stylesheet" href="/${file}">`).join('')}
    </head><body><main><header><h1>Profile</h1><div class="chat-topbar-actions"></div></header><section id="chatThread" data-view="chat">
    <section id="fixtureNativeCall" tabindex="-1" data-profile-call-id="${seed.call_id}">Existing call card <span data-fixture-media>Media stays attached</span></section></section>
    <form id="chatForm"><textarea id="chatInput" aria-label="Private Chat message"></textarea></form><section id="topMindHost"></section></main>
    <aside id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside>
    <script>
    window.fixtureScope={owner:1,agent:7,cid:11,binding:__BINDING__};window.fixtureSeed=${JSON.stringify(seed)};
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:fixtureScope.binding};
    window.STONEFELLOW_CHAT_CONTINUITY=window.STONEFELLOW_CHAT_CONTINUITY_V87={agentId:()=>fixtureScope.agent,conversationId:()=>fixtureScope.cid,isReady:()=>true,isRestoring:()=>false};
    window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:fixtureScope.binding,activityEndpoint:'/api/agent-activity-hub-v1.php',topOfMindEndpoint:'/fixture/work'};
    window.VP3_PROFILE_WORKSPACE_V1={endpoint:'/api/profile-specialist-workspace-v1.php',csrf:'pa3-fixture-csrf',ownerKey:'1',agentId:7,workingAttentionBinding:fixtureScope.binding,profileUrl:'/profile-agent.php'};
    window.VP3_CHAT_SPECIALIST_PRESENCE={owner_user_id:1,agent_id:7,states:{profile:'idle'}};
    window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/chat.php',state:()=>({state:'idle'}),conversationRef:()=>({id:fixtureScope.cid,agent:fixtureScope.agent}),conversationUrl:ref=>'/chat.php?agent='+ref.agent+'&conversation_id='+ref.id,isMainChat:()=>true,focusChat:()=>{},stop:()=>{}};
    window.STONEFELLOW_NOTIFICATION_CENTER={close:()=>{}};
    </script>${assets.filter(file=>file.endsWith('.js')).map(file=>`<script src="/${file}"></script>`).join('')}</body></html>`;
  writeFileSync(join(temp,'page.html'),page);
  writeFileSync(join(temp,'router.php'),`<?php
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if($path==='/health'){echo 'ready';return;}
    $assets=${JSON.stringify(assets)};
    if(in_array(ltrim($path,'/'),$assets,true)){header('Content-Type: '.(str_ends_with($path,'.css')?'text/css':'text/javascript').'; charset=utf-8');readfile(${quote(root)}.ltrim($path,'/'));return;}
    if(in_array($path,['/api/profile-specialist-workspace-v1.php','/api/agent-activity-hub-v1.php'],true)){require __DIR__.$path;return;}
    require __DIR__.'/includes/bootstrap.php';
    if(in_array($path,['/session','/'],true)&&!current_user())pa3_login(1);
    if($path==='/session'){header('Content-Type:application/json');echo json_encode(['binding'=>vp3_catch_up_page_binding_v1(current_user()),'csrf'=>csrf_token(),'seed'=>$seed]);return;}
    if($path==='/fixture/reset'&&($_SERVER['REQUEST_METHOD']??'')==='POST'){header('Content-Type:application/json');echo json_encode(pws_server_seed());return;}
    if($path==='/fixture/state'){
      $state=[];foreach(['profile_agent_messages','profile_agent_handoff_operations_pa3','profile_owner_drafts_v1','profile_calls_pa3c','chat_messages'] as $table)$state[$table]=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
      $dispatches=file_get_contents(${quote(join(temp,'provider-dispatches'))});$state['provider_dispatches']=$dispatches===''?[]:explode("\\n",trim($dispatches));
      header('Content-Type:application/json');echo json_encode($state,JSON_THROW_ON_ERROR);return;
    }
    if($path==='/fixture/control'&&($_SERVER['REQUEST_METHOD']??'')==='POST'){
      $input=json_decode(file_get_contents('php://input'),true);$action=$input['action']??'';
      if($action==='revoke')db()->exec("DELETE FROM role_permissions WHERE role='member' AND permission_key='profile_chat.access'");
      elseif($action==='restore')db()->exec("INSERT OR IGNORE INTO role_permissions(role,permission_key) VALUES('member','profile_chat.access')");
      elseif($action==='profile_off')db()->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1');
      elseif($action==='profile_missing')db()->exec('DELETE FROM user_profiles WHERE user_id=1');
      elseif($action==='new_message'){
        pfs_advance(5000);pa3_admit('A new visitor question changes this reply.',(int)$seed['primary'],bin2hex(random_bytes(16)),0,'b');pa3_login(1);
        $seed['clock']=$GLOBALS['pa3Now'];file_put_contents(${quote(join(temp,'seed.json'))},json_encode($seed,JSON_THROW_ON_ERROR));
      }else{http_response_code(422);}
      header('Content-Type:application/json');echo json_encode(['ok'=>http_response_code()<400]);return;
    }
    if($path==='/fixture/work'){
      require_once ${quote(join(root,'includes/agent-activity-hub-v1.php'))};header('Content-Type:application/json');
      $state=vp3_agent_activity_hub_request_v1(db(),current_user(),'GET',$_GET['agent_id']??'7',$_GET['conversation_id']??'11',$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??'','profile');
      $state['contract']=($_GET['view']??'')==='work'?'vp3.agent.work.v1':'vp3.top-of-mind.v1';$state['health']=['enabled'=>false,'active_jobs'=>[]];$state['items']=[];$state['state']='ready';
      echo json_encode(['ok'=>true,'state'=>$state]);return;
    }
    if($path==='/'){header('Content-Type:text/html; charset=utf-8');echo str_replace('__BINDING__',json_encode(vp3_catch_up_page_binding_v1(current_user())),file_get_contents(__DIR__.'/page.html'));return;}
    http_response_code(404);echo 'not found';
  `);
  const probe=net.createServer();await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));const port=probe.address().port;await new Promise(resolve=>probe.close(resolve));
  const server=spawn(php,['-S',`127.0.0.1:${port}`,join(temp,'router.php')],{cwd:temp,env,stdio:['ignore','pipe','pipe']});
  let logs='';server.stdout.on('data',value=>{logs+=value;});server.stderr.on('data',value=>{logs+=value;});
  const base=`http://127.0.0.1:${port}`;
  const close=async()=>{server.kill();await new Promise(resolve=>{if(server.exitCode!==null)resolve();else{server.once('exit',resolve);setTimeout(resolve,1000).unref();}});rmSync(temp,{recursive:true,force:true});};
  try {for(let attempt=0;;attempt++){if(server.exitCode!==null)throw new Error(logs||'Fixture server exited');try{if((await fetch(base+'/health')).ok)break;}catch{}if(attempt>100)throw new Error('Fixture server did not start: '+logs);await new Promise(resolve=>setTimeout(resolve,40));}}
  catch(error){await close();throw error;}
  return {base,seed,logs:()=>logs,close,readWriteFailure:()=>{try{return readFileSync(join(temp,'unexpected-write'),'utf8');}catch{return '';}}};
}
