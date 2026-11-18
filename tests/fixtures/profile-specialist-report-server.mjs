import {spawn} from 'node:child_process';
import {copyFileSync,mkdirSync,mkdtempSync,readFileSync,rmSync,writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

/** Real activity GET and Profile adapter over an isolated, provider-free SQLite fixture. */
export async function startProfileSpecialistReportServer() {
  const root=fileURLToPath(new URL('../../',import.meta.url));
  const temp=mkdtempSync(join(tmpdir(),'vp3-profile-report-'));
  const quoted=value=>"'"+value.replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
  mkdirSync(join(temp,'api'));mkdirSync(join(temp,'includes'));
  copyFileSync(join(root,'api/agent-activity-hub-v1.php'),join(temp,'api/agent-activity-hub-v1.php'));
  writeFileSync(join(temp,'includes/agent-activity-hub-v1.php'),'<?php require_once '+quoted(join(root,'includes/agent-activity-hub-v1.php'))+';');
  writeFileSync(join(temp,'includes/bootstrap.php'),`<?php
    session_start();
    require ${quoted(join(root,'tests/fixtures/profile-specialist-sources-v1.php'))};
    pfs_reset();
    pfs_need_owner();
    pa3_login(1);
    require_once ${quoted(join(root,'includes/agent-activity-hub-v1.php'))};
    $scenario=$_SERVER['HTTP_X_VP3_TEST_PROFILE_STATE']??'';
    if($scenario==='unconfigured')db()->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1');
    if($scenario==='limited')db()->exec("DELETE FROM role_permissions WHERE permission_key='artist_listening.access'");
    if($scenario==='unavailable')db()->failSql='FROM profile_agent_conversations';
    $missingPermission=match($scenario){'missing_profile_agent_access'=>'profile_agent.access','missing_profile_chat_access'=>'profile_chat.access',default=>''};
    if($missingPermission!=='')db()->exec("DELETE FROM role_permissions WHERE role='member' AND permission_key='".$missingPermission."'");
    $latePermission=match($scenario){'late_profile_agent_access'=>'profile_agent.access','late_profile_chat_access'=>'profile_chat.access',default=>''};
    $before=[];$permissionRows=[];$sourceRead=false;$revoked=false;
    foreach(db()->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table){
      if(!preg_match('/^[a-z0-9_]+$/D',$table))continue;
      $rows=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
      $before[$table]=hash('sha256',json_encode($rows));
      if($table==='role_permissions')$permissionRows=$rows;
    }
    db()->afterSql=static function(string $sql,PA3PDO $pdo)use(&$before,$permissionRows,$latePermission,&$sourceRead,&$revoked):void{
      if(!preg_match('/^\\s*(SELECT|PRAGMA)\\b/i',$sql)){
        file_put_contents(${quoted(join(temp,'unexpected-write'))},'Profile report GET attempted a write.');
        throw new RuntimeException('Fixture rejected a read-side write.');
      }
      if($latePermission==='')return;
      if($pdo->inTransaction()&&str_contains($sql,'FROM profile_agent_conversations'))$sourceRead=true;
      if($sourceRead&&!$revoked&&!$pdo->inTransaction()&&$sql==='SELECT * FROM users WHERE id=?'){
        // A concurrent administrator revokes one Profile grant after the source
        // transaction committed but before the final authority reread completes.
        $revoked=true;
        $pdo->exec("DELETE FROM role_permissions WHERE role='member' AND permission_key='".$latePermission."'");
        // Permit only this explicit fixture mutation; all other rows stay immutable.
        $expected=array_values(array_filter($permissionRows,static fn(array $row):bool=>$row['role']!=='member'||$row['permission_key']!==$latePermission));
        $before['role_permissions']=hash('sha256',json_encode($expected));
        file_put_contents(${quoted(join(temp,'late-revocation'))},$latePermission);
      }
    };
    register_shutdown_function(static function()use(&$before):void{
      $pdo=db();$pdo->failSql='';$after=[];
      foreach($pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table){
        if(preg_match('/^[a-z0-9_]+$/D',$table))$after[$table]=hash('sha256',json_encode($pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC)));
      }
      if($before!==$after||count($GLOBALS['pa3ModelCalls']??[])>0){
        file_put_contents(${quoted(join(temp,'unexpected-write'))},'Profile report GET changed storage or invoked inference.');
      }
    });
  `);
  const assets=['agent-eyes-core.js','agent-orb.js','agent-orb.css','agent-activity-hub-v1.js','agent-activity-hub-v1.css',
    'agent-top-of-mind-v1.js','agent-top-of-mind-v1.css','chat-specialist-rail-v1.js','chat-specialist-rail-v1.css'];
  const page=`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Profile specialist acceptance</title>
    <style>body{margin:0;font:15px/1.5 system-ui;background:#f6f8fb;color:#202b3b}main{max-width:760px;padding:32px;margin:auto}button{font:inherit}#sfOnlineRailV109{position:fixed;right:12px;top:120px}</style>
    ${assets.filter(file=>file.endsWith('.css')).map(file=>`<link rel="stylesheet" href="/${file}">`).join('')}
    <main><h1>Profile</h1><p>Current visitor conversations, calls and reviewed follow-ups.</p>
    <section id="chatThread" data-view="chat"></section><form id="chatForm"><textarea aria-label="Chat message" id="chatInput"></textarea></form>
    <section id="topMindHost"></section></main><aside id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside>
    <script>
    window.fixtureScope={owner:1,agent:7,cid:11,binding:__BINDING__};
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:fixtureScope.binding};
    window.STONEFELLOW_CHAT_CONTINUITY={agentId:()=>fixtureScope.agent,conversationId:()=>fixtureScope.cid,isReady:()=>true,isRestoring:()=>false};
    window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:fixtureScope.binding,activityEndpoint:'/api/agent-activity-hub-v1.php',topOfMindEndpoint:'/fixture/work'};
    window.VP3_CHAT_SPECIALIST_PRESENCE={owner_user_id:1,agent_id:7,states:{profile:'idle'}};
    window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/chat.php',state:()=>({state:'idle'}),conversationRef:()=>({id:fixtureScope.cid,agent:fixtureScope.agent}),conversationUrl:ref=>'/chat.php?agent='+ref.agent+'&conversation_id='+ref.id,isMainChat:()=>true,focusChat:()=>{},stop:()=>{}};
    window.STONEFELLOW_NOTIFICATION_CENTER={close:()=>{}};
    window.profileScenario='';
    </script>
    ${assets.filter(file=>file.endsWith('.js')).map(file=>`<script src="/${file}"></script>`).join('')}`;
  writeFileSync(join(temp,'page.html'),page);
  writeFileSync(join(temp,'router.php'),`<?php
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if($path==='/health'){echo 'ready';return;}
    $assets=${JSON.stringify(assets)};
    if(in_array(ltrim($path,'/'),$assets,true)){
      header('Content-Type: '.(str_ends_with($path,'.css')?'text/css':'text/javascript').'; charset=utf-8');
      readfile(${quoted(root)}.ltrim($path,'/'));return;
    }
    if($path==='/api/agent-activity-hub-v1.php'){require __DIR__.$path;return;}
    require __DIR__.'/includes/bootstrap.php';
    if($path==='/session'){header('Content-Type:application/json');echo json_encode(['binding'=>vp3_catch_up_page_binding_v1(current_user())]);return;}
    if($path==='/fixture/work'){
      header('Content-Type:application/json');
      $cid=(int)($_GET['conversation_id']??11);
      $state=vp3_agent_activity_hub_request_v1(db(),current_user(),'GET',$_GET['agent_id']??'7',(string)$cid,
        $_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??'','profile');
      $state['contract']=($_GET['view']??'')==='work'?'vp3.agent.work.v1':'vp3.top-of-mind.v1';$state['health']=['enabled'=>false,'active_jobs'=>[]];
      $state['items']=[];$state['state']='ready';
      echo json_encode(['ok'=>true,'state'=>$state]);return;
    }
    if($path==='/'){
      header('Content-Type:text/html; charset=utf-8');
      echo str_replace('__BINDING__',json_encode(vp3_catch_up_page_binding_v1(current_user())),file_get_contents(__DIR__.'/page.html'));return;
    }
    http_response_code(404);echo 'not found';
  `);
  const probe=net.createServer();await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));
  const port=probe.address().port;await new Promise(resolve=>probe.close(resolve));
  const {VP3_TEST_MYSQL_DSN,VP3_PA3_CONFIG,VP3_PA3_ROOT,...environment}=process.env;
  const server=spawn(process.env.VP3_PHP_BINARY||'php',['-S',`127.0.0.1:${port}`,join(temp,'router.php')],{
    cwd:temp,env:environment,stdio:['ignore','pipe','pipe']});
  let logs='';server.stdout.on('data',data=>{logs+=data;});server.stderr.on('data',data=>{logs+=data;});
  const base=`http://127.0.0.1:${port}`;
  const close=async()=>{
    server.kill();await new Promise(resolve=>{if(server.exitCode!==null)resolve();else{server.once('exit',resolve);setTimeout(resolve,1000).unref();}});
    rmSync(temp,{recursive:true,force:true});
  };
  try{
    for(let tries=0;;tries++){
      if(server.exitCode!==null)throw new Error(logs||'Fixture server exited');
      try{if((await fetch(base+'/health')).ok)break;}catch{}
      if(tries>100)throw new Error('Fixture server did not start: '+logs);
      await new Promise(resolve=>setTimeout(resolve,40));
    }
  }catch(error){await close();throw error;}
  return {base,temp,logs:()=>logs,close,readLateRevocation:()=>{
    try{return readFileSync(join(temp,'late-revocation'),'utf8');}catch{return '';}
  },readWriteFailure:()=>{
    try{return readFileSync(join(temp,'unexpected-write'),'utf8');}catch{return '';}
  }};
}
