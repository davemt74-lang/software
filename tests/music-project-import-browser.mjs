import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';
const read=p=>fs.readFile(new URL('../'+p,import.meta.url),'utf8');
const zip=(names=['session.rpp','Bass.wav'])=>execFileSync('python3',['-c',String.raw`import io,zipfile,sys,json
b=io.BytesIO()
with zipfile.ZipFile(b,'w') as z:
 for n in json.loads(sys.argv[1]):z.writestr(n,'<REAPER_PROJECT 0.1 7\nTEMPO 86\n  <TRACK\n    NAME "Bass"\n    <ITEM\n      <SOURCE WAVE\n        FILE "Bass.wav"\n      >\n    >\n  >\n>\n' if n.endswith('.rpp') else b'RIFF'+bytes(100))
sys.stdout.buffer.write(b.getvalue())`,JSON.stringify(names)]);
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
const calls=[];let failPlace=false,parsedMeta=null,savedReview=null;
const review={track:{title:'Song <img>',description:'A production project',genre:'Reggae'},project:{project_name:'Session',tempo_bpm:86,time_signature:'4/4'},stems:[{file_name:'Bass.wav',stem_name:'Bass',stem_role:'Bass',instrument:'Electric Bass'}]};
await page.route('http://project.test/',r=>r.fulfill({contentType:'text/html',body:'<html><head></head><body><input type="file" id="stemZipInput"><button id="stemUploadButton">Upload and review ZIP</button><button id="stemUploadCancel" hidden>Cancel upload</button><div id="stemUploadProgress" hidden><span id="stemUploadProgressBar"></span><span id="stemUploadProgressText"></span></div><p id="stemUploadStatus"></p></body></html>'}));
await page.route('**/api/import',async r=>{
 const body=r.request().postDataBuffer().toString();const action=body.match(/name="action"\r\n\r\n([^\r]+)/)?.[1];assert.ok(action);calls.push(action);
 if(action==='meta')parsedMeta=JSON.parse(body.match(/name="rpp_meta_json"\r\n\r\n([^\r]+)/)[1]);
 if(action==='review')savedReview=JSON.parse(body.match(/name="review_json"\r\n\r\n([^\r]+)/)[1]);
 if(action==='file_place'&&failPlace)return r.fulfill({status:500,json:{ok:false,error:'Storage unavailable'}});
 const out={ok:true,importer_build:'v79'};
 if(action==='review_prepare')out.review=review;
 if(action==='save_db')out.total_stems=1;
 if(action==='file_place')out.completed=1;
 if(action==='save_finish')Object.assign(out,{stem_count:1,studio_url:'http://project.test/done'});
 await r.fulfill({json:out});
});
await page.route('http://project.test/done',r=>r.fulfill({body:'Import complete'}));
async function boot(){calls.length=0;await page.goto('http://project.test/');await page.evaluate(()=>{window.STONEFELLOW_BROWSER_ZIP={endpoint:'/api/import',csrf:'fixture',trackId:9,chunkBytes:1048576,maxBytes:10485760,reviewBeforeSave:true,requireProject:true};});for(const p of ['admin/stem-import-review-v79.js','admin/stem-browser-zip-v79.js'])await page.addScriptTag({content:await read(p)});}
async function upload(names){await page.locator('#stemZipInput').setInputFiles({name:'project.zip',mimeType:'application/zip',buffer:zip(names)});await page.locator('#stemUploadButton').click();}
try{
 await boot();await upload();await page.getByRole('button',{name:'Save Import',exact:true}).waitFor();
 assert.equal(parsedMeta.tempo_bpm,86,'Actual RPP parser lost the tempo');
 assert.ok(calls.includes('review_prepare'));assert.ok(!calls.includes('save_open'),'Active save began before review');assert.equal(await page.locator('.stem-import-review img').count(),0,'Untrusted title became markup');
 assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Mobile review overflowed');
 await page.getByRole('button',{name:'Cancel Import',exact:true}).click();await page.waitForFunction(()=>!document.querySelector('#stemUploadButton').disabled);assert.ok(calls.includes('abort'));assert.ok(!calls.includes('save_finish'),'Cancel activated the new project');
 await boot();await upload();await page.getByRole('button',{name:'Save Import',exact:true}).waitFor();await page.locator('[data-review-track="title"]').fill('Reviewed song');await page.getByRole('button',{name:'Save Import',exact:true}).click();await page.waitForURL('http://project.test/done');
 assert.equal(savedReview.track.title,'Reviewed song','Reviewed title did not reach the save session');
 assert.ok(calls.indexOf('review')<calls.indexOf('save_open'));assert.ok(calls.indexOf('file_place')<calls.indexOf('save_finish'));assert.ok(!calls.includes('metadata_update'),'Pre-save review unexpectedly used post-save correction');
 await boot();failPlace=true;await upload();await page.getByRole('button',{name:'Save Import',exact:true}).click();await page.waitForFunction(()=>!document.querySelector('#stemUploadButton').disabled);assert.match(await page.locator('#stemUploadStatus').innerText(),/Storage unavailable/);assert.ok(calls.includes('abort')&&!calls.includes('save_finish'),'Failed file placement activated a package');failPlace=false;
 await boot();await upload(['Bass.wav']);await page.waitForFunction(()=>!document.querySelector('#stemUploadButton').disabled);assert.match(await page.locator('#stemUploadStatus').innerText(),/no REAPER/);assert.ok(!calls.includes('init'),'Missing RPP created an import');
 await boot();await upload(['session.rpp','folder/Bass.wav','other/Bass.wav']);await page.waitForFunction(()=>!document.querySelector('#stemUploadButton').disabled);assert.match(await page.locator('#stemUploadStatus').innerText(),/same file name/);assert.ok(!calls.includes('init'),'Duplicate names created an import');
 assert.deepEqual(errors,[]);console.log('MUSIC_PROJECT_IMPORT_BROWSER=PASS real ZIP extraction, pre-save review, cancellation, staging failure, RPP and duplicate validation, mobile and escaped metadata');
}finally{await browser.close();}
