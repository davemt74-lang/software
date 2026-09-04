import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source=fs.readFileSync('admin/stem-automation-mixer-v211.js','utf8');
const css=fs.readFileSync('admin/stem-automation-mixer-v211.css','utf8');
const wrapper=fs.readFileSync('admin/stems.php','utf8');
const core=fs.readFileSync('admin/stems-v108.js','utf8');
const sandbox={console};
sandbox.globalThis=sandbox;
vm.createContext(sandbox);
vm.runInContext(source,sandbox,{filename:'stem-automation-mixer-v211.js'});
const api=sandbox.StonefellowStemAutomationMixerV211;

assert.ok(api,'v211 API should load');
assert.equal(api.build,'stem-automation-mixer-v211-20260901');
assert.deepEqual(Array.from(api.modes),['read','touch','latch','write']);
assert.equal(api.normalizeMode('TOUCH'),'touch');
assert.equal(api.normalizeMode('bad'),'read');

const linear=[{t:0,v:0},{t:10,v:1}];
assert.equal(api.automationValueAt(linear,5,0),.5);
assert.equal(api.automationValueAt(linear,-1,0),0);
assert.equal(api.automationValueAt(linear,99,0),1);

let points=[];
points=api.insertAutomationPoint(points,1,.2);
points=api.insertAutomationPoint(points,2,.4);
points=api.insertAutomationPoint(points,3,.6);
assert.equal(points.length,3);
points=api.insertAutomationPoint(points,2.01,.5,{replaceWindow:.05});
assert.ok(points.some(point=>Math.abs(point.t-2.01)<1e-9&&Math.abs(point.v-.5)<1e-9));

const automation={
  volume:[{t:1,v:.8},{t:2,v:.9},{t:5,v:1.1}],
  pan:[{t:1.5,v:-.5},{t:2.5,v:.5}],
  auxA:[{t:2,v:.25}],
  auxB:[]
};
const copied=api.copyAutomationRange(automation,1,3);
assert.equal(copied.duration,2);
assert.deepEqual(Array.from(copied.parameters.volume,point=>point.t),[0,1]);
assert.deepEqual(Array.from(copied.parameters.pan,point=>point.t),[.5,1.5]);
const pasted=api.pasteAutomationRange(automation,copied,8,{replace:true});
assert.ok(pasted.volume.some(point=>Math.abs(point.t-8)<1e-9&&Math.abs(point.v-.8)<1e-9));
assert.ok(pasted.pan.some(point=>Math.abs(point.t-9.5)<1e-9&&Math.abs(point.v-.5)<1e-9));

const shifted=api.shiftAutomationRange([{t:1,v:0},{t:2,v:.5},{t:4,v:1}],1,2,3);
assert.deepEqual(Array.from(shifted,point=>point.t),[4,4,5]);

const parsed=api.parsePluginTarget('plugin:2:compressor:threshold');
assert.deepEqual({...parsed},{index:2,type:'compressor',param:'threshold'});
assert.equal(api.parsePluginTarget('volume'),null);
assert.deepEqual({...api.pluginParamSpec('delay','feedback')},{min:0,max:.92,unit:'%'});
assert.equal(api.normalizePluginValue('compressor','ratio',99),20);
assert.equal(api.pluginAutomationKey(1,'Delay','mix'),'plugin:1:delay:mix');

const silence=new Uint8Array(256);silence.fill(128);
assert.equal(api.rmsFromTimeDomain(silence).db,-96);
const loud=new Uint8Array([255,1,255,1]);
assert.ok(api.rmsFromTimeDomain(loud).db>-4);

assert.match(core,/automation:\s*\{[\s\S]*volume:\s*\[\][\s\S]*pan:\s*\[\][\s\S]*auxA:\s*\[\][\s\S]*auxB:\s*\[\]/,'v211 must extend the existing core automation model rather than replace it');
assert.match(core,/type\s*===\s*['"]automation_point['"]/,'existing automation command path must remain authoritative');
assert.match(source,/const MAX_POINTS=1200/,'plugin automation capture should retain long-session headroom');
assert.match(source,/mode==='write'\|\|\(touch\?\.parameter===target&&\(touch\.active\|\|touch\.latched\)\)/,'plugin READ playback must not fight WRITE, TOUCH, or LATCH input');
assert.match(source,/touches\.clear\(\);pluginApplyCache\.clear\(\);lastWrites\.clear\(\)/,'automation latch/write state must reset when transport stops');
assert.match(source,/READ/);
assert.match(source,/TOUCH/);
assert.match(source,/LATCH/);
assert.match(source,/WRITE/);
assert.match(source,/automation_range_paste/);
assert.match(source,/automationFollowClipEvent/);
assert.match(source,/plugin_param/);
assert.match(source,/getByteTimeDomainData/);
assert.match(source,/data-v211-meter/);
assert.match(source,/track_trim/);
assert.match(source,/SOLO CLEAR/);
assert.match(source,/MUTE CLEAR/);
assert.match(source,/AUTO CLEAN/);
assert.match(source,/stonefellow:stem-edit-v210/);
assert.doesNotMatch(source,/SpeechRecognition|ElevenLabs|premium-voice|chatVoiceButton|conversation-voice/);

assert.match(css,/sf-v211-meter/);
assert.match(css,/sf-v211-mixer-compact/);
assert.match(css,/sf-v211-mixer-wide/);
assert.match(css,/sf-v211-plugin-lane/);

assert.match(wrapper,/\$automationMixerToken = 'stem-automation-mixer-v211-20260901';/);
assert.match(wrapper,/data-stem-automation-v211/);
assert.match(wrapper,/stem-automation-mixer-v211\.js\?v=/);
assert.match(wrapper,/stem-automation-mixer-v211\.css\?v=/);
assert.match(wrapper,/stem-automation-mixer-v211-20260901/);
const v210Index=wrapper.indexOf('data-stem-editing-v210');
const v209Index=wrapper.indexOf('data-stem-editing-v209');
const v211Index=wrapper.indexOf('data-stem-automation-v211');
assert.ok(v210Index>=0&&v209Index>v210Index&&v211Index>v209Index,'v211 should load after v210 capture and v209 editing runtimes');

console.log('STEM_AUTOMATION_MIXER_V211=PASS');
