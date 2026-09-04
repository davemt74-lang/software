(function(root,factory){
  const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;
  root.StonefellowStemMasterClockV201=api;
})(typeof globalThis!=='undefined'?globalThis:window,function(){
  'use strict';

  const finite=value=>Number.isFinite(Number(value));
  const cleanRate=value=>Math.max(.25,Math.min(4,finite(value)?Number(value):1));
  const cleanPosition=value=>Math.max(0,finite(value)?Number(value):0);

  function createClock(options={}){
    const audioNow=typeof options.audioNow==='function'?options.audioNow:()=>NaN;
    const fallbackNow=typeof options.fallbackNow==='function'?options.fallbackNow:()=>Date.now()/1000;
    let anchorTimeline=0;
    let anchorClock=0;
    let rate=1;
    let running=false;
    let source='fallback';
    let lastTimeline=0;

    const readClock=()=>{
      const audio=Number(audioNow());
      if(finite(audio))return {time:audio,source:'audio-context'};
      return {time:Number(fallbackNow())||0,source:'fallback'};
    };

    const current=()=>{
      if(!running)return anchorTimeline;
      const now=readClock();
      // Never mix two clock epochs during a running transport. AudioContext
      // availability changes are adopted at the next explicit anchor.
      if(now.source!==source)return lastTimeline;
      lastTimeline=Math.max(0,anchorTimeline+Math.max(0,now.time-anchorClock)*rate);
      return lastTimeline;
    };

    const anchor=(position,nextRate,shouldRun,clockAt=null)=>{
      anchorTimeline=cleanPosition(position);
      rate=cleanRate(nextRate);
      const now=readClock();
      anchorClock=clockAt!==null&&finite(clockAt)?Number(clockAt):now.time;
      source=now.source;
      running=Boolean(shouldRun);
      lastTimeline=anchorTimeline;
      return anchorTimeline;
    };

    return Object.freeze({
      start(position=anchorTimeline,nextRate=rate,clockAt=null){return anchor(position,nextRate,true,clockAt);},
      pause(position=current()){return anchor(position,rate,false);},
      seek(position){return anchor(position,rate,running);},
      setRate(nextRate){return anchor(current(),nextRate,running);},
      current,
      snapshot(){return {position:current(),rate,running,source,anchorClock,anchorTimeline};}
    });
  }

  return Object.freeze({createClock,cleanRate,cleanPosition});
});
