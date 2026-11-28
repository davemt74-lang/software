(() => {
  'use strict';
  const contract='vp3.profile-knowledge.v1',drafts=new Map();
  let current=null,sequence=0,styled=false;
  const positive=value=>Number.isSafeInteger(value)&&value>0;
  const node=(tag,text)=>{const value=document.createElement(tag);if(text!==undefined)value.textContent=text;return value;};
  function style(){
    if(styled)return;styled=true;
    const sheet=node('style');sheet.textContent=`.vp3-profile-knowledge-dialog{box-sizing:border-box;width:min(680px,calc(100vw - 24px));max-height:calc(100dvh - 32px);margin:auto;padding:24px;border:1px solid #d6dce3;border-radius:14px;background:#fff;color:#17202a;font:14px/1.5 system-ui,sans-serif;overflow:auto}.vp3-profile-knowledge-dialog::backdrop{background:rgba(14,23,34,.5)}.vp3-profile-knowledge-dialog h2{margin:0 0 6px;font-size:20px}.vp3-profile-knowledge-dialog p{margin:8px 0;overflow-wrap:anywhere}.vp3-profile-knowledge-dialog form{display:grid;gap:14px}.vp3-profile-knowledge-dialog label{display:grid;gap:5px;font-weight:600}.vp3-profile-knowledge-dialog input:not([type=checkbox]),.vp3-profile-knowledge-dialog textarea,.vp3-profile-knowledge-dialog select{box-sizing:border-box;width:100%;min-width:0;padding:9px;border:1px solid #aeb8c4;border-radius:7px;background:#fff;color:#17202a;font:inherit}.vp3-profile-knowledge-dialog textarea{min-height:180px;resize:vertical}.vp3-profile-knowledge-dialog .vp3-profile-knowledge-check{display:flex;align-items:flex-start;gap:9px;font-weight:400}.vp3-profile-knowledge-dialog input[type=checkbox]{flex:0 0 auto;margin-top:4px}.vp3-profile-knowledge-dialog details{border:1px solid #e1e5e9;border-radius:8px;padding:10px}.vp3-profile-knowledge-dialog details p{white-space:pre-wrap}.vp3-profile-knowledge-dialog summary{cursor:pointer;font-weight:600}.vp3-profile-knowledge-dialog .vp3-profile-knowledge-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.vp3-profile-knowledge-dialog button{padding:9px 13px;border:1px solid #aeb8c4;border-radius:7px;background:#fff;color:#17202a;font:inherit;cursor:pointer}.vp3-profile-knowledge-dialog button[type=submit]{background:#17202a;color:#fff}.vp3-profile-knowledge-dialog button:disabled{opacity:.55;cursor:default}.vp3-profile-knowledge-dialog [role=status]{min-height:21px}.vp3-profile-knowledge-dialog [data-knowledge-error=true]{color:#99282b}.vp3-profile-knowledge-dialog a{color:#145ca2}.vp3-profile-knowledge-dialog [hidden]{display:none!important}`;
    document.head.appendChild(sheet);
  }
  function source(options){
    const value=options.source?.();
    return value&&positive(value.conversation_id)&&positive(value.owner_request_id)?{conversation_id:value.conversation_id,owner_request_id:value.owner_request_id,context:String(value.context||'')}:null;
  }
  const key=value=>value.conversation_id+':'+value.owner_request_id+':'+value.context;
  const valid=state=>current===state&&state.host.isConnected&&key(source(state.options)||{})===key(state.source);
  function remember(state){
    if(!state.form||state.saved)return;
    drafts.set(state.key,{title:state.title.value,content:state.content.value,folder_id:state.folder.value,source_revision:state.preview.source_revision});
    if(drafts.size>20)drafts.delete(drafts.keys().next().value);
  }
  function close(state){
    if(current!==state)return;remember(state);current=null;state.controller?.abort();state.dialog.close();state.dialog.remove();
    if(state.button.isConnected&&!state.button.hidden)state.button.focus();
  }
  function notice(state,text,error=false){state.notice.textContent=text;state.notice.dataset.knowledgeError=String(error);}
  async function request(state,action,payload,write=false){
    const endpoint=new URL(state.options.endpoint,location.href);
    if(endpoint.origin!==location.origin)throw new Error('Knowledge is unavailable for this page.');
    const controller=new AbortController();state.controller=controller;let timer=0;
    const options={method:write?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal};
    if(write){options.headers={'Content-Type':'application/json'};options.body=JSON.stringify({action,csrf_token:state.options.csrf,...payload});}
    else endpoint.search=new URLSearchParams({action,...payload});
    try{return await Promise.race([
      (async()=>{let response;try{response=await fetch(endpoint.href,options);}catch(_){throw new Error(write?'Saving could not be confirmed. Your note is kept. Close and reopen to check for a saved item before trying again.':'Knowledge could not be loaded. Close and reopen to try again.');}const data=await response.json().catch(()=>null);if(!response.ok||data?.ok!==true){const error=new Error(typeof data?.error==='string'?data.error:'Knowledge could not be loaded. Try again.');error.status=response.status;throw error;}return data;})(),
      new Promise((_,reject)=>{timer=window.setTimeout(()=>{controller.abort();reject(new Error(write?'Saving could not be confirmed. Your note is kept. Close and reopen to check for a saved item before trying again.':'Knowledge could not be loaded in time. Close and reopen to try again.'));},15000);})
    ]);}finally{window.clearTimeout(timer);if(state.controller===controller)state.controller=null;}
  }
  function bound(value,state){return value?.contract===contract&&value.conversation_id===state.source.conversation_id&&value.owner_request_id===state.source.owner_request_id&&positive(value.visitor_message_id)&&positive(value.reply_message_id)&&/^[a-f0-9]{64}$/.test(value.source_revision||'');}
  function savedItem(value){
    if(!value||!positive(value.knowledge_id)||typeof value.title!=='string'||!Number.isSafeInteger(value.folder_id)||value.folder_id<0||typeof value.folder_name!=='string'||typeof value.view_url!=='string')return null;
    try{const url=new URL(value.view_url,location.href);return url.origin===location.origin&&['http:','https:'].includes(url.protocol)?{...value,url:url.href}:null;}catch(_){return null;}
  }
  function knowledgeAccess(state,host){
    const message=node('p','Profile Agent use follows your existing Knowledge Access settings.');
    const endpoint=new URL(state.options.endpoint,location.href),url=new URL('../profile-agent.php?tab=knowledge',endpoint);
    if(url.origin===location.origin){const link=node('a','Open Knowledge Access (new tab)');link.href=url.href;link.target='_blank';link.rel='noopener';message.append(node('br'),link);}
    host.append(message);
  }
  function showSaved(state,value,created){
    const saved=savedItem(value);if(!saved)throw new Error('The saved Knowledge item could not be verified. Close and reopen to check it.');
    state.saved=true;state.busy=false;drafts.delete(state.key);state.body.replaceChildren();
    state.body.append(node('p',created?'Saved to your Knowledge.':'This answer is already saved to your Knowledge.'),node('p','Folder: '+(saved.folder_name||'Unfiled')));
    knowledgeAccess(state,state.body);
    const link=node('a','Open '+saved.title);link.href=saved.url;state.body.append(link);notice(state,'');state.cancel.textContent='Close';state.cancel.disabled=false;
  }
  function field(state,label,name,tag='input'){
    const wrapper=node('label',label),input=node(tag);input.name=name;input.id='profileKnowledge'+state.id+'-'+name;wrapper.htmlFor=input.id;wrapper.append(input);state.form.append(wrapper);return input;
  }
  function check(state,label,name){const wrapper=node('label');wrapper.className='vp3-profile-knowledge-check';const input=node('input');input.type='checkbox';input.name=name;wrapper.append(input,node('span',label));state.form.append(wrapper);return input;}
  function controls(state,busy){
    state.busy=busy;state.form?.querySelectorAll('input,textarea,select,button').forEach(element=>{element.disabled=busy;});state.cancel.disabled=busy;
    if(state.save)state.save.disabled=busy||!state.reviewed.checked;
  }
  function renderEditor(state,preview){
    if(!bound(preview,state)||typeof preview.title!=='string'||typeof preview.content!=='string'||typeof preview.question!=='string'||typeof preview.reply!=='string'||!Array.isArray(preview.folders)||preview.folders.some(folder=>!folder||!Number.isSafeInteger(folder.id)||folder.id<0||typeof folder.name!=='string'))throw new Error('The source answer or Knowledge folders could not be verified.');
    state.preview=preview;if(preview.saved){showSaved(state,preview.saved,false);return;}
    const draft=drafts.get(state.key);state.body.replaceChildren();
    const evidence=node('details'),summary=node('summary','Original question and owner answer');evidence.append(summary,node('strong','Visitor question'),node('p',preview.question),node('strong','Your answer'),node('p',preview.reply));state.body.append(evidence);
    state.form=node('form');state.form.dataset.profileKnowledgeForm='';state.body.append(state.form);
    state.title=field(state,'Title','title');state.title.required=true;state.title.maxLength=190;state.title.value=draft?.title??preview.title;
    state.content=field(state,'Knowledge note — review the question and answer','content','textarea');state.content.required=true;state.content.maxLength=32768;state.content.value=draft?.content??preview.content;
    state.folder=field(state,'Knowledge folder','folder_id','select');
    const folders=preview.folders.some(folder=>folder.id===0)?preview.folders:[{id:0,name:'Unfiled'},...preview.folders];
    for(const folder of folders){const option=node('option',folder.name||'Unfiled');option.value=String(folder.id);state.folder.append(option);}
    state.folder.value=folders.some(folder=>String(folder.id)===draft?.folder_id)?draft.folder_id:'0';
    knowledgeAccess(state,state.form);
    state.reviewed=check(state,'I reviewed the note and want to save it to my Knowledge','approve_content');state.reviewed.required=true;
    state.save=node('button','Save to Knowledge');state.save.type='submit';state.form.append(state.save);controls(state,false);
    const changed=()=>{if(!state.busy){remember(state);controls(state,false);}};state.form.addEventListener('input',changed);state.form.addEventListener('change',changed);
    state.form.addEventListener('submit',event=>{event.preventDefault();event.stopPropagation();void save(state);});
    notice(state,draft&&draft.source_revision!==preview.source_revision?'The owner answer changed. Your note edits are kept. Review them against the current answer before saving.':'');state.title.focus();
  }
  async function save(state){
    if(!valid(state)){close(state);return;}
    if(state.busy||!state.reviewed.checked||!state.form.reportValidity())return;
    remember(state);const payload={conversation_id:state.source.conversation_id,owner_request_id:state.source.owner_request_id,source_revision:state.preview.source_revision,title:state.title.value.trim(),content:state.content.value.trim(),folder_id:Number(state.folder.value),approve_content:true};
    if(!payload.title||!payload.content){notice(state,'Add a title and reviewed note before saving.',true);return;}
    controls(state,true);notice(state,'Saving to your Knowledge…');
    try{const result=await request(state,'owner_knowledge_save',payload,true);if(!valid(state))return;if(!bound(result,state)||result.source_revision!==payload.source_revision)throw new Error('The saved source could not be verified. Close and reopen to check it.');showSaved(state,result.saved,result.created===true);}
    catch(error){if(valid(state))notice(state,error.message||'Saving could not be confirmed. Your note is kept.',true);}
    finally{if(valid(state)&&!state.saved)controls(state,false);}
  }
  async function open(host,button,options){
    const identity=source(options);if(!identity||!host.isConnected)return;if(current)close(current);style();
    const dialog=node('dialog'),heading=node('h2','Save answer to Knowledge'),description=node('p','Review the question and answer, edit the note, and choose one of your Knowledge folders.');
    dialog.className='vp3-profile-knowledge-dialog';heading.id='profileKnowledgeHeading'+(++sequence);dialog.setAttribute('aria-labelledby',heading.id);
    const body=node('div'),status=node('p','Loading the saved owner answer and your Knowledge folders…');status.setAttribute('role','status');status.setAttribute('aria-live','polite');
    const actions=node('div'),cancel=node('button','Cancel');cancel.type='button';actions.className='vp3-profile-knowledge-actions';actions.append(cancel);dialog.append(heading,description,body,status,actions);
    const state={id:sequence,source:identity,key:options.endpoint+':'+key(identity),host,button,options,dialog,body,notice:status,cancel,busy:false,saved:false};current=state;
    cancel.addEventListener('click',()=>{if(!state.busy)close(state);});dialog.addEventListener('cancel',event=>{event.preventDefault();if(!state.busy)close(state);});document.body.append(dialog);dialog.showModal();
    try{const result=await request(state,'owner_knowledge_preview',{conversation_id:identity.conversation_id,owner_request_id:identity.owner_request_id});if(valid(state))renderEditor(state,result.preview);}
    catch(error){if(valid(state))notice(state,error.message||'The saved answer could not be loaded.',true);}
  }
  function attach(host,options){
    if(!host||!options?.endpoint||!options?.csrf||(typeof options.source!=='function'&&typeof options.sources!=='function'))return null;
    let selected=0,signature='';
    const choices=()=>{const values=typeof options.sources==='function'?options.sources():[source(options)];return Array.isArray(values)?values.filter(value=>value&&positive(value.conversation_id)&&positive(value.owner_request_id)):[];};
    const boundOptions={...options,source:()=>{const values=choices();return values.find(value=>value.owner_request_id===selected)||values[0]||null;}};
    const label=node('label','Question for this answer'),select=node('select');select.setAttribute('aria-label','Question for this answer');select.dataset.profileKnowledgeQuestion='';select.style.maxWidth='100%';label.style.display='grid';label.style.gap='5px';label.append(select);
    const button=node('button','Save to Knowledge');button.type='button';button.dataset.profileKnowledgeOpen='';host.append(label,button);button.addEventListener('click',()=>void open(host,button,boundOptions));
    const refresh=()=>{
      const values=choices(),next=JSON.stringify(values.map(value=>[value.owner_request_id,value.conversation_id,value.context,value.question_excerpt]));
      if(next!==signature){signature=next;if(values.length&&!values.some(value=>value.owner_request_id===selected))selected=values[0].owner_request_id;select.replaceChildren(...values.map((value,index)=>{const option=node('option',String(value.question_excerpt||'Question '+(index+1)));option.value=String(value.owner_request_id);return option;}));select.value=String(selected);}
      label.hidden=values.length<2;label.style.display=label.hidden?'none':'grid';select.disabled=values.length<2;
      button.hidden=!source(boundOptions);button.disabled=button.hidden;button.style.display=button.hidden?'none':'';if(current?.button===button&&!valid(current))close(current);
    };
    select.addEventListener('change',()=>{selected=Number(select.value);refresh();});refresh();
    return Object.freeze({refresh,destroy(){if(current?.button===button)close(current);label.remove();button.remove();}});
  }
  window.addEventListener('pagehide',()=>{if(current)close(current);drafts.clear();});
  window.VP3ProfileOwnerKnowledgeV1=Object.freeze({attach});
})();
