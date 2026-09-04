import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source=fs.readFileSync('admin/stem-professional-editing-v210.js','utf8');
const css=fs.readFileSync('admin/stem-professional-editing-v210.css','utf8');
const wrapper=fs.readFileSync('admin/stems.php','utf8');
const studioSource=fs.readFileSync('admin/stems-v108.js','utf8');

const sandbox={console};
sandbox.globalThis=sandbox;
vm.createContext(sandbox);
vm.runInContext(source,sandbox,{filename:'stem-professional-editing-v210.js'});
const api=sandbox.StonefellowStemProfessionalEditingV210;

assert.ok(api);
assert.equal(api.build,'stem-professional-editing-v210-20260901');

const base={
  sessionTempo:120,
  stems:{
    '1':{id:1,clips:[
      {id:'main',timelineStart:0,timelineLength:8,sourceStart:0,sourceEnd:8,muted:false,fadeIn:0,fadeOut:0}
    ]},
    '2':{id:2,clips:[
      {id:'take-a',timelineStart:0,timelineLength:8,sourceStart:10,sourceEnd:18,muted:true,fadeIn:0,fadeOut:0}
    ]},
    '3':{id:3,clips:[
      {id:'take-b',timelineStart:0,timelineLength:8,sourceStart:20,sourceEnd:28,muted:true,fadeIn:0,fadeOut:0}
    ]},
    '4':{id:4,clips:[
      {id:'other',timelineStart:9,timelineLength:2,sourceStart:0,sourceEnd:2,muted:false,fadeIn:0,fadeOut:0}
    ]}
  },
  libraryClips:[
    {id:'lib',stemId:9,sourceDuration:12,sourceStart:1,sourceEnd:3,timelineStart:2,timelineLength:2,fadeIn:0,fadeOut:0}
  ]
};

assert.equal(api.clipEntries(base).length,5);
assert.deepEqual(
  {...api.selectionBounds(base,['main','lib'])},
  {start:0,end:8,duration:8,count:2}
);

const moved=api.moveSelection(base,['main','lib'],1.5);
assert.equal(moved.moved,2);
assert.equal(moved.delta,1.5);
assert.equal(moved.state.stems['1'].clips[0].timelineStart,1.5);
assert.equal(moved.state.libraryClips[0].timelineStart,3.5);
assert.equal(base.stems['1'].clips[0].timelineStart,0,'v210 transforms must be immutable');

const clampedMove=api.moveSelection(base,['main','lib'],-5);
assert.equal(clampedMove.delta,0,'group move must preserve the earliest selected clip at timeline zero');

let serial=0;
const duplicated=api.duplicateSelection(base,['main','lib'],4,{
  idFactory:baseId=>`${baseId}-copy-${++serial}`
});
assert.equal(duplicated.ids.length,2);
assert.ok(duplicated.state.stems['1'].clips.some(clip=>clip.id==='main-copy-1'&&clip.timelineStart===4));
assert.ok(duplicated.state.libraryClips.some(clip=>clip.id==='lib-copy-2'&&clip.timelineStart===6));

const fadeIn=api.setClipFade(base,'main','in',1.25);
assert.equal(fadeIn.changed,true);
assert.equal(fadeIn.state.stems['1'].clips[0].fadeIn,1.25);
const fadeClamp=api.setClipFade(base,'main','out',99);
assert.equal(fadeClamp.state.stems['1'].clips[0].fadeOut,8);

const crossfadeBase={
  stems:{'1':{id:1,clips:[
    {id:'left',timelineStart:0,timelineLength:3,sourceStart:0,sourceEnd:3,fadeIn:0,fadeOut:0},
    {id:'right',timelineStart:3,timelineLength:3,sourceStart:3,sourceEnd:6,fadeIn:0,fadeOut:0}
  ]}},
  libraryClips:[]
};
const xfade=api.setCrossfadePair(crossfadeBase,'left','right',.5,{createOverlap:true});
assert.equal(xfade.changed,true);
assert.equal(xfade.duration,.5);
assert.equal(xfade.state.stems['1'].clips.find(clip=>clip.id==='left').fadeOut,.5);
assert.equal(xfade.state.stems['1'].clips.find(clip=>clip.id==='right').fadeIn,.5);
assert.equal(xfade.state.stems['1'].clips.find(clip=>clip.id==='right').timelineStart,2.5);

const splitUsed=new Set(['clip']);
const split=api.splitClipAt(
  {id:'clip',timelineStart:2,timelineLength:4,sourceStart:10,sourceEnd:18,fadeIn:.2,fadeOut:.3},
  4,
  splitUsed,
  baseId=>`${baseId}-right`
);
assert.equal(split.length,2);
assert.equal(split[0].timelineStart,2);
assert.equal(split[0].timelineLength,2);
assert.equal(split[0].sourceEnd,14);
assert.equal(split[1].timelineStart,4);
assert.equal(split[1].timelineLength,2);
assert.equal(split[1].sourceStart,14);
assert.equal(split[0].fadeOut,0);
assert.equal(split[1].fadeIn,0);

serial=0;
const comp=api.compTakeRange(base,[1,2,3],2,2,4,{
  idFactory:baseId=>`${baseId}-comp-${++serial}`
});
assert.ok(comp.changed>0);
for(const stemId of ['1','2','3']){
  const clips=comp.state.stems[stemId].clips;
  assert.equal(clips.length,3,`stem ${stemId} should split on both comp boundaries`);
  const middle=clips.find(clip=>Math.abs(clip.timelineStart-2)<1e-9&&Math.abs(clip.timelineLength-2)<1e-9);
  assert.ok(middle,`stem ${stemId} should contain the comp-range segment`);
  assert.equal(middle.muted,stemId!=='2',`only chosen take ${stemId} range should be audible`);
}
assert.equal(comp.state.stems['1'].clips[0].muted,false,'main track before comp range stays audible');
assert.equal(comp.state.stems['1'].clips[2].muted,false,'main track after comp range stays audible');
assert.equal(comp.state.stems['2'].clips[0].muted,true,'chosen take outside comp range preserves prior mute state');
assert.equal(comp.state.stems['2'].clips[2].muted,true,'chosen take outside comp range preserves prior mute state');
assert.equal(comp.state.stems['4'].clips[0].timelineStart,9,'unrelated tracks must remain unchanged');

assert.equal(api.rectIntersects({left:0,top:0,right:10,bottom:10},{left:5,top:5,right:15,bottom:15}),true);
assert.equal(api.rectIntersects({left:0,top:0,right:4,bottom:4},{left:5,top:5,right:15,bottom:15}),false);
assert.deepEqual({...api.rangeFromPixels(100,300,0,400,8)},{start:2,end:6});

/* Existing split remains authoritative: v210 only routes menu action into it. */
assert.match(studioSource,/function splitSelectedSection\s*\(/);
assert.match(studioSource,/splitShortcut/);
assert.match(source,/type:'clip_split'/);
assert.match(source,/Ctrl\/Cmd\+S/);
assert.doesNotMatch(source,/function\s+splitSelectedSection\s*\(/,'v210 must not duplicate the core split implementation');

/* Professional UI/gesture contracts. */
assert.match(source,/toolMode='pointer'/);
assert.match(source,/data-v210-tool="marquee"/);
assert.match(source,/data-v210-tool="range"/);
assert.match(source,/clip_group_move/);
assert.match(source,/clip_alt_duplicate/);
assert.match(source,/clip_slip_drag/);
assert.match(source,/clip_fade_drag/);
assert.match(source,/clip_crossfade_drag/);
assert.match(source,/take_comp/);
assert.match(source,/data-v210-menu="cut"/);
assert.match(source,/data-v210-menu="copy"/);
assert.match(source,/data-v210-menu="paste"/);
assert.match(source,/data-v210-menu="duplicate"/);
assert.match(source,/data-v210-menu="split"/);
assert.match(source,/data-v210-menu="mute"/);
assert.match(source,/data-v210-menu="delete"/);
assert.match(source,/event\.altKey&&event\.shiftKey/,'Alt/Option+Shift drag is the slip gesture');
assert.match(source,/event\.altKey&&!event\.shiftKey/,'Alt/Option drag is the duplicate gesture');
assert.match(source,/beginUndoGroup/);
assert.match(source,/applyMixState/);
assert.match(source,/endUndoGroup/);
assert.match(source,/recordManualEdit/);
assert.match(css,/sf-v210-marquee/);
assert.match(css,/sf-v210-fade-handle/);
assert.match(css,/sf-v210-xfade-handle/);
assert.match(css,/sf-v210-context/);
assert.match(css,/sf-v210-take-badge/);
assert.doesNotMatch(source,/SpeechRecognition|premium-voice|ElevenLabs|chatVoiceButton/);

/* Wrapper must load the capture layer before v209, while v210 waits for v209 API. */
assert.match(wrapper,/\$professionalEditingToken = 'stem-professional-editing-v210-20260901';/);
assert.match(wrapper,/stem-professional-editing-v210\.css\?v=/);
assert.match(wrapper,/stem-professional-editing-v210\.js\?v=/);
assert.match(wrapper,/data-stem-editing-v210/);
const v210Index=wrapper.indexOf('data-stem-editing-v210');
const v209Index=wrapper.indexOf('data-stem-editing-v209');
assert.ok(v210Index>=0&&v209Index>=0&&v210Index<v209Index,'v210 capture layer must register before v209 selection listener');
assert.match(source,/if\(!studio\(\)\|\|!v209\(\)\|\|!v209Api\(\)/,'v210 must wait for v209 before activating its UI');

console.log('STEM_PROFESSIONAL_EDITING_V210=PASS');
