(() => {
  'use strict';

  const root = typeof window !== 'undefined' ? window : globalThis;
  const BUILD = 'artist-listening-realtime';
  const FINAL_DUPLICATE_WINDOW_MS = 1500;
  const FINAL_LOGICAL_TOLERANCE_MS = 750;
  const FINAL_REGISTRY_TTL_MS = 12000;
  const recentFinals = root.STONEFELLOW_ARTIST_LISTENING_RECENT_FINALS instanceof Map
    ? root.STONEFELLOW_ARTIST_LISTENING_RECENT_FINALS
    : new Map();
  root.STONEFELLOW_ARTIST_LISTENING_RECENT_FINALS = recentFinals;

  const cleanText = value => String(value || '').replace(/\s+/g, ' ').trim();
  const token = value => String(value || '').toLowerCase().replace(/[^a-z0-9']/g, '');
  const words = value => cleanText(value).split(' ').filter(Boolean);
  const finalSignature = value => words(value).map(token).filter(Boolean).join(' ');

  function recentFinalDuplicate(text, atMs = 0) {
    const signature = finalSignature(text);
    if (!signature) return false;
    const wallMs = Date.now();
    const logicalMs = Math.max(0, Number(atMs || 0));
    const previous = recentFinals.get(signature);
    const duplicate = !!previous && (
      wallMs - Number(previous.wallMs || 0) <= FINAL_DUPLICATE_WINDOW_MS
      || (logicalMs > 0 && Number(previous.logicalMs || 0) > 0
        && Math.abs(logicalMs - Number(previous.logicalMs || 0)) <= FINAL_LOGICAL_TOLERANCE_MS)
    );
    recentFinals.set(signature, {wallMs, logicalMs});
    for (const [key, value] of recentFinals.entries()) {
      if (wallMs - Number(value?.wallMs || 0) > FINAL_REGISTRY_TTL_MS) recentFinals.delete(key);
    }
    return duplicate;
  }

  function reconcileFinal(history, incoming, maxOverlap = 32) {
    const left = words(history);
    const right = words(incoming);
    if (!right.length) return {delta:'', merged:cleanText(history), duplicate:true, overlapWords:0};
    const leftTokens = left.map(token);
    const rightTokens = right.map(token);
    const recent = leftTokens.slice(-Math.max(48, maxOverlap));
    const candidate = rightTokens.join(' ');
    if (recent.join(' ').endsWith(candidate)) return {delta:'', merged:cleanText(history), duplicate:true, overlapWords:right.length};
    let overlap = 0;
    for (let count = Math.min(maxOverlap, left.length, right.length); count >= 2; count -= 1) {
      if (leftTokens.slice(-count).join(' ') === rightTokens.slice(0, count).join(' ')) { overlap = count; break; }
    }
    if (!overlap && leftTokens.length && rightTokens.length === 1 && leftTokens.at(-1) === rightTokens[0]) overlap = 1;
    const delta = cleanText(right.slice(overlap).join(' '));
    return {delta,merged:cleanText(`${cleanText(history)} ${delta}`),duplicate:delta === '',overlapWords:overlap};
  }

  class TranscriptContinuity {
    constructor(initialText = '') { this.committed=cleanText(initialText);this.interim='';this.lastFinalAt=0;this.accepted=0;this.duplicates=0; }
    seed(text) { this.committed=cleanText(text);return this.committed; }
    setInterim(text) { this.interim=cleanText(text);return this.interim; }
    finalize(text, atMs = Date.now()) {
      const logicalAt = Number(atMs || Date.now());
      if (recentFinalDuplicate(text, logicalAt)) {
        this.interim='';
        this.lastFinalAt=logicalAt;
        this.duplicates+=1;
        return {delta:'',merged:this.committed,duplicate:true,overlapWords:words(text).length,sharedDuplicate:true};
      }
      const result=reconcileFinal(this.committed,text);
      this.interim='';
      this.lastFinalAt=logicalAt;
      if(result.duplicate)this.duplicates+=1;
      else{this.committed=result.merged;this.accepted+=1;}
      return result;
    }
  }

  function featureDistance(a, b) {
    if (!a || !b) return Number.POSITIVE_INFINITY;
    const rms=Math.abs(Number(a.rms||0)-Number(b.rms||0))/.16;
    const zcr=Math.abs(Number(a.zcr||0)-Number(b.zcr||0))/.18;
    const centroid=Math.abs(Number(a.centroid||0)-Number(b.centroid||0))/.25;
    return Math.sqrt((rms*rms*.10)+(zcr*zcr*.34)+(centroid*centroid*.56));
  }

  function aggregateFeatures(frames, startMs, endMs) {
    const selected=(Array.isArray(frames)?frames:[]).filter(frame=>{const time=Number(frame?.timeMs||0);return time>=Math.max(0,Number(startMs||0)-180)&&time<=Number(endMs||0)+120&&Number(frame?.rms||0)>.004;});
    if(!selected.length)return null;
    const average=key=>selected.reduce((sum,row)=>sum+Number(row[key]||0),0)/selected.length;
    return {rms:average('rms'),zcr:average('zcr'),centroid:average('centroid'),frames:selected.length};
  }

  class SpeakerTurnModel {
    constructor(options = {}) { this.maxSpeakers=Math.max(1,Math.min(4,Number(options.maxSpeakers||4)));this.expectedSpeakers=Math.max(0,Math.min(4,Number(options.expectedSpeakers||0)));this.clusters=[];this.lastIndex=1;this.lastEndedMs=0;this.turns=0;this.switchCandidate=0;this.switchVotes=0; }
    setExpected(count) { this.expectedSpeakers=Math.max(0,Math.min(4,Number(count||0))); }
    _addCluster(features) { const index=this.clusters.length+1;this.clusters.push({index,centroid:features?{...features}:null,samples:features?1:0});return this.clusters.at(-1); }
    _update(cluster,features) { if(!features)return;if(!cluster.centroid){cluster.centroid={...features};cluster.samples=1;return;}const weight=Math.min(20,Math.max(2,cluster.samples*1.5));for(const key of ['rms','zcr','centroid'])cluster.centroid[key]=(cluster.centroid[key]*weight+Number(features[key]||0))/(weight+1);cluster.samples+=1; }
    _stableCandidate(candidate,distance,runnerUp,pauseMs) {
      const last=this.clusters[this.lastIndex-1]||candidate;
      if(!candidate||candidate.index===last.index){this.switchCandidate=0;this.switchVotes=0;return candidate||last;}
      const separation=Number.isFinite(runnerUp)?runnerUp-distance:1;
      const strong=distance<.52&&separation>.18;
      const decisive=distance<.28&&separation>.45;
      const conversationalGap=pauseMs>=900;
      if((strong&&conversationalGap)||decisive){this.switchCandidate=0;this.switchVotes=0;return candidate;}
      if(this.switchCandidate===candidate.index)this.switchVotes+=1;else{this.switchCandidate=candidate.index;this.switchVotes=1;}
      if(this.switchVotes>=2&&(pauseMs>=420||strong)){this.switchCandidate=0;this.switchVotes=0;return candidate;}
      return last;
    }
    assign(input = {}) {
      const features=input.features||null;const startedMs=Math.max(0,Number(input.startedMs||0));const endedMs=Math.max(startedMs,Number(input.endedMs||startedMs));const pauseMs=Math.max(0,startedMs-this.lastEndedMs);const forced=Math.max(0,Math.min(4,Number(input.forcedIndex||0)));
      while(forced&&this.clusters.length<forced)this._addCluster(null);
      let cluster=forced?this.clusters[forced-1]:null;let distance=0;let inferredConfidence=forced?1:.5;
      if(!cluster){
        if(!this.clusters.length)cluster=this._addCluster(features);
        else if(features){
          const ranked=this.clusters.map(item=>({item,distance:featureDistance(features,item.centroid)})).sort((a,b)=>a.distance-b.distance);
          const candidate=ranked[0].item;distance=ranked[0].distance;const runnerUp=ranked[1]?.distance??Number.POSITIVE_INFINITY;const limit=this.expectedSpeakers||this.maxSpeakers;const splitThreshold=this.expectedSpeakers?.78:1.02;
          const canSplit=this.clusters.length<limit&&this.turns>=1&&pauseMs>=650&&distance>=splitThreshold;
          if(canSplit){cluster=this._addCluster(features);inferredConfidence=Math.min(.9,.6+distance*.15);this.switchCandidate=0;this.switchVotes=0;}
          else{cluster=this._stableCandidate(candidate,distance,runnerUp,pauseMs);const chosenDistance=featureDistance(features,cluster.centroid);inferredConfidence=Math.max(.42,Math.min(.96,.94-chosenDistance*.28+(pauseMs>=900?.04:0)));distance=chosenDistance;}
        }else{cluster=this.clusters[this.lastIndex-1]||this.clusters[0];inferredConfidence=.3;}
      }
      this._update(cluster,features);this.lastIndex=cluster.index;this.lastEndedMs=endedMs;this.turns+=1;
      return {index:cluster.index,label:`Speaker ${cluster.index}`,confidence:inferredConfidence,distance,pauseMs,inferred:!forced};
    }
  }

  function autoTitle(date = new Date()) { const day=date.toLocaleDateString([],{month:'short',day:'numeric'});const time=date.toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});return `Transcription · ${day} · ${time}`; }

  const api={BUILD,FINAL_DUPLICATE_WINDOW_MS,FINAL_LOGICAL_TOLERANCE_MS,cleanText,reconcileFinal,recentFinalDuplicate,TranscriptContinuity,featureDistance,aggregateFeatures,SpeakerTurnModel,autoTitle};
  root.STONEFELLOW_ARTIST_LISTENING_REALTIME=api;
  if(typeof module!=='undefined'&&module.exports)module.exports=api;

})();