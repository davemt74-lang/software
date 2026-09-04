(function(root){
  'use strict';

  const BUILD='stem-audio-engine-v215-hardening-20260901';
  if(!root.document||root.__STONEFELLOW_STEM_V215_HARDENING__)return;
  root.__STONEFELLOW_STEM_V215_HARDENING__=true;

  const document=root.document;
  const cfg=root.STONEFELLOW_STEM_STUDIO||{};
  const ext=root.STONEFELLOW_STEM_AUDIO_V215||{};
  const storageKey=`stonefellow:stem:v215:${Number(cfg.userId||0)}:${Number(cfg.trackId||0)}`;
  const pendingKey=`${storageKey}:pending`;
  const num=(value,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;
  const clamp=(value,min,max)=>Math.min(max,Math.max(min,num(value,min)));
  const sleep=ms=>new Promise(resolve=>root.setTimeout(resolve,ms));
  let attempts=0;
  let pendingSnapshot=null;
  let nextFetch=null;
  let fetchWrapper=null;

  try{
    const value=JSON.parse(root.localStorage?.getItem(pendingKey)||'null');
    if(value&&typeof value==='object')pendingSnapshot=value;
  }catch(error){}

  const core=()=>root.STONEFELLOW_STUDIO_RUNTIME_V87||null;
  const studio=()=>root.StonefellowStemStudioV91||null;
  const runtime=()=>root.StonefellowStemAudioEngineV215Runtime||null;
  const api=()=>root.StonefellowStemAudioEngineV215||null;
  const agent=()=>{try{return studio()?.getAgentState?.()||{};}catch(error){return{};}};
  const context=()=>core()?.getContext?.()||null;

  function disconnect(source,destination){
    if(!source)return;
    try{destination?source.disconnect(destination):source.disconnect();}catch(error){}
  }

  function sourceTrack(stemId){
    return (agent().stems||[]).find(row=>Number(row?.id||0)===Number(stemId||0))||null;
  }

  function sourceEngineSettings(stemId){
    const settings=runtime()?.getSettings?.()||{};
    return settings.tracks?.[String(Number(stemId)||0)]||{manualDelayMs:0,polarity:false};
  }

  function needsCorrectedRealtime(stemId){
    const row=sourceTrack(stemId);
    if(!row)return false;
    const ctx=context();
    const latency=api()?.pluginLatencyMs?.(row.plugins||[],Number(ctx?.sampleRate||48000))||0;
    const track=sourceEngineSettings(stemId);
    return latency>.01||Boolean(track.polarity)||Math.abs(num(row.trim,0))>.001;
  }

  function flattenChunks(chunks,channels=2){
    const rows=Array.isArray(chunks)?chunks:[];
    const total=rows.reduce((sum,row)=>sum+Number(row?.[0]?.length||0),0);
    return Array.from({length:channels},(_,channel)=>{
      const out=new Float32Array(total);
      let offset=0;
      rows.forEach(row=>{
        const src=row?.[Math.min(channel,(row?.length||1)-1)]||row?.[0]||new Float32Array(0);
        out.set(src,offset);
        offset+=src.length;
      });
      return out;
    });
  }

  async function correctedRealtimeRender(stemId,range){
    const rt=core();
    const engine=runtime();
    const ctx=context();
    const row=sourceTrack(stemId);
    const stem=rt?.getStem?.(Number(stemId||0));
    const source=rt?.getStemCaptureSource?.(Number(stemId||0));
    if(!rt||!engine||!ctx||!row||!stem||!source)throw new Error('Corrected track-print source is unavailable.');
    if(rt.isCoreRecording?.())throw new Error('Stop recording before bouncing audio.');
    if(ctx.state==='suspended')await ctx.resume();

    const duration=Math.max(.05,num(agent().duration,.05));
    const start=clamp(range?.start,0,duration);
    const end=clamp(range?.end,start,duration);
    if(end<=start+.01)throw new Error('Select a valid bounce range.');

    const settings=engine.getSettings?.()||{};
    const pre=Math.min(clamp(settings.preRollSeconds,0,10),start);
    const post=clamp(settings.postRollSeconds,0,15);
    const pluginMs=Math.max(0,num(api()?.pluginLatencyMs?.(row.plugins||[],Number(ctx.sampleRate||48000)),0));
    const pluginSeconds=pluginMs/1000;
    const sampleRate=Number(ctx.sampleRate||48000);
    const captureStart=start-pre;
    const originalPosition=num(rt.getPosition?.(),0);
    const wasPlaying=Boolean(rt.isPlaying?.());
    if(wasPlaying)rt.pause?.();

    if(typeof rt.seek!=='function')throw new Error('Verified render seek is unavailable.');

    const phase=ctx.createGain();
    phase.gain.value=sourceEngineSettings(stemId).polarity?-1:1;
    const processor=ctx.createScriptProcessor(2048,2,2);
    const sink=ctx.createGain();
    sink.gain.value=0;
    const chunks=[];
    processor.onaudioprocess=event=>{
      const input=event.inputBuffer;
      const count=Math.max(1,Math.min(2,input.numberOfChannels||1));
      const block=[];
      for(let channel=0;channel<2;channel+=1){
        block.push(new Float32Array(input.getChannelData(Math.min(channel,count-1))));
      }
      chunks.push(block);
    };

    source.connect(phase);
    phase.connect(processor);
    processor.connect(sink);
    sink.connect(ctx.destination);

    try{
      await rt.seek(captureStart,false);
      await rt.play?.();
      const deadline=Date.now()+Math.max(6000,(end-captureStart+3)*1000);
      while(Date.now()<deadline){
        const position=num(rt.getPosition?.(),0);
        if(position>=end-.025||(!rt.isPlaying?.()&&position>=end-.1))break;
        await sleep(20);
      }
      rt.pause?.();
      const tail=post+pluginSeconds;
      if(tail>0)await sleep(tail*1000);
    }finally{
      processor.onaudioprocess=null;
      disconnect(source,phase);
      disconnect(phase);
      disconnect(processor);
      disconnect(sink);
      try{
        await rt.seek(originalPosition,false);
        if(wasPlaying)await rt.play?.();
      }catch(error){}
    }

    let pcm=flattenChunks(chunks,2);
    const trimFrames=Math.max(0,Math.round((pre+pluginSeconds)*sampleRate));
    const wantedFrames=Math.max(1,Math.round((end-start+post)*sampleRate));
    pcm=pcm.map(channel=>channel.slice(trimFrames,Math.min(channel.length,trimFrames+wantedFrames)));
    if((pcm[0]?.length||0)<Math.min(Math.round(sampleRate*.1),Math.round((end-start)*sampleRate*.5))){
      throw new Error('Corrected bounce captured too little audio.');
    }
    return {pcm,sampleRate,range:{start,end,duration:end-start},postRoll:post,mode:'realtime-latency-corrected'};
  }

  async function applyRenderedTrackHandoff(snapshot){
    if(!snapshot)return false;
    const sourceId=Number(snapshot.originalStemId||0);
    const renderedId=Number(snapshot.renderStemId||0);
    if(sourceId<1||renderedId<1)return false;

    for(let attempt=0;attempt<40;attempt+=1){
      if(core()?.getStem?.(renderedId)&&sourceTrack(sourceId))break;
      await sleep(75);
    }
    if(!core()?.getStem?.(renderedId))return false;

    const row=sourceTrack(sourceId);
    if(!row)return false;
    const track=sourceEngineSettings(sourceId);
    const execute=studio()?.executeAgentCommand;
    if(typeof execute!=='function')return false;

    if(String(row.route||'direct')!=='direct'){
      await execute({type:'route',stem_id:renderedId,route:String(row.route)}).catch(()=>{});
    }
    await execute({type:'send',stem_id:renderedId,bus:'a',value:clamp(row.send_a,0,1)}).catch(()=>{});
    await execute({type:'send',stem_id:renderedId,bus:'b',value:clamp(row.send_b,0,1)}).catch(()=>{});
    runtime()?.setTrackDelay?.(renderedId,clamp(track.manualDelayMs,-500,500));
    root.dispatchEvent(new CustomEvent('stonefellow:stem-audio-engine-v215-handoff',{detail:{build:BUILD,sourceStemId:sourceId,renderStemId:renderedId}}));
    return true;
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

  async function engineSidecar(action,mixId,engine=null){
    if(!nextFetch||!ext.endpoint||!(Number(mixId)>0))return null;
    const form=new root.FormData();
    form.append('csrf_token',String(cfg.csrf||''));
    form.append('action',String(action));
    form.append('track_id',String(cfg.trackId||0));
    form.append('mix_id',String(mixId));
    if(engine)form.append('engine_json',JSON.stringify(engine));
    const response=await nextFetch(String(ext.endpoint),{method:'POST',credentials:'same-origin',body:form});
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok)throw new Error(data?.error||'Could not synchronize v215 saved-mix state.');
    return data;
  }

  async function applySavedEngine(engine){
    const rt=runtime();
    const execute=studio()?.executeAgentCommand;
    if(!rt||!engine||typeof engine!=='object')return false;
    const current=rt.getSettings?.()||{};
    rt.setPdc?.(engine.pdc!==false);
    rt.setRecordOffset?.(num(engine.recordOffsetMs,0));
    const ids=new Set([...Object.keys(current.tracks||{}),...Object.keys(engine.tracks||{})]);
    ids.forEach(key=>{
      const id=Number(key||0);
      if(id<1)return;
      rt.setTrackDelay?.(id,num(engine.tracks?.[key]?.manualDelayMs,0));
      rt.setPolarity?.(id,Boolean(engine.tracks?.[key]?.polarity));
    });
    if(typeof execute==='function'){
      await execute({type:'pre_roll',value:num(engine.preRollSeconds,1)}).catch(()=>{});
      await execute({type:'post_roll',value:num(engine.postRollSeconds,2)}).catch(()=>{});
    }
    rt.refresh?.();
    return true;
  }

  async function savedMixFetch(input,init){
    const payload=mixPayload(input,init);
    const response=await nextFetch(input,init);
    if(!payload||!response.ok)return response;
    try{
      if(payload.action==='save'){
        const data=await response.clone().json();
        const engine=runtime()?.getSettings?.();
        if(data?.mix_id&&engine)await engineSidecar('save_mix_engine',Number(data.mix_id),engine);
      }else if(payload.action==='load'){
        const mixId=Number(payload.mix_id||0);
        if(mixId>0){
          const data=await engineSidecar('load_mix_engine',mixId);
          if(data?.has_engine!==false)await applySavedEngine(data?.engine);
        }
      }
    }catch(error){
      console.warn('Stem v215 saved-mix state:',error);
    }
    return response;
  }

  function installSavedMixBridge(){
    if(fetchWrapper||!root.fetch||!cfg.mixEndpoint||!ext.endpoint)return false;
    nextFetch=root.fetch.bind(root);
    fetchWrapper=savedMixFetch;
    root.fetch=fetchWrapper;
    return true;
  }

  function bind(){
    const engine=runtime();
    if(!engine||!core()||!studio()||!api()){
      attempts+=1;
      if(attempts<240)root.setTimeout(bind,60);
      else root.__STONEFELLOW_STEM_V215_HARDENING__=false;
      return;
    }

    engine.registerOfflineProvider?.({
      supports:(stemId)=>needsCorrectedRealtime(stemId),
      render:(stemId,range)=>correctedRealtimeRender(stemId,range)
    });
    installSavedMixBridge();

    if(pendingSnapshot){
      root.setTimeout(()=>void applyRenderedTrackHandoff(pendingSnapshot),220);
    }

    root.StonefellowStemAudioEngineV215Hardening={
      build:BUILD,
      needsCorrectedRealtime,
      correctedRealtimeRender,
      applyRenderedTrackHandoff,
      applySavedEngine,
      installSavedMixBridge
    };
    root.dispatchEvent(new CustomEvent('stonefellow:stem-audio-engine-v215-hardening',{detail:{build:BUILD}}));
  }

  bind();
  root.addEventListener('pagehide',()=>{
    if(fetchWrapper&&root.fetch===fetchWrapper)root.fetch=nextFetch;
  },{once:true});
})(typeof globalThis!=='undefined'?globalThis:window);
