(() => {
  'use strict';
  const cfg = window.STONEFELLOW_BROWSER_ZIP;
  if (!cfg?.musicLibrary) return;
  const el = id => document.getElementById(id);
  const overlay = el('musicImportOverlay');
  const title = el('musicImportTitle');
  const phaseText = el('musicImportPhase');
  const count = el('musicImportCounts');
  const bar = el('musicImportBar');
  const track = el('musicImportTrack');
  const list = el('musicImportItems');
  const errorBox = el('musicImportError');
  const cancelBtn = el('musicImportCancel');
  const retryBtn = el('musicImportRetry');
  const closeBtn = el('musicImportClose');
  const viewLink = el('musicImportView');
  if (![overlay,title,phaseText,count,bar,track,list,errorBox,cancelBtn,retryBtn,closeBtn,viewLink].every(Boolean)) return;

  const validStates = new Set(['waiting','extracting','uploading','uploaded','importing','complete','failed','cancelled']);
  const labels = {waiting:'Waiting',extracting:'Extracting',uploading:'Uploading',uploaded:'Uploaded',importing:'Importing',complete:'Imported',failed:'Failed',cancelled:'Cancelled'};
  const rows = new Map();
  let mode = 'idle';
  let callbacks = {};
  let previousFocus = null;
  let voiceEnabled = cfg.agentVoiceEnabled === true;
  let sessionVoiced = new Set();
  let committed = false;

  function announce(key,message) {
    if (sessionVoiced.has(key)) return;
    sessionVoiced.add(key);
    if (!voiceEnabled || document.hidden) return;
    const voiceCenter=window.STONEFELLOW_NOTIFICATION_CENTER;
    if(typeof voiceCenter?.announceWhen==='function'){
      // Reuse VP3's configured Agent voice provider and serialized announcement
      // queue rather than interrupting it with an independent speech engine.
      try {void voiceCenter.announceWhen(message,()=>voiceEnabled&&!document.hidden);} catch (_) {}
      return;
    }
    try {
      if (!window.speechSynthesis || !window.SpeechSynthesisUtterance || window.speechSynthesis.speaking) return;
      const speech = new window.SpeechSynthesisUtterance(message);
      speech.rate = 1;
      window.speechSynthesis.speak(speech);
    } catch (_) { /* Keep visible announcements even if speech is unavailable. */ }
  }
  window.addEventListener('stonefellow:agent-voice', event => {
    voiceEnabled = event.detail?.enabled === true;
  });

  function setPhase(text, percent) {
    phaseText.textContent = String(text || 'Preparing files…').slice(0,220);
    const value = Math.max(0,Math.min(100,Number(percent) || 0));
    bar.style.width = `${value}%`;
    track.setAttribute('aria-valuenow',String(Math.round(value)));
  }
  function totals() {
    const values = [...rows.values()];
    const uploaded = values.filter(v => ['uploaded','importing','complete'].includes(v.state)).length;
    const imported = values.filter(v => v.state === 'complete').length;
    const stems = values.filter(v => v.kind === 'stem');
    const stemsImported = stems.filter(v => v.state === 'complete').length;
    count.textContent = `${uploaded} of ${values.length} files uploaded · ${stemsImported} of ${stems.length} stems imported`;
    return {uploaded, imported, stemCount:stems.length};
  }
  function makeRow(entry) {
    const li = document.createElement('li');
    li.className='vp3-music-import-item';
    const header = document.createElement('div');
    header.className='vp3-music-import-item-head';
    const name=document.createElement('span');
    name.className='vp3-music-import-name';
    name.textContent=entry.name;
    const state=document.createElement('strong');
    state.className='vp3-music-import-state';
    header.append(name,state);
    const meter=document.createElement('div');
    meter.className='vp3-music-import-item-track';
    const fill=document.createElement('span');
    meter.append(fill);
    li.append(header,meter);
    list.append(li);
    return {entry,li,state,fill};
  }
  function file(key,state,percent,detail='') {
    const row=rows.get(String(key));
    if (!row || !validStates.has(state) || mode === 'idle' || mode === 'complete') return;
    if (!row.node) row.node=makeRow(row);
    row.state=state;
    row.percent=Math.max(0,Math.min(100,Number(percent)||0));
    row.node.li.dataset.state=state;
    row.node.state.textContent=detail ? `${labels[state]} · ${String(detail).slice(0,80)}` : labels[state];
    row.node.fill.style.width=`${row.percent}%`;
    totals();
  }
  function show() {
    overlay.hidden=false;
    document.body.classList.add('vp3-music-import-open');
    cancelBtn.focus();
  }
  function pause() {
    if (mode !== 'uploading') return;
    mode='reviewing';
    overlay.hidden=true;
    document.body.classList.remove('vp3-music-import-open');
  }
  function resume() {
    if (mode!=='reviewing') return;
    mode='uploading';
    show();
  }
  function setFiles(files) {
    if(mode!=='uploading')return;
    rows.clear();list.replaceChildren();
    for(const entry of files||[]){
      const key=String(entry.key||'');
      if(!key||rows.has(key))continue;
      rows.set(key,{...entry,state:'waiting',percent:0,node:null});
      file(key,'waiting',0);
    }
    totals();
  }
  function begin({name,files,onCancel,onRetry}) {
    if (mode === 'uploading' || mode === 'reviewing') return false;
    previousFocus=document.activeElement;
    committed=false;
    sessionVoiced=new Set();
    callbacks={onCancel,onRetry};
    mode='uploading';
    setFiles(files);
    title.textContent = `Importing ${String(name || 'REAPER project').slice(0,140)}`;
    errorBox.hidden=true;errorBox.textContent='';
    cancelBtn.hidden=false;cancelBtn.disabled=false;
    retryBtn.hidden=true;closeBtn.hidden=true;viewLink.hidden=true;
    overlay.dataset.state='uploading';
    setPhase('Preparing REAPER files…',1);
    totals();
    show();
    announce('start',rows.size>1 ? 'I am uploading your REAPER stems now.' : 'I am uploading your REAPER project now.');
    return true;
  }
  function phase(text,percent) {
    if(mode==='uploading') setPhase(text,percent);
  }
  function rppUploaded() {
    file('rpp','uploaded',100);
    announce('rpp-uploaded','Your REAPER file upload is complete. I am preparing the stems for import.');
  }
  function importing() {
    announce('importing','I am importing your stems into the music library now.');
  }
  function finish({stemCount=0,url=''}) {
    if(mode==='idle') return;
    committed=true;
    mode='complete';
    overlay.dataset.state='complete';
    for (const [key,row] of rows) {
      if(row.state!=='complete') file(key,'complete',100);
    }
    // file() is deliberately disabled in complete mode, so finalize directly.
    for(const row of rows.values()) {
      row.state='complete';row.percent=100;
      if(row.node){row.node.li.dataset.state='complete';row.node.state.textContent='Imported';row.node.fill.style.width='100%';}
    }
    totals();
    setPhase(`Import complete · ${Number(stemCount)||0} stems saved`,100);
    cancelBtn.hidden=true;retryBtn.hidden=true;closeBtn.hidden=false;
    viewLink.hidden=!url;
    if(url) viewLink.href=url;
    show();
    announce('success',Number(stemCount)>0 ? 'Your REAPER project upload is complete. Your stems are imported into the music library.' : 'Your REAPER file upload is complete, and the project is saved.');
  }
  function failed(message,key='') {
    if(committed) return;
    if(mode==='idle') return;
    if(mode==='reviewing') resume();
    mode='failed';
    overlay.dataset.state='failed';
    if(key) file(key,'failed',0);
    errorBox.textContent=String(message||'The import failed. Your previously saved project is unchanged.').slice(0,700);
    errorBox.hidden=false;
    setPhase('Import failed · review the error and retry',Number(track.getAttribute('aria-valuenow'))||0);
    cancelBtn.hidden=true;retryBtn.hidden=false;closeBtn.hidden=false;viewLink.hidden=true;
    show();
    announce('failed','Your REAPER upload failed. Please review the error and try again.');
  }
  function cancelled() {
    if(committed||mode==='idle')return;
    if(mode==='reviewing')resume();
    mode='cancelled';overlay.dataset.state='cancelled';
    errorBox.hidden=false;
    errorBox.textContent='Import cancelled. You can retry with the selected files.';
    setPhase('Upload cancelled',Number(track.getAttribute('aria-valuenow'))||0);
    cancelBtn.hidden=true;retryBtn.hidden=false;closeBtn.hidden=false;
    show();
  }
  function close() {
    if(mode==='uploading'||mode==='reviewing')return;
    overlay.hidden=true;document.body.classList.remove('vp3-music-import-open');
    mode='idle';
    if(previousFocus?.isConnected) previousFocus.focus();
  }
  cancelBtn.addEventListener('click',()=>{if(mode==='uploading'&&typeof callbacks.onCancel==='function')callbacks.onCancel();});
  retryBtn.addEventListener('click',()=>{if(mode!=='failed'&&mode!=='cancelled')return;close();if(typeof callbacks.onRetry==='function')callbacks.onRetry();});
  closeBtn.addEventListener('click',close);
  overlay.addEventListener('keydown',event=>{
    if(event.key==='Escape' && mode!=='uploading' && mode!=='reviewing'){event.preventDefault();close();}
  });
  window.VP3MusicImportProgress={begin,setFiles,phase,file,pause,resume,rppUploaded,importing,finish,failed,cancelled,close};
})();
