(function(root,factory){
  'use strict';
  const api=factory();
  root.StonefellowStemMidiCompositionV218=api;
  if(root.document) api.install(root,root.document);
})(typeof globalThis!=='undefined'?globalThis:window,function(){
  'use strict';

  const BUILD='stem-midi-composition-v218-20260902';
  const PPQ=960;
  const SAVE_DELAY=700;
  const MODES={
    major:[0,2,4,5,7,9,11],
    minor:[0,2,3,5,7,8,10],
    dorian:[0,2,3,5,7,9,10],
    mixolydian:[0,2,4,5,7,9,10],
    pentatonic:[0,2,4,7,9],
    minor_pentatonic:[0,3,5,7,10]
  };
  const DIVISIONS={'1/4':PPQ,'1/8':PPQ/2,'1/16':PPQ/4,'1/32':PPQ/8,'1/8T':PPQ/3,'1/16T':PPQ/6};
  const ROOT_NAMES=['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
  const clone=value=>JSON.parse(JSON.stringify(value));
  const num=(value,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;
  const clamp=(value,min,max)=>Math.min(max,Math.max(min,num(value,min)));
  const safeId=value=>String(value||'').replace(/[^a-zA-Z0-9_-]/g,'').slice(0,80);
  const makeId=prefix=>`${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,9)}`;

  function divisionTicks(division='1/16',ppq=PPQ){
    const base=DIVISIONS[division]||PPQ/4;
    return base*(Math.max(24,num(ppq,PPQ))/PPQ);
  }

  function scalePitchClasses(rootNote=0,mode='major'){
    const root=Math.round(clamp(rootNote,0,11));
    const intervals=MODES[mode]||MODES.major;
    return intervals.map(interval=>(root+interval)%12).sort((a,b)=>a-b);
  }

  function pitchInScale(pitch,rootNote=0,mode='major'){
    return scalePitchClasses(rootNote,mode).includes(((Math.round(num(pitch,60))%12)+12)%12);
  }

  function nearestScalePitch(pitch,rootNote=0,mode='major'){
    const target=Math.round(clamp(pitch,0,127));
    if(pitchInScale(target,rootNote,mode)) return target;
    for(let delta=1;delta<12;delta+=1){
      const down=target-delta;
      const up=target+delta;
      if(down>=0&&pitchInScale(down,rootNote,mode)) return down;
      if(up<=127&&pitchInScale(up,rootNote,mode)) return up;
    }
    return target;
  }

  function chordIntervals(quality='major'){
    const map={major:[0,4,7],minor:[0,3,7],maj7:[0,4,7,11],min7:[0,3,7,10],dom7:[0,4,7,10],sus2:[0,2,7],sus4:[0,5,7]};
    return map[quality]||map.major;
  }

  function chordPitches(rootPitch=60,quality='major',inversion=0){
    const pitches=chordIntervals(quality).map(interval=>Math.round(clamp(rootPitch+interval,0,127)));
    const turns=Math.max(0,Math.min(pitches.length-1,Math.round(num(inversion,0))));
    for(let index=0;index<turns;index+=1){
      const first=pitches.shift();
      pitches.push(Math.round(clamp(first+12,0,127)));
    }
    return pitches.sort((a,b)=>a-b);
  }

  function seededUnit(seed){
    let value=2166136261;
    const text=String(seed||'');
    for(let index=0;index<text.length;index+=1){
      value^=text.charCodeAt(index);
      value=Math.imul(value,16777619)>>>0;
    }
    value^=value<<13;
    value^=value>>>17;
    value^=value<<5;
    return (value>>>0)/4294967295;
  }

  function swingTick(tick,division='1/16',swing=0,ppq=PPQ){
    const grid=divisionTicks(division,ppq);
    const index=Math.round(num(tick,0)/grid);
    const amount=clamp(swing,0,75)/100;
    return Math.max(0,Math.round(num(tick,0)+(index%2===1?grid*.5*amount:0)));
  }

  function humanizeNotes(notes,timingTicks=0,velocityPercent=0,seed='humanize'){
    const timing=Math.max(0,Math.round(num(timingTicks,0)));
    const velocity=Math.max(0,num(velocityPercent,0))/100;
    return (Array.isArray(notes)?notes:[]).map((note,index)=>{
      const timeRand=seededUnit(`${seed}:t:${index}`)*2-1;
      const velRand=seededUnit(`${seed}:v:${index}`)*2-1;
      return {
        ...note,
        startTick:Math.max(0,Math.round(num(note.startTick,0)+timeRand*timing)),
        velocity:clamp(num(note.velocity,.8)*(1+velRand*velocity),.01,1)
      };
    });
  }

  function defaultStep(){return {on:false,velocity:.82,probability:1,lengthSteps:.9};}
  function defaultLane(pitch,name,steps=16){return {pitch,name,steps:Array.from({length:steps},defaultStep)};}
  function defaultPattern(trackId='',clipId='',steps=16){
    const count=[8,16,32,64].includes(Number(steps))?Number(steps):16;
    return {
      id:makeId('pattern'),name:'Pattern 1',trackId:String(trackId||''),clipId:String(clipId||''),
      division:'1/16',steps:count,startTick:0,seed:1,
      lanes:[defaultLane(36,'Kick',count),defaultLane(38,'Snare',count),defaultLane(42,'Closed Hat',count),defaultLane(39,'Clap',count)]
    };
  }

  function normalizeStep(value){
    const row=value&&typeof value==='object'?value:{};
    return {
      on:Boolean(row.on),
      velocity:clamp(row.velocity??.82,.01,1),
      probability:clamp(row.probability??1,0,1),
      lengthSteps:clamp(row.lengthSteps??.9,.05,4)
    };
  }

  function normalizePattern(value,index=0){
    const raw=value&&typeof value==='object'?value:{};
    const steps=[8,16,32,64].includes(Number(raw.steps))?Number(raw.steps):16;
    const division=Object.hasOwn(DIVISIONS,String(raw.division||''))?String(raw.division):'1/16';
    return {
      id:safeId(raw.id)||`pattern-${index+1}`,
      name:String(raw.name||`Pattern ${index+1}`).replace(/\s+/g,' ').trim().slice(0,80)||`Pattern ${index+1}`,
      trackId:safeId(raw.trackId),clipId:safeId(raw.clipId),division,steps,
      startTick:Math.max(0,Math.round(num(raw.startTick,0))),seed:Math.max(0,Math.round(num(raw.seed,1))),
      lanes:(Array.isArray(raw.lanes)?raw.lanes:[]).slice(0,32).map((lane,laneIndex)=>({
        pitch:Math.round(clamp(lane?.pitch??36+laneIndex,0,127)),
        name:String(lane?.name||`Lane ${laneIndex+1}`).replace(/\s+/g,' ').trim().slice(0,40),
        steps:Array.from({length:steps},(_,stepIndex)=>normalizeStep(lane?.steps?.[stepIndex]))
      }))
    };
  }

  function normalizeCcLane(value,index=0){
    const raw=value&&typeof value==='object'?value:{};
    let controller=String(raw.controller||'1');
    if(controller!=='pitch'&&(!/^\d{1,3}$/.test(controller)||Number(controller)>127)) controller='1';
    const max=controller==='pitch'?16383:127;
    return {
      id:safeId(raw.id)||`cc-${index+1}`,trackId:safeId(raw.trackId),clipId:safeId(raw.clipId),controller,
      points:(Array.isArray(raw.points)?raw.points:[]).slice(0,2048).map(point=>({
        tick:Math.max(0,Math.round(num(point?.tick,0))),value:clamp(point?.value??0,0,max)
      })).sort((a,b)=>a.tick-b.tick)
    };
  }

  function defaultComposition(){
    return {
      version:1,updatedAt:0,
      scale:{root:0,mode:'major',lock:false},
      swing:0,
      humanize:{timingTicks:0,velocityPercent:0},
      patterns:[],ccLanes:[]
    };
  }

  function normalizeComposition(value){
    const raw=value&&typeof value==='object'?value:{};
    const mode=Object.hasOwn(MODES,String(raw.scale?.mode||''))?String(raw.scale.mode):'major';
    return {
      version:1,
      updatedAt:Math.max(0,num(raw.updatedAt,0)),
      scale:{root:Math.round(clamp(raw.scale?.root??0,0,11)),mode,lock:Boolean(raw.scale?.lock)},
      swing:clamp(raw.swing??0,0,75),
      humanize:{
        timingTicks:Math.round(clamp(raw.humanize?.timingTicks??0,0,240)),
        velocityPercent:clamp(raw.humanize?.velocityPercent??0,0,60)
      },
      patterns:(Array.isArray(raw.patterns)?raw.patterns:[]).slice(0,128).map(normalizePattern),
      ccLanes:(Array.isArray(raw.ccLanes)?raw.ccLanes:[]).slice(0,128).map(normalizeCcLane)
    };
  }

  function patternPrefix(patternId){return `v218pat-${safeId(patternId)}-`;}

  function patternToNotes(patternInput,options={}){
    const pattern=normalizePattern(patternInput,0);
    const ppq=Math.max(24,num(options.ppq,PPQ));
    const swing=clamp(options.swing??0,0,75);
    const grid=divisionTicks(pattern.division,ppq);
    const prefix=patternPrefix(pattern.id);
    const out=[];
    pattern.lanes.forEach((lane,laneIndex)=>lane.steps.forEach((step,stepIndex)=>{
      if(!step.on||step.probability<=0) return;
      if(step.probability<1&&seededUnit(`${pattern.id}:${pattern.seed}:${laneIndex}:${stepIndex}`)>=step.probability) return;
      const raw=pattern.startTick+stepIndex*grid;
      out.push({
        id:`${prefix}${laneIndex}-${stepIndex}`,
        pitch:lane.pitch,
        startTick:swingTick(raw,pattern.division,swing,ppq),
        durationTick:Math.max(1,Math.round(grid*step.lengthSteps)),
        velocity:step.velocity,
        channel:1
      });
    }));
    return out.sort((a,b)=>a.startTick-b.startTick||a.pitch-b.pitch);
  }

  function arpeggiate(notesInput,options={}){
    const notes=(Array.isArray(notesInput)?notesInput:[]).map(note=>({...note,pitch:Math.round(clamp(note.pitch,0,127))}));
    if(!notes.length) return [];
    const division=Object.hasOwn(DIVISIONS,String(options.division||''))?String(options.division):'1/16';
    const grid=divisionTicks(division,PPQ);
    const bars=Math.max(1,Math.min(8,Math.round(num(options.bars,1))));
    const octaves=Math.max(1,Math.min(4,Math.round(num(options.octaves,1))));
    const gate=clamp(options.gate??.8,.05,1);
    const mode=['up','down','updown','random'].includes(options.mode)?options.mode:'up';
    const base=[...new Set(notes.map(note=>note.pitch))].sort((a,b)=>a-b);
    let pool=[];
    for(let octave=0;octave<octaves;octave+=1){
      base.forEach(pitch=>{if(pitch+octave*12<=127)pool.push(pitch+octave*12);});
    }
    if(mode==='down') pool=pool.reverse();
    if(mode==='updown'&&pool.length>1) pool=pool.concat(pool.slice(1,-1).reverse());
    const count=Math.round(bars*PPQ*4/grid);
    const start=Math.min(...notes.map(note=>num(note.startTick,0)));
    const velocity=notes.reduce((sum,note)=>sum+num(note.velocity,.8),0)/notes.length;
    const out=[];
    for(let index=0;index<count;index+=1){
      const pitch=mode==='random'
        ?pool[Math.min(pool.length-1,Math.floor(seededUnit(`${options.seed||1}:${index}`)*pool.length))]
        :pool[index%pool.length];
      out.push({
        id:makeId('arp'),pitch,
        startTick:Math.round(start+index*grid),
        durationTick:Math.max(1,Math.round(grid*gate)),
        velocity:clamp(velocity,.01,1),channel:1
      });
    }
    return out;
  }

  function install(root,document){
    if(root.__STONEFELLOW_STEM_MIDI_V218_INSTALLED__) return false;
    root.__STONEFELLOW_STEM_MIDI_V218_INSTALLED__=true;

    const midiCfg=root.STONEFELLOW_STEM_MIDI_V217||{};
    const studioCfg=root.STONEFELLOW_STEM_STUDIO||{};
    const localKey=`stonefellow:stem:midi:v218:${Number(studioCfg.userId||0)}:${Number(studioCfg.trackId||0)}`;
    let attempts=0,composition=defaultComposition(),panel=null,openButton=null,saveTimer=0;
    let nextFetch=null,fetchWrapper=null,selectedNotes=new Set(),selectedPatternId='',selectedCell=null,observer=null;
    let scaleLockApplying=false,ccAccess=null,ccInputs=new Map();

    const runtime=()=>root.StonefellowStemMidiV217Runtime||null;
    const studio=()=>root.StonefellowStemStudioV91||null;
    const core=()=>root.STONEFELLOW_STUDIO_RUNTIME_V87||null;
    const agent=()=>{try{return studio()?.getAgentState?.()||{};}catch(error){return{};}};
    const state=()=>runtime()?.getState?.()||{tracks:[],selectedTrackId:'',selectedClipId:''};
    const selectedTrack=()=>{const current=state();return current.tracks.find(row=>row.id===current.selectedTrackId)||current.tracks[0]||null;};
    const selectedClip=()=>{const current=state(),track=selectedTrack();return track?.clips.find(row=>row.id===current.selectedClipId)||track?.clips[0]||null;};
    const currentTick=()=>Math.max(0,(num(core()?.getPosition?.(),0)*Math.max(20,num(agent().tempo||studioCfg.sourceTempo,120))*PPQ/60)-num(selectedClip()?.startTick,0));

    function readLocal(){
      try{return normalizeComposition(JSON.parse(root.localStorage?.getItem(localKey)||'null'));}
      catch(error){return defaultComposition();}
    }

    function writeLocal(){try{root.localStorage?.setItem(localKey,JSON.stringify(composition));}catch(error){}}
    function setStatus(text,error=false){const node=panel?.querySelector('[data-v218-status]');if(node){node.textContent=String(text||'');node.classList.toggle('error',Boolean(error));}}
    function announce(){root.dispatchEvent(new CustomEvent('stonefellow:stem-midi-v218-change',{detail:{build:BUILD,composition:clone(composition)}}));}

    function touchComposition(render=true){
      composition.updatedAt=Date.now();
      writeLocal();
      announce();
      scheduleSave();
      if(render) renderPanel();
      applyScaleClasses();
    }

    function scheduleSave(){
      root.clearTimeout(saveTimer);
      saveTimer=root.setTimeout(()=>void runtime()?.save?.(),SAVE_DELAY);
    }

    function sameUrl(input,target){
      try{return new URL(typeof input==='string'?input:input?.url||'',root.location.href).href===new URL(String(target||''),root.location.href).href;}
      catch(error){return false;}
    }

    function formAction(init){
      const body=init?.body;
      return body&&typeof body.get==='function'?String(body.get('action')||''):'';
    }

    function mixAction(input,init){
      if(!studioCfg.mixEndpoint||typeof init?.body!=='string'||!sameUrl(input,studioCfg.mixEndpoint)) return null;
      try{return JSON.parse(init.body);}catch(error){return null;}
    }

    function injectComposition(form){
      if(!form||typeof form.get!=='function'||typeof form.set!=='function') return;
      const raw=String(form.get('state_json')||'');
      if(!raw) return;
      try{
        const data=JSON.parse(raw);
        if(data&&typeof data==='object'){
          data.compositionV218=clone(composition);
          form.set('state_json',JSON.stringify(data));
        }
      }catch(error){}
    }

    async function midiRequest(action,extra={}){
      const form=new root.FormData();
      form.append('csrf_token',String(studioCfg.csrf||''));
      form.append('action',action);
      form.append('track_id',String(studioCfg.trackId||0));
      Object.entries(extra).forEach(([key,value])=>form.append(key,String(value)));
      const response=await nextFetch(String(midiCfg.endpoint),{method:'POST',credentials:'same-origin',body:form});
      const data=await response.json().catch(()=>null);
      if(!response.ok||!data?.ok) throw new Error(data?.error||'MIDI composition request failed.');
      return data;
    }

    async function attachFullSnapshot(mixId,beforeState){
      const full={...(beforeState||runtime()?.getState?.()||{}),compositionV218:clone(composition)};
      let lastError=null;
      for(let attempt=1;attempt<=3;attempt+=1){
        try{return await midiRequest('snapshot_attach',{mix_id:mixId,state_json:JSON.stringify(full)});}
        catch(error){lastError=error;if(attempt<3)await new Promise(resolve=>root.setTimeout(resolve,120*attempt));}
      }
      throw lastError||new Error('MIDI composition snapshot failed.');
    }

    function failureResponse(message,partial=false){
      return new root.Response(JSON.stringify({ok:false,error:String(message||'MIDI composition session failed.'),partial_save:Boolean(partial)}),{
        status:503,
        headers:{'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store'}
      });
    }

    async function bridgedFetch(input,init){
      const midiHit=sameUrl(input,midiCfg.endpoint);
      const action=midiHit?formAction(init):'';
      if(midiHit&&(action==='save'||action==='snapshot_attach')) injectComposition(init.body);

      const mix=mixAction(input,init);
      const beforeMidi=mix?.action==='load'||mix?.action==='save'?runtime()?.getState?.():null;
      const beforeComp=mix?.action==='load'?clone(composition):null;
      const response=await nextFetch(input,init);

      if(midiHit&&response?.ok&&(action==='load'||action==='snapshot_load')){
        try{
          const data=await response.clone().json();
          const incoming=data?.state?.compositionV218;
          if(incoming){
            const server=normalizeComposition(incoming);
            const local=readLocal();
            composition=local.updatedAt>server.updatedAt?local:server;
            writeLocal();
            renderPanel();
            applyScaleClasses();
          }
        }catch(error){console.warn('Stem MIDI v218 load:',error);}
      }

      if(!mix||!response?.ok) return response;

      if(mix.action==='save'){
        try{
          const data=await response.clone().json();
          const mixId=Number(data?.mix_id||0);
          if(mixId>0) await attachFullSnapshot(mixId,beforeMidi);
          setStatus('COMPOSITION SNAPSHOT SAVED');
          return response;
        }catch(error){
          setStatus('COMPOSITION SNAPSHOT FAILED',true);
          return failureResponse(`Mix audio state saved, but MIDI composition snapshot failed: ${error.message||error}`,true);
        }
      }

      if(mix.action==='load'){
        try{
          const mixId=Number(mix.mix_id||0);
          if(mixId>0){
            const data=await midiRequest('snapshot_load',{mix_id:mixId});
            composition=normalizeComposition(data?.state?.compositionV218||defaultComposition());
            writeLocal();
            renderPanel();
            applyScaleClasses();
          }
          return response;
        }catch(error){
          if(beforeMidi) runtime()?.restoreState?.(beforeMidi,{save:false});
          composition=beforeComp||defaultComposition();
          writeLocal();
          renderPanel();
          setStatus('COMPOSITION RESTORE FAILED',true);
          return failureResponse(`Mix recall stopped because MIDI composition could not be restored: ${error.message||error}`,false);
        }
      }

      return response;
    }

    function applyMidiState(next){
      runtime()?.restoreState?.(next,{save:true});
      root.setTimeout(()=>{applySelectionClasses();applyScaleClasses();},40);
    }

    function getClipInState(current,trackId,clipId){
      const track=current.tracks.find(row=>row.id===trackId);
      return {track,clip:track?.clips.find(row=>row.id===clipId)};
    }

    function extendClip(clip,endTick){
      if(!clip) return;
      const end=Math.max(0,Math.round(num(endTick,0)));
      if(end>clip.lengthTick) clip.lengthTick=Math.ceil(end/(PPQ/4))*(PPQ/4);
    }

    function currentPattern(){return composition.patterns.find(row=>row.id===selectedPatternId)||composition.patterns[0]||null;}

    function ensurePattern(){
      let pattern=currentPattern();
      if(pattern) return pattern;
      const track=selectedTrack(),clip=selectedClip();
      if(!track||!clip) return null;
      pattern=defaultPattern(track.id,clip.id,16);
      pattern.startTick=Math.max(0,Math.round(currentTick()/divisionTicks(pattern.division))*divisionTicks(pattern.division));
      composition.patterns.push(pattern);
      selectedPatternId=pattern.id;
      touchComposition();
      return pattern;
    }

    function syncPatternIntoState(current,patternInput){
      const pattern=patternInput||currentPattern();
      if(!pattern) return false;
      const target=getClipInState(current,pattern.trackId,pattern.clipId);
      if(!target.clip) return false;
      const prefix=patternPrefix(pattern.id);
      const generated=patternToNotes(pattern,{ppq:PPQ,swing:composition.swing});
      target.clip.notes=(target.clip.notes||[])
        .filter(note=>!String(note.id||'').startsWith(prefix))
        .concat(generated)
        .sort((a,b)=>a.startTick-b.startTick||a.pitch-b.pitch);
      const last=generated.reduce((max,note)=>Math.max(max,note.startTick+note.durationTick),0);
      extendClip(target.clip,last);
      return true;
    }

    function syncPattern(patternInput){
      const current=state();
      if(!syncPatternIntoState(current,patternInput)) return false;
      applyMidiState(current);
      return true;
    }

    function syncAllPatterns(){
      const current=state();
      let changed=false;
      composition.patterns.forEach(pattern=>{if(syncPatternIntoState(current,pattern)) changed=true;});
      if(changed) applyMidiState(current);
      return changed;
    }

    function convertPattern(){
      const pattern=currentPattern();
      if(!pattern) return;
      const current=state();
      const target=getClipInState(current,pattern.trackId,pattern.clipId);
      const prefix=patternPrefix(pattern.id);
      if(!target.clip) return;
      target.clip.notes=(target.clip.notes||[]).map(note=>String(note.id||'').startsWith(prefix)?{...note,id:makeId('note')}:note);
      composition.patterns=composition.patterns.filter(row=>row.id!==pattern.id);
      selectedPatternId=composition.patterns[0]?.id||'';
      composition.updatedAt=Date.now();
      writeLocal();
      applyMidiState(current);
      scheduleSave();
      renderPanel();
    }

    function duplicatePattern(){
      const source=currentPattern();
      if(!source) return;
      const copy=normalizePattern({...clone(source),id:makeId('pattern'),name:`${source.name} Copy`,seed:source.seed+1},composition.patterns.length);
      copy.startTick=source.startTick+Math.round(source.steps*divisionTicks(source.division));
      composition.patterns.push(copy);
      selectedPatternId=copy.id;
      touchComposition();
      syncPattern(copy);
    }

    function selectedNotesData(){
      const clip=selectedClip();
      if(!clip) return [];
      return (clip.notes||[]).filter(note=>selectedNotes.has(note.id));
    }

    function mutateSelected(mutator){
      const current=state();
      const track=current.tracks.find(row=>row.id===current.selectedTrackId)||current.tracks[0];
      const clip=track?.clips.find(row=>row.id===current.selectedClipId)||track?.clips[0];
      if(!clip||!selectedNotes.size) return false;
      clip.notes=clip.notes.map(note=>selectedNotes.has(note.id)?mutator({...note}):note).filter(Boolean).sort((a,b)=>a.startTick-b.startTick||a.pitch-b.pitch);
      if(clip.notes.length){
        const last=clip.notes.reduce((max,note)=>Math.max(max,note.startTick+note.durationTick),0);
        extendClip(clip,last);
      }
      applyMidiState(current);
      return true;
    }

    function selectAllNotes(){
      const clip=selectedClip();
      selectedNotes=new Set((clip?.notes||[]).map(note=>note.id));
      applySelectionClasses();
      renderPanel();
    }

    let noteClipboard=[];

    function copySelected(){
      const notes=selectedNotesData();
      if(!notes.length) return;
      const min=Math.min(...notes.map(note=>note.startTick));
      noteClipboard=notes.map(note=>({...clone(note),startTick:note.startTick-min}));
      setStatus(`${noteClipboard.length} NOTE${noteClipboard.length===1?'':'S'} COPIED`);
    }

    function pasteNotes(){
      if(!noteClipboard.length) return;
      const current=state();
      const track=current.tracks.find(row=>row.id===current.selectedTrackId)||current.tracks[0];
      const clip=track?.clips.find(row=>row.id===current.selectedClipId)||track?.clips[0];
      if(!clip) return;
      const start=Math.round(currentTick());
      const scale=composition.scale;
      const added=noteClipboard.map(note=>{
        let pitch=note.pitch;
        if(scale.lock&&track.instrument?.type!=='drum') pitch=nearestScalePitch(pitch,scale.root,scale.mode);
        return {...clone(note),id:makeId('note'),pitch,startTick:Math.max(0,start+note.startTick)};
      });
      clip.notes=(clip.notes||[]).concat(added).sort((a,b)=>a.startTick-b.startTick||a.pitch-b.pitch);
      extendClip(clip,added.reduce((max,note)=>Math.max(max,note.startTick+note.durationTick),0));
      selectedNotes=new Set(added.map(note=>note.id));
      applyMidiState(current);
      setStatus(`${added.length} NOTES PASTED`);
    }

    function duplicateSelected(){
      const notes=selectedNotesData();
      if(!notes.length) return;
      const current=state();
      const track=current.tracks.find(row=>row.id===current.selectedTrackId)||current.tracks[0];
      const clip=track?.clips.find(row=>row.id===current.selectedClipId)||track?.clips[0];
      if(!clip) return;
      const min=Math.min(...notes.map(note=>note.startTick));
      const max=Math.max(...notes.map(note=>note.startTick+note.durationTick));
      const offset=Math.max(divisionTicks('1/16'),Math.ceil((max-min)/divisionTicks('1/16'))*divisionTicks('1/16'));
      const added=notes.map(note=>({...clone(note),id:makeId('note'),startTick:note.startTick+offset}));
      clip.notes=(clip.notes||[]).concat(added).sort((a,b)=>a.startTick-b.startTick||a.pitch-b.pitch);
      extendClip(clip,added.reduce((end,note)=>Math.max(end,note.startTick+note.durationTick),0));
      selectedNotes=new Set(added.map(note=>note.id));
      applyMidiState(current);
      setStatus(`${added.length} NOTES DUPLICATED`);
    }

    function deleteSelected(){
      if(!selectedNotes.size) return;
      mutateSelected(()=>null);
      selectedNotes.clear();
      applySelectionClasses();
      renderPanel();
    }

    function quantizeSelected(division){
      const grid=divisionTicks(division);
      mutateSelected(note=>({...note,startTick:Math.max(0,Math.round(note.startTick/grid)*grid)}));
    }

    function setSelectedLength(division){
      const length=Math.max(1,Math.round(divisionTicks(division)));
      mutateSelected(note=>({...note,durationTick:length}));
    }

    function legatoSelected(){
      const notes=selectedNotesData().sort((a,b)=>a.startTick-b.startTick||a.pitch-b.pitch);
      if(!notes.length) return;
      const replacements=new Map();
      notes.forEach((note,index)=>{
        const next=notes[index+1];
        const duration=next?Math.max(1,next.startTick-note.startTick):Math.max(1,note.durationTick);
        replacements.set(note.id,{...note,durationTick:duration});
      });
      mutateSelected(note=>replacements.get(note.id)||note);
    }

    function snapSelectedToScale(){
      const scale=composition.scale;
      mutateSelected(note=>({...note,pitch:nearestScalePitch(note.pitch,scale.root,scale.mode)}));
    }

    function humanizeSelected(){
      const notes=selectedNotesData();
      if(!notes.length) return;
      const changed=humanizeNotes(notes,composition.humanize.timingTicks,composition.humanize.velocityPercent,Date.now());
      const replacements=new Map(changed.map(note=>[note.id,note]));
      mutateSelected(note=>replacements.get(note.id)||note);
    }

    function addChord(){
      const current=state();
      const track=current.tracks.find(row=>row.id===current.selectedTrackId)||current.tracks[0];
      const clip=track?.clips.find(row=>row.id===current.selectedClipId)||track?.clips[0];
      if(!clip) return;
      const rootPitch=Number(panel?.querySelector('[data-v218-chord-root]')?.value||60);
      const quality=panel?.querySelector('[data-v218-chord-quality]')?.value||'major';
      const inversion=Number(panel?.querySelector('[data-v218-inversion]')?.value||0);
      const start=Math.round(currentTick());
      let pitches=chordPitches(rootPitch,quality,inversion);
      if(composition.scale.lock&&track.instrument?.type!=='drum'){
        pitches=[...new Set(pitches.map(pitch=>nearestScalePitch(pitch,composition.scale.root,composition.scale.mode)))].sort((a,b)=>a-b);
      }
      const added=pitches.map(pitch=>({id:makeId('note'),pitch,startTick:start,durationTick:PPQ,velocity:.82,channel:1}));
      clip.notes=(clip.notes||[]).concat(added).sort((a,b)=>a.startTick-b.startTick||a.pitch-b.pitch);
      extendClip(clip,start+PPQ);
      selectedNotes=new Set(added.map(note=>note.id));
      applyMidiState(current);
    }

    function arpeggiateSelected(){
      const source=selectedNotesData();
      if(!source.length) return;
      const division=panel?.querySelector('[data-v218-arp-rate]')?.value||'1/16';
      const mode=panel?.querySelector('[data-v218-arp-mode]')?.value||'up';
      const octaves=Number(panel?.querySelector('[data-v218-arp-octaves]')?.value||1);
      const generated=arpeggiate(source,{division,mode,octaves,bars:1,gate:.8,seed:Date.now()});
      const current=state();
      const track=current.tracks.find(row=>row.id===current.selectedTrackId)||current.tracks[0];
      const clip=track?.clips.find(row=>row.id===current.selectedClipId)||track?.clips[0];
      if(!clip) return;
      const scale=composition.scale;
      if(scale.lock&&track.instrument?.type!=='drum'){
        generated.forEach(note=>{note.pitch=nearestScalePitch(note.pitch,scale.root,scale.mode);});
      }
      clip.notes=(clip.notes||[]).filter(note=>!selectedNotes.has(note.id)).concat(generated).sort((a,b)=>a.startTick-b.startTick||a.pitch-b.pitch);
      extendClip(clip,generated.reduce((max,note)=>Math.max(max,note.startTick+note.durationTick),0));
      selectedNotes=new Set(generated.map(note=>note.id));
      applyMidiState(current);
    }

    function enforceScaleLock(){
      if(scaleLockApplying||!composition.scale.lock) return false;
      const current=state();
      const track=current.tracks.find(row=>row.id===current.selectedTrackId)||current.tracks[0];
      const clip=track?.clips.find(row=>row.id===current.selectedClipId)||track?.clips[0];
      if(!track||!clip||track.instrument?.type==='drum') return false;
      let changed=false;
      const next=clone(current);
      const targetTrack=next.tracks.find(row=>row.id===track.id);
      const targetClip=targetTrack?.clips.find(row=>row.id===clip.id);
      if(!targetClip) return false;
      targetClip.notes=targetClip.notes.map(note=>{
        const pitch=nearestScalePitch(note.pitch,composition.scale.root,composition.scale.mode);
        if(pitch!==note.pitch) changed=true;
        return pitch===note.pitch?note:{...note,pitch};
      });
      if(!changed) return false;
      scaleLockApplying=true;
      applyMidiState(next);
      root.setTimeout(()=>{scaleLockApplying=false;},60);
      return true;
    }

    function currentCcLane(create=false){
      const track=selectedTrack(),clip=selectedClip();
      const controller=String(panel?.querySelector('[data-v218-cc-controller]')?.value||'1');
      if(!track||!clip) return null;
      let lane=composition.ccLanes.find(row=>row.trackId===track.id&&row.clipId===clip.id&&row.controller===controller);
      if(!lane&&create){
        lane={id:makeId('cc'),trackId:track.id,clipId:clip.id,controller,points:[]};
        composition.ccLanes.push(lane);
      }
      return lane||null;
    }

    function addCcPoint(event){
      const graph=event.currentTarget;
      const rect=graph.getBoundingClientRect();
      const clip=selectedClip();
      if(!clip) return;
      const lane=currentCcLane(true);
      const max=lane.controller==='pitch'?16383:127;
      const tick=Math.max(0,Math.round((event.clientX-rect.left)/Math.max(1,rect.width)*clip.lengthTick));
      const value=Math.round(clamp(1-(event.clientY-rect.top)/Math.max(1,rect.height),0,1)*max);
      lane.points.push({tick,value});
      lane.points.sort((a,b)=>a.tick-b.tick);
      touchComposition();
    }

    function recordHardwareCcData(dataInput){
      const data=Array.from(dataInput||[]);
      if(data.length<3||!Boolean(agent().playing)) return;
      const status=data[0]&0xf0;
      if(status!==0xb0) return;
      const controller=String(data[1]);
      const value=data[2];
      const current=state();
      const track=current.tracks.find(row=>row.armed)||selectedTrack();
      const clip=track?.clips.find(row=>row.id===current.selectedClipId)||track?.clips[0];
      if(!track||!clip) return;
      let lane=composition.ccLanes.find(row=>row.trackId===track.id&&row.clipId===clip.id&&row.controller===controller);
      if(!lane){
        lane={id:makeId('cc'),trackId:track.id,clipId:clip.id,controller,points:[]};
        composition.ccLanes.push(lane);
      }
      lane.points.push({tick:Math.max(0,Math.round(currentTick())),value});
      if(lane.points.length>2048) lane.points=lane.points.slice(-2048);
      composition.updatedAt=Date.now();
      writeLocal();
      scheduleSave();
      renderCcGraph();
    }

    function bindCcInputs(){
      if(!ccAccess) return;
      ccAccess.inputs.forEach(input=>{
        if(ccInputs.has(input.id)) return;
        const handler=event=>recordHardwareCcData(event.data);
        input.addEventListener('midimessage',handler);
        ccInputs.set(input.id,{input,handler});
      });
      Array.from(ccInputs.entries()).forEach(([id,row])=>{
        if(!ccAccess.inputs.has(id)){
          row.input.removeEventListener('midimessage',row.handler);
          ccInputs.delete(id);
        }
      });
      setStatus(`${ccAccess.inputs.size} CC INPUT${ccAccess.inputs.size===1?'':'S'} READY`);
    }

    async function enableCcCapture(){
      if(!root.navigator?.requestMIDIAccess){setStatus('WEB MIDI NOT SUPPORTED',true);return false;}
      try{
        ccAccess=await root.navigator.requestMIDIAccess({sysex:false});
        ccAccess.onstatechange=bindCcInputs;
        bindCcInputs();
        return true;
      }catch(error){setStatus('MIDI CC ACCESS DENIED',true);return false;}
    }

    function releaseCcInputs(){
      ccInputs.forEach(row=>row.input.removeEventListener('midimessage',row.handler));
      ccInputs.clear();
      if(ccAccess) ccAccess.onstatechange=null;
      ccAccess=null;
    }

    function applySelectionClasses(){
      document.querySelectorAll('.sf-midi-note[data-note-id]').forEach(node=>{
        node.classList.toggle('sf-v218-selected',selectedNotes.has(node.dataset.noteId));
      });
    }

    function applyScaleClasses(){
      const scale=composition.scale;
      const track=selectedTrack();
      const clip=selectedClip();
      document.querySelectorAll('.sf-midi-note[data-note-id]').forEach(node=>{
        const note=clip?.notes?.find(row=>row.id===node.dataset.noteId);
        const out=Boolean(note)&&track?.instrument?.type!=='drum'&&!pitchInScale(note.pitch,scale.root,scale.mode);
        node.classList.toggle('sf-v218-outscale',out);
      });
    }

    function bindRollSelection(){
      const roll=document.querySelector('[data-midi-roll]');
      if(!roll||roll.dataset.v218SelectionBound) return false;
      roll.dataset.v218SelectionBound='1';
      roll.addEventListener('pointerdown',event=>{
        const note=event.target.closest?.('.sf-midi-note[data-note-id]');
        if(note&&(event.shiftKey||event.ctrlKey||event.metaKey)){
          event.preventDefault();
          event.stopImmediatePropagation();
          const id=note.dataset.noteId;
          if(selectedNotes.has(id)) selectedNotes.delete(id); else selectedNotes.add(id);
          applySelectionClasses();
          renderPanel();
          return;
        }
        if(event.altKey&&!note){
          event.preventDefault();
          event.stopImmediatePropagation();
          const rect=roll.getBoundingClientRect();
          const startX=event.clientX,startY=event.clientY;
          const box=document.createElement('div');
          box.className='sf-v218-lasso';
          roll.appendChild(box);
          const move=pointer=>{
            const x1=Math.min(startX,pointer.clientX)-rect.left;
            const y1=Math.min(startY,pointer.clientY)-rect.top;
            const x2=Math.max(startX,pointer.clientX)-rect.left;
            const y2=Math.max(startY,pointer.clientY)-rect.top;
            box.style.left=`${x1}px`;box.style.top=`${y1}px`;box.style.width=`${x2-x1}px`;box.style.height=`${y2-y1}px`;
          };
          const up=()=>{
            const selectionRect=box.getBoundingClientRect();
            selectedNotes.clear();
            roll.querySelectorAll('.sf-midi-note[data-note-id]').forEach(node=>{
              const noteRect=node.getBoundingClientRect();
              if(noteRect.right>=selectionRect.left&&noteRect.left<=selectionRect.right&&noteRect.bottom>=selectionRect.top&&noteRect.top<=selectionRect.bottom){
                selectedNotes.add(node.dataset.noteId);
              }
            });
            box.remove();
            root.removeEventListener('pointermove',move);
            applySelectionClasses();
            renderPanel();
          };
          root.addEventListener('pointermove',move);
          root.addEventListener('pointerup',up,{once:true});
        }else if(note&&!event.shiftKey&&!event.ctrlKey&&!event.metaKey){
          selectedNotes=new Set([note.dataset.noteId]);
          root.setTimeout(()=>{applySelectionClasses();renderPanel();},0);
        }
      },true);
      return true;
    }

    function ensureOpenButton(){
      const host=document.querySelector('[data-midi-v217-toolbar]')||document.querySelector('.sf-midi-toolbar');
      if(!host) return false;
      if(host.querySelector('[data-v218-open]')){
        openButton=host.querySelector('[data-v218-open]');
        return true;
      }
      openButton=document.createElement('button');
      openButton.type='button';
      openButton.dataset.v218Open='1';
      openButton.textContent='COMPOSE';
      openButton.addEventListener('click',()=>{
        ensurePanel();
        panel.hidden=!panel.hidden;
        if(!panel.hidden) renderPanel();
      });
      host.insertBefore(openButton,host.querySelector('[data-midi-status]')||null);
      return true;
    }

    function ensurePanel(){
      if(panel) return panel;
      panel=document.createElement('section');
      panel.className='sf-v218-panel';
      panel.hidden=true;
      panel.innerHTML=`
        <header><div><span>MIDI v218</span><strong>COMPOSE + SEQUENCE</strong></div><div><span data-v218-status>READY</span><button type="button" data-v218-close>×</button></div></header>
        <nav><button type="button" data-v218-tab="step" class="active">STEP</button><button type="button" data-v218-tab="notes">NOTES</button><button type="button" data-v218-tab="harmony">HARMONY</button><button type="button" data-v218-tab="cc">CC</button></nav>
        <div class="sf-v218-pane active" data-v218-pane="step">
          <div class="sf-v218-row"><select data-v218-pattern></select><button type="button" data-v218-new-pattern>NEW</button><button type="button" data-v218-duplicate-pattern>DUP</button><button type="button" data-v218-convert-pattern>CONVERT TO MIDI</button><label>STEPS <select data-v218-steps><option>8</option><option selected>16</option><option>32</option><option>64</option></select></label><label>GRID <select data-v218-division><option>1/8</option><option selected>1/16</option><option>1/32</option><option>1/8T</option><option>1/16T</option></select></label><label>SWING <input type="range" min="0" max="75" step="1" data-v218-swing><span data-v218-swing-value></span></label><button type="button" data-v218-reroll>REROLL</button></div>
          <div class="sf-v218-sequencer" data-v218-sequencer></div>
          <div class="sf-v218-step-inspector"><strong>STEP</strong><span data-v218-cell-label>select a step</span><label>VEL <input type="range" min="1" max="127" data-v218-step-velocity></label><label>PROB <input type="range" min="0" max="100" data-v218-step-probability></label></div>
        </div>
        <div class="sf-v218-pane" data-v218-pane="notes">
          <div class="sf-v218-row"><button type="button" data-v218-select-all>SELECT ALL</button><button type="button" data-v218-copy>COPY</button><button type="button" data-v218-paste>PASTE</button><button type="button" data-v218-duplicate>DUPLICATE</button><button type="button" data-v218-delete>DELETE</button><span>Shift/Ctrl-click notes · Alt-drag empty roll for lasso</span></div>
          <div class="sf-v218-row"><label>QUANTIZE <select data-v218-note-grid><option>1/8</option><option selected>1/16</option><option>1/32</option><option>1/8T</option><option>1/16T</option></select></label><button type="button" data-v218-quantize-selected>QUANTIZE SELECTED</button><label>LENGTH <select data-v218-note-length><option>1/32</option><option selected>1/16</option><option>1/8</option><option>1/4</option></select></label><button type="button" data-v218-set-length>SET LENGTH</button><button type="button" data-v218-legato>LEGATO</button></div>
          <div class="sf-v218-row"><label>TIMING ± <input type="number" min="0" max="240" value="0" data-v218-human-time></label><label>VELOCITY ±% <input type="number" min="0" max="60" value="0" data-v218-human-velocity></label><button type="button" data-v218-humanize>HUMANIZE</button><span data-v218-selection-count>0 selected</span></div>
        </div>
        <div class="sf-v218-pane" data-v218-pane="harmony">
          <div class="sf-v218-row"><label>KEY <select data-v218-scale-root>${ROOT_NAMES.map((name,index)=>`<option value="${index}">${name}</option>`).join('')}</select></label><label>SCALE <select data-v218-scale-mode>${Object.keys(MODES).map(mode=>`<option value="${mode}">${mode.replace('_',' ')}</option>`).join('')}</select></label><label><input type="checkbox" data-v218-scale-lock> SCALE LOCK</label><button type="button" data-v218-snap-scale>SNAP SELECTED</button></div>
          <div class="sf-v218-row"><label>CHORD ROOT <select data-v218-chord-root>${Array.from({length:25},(_,index)=>{const pitch=48+index;return `<option value="${pitch}" ${pitch===60?'selected':''}>${ROOT_NAMES[pitch%12]}${Math.floor(pitch/12)-1}</option>`;}).join('')}</select></label><label>QUALITY <select data-v218-chord-quality><option value="major">Major</option><option value="minor">Minor</option><option value="maj7">Maj7</option><option value="min7">Min7</option><option value="dom7">Dom7</option><option value="sus2">Sus2</option><option value="sus4">Sus4</option></select></label><label>INV <select data-v218-inversion><option>0</option><option>1</option><option>2</option><option>3</option></select></label><button type="button" data-v218-add-chord>ADD CHORD</button></div>
          <div class="sf-v218-row"><label>ARP <select data-v218-arp-mode><option>up</option><option>down</option><option>updown</option><option>random</option></select></label><label>RATE <select data-v218-arp-rate><option>1/8</option><option selected>1/16</option><option>1/32</option><option>1/8T</option><option>1/16T</option></select></label><label>OCT <select data-v218-arp-octaves><option>1</option><option>2</option><option>3</option><option>4</option></select></label><button type="button" data-v218-arpeggiate>ARPEGGIATE SELECTED</button></div>
        </div>
        <div class="sf-v218-pane" data-v218-pane="cc">
          <div class="sf-v218-row"><label>LANE <select data-v218-cc-controller><option value="1">Mod Wheel CC1</option><option value="64">Sustain CC64</option><option value="11">Expression CC11</option><option value="7">Volume CC7</option><option value="10">Pan CC10</option><option value="pitch">Pitch Bend</option></select></label><button type="button" data-v218-cc-input>CAPTURE MIDI CC</button><button type="button" data-v218-clear-cc>CLEAR LANE</button><span>Double-click graph to add points. Hardware CC capture stores controller moves while the Studio transport runs.</span></div>
          <div class="sf-v218-cc-graph" data-v218-cc-graph></div>
        </div>`;
      document.body.appendChild(panel);
      bindPanel();
      return panel;
    }

    function bindPanel(){
      panel.querySelector('[data-v218-close]').addEventListener('click',()=>panel.hidden=true);
      panel.querySelectorAll('[data-v218-tab]').forEach(button=>button.addEventListener('click',()=>{
        panel.querySelectorAll('[data-v218-tab]').forEach(item=>item.classList.toggle('active',item===button));
        panel.querySelectorAll('[data-v218-pane]').forEach(pane=>pane.classList.toggle('active',pane.dataset.v218Pane===button.dataset.v218Tab));
        if(button.dataset.v218Tab==='cc') renderCcGraph();
      }));

      panel.querySelector('[data-v218-new-pattern]').addEventListener('click',()=>{
        const track=selectedTrack(),clip=selectedClip();
        if(!track||!clip) return;
        const pattern=defaultPattern(track.id,clip.id,Number(panel.querySelector('[data-v218-steps]').value));
        pattern.name=`Pattern ${composition.patterns.length+1}`;
        pattern.startTick=Math.max(0,Math.round(currentTick()/divisionTicks(pattern.division))*divisionTicks(pattern.division));
        composition.patterns.push(pattern);
        selectedPatternId=pattern.id;
        touchComposition();
        syncPattern(pattern);
      });

      panel.querySelector('[data-v218-duplicate-pattern]').addEventListener('click',duplicatePattern);
      panel.querySelector('[data-v218-convert-pattern]').addEventListener('click',convertPattern);
      panel.querySelector('[data-v218-pattern]').addEventListener('change',event=>{selectedPatternId=event.target.value;selectedCell=null;renderPanel();});
      panel.querySelector('[data-v218-steps]').addEventListener('change',event=>{
        const pattern=currentPattern();
        if(!pattern) return;
        const steps=Number(event.target.value);
        pattern.steps=steps;
        pattern.lanes.forEach(lane=>{lane.steps=Array.from({length:steps},(_,index)=>normalizeStep(lane.steps[index]));});
        touchComposition();
        syncPattern(pattern);
      });
      panel.querySelector('[data-v218-division]').addEventListener('change',event=>{
        const pattern=currentPattern();
        if(!pattern) return;
        pattern.division=event.target.value;
        touchComposition();
        syncPattern(pattern);
      });
      panel.querySelector('[data-v218-swing]').addEventListener('input',event=>{
        composition.swing=clamp(event.target.value,0,75);
        composition.updatedAt=Date.now();
        writeLocal();
        syncAllPatterns();
        scheduleSave();
        const label=panel.querySelector('[data-v218-swing-value]');
        if(label) label.textContent=`${Math.round(composition.swing)}%`;
      });
      panel.querySelector('[data-v218-swing]').addEventListener('change',renderPanel);
      panel.querySelector('[data-v218-reroll]').addEventListener('click',()=>{
        const pattern=currentPattern();
        if(!pattern) return;
        pattern.seed+=1;
        touchComposition();
        syncPattern(pattern);
      });

      const stepVelocity=panel.querySelector('[data-v218-step-velocity]');
      const stepProbability=panel.querySelector('[data-v218-step-probability]');
      stepVelocity.addEventListener('input',event=>{
        const pattern=currentPattern();
        if(!pattern||!selectedCell) return;
        const step=pattern.lanes[selectedCell.lane]?.steps[selectedCell.step];
        if(!step) return;
        step.velocity=clamp(Number(event.target.value)/127,.01,1);
        touchComposition(false);
        syncPattern(pattern);
      });
      stepVelocity.addEventListener('change',renderPanel);
      stepProbability.addEventListener('input',event=>{
        const pattern=currentPattern();
        if(!pattern||!selectedCell) return;
        const step=pattern.lanes[selectedCell.lane]?.steps[selectedCell.step];
        if(!step) return;
        step.probability=clamp(Number(event.target.value)/100,0,1);
        touchComposition(false);
        syncPattern(pattern);
      });
      stepProbability.addEventListener('change',renderPanel);

      panel.querySelector('[data-v218-select-all]').addEventListener('click',selectAllNotes);
      panel.querySelector('[data-v218-copy]').addEventListener('click',copySelected);
      panel.querySelector('[data-v218-paste]').addEventListener('click',pasteNotes);
      panel.querySelector('[data-v218-duplicate]').addEventListener('click',duplicateSelected);
      panel.querySelector('[data-v218-delete]').addEventListener('click',deleteSelected);
      panel.querySelector('[data-v218-quantize-selected]').addEventListener('click',()=>quantizeSelected(panel.querySelector('[data-v218-note-grid]').value));
      panel.querySelector('[data-v218-set-length]').addEventListener('click',()=>setSelectedLength(panel.querySelector('[data-v218-note-length]').value));
      panel.querySelector('[data-v218-legato]').addEventListener('click',legatoSelected);
      panel.querySelector('[data-v218-humanize]').addEventListener('click',()=>{
        composition.humanize.timingTicks=clamp(panel.querySelector('[data-v218-human-time]').value,0,240);
        composition.humanize.velocityPercent=clamp(panel.querySelector('[data-v218-human-velocity]').value,0,60);
        touchComposition();
        humanizeSelected();
      });

      panel.querySelector('[data-v218-scale-root]').addEventListener('change',event=>{
        composition.scale.root=Number(event.target.value);
        touchComposition();
        enforceScaleLock();
      });
      panel.querySelector('[data-v218-scale-mode]').addEventListener('change',event=>{
        composition.scale.mode=event.target.value;
        touchComposition();
        enforceScaleLock();
      });
      panel.querySelector('[data-v218-scale-lock]').addEventListener('change',event=>{
        composition.scale.lock=event.target.checked;
        touchComposition();
        enforceScaleLock();
      });
      panel.querySelector('[data-v218-snap-scale]').addEventListener('click',snapSelectedToScale);
      panel.querySelector('[data-v218-add-chord]').addEventListener('click',addChord);
      panel.querySelector('[data-v218-arpeggiate]').addEventListener('click',arpeggiateSelected);

      panel.querySelector('[data-v218-cc-controller]').addEventListener('change',renderCcGraph);
      panel.querySelector('[data-v218-cc-graph]').addEventListener('dblclick',addCcPoint);
      panel.querySelector('[data-v218-cc-input]').addEventListener('click',()=>void enableCcCapture());
      panel.querySelector('[data-v218-clear-cc]').addEventListener('click',()=>{
        const lane=currentCcLane(false);
        if(lane){lane.points=[];touchComposition();}
      });

      panel.addEventListener('keydown',event=>{
        if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='c'){
          event.preventDefault();
          copySelected();
        }
        if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='v'){
          event.preventDefault();
          pasteNotes();
        }
      });
    }

    function renderSequencer(){
      const host=panel?.querySelector('[data-v218-sequencer]');
      const pattern=currentPattern();
      if(!host) return;
      if(!pattern){
        host.innerHTML='<div class="sf-v218-empty">Create a pattern on the selected MIDI clip.</div>';
        return;
      }
      host.innerHTML='';
      pattern.lanes.forEach((lane,laneIndex)=>{
        const row=document.createElement('div');
        row.className='sf-v218-seq-row';
        row.style.gridTemplateColumns=`132px repeat(${pattern.steps}, minmax(24px,1fr))`;
        const label=document.createElement('button');
        label.type='button';
        label.className='sf-v218-lane-label';
        label.textContent=`${lane.name} · ${lane.pitch}`;
        row.appendChild(label);
        lane.steps.forEach((step,stepIndex)=>{
          const button=document.createElement('button');
          button.type='button';
          button.className='sf-v218-step';
          button.classList.toggle('on',step.on);
          button.classList.toggle('beat',stepIndex%4===0);
          button.style.opacity=step.on?String(.55+step.velocity*.45):'';
          button.title=`Step ${stepIndex+1} · Vel ${Math.round(step.velocity*127)} · Prob ${Math.round(step.probability*100)}%`;
          button.addEventListener('click',()=>{
            selectedCell={lane:laneIndex,step:stepIndex};
            step.on=!step.on;
            touchComposition();
            syncPattern(pattern);
          });
          button.addEventListener('contextmenu',event=>{
            event.preventDefault();
            selectedCell={lane:laneIndex,step:stepIndex};
            step.velocity=step.velocity>.85?.55:Math.min(1,step.velocity+.15);
            step.on=true;
            touchComposition();
            syncPattern(pattern);
          });
          row.appendChild(button);
        });
        host.appendChild(row);
      });
    }

    function renderCcGraph(){
      const graph=panel?.querySelector('[data-v218-cc-graph]');
      if(!graph) return;
      const lane=currentCcLane(false),clip=selectedClip();
      graph.innerHTML='';
      if(!clip) return;
      (lane?.points||[]).forEach((point,index)=>{
        const dot=document.createElement('button');
        dot.type='button';
        dot.className='sf-v218-cc-point';
        const max=lane.controller==='pitch'?16383:127;
        dot.style.left=`${clamp(point.tick/Math.max(1,clip.lengthTick),0,1)*100}%`;
        dot.style.bottom=`${clamp(point.value/max,0,1)*100}%`;
        dot.title=`${point.tick} ticks · ${Math.round(point.value)}`;
        dot.addEventListener('click',event=>{
          event.stopPropagation();
          lane.points.splice(index,1);
          touchComposition();
        });
        graph.appendChild(dot);
      });
    }

    function renderPanel(){
      if(!panel) return;
      if(!selectedPatternId&&composition.patterns[0]) selectedPatternId=composition.patterns[0].id;
      const pattern=currentPattern();
      const select=panel.querySelector('[data-v218-pattern]');
      select.innerHTML='';
      composition.patterns.forEach(item=>{
        const option=document.createElement('option');
        option.value=item.id;
        option.textContent=item.name;
        if(item.id===selectedPatternId) option.selected=true;
        select.appendChild(option);
      });
      panel.querySelector('[data-v218-steps]').value=String(pattern?.steps||16);
      panel.querySelector('[data-v218-division]').value=pattern?.division||'1/16';
      panel.querySelector('[data-v218-swing]').value=String(composition.swing);
      panel.querySelector('[data-v218-swing-value]').textContent=`${Math.round(composition.swing)}%`;
      panel.querySelector('[data-v218-scale-root]').value=String(composition.scale.root);
      panel.querySelector('[data-v218-scale-mode]').value=composition.scale.mode;
      panel.querySelector('[data-v218-scale-lock]').checked=composition.scale.lock;
      panel.querySelector('[data-v218-human-time]').value=String(composition.humanize.timingTicks);
      panel.querySelector('[data-v218-human-velocity]').value=String(composition.humanize.velocityPercent);
      panel.querySelector('[data-v218-selection-count]').textContent=`${selectedNotes.size} selected`;
      const cell=selectedCell&&pattern?.lanes[selectedCell.lane]?.steps[selectedCell.step];
      panel.querySelector('[data-v218-cell-label]').textContent=cell?`${pattern.lanes[selectedCell.lane].name} · ${selectedCell.step+1}`:'select a step';
      panel.querySelector('[data-v218-step-velocity]').value=String(Math.round((cell?.velocity||.82)*127));
      panel.querySelector('[data-v218-step-probability]').value=String(Math.round((cell?.probability??1)*100));
      renderSequencer();
      renderCcGraph();
      applySelectionClasses();
      applyScaleClasses();
    }

    async function loadComposition(){
      const local=readLocal();
      try{
        const data=await midiRequest('load');
        const server=normalizeComposition(data?.state?.compositionV218||defaultComposition());
        composition=local.updatedAt>server.updatedAt?local:server;
        if(local.updatedAt>server.updatedAt) scheduleSave();
      }catch(error){
        composition=local;
        setStatus('LOCAL COMPOSITION RECOVERY',true);
      }
      writeLocal();
      selectedPatternId=composition.patterns[0]?.id||'';
      renderPanel();
      applyScaleClasses();
      enforceScaleLock();
    }

    function bind(){
      if(!runtime()||!midiCfg.endpoint||!studioCfg.mixEndpoint||!ensureOpenButton()){
        attempts+=1;
        if(attempts<260) root.setTimeout(bind,60);
        else root.__STONEFELLOW_STEM_MIDI_V218_INSTALLED__=false;
        return;
      }
      ensurePanel();
      nextFetch=root.fetch.bind(root);
      fetchWrapper=bridgedFetch;
      root.fetch=fetchWrapper;
      bindRollSelection();
      observer=new MutationObserver(()=>{
        bindRollSelection();
        applySelectionClasses();
        applyScaleClasses();
      });
      const editor=document.querySelector('[data-midi-v217-editor]')||document.querySelector('.sf-midi-editor');
      if(editor) observer.observe(editor,{subtree:true,childList:true});
      root.addEventListener('stonefellow:stem-midi-v217-change',()=>root.setTimeout(()=>{
        bindRollSelection();
        if(!scaleLockApplying) enforceScaleLock();
        applySelectionClasses();
        applyScaleClasses();
        renderPanel();
      },20));
      void loadComposition();
      root.StonefellowStemMidiCompositionV218Runtime={
        build:BUILD,
        getComposition:()=>clone(composition),
        setComposition:value=>{composition=normalizeComposition(value);touchComposition();enforceScaleLock();},
        syncPattern,patternToNotes,selectAllNotes,copySelected,pasteNotes,duplicateSelected,
        quantizeSelected,setSelectedLength,legatoSelected,snapSelectedToScale,humanizeSelected,
        addChord,arpeggiateSelected,enableCcCapture,
        open:()=>{panel.hidden=false;renderPanel();}
      };
      root.dispatchEvent(new CustomEvent('stonefellow:stem-midi-v218',{detail:{build:BUILD}}));
    }

    root.addEventListener('pagehide',event=>{
      root.clearTimeout(saveTimer);
      writeLocal();
      if(event.persisted) return;
      observer?.disconnect();
      releaseCcInputs();
      if(fetchWrapper&&root.fetch===fetchWrapper) root.fetch=nextFetch;
    });
    root.addEventListener('pageshow',event=>{
      if(!event.persisted) return;
      if(fetchWrapper&&root.fetch!==fetchWrapper){
        nextFetch=root.fetch.bind(root);
        root.fetch=fetchWrapper;
      }
      bindRollSelection();
      applySelectionClasses();
      applyScaleClasses();
      renderPanel();
    });

    bind();
    return true;
  }

  return Object.freeze({
    build:BUILD,PPQ,MODES,DIVISIONS,divisionTicks,
    scalePitchClasses,pitchInScale,nearestScalePitch,
    chordIntervals,chordPitches,seededUnit,swingTick,humanizeNotes,
    defaultPattern,normalizePattern,normalizeComposition,patternPrefix,patternToNotes,arpeggiate,install
  });
});
