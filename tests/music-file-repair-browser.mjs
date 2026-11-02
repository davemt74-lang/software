import assert from 'node:assert/strict';
import {chromium} from 'playwright';
import {fixture} from './music-file-repair-fixture.mjs';
const f=await fixture();const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
const page=await browser.newPage({viewport:{width:390,height:844},extraHTTPHeaders:{'X-User':'11'}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
try{
 await page.goto(f.base+'/music-file-repair.php?workspace=1');
 await page.getByRole('heading',{name:'Existing song',exact:true}).waitFor();assert.equal(await page.getByRole('heading',{name:'Legacy song',exact:true}).count(),0,'Available song appeared under Missing files');
 const row=page.locator('article').filter({has:page.getByRole('heading',{name:'Existing song',exact:true})});assert.match(await row.innerText(),/Listening master: Missing or unreadable/);await row.getByRole('link',{name:'Replace audio',exact:true}).click();
 await page.getByRole('heading',{name:'Replace audio: Existing song',exact:true}).waitFor();
 assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Mobile replacement page overflowed');
 await page.getByLabel('Replacement listening audio').setInputFiles({name:'master.wav',mimeType:'audio/wav',buffer:f.wav});await page.getByRole('button',{name:'Save replacement audio',exact:true}).click();await page.waitForURL(f.base+'/music-file-repair.php?workspace=1');
 assert.match(await page.getByRole('status').innerText(),/Replacement audio saved/);assert.match(await page.locator('article').filter({has:page.getByRole('heading',{name:'Existing song',exact:true})}).innerText(),/Listening master: Available/);
 await page.getByRole('link',{name:/All checked songs/}).click();const legacy=page.locator('article').filter({has:page.getByRole('heading',{name:'Legacy song',exact:true})});await legacy.getByRole('link',{name:'Add REAPER / stems',exact:true}).click();
 await page.getByRole('heading',{name:'Legacy song',exact:true}).waitFor();assert.ok(page.url().includes('source_track=2'),'Legacy import handoff lost its source identity');assert.equal(await page.locator('#stemZipInput').count(),1,'Legacy import did not render the existing uploader');
 await page.goto(f.base+'/music-file-repair.php?workspace=1&kind=catalog&id=10');await page.getByLabel('Replacement listening audio').setInputFiles({name:'fake.wav',mimeType:'audio/wav',buffer:Buffer.from('<html>not audio</html>')});await page.getByRole('button',{name:'Save replacement audio',exact:true}).click();await page.waitForURL(f.base+'/music-file-repair.php?workspace=1');assert.match(await page.getByRole('alert').innerText(),/not reach readable audio|not recognized/);
 f.run(`db()->exec("UPDATE artist_catalog_tracks_v181 SET title='Song <img src=x>' WHERE id=10");`);await page.reload();assert.equal(await page.getByRole('heading',{name:'Song <img src=x>',exact:true}).count(),1);assert.equal(await page.locator('article img').count(),0,'Song title became markup');
 assert.deepEqual(errors,[]);console.log('MUSIC_FILE_REPAIR_BROWSER=PASS actual PHP mobile form, native upload and status, retained missing project files, legacy RPP handoff, invalid audio and escaped metadata');
}finally{await browser.close();f.close();}
