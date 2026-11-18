import assert from 'node:assert/strict';
import {readFile, mkdir} from 'node:fs/promises';
import {chromium} from 'playwright';

const origin='https://profile-image-settings.test';
const endpoint='/api/profile-image-settings-v1.php';
const assets=[101,102,103,104].map((id,index)=>({asset_id:id,title:index===0?'Portrait <img src=x onerror="window.imageSettingsAttack=true">':`Style image ${index+1}`,mime_type:'image/png',available:true,url:`/media-library-file-v86.php?id=${id}`}));
let settings={enabled:false,daily_limit:10,visitor_daily_limit:3,revision:0};
const catalogs=[{id:13,name:'Watercolor collection',count:4,approved:false,revision:0,style_instructions:'',default_price_cents:0,references:[]},{id:14,name:'Studio collection',count:0,approved:false,revision:0,style_instructions:'',default_price_cents:0,references:[]}];
const posts=[],headers=[],errors=[];
let deny=false,conflict=false,checks=0,collectionGate=null;
const defer=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return {promise,resolve};};
const check=(value,label)=>{assert.ok(value,label);checks++;};
const payload=id=>({ok:true,settings:{...settings},collections:structuredClone(catalogs),selected_collection_id:id,assets:id===13?assets:[],assets_truncated:false,
  history:{total_completed:1,records:[{request_id:'a'.repeat(32),catalog_name:'Watercolor collection',source_count:1,status:'completed',created_at:'2026-11-18 14:20:00',conversation_id:77,input_message_id:80,result_message_id:81,input_url:'/api/profile-agent.php?action=owner_image&conversation_id=77&message_id=80',output_url:'/api/profile-agent.php?action=owner_image&conversation_id=77&message_id=81'}]}});
const scripts=await Promise.all(['profile-image-settings-v1.js','profile-image-settings-v1.css','profile-agent-portal.css'].map(file=>readFile(file,'utf8')));
const html=`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/profile-agent-portal.css"><link rel="stylesheet" href="/profile-image-settings-v1.css"><style>body{margin:0;padding:20px;background:#f7f8fa;font-family:Arial,sans-serif}main{max-width:940px;margin:auto}*{box-sizing:border-box}</style></head><body><main><div id="profileImageSettings"></div></main><script>window.PROFILE_AGENT_PORTAL={imageSettingsEndpoint:'${endpoint}',ownerUserId:41,csrf:'owner-fixture',mediaLibraryUrl:'/media-library.php'};</script><script src="/profile-image-settings-v1.js"></script></body></html>`;
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
try{
  const page=await browser.newPage({viewport:{width:1200,height:900}});page.setDefaultTimeout(10000);
  page.on('pageerror',error=>errors.push(error.message));
  await page.route(origin+'/**',async route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.pathname===endpoint){
      headers.push(request.headers()['x-vp3-media-owner']);
      if(deny)return route.fulfill({status:403,json:{ok:false,error:'The account changed.'}});
      const id=Number(url.searchParams.get('collection_id')||0);
      if(request.method()==='GET'&&id===14&&collectionGate){const gate=collectionGate;collectionGate=null;gate.started.resolve();await gate.release.promise;}
      if(request.method()==='POST'){
        const body=request.postDataJSON();posts.push(body);
        if(conflict){conflict=false;return route.fulfill({status:409,json:{ok:false,error:'These settings changed. Reload and review them before saving.'}});}
        if(body.action==='save_settings')settings={...body.settings,revision:settings.revision+1};
        if(body.action==='save_catalog'){
          const catalog=catalogs.find(item=>item.id===body.collection_id);
          Object.assign(catalog,{approved:body.approved,style_instructions:body.style_instructions,default_price_cents:body.default_price_cents,revision:catalog.revision+1,
            references:body.references.map(ref=>({...assets.find(item=>item.asset_id===ref.asset_id),...ref,effective_price_cents:ref.price_override_cents??body.default_price_cents}))});
        }
      }
      return route.fulfill({json:payload(id)});
    }
    const index=['/profile-image-settings-v1.js','/profile-image-settings-v1.css','/profile-agent-portal.css'].indexOf(url.pathname);
    if(index>=0)return route.fulfill({contentType:index===0?'text/javascript':'text/css',body:scripts[index]});
    if(url.pathname==='/media-library-file-v86.php')return route.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRz0AAAAASUVORK5CYII=','base64')});
    return route.fulfill({contentType:'text/html',body:html});
  });
  await page.goto(origin+'/profile-agent.php?tab=agent#profileImageUseHistory');
  await page.getByRole('button',{name:'Save photo editing',exact:true}).waitFor();
  await page.waitForFunction(()=>document.activeElement===document.querySelector('#profileImageUseHistory h3'));
  check(await page.locator('#profileImageUseHistory h3').evaluate(heading=>{
    const rect=heading.getBoundingClientRect();return heading.textContent==='Recent photo edits'&&heading.tabIndex===-1&&rect.top>=0&&rect.bottom<=innerHeight;
  }),'initial saved-creations history link lands on its visible heading with keyboard focus after loading');
  check(!await page.getByRole('checkbox',{name:'Offer free photo edits in Profile chat'}).isChecked(),'service starts disabled');
  check(posts.length===0,'landing on and reading saved history never save settings or start a generation');
  await page.getByRole('checkbox',{name:'Offer free photo edits in Profile chat'}).check();
  await page.getByLabel('Edits per day across your profile').fill('8');
  await page.getByRole('button',{name:'Save photo editing',exact:true}).click();
  await page.getByText('Photo editing settings saved.',{exact:true}).waitFor();
  check(posts[0].csrf_token==='owner-fixture'&&posts[0].expected_revision===0&&posts[0].settings.daily_limit===8,'settings save binds CSRF and rendered revision');
  await page.getByLabel('Collection',{exact:true}).selectOption('13');
  await page.locator('[data-style-asset="101"]').waitFor();
  check(await page.evaluate(()=>window.imageSettingsAttack===undefined),'catalog titles render as text, without active HTML');
  check(posts.length===1,'selecting a style catalog does not save or process images');
  await page.getByRole('checkbox',{name:'Make this catalog available for free photo edits'}).check();
  await page.getByLabel('Style instructions',{exact:true}).fill('Soft watercolor edges; preserve the face.');
  await page.getByLabel('Default image price',{exact:true}).fill('2.50');
  const first=page.locator('[data-style-asset="101"]');
  await first.locator('[data-style-approved]').check();
  await first.locator('[data-style-price]').fill('0.00');
  await page.getByRole('button',{name:'Save reference catalog',exact:true}).click();
  await page.getByText('Reference catalog saved.',{exact:true}).waitFor();
  check(posts[1].default_price_cents===250&&posts[1].references.length===1&&posts[1].references[0].price_override_cents===0,'explicit zero overrides a nonzero default without a payment request');
  await page.getByLabel('Style instructions',{exact:true}).fill('Unsaved creative direction');
  const gate={started:defer(),release:defer()};collectionGate=gate;
  await page.getByLabel('Collection',{exact:true}).selectOption('14');
  await gate.started.promise;
  await page.getByLabel('Style instructions',{exact:true}).fill('Creative direction typed while the next catalog loads');
  gate.release.resolve();
  await page.getByText('Studio collection',{exact:true}).last().waitFor();
  await page.waitForFunction(()=>!document.querySelector('[data-image-catalog-select]').disabled);
  await page.getByLabel('Collection',{exact:true}).selectOption('13');
  await page.locator('[data-style-asset="101"]').waitFor();
  check(await page.getByLabel('Style instructions',{exact:true}).inputValue()==='Creative direction typed while the next catalog loads','switching collections preserves edits made while the next request is pending');
  for(const id of [102,103])await page.locator(`[data-style-asset="${id}"] [data-style-approved]`).check();
  await page.locator('[data-style-asset="104"] [data-style-approved]').click();
  check(await page.locator('[data-style-approved]:checked').count()===3,'fourth exemplar is not selected');
  await page.getByLabel('Default image price',{exact:true}).fill('2.345');
  await page.getByRole('button',{name:'Save reference catalog',exact:true}).click();
  await page.getByText('Enter a price with at most two decimal places, such as 0.00.',{exact:true}).waitFor();
  check(posts.length===2,'ambiguous fractional price cannot be sent');
  await page.getByLabel('Default image price',{exact:true}).fill('0.00');
  conflict=true;
  await page.getByRole('button',{name:'Save reference catalog',exact:true}).click();
  await page.getByText('These settings changed. Reload and review them before saving.',{exact:true}).waitFor();
  check(await page.getByLabel('Style instructions',{exact:true}).inputValue()==='Creative direction typed while the next catalog loads','conflict preserves the draft for review');
  check(posts[2].expected_revision===1,'catalog save uses the rendered version');
  await page.getByRole('button',{name:'Refresh image activity',exact:true}).click();
  await page.getByText('Image activity refreshed.',{exact:true}).waitFor();
  check(await page.getByLabel('Style instructions',{exact:true}).inputValue()==='Creative direction typed while the next catalog loads','activity refresh cannot replace a creative draft');
  check(await page.getByRole('link',{name:'Open conversation',exact:true}).getAttribute('href')===origin+'/profile-agent.php?conversation=77','tracked use links to its original conversation');
  await mkdir('artifacts/profile-image-settings',{recursive:true});
  await page.screenshot({path:'artifacts/profile-image-settings/desktop.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'owner controls fit a mobile viewport');
  check(await page.locator('#profileImageSettings').evaluate(root=>[...root.querySelectorAll('*')].filter(node=>node.tagName!=='TEXTAREA'&&['auto','scroll'].includes(getComputedStyle(node).overflowY)&&node.scrollHeight>node.clientHeight+1).length===0),'owner image controls do not add a nested vertical scroller');
  await page.screenshot({path:'artifacts/profile-image-settings/mobile.png',fullPage:true});
  deny=true;await page.getByRole('button',{name:'Refresh image activity',exact:true}).click();
  await page.getByText('Your account or access changed. Reload Profile settings to continue.',{exact:true}).waitFor();
  check(await page.locator('[data-style-asset]').count()===0,'account change withdraws private reference images');
  check(headers.length>0&&headers.every(value=>value==='41'),'every request binds the page owner');
  check(errors.length===0,'owner image settings have no browser errors: '+errors.join('; '));
  console.log(`Profile image settings browser: ${checks} checks passed.`);
}finally{await browser.close();}
