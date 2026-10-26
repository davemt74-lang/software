import fs from 'node:fs';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url),{chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_EXECUTABLE_PATH,args:['--no-sandbox']});
const base=fs.readFileSync('admin/admin.css','utf8'),controls=fs.readFileSync('admin/stem-controls.css','utf8');
const wrapper=fs.readFileSync('admin/stems.php','utf8');
assert.match(wrapper,/preg_replace\('[^\n]*data-admin-tech-theme/,'Only the Studio render removes the incompatible admin theme');
assert.match(wrapper,/stem-controls\.css\?v=media-20261026/);
const strip=(name,cls='daw-stem-volume-control')=>`<section class="daw-channel"><header>${name}</header><label>INPUT <select aria-label="${name} input"><option>Default input</option></select></label><label class="daw-fader-wrap daw-stem-fader-wrap"><input class="daw-fader ${cls}" type="range" min="0" max="1.5" step=".01" value="1" aria-label="${name} volume"><output>+0.0 dB</output></label></section>`;
try{
 const page=await browser.newPage({viewport:{width:1440,height:900}});
 await page.setContent(`<style>${base}\n${controls}</style><body class="admin-canvas-mode"><div class="admin-layout admin-layout-canvas"><main class="admin-content admin-content-canvas"><header class="daw-canvas-header"><strong>STEM STUDIO · Fever Dream</strong></header><div class="daw-studio" id="stemStudio"><section class="daw-arrange"><h2>Original audio tracks</h2><p>Drums · Bass · Guitar · Vocal</p></section><section class="daw-mixer"><div class="daw-transport"><button>▶</button><label>TEMPO <input type="number" value="100" aria-label="Tempo"></label><select aria-label="Loop"><option>Loop off</option></select></div><div class="daw-mixer-scroll">${strip('MASTER','daw-master-volume')}${strip('DRUMS')}${strip('BASS')}${strip('GUITAR')}${strip('VOCAL')}</div></section></div></main></div></body>`);
 const geometry=await page.locator('main').evaluate(node=>({left:node.getBoundingClientRect().left,width:node.getBoundingClientRect().width,padding:getComputedStyle(node).paddingLeft}));
 assert.equal(geometry.left,0);assert.equal(geometry.width,1440);assert.equal(geometry.padding,'0px');
 const input=page.getByRole('spinbutton',{name:'Tempo'});
 const inputStyle=await input.evaluate(node=>({background:getComputedStyle(node).backgroundColor,color:getComputedStyle(node).color}));
 assert.notEqual(inputStyle.background,'rgb(255, 255, 255)');
 assert.notEqual(inputStyle.color,'rgb(17, 19, 24)');
 const slider=page.getByRole('slider',{name:'DRUMS volume'});
 assert.equal(await slider.evaluate(node=>getComputedStyle(node).backgroundColor),'rgba(0, 0, 0, 0)');
 await slider.focus();await page.keyboard.press('ArrowRight');assert.ok(Number(await slider.inputValue())>1,'Fader keyboard control remains functional');
 if(process.env.STEM_SCREENSHOT)await page.screenshot({path:process.env.STEM_SCREENSHOT,fullPage:true});
 await page.setViewportSize({width:390,height:844});
 assert.equal(await page.locator('main').evaluate(node=>node.getBoundingClientRect().left),0,'Mobile canvas has no inherited admin gutter');
 console.log('STEM_CONTROLS_BROWSER=PASS full-width desktop/mobile canvas, dark controls, functional faders');
}finally{await browser.close();}
