(function(root,factory){
  'use strict';
  const api=factory();
  root.StonefellowStemMidiV217=api;
  if(root.document) api.install(root,root.document);
})(typeof globalThis!=='undefined'?globalThis:window,function(){
  'use strict';

  const BUILD='stem-midi-foundation-v217-20260901';
  const PPQ=960;
  const FOUR_BARS=PPQ*16;
  const SAVE_DELAY=900;
  const LOOKAHEAD_MS=25;
  const SCHEDULE_AHEAD=.14;
  const PIANO_LOW=36;
  const PIANO_HIGH=84;
  const clone=value=>JSON.parse(JSON.stringify(value));
  const num=(value,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;
  const clamp=(value,min,max)=>Math.min(max,Math.max(min,num(value,min)));
  const id=prefix=>`${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,9)}`;

  function noteName(pitch){
    const names=['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
    const p=Math.max(0,Math.min(127,Math.round(num(pitch,60))));
    return `${names[p%12]}${Math.floor(p/12)-1}`;
  }

  function secondsToTicks(seconds,tempo,ppq=PPQ){
    return Math.max(0,num(seconds,0))*Math.max(1,num(tempo,120))*ppq/60;
  }

  function ticksToSeconds(ticks,tempo,ppq=PPQ){
    return Math.max(0,num(ticks,0))*60/(Math.max(1,num(tempo,120))*ppq);
  }

  function quantizeTick(tick,division='1/16',ppq=PPQ){
    const map={'1/1':ppq*4,'1/2':ppq*2,'1/4':ppq,'1/8':ppq/2,'1/16':ppq/4,'1/32':ppq/8,'1/8T':ppq/3,'1/16T':ppq/6};
    const grid=map[division]||ppq/4;
    return Math.max(0,Math.round(num(tick,0)/grid)*grid);
  }

  function midiFrequency(pitch){
    return 440*Math.pow(2,(clamp(Math.round(num(pitch,69)),0,127)-69)/12);
  }

  function emptyState(){
    return {version:1,ppq:PPQ,tracks:[],selectedTrackId:'',selectedClipId:'',updatedAt:0};
  }

  function defaultInstrument(type='poly'){
    return {
      type:type==='drum'?'drum':'poly',
      waveform:type==='drum'?'sine':'sawtooth',
      attack:.01,
      release:.18,
      gain:.65,
      octave:0
    };
  }

  function newClip(name='MIDI Clip',startTick=0,lengthTick=FOUR_BARS){
    return {
      id:id('clip'),
      name,
      startTick:Math.max(0,Math.round(num(startTick,0))),
      lengthTick:Math.max(1,Math.round(num(lengthTick,FOUR_BARS))),
      loop:false,
      notes:[]
    };
  }

  function newTrack(name='MIDI Track',type='poly'){
    const clip=newClip('Clip 1',0,FOUR_BARS);
    return {
      id:id('midi'),
      name,
      instrument:defaultInstrument(type),
      volume:.8,
      pan:0,
      muted:false,
      solo:false,
      armed:false,
      clips:[clip]
    };
  }

  function normalizeNote(note){
    return {
      id:String(note?.id||id('note')),
      pitch:Math.round(clamp(note?.pitch??60,0,127)),
      startTick:Math.max(0,Math.round(num(note?.startTick,0))),
      durationTick:Math.max(1,Math.round(num(note?.durationTick,PPQ/4))),
      velocity:clamp(note?.velocity??.8,.01,1),
      channel:Math.round(clamp(note?.channel??1,1,16))
    };
  }

  function normalizeClip(clip,index=0){
    return {
      id:String(clip?.id||id('clip')),
      name:String(clip?.name||`Clip ${index+1}`).slice(0,80),
      startTick:Math.max(0,Math.round(num(clip?.startTick,0))),
      lengthTick:Math.max(1,Math.round(num(clip?.lengthTick,FOUR_BARS))),
      loop:Boolean(clip?.loop),
      notes:(Array.isArray(clip?.notes)?clip.notes:[])
        .map(normalizeNote)
        .sort((a,b)=>a.startTick-b.startTick||a.pitch-b.pitch)
    };
  }

  function normalizeTrack(track,index=0){
    const inst={...defaultInstrument(track?.instrument?.type),...(track?.instrument||{})};
    inst.type=inst.type==='drum'?'drum':'poly';
    inst.waveform=['sine','triangle','square','sawtooth'].includes(inst.waveform)?inst.waveform:'sawtooth';
    inst.attack=clamp(inst.attack??.01,.001,2);
    inst.release=clamp(inst.release??.18,.01,5);
    inst.gain=clamp(inst.gain??.65,0,1);
    inst.octave=Math.round(clamp(inst.octave??0,-3,3));
    return {
      id:String(track?.id||id('midi')),
      name:String(track?.name||`MIDI ${index+1}`).slice(0,80),
      instrument:inst,
      volume:clamp(track?.volume??.8,0,1.5),
      pan:clamp(track?.pan??0,-1,1),
      muted:Boolean(track?.muted),
      solo:Boolean(track?.solo),
      armed:Boolean(track?.armed),
      clips:(Array.isArray(track?.clips)?track.clips:[]).map(normalizeClip)
    };
  }

  function normalizeState(value){
    const raw=value&&typeof value==='object'?value:{};
    return {
      version:1,
      ppq:PPQ,
      tracks:(Array.isArray(raw.tracks)?raw.tracks:[]).slice(0,64).map(normalizeTrack),
      selectedTrackId:String(raw.selectedTrackId||''),
      selectedClipId:String(raw.selectedClipId||''),
      updatedAt:Math.max(0,num(raw.updatedAt,0))
    };
  }

  function quantizeNotes(notes,division='1/16'){
    return (Array.isArray(notes)?notes:[]).map(note=>({
      ...normalizeNote(note),
      startTick:quantizeTick(note.startTick,division)
    }));
  }

  function transposeNotes(notes,semitones){
    return (Array.isArray(notes)?notes:[]).map(note=>({
      ...normalizeNote(note),
      pitch:Math.round(clamp(num(note.pitch,60)+num(semitones,0),0,127))
    }));
  }

  function install(root,document){
    if(root.__STONEFELLOW_STEM_MIDI_V217_INSTALLED__) return false;
    const cfg=root.STONEFELLOW_STEM_MIDI_V217||{};
    if(!cfg.enabled||!cfg.allowed||!cfg.endpoint) return false;
    root.__STONEFELLOW_STEM_MIDI_V217_INSTALLED__=true;

    const studioCfg=root.STONEFELLOW_STEM_STUDIO||{};
    const storageKey=`stonefellow:stem:midi:v217:${Number(studioCfg.userId||0)}:${Number(studioCfg.trackId||0)}`;
    let state=emptyState();
    let toolbar=null;
    let editor=null;
    let saveTimer=0;
    let saveBusy=false;
    let saveAgain=false;
    let loaded=false;
    let selectedNoteId='';
    let midiAccess=null;
    let midiRecording=false;
    let recordingNotes=new Map();
    let scheduleTimer=0;
    let lastPosition=-1;
    let lastPlaying=false;
    const audioTracks=new Map();
    const voices=new Map();
    const scheduled=new Set();

    const studio=()=>root.StonefellowStemStudioV91||null;
    const core=()=>root.STONEFELLOW_STUDIO_RUNTIME_V87||null;
    const agent=()=>{try{return studio()?.getAgentState?.()||{};}catch(error){return{};}};
    const tempo=()=>Math.max(20,num(agent().tempo||studioCfg.sourceTempo,120));
    const currentTick=()=>secondsToTicks(core()?.getPosition?.()||0,tempo());
    const selectedTrack=()=>state.tracks.find(row=>row.id===state.selectedTrackId)||state.tracks[0]||null;
    const selectedClip=()=>{
      const track=selectedTrack();
      return track?.clips.find(row=>row.id===state.selectedClipId)||track?.clips[0]||null;
    };
    const selectedNote=()=>selectedClip()?.notes.find(row=>row.id===selectedNoteId)||null;

    function csrfForm(action,extra={}){
      const form=new root.FormData();
      form.append('csrf_token',String(studioCfg.csrf||''));
      form.append('action',action);
      form.append('track_id',String(studioCfg.trackId||0));
      Object.entries(extra).forEach(([key,value])=>form.append(key,String(value)));
      return form;
    }

    async function request(action,extra={}){
      const response=await root.fetch(String(cfg.endpoint),{
        method:'POST',
        credentials:'same-origin',
        body:csrfForm(action,extra)
      });
      const data=await response.json().catch(()=>null);
      if(!response.ok||!data?.ok) throw new Error(data?.error||'MIDI request failed.');
      return data;
    }

    function persistLocal(){
      try{root.localStorage?.setItem(storageKey,JSON.stringify(state));}catch(error){}
    }

    function announce(){
      root.dispatchEvent(new CustomEvent('stonefellow:stem-midi-v217-change',{
        detail:{build:BUILD,state:clone(state)}
      }));
    }

    function markChanged(render=true){
      state.updatedAt=Date.now();
      persistLocal();
      announce();
      if(render) renderAll();
      scheduleSave();
    }

    function scheduleSave(delay=SAVE_DELAY){
      root.clearTimeout(saveTimer);
      saveTimer=root.setTimeout(()=>void saveNow(),delay);
    }

    async function saveNow(){
      if(!loaded) return false;
      if(saveBusy){saveAgain=true;return false;}
      saveBusy=true;
      setStatus('SAVING…');
      try{
        await request('save',{state_json:JSON.stringify(state)});
        setStatus('MIDI SAVED');
        return true;
      }catch(error){
        setStatus(error.message||'MIDI SAVE FAILED',true);
        return false;
      }finally{
        saveBusy=false;
        if(saveAgain){saveAgain=false;scheduleSave(120);}
      }
    }

    function resolveSelection(){
      if(state.selectedTrackId&&!state.tracks.some(track=>track.id===state.selectedTrackId)) state.selectedTrackId='';
      if(!state.selectedTrackId&&state.tracks[0]) state.selectedTrackId=state.tracks[0].id;
      const track=selectedTrack();
      if(state.selectedClipId&&!track?.clips.some(clip=>clip.id===state.selectedClipId)) state.selectedClipId='';
      if(!state.selectedClipId&&track?.clips[0]) state.selectedClipId=track.clips[0].id;
    }

    async function load(){
      let local=null;
      let useLocal=false;
      try{local=normalizeState(JSON.parse(root.localStorage?.getItem(storageKey)||'null'));}catch(error){}
      try{
        const data=await request('load');
        state=normalizeState(data.state);
        if(local?.updatedAt>state.updatedAt&&local.tracks.length){state=local;useLocal=true;}
      }catch(error){
        if(local?.tracks.length){state=local;useLocal=true;}
        else throw error;
      }
      resolveSelection();
      loaded=true;
      renderAll();
      persistLocal();
      if(useLocal) scheduleSave(180);
      return state;
    }

    function restoreState(value,options={}){
      stopScheduledVoices();
      recordingNotes.clear();
      state=normalizeState(value);
      resolveSelection();
      selectedNoteId='';
      state.updatedAt=Date.now();
      persistLocal();
      announce();
      renderAll();
      if(options.save!==false) scheduleSave(150);
      return clone(state);
    }

    function audioContext(){
      const rt=core();
      rt?.ensureAudioGraph?.();
      return rt?.getContext?.()||null;
    }

    function midiDestination(){
      return core()?.getMasterInput?.()||core()?.getMasterSource?.()||audioContext()?.destination||null;
    }

    function destroyAudioTrack(trackId){
      const row=audioTracks.get(trackId);
      if(!row) return;
      try{row.gain.disconnect();}catch(error){}
      try{row.pan.disconnect();}catch(error){}
      audioTracks.delete(trackId);
    }

    function ensureAudioTrack(track){
      const ctx=audioContext();
      if(!ctx) return null;
      let row=audioTracks.get(track.id);
      if(row?.ctx===ctx) return row;
      destroyAudioTrack(track.id);
      const gain=ctx.createGain();
      const pan=typeof ctx.createStereoPanner==='function'?ctx.createStereoPanner():ctx.createGain();
      gain.connect(pan);
      pan.connect(midiDestination());
      row={ctx,gain,pan};
      audioTracks.set(track.id,row);
      return row;
    }

    function updateAudioTracks(){
      const anySolo=state.tracks.some(track=>track.solo);
      state.tracks.forEach(track=>{
        const row=ensureAudioTrack(track);
        if(!row) return;
        const audible=!track.muted&&(!anySolo||track.solo);
        row.gain.gain.value=audible?clamp(track.volume??.8,0,1.5):0;
        if(row.pan.pan) row.pan.pan.value=clamp(track.pan??0,-1,1);
      });
      Array.from(audioTracks.keys())
        .filter(key=>!state.tracks.some(track=>track.id===key))
        .forEach(destroyAudioTrack);
    }

    function voiceKey(trackId,pitch,token='live'){
      return `${trackId}:${pitch}:${token}`;
    }

    function startVoice(track,pitch,velocity=.8,when=null,token='live',duration=null){
      const row=ensureAudioTrack(track);
      const ctx=row?.ctx;
      if(!row||!ctx) return null;
      if(ctx.state==='suspended') void ctx.resume();
      const p=Math.round(clamp(pitch+num(track.instrument.octave,0)*12,0,127));
      const now=when==null?ctx.currentTime:Math.max(ctx.currentTime,num(when,ctx.currentTime));
      const osc=ctx.createOscillator();
      const env=ctx.createGain();
      osc.type=track.instrument.type==='drum'?(p<48?'sine':'square'):track.instrument.waveform;
      osc.frequency.setValueAtTime(midiFrequency(p),now);
      const attack=track.instrument.type==='drum'?.002:track.instrument.attack;
      const release=track.instrument.type==='drum'?.08:track.instrument.release;
      const peak=clamp(velocity,.01,1)*clamp(track.instrument.gain??.65,0,1);
      env.gain.setValueAtTime(.0001,now);
      env.gain.exponentialRampToValueAtTime(Math.max(.0002,peak),now+attack);
      osc.connect(env);
      env.connect(row.gain);
      const key=voiceKey(track.id,pitch,token);
      const voice={osc,env,ctx,release,scheduled:token!=='live',token};
      voices.set(key,voice);
      osc.onended=()=>{
        voices.delete(key);
        if(voice.scheduled) scheduled.delete(token);
        try{osc.disconnect();}catch(error){}
        try{env.disconnect();}catch(error){}
      };
      osc.start(now);
      if(duration!=null){
        const off=Math.max(now+attack+.005,now+duration);
        env.gain.setTargetAtTime(.0001,off,Math.max(.005,release/3));
        osc.stop(off+release+.08);
      }
      return key;
    }

    function stopVoice(trackId,pitch,token='live'){
      const key=voiceKey(trackId,pitch,token);
      const voice=voices.get(key);
      if(!voice) return;
      const now=voice.ctx.currentTime;
      try{
        voice.env.gain.cancelScheduledValues(now);
        voice.env.gain.setTargetAtTime(.0001,now,Math.max(.005,voice.release/3));
        voice.osc.stop(now+voice.release+.08);
      }catch(error){}
      voices.delete(key);
    }

    function stopScheduledVoices(){
      voices.forEach((voice,key)=>{
        if(!voice.scheduled) return;
        try{
          const now=voice.ctx.currentTime;
          voice.env.gain.cancelScheduledValues(now);
          voice.env.gain.setValueAtTime(.0001,now);
          voice.osc.stop(now+.001);
        }catch(error){}
        voices.delete(key);
      });
      scheduled.clear();
    }

    function stopAllVoices(){
      voices.forEach((voice,key)=>{
        try{voice.osc.stop();}catch(error){}
        voices.delete(key);
      });
      scheduled.clear();
    }

    function schedulePlayback(){
      if(!loaded) return;
      const rt=core();
      const ctx=audioContext();
      if(!rt||!ctx) return;
      updateAudioTracks();
      const playing=Boolean(agent().playing);
      const position=num(rt.getPosition?.(),0);

      if(!playing){
        if(lastPlaying) stopScheduledVoices();
        lastPlaying=false;
        lastPosition=position;
        return;
      }

      if(lastPlaying&&lastPosition>=0&&(
        position<lastPosition-.05||Math.abs(position-lastPosition)>.7
      )) stopScheduledVoices();

      lastPlaying=true;
      lastPosition=position;
      const t=tempo();
      const nowTick=secondsToTicks(position,t);
      const endTick=secondsToTicks(position+SCHEDULE_AHEAD,t);
      const ctxNow=ctx.currentTime;
      const anySolo=state.tracks.some(track=>track.solo);

      state.tracks.forEach(track=>{
        if(track.muted||(anySolo&&!track.solo)) return;
        track.clips.forEach(clip=>clip.notes.forEach(note=>{
          const abs=clip.startTick+note.startTick;
          if(abs<nowTick-2||abs>endTick) return;
          const key=`${track.id}:${clip.id}:${note.id}:${abs}`;
          if(scheduled.has(key)) return;
          scheduled.add(key);
          const delay=ticksToSeconds(abs-nowTick,t);
          const duration=ticksToSeconds(note.durationTick,t);
          startVoice(track,note.pitch,note.velocity,ctxNow+Math.max(0,delay),key,duration);
        }));
      });

      if(scheduled.size>12000) scheduled.clear();
    }

    function setStatus(text,error=false){
      const node=toolbar?.querySelector('[data-midi-status]');
      if(node){node.textContent=String(text||'');node.classList.toggle('error',error);}
    }

    function addTrack(type='poly'){
      const track=newTrack(type==='drum'?'Drum Rack':`MIDI ${state.tracks.length+1}`,type);
      track.clips[0].startTick=quantizeTick(currentTick(),'1/4');
      state.tracks.push(track);
      state.selectedTrackId=track.id;
      state.selectedClipId=track.clips[0].id;
      selectedNoteId='';
      markChanged();
      openEditor();
      return track;
    }

    function removeTrack(trackId){
      const index=state.tracks.findIndex(row=>row.id===trackId);
      if(index<0) return;
      stopScheduledVoices();
      state.tracks.splice(index,1);
      destroyAudioTrack(trackId);
      const next=state.tracks[Math.min(index,state.tracks.length-1)]||null;
      state.selectedTrackId=next?.id||'';
      state.selectedClipId=next?.clips[0]?.id||'';
      selectedNoteId='';
      markChanged();
    }

    function addClip(){
      const track=selectedTrack();
      if(!track) return null;
      const start=quantizeTick(currentTick(),'1/4');
      const clip=newClip(`Clip ${track.clips.length+1}`,start,FOUR_BARS);
      track.clips.push(clip);
      state.selectedClipId=clip.id;
      selectedNoteId='';
      markChanged();
      return clip;
    }

    function currentDivision(){
      return editor?.querySelector('[data-midi-quantize]')?.value||'1/16';
    }

    function divisionTicks(value){
      const values={'1/4':PPQ,'1/8':PPQ/2,'1/16':PPQ/4,'1/32':PPQ/8,'1/8T':PPQ/3,'1/16T':PPQ/6};
      return values[value]||PPQ/4;
    }

    function extendClipTo(clip,endTick){
      const grid=Math.max(1,divisionTicks(currentDivision()));
      const needed=Math.max(1,Math.ceil(Math.max(0,num(endTick,0))/grid)*grid);
      clip.lengthTick=Math.max(clip.lengthTick,needed);
    }

    function addNote(pitch,startTick,durationTick=PPQ/4,velocity=.8){
      const clip=selectedClip();
      if(!clip) return null;
      const note=normalizeNote({
        id:id('note'),pitch,startTick:Math.max(0,startTick),durationTick,velocity,channel:1
      });
      clip.notes.push(note);
      extendClipTo(clip,note.startTick+note.durationTick);
      clip.notes.sort((a,b)=>a.startTick-b.startTick||a.pitch-b.pitch);
      selectedNoteId=note.id;
      markChanged();
      return note;
    }

    function deleteSelectedNote(){
      const clip=selectedClip();
      if(!clip||!selectedNoteId) return;
      clip.notes=clip.notes.filter(row=>row.id!==selectedNoteId);
      selectedNoteId='';
      markChanged();
    }

    function quantizeSelected(division){
      const clip=selectedClip();
      if(!clip) return;
      clip.notes=quantizeNotes(clip.notes,division);
      clip.notes.forEach(note=>extendClipTo(clip,note.startTick+note.durationTick));
      markChanged();
    }

    function transposeSelected(amount){
      const clip=selectedClip();
      if(!clip) return;
      clip.notes=transposeNotes(clip.notes,amount);
      markChanged();
    }

    function gridMetrics(){
      return {rowHeight:18,beatWidth:64,pitchCount:PIANO_HIGH-PIANO_LOW+1};
    }

    function setNoteNodeGeometry(node,note,beatWidth,rowHeight){
      node.style.left=`${note.startTick/PPQ*beatWidth}px`;
      node.style.top=`${(PIANO_HIGH-note.pitch)*rowHeight+1}px`;
      node.style.width=`${Math.max(8,note.durationTick/PPQ*beatWidth)}px`;
      node.style.opacity=String(.55+note.velocity*.45);
      node.title=`${noteName(note.pitch)} · ${Math.round(note.velocity*127)}`;
    }

    function renderPianoRoll(){
      if(!editor) return;
      const track=selectedTrack();
      const clip=selectedClip();
      const roll=editor.querySelector('[data-midi-roll]');
      const keys=editor.querySelector('[data-midi-keys]');
      if(!roll||!keys) return;
      if(!track||!clip){
        roll.innerHTML='<div class="sf-midi-empty">Add a MIDI track to begin.</div>';
        keys.innerHTML='';
        return;
      }

      const {rowHeight,beatWidth,pitchCount}=gridMetrics();
      const width=Math.max(beatWidth*16,Math.ceil(clip.lengthTick/PPQ)*beatWidth+beatWidth);
      const height=pitchCount*rowHeight;
      keys.innerHTML='';
      for(let pitch=PIANO_HIGH;pitch>=PIANO_LOW;pitch--){
        const key=document.createElement('button');
        key.type='button';
        key.className=`sf-midi-key ${[1,3,6,8,10].includes(pitch%12)?'black':''}`;
        key.style.height=`${rowHeight}px`;
        key.textContent=noteName(pitch);
        key.dataset.pitch=String(pitch);
        key.addEventListener('pointerdown',()=>liveNoteOn(pitch,.8));
        key.addEventListener('pointerup',()=>liveNoteOff(pitch));
        key.addEventListener('pointerleave',event=>{if(event.buttons) liveNoteOff(pitch);});
        keys.appendChild(key);
      }

      roll.style.width=`${width}px`;
      roll.style.height=`${height}px`;
      roll.style.setProperty('--midi-beat',`${beatWidth}px`);
      roll.style.setProperty('--midi-row',`${rowHeight}px`);
      roll.innerHTML='';
      const grid=document.createElement('div');
      grid.className='sf-midi-grid';
      grid.style.width=`${width}px`;
      grid.style.height=`${height}px`;
      grid.addEventListener('dblclick',event=>{
        if(event.target!==grid) return;
        const rect=grid.getBoundingClientRect();
        const x=event.clientX-rect.left;
        const y=event.clientY-rect.top;
        const pitch=PIANO_HIGH-Math.floor(y/rowHeight);
        const tick=quantizeTick(x/beatWidth*PPQ,currentDivision());
        addNote(pitch,tick,divisionTicks(currentDivision()),.8);
      });
      roll.appendChild(grid);

      clip.notes.forEach(note=>{
        const node=document.createElement('div');
        node.className='sf-midi-note'+(note.id===selectedNoteId?' selected':'');
        node.dataset.noteId=note.id;
        setNoteNodeGeometry(node,note,beatWidth,rowHeight);
        const handle=document.createElement('span');
        handle.className='sf-midi-resize';
        node.appendChild(handle);
        bindNoteDrag(node,note,clip,beatWidth,rowHeight);
        grid.appendChild(node);
      });
      renderVelocity();
    }

    function bindNoteDrag(node,note,clip,beatWidth,rowHeight){
      node.addEventListener('pointerdown',event=>{
        if(event.button!==0) return;
        selectedNoteId=note.id;
        node.parentElement?.querySelectorAll('.sf-midi-note.selected').forEach(item=>item.classList.remove('selected'));
        node.classList.add('selected');
        renderVelocity();
        const resizing=event.target.classList.contains('sf-midi-resize');
        const startX=event.clientX;
        const startY=event.clientY;
        const startTick=note.startTick;
        const startPitch=note.pitch;
        const startDuration=note.durationTick;
        try{node.setPointerCapture?.(event.pointerId);}catch(error){}
        event.preventDefault();

        const move=ev=>{
          const dx=ev.clientX-startX;
          const dy=ev.clientY-startY;
          if(resizing){
            note.durationTick=Math.max(
              divisionTicks(currentDivision()),
              quantizeTick(startDuration+dx/beatWidth*PPQ,currentDivision())
            );
          }else{
            note.startTick=quantizeTick(Math.max(0,startTick+dx/beatWidth*PPQ),currentDivision());
            note.pitch=Math.round(clamp(startPitch-Math.round(dy/rowHeight),0,127));
          }
          extendClipTo(clip,note.startTick+note.durationTick);
          setNoteNodeGeometry(node,note,beatWidth,rowHeight);
          renderVelocity();
        };

        const finish=()=>{
          root.removeEventListener('pointermove',move);
          root.removeEventListener('pointerup',finish);
          root.removeEventListener('pointercancel',finish);
          clip.notes.sort((a,b)=>a.startTick-b.startTick||a.pitch-b.pitch);
          markChanged(false);
          renderPianoRoll();
        };
        root.addEventListener('pointermove',move);
        root.addEventListener('pointerup',finish,{once:true});
        root.addEventListener('pointercancel',finish,{once:true});
      });
    }

    function renderVelocity(){
      const note=selectedNote();
      const slider=editor?.querySelector('[data-midi-velocity]');
      const label=editor?.querySelector('[data-midi-velocity-value]');
      if(slider){
        slider.disabled=!note;
        slider.value=String(Math.round((note?.velocity||.8)*127));
      }
      if(label) label.textContent=note?String(Math.round(note.velocity*127)):'—';
    }

    function escapeHtml(value){
      return String(value||'').replace(/[&<>"']/g,char=>({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
      }[char]));
    }

    function renderTrackList(){
      const list=editor?.querySelector('[data-midi-tracks]');
      if(!list) return;
      list.innerHTML='';
      state.tracks.forEach(track=>{
        const row=document.createElement('button');
        row.type='button';
        row.className='sf-midi-track'+(track.id===state.selectedTrackId?' active':'');
        row.innerHTML=`<strong>${escapeHtml(track.name)}</strong><small>${track.instrument.type==='drum'?'DRUM':'SYNTH'} · ${track.clips.length} clip${track.clips.length===1?'':'s'}</small>`;
        row.addEventListener('click',()=>{
          state.selectedTrackId=track.id;
          state.selectedClipId=track.clips[0]?.id||'';
          selectedNoteId='';
          renderAll();
        });
        list.appendChild(row);
      });
    }

    function renderClipList(){
      const select=editor?.querySelector('[data-midi-clip-select]');
      const track=selectedTrack();
      if(!select) return;
      select.innerHTML='';
      (track?.clips||[]).forEach(clip=>{
        const option=document.createElement('option');
        option.value=clip.id;
        option.textContent=clip.name;
        if(clip.id===state.selectedClipId) option.selected=true;
        select.appendChild(option);
      });
    }

    function renderInspector(){
      const track=selectedTrack();
      if(!editor) return;
      const name=editor.querySelector('[data-midi-track-name]');
      const type=editor.querySelector('[data-midi-instrument]');
      const wave=editor.querySelector('[data-midi-wave]');
      const vol=editor.querySelector('[data-midi-volume]');
      const pan=editor.querySelector('[data-midi-pan]');
      if(name) name.value=track?.name||'';
      if(type) type.value=track?.instrument.type||'poly';
      if(wave) wave.value=track?.instrument.waveform||'sawtooth';
      if(vol) vol.value=String(track?.volume??.8);
      if(pan) pan.value=String(track?.pan??0);
      editor.querySelector('[data-midi-arm]')?.classList.toggle('active',Boolean(track?.armed));
      editor.querySelector('[data-midi-mute]')?.classList.toggle('active',Boolean(track?.muted));
      editor.querySelector('[data-midi-solo]')?.classList.toggle('active',Boolean(track?.solo));
    }

    function renderAll(){
      renderTrackList();
      renderClipList();
      renderInspector();
      renderPianoRoll();
      updateAudioTracks();
      updateToolbar();
    }

    function buildToolbar(){
      const host=document.querySelector('.daw-mixer-toolbar')||document.querySelector('.daw-toolbar');
      if(!host) return false;
      if(host.querySelector('[data-midi-v217-toolbar]')){
        toolbar=host.querySelector('[data-midi-v217-toolbar]');
        return true;
      }
      toolbar=document.createElement('div');
      toolbar.className='sf-midi-toolbar';
      toolbar.dataset.midiV217Toolbar=BUILD;
      toolbar.innerHTML='<span class="sf-midi-title">MIDI</span><button type="button" data-midi-add>+ MIDI</button><button type="button" data-midi-drum>+ DRUM</button><button type="button" data-midi-open>PIANO ROLL</button><button type="button" data-midi-input>MIDI IN</button><span data-midi-status>READY</span>';
      host.appendChild(toolbar);
      toolbar.querySelector('[data-midi-add]').addEventListener('click',()=>addTrack('poly'));
      toolbar.querySelector('[data-midi-drum]').addEventListener('click',()=>addTrack('drum'));
      toolbar.querySelector('[data-midi-open]').addEventListener('click',openEditor);
      toolbar.querySelector('[data-midi-input]').addEventListener('click',()=>void enableMidiInput());
      return true;
    }

    function updateToolbar(){
      const button=toolbar?.querySelector('[data-midi-input]');
      if(button) button.classList.toggle('active',Boolean(midiAccess));
    }

    function ensureEditor(){
      if(editor) return editor;
      editor=document.createElement('section');
      editor.className='sf-midi-editor';
      editor.hidden=true;
      editor.dataset.midiV217Editor=BUILD;
      editor.innerHTML=`<header><div><span>MIDI WORKSPACE</span><strong>PIANO ROLL</strong></div><div class="sf-midi-head-actions"><button type="button" data-midi-record>● REC</button><button type="button" data-midi-save>SAVE</button><button type="button" data-midi-close>×</button></div></header><div class="sf-midi-body"><aside class="sf-midi-sidebar"><div class="sf-midi-sidebar-head"><strong>TRACKS</strong><button type="button" data-midi-add-track>+</button></div><div data-midi-tracks></div><button type="button" data-midi-remove-track class="danger">REMOVE TRACK</button></aside><main class="sf-midi-main"><div class="sf-midi-controls"><input data-midi-track-name aria-label="MIDI track name"><select data-midi-instrument><option value="poly">Poly Synth</option><option value="drum">Drum Rack</option></select><select data-midi-wave><option>sawtooth</option><option>square</option><option>triangle</option><option>sine</option></select><label>VOL <input type="range" min="0" max="1.5" step="0.01" data-midi-volume></label><label>PAN <input type="range" min="-1" max="1" step="0.01" data-midi-pan></label><button type="button" data-midi-arm>ARM</button><button type="button" data-midi-mute>M</button><button type="button" data-midi-solo>S</button></div><div class="sf-midi-editbar"><select data-midi-clip-select></select><button type="button" data-midi-add-clip>+ CLIP</button><select data-midi-quantize><option value="1/4">1/4</option><option value="1/8">1/8</option><option value="1/16" selected>1/16</option><option value="1/32">1/32</option><option value="1/8T">1/8T</option><option value="1/16T">1/16T</option></select><button type="button" data-midi-do-quantize>QUANTIZE</button><button type="button" data-midi-down>−1</button><button type="button" data-midi-up>+1</button><button type="button" data-midi-oct-down>−12</button><button type="button" data-midi-oct-up>+12</button><button type="button" data-midi-delete-note>DELETE NOTE</button><label>VELOCITY <input type="range" min="1" max="127" step="1" data-midi-velocity><span data-midi-velocity-value>—</span></label></div><div class="sf-midi-roll-wrap"><div class="sf-midi-keys" data-midi-keys></div><div class="sf-midi-roll-scroll"><div class="sf-midi-roll" data-midi-roll></div></div></div><div class="sf-midi-keyboard" data-midi-keyboard></div></main></div>`;
      document.body.appendChild(editor);
      bindEditor();
      buildKeyboard();
      return editor;
    }

    function bindEditor(){
      editor.querySelector('[data-midi-close]').addEventListener('click',()=>editor.hidden=true);
      editor.querySelector('[data-midi-save]').addEventListener('click',()=>void saveNow());
      editor.querySelector('[data-midi-add-track]').addEventListener('click',()=>addTrack('poly'));
      editor.querySelector('[data-midi-remove-track]').addEventListener('click',()=>{
        const track=selectedTrack();
        if(track&&root.confirm('Remove this MIDI track?')) removeTrack(track.id);
      });
      editor.querySelector('[data-midi-add-clip]').addEventListener('click',addClip);
      editor.querySelector('[data-midi-clip-select]').addEventListener('change',event=>{
        state.selectedClipId=event.target.value;
        selectedNoteId='';
        renderPianoRoll();
      });
      editor.querySelector('[data-midi-do-quantize]').addEventListener('click',()=>quantizeSelected(currentDivision()));
      editor.querySelector('[data-midi-down]').addEventListener('click',()=>transposeSelected(-1));
      editor.querySelector('[data-midi-up]').addEventListener('click',()=>transposeSelected(1));
      editor.querySelector('[data-midi-oct-down]').addEventListener('click',()=>transposeSelected(-12));
      editor.querySelector('[data-midi-oct-up]').addEventListener('click',()=>transposeSelected(12));
      editor.querySelector('[data-midi-delete-note]').addEventListener('click',deleteSelectedNote);
      editor.querySelector('[data-midi-track-name]').addEventListener('change',event=>{
        const track=selectedTrack();
        if(track){track.name=String(event.target.value||'MIDI').slice(0,80);markChanged();}
      });
      editor.querySelector('[data-midi-instrument]').addEventListener('change',event=>{
        const track=selectedTrack();
        if(track){track.instrument.type=event.target.value==='drum'?'drum':'poly';markChanged();}
      });
      editor.querySelector('[data-midi-wave]').addEventListener('change',event=>{
        const track=selectedTrack();
        if(track){track.instrument.waveform=event.target.value;markChanged(false);}
      });
      editor.querySelector('[data-midi-volume]').addEventListener('input',event=>{
        const track=selectedTrack();
        if(track){track.volume=clamp(event.target.value,0,1.5);updateAudioTracks();markChanged(false);}
      });
      editor.querySelector('[data-midi-pan]').addEventListener('input',event=>{
        const track=selectedTrack();
        if(track){track.pan=clamp(event.target.value,-1,1);updateAudioTracks();markChanged(false);}
      });
      editor.querySelector('[data-midi-arm]').addEventListener('click',()=>{
        const track=selectedTrack();
        if(track){
          const next=!track.armed;
          state.tracks.forEach(row=>row.armed=row.id===track.id?next:false);
          markChanged();
        }
      });
      editor.querySelector('[data-midi-mute]').addEventListener('click',()=>{
        const track=selectedTrack();
        if(track){track.muted=!track.muted;markChanged();}
      });
      editor.querySelector('[data-midi-solo]').addEventListener('click',()=>{
        const track=selectedTrack();
        if(track){track.solo=!track.solo;markChanged();}
      });
      editor.querySelector('[data-midi-record]').addEventListener('click',()=>{
        midiRecording=!midiRecording;
        if(!midiRecording) recordingNotes.clear();
        editor.querySelector('[data-midi-record]').classList.toggle('active',midiRecording);
        setStatus(midiRecording?'MIDI RECORD ARMED':'READY');
      });
      editor.querySelector('[data-midi-velocity]').addEventListener('input',event=>{
        const note=selectedNote();
        if(note){
          note.velocity=clamp(Number(event.target.value)/127,.01,1);
          renderVelocity();
          markChanged(false);
        }
      });
      editor.addEventListener('keydown',event=>{
        if((event.key==='Delete'||event.key==='Backspace')&&selectedNoteId){
          event.preventDefault();
          deleteSelectedNote();
        }
      });
    }

    function buildKeyboard(){
      const box=editor.querySelector('[data-midi-keyboard]');
      box.innerHTML='';
      for(let pitch=48;pitch<=72;pitch++){
        const button=document.createElement('button');
        button.type='button';
        button.className=[1,3,6,8,10].includes(pitch%12)?'black':'';
        button.textContent=noteName(pitch);
        button.addEventListener('pointerdown',()=>liveNoteOn(pitch,.85));
        button.addEventListener('pointerup',()=>liveNoteOff(pitch));
        button.addEventListener('pointerleave',event=>{if(event.buttons) liveNoteOff(pitch);});
        box.appendChild(button);
      }
    }

    function openEditor(){
      ensureEditor();
      editor.hidden=false;
      renderAll();
    }

    function armedTrack(){
      return state.tracks.find(track=>track.armed)||selectedTrack();
    }

    function ensureRecordClip(track){
      let clip=track?.clips.find(row=>row.id===state.selectedClipId);
      if(!clip){
        clip=newClip(`Clip ${track.clips.length+1}`,quantizeTick(currentTick(),'1/4'),FOUR_BARS);
        track.clips.push(clip);
        state.selectedClipId=clip.id;
      }
      return clip;
    }

    function liveNoteOn(pitch,velocity=.8,channel=1){
      const track=armedTrack();
      if(!track) return;
      startVoice(track,pitch,velocity,null,'live');
      if(!midiRecording||!Boolean(agent().playing)) return;
      const clip=ensureRecordClip(track);
      const absTick=currentTick();
      const relative=Math.max(0,absTick-clip.startTick);
      const key=`${channel}:${pitch}`;
      recordingNotes.set(key,{
        trackId:track.id,clipId:clip.id,pitch,velocity,channel,startTick:relative
      });
    }

    function liveNoteOff(pitch,channel=1){
      const track=armedTrack();
      if(track) stopVoice(track.id,pitch,'live');
      const key=`${channel}:${pitch}`;
      const pending=recordingNotes.get(key);
      if(!pending) return;
      recordingNotes.delete(key);
      const target=state.tracks.find(row=>row.id===pending.trackId);
      const clip=target?.clips.find(row=>row.id===pending.clipId);
      if(!clip) return;
      const end=Math.max(pending.startTick+1,currentTick()-clip.startTick);
      let duration=quantizeTick(Math.max(1,end-pending.startTick),currentDivision());
      if(duration<1) duration=divisionTicks(currentDivision());
      const note=normalizeNote({...pending,id:id('note'),durationTick:duration});
      clip.notes.push(note);
      extendClipTo(clip,note.startTick+note.durationTick);
      clip.notes.sort((a,b)=>a.startTick-b.startTick||a.pitch-b.pitch);
      markChanged();
    }

    function handleMidiMessage(event){
      const data=Array.from(event.data||[]);
      const status=data[0]||0;
      const type=status&0xf0;
      const channel=(status&0x0f)+1;
      const pitch=data[1]||0;
      const velocity=(data[2]||0)/127;
      if(type===0x90&&velocity>0) liveNoteOn(pitch,velocity,channel);
      else if(type===0x80||(type===0x90&&velocity===0)) liveNoteOff(pitch,channel);
    }

    function bindMidiInputs(){
      if(!midiAccess) return;
      midiAccess.inputs.forEach(input=>{input.onmidimessage=handleMidiMessage;});
      midiAccess.onstatechange=()=>bindMidiInputs();
      updateToolbar();
    }

    async function enableMidiInput(){
      if(!root.navigator?.requestMIDIAccess){
        setStatus('WEB MIDI NOT SUPPORTED',true);
        return false;
      }
      try{
        midiAccess=await root.navigator.requestMIDIAccess({sysex:false});
        bindMidiInputs();
        setStatus(`${midiAccess.inputs.size} MIDI INPUT${midiAccess.inputs.size===1?'':'S'}`);
        return true;
      }catch(error){
        setStatus('MIDI ACCESS DENIED',true);
        return false;
      }
    }

    function bind(){
      if(!studio()||!core()){
        root.setTimeout(bind,70);
        return;
      }
      buildToolbar();
      ensureEditor();
      scheduleTimer=root.setInterval(schedulePlayback,LOOKAHEAD_MS);
      void load().then(()=>setStatus('MIDI READY')).catch(error=>setStatus(error.message,true));

      root.addEventListener('pagehide',()=>{
        root.clearTimeout(saveTimer);
        persistLocal();
        stopAllVoices();
      });
      root.addEventListener('pageshow',()=>{
        if(!scheduleTimer) scheduleTimer=root.setInterval(schedulePlayback,LOOKAHEAD_MS);
        lastPlaying=false;
        lastPosition=num(core()?.getPosition?.(),0);
        updateAudioTracks();
      });
      root.document?.addEventListener('visibilitychange',()=>{
        if(root.document.hidden) stopAllVoices();
      });

      root.StonefellowStemMidiV217Runtime={
        build:BUILD,
        getState:()=>clone(state),
        setState:value=>restoreState(value,{save:true}),
        restoreState,
        save:saveNow,
        addTrack,
        addClip,
        addNote,
        quantizeSelected,
        transposeSelected,
        enableMidiInput,
        openEditor,
        noteOn:liveNoteOn,
        noteOff:liveNoteOff,
        stopScheduledVoices,
        stop:stopAllVoices
      };
      root.dispatchEvent(new CustomEvent('stonefellow:stem-midi-v217',{
        detail:{build:BUILD,ppq:PPQ}
      }));
    }

    bind();
    return true;
  }

  return Object.freeze({
    build:BUILD,
    PPQ,
    FOUR_BARS,
    noteName,
    midiFrequency,
    secondsToTicks,
    ticksToSeconds,
    quantizeTick,
    emptyState,
    newClip,
    newTrack,
    normalizeNote,
    normalizeClip,
    normalizeTrack,
    normalizeState,
    quantizeNotes,
    transposeNotes,
    install
  });
});
