(function(root,factory){
  const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;
  root.StonefellowStemLoopPlannerV204=api;
})(typeof globalThis!=='undefined'?globalThis:window,function(){
  'use strict';

  function expandLoopClip(clip,options={}){
    if(!clip||clip.muted)return [];
    const projectRate=Math.max(.25,Math.min(4,Number(options.projectRate)||1));
    const mediaRate=Math.max(.25,Math.min(4,Number(options.mediaRate)||1));
    const position=Math.max(0,Number(options.position)||0);
    const projectEnd=Math.max(position,Number(options.projectEnd)||position);
    const overallStart=Math.max(0,Number(clip.timelineStart)||0);
    const overallEnd=Math.min(projectEnd,overallStart+Math.max(0,Number(clip.timelineLength)||0));
    const sourceStart=Math.max(0,Number(clip.sourceStart)||0);
    const sourceEnd=Math.max(sourceStart,Number(clip.sourceEnd)||sourceStart);
    const sourceLength=sourceEnd-sourceStart;
    const segmentTimeline=sourceLength*(projectRate/mediaRate);
    if(sourceLength<=.001||segmentTimeline<=.001||overallEnd<=Math.max(position,overallStart))return [];
    const firstIndex=Math.max(0,Math.floor((Math.max(position,overallStart)-overallStart)/segmentTimeline));
    const maxRepeats=Math.max(1,Math.min(65536,Math.floor(Number(options.maxRepeats)||8192)));
    const result=[];
    for(let index=firstIndex;index<firstIndex+maxRepeats;index++){
      const timelineStart=overallStart+index*segmentTimeline;
      if(timelineStart>=overallEnd-.000001)break;
      const timelineLength=Math.min(segmentTimeline,overallEnd-timelineStart);
      const usedSource=timelineLength*(mediaRate/projectRate);
      result.push({
        id:`${String(clip.id||'loop')}-repeat-${index}`,
        timelineStart,
        timelineLength,
        sourceStart,
        sourceEnd:Math.min(sourceEnd,sourceStart+usedSource),
        gainDb:Number(clip.gainDb)||0,
        muted:false,
        fadeIn:index===0?Math.max(0,Number(clip.fadeIn)||0):0,
        fadeOut:timelineStart+timelineLength>=overallEnd-.000001?Math.max(0,Number(clip.fadeOut)||0):0,
        repeatIndex:index
      });
    }
    const plannedEnd=result.length
      ? result[result.length-1].timelineStart+result[result.length-1].timelineLength
      : Math.max(position,overallStart);
    if(plannedEnd<overallEnd-.000001)throw new Error('Loop expansion exceeds the safe repeat budget.');
    return result;
  }

  return Object.freeze({expandLoopClip});
});
