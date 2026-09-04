(() => {
  'use strict';

  const BUILD='artist-listening-transcript';
  const cfg=window.STONEFELLOW_ARTIST_LISTENING_V172||{};
  if(!cfg.endpoint)return;

  const endpoint237=String(cfg.longEndpoint||String(cfg.endpoint).replace(/artist-listening-v172\.php(?:\?.*)?$/i,'artist-listening-long-v237.php'));
  const nativeFetch=window.fetch.bind(window);
  const state={
    sessionId:0,page:1,manifest:null,view:'page',meta:new Map(),requested:new Map(),
    continuousLoaded:0,continuousBusy:false,continuousObserver:null,
    lastError:'',manifestTimer:0,
    uiObserver:null,uiObserverTimer:0,schemaReady:false,
  };
  const proof=window.STONEFELLOW_ARTIST_LISTENING_TRANSCRIPT={
    build:BUILD,loaded:true,pageWords:2500,pagedFetches:0,libraryFetches:0,continuousPages:0,
    liveCompactions:0,lastError:'',
  };

  const clean=value=>String(value||'').replace(/\s+/g,' ').trim();
  const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const words=value=>{const text=clean(value);return text?text.split(' ').length:0;};
  const formatTime=milliseconds=>{const total=Math.max(0,Math.floor(Number(milliseconds||0)/1000));const h=Math.floor(total/3600),m=Math.floor((total%3600)/60),s=total%60;return h?`${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`:`${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;};

  function responseJson(data,status=200){return new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json; charset=UTF-8','Cache-Control':'no-store'}});}
  function parseRequest(input,init={}){
    try{
      const href=typeof input==='string'?input:String(input?.url||'');
      const url=new URL(href,location.href);
      const method=String(init.method||(typeof input!=='string'?input?.method:'')||'GET').toUpperCase();
      return {url,method,init};
    }catch(error){return null;}
  }
  async function api(action,payload={},method='GET'){
    let url=endpoint237;
    const options={method,credentials:'same-origin',headers:{Accept:'application/json'}};
    if(method==='GET'){
      const target=new URL(url,location.href);target.searchParams.set('action',action);
      Object.entries(payload).forEach(([key,value])=>{if(value!==undefined&&value!==null&&value!=='')target.searchParams.set(key,String(value));});
      url=target.toString();
    }else{
      options.headers['Content-Type']='application/json';
      options.body=JSON.stringify({action,csrf_token:String(cfg.csrf||''),...payload});
    }
    const response=await nativeFetch(url,options);
    const data=await response.json().catch(()=>({ok:false,error:'Long transcript API returned an invalid response.'}));
    if(!response.ok||!data.ok)throw new Error(String(data.error||`Long transcript request failed (${response.status}).`));
    return data;
  }

  async function workspaceRequest(action, payload = {}) {
    if (action === 'library') {
      const data = await api('library');
      proof.libraryFetches += 1;
      for (const row of Array.isArray(data.sessions) ? data.sessions : []) state.meta.set(Number(row.id || 0), row);
      state.schemaReady = !!data.schema_ready;
      return data;
    }
    if (action === 'session') {
      const id = Math.max(0, Number(payload.session_id || 0));
      const page = Math.max(1, Number(state.requested.get(id) || 1));
      const data = await api('page', {session_id:id, page});
      proof.pagedFetches += 1;
      state.schemaReady = !!data.schema_ready;
      const merged = {...(state.meta.get(id) || {}), ...(data.session || {})};
      merged.segments = Array.isArray(data.session?.segments) ? data.session.segments : [];
      merged.continuous_text = String(data.session?.continuous_text || '');
      merged.transcript_page = Number(data.page?.page_number || page);
      merged.transcript_pages = Number(data.page?.page_count || 1);
      merged.transcript_paged = true;
      return {ok:true, session:merged, build:BUILD};
    }
    throw new Error(`Unsupported transcript workspace action: ${action}`);
  }
  proof.workspaceRequest = workspaceRequest;

  function clientPages(segments=[]){
    const pages=[];let page=null,total=0;
    const finish=()=>{if(page){pages.push(page);page=null;}};
    for(const row of Array.isArray(segments)?segments:[]){
      const type=String(row.segment_type||row.type||'transcript');
      const text=clean(row.transcript_text||row.text||'');
      const count=type==='transcript'?words(text):0;
      if(count&&page&&page.words>0&&page.words+count>2500)finish();
      if(!page)page={segments:[],words:0};
      page.segments.push(row);page.words+=count;total+=count;
    }
    finish();
    if(!pages.length)pages.push({segments:[],words:0});
    return {pages,total};
  }

  // Capture-phase compaction prevents the workspace and analysis renderers from
  // repeatedly processing thousands of historical live turns.
  window.addEventListener('stonefellow:artist-listening-live',event=>{
    const detail=event?.detail;
    if(!detail||!Array.isArray(detail.segments)||detail.__listeningTranscriptCompacted)return;
    const mapped=clientPages(detail.segments);
    const last=mapped.pages[mapped.pages.length-1];
    detail.__listeningTranscriptCompacted=true;
    detail.totalWordCount=mapped.total;
    detail.totalPages=mapped.pages.length;
    detail.currentPage=mapped.pages.length;
    detail.pageWordCount=last.words;
    detail.segments=last.segments;
    proof.liveCompactions+=1;
    const id=Number(detail.sessionId||detail.session?.id||0);
    if(id>0){state.sessionId=id;state.page=mapped.pages.length;renderNavLive(mapped.total,mapped.pages.length,last.words);}
    if(['synced','stopped'].includes(String(detail.action||'')))scheduleManifest(350,detail.action==='stopped');
  },true);

  function ensureUi(){
    const header=document.querySelector('.sf-listening-workspace-editor-top');
    const sidebar=document.querySelector('.sf-listening-workspace-sidebar');
    const content=document.querySelector('.sf-listening-workspace-editor-content');
    if(!header||!sidebar||!content)return false;

    if(!document.getElementById('sfListeningTranscriptNav')){
      const nav=document.createElement('div');
      nav.id='sfListeningTranscriptNav';nav.className='sf-listening-transcript-nav';
      nav.innerHTML='<button type="button" data-listening-transcript-prev aria-label="Previous transcript page">←</button><label>Page <select data-listening-transcript-page aria-label="Transcript page"></select></label><span data-listening-transcript-of>of 1</span><button type="button" data-listening-transcript-next aria-label="Next transcript page">→</button><button type="button" class="sf-listening-transcript-continuous-toggle" data-listening-transcript-continuous aria-pressed="false">Continuous View</button><span class="sf-listening-transcript-total" data-listening-transcript-total>0 words</span>';
      header.insertAdjacentElement('afterend',nav);
      nav.querySelector('[data-listening-transcript-prev]').addEventListener('click',()=>goPage(state.page-1));
      nav.querySelector('[data-listening-transcript-next]').addEventListener('click',()=>goPage(state.page+1));
      nav.querySelector('[data-listening-transcript-page]').addEventListener('change',event=>goPage(Number(event.target.value||1)));
      nav.querySelector('[data-listening-transcript-continuous]').addEventListener('click',()=>state.view==='continuous'?exitContinuous():enterContinuous());
    }

    if(!document.getElementById('sfListeningTranscriptContinuous')){
      const continuous=document.createElement('section');
      continuous.id='sfListeningTranscriptContinuous';continuous.className='sf-listening-transcript-continuous';continuous.hidden=true;
      continuous.innerHTML='<header><div><small>CONTINUOUS VIEW</small><h2 data-listening-transcript-continuous-title>Transcription</h2></div><span data-listening-transcript-continuous-progress></span></header><div data-listening-transcript-continuous-pages></div><div class="sf-listening-transcript-sentinel" data-listening-transcript-sentinel>Loading next page…</div>';
      content.appendChild(continuous);
    }
    return true;
  }


  function stopBootObserver(){
    state.uiObserver?.disconnect();state.uiObserver=null;
    if(state.uiObserverTimer)clearTimeout(state.uiObserverTimer);state.uiObserverTimer=0;
  }
  function bootUiPass(){
    const workspaceReady=!!document.querySelector('.sf-listening-workspace-editor-top')&&!!document.querySelector('.sf-listening-workspace-sidebar');
    if(workspaceReady&&!document.getElementById('sfListeningTranscriptNav')){
      ensureUi();
      if(state.manifest)renderManifest();
    }
    if(document.getElementById('sfListeningTranscriptNav'))stopBootObserver();
  }

  function renderNavLive(total,pageCount,pageWords){
    if(!ensureUi())return;
    const totalNode=document.querySelector('[data-listening-transcript-total]');
    if(totalNode)totalNode.textContent=`${Number(total||0).toLocaleString()} words · page ${Number(pageWords||0).toLocaleString()}`;
    const of=document.querySelector('[data-listening-transcript-of]');if(of)of.textContent=`of ${pageCount}`;
  }

  function renderManifest(){
    if(!ensureUi()||!state.manifest)return;
    const manifest=state.manifest;
    state.page=Math.max(1,Math.min(Number(manifest.page_count||1),state.page));
    const select=document.querySelector('[data-listening-transcript-page]');
    if(select){
      const nextHtml=(manifest.pages||[]).map(page=>`<option value="${page.page_number}"${Number(page.page_number)===state.page?' selected':''}>${page.page_number}</option>`).join('');
      if(select.innerHTML!==nextHtml)select.innerHTML=nextHtml;
      select.value=String(state.page);
    }
    const of=document.querySelector('[data-listening-transcript-of]');if(of)of.textContent=`of ${manifest.page_count||1}`;
    const current=(manifest.pages||[])[state.page-1];
    const total=document.querySelector('[data-listening-transcript-total]');if(total)total.textContent=`${Number(manifest.total_words||0).toLocaleString()} words · page ${Number(current?.word_count||0).toLocaleString()}`;
    const prev=document.querySelector('[data-listening-transcript-prev]');const next=document.querySelector('[data-listening-transcript-next]');
    if(prev)prev.disabled=state.page<=1||state.view==='continuous';
    if(next)next.disabled=state.page>=Number(manifest.page_count||1)||state.view==='continuous';
    const editor=document.querySelector('[data-listening-workspace-editor]');const save=document.querySelector('[data-listening-workspace-save]');
    if(editor&&state.view==='page')editor.readOnly=true;
    if(save)save.disabled=true;
  }

  async function loadManifest(sessionId,preferredPage=null){
    sessionId=Math.max(0,Number(sessionId||0));if(!sessionId)return;
    try{
      const data=await api('manifest',{session_id:sessionId});
      if(state.sessionId&&state.sessionId!==sessionId)return;
      state.sessionId=sessionId;state.manifest=data.manifest||null;state.schemaReady=!!state.manifest?.schema_ready;
      if(preferredPage!==null)state.page=Math.max(1,Number(preferredPage||1));
      else if(state.manifest)state.page=Math.max(1,Math.min(Number(state.manifest.page_count||1),state.page));
      state.lastError='';renderManifest();
    }catch(error){
      state.lastError=String(error?.message||error);proof.lastError=state.lastError;
    }
  }
  function scheduleManifest(delay=300){
    if(state.manifestTimer)clearTimeout(state.manifestTimer);
    state.manifestTimer=setTimeout(()=>{
      state.manifestTimer=0;
      void loadManifest(state.sessionId,state.page);
    },delay);
  }

  function goPage(page){
    if(!state.manifest||state.view==='continuous')return;
    page=Math.max(1,Math.min(Number(state.manifest.page_count||1),Number(page||1)));
    if(page===state.page)return;
    state.page=page;state.requested.set(state.sessionId,page);renderManifest();
    const workspace=window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.api;
    if(!workspace?.openDocument){state.lastError='Transcription workspace API is unavailable.';proof.lastError=state.lastError;return;}
    void workspace.openDocument(state.sessionId).catch(error=>{state.lastError=String(error?.message||error);proof.lastError=state.lastError;});
  }

  function emitViewChanged(){
    window.dispatchEvent(new CustomEvent('stonefellow:artist-listening-view-changed',{
      detail:{view:state.view,sessionId:state.sessionId,source:'transcript'}
    }));
  }

  function participantName(label){const participants=state.manifest?.participants||{};return String(participants[label]||label||'Speaker 1');}
  function continuousPageHtml(page){
    const turns=(page.segments||[]).filter(row=>String(row.segment_type||'')==='transcript');
    return `<article class="sf-listening-transcript-continuous-page" data-listening-transcript-cont-page="${page.page_number}"><header><b>Page ${page.page_number}</b><span>${Number(page.word_count||0).toLocaleString()} words · ${formatTime(page.start_ms)}–${formatTime(page.end_ms)}</span></header>${turns.map(row=>`<div class="sf-listening-transcript-turn"><div><strong>${esc(participantName(String(row.speaker_label||'Speaker 1')))}</strong><time>${formatTime(row.started_ms)}</time></div><p>${esc(row.transcript_text||'')}</p></div>`).join('')||'<p class="sf-listening-transcript-empty">No transcript text on this page.</p>'}</article>`;
  }
  async function loadContinuousPage(pageNumber){
    if(state.continuousBusy||!state.sessionId||!state.manifest||pageNumber>Number(state.manifest.page_count||1))return;
    state.continuousBusy=true;
    try{
      const data=await api('page',{session_id:state.sessionId,page:pageNumber});
      const target=document.querySelector('[data-listening-transcript-continuous-pages]');if(target)target.insertAdjacentHTML('beforeend',continuousPageHtml(data.page||{}));
      state.continuousLoaded=pageNumber;proof.continuousPages+=1;
      const progress=document.querySelector('[data-listening-transcript-continuous-progress]');if(progress)progress.textContent=`Loaded ${state.continuousLoaded} of ${state.manifest.page_count}`;
      const sentinel=document.querySelector('[data-listening-transcript-sentinel]');if(sentinel)sentinel.hidden=state.continuousLoaded>=Number(state.manifest.page_count||1);
    }catch(error){state.lastError=String(error?.message||error);}finally{state.continuousBusy=false;}
  }
  function enterContinuous(){
    if(!state.manifest||!ensureUi())return;
    state.view='continuous';state.continuousLoaded=0;
    const container=document.getElementById('sfListeningTranscriptContinuous');
    const documentArea=document.querySelector('.sf-listening-workspace-document-area');if(documentArea)documentArea.hidden=true;
    const pages=document.querySelector('[data-listening-transcript-continuous-pages]');if(pages)pages.replaceChildren();if(container)container.hidden=false;
    document.querySelector('[data-listening-workspace-editor]')?.setAttribute('hidden','');
    document.querySelector('[data-listening-workspace-turns]')?.setAttribute('hidden','');
    const toggle=document.querySelector('[data-listening-transcript-continuous]');if(toggle){toggle.textContent='Page View';toggle.setAttribute('aria-pressed','true');}
    const title=document.querySelector('[data-listening-transcript-continuous-title]');if(title)title.textContent=String(state.manifest.title||'Transcription');
    const sentinel=document.querySelector('[data-listening-transcript-sentinel]');if(sentinel){sentinel.hidden=false;sentinel.textContent='Loading next page…';}
    state.continuousObserver?.disconnect();
    if(sentinel&&'IntersectionObserver'in window){
      state.continuousObserver=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting))void loadContinuousPage(state.continuousLoaded+1);},{rootMargin:'700px'});
      state.continuousObserver.observe(sentinel);
    }
    void loadContinuousPage(1);
    renderManifest();
    emitViewChanged();
  }
  function exitContinuous(){
    state.view='page';state.continuousObserver?.disconnect();state.continuousObserver=null;
    const container=document.getElementById('sfListeningTranscriptContinuous');if(container)container.hidden=true;
    const documentArea=document.querySelector('.sf-listening-workspace-document-area');if(documentArea)documentArea.hidden=false;
    document.querySelector('[data-listening-workspace-editor]')?.removeAttribute('hidden');
    document.querySelector('[data-listening-workspace-turns]')?.removeAttribute('hidden');
    const toggle=document.querySelector('[data-listening-transcript-continuous]');if(toggle){toggle.textContent='Continuous View';toggle.setAttribute('aria-pressed','false');}
    state.requested.set(state.sessionId,state.page);
    emitViewChanged();
    const workspace=window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.api;
    if(workspace?.openDocument)void workspace.openDocument(state.sessionId).catch(error=>{state.lastError=String(error?.message||error);proof.lastError=state.lastError;});
    renderManifest();
  }


  function transcriptionTranscriptState(){
    return {
      sessionId:Math.max(0,Number(state.sessionId||0)),
      page:Math.max(1,Number(state.page||1)),
      view:String(state.view||'page'),
      pageCount:Math.max(1,Number(state.manifest?.page_count||1)),
      totalWords:Math.max(0,Number(state.manifest?.total_words||0)),
      continuousLoaded:Math.max(0,Number(state.continuousLoaded||0)),
      schemaReady:!!state.schemaReady,
      lastError:String(state.lastError||''),
    };
  }
  proof.api={
    getState:transcriptionTranscriptState,
    goPage:page=>{goPage(page);return transcriptionTranscriptState();},
    setView:view=>{view=String(view||'page');if(view==='continuous')enterContinuous();else if(view==='page')exitContinuous();else throw new Error('Transcript page view must be page or continuous.');return transcriptionTranscriptState();},
    loadManifest:async(sessionId,page=null)=>{await loadManifest(sessionId,page);if(state.lastError)throw new Error(state.lastError);return transcriptionTranscriptState();},
  };

  window.addEventListener('stonefellow:artist-listening-document-selected',event=>{
    const session=event?.detail?.session;const id=Math.max(0,Number(session?.id||0));
    if(!id){state.sessionId=0;state.manifest=null;return;}
    state.sessionId=id;state.page=Math.max(1,Number(session?.transcript_page||state.requested.get(id)||1));state.requested.set(id,state.page);
    if(session?.transcript_paged){
      const editor=document.querySelector('[data-listening-workspace-editor]');if(editor)editor.readOnly=true;
      const save=document.querySelector('[data-listening-workspace-save]');if(save)save.disabled=true;
    }
    void loadManifest(id,state.page);
  });

  function boot(){
    bootUiPass();
    if(!document.getElementById('sfListeningTranscriptNav')){
      state.uiObserver=new MutationObserver(bootUiPass);
      state.uiObserver.observe(document.body,{subtree:true,childList:true});
      state.uiObserverTimer=setTimeout(stopBootObserver,15000);
    }
    const current=Math.max(0,Number(window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.currentSessionId||cfg.initialSessionId||0));
    if(current){state.sessionId=current;void loadManifest(current,1);}
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
