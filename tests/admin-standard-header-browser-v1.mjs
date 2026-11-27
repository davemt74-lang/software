import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(new URL('..',import.meta.url).pathname),temp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-admin-header-'));
for(const dir of ['admin','includes'])fs.mkdirSync(path.join(temp,dir));
for(const file of ['_header.php','admin-header-standard-v1.css'])fs.copyFileSync(path.join(root,'admin',file),path.join(temp,'admin',file));
fs.writeFileSync(path.join(temp,'includes/bootstrap.php'),`<?php
function require_login(){}function current_user(){return ['id'=>1,'display_name'=>'David Evans','email'=>'david@example.test','roles'=>['admin']];}
function user_role_labels($u){return ['Admin'];}function flash($k){return null;}function notification_unread_count($u){return 0;}function notification_recent($u,$n){return [];}
function db(){static $p;return $p??=new PDO('sqlite::memory:');}
function has_permission($p,$u=null){return !getenv('VP3_HEADER_RESTRICTED')||in_array($p,['admin.access','account.access'],true);}
function permission_v105_has($p){return false;}function user_has_role($r,$u){return in_array($r,$u['roles']);}
function user_avatar_url($u){return '';}function user_initials($u){return 'DE';}function url($p){return $p;}function e($v){return htmlspecialchars((string)$v,ENT_QUOTES);}
function vp3_phone_workspace_available_v1($p,$u){return !getenv('VP3_HEADER_RESTRICTED');}
require ${JSON.stringify(root+'/includes/phone-workspace-ui-v1.php')};
`);
function render(canvas,restricted){
 return execFileSync(process.env.PHP_BINARY||'php',['-r',`$adminTitle=${JSON.stringify(canvas?'Music Studio':'Runtime status')};$adminCanvasMode=${canvas?'true':'false'};require ${JSON.stringify(temp+'/admin/_header.php')};`],{encoding:'utf8',env:{...process.env,VP3_HEADER_RESTRICTED:restricted?'1':''}})
  +(canvas?'<div class="daw-canvas-header">Project controls</div><div class="daw-studio"><section style="min-width:0;overflow:hidden">Studio workspace</section></div>':'<section><h2>Admin workspace</h2><p>Page content</p></section>')
  +'</main></div><script src="/admin/admin.js"></script></body></html>';
}
const server=http.createServer((req,res)=>{
 const u=new URL(req.url,'http://fixture');
 if(u.pathname==='/api/member-notifications.php'){res.setHeader('Content-Type','application/json');return res.end('{"ok":true,"unread":0,"items":[]}');}
 if(u.pathname.endsWith('.css')||u.pathname.endsWith('.js')){const file=path.join(root,u.pathname);res.setHeader('Content-Type',u.pathname.endsWith('.css')?'text/css':'text/javascript');return res.end(fs.readFileSync(file));}
 res.setHeader('Content-Type','text/html');res.end(render(u.searchParams.has('canvas'),u.searchParams.has('restricted')));
});await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
try{
 for(const width of [1280,390,320])for(const canvas of [false,true]){
  const page=await browser.newPage({viewport:{width,height:800}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://127.0.0.1:'+server.address().port+'/admin/review.php'+(canvas?'?canvas=1':''));
  assert.equal(await page.locator('[data-admin-standard-header]').count(),1);assert.equal(await page.locator('.admin-mobile-bar').count(),0);
  const phone=page.locator('[data-admin-standard-header] [data-phone-workspace-toggle]');await phone.waitFor();
  const header=await page.locator('[data-admin-standard-header]').boundingBox();assert.ok(header.x>=-1&&header.x+header.width<=width+1);
  await page.locator('#adminUserMenuButton').click();const menu=page.locator('#adminUserDropdown');await menu.waitFor();
  assert.deepEqual(await menu.locator('a').allTextContents(),['Account settings','Admin dashboard','Users and access','AI and API settings','Sign out']);
  for(const link of await menu.locator('a').all()){const box=await link.boundingBox();assert.ok(box.height>=42);assert.ok(box.x>=0&&box.x+box.width<=width+1,JSON.stringify({width,canvas,box}));}
  assert.equal(await menu.getByRole('group',{name:'Administration',exact:true}).count(),1);
  await page.keyboard.press('Escape');await menu.waitFor({state:'hidden'});
  if(canvas||width<=900){await page.locator('#adminMenuToggle').click();await page.waitForFunction(()=>document.body.classList.contains('admin-sidebar-open'));assert.equal(await page.locator('#adminSidebar').isVisible(),true);await page.locator('#adminSidebarClose').click();assert.equal(await page.locator('#adminMenuToggle').getAttribute('aria-expanded'),'false');await page.waitForFunction(()=>document.getElementById('adminSidebar').getBoundingClientRect().right<=1);}
  const geometry=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth,height:innerHeight,scrollHeight:document.documentElement.scrollHeight,
   studio:document.querySelector('.daw-studio')?.getBoundingClientRect().toJSON(),header:document.querySelector('[data-admin-standard-header]').getBoundingClientRect().toJSON()}));
  assert.ok(geometry.scrollWidth<=width+1,JSON.stringify(geometry));
  if(canvas){assert.ok(geometry.studio.y>=geometry.header.bottom&&geometry.studio.bottom<=800+1&&geometry.studio.height>600,JSON.stringify(geometry));assert.ok(geometry.scrollHeight<=800+1,JSON.stringify(geometry));}
  if(process.env.VP3_SHELL_SCREENSHOT_DIR){fs.mkdirSync(process.env.VP3_SHELL_SCREENSHOT_DIR,{recursive:true});await page.locator('#adminUserMenuButton').click();await page.screenshot({path:path.join(process.env.VP3_SHELL_SCREENSHOT_DIR,`admin-header-${canvas?'studio':'page'}-${width}.png`)});}
  assert.deepEqual(errors,[]);await page.close();console.log('PASS shared Admin header, grouped user menu and navigation at '+width+'px'+(canvas?' in fullscreen Studio':''));
 }
 const page=await browser.newPage();await page.goto('http://127.0.0.1:'+server.address().port+'/admin/review.php?restricted=1');await page.locator('#adminUserMenuButton').click();
 assert.deepEqual(await page.locator('#adminUserDropdown a').allTextContents(),['Account settings','Admin dashboard','Sign out']);assert.equal(await page.locator('[data-phone-workspace-toggle]').count(),0);
 const runtime=fs.readFileSync(path.join(root,'admin/runtime-status.php'),'utf8');assert.ok(runtime.includes("require __DIR__.'/_header.php'"));assert.ok(runtime.includes("require __DIR__.'/_footer.php'"));
 console.log('ADMIN_STANDARD_HEADER_BROWSER_V1=PASS (7 cases)');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));fs.rmSync(temp,{recursive:true,force:true});}
