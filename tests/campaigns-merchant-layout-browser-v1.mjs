import assert from 'node:assert/strict';
import fs from 'node:fs';
import { chromium } from 'playwright';

const pageMarkup=fs.readFileSync('campaigns.php','utf8');
assert.match(pageMarkup,/<main class="chat-main cr-main">/);
assert.match(pageMarkup,/<div class="cr-wrap" role="region" aria-label="Campaigns content" tabindex="0">/);
assert.match(pageMarkup,/id="new-merchant"/);
const origins='https://merchant-layout.vp3.test';
const nav=Array.from({length:28},(_,i)=>'<a class="chat-sidebar-nav-link" href="#menu-'+i+'"><span>◇</span><strong>Menu item '+(i+1)+'</strong></a>').join('');
const fields=Array.from({length:38},(_,i)=>'<label>Merchant detail '+i+'<input name="detail_'+i+'" placeholder="Field '+i+'"></label>').join('');
const markup='<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="cr-page"><div class="chat-app">'+
 '<aside class="chat-sidebar workspace-main-sidebar" id="chatSidebar" data-shell-active="campaigns"><div class="chat-sidebar-top"><a class="chat-brand" href="/">VP3</a><button class="chat-icon-button mobile-only" id="closeChatSidebar" type="button">Close</button></div>'+
 '<div class="chat-sidebar-sections"><section class="chat-sidebar-nav-section"><nav class="chat-sidebar-nav agent-primary-nav">'+nav+'</nav></section></div>'+
 '<footer class="agent-sidebar-footer"><button class="agent-sidebar-user-button" type="button">Owner menu</button></footer></aside>'+
 '<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>'+
 '<main class="chat-main cr-main"><header class="chat-topbar"><button id="openChatSidebar" type="button" class="chat-icon-button mobile-only">Menu</button><strong>Campaigns</strong></header>'+
 '<div class="cr-wrap" role="region" aria-label="Campaigns content" tabindex="0"><section class="cr-toolbar"><strong>Merchant workspace</strong></section><section class="cr-empty" id="new-merchant"><h2>Create your first Merchant</h2><p>Business identity stays separate from your login.</p><form class="cr-form">'+fields+'<button type="submit" class="cr-btn primary" id="merchantSubmit">Create Merchant</button></form></section></div></main></div></body></html>';
fs.mkdirSync('artifacts/campaigns-merchant-layout',{recursive:true});
const browser=await chromium.launch({headless:true});
try{
  for(const viewport of [{width:1365,height:820},{width:900,height:700},{width:810,height:700},{width:390,height:740},{width:320,height:620}]){
    const page=await browser.newPage({viewport});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.route(origins+'/**',route=>route.fulfill({status:200,contentType:'text/html',body:markup}));
    await page.goto(origins+'/campaigns.php?new_merchant=1');
    for(const file of ['chat.css','campaigns-v100.css','agent-ui-v034.css','member-page-scroll.css'])await page.addStyleTag({path:file});
    await page.addScriptTag({path:'member-shell-v77.js'});
    const measure=()=>page.evaluate(()=>{
      const rect=sel=>{const el=document.querySelector(sel);if(!el)throw new Error('Missing '+sel);const r=el.getBoundingClientRect();
        return {top:r.top,bottom:r.bottom,left:r.left,right:r.right,clientHeight:el.clientHeight,scrollHeight:el.scrollHeight,scrollTop:el.scrollTop,overflow:getComputedStyle(el).overflowY};};
      return {body:document.documentElement.scrollWidth,viewport:window.innerWidth,scrollY:window.scrollY,topbar:rect('.chat-topbar'),
        main:rect('.cr-main'),content:rect('.cr-wrap'),sidebar:rect('.workspace-main-sidebar'),
        navigation:rect('.workspace-main-sidebar .chat-sidebar-sections'),footer:rect('.agent-sidebar-footer')};
    });
    let state=await measure();
    assert(state.body<=state.viewport+1,'no horizontal overflow at '+viewport.width);
    assert(state.content.scrollHeight>state.content.clientHeight,'merchant form is longer than viewport at '+viewport.width);
    assert(state.content.overflow==='auto','merchant form owns vertical scrolling at '+viewport.width);
    assert(state.navigation.scrollHeight>state.navigation.clientHeight,'sidebar navigation can scroll at '+viewport.width);
    const firstHeaderTop=state.topbar.top;
    await page.locator('.cr-wrap').focus();
    assert.equal(await page.evaluate(()=>document.activeElement?.getAttribute('aria-label')),'Campaigns content',
      'keyboard focus can enter scrollable Campaigns content at '+viewport.width);
    await page.locator('.cr-wrap').evaluate(el=>{el.scrollTop=el.scrollHeight;});
    state=await measure();
    assert(state.content.scrollTop>0,'merchant content scrolls to the form footer at '+viewport.width);
    assert(Math.abs(state.topbar.top-firstHeaderTop)<=1&&state.scrollY===0,'global shell/header remains fixed at '+viewport.width);
    const submit=await page.locator('#merchantSubmit').boundingBox();
    assert(submit&&submit.y>=state.content.top-1&&submit.y+submit.height<=state.content.bottom+1,'Create Merchant button reachable at '+viewport.width);
    if(viewport.width>820){
      assert(state.sidebar.left>=-1&&state.sidebar.right<=viewport.width,'desktop sidebar stays visible');
      await page.locator('.chat-sidebar-sections').evaluate(el=>{el.scrollTop=el.scrollHeight;});
      const bottom=await measure();
      assert(bottom.navigation.scrollTop>0,'sidebar navigation scrolls independently on desktop');
      assert(bottom.footer.bottom<=viewport.height+1&&bottom.footer.top>bottom.navigation.top,'user footer remains pinned at '+viewport.width);
    }else{
      await page.locator('#openChatSidebar').click();
      await page.waitForTimeout(320);
      assert(await page.evaluate(()=>document.body.classList.contains('chat-nav-open')),'mobile navigation opens at '+viewport.width);
      const open=await measure();
      assert(open.sidebar.left>=-2&&open.sidebar.right<=viewport.width+1,'mobile sidebar fits inside viewport');
      await page.locator('.chat-sidebar-sections').evaluate(el=>{el.scrollTop=el.scrollHeight;});
      const bottom=await measure();
      assert(bottom.navigation.scrollTop>0,'mobile sidebar navigation scrolls');
      assert(bottom.footer.top>=0&&bottom.footer.bottom<=viewport.height+1,'mobile owner footer remains visible');
      await page.locator('#closeChatSidebar').click();
      assert(!await page.evaluate(()=>document.body.classList.contains('chat-nav-open')),'mobile navigation closes');
    }
    assert.deepEqual(errors,[],'no shell JavaScript errors at '+viewport.width);
    await page.screenshot({path:'artifacts/campaigns-merchant-layout/merchant-'+viewport.width+'.png'});
    console.log('PASS create merchant content scroll, sidebar, footer, and responsive geometry '+viewport.width+'px');
    await page.close();
  }
}finally{await browser.close();}
