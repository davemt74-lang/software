import assert from 'node:assert/strict';import fs from 'node:fs';import vm from 'node:vm';
const source=fs.readFileSync('tracky-agent-scene-v1g3d.js','utf8');
function run(age,visible='visible',transit=0){let timer,hidden;const text={textContent:'Possible chair'};const panel={dataset:{sceneState:'available',sceneAge:age},querySelector:()=>text};const document={visibilityState:visible,getElementById:()=>panel,addEventListener:(_,fn)=>hidden=fn};vm.runInNewContext(source,{document,performance:{now:()=>transit},setTimeout:(fn,ms)=>timer={fn,ms}});return {text,timer,document,hide:()=>hidden()};}
const fresh=run('59','visible',250);assert.equal(fresh.timer.ms,750);fresh.timer.fn();assert.doesNotMatch(fresh.text.textContent,/chair/);
for(const age of ['invalid','-1','61'])assert.doesNotMatch(run(age).text.textContent,/chair/);
const hidden=run('2','hidden');assert.doesNotMatch(hidden.text.textContent,/chair/);
const changed=run('2');changed.document.visibilityState='hidden';changed.hide();assert.doesNotMatch(changed.text.textContent,/chair/);
assert.doesNotMatch(source,/fetch|getUserMedia|innerHTML|localStorage|sessionStorage/);
console.log('TRACKY_SHARED_SCENE_CLOUD_UI_V1G3D: snapshot expiry, navigation transit, visibility and no camera/network authority PASS');
