(() => {
  'use strict';

  const BUILD = 'premium-voice-verified-v157-20260829';
  // Keep the first utterance deliberately short so streamed LLM output reaches
  // ElevenLabs quickly. Later chunks stay larger and are prefetched while the
  // current chunk is playing, preserving natural cadence without startup lag.
  const FIRST_CHUNK_LIMIT = 180;
  const CHUNK_LIMIT = 900;
  const AUDIO_FETCH_TIMEOUT_MS = 12000;
  const AUDIO_START_TIMEOUT_MS = 8000;
  const SILENT_WAV = 'data:audio/wav;base64,UklGRmQBAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YUABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==';
  const proof = window.STONEFELLOW_PREMIUM_VOICE_V122 = window.STONEFELLOW_PREMIUM_VOICE_V122 || {
    loaded:true,warmRequests:0,ticketRequests:0,streamStarts:0,streamEnds:0,
    chunkStarts:0,chunkEnds:0,livePushes:0,prefetchedTickets:0,failures:0,lastChunkCount:0,lastError:'',
    modelId:'',outputFormat:'',lastTicketMs:0,lastFirstAudioMs:0,bestFirstAudioMs:0,lastFirstChunkChars:0,lastStreamCreatedAt:0,
    unlockAttempts:0,unlockSuccesses:0,unlockFailures:0,audioUnlocked:false,lastPlaybackError:'',credentialState:'unknown',
    audioFetches:0,audioFetchFailures:0,audioStartTimeouts:0,stopsBeforeStart:0,verifiedReady:false
  };
  proof.build = BUILD;
  for(const [key,value] of Object.entries({unlockAttempts:0,unlockSuccesses:0,unlockFailures:0,audioUnlocked:false,lastPlaybackError:'',credentialState:'unknown',audioFetches:0,audioFetchFailures:0,audioStartTimeouts:0,stopsBeforeStart:0,verifiedReady:false})){
    if(!(key in proof))proof[key]=value;
  }

  const abortError = () => new DOMException('Aborted', 'AbortError');

  function resolveEndpoint(agentEndpoint) {
    try {
      return new URL('agent-voice-v117.php', new URL(String(agentEndpoint || '/api/chat.php'), window.location.href)).toString();
    } catch (error) {
      return '';
    }
  }

  function splitPart(part, limit) {
    const out=[];
    let rest=String(part||'').trim();
    while(rest.length>limit){
      let cut=-1;
      for(const marker of ['. ', '! ', '? ', '; ', ', ', ' — ', ' - ']){
        const index=rest.lastIndexOf(marker,limit);
        if(index>cut)cut=index+marker.length-1;
      }
      if(cut<Math.floor(limit*.55))cut=rest.lastIndexOf(' ',limit);
      if(cut<Math.floor(limit*.55))cut=limit;
      out.push(rest.slice(0,cut).trim());
      rest=rest.slice(cut).trim();
    }
    if(rest)out.push(rest);
    return out;
  }

  function sentenceChunks(text) {
    const clean=String(text||'').replace(/\r/g,'').trim();
    if(!clean)return [];
    const sentences=clean.match(/[^.!?\n]+(?:[.!?]+["')\]]*|\n+|$)/g)||[clean];
    const out=[];
    let first=true;
    let pending='';

    const flush=value=>{
      const part=String(value||'').replace(/\s+/g,' ').trim();
      if(!part)return;
      const limit=first?FIRST_CHUNK_LIMIT:CHUNK_LIMIT;
      if(first||part.length>limit){
        const pieces=splitPart(part,limit);
        pieces.forEach(piece=>{if(piece){out.push(piece);first=false;}});
        return;
      }
      const candidate=pending?`${pending} ${part}`:part;
      if(candidate.length<=limit){pending=candidate;return;}
      if(pending){out.push(pending);pending='';first=false;}
      splitPart(part,CHUNK_LIMIT).forEach(piece=>{if(piece)out.push(piece);});
    };

    for(const sentence of sentences){
      if(first){flush(sentence);continue;}
      const part=String(sentence||'').replace(/\s+/g,' ').trim();
      if(!part)continue;
      const candidate=pending?`${pending} ${part}`:part;
      if(candidate.length<=CHUNK_LIMIT)pending=candidate;
      else{
        if(pending)out.push(pending);
        pending='';
        splitPart(part,CHUNK_LIMIT).forEach(piece=>out.push(piece));
      }
    }
    if(pending)out.push(pending);
    return out.filter(Boolean);
  }

  function takeReadySentence(buffer, force=false, firstPending=false) {
    const text=String(buffer||'').replace(/\r/g,'');
    if(!text)return null;
    const match=text.match(/^([\s\S]*?[.!?]["')\]]*)(?=\s|\n|$)/);
    if(match&&match[1].trim())return {text:match[1].trim(),rest:text.slice(match[0].length).trimStart()};
    const newline=text.indexOf('\n');
    if(newline>=0&&text.slice(0,newline).trim().length>=24){
      return {text:text.slice(0,newline).trim(),rest:text.slice(newline+1).trimStart()};
    }
    const softLimit=firstPending?FIRST_CHUNK_LIMIT:CHUNK_LIMIT;
    if(text.length>softLimit+60){
      let cut=text.lastIndexOf(' ',softLimit);
      if(cut<Math.floor(softLimit*.6))cut=softLimit;
      return {text:text.slice(0,cut).trim(),rest:text.slice(cut).trimStart()};
    }
    if(force&&text.trim())return {text:text.trim(),rest:''};
    return null;
  }

  function PremiumVoice(options={}) {
    const endpoint=resolveEndpoint(options.agentEndpoint);
    const csrf=String(options.csrf||'');
    let active=null;
    let warmed=false;
    const primedAudio=typeof Audio==='function'?new Audio():null;
    let unlockPromise=null;
    let audioUnlocked=false;
    let outputVolume=1;
    const objectUrls=new WeakMap();
    if(primedAudio){
      primedAudio.preload='auto';
      primedAudio.playsInline=true;
    }

    function resetPrimedAudio(){
      if(!primedAudio)return;
      primedAudio.onplaying=null;
      primedAudio.onended=null;
      primedAudio.onerror=null;
      try{
        primedAudio.pause();
        primedAudio.removeAttribute('src');
        primedAudio.load();
      }catch(error){}
      primedAudio.muted=false;
      primedAudio.volume=outputVolume;
    }

    function unlock(){
      if(audioUnlocked)return Promise.resolve(true);
      if(unlockPromise)return unlockPromise;
      if(!primedAudio)return Promise.resolve(false);
      proof.unlockAttempts+=1;
      primedAudio.muted=false;
      primedAudio.volume=.001;
      primedAudio.src=SILENT_WAV;
      primedAudio.load();
      let playResult;
      try{playResult=primedAudio.play();}
      catch(error){
        proof.unlockFailures+=1;
        proof.lastPlaybackError=String(error?.message||error||'Audio unlock failed.');
        resetPrimedAudio();
        return Promise.resolve(false);
      }
      unlockPromise=Promise.resolve(playResult).then(()=>{
        audioUnlocked=true;
        proof.audioUnlocked=true;
        proof.unlockSuccesses+=1;
        resetPrimedAudio();
        return true;
      }).catch(error=>{
        proof.unlockFailures+=1;
        proof.lastPlaybackError=String(error?.message||error||'Audio unlock failed.');
        resetPrimedAudio();
        return false;
      }).finally(()=>{unlockPromise=null;});
      return unlockPromise;
    }

    async function warm(){
      if(warmed||!endpoint||!csrf)return warmed;
      proof.warmRequests+=1;
      try{
        const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({csrf_token:csrf,action:'warm'}),keepalive:true});
        const data=await response.json().catch(()=>({}));
        warmed=response.ok&&!!data?.ready;
        proof.verifiedReady=warmed&&data?.verified===true;
        proof.modelId=String(data?.model_id||proof.modelId||'');
        proof.outputFormat=String(data?.output_format||proof.outputFormat||'');
        proof.credentialState=String(data?.credential_state||proof.credentialState||'unknown');
        if(!warmed&&data?.error)proof.lastError=String(data.error);
      }catch(error){proof.lastError=String(error?.message||error||'Voice warm-up failed');}
      return warmed;
    }

    async function fetchAudio(streamUrl,signal){
      const controller=new AbortController();
      const timeout=setTimeout(()=>controller.abort(),AUDIO_FETCH_TIMEOUT_MS);
      const abort=()=>controller.abort();
      signal?.addEventListener?.('abort',abort,{once:true});
      proof.audioFetches+=1;
      try{
        const response=await fetch(streamUrl,{method:'GET',credentials:'same-origin',headers:{Accept:'audio/mpeg, audio/*;q=0.9, application/json;q=0.2'},signal:controller.signal,cache:'no-store'});
        const contentType=String(response.headers.get('Content-Type')||'').toLowerCase();
        if(!response.ok||!contentType.startsWith('audio/')){
          let message='ElevenLabs audio could not be generated.';
          try{
            const data=await response.json();
            if(data?.error)message=String(data.error);
          }catch(error){
            if(!response.ok)message=`ElevenLabs voice HTTP ${response.status}`;
          }
          throw new Error(message);
        }
        const blob=await response.blob();
        if(!blob.size)throw new Error('ElevenLabs returned empty audio.');
        if(typeof URL?.createObjectURL!=='function')return {src:streamUrl,revoke:false};
        return {src:URL.createObjectURL(blob),revoke:true};
      }catch(error){
        proof.audioFetchFailures+=1;
        if(error?.name==='AbortError'&&signal?.aborted)throw abortError();
        if(error?.name==='AbortError')throw new Error('ElevenLabs audio request timed out.');
        throw error;
      }finally{
        clearTimeout(timeout);
        signal?.removeEventListener?.('abort',abort);
      }
    }

    async function ticket(text,signal){
      proof.ticketRequests+=1;
      const startedAt=performance.now();
      const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({csrf_token:csrf,action:'ticket',text}),signal});
      const data=await response.json().catch(()=>({}));
      proof.lastTicketMs=Math.max(0,Math.round(performance.now()-startedAt));
      if(!response.ok||!data?.ok||!data?.stream_url)throw new Error(data?.error||`ElevenLabs voice HTTP ${response.status}`);
      proof.modelId=String(data?.model_id||proof.modelId||'');
      proof.outputFormat=String(data?.output_format||proof.outputFormat||'');
      return String(data.stream_url);
    }

    function createStream(callbacks={}){
      if(!endpoint||!csrf)throw new Error('Premium voice endpoint is unavailable.');
      if(active)active.stop();

      const streamStartedAt=performance.now();
      proof.lastStreamCreatedAt=Date.now();
      const controller=new AbortController();
      const queue=[];
      let buffer='';
      let audio=null;
      let stopped=false;
      let ended=false;
      let playing=false;
      let first=true;
      let started=false;
      let resolveStarted,rejectStarted,resolveDone;
      const startedPromise=new Promise((resolve,reject)=>{resolveStarted=resolve;rejectStarted=reject;});
      const donePromise=new Promise(resolve=>{resolveDone=resolve;});
      let nextTicket=null;
      let startedSettled=false;
      proof.lastChunkCount=0;

      const settleStarted=(ok,error=null)=>{
        if(startedSettled)return;
        startedSettled=true;
        if(error)rejectStarted?.(error);else resolveStarted?.(!!ok);
      };

      const clearAudio=target=>{
        if(!target)return;
        target.onplaying=null;target.onended=null;target.onerror=null;
        try{target.pause();target.removeAttribute('src');target.load();}catch(error){}
        const objectUrl=objectUrls.get(target);
        if(objectUrl){try{URL.revokeObjectURL(objectUrl);}catch(error){}objectUrls.delete(target);}
        if(audio===target)audio=null;
      };

      const finish=()=>{
        if(stopped)return;
        stopped=true;nextTicket=null;clearAudio(audio);
        proof.streamEnds+=started?1:0;
        if(started){try{callbacks.onEnd?.();}catch(error){}}
        else settleStarted(false,new Error('Voice stream ended before playback started.'));
        resolveDone?.(true);
        if(active===session)active=null;
      };

      const fail=error=>{
        if(stopped||error?.name==='AbortError')return;
        proof.failures+=1;proof.lastError=String(error?.message||error||'Voice playback failed');
        const hadStarted=started;stopped=true;nextTicket=null;clearAudio(audio);
        try{controller.abort();}catch(abortError){}
        if(hadStarted){try{callbacks.onError?.(error);}catch(callbackError){}}
        else settleStarted(false,error);
        resolveDone?.(false);
        if(active===session)active=null;
      };

      function primeNextTicket(){
        if(stopped||controller.signal.aborted||nextTicket||!queue.length)return;
        const text=queue[0];
        proof.prefetchedTickets+=1;
        nextTicket={
          text,
          promise:ticket(text,controller.signal).then(
            url=>({ok:true,url}),
            error=>({ok:false,error})
          )
        };
      }

      const enqueue=text=>{
        const parts=splitPart(String(text||'').replace(/\s+/g,' ').trim(),first?FIRST_CHUNK_LIMIT:CHUNK_LIMIT);
        for(const part of parts){
          if(!part)continue;
          queue.push(part);first=false;proof.lastChunkCount+=1;
        }
        if(playing)primeNextTicket();
      };

      function drain(force=false){
        let guard=0;
        while(guard++<50){
          const ready=takeReadySentence(buffer,force,first);
          if(!ready)break;
          buffer=ready.rest;enqueue(ready.text);force=false;
        }
        void pump();
      }

      async function playText(text,prefetched=null){
        if(stopped||controller.signal.aborted)throw abortError();
        if(unlockPromise)await unlockPromise;
        let streamUrl='';
        if(prefetched){
          const result=await prefetched;
          if(!result?.ok)throw result?.error||new Error('Voice ticket prefetch failed.');
          streamUrl=String(result.url||'');
        }else streamUrl=await ticket(text,controller.signal);
        if(stopped||controller.signal.aborted)throw abortError();
        const source=await fetchAudio(streamUrl,controller.signal);
        if(stopped||controller.signal.aborted)throw abortError();
        const current=(!started&&primedAudio)?primedAudio:new Audio();
        audio=current;current.preload='auto';current.playsInline=true;current.muted=false;current.volume=outputVolume;
        await new Promise((resolve,reject)=>{
          let heard=false;let settled=false;
          const startTimer=setTimeout(()=>{
            if(settled||heard)return;
            proof.audioStartTimeouts+=1;
            proof.lastPlaybackError='ElevenLabs audio loaded but playback did not start.';
            settled=true;clearAudio(current);reject(new Error(proof.lastPlaybackError));
          },AUDIO_START_TIMEOUT_MS);
          const finish=(ok,error=null)=>{
            if(settled)return;
            settled=true;clearTimeout(startTimer);
            if(error)reject(error);else resolve(ok);
          };
          current.onplaying=()=>{
            if(stopped||heard||settled)return;
            heard=true;proof.chunkStarts+=1;
            if(!started){
              started=true;audioUnlocked=true;proof.audioUnlocked=true;proof.streamStarts+=1;
              const firstAudioMs=Math.max(0,Math.round(performance.now()-streamStartedAt));
              proof.lastFirstAudioMs=firstAudioMs;
              proof.bestFirstAudioMs=proof.bestFirstAudioMs>0?Math.min(proof.bestFirstAudioMs,firstAudioMs):firstAudioMs;
              proof.lastFirstChunkChars=String(text||'').length;
              try{window.dispatchEvent(new CustomEvent('stonefellow:voice-latency',{detail:{build:BUILD,first_audio_ms:firstAudioMs,ticket_ms:proof.lastTicketMs,first_chunk_chars:proof.lastFirstChunkChars,model_id:proof.modelId,output_format:proof.outputFormat}}));}catch(error){}
              try{callbacks.onStart?.();}catch(error){}
              settleStarted(true);
            }
            try{callbacks.onChunkStart?.(text);}catch(error){}
          };
          current.onended=()=>{proof.chunkEnds+=1;try{callbacks.onChunkEnd?.(text);}catch(error){};finish(true);clearAudio(current);};
          current.onerror=()=>{
            const code=Number(current.error?.code||0);
            const message=code?`ElevenLabs streaming playback failed (media ${code}).`:'ElevenLabs streaming playback failed.';
            proof.lastPlaybackError=message;
            finish(false,new Error(message));
            clearAudio(current);
          };
          current.src=source.src;
          if(source.revoke)objectUrls.set(current,source.src);
          current.load();
          Promise.resolve(current.play()).catch(error=>{
            const name=String(error?.name||'');
            const message=name==='NotAllowedError'
              ? 'Browser blocked ElevenLabs audio. Tap LISTEN once to enable voice playback.'
              : String(error?.message||error||'ElevenLabs playback could not start.');
            proof.lastPlaybackError=message;
            finish(false,new Error(message));
          });
        });
      }

      async function pump(){
        if(stopped||playing)return;
        if(!queue.length){if(ended&&!buffer.trim())finish();return;}
        playing=true;
        try{
          while(queue.length&&!stopped){
            const text=queue.shift();
            let prefetched=null;
            if(nextTicket&&nextTicket.text===text){prefetched=nextTicket.promise;nextTicket=null;}
            else if(nextTicket){nextTicket=null;}
            primeNextTicket();
            await playText(text,prefetched);
            primeNextTicket();
          }
          playing=false;
          if(ended&&!queue.length&&!buffer.trim())finish();
        }catch(error){playing=false;fail(error);}
      }

      const session={
        push(delta){
          if(stopped)return;
          const text=String(delta||'');if(!text)return;
          proof.livePushes+=1;buffer+=text;drain(false);
        },
        end(){if(stopped)return;ended=true;drain(true);if(!queue.length&&!playing&&!buffer.trim())finish();},
        stop(){
          if(stopped)return;
          stopped=true;nextTicket=null;try{controller.abort();}catch(error){};clearAudio(audio);
          if(!started){proof.stopsBeforeStart+=1;settleStarted(false);}
          resolveDone?.(false);if(active===session)active=null;
        },
        started:startedPromise,done:donePromise,isStarted:()=>started,queued:()=>queue.length
      };
      active=session;
      return session;
    }

    async function speak(text,callbacks={}){
      const message=String(text||'').trim();if(!message)throw new Error('No voice text.');
      const session=createStream(callbacks);
      for(const chunk of sentenceChunks(message))session.push(chunk+' ');
      session.end();
      return session.started;
    }

    function stop(){active?.stop();active=null;}
    function setVolume(value=1){
      outputVolume=Math.max(0,Math.min(1,Number(value)||0));
      if(primedAudio)primedAudio.volume=outputVolume;
      return outputVolume;
    }
    void warm();
    return {speak,createStream,stop,warm,unlock,setVolume,chunks:sentenceChunks,isActive:()=>Boolean(active),isUnlocked:()=>audioUnlocked,endpoint};
  }

  window.StonefellowPremiumVoiceV122=PremiumVoice;
})();
