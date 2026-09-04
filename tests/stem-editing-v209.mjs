import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source=fs.readFileSync('admin/stem-editing-v209.js','utf8');
const wrapper=fs.readFileSync('admin/stems.php','utf8');
const studioSource=fs.readFileSync('admin/stems-v108.js','utf8');
const sandbox={console};
sandbox.globalThis=sandbox;
vm.createContext(sandbox);
vm.runInContext(source,sandbox,{filename:'stem-editing-v209.js'});
const api=sandbox.StonefellowStemEditingV209;

assert.ok(api);
assert.equal(api.build,'stem-editing-foundation-v209-20260901');
assert.match(studioSource,/state\.stems\[String\(stem\.id\)\]/,'fixture must mirror the production keyed stem-state contract');

const base={
  sessionTempo:120,
  stems:{
    '1':{id:1,clips:[
      {id:'a',timelineStart:0,timelineLength:2,sourceStart:0,sourceEnd:2,gainDb:0,muted:false,fadeIn:0,fadeOut:0},
      {id:'b',timelineStart:3,timelineLength:2,sourceStart:2,sourceEnd:4,gainDb:0,muted:false,fadeIn:0,fadeOut:0}
    ]},
    '2':{id:2,clips:[
      {id:'c',timelineStart:6,timelineLength:1,sourceStart:0,sourceEnd:1,gainDb:0,muted:false,fadeIn:0,fadeOut:0}
    ]}
  },
  libraryClips:[
    {id:'lib1',stemId:9,name:'Loop',sourceTempo:120,sourceDuration:8,sourceStart:1,sourceEnd:3,timelineStart:1,timelineLength:2}
  ]
};

const entries=api.stateClipEntries(base);
assert.equal(entries.length,4);
assert.equal(api.findStemState(base,1),base.stems['1']);
assert.deepEqual(
  {...api.selectionBounds(entries.filter(row=>['a','b'].includes(row.id)))},
  {start:0,end:5,duration:5}
);

const clipboard=api.createClipboard(base,['a','lib1']);
assert.equal(clipboard.entries.length,2);
assert.equal(clipboard.bounds.start,0);
assert.equal(clipboard.bounds.end,3);
assert.equal(clipboard.entries.find(row=>row.clip.id==='lib1').relativeStart,1);

let id=0;
const pasted=api.pasteClipboard(base,clipboard,8,{idFactory:()=>`new-${++id}`});
assert.deepEqual(Array.from(pasted.ids),['new-1','new-2']);
assert.equal(pasted.state.stems['1'].clips.find(clip=>clip.id==='new-1').timelineStart,8);
assert.equal(pasted.state.libraryClips.find(clip=>clip.id==='new-2').timelineStart,9);
assert.equal(base.stems['1'].clips.length,2,'source state must remain immutable');

id=0;
const ripplePaste=api.pasteClipboard(base,api.createClipboard(base,['a']),3,{ripple:true,idFactory:()=>`r-${++id}`});
assert.equal(ripplePaste.state.stems['1'].clips.find(clip=>clip.id==='b').timelineStart,5);
assert.equal(ripplePaste.state.stems['2'].clips.find(clip=>clip.id==='c').timelineStart,8);
assert.equal(ripplePaste.state.stems['1'].clips.find(clip=>clip.id==='r-1').timelineStart,3);

const deleted=api.deleteSelection(base,['b'],{ripple:false});
assert.equal(deleted.removed,1);
assert.equal(deleted.state.stems['1'].clips.some(clip=>clip.id==='b'),false);
assert.equal(deleted.state.stems['2'].clips.find(clip=>clip.id==='c').timelineStart,6);

const rippleDeleted=api.deleteSelection(base,['b'],{ripple:true});
assert.equal(rippleDeleted.state.stems['2'].clips.find(clip=>clip.id==='c').timelineStart,4);

const nudged=api.nudgeSelection(base,['a','lib1'],-2);
assert.equal(nudged.delta,0,'group nudge must not move any selected clip before zero');
const nudgedRight=api.nudgeSelection(base,['a','lib1'],.5);
assert.equal(nudgedRight.state.stems['1'].clips.find(clip=>clip.id==='a').timelineStart,.5);
assert.equal(nudgedRight.state.libraryClips.find(clip=>clip.id==='lib1').timelineStart,1.5);

const slipped=api.slipSelection(base,['lib1'],2,{lib1:8},{lib1:1});
assert.equal(slipped.changed,1);
assert.equal(slipped.state.libraryClips[0].timelineStart,1,'slip must preserve timeline position');
assert.equal(slipped.state.libraryClips[0].timelineLength,2,'slip must preserve timeline length');
assert.equal(slipped.state.libraryClips[0].sourceStart,3);
assert.equal(slipped.state.libraryClips[0].sourceEnd,5);

const stretchedSlip=api.slipSelection(base,['lib1'],2,{lib1:8},{lib1:2});
assert.equal(stretchedSlip.state.libraryClips[0].sourceStart,2,'timeline slip must convert through the source/timeline tempo ratio');
assert.equal(stretchedSlip.state.libraryClips[0].sourceEnd,4);

const slipClamped=api.slipSelection(base,['lib1'],99,{lib1:4},{lib1:1});
assert.equal(slipClamped.state.libraryClips[0].sourceStart,2);
assert.equal(slipClamped.state.libraryClips[0].sourceEnd,4);

const crossfadeBase={
  stems:{'1':{id:1,clips:[
    {id:'left',timelineStart:0,timelineLength:2,sourceStart:0,sourceEnd:2,fadeIn:0,fadeOut:0},
    {id:'right',timelineStart:2,timelineLength:2,sourceStart:0,sourceEnd:2,fadeIn:0,fadeOut:0}
  ]}},libraryClips:[]
};
const xfade=api.crossfadeSelection(crossfadeBase,['left','right'],.25,{createOverlap:true});
assert.equal(xfade.pairs,1);
assert.equal(xfade.state.stems['1'].clips[1].timelineStart,1.75);
assert.equal(xfade.state.stems['1'].clips[0].fadeOut,.25);
assert.equal(xfade.state.stems['1'].clips[1].fadeIn,.25);

assert.match(source,/Ctrl\/Cmd\+D/);
assert.match(source,/RIPPLE ALL/);
assert.match(source,/clip_ripple_delete/);
assert.match(source,/clip_slip/);
assert.match(source,/clip_crossfade/);
assert.match(source,/beginUndoGroup/);
assert.match(source,/getLedgerState/);
assert.match(source,/applyMixState/);
assert.match(source,/endUndoGroup/);
assert.match(source,/recordManualEdit/);
assert.match(source,/data-main-clip-id/);
assert.match(source,/data-library-clip-id/);
assert.match(source,/timelineRatio/);
assert.doesNotMatch(source,/SpeechRecognition|premium-voice|ElevenLabs|chatVoiceButton/);

assert.match(wrapper,/\$editingToken = 'stem-editing-foundation-v209-20260901';/);
assert.match(wrapper,/data-stem-editing-v209/);
assert.match(wrapper,/stem-editing-v209\.js\?v=/);
const replacementIndex=wrapper.indexOf('$html = str_replace(array_keys($replacements)');
const editingIndex=wrapper.indexOf("$editingRuntime = '<script data-stem-editing-v209");
assert.ok(replacementIndex>=0&&editingIndex>replacementIndex,'v209 must be injected only after the active stems-v108 rewrite is complete');

console.log('STEM_EDITING_V209=PASS');
