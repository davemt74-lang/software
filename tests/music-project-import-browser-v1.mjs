import assert from 'node:assert/strict';
import fs from 'node:fs';
import { chromium } from 'playwright';

const source=fs.readFileSync('music-project-import.php','utf8');
const match=source.match(/<div id="musicImportOverlay"[\s\S]*?<\/section><\/div>/);
assert(match,'Use the real Music Library import overlay markup');
const html=match[0].replace(/<\?=\s*e\(\$self\)\s*\?>/g,'/music-project-import.php?track=12');
const browser=await chromium.launch({headless:true});
try{
  for (const viewport of [{width:1280,height:800},{width:390,height:780}]){
    const page=await browser.newPage({viewport});
    await page.setContent(`<html><body>${html}</body></html>`);
    await page.addStyleTag({path:'music-import-progress-v1.css'});
    await page.evaluate(()=>{window.STONEFELLOW_BROWSER_ZIP={musicLibrary:true,agentVoiceEnabled:false};});
    await page.addScriptTag({path:'music-import-progress-v1.js'});
    await page.evaluate(()=>{
      window.VP3MusicImportProgress.begin({name:'Test Song',files:[
        {key:'rpp',name:'test.RPP',kind:'project'},
        ...Array.from({length:12},(_,i)=>({key:`stem-${i}`,name:`Stem ${i+1}.wav`,kind:'stem'}))
      ]});
      window.VP3MusicImportProgress.file('rpp','uploaded',100);
      window.VP3MusicImportProgress.file('stem-0','uploading',42);
    });
    assert.equal(await page.locator('#musicImportOverlay').isVisible(),true);
    assert.equal(await page.locator('#musicImportItems li').count(),13);
    assert.match(await page.locator('#musicImportCounts').innerText(),/1 of 13 files uploaded/);
    const sizes=await page.evaluate(()=>{
      const rect=document.querySelector('.vp3-music-import-dialog').getBoundingClientRect();
      return {left:rect.left,right:rect.right,top:rect.top,bottom:rect.bottom,sw:innerWidth,sh:innerHeight};
    });
    assert(sizes.left>=-1 && sizes.right<=sizes.sw+1,`horizontal fit ${viewport.width}`);
    assert(sizes.top>=-1 && sizes.bottom<=sizes.sh+1,`vertical fit ${viewport.width}`);
    await page.evaluate(()=>window.VP3MusicImportProgress.pause());
    assert.equal(await page.locator('#musicImportOverlay').isVisible(),false);
    await page.evaluate(()=>window.VP3MusicImportProgress.resume());
    await page.evaluate(()=>window.VP3MusicImportProgress.finish({stemCount:12,url:'/music-project-import.php?track=12'}));
    assert.equal(await page.locator('#musicImportView').isVisible(),true);
    assert.equal(await page.locator('#musicImportTrack').getAttribute('aria-valuenow'),'100');
    await page.close();
    console.log(`PASS Music Library import overlay ${viewport.width}px`);
  }
}finally{await browser.close();}
