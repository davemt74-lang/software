(function(root,factory){
  const api=factory(root);
  if(typeof module==='object'&&module.exports)module.exports=api;
  root.StonefellowStemTimeStretchV203=api;
})(typeof globalThis!=='undefined'?globalThis:window,function(root){
  'use strict';

  function planEvent(clip,options={}){
    const position=Math.max(0,Number(options.position)||0);
    const projectEnd=Math.max(position,Number(options.projectEnd)||position);
    const projectRate=Math.max(.25,Math.min(4,Number(options.projectRate)||1));
    const mediaRate=Math.max(.25,Math.min(4,Number(options.mediaRate)||1));
    const clipStart=Math.max(0,Number(clip?.timelineStart)||0);
    const clipLength=Math.max(0,Number(clip?.timelineLength)||0);
    const eventStart=Math.max(position,clipStart);
    const eventEnd=Math.min(projectEnd,clipStart+clipLength);
    if(!clip||clip.muted||eventStart>=eventEnd)return null;
    const elapsed=eventStart-clipStart;
    const sampleRate=Number(options.sampleRate||48000);
    const sourcePosition=Math.max(0,Number(clip.sourceStart)||0)+elapsed*(mediaRate/projectRate);
    const bufferDuration=Math.max(0,Number(options.bufferDuration)||Infinity);
    const sourceEnd=Math.min(bufferDuration,Number.isFinite(Number(clip.sourceEnd))?Number(clip.sourceEnd):bufferDuration);
    const availableOutput=Math.max(0,sourceEnd-sourcePosition)/mediaRate;
    const outputDuration=Math.min((eventEnd-eventStart)/projectRate,availableOutput);
    if(outputDuration<=.0001)return null;
    const sourceFrame=sourcePosition*sampleRate;
    const baseGain=Math.pow(10,(Number(clip.gainDb)||0)/20);
    const fadeIn=Math.max(0,Math.min(clipLength,Number(clip.fadeIn)||0));
    const fadeOut=Math.max(0,Math.min(clipLength,Number(clip.fadeOut)||0));
    return {
      startDelay:(eventStart-position)/projectRate,
      outputDuration,
      sourceFrame,
      rate:mediaRate,
      gain:baseGain,
      fadeInDuration:Math.max(0,fadeIn-elapsed)/projectRate,
      fadeOutDuration:Math.min(outputDuration,fadeOut/projectRate)
    };
  }

  function createEngine(context,options={}){
    if(!context?.audioWorklet)throw new Error('AudioWorklet is unavailable.');
    const workletUrl=String(options.workletUrl||'/admin/stem-time-stretch-worklet-v203.js?v=203');
    const tracks=new Map();
    let moduleReady=null;
    let generation=0;
    const ensure=()=>moduleReady||=(context.audioWorklet.addModule(workletUrl));

    async function prepareTrack(key,buffer,destination){
      await ensure();
      const id=String(key);
      if(tracks.has(id)){
        const existing=tracks.get(id);
        if(existing.destination!==destination){
          try{existing.node.disconnect();}catch(error){}
          existing.node.connect(destination);
          existing.destination=destination;
        }
        return existing;
      }
      const channels=[];
      for(let index=0;index<buffer.numberOfChannels;index++)channels.push(buffer.getChannelData(index).slice());
      const node=new AudioWorkletNode(context,'stonefellow-time-stretch-v203',{
        numberOfInputs:0,
        numberOfOutputs:1,
        outputChannelCount:[Math.max(1,Math.min(2,buffer.numberOfChannels))]
      });
      node.connect(destination);
      node.port.postMessage({type:'buffer',channels},channels.map(channel=>channel.buffer));
      const track={node,duration:Number(buffer.duration)||0,destination};
      tracks.set(id,track);
      return track;
    }

    function stop(){
      generation+=1;
      tracks.forEach(track=>track.node.port.postMessage({type:'clear',generation}));
      return generation;
    }

    function schedule(key,clips,options={}){
      const track=tracks.get(String(key));
      if(!track)throw new Error('Time-stretch track is not prepared.');
      const startAt=Math.max(context.currentTime+.035,Number(options.startAt)||0);
      const sampleRate=Number(context.sampleRate)||48000;
      const events=(clips||[]).map(clip=>{
        const plan=planEvent(clip,{...options,sampleRate,bufferDuration:track.duration});
        if(!plan)return null;
        return {
          startFrame:Math.round((startAt+plan.startDelay)*sampleRate),
          outputFrames:Math.round(plan.outputDuration*sampleRate),
          sourceFrame:plan.sourceFrame,
          rate:plan.rate,
          gain:plan.gain,
          fadeInFrames:Math.round(plan.fadeInDuration*sampleRate),
          fadeOutFrames:Math.round(plan.fadeOutDuration*sampleRate)
        };
      }).filter(Boolean);
      track.node.port.postMessage({type:'schedule',generation,events});
      return {startAt,eventCount:events.length,generation};
    }

    return Object.freeze({ensure,prepareTrack,schedule,stop,trackCount:()=>tracks.size,generation:()=>generation});
  }

  return Object.freeze({planEvent,createEngine});
});
