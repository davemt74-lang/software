(function(root,factory){
  const api=factory(root);
  if(typeof module==='object'&&module.exports)module.exports=api;
  const streamingOnly=Boolean(root?.document&&api?.configuredProjectRequiresStreaming?.());
  if(streamingOnly){
    root.__STONEFELLOW_STEM_STREAMING_ONLY_V234__=true;
    root.StonefellowStemBufferSchedulerV202Runtime=api;
    root.StonefellowStemBufferSchedulerV202=null;
  }else{
    root.StonefellowStemBufferSchedulerV202=api;
  }
})(typeof globalThis!=='undefined'?globalThis:window,function(root){
  'use strict';

  const finite=value=>Number.isFinite(Number(value));
  const positive=(value,fallback)=>Math.max(.0001,finite(value)?Number(value):fallback);
  const DEFAULT_MAX_DECODED_BYTES=384*1024*1024;

  function configuredProjectDecodeEstimate(){
    const rows=Array.isArray(root?.STONEFELLOW_STEM_STUDIO?.stems)?root.STONEFELLOW_STEM_STUDIO.stems:[];
    let bytes=0,matched=0;
    rows.forEach(row=>{
      if(row?.isEmptyRecordingTrack||!String(row?.url||''))return;
      const duration=Math.max(0,Number(row?.duration)||0);if(duration<=0)return;
      const channels=Math.max(1,Math.min(8,Number(row?.channels||row?.channelCount)||2));
      const sampleRate=Math.max(8000,Math.min(192000,Number(row?.sampleRate||row?.sample_rate)||48000));
      bytes+=duration*sampleRate*channels*4;matched+=1;
    });
    return {bytes:Math.ceil(bytes),matched,total:rows.length,maxDecodedBytes:DEFAULT_MAX_DECODED_BYTES};
  }

  function configuredProjectRequiresStreaming(maxDecodedBytes=DEFAULT_MAX_DECODED_BYTES){
    const estimate=configuredProjectDecodeEstimate();
    return estimate.matched>0&&estimate.bytes>Math.max(16*1024*1024,Number(maxDecodedBytes)||DEFAULT_MAX_DECODED_BYTES);
  }

  // Seek and drift recovery are owned by StonefellowStemTransportV200.
  // This module schedules decoded audio and must not patch transport authority.


  // Project readiness is owned by the canonical Stem project loader.
  // This module owns decoded-audio planning, loading and scheduling only.

  function planClip(clip,options={}){
    const position=Math.max(0,Number(options.position)||0);
    const projectEnd=Math.max(position,Number(options.projectEnd)||position);
    const projectRate=positive(options.projectRate,1);
    const mediaRate=positive(options.mediaRate,1);
    const bufferDuration=Math.max(0,Number(options.bufferDuration)||0);
    const clipStart=Math.max(0,Number(clip?.timelineStart)||0);
    const clipLength=Math.max(0,Number(clip?.timelineLength)||0);
    const clipEnd=Math.min(projectEnd,clipStart+clipLength);
    const eventStart=Math.max(position,clipStart);
    if(!clip||clip.muted||clipLength<=0||eventStart>=clipEnd)return null;
    const sourceStart=Math.max(0,Number(clip.sourceStart)||0);
    const sourceEnd=Math.min(bufferDuration||Infinity,Math.max(sourceStart,finite(clip.sourceEnd)?Number(clip.sourceEnd):bufferDuration));
    const offset=sourceStart+(eventStart-clipStart)*(mediaRate/projectRate);
    const available=Math.max(0,sourceEnd-offset);
    const wanted=(clipEnd-eventStart)*(mediaRate/projectRate);
    const sourceDuration=Math.min(available,wanted);
    if(sourceDuration<=.001)return null;
    const baseGain=Math.pow(10,(Number(clip.gainDb)||0)/20);
    const elapsed=eventStart-clipStart;
    const fadeInTimeline=Math.max(0,Math.min(Number(clip.fadeIn)||0,clipLength));
    const fadeOutTimeline=Math.max(0,Math.min(Number(clip.fadeOut)||0,clipLength));
    const remainingAtStart=Math.max(0,clipLength-elapsed);
    let gainAtStart=baseGain;
    if(fadeInTimeline>0)gainAtStart=Math.min(gainAtStart,baseGain*Math.min(1,elapsed/fadeInTimeline));
    if(fadeOutTimeline>0)gainAtStart=Math.min(gainAtStart,baseGain*Math.min(1,remainingAtStart/fadeOutTimeline));
    return {
      when:(eventStart-position)/projectRate,offset,sourceDuration,playbackRate:mediaRate,
      gain:baseGain,gainAtStart,
      fadeInRemaining:Math.max(0,fadeInTimeline-elapsed)/projectRate,
      fadeOutDelay:Math.max(0,clipLength-fadeOutTimeline-elapsed)/projectRate,
      fadeOut:fadeOutTimeline/projectRate,timelineStart:eventStart,
      timelineEnd:eventStart+(sourceDuration/mediaRate)*projectRate
    };
  }

  function createScheduler(context,options={}){
    if(!context)throw new Error('AudioContext is required.');
    const fetcher=options.fetcher||root?.fetch?.bind(root);
    const maxDecodedBytes=Math.max(16*1024*1024,Number(options.maxDecodedBytes)||DEFAULT_MAX_DECODED_BYTES);
    const buffers=new Map();
    const active=new Set();
    let decodedBytes=0,estimatedDecodedBytes=0,memoryBudgetExceeded=false,memoryBudgetError=null;

    function estimateKnownProject(urls){
      const rows=Array.isArray(root?.STONEFELLOW_STEM_STUDIO?.stems)?root.STONEFELLOW_STEM_STUDIO.stems:[];
      if(!rows.length)return {bytes:0,matched:0,total:0};
      const byUrl=new Map();
      rows.forEach(row=>{const url=String(row?.url||'');if(url)byUrl.set(url,row);});
      const unique=[...new Set((urls||[]).map(String).filter(Boolean))];
      let bytes=0,matched=0;
      unique.forEach(url=>{
        const row=byUrl.get(url);if(!row)return;
        const duration=Math.max(0,Number(row.duration)||0);if(duration<=0)return;
        const channels=Math.max(1,Math.min(8,Number(row.channels||row.channelCount)||2));
        const sampleRate=Math.max(8000,Math.min(192000,Number(row.sampleRate||row.sample_rate)||48000));
        bytes+=duration*sampleRate*channels*4;matched+=1;
      });
      return {bytes:Math.ceil(bytes),matched,total:unique.length};
    }
    function makeBudgetError(){
      const error=new Error('Decoded project audio exceeds the safe memory budget. Streaming media transport is required for this project.');
      error.name='StemDecodedAudioBudgetError';error.code='STEM_DECODED_AUDIO_BUDGET';
      error.maxDecodedBytes=maxDecodedBytes;error.decodedBytes=decodedBytes;error.estimatedDecodedBytes=estimatedDecodedBytes;
      return error;
    }
    function tripBudget(estimate=0){
      estimatedDecodedBytes=Math.max(estimatedDecodedBytes,Math.max(0,Number(estimate)||0));
      if(!memoryBudgetExceeded){memoryBudgetExceeded=true;memoryBudgetError=makeBudgetError();}
      buffers.clear();decodedBytes=0;return memoryBudgetError;
    }
    async function load(url){
      const key=String(url||'');
      if(!key)throw new Error('Audio URL is required.');
      if(memoryBudgetExceeded)throw memoryBudgetError||makeBudgetError();
      if(buffers.has(key))return buffers.get(key);
      const pending=(async()=>{
        if(!fetcher)throw new Error('Fetch is unavailable.');
        const response=await fetcher(key,{credentials:'same-origin'});
        if(!response?.ok)throw new Error(`Audio fetch failed (${response?.status||0}).`);
        const bytes=await response.arrayBuffer();
        if(memoryBudgetExceeded)throw memoryBudgetError||makeBudgetError();
        const buffer=await context.decodeAudioData(bytes.slice(0));
        const estimated=Math.max(0,Number(buffer.length)||0)*Math.max(1,Number(buffer.numberOfChannels)||1)*4;
        if(decodedBytes+estimated>maxDecodedBytes)throw tripBudget(decodedBytes+estimated);
        decodedBytes+=estimated;return buffer;
      })();
      buffers.set(key,pending);
      try{return await pending;}catch(error){buffers.delete(key);throw error;}
    }
    async function prepare(urls){
      if(memoryBudgetExceeded)throw memoryBudgetError||makeBudgetError();
      const unique=[...new Set((urls||[]).map(String).filter(Boolean))];
      const preflight=estimateKnownProject(unique);estimatedDecodedBytes=preflight.bytes;
      if(preflight.matched>0&&preflight.bytes>maxDecodedBytes)throw tripBudget(preflight.bytes);
      for(const url of unique)await load(url);
      return unique.length;
    }
    function stop(when=context.currentTime){
      active.forEach(item=>{
        try{item.source.stop(Math.max(context.currentTime,Number(when)||0));}catch(error){}
        try{item.source.disconnect();}catch(error){}
        try{item.gain.disconnect();}catch(error){}
      });
      active.clear();
    }
    async function schedule(items,startAt=context.currentTime+.035,options={}){
      if(memoryBudgetExceeded)throw memoryBudgetError||makeBudgetError();
      const rows=Array.isArray(items)?items:[];
      const isCurrent=typeof options.isCurrent==='function'?options.isCurrent:()=>true;
      if(!isCurrent())throw new Error('Stale AudioBuffer transport generation.');
      const decoded=await Promise.all(rows.map(item=>load(item.url)));
      if(!isCurrent())throw new Error('Stale AudioBuffer transport generation.');
      const effectiveStart=Math.max(Number(startAt)||0,context.currentTime+.025);
      const scheduled=[];
      for(let index=0;index<rows.length;index++){
        if(!isCurrent()){stop();throw new Error('Stale AudioBuffer transport generation.');}
        const item=rows[index],buffer=decoded[index];
        const plan=planClip(item.clip,{...item,bufferDuration:buffer.duration});
        if(!plan)continue;
        const source=context.createBufferSource();
        const gain=context.createGain();
        const when=effectiveStart+plan.when;
        const end=when+plan.sourceDuration/plan.playbackRate;
        source.buffer=buffer;source.playbackRate.value=plan.playbackRate;
        source.connect(gain);gain.connect(item.destination);
        gain.gain.cancelScheduledValues(when);gain.gain.setValueAtTime(plan.gainAtStart,when);
        if(plan.fadeInRemaining>0)gain.gain.linearRampToValueAtTime(plan.gain,Math.min(end,when+plan.fadeInRemaining));
        if(plan.fadeOut>0){
          const fadeStart=Math.max(when,Math.min(end,when+plan.fadeOutDelay));
          gain.gain.setValueAtTime(plan.gain,fadeStart);gain.gain.linearRampToValueAtTime(0,end);
        }
        const record={source,gain};active.add(record);
        source.onended=()=>{active.delete(record);try{source.disconnect();}catch(error){}try{gain.disconnect();}catch(error){}};
        source.start(when,plan.offset,plan.sourceDuration);
        scheduled.push({...plan,when,end,url:item.url});
      }
      return {startAt:effectiveStart,events:scheduled};
    }
    return Object.freeze({
      load,prepare,schedule,stop,getBuffer:url=>load(url),
      bufferCount:()=>buffers.size,activeCount:()=>active.size,
      decodedBytes:()=>decodedBytes,estimatedDecodedBytes:()=>estimatedDecodedBytes,
      maxDecodedBytes:()=>maxDecodedBytes,memoryBudgetExceeded:()=>memoryBudgetExceeded
    });
  }

  return Object.freeze({
    planClip,createScheduler,configuredProjectDecodeEstimate,configuredProjectRequiresStreaming
  });
});