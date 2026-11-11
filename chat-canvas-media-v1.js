(function(){
'use strict';
const cfg=window.VP3_CANVAS_MEDIA_V1||{};if(!cfg.endpoint||!cfg.csrf)return;
const states=new WeakMap(),started=new Set(),running=new Set();
const orbJobs=new Map();
const specialistJobs=new Map();
const inProgress={queued:'Queued · waiting for Cloud worker',generating:'Generating image…',paused:'Paused'};
const make=(tag,text)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=String(text);return e;};
const continuity=()=>window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
function scope(){return Number(continuity()?.conversationId?.()??window.STONEFELLOW_CHAT?.initialConversationId??0);}
function selectedAgent(){return Number(continuity()?.agentId?.()??cfg.agentId??0);}
async function api(action,payload,s){const r=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:cfg.csrf,action,conversation_id:s.cid,agent_id:s.agentId,...payload})});let data;try{data=await r.json();}catch(_){throw new Error('Media status was not confirmed. Refresh its status before requesting another image.');}if(!r.ok||!data.ok){const e=new Error(data.error||'Media unavailable.');e.status=r.status;throw e;}return data;}
function ownUrl(value,path){try{const u=new URL(value,location.href);return u.origin===location.origin&&u.pathname.endsWith(path)&&!u.username&&!u.password?u.href:'';}catch(_){return '';}}
function assetCard(asset,s,marketingPrefill=null){
 const card=make('figure');card.className='vp3-canvas-media-card';card.appendChild(make('figcaption',asset.title||'Saved media'));
 const kind=asset.media_type,src=ownUrl(asset.url,'/media-library-file-v86.php');
 if(asset.available!==true||!src||!['photo','video','audio'].includes(kind)){card.appendChild(make('p','Saved media file is unavailable.'));return card;}
 const media=make(kind==='photo'?'img':kind);media.src=src;if(kind==='photo'){media.alt=String(asset.title||'Saved image');media.loading='lazy';}else{media.controls=true;media.preload='metadata';if(kind==='video')media.playsInline=true;}
 card.appendChild(media);card.appendChild(make('small',asset.source==='openai_image'?'Generated image · saved privately':'Saved '+kind));
 const controls=make('div');controls.className='vp3-canvas-media-controls';const open=make('a','Open full size');open.href=src;open.target='_blank';open.rel='noopener';controls.appendChild(open);
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
function failure(s,card,error){
 const id='image:'+card.dataset.generationId;
 if(error.status===401||error.status===419)clearOrbJobs();
 else if(orbJobs.get(id)?.state===s){orbJobs.delete(id);syncOrbJobs(true);}
 if(!active(s))return;
 stop(s);clearSpecialist(s);s.generation=null;
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
 const signature=JSON.stringify(cardData);
 if(s.generation&&signature===s.generation.signature)return;
 s.generation={status:g.status,signature};
 card.dataset.state=g.status;
 s.caption.textContent=g.title||'Image generation';
 const hasImage=g.status==='completed'&&g.asset?.available===true&&g.asset.media_type==='photo'&&ownUrl(g.asset.url,'/media-library-file-v86.php');
 let statusText=Object.hasOwn(inProgress,g.status)?inProgress[g.status]:g.status.replaceAll('_',' ');
 if(hasImage)statusText='Image Generator finished. Your image is ready.';
 else if(g.status==='file_unavailable'||g.status==='completed')statusText='The saved image is unavailable.';
 if(s.status.textContent!==statusText)s.status.textContent=statusText;
 s.details.replaceChildren(make('small',g.model+' · '+g.size+' · '+g.quality+(g.background==='transparent'?' · transparent background':'')));
 if(g.message)s.details.appendChild(make('p',g.message));
 if(hasImage){s.details.appendChild(assetCard(g.asset,s));return;}
 if(Object.hasOwn(inProgress,g.status)){
  const details=make('details'),summary=make('summary','Requested image');details.append(summary,make('p',g.prompt));s.details.appendChild(details);
  const cancel=make('button','Cancel image');cancel.type='button';cancel.onclick=async()=>{
   if(!active(s)||s.cancelling)return;
   s.cancelling=true;cancel.disabled=true;stop(s);++s.ticket;
   try{const d=await api('cancel',{generation_id:g.id,confirmed:true},s);renderJob(s,card,d.generation);}
   catch(e){failure(s,card,e);}
   finally{s.cancelling=false;if(s.generation&&Object.hasOwn(inProgress,s.generation.status))schedule(s,card,g.id);}
  };s.details.appendChild(cancel);
 }
 if(g.status==='cancelled'&&g.provider_started)s.details.appendChild(make('p','Cancelled. OpenAI may already have processed the request; no output will be published by this job.'));
}
function schedule(s,card,id){stop(s);s.timer=setTimeout(()=>void poll(s,card,id),2500);}
async function poll(s,card,id,manual=false){
 if(!active(s)||document.hidden||s.cancelling)return;
 stop(s);const ticket=++s.ticket;
 try{
  const d=await api('get',{generation_id:id},s);if(!active(s)||ticket!==s.ticket)return;renderJob(s,card,d.generation);
  if(manual&&d.generation.status==='queued'&&!d.generation.provider_started&&!running.has(id))started.delete(id);
  if(d.generation.status==='queued'&&!started.has(id)&&!running.has(id)){
   started.add(id);running.add(id);
   // Separate request: the ordinary Chat composer remains available.
   void api('tick',{generation_id:id},s).then(r=>{if(active(s)&&ticket===s.ticket)renderJob(s,card,r.generation);})
    .catch(e=>{if(ticket===s.ticket)failure(s,card,e);}).finally(()=>running.delete(id));
  }
  if(Object.hasOwn(inProgress,d.generation.status))schedule(s,card,id);
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
  s.caption=make('figcaption','Image Generator');
  s.status=make('small','Checking image status…');s.status.className='vp3-image-generation-status';s.status.setAttribute('role','status');
  s.details=make('div');
  card.append(s.caption,s.status,s.details);host.appendChild(card);void poll(s,card,id);
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
window.VP3CanvasMedia={mount};new MutationObserver(scan).observe(document.documentElement,{childList:true,subtree:true});
window.addEventListener('stonefellow:chat-context-changing',()=>{clearSpecialists();clearOrbJobs();});
window.addEventListener('vp3:participant-task-context',()=>{clearSpecialists();clearOrbJobs();});
window.addEventListener('vp3:agent-orb-ready',()=>syncOrbJobs(true));
window.addEventListener('vp3:chat-context-ready',()=>{refreshJobs();scan();});
window.addEventListener('vp3:chat-canvas-view-changed',refreshJobs);
document.addEventListener('visibilitychange',refreshJobs);
window.addEventListener('pagehide',()=>{clearSpecialists();clearOrbJobs();});
scan();
})();
