(() => {
  'use strict';
  const root=window,doc=document,BUILD='stem-media-health-v235';
  if(root.StonefellowStemMediaHealthV235)return;
  const pending=new Map(),attempts=new Map(),details=new Map(),originalTitles=new WeakMap();
  let disposed=false;
  const media=id=>doc.querySelector(`audio.stem-audio[data-stem-audio="${Number(id)}"]`);
  const nodes=id=>[
    doc.querySelector(`.daw-track-row[data-stem-id="${Number(id)}"]`),
    doc.querySelector(`.daw-arrange-row[data-arrange-stem="${Number(id)}"]`),
    doc.querySelector(`.daw-stem-channel[data-mixer-stem="${Number(id)}"]`)
  ].filter(Boolean);
  const label=reason=>({missing:'MISSING MEDIA',not_found:'MISSING MEDIA',empty:'INVALID MEDIA',
    invalid_signature:'INVALID MEDIA',signature_mismatch:'INVALID MEDIA',unsupported_extension:'INVALID MEDIA',
    forbidden:'ACCESS DENIED',decode_error:'DECODER ERROR',seek_failed:'SEEK FAILED',
    network_error:'NETWORK ERROR',incomplete_transfer:'TRANSFER INCOMPLETE'}[reason]||'AUDIO UNAVAILABLE');
  function clear(id){
    const audio=media(id);if(audio){delete audio.dataset.stemMediaOfflineV229;delete audio.dataset.stemMediaRetryingV235;delete audio.dataset.stemMediaReasonV235;}
    nodes(id).forEach(node=>{
      delete node.dataset.stemMediaStatus;delete node.dataset.stemMediaLabel;
      if(originalTitles.has(node)){node.title=originalTitles.get(node);originalTitles.delete(node);}
      node.querySelectorAll('[data-stem-media-offline-v229]').forEach(badge=>badge.remove());
    });
    details.delete(Number(id));
  }
  function mark(id,reason='network_error',status=0,emit=true){
    id=Number(id);if(id<1||disposed)return;
    const audio=media(id);if(audio){audio.dataset.stemMediaOfflineV229='1';audio.dataset.stemMediaReasonV235=reason;delete audio.dataset.stemMediaRetryingV235;audio.pause();}
    const text=label(reason),title=`${text}${status?` · HTTP ${status}`:''} · ${reason}`;
    details.set(id,{id,reason,status:Number(status)||0});
    nodes(id).forEach(node=>{
      if(!originalTitles.has(node))originalTitles.set(node,node.title||'');
      node.dataset.stemMediaStatus='offline';node.dataset.stemMediaLabel=text;node.title=title;
    });
    const sidebar=nodes(id)[0];
    if(sidebar&&!sidebar.querySelector('[data-stem-media-offline-v229]')){
      const badge=doc.createElement('span');badge.dataset.stemMediaOfflineV229='1';badge.textContent=text+' ';
      const retryButton=doc.createElement('button');retryButton.type='button';retryButton.textContent='Retry';
      retryButton.setAttribute('aria-label',`Retry loading track ${id}`);
      retryButton.addEventListener('click',event=>{event.stopPropagation();void retry(id,true);});
      // The track selector is itself a button. Keep Retry as a sibling control.
      badge.append(retryButton);sidebar.append(badge);
    }
    if(emit)root.dispatchEvent(new CustomEvent('stonefellow:stem-media-offline',{detail:{build:BUILD,stemId:id,reason,status}}));
  }
  async function reload(id){
    const audio=media(id);if(!audio||disposed)return false;
    audio.dataset.stemMediaRetryingV235='1';
    if(root.StonefellowStemMediaV235)return root.StonefellowStemMediaV235.retry(id);
    // Older embeds retain their original authenticated media URL.
    audio.preload='auto';audio.load();return true;
  }
  async function diagnose(audio){
    const id=Number(audio?.dataset?.stemAudio);if(!id||disposed||pending.has(id))return;
    const work=(async()=>{
      let reason=[3,4].includes(audio.error?.code)?'decode_error':'network_error',status=0;
      const source=audio.dataset.stemMediaSource||audio.currentSrc||audio.src;
      try{
        const url=new URL(source,location.href);
        if(url.origin===location.origin&&url.pathname.endsWith('/stem-media-v34.php')){
          const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),8000);
          try{
            const response=await root.fetch(url.href,{method:'HEAD',credentials:'same-origin',cache:'no-store',signal:controller.signal});
            status=response.status;
            const nativeReason=String(response.headers.get('X-Stonefellow-Media-Status')||'');
            if(!response.ok)reason=nativeReason||'network_error';
            else if(String(response.headers.get('Content-Type')||'').includes('text/html'))reason='invalid_response';
          }finally{clearTimeout(timer);}
        }
      }catch(_){}
      if(disposed)return;
      const terminal=[401,403,404,415].includes(status)||['missing','not_found','empty','invalid_signature','signature_mismatch','unsupported_extension','forbidden','invalid_response'].includes(reason);
      if(!terminal&&(attempts.get(id)||0)<1){
        attempts.set(id,(attempts.get(id)||0)+1);
        await reload(id);
      }else mark(id,reason,status);
    })();
    pending.set(id,work);
    try{await work;}catch(_){mark(id,'network_error');}finally{if(pending.get(id)===work)pending.delete(id);}
  }
  async function retry(id,manual=false){
    id=Number(id);if(disposed||pending.has(id))return false;
    if(manual)attempts.delete(id);
    clear(id);
    const work=reload(id);pending.set(id,work);
    try{return await work;}catch(_){mark(id,'network_error');return false;}finally{if(pending.get(id)===work)pending.delete(id);}
  }
  function bind(audio){
    if(audio.dataset.stemMediaHealthV229==='1')return;
    audio.dataset.stemMediaHealthV229='1';
    audio.addEventListener('error',()=>{void diagnose(audio);});
    audio.addEventListener('canplay',()=>{
      if(audio.error||audio.readyState<3)return;
      clear(audio.dataset.stemAudio);
      root.dispatchEvent(new CustomEvent('stonefellow:stem-media-online',{detail:{build:BUILD,stemId:Number(audio.dataset.stemAudio)}}));
    });
    if(audio.dataset.stemMediaOfflineV229==='1')mark(audio.dataset.stemAudio,audio.dataset.stemMediaReasonV235||'network_error',Number(audio.dataset.stemMediaHttpV235)||0);
    else if(audio.error)void diagnose(audio);
  }
  root.addEventListener('stonefellow:stem-media-offline',event=>{
    if(event.detail?.build===BUILD)return;
    const {stemId,reason,status}=event.detail||{};
    if(reason==='seek_failed'&&(attempts.get(Number(stemId))||0)<1){
      attempts.set(Number(stemId),1);void retry(stemId,false);
    }else mark(stemId,reason,status,false);
  });
  root.addEventListener('stonefellow:stem-media-online',event=>clear(event.detail?.stemId));
  root.addEventListener('pagehide',()=>{disposed=true;},{once:true});
  root.StonefellowStemMediaHealthV235=Object.freeze({build:BUILD,retry:id=>retry(id,true),snapshot:()=>[...details.values()]});
  const boot=()=>doc.querySelectorAll('audio.stem-audio[data-stem-audio]').forEach(bind);
  if(doc.readyState==='loading')doc.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
