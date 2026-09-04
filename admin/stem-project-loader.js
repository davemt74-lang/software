(function(root,factory){
  const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;
  root.StonefellowStemProjectLoader=api;
  if(root?.document)api.install(root);
})(typeof globalThis!=='undefined'?globalThis:window,function(){
  'use strict';

  /*
   * Project readiness is a hard gate. The editor stays covered until the
   * runtime exists, every required stem reaches HAVE_ENOUGH_DATA, and every
   * true waveform has completed. Waveform media requests are released only
   * after the audio phase is complete so waveform decoding cannot compete
   * with initial stem loading.
   */
  function installProjectLoadingOverlay(host){
    const doc=host?.document;
    const cfg=host?.STONEFELLOW_STEM_STUDIO;
    if(!doc?.createElement||!doc.body||!cfg||!Array.isArray(cfg.stems)||host.__STONEFELLOW_STEM_PROJECT_LOADER_V232__)return false;
    host.__STONEFELLOW_STEM_PROJECT_LOADER_V232__=true;

    const BUILD='stem-project-loader-v232-20260902';
    const expected=cfg.stems.filter(row=>!row?.isEmptyRecordingTrack&&Number(row?.id||0)>0);
    const expectedIds=expected.map(row=>Number(row.id));
    const metaById=new Map(expected.map(row=>[Number(row.id),row]));
    const cachePrefix='stonefellow:waveform:v232:';
    const cacheIndexKey=`${cachePrefix}index`;
    const cacheTtlMs=6*60*60*1000;
    const maxCacheEntries=24;
    const originalFetchFunction=typeof host.fetch==='function'?host.fetch:null;
    const originalFetch=originalFetchFunction?originalFetchFunction.bind(host):null;
    let loaderFetch=null;
    let playbackReady=false;
    let mediaPhaseReady=false;
    let continuedWithFailures=false;
    let lastFailures=[];
    const acceptedFailures=new Map();
    let mediaReadyResolve=()=>{};
    const mediaReadyPromise=new Promise(resolve=>{mediaReadyResolve=resolve;});

    const cacheKey=stemId=>{
      const row=metaById.get(Number(stemId))||{};
      return `${cachePrefix}${Number(cfg.userId||0)}:${Number(cfg.trackId||0)}:${Number(stemId||0)}:${Number(row.duration||0).toFixed(3)}:${String(cfg.pluginImportVersion||'base')}`;
    };
    const cacheRead=stemId=>{
      try{
        const raw=host.localStorage?.getItem(cacheKey(stemId));
        if(!raw)return null;
        const data=JSON.parse(raw);
        if(!data||Date.now()-Number(data.savedAt||0)>cacheTtlMs||!Array.isArray(data.mins)||!Array.isArray(data.maxs)||!data.mins.length||data.mins.length!==data.maxs.length){
          host.localStorage?.removeItem(cacheKey(stemId));return null;
        }
        return data;
      }catch(error){return null;}
    };
    const trimCache=()=>{
      try{
        const index=JSON.parse(host.localStorage?.getItem(cacheIndexKey)||'[]');
        if(!Array.isArray(index))return;
        index.sort((a,b)=>Number(b?.savedAt||0)-Number(a?.savedAt||0));
        index.slice(maxCacheEntries).forEach(item=>host.localStorage?.removeItem(String(item?.key||'')));
        host.localStorage?.setItem(cacheIndexKey,JSON.stringify(index.slice(0,maxCacheEntries)));
      }catch(error){}
    };
    const cacheWrite=(stemId,waveform)=>{
      if(!waveform||!Array.isArray(waveform.mins)||!Array.isArray(waveform.maxs)||!waveform.mins.length)return false;
      try{
        const key=cacheKey(stemId);
        const quantize=list=>list.map(value=>Math.round(Math.max(-1,Math.min(1,Number(value)||0))*10000)/10000);
        const savedAt=Date.now();
        const payload={
          savedAt,
          mins:quantize(waveform.mins),maxs:quantize(waveform.maxs),
          duration:Number(waveform.duration||metaById.get(Number(stemId))?.duration||0),
          sample_rate:Number(waveform.sampleRate||waveform.sample_rate||0),
          channels:Number(waveform.channels||0)
        };
        host.localStorage?.setItem(key,JSON.stringify(payload));
        let index=[];
        try{index=JSON.parse(host.localStorage?.getItem(cacheIndexKey)||'[]');}catch(error){index=[];}
        if(!Array.isArray(index))index=[];
        index=index.filter(item=>item?.key!==key);
        index.unshift({key,savedAt});
        host.localStorage?.setItem(cacheIndexKey,JSON.stringify(index.slice(0,maxCacheEntries+8)));
        trimCache();
        return true;
      }catch(error){return false;}
    };

    const idle=()=>new Promise(resolve=>{
      if(typeof host.requestIdleCallback==='function')host.requestIdleCallback(()=>resolve(),{timeout:900});
      else host.setTimeout?.(resolve,60)??resolve();
    });
    const parseUrl=input=>{
      try{return new URL(typeof input==='string'?input:input?.url||'',host.location?.href||'http://localhost/');}
      catch(error){return null;}
    };
    if(originalFetch&&typeof host.Response==='function'){
      loaderFetch=async function projectLoaderFetch(input,init={}){
        const url=parseUrl(input);
        const path=String(url?.pathname||'');
        if(path.endsWith('/api/stem-waveform-v49.php')){
          const stemId=Number(url?.searchParams?.get('id')||0);
          const cached=cacheRead(stemId);
          if(cached){
            return new host.Response(JSON.stringify({
              ok:true,supported:true,format:'CACHE',stem_id:stemId,
              points:cached.mins.length,duration:cached.duration,
              sample_rate:cached.sample_rate,channels:cached.channels,
              mins:cached.mins,maxs:cached.maxs
            }),{status:200,headers:{'Content-Type':'application/json; charset=utf-8','Cache-Control':'private, no-store','X-Stonefellow-Waveform-Cache':'v232'}});
          }
        }
        const browserWaveformMedia=path.endsWith('/stem-media-v34.php')&&Object.prototype.hasOwnProperty.call(init||{},'cache');
        if(browserWaveformMedia&&!mediaPhaseReady){
          await mediaReadyPromise;
          await idle();
        }
        return originalFetch(input,init);
      };
      host.fetch=loaderFetch;
    }
    const restoreFetch=()=>{
      if(loaderFetch&&host.fetch===loaderFetch&&originalFetchFunction){
        host.fetch=originalFetchFunction;
      }
      loaderFetch=null;
    };

    const style=doc.createElement('style');
    style.dataset.stemProjectLoaderV232='1';
    style.textContent=`
      .stem-project-loader-v232{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;background:rgba(15,17,17,.91);backdrop-filter:blur(12px);color:#f5f7f4;font-family:Inter,system-ui,sans-serif;transition:opacity .28s ease,visibility .28s ease}
      .stem-project-loader-v232.is-ready{opacity:0;visibility:hidden;pointer-events:none}
      .stem-project-loader-v232-card{width:min(460px,calc(100vw - 38px));padding:28px;border:1px solid rgba(255,255,255,.12);border-radius:18px;background:rgba(28,31,30,.92);box-shadow:0 28px 90px rgba(0,0,0,.38)}
      .stem-project-loader-v232-mark{height:48px;display:flex;align-items:center;gap:5px;margin-bottom:20px}
      .stem-project-loader-v232-mark i{display:block;width:5px;border-radius:8px;background:#eef3ed;animation:stemLoaderPulse .88s ease-in-out infinite alternate}
      .stem-project-loader-v232-mark i:nth-child(1),.stem-project-loader-v232-mark i:nth-child(7){height:13px}.stem-project-loader-v232-mark i:nth-child(2),.stem-project-loader-v232-mark i:nth-child(6){height:25px;animation-delay:.09s}.stem-project-loader-v232-mark i:nth-child(3),.stem-project-loader-v232-mark i:nth-child(5){height:37px;animation-delay:.18s}.stem-project-loader-v232-mark i:nth-child(4){height:46px;animation-delay:.27s}
      @keyframes stemLoaderPulse{from{transform:scaleY(.45);opacity:.45}to{transform:scaleY(1);opacity:1}}
      .stem-project-loader-v232-card small{display:block;margin-bottom:7px;color:#aeb8b0;font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}
      .stem-project-loader-v232-card h2{margin:0;font-size:24px;line-height:1.15;font-weight:750;letter-spacing:-.025em}
      .stem-project-loader-v232-status{margin-top:16px;font-size:13px;color:#d6ddd7}
      .stem-project-loader-v232-wave{margin-top:5px;font-size:11px;color:#8f9b92}
      .stem-project-loader-v232-track{height:5px;margin-top:18px;border-radius:999px;background:rgba(255,255,255,.09);overflow:hidden}
      .stem-project-loader-v232-track i{display:block;height:100%;width:0;border-radius:inherit;background:#f1f5f1;transition:width .18s ease}
      .stem-project-loader-v232-failures{display:none;margin-top:15px;padding:12px 13px;border:1px solid rgba(255,255,255,.14);border-radius:12px;background:rgba(0,0,0,.16);font-size:12px;color:#dfe6e0}.stem-project-loader-v232.has-error .stem-project-loader-v232-failures{display:block}.stem-project-loader-v232-failures strong{display:block;margin-bottom:7px;font-size:11px;letter-spacing:.08em;text-transform:uppercase}.stem-project-loader-v232-failures ul{margin:0;padding-left:18px}.stem-project-loader-v232-failures li+li{margin-top:4px}
      .stem-project-loader-v232-actions{display:none;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:17px}.stem-project-loader-v232.has-error .stem-project-loader-v232-actions{display:flex}.stem-project-loader-v232-actions button{border:1px solid rgba(255,255,255,.18);border-radius:999px;background:transparent;color:#eef3ef;padding:8px 13px;font:700 11px/1 system-ui;cursor:pointer}.stem-project-loader-v232-actions button[data-loader-continue]{background:#eef3ef;color:#171a18}
    `;
    doc.head?.appendChild(style);

    const overlay=doc.createElement('div');
    overlay.className='stem-project-loader-v232';
    overlay.dataset.stemProjectLoaderV232=BUILD;
    overlay.innerHTML=`<div class="stem-project-loader-v232-card" role="status" aria-live="polite"><div class="stem-project-loader-v232-mark" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><small>Stonefellow Studio</small><h2>Loading ${String(cfg.projectTitle||'song').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]))}</h2><div class="stem-project-loader-v232-status" data-loader-status>Loading project audio…</div><div class="stem-project-loader-v232-wave" data-loader-wave>Waiting for all audio and waveforms.</div><div class="stem-project-loader-v232-track"><i data-loader-progress></i></div><div class="stem-project-loader-v232-failures" data-loader-failures hidden></div><div class="stem-project-loader-v232-actions"><button type="button" data-loader-retry>Reload and retry</button><button type="button" data-loader-continue>Continue with available tracks</button></div></div>`;
    doc.body.appendChild(overlay);
    const statusNode=overlay.querySelector?.('[data-loader-status]');
    const waveNode=overlay.querySelector?.('[data-loader-wave]');
    const progressNode=overlay.querySelector?.('[data-loader-progress]');
    const failuresNode=overlay.querySelector?.('[data-loader-failures]');
    const retryButton=overlay.querySelector?.('[data-loader-retry]');
    const continueButton=overlay.querySelector?.('[data-loader-continue]');

    const escapeSelector=value=>host.CSS?.escape?host.CSS.escape(String(value)):String(value).replace(/[^a-zA-Z0-9_-]/g,'\\$&');
    const mediaFor=id=>doc.querySelector?.(`audio.stem-audio[data-stem-audio="${escapeSelector(id)}"]`)||null;
    const liveStem=id=>host.STONEFELLOW_STUDIO_RUNTIME_V87?.getStem?.(Number(id))||null;
    const escapeHtml=value=>String(value??'').replace(/[&<>\"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[char]));
    const stemLabel=id=>{
      const row=metaById.get(Number(id))||{};
      return String(row.name||row.title||row.stem_name||row.filename||row.file_name||`Track ${Number(id)}`);
    };
    const failureKey=item=>`${String(item?.type||'unknown')}:${Number(item?.id||0)}`;
    const failureAccepted=item=>acceptedFailures.has(failureKey(item));
    const acceptFailures=failures=>{
      (Array.isArray(failures)?failures:[]).forEach(item=>acceptedFailures.set(failureKey(item),{...item}));
    };
    const renderFailures=failures=>{
      lastFailures=Array.isArray(failures)?failures.map(item=>({...item})):[];
      if(!failuresNode)return;
      if(!lastFailures.length){failuresNode.hidden=true;failuresNode.innerHTML='';return;}
      failuresNode.hidden=false;
      failuresNode.innerHTML=`<strong>Could not load</strong><ul>${lastFailures.map(item=>`<li>${escapeHtml(stemLabel(item.id))} — ${item.type==='audio'?'Audio unavailable':'Waveform unavailable'}</li>`).join('')}</ul>`;
    };
    const mediaState=id=>{
      const stem=liveStem(id);
      const audio=stem?.audio||mediaFor(id);
      if(stem?.mediaUnavailable||audio?.error||audio?.dataset?.stemMediaOfflineV229==='1')return 'failed';
      if(audio&&Number(audio.readyState||0)>=4)return 'ready';
      return 'pending';
    };
    const waveformState=id=>{
      const stem=liveStem(id);
      if(stem?.waveformData)return 'ready';
      if(stem?.mediaUnavailable)return 'blocked';
      if(stem?.waveformError)return 'failed';
      return 'pending';
    };
    const finishReady=(options={})=>{
      if(playbackReady)return;
      const failures=Array.isArray(options.failures)?options.failures.map(item=>({...item})):[];
      const degraded=Boolean(options.degraded&&failures.length);
      playbackReady=true;
      continuedWithFailures=degraded;
      restoreFetch();
      if(degraded&&!mediaPhaseReady){mediaPhaseReady=true;mediaReadyResolve();}
      host.__STONEFELLOW_STEM_PLAYBACK_READY_V232__=true;
      host.__STONEFELLOW_STEM_PLAYBACK_DEGRADED_V232__=degraded?failures:false;
      const ReadyEvent=host.CustomEvent||globalThis.CustomEvent;
      if(typeof ReadyEvent==='function')host.dispatchEvent?.(new ReadyEvent('stonefellow:stem-playback-ready',{detail:{build:BUILD,forced:false,degraded,failures}}));
      if(statusNode)statusNode.textContent=degraded?`Opened with ${failures.length} unavailable item${failures.length===1?'':'s'}`:'Song fully loaded';
      if(waveNode)waveNode.textContent=degraded?'Available tracks are ready to use.':'Audio and waveforms ready';
      if(progressNode)progressNode.style.width='100%';
      host.setTimeout?.(()=>overlay.classList.add('is-ready'),220);
      host.setTimeout?.(()=>overlay.remove?.(),620);
      host.removeEventListener?.('keydown',blockSpace,true);
    };
    const blockSpace=event=>{
      if(playbackReady||event?.code!=='Space')return;
      event.preventDefault?.();event.stopImmediatePropagation?.();event.stopPropagation?.();
    };
    host.addEventListener?.('keydown',blockSpace,true);
    retryButton?.addEventListener?.('click',()=>host.location?.reload?.());
    continueButton?.addEventListener?.('click',()=>{
      if(!lastFailures.length)return;
      acceptFailures(lastFailures);
      renderFailures([]);
    });

    const captured=new Set();
    const tick=()=>{
      const runtimeReady=Boolean(host.STONEFELLOW_STUDIO_RUNTIME_V87?.play);
      let mediaReady=0,mediaFailed=0,waveformReady=0,waveformFailed=0;
      const failures=[];
      expectedIds.forEach(id=>{
        const media=mediaState(id);
        if(media==='ready')mediaReady+=1;
        else if(media==='failed'){
          mediaFailed+=1;
          const failure={id:Number(id),type:'audio'};
          if(!failureAccepted(failure))failures.push(failure);
        }
        const wave=waveformState(id);
        if(wave==='ready')waveformReady+=1;
        else if(wave==='failed'){
          waveformFailed+=1;
          const failure={id:Number(id),type:'waveform'};
          if(!failureAccepted(failure))failures.push(failure);
        }
        const stem=liveStem(id);
        if(stem?.waveformData&&!captured.has(id)){
          captured.add(id);cacheWrite(id,stem.waveformData);
        }
      });
      renderFailures(failures);
      const total=expectedIds.length;
      const pendingAudioFailures=failures.filter(item=>item.type==='audio').length;
      const pendingWaveformFailures=failures.filter(item=>item.type==='waveform').length;
      const audioComplete=runtimeReady&&(mediaReady+mediaFailed===total)&&pendingAudioFailures===0;
      if(audioComplete&&!mediaPhaseReady){mediaPhaseReady=true;mediaReadyResolve();}
      const hasError=failures.length>0;
      overlay.classList.toggle('has-error',hasError);
      if(continueButton){
        continueButton.textContent=pendingAudioFailures>0?'Continue with available tracks':'Continue without missing waveforms';
      }
      const audioProgress=total?mediaReady/total:(runtimeReady?1:0);
      const waveformTarget=Math.max(0,mediaReady);
      const waveformProgress=waveformTarget?waveformReady/waveformTarget:(mediaFailed===total?1:0);
      const percent=Math.min(99,Math.round((audioProgress*.65+waveformProgress*.35)*100));
      if(progressNode&&!playbackReady)progressNode.style.width=`${percent}%`;
      if(statusNode&&!playbackReady){
        if(pendingAudioFailures)statusNode.textContent=`Audio load failed · ${pendingAudioFailures} track${pendingAudioFailures===1?'':'s'} · choose an option below`;
        else if(!runtimeReady)statusNode.textContent='Starting studio runtime…';
        else if(mediaReady+mediaFailed<total)statusNode.textContent=`Loading project audio · ${mediaReady+mediaFailed} / ${total} tracks`;
        else if(pendingWaveformFailures)statusNode.textContent=`Waveform load failed · ${pendingWaveformFailures} track${pendingWaveformFailures===1?'':'s'} · choose an option below`;
        else if(acceptedFailures.size)statusNode.textContent='Loading remaining available tracks…';
        else statusNode.textContent='Audio loaded · finishing waveforms…';
      }
      if(waveNode&&!playbackReady){
        waveNode.textContent=total?`Waveforms · ${waveformReady} / ${mediaReady}${pendingWaveformFailures?` · ${pendingWaveformFailures} need a decision`:''}`:'No audio tracks to load';
      }
      const projectReady=runtimeReady&&expectedIds.every(id=>{
        const media=mediaState(id);
        if(media==='failed')return acceptedFailures.has(`audio:${Number(id)}`);
        if(media!=='ready')return false;
        const wave=waveformState(id);
        if(wave==='ready')return true;
        if(wave==='failed')return acceptedFailures.has(`waveform:${Number(id)}`);
        return false;
      });
      if(!playbackReady&&projectReady){
        finishReady({degraded:acceptedFailures.size>0,failures:[...acceptedFailures.values()]});
      }
      if(!playbackReady)host.setTimeout?.(tick,180);
    };
    tick();

    host.StonefellowStemProjectLoaderV232={
      build:BUILD,
      isPlaybackReady:()=>playbackReady,
      isMediaReady:()=>mediaPhaseReady,
      wasForced:()=>false,
      continuedWithFailures:()=>continuedWithFailures,
      failures:()=>lastFailures.map(item=>({...item})),
      acceptedFailures:()=>[...acceptedFailures.values()].map(item=>({...item})),
      cacheKey,
      cacheRead,
      cacheWrite
    };
    return true;
  }


  return Object.freeze({
    install:installProjectLoadingOverlay,
    installProjectLoadingOverlay
  });
});
