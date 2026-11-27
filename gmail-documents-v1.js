(() => {
  'use strict';
  if (window.VP3GmailDocumentsV1) return;
  const views=new Set(),id=/^[a-f0-9]{32}$/,digest=/^[a-f0-9]{64}$/;
  const text=(v,n)=>typeof v==='string'&&Array.from(v).length<=n&&!/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/u.test(v);
  const node=(tag,value='',cls='')=>{const el=document.createElement(tag);el.textContent=value;if(cls)el.className=cls;return el;};
  const button=(label,handler)=>{const el=node('button',label);el.type='button';el.addEventListener('click',event=>{if(event.isTrusted)void handler();});return el;};
  function scope(){
    const cfg=window.VP3_CHAT_EMAIL_V1,live=window.STONEFELLOW_CHAT;
    const runtime=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
    const cid=Number(runtime?.conversationId?.()||0),agent=Number(cfg?.agentId);
    if(!cfg||!cfg.csrf||String(live?.userId)!==String(cfg.ownerKey)||Number(live?.agentId||0)!==agent
      ||Number(runtime?.agentId?.()??agent)!==agent||runtime?.isRestoring?.()||runtime?.isReady?.()===false
      ||window.VP3ParticipantContinuity?.selectedContext?.()||!Number.isSafeInteger(cid)||cid<1)return null;
    return {owner_key:String(cfg.ownerKey),agent_id:agent,conversation_id:cid,csrf_token:cfg.csrf,binding:String(live.workingAttentionBinding||'')};
  }
  const key=s=>s?JSON.stringify(s):'';
  function endpoint(){
    const url=new URL(window.VP3_CHAT_EMAIL_V1.endpoint,location.href);
    if(url.origin!==location.origin||url.username||url.password)throw Error('Attachment review is unavailable.');
    if(url.searchParams.get('workspace_email')==='1'){url.searchParams.delete('workspace_email');url.searchParams.set('workspace_documents','1');}
    else if(url.pathname.endsWith('/chat-email-v1.php'))url.pathname=url.pathname.replace(/chat-email-v1\.php$/,'gmail-documents-v1.php');
    else throw Error('Attachment review is unavailable.');
    return url;
  }
  function checkedReview(value){
    if(value===null)return null;
    if(!value||!id.test(value.id)||!['preparing','dispatched','ready','failed'].includes(value.state))throw Error('Attachment review could not be verified.');
    if(value.state==='ready'){
      const r=value.result;
      if(!r||!['readable','partial','unreadable'].includes(r.coverage)||!text(r.summary,3000)||!text(r.reply_body,6000)
        ||!Array.isArray(r.facts)||r.facts.length>10||!Array.isArray(r.questions)||r.questions.length>8
        ||r.facts.some(f=>!text(f.text,500)||!Number.isSafeInteger(f.page)||f.page<1||f.page>1000)
        ||r.questions.some(q=>!text(q,500))||(r.coverage==='unreadable'&&(r.facts.length||r.reply_body)))throw Error('Attachment summary could not be verified.');
    }
    return value;
  }
  function mount(host,source,options={}){
    if(!host||!scope()||!(text(source?.unavailable,300)||(typeof source?.source_ref==='string'&&source.source_ref&&source.source_ref.length<=6000)))return null;
    const captured=scope(),signature=key(captured),url=endpoint(),life=new AbortController(),objects=new Set();
    let disposed=false;const timers=new Set();
    const root=node('section','','vp3-gmail-documents');root.setAttribute('aria-label','Email attachments');
    root.append(node('h3','Attachments'));const message=node('p','Loading attachment details…');message.setAttribute('role','status');root.append(message);host.append(root);
    const current=()=>!disposed&&root.isConnected&&key(scope())===signature&&(options.isCurrent?.()??true);
    const dispose=()=>{if(disposed)return;disposed=true;life.abort();for(const t of timers)clearTimeout(t);for(const u of objects)URL.revokeObjectURL(u);observer.disconnect();root.remove();views.delete(dispose);};
    const observer=new MutationObserver(()=>{if(!root.isConnected||key(scope())!==signature)dispose();});observer.observe(document.documentElement,{childList:true,subtree:true});views.add(dispose);
    async function request(action,fields={}){
      if(!current())throw new DOMException('Private view changed.','AbortError');
      const abort=new AbortController(),stop=()=>abort.abort();life.signal.addEventListener('abort',stop,{once:true});
      const timer=setTimeout(()=>abort.abort(),action==='analyze'?140000:25000);
      try{
        const {binding,...context}=captured;
        const response=await fetch(url.href,{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',signal:abort.signal,
          headers:{'Content-Type':'application/json'},body:JSON.stringify({...fields,...context,source_ref:source.source_ref,action})});
        const result=await response.json();if(!current())throw new DOMException('Private view changed.','AbortError');
        if(!response.ok||result?.ok!==true){
          if([401,403,409,419].includes(response.status)){dispose();window.dispatchEvent(new Event('vp3:phone-agent-access-changed'));}
          throw Error(result?.error||'The attachment review could not be verified.');
        }
        return result;
      }finally{clearTimeout(timer);life.signal.removeEventListener('abort',stop);}
    }
    function fail(status,error){if(current()&&error.name!=='AbortError')status.textContent=error.message;}
    function row(part,installed){
      if(!part||!/^0(?:\.[0-9]{1,3}){0,12}$/.test(part.part)||!text(part.name,180)||!Number.isSafeInteger(part.size)||part.size<0
        ||typeof part.supported!=='boolean')throw Error('Attachment metadata could not be verified.');
      const box=node('details'),title=node('summary',part.name+' · '+Math.max(1,Math.ceil(part.size/1024))+' KB');box.append(title);
      const status=node('p',!part.supported?(part.reason==='over_limit'?'This attachment exceeds the 2 MiB review limit.':'PDF, JPEG, PNG and WebP attachments are supported.')
        :!installed?'Run Database Upgrade to analyze this attachment.':'Open the original or ask the Agent to review it.');status.setAttribute('role','status');box.append(status);root.append(box);
      if(!part.supported)return;
      const label=node('label'),input=node('textarea');input.rows=2;input.maxLength=600;input.placeholder='For example: What does this quote include, and is a delivery date stated?';
      label.append(node('span','Question or reply direction (optional)'),input);box.append(label);
      input.addEventListener('input',()=>{input.style.height='auto';input.style.height=Math.max(82,input.scrollHeight)+'px';});
      const actions=node('div','','vp3-gmail-document-actions'),output=node('div','','vp3-gmail-document-result');
      let review=null,busy=false,loaded=false,poll=null,generation=0;
      const setBusy=value=>{busy=value;analyze.disabled=value||!installed;input.disabled=value;box.setAttribute('aria-busy',String(value));};
      async function original(page=1){
        if(!current())return;
        const tab=window.open('about:blank','_blank');if(tab)tab.opener=null;
        try{
          const r=await request('open',{part:part.part}),f=r.file;
          if(!f||f.name!==part.name||f.mime!==part.mime||!text(f.data,2796210)||!digest.test(f.sha256))throw Error('The original attachment could not be verified.');
          const bytes=Uint8Array.from(atob(f.data),c=>c.charCodeAt(0));if(bytes.length!==part.size)throw Error('The original attachment changed.');
          const actual=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes)),b=>b.toString(16).padStart(2,'0')).join('');
          if(!current())throw new DOMException('Private view changed.','AbortError');if(actual!==f.sha256)throw Error('Attachment integrity check failed.');
          const blob=URL.createObjectURL(new Blob([bytes],{type:f.mime}));objects.add(blob);
          if(tab)tab.location.href=blob+(f.mime==='application/pdf'?'#page='+page:'');
          else {const link=node('a','Open original attachment');link.href=blob;link.target='_blank';link.rel='noopener noreferrer';status.replaceChildren(link);}
        }catch(error){tab?.close();fail(status,error);}
      }
      function render(value){
        review=checkedReview(value);output.replaceChildren();clearTimeout(poll);timers.delete(poll);
        if(!review){status.textContent='Ready to analyze this attachment.';return;}
        if(['preparing','dispatched'].includes(review.state)){
          status.textContent='The Agent is reviewing the original attachment…';setBusy(true);
          poll=setTimeout(()=>{timers.delete(poll);void get();},5000);timers.add(poll);return;
        }
        setBusy(false);analyze.textContent='Analyze again';
        if(review.state==='failed'){status.textContent='This review did not finish. Check access or analyze again when you are ready.';return;}
        const r=review.result;status.textContent=r.coverage==='partial'?'Some document content could not be read confidently.':r.coverage==='unreadable'?'The attachment could not be read.':'Attachment review ready.';
        output.append(node('h4','Summary'),node('p',r.summary));
        if(r.facts.length){const list=node('ul');for(const fact of r.facts){const li=node('li');li.append(node('span',fact.text),button('Page '+fact.page,()=>original(fact.page)));list.append(li);}output.append(node('h4','Details and source pages'),list);}
        output.append(node('small','Page references are supplied by the Agent. Open the original to check the details.'));
        if(r.questions.length){const list=node('ul');for(const q of r.questions)list.append(node('li',q));output.append(node('h4','Missing details and questions'),list);}
        if(r.reply_body){
          const draft=node('details'),heading=node('summary','Suggested reply');draft.append(heading,node('p',r.reply_body));output.append(draft);
          if(options.onUseReply)draft.append(button('Use wording in this draft',async()=>{
            if(!current()||busy)return;await options.onUseReply(r.reply_body);if(current())status.textContent='Review the wording in the email composer before saving or sending.';
          }));
          else if(!url.searchParams.has('workspace_documents'))draft.append(button('Create reply draft',async()=>{
            if(!current()||busy)return;setBusy(true);
            try{const created=await request('reply',{part:part.part,review_id:review.id});if(!id.test(created.draft?.id))throw Error('The reply draft could not be verified.');
              const ok=await window.VP3EmailComposerV1?.openDraft({draft_id:created.draft.id,agent_id:captured.agent_id,conversation_id:captured.conversation_id},{surface:'canvas'});
              if(current())status.textContent=ok?'Reply opened for review.':'Reply saved. Open View drafts in Gmail to review it.';window.dispatchEvent(new Event('vp3:chat-email-drafts-changed'));
            }catch(error){fail(status,error);}finally{if(current())setBusy(false);}
          }));
        }
      }
      async function get(){if(!current())return;const token=generation;try{const r=await request('get',{part:part.part});if(token===generation)render(r.review);}catch(error){if(token===generation){setBusy(false);fail(status,error);}}}
      const open=button('Open original',()=>original());
      const analyze=button('Analyze attachment',async()=>{
        if(!current()||busy)return;generation++;setBusy(true);status.textContent='The Agent is reviewing this attachment…';
        const request_id=Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
        try{render((await request('analyze',{part:part.part,request_id,instruction:input.value.trim()})).review);}
        catch(error){if(current()){fail(status,error);setBusy(false);const check=button('Check saved review',()=>get());output.replaceChildren(check);}}
        finally{if(current()&&!['preparing','dispatched'].includes(review?.state))setBusy(false);}
      });
      analyze.disabled=!installed;actions.append(open,analyze);box.append(actions,output);
      box.addEventListener('toggle',()=>{if(box.open&&!loaded&&installed){loaded=true;void get();}});
    }
    if(source.unavailable)message.textContent=source.unavailable;
    else void request('list').then(r=>{if(!current())return;if(!Array.isArray(r.parts)||r.parts.length>20||typeof r.installed!=='boolean')throw Error('Attachment details could not be verified.');
      message.textContent='Review one attachment at a time. Supported files: PDF and images up to 2 MiB.';for(const p of r.parts)row(p,r.installed);
    }).catch(e=>fail(message,e));
    return {root,dispose};
  }
  for(const event of ['stonefellow:chat-context-changing','vp3:participant-selection-changed','vp3:phone-agent-access-changed'])window.addEventListener(event,()=>{for(const dispose of [...views])dispose();});
  window.VP3GmailDocumentsV1=Object.freeze({mount,checkedReview});
})();
