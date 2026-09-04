import fs from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const source=fs.readFileSync('admin/stem-loop-planner-v204.js','utf8');
const sandbox={};sandbox.globalThis=sandbox;vm.runInNewContext(source,sandbox);
const api=sandbox.StonefellowStemLoopPlannerV204;

const clip={id:'library-1',timelineStart:4,timelineLength:10,sourceStart:1,sourceEnd:3};
let repeats=api.expandLoopClip(clip,{position:0,projectEnd:30,projectRate:1,mediaRate:1});
assert.equal(repeats.length,5);
assert.deepEqual(Array.from(repeats,item=>item.timelineStart),[4,6,8,10,12]);
assert.ok(repeats.every(item=>item.sourceStart===1&&item.sourceEnd===3));

repeats=api.expandLoopClip(clip,{position:9,projectEnd:30,projectRate:1,mediaRate:1});
assert.deepEqual(Array.from(repeats,item=>item.timelineStart),[8,10,12]);

repeats=api.expandLoopClip({...clip,timelineLength:5},{position:0,projectEnd:30,projectRate:2,mediaRate:1});
assert.equal(repeats.length,2);
assert.equal(repeats[0].timelineLength,4);
assert.equal(repeats[1].timelineLength,1);
assert.equal(repeats[1].sourceEnd,1.5);

assert.equal(api.expandLoopClip({...clip,muted:true},{position:0,projectEnd:30}).length,0);
assert.equal(api.expandLoopClip({...clip,sourceEnd:1},{position:0,projectEnd:30}).length,0);
assert.throws(
  ()=>api.expandLoopClip({...clip,timelineLength:20},{position:0,projectEnd:30,projectRate:1,mediaRate:1,maxRepeats:2}),
  /repeat budget/
);

const studio=fs.readFileSync('admin/stems-v108.js','utf8');
const page=fs.readFileSync('admin/stems-legacy-v108.php','utf8');
assert.match(studio,/StonefellowStemLoopPlannerV204/);
assert.match(studio,/loopPlanner\.expandLoopClip/);
assert.match(studio,/`library-\$\{clip\.id\}`/);
assert.match(studio,/destination:clip\.gainNode/);
assert.ok(page.indexOf('stem-time-stretch-v203.js') < page.indexOf('stem-loop-planner-v204.js'));
assert.ok(page.indexOf('stem-loop-planner-v204.js') < page.indexOf('stem-transport-v200.js'));

console.log('STEM_LOOP_PLANNER_V204=PASS');
