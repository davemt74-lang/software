(function(root){
  'use strict';

  const BUILD='stem-virtual-midi-keyboard-v219-20260902';
  if(root.__STONEFELLOW_STEM_VIRTUAL_MIDI_V219__) return;
  root.__STONEFELLOW_STEM_VIRTUAL_MIDI_V219__=true;

  const KEY_MAP={
    a:0,w:1,s:2,e:3,d:4,f:5,t:6,g:7,y:8,h:9,u:10,j:11,k:12,
    o:13,l:14,p:15,';':16,"'":17
  };
  const KEY_LABELS={0:'A',1:'W',2:'S',3:'E',4:'D',5:'F',6:'T',7:'G',8:'Y',9:'H',10:'U',11:'J',12:'K',13:'O',14:'L',15:'P',16:';',17:"'"};
  const BLACK_CLASSES=new Set([1,3,6,8,10]);
  const NOTE_NAMES=['C','C♯','D','D♯','E','F','F♯','G','G♯','A','A♯','B'];
  const clamp=(value,min,max)=>Math.min(max,Math.max(min,Number(value)||0));
  const noteName=pitch=>`${NOTE_NAMES[((pitch%12)+12)%12]}${Math.floor(pitch/12)-1}`;
  const isTypingTarget=target=>Boolean(target?.closest?.('input,textarea,select,[contenteditable="true"],[contenteditable=""],.CodeMirror,.monaco-editor'));

  let attempts=0;
  let panel=null;
  let launchButton=null;
  let keyboardEnabled=false;
  let octave=4;
  let velocity=100;
  let pressedKeys=new Map();
  let pointerNotes=new Map();
  let statusTimer=0;
  let lastTargetTrackId='';

  const runtime=()=>root.StonefellowStemMidiV217Runtime||null;
  const midiState=()=>runtime()?.getState?.()||{tracks:[],selectedTrackId:''};

  function basePitch(){ return clamp((octave+1)*12,0,109); }

  function targetTrack(){
    const state=midiState();
    return state.tracks?.find(track=>track.armed)
      ||state.tracks?.find(track=>track.id===state.selectedTrackId)
      ||state.tracks?.[0]
      ||null;
  }

  function setStatus(text,temporary=false){
    const node=panel?.querySelector('[data-v219-status]');
    if(!node) return;
    node.textContent=String(text||'');
    root.clearTimeout(statusTimer);
    if(temporary) statusTimer=root.setTimeout(updateStatus,1500);
  }

  function updateStatus(){
    const track=targetTrack();
    const target=track?`${track.armed?'ARMED':'SELECTED'} · ${track.name}`:'NO MIDI TRACK';
    setStatus(`${keyboardEnabled?'COMPUTER KEYS ON':'COMPUTER KEYS OFF'} · ${target}`);
    const toggle=panel?.querySelector('[data-v219-computer-toggle]');
    if(toggle){
      toggle.classList.toggle('active',keyboardEnabled);
      toggle.setAttribute('aria-pressed',keyboardEnabled?'true':'false');
      toggle.textContent=keyboardEnabled?'COMPUTER KEYS ON':'COMPUTER KEYS OFF';
    }
    const octaveNode=panel?.querySelector('[data-v219-octave]');
    if(octaveNode) octaveNode.textContent=`OCT ${octave}`;
    const velocityNode=panel?.querySelector('[data-v219-velocity]');
    if(velocityNode) velocityNode.textContent=`VEL ${velocity}`;
  }

  function highlightPitch(pitch,on){
    panel?.querySelectorAll(`[data-v219-pitch="${pitch}"]`).forEach(node=>node.classList.toggle('pressed',Boolean(on)));
  }

  function noteOn(pitch){
    const rt=runtime();
    if(!rt?.noteOn) return false;
    const track=targetTrack();
    if(!track){
      setStatus('CREATE OR SELECT A MIDI TRACK FIRST',true);
      return false;
    }
    const p=Math.round(clamp(pitch,0,127));
    rt.noteOn(p,clamp(velocity/127,.01,1));
    highlightPitch(p,true);
    return true;
  }

  function noteOff(pitch){
    const p=Math.round(clamp(pitch,0,127));
    runtime()?.noteOff?.(p);
    highlightPitch(p,false);
  }

  function releaseComputerKeys(){
    pressedKeys.forEach(pitch=>noteOff(pitch));
    pressedKeys.clear();
  }

  function releasePointers(){
    pointerNotes.forEach(pitch=>noteOff(pitch));
    pointerNotes.clear();
  }

  function releaseAll(){
    releaseComputerKeys();
    releasePointers();
  }

  function panic(){
    releaseAll();
    runtime()?.stop?.();
    setStatus('ALL MIDI NOTES OFF',true);
  }

  function handleMidiStateChange(){
    const nextTargetId=targetTrack()?.id||'';
    const hasHeldNotes=pressedKeys.size>0||pointerNotes.size>0;
    if(lastTargetTrackId&&nextTargetId!==lastTargetTrackId&&hasHeldNotes){
      releaseAll();
      runtime()?.stop?.();
    }
    lastTargetTrackId=nextTargetId;
    updateStatus();
  }

  function toggleComputerKeys(force){
    const next=typeof force==='boolean'?force:!keyboardEnabled;
    if(next===keyboardEnabled) return;
    keyboardEnabled=next;
    if(!keyboardEnabled) releaseComputerKeys();
    updateStatus();
  }

  function setOctave(next){
    const value=Math.round(clamp(next,0,8));
    if(value===octave) return;
    releaseAll();
    octave=value;
    try{root.localStorage?.setItem('stonefellow:stem:v219:octave',String(octave));}catch(error){}
    renderKeys();
    updateStatus();
  }

  function setVelocity(next){
    velocity=Math.round(clamp(next,1,127));
    try{root.localStorage?.setItem('stonefellow:stem:v219:velocity',String(velocity));}catch(error){}
    updateStatus();
  }

  function open(){
    ensurePanel();
    panel.classList.add('open');
    panel.setAttribute('aria-hidden','false');
    launchButton?.classList.add('active');
    lastTargetTrackId=targetTrack()?.id||'';
    updateStatus();
    root.dispatchEvent(new CustomEvent('stonefellow:stem-virtual-midi-v219-open',{detail:{build:BUILD}}));
  }

  function close(){
    if(!panel) return;
    releaseAll();
    toggleComputerKeys(false);
    panel.classList.remove('open');
    panel.setAttribute('aria-hidden','true');
    launchButton?.classList.remove('active');
  }

  function onKeyDown(event){
    if(!panel?.classList.contains('open')) return;
    const key=String(event.key||'').toLowerCase();
    if(key==='escape'&&!isTypingTarget(event.target)){
      close();
      event.preventDefault();
      return;
    }
    if(!keyboardEnabled) return;
    if(event.ctrlKey||event.metaKey||event.altKey||isTypingTarget(event.target)) return;
    if(key==='z'){
      if(!event.repeat) setOctave(octave-1);
      event.preventDefault();
      return;
    }
    if(key==='x'){
      if(!event.repeat) setOctave(octave+1);
      event.preventDefault();
      return;
    }
    if(key==='c'){
      if(!event.repeat) setVelocity(velocity-8);
      event.preventDefault();
      return;
    }
    if(key==='v'){
      if(!event.repeat) setVelocity(velocity+8);
      event.preventDefault();
      return;
    }
    if(!Object.hasOwn(KEY_MAP,key)||event.repeat||pressedKeys.has(key)) return;
    const pitch=basePitch()+KEY_MAP[key];
    if(noteOn(pitch)) pressedKeys.set(key,pitch);
    event.preventDefault();
  }

  function onKeyUp(event){
    const key=String(event.key||'').toLowerCase();
    if(!pressedKeys.has(key)) return;
    const pitch=pressedKeys.get(key);
    pressedKeys.delete(key);
    noteOff(pitch);
    event.preventDefault();
  }

  function renderKeys(){
    const bed=panel?.querySelector('[data-v219-keybed]');
    if(!bed) return;
    bed.innerHTML='';
    const start=basePitch();
    const end=Math.min(127,start+24);
    const whitePitches=[];
    for(let pitch=start;pitch<=end;pitch+=1){
      if(!BLACK_CLASSES.has(pitch%12)) whitePitches.push(pitch);
    }
    const whiteCount=whitePitches.length;
    whitePitches.forEach((pitch,index)=>{
      const key=root.document.createElement('button');
      key.type='button';
      key.className='sf-v219-key white';
      key.dataset.v219Pitch=String(pitch);
      key.style.setProperty('--left',`${index/whiteCount*100}%`);
      key.style.setProperty('--width',`${100/whiteCount}%`);
      const offset=pitch-start;
      const computer=KEY_LABELS[offset]||'';
      key.innerHTML=`<span class="sf-v219-computer-label">${computer}</span><span class="sf-v219-note-label">${noteName(pitch)}</span>`;
      bindPointerKey(key,pitch);
      bed.appendChild(key);
    });
    for(let pitch=start;pitch<=end;pitch+=1){
      if(!BLACK_CLASSES.has(pitch%12)) continue;
      const previousWhite=whitePitches.filter(row=>row<pitch).length;
      if(previousWhite<1||previousWhite>=whiteCount) continue;
      const key=root.document.createElement('button');
      key.type='button';
      key.className='sf-v219-key black';
      key.dataset.v219Pitch=String(pitch);
      key.style.setProperty('--left',`${previousWhite/whiteCount*100}%`);
      key.style.setProperty('--width',`${100/whiteCount*.62}%`);
      const offset=pitch-start;
      const computer=KEY_LABELS[offset]||'';
      key.innerHTML=`<span class="sf-v219-computer-label">${computer}</span>`;
      bindPointerKey(key,pitch);
      bed.appendChild(key);
    }
  }

  function bindPointerKey(key,pitch){
    key.addEventListener('pointerdown',event=>{
      event.preventDefault();
      try{key.setPointerCapture(event.pointerId);}catch(error){}
      if(noteOn(pitch)) pointerNotes.set(event.pointerId,pitch);
    });
    const end=event=>{
      if(!pointerNotes.has(event.pointerId)) return;
      const activePitch=pointerNotes.get(event.pointerId);
      pointerNotes.delete(event.pointerId);
      noteOff(activePitch);
    };
    key.addEventListener('pointerup',end);
    key.addEventListener('pointercancel',end);
    key.addEventListener('lostpointercapture',end);
  }

  function ensurePanel(){
    if(panel) return panel;
    panel=root.document.createElement('section');
    panel.className='sf-v219-keyboard-drawer';
    panel.dataset.stemVirtualMidiV219=BUILD;
    panel.setAttribute('aria-hidden','true');
    panel.innerHTML=`
      <div class="sf-v219-bar">
        <div class="sf-v219-title"><strong>VIRTUAL MIDI KEYBOARD</strong><span data-v219-status>COMPUTER KEYS OFF</span></div>
        <div class="sf-v219-controls">
          <button type="button" data-v219-computer-toggle aria-pressed="false">COMPUTER KEYS OFF</button>
          <button type="button" data-v219-octave-down title="Octave down · Z">− OCT</button>
          <span data-v219-octave>OCT 4</span>
          <button type="button" data-v219-octave-up title="Octave up · X">+ OCT</button>
          <button type="button" data-v219-velocity-down title="Velocity down · C">− VEL</button>
          <span data-v219-velocity>VEL 100</span>
          <button type="button" data-v219-velocity-up title="Velocity up · V">+ VEL</button>
          <button type="button" data-v219-panic>PANIC</button>
          <button type="button" data-v219-close aria-label="Close virtual MIDI keyboard">×</button>
        </div>
      </div>
      <div class="sf-v219-help"><span>A W S E D F T G Y H U J K… = NOTES</span><span>Z/X = OCTAVE</span><span>C/V = VELOCITY</span><span>Uses armed MIDI track · REC follows MIDI workspace</span></div>
      <div class="sf-v219-keybed" data-v219-keybed></div>`;
    root.document.body.appendChild(panel);
    panel.querySelector('[data-v219-computer-toggle]').addEventListener('click',()=>toggleComputerKeys());
    panel.querySelector('[data-v219-octave-down]').addEventListener('click',()=>setOctave(octave-1));
    panel.querySelector('[data-v219-octave-up]').addEventListener('click',()=>setOctave(octave+1));
    panel.querySelector('[data-v219-velocity-down]').addEventListener('click',()=>setVelocity(velocity-8));
    panel.querySelector('[data-v219-velocity-up]').addEventListener('click',()=>setVelocity(velocity+8));
    panel.querySelector('[data-v219-panic]').addEventListener('click',panic);
    panel.querySelector('[data-v219-close]').addEventListener('click',close);
    try{
      const savedOctave=Number(root.localStorage?.getItem('stonefellow:stem:v219:octave'));
      const savedVelocity=Number(root.localStorage?.getItem('stonefellow:stem:v219:velocity'));
      if(Number.isFinite(savedOctave)) octave=Math.round(clamp(savedOctave,0,8));
      if(Number.isFinite(savedVelocity)) velocity=Math.round(clamp(savedVelocity,1,127));
    }catch(error){}
    renderKeys();
    updateStatus();
    return panel;
  }

  function installLauncher(){
    const toolbar=root.document.querySelector('.sf-midi-toolbar');
    if(!toolbar) return false;
    if(toolbar.querySelector('[data-v219-keyboard-launch]')){
      launchButton=toolbar.querySelector('[data-v219-keyboard-launch]');
      return true;
    }
    launchButton=root.document.createElement('button');
    launchButton.type='button';
    launchButton.dataset.v219KeyboardLaunch='1';
    launchButton.textContent='KEYBOARD';
    launchButton.title='Open virtual MIDI keyboard';
    const pianoRoll=toolbar.querySelector('[data-midi-open]');
    if(pianoRoll?.nextSibling) toolbar.insertBefore(launchButton,pianoRoll.nextSibling);
    else toolbar.appendChild(launchButton);
    launchButton.addEventListener('click',()=>panel?.classList.contains('open')?close():open());
    return true;
  }

  function install(){
    if(!runtime()||!installLauncher()){
      attempts+=1;
      if(attempts<260) root.setTimeout(install,50);
      else root.__STONEFELLOW_STEM_VIRTUAL_MIDI_V219__=false;
      return;
    }
    ensurePanel();
    lastTargetTrackId=targetTrack()?.id||'';
    root.addEventListener('keydown',onKeyDown,true);
    root.addEventListener('keyup',onKeyUp,true);
    root.addEventListener('blur',releaseAll);
    root.document.addEventListener('visibilitychange',()=>{if(root.document.hidden) releaseAll();});
    root.addEventListener('stonefellow:stem-midi-v217-change',handleMidiStateChange);
    root.StonefellowStemVirtualMidiV219={
      build:BUILD,
      open,close,panic,
      setComputerKeyboardEnabled:toggleComputerKeys,
      setOctave,setVelocity,
      getState:()=>({open:Boolean(panel?.classList.contains('open')),computerKeyboardEnabled:keyboardEnabled,octave,velocity,targetTrackId:targetTrack()?.id||''})
    };
    root.dispatchEvent(new CustomEvent('stonefellow:stem-virtual-midi-v219',{detail:{build:BUILD}}));
  }

  root.addEventListener('pagehide',()=>{
    releaseAll();
    root.clearTimeout(statusTimer);
    // Keep listeners installed for browser back-forward cache restores. A real
    // unload releases the document; a bfcache restore must keep the keyboard live.
  });

  install();
})(typeof globalThis!=='undefined'?globalThis:window);
