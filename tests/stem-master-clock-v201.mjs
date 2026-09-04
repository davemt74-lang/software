import fs from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const source=fs.readFileSync('admin/stem-master-clock-v201.js','utf8');
const sandbox={console};
sandbox.globalThis=sandbox;
vm.runInNewContext(source,sandbox);
const clockApi=sandbox.StonefellowStemMasterClockV201;

let audioTime=10;
let fallbackTime=100;
const clock=clockApi.createClock({
  audioNow:()=>audioTime,
  fallbackNow:()=>fallbackTime
});

assert.equal(clock.current(),0);
clock.start(4,1);
audioTime=10.5;
assert.equal(clock.current(),4.5);

clock.start(4.5,1,11);
audioTime=10.75;
assert.equal(clock.current(),4.5);
audioTime=11.25;
assert.equal(clock.current(),4.75);

clock.setRate(2);
audioTime=11.75;
assert.equal(clock.current(),5.75);

clock.pause();
audioTime=20;
assert.equal(clock.current(),5.75);

clock.seek(2.25);
assert.equal(clock.current(),2.25);
clock.start(2.25,.5);
audioTime=21;
assert.equal(clock.current(),2.75);

const snapshot=clock.snapshot();
assert.equal(snapshot.source,'audio-context');
assert.equal(snapshot.running,true);
assert.equal(snapshot.rate,.5);

let unavailable=NaN;
const fallbackClock=clockApi.createClock({
  audioNow:()=>unavailable,
  fallbackNow:()=>fallbackTime
});
fallbackClock.start(1,1);
fallbackTime=101.25;
assert.equal(fallbackClock.current(),2.25);

// A running clock never mixes fallback and AudioContext epochs. The new
// source is adopted only at an explicit transport anchor.
unavailable=3;
assert.equal(fallbackClock.current(),2.25);
fallbackClock.start(2.25,1);
unavailable=3.5;
assert.equal(fallbackClock.current(),2.75);

assert.equal(clockApi.cleanRate(99),4);
assert.equal(clockApi.cleanRate(0),.25);
assert.equal(clockApi.cleanPosition(-3),0);

const studio=fs.readFileSync('admin/stems-v108.js','utf8');
const page=fs.readFileSync('admin/stems-legacy-v108.php','utf8');
assert.match(studio,/StonefellowStemMasterClockV201/);
assert.match(studio,/masterClock\.current\(\)/);
assert.match(studio,/masterClock\?\.start\(position,transportRate\(\)\)/);
assert.doesNotMatch(studio,/startedAt\s*=/);
assert.ok(page.indexOf('stem-master-clock-v201.js') < page.indexOf('stem-transport-v200.js'));

console.log('STEM_MASTER_CLOCK_V201=PASS');
