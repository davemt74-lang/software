(function(root,factory){
  'use strict';
  const api=factory();
  root.StonefellowStemAudioEngineV215=api;
  if(root.document)api.install(root,root.document);
})(typeof globalThis!=='undefined'?globalThis:window,function(){
  'use strict';

  const BUILD='stem-audio-engine-v215-20260901';
  const MAX_DELAY_MS=1500;
  const MAX_MANUAL_MS=500;
  const clone=value=>JSON.parse(JSON.stringify(value));
  const num=(value,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;
  const clamp=(value,min,max)=>Math.min(max,Math.max(min,num(value,min)));
  const sleep=(root,ms)=>new Promise(resolve=>root.setTimeout(resolve,ms));
  const PLUGIN_LATENCY_MS=Object.freeze({eq5:0,delay:0,reverb:0,compressor:6,limiter:6});

  function singlePluginLatencyMs(plugin,sampleRate=48000){
    if(!plugin||plugin.enabled===false)return 0;
    const explicit=[plugin.latency_ms,plugin.latencyMs,plugin.params?.latency_ms,plugin.params?.latencyMs,plugin.params?.lookahead_ms,plugin.params?.lookaheadMs]
      .map(Number).find(value=>Number.isFinite(value)&&value>=0);
    if(Number.isFinite(explicit))return clamp(explicit,0,MAX_DELAY_MS);
    const type=String(plugin.type||plugin.name||'').toLowerCase();
    if(type==='compressor'||type==='limiter')return 6;
    if(/convolver|convolution/.test(type))return 128/Math.max(8000,num(sampleRate,48000))*1000;
    return Number(PLUGIN_LATENCY_MS[type]||0);
  }

  function pluginLatencyMs(plugins,sampleRate=48000){
    if(!Array.isArray(plugins))return singlePluginLatencyMs(plugins,sampleRate);
    return plugins.reduce((sum,plugin)=>sum+singlePluginLatencyMs(plugin,sampleRate),0);
  }

  function compensationPlan(rows){
    const clean=(Array.isArray(rows)?rows:[]).map(row=>({
      id:Number(row?.id||0),
      pluginMs:Math.max(0,num(row?.pluginMs,0)),
      manualMs:clamp(row?.manualMs,-MAX_MANUAL_MS,MAX_MANUAL_MS)
    })).filter(row=>row.id>0);
    const common=clean.reduce((max,row)=>Math.max(max,row.pluginMs),0);
    let minRaw=0;
    const prepared=clean.map(row=>{
      const raw=Math.max(0,common-row.pluginMs)+row.manualMs;
      minRaw=Math.min(minRaw,raw);
      return {...row,raw};
    });
    const shift=Math.max(0,-minRaw);
    return prepared.map(row=>({
      id:row.id,pluginMs:row.pluginMs,manualMs:row.manualMs,
      compensationMs:clamp(row.raw+shift,0,MAX_DELAY_MS),
      resultingMs:row.pluginMs+row.raw+shift,
      commonMs:common+shift
    }));
  }

  function contextLatency(context){
    const base=Math.max(0,num(context?.baseLatency,0));
    const output=Math.max(0,num(context?.outputLatency,0));
    return {baseSeconds:base,outputSeconds:output,totalSeconds:base+output,totalMs:(base+output)*1000};
  }

  function adjustedRecordingStart(startSeconds,context,manualOffsetMs=0,inputLatencyMs=0,roundTripMs=0){
    const start=Math.max(0,num(startSeconds,0));
    const browser=contextLatency(context).totalMs+Math.max(0,num(inputLatencyMs,0));
    const automatic=Math.max(0,num(roundTripMs,0))||browser;
    const advance=automatic+clamp(manualOffsetMs,-1000,1000);
    return Math.max(0,start-advance/1000);
  }

  function engineSettings(value={}){
    const next={
      pdc:value.pdc!==false,
      recordOffsetMs:clamp(value.recordOffsetMs??0,-1000,1000),
      preRollSeconds:clamp(value.preRollSeconds??1,0,10),
      postRollSeconds:clamp(value.postRollSeconds??2,0,15),
      tracks:{},
      freezes:value.freezes&&typeof value.freezes==='object'?clone(value.freezes):{},
      calibration:value.calibration&&typeof value.calibration==='object'?clone(value.calibration):null
    };
    Object.entries(value.tracks||{}).forEach(([id,row])=>{
      const key=String(Number(id)||0);
      if(key==='0')return;
      next.tracks[key]={
        manualDelayMs:clamp(row?.manualDelayMs??row?.delayMs??0,-MAX_MANUAL_MS,MAX_MANUAL_MS),
        polarity:Boolean(row?.polarity)
      };
    });
    return next;
  }

  function stemRows(state){
    if(Array.isArray(state?.stems))return state.stems.map((stem,index)=>({id:Number(stem?.id??index),stem}));
    if(state?.stems&&typeof state.stems==='object')return Object.entries(state.stems).map(([id,stem])=>({id:Number(stem?.id??id),stem}));
    return [];
  }

  function standardGroupKey(group){return ['vocals','rhythm','music'].includes(String(group||''))?`group-${group}`:'';}
  function routePlugins(state,group){
    const clean=String(group||'direct');
    if(clean==='direct')return [];
    const fixed=standardGroupKey(clean);
    if(fixed)return state?.channelPlugins?.[fixed]||[];
    const bus=(Array.isArray(state?.customBuses)?state.customBuses:[]).find(item=>String(item?.id??item?.key??'')===clean);
    return bus?.plugins||[];
  }

  function computePdcPlan(state,settings={},sampleRate=48000){
    const cfg=engineSettings(settings);
    const masterMs=pluginLatencyMs(state?.channelPlugins?.master||[],sampleRate);
    const auxAMs=pluginLatencyMs(state?.channelPlugins?.['aux-a']||[],sampleRate);
    const auxBMs=pluginLatencyMs(state?.channelPlugins?.['aux-b']||[],sampleRate);
    const paths=[];const tracks={};
    stemRows(state).forEach(({id,stem})=>{
      if(!(id>0)||!stem)return;
      const trackMs=pluginLatencyMs(stem.plugins||[],sampleRate);
      const routeMs=pluginLatencyMs(routePlugins(state,stem.group),sampleRate);
      const manual=clamp(cfg.tracks[String(id)]?.manualDelayMs??0,-MAX_MANUAL_MS,MAX_MANUAL_MS);
      const mainBase=trackMs+routeMs+masterMs;
      tracks[String(id)]={id,trackLatencyMs:trackMs,routeLatencyMs:routeMs,masterLatencyMs:masterMs,manualDelayMs:manual,paths:{main:{baseMs:mainBase}}};
      paths.push({id,key:'main',baseMs:mainBase,manualMs:manual});
      if(num(stem.sends?.a,0)>.0001){const base=trackMs+auxAMs+masterMs;tracks[String(id)].paths.auxA={baseMs:base};paths.push({id,key:'auxA',baseMs:base,manualMs:manual});}
      if(num(stem.sends?.b,0)>.0001){const base=trackMs+auxBMs+masterMs;tracks[String(id)].paths.auxB={baseMs:base};paths.push({id,key:'auxB',baseMs:base,manualMs:manual});}
    });
    const maxBase=cfg.pdc&&paths.length?Math.max(...paths.map(path=>path.baseMs)):0;
    let minRaw=0;
    paths.forEach(path=>{path.pdcMs=cfg.pdc?Math.max(0,maxBase-path.baseMs):0;path.rawMs=path.pdcMs+path.manualMs;minRaw=Math.min(minRaw,path.rawMs);});
    const globalShiftMs=Math.max(0,-minRaw);
    paths.forEach(path=>{
      const info=tracks[String(path.id)]?.paths?.[path.key];if(!info)return;
      info.pdcMs=path.pdcMs;info.delayMs=clamp(path.rawMs+globalShiftMs,0,MAX_DELAY_MS);
    });
    return {enabled:cfg.pdc,maxBaseLatencyMs:maxBase,globalShiftMs,masterLatencyMs:masterMs,auxLatencyMs:{a:auxAMs,b:auxBMs},tracks};
  }

  function selectedBounceRange(agent,range=null){
    const duration=Math.max(.05,num(agent?.duration,.05));
    if(range&&num(range.end,0)>num(range.start,0)+.01){
      const start=clamp(range.start,0,duration),end=clamp(range.end,start,duration);
      return {start,end,duration:end-start,source:'range'};
    }
    const selected=String(agent?.selected_clip_id||'');
    const clip=(agent?.clips||[]).find(item=>String(item?.id||'')===selected);
    if(clip){
      const start=clamp(clip.start,0,duration),end=clamp(start+num(clip.duration,0),start,duration);
      if(end>start+.01)return {start,end,duration:end-start,source:'clip'};
    }
    return {start:0,end:duration,duration,source:'track'};
  }

  function renderName(base,mode){
    const clean=String(base||'Audio').replace(/\s+(?:Freeze|Commit|Bounce)(?:\s+\d+)?$/i,'').trim()||'Audio';
    return `${clean} ${mode==='freeze'?'Freeze':mode==='commit'?'Commit':'Bounce'}`.slice(0,120);
  }

  function flattenChunks(chunks,channels=2){
    const rows=Array.isArray(chunks)?chunks:[];
    const total=rows.reduce((sum,row)=>sum+Number(row?.[0]?.length||0),0);
    return Array.from({length:channels},(_,channel)=>{
      const out=new Float32Array(total);let offset=0;
      rows.forEach(row=>{const src=row?.[Math.min(channel,(row?.length||1)-1)]||row?.[0]||new Float32Array(0);out.set(src,offset);offset+=src.length;});
      return out;
    });
  }

  function install(root,document){
    if(!document||root.__STONEFELLOW_STEM_V215_INSTALLED__)return false;
    root.__STONEFELLOW_STEM_V215_INSTALLED__=true;

    const cfg=root.STONEFELLOW_STEM_STUDIO||{};
    const ext=root.STONEFELLOW_STEM_AUDIO_V215||{};
    const storageKey=`stonefellow:stem:v215:${Number(cfg.userId||0)}:${Number(cfg.trackId||0)}`;
    const pendingKey=`${storageKey}:pending`;
    let settings=engineSettings();
    try{settings=engineSettings(JSON.parse(root.localStorage?.getItem(storageKey)||'null')||{});}catch(error){}
    let toolbar=null,modal=null,statusNode=null,observer=null,refreshTimer=0,bindAttempts=0;
    let nextFetch=null,fetchWrapper=null,originalGetMixState=null,originalApplyMixState=null,originalExecuteAgentCommand=null;
    let plan={enabled:true,maxBaseLatencyMs:0,tracks:{}};
    let offlineProvider=null;
    let prerollBypass=false;
    const patches=new Map();

    const core=()=>root.STONEFELLOW_STUDIO_RUNTIME_V87||null;
    const studio=()=>root.StonefellowStemStudioV91||null;
    const v210=()=>root.StonefellowStemProfessionalEditingV210Runtime||null;
    const v213=()=>root.StonefellowStemRecordingEngineV213Runtime||null;
    const agent=()=>{try{return studio()?.getAgentState?.()||{};}catch(error){return{};}};
    const context=()=>core()?.getContext?.()||null;
    const selectedId=()=>Number(core()?.getSelectedStemId?.()||agent().selected_id||0);

    function persist(){try{root.localStorage?.setItem(storageKey,JSON.stringify(settings));}catch(error){}}
    function setText(node,value){const text=String(value??'');if(node&&node.textContent!==text)node.textContent=text;}
    function trackSetting(id){
      const key=String(Number(id)||0);if(!settings.tracks[key])settings.tracks[key]={manualDelayMs:0,polarity:false};
      settings.tracks[key].manualDelayMs=clamp(settings.tracks[key].manualDelayMs,-MAX_MANUAL_MS,MAX_MANUAL_MS);
      settings.tracks[key].polarity=Boolean(settings.tracks[key].polarity);return settings.tracks[key];
    }
    function showStatus(message,kind=''){
      if(!statusNode){statusNode=document.createElement('div');statusNode.id='stemV215Status';statusNode.className='sf-v215-status';document.body.appendChild(statusNode);}
      setText(statusNode,message);statusNode.classList.toggle('error',kind==='error');statusNode.classList.add('show');root.clearTimeout(statusNode._timer);statusNode._timer=root.setTimeout(()=>statusNode.classList.remove('show'),2600);
    }

    function disconnect(source,destination){if(!source||!destination)return;try{source.disconnect(destination);}catch(error){}}

    function ensurePatch(stem){
      const ctx=context();const id=Number(stem?.id||0);if(!ctx||id<1||!stem?.liveCaptureTap||!stem?.analyserNode)return null;
      const existing=patches.get(id);
      if(existing&&existing.context===ctx&&existing.source===stem.liveCaptureTap)return existing;
      if(existing){try{existing.phase.disconnect();}catch(error){}try{existing.mainDelay.disconnect();}catch(error){}try{existing.auxADelay.disconnect();}catch(error){}try{existing.auxBDelay.disconnect();}catch(error){}patches.delete(id);}
      disconnect(stem.liveCaptureTap,stem.analyserNode);disconnect(stem.liveCaptureTap,stem.auxASendGain);disconnect(stem.liveCaptureTap,stem.auxBSendGain);
      const phase=ctx.createGain(),mainDelay=ctx.createDelay(2),auxADelay=ctx.createDelay(2),auxBDelay=ctx.createDelay(2);
      phase.gain.value=1;mainDelay.delayTime.value=0;auxADelay.delayTime.value=0;auxBDelay.delayTime.value=0;
      stem.liveCaptureTap.connect(phase);phase.connect(mainDelay);mainDelay.connect(stem.analyserNode);
      if(stem.auxASendGain){phase.connect(auxADelay);auxADelay.connect(stem.auxASendGain);}
      if(stem.auxBSendGain){phase.connect(auxBDelay);auxBDelay.connect(stem.auxBSendGain);}
      const patch={id,context:ctx,source:stem.liveCaptureTap,stem,phase,mainDelay,auxADelay,auxBDelay};patches.set(id,patch);return patch;
    }

    function restoreGraphs(){
      patches.forEach(patch=>{
        const stem=patch.stem;disconnect(stem?.liveCaptureTap,patch.phase);try{patch.phase.disconnect();}catch(error){}try{patch.mainDelay.disconnect();}catch(error){}try{patch.auxADelay.disconnect();}catch(error){}try{patch.auxBDelay.disconnect();}catch(error){}
        try{stem?.liveCaptureTap?.connect(stem.analyserNode);}catch(error){}try{if(stem?.auxASendGain)stem.liveCaptureTap.connect(stem.auxASendGain);}catch(error){}try{if(stem?.auxBSendGain)stem.liveCaptureTap.connect(stem.auxBSendGain);}catch(error){}
      });patches.clear();
    }

    function applyPdc(){
      const ctx=context(),s=studio();if(!ctx||!s)return false;
      const mix=s.getMixState?.()||{};plan=computePdcPlan(mix,settings,ctx.sampleRate||48000);
      (agent().stems||[]).forEach(row=>{
        const id=Number(row?.id||0),stem=core()?.getStem?.(id),patch=ensurePatch(stem);if(!patch)return;
        const info=plan.tracks[String(id)]||{},track=trackSetting(id),now=ctx.currentTime;
        patch.phase.gain.setTargetAtTime(track.polarity?-1:1,now,.004);
        patch.mainDelay.delayTime.setTargetAtTime(clamp(num(info.paths?.main?.delayMs,0)/1000,0,1.5),now,.01);
        patch.auxADelay.delayTime.setTargetAtTime(clamp(num(info.paths?.auxA?.delayMs,info.paths?.main?.delayMs||0)/1000,0,1.5),now,.01);
        patch.auxBDelay.delayTime.setTargetAtTime(clamp(num(info.paths?.auxB?.delayMs,info.paths?.main?.delayMs||0)/1000,0,1.5),now,.01);
      });
      decorateTracks();refreshUi();return true;
    }

    function currentLatency(){
      const ctx=context(),base=contextLatency(ctx);return {
        ...base,sampleRate:Number(ctx?.sampleRate||core()?.getSampleRate?.()||48000),
        inputMs:Math.max(0,num(settings.calibration?.reportedInputMs,0)),
        roundTripMs:Math.max(0,num(settings.calibration?.roundTripMs,0))
      };
    }

    function scheduleRefresh(delay=45){root.clearTimeout(refreshTimer);refreshTimer=root.setTimeout(()=>applyPdc(),delay);}

    function decorateTracks(){
      (agent().stems||[]).forEach(info=>{
        const id=Number(info.id||0),stem=core()?.getStem?.(id),host=stem?.mixer||stem?.leftRow;if(!host)return;
        let strip=host.querySelector(`[data-v215-track="${id}"]`);
        if(!strip){
          strip=document.createElement('div');strip.className='sf-v215-track-engine';strip.dataset.v215Track=String(id);
          strip.innerHTML=`<button type="button" data-v215-phase title="Invert polarity">Ø</button><label title="Manual track timing offset"><input data-v215-delay type="number" min="-${MAX_MANUAL_MS}" max="${MAX_MANUAL_MS}" step="0.1"><span>ms</span></label><small data-v215-pdc>PDC 0 ms</small><button type="button" data-v215-freeze>FREEZE</button>`;
          host.appendChild(strip);
          strip.querySelector('[data-v215-phase]').addEventListener('click',()=>{const row=trackSetting(id);row.polarity=!row.polarity;persist();scheduleRefresh();});
          strip.querySelector('[data-v215-delay]').addEventListener('change',event=>{trackSetting(id).manualDelayMs=clamp(event.currentTarget.value,-MAX_MANUAL_MS,MAX_MANUAL_MS);persist();scheduleRefresh();});
          strip.querySelector('[data-v215-freeze]').addEventListener('click',()=>void (settings.freezes[String(id)]?unfreezeTrack(id):freezeTrack(id)));
        }
        const row=trackSetting(id),p=plan.tracks[String(id)]||{};
        const phase=strip.querySelector('[data-v215-phase]');phase?.classList.toggle('active',row.polarity);phase?.setAttribute('aria-pressed',row.polarity?'true':'false');
        const delay=strip.querySelector('[data-v215-delay]');if(delay&&document.activeElement!==delay)delay.value=String(row.manualDelayMs);
        const badge=strip.querySelector('[data-v215-pdc]');setText(badge,`PDC ${num(p.paths?.main?.delayMs,0).toFixed(1)} ms`);
        const freeze=strip.querySelector('[data-v215-freeze]');setText(freeze,settings.freezes[String(id)]?'UNFREEZE':'FREEZE');
      });
    }

    function buildToolbar(){
      const host=document.querySelector('.daw-mixer-toolbar')||document.querySelector('.daw-toolbar');if(!host)return false;
      toolbar=host.querySelector('[data-v215-toolbar]');if(toolbar)return true;
      toolbar=document.createElement('div');toolbar.className='sf-v215-toolbar';toolbar.dataset.v215Toolbar=BUILD;
      toolbar.innerHTML='<button type="button" data-v215-open><b>ENGINE</b><span data-v215-summary>PDC</span></button>';
      host.appendChild(toolbar);toolbar.querySelector('[data-v215-open]').addEventListener('click',openModal);return true;
    }

    function buildModal(){
      if(modal)return true;modal=document.getElementById('stemAudioEngineDialogV215');if(modal)return true;
      modal=document.createElement('div');modal.id='stemAudioEngineDialogV215';modal.className='sf-v215-modal';modal.hidden=true;
      modal.innerHTML=`<div class="sf-v215-backdrop" data-v215-close></div><section role="dialog" aria-modal="true" aria-labelledby="stemV215Title"><header><div><small>STEM STUDIO v215</small><h2 id="stemV215Title">Audio Engine</h2></div><button type="button" data-v215-close>×</button></header><div class="sf-v215-grid"><article><h3>Latency & PDC</h3><label class="sf-v215-check"><input type="checkbox" data-v215-pdc> Plugin Delay Compensation</label><div class="sf-v215-metrics"><span><b data-v215-base>—</b><small>BASE</small></span><span><b data-v215-output>—</b><small>OUTPUT</small></span><span><b data-v215-input>—</b><small>INPUT EST.</small></span><span><b data-v215-max>—</b><small>MAX PATH</small></span></div><label>Manual recording correction <input data-v215-record-offset type="number" min="-1000" max="1000" step="0.1"> ms</label><p>Positive recording correction moves captured audio earlier. Browser/device latency is compensated automatically.</p><div class="sf-v215-actions"><button type="button" data-v215-probe>PROBE DEVICE</button><button type="button" data-v215-calibrate>CALIBRATE LOOPBACK</button></div><p data-v215-calibration-note>Loopback calibration is optional. Connect an output to the selected input before calibrating.</p></article><article><h3>Pre/Post Roll</h3><label>Recording / render pre-roll <input data-v215-pre type="number" min="0" max="10" step="0.1"> sec</label><label>Render tail / post-roll <input data-v215-post type="number" min="0" max="15" step="0.1"> sec</label><p>Pre-roll runs the transport before the record/render point. Render post-roll preserves delay and reverb tails.</p><div class="sf-v215-capability" data-v215-fast>FAST BOUNCE: REAL-TIME FALLBACK</div></article><article class="sf-v215-bounce"><h3>Bounce / Freeze / Commit</h3><p data-v215-selected>No track selected.</p><div class="sf-v215-actions"><button type="button" data-v215-bounce-range>BOUNCE RANGE / CLIP</button><button type="button" data-v215-bounce-track>BOUNCE TRACK</button><button type="button" data-v215-commit>COMMIT TRACK</button><button type="button" data-v215-freeze-selected>FREEZE TRACK</button></div><p>Freeze and Commit print track processing into new audio while preserving the source track. Bounce creates a muted rendered track so playback does not double.</p></article></div><footer><span data-v215-footer>Ready</span><button type="button" data-v215-close>DONE</button></footer></section>`;
      document.body.appendChild(modal);
      modal.querySelectorAll('[data-v215-close]').forEach(button=>button.addEventListener('click',closeModal));
      modal.querySelector('[data-v215-pdc]').addEventListener('change',event=>{settings.pdc=event.currentTarget.checked;persist();scheduleRefresh();});
      modal.querySelector('[data-v215-record-offset]').addEventListener('change',event=>{settings.recordOffsetMs=clamp(event.currentTarget.value,-1000,1000);persist();refreshUi();});
      modal.querySelector('[data-v215-pre]').addEventListener('change',event=>{settings.preRollSeconds=clamp(event.currentTarget.value,0,10);persist();refreshUi();});
      modal.querySelector('[data-v215-post]').addEventListener('change',event=>{settings.postRollSeconds=clamp(event.currentTarget.value,0,15);persist();refreshUi();});
      modal.querySelector('[data-v215-probe]').addEventListener('click',()=>void probeDevice());
      modal.querySelector('[data-v215-calibrate]').addEventListener('click',()=>void calibrateLoopback());
      modal.querySelector('[data-v215-bounce-range]').addEventListener('click',()=>void bounceSelected('bounce',true));
      modal.querySelector('[data-v215-bounce-track]').addEventListener('click',()=>void bounceSelected('bounce',false));
      modal.querySelector('[data-v215-commit]').addEventListener('click',()=>void bounceSelected('commit',false));
      modal.querySelector('[data-v215-freeze-selected]').addEventListener('click',()=>{const id=selectedId();if(id)void (settings.freezes[String(id)]?unfreezeTrack(id):freezeTrack(id));});
      return true;
    }

    function openModal(){buildModal();modal.hidden=false;modal.classList.add('open');refreshUi();}
    function closeModal(){if(!modal)return;modal.classList.remove('open');modal.hidden=true;}

    function refreshUi(){
      buildToolbar();buildModal();const latency=currentLatency();
      const summary=toolbar?.querySelector('[data-v215-summary]');setText(summary,`PDC ${num(plan.maxBaseLatencyMs,0).toFixed(1)} ms`);
      if(!modal)return;
      const text=(selector,value)=>setText(modal.querySelector(selector),value);
      const pdc=modal.querySelector('[data-v215-pdc]');if(pdc)pdc.checked=settings.pdc;
      const offset=modal.querySelector('[data-v215-record-offset]');if(offset&&document.activeElement!==offset)offset.value=String(settings.recordOffsetMs);
      const pre=modal.querySelector('[data-v215-pre]');if(pre&&document.activeElement!==pre)pre.value=String(settings.preRollSeconds);
      const post=modal.querySelector('[data-v215-post]');if(post&&document.activeElement!==post)post.value=String(settings.postRollSeconds);
      text('[data-v215-base]',`${latency.baseMs.toFixed(1)} ms`);text('[data-v215-output]',`${latency.outputMs.toFixed(1)} ms`);text('[data-v215-input]',latency.inputMs?`${latency.inputMs.toFixed(1)} ms`:'—');text('[data-v215-max]',`${num(plan.maxBaseLatencyMs,0).toFixed(1)} ms`);
      const id=selectedId(),row=(agent().stems||[]).find(item=>Number(item.id)===id);text('[data-v215-selected]',row?`${row.name} · ${row.role||'Track'} · ${settings.freezes[String(id)]?'FROZEN':'LIVE'}`:'No track selected.');
      setText(modal.querySelector('[data-v215-freeze-selected]'),id&&settings.freezes[String(id)]?'UNFREEZE TRACK':'FREEZE TRACK');
      setText(modal.querySelector('[data-v215-fast]'),offlineProvider?'FAST BOUNCE: PROVIDER READY':(root.OfflineAudioContext||root.webkitOfflineAudioContext)?'FAST BOUNCE: AUTO · COMPLEX TRACKS USE REAL-TIME':'FAST BOUNCE: REAL-TIME FALLBACK');
    }

    async function probeDevice(){
      const id=selectedId(),stem=id?core()?.getStem?.(id):null,deviceId=String(stem?.recordingInputDeviceId||'');let stream=null;
      try{
        if(!root.navigator.mediaDevices?.getUserMedia)throw new Error('Browser audio input is unavailable.');
        const audio={echoCancellation:false,noiseSuppression:false,autoGainControl:false};if(deviceId)audio.deviceId={ideal:deviceId};
        stream=await root.navigator.mediaDevices.getUserMedia({audio,video:false});const track=stream.getAudioTracks?.()[0];const reported=Math.max(0,num(track?.getSettings?.().latency,0))*1000;
        settings.calibration={...(settings.calibration||{}),reportedInputMs:reported,probedAt:new Date().toISOString()};persist();refreshUi();showStatus(reported?`Input reports ${reported.toFixed(1)} ms latency.`:'Browser did not report input latency.');return clone(settings.calibration);
      }catch(error){showStatus(error?.message||'Could not probe the audio device.','error');throw error;}
      finally{stream?.getTracks?.().forEach(track=>{try{track.stop();}catch(error){}});}
    }

    async function calibrateLoopback(){
      if(!root.confirm('Loopback calibration plays a short test tone. Connect an output to the selected input (or place the mic near the speaker), then continue.'))return null;
      core()?.ensureAudioGraph?.();const ctx=context();if(!ctx)throw new Error('Audio engine is unavailable.');if(ctx.state==='suspended')await ctx.resume();
      const id=selectedId(),stem=id?core()?.getStem?.(id):null,deviceId=String(stem?.recordingInputDeviceId||'');let stream=null,source=null,processor=null,sink=null;
      try{
        const audio={echoCancellation:false,noiseSuppression:false,autoGainControl:false};if(deviceId)audio.deviceId={ideal:deviceId};stream=await root.navigator.mediaDevices.getUserMedia({audio,video:false});source=ctx.createMediaStreamSource(stream);processor=ctx.createScriptProcessor(1024,1,1);sink=ctx.createGain();sink.gain.value=0;source.connect(processor);processor.connect(sink);sink.connect(ctx.destination);
        const blocks=[];let firstPlayback=null;processor.onaudioprocess=event=>{if(firstPlayback===null)firstPlayback=num(event.playbackTime,ctx.currentTime);blocks.push(new Float32Array(event.inputBuffer.getChannelData(0)));};
        const clickAt=ctx.currentTime+.28,osc=ctx.createOscillator(),gain=ctx.createGain();osc.frequency.value=1800;gain.gain.setValueAtTime(.0001,ctx.currentTime);gain.gain.setValueAtTime(.7,clickAt);gain.gain.exponentialRampToValueAtTime(.0001,clickAt+.018);osc.connect(gain);gain.connect(ctx.destination);osc.start(clickAt);osc.stop(clickAt+.022);
        await sleep(root,1050);processor.onaudioprocess=null;
        const pcm=flattenChunks(blocks.map(block=>[block]),1)[0],rate=ctx.sampleRate||48000,expected=Math.max(0,Math.round((clickAt-num(firstPlayback,clickAt))*rate)),from=Math.min(Math.max(0,expected),Math.max(0,pcm.length-1)),to=Math.min(pcm.length,from+Math.round(rate*.75));
        let best=from,peak=0;for(let i=from;i<to;i+=1){const value=Math.abs(pcm[i]||0);if(value>peak){peak=value;best=i;}}
        if(peak<.015)throw new Error('Loopback click was not detected. Check the physical loopback/input level and retry.');
        const roundTripMs=Math.max(0,(best-expected)/rate*1000),browserMs=contextLatency(ctx).totalMs,reportedInputMs=Math.max(0,roundTripMs-browserMs);
        settings.calibration={reportedInputMs,roundTripMs,peak,calibratedAt:new Date().toISOString()};settings.recordOffsetMs=0;persist();refreshUi();showStatus(`Loopback measured ${roundTripMs.toFixed(1)} ms round-trip.`);return clone(settings.calibration);
      }catch(error){showStatus(error?.message||'Loopback calibration failed.','error');throw error;}
      finally{try{source?.disconnect();}catch(error){}try{processor?.disconnect();}catch(error){}try{sink?.disconnect();}catch(error){}stream?.getTracks?.().forEach(track=>{try{track.stop();}catch(error){}});}
    }

    function projectRecordingStart(input,init){
      if(!(init?.body instanceof root.FormData)||String(init.body.get('action')||'')!=='recording_start')return false;
      try{return new URL(typeof input==='string'?input:input?.url||'',root.location.href).href===new URL(String(cfg.projectEndpoint||''),root.location.href).href;}catch(error){return false;}
    }

    async function fetchLayer(input,init){
      if(projectRecordingStart(input,init)){
        const form=init.body,raw=num(form.get('start_offset'),0),latency=currentLatency();
        const adjusted=adjustedRecordingStart(raw,context(),settings.recordOffsetMs,latency.inputMs,latency.roundTripMs);
        form.set('start_offset',String(adjusted));form.set('v215_latency_compensation_ms',String(Math.round((raw-adjusted)*1000000)/1000));
      }
      return nextFetch(input,init);
    }

    async function recordingPreroll(event){
      if(prerollBypass||settings.preRollSeconds<=.01||event.button!==0)return;
      if(agent().recording)return;
      const button=event.currentTarget,target=Math.max(0,num(core()?.getPosition?.(),0)),start=Math.max(0,target-settings.preRollSeconds);if(target-start<.03)return;
      event.preventDefault();event.stopImmediatePropagation();prerollBypass=true;
      try{
        await core()?.seek?.(start,false);await core()?.play?.();const deadline=Date.now()+(settings.preRollSeconds+2)*1000;
        while(Date.now()<deadline&&num(core()?.getPosition?.(),0)<target-.025)await sleep(root,20);
        core()?.pause?.();button.click();
      }catch(error){showStatus('Pre-roll failed; starting recording normally.','error');button.click();}
      finally{root.setTimeout(()=>{prerollBypass=false;},90);}
    }

    function savePending(value){try{if(value)root.localStorage?.setItem(pendingKey,JSON.stringify(value));else root.localStorage?.removeItem(pendingKey);}catch(error){}}
    function loadPending(){try{return JSON.parse(root.localStorage?.getItem(pendingKey)||'null');}catch(error){return null;}}

    async function applyPending(){
      const pending=loadPending();if(!pending||Date.now()-num(pending.createdAt,0)>10*60*1000){if(pending)savePending(null);return false;}
      if(!['freeze','commit','bounce'].includes(String(pending.kind)))return false;
      const original=Number(pending.originalStemId||0),rendered=Number(pending.renderStemId||0);if(!(rendered>0)||!core()?.getStem?.(rendered))return false;
      if(pending.kind==='bounce')await studio()?.executeAgentCommand?.({type:'mute',stem_id:rendered});
      else{
        if(original>0)await studio()?.executeAgentCommand?.({type:'mute',stem_id:original});await studio()?.executeAgentCommand?.({type:'unmute',stem_id:rendered});
        if(pending.kind==='freeze'&&original>0){
          const originalStem=core()?.getStem?.(original);for(let index=0;index<(originalStem?.plugins||[]).length;index+=1){if(originalStem.plugins[index]?.enabled!==false)await studio()?.executeAgentCommand?.({type:'plugin_bypass',stem_id:original,plugin_index:index,bypassed:true});}
          settings.freezes[String(original)]={renderStemId:rendered,pluginStates:Array.isArray(pending.pluginStates)?pending.pluginStates:[],originalMuted:Boolean(pending.originalMuted),createdAt:Date.now()};persist();
        }
      }
      savePending(null);scheduleRefresh();showStatus(pending.kind==='freeze'?'Track frozen.':pending.kind==='commit'?'Track committed.':'Bounce created muted.');return true;
    }

    async function captureRealtime(stemId,range){
      core()?.ensureAudioGraph?.();const ctx=context();if(!ctx)throw new Error('Web Audio engine is unavailable.');if(ctx.state==='suspended')await ctx.resume();
      const stem=core()?.getStem?.(Number(stemId)),patch=ensurePatch(stem),source=patch?.phase||core()?.getStemCaptureSource?.(Number(stemId));if(!source)throw new Error('Selected track capture source is unavailable.');
      const duration=Math.max(.05,num(agent().duration,.05)),start=clamp(range.start,0,duration),end=clamp(range.end,start,duration);if(end<=start+.01)throw new Error('Select a valid bounce range.');
      const pre=Math.min(settings.preRollSeconds,start),captureStart=start-pre,post=settings.postRollSeconds,sampleRate=ctx.sampleRate||48000,wasPlaying=Boolean(core()?.isPlaying?.()),originalPosition=num(core()?.getPosition?.(),0);if(wasPlaying)core()?.pause?.();
      const processor=ctx.createScriptProcessor(2048,2,2),sink=ctx.createGain(),chunks=[];sink.gain.value=0;processor.onaudioprocess=event=>{const row=[];for(let channel=0;channel<Math.min(2,event.inputBuffer.numberOfChannels);channel+=1)row.push(new Float32Array(event.inputBuffer.getChannelData(channel)));if(row.length===1)row.push(new Float32Array(row[0]));if(row.length)chunks.push(row);};source.connect(processor);processor.connect(sink);sink.connect(ctx.destination);
      try{
        await core()?.seek?.(captureStart,false);await core()?.play?.();const deadline=Date.now()+Math.max(5000,(end-captureStart+3)*1000);
        while(Date.now()<deadline){const pos=num(core()?.getPosition?.(),0);if(pos>=end-.025||(!core()?.isPlaying?.()&&pos>=end-.1))break;await sleep(root,20);}core()?.pause?.();if(post>0)await sleep(root,post*1000);
      }finally{
        processor.onaudioprocess=null;disconnect(source,processor);try{processor.disconnect();}catch(error){}try{sink.disconnect();}catch(error){}try{await core()?.seek?.(originalPosition,false);if(wasPlaying)await core()?.play?.();}catch(error){}
      }
      let pcm=flattenChunks(chunks,2);const trimStart=Math.max(0,Math.round(pre*sampleRate)),wanted=Math.max(1,Math.round((end-start+post)*sampleRate));pcm=pcm.map(channel=>channel.slice(trimStart,Math.min(channel.length,trimStart+wanted)));
      return {pcm,sampleRate,range:{start,end},postRoll:post,mode:'realtime'};
    }

    function canSimpleOffline(stemId,range){
      const Offline=root.OfflineAudioContext||root.webkitOfflineAudioContext,stem=core()?.getStem?.(Number(stemId)),mix=studio()?.getMixState?.()?.stems?.[String(stemId)];if(!Offline||!stem?.url||!mix)return false;
      if((mix.plugins||[]).some(plugin=>plugin?.enabled!==false)||Object.values(mix.automation||{}).some(points=>Array.isArray(points)&&points.length))return false;
      const clips=Array.isArray(mix.clips)?mix.clips:[];if(clips.length!==1)return false;const clip=clips[0];
      if(num(clip.fadeIn,0)>0||num(clip.fadeOut,0)>0||num(clip.gainDb,0)!==0||clip.muted||Math.abs(num(stem.timelineRatio,1)-1)>.0001)return false;
      return num(range.start,0)>=num(clip.timelineStart,0)-.001&&num(range.end,0)<=num(clip.timelineStart,0)+num(clip.timelineLength,0)+.001;
    }

    async function captureOffline(stemId,range){
      const stem=core()?.getStem?.(Number(stemId)),ctx=context();if(!stem?.url||!ctx)throw new Error('Fast bounce source is unavailable.');
      const response=await nextFetch(stem.url,{credentials:'same-origin'});if(!response.ok)throw new Error('Could not load the track for fast bounce.');const audio=await ctx.decodeAudioData(await response.arrayBuffer());const sampleRate=ctx.sampleRate||audio.sampleRate||48000,frames=Math.max(1,Math.ceil((range.end-range.start)*sampleRate)),Offline=root.OfflineAudioContext||root.webkitOfflineAudioContext,offline=new Offline(2,frames,sampleRate),source=offline.createBufferSource(),gain=offline.createGain();source.buffer=audio;gain.gain.value=num(stem.userGain,1);source.connect(gain);let current=gain;if(offline.createStereoPanner){const pan=offline.createStereoPanner();pan.pan.value=num(stem.pan?.value,0);gain.connect(pan);current=pan;}current.connect(offline.destination);
      const clip=studio()?.getMixState?.()?.stems?.[String(stemId)]?.clips?.[0]||{},offset=Math.max(0,num(clip.sourceStart,0)+(range.start-num(clip.timelineStart,0)));source.start(0,offset,Math.max(.01,range.end-range.start));const rendered=await offline.startRendering(),pcm=[];for(let c=0;c<Math.min(2,rendered.numberOfChannels);c+=1)pcm.push(new Float32Array(rendered.getChannelData(c)));if(pcm.length===1)pcm.push(new Float32Array(pcm[0]));return {pcm,sampleRate,range,postRoll:0,mode:'offline'};
    }

    async function renderPcm(stemId,range){
      if(offlineProvider?.supports?.(stemId,range))return offlineProvider.render(stemId,range);
      if(canSimpleOffline(stemId,range))return captureOffline(stemId,range);
      return captureRealtime(stemId,range);
    }

    async function uploadRender(stemId,mode,range){
      if(!ext.endpoint)throw new Error('v215 rendered-stem endpoint is unavailable.');const row=(agent().stems||[]).find(item=>Number(item.id)===Number(stemId));if(!row)throw new Error('Select a track first.');
      const rendered=await renderPcm(stemId,range),encoder=root.StonefellowStemRenderExportV214?.encodeWav||root.StonefellowStemRenderExportV214Runtime?.encodeWav;if(typeof encoder!=='function')throw new Error('v214 WAV encoder is unavailable.');const wav=encoder(rendered.pcm,rendered.sampleRate,24,true,215);
      const form=new root.FormData();form.append('csrf_token',String(cfg.csrf||''));form.append('action','import_render');form.append('track_id',String(cfg.trackId||0));form.append('source_stem_id',String(stemId));form.append('mode',mode);form.append('start_offset',String(range.start||0));form.append('name',renderName(row.name,mode));form.append('audio',new root.Blob([wav],{type:'audio/wav'}),'render-v215.wav');
      const response=await nextFetch(String(ext.endpoint),{method:'POST',credentials:'same-origin',body:form}),data=await response.json().catch(()=>({ok:false,error:'Invalid v215 import response.'}));if(!response.ok||!data.ok)throw new Error(data.error||'Could not import rendered audio.');return {...data,renderMode:rendered.mode};
    }

    async function bounceForId(stemId,mode='bounce',useSelection=false){
      const id=Number(stemId||0);if(!(id>0))throw new Error('Select a track first.');const state=agent(),range=useSelection?selectedBounceRange(state,v210()?.getRange?.()):{start:0,end:Math.max(.05,num(state.duration,.05)),duration:Math.max(.05,num(state.duration,.05)),source:'track'},stem=core()?.getStem?.(id),pluginStates=(stem?.plugins||[]).map(plugin=>plugin.enabled!==false);showStatus(`${mode.toUpperCase()} rendering ${range.duration.toFixed(1)}s…`);
      try{const result=await uploadRender(id,mode,range);savePending({kind:mode,originalStemId:id,renderStemId:Number(result.stem_id||0),pluginStates,originalMuted:Boolean((state.stems||[]).find(item=>Number(item.id)===id)?.muted),createdAt:Date.now()});showStatus(`${mode.toUpperCase()} complete · ${result.renderMode==='offline'?'fast':'real-time'} render.`);root.setTimeout(()=>root.location.reload(),420);return result;}
      catch(error){showStatus(error?.message||`${mode} failed.`,'error');throw error;}
    }

    async function bounceSelected(mode='bounce',useSelection=true){return bounceForId(selectedId(),mode,useSelection);}
    async function freezeTrack(id){if(settings.freezes[String(id)])return unfreezeTrack(id);return bounceForId(id,'freeze',false);}

    async function unfreezeTrack(id){
      const freeze=settings.freezes[String(id)];if(!freeze)return false;showStatus('Restoring live track…');
      try{
        const stem=core()?.getStem?.(id);for(let index=0;index<(stem?.plugins||[]).length;index+=1){await studio()?.executeAgentCommand?.({type:'plugin_bypass',stem_id:id,plugin_index:index,bypassed:freeze.pluginStates?.[index]===false});}
        await studio()?.executeAgentCommand?.({type:freeze.originalMuted?'mute':'unmute',stem_id:id});
        const form=new root.FormData();form.append('csrf_token',String(cfg.csrf||''));form.append('action','remove_render');form.append('track_id',String(cfg.trackId||0));form.append('stem_id',String(freeze.renderStemId||0));const response=await nextFetch(String(ext.endpoint),{method:'POST',credentials:'same-origin',body:form}),data=await response.json().catch(()=>({ok:false,error:'Invalid unfreeze response.'}));if(!response.ok||!data.ok)throw new Error(data.error||'Could not remove frozen render.');delete settings.freezes[String(id)];persist();showStatus('Track unfrozen.');root.setTimeout(()=>root.location.reload(),320);return true;
      }catch(error){showStatus(error?.message||'Unfreeze failed.','error');throw error;}
    }

    function wrapStudio(){
      const s=studio();if(!s||originalGetMixState)return;
      originalGetMixState=s.getMixState.bind(s);originalApplyMixState=s.applyMixState.bind(s);originalExecuteAgentCommand=s.executeAgentCommand.bind(s);
      s.getMixState=()=>{const state=originalGetMixState();state.engineV215=clone(settings);return state;};
      s.applyMixState=state=>{if(state?.engineV215)settings=engineSettings(state.engineV215);const result=originalApplyMixState(state);persist();scheduleRefresh(80);return result;};
      s.executeAgentCommand=async command=>{
        const type=String(command?.type||''),id=Number(command?.stem_id||0);
        if(type==='track_delay'&&id>0){trackSetting(id).manualDelayMs=clamp(command.value,-MAX_MANUAL_MS,MAX_MANUAL_MS);persist();scheduleRefresh();return {status:'success',result:`Track delay ${trackSetting(id).manualDelayMs.toFixed(1)} ms`};}
        if(type==='track_polarity'&&id>0){trackSetting(id).polarity=Boolean(command.value);persist();scheduleRefresh();return {status:'success',result:trackSetting(id).polarity?'Polarity inverted':'Polarity normal'};}
        if(type==='pdc_toggle'){settings.pdc=Boolean(command.value);persist();scheduleRefresh();return {status:'success',result:settings.pdc?'PDC enabled':'PDC disabled'};}
        if(type==='record_offset'){settings.recordOffsetMs=clamp(command.value,-1000,1000);persist();refreshUi();return {status:'success',result:`Recording correction ${settings.recordOffsetMs.toFixed(1)} ms`};}
        if(type==='pre_roll'){settings.preRollSeconds=clamp(command.value,0,10);persist();refreshUi();return {status:'success',result:`Pre-roll ${settings.preRollSeconds.toFixed(1)} s`};}
        if(type==='post_roll'){settings.postRollSeconds=clamp(command.value,0,15);persist();refreshUi();return {status:'success',result:`Post-roll ${settings.postRollSeconds.toFixed(1)} s`};}
        if(type==='track_freeze'&&id>0){await freezeTrack(id);return {status:'success',result:'Track freeze started'};}
        if(type==='track_unfreeze'&&id>0){await unfreezeTrack(id);return {status:'success',result:'Track unfreeze started'};}
        if(type==='track_commit'&&id>0){await bounceForId(id,'commit',false);return {status:'success',result:'Track commit started'};}
        if(type==='track_bounce'&&id>0){await bounceForId(id,'bounce',Boolean(command.range));return {status:'success',result:'Track bounce started'};}
        return originalExecuteAgentCommand(command);
      };
    }

    function bind(){
      if(!core()||!studio()||!root.StonefellowStemRenderExportV214||!buildToolbar()||!buildModal()){
        bindAttempts+=1;if(bindAttempts<240)root.setTimeout(bind,60);else root.__STONEFELLOW_STEM_V215_INSTALLED__=false;return;
      }
      nextFetch=root.fetch.bind(root);fetchWrapper=fetchLayer;root.fetch=fetchWrapper;wrapStudio();core()?.ensureAudioGraph?.();document.getElementById('studioRecordButton')?.addEventListener('click',recordingPreroll,true);scheduleRefresh(100);void applyPending();
      observer=new MutationObserver(()=>scheduleRefresh(90));observer.observe(document.getElementById('stemStudio')||document.body,{childList:true,subtree:true});
      root.addEventListener('stonefellow:stem-render-v214-restored',()=>scheduleRefresh(40));root.addEventListener('stonefellow:stem-recording-engine-v213',()=>scheduleRefresh(100));
      root.dispatchEvent(new CustomEvent('stonefellow:stem-audio-engine-v215',{detail:{build:BUILD}}));
    }
    bind();

    root.addEventListener('pagehide',()=>{
      observer?.disconnect();root.clearTimeout(refreshTimer);restoreGraphs();if(fetchWrapper&&root.fetch===fetchWrapper)root.fetch=nextFetch;
      const s=studio();if(s&&originalGetMixState)s.getMixState=originalGetMixState;if(s&&originalApplyMixState)s.applyMixState=originalApplyMixState;if(s&&originalExecuteAgentCommand)s.executeAgentCommand=originalExecuteAgentCommand;
    },{once:true});

    root.StonefellowStemAudioEngineV215Runtime={
      build:BUILD,getLatency:currentLatency,getPlan:()=>clone(plan),getSettings:()=>clone(settings),refresh:applyPdc,
      setPdc:value=>{settings.pdc=Boolean(value);persist();scheduleRefresh();return settings.pdc;},
      setTrackDelay:(id,value)=>{trackSetting(id).manualDelayMs=clamp(value,-MAX_MANUAL_MS,MAX_MANUAL_MS);persist();scheduleRefresh();return trackSetting(id).manualDelayMs;},
      setPolarity:(id,value)=>{trackSetting(id).polarity=Boolean(value);persist();scheduleRefresh();return trackSetting(id).polarity;},
      setRecordOffset:value=>{settings.recordOffsetMs=clamp(value,-1000,1000);persist();refreshUi();return settings.recordOffsetMs;},
      probeDevice,calibrateLoopback,freezeTrack,unfreezeTrack,bounce:(useSelection=true)=>bounceSelected('bounce',useSelection),commit:()=>bounceSelected('commit',false),
      registerOfflineProvider:provider=>{offlineProvider=provider&&typeof provider.render==='function'?provider:null;refreshUi();return Boolean(offlineProvider);}
    };
    return true;
  }

  return Object.freeze({
    build:BUILD,PLUGIN_LATENCY_MS,singlePluginLatencyMs,pluginLatencyMs,compensationPlan,contextLatency,adjustedRecordingStart,engineSettings,computePdcPlan,selectedBounceRange,renderName,flattenChunks,install
  });
});
