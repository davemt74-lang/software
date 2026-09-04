(() => {
  'use strict';

  const BUILD='premium-autoplay-v148-20260828';
  const Original=window.StonefellowPremiumVoiceV122;
  if(typeof Original!=='function')return;

  const proof=window.STONEFELLOW_PREMIUM_AUTOPLAY_V148=window.STONEFELLOW_PREMIUM_AUTOPLAY_V148||{
    build:BUILD,loaded:true,autoAttempts:0,blockedAttempts:0,queuedRetries:0,retryStarts:0,retrySuccesses:0
  };

  const blocked=error=>/Browser blocked ElevenLabs audio|NotAllowedError|play\(\) failed because the user didn't interact|user didn't interact/i.test(String(error?.message||error||''));

  window.StonefellowPremiumVoiceV122=function StonefellowPremiumVoiceAutoplayV148(options={}){
    const voice=Original(options);
    let pending=null;
    let retrying=false;

    const retryPending=async()=>{
      if(retrying||!pending)return false;
      retrying=true;
      const item=pending;
      pending=null;
      proof.retryStarts+=1;
      try{
        const unlocked=typeof voice.unlock==='function'?await voice.unlock():true;
        if(!unlocked){pending=item;proof.queuedRetries+=1;return false;}
        await voice.speak(item.text,item.callbacks);
        proof.retrySuccesses+=1;
        return true;
      }catch(error){
        if(blocked(error)){pending=item;proof.queuedRetries+=1;return false;}
        try{item.callbacks?.onError?.(error);}catch(callbackError){}
        return false;
      }finally{retrying=false;}
    };

    const speak=async(text,callbacks={})=>{
      const message=String(text||'').trim();
      proof.autoAttempts+=1;
      try{
        return await voice.speak(message,callbacks);
      }catch(error){
        if(!blocked(error))throw error;
        proof.blockedAttempts+=1;
        pending={text:message,callbacks};
        proof.queuedRetries+=1;
        // Release the Chat/Studio speaking state without surfacing a false
        // ElevenLabs error. The exact same utterance is retried after the
        // first real user gesture unlocks browser audio.
        try{callbacks?.onEnd?.({blocked:true,queued:true,build:BUILD});}catch(callbackError){}
        return false;
      }
    };

    const unlock=async()=>{
      const unlocked=typeof voice.unlock==='function'?await voice.unlock():false;
      if(unlocked&&pending)queueMicrotask(()=>{void retryPending();});
      return !!unlocked;
    };

    return {
      ...voice,
      speak,
      unlock,
      // Agent Chat should attempt the persisted intro automatically. Browser
      // policy is handled by the queued retry above rather than suppressing
      // the attempt before it happens.
      isUnlocked:()=>true,
      retryPending,
      hasPendingAutoplay:()=>!!pending,
      autoplayBuild:BUILD
    };
  };
})();
