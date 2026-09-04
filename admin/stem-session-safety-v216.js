(function(root,factory){
  'use strict';
  const api=factory();
  root.StonefellowStemSessionSafetyV216=api;
  if(root.document)api.install(root,root.document);
})(typeof globalThis!=='undefined'?globalThis:window,function(){
  'use strict';

  const BUILD='stem-session-safety-v216-20260901';
  const AUTOSAVE_NAME='__V216_AUTOSAVE__';
  const SLOT_A_NAME='__V216_SLOT_A__';
  const SLOT_B_NAME='__V216_SLOT_B__';
  const CHECKPOINT_PREFIX='__V216_CHECKPOINT__:';
  const HIDDEN_PREFIX='__V216_';
  const AUTOSAVE_DELAY=2200;
  const AUTOSAVE_MIN_GAP=3500;
  const SAMPLE_INTERVAL=800;
  const clone=value=>JSON.parse(JSON.stringify(value));
  const num=(value,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;

  function canonicalize(value,seen=new WeakSet()){
    if(value===null||typeof value!=='object'){
      if(typeof value==='number'&&!Number.isFinite(value))return 0;
      if(typeof value==='undefined'||typeof value==='function')return null;
      return value;
    }
    if(seen.has(value))return null;
    seen.add(value);
    if(Array.isArray(value)){
      const result=value.map(item=>canonicalize(item,seen));
      seen.delete(value);
      return result;
    }
    const result={};
    Object.keys(value).sort().forEach(key=>{
      const item=value[key];
      if(typeof item==='undefined'||typeof item==='function')return;
      result[key]=canonicalize(item,seen);
    });
    seen.delete(value);
    return result;
  }

  function stableStringify(value){return JSON.stringify(canonicalize(value));}

  function hashString(value){
    const text=String(value||'');
    let hash=0x811c9dc5;
    for(let index=0;index<text.length;index+=1){
      hash^=text.charCodeAt(index);
      hash=Math.imul(hash,0x01000193)>>>0;
    }
    return hash.toString(16).padStart(8,'0');
  }

  function sessionSignature(coreState,v211State,v215State){
    return `v216:${hashString(stableStringify({core:coreState||{},automationV211:v211State||{},audioV215:v215State||{}}))}`;
  }

  function checkpointLabel(value){return String(value||'').replace(/\s+/g,' ').trim().slice(0,120);}

  function reservedName(kind,now=Date.now()){
    if(kind==='autosave')return AUTOSAVE_NAME;
    if(kind==='slot_a')return SLOT_A_NAME;
    if(kind==='slot_b')return SLOT_B_NAME;
    if(kind==='checkpoint')return `${CHECKPOINT_PREFIX}${Math.max(0,Number(now)||0)}`;
    return '';
  }

  function classifyMixName(name){
    const value=String(name||'');
    if(value===AUTOSAVE_NAME)return 'autosave';
    if(value===SLOT_A_NAME)return 'slot_a';
    if(value===SLOT_B_NAME)return 'slot_b';
    if(value.startsWith(CHECKPOINT_PREFIX))return 'checkpoint';
    return 'user_save';
  }

  function isHiddenMixName(name){return String(name||'').startsWith(HIDDEN_PREFIX);}

  function unsupportedV211Slice(value){
    const raw=value&&typeof value==='object'?value:{};
    return {
      pluginTargets:raw.pluginTargets||{},
      pluginAutomation:raw.pluginAutomation||{},
      draw:raw.draw||{},
      peakHoldMs:Number(raw.peakHoldMs||1500)
    };
  }

  function needsV211Reload(current,next){
    return stableStringify(unsupportedV211Slice(current))!==stableStringify(unsupportedV211Slice(next));
  }

  function install(root,document){
    if(!document||root.__STONEFELLOW_STEM_V216_INSTALLED__)return false;
    root.__STONEFELLOW_STEM_V216_INSTALLED__=true;

    const cfg=root.STONEFELLOW_STEM_STUDIO||{};
    const ext=root.STONEFELLOW_STEM_SESSION_V216||{};
    const trackId=Number(cfg.trackId||0);
    const userId=Number(cfg.userId||0);
    const dirtyKey=`stonefellow:stem:v216:dirty:${userId}:${trackId}`;
    const pendingKey=`stonefellow:stem:v216:pending:${userId}:${trackId}`;
    const v211StorageKey=`stonefellow:stem:v211:${userId}:${trackId}`;

    let attempts=0;
    let toolbar=null;
    let modal=null;
    let statusTimer=0;
    let sampleTimer=0;
    let autosaveTimer=0;
    let lastAutosaveAt=0;
    let currentSignature='';
    let lastExplicitSignature='';
    let lastAutosaveSignature='';
    let dirty=false;
    let busy=false;
    let suppressLoadHook=false;
    let pendingRestoreActive=false;
    let fetchWrapper=null;
    let nextFetch=null;
    let indexState={autosave:null,slot_a:null,slot_b:null,checkpoints:[]};

    const studio=()=>root.StonefellowStemStudioV91||null;
    const core=()=>root.STONEFELLOW_STUDIO_RUNTIME_V87||null;
    const v211=()=>root.StonefellowStemAutomationMixerV211Runtime||null;
    const v215=()=>root.StonefellowStemAudioEngineV215Runtime||null;
    const v215Hardening=()=>root.StonefellowStemAudioEngineV215Hardening||null;

    function readJsonStorage(storage,key){
      try{return JSON.parse(storage?.getItem(key)||'null');}catch(error){return null;}
    }

    function writeJsonStorage(storage,key,value){
      try{storage?.setItem(key,JSON.stringify(value));return true;}catch(error){return false;}
    }

    function removeStorage(storage,key){try{storage?.removeItem(key);}catch(error){}}

    function captureSession(){
      const coreState=studio()?.collectMixState?.()||studio()?.getMixState?.()||{};
      const automationV211=v211()?.getSettings?.()||{};
      const audioV215=v215()?.getSettings?.()||{};
      return {
        core:clone(coreState),
        automationV211:clone(automationV211),
        audioV215:clone(audioV215),
        signature:sessionSignature(coreState,automationV211,audioV215),
        capturedAt:Date.now()
      };
    }

    function formatClock(value){
      const date=value instanceof Date?value:new Date(value||Date.now());
      try{return date.toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});}catch(error){return '';} 
    }

    function stateEl(){return toolbar?.querySelector('[data-v216-state]')||null;}
    function autoEl(){return toolbar?.querySelector('[data-v216-auto]')||null;}

    function setState(text,kind=''){
      const node=stateEl();
      if(!node)return;
      node.textContent=String(text||'');
      node.dataset.state=String(kind||'');
      toolbar?.classList.toggle('is-dirty',kind==='dirty');
      toolbar?.classList.toggle('is-saving',kind==='saving');
      toolbar?.classList.toggle('is-error',kind==='error');
    }

    function setAutosaveText(text){const node=autoEl();if(node)node.textContent=String(text||'');}

    function showToast(message,kind=''){
      let node=document.getElementById('stemV216Toast');
      if(!node){node=document.createElement('div');node.id='stemV216Toast';node.className='sf-v216-toast';document.body.appendChild(node);}
      node.textContent=String(message||'');
      node.classList.toggle('error',kind==='error');
      node.classList.add('show');
      root.clearTimeout(statusTimer);
      statusTimer=root.setTimeout(()=>node.classList.remove('show'),2400);
    }

    function persistDirtyMarker(){
      if(!dirty){removeStorage(root.localStorage,dirtyKey);return;}
      writeJsonStorage(root.localStorage,dirtyKey,{
        build:BUILD,
        dirty:true,
        signature:currentSignature,
        lastExplicitSignature,
        changedAt:Date.now()
      });
    }

    function markDirty(signature=currentSignature){
      currentSignature=String(signature||currentSignature||'');
      dirty=currentSignature!==''&&currentSignature!==lastExplicitSignature;
      persistDirtyMarker();
      if(dirty)setState(lastAutosaveSignature===currentSignature?'AUTOSAVED · UNSAVED VERSION':'UNSAVED CHANGES','dirty');
      else setState('SAVED','saved');
      if(dirty)scheduleAutosave();
      return dirty;
    }

    function markExplicitSaved(signature=currentSignature){
      lastExplicitSignature=String(signature||currentSignature||'');
      currentSignature=captureSession().signature;
      dirty=currentSignature!==lastExplicitSignature;
      persistDirtyMarker();
      if(dirty){setState('UNSAVED CHANGES','dirty');scheduleAutosave();}
      else setState('SAVED','saved');
    }

    function mixPayload(input,init){
      if(typeof init?.body!=='string'||!cfg.mixEndpoint)return null;
      try{
        const actual=new URL(typeof input==='string'?input:input?.url||'',root.location.href).href;
        const target=new URL(String(cfg.mixEndpoint),root.location.href).href;
        if(actual!==target)return null;
        const body=JSON.parse(init.body);
        return body&&typeof body==='object'?body:null;
      }catch(error){return null;}
    }

    async function endpointRequest(action,fields={}){
      if(!nextFetch||!ext.endpoint)throw new Error('Session safety endpoint is unavailable.');
      const form=new root.FormData();
      form.append('csrf_token',String(cfg.csrf||''));
      form.append('action',String(action));
      form.append('track_id',String(trackId));
      Object.entries(fields).forEach(([key,value])=>{
        if(value===undefined||value===null)return;
        form.append(key,String(value));
      });
      const response=await nextFetch(String(ext.endpoint),{method:'POST',credentials:'same-origin',body:form});
      const data=await response.json().catch(()=>null);
      if(!response.ok||!data?.ok)throw new Error(data?.error||'Session request failed.');
      return data;
    }

    async function attachSession(mixId,envelope,kind,label=''){
      if(!(Number(mixId)>0)||!envelope)return null;
      return endpointRequest('attach',{
        mix_id:Number(mixId),
        kind,
        label:checkpointLabel(label),
        signature:envelope.signature,
        saved_at:envelope.capturedAt,
        automation_json:JSON.stringify(envelope.automationV211||{})
      });
    }

    async function loadExtras(mixId){
      if(!(Number(mixId)>0))return null;
      return endpointRequest('load',{mix_id:Number(mixId)});
    }

    async function refreshIndex(){
      try{
        const data=await endpointRequest('index');
        indexState=data.records||indexState;
        if(!Array.isArray(indexState.checkpoints))indexState.checkpoints=[];
        refreshSlotUi();
        renderCheckpointList();
        return indexState;
      }catch(error){
        console.warn('Stem v216 index:',error);
        return indexState;
      }
    }

    function filterHiddenMixes(response){
      if(!root.Response||!response?.ok)return response;
      return response.clone().json().then(data=>{
        if(!Array.isArray(data?.mixes))return response;
        data.mixes=data.mixes.filter(row=>!isHiddenMixName(row?.mix_name));
        const headers=new root.Headers(response.headers);
        headers.delete('content-length');
        headers.set('content-type','application/json; charset=utf-8');
        return new root.Response(JSON.stringify(data),{status:response.status,statusText:response.statusText,headers});
      }).catch(()=>response);
    }

    function applyV211Settings(nextSettings){
      if(!nextSettings||typeof nextSettings!=='object')return false;
      const runtime=v211();
      const current=runtime?.getSettings?.()||{};
      const reload=needsV211Reload(current,nextSettings);
      const currentModes=current.modes||{};
      const nextModes=nextSettings.modes||{};
      const ids=new Set([...Object.keys(currentModes),...Object.keys(nextModes)]);
      ids.forEach(key=>{
        const id=Number(key||0);
        if(id>0)runtime?.setMode?.(id,nextModes[key]||'read');
      });
      runtime?.setDensity?.(nextSettings.density||'normal');
      runtime?.setFollowClips?.(nextSettings.followClips!==false);
      writeJsonStorage(root.localStorage,v211StorageKey,nextSettings);
      return reload;
    }

    function queueReloadRestore(mixId,kind,name=''){
      writeJsonStorage(root.sessionStorage,pendingKey,{mixId:Number(mixId),kind:String(kind||''),name:String(name||''),queuedAt:Date.now()});
      root.setTimeout(()=>root.location.reload(),180);
    }

    async function sessionFetch(input,init){
      const payload=mixPayload(input,init);
      const envelope=payload?.action==='save'?captureSession():null;
      let response=await nextFetch(input,init);
      if(!payload||!response)return response;

      if(payload.action==='list')return filterHiddenMixes(response);

      if(payload.action==='save'&&response.ok){
        try{
          const data=await response.clone().json();
          const mixId=Number(data?.mix_id||0);
          const kind=classifyMixName(payload.mix_name);
          const label=kind==='checkpoint'?String(payload.v216_label||''):kind==='slot_a'?'A':kind==='slot_b'?'B':'';
          if(mixId>0&&envelope)await attachSession(mixId,envelope,kind,label);
          if(kind==='user_save')markExplicitSaved(envelope?.signature||'');
        }catch(error){
          console.warn('Stem v216 save sidecar:',error);
          if(classifyMixName(payload.mix_name)==='user_save')showToast('Mix saved, but session extras could not be attached.','error');
        }
        return response;
      }

      if(payload.action==='load'&&response.ok&&!suppressLoadHook){
        try{
          const mixId=Number(payload.mix_id||0);
          const extras=await loadExtras(mixId);
          const next=extras?.session?.automationV211;
          const reload=extras?.has_session&&next?applyV211Settings(next):false;
          if(reload){
            queueReloadRestore(mixId,'explicit','');
          }else{
            root.setTimeout(()=>{
              currentSignature=captureSession().signature;
              lastExplicitSignature=currentSignature;
              dirty=false;
              persistDirtyMarker();
              setState('SAVED','saved');
            },160);
          }
        }catch(error){console.warn('Stem v216 load sidecar:',error);}
      }
      return response;
    }

    function installFetchBridge(){
      if(fetchWrapper||!root.fetch||!cfg.mixEndpoint||!ext.endpoint)return false;
      nextFetch=root.fetch.bind(root);
      fetchWrapper=sessionFetch;
      root.fetch=fetchWrapper;
      return true;
    }

    async function saveReserved(kind,label=''){
      if(busy)return null;
      const name=reservedName(kind);
      if(!name)throw new Error('Invalid session record type.');
      const existing=kind==='autosave'?indexState.autosave:kind==='slot_a'?indexState.slot_a:kind==='slot_b'?indexState.slot_b:null;
      const envelope=captureSession();
      busy=true;
      if(kind==='autosave')setState('AUTOSAVING…','saving');
      try{
        const result=await studio()?.mixRequest?.('save',{
          mix_id:Number(existing?.id||0),
          mix_name:name,
          state:envelope.core,
          v216_label:checkpointLabel(label)
        });
        const mixId=Number(result?.mix_id||0);
        if(!(mixId>0))throw new Error('Session save did not return a mix id.');
        if(kind==='autosave'){
          lastAutosaveAt=Date.now();
          lastAutosaveSignature=envelope.signature;
          indexState.autosave={...(indexState.autosave||{}),id:mixId,name,signature:envelope.signature,saved_at:envelope.capturedAt,label:''};
          setAutosaveText(`AUTOSAVED ${formatClock(Date.now())}`);
          markDirty(captureSession().signature);
        }else{
          await refreshIndex();
          showToast(kind==='checkpoint'?`Checkpoint “${checkpointLabel(label)||'Checkpoint'}” saved.`:`Snapshot ${kind==='slot_a'?'A':'B'} captured.`);
        }
        return {mixId,envelope};
      }finally{
        busy=false;
        if(kind==='autosave'&&dirty)setState(lastAutosaveSignature===currentSignature?'AUTOSAVED · UNSAVED VERSION':'UNSAVED CHANGES','dirty');
      }
    }

    function scheduleAutosave(delay=AUTOSAVE_DELAY){
      if(!cfg.canSaveMix||!ext.endpoint||busy)return;
      root.clearTimeout(autosaveTimer);
      const gap=Math.max(0,AUTOSAVE_MIN_GAP-(Date.now()-lastAutosaveAt));
      autosaveTimer=root.setTimeout(async()=>{
        if(!dirty)return;
        if(core()?.isCoreRecording?.()){
          scheduleAutosave(1600);
          return;
        }
        try{await saveReserved('autosave');}
        catch(error){setState('AUTOSAVE FAILED','error');setAutosaveText('LOCAL RECOVERY ACTIVE');console.warn('Stem v216 autosave:',error);scheduleAutosave(6000);}
      },Math.max(delay,gap));
    }

    async function loadRecord(mixId,options={}){
      const id=Number(mixId||0);
      if(!(id>0)||busy)return false;
      busy=true;
      pendingRestoreActive=true;
      setState('RESTORING…','saving');
      try{
        const extras=await loadExtras(id).catch(()=>null);
        const reloadNeeded=extras?.has_session&&extras?.session?.automationV211?applyV211Settings(extras.session.automationV211):false;
        suppressLoadHook=true;
        const data=await studio()?.mixRequest?.('load',{mix_id:id});
        suppressLoadHook=false;
        if(!data?.mix?.state)throw new Error('Saved session state is unavailable.');
        studio()?.applyMixState?.(data.mix.state);
        if(options.kind==='explicit')studio()?.setSelectedMixRef?.(id,String(data.mix.mix_name||options.name||''));
        await new Promise(resolve=>root.setTimeout(resolve,80));
        currentSignature=captureSession().signature;
        if(options.kind==='explicit'){
          lastExplicitSignature=currentSignature;
          dirty=false;
          persistDirtyMarker();
          setState('SAVED','saved');
        }else{
          dirty=currentSignature!==lastExplicitSignature;
          persistDirtyMarker();
          setState(options.kind==='recovery'?'RECOVERED · UNSAVED VERSION':'RESTORED · UNSAVED VERSION','dirty');
          scheduleAutosave(900);
        }
        if(reloadNeeded&&options.allowReload!==false){
          queueReloadRestore(id,options.kind||'snapshot',String(data.mix.mix_name||''));
          return true;
        }
        if(options.kind==='recovery')showToast('Recovered the last autosaved Studio session.');
        else if(options.kind==='slot_a'||options.kind==='slot_b')showToast(`Snapshot ${options.kind==='slot_a'?'A':'B'} recalled.`);
        else if(options.kind==='checkpoint')showToast('Checkpoint restored.');
        return true;
      }catch(error){
        suppressLoadHook=false;
        setState('RESTORE FAILED','error');
        showToast(error.message||'Could not restore session.','error');
        return false;
      }finally{
        busy=false;
        pendingRestoreActive=false;
      }
    }

    async function createCheckpoint(label){
      const clean=checkpointLabel(label)||`Checkpoint ${indexState.checkpoints.length+1}`;
      const result=await saveReserved('checkpoint',clean);
      return Boolean(result);
    }

    async function deleteCheckpoint(id){
      try{
        await endpointRequest('delete_checkpoint',{mix_id:Number(id)});
        await refreshIndex();
        showToast('Checkpoint deleted.');
        return true;
      }catch(error){showToast(error.message||'Could not delete checkpoint.','error');return false;}
    }

    function refreshSlotUi(){
      if(!toolbar)return;
      const a=toolbar.querySelector('[data-v216-load-slot="a"]');
      const b=toolbar.querySelector('[data-v216-load-slot="b"]');
      if(a)a.disabled=!(Number(indexState.slot_a?.id)>0);
      if(b)b.disabled=!(Number(indexState.slot_b?.id)>0);
      toolbar.querySelector('[data-v216-set-slot="a"]')?.classList.toggle('has-slot',Number(indexState.slot_a?.id)>0);
      toolbar.querySelector('[data-v216-set-slot="b"]')?.classList.toggle('has-slot',Number(indexState.slot_b?.id)>0);
    }

    function buildToolbar(){
      const host=document.querySelector('.daw-mixer-toolbar');
      if(!host)return false;
      if(host.querySelector('[data-v216-toolbar]')){toolbar=host.querySelector('[data-v216-toolbar]');return true;}
      toolbar=document.createElement('div');
      toolbar.className='sf-v216-toolbar';
      toolbar.dataset.v216Toolbar=BUILD;
      toolbar.innerHTML=`<span class="sf-v216-title">SESSION</span><strong data-v216-state data-state="saved">SAVED</strong><small data-v216-auto>SERVER AUTOSAVE READY</small><button type="button" data-v216-save>SAVE</button><button type="button" data-v216-save-as>SAVE AS</button><span class="sf-v216-ab"><button type="button" data-v216-set-slot="a">SET A</button><button type="button" data-v216-load-slot="a" disabled>A</button><button type="button" data-v216-set-slot="b">SET B</button><button type="button" data-v216-load-slot="b" disabled>B</button></span><button type="button" data-v216-checkpoints>CHECKPOINTS</button>`;
      host.appendChild(toolbar);
      toolbar.querySelector('[data-v216-save]').addEventListener('click',()=>{
        const button=document.getElementById('studioSaveButton');
        if(button)button.click();else void studio()?.saveCurrentVersion?.();
      });
      toolbar.querySelector('[data-v216-save-as]').addEventListener('click',()=>document.getElementById('studioSaveAsButton')?.click());
      toolbar.querySelector('[data-v216-set-slot="a"]').addEventListener('click',()=>void saveReserved('slot_a'));
      toolbar.querySelector('[data-v216-set-slot="b"]').addEventListener('click',()=>void saveReserved('slot_b'));
      toolbar.querySelector('[data-v216-load-slot="a"]').addEventListener('click',()=>void loadRecord(indexState.slot_a?.id,{kind:'slot_a'}));
      toolbar.querySelector('[data-v216-load-slot="b"]').addEventListener('click',()=>void loadRecord(indexState.slot_b?.id,{kind:'slot_b'}));
      toolbar.querySelector('[data-v216-checkpoints]').addEventListener('click',()=>openCheckpointModal());
      refreshSlotUi();
      return true;
    }

    function ensureModal(){
      if(modal)return modal;
      modal=document.createElement('div');
      modal.className='sf-v216-modal';
      modal.hidden=true;
      modal.innerHTML=`<div class="sf-v216-modal-backdrop" data-v216-close></div><section role="dialog" aria-modal="true" aria-labelledby="v216CheckpointTitle"><header><div><span>SESSION SAFETY</span><h2 id="v216CheckpointTitle">Checkpoints</h2></div><button type="button" data-v216-close aria-label="Close">×</button></header><div class="sf-v216-create"><input type="text" maxlength="120" data-v216-checkpoint-name placeholder="Checkpoint name"><button type="button" data-v216-create-checkpoint>CREATE CHECKPOINT</button></div><div class="sf-v216-checkpoint-list" data-v216-checkpoint-list></div></section>`;
      document.body.appendChild(modal);
      modal.querySelectorAll('[data-v216-close]').forEach(node=>node.addEventListener('click',()=>{modal.hidden=true;}));
      modal.querySelector('[data-v216-create-checkpoint]').addEventListener('click',async()=>{
        const input=modal.querySelector('[data-v216-checkpoint-name]');
        const label=checkpointLabel(input?.value||'');
        if(await createCheckpoint(label)){if(input)input.value='';renderCheckpointList();}
      });
      modal.addEventListener('keydown',event=>{if(event.key==='Escape')modal.hidden=true;});
      return modal;
    }

    function checkpointTimestamp(row){
      const value=Number(row?.saved_at||0);
      if(value>0)return new Date(value).toLocaleString();
      return String(row?.updated_at||'');
    }

    function renderCheckpointList(){
      const box=ensureModal().querySelector('[data-v216-checkpoint-list]');
      if(!box)return;
      const rows=Array.isArray(indexState.checkpoints)?indexState.checkpoints:[];
      if(!rows.length){box.innerHTML='<p class="sf-v216-empty">No checkpoints yet. Create one before a major edit, comp, mix or render change.</p>';return;}
      box.innerHTML='';
      rows.forEach(row=>{
        const item=document.createElement('article');
        item.className='sf-v216-checkpoint';
        const copy=document.createElement('div');
        const title=document.createElement('strong');title.textContent=String(row.label||'Checkpoint');
        const time=document.createElement('small');time.textContent=checkpointTimestamp(row);
        copy.append(title,time);
        const actions=document.createElement('div');
        const restore=document.createElement('button');restore.type='button';restore.textContent='RESTORE';restore.addEventListener('click',()=>{modal.hidden=true;void loadRecord(row.id,{kind:'checkpoint'});});
        const remove=document.createElement('button');remove.type='button';remove.textContent='DELETE';remove.className='danger';remove.addEventListener('click',()=>void deleteCheckpoint(row.id));
        actions.append(restore,remove);item.append(copy,actions);box.appendChild(item);
      });
    }

    async function openCheckpointModal(){
      ensureModal().hidden=false;
      await refreshIndex();
      const input=modal.querySelector('[data-v216-checkpoint-name]');
      root.setTimeout(()=>input?.focus(),0);
    }

    function sample(){
      if(busy||pendingRestoreActive)return;
      try{
        const signature=captureSession().signature;
        if(!currentSignature){currentSignature=signature;return;}
        if(signature===currentSignature)return;
        currentSignature=signature;
        markDirty(signature);
      }catch(error){}
    }

    async function restorePendingOrCrash(){
      const pending=readJsonStorage(root.sessionStorage,pendingKey);
      if(pending?.mixId){
        removeStorage(root.sessionStorage,pendingKey);
        await loadRecord(Number(pending.mixId),{kind:String(pending.kind||'snapshot'),name:String(pending.name||''),allowReload:false});
        return true;
      }
      const marker=readJsonStorage(root.localStorage,dirtyKey);
      if(marker?.dirty&&Number(indexState.autosave?.id)>0){
        lastExplicitSignature=String(marker.lastExplicitSignature||'');
        await loadRecord(Number(indexState.autosave.id),{kind:'recovery',allowReload:false});
        return true;
      }
      return false;
    }

    async function startup(){
      await refreshIndex();
      const recovered=await restorePendingOrCrash();
      currentSignature=captureSession().signature;
      if(!recovered){
        const marker=readJsonStorage(root.localStorage,dirtyKey);
        if(marker?.dirty){
          lastExplicitSignature=String(marker.lastExplicitSignature||'');
          dirty=currentSignature!==lastExplicitSignature;
          if(dirty){setState('LOCAL RECOVERY · UNSAVED','dirty');scheduleAutosave(700);}
        }else{
          lastExplicitSignature=currentSignature;
          dirty=false;
          setState('SAVED','saved');
        }
      }
      if(indexState.autosave?.saved_at)setAutosaveText(`AUTOSAVED ${formatClock(indexState.autosave.saved_at)}`);
      sampleTimer=root.setInterval(sample,SAMPLE_INTERVAL);
      root.dispatchEvent(new CustomEvent('stonefellow:stem-session-safety-v216',{detail:{build:BUILD}}));
    }

    function lateBind(){
      if(!studio()||!core()||!v211()||!v215()||!v215Hardening()||!cfg.canSaveMix||!ext.endpoint||!buildToolbar()){
        attempts+=1;
        if(attempts<260)root.setTimeout(lateBind,60);
        else root.__STONEFELLOW_STEM_V216_INSTALLED__=false;
        return;
      }
      installFetchBridge();
      void startup();
    }
    lateBind();

    root.addEventListener('pagehide',()=>{
      root.clearInterval(sampleTimer);
      root.clearTimeout(autosaveTimer);
      if(dirty)persistDirtyMarker();
      if(fetchWrapper&&root.fetch===fetchWrapper)root.fetch=nextFetch;
    },{once:true});

    root.StonefellowStemSessionSafetyV216Runtime={
      build:BUILD,
      capture:()=>clone(captureSession()),
      getState:()=>({dirty,currentSignature,lastExplicitSignature,lastAutosaveSignature,records:clone(indexState)}),
      autosave:()=>saveReserved('autosave'),
      setA:()=>saveReserved('slot_a'),
      setB:()=>saveReserved('slot_b'),
      recallA:()=>loadRecord(indexState.slot_a?.id,{kind:'slot_a'}),
      recallB:()=>loadRecord(indexState.slot_b?.id,{kind:'slot_b'}),
      checkpoint:createCheckpoint,
      restoreCheckpoint:id=>loadRecord(id,{kind:'checkpoint'}),
      deleteCheckpoint,
      refreshIndex,
      markExplicitSaved,
      applyV211Settings
    };
    return true;
  }

  return Object.freeze({
    build:BUILD,
    stableStringify,
    hashString,
    sessionSignature,
    checkpointLabel,
    reservedName,
    classifyMixName,
    isHiddenMixName,
    needsV211Reload,
    install
  });
});
