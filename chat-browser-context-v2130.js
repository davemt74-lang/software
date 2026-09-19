(() => {
  'use strict';

  const HASH_KEY = 'vp3-browser-context=';
  const MAX_FRAGMENT = 24000;
  let current = null;
  let strip = null;

  function decodeBase64Url(value) {
    const raw=String(value||'').trim();
    if(!raw||raw.length>MAX_FRAGMENT)return null;
    try{
      let base64=raw.replace(/-/g,'+').replace(/_/g,'/');
      while(base64.length%4)base64+='=';
      const binary=atob(base64);
      const bytes=Uint8Array.from(binary,ch=>ch.charCodeAt(0));
      return JSON.parse(new TextDecoder().decode(bytes));
    }catch(_error){return null;}
  }

  function http(value) {
    try{
      const u=new URL(String(value||''));
      return ['http:','https:'].includes(u.protocol)?u.href:'';
    }catch(_error){return '';}
  }

  function cleanText(value,max) {
    return String(value==null?'':value).replace(/\0/g,'').trim().slice(0,max);
  }

  function normalize(payload) {
    if(!payload||payload.contract!=='browser-context-v2130'||payload.ephemeral!==true||!payload.page)return null;
    const page=payload.page||{},url=http(page.url);
    if(!url)return null;
    return {
      contract:'browser-context-v2130',
      ephemeral:true,
      page:{
        url,
        canonical_url:http(page.canonical_url)||url,
        title:cleanText(page.title,512),
        domain:cleanText(page.domain,253),
        selected_text:cleanText(page.selected_text,12000),
        metadata:{
          description:cleanText(page.metadata?.description,1000),
          author:cleanText(page.metadata?.author,240),
          site_name:cleanText(page.metadata?.site_name,240),
          language:cleanText(page.metadata?.language,32)
        },
        media:page.media&&typeof page.media==='object'?page.media:null
      },
      relationships:Array.isArray(payload.relationships)?payload.relationships.slice(0,15).map(row=>({
        type:cleanText(row?.type,40),
        title:cleanText(row?.title,190),
        detail:cleanText(row?.detail,420)
      })):[],
      prompt:cleanText(payload.prompt,1200)
    };
  }

  function removeFragment() {
    try{history.replaceState(history.state,'',location.pathname+location.search);}catch(_error){}
  }

  function render() {
    strip?.remove();strip=null;
    if(!current)return;
    const composer=document.getElementById('chatComposerShell')||document.getElementById('chatForm')?.parentElement;
    if(!composer)return;

    strip=document.createElement('section');
    strip.className='chat-browser-context-v2130';
    strip.setAttribute('aria-label','Browser context');

    const copy=document.createElement('div');
    copy.className='chat-browser-context-copy';
    const kicker=document.createElement('small');kicker.textContent='Browser context · temporary';
    const title=document.createElement('strong');title.textContent=current.page.title||current.page.domain||'Current page';
    const detail=document.createElement('span');detail.textContent=current.page.domain+(current.page.selected_text?' · highlighted text attached':'');
    copy.append(kicker,title,detail);

    const remove=document.createElement('button');
    remove.type='button';remove.textContent='Remove';remove.className='chat-browser-context-remove';
    remove.addEventListener('click',clear);

    strip.append(copy,remove);
    const form=document.getElementById('chatForm');
    if(form&&form.parentElement===composer)composer.insertBefore(strip,form);
    else composer.prepend(strip);

    const input=document.getElementById('chatInput');
    if(input&&current.prompt&&!String(input.value||'').trim()){
      input.value=current.prompt;
      input.dispatchEvent(new Event('input',{bubbles:true}));
      input.focus();
    }
  }

  function clear() {
    current=null;
    strip?.remove();strip=null;
    window.dispatchEvent(new CustomEvent('vp3:browser-context-cleared'));
  }

  function agentContext() {
    if(!current)return null;
    return {
      browser_context:{
        contract:current.contract,
        ephemeral:true,
        page:current.page,
        relationships:current.relationships,
        prompt:current.prompt
      }
    };
  }

  const hash=String(location.hash||'').replace(/^#/,'');
  if(hash.startsWith(HASH_KEY)){
    const payload=normalize(decodeBase64Url(hash.slice(HASH_KEY.length)));
    removeFragment();
    if(payload){current=payload;window.setTimeout(render,0);}
  }

  window.VP3_BROWSER_CONTEXT_V2130_RUNTIME={
    current:()=>current,
    agentContext,
    consume:clear,
    clear
  };

  window.addEventListener('pageshow',()=>{if(current)render();});
  window.dispatchEvent(new CustomEvent('vp3:browser-context-ready',{detail:{active:Boolean(current)}}));
})();