(() => {
  'use strict';
  const contract='vp3.profile-image-edit.v1';
  const positive=value=>Number.isSafeInteger(value)&&value>0;
  const text=(value,limit)=>typeof value==='string'&&Array.from(value).length<=limit;
  const requestId=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
  const node=(tag,value,className='')=>{const element=document.createElement(tag);if(value!==undefined)element.textContent=value;if(className)element.className=className;return element;};
  const button=(label,handler)=>{const element=node('button',label,'profile-agent-panel-action');element.type='button';element.addEventListener('click',handler);return element;};

  function mount({shell,thread,form,cfg,context,registerPanel,showPanel,onDraft,notice,focusComposer}){
    let service=null,draft=null,recovery=null,blobUrl='',busy=false,sequence=0,jobs=new Map(),stopped=false;
    const openButtons=[...shell.querySelectorAll('[data-profile-image-edit-open]')];
    const shareButton=shell.querySelector('[data-profile-image-share-owner]');
    const chip=node('div',undefined,'profile-image-draft');chip.hidden=true;chip.dataset.profileImageDraft='';
    form.querySelector('.profile-agent-toolbar')?.before(chip);
    const panel=node('section',undefined,'profile-agent-panel profile-image-panel');panel.hidden=true;panel.dataset.profileImagePanel='';
    panel.id='profileImageEditPanel';panel.setAttribute('aria-labelledby','profileImageEditTitle');
    const heading=node('h2','Edit photo with AI');heading.id='profileImageEditTitle';heading.tabIndex=-1;
    const intro=node('p','Choose a free style and the photo you want to edit. Describe your changes in the chat composer.');
    const catalogLabel=node('label',undefined,'profile-image-field'),catalogSelect=node('select');
    catalogSelect.setAttribute('aria-label','Style catalog');catalogLabel.append(node('span','Style catalog'),catalogSelect);
    const imageLabel=node('label',undefined,'profile-image-field'),imageSelect=node('select');
    imageSelect.setAttribute('aria-label','Style reference');imageLabel.append(node('span','Style reference'),imageSelect);
    const preview=node('img');preview.alt='Photo selected for AI editing';preview.hidden=true;
    const sourceLabel=node('p','Choose a JPEG, PNG or WebP photo up to 4 MB.');sourceLabel.dataset.profileImageSource='';
    const feedback=node('p');feedback.setAttribute('role','status');
    const choose=button('Choose photo',()=>choosePhoto());choose.dataset.profileImageChoose='';
    const done=button('Use photo in chat',()=>{
      if(!draft){feedback.textContent='Choose a photo first.';return;}
      try{updateSelection();validateDraft();feedback.textContent='';showPanel(null,false);focusComposer();}
      catch(error){feedback.textContent=error.message;}
    });done.dataset.profileImageUse='';
    const cancel=button('Back to conversation',()=>showPanel(null));
    const actions=node('div',undefined,'profile-agent-panel-actions');actions.append(choose,done,cancel);
    panel.append(heading,intro,catalogLabel,imageLabel,preview,sourceLabel,feedback,actions);
    shell.querySelector('.profile-agent-footer').before(panel);
    const entry={panel,button:null};registerPanel(entry);

    const viewer=node('section',undefined,'profile-agent-panel profile-image-viewer');viewer.hidden=true;viewer.dataset.profileImageViewer='';
    viewer.id='profileImageViewer';viewer.setAttribute('aria-labelledby','profileImageViewerTitle');
    const viewerTitle=node('h2','Edited photo');viewerTitle.id='profileImageViewerTitle';viewerTitle.tabIndex=-1;
    const viewerImage=node('img');viewerImage.alt='Edited photo';
    const viewerActions=node('div',undefined,'profile-agent-panel-actions');
    viewer.append(viewerTitle,viewerImage,viewerActions);shell.querySelector('.profile-agent-footer').before(viewer);
    const viewerEntry={panel:viewer,button:null};registerPanel(viewerEntry);

    function active(){const current=context();return !stopped&&current.active&&!current.sessionChanged;}
    function enabled(){return service?.enabled===true&&service.catalogs.length>0;}
    function guard(){
      const current=context();
      if(!active())throw new Error('Reload this profile before editing a photo.');
      if(current.loading)throw new Error('Wait for the chat to finish loading.');
      if(current.busy||busy)throw new Error('Finish or retry the current chat action before choosing another photo.');
      if(current.human)throw new Error('Return to Agent chat before requesting an AI photo edit.');
      if(!enabled())throw new Error(service?.reason||'Photo editing is unavailable on this profile.');
      return current;
    }
    function validService(value){
      if(value?.contract!==contract||typeof value.enabled!=='boolean'||!Number.isSafeInteger(value.max_input_bytes)
        ||value.max_input_bytes<1||value.max_input_bytes>4194304||!Array.isArray(value.catalogs)||value.catalogs.length>100)return null;
      const catalogs=value.catalogs.filter(item=>positive(item?.collection_id)&&text(item.name,100)&&item.name.trim()
        &&positive(item.catalog_revision)&&Array.isArray(item.references)&&item.references.length>0&&item.references.length<=3
        &&item.references.every(reference=>positive(reference?.asset_id)&&text(reference.title,500)&&reference.price_cents===0));
      return {...value,catalogs};
    }
    function selectedCatalog(){return service?.catalogs.find(item=>item.collection_id===Number(catalogSelect.value));}
    function renderCatalogs(){
      const selected=Number(catalogSelect.value)||draft?.catalog_id;
      catalogSelect.replaceChildren(...(service?.catalogs||[]).map(item=>new Option(item.name,String(item.collection_id))));
      if(service?.catalogs.some(item=>item.collection_id===selected))catalogSelect.value=String(selected);
      renderReferences();
    }
    function renderReferences(){
      const selected=Number(imageSelect.value)||draft?.image_id||0,catalog=selectedCatalog();
      imageSelect.replaceChildren(new Option('Match this catalog’s style','0'),...(catalog?.references||[]).map(item=>new Option(item.title||'Style image',String(item.asset_id))));
      imageSelect.value=catalog?.references.some(item=>item.asset_id===selected)?String(selected):'0';
    }
    function updateSelection(){
      if(!draft)return;
      const catalog=selectedCatalog();
      if(catalog){draft.catalog_id=catalog.collection_id;draft.catalog_revision=catalog.catalog_revision;draft.image_id=Number(imageSelect.value)||0;}
      renderDraft();onDraft();
    }
    catalogSelect.addEventListener('change',()=>{imageSelect.value='0';renderReferences();updateSelection();});
    imageSelect.addEventListener('change',updateSelection);
    function releaseBlob(){if(blobUrl){URL.revokeObjectURL(blobUrl);blobUrl='';}}
    function clearDraft(notify=true){releaseBlob();draft=null;recovery=null;sequence++;renderDraft();if(notify)onDraft();}
    function renderDraft(){
      chip.replaceChildren();chip.hidden=!draft&&!recovery;
      preview.hidden=!draft;preview.removeAttribute('src');
      sourceLabel.textContent=draft?draft.name:'Choose a JPEG, PNG or WebP photo up to 4 MB.';
      if(recovery){
        const label=node('span','Saved AI edit · '+recovery.saved.image_subject.name);
        const restore=button('Choose original photo to retry',()=>reattach());restore.dataset.profileImageReattach='';
        chip.append(label,restore);sync();return;
      }
      if(!draft)return;
      if(draft.preview)preview.src=draft.preview;
      const catalog=service?.catalogs.find(item=>item.collection_id===draft.catalog_id);
      const label=node('span','AI edit · '+draft.name+(catalog?' · '+catalog.name:''));
      const change=button('Change',()=>open(change));change.setAttribute('aria-label','Change AI photo or style');
      const remove=button('Remove',()=>clearDraft());remove.setAttribute('aria-label','Remove AI photo');
      chip.append(label,change,remove);sync();
    }
    function open(trigger){
      try{guard();feedback.textContent='';entry.button=trigger||openButtons[0];renderCatalogs();renderDraft();showPanel(entry);}
      catch(error){notice(error.message);}
    }
    async function fingerprint(file){
      if(typeof crypto!=='object'||!crypto.subtle)throw new Error('Open this profile using HTTPS to verify your photo before editing.');
      const hash=await crypto.subtle.digest('SHA-256',await file.arrayBuffer());
      return Array.from(new Uint8Array(hash),value=>value.toString(16).padStart(2,'0')).join('');
    }
    function choosePhoto(){
      let current;
      try{current=guard();}catch(error){feedback.textContent=error.message;return;}
      const selectedKey=current.key,attempt=++sequence;
      const input=node('input');input.type='file';input.accept='image/jpeg,image/png,image/webp';
      input.addEventListener('change',async()=>{
        const file=input.files?.[0];if(!file||attempt!==sequence)return;
        try{
          const latest=guard();if(latest.key!==selectedKey)throw new Error('The conversation changed. Choose your photo again.');
          if(file.size<1||file.size>service.max_input_bytes)throw new Error('Choose a complete JPEG, PNG or WebP photo up to 4 MB.');
          if(file.type&&!['image/jpeg','image/png','image/webp'].includes(file.type))throw new Error('Choose a JPEG, PNG or WebP photo.');
          const catalog=selectedCatalog();if(!catalog)throw new Error('Choose an available free style catalog.');
          busy=true;sync();feedback.textContent='Checking your photo…';
          const sha256=await fingerprint(file);busy=false;
          if(attempt!==sequence)return;
          const checked=guard();if(checked.key!==selectedKey)throw new Error('The conversation changed. Choose your photo again.');
          releaseBlob();blobUrl=URL.createObjectURL(file);
          draft={upload:file,name:file.name,preview:blobUrl,catalog_id:catalog.collection_id,catalog_revision:catalog.catalog_revision,image_id:Number(imageSelect.value)||0,base_message_id:0,
            image_subject:{sha256,name:file.name,bytes:file.size,mime:file.type||''}};
          feedback.textContent='Photo selected. Add your instructions in the chat before sending.';renderDraft();onDraft();
        }catch(error){feedback.textContent=error.message;}finally{busy=false;sync();}
      },{once:true});input.click();
    }
    function reattach(){
      const saved=recovery?.saved,accept=recovery?.accept,current=context();
      if(!saved||!active()||current.loading||current.sending||busy)return;
      const selectedKey=current.key,attempt=++sequence,input=node('input');input.type='file';input.accept='image/jpeg,image/png,image/webp';
      input.addEventListener('change',async()=>{
        const file=input.files?.[0];if(!file||attempt!==sequence)return;
        try{
          const expected=saved.image_subject;
          if(!active()||context().key!==selectedKey||recovery?.saved.client_request_id!==saved.client_request_id)throw new Error('The conversation changed. Reload before retrying.');
          if(file.name!==expected.name||file.size!==expected.bytes)throw new Error('Choose the same original photo for this saved edit.');
          busy=true;sync();notice('Checking the original photo…');
          const sha256=await fingerprint(file);
          if(attempt!==sequence||!active()||context().key!==selectedKey||recovery?.saved.client_request_id!==saved.client_request_id)return;
          if(sha256!==expected.sha256)throw new Error('This is a different photo. Choose the same original photo to retry the saved edit.');
          await accept(file);
        }catch(error){notice(error.message);}finally{busy=false;sync();}
      },{once:true});input.click();
    }
    function validateDraft(){
      guard();
      if(!draft)throw new Error('Choose the photo you want to edit.');
      const catalog=service.catalogs.find(item=>item.collection_id===draft.catalog_id);
      if(!catalog||catalog.catalog_revision!==draft.catalog_revision
        ||(draft.image_id>0&&!catalog.references.some(item=>item.asset_id===draft.image_id)))throw new Error('This style catalog changed. Choose the style again before sending.');
      return draft;
    }
    function prepare(message){
      const value=validateDraft();
      if(!message.trim())throw new Error('Describe how you want the photo edited.');
      if(Array.from(message).length>2000)throw new Error('Keep the photo instructions within 2,000 characters.');
      return {kind:'image_edit',catalog_id:value.catalog_id,catalog_revision:value.catalog_revision,image_id:value.image_id,
        ...(value.base_message_id?{base_message_id:value.base_message_id}:{}),...(value.upload?{upload:value.upload,image_subject:value.image_subject}:{})};
    }
    function localImageUrl(value,id,previewMode){
      if(!text(value,2000)||!positive(id))return null;
      try{
        const url=new URL(value,window.location.href),endpoint=new URL(cfg.endpoint,window.location.href),current=context();
        const allowed=new Set(['action','username','conversation_namespace','conversation_id','message_id','preview']);
        if(url.origin!==window.location.origin||url.pathname!==endpoint.pathname||url.username||url.password||url.hash
          ||url.searchParams.get('action')!=='image'||url.searchParams.get('username')!==cfg.username
          ||url.searchParams.get('conversation_namespace')!==cfg.conversationNamespace
          ||url.searchParams.get('conversation_id')!==String(current.id)||url.searchParams.get('message_id')!==String(id)
          ||(previewMode?url.searchParams.get('preview')!=='1':url.searchParams.has('preview')&&url.searchParams.get('preview')!=='0')
          ||[...url.searchParams.keys()].some(key=>!allowed.has(key)||url.searchParams.getAll(key).length!==1))return null;
        return url.href;
      }catch(_error){return null;}
    }
    function projection(value,id,type){
      if(!value||!requestId(value.request_id)||!['input','result'].includes(value.kind)
        ||(value.kind==='input'?type!=='visitor':type!=='agent')||!text(value.filename,255)||!value.filename.trim()
        ||!['image/jpeg','image/png','image/webp'].includes(value.mime)||!Number.isSafeInteger(value.bytes)||value.bytes<1||value.bytes>26214400)return null;
      const url=localImageUrl(value.url,id,true),download=localImageUrl(value.download_url,id,false);
      return url&&download?{...value,id,url,download_url:download}:null;
    }
    function reuse(image,trigger){
      try{
        guard();const job=jobs.get(image.request_id);
        if(job?.catalog_id&&service.catalogs.some(item=>item.collection_id===job.catalog_id))catalogSelect.value=String(job.catalog_id);
        renderReferences();if(job?.image_id)imageSelect.value=String(job.image_id);
        const catalog=selectedCatalog();if(!catalog)throw new Error('Choose an available free style catalog.');
        releaseBlob();draft={name:'Previous edited photo',preview:image.url,base_message_id:image.id,catalog_id:catalog.collection_id,catalog_revision:catalog.catalog_revision,image_id:Number(imageSelect.value)||0};
        entry.button=trigger;renderDraft();onDraft();showPanel(entry);feedback.textContent='This edited photo is the new subject. Add your next instructions in the chat.';
      }catch(error){notice(error.message);}
    }
    function showImage(image,trigger){
      viewerEntry.button=trigger;viewerTitle.textContent=image.kind==='result'?'Edited photo':'Original photo';viewerImage.alt=viewerTitle.textContent;
      viewerImage.src=image.url;viewerActions.replaceChildren();
      const download=node('a','Download photo','profile-agent-panel-action');download.href=image.download_url;download.download=image.filename;
      viewerActions.append(download);
      if(image.kind==='result')viewerActions.append(button('Edit this result',()=>reuse(image,trigger)));
      viewerActions.append(button('Back to conversation',()=>showPanel(null)));showPanel(viewerEntry);
    }
    function renderMessage(container,value,id,type){
      const image=projection(value,id,type);if(!image)return;
      const openImage=button(image.kind==='result'?'View edited photo':'View original photo',()=>showImage(image,openImage));
      openImage.className='profile-image-open';openImage.dataset.profileImageResult=image.kind==='result'?String(id):'';
      const thumbnail=node('img');thumbnail.alt=image.kind==='result'?'Edited photo':'Photo supplied for editing';thumbnail.loading='lazy';thumbnail.src=image.url;
      thumbnail.addEventListener('error',()=>{thumbnail.remove();openImage.textContent='Photo preview unavailable';openImage.disabled=true;},{once:true});
      openImage.replaceChildren(thumbnail,node('span',image.kind==='result'?'View edited photo':'View original photo'));container.append(openImage);
    }
    function timestamp(value){
      if(typeof value==='number')return value<1e12?value*1000:value;
      if(typeof value!=='string'||!value)return 0;
      return Date.parse(/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(value)?value.replace(' ','T')+'Z':value)||0;
    }
    function renderStatuses(){
      const now=Date.now()+Number(context().clockOffset||0);
      for(const job of jobs.values()){
        const parent=thread.querySelector(`[data-message-id="${job.input_message_id}"]`);if(!parent)continue;
        let status=parent.querySelector('[data-profile-image-job]');
        if(!status){status=node('div',undefined,'profile-image-job');status.dataset.profileImageJob=job.request_id;status.setAttribute('role','status');parent.append(status);}
        const started=Number(job.started_at_ms)||0,live=job.state==='editing'&&started>0&&timestamp(job.active_lease_expires_at)>now;
        status.dataset.active=String(live);
        let label=({queued:'Queued for photo editing',editing:live?'Editing your photo':'Checking photo edit status',ready:'Edited photo ready',failed:'Photo edit could not be completed',cancelled:'Photo edit cancelled'})[job.state];
        if(live){const seconds=Math.max(0,Math.floor((now-started)/1000));label+=' · '+(seconds<60?seconds+'s':Math.floor(seconds/60)+'m '+seconds%60+'s')+' elapsed';}
        if(['failed','cancelled'].includes(job.state)&&text(job.error,300)&&job.error.trim())label=job.error;
        if(status.textContent!==label)status.textContent=label;
      }
    }
    function update(data){
      if(Object.prototype.hasOwnProperty.call(data,'image_editing')){
        const next=validService(data.image_editing),before=JSON.stringify(service?.catalogs||[]);service=next;
        if(before!==JSON.stringify(service?.catalogs||[]))renderCatalogs();
      }
      if(Array.isArray(data.image_edits)){
        jobs=new Map(data.image_edits.filter(item=>requestId(item?.request_id)&&positive(item.input_message_id)
          &&['queued','editing','ready','failed','cancelled'].includes(item.state)).slice(0,100).map(item=>[item.request_id,item]));
      }
      sync();renderStatuses();
    }
    function attachmentChoice(kind,ownerReady){
      const ai=shell.querySelector('[data-profile-attachment-help] [data-profile-image-edit-open]');
      if(ai)ai.hidden=kind!=='image'||!enabled();
      if(shareButton)shareButton.hidden=kind!=='image'||!ownerReady||!enabled();
    }
    function sync(){
      const current=context(),locked=busy||current.busy||!active();
      openButtons.forEach(open=>{if(!open.closest('[data-profile-attachment-help]'))open.hidden=!enabled();open.disabled=locked;});
      for(const control of [catalogSelect,imageSelect,choose,done,...chip.querySelectorAll('button')])control.disabled=locked;
      const reattachButton=chip.querySelector('[data-profile-image-reattach]');
      if(reattachButton)reattachButton.disabled=busy||!active()||current.loading||current.sending;
      done.disabled=locked||!draft;
      if(!enabled()&&draft&&!locked)feedback.textContent='This style is no longer available. Choose another free style before sending.';
    }
    openButtons.forEach(openButton=>openButton.addEventListener('click',()=>open(openButton)));
    const timer=window.setInterval(()=>{if(!stopped&&active())renderStatuses();},1000);
    return {
      enabled,open,prepare,update,sync,attachmentChoice,renderMessage,renderStatuses,
      hasDraft:()=>draft!==null,
      hasActive:()=>[...jobs.values()].some(job=>['queued','editing'].includes(job.state)),
      accepted:()=>clearDraft(false),clearDraft,
      recover(saved,accept){releaseBlob();draft=null;recovery={saved,accept};renderDraft();},
      rejected(saved){
        if(!recovery)return;
        recovery=null;
        if(saved.upload){releaseBlob();blobUrl=URL.createObjectURL(saved.upload);draft={upload:saved.upload,name:saved.upload.name,preview:blobUrl,
          catalog_id:saved.catalog_id,catalog_revision:saved.catalog_revision,image_id:saved.image_id||0,base_message_id:0,image_subject:saved.image_subject};}
        renderDraft();
      },
      pending:value=>{busy=value;sync();},
      reset(stop=false){clearDraft(false);jobs.clear();service=null;viewerImage.removeAttribute('src');viewerActions.replaceChildren();showPanel(null,false);panel.hidden=viewer.hidden=true;openButtons.forEach(openButton=>{openButton.hidden=true;});if(shareButton)shareButton.hidden=true;if(stop){stopped=true;clearInterval(timer);}sync();}
    };
  }
  window.VP3ProfileImageEditsV1={mount};
})();
