import {spawn,execFile} from 'node:child_process';
import {copyFileSync,mkdirSync,mkdtempSync,readFileSync,rmSync,writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {promisify} from 'node:util';

/** Persistent isolated SQL fixture serving unchanged production Profile APIs. */
export async function startProfileWorkspaceServer({analytics=true,imageAnalytics=false,responseAnalytics=false,marketing=false,marketingAgent=7}={}){
  if(![0,7].includes(marketingAgent)||marketing&&marketingAgent===0&&!analytics)throw new Error('Unsupported isolated Marketing fixture scope.');
  const root=fileURLToPath(new URL('../../',import.meta.url));
  const temp=mkdtempSync(join(tmpdir(),'vp3-profile-workspace-'));
  const quote=value=>"'"+value.replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
  const php=process.env.VP3_PHP_BINARY||'php';
  const {VP3_TEST_MYSQL_DSN,VP3_PA3_ROOT,VP3_PA3_CONFIG,...baseEnvironment}=process.env;
  const env={...baseEnvironment,VP3_PA3_CONFIG:join(temp,'config.json')};
  mkdirSync(join(temp,'api'));mkdirSync(join(temp,'includes'));
  for(const file of ['profile-specialist-workspace-v1.php','agent-activity-hub-v1.php',...(marketing?['marketing-followthrough-v1.php','initiative-workbench-v1.php']:[])]){
    copyFileSync(join(root,'api',file),join(temp,'api',file));
    writeFileSync(join(temp,'includes',file),'<?php require_once '+quote(join(root,'includes',file))+';');
  }
  if(marketing)for(const name of ['initiative-work-marketing-v1.php','initiative-work-marketing-promotion-v1.php','initiative-marketing-plans-v1.php'])writeFileSync(join(temp,'includes',name),'<?php require_once '+quote(join(root,'includes',name))+';');
  if(marketing)writeFileSync(join(temp,'includes','profile-analytics-authority-v1.php'),'<?php require_once '+quote(join(root,'includes/profile-analytics-authority-v1.php'))+';');
  writeFileSync(join(temp,'fixture.php'),`<?php
    ${marketing?"if(!defined('STONEFELLOW_ROOT'))define('STONEFELLOW_ROOT',__DIR__);":''}
    function vp3_profile_owner_draft_model_v1(PDO $pdo,array $scope,array $wire,string $prompt,Closure $dispatch):array{
      if(!$dispatch())return ['ok'=>false];
      file_put_contents(${quote(join(temp,'provider-dispatches'))},$wire['prepare_request_id']."\\n",FILE_APPEND);
      return ['ok'=>true,'answer'=>json_encode(['body'=>'Here is a private draft for this exact visitor.'],JSON_THROW_ON_ERROR)];
    }
    require ${quote(join(root,'tests/fixtures/profile-specialist-workspace-v1.php'))};
    require ${quote(join(root,'tests/fixtures/profile-specialist-analytics-v1.php'))};
    require ${quote(join(root,'tests/fixtures/profile-call-outcomes-data-v1.php'))};
    ${analytics?'require '+quote(join(root,'tests/fixtures/profile-analytics-period-data-v1.php'))+';':''}
    ${responseAnalytics?'require '+quote(join(root,'tests/fixtures/profile-response-analytics-data-v1.php'))+';':''}
    require ${quote(join(root,'includes/profile-analytics-destination-v1.php'))};
    ${marketing?'require '+quote(join(root,'tests/fixtures/profile-commerce-followthrough-data-v1.php'))+'; require '+quote(join(root,'includes/marketing-followthrough-destination-v1.php'))+';':''}
    function pws_server_seed():array{
      $seed=pws_reset();
      foreach(['artist_transcript_segments_v172','artist_transcript_master_analysis_v237','artist_transcript_page_analysis_v237'] as $table)if(table_exists($table))db()->exec('DELETE FROM '.$table);
      profile_specialist_analytics_fixture_v1(db());
      db()->prepare("UPDATE profile_agent_messages SET created_at=? WHERE sender_type='visitor'")->execute([gmdate('Y-m-d H:i:s',time()-10)]);
      pfs_advance(5000);
      $seed['outcomes']=pco_seed_v1(db(),['agent_id'=>7,'include_ending'=>false,'tasks'=>${marketing?3:23}]);
      $offer=pa3c_offer('visitor',pa3c_context(2,'d'));
      vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input($offer,'accept'));
      vp3_profile_call_work_pa3c(db(),(int)$offer['call_id'],'pa3c_provider');
      pa3_login(1);
      $seed['call_id']=(int)$offer['call_id'];$seed['call_conversation_id']=(int)$offer['conversation_id'];
      $seed['visit_events']=[];
      foreach(['8','9'] as $browser){$visit=pa4b_note(pa4b_context(0,$browser));$seed['visit_events'][]=(int)$visit['event']['id'];}
      pa3_login(1);
      ${analytics?`$seed['analytics']=profile_analytics_period_fixture_v1(db(),1,7,11);
      ${imageAnalytics?"$seed['image_analytics']=profile_image_analytics_fixture_v1(db(),$seed['analytics'],new DateTimeImmutable('now',new DateTimeZone('UTC')));":''}
      ${responseAnalytics?"$seed['responses']=profile_response_analytics_fixture_v1(db(),$seed['analytics'],new DateTimeImmutable('now',new DateTimeZone('UTC')));":''}
      $systemChat=db()->query('SELECT * FROM chat_conversations WHERE id=11')->fetch(PDO::FETCH_ASSOC);
      $systemChat['id']=94001;$systemChat['user_agent_id']=null;
      profile_analytics_period_fixture_row_v1(db(),'chat_conversations',$systemChat);
      $seed['system_chat']=94001;
      pa3_login(1);
      `:''}
      ${marketing?`$seed['followthrough']=profile_commerce_followthrough_fixture_v1(db(),1,${marketingAgent},${marketingAgent===0?94001:11});
      ${marketingAgent===7?`// An open thread remains discoverable while its saved promotion receipt keeps its fixed historical dates.
      db()->prepare("UPDATE profile_agent_conversations SET status='open' WHERE owner_user_id=? AND id=?")
        ->execute([1,$seed['followthrough']['ids']['public_conversation']]);
      profile_commerce_followthrough_fixture_event_v1(db(),$seed['followthrough']);`:''}
      profile_analytics_period_fixture_table_v1(db(),'agent_commerce_product_bindings_v800',['product_id'=>'BIGINT PRIMARY KEY','binding_type'=>"VARCHAR(40) NOT NULL DEFAULT ''",'binding_id'=>'BIGINT NULL']);
      profile_analytics_period_fixture_table_v1(db(),'agent_commerce_products_v800',['description'=>'TEXT NULL','metadata_json'=>'TEXT NULL','is_active'=>'INTEGER NOT NULL DEFAULT 1','product_type'=>"VARCHAR(32) NOT NULL DEFAULT 'physical'",
        'fulfillment_type'=>"VARCHAR(32) NOT NULL DEFAULT 'manual'",'payment_mode'=>"VARCHAR(24) NOT NULL DEFAULT 'one_time'",'price_cents'=>'BIGINT NOT NULL DEFAULT 2000',
        'deposit_cents'=>'BIGINT NOT NULL DEFAULT 0','currency'=>"VARCHAR(8) NOT NULL DEFAULT 'usd'",'updated_at'=>'DATETIME NULL']);
      profile_analytics_period_fixture_table_v1(db(),'user_media_assets',['file_size'=>'BIGINT NOT NULL DEFAULT 0']);
      $private=db()->query('SELECT * FROM agent_commerce_products_v800 WHERE id='.(int)$seed['followthrough']['ids']['product'])->fetch(PDO::FETCH_ASSOC);
      $private['id']=993101;$private['product_key']='profile:private-marketing-hub';$private['title']='Private T-shirt listing';$private['is_active']=0;
      $private['metadata_json']=json_encode(['listing_draft'=>'product_text_v1','agent_id'=>${marketingAgent},'conversation_id'=>${marketingAgent===0?94001:11},'source_title'=>'Description','visibility'=>'hidden'],JSON_THROW_ON_ERROR);
      profile_analytics_period_fixture_row_v1(db(),'agent_commerce_products_v800',$private);
      profile_analytics_period_fixture_row_v1(db(),'agent_product_launches_v1',['product_id'=>993101,'owner_user_id'=>1,'agent_id'=>${marketingAgent},'conversation_id'=>${marketingAgent===0?94001:11},
        'draft_key'=>null,'creative_id'=>null,'last_stage'=>'listing_review','last_notice_key'=>str_repeat('f',64),'next_check_at'=>'2020-01-01 00:00:00'],'product_id');
      $seed['followthrough']['ids']['private_plan']=993101;
      // Keep discovery meaningful when this historical fixture runs much later;
      // the extra unthreaded click is outside both saved fixed-period windows.
      $today=(new DateTimeImmutable('today',new DateTimeZone('UTC')))->getTimestamp();
      if($today>=$seed['followthrough']['cutoff']+2*86400)profile_analytics_period_fixture_row_v1(db(),'agent_promotion_clicks_v1',[
        'click_id'=>md5('marketing-hub-current-profile-click'),'publication_id'=>$seed['followthrough']['publication_id'],
        'placement'=>'profile','created_at'=>gmdate('Y-m-d H:i:s',$today-3600)],'click_id');
      pa3_login(1);`:''}
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
    $GLOBALS['pa3AppBase']=str_starts_with((string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),'/nested/app/')?'/nested/app':'';
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
    if(($_SERVER['REQUEST_METHOD']??'')==='GET'&&preg_match('~/api/[^/]+$~',parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH))){
      $before=pws_server_fingerprint();$providerBefore=file_get_contents(${quote(join(temp,'provider-dispatches'))});
      db()->afterSql=static function(string $sql):void{
        if(!preg_match('/^\\s*(SELECT|PRAGMA)\\b/i',$sql)){
          file_put_contents(${quote(join(temp,'unexpected-write'))},'Workspace GET attempted a storage write.');
          throw new RuntimeException('Fixture rejected a read-side write.');
        }
        if(is_file(${quote(join(temp,'marketing-logout-after-read'))})){
          $stack=array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),'function');$position=array_search('vp3_profile_analytics_authority_current_v1',$stack,true);
          if($position!==false&&($stack[$position+1]??null)==='vp3_agent_activity_product_marketing_v1'){
            unlink(${quote(join(temp,'marketing-logout-after-read'))});
            if(session_status()!==PHP_SESSION_ACTIVE)session_start(['use_cookies'=>false,'cache_limiter'=>'']);
            $_SESSION=[];session_destroy();reset_current_user_cache();
          }
        }
        if(is_file(${quote(join(temp,'marketing-profile-revoke-after-read'))})){
          $stack=array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),'function');$position=array_search('vp3_profile_analytics_authority_current_v1',$stack,true);
          if($position!==false&&($stack[$position+1]??null)==='vp3_agent_activity_product_marketing_v1')$GLOBALS['pwsMarketingSourcesReturned']=true;
          if(!empty($GLOBALS['pwsMarketingSourcesReturned'])&&in_array('current_user',$stack,true)&&!in_array('vp3_agent_activity_hub_v1',$stack,true)){
            unlink(${quote(join(temp,'marketing-profile-revoke-after-read'))});$hook=db()->afterSql;db()->afterSql=null;
            try{db()->exec("DELETE FROM role_permissions WHERE role=(SELECT role FROM users WHERE id=1) AND permission_key='profile_chat.access'");}
            finally{db()->afterSql=$hook;}$GLOBALS['pwsInjectedProfileRevocation']=true;
          }
        }
      };
      register_shutdown_function(static function()use($before,$providerBefore):void{
        $after=pws_server_fingerprint();if(!empty($GLOBALS['pwsInjectedProfileRevocation'])){unset($before['role_permissions'],$after['role_permissions']);}
        if($before!==$after||$providerBefore!==file_get_contents(${quote(join(temp,'provider-dispatches'))}))
          file_put_contents(${quote(join(temp,'unexpected-write'))},'Workspace GET changed storage or dispatched inference.');
      });
    }
  `);
  const assets=['agent-eyes-core.js','agent-orb.js','agent-orb.css','agent-activity-hub-v1.js','agent-activity-hub-v1.css',
    'agent-top-of-mind-v1.js','agent-top-of-mind-v1.css','chat-specialist-rail-v1.js','chat-specialist-rail-v1.css',
    'profile-agent-analytics.js','profile-agent-analytics.css','profile-specialist-workspace-v1.js','profile-specialist-workspace-v1.css',
    'profile-activity-chat.js','profile-activity-chat.css','profile-calls-pa3c.js','profile-calls-pa3c.css','profile-call-activity-pa3c.js','profile-call-activity-pa3c.css',
    ...(marketing?['media-library-picker-v1.js','media-library-picker-v1.css','chat-media-commands-v1.js','chat-media-commands-v1.css','chat-marketing-followthrough-v1.js',
      'chat-initiative-workbench-v1.js','chat-initiative-workbench-v1.css','chat-marketing-canvas-v1.js','chat-marketing-creative-render-v1.js']:[])];
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
    window.VP3_PROFILE_WORKSPACE_V1={endpoint:'/api/profile-specialist-workspace-v1.php',csrf:'pa3-fixture-csrf',ownerKey:'1',agentId:7,workingAttentionBinding:fixtureScope.binding,profileUrl:'/profile-agent.php',chatUrl:'/chat.php'};
    window.VP3_CHAT_SPECIALIST_PRESENCE={owner_user_id:1,agent_id:7,states:{profile:'idle'}};
    window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/chat.php',state:()=>({state:'idle'}),conversationRef:()=>({id:fixtureScope.cid,agent:fixtureScope.agent}),conversationUrl:ref=>'/chat.php?agent='+ref.agent+'&conversation_id='+ref.id,isMainChat:()=>true,focusChat:()=>{},stop:()=>{}};
    window.STONEFELLOW_NOTIFICATION_CENTER={close:()=>{}};
    </script>${assets.filter(file=>file.endsWith('.js')).map(file=>`<script src="/${file}"></script>`).join('')}</body></html>`;
  writeFileSync(join(temp,'page.html'),page);
  const chatPage=`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Profile period destination acceptance</title>
<link rel="stylesheet" href="__BASE__/chat.css">
<link rel="stylesheet" href="__BASE__/agent-orb.css">
<link rel="stylesheet" href="__BASE__/agent-activity-hub-v1.css">
<link rel="stylesheet" href="__BASE__/agent-top-of-mind-v1.css">
<link rel="stylesheet" href="__BASE__/chat-specialist-rail-v1.css">
<link rel="stylesheet" href="__BASE__/profile-agent-analytics.css">
<link rel="stylesheet" href="__BASE__/profile-specialist-workspace-v1.css">
<link rel="stylesheet" href="__BASE__/profile-activity-chat.css">
<link rel="stylesheet" href="__BASE__/profile-calls-pa3c.css">
<link rel="stylesheet" href="__BASE__/profile-call-activity-pa3c.css">
${marketing?'<link rel="stylesheet" href="__BASE__/chat-media-commands-v1.css"><link rel="stylesheet" href="__BASE__/chat-initiative-workbench-v1.css"><link rel="stylesheet" href="__BASE__/media-library-picker-v1.css">':''}
<style>body{margin:0;font:15px/1.5 system-ui;background:#f6f8fb;color:#202b3b}main{max-width:760px;padding:24px;margin:auto}button{font:inherit}.chat-topbar-actions{display:flex;gap:8px}#sfOnlineRailV109{position:fixed;right:12px;top:120px}[hidden]{display:none!important}</style>
</head><body class="chat-page"><main><header><h1>Private Chat</h1><div class="chat-topbar-actions"></div></header>
<button id="newChatButton" type="button">New Chat</button><div id="chatHistory"></div>
<section id="chatThread" class="chat-thread" data-view="chat"><div id="chatWelcome" hidden></div></section>
<form id="chatForm"><textarea id="chatInput" aria-label="Private Chat message"></textarea><button id="sendChatButton">Send</button></form>
</main><aside id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside>
<script>
window.fixtureSeed=__SEED__;window.fixtureScope={owner:1,agent:__AGENT__,cid:__CID__,binding:__BINDING__};
window.STONEFELLOW_CHAT={userId:1,agentId:__AGENT__,initialConversationId:__CID__,initialView:'chat',endpoint:'__BASE__/api/chat-v236.php',csrf:__CSRF__,workingAttentionBinding:__BINDING__,profileAnalyticsDestination:__DESTINATION__,marketingFollowthroughDestination:__MARKETING_DESTINATION__,autoGreetEnabled:false};
window.VP3_ORB_CONFIG={ownerKey:'1',agentId:__AGENT__,workingAttentionBinding:__BINDING__,activityEndpoint:'__BASE__/api/agent-activity-hub-v1.php',topOfMindEndpoint:'__BASE__/fixture/work'};
__PROFILE_WORKSPACE_CONFIG__
window.VP3_CHAT_SPECIALIST_PRESENCE={owner_user_id:1,agent_id:__AGENT__,states:__PROFILE_PRESENCE__};
${marketing?"window.VP3_INITIATIVE_WORKBENCH={ownerKey:'1',agentId:__AGENT__,csrf:__CSRF__,endpoint:'__BASE__/api/initiative-workbench-v1.php',mediaEndpoint:'__BASE__/api/media-library-v86.php',followthroughEndpoint:'__BASE__/api/marketing-followthrough-v1.php'};":''}
window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'__BASE__/chat.php',state:()=>({state:'idle'}),conversationRef:()=>({id:window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()||0,agent:__AGENT__}),conversationUrl:ref=>'__BASE__/chat.php?agent='+(__AGENT__||'system')+'&conversation_id='+ref.id,isMainChat:()=>true,focusChat:()=>{},stop:()=>{}};
window.STONEFELLOW_NOTIFICATION_CENTER={close:()=>{}};
</script>
<script src="__BASE__/chat.js"></script>
<script src="__BASE__/agent-eyes-core.js"></script>
<script src="__BASE__/agent-orb.js"></script>
<script src="__BASE__/agent-activity-hub-v1.js"></script>
<script src="__BASE__/agent-top-of-mind-v1.js"></script>
<script src="__BASE__/chat-specialist-rail-v1.js"></script>
<script src="__BASE__/profile-agent-analytics.js"></script>
__PROFILE_WORKSPACE_SCRIPT__
<script src="__BASE__/profile-activity-chat.js"></script>
<script src="__BASE__/profile-calls-pa3c.js"></script>
<script src="__BASE__/profile-call-activity-pa3c.js"></script>
${marketing?['media-library-picker-v1.js','chat-marketing-creative-render-v1.js','chat-marketing-canvas-v1.js','chat-initiative-workbench-v1.js','chat-marketing-followthrough-v1.js','chat-media-commands-v1.js'].map(name=>'<script src="__BASE__/'+name+'"></script>').join(''):''}
</body></html>`;
  writeFileSync(join(temp,'chat-page.html'),chatPage);
  writeFileSync(join(temp,'router.php'),`<?php
    $originalQuery=(string)($_SERVER['QUERY_STRING']??'');
    $originalPath=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    $appBase=str_starts_with($originalPath,'/nested/app/')?'/nested/app':'';
    $path=$appBase!==''?substr($originalPath,strlen($appBase)):$originalPath;
    if($path==='/health'){echo 'ready';return;}
    $assets=${JSON.stringify([...assets,'chat.js','chat.css'])};
    if(in_array(ltrim($path,'/'),$assets,true)){header('Content-Type: '.(str_ends_with($path,'.css')?'text/css':'text/javascript').'; charset=utf-8');readfile(${quote(root)}.ltrim($path,'/'));return;}
    if(in_array($path,${JSON.stringify(['/api/profile-specialist-workspace-v1.php','/api/agent-activity-hub-v1.php',...(marketing?['/api/marketing-followthrough-v1.php','/api/initiative-workbench-v1.php']:[])])},true)){require __DIR__.$path;return;}
    require __DIR__.'/includes/bootstrap.php';
    if(in_array($path,['/session','/','/chat.php'],true)&&!current_user())pa3_login(1);
    if($path==='/session'){header('Content-Type:application/json');echo json_encode(['binding'=>vp3_catch_up_page_binding_v1(current_user()),'csrf'=>csrf_token(),'seed'=>$seed]);return;}
    if($path==='/chat.php'){
      // Production authority proves selection; this fixture has one known owned fallback Chat.
      $requested=vp3_profile_analytics_destination_request_v1($originalQuery);$marketingRequested=${marketing?'vp3_marketing_followthrough_destination_request_v1($originalQuery)':'null'};
      $initialRequested=$requested??$marketingRequested;$selectedAgent=7;$selectedChat=11;$allowed=true;
      if($initialRequested!==null){
        try{vp3_profile_workspace_scope_v1(db(),current_user(),$initialRequested['agent_id'],$initialRequested['conversation_id']);
          $selectedAgent=$initialRequested['agent_id'];$selectedChat=$initialRequested['conversation_id'];}catch(Throwable $e){}
      }
      try{vp3_profile_workspace_scope_v1(db(),current_user(),$selectedAgent,$selectedChat);}catch(Throwable $e){$allowed=false;}
      $binding=vp3_catch_up_page_binding_v1(current_user());
      $destination=vp3_profile_analytics_destination_bind_v1($requested,(int)current_user()['id'],$selectedAgent,$selectedChat,$binding,$appBase.'/chat.php',$allowed);
      $marketingDestination=${marketing?"vp3_marketing_followthrough_destination_bind_v1($marketingRequested,(int)current_user()['id'],$selectedAgent,$selectedChat,$binding,$appBase.'/chat.php',$allowed,db())":'null'};
      $jsonFlags=JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
      $profileEnabled=personal_capability_has_v242('profile_agent.access',current_user())&&personal_capability_has_v242('profile_chat.access',current_user());
      $profileWorkspaceConfig=$profileEnabled?'window.VP3_PROFILE_WORKSPACE_V1='.json_encode(['endpoint'=>$appBase.'/api/profile-specialist-workspace-v1.php','csrf'=>csrf_token(),'ownerKey'=>'1','agentId'=>$selectedAgent,'workingAttentionBinding'=>$binding,'profileUrl'=>$appBase.'/profile-agent.php','chatUrl'=>$appBase.'/chat.php'],$jsonFlags).';':'';
      $profileWorkspaceScript=$profileEnabled?'<script src="'.$appBase.'/profile-specialist-workspace-v1.js"></script>':${marketing?"'<script data-vp3-period-tools-only src=\"'.$appBase.'/profile-specialist-workspace-v1.js\"></script>'":"''"};
      $replacements=['__BASE__'=>$appBase,'__AGENT__'=>(string)$selectedAgent,'__CID__'=>(string)$selectedChat,
        '__BINDING__'=>json_encode($binding,$jsonFlags),'__CSRF__'=>json_encode(csrf_token(),$jsonFlags),
        '__DESTINATION__'=>json_encode($destination,$jsonFlags),'__MARKETING_DESTINATION__'=>json_encode($marketingDestination,$jsonFlags),'__SEED__'=>json_encode($seed,$jsonFlags),
        '__PROFILE_WORKSPACE_CONFIG__'=>$profileWorkspaceConfig,'__PROFILE_WORKSPACE_SCRIPT__'=>$profileWorkspaceScript,'__PROFILE_PRESENCE__'=>$profileEnabled?'{profile:"idle"}':'{}'];
      header('Content-Type:text/html; charset=utf-8');echo str_replace(array_keys($replacements),array_values($replacements),file_get_contents(__DIR__.'/chat-page.html'));return;
    }
    if($path==='/fixture/reset'&&($_SERVER['REQUEST_METHOD']??'')==='POST'){header('Content-Type:application/json');echo json_encode(pws_server_seed());return;}
    if($path==='/fixture/fingerprint'){header('Content-Type:application/json');echo json_encode(pws_server_fingerprint(),JSON_THROW_ON_ERROR);return;}
    if($path==='/fixture/marketing-sources'){
      header('Content-Type:application/json');
      try{require_once ${quote(join(root,'includes/agent-activity-hub-v1.php'))};require_once ${quote(join(root,'includes/agent-coordination-product-marketing-v1.php'))};
        $scope=vp3_agent_activity_scope_v1(db(),current_user(),${marketingAgent},${marketingAgent===0?94001:11});
        echo json_encode(vp3_coordination_product_marketing_sources_v1(db(),$scope,null,new DateTimeImmutable('now',new DateTimeZone('UTC'))),JSON_THROW_ON_ERROR);
      }catch(Throwable $error){http_response_code(500);echo json_encode(['error'=>get_class($error).': '.$error->getMessage()]);}return;
    }
    if($path==='/fixture/state'){
      $state=[];foreach(['profile_agent_messages','profile_agent_handoff_operations_pa3','profile_owner_drafts_v1','profile_calls_pa3c','chat_messages'] as $table)$state[$table]=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
      $dispatches=file_get_contents(${quote(join(temp,'provider-dispatches'))});$state['provider_dispatches']=$dispatches===''?[]:explode("\\n",trim($dispatches));
      header('Content-Type:application/json');echo json_encode($state,JSON_THROW_ON_ERROR);return;
    }
    if($path==='/fixture/control'&&($_SERVER['REQUEST_METHOD']??'')==='POST'){
      $input=json_decode(file_get_contents('php://input'),true);$action=$input['action']??'';
      if($action==='revoke')db()->exec("DELETE FROM role_permissions WHERE role=(SELECT role FROM users WHERE id=1) AND permission_key='profile_chat.access'");
      elseif($action==='restore')db()->exec("INSERT OR IGNORE INTO role_permissions(role,permission_key) SELECT role,'profile_chat.access' FROM users WHERE id=1");
      elseif($action==='timezone_change')db()->exec("UPDATE users SET timezone='Asia/Tokyo' WHERE id=1");
      elseif($action==='analytics_events_off')db()->exec('ALTER TABLE profile_events RENAME TO fixture_unavailable_profile_events');
      elseif($action==='analytics_events_restore')db()->exec('ALTER TABLE fixture_unavailable_profile_events RENAME TO profile_events');
      elseif($action==='analytics_images_off')db()->exec('ALTER TABLE profile_image_edits_v1 RENAME TO fixture_unavailable_profile_image_edits');
      elseif($action==='analytics_images_restore')db()->exec('ALTER TABLE fixture_unavailable_profile_image_edits RENAME TO profile_image_edits_v1');
      elseif($action==='analytics_responses_off')db()->exec('ALTER TABLE profile_agent_owner_requests_v1 RENAME TO fixture_unavailable_profile_owner_requests');
      elseif($action==='analytics_responses_restore')db()->exec('ALTER TABLE fixture_unavailable_profile_owner_requests RENAME TO profile_agent_owner_requests_v1');
      elseif($action==='analytics_note_revoke')db()->exec("UPDATE user_data_policies SET owner_agents_allowed=0 WHERE owner_user_id=1 AND resource_type='knowledge'");
      elseif($action==='analytics_note_restore')db()->exec("UPDATE user_data_policies SET owner_agents_allowed=1 WHERE owner_user_id=1 AND resource_type='knowledge'");
      elseif($action==='analytics_revoke')db()->exec("DELETE FROM role_permissions WHERE role=(SELECT role FROM users WHERE id=1) AND permission_key IN ('profile_chat.access','account.access')");
      elseif($action==='analytics_period_off')db()->exec("DELETE FROM settings WHERE setting_key='personal_capabilities_seed_v242'");
      elseif($action==='analytics_period_restore')db()->exec("INSERT INTO settings(setting_key,setting_value) VALUES('personal_capabilities_seed_v242','1')");
      elseif($action==='followthrough_clicks_off')db()->exec('ALTER TABLE agent_promotion_clicks_v1 RENAME TO fixture_promotion_clicks_unavailable');
      elseif($action==='followthrough_clicks_restore')db()->exec('ALTER TABLE fixture_promotion_clicks_unavailable RENAME TO agent_promotion_clicks_v1');
      elseif($action==='followthrough_legacy_click')db()->prepare('UPDATE agent_promotion_clicks_v1 SET native_context_json_v1=NULL WHERE click_id=?')->execute([$seed['followthrough']['ids']['native_click']]);
      elseif($action==='followthrough_changed')db()->prepare('UPDATE agent_commerce_promotions_v1 SET conversation_id=12 WHERE id=?')->execute([$seed['followthrough']['ids']['original_creative']]);
      elseif($action==='revoke_chat')db()->exec("DELETE FROM role_permissions WHERE role=(SELECT role FROM users WHERE id=1) AND permission_key='chat.access'");
      elseif($action==='logout'){$_SESSION=[];session_destroy();reset_current_user_cache();}
      elseif($action==='marketing_logout_after_read')file_put_contents(${quote(join(temp,'marketing-logout-after-read'))},'1');
      elseif($action==='marketing_profile_revoke_after_read')file_put_contents(${quote(join(temp,'marketing-profile-revoke-after-read'))},'1');
      elseif($action==='profile_off')db()->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1');
      elseif($action==='profile_missing')db()->exec('DELETE FROM user_profiles WHERE user_id=1');
      elseif($action==='recording_revoke')db()->exec("DELETE FROM role_permissions WHERE role=(SELECT role FROM users WHERE id=1) AND permission_key='artist_listening.access'");
      elseif($action==='recording_restore')db()->exec("INSERT OR IGNORE INTO role_permissions(role,permission_key) SELECT role,'artist_listening.access' FROM users WHERE id=1");
      elseif($action==='transcript_change')db()->prepare("UPDATE artist_transcript_segments_v172 SET transcript_text=transcript_text||' Corrected saved detail.' WHERE id=(SELECT MIN(id) FROM artist_transcript_segments_v172 WHERE session_id=?)")->execute([(int)$seed['outcomes']['assisted']['session_id']]);
      elseif($action==='analysis_refresh')pco_analysis_v1(db(),(int)$seed['outcomes']['assisted']['session_id']);
      elseif($action==='tasks_complete')db()->prepare("UPDATE agent_memory_items SET metadata_json=json_set(metadata_json,'$.task_status','completed') WHERE memory_type='task' AND json_extract(metadata_json,'$.source_session_id')=CAST(? AS INTEGER)")->execute([(int)$seed['outcomes']['assisted']['session_id']]);
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
