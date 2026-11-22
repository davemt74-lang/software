(() => {
  'use strict';
  function mount({shell,thread,form,cfg,showPanel,notice}){
    const privateConfig=cfg.ownerImage;
    if(!privateConfig||typeof privateConfig.endpoint!=='string'||typeof privateConfig.csrf_token!=='string'
      ||typeof privateConfig.owner_key!=='string')return null;
    const input=form.querySelector('textarea'),submit=form.querySelector('button[type="submit"]');
    if(!input||!submit)return null;
    const text=(tag,value,cls='')=>{const el=document.createElement(tag);if(cls)el.className=cls;if(value!==undefined)el.textContent=value;return el;};
    const chip=text('div',undefined,'profile-owner-image-reference');chip.hidden=true;chip.dataset.profileOwnerImageReference='';
    const refPreview=text('img'),refTitle=text('span'),remove=text('button','×');
    remove.type='button';remove.setAttribute('aria-label','Remove selected reference image');
    chip.append(refPreview,refTitle,remove);
    form.querySelector('.profile-agent-toolbar')?.before(chip);
    let reference=null,busy=false,stopped=false,pending=null,poll=null,activeCard=null,activeUser=null,requestEpoch=0;
    const storageKey='vp3:profile-owner-image:'+privateConfig.owner_key+':'+cfg.username;
    const active=()=>!stopped&&shell.isConnected&&form.isConnected;
    const allowUrl=(value,pathname)=>{try{
      const url=new URL(value,window.location.href);
      return url.origin===location.origin&&url.pathname.endsWith(pathname)&&!url.username&&!url.password&&!url.hash?url.href:null;
    }catch{return null;}};
    const validRef=value=>{
      if(!value||value.username!==cfg.username)return null;
      const id=value.image_id,collection=value.catalog_id,revision=value.catalog_revision;
      if(![id,collection,revision].every(v=>Number.isSafeInteger(v)&&v>0))return null;
      const url=allowUrl(value.preview_url,'/api/profile-agent.php');
      if(!url)return null;
      const u=new URL(url);
      if(u.searchParams.get('action')!=='reference_image'||u.searchParams.get('username')!==cfg.username||
         u.searchParams.get('catalog_id')!==String(collection)||u.searchParams.get('catalog_revision')!==String(revision)||
         u.searchParams.get('image_id')!==String(id))return null;
      return {username:cfg.username,image_id:id,catalog_id:collection,catalog_revision:revision,
        preview_url:url,title:String(value.title||'Selected reference').slice(0,160)};
    };
    function composer(){
      input.disabled=false; // Owner preview accepts prompts locally, never visitor messages.
      input.placeholder=reference?'Use this image. Enter your image description…':'Select an image from Image collection to generate privately…';
      submit.disabled=busy||!reference;
      submit.title=reference?'Generate image using selected reference':'Select a reference image';
      chip.hidden=!reference;chip.replaceChildren();
      if(reference){
        refPreview.src=reference.preview_url;refPreview.alt='Selected image reference';
        refTitle.textContent=reference.title+' · Using as reference';
        remove.disabled=busy;
        chip.append(refPreview,refTitle,remove);
      }
    }
    function select(value){
      if(!active()||busy){notice('Finish the current image request before switching references.');return false;}
      if(value!==null&&!validRef(value)){notice('The selected image is unavailable. Refresh Image collection.');return false;}
      reference=value===null?null:validRef(value);
      composer();
      showPanel(null,false);
      if(reference){notice('Reference selected. Describe the new image, then send. Your visitor conversations remain separate.');input.focus({preventScroll:true});}
      else notice('Choose a collection image to start a private image request.');
      return true;
    }
    remove.addEventListener('click',()=>select(null));
    function appendMessage(role,prompt,preview){
      const item=text('div',undefined,'profile-agent-message '+role);
      item.dataset.profileOwnerImageMessage=role;
      const head=text('strong',role==='visitor'?'You · private image request':cfg.agentName||'Image Agent','profile-agent-message-speaker');
      item.append(head,text('span',prompt,'profile-agent-message-body'));
      if(preview){
        const img=text('img');img.alt='Image used as a visual reference';img.src=preview;
        img.className='profile-owner-image-thumb';item.append(img);
      }
      thread.append(item);thread.scrollTop=thread.scrollHeight;
      return item;
    }
    function progress(card,stage='queued',detail=''){
      card.classList.add('profile-owner-image-job');
      card.replaceChildren(text('strong',cfg.agentName||'Image Agent','profile-agent-message-speaker'));
      const wrapper=text('div',undefined,'vp3-image-request');
      wrapper.dataset.activity=stage==='completed'||stage==='failed'?'idle':'active';
      const panel=text('div',undefined,'vp3-image-progress');panel.dataset.count='1';
      const art=text('div',undefined,'vp3-image-progress-art');art.setAttribute('aria-hidden','true');
      const frame=text('span',undefined,'vp3-image-progress-frame');frame.style.setProperty('--image-index','0');art.append(frame);
      const copy=text('div',undefined,'vp3-image-progress-copy');
      copy.append(text('strong',stage==='queued'?'Preparing your image…':stage==='generating'?'Creating your image…':'Checking your image request…'),
        text('p',detail||'Using the selected reference. Your request is saved privately.'));
      const stages=text('ol',undefined,'vp3-image-progress-stages');stages.setAttribute('aria-label','Image creation progress');
      const index=stage==='queued'?0:1;
      ['Queued','Creating','Ready'].forEach((label,i)=>{const part=text('li',label);if(i===index)part.setAttribute('aria-current','step');if(i<index)part.dataset.complete='true';stages.append(part);});
      panel.append(art,copy,stages);wrapper.append(panel);card.append(wrapper);
      thread.scrollTop=thread.scrollHeight;
    }
    function assetUrl(asset){
      if(!asset||asset.available!==true||asset.mime_type!=='image/png'
        ||!Number.isSafeInteger(asset.id)||asset.id<1)return null;
      const url=allowUrl(asset.url,'/media-library-file-v86.php');if(!url)return null;
      const parsed=new URL(url);
      if(parsed.searchParams.get('id')!==String(asset.id)||[...parsed.searchParams.keys()].some(k=>k!=='id'))return null;
      return url;
    }
    function completed(card,generation){
      const assets=Array.isArray(generation.assets)&&generation.assets.length?generation.assets:[generation.asset];
      const valid=assets.map(asset=>({asset,url:assetUrl(asset)}));
      if(!valid.length||valid.some(item=>!item.url))throw new Error('The image finished, but the saved file is unavailable. Open Media Library to verify it.');
      card.classList.add('profile-owner-image-job');
      card.replaceChildren(text('strong',cfg.agentName||'Image Agent','profile-agent-message-speaker'),text('p','Your image is ready and saved privately.'));
      const gallery=text('div',undefined,'profile-owner-image-results');
      for(const {asset,url} of valid){
        const link=text('a');link.href=url;link.target='_blank';link.rel='noopener noreferrer';link.dataset.profileOwnerImageResult=String(asset.id);
        link.setAttribute('aria-label','View generated image '+(asset.title||''));const img=text('img');
        img.src=url;img.alt=asset.title||'Generated image';img.loading='lazy';link.append(img);gallery.append(link);
      }
      card.append(gallery);
      const actions=text('div',undefined,'profile-owner-image-actions');
      const library=text('a','Manage in Media Library');library.href=privateConfig.library_url;actions.append(library);
      const catalog=text('a','Add to image collection');catalog.href=cfg.imageSettingsUrl;actions.append(catalog);card.append(actions);
      thread.scrollTop=thread.scrollHeight;
    }
    function failed(card,message,terminal=false){
      card.classList.add('profile-owner-image-job');
      card.replaceChildren(text('strong','Image request needs attention','profile-agent-message-speaker'),
        text('p',String(message||'The image request could not be confirmed.').slice(0,350)));
      const action=text('button',terminal?'Start a new image request':pending?.request_key||pending?.id?'Check saved image request':'Retry image request');
      action.type='button';action.dataset.profileOwnerImageRetry='';
      action.addEventListener('click',()=>{
        if(terminal){
          const draft=pending?.prompt||input.value;
          pending=null;persist();done();input.value=draft;input.focus({preventScroll:true});
          notice('The previous request ended. Review your description before sending a new image request.');
        }else if(pending?.id)void follow(pending.id,card,true);
        else if(pending)void submitSaved(card,true);
      });
      card.append(action);thread.scrollTop=thread.scrollHeight;
    }
    function api(action,fields={},signal){
      const endpoint=allowUrl(privateConfig.endpoint,'/api/media-library-image-v1.php');
      if(!endpoint)throw new Error('The private image service address has changed. Reload your Profile.');
      return fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action,csrf_token:privateConfig.csrf_token,owner_key:privateConfig.owner_key,...fields}),
        ...(signal?{signal}:{})}).then(async response=>{
          const result=await response.json().catch(()=>null);
          if(!response.ok||result?.ok!==true)throw new Error(result?.error||'The private image service could not confirm the request.');
          return result;
        });
    }
    function persist(){try{
      if(pending?.id)sessionStorage.setItem(storageKey,JSON.stringify({id:pending.id,prompt:pending.prompt,reference:pending.reference}));
      else sessionStorage.removeItem(storageKey);
    }catch{}}
    function done(){busy=false;composer();}
    async function follow(id,card,requested=false){
      if(!active()||!pending||pending.id!==id)return;
      const epoch=requestEpoch;
      try{
        // Canonical tick may complete work synchronously. No automatic provider
        // replay is performed: the saved generation id is stable and one-use.
        const read=await api(requested?'tick':'get',{generation_id:id});
        if(epoch!==requestEpoch||!active())return;
        const g=read.generation;if(!g||g.id!==id)throw new Error('The saved generation could not be verified.');
        if(g.status==='completed'){
          completed(card,g);pending=null;persist();done();
          notice('Image ready. Saved in your private Media Library.');return;
        }
        if(['failed','cancelled','file_unavailable'].includes(g.status)){
          failed(card,g.message||'The image request could not be completed. No new request was started.',true);
          // Keep the reference and typed instructions available, but do not
          // silently generate again after a completed/unknown provider call.
          done();notice(g.message||'Image request failed. Review the saved result before trying again.');
          return;
        }
        if(!['queued','generating','paused'].includes(g.status))throw new Error('Unexpected image job state.');
        progress(card,g.status,g.status==='paused'?'This image job is paused. Your saved request is still available.':'Your image is being generated. The page will check its saved status.');
        const scheduled=requestEpoch;
        poll=window.setTimeout(()=>{if(active()&&scheduled===requestEpoch)void follow(id,card,false);},2500);
      }catch(error){
        if(epoch!==requestEpoch||!active())return;
        failed(card,'The request status could not be checked. It may still be running. Use Check image request instead of creating a duplicate.');
        done();notice(error.message||'Could not verify the saved image request.');
      }
    }
    function plan(){
      return {prompt:pending.prompt,title:'Profile image: '+pending.prompt.slice(0,150),
        size:'1024x1024',quality:'medium',background:'opaque',
        profile_reference:{username:pending.reference.username,catalog_id:pending.reference.catalog_id,
          catalog_revision:pending.reference.catalog_revision,image_id:pending.reference.image_id}};
    }
    async function submitSaved(card,recheck=false){
      if(!pending||!active())return;
      if(busy&&!recheck)return;
      const epoch=++requestEpoch;
      busy=true;composer();progress(card,'queued');
      try{
        if(!pending.request_key){
          const reviewed=await api('preview',plan());
          if(!active()||epoch!==requestEpoch)return;
          if(!/^[a-f0-9]{32}$/.test(reviewed.request_key)||typeof reviewed.review_token!=='string')
            throw new Error('The image review token could not be verified.');
          pending.request_key=reviewed.request_key;pending.review_token=reviewed.review_token;
        }
        const accepted=await api('submit',{...plan(),request_key:pending.request_key,
          review_token:pending.review_token,confirmed:true});
        if(!active()||epoch!==requestEpoch)return;
        if(!/^[a-f0-9-]{36}$/.test(accepted.generation?.id))
          throw new Error('Image request acceptance could not be verified. Check the same request before retrying.');
        pending.id=accepted.generation.id;persist();
        input.value=''; // Clear the draft only AFTER server acceptance.
        progress(card,accepted.generation.status||'queued');
        await follow(pending.id,card,true);
      }catch(error){
        if(!active()||epoch!==requestEpoch)return;
        failed(card,error.message||'Image generation could not be started.');
        // Keep the prompt, reference and same review key for an explicit safe
        // retry. A transport error after admission must NOT create a new job.
        input.value=pending.prompt;done();notice(error.message||'Image request not confirmed.');
      }
    }
    async function send(event){
      event.preventDefault();
      if(!active()||busy||!reference)return;
      const prompt=input.value.trim();
      if([...prompt].length<3||[...prompt].length>2000){notice('Enter an image description between 3 and 2,000 characters.');input.focus();return;}
      if(pending?.id){notice('Your previous image request is unresolved. Check its status before generating another.');return;}
      if(pending?.request_key){notice('Check the previous image request first to avoid an accidental duplicate.');return;}
      if(poll)window.clearTimeout(poll);
      pending={prompt,reference:{...reference},id:null,request_key:null,review_token:null};
      activeUser=appendMessage('visitor',prompt,reference.preview_url);
      activeCard=appendMessage('agent','');
      await submitSaved(activeCard);
    }
    form.addEventListener('submit',event=>{void send(event);});
    input.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();form.requestSubmit();}});
    window.addEventListener('pagehide',()=>{stopped=true;requestEpoch++;if(poll)window.clearTimeout(poll);},{once:true});
    composer();
    // Reopen a previously admitted job, never start another generation after
    // reload. The server enforces current owner/session authority on the read.
    try{
      const saved=JSON.parse(sessionStorage.getItem(storageKey)||'null');
      if(saved&&/^[a-f0-9-]{36}$/.test(saved.id||'')&&typeof saved.prompt==='string'){
        const restored=validRef(saved.reference);
        if(restored){
          reference=restored;pending={id:saved.id,prompt:saved.prompt,reference:restored};
          composer();activeUser=appendMessage('visitor',saved.prompt,restored.preview_url);
          activeCard=appendMessage('agent','');progress(activeCard,'queued','Restoring the saved private image request…');
          busy=true;composer();void follow(saved.id,activeCard,false);
        }
      }
    }catch{}
    return {select,selected:()=>reference,active:()=>busy,clear:()=>select(null)};
  }
  window.VP3ProfileImageOwnerPreviewV1={mount};
})();