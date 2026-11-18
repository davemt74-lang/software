(function(){
'use strict';
const cfg=window.VP3_CANVAS_MEDIA_V1||{};if(!cfg.endpoint||!cfg.csrf)return;
const states=new WeakMap(),started=new Set(),running=new Set(),recovering=new Set();
const orbJobs=new Map();
const specialistJobs=new Map();
const progressClocks=new Map();
let imageView=null,pendingImageView=null,imageViewTicket=0;
const inProgress={queued:'Queued · waiting for Cloud worker',generating:'Generating image…',paused:'Paused'};
const make=(tag,text)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=String(text);return e;};
const continuity=()=>window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
function scope(){return Number(continuity()?.conversationId?.()??window.STONEFELLOW_CHAT?.initialConversationId??0);}
function selectedAgent(){return Number(continuity()?.agentId?.()??cfg.agentId??0);}
async function api(action,payload,s){const r=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:cfg.csrf,action,conversation_id:s.cid,agent_id:s.agentId,...payload})});let data;try{data=await r.json();}catch(_){throw new Error('Media status was not confirmed. Refresh its status before requesting another image.');}if(!r.ok||!data.ok){const e=new Error(data.error||'Media unavailable.');e.status=r.status;throw e;}return data;}
function ownUrl(value,path){try{const u=new URL(value,location.href);return u.origin===location.origin&&u.pathname.endsWith(path)&&!u.username&&!u.password?u.href:'';}catch(_){return '';}}
function photoAssets(assets){
 const seen=new Set();
 return (Array.isArray(assets)?assets:[]).filter(asset=>{
  if(!asset||!Number.isSafeInteger(asset.id)||asset.id<1||seen.has(asset.id)||asset.available!==true||asset.media_type!=='photo')return false;
  const address=ownUrl(asset.url,'/media-library-file-v86.php');
  if(!address||new URL(address).searchParams.get('id')!==String(asset.id))return false;
  seen.add(asset.id);return true;
 });
}
function canViewImages(s){return active(s)&&chatVisible()&&!window.VP3ParticipantContinuity?.selectedContext?.();}
function closeImages(restoreFocus=false){
 ++imageViewTicket;
 if(pendingImageView){pendingImageView.host.remove();pendingImageView=null;}
 if(!imageView)return;
 const previous=imageView;imageView=null;
 previous.panel.remove();previous.gallery.hidden=false;
 previous.s.host.classList.remove('vp3-canvas-media-viewing');
 if(previous.external){states.delete(previous.s.host);previous.s.host.remove();}
 if(restoreFocus){const target=previous.focusReturn?.isConnected?previous.focusReturn:document.getElementById('chatInput');target?.focus({preventScroll:true});}
}
function openGallery(s,gallery,assets,index,focusReturn,external=false){
 if(!canViewImages(s)||!assets.length)return false;
 closeImages(false);
 const panel=make('section');panel.className='vp3-image-viewer';panel.setAttribute('role','region');panel.setAttribute('aria-label','Image viewer');panel.tabIndex=-1;
 const header=make('header'),back=make('button',external?'Back to Chat':'Back to images');back.type='button';back.className='vp3-image-viewer-back';back.addEventListener('click',()=>closeImages(true));
 const position=make('span');position.className='vp3-image-viewer-position';position.setAttribute('aria-live','polite');
 const navigation=make('div');navigation.className='vp3-image-viewer-navigation';
 const previous=make('button','←'),next=make('button','→');
 previous.type=next.type='button';previous.setAttribute('aria-label','Previous image');next.setAttribute('aria-label','Next image');
 navigation.append(previous,position,next);header.append(back,navigation);
 const frame=make('figure');frame.className='vp3-image-viewer-frame';
 const picture=make('img');picture.decoding='async';const title=make('figcaption');frame.append(picture,title);
 const thumbnails=make('div');thumbnails.className='vp3-image-viewer-thumbnails';thumbnails.setAttribute('role','group');thumbnails.setAttribute('aria-label','Choose an image');
 const view={s,panel,gallery,assets,index:0,focusReturn,external};
 const choose=chosen=>{
  if(imageView!==view||!canViewImages(s))return;
  view.index=Math.max(0,Math.min(assets.length-1,chosen));const asset=assets[view.index];
  picture.src=ownUrl(asset.url,'/media-library-file-v86.php');picture.alt=String(asset.title||'Generated image');title.textContent=String(asset.title||'');
  position.textContent=(view.index+1)+' / '+assets.length;previous.disabled=view.index===0;next.disabled=view.index===assets.length-1;
  for(const [i,button] of Array.from(thumbnails.children).entries())button.setAttribute('aria-pressed',String(i===view.index));
 };
 previous.addEventListener('click',()=>choose(view.index-1));next.addEventListener('click',()=>choose(view.index+1));
 for(const [i,asset] of assets.entries()){
  const button=make('button');button.type='button';button.setAttribute('aria-label','View image '+(i+1));
  const thumb=make('img');thumb.src=ownUrl(asset.url,'/media-library-file-v86.php');thumb.alt='';thumb.loading='lazy';button.append(thumb);button.addEventListener('click',()=>choose(i));thumbnails.append(button);
 }
 navigation.hidden=assets.length<2;thumbnails.hidden=assets.length<2;
 panel.addEventListener('keydown',event=>{
  if(event.key==='Escape'){event.preventDefault();closeImages(true);}
  else if(assets.length>1&&['ArrowLeft','ArrowRight'].includes(event.key)){event.preventDefault();choose(view.index+(event.key==='ArrowRight'?1:-1));}
 });
 let touch=null;
 frame.addEventListener('pointerdown',event=>{if(event.pointerType==='touch')touch={x:event.clientX,y:event.clientY};});
 frame.addEventListener('pointerup',event=>{if(!touch)return;const dx=event.clientX-touch.x,dy=event.clientY-touch.y;touch=null;if(Math.abs(dx)>50&&Math.abs(dy)<40)choose(view.index+(dx<0?1:-1));});
 frame.addEventListener('pointercancel',()=>{touch=null;});
 panel.append(header,frame,thumbnails);gallery.hidden=true;gallery.after(panel);s.host.classList.add('vp3-canvas-media-viewing');imageView=view;
 choose(index);panel.focus({preventScroll:true});panel.scrollIntoView({block:'start',behavior:'instant'});return true;
}
function imageGallery(assets,s){
 const gallery=make('div');gallery.className='vp3-image-gallery';gallery.dataset.count=String(assets.length);gallery.setAttribute('role','group');gallery.setAttribute('aria-label',assets.length===1?'Saved image':'Saved images');
 for(const [index,asset] of assets.entries()){
  const tile=make('button');tile.type='button';tile.className='vp3-image-tile';tile.dataset.imageAssetId=String(asset.id);tile.setAttribute('aria-label','View '+String(asset.title||'image '+(index+1))+' in Chat');
  const picture=make('img');picture.src=ownUrl(asset.url,'/media-library-file-v86.php');picture.alt=String(asset.title||'Saved image');picture.loading='lazy';picture.decoding='async';
  tile.append(picture);tile.addEventListener('click',()=>openGallery(s,gallery,assets,index,tile));gallery.append(tile);
 }
 return gallery;
}
async function openImages(assetIds,{index=0,focusReturn=null}={}){
 if(!Array.isArray(assetIds)||!assetIds.length||assetIds.length>12||assetIds.some(id=>!Number.isSafeInteger(id)||id<1)||new Set(assetIds).size!==assetIds.length)return false;
 if(!Number.isInteger(index)||index<0||index>=assetIds.length)return false;
 const thread=document.getElementById('chatThread');
 if(!thread||!chatVisible()||!scope()||window.VP3ParticipantContinuity?.selectedContext?.())return false;
 closeImages(false);const ticket=++imageViewTicket;
 const host=make('section');host.className='vp3-canvas-media vp3-image-history-view';host.setAttribute('aria-label','Saved image gallery');
 const s={host,cid:scope(),agentId:selectedAgent(),timer:null,ticket:0};states.set(host,s);thread.append(host);
 const pending=make('p','Opening your saved images…');pending.setAttribute('role','status');host.append(pending);pendingImageView={host,s};
 try{
  const response=await api('assets',{asset_ids:assetIds},s);
  if(ticket!==imageViewTicket||!canViewImages(s)){host.remove();return false;}
  const byId=new Map(photoAssets(response.assets).map(asset=>[asset.id,asset]));
  const assets=assetIds.map(id=>byId.get(id));
  if(assets.some(asset=>!asset))throw new Error('One of these saved images is no longer available.');
  pendingImageView=null;const gallery=imageGallery(assets,s);host.replaceChildren(gallery);
  return openGallery(s,gallery,assets,index,focusReturn,true);
 }catch(error){
  if(ticket!==imageViewTicket||!canViewImages(s)){host.remove();return false;}
  pending.textContent=error.message||'The saved image could not be opened.';
  const dismiss=make('button','Back to Chat');dismiss.type='button';dismiss.onclick=()=>{closeImages(false);if(focusReturn?.isConnected)focusReturn.focus();else document.getElementById('chatInput')?.focus();};host.append(dismiss);
  return false;
 }
}
function assetCard(asset,s,marketingPrefill=null){
 const card=make('figure');card.className='vp3-canvas-media-card';card.appendChild(make('figcaption',asset.title||'Saved media'));
 const kind=asset.media_type,src=ownUrl(asset.url,'/media-library-file-v86.php');
 if(asset.available!==true||!src||!['photo','video','audio'].includes(kind)||kind==='photo'&&!photoAssets([asset]).length){card.appendChild(make('p','Saved media file is unavailable.'));return card;}
 const media=kind==='photo'?imageGallery(photoAssets([asset]),s):make(kind);if(kind!=='photo'){media.src=src;media.controls=true;media.preload='metadata';if(kind==='video')media.playsInline=true;}
 card.appendChild(media);card.appendChild(make('small',asset.source==='openai_image'?'Generated image · saved privately':'Saved '+kind));
 const controls=make('div');controls.className='vp3-canvas-media-controls';if(kind!=='photo'){const open=make('a','Open full size');open.href=src;open.target='_blank';open.rel='noopener';controls.appendChild(open);}
 const download=make('a','Download');download.href=src;download.download='media-'+Number(asset.id)+(kind==='photo'?({'image/png':'.png','image/jpeg':'.jpg','image/webp':'.webp'}[asset.mime_type]||''):'');controls.appendChild(download);
 if(kind==='photo'&&['image/jpeg','image/png','image/webp'].includes(asset.mime_type)&&window.VP3InitiativeWorkbench){
  const draft=make('button','Draft listing');draft.type='button';
  draft.addEventListener('click',()=>{if(active(s))window.VP3InitiativeWorkbench.open('product',String(asset.id));});controls.appendChild(draft);
  const marketing=make('button',marketingPrefill?'Design promotion':'Draft marketing');marketing.type='button';
  marketing.addEventListener('click',()=>{
   if(!active(s))return;
   if(marketingPrefill)window.VP3InitiativeWorkbench.open('marketing',String(asset.id),{marketingPrefill});
   else window.VP3InitiativeWorkbench.open('marketing',String(asset.id));
  });controls.appendChild(marketing);
 }
 const editor=ownUrl(asset.editor_url,'/video-editor.php');if(editor){const edit=make('a','Open in editor');edit.href=editor;controls.appendChild(edit);}card.appendChild(controls);return card;
}
function matchesScope(s){return s.cid===scope()&&s.agentId===selectedAgent();}
function active(s){return s.host.isConnected&&s.cid>0&&matchesScope(s)&&!continuity()?.isRestoring?.();}
function chatVisible(){return !document.hidden&&document.getElementById('chatThread')?.dataset.view==='chat';}
function syncOrbJobs(force=false){
 const now=Date.now(),owner=window.STONEFELLOW_CHAT?.userId;
 const visible=chatVisible()&&!window.VP3ParticipantContinuity?.selectedContext?.()
  &&(cfg.userId===undefined||Number(owner)===Number(cfg.userId));
 for(const [id,job] of orbJobs){
  if(!visible||!active(job.state)||job.expiresAt<=now){orbJobs.delete(id);force=true;}
 }
 if(force)window.VP3AgentOrb?.setJobs?.('image',Array.from(orbJobs,([id,{expiresAt}])=>({id,expiresAt})));
}
function clearOrbJobs(){orbJobs.clear();syncOrbJobs(true);}
function stop(s){clearTimeout(s.timer);s.timer=null;}
function clearSpecialist(s){
 const key=s.specialistKey;
 if(!key||specialistJobs.get(key)!==s)return;
 specialistJobs.delete(key);
 s.specialistKey=null;
 window.VP3ChatSpecialists?.remove(key);
}
function clearSpecialists(){
 for(const job of specialistJobs.values())clearSpecialist(job);
}
function showSpecialist(s,card,g,expiresAt){
 const state=expiresAt?'running':g.status==='queued'||g.status==='paused'?g.status:'';
 if(!active(s)||!chatVisible()||!state){clearSpecialist(s);return;}
 const rail=window.VP3ChatSpecialists;if(!rail)return;
 const key='image-generator:'+g.id;
 const existing=specialistJobs.get(key);
 if(existing&&existing!==s&&active(existing))return;
 s.specialistKey=key;
 specialistJobs.set(key,s);
 rail.update(key,{type:'image-generator',state,label:'Image Generator',title:g.title||'Requested image',
  status:inProgress[g.status],expiresAt:expiresAt||Date.now()+7500,desktop:state==='running',inline:false,
  detail:[g.model,g.size,g.quality,g.background==='transparent'?'Transparent background':''].filter(Boolean).join(' · '),
  onView:()=>{if(active(s)){card.scrollIntoView({block:'center'});card.focus({preventScroll:true});}}});
}
function forgetClock(s){if(s.clockCard)progressClocks.delete(s.clockCard);s.clockCard=null;}
function clockTime(value){
 if(typeof value!=='string'||!value)return null;
 const normalized=/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(value)?value.replace(' ','T')+'Z':value;
 const timestamp=Date.parse(normalized);return Number.isFinite(timestamp)&&timestamp<=Date.now()+1000?timestamp:null;
}
function refreshClocks(){
 for(const [card,clock] of progressClocks){
  if(!active(clock.s)||!clock.s.generation||!Object.hasOwn(inProgress,clock.s.generation.status)){progressClocks.delete(card);continue;}
  if(clock.s.leaseExpiresAt&&clock.s.leaseExpiresAt<=Date.now())card.dataset.activity='waiting';
  const seconds=Math.max(0,Math.floor((Date.now()-clock.start)/1000));
  const elapsed=seconds<60?seconds+'s':Math.floor(seconds/60)+'m '+String(seconds%60).padStart(2,'0')+'s';
  const text=clock.label+' '+elapsed;if(clock.node.textContent!==text)clock.node.textContent=text;
 }
}
function progressPanel(s,card,g,count,expiresAt){
 const progress=make('div');progress.className='vp3-image-progress';progress.dataset.count=String(count);
 const artwork=make('div');artwork.className='vp3-image-progress-art';artwork.setAttribute('aria-hidden','true');
 for(let i=0;i<count;i++){const frame=make('span');frame.className='vp3-image-progress-frame';frame.style.setProperty('--image-index',String(i));artwork.append(frame);}
 const copy=make('div');copy.className='vp3-image-progress-copy';
 const heading=g.status==='queued'?'Your '+(count===1?'image is':count+' images are')+' queued':g.status==='paused'?'Image creation is paused':expiresAt?'Creating '+(count===1?'your image':count+' images'):'Checking your image request';
 copy.append(make('strong',heading),make('p','Your request is saved. You can keep chatting.'));
 const stages=make('ol');stages.className='vp3-image-progress-stages';stages.setAttribute('aria-label','Image creation progress');
 const current=g.status==='queued'||g.status==='paused'&&!g.provider_started?0:1;
 for(const [index,label] of ['Queued','Creating','Ready'].entries()){
  const stage=make('li',label);if(index===current)stage.setAttribute('aria-current','step');if(index<current)stage.dataset.complete='true';stages.append(stage);
 }
 const started=clockTime(g.provider_started_at),requested=clockTime(g.created_at);
 if(started!==null||requested!==null){
  const clock=make('time');clock.className='vp3-image-generation-elapsed';const start=started??requested;clock.dateTime=new Date(start).toISOString();
  copy.append(clock);s.clockCard=card;progressClocks.set(card,{s,node:clock,start,label:started!==null?'Creating for':'Request elapsed'});
 }
 progress.append(artwork,copy,stages);return progress;
}
function failure(s,card,error){
 const id='image:'+card.dataset.generationId;
 if(error.status===401||error.status===419)clearOrbJobs();
 else if(orbJobs.get(id)?.state===s){orbJobs.delete(id);syncOrbJobs(true);}
 if(!active(s))return;
 stop(s);forgetClock(s);clearSpecialist(s);s.generation=null;
 if(imageView?.s===s)closeImages(false);
 card.dataset.state='unavailable';
 s.caption.textContent='Image Generator';
 s.status.textContent=error.message||'Media unavailable.';
 s.details.replaceChildren();
 if([401,403,404,419].includes(error.status))return;
 const b=make('button','Refresh status');b.type='button';b.onclick=()=>void poll(s,card,card.dataset.generationId,true);s.details.appendChild(b);
}
function renderJob(s,card,g){
 if(!active(s))return;
 if(g?.id!==card.dataset.generationId||typeof g.status!=='string')throw new Error('Image status could not be verified.');
 const {active_lease_expires_at:lease,...cardData}=g;
 const now=Date.now();
 const expiresAt=g.status==='generating'&&Number.isSafeInteger(lease)&&lease>now?lease:0;
 s.leaseExpiresAt=expiresAt;card.dataset.activity=expiresAt?'active':g.status==='queued'?'queued':'waiting';
 if(/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(g.id)){
  const id='image:'+g.id;
  if(expiresAt){
   orbJobs.set(id,{state:s,expiresAt:Math.min(expiresAt,now+7500)});syncOrbJobs(true);
  }
  else if(orbJobs.delete(id))syncOrbJobs(true);
 }
 showSpecialist(s,card,g,expiresAt);
 if(!Object.hasOwn(inProgress,g.status))stop(s);
 // Keep focused controls and expanded details in place on unchanged polls.
 const signature=JSON.stringify({...cardData,verified_active:Boolean(expiresAt)});
 if(s.generation&&signature===s.generation.signature)return;
 const previousStatus=s.generation?.status;
 s.generation={status:g.status,signature};
 if(previousStatus!==g.status)queueMicrotask(()=>{
  if(active(s)&&s.generation?.signature===signature)window.dispatchEvent(new CustomEvent('vp3:image-generation-updated',{detail:{generationId:g.id,status:g.status,conversationId:s.cid,agentId:s.agentId}}));
 });
 forgetClock(s);if(imageView?.s===s)closeImages(false);
 card.dataset.state=g.status;
 s.caption.textContent=g.title||'Image generation';
 const count=Number.isInteger(g.count)&&g.count>=1&&g.count<=4?g.count:1;
 const images=['completed','file_unavailable'].includes(g.status)?photoAssets(Array.isArray(g.assets)?g.assets:(g.asset?[g.asset]:[])).slice(0,count):[];
 const hasImage=images.length>0;
 let statusText=Object.hasOwn(inProgress,g.status)?inProgress[g.status]:g.status.replaceAll('_',' ');
 if(g.status==='generating')statusText=expiresAt?(count>1?'Generating '+count+' images…':inProgress.generating):'Checking image status…';
 if(hasImage)statusText=g.status==='completed'&&images.length===count?(count===1?'Image Generator finished. Your image is ready.':'Image Generator finished. Your '+count+' images are ready.'):images.length+' of '+count+' saved images available.';
 else if(g.status==='file_unavailable'||g.status==='completed')statusText='The saved image is unavailable.';
 if(s.status.textContent!==statusText)s.status.textContent=statusText;
 s.details.replaceChildren();
 if(g.message){const message=make('p',g.message);message.className='vp3-image-generation-message';s.details.appendChild(message);}
 if(hasImage){s.details.appendChild(imageGallery(images,s));return;}
 if(Object.hasOwn(inProgress,g.status)){
  const progress=progressPanel(s,card,g,count,expiresAt);s.details.append(progress);refreshClocks();
  const cancel=make('button','×');cancel.type='button';cancel.className='vp3-image-cancel';cancel.title='Cancel image';cancel.setAttribute('aria-label','Cancel image');cancel.onclick=async()=>{
   if(!active(s)||s.cancelling)return;
   s.cancelling=true;cancel.disabled=true;stop(s);++s.ticket;
   try{const d=await api('cancel',{generation_id:g.id,confirmed:true},s);renderJob(s,card,d.generation);}
   catch(e){failure(s,card,e);}
   finally{s.cancelling=false;if(s.generation&&Object.hasOwn(inProgress,s.generation.status))schedule(s,card,g.id);}
  };progress.appendChild(cancel);
 }
 if(g.status==='cancelled'&&g.provider_started)s.details.appendChild(make('p','Cancelled. OpenAI may already have processed the request; no output will be published by this job.'));
}
function schedule(s,card,id){stop(s);s.timer=setTimeout(()=>void poll(s,card,id),2500);}
async function poll(s,card,id,manual=false){
 if(!active(s)||document.hidden||s.cancelling)return;
 stop(s);const ticket=++s.ticket;
 try{
  let {generation}=await api('get',{generation_id:id},s);if(!active(s)||ticket!==s.ticket)return;
  if(generation?.id===id&&generation.status==='generating'&&generation.lease_expired===true&&!recovering.has(id)){
   recovering.add(id);
   try{({generation}=await api('recover',{generation_id:id},s));}finally{recovering.delete(id);}
   if(!active(s)||ticket!==s.ticket)return;
   if(generation?.lease_expired===true)throw new Error('The image worker stopped. Refresh its status to check the saved result.');
  }
  renderJob(s,card,generation);
  if(manual&&generation.status==='queued'&&!generation.provider_started&&!running.has(id))started.delete(id);
  if(generation.status==='queued'&&!started.has(id)&&!running.has(id)){
   started.add(id);running.add(id);
   // Separate request: the ordinary Chat composer remains available.
   void api('tick',{generation_id:id},s).then(r=>{if(active(s)&&ticket===s.ticket)renderJob(s,card,r.generation);})
    .catch(e=>{if(ticket===s.ticket)failure(s,card,e);}).finally(()=>running.delete(id));
  }
  if(Object.hasOwn(inProgress,generation.status))schedule(s,card,id);
 }catch(e){if(ticket===s.ticket)failure(s,card,e);}
}
function mount(host,ref){
 if(states.has(host)||!ref||!host.isConnected)return;
 const s={host,cid:scope(),agentId:selectedAgent(),timer:null,ticket:0};
 if(!active(s))return;states.set(host,s);host.replaceChildren();
 const ids=Array.isArray(ref.asset_ids)?ref.asset_ids.filter(i=>Number.isSafeInteger(i)&&i>0).slice(0,12):[];
 const prefill=ref.marketing_prefill;
 const marketingPrefill=Number.isInteger(prefill?.level)&&prefill.level>=1&&prefill.level<=10
  &&typeof prefill.prompt==='string'&&Array.from(prefill.prompt).length<=600
  &&!/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/.test(prefill.prompt)
  ? {level:prefill.level,prompt:prefill.prompt}:null;
 if(ids.length){const pending=make('p','Loading private media…');host.appendChild(pending);void api('assets',{asset_ids:ids},s).then(d=>{if(!active(s))return;pending.remove();for(const asset of d.assets||[])host.appendChild(assetCard(asset,s,marketingPrefill));if(!d.assets?.length)host.appendChild(make('p','These media assets are no longer available.'));}).catch(e=>{if(active(s)){pending.textContent=e.message;}});}
 const jobs=Array.isArray(ref.generation_ids)?ref.generation_ids.filter(i=>typeof i==='string'&&/^[a-f0-9-]{36}$/.test(i)).slice(0,1):[];
 for(const id of jobs){
  const card=make('figure');card.className='vp3-canvas-media-card';card.dataset.generationId=id;card.tabIndex=-1;
  card.classList.add('vp3-image-request');
  s.caption=make('figcaption','Image Generator');s.caption.className='vp3-image-caption';
  s.status=make('small','Checking image status…');s.status.className='vp3-image-generation-status';s.status.setAttribute('role','status');
  s.details=make('div');
  card.append(s.caption,s.details,s.status);host.appendChild(card);void poll(s,card,id);
 }
}
function scan(){
 syncOrbJobs();
 for(const job of specialistJobs.values())if(!active(job))clearSpecialist(job);
 for(const host of document.querySelectorAll('.vp3-canvas-media')){
  const s=states.get(host);
  if(s&&!matchesScope(s)){
   stop(s);
   if(host.firstChild)host.replaceChildren();
   continue;
  }
  mount(host,host._vp3CanvasMediaRef);
 }
}
function refreshJobs(){
 if(!chatVisible()){clearSpecialists();clearOrbJobs();return;}
 syncOrbJobs();
 for(const host of document.querySelectorAll('.vp3-canvas-media')){
  const s=states.get(host),card=host.querySelector('[data-generation-id]');
  if(s&&card&&active(s))void poll(s,card,card.dataset.generationId);
 }
}
window.VP3CanvasMedia={mount,openImages,closeImages:()=>closeImages(true)};new MutationObserver(scan).observe(document.documentElement,{childList:true,subtree:true});
setInterval(refreshClocks,1000);
window.addEventListener('stonefellow:chat-context-changing',()=>closeImages(false));
window.addEventListener('vp3:participant-task-context',()=>closeImages(false));
window.addEventListener('vp3:chat-canvas-view-changed',()=>{if(!chatVisible())closeImages(false);});
window.addEventListener('pagehide',()=>closeImages(false));
window.addEventListener('stonefellow:chat-context-changing',()=>{clearSpecialists();clearOrbJobs();});
window.addEventListener('vp3:participant-task-context',()=>{clearSpecialists();clearOrbJobs();});
window.addEventListener('vp3:agent-orb-ready',()=>syncOrbJobs(true));
window.addEventListener('vp3:chat-context-ready',()=>{refreshJobs();scan();});
window.addEventListener('vp3:chat-canvas-view-changed',refreshJobs);
document.addEventListener('visibilitychange',refreshJobs);
window.addEventListener('pagehide',()=>{clearSpecialists();clearOrbJobs();});
scan();
})();
