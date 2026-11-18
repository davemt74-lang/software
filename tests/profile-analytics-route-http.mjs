import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {mkdtempSync,writeFileSync,rmSync,readFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import net from 'node:net';
import {periodRef,cutoff} from './fixtures/profile-analytics-period-ui-v1.mjs';

const root=fileURLToPath(new URL('../',import.meta.url)),temp=mkdtempSync(join(tmpdir(),'vp3-profile-route-'));
const quote=value=>"'"+value.replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
const router=String.raw`<?php
require_once __HELPER__;
header('Content-Type: application/json');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$agent=$path==='/system'?0:7;$conversation=$path==='/other-chat'?12:11;
$allowed=$path!=='/denied';
$requested=vp3_profile_analytics_destination_request_v1((string)($_SERVER['QUERY_STRING']??''),new DateTimeImmutable('2026-11-20T00:00:00+00:00'));
$bound=vp3_profile_analytics_destination_bind_v1($requested,1,$agent,$conversation,'page-binding','/nested/app/chat.php',$allowed);
echo json_encode(['requested'=>$requested,'destination'=>$bound],JSON_THROW_ON_ERROR);
`;
writeFileSync(join(temp,'router.php'),router.replace('__HELPER__',quote(join(root,'includes/profile-analytics-destination-v1.php'))));
const probe=net.createServer();await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));const port=probe.address().port;await new Promise(resolve=>probe.close(resolve));
const child=spawn(process.env.VP3_PHP_BINARY||'php',['-S','127.0.0.1:'+port,join(temp,'router.php')],{cwd:temp,stdio:['ignore','pipe','pipe']});
let logs='';child.stdout.on('data',data=>logs+=data);child.stderr.on('data',data=>logs+=data);
let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;};
const base='http://127.0.0.1:'+port;
const query=(agent='7',cid='11',ref=periodRef())=>'agent='+agent+'&conversation_id='+cid+'&profile_analytics='+encodeURIComponent(ref);
const read=async(path,raw)=>{const response=await fetch(base+path+'?'+raw);assert.equal(response.status,200,await response.clone().text());return response.json();};
try{
  for(let attempt=0;;attempt++){if(child.exitCode!==null)throw new Error(logs);try{if((await fetch(base+'/')).ok)break;}catch{}if(attempt>100)throw new Error('PHP route fixture did not start: '+logs);await new Promise(resolve=>setTimeout(resolve,40));}
  const valid=await read('/',query());
  check(valid.destination?.chatAgentId===7&&valid.destination.chatConversationId===11,'actual helper binds exact authorized selected tuple');
  const url=new URL(valid.destination.href,'https://example.test');
  check(url.pathname==='/nested/app/chat.php'&&url.searchParams.get('profile_analytics')===periodRef(),'canonical source keeps deployment base and exact ref');
  check(!url.searchParams.has('working_attention_binding')&&!url.searchParams.has('owner'),'credentials and owner identity absent from URL');
  check((await read('/system',query('system'))).destination?.chatAgentId===0,'system Agent is explicit zero scope');
  check((await read('/',query('8'))).destination===null,'wrong named Agent cannot inherit fallback Agent');
  check((await read('/system',query('7'))).destination===null,'retired or invalid named Agent cannot inherit system fallback');
  check((await read('/other-chat',query())).destination===null,'wrong requested Chat cannot inherit selected fallback Chat');
  check((await read('/denied',query())).destination===null,'private Profile access denial withholds destination');
  const havanaGap=periodRef(1,Math.floor(Date.parse('2026-03-08T05:00:00Z')/1000),'America/Havana','e');
  check((await read('/',query('7','11',havanaGap))).destination?.ref===havanaGap,'actual helper retains canonical first valid day start when midnight is skipped');
  for(const raw of [
    query()+'&agent=7',query()+'&%61gent=7',query()+'&conversation_id=11',query()+'&profile_analytics='+encodeURIComponent(periodRef()),
    query().replace('agent=7','agent[]=7'),query().replace('conversation_id=11','conversation.id=11'),
    query().replace('agent=7','%20agent=7'),query().replace('agent=7','agent=07'),query('0'),query('SYSTEM'),
    query('9007199254740992'),query('7','9007199254740992'),query('7','0'),
    query().replace('&conversation_id=11',''),query().replace('agent=7&',''),
    query('7','11',''),query('7','11',periodRef()+'='),query('7','11',periodRef(1,cutoff+1)),
    query('7','11',periodRef(1,cutoff,'America/New_York','u')),
    query('7','11',periodRef(1,Math.floor(Date.parse('2026-11-01T05:00:00Z')/1000),'America/Havana','e')),
    query()+'&marketing_plan=87',query()+'&profile_webmcp_return=token',
    query().replace('agent=7','agent=%ZZ'),query().replace('profile_analytics=','profile_analytics=%0A')
  ]){
    const result=await read('/',raw);
    check(result.requested===null&&result.destination===null,'malformed, repeated or competing destination rejected');
  }
  const again=await read('/',query());
  check(again.destination.ref===valid.destination.ref&&again.destination.href===valid.destination.href,'same-scope reload keeps canonical fixed destination');
  const source=readFileSync(join(root,'chat.php'),'utf8');
  check(source.includes('vp3_profile_analytics_destination_bind_v1(')&&source.indexOf('$profileAnalyticsOriginalQuery')<source.indexOf("require __DIR__ . '/chat-legacy-v108.php'"),'actual page calls the helper using pre-fallback query capture');
  console.log('PROFILE_ANALYTICS_ROUTE_HTTP=PASS ('+checks+' checks; production PHP request/binding helper)');
}catch(error){error.message+='\nPHP log:\n'+logs;throw error;}
finally{child.kill();await new Promise(resolve=>{if(child.exitCode!==null)resolve();else{child.once('exit',resolve);setTimeout(resolve,1000).unref();}});rmSync(temp,{recursive:true,force:true});}
