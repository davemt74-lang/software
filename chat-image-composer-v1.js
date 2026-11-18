(() => {
  'use strict';
  const cfg=window.VP3_IMAGE_COMPOSER_V1,form=document.getElementById('chatForm');
  const shell=document.getElementById('chatComposerShell'),input=document.getElementById('chatInput');
  const tools=document.getElementById('chatToolsContent');
  if(!cfg||!form||!shell||!input||!tools||window.VP3ImageComposer)return;
  const owner=String(cfg.ownerKey||''),agent=Number(cfg.agentId||0),binding=String(cfg.workingAttentionBinding||'');
  const csrf=String(cfg.csrf||''),limit=4*1024*1024;
  const runtime=()=>window.STONEFELLOW_CHAT_CONTINUITY_V87;
  const currentId=()=>Number(runtime()?.conversationId?.()||0);
  let selection=null,operation=null,offeredImage=null,revision=0,restoreKey='';
  const make=(tag,text,cls)=>{const el=document.createElement(tag);if(text)el.textContent=text;if(cls)el.className=cls;return el;};
  const section=make('section',null,'vp3-image-upload-tool');
  const upload=make('button','Upload base image','vp3-image-upload-button');upload.type='button';
  const hint=make('p','Start an image prompt from your own photo or artwork.');
  const file=make('input');file.type='file';file.accept='image/png,image/jpeg,image/webp';file.hidden=true;file.setAttribute('aria-label','Choose base image');
  const toolStatus=make('p',null,'vp3-image-upload-status');toolStatus.setAttribute('role','status');toolStatus.setAttribute('aria-live','polite');
  section.append(upload,hint,file,toolStatus);tools.prepend(section);
  const chip=make('section',null,'vp3-image-reference');chip.hidden=true;chip.setAttribute('aria-label','Base image for your next prompt');
  shell.insertBefore(chip,form);
  const choice=make('section',null,'vp3-image-reference-choice');choice.hidden=true;choice.setAttribute('aria-label','Use the viewed image as an editing base');
  const referenceStatus=make('p',null,'vp3-image-reference-status');referenceStatus.setAttribute('role','status');referenceStatus.setAttribute('aria-live','polite');
  shell.insertBefore(choice,chip);shell.insertBefore(referenceStatus,form);
  let spaceFrame=0;
  function reserveComposerSpace(){
    if(spaceFrame)return;
    spaceFrame=requestAnimationFrame(()=>{
      spaceFrame=0;
      const thread=document.getElementById('chatThread');if(!thread)return;
      // The footer floats over Chat. Reserve its measured height so a base,
      // replacement choice and growing draft cannot cover the final image.
      const active=!chip.hidden||!choice.hidden||Boolean(referenceStatus.textContent);
      if(active&&shell.isConnected&&shell.getClientRects().length){
        thread.style.setProperty('--vp3-image-composer-space',Math.ceil(shell.getBoundingClientRect().height+16)+'px');
        thread.setAttribute('data-vp3-image-composer-space','');
      }else{
        thread.style.removeProperty('--vp3-image-composer-space');
        thread.removeAttribute('data-vp3-image-composer-space');
      }
    });
  }
  if(typeof ResizeObserver==='function')new ResizeObserver(reserveComposerSpace).observe(shell);
  window.addEventListener('resize',reserveComposerSpace,{passive:true});
  input.addEventListener('input',reserveComposerSpace);

  function identity(){
    const live=window.STONEFELLOW_CHAT;
    return String(live?.userId||'')===owner&&Number(live?.agentId||0)===agent
      &&String(live?.csrf||'')===csrf&&String(live?.workingAttentionBinding||'')===binding;
  }
  function available(){
    const chat=runtime();
    return identity()&&chat?.isReady?.()&&!chat.isRestoring?.()&&!chat.isBusy?.()
      &&!chat.hasInitiativeReply?.()&&!window.VP3ParticipantContinuity?.selectedContext?.();
  }
  function storageKey(cid){return 'vp3:image-base:'+owner+':'+agent+':'+cid;}
  function forget(cid){try{sessionStorage.removeItem(storageKey(cid));}catch{}}
  function remember(){
    if(!selection)return;
    try{sessionStorage.setItem(storageKey(selection.conversationId),JSON.stringify({assetId:selection.asset.id,binding}));}catch{}
  }
  function message(text){toolStatus.textContent=text||'';}
  function referenceMessage(text){referenceStatus.textContent=text||'';reserveComposerSpace();}
  function safeAsset(asset){
    if(!asset||!Number.isSafeInteger(asset.id)||asset.id<1||asset.media_type!=='photo'||!asset.available)return null;
    try{
      const url=new URL(asset.url,location.href);
      const expected=new URL(cfg.mediaFileEndpoint,location.href);
      if(url.origin!==location.origin||url.username||url.password||url.hash||url.pathname!==expected.pathname||url.searchParams.getAll('id').length!==1||url.searchParams.get('id')!==String(asset.id)
        ||[...url.searchParams.keys()].some(key=>key!=='id'))return null;
      return {...asset,url:url.href};
    }catch{return null;}
  }
  function currentOffer(offer){
    try{return offeredImage===offer&&identity()&&offer.conversationId===currentId()
      &&offer.agentId===agent&&offer.ownerKey===owner&&!window.VP3ParticipantContinuity?.selectedContext?.()
      &&offer.isCurrent()===true;}catch{return false;}
  }
  function retireOffer(viewToken){
    if(!offeredImage||offeredImage.viewToken!==viewToken)return false;
    if(operation?.kind==='selection'&&operation.offer===offeredImage){operation.controller.abort();operation=null;}
    offeredImage=null;referenceMessage('');return true;
  }
  function renderChoice(){
    const focused=choice.contains(document.activeElement);
    if(offeredImage&&!currentOffer(offeredImage))retireOffer(offeredImage.viewToken);
    choice.replaceChildren();
    const offer=offeredImage,pending=Boolean(offer&&operation?.kind==='selection'&&operation.offer===offer);
    choice.hidden=!offer||!pending&&selection?.asset.id===offer.asset.id;
    if(choice.hidden)return;
    const preview=make('img');preview.src=offer.asset.url;preview.alt='Viewed image available as an editing base';preview.width=48;preview.height=48;
    const text=make('div');text.append(make('strong',pending?'Checking selected image…':offer.count>1?'Image '+(offer.index+1)+' of '+offer.count:'Viewed image'),make('span',offer.asset.title||'Saved image'));
    const use=make('button',pending?'Cancel':selection?'Replace base image':'Use this image');use.type='button';
    if(pending)use.setAttribute('aria-label','Cancel image selection');
    use.disabled=Boolean(operation&&!pending);
    use.addEventListener('click',event=>{
      if(!event.isTrusted||!currentOffer(offer))return;
      if(pending&&operation?.offer===offer){operation.controller.abort();operation=null;render();referenceMessage('Image selection cancelled. Your draft is kept.');}
      else void attachOfferedImage(offer);
    });
    choice.append(preview,text,use);choice.setAttribute('aria-busy',String(pending));
    if(focused)use.focus({preventScroll:true});
  }
  function render(){
    reserveComposerSpace();
    renderChoice();
    chip.replaceChildren();chip.hidden=!selection&&(!operation||operation.kind==='selection');
    if(chip.hidden)return;
    if(selection){
      const preview=make('img');preview.src=selection.asset.url;preview.alt='Selected base image';preview.width=48;preview.height=48;
      const text=make('div');text.append(make('strong','Base image'),make('span',selection.asset.title||'Uploaded image'));
      chip.append(preview,text);
    }else if(operation){
      const shimmer=make('span',null,'vp3-image-upload-indicator');shimmer.setAttribute('aria-hidden','true');
      const text=make('div');text.append(make('strong',operation.label),make('span',operation.name||''));
      const progress=make('progress');progress.max=100;progress.value=operation.progress;progress.setAttribute('aria-label','Base image upload progress');
      text.append(progress);chip.append(shimmer,text);
    }
    const remove=make('button','×');remove.type='button';remove.setAttribute('aria-label',selection?'Remove base image':'Cancel base image upload');
    remove.addEventListener('click',()=>{clear(true);referenceMessage('');input.focus();});chip.append(remove);
  }
  function clear(removeSaved=false){
    const cid=selection?.conversationId||operation?.conversationId||currentId();
    revision++;operation?.controller.abort();operation=null;selection=null;
    if(removeSaved&&cid>0)forget(cid);
    file.value='';referenceMessage('');render();
  }
  function owned(op){
    return operation===op&&!op.controller.signal.aborted&&identity()&&currentId()===op.conversationId;
  }
  async function jsonRequest(endpoint,body,op,{multipart=false}={}){
    const headers=multipart?{'X-VP3-Media-Owner':owner}:{'Content-Type':'application/json','X-VP3-Working-Attention':binding};
    const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers,
      body:multipart?body:JSON.stringify(body),signal:op.controller.signal});
    const data=await response.json().catch(()=>({ok:false,error:'The image response could not be read.'}));
    if(!owned(op))throw new DOMException('Chat changed.','AbortError');
    if(!response.ok||!data.ok)throw new Error(data.error||'The image could not be attached.');
    return data;
  }
  async function validate(assetId,op){
    const data=await jsonRequest(cfg.endpoint,{action:'reference',csrf_token:csrf,agent_id:agent,
      conversation_id:op.conversationId,asset_id:assetId},op);
    const asset=safeAsset(data.asset);
    if(!asset||asset.id!==assetId||data.reference?.asset_id!==asset.id)throw new Error('The base image could not be verified.');
    return asset;
  }
  async function attachOfferedImage(offer){
    if(!currentOffer(offer))return false;
    if(!available()||operation){referenceMessage('Finish the current Chat action before attaching this image.');return false;}
    const op={kind:'selection',offer,conversationId:offer.conversationId,controller:new AbortController()};
    operation=op;referenceMessage('');render();
    const timer=setTimeout(()=>{op.timedOut=true;op.controller.abort();},30000);
    try{
      const asset=await validate(offer.asset.id,op);
      if(!owned(op)||!currentOffer(offer)||!available()){
        if(operation===op){operation=null;render();}return false;
      }
      selection={asset,conversationId:offer.conversationId,selectionId:++revision};operation=null;remember();render();
      referenceMessage('Base image attached. Your draft is kept.');input.focus({preventScroll:true});return true;
    }catch(error){
      if(operation===op){
        operation=null;render();
        if(currentOffer(offer))referenceMessage(op.timedOut?'The image check timed out. Your current base and draft are kept.':error.name==='AbortError'?'Image selection cancelled. Your draft is kept.':error.message);
      }
      return false;
    }finally{clearTimeout(timer);}
  }
  async function uploadFile(chosen){
    if(!chosen)return;
    if(!available()||operation){message('Finish the current Chat action before attaching an image.');return;}
    if(!['image/png','image/jpeg','image/webp'].includes(chosen.type)||chosen.size<1||chosen.size>limit){
      message('Choose a PNG, JPG or WebP image up to 4 MB.');file.value='';return;
    }
    if(selection){message('Remove the current base image before choosing another.');file.value='';return;}
    const startRevision=revision;
    let cid=currentId();
    if(!cid){try{cid=await runtime()?.ensureConversation?.();}catch(error){message(error.message);return;}}
    if(!cid||revision!==startRevision||!available()||currentId()!==cid){message('Wait for Chat to finish opening, then choose your image.');return;}
    const op={conversationId:cid,controller:new AbortController(),label:'Uploading base image…',name:chosen.name,progress:0};
    operation=op;message('');referenceMessage('');render();
    const timer=setTimeout(()=>op.controller.abort(),90000);
    const post=async(action,fields={})=>{
      const body=new FormData();body.set('action',action);body.set('csrf_token',csrf);
      for(const [key,value] of Object.entries(fields))body.set(key,value);
      return jsonRequest(cfg.uploadEndpoint,body,op,{multipart:true});
    };
    try{
      const begin=await post('begin',{media_type:'photo',mime_type:chosen.type,title:chosen.name,
        original_name:chosen.name,source:'chat_image_reference'});
      if(!/^[a-f0-9]{32}$/.test(begin.token))throw new Error('The upload could not be started.');
      const size=Math.min(1024*1024,Number.isSafeInteger(begin.chunk_size)&&begin.chunk_size>0?begin.chunk_size:1024*1024);
      const total=Math.ceil(chosen.size/size);
      for(let index=0;index<total;index++){
        await post('chunk',{token:begin.token,index:String(index),total_chunks:String(total),
          chunk:chosen.slice(index*size,Math.min(chosen.size,(index+1)*size),chosen.type)});
        op.progress=Math.round(Math.min(chosen.size,(index+1)*size)/chosen.size*100);render();
      }
      op.label='Checking base image…';render();
      const finished=await post('finish',{token:begin.token});
      if(!Number.isSafeInteger(finished.asset?.id))throw new Error('The uploaded image could not be verified.');
      const asset=await validate(finished.asset.id,op);
      if(!owned(op))return;
      selection={asset,conversationId:cid,selectionId:++revision};operation=null;remember();render();
      message('Base image attached. Describe the image or changes you want in Chat.');
      document.getElementById('chatToolsPanel')?.querySelector('[aria-label="Close chat tools"]')?.click();
      input.focus();
    }catch(error){
      if(operation===op){operation=null;render();message(error.name==='AbortError'?'The upload stopped. Your prompt is kept.':error.message);}
    }finally{clearTimeout(timer);file.value='';}
  }
  async function restore(){
    const cid=currentId(),key=storageKey(cid);
    if(cid<1||!identity()||selection||operation||restoreKey===key)return;
    restoreKey=key;
    let saved;try{saved=JSON.parse(sessionStorage.getItem(key)||'null');}catch{}
    if(!saved)return;
    if(saved.binding!==binding||!Number.isSafeInteger(saved.assetId)||saved.assetId<1){forget(cid);return;}
    const op={conversationId:cid,controller:new AbortController(),label:'Restoring base image…',name:'',progress:100};
    operation=op;render();
    try{
      const asset=await validate(saved.assetId,op);
      if(!owned(op))return;
      selection={asset,conversationId:cid,selectionId:++revision};operation=null;render();
    }catch(error){
      if(operation===op){operation=null;forget(cid);render();message('The previous base image is unavailable. Choose it again to continue.');}
    }
  }
  upload.addEventListener('click',()=>{if(available()&&!operation)file.click();else message('Finish the current Chat action before attaching an image.');});
  file.addEventListener('change',()=>void uploadFile(file.files?.[0]));
  window.addEventListener('stonefellow:chat-context-changing',()=>{if(offeredImage)retireOffer(offeredImage.viewToken);clear();restoreKey='';message('');referenceMessage('');});
  for(const name of ['vp3:participant-selection-changed','vp3:participant-task-context']){
    window.addEventListener(name,()=>{if(offeredImage)retireOffer(offeredImage.viewToken);clear(true);restoreKey='';message('');referenceMessage('');});
  }
  window.addEventListener('vp3:chat-context-ready',()=>{if(selection&&selection.conversationId!==currentId())clear();void restore();});
  window.addEventListener('pagehide',()=>operation?.controller.abort(),{once:true});
  window.VP3ImageComposer=Object.freeze({
    offerImage(asset,{conversationId,agentId,ownerKey,viewToken,index=0,count=1,isCurrent}={}){
      if(!viewToken||typeof isCurrent!=='function'||!identity()||conversationId!==currentId()||agentId!==agent
        ||String(ownerKey)!==owner||!Number.isInteger(index)||!Number.isInteger(count)||count<1||count>12||index<0||index>=count)return false;
      const photo=safeAsset(asset);
      if(!photo||!['image/jpeg','image/png','image/webp'].includes(photo.mime_type)){
        if(retireOffer(viewToken))render();return false;
      }
      try{if(isCurrent()!==true)return false;}catch{return false;}
      if(offeredImage?.viewToken===viewToken&&offeredImage.asset.id===photo.id&&currentOffer(offeredImage))return true;
      if(offeredImage)retireOffer(offeredImage.viewToken);
      offeredImage={asset:photo,conversationId,agentId,ownerKey:String(ownerKey),viewToken,index,count,isCurrent};
      referenceMessage('');render();return true;
    },
    clearImageOffer(viewToken){if(retireOffer(viewToken))render();},
    requestContext(cid){
      if(operation)throw new Error('Wait for the base image to finish attaching before sending your prompt.');
      if(!selection)return null;
      if(window.VP3ParticipantContinuity?.selectedContext?.()){
        clear(true);throw new Error('Use your private owner Chat for this base image.');
      }
      if(!identity()||selection.conversationId!==cid){clear();throw new Error('The image attachment belongs to a different Chat. Choose it again.');}
      return {assetId:selection.asset.id,conversationId:cid,selectionId:selection.selectionId};
    },
    consume(result,reference){
      if(!reference||!selection||reference.selectionId!==selection.selectionId||reference.assetId!==selection.asset.id
        ||Number(result?.conversation_id)!==reference.conversationId||Number(result?.agent_id)!==agent||!identity())return;
      const receipt=result.tool_receipt;
      if(receipt?.contract==='vp3.tool-receipt.v1'&&receipt.capability==='images.generate'
        &&receipt.operation==='queue'&&receipt.status==='completed'
        &&Number.isSafeInteger(receipt.record_id)&&receipt.record_id>0
        &&Array.isArray(result.canvas_media?.generation_ids)&&result.canvas_media.generation_ids.length===1
        &&/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(result.canvas_media.generation_ids[0])){
        clear(true);message('');
      }
    },
    async stagePrompt(prompt,{count=1,referenceAssetId=0,conversationId=currentId()}={}){
      if(typeof prompt!=='string'||!prompt.trim()||prompt.length>4000||!Number.isInteger(count)||count<1||count>4
        ||!Number.isSafeInteger(referenceAssetId)||referenceAssetId<0||conversationId!==currentId()
        ||!available()||operation||selection)return false;
      const text='Generate '+count+' '+(count===1?'image':'images')+': '+prompt.trim();
      if(input.value.trim()&&input.value!==text)return false;
      let asset=null;
      if(referenceAssetId){
        const op={conversationId,controller:new AbortController(),label:'Attaching base image…',name:'',progress:100};
        operation=op;render();
        try{asset=await validate(referenceAssetId,op);}
        catch(error){if(operation===op){operation=null;render();message(error.message);}return false;}
        if(!owned(op))return false;
        operation=null;render();
      }
      if(!available()||currentId()!==conversationId||!runtime()?.stageSpecialistPrompt?.(text,conversationId))return false;
      if(asset){selection={asset,conversationId,selectionId:++revision};remember();referenceMessage('');render();}
      return true;
    }
  });
  void restore();
})();
