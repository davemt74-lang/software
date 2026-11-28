(() => {
  'use strict';
  if (window.VP3GmailEmailCaptureV1) return;
  const views = new Set();
  const node = (tag, text = '', cls = '') => {const el=document.createElement(tag);el.textContent=text;if(cls)el.className=cls;return el;};
  const bounded = (value, max) => Array.from(String(value ?? '')).slice(0,max).join('');
  const uuid = () => crypto.randomUUID().replaceAll('-','');
  function scope() {
    const cfg=window.VP3_CHAT_EMAIL_V1,live=window.STONEFELLOW_CHAT;
    const chat=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
    const conversation=Number(chat?.conversationId?.()),agent=Number(cfg?.agentId);
    if (!cfg?.captureEndpoint||!cfg.csrf||String(live?.userId)!==String(cfg.ownerKey)||Number(live?.agentId||0)!==agent
      ||Number(chat?.agentId?.()??agent)!==agent||chat?.isRestoring?.()||chat?.isReady?.()===false
      ||window.VP3ParticipantContinuity?.selectedContext?.()||!Number.isSafeInteger(conversation)||conversation<1) return null;
    return {owner_key:String(cfg.ownerKey),agent_id:agent,conversation_id:conversation,csrf_token:cfg.csrf};
  }
  const signature = value => JSON.stringify(value);
  const dateLabel = value => {
    if(typeof value!=='string'||!/^\d{4}-\d\d-\d\dT\d\d:\d\d$/.test(value))return '';
    const [y,m,d,h,min]=value.split(/[-T:]/).map(Number);
    return new Date(Date.UTC(y,m-1,d,h,min)).toLocaleString(undefined,{timeZone:'UTC',dateStyle:'medium',timeStyle:'short'});
  };
  const intersects = (a,b) => a.right>b.left&&a.left<b.right&&a.bottom>b.top&&a.top<b.bottom;
  function selectedText(body,rect) {
    const doc=body.ownerDocument,walker=doc.createTreeWalker(body,NodeFilter.SHOW_TEXT),parts=[];
    let scanned=0,characters=0,total=0,current;
    while ((current=walker.nextNode())&&scanned++<12000&&total<6000) {
      if (!current.textContent.trim()||current.parentElement?.closest('script,style,noscript,template,[hidden]')) continue;
      const parent=current.parentElement,style=doc.defaultView.getComputedStyle(parent);
      if (style.visibility==='hidden'||style.display==='none'||style.opacity==='0') continue;
      const whole=doc.createRange();whole.selectNodeContents(current);
      if (![...whole.getClientRects()].some(box=>intersects(box,rect))) continue;
      // Intersect characters, rather than copying an entire paragraph touching an edge.
      let text='';const value=current.textContent;
      for (let i=0;i<value.length&&total+text.length<6000&&characters++<50000;i++) {
        const range=doc.createRange();range.setStart(current,i);range.setEnd(current,i+1);
        if ([...range.getClientRects()].some(box=>intersects(box,rect))) text+=value[i];
      }
      if (text.trim()) {parts.push(text.trim());total+=text.length;}
    }
    return bounded(parts.join('\n'),6000);
  }
  function mount({root,toolbar,content,body,frame,message,source,isCurrent=()=>true}) {
    const captured=scope();
    if (!captured||!root||!body||typeof source!=='string'||source.length>6000||!/^[a-f0-9]+$/.test(source)) return null;
    let endpoint;try {endpoint=new URL(window.VP3_CHAT_EMAIL_V1.captureEndpoint,location.href);if(endpoint.origin!==location.origin||endpoint.username||endpoint.password) return null;}catch{return null;}
    const life=new AbortController(),key=signature(captured),doc=body.ownerDocument,sourceDialog=root.closest('dialog');
    let disposed=false,selecting=false,drag=null,region=null,selection='',photo='',generation=0,busy=false,stream=null,analysis=null,analysisRequest=null,poll=0,currentActions=[],historyCursor=null;
    const current=()=>!disposed&&root.isConnected&&root.getClientRects().length>0&&(!sourceDialog||sourceDialog.open)&&signature(scope())===key&&isCurrent();
    const button=(label,fn)=>{const el=node('button',label);el.type='button';el.addEventListener('click',event=>{if(event.isTrusted&&current())void fn(event);});return el;};
    const status=node('span','','vp3-email-capture-status');status.setAttribute('role','status');
    const choose=button('Capture area',()=>setSelecting(!selecting));choose.setAttribute('aria-pressed','false');
    const actions=button('Email actions',()=>{if (!region&&!selection) useWholeText();openPreview();});
    const recent=button('Recent analyses',()=>{openPreview();void loadHistory();});
    toolbar.append(choose,actions,recent,status);
    content.classList.add('vp3-email-capture-host');
    const overlay=node('div','','vp3-email-capture-overlay'),box=node('div','','vp3-email-capture-box');
    overlay.hidden=true;box.hidden=true;content.append(overlay,box);
    const menu=node('div','','vp3-email-capture-menu');menu.hidden=true;menu.setAttribute('role','menu');menu.setAttribute('aria-label','Selected email actions');root.append(menu);
    const dialog=node('dialog','','vp3-email-capture-dialog');dialog.setAttribute('aria-label','Selected email area');
    const heading=node('header'),title=node('h2','Selected email area'),close=button('Close',()=>dialog.close());heading.append(title,close);
    const origin=node('p',bounded(message.subject||'(No subject)',500)+(message.from?' · '+bounded(message.from,500):''),'vp3-email-capture-origin');
    const selected=node('textarea');selected.rows=5;selected.maxLength=6000;selected.setAttribute('aria-label','Selected email text');
    const image=node('img');image.alt='Captured email area';image.hidden=true;
    const output=node('section','','vp3-email-capture-result'),notice=node('p','','vp3-email-capture-notice');notice.setAttribute('role','status');
    const controls=node('div','','vp3-email-capture-actions'),formHost=node('section'),completed=node('section','','vp3-email-capture-completed');
    const history=node('section','','vp3-email-capture-history');history.hidden=true;
    const back=button('Back to email',()=>{dialog.close();root.scrollIntoView({block:'nearest'});});
    dialog.append(heading,origin,back,selected,image,notice,controls,history,output,completed,formHost);root.append(dialog);
    let lastFocus=null;
    const say=text=>{if(current()){status.textContent=text;notice.textContent=text;}};
    const snapshot=()=>({generation,text:selection,photo});
    const matches=s=>current()&&s.generation===generation&&s.text===selection&&s.photo===photo;
    function clearAttempt() {clearTimeout(poll);poll=0;analysis=analysisRequest=null;currentActions=[];output.replaceChildren();completed.replaceChildren();formHost.replaceChildren();history.hidden=true;history.replaceChildren();historyCursor=null;}
    function changed() {generation++;clearAttempt();}
    function setSelecting(value) {
      if (!current()||busy) return;
      selecting=value;choose.setAttribute('aria-pressed',String(value));overlay.hidden=!value;
      say(value?'Drag over text or a photo in this email. Press Escape to cancel.':'');
    }
    function localRect(rect) {
      const base=content.getBoundingClientRect();return {x:rect.left-base.left,y:rect.top-base.top,width:rect.width,height:rect.height};
    }
    function viewportRect() {
      if (!region) return null;const base=content.getBoundingClientRect();
      return {left:base.left+region.x,top:base.top+region.y,right:base.left+region.x+region.width,bottom:base.top+region.y+region.height,width:region.width,height:region.height};
    }
    const fullyVisible=()=>{const r=viewportRect();return r&&r.left>=0&&r.top>=0&&r.right<=innerWidth&&r.bottom<=innerHeight;};
    function paint(rect) {
      region=localRect(rect);box.hidden=false;
      Object.assign(box.style,{left:region.x+'px',top:region.y+'px',width:region.width+'px',height:region.height+'px'});
    }
    function bodyRect(rect) {
      if (!frame) return rect;
      const f=frame.getBoundingClientRect();return {left:rect.left-f.left,top:rect.top-f.top,right:rect.right-f.left,bottom:rect.bottom-f.top};
    }
    function selectRect(rect) {
      changed();photo='';image.hidden=true;image.removeAttribute('src');paint(rect);
      selection=selectedText(body,bodyRect(rect));selected.value=selection;
      say(selection?'Email area selected. Right-click it or choose Email actions.':'Photo area selected. Choose Snap photo to capture it.');
    }
    function useWholeText() {
      changed();selection=bounded(body.innerText||message.body||'',6000);selected.value=selection;photo='';region=null;box.hidden=true;
    }
    const dragRect=event=>{
      const base=content.getBoundingClientRect(),a=drag;
      const x=Math.max(base.left,Math.min(base.right,event.clientX)),y=Math.max(base.top,Math.min(base.bottom,event.clientY));
      return {left:Math.min(a.x,x),top:Math.min(a.y,y),right:Math.max(a.x,x),bottom:Math.max(a.y,y),width:Math.abs(x-a.x),height:Math.abs(y-a.y)};
    };
    overlay.addEventListener('pointerdown',event=>{
      if (!current()||!selecting||event.button!==0||busy) return;
      event.preventDefault();drag={x:event.clientX,y:event.clientY};overlay.setPointerCapture(event.pointerId);
    });
    overlay.addEventListener('pointermove',event=>{if(drag)paint(dragRect(event));});
    overlay.addEventListener('pointerup',event=>{
      if (!drag) return;const rect=dragRect(event);drag=null;setSelecting(false);
      if(rect.width<4||rect.height<4){box.hidden=true;region=null;return;}
      selectRect(rect);openMenu(event.clientX,event.clientY);
    });
    overlay.addEventListener('pointercancel',()=>{drag=null;setSelecting(false);});
    selected.addEventListener('input',()=>{if(busy)return;selection=bounded(selected.value,6000);changed();refreshControls();});
    function contextMenu(event) {
      if (!event.isTrusted||!current()||busy||selecting) return;
      const sel=doc.getSelection();
      if(sel&&!sel.isCollapsed&&body.contains(sel.anchorNode)&&body.contains(sel.focusNode)) {
        changed();selection=bounded(sel.toString(),6000);selected.value=selection;photo='';image.hidden=true;image.removeAttribute('src');
        const r=sel.getRangeAt(0).getBoundingClientRect(),f=frame?.getBoundingClientRect();
        paint({left:r.left+(f?.left||0),top:r.top+(f?.top||0),width:r.width,height:r.height});
      } else if(event.target.closest?.('img')&&body.contains(event.target)) {
        const r=event.target.getBoundingClientRect(),f=frame?.getBoundingClientRect();
        selectRect({left:r.left+(f?.left||0),top:r.top+(f?.top||0),right:r.right+(f?.left||0),bottom:r.bottom+(f?.top||0),width:r.width,height:r.height});
      } else if(!region&&!selection) return;
      event.preventDefault();const f=frame?.getBoundingClientRect();openMenu(event.clientX+(f?.left||0),event.clientY+(f?.top||0));
    }
    body.addEventListener('contextmenu',contextMenu,{signal:life.signal});
    overlay.addEventListener('contextmenu',event=>{if(region){event.preventDefault();openMenu(event.clientX,event.clientY);}});
    const registry=[
      {id:'history',label:'Saved analyses',run:()=>loadHistory()},
      {id:'text',label:'Analyze text (uses AI)',run:()=>analyze('text')},
      {id:'snap',label:'Snap photo',run:event=>snap(event)},
      {id:'photo',label:'Analyze photo (uses AI)',run:()=>analyze('photo')},
      {id:'calendar',label:'Add to calendar',run:()=>showForm('calendar')},
      {id:'reminder',label:'Set reminder',run:()=>showForm('reminder')},
      {id:'task',label:'Save as task',run:()=>showForm('task')},
      {id:'chat',label:'Send finding to Chat',run:()=>showForm('chat')},
      {id:'research',label:'Research product in Chat',run:()=>showForm('research')},
      {id:'amazon',label:'Search Amazon',run:()=>showForm('amazon')},
    ];
    const disabled=id=>busy||(id==='text'&&!selection.trim())||(id==='photo'&&!photo)||(id==='snap'&&!region);
    function renderActions(host,forMenu=false) {
      host.replaceChildren();for(const action of registry){const el=button(action.label,event=>{
        menu.hidden=true;if(action.id!=='snap')openPreview();void action.run(event);
      });el.dataset.emailCaptureAction=action.id;el.disabled=disabled(action.id);if(forMenu)el.setAttribute('role','menuitem');host.append(el);}
    }
    function refreshControls(){renderActions(controls);selected.disabled=busy;choose.disabled=busy;actions.disabled=busy;recent.disabled=busy;for(const b of history.querySelectorAll('button'))b.disabled=busy;}
    function openPreview() {
      if(!current())return;menu.hidden=true;selected.value=selection;image.hidden=!photo;if(photo)image.src=photo;
      refreshControls();if(!dialog.open){lastFocus=document.activeElement;dialog.showModal();}
    }
    function openMenu(x,y) {
      if(!current())return;renderActions(menu,true);menu.hidden=false;
      menu.style.left=Math.max(8,Math.min(x,innerWidth-menu.offsetWidth-8))+'px';
      menu.style.top=Math.max(8,Math.min(y,innerHeight-menu.offsetHeight-8))+'px';
      menu.querySelector('button:not(:disabled)')?.focus();
    }
    menu.addEventListener('keydown',event=>{
      if(!['ArrowDown','ArrowUp','Home','End','Escape'].includes(event.key))return;event.preventDefault();
      if(event.key==='Escape'){menu.hidden=true;actions.focus();return;}
      const items=[...menu.querySelectorAll('button:not(:disabled)')],index=items.indexOf(document.activeElement);
      items[event.key==='Home'?0:event.key==='End'?items.length-1:(index+(event.key==='ArrowUp'?-1:1)+items.length)%items.length]?.focus();
    });
    document.addEventListener('pointerdown',event=>{if(!menu.contains(event.target))menu.hidden=true;},{signal:life.signal});
    document.addEventListener('keydown',event=>{if(event.key==='Escape'){menu.hidden=true;if(selecting)setSelecting(false);}},{signal:life.signal});
    dialog.addEventListener('close',()=>{menu.hidden=true;lastFocus?.isConnected&&lastFocus.focus({preventScroll:true});});
    if(doc!==document)doc.addEventListener('pointerdown',()=>{menu.hidden=true;},{signal:life.signal});
    async function request(action,fields={},timeout=25000) {
      if(!current())throw Error('The selected email changed.');
      const control=new AbortController(),stop=()=>control.abort();life.signal.addEventListener('abort',stop,{once:true});
      const timer=setTimeout(stop,timeout);
      try {
        const response=await fetch(endpoint.href,{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',signal:control.signal,
          headers:{'Content-Type':'application/json'},body:JSON.stringify({...fields,...captured,action,source_ref:source})});
        const data=await response.json().catch(()=>null);if(!current())throw Error('The selected email changed.');
        if(!response.ok||data?.ok!==true){
          const error=Error(data?.error||'The email action could not be completed.');error.status=response.status;
          if([401,403,409,419].includes(response.status)){
            entry.accessRevoked=true;
            destroy();root.append(node('p',error.message+' Reopen the original email.','vp3-email-capture-notice'));
          }
          throw error;
        }
        return data;
      } finally {clearTimeout(timer);life.signal.removeEventListener('abort',stop);}
    }
    const timezone=()=>{try{return Intl.DateTimeFormat().resolvedOptions().timeZone||'UTC';}catch{return 'UTC';}};
    function receiptNode(value) {
      const item=node('article','','vp3-email-capture-receipt');
      const kind=({calendar:'Calendar event',reminder:'Reminder',task:'Task',chat:'Chat message'})[value.kind]||'Saved action';
      const status=bounded(value.status,40).replaceAll('_',' ');
      item.append(node('strong',bounded(value.title||'Saved action',240)),node('p',`${kind} #${Number(value.record_id)||0} · ${status.charAt(0).toUpperCase()+status.slice(1)}`));
      if(value.available!==true)return item;
      if(value.detail){const details=node('details');details.append(node('summary','View saved '+bounded(value.kind,12)),node('p',bounded(value.detail,10000)));item.append(details);}
      if(typeof value.href==='string'){
        try{const target=new URL(value.href,location.href);if(target.origin===location.origin&&!target.username&&!target.password){
          const link=node('a',value.kind==='calendar'?'Open calendar event':'Open Chat');link.href=target.href;link.target='_blank';link.rel='noopener noreferrer';item.append(link);
        }}catch{}
      }
      if(value.kind==='reminder'&&window.VP3TaskNotes?.open)item.append(button('Open reminder list',()=>{
        dialog.close();sourceDialog?.close();window.VP3TaskNotes.open({kind:'reminders',id:0,view:'all',timezone:timezone()});
      }));
      return item;
    }
    function renderCompleted(values=[]) {
      currentActions=Array.isArray(values)?values.slice(0,40):[];completed.replaceChildren();
      if(currentActions.length){completed.append(node('h3','Saved actions'));for(const value of currentActions)completed.append(receiptNode(value));}
    }
    async function loadHistory(cursor=null) {
      if(busy||!current())return;const s=snapshot();busy=true;refreshControls();history.hidden=false;
      if(cursor===null)history.replaceChildren(node('h3','Recent analyses'),node('p','Loading saved analyses…'));
      try{
        const data=await request('history',cursor?{cursor}:{});if(!matches(s))return;
        const saved=data.history;if(!saved||!Array.isArray(saved.items)||saved.items.length>10)throw Error('The saved analysis list could not be verified.');
        if(cursor===null)history.replaceChildren(node('h3','Recent analyses'),node('div','','vp3-email-capture-history-list'));
        const list=history.querySelector('.vp3-email-capture-history-list')||history;
        history.querySelector('[data-history-more]')?.remove();
        if(!saved.items.length&&cursor===null)history.append(node('p','No saved analyses for this email in this Chat yet.'));
        for(const item of saved.items){
          if(!item||!/^[a-f0-9]{32}$/.test(item.id))throw Error('The saved analysis list could not be verified.');
          const row=node('article','','vp3-email-capture-history-item'),date=new Date(item.created_at);
          row.append(node('strong',`${item.mode==='photo'?'Photo':'Text'} analysis · ${bounded(item.state,20)}`),node('small',Number.isFinite(date.getTime())?date.toLocaleString():''),node('p',bounded(item.summary||item.excerpt||'Saved analysis attempt',300)),button('Reopen analysis',()=>restoreAnalysis(item.id)));
          list.append(row);
        }
        historyCursor=typeof saved.next_cursor==='string'?saved.next_cursor:null;
        if(historyCursor){const more=button('More analyses',()=>loadHistory(historyCursor));more.dataset.historyMore='true';history.append(more);}
        if(cursor===null&&Array.isArray(saved.actions)&&saved.actions.length){
          const details=node('details','','vp3-email-capture-source-actions');details.append(node('summary','Saved actions from this email'));const seen=new Set();
          for(const value of saved.actions.slice(0,40)){const key=value.kind+':'+value.record_id;if(!seen.has(key)){details.append(receiptNode(value));seen.add(key);}}history.append(details);
        }
        say('Saved analyses loaded. Choose one to reopen.');
      }catch(error){if(matches(s)){if(cursor===null)history.replaceChildren(node('h3','Recent analyses'));history.append(node('p',error.message));say(error.message);}}
      finally{if(matches(s)){busy=false;refreshControls();}}
    }
    async function restoreAnalysis(id) {
      if(busy||!current())return;const s=snapshot();busy=true;refreshControls();say('Reopening saved analysis…');
      try{
        const data=await request('get',{capture_id:id});if(!matches(s))return;
        changed();selection=bounded(data.capture?.selected_text||'',6000);selected.value=selection;photo='';region=null;box.hidden=true;image.hidden=true;image.removeAttribute('src');
        showAnalysis(data.capture);history.hidden=true;
        if(['preparing','dispatched'].includes(data.capture.state)){
          const token=analysisRequest='saved:'+id,next=snapshot();poll=setTimeout(()=>void checkAttempt(token,next,id),1500);return;
        }
        say('Saved analysis reopened.');
      }catch(error){if(current())say(error.message);}
      finally{if(current()&&!poll){busy=false;refreshControls();}}
    }
    function showAnalysis(value) {
      if(!value||!/^[a-f0-9]{32}$/.test(value.id)||!['preparing','dispatched','ready','failed'].includes(value.state))throw Error('The saved analysis could not be verified.');
      analysis=value;output.replaceChildren();renderCompleted(value.actions);
      if(value.state==='ready'){
        const result=value.result;
        if(!result||typeof result.summary!=='string'||result.summary.length>8000||typeof result.extracted_text!=='string'||result.extracted_text.length>24000
          ||typeof result.product_query!=='string'||result.product_query.length>640||!Array.isArray(result.dates)||result.dates.length>5)throw Error('The saved analysis could not be verified.');
        output.append(node('h3','Analysis'),node('p',result.summary));
        if(result.extracted_text){const details=node('details');details.append(node('summary','Read captured text'),node('p',result.extracted_text));output.append(details);}
        if(result.dates.length){const dates=node('ul');for(const date of result.dates)dates.append(node('li',[date.title,dateLabel(date.start_local),date.timezone,date.note].filter(Boolean).join(' · ')));output.append(dates);}
        say('Analysis ready. Review the details before saving an action.');
      }else if(value.state==='failed'){
        say(value.reason==='not_dispatched'?'Analysis did not start. Check your Agent’s model settings or AI allowance.':'This attempt stopped. Check AI usage before starting another analysis.');
        if(typeof value.received_text==='string'&&value.received_text){const details=node('details');details.append(node('summary','Received analysis'),node('pre',value.received_text));output.append(details);}
      }else say('Email specialist is analyzing the selected area…');
    }
    async function checkAttempt(attempt,s,captureId=null) {
      if(!matches(s)||analysisRequest!==attempt)return;
      poll=0;
      try {
        const data=await request('get',captureId?{capture_id:captureId}:{request_id:attempt});if(!matches(s)||analysisRequest!==attempt)return;
        showAnalysis(data.capture);
        if(['preparing','dispatched'].includes(data.capture.state))poll=setTimeout(()=>void checkAttempt(attempt,s,captureId),2000);
        else {busy=false;refreshControls();}
      }catch(error){if(matches(s)){busy=false;refreshControls();say(error.message);}}
    }
    async function analyze(mode) {
      if(busy||!current()||mode==='text'&&!selection.trim()||mode==='photo'&&!photo)return;
      const attempt=analysisRequest=uuid(),s=snapshot();busy=true;refreshControls();output.replaceChildren();formHost.replaceChildren();say('Email specialist is analyzing the selected area…');
      try {
        const data=await request('analyze',{request_id:attempt,mode,text:selection,image:mode==='photo'?photo:'',timezone:timezone()},140000);
        if(!matches(s)||analysisRequest!==attempt)return;showAnalysis(data.capture);
        if(['preparing','dispatched'].includes(data.capture.state)){poll=setTimeout(()=>void checkAttempt(attempt,s),2000);return;}
      }catch(error){
        if(!matches(s))return;
        if([401,403,409,419,422].includes(error.status)){say(error.message);}
        else {say('Checking the saved analysis attempt…');poll=setTimeout(()=>void checkAttempt(attempt,s),0);return;}
      }
      finally {if(matches(s)&&analysisRequest===attempt&&!poll){busy=false;refreshControls();}}
    }
    async function snap(event) {
      if(!event.isTrusted||busy||!current()||!region)return;
      if(!fullyVisible()){openPreview();say('Bring the whole marked area into view, or select a smaller area, before snapping a photo.');return;}
      if(!navigator.mediaDevices?.getDisplayMedia||!window.CropTarget?.fromElement){openPreview();say('Photo area capture needs a desktop browser with region sharing. Text actions are available here.');return;}
      const s=snapshot();busy=true;refreshControls();menu.hidden=true;if(dialog.open)dialog.close();
      say('Choose this VP3 tab to capture the marked email area.');
      let video=null,completed=false;
      try {
        box.style.borderColor='transparent';
        // The picker is invoked directly in the trusted click, before any await.
        const pending=navigator.mediaDevices.getDisplayMedia({video:{displaySurface:'browser'},audio:false,preferCurrentTab:true,selfBrowserSurface:'include',monitorTypeSurfaces:'exclude',surfaceSwitching:'exclude'});
        stream=await pending;
        if(!matches(s))return;
        const track=stream.getVideoTracks()[0];
        if(track?.getSettings?.().displaySurface!=='browser'||typeof track.cropTo!=='function')throw Error('Choose this VP3 browser tab to capture the email area.');
        const target=await CropTarget.fromElement(box);await track.cropTo(target);
        if(!matches(s))return;
        // Only attach a video sink after the native track has been cropped.
        video=document.createElement('video');video.muted=true;video.playsInline=true;video.srcObject=stream;
        await Promise.race([video.play().then(()=>new Promise((resolve,reject)=>{
          const timer=setTimeout(()=>reject(Error('No current cropped photo was received.')),10000);
          const ready=()=>{clearTimeout(timer);resolve();};
          if(typeof video.requestVideoFrameCallback==='function')video.requestVideoFrameCallback(ready);
          else if(video.readyState>=2)ready();else video.addEventListener('loadeddata',ready,{once:true});
        })),new Promise((_,reject)=>setTimeout(()=>reject(Error('Photo capture timed out.')),12000))]);
        if(!matches(s)||!fullyVisible()||track.readyState!=='live'||!video.videoWidth||!video.videoHeight)return;
        const scale=Math.min(1,1280/Math.max(video.videoWidth,video.videoHeight)),canvas=document.createElement('canvas');
        canvas.width=Math.max(1,Math.round(video.videoWidth*scale));canvas.height=Math.max(1,Math.round(video.videoHeight*scale));
        canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);
        const jpeg=canvas.toDataURL('image/jpeg',.75);if(jpeg.length>420000)throw Error('Select a smaller photo area.');
        changed();photo=jpeg;image.src=photo;image.hidden=false;completed=true;say('Photo captured. Sharing has stopped. Choose Analyze photo when ready.');
      }catch(error){if(current())say(error.name==='NotAllowedError'?'Photo capture was cancelled.':error.message);}
      finally {
        for(const track of stream?.getTracks()||[])track.stop();stream=null;if(video)video.srcObject=null;
        if(current()){box.style.borderColor='';busy=false;if(matches(s)||completed)openPreview();refreshControls();}
      }
    }
    function showForm(kind) {
      if(busy)return;formHost.replaceChildren();const form=node('form'),fields={},info=node('p');info.setAttribute('role','status');
      form.append(node('h3',({calendar:'Add to calendar',reminder:'Set reminder',task:'Save as task',chat:'Send finding to Chat',research:'Research product in Chat',amazon:'Search Amazon'})[kind]));
      const field=(name,label,type,value='',max=500)=>{const wrapper=node('label',label),input=node(type==='textarea'?'textarea':'input');if(type==='textarea')input.rows=4;else input.type=type;input.name=name;input.value=value;input.maxLength=max;input.required=true;wrapper.append(input);fields[name]=input;form.append(wrapper);return input;};
      const suggested=analysis?.state==='ready'?analysis.result:null;
      const date=suggested?.dates?.[0]||{},defaultTitle=bounded(date.title||selection.trim().split('\n')[0]||message.subject||'',190);
      let dateChoice=null;
      if(['calendar','reminder'].includes(kind)&&suggested?.dates?.length>1){
        const label=node('label','Suggested date'),select=node('select');select.setAttribute('aria-label','Suggested date');dateChoice=select;
        for(const [i,d] of suggested.dates.entries()){const option=node('option',[d.title,dateLabel(d.start_local)].filter(Boolean).join(' · '));option.value=String(i);select.append(option);}
        label.append(select);form.append(label);
      }
      if(['task','chat'].includes(kind)){
        const finding=[suggested?.summary,suggested?.extracted_text||selection].filter(Boolean).join('\n\n');
        if(kind==='task'){field('title','Task title','text',bounded(defaultTitle,180),180);field('notes','Task notes (optional)','textarea',bounded(finding,4000),4000).required=false;}
        else field('message','Finding to share','textarea',bounded(finding,8000),8000);
        form.append(node('p',kind==='task'?'Save to this Agent’s existing tasks with the email source attached.':'Save this reviewed finding in this Chat. Your typed Chat draft is kept.'));
      }else if(['research','amazon'].includes(kind)) {
        const cleaned=selection.replace(/https?:\/\/\S+|\b\S+@\S+\b/g,'').replace(/\s+/g,' ').trim();
        const max=kind==='research'?120:160;
        field('query','Search phrase','text',bounded(suggested?.product_query||cleaned,max),max);
        form.append(node('p',kind==='research'?'Prepare a request in Chat. Your current Chat draft is kept.':'Open an Amazon search for this phrase.'));
      }else {
        field('title','Title','text',defaultTitle,190);
        if(kind==='calendar'){
          field('date','Date','date',date.start_local?.slice(0,10)||'');field('start_time','Start time','time',date.start_local?.slice(11,16)||'');
          field('end_date','End date','date',date.end_local?.slice(0,10)||date.start_local?.slice(0,10)||'').required=false;
          field('end_time','End time','time',date.end_local?.slice(11,16)||'').required=false;
          field('location','Location (optional)','text','',500).required=false;
        }else field('start_local','Remind me at','datetime-local',date.start_local||'');
        field('timezone','Time zone','text',date.timezone||timezone(),80);
        form.append(node('p','Review the date, time and time zone. The email source and selected text stay attached to this action.'));
      }
      dateChoice?.addEventListener('change',()=>{
        const d=suggested.dates[Number(dateChoice.value)];if(!d)return;
        fields.title.value=d.title||defaultTitle;fields.timezone.value=d.timezone||timezone();
        if(kind==='calendar'){
          fields.date.value=d.start_local?.slice(0,10)||'';fields.start_time.value=d.start_local?.slice(11,16)||'';
          fields.end_date.value=d.end_local?.slice(0,10)||d.start_local?.slice(0,10)||'';fields.end_time.value=d.end_local?.slice(11,16)||'';
        }else fields.start_local.value=d.start_local||'';
      });
      const save=button(({calendar:'Save calendar event',reminder:'Save reminder',task:'Save task',chat:'Send to Chat',research:'Prepare in Chat',amazon:'Search Amazon'})[kind],()=>form.requestSubmit());
      form.append(save,info);formHost.append(form);
      form.addEventListener('submit',async event=>{
        event.preventDefault();if(!current()||busy||!form.reportValidity())return;
        const values=Object.fromEntries(Object.entries(fields).map(([key,input])=>[key,input.value.trim()]));
        if(kind==='research'){
          const chat=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
          if(chat?.stageSpecialistPrompt?.('/research '+values.query,captured.conversation_id)!==true){info.textContent='Finish the current Chat turn or save your typed draft, then try again.';return;}
          dialog.close();root.closest('dialog')?.close();return;
        }
        if(kind==='amazon'){
          const url=new URL('https://www.amazon.com/s');url.searchParams.set('k',values.query);
          window.open(url.href,'_blank','noopener,noreferrer');info.textContent='Amazon search opened.';return;
        }
        const s=snapshot();busy=true;for(const input of Object.values(fields))input.disabled=true;if(dateChoice)dateChoice.disabled=true;save.disabled=true;info.textContent='Saving…';refreshControls();
        try {
          const data=await request(kind,{text:selection,fields:values,...(analysis?.id?{capture_id:analysis.id}:{})});if(!matches(s))return;
          info.textContent=data.saved?.message||'Saved.';save.textContent='Saved';
          if(data.saved?.action){const updated=[data.saved.action,...currentActions.filter(x=>x.id!==data.saved.action.id)];renderCompleted(updated);if(analysis)analysis.actions=updated;}
        }catch(error){if(matches(s)){
          info.textContent=error.message+(error.status===422?'':' Retry keeps the same details.');
          if(error.status===422){for(const input of Object.values(fields))input.disabled=false;if(dateChoice)dateChoice.disabled=false;}
          save.textContent=error.status===422?'Save corrected details':'Retry save';save.disabled=false;
        }}
        finally {if(matches(s)){busy=false;refreshControls();}}
      });
    }
    let size=null;
    const resize=typeof ResizeObserver==='function'?new ResizeObserver(()=>{
      const rect=content.getBoundingClientRect(),next=[rect.width,rect.height];
      if(size&&size.some((v,i)=>Math.abs(v-next[i])>1)&&(region||photo)){
        changed();busy=false;selection=photo='';selected.value='';image.hidden=true;image.removeAttribute('src');region=null;box.hidden=true;
        for(const track of stream?.getTracks()||[])track.stop();stream=null;say('The email layout changed. Select the area again.');refreshControls();
      }
      size=next;
    }):null;
    resize?.observe(content);
    function destroy() {
      if(disposed)return;disposed=true;life.abort();clearTimeout(poll);for(const track of stream?.getTracks()||[])track.stop();stream=null;
      resize?.disconnect();
      image.removeAttribute('src');selected.value='';output.replaceChildren();completed.replaceChildren();history.replaceChildren();formHost.replaceChildren();
      for(const el of [choose,actions,recent,status,overlay,box,menu,dialog])el.remove();selection=photo='';analysis=analysisRequest=null;currentActions=[];historyCursor=null;views.delete(entry);
    }
    sourceDialog?.addEventListener('close',destroy,{signal:life.signal});
    const entry={root,destroy,current};views.add(entry);refreshControls();return entry;
  }
  new MutationObserver(()=>{for(const view of views)if(!view.current())view.destroy();}).observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden','open','data-view']});
  for(const name of ['stonefellow:chat-context-changing','vp3:participant-selection-changed','pagehide'])window.addEventListener(name,()=>{for(const view of views)view.destroy();});
  window.VP3GmailEmailCaptureV1=Object.freeze({mount});
})();
