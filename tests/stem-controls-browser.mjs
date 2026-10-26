import fs from 'node:fs';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const require=createRequire(import.meta.url),{chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_EXECUTABLE_PATH,args:['--no-sandbox']});
const base=fs.readFileSync('admin/admin.css','utf8'),controls=fs.readFileSync('admin/stem-controls.css','utf8');
const wrapper=fs.readFileSync('admin/stems.php','utf8');
assert.match(wrapper,/preg_replace\('[^\n]*data-admin-tech-theme/,'Only the Studio render removes the incompatible admin theme');
assert.match(wrapper,/stem-controls\.css\?v=media-20261026/);
// Render the production Studio template itself, with local fixture records.
// No copied mixer layout: structural regressions must be visible in review.
const layout=execFileSync(process.env.PHP_BINARY||'php',['-r',`
error_reporting(E_ALL);
function e($value){return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url($path){return $path;}
function stem_format_duration($seconds){return sprintf('%d:%02d',intdiv((int)$seconds,60),(int)$seconds%60);}
$source=file_get_contents('admin/stems-legacy-v108.php');
$a=strpos($source,'<div class="daw-studio" id="stemStudio">');
$b=strpos($source,'  <div class="daw-modal" id="recordingSaveDialog"',$a);
$maxDuration=97;$sourceTempo=100;$durationMeasures=0;
$project=['project_name'=>'Fever Dream','project_sample_rate'=>44100,'time_signature'=>'4/4'];
$stems=[];
foreach(['DRUMS','BASS','GUITAR','VOCAL'] as $index=>$name){
 $stems[]=['id'=>$index+1,'stem_name'=>$name,'stem_role'=>ucfirst(strtolower($name)),'file_name'=>$name.'.wav','duration_seconds'=>97,'start_offset_seconds'=>0,'rpp_volume'=>1,'rpp_pan'=>0];
}
eval('?>'.substr($source,$a,$b-$a).'</div>');
`],{encoding:'utf8'});
const extra=['admin/stems-v107.css','admin/stems-extra-v107.css','admin/stem-professional-editing-v210.css'].map(file=>fs.readFileSync(file,'utf8')).join('\n');
try{
 const page=await browser.newPage({viewport:{width:1440,height:900}});
 await page.setContent(`<style>${base}\n${extra}\n${controls}</style><body class="admin-canvas-mode"><div class="admin-layout admin-layout-canvas"><main class="admin-content admin-content-canvas"><header class="daw-canvas-header"><strong>STEM STUDIO · Fever Dream</strong></header>${layout}</main></div></body>`);
 const geometry=await page.locator('main').evaluate(node=>({left:node.getBoundingClientRect().left,width:node.getBoundingClientRect().width,padding:getComputedStyle(node).paddingLeft}));
 assert.equal(geometry.left,0);assert.equal(geometry.width,1440);assert.equal(geometry.padding,'0px');
 const mixer=await page.locator('.daw-mixer').boundingBox();
 assert.ok(mixer.y>200&&mixer.y+mixer.height<=901,'Real mixer remains visible below the arrange canvas');
 const input=page.getByRole('spinbutton',{name:'Session tempo'});
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
