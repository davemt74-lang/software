import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';
const root=new URL('../',import.meta.url);
const assets=Object.fromEntries(await Promise.all(['chat.css','account.css','ai-usage-v1.css'].map(async name=>[name,await fs.readFile(new URL(name,root),'utf8')])));
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const origin='https://usage.test';
try{
  const context=await browser.newContext();
  await context.route(origin+'/**',async route=>{
    const url=new URL(route.request().url());
    if(assets[url.pathname.slice(1)])return route.fulfill({contentType:'text/css',body:assets[url.pathname.slice(1)]});
    if(url.pathname==='/chat.js')return route.fulfill({contentType:'text/javascript',body:''});
    if(url.pathname==='/ai-usage.php')return route.fulfill({contentType:'text/html; charset=utf-8',body:execFileSync('php',[new URL('fixtures/ai-usage-page-v1.php',import.meta.url).pathname,url.search.slice(1)],{encoding:'utf8'})});
    return route.fulfill({status:404,body:'Fixture not found'});
  });
  const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
  for(const viewport of [{width:1440,height:1000},{width:390,height:844}]){
    await page.setViewportSize(viewport);await page.goto(origin+'/ai-usage.php');
    assert.equal(await page.locator('.usage-stats .usage-stat').count(),4);
    assert.equal(await page.locator('.usage-stat').nth(0).locator('strong').textContent(),'33');
    assert.equal(await page.locator('.usage-stat').nth(1).locator('strong').textContent(),'3,875');
    assert.equal(await page.locator('.usage-stat').nth(2).locator('strong').textContent(),'0','Admin exemption must not erase reported provider tokens or fabricate plan debits');
    assert.match(await page.locator('.usage-stat').nth(3).textContent(),/\$0\.0004 \+ unknown/);
    assert.equal(await page.locator('.usage-card').last().locator('tbody tr').count(),25,'History must stay bounded');
    assert.equal(await page.getByText('private-foreign-model',{exact:true}).count(),0,'Member usage cannot reveal another owner');
    assert.ok(await page.getByText('Unpriced',{exact:true}).count()>0);
    assert.ok(await page.getByText('Usage unreported',{exact:true}).count()>0);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Horizontal table scrolling must remain inside each card');
    for(const stat of await page.locator('.usage-stat').all()){const box=await stat.boundingBox();assert.ok(box.x>=0&&box.x+box.width<=viewport.width,'Every metric remains inside the visible viewport');}
    if(process.env.VP3_USAGE_SCREENSHOTS)await page.screenshot({path:'/workspace/scratch/48e2f20316b2/usage-'+viewport.width+'.png'});
    await page.getByRole('link',{name:'Next',exact:true}).click();
    assert.match(page.url(),/page=2/);assert.equal(await page.locator('.usage-card').last().locator('tbody tr').count(),8);
    await page.getByRole('combobox',{name:/Show/}).selectOption('all');await page.getByRole('button',{name:'Apply',exact:true}).click();
    assert.equal(await page.locator('.usage-stat').nth(0).locator('strong').textContent(),'73');
    assert.equal(await page.getByRole('link',{name:'Previous',exact:true}).count(),0,'Changing filters resets pagination');
    await page.getByRole('combobox',{name:/Period/}).selectOption('all');await page.getByRole('button',{name:'Apply',exact:true}).click();
    assert.equal(await page.getByRole('combobox',{name:/Period/}).inputValue(),'all');
  }
  assert.deepEqual(errors,[]);console.log('AI usage page PHP render, admin usage, filters, owner scope, pagination and mobile layout passed.');
}finally{await browser.close();}
