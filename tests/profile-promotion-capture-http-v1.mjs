import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn,spawnSync} from 'node:child_process';

const root=process.cwd(),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-promotion-capture-http-'));
const php=process.env.VP3_PHP_BINARY||'php';
const env={...process.env,VP3_PA3_ROOT:root,VP3_PA3_CONFIG:path.join(tmp,'config.json')};
fs.mkdirSync(path.join(tmp,'includes'));
for(const file of fs.readdirSync('includes'))if(file!=='bootstrap.php')fs.symlinkSync(path.join(root,'includes',file),path.join(tmp,'includes',file));
fs.copyFileSync('profile-promotion-click.php',path.join(tmp,'profile-promotion-click.php'));
fs.writeFileSync(path.join(tmp,'includes/bootstrap.php'),`<?php
define('STONEFELLOW_ROOT',dirname(__DIR__));
require getenv('VP3_PA3_ROOT').'/tests/fixtures/profile-promotion-capture-v1.php';
if(PHP_SAPI!=='cli')session_start();
if(PHP_SAPI!=='cli'&&str_starts_with($_SERVER['REQUEST_URI']??'','/profile-promotion-click.php')){
    $fault=is_file(STONEFELLOW_ROOT.'/fault')?trim(file_get_contents(STONEFELLOW_ROOT.'/fault')):'';
    $pdo->beforeSql=static function(string $sql)use($fault):void{
        if(preg_match('/^\\s*(?:CREATE|ALTER|DROP)\\b/i',$sql))throw new LogicException('A public link attempted schema migration.');
        if($fault==='schema'&&str_starts_with($sql,'SELECT public_conversation_id'))throw new PDOException('SECRET capture schema failure.');
    };
    $pdo->afterSql=static function(string $sql)use($fault):void{
        if(str_starts_with($sql,'INSERT INTO agent_promotion_clicks_v1')){
            file_put_contents(STONEFELLOW_ROOT.'/insert-log',"insert\\n",FILE_APPEND|LOCK_EX);
            if($fault==='after_insert')throw new PDOException('SECRET capture insert acknowledgement lost.');
        }
    };
}
`);
fs.writeFileSync(path.join(tmp,'fixture.php'),`<?php
require __DIR__.'/includes/bootstrap.php';
$command=$argv[1]??'';
if($command==='cleanup'){
    $cfg=$GLOBALS['pa3Config'];
    if(isset($cfg['database'])&&preg_match('/^vp3_profile_handoff_pa3_[a-f0-9]{12}$/D',$cfg['database'])){
        (new PDO($cfg['admin_dsn'],$cfg['user'],$cfg['password']))->exec('DROP DATABASE IF EXISTS '.chr(96).$cfg['database'].chr(96));
    }
    exit;
}
if($command==='init'){
    $f=pcap_fixture_init_v1();file_put_contents(__DIR__.'/seed.json',json_encode($f,JSON_THROW_ON_ERROR));
    echo json_encode(['driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)]+$f,JSON_THROW_ON_ERROR);exit;
}
$f=json_decode(file_get_contents(__DIR__.'/seed.json'),true,512,JSON_THROW_ON_ERROR);
if($command==='legacy')profile_promotion_attribution_record_click_v1($pdo,1,$f['publication_id'],'profile_agent',$argv[2]);
if($command==='rotate')$pdo->prepare('UPDATE agent_commerce_promotions_v1 SET public_key=? WHERE id=?')->execute([$argv[2],$f['promotion_id']]);
if($command==='token'){$pdo->beginTransaction();vp3_profile_agent_response_token_v1($pdo,(int)$argv[2],true);$pdo->commit();}
if($command==='retire'){
    $pdo->exec("UPDATE user_agents SET is_active=0 WHERE id=7");
    $pdo->exec("UPDATE user_profiles SET profile_agent_enabled=0,is_public=0,username='retired-owner' WHERE user_id=1");
    $pdo->exec("UPDATE users SET is_active=0,password_hash='changed' WHERE id=2");
}
if($command==='restore'){
    $pdo->exec("UPDATE user_agents SET is_active=1 WHERE id=7");
    $pdo->exec("UPDATE user_profiles SET profile_agent_enabled=1,is_public=1,username='fixture-owner' WHERE user_id=1");
    $pdo->exec("UPDATE users SET is_active=1,password_hash='visitor-a-password' WHERE id=2");
}
if($command==='old_schema'){
    $pdo->exec('ALTER TABLE agent_promotion_clicks_v1 DROP COLUMN public_conversation_id');
    $pdo->exec('ALTER TABLE agent_promotion_clicks_v1 DROP COLUMN public_conversation_binding_sha256_v1');
}
if($command==='upgrade')profile_promotion_capture_install_v1($pdo,'clicks');
if($command==='evidence'){echo json_encode(profile_promotion_public_click_evidence_v1($pdo,1,$f['publication_id'],$argv[2]),JSON_THROW_ON_ERROR);exit;}
echo json_encode(['observed'=>pcap_fixture_observed_v1(),'clicks'=>$pdo->query('SELECT * FROM agent_promotion_clicks_v1 ORDER BY click_id')->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR);
`);
fs.writeFileSync(path.join(tmp,'issue.php'),`<?php
require __DIR__.'/includes/bootstrap.php';
$f=json_decode(file_get_contents(__DIR__.'/seed.json'),true,512,JSON_THROW_ON_ERROR);
$mode=$_GET['mode']??'native';$native=null;
if($mode==='native'){
    $native=pcap_fixture_native_v1((int)($_GET['visitor']??2),(string)($_GET['browser']??'b'));
    foreach($native['cookies'] as $name=>$value)setcookie($name,$value,['path'=>'/','httponly'=>true,'samesite'=>'Lax']);
    $cid=$native['public_conversation_id'];$placement='profile_agent';
}elseif($mode==='owner'){pa3_login(1);$cid=0;$placement='profile';}
else{$cid=(int)($_GET['cid']??0);$placement=$mode==='profile'?'profile':'profile_agent';}
$url=profile_product_promotion_click_url_v1(['key'=>$f['publication_id']],$placement,$cid);
parse_str(parse_url($url,PHP_URL_QUERY),$query);$claim=profile_product_promotion_click_claim_v1($f['publication_id'],$query);
header('Content-Type: application/json');echo json_encode(['url'=>$url,'cid'=>$cid,'claim'=>$claim],JSON_THROW_ON_ERROR);
`);

function cli(command,...args){
  const result=spawnSync(php,[path.join(tmp,'fixture.php'),command,...args.map(String)],{env,encoding:'utf8'});
  assert.equal(result.status,0,result.stdout+'\n'+result.stderr);return result.stdout?JSON.parse(result.stdout):null;
}
const seed=cli('init');
const server=net.createServer();await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const port=server.address().port;await new Promise(resolve=>server.close(resolve));
const child=spawn(php,['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp],{env,stdio:['ignore','ignore','pipe']});
const base=`http://127.0.0.1:${port}`;let logs='',checks=0;child.stderr.on('data',chunk=>logs+=chunk);
const check=(condition,label)=>{assert.ok(condition,label);checks++;console.log('PASS '+label);};
const jar=()=>new Map();
async function request(route,cookies,{method='GET',headers={},drop=[],values={}}={}){
  const sent=new Map(cookies);for(const name of drop)sent.delete(name);for(const [name,value]of Object.entries(values))sent.set(name,value);
  const response=await fetch(base+route,{method,redirect:'manual',headers:{Cookie:[...sent].map(([k,v])=>k+'='+v).join('; '),...headers}});
  const sets=response.headers.getSetCookie();for(const entry of sets){const first=entry.split(';')[0],i=first.indexOf('=');cookies.set(first.slice(0,i),first.slice(i+1));}
  return {status:response.status,location:response.headers.get('location'),sets,text:await response.text()};
}
async function issue(cookies,query={}){const response=await request('/issue.php?'+new URLSearchParams(query),cookies);assert.equal(response.status,200,response.text);return JSON.parse(response.text);}
const state=()=>cli('state');
const insertCount=()=>fs.existsSync(path.join(tmp,'insert-log'))?fs.readFileSync(path.join(tmp,'insert-log'),'utf8').trim().split('\n').filter(Boolean).length:0;
try{
  let online=false;for(let n=0;n<60;n++){try{await fetch(base+'/missing');online=true;break;}catch{await new Promise(resolve=>setTimeout(resolve,50));}}
  assert.ok(online,logs);
  const browser=jar(),link=await issue(browser);const target='/fixture-owner/product/field-guide';
  const initial=state();
  for(const options of [{method:'HEAD'},{headers:{Purpose:'prefetch'}},{headers:{'Sec-Purpose':'prefetch;prerender'}},{headers:{'X-Moz':'prefetch'}}]){
    const r=await request(link.url,browser,options);
    check(r.status===303&&r.location===target&&state().observed.agent_promotion_clicks_v1.count===0,'HEAD and speculative link fetches preserve canonical navigation without recording a click');
  }
  check(JSON.stringify(state())===JSON.stringify(initial),'speculative fetches create no native session, conversation, notice, event, order, or checkout');
  const post=await request(link.url,browser,{method:'POST'});
  check(post.status===405&&JSON.stringify(state())===JSON.stringify(initial),'the actual click endpoint rejects unsupported methods without mutation');
  for(const options of [
    {drop:['sf_profile_visitor','sf_profile_conversation']},
    {drop:['sf_profile_visitor']},
    {drop:['sf_profile_conversation']},
    {values:{sf_profile_visitor:'not-a-cookie'}},
    {values:{sf_profile_conversation:'not-a-cookie'}},
    {values:{sf_profile_conversation:'c'.repeat(64)}},
    {values:{sf_profile_visitor:'d'.repeat(64)}},
  ]){
    const r=await request(link.url,browser,options);
    check(r.status===303&&r.location===target&&!r.sets.some(value=>/^sf_profile_(?:visitor|conversation)=/.test(value))&&JSON.stringify(state())===JSON.stringify(initial),
      'signed links without the exact existing native cookies create no replacement visitor context or click');
  }
  const bad=new URL(link.url,base);bad.searchParams.set('proof','0'.repeat(64));
  const invalid=await request(bad.pathname+bad.search,browser);
  check(invalid.status===303&&invalid.location===target&&JSON.stringify(state())===JSON.stringify(initial),'invalid signed link proof never captures attribution');
  const wrong=await issue(browser,{mode:'link',cid:12});const beforeWrong=state();
  const wrongResult=await request(wrong.url,browser);
  check(wrongResult.status===303&&wrongResult.location===target&&JSON.stringify(state())===JSON.stringify(beforeWrong),'the original private Chat ID cannot be used as a public conversation target');

  const accepted=await request(link.url,browser);const captured=state(),row=captured.clicks.find(value=>value.click_id===link.claim.click_id);
  check(accepted.status===303&&accepted.location===target+'?promotion_click='+link.claim.click_id,'a real accepted native click redirects with its exact opaque attribution ID');
  check(Number(row.public_conversation_id)===link.cid&&/^[a-f0-9]{64}$/.test(row.public_conversation_binding_sha256_v1),'the endpoint persists exact public conversation identity with its native binding digest');
  check(!accepted.text&&!/credential|session_key|password|binding_sha256/.test(accepted.location),'public navigation never serializes hidden authority evidence');
  const proof=cli('evidence',link.claim.click_id);
  check(proof.availability==='ready'&&proof.public_conversation_id===link.cid,'the captured row can be reread through the production historical evidence helper');
  await request(link.url,browser); // Model a response that the browser discarded.
  const retry=await request(link.url,browser);
  check(retry.location===accepted.location&&JSON.stringify(state())===JSON.stringify(captured),'lost-response retries recover the same accepted click without rewriting its binding');
  const otherBrowser=jar();const other=await issue(otherBrowser,{visitor:3,browser:'e'});
  const forged=await issue(otherBrowser,{mode:'link',cid:link.cid});const beforeOther=state();
  const denied=await request(forged.url,otherBrowser);
  check(other.cid!==link.cid&&denied.location===target&&JSON.stringify(state())===JSON.stringify(beforeOther),'a valid signed link in another member browser cannot reuse the captured native thread');
  const owner=jar(),ownerLink=await issue(owner,{mode:'owner'}),beforeOwner=state();
  check((await request(ownerLink.url,owner)).location===target&&JSON.stringify(state())===JSON.stringify(beforeOwner),'the owner’s own link navigation remains excluded from visit attribution');

  cli('token',link.cid);
  check(JSON.stringify(cli('evidence',link.claim.click_id))===JSON.stringify(proof),'mutable response-token rotation preserves the exact saved source revision');
  cli('retire');
  check(JSON.stringify(cli('evidence',link.claim.click_id))===JSON.stringify(proof),'retired public access and expired member authentication do not erase valid historical click evidence');
  cli('restore');cli('rotate','d'.repeat(32));
  check((await request(link.url,browser)).status===404&&JSON.stringify(cli('evidence',link.claim.click_id))===JSON.stringify(proof),'rotated publication links stop accepting clicks while historical exact publication evidence remains readable');
  cli('rotate',seed.publication_id);

  const guest=jar(),guestLink=await issue(guest,{visitor:0,browser:'f'});const beforeFault=state();
  fs.writeFileSync(path.join(tmp,'fault'),'after_insert');const writes=insertCount();
  const failed=await request(guestLink.url,guest);fs.rmSync(path.join(tmp,'fault'));
  check(failed.status===303&&failed.location===target&&!failed.text.includes('SECRET')&&insertCount()===writes+1&&JSON.stringify(state())===JSON.stringify(beforeFault),
    'failure after the real endpoint INSERT rolls back once without a legacy fallback write or leaked diagnostic');
  const guestAccepted=await request(guestLink.url,guest);
  check(guestAccepted.location===target+'?promotion_click='+guestLink.claim.click_id&&cli('evidence',guestLink.claim.click_id).availability==='ready','a later guest retry captures its canonical anonymous native binding once');
  const legacyBrowser=jar(),legacyLink=await issue(legacyBrowser,{visitor:2,browser:'1'});cli('legacy',legacyLink.claim.click_id);
  const legacyBefore=state().clicks.find(value=>value.click_id===legacyLink.claim.click_id);
  await request(legacyLink.url,legacyBrowser);
  check(legacyBefore.public_conversation_id===null&&legacyBefore.public_conversation_binding_sha256_v1===null&&JSON.stringify(state().clicks.find(value=>value.click_id===legacyLink.claim.click_id))===JSON.stringify(legacyBefore),
    'a real HTTP replay of a legacy click never backfills a current public conversation');
  const profileBrowser=jar(),profileLink=await issue(profileBrowser,{mode:'profile',cid:0});
  const plain=await request(profileLink.url,profileBrowser);const plainRow=state().clicks.find(value=>value.click_id===profileLink.claim.click_id);
  check(plain.location===target+'?promotion_click='+profileLink.claim.click_id&&plainRow.public_conversation_id===null&&cli('evidence',profileLink.claim.click_id).availability==='ready',
    'a Profile-page click remains valid unthreaded placement evidence without native-cookie creation');

  const pendingBrowser=jar(),pending=await issue(pendingBrowser,{visitor:2,browser:'2'}),beforeSchema=state();
  fs.writeFileSync(path.join(tmp,'fault'),'schema');const schemaFailed=await request(pending.url,pendingBrowser);fs.rmSync(path.join(tmp,'fault'));
  check(schemaFailed.location===target&&JSON.stringify(state())===JSON.stringify(beforeSchema),'an operational schema failure cannot silently downgrade an accepted native click');
  cli('old_schema');const old=await request(pending.url,pendingBrowser);
  check(old.location===target+'?promotion_click='+pending.claim.click_id&&!('public_conversation_id'in state().clicks.find(value=>value.click_id===pending.claim.click_id)),
    'definitely missing optional columns preserve real link navigation with the old one-shot writer and no DDL');
  cli('upgrade');const afterUpgrade=state().clicks.find(value=>value.click_id===pending.claim.click_id);await request(pending.url,pendingBrowser);
  check(afterUpgrade.public_conversation_id===null&&afterUpgrade.public_conversation_binding_sha256_v1===null&&JSON.stringify(state().clicks.find(value=>value.click_id===pending.claim.click_id))===JSON.stringify(afterUpgrade),
    'explicit upgrade followed by HTTP replay leaves original unknown association evidence NULL');
  check(!/PHP (?:Warning|Fatal error)|Uncaught/.test(logs),'the real endpoint completes without PHP warnings or uncaught errors');
  console.log(`PROFILE_PROMOTION_CAPTURE_HTTP_V1=PASS driver=${seed.driver} checks=${checks}`);
}catch(error){console.error(logs.slice(-4500));throw error;}finally{
  if(child.exitCode===null&&child.signalCode===null){const stopped=new Promise(resolve=>child.once('exit',resolve));child.kill();await stopped;}
  cli('cleanup');fs.rmSync(tmp,{recursive:true,force:true});
}
