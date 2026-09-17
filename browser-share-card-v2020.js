(()=>{
  'use strict';
  const BUILD='browser-share-card-v2020-20260917';
  const styleId='vp3-browser-share-card-v2020-style';
  if(!document.getElementById(styleId)){
    const style=document.createElement('style');style.id=styleId;style.textContent=`
.vp3-browser-share-card-v2020{display:grid;gap:10px;margin-top:8px;padding:12px;border:1px solid rgba(127,127,127,.24);border-radius:14px;background:rgba(127,127,127,.06);max-width:680px}.vp3-browser-share-card-v2020 header{display:flex;gap:10px;align-items:flex-start;justify-content:space-between}.vp3-browser-share-card-v2020 .vp3-bs-source{min-width:0;display:grid;gap:2px}.vp3-browser-share-card-v2020 .vp3-bs-source strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.vp3-browser-share-card-v2020 .vp3-bs-source small{opacity:.68;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.vp3-browser-share-card-v2020 blockquote{margin:0;padding:10px 12px;border-left:3px solid currentColor;background:rgba(127,127,127,.08);white-space:pre-wrap;overflow-wrap:anywhere}.vp3-browser-share-card-v2020 .vp3-bs-note{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}.vp3-browser-share-card-v2020 .vp3-bs-actions{display:flex;flex-wrap:wrap;gap:7px}.vp3-browser-share-card-v2020 .vp3-bs-actions button,.vp3-browser-share-card-v2020 .vp3-bs-actions a{appearance:none;border:1px solid rgba(127,127,127,.3);border-radius:999px;background:transparent;color:inherit;padding:6px 10px;font:inherit;font-size:12px;text-decoration:none;cursor:pointer}.vp3-browser-share-card-v2020 .vp3-bs-actions button:disabled{opacity:.45;cursor:not-allowed}.vp3-browser-share-card-v2020 .vp3-bs-status{font-size:12px;opacity:.72}.vp3-browser-share-card-v2020[data-busy="1"]{opacity:.72}`;document.head.appendChild(style);
  }
  const text=v=>String(v??'');
  const safeHttp=v=>{try{const raw=text(v).trim();if(!/^https?:\/\//i.test(raw))return '';const u=new URL(raw);return /^https?:$/.test(u.protocol)?u.toString():'';}catch{return '';}};
  async function post(api,csrf,action,id,extra={}){const body=new URLSearchParams({action,browser_share_id:id,csrf_token:csrf,...extra});const r=await fetch(api,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));if(!r.ok||!j.ok)throw new Error(j.message||j.error||'Request failed');return j;}
  function button(label,action){const b=document.createElement('button');b.type='button';b.textContent=label;b.dataset.browserShareAction=action;return b;}
  function create(share,options={}){
    if(!share||!share.id)return null;
    const root=document.createElement('article');root.className='vp3-browser-share-card-v2020';root.dataset.browserShareId=text(share.id);root.dataset.build=BUILD;
    const head=document.createElement('header'),source=document.createElement('div');source.className='vp3-bs-source';
    const title=document.createElement('strong');title.textContent=text(share.source?.title||share.source?.domain||'Browser Share');
    const domain=document.createElement('small');domain.textContent=text(share.source?.domain||'Shared from the web');source.append(title,domain);head.append(source);root.append(head);
    if(text(share.selection).trim()){const quote=document.createElement('blockquote');quote.textContent=text(share.selection);root.append(quote);}
    if(text(share.note).trim()){const note=document.createElement('p');note.className='vp3-bs-note';note.textContent=text(share.note);root.append(note);}
    const actions=document.createElement('div');actions.className='vp3-bs-actions';
    if(typeof options.onReply==='function')actions.append(button('Reply','reply'));
    actions.append(button('Ask VP3','ask'));
    const url=safeHttp(share.source?.url);if(url){const a=document.createElement('a');a.href=url;a.target='_blank';a.rel='noopener noreferrer';a.textContent='Open source';actions.append(a);}
    actions.append(button('Save to Knowledge','knowledge'),button('Create Task','task'));root.append(actions);
    const status=document.createElement('div');status.className='vp3-bs-status';status.hidden=true;root.append(status);
    const setStatus=(message,error=false)=>{status.hidden=!message;status.textContent=message||'';status.dataset.error=error?'1':'0';};
    actions.addEventListener('click',async e=>{const target=e.target.closest('button[data-browser-share-action]');if(!target)return;const action=target.dataset.browserShareAction;
      if(action==='reply'){options.onReply?.(share);return;}
      const api=options.api||'/api/browser-share-chat-feed-v2020.php',csrf=options.csrf||'';
      if(action==='ask'){
        if(typeof options.onAsk==='function'){options.onAsk(share);return;}
        if(!csrf){setStatus('Ask VP3 is unavailable on this page.',true);return;}
        root.dataset.busy='1';target.disabled=true;setStatus('Opening VP3 with this share…');
        try{const result=await post(api,csrf,'ask_agent',text(share.id));location.href=result.chat_url||options.chatUrl||'/chat.php';}catch(err){setStatus(err.message||'Could not open VP3.',true);delete root.dataset.busy;target.disabled=false;}return;
      }
      if(!csrf){setStatus('Action unavailable on this page.',true);return;}
      root.dataset.busy='1';target.disabled=true;setStatus(action==='knowledge'?'Saving…':'Creating task…');
      try{const result=await post(api,csrf,action==='knowledge'?'save_knowledge':'create_task',text(share.id));if(action==='knowledge'){setStatus('Saved to My Knowledge.');if(result.view_url){status.textContent='';const a=document.createElement('a');a.href=result.view_url;a.textContent='Saved to My Knowledge';status.appendChild(a);status.hidden=false;}}else setStatus(`Task created${result.workflow?.id?' #'+result.workflow.id:''}.`);}catch(err){setStatus(err.message||'Action failed.',true);}finally{delete root.dataset.busy;target.disabled=false;}
    });
    return root;
  }
  window.VP3BrowserShareCardV2020={build:BUILD,create};
})();