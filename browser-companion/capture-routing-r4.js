/* Uses canonical Cloud captures and Chat. No parallel Chrome message/Knowledge store. */
(() => {
  'use strict';
  const view=document.getElementById('agentView');if(!view)return;
  const panel=document.createElement('details');panel.className='card capture-routing-r4';
  const heading=document.createElement('summary');heading.textContent='Add highlighted annotation to Chat';panel.append(heading);
  const status=document.createElement('p');status.className='hint';status.setAttribute('role','status');panel.append(status);
  function field(label,element){const box=document.createElement('label');box.className='field';const name=document.createElement('span');name.textContent=label;box.append(name,element);panel.append(box);return element;}
  const quote=field('Highlighted text',document.createElement('textarea'));quote.readOnly=true;quote.rows=4;
  const source=document.createElement('p');source.className='hint';panel.append(source);
  const annotation=field('Your annotation',document.createElement('textarea'));annotation.rows=2;annotation.maxLength=4096;
  const project=field('Research project (optional)',document.createElement('select'));
  const save=field('Save to shared Cloud / HomeServer Knowledge',document.createElement('input'));save.type='checkbox';
  const folder=field('Existing Knowledge folder',document.createElement('select'));folder.disabled=true;
  const tags=field('Tags (comma separated)',document.createElement('input'));tags.maxLength=1000;
  const refresh=document.createElement('button');refresh.type='button';refresh.textContent='Load highlight and destinations';panel.append(refresh);
  const send=document.createElement('button');send.type='button';send.className='primary';send.textContent='Send annotation to Chat';send.disabled=true;panel.append(send);
  const hint=document.createElement('p');hint.className='hint';hint.textContent='Uses the conversation selected above, or creates a new chat. Project links use existing Research permissions. Knowledge reaches HomeServer through workspace sync.';panel.append(hint);
  const card=view.querySelector('.agent-workspace-card');(card||view).insertBefore(panel,(card||view).querySelector('#agentMessages'));
  let frozen=null,options=null,sending=false,generation=0;
  function fill(select,none,rows,key,label){select.replaceChildren(new Option(none,''));for(const row of rows||[])select.append(new Option(String(row[label]),String(row[key])));}
  function lock(on){sending=on;[send,refresh,annotation,project,save,tags].forEach(x=>x.disabled=on);folder.disabled=on||!save.checked;send.disabled=on||!frozen?.selected_text||!options;}
  async function load(useCurrent=false){
    const gen=++generation;options=null;frozen=null;quote.value='';source.textContent='';lock(true);
    try{
      const current=useCurrent?capture:await msg('capture');
      const result=await msg('capture_r4',{action:'options',payload:agentWorkspaceAgentId>0?{agent_id:agentWorkspaceAgentId}:{}});
      if(gen!==generation)return;
      if(!current?.available||!String(current.selected_text||'').trim())throw Error('Highlight text on the page, then load it here.');
      frozen=structuredClone(current);options=result;agentWorkspaceAgentId=Number(result.agent_id||0);
      quote.value=frozen.selected_text;source.textContent=frozen.title+' · '+frozen.source_url;
      fill(project,'No project',result.projects,'id','title');fill(folder,'Unfiled Knowledge',result.folders,'id','folder_name');
      save.checked=false;save.disabled=!result.knowledge_available;
      status.textContent='Review this highlight, annotation and selected destinations before sending.';
    }catch(e){if(gen===generation)status.textContent=e.message;}
    finally{if(gen===generation){lock(false);save.disabled=!options?.knowledge_available;}}
  }
  save.addEventListener('change',()=>folder.disabled=!save.checked);refresh.addEventListener('click',()=>load());
  panel.addEventListener('toggle',()=>{if(panel.open&&!sending&&!frozen)load(true);});
  send.addEventListener('click',async()=>{
    if(sending||!frozen||!options)return;
    const tagList=tags.value.split(/[,\n]/).map(x=>x.trim()).filter(Boolean);
    if(tagList.length>20||tagList.some(x=>Array.from(x).length>40)){status.textContent='Use up to 20 tags, each at most 40 characters.';return;}
    if(new TextEncoder().encode(annotation.value).length>4096){status.textContent='Your annotation must fit within 4,096 bytes.';return;}
    const payload={owner_user_id:Number(options.owner_user_id),device_id:options.device_id,agent_id:Number(options.agent_id),conversation_id:Number(agentConversationId||0),project_id:project.value,
      save_knowledge:save.checked,folder_id:save.checked?Number(folder.value||0):0,tags:tags.value,
      source:{url:frozen.source_url,canonical_url:frozen.canonical_url||frozen.source_url,title:frozen.title||''},
      snapshot:{selected_text:frozen.selected_text,captured_at:frozen.captured_at},message:{note:annotation.value},share_type:'selection',confirmed:true};
    lock(true);status.textContent='Saving annotation…';
    try{
      const result=await msg('capture_r4',{action:'send',payload});
      agentWorkspaceAgentId=Number(result.agent_id);agentConversationId=Number(result.conversation_id);
      await loadAgentConversationsV2160();await loadAgentConversationV2160(result.conversation_id);
      status.textContent=result.idempotent_replay?'This annotation was already delivered.':save.checked?'Annotation delivered. Knowledge saved; HomeServer will receive it through workspace sync.':'Annotation delivered to Chat.';
      annotation.value='';frozen=null;quote.value='';source.textContent='';panel.open=false;
    }catch(e){status.textContent=e.message+' Your reviewed capture is retained here for retry.';}
    finally{lock(false);save.disabled=!options?.knowledge_available;}
  });
  window.VP3_OPEN_CAPTURE_R4=()=>{options=null;panel.open=true;load(true);};
})();
