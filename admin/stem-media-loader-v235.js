(function(root,factory){
  const api=factory(root);
  if(typeof module==='object'&&module.exports)module.exports=api;
  root.StonefellowStemMediaLoaderV235=api;
  if(root.document)api.install(root);
})(typeof globalThis!=='undefined'?globalThis:window,function(root){
  'use strict';
  const BUILD='stem-media-loader-v235';
  const transient=error=>!error?.status||[408,425,429].includes(error.status)||error.status>=500;
  function failure(message,status=0,reason='network_error'){
    const error=new Error(message);error.status=Number(status)||0;error.reason=String(reason||'network_error');return error;
  }

  /** Share compressed bytes, never an unbounded project of decoded PCM. */
  function createPool(options={}){
    const fetcher=options.fetcher||root.fetch?.bind(root);
    const maxBytes=Math.max(1024,Math.min(128*1024*1024,Number(options.maxBytes)||128*1024*1024));
    const maxFileBytes=Math.min(maxBytes,Number(options.maxFileBytes)||64*1024*1024);
    const concurrency=Math.max(1,Math.min(3,Number(options.concurrency)||2));
    const allowed=options.allowedUrls?new Set(options.allowedUrls):null;
    const records=new Map(),waiters=[],controllers=new Set();
    let active=0,retained=0,reserved=0,disposed=false,fetches=0,maxActive=0,hits=0;
    const acquire=()=>new Promise((resolve,reject)=>{
      if(disposed){reject(failure('Project closed.'));return;}
      if(active<concurrency){active++;maxActive=Math.max(maxActive,active);resolve();}
      else waiters.push({resolve,reject});
    });
    const release=()=>{const next=waiters.shift();if(next&&!disposed)next.resolve();else active=Math.max(0,active-1);};
    const discard=key=>{
      const record=records.get(key);records.delete(key);
      if(record?.value?.blob){retained=Math.max(0,retained-record.value.blob.size);root.URL?.revokeObjectURL?.(record.value.url);}
    };
    async function transfer(key){
      if(!fetcher)throw failure('Audio service is unavailable.');
      const controller=new AbortController();controllers.add(controller);
      const timer=setTimeout(()=>controller.abort(),60000);
      let reservation=0;
      try{
        fetches++;
        const response=await fetcher(key,{credentials:'same-origin',cache:'default',signal:controller.signal});
        if(!response?.ok)throw failure(`Audio request failed (${response?.status||0}).`,response?.status,response?.headers?.get?.('X-Stonefellow-Media-Status'));
        const mime=String(response.headers?.get?.('Content-Type')||'').split(';')[0].trim().toLowerCase();
        if(!mime.startsWith('audio/')&&mime!=='application/octet-stream')throw failure('The audio service returned a page instead of audio.',response.status,'invalid_response');
        const size=Number(response.headers?.get?.('Content-Length')||0);
        // Large sources stay on the authenticated streaming endpoint instead
        // of exhausting memory. Normal MP3 projects use one shared Blob/source.
        if(size<=0||size>maxFileBytes||retained+reserved+size>maxBytes){
          await response.body?.cancel?.();
          return {url:key,blob:null,size,mode:'stream'};
        }
        reservation=size;reserved+=reservation;
        const blob=await response.blob();
        if(disposed)throw failure('Project closed.');
        if(blob.size!==size)throw failure('The audio transfer was incomplete.',0,'incomplete_transfer');
        const url=root.URL?.createObjectURL?.(blob);
        if(!url)return {url:key,blob:null,size,mode:'stream'};
        retained+=blob.size;
        return {url,blob,size,mode:'local'};
      }finally{
        reserved-=reservation;clearTimeout(timer);controllers.delete(controller);
      }
    }
    async function load(url){
      const key=String(url||'');
      if(disposed)throw failure('Project closed.');
      if(!key||(allowed&&!allowed.has(key)))throw failure('Audio does not belong to this project.',403,'forbidden');
      if(records.has(key)){hits++;return records.get(key).promise;}
      const record={value:null,promise:null};
      record.promise=(async()=>{
        await acquire();
        try{
          for(let attempt=0;attempt<2;attempt++){
            if(disposed)throw failure('Project closed.');
            try{
              const value=await transfer(key);
              if(disposed||records.get(key)!==record){
                if(value.blob){retained=Math.max(0,retained-value.blob.size);root.URL?.revokeObjectURL?.(value.url);}
                throw failure('Audio load was replaced.');
              }
              record.value=value;return value;
            }
            catch(error){if(disposed||records.get(key)!==record||attempt===1||!transient(error))throw error;await new Promise(resolve=>setTimeout(resolve,250));}
          }
        }finally{release();}
      })();
      records.set(key,record);
      try{return await record.promise;}catch(error){if(records.get(key)===record)discard(key);throw error;}
    }
    async function response(url,init={}){
      const source=await load(url);
      return source.blob?new root.Response(source.blob,{status:200,headers:{'Content-Type':source.blob.type||'audio/mpeg','Content-Length':String(source.blob.size)}}):fetcher(url,init);
    }
    function dispose(){
      disposed=true;controllers.forEach(controller=>controller.abort());
      waiters.splice(0).forEach(waiter=>waiter.reject(failure('Project closed.')));
      [...records.keys()].forEach(discard);
    }
    return Object.freeze({load,response,discard,dispose,getBlob:async url=>(await load(url)).blob,
      stats:()=>({active,queued:waiters.length,retainedBytes:retained,reservedBytes:reserved,maxBytes,fetches,cacheHits:hits,maxActive})});
  }

  function install(host){
    const cfg=host.STONEFELLOW_STEM_STUDIO,doc=host.document;
    if(!cfg||!Array.isArray(cfg.stems)||!doc?.querySelector||host.StonefellowStemMediaV235)return false;
    const expected=cfg.stems.filter(row=>Number(row?.id)>0&&!row.isEmptyRecordingTrack);
    const rows=new Map(expected.map(row=>[Number(row.id),row]));
    const sources=new Map(expected.map(row=>[Number(row.id),String(row.url||'')]));
    const pool=createPool({fetcher:host.fetch.bind(host),allowedUrls:[...sources.values()]});
    const generations=new Map(),observations=new Map(),readyTimers=new Map();
    const emit=(name,detail)=>host.dispatchEvent(new host.CustomEvent(name,{detail:{build:BUILD,...detail}}));
    async function prepare(id,refresh=false){
      id=Number(id);const row=rows.get(id),source=sources.get(id);
      const audio=doc.querySelector(`audio.stem-audio[data-stem-audio="${id}"]`);
      if(!row||!source||!audio)return false;
      clearTimeout(readyTimers.get(id));readyTimers.delete(id);
      const generation=(generations.get(id)||0)+1;generations.set(id,generation);
      audio.dataset.stemMediaGenerationV235=String(generation);
      audio.dataset.stemMediaSource=source;
      audio.dataset.stemMediaRetryingV235='1';
      observations.set(id,{id,name:String(row.name||`Track ${id}`),state:'loading'});
      try{
        if(refresh)pool.discard(source);
        const result=await pool.load(source);
        if(generations.get(id)!==generation)return false;
        const ready=()=>{
          if(generations.get(id)!==generation||audio.error||audio.readyState<3)return;
          clearTimeout(readyTimers.get(id));readyTimers.delete(id);
          delete audio.dataset.stemMediaRetryingV235;
          observations.set(id,{id,name:String(row.name||`Track ${id}`),state:'ready',mode:result.mode});
          const stem=host.STONEFELLOW_STUDIO_RUNTIME_V87?.getStem?.(id);
          if(stem?.crossfadeAudio){stem.crossfadeAudio.src=result.url;stem.crossfadeAudio.preload='none';}
          emit('stonefellow:stem-media-online',{stemId:id});
        };
        audio.addEventListener('canplay',ready,{once:true});
        readyTimers.set(id,setTimeout(()=>{
          if(generations.get(id)!==generation||audio.readyState>=3)return;
          delete audio.dataset.stemMediaRetryingV235;
          audio.dataset.stemMediaOfflineV229='1';audio.dataset.stemMediaReasonV235='network_error';
          emit('stonefellow:stem-media-offline',{stemId:id,reason:'network_error'});
        },30000));
        audio.src=result.url;audio.dataset.stemMediaLoadedGenerationV235=String(generation);audio.preload='auto';audio.load();ready();
        return true;
      }catch(error){
        if(generations.get(id)!==generation)return false;
        delete audio.dataset.stemMediaRetryingV235;
        audio.dataset.stemMediaOfflineV229='1';
        audio.dataset.stemMediaReasonV235=error.reason||'network_error';
        audio.dataset.stemMediaHttpV235=String(error.status||0);
        observations.set(id,{id,name:String(row.name||`Track ${id}`),state:'failed',reason:error.reason||'network_error',status:error.status||0});
        emit('stonefellow:stem-media-offline',{stemId:id,reason:error.reason||'network_error',status:error.status||0});
        return false;
      }
    }
    host.StonefellowStemMediaV235=Object.freeze({build:BUILD,prepare,retry:id=>prepare(id,true),
      getBlob:url=>[...sources.values()].includes(String(url))?pool.getBlob(url):Promise.resolve(null),
      fetchAudio:(url,init)=>[...sources.values()].includes(String(url))?pool.response(url,init):host.fetch(url,init),
      snapshot:()=>({trackId:Number(cfg.trackId),...pool.stats(),stems:[...observations.values()]})});
    host.addEventListener('stonefellow:stem-media-offline',event=>{
      const id=Number(event.detail?.stemId),row=rows.get(id);if(!row)return;
      observations.set(id,{id,name:String(row.name||`Track ${id}`),state:'failed',reason:event.detail.reason||'network_error'});
    });
    expected.forEach(row=>{void prepare(row.id);});
    host.addEventListener('pagehide',()=>{
      rows.forEach((_,id)=>generations.set(id,(generations.get(id)||0)+1));pool.dispose();
      readyTimers.forEach(clearTimeout);readyTimers.clear();
    },{once:true});
    // A restored history entry contains disposed audio/Agent runtimes and
    // revoked Blob URLs. Reload it from the preserved local mix, never play it.
    host.addEventListener('pageshow',event=>{if(event.persisted)host.location.reload();});
    return true;
  }
  return Object.freeze({createPool,install});
});
