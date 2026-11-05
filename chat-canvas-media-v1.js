(function(){
'use strict';
const cfg=window.VP3_CANVAS_MEDIA_V1||{};if(!cfg.endpoint||!cfg.csrf)return;
const states=new WeakMap(),started=new Set();
const make=(tag,text)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=String(text);return e;};
function scope(){return Number(window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()||window.STONEFELLOW_CHAT?.initialConversationId||0);}
async function api(action,payload,cid){const r=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:cfg.csrf,action,conversation_id:cid,agent_id:Number(cfg.agentId||0),...payload})});let data;try{data=await r.json();}catch(_){throw new Error('Media status was not confirmed. Refresh its status before requesting another image.');}if(!r.ok||!data.ok){const e=new Error(data.error||'Media unavailable.');e.status=r.status;throw e;}return data;}
function ownUrl(value,path){try{const u=new URL(value,location.href);return u.origin===location.origin&&u.pathname.endsWith(path)&&!u.username&&!u.password?u.href:'';}catch(_){return '';}}
function assetCard(asset){
 const card=make('figure');card.className='vp3-canvas-media-card';card.appendChild(make('figcaption',asset.title||'Saved media'));
 const kind=asset.media_type,src=ownUrl(asset.url,'/media-library-file-v86.php');
 if(asset.available!==true||!src||!['photo','video','audio'].includes(kind)){card.appendChild(make('p','Saved media file is unavailable.'));return card;}
 const media=make(kind==='photo'?'img':kind);media.src=src;if(kind==='photo'){media.alt=String(asset.title||'Saved image');media.loading='lazy';}else{media.controls=true;media.preload='metadata';if(kind==='video')media.playsInline=true;}
 card.appendChild(media);card.appendChild(make('small',asset.source==='openai_image'?'Generated image · saved privately':'Saved '+kind));
 const controls=make('div');controls.className='vp3-canvas-media-controls';const open=make('a','Open full size');open.href=src;open.target='_blank';open.rel='noopener';controls.appendChild(open);
 const download=make('a','Download');download.href=src;download.download='media-'+Number(asset.id)+(kind==='photo'?({'image/png':'.png','image/jpeg':'.jpg','image/webp':'.webp'}[asset.mime_type]||''):'');controls.appendChild(download);
 if(kind==='photo'&&['image/jpeg','image/png','image/webp'].includes(asset.mime_type)&&window.VP3InitiativeWorkbench){
  const draft=make('button','Draft listing');draft.type='button';
  draft.addEventListener('click',()=>window.VP3InitiativeWorkbench.open('product',String(asset.id)));controls.appendChild(draft);
 }
 const editor=ownUrl(asset.editor_url,'/video-editor.php');if(editor){const edit=make('a','Open in editor');edit.href=editor;controls.appendChild(edit);}card.appendChild(controls);return card;
}
function active(s){return s.host.isConnected&&s.cid===scope()&&s.cid>0;}
function stop(s){clearTimeout(s.timer);s.timer=null;}
function failure(s,card,error){if(!active(s))return;card.replaceChildren(make('p',error.message||'Media unavailable.'));if([401,403,404,419].includes(error.status)){stop(s);return;}const b=make('button','Refresh status');b.type='button';b.onclick=()=>void poll(s,card,card.dataset.generationId,true);card.appendChild(b);}
function renderJob(s,card,g){
 if(!active(s))return;card.dataset.state=g.status;card.replaceChildren(make('figcaption',g.title||'Image generation'));
 card.appendChild(make('small',g.status==='queued'?'Queued · waiting for Cloud worker':g.status==='generating'?'Generating image…':g.status.replaceAll('_',' ')));
 card.appendChild(make('small',g.model+' · '+g.size+' · '+g.quality+(g.background==='transparent'?' · transparent background':'')));
 if(g.message)card.appendChild(make('p',g.message));
 if(g.status==='completed'&&g.asset){card.appendChild(assetCard(g.asset));return;}
 if(['queued','generating','paused'].includes(g.status)){
  const details=make('details'),summary=make('summary','Requested image');details.append(summary,make('p',g.prompt));card.appendChild(details);
  const cancel=make('button','Cancel image');cancel.type='button';cancel.onclick=async()=>{cancel.disabled=true;try{const d=await api('cancel',{generation_id:g.id,confirmed:true},s.cid);renderJob(s,card,d.generation);}catch(e){failure(s,card,e);}};card.appendChild(cancel);
 }
 if(g.status==='cancelled'&&g.provider_started)card.appendChild(make('p','Cancelled. OpenAI may already have processed the request; no output will be published by this job.'));
}
async function poll(s,card,id,manual=false){
 if(!active(s)||document.hidden)return;const ticket=++s.ticket;
 try{
  const d=await api('get',{generation_id:id},s.cid);if(!active(s)||ticket!==s.ticket)return;renderJob(s,card,d.generation);
  if(manual&&d.generation.status==='queued'&&!d.generation.provider_started)started.delete(id);
  if(d.generation.status==='queued'&&!started.has(id)){
   started.add(id);
   // Separate request: the ordinary Chat composer remains available.
   void api('tick',{generation_id:id},s.cid).then(r=>{if(active(s))renderJob(s,card,r.generation);}).catch(e=>failure(s,card,e));
  }
  if(['queued','generating'].includes(d.generation.status))s.timer=setTimeout(()=>void poll(s,card,id),2500);
 }catch(e){failure(s,card,e);}
}
function mount(host,ref){
 if(states.has(host)||!ref||!host.isConnected)return;const s={host,cid:scope(),timer:null,ticket:0};if(!s.cid)return;states.set(host,s);host.replaceChildren();
 const ids=Array.isArray(ref.asset_ids)?ref.asset_ids.filter(i=>Number.isSafeInteger(i)&&i>0).slice(0,12):[];
 if(ids.length){const pending=make('p','Loading private media…');host.appendChild(pending);void api('assets',{asset_ids:ids},s.cid).then(d=>{if(!active(s))return;pending.remove();for(const asset of d.assets||[])host.appendChild(assetCard(asset));if(!d.assets?.length)host.appendChild(make('p','These media assets are no longer available.'));}).catch(e=>{if(active(s)){pending.textContent=e.message;}});}
 const jobs=Array.isArray(ref.generation_ids)?ref.generation_ids.filter(i=>typeof i==='string'&&/^[a-f0-9-]{36}$/.test(i)).slice(0,1):[];
 for(const id of jobs){const card=make('figure');card.className='vp3-canvas-media-card';card.dataset.generationId=id;host.appendChild(card);void poll(s,card,id);}
}
function scan(){for(const host of document.querySelectorAll('.vp3-canvas-media')){const s=states.get(host);if(s&&!active(s)){stop(s);host.replaceChildren();continue;}mount(host,host._vp3CanvasMediaRef);}}
window.VP3CanvasMedia={mount};new MutationObserver(scan).observe(document.documentElement,{childList:true,subtree:true});
document.addEventListener('visibilitychange',()=>{if(document.hidden)return;for(const host of document.querySelectorAll('.vp3-canvas-media')){const s=states.get(host);const card=host.querySelector('[data-generation-id]');if(s&&card&&active(s)){stop(s);void poll(s,card,card.dataset.generationId);}}});scan();
})();
