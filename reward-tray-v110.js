(function(){
'use strict';
const cfg=window.VP3_REWARD_TRAY_V110||{};
if(!cfg.endpoint)return;
let state={tray:{inbox:[],sent:[],claimed:[],counts:{inbox:0,sent:0,claimed:0}},contacts:[]};
let active='';let savedChildren=[];let claimCtx=null;let loaded=false;
const qs=(s,r=document)=>r.querySelector(s);
const esc=v=>String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
const post=async payload=>{
 const res=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({...payload,csrf_token:cfg.csrf})});
 const data=await res.json().catch(()=>({ok:false,error:'Invalid server response.'}));
 if(!res.ok||!data.ok)throw new Error(data.error||'Reward action failed.');
 return data;
};
const getState=async()=>{
 const res=await fetch(cfg.endpoint+'?action=state',{credentials:'same-origin',headers:{'Accept':'application/json'}});
 const data=await res.json().catch(()=>({ok:false,error:'Invalid server response.'}));
 if(!res.ok||!data.ok)throw new Error(data.error||'Rewards are unavailable.');
 state={tray:data.tray||state.tray,contacts:Array.isArray(data.contacts)?data.contacts:[]};loaded=true;renderCounts();if(active)render(active);
};
function renderCounts(){
 const counts=state.tray?.counts||{};
 ['inbox','sent','claimed'].forEach(k=>{document.querySelectorAll('[data-reward-count="'+k+'"]').forEach(el=>{el.textContent=String(Number(counts[k]||0));});});
}
function fmtDate(v){if(!v)return '';const d=new Date(String(v).replace(' ','T')+'Z');return Number.isNaN(d.getTime())?'':d.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'});}
function card(row,bucket){
 const exp=row.expires_at?'<span>Expires '+esc(fmtDate(row.expires_at))+'</span>':'';
 const sent=row.sent_at?'<span>Sent '+esc(fmtDate(row.sent_at))+'</span>':'';
 const claimed=row.claimed_at?'<span>Claimed '+esc(fmtDate(row.claimed_at))+'</span>':'';
 const recipient=row.recipient_name||row.recipient_email?'<span>To '+esc(row.recipient_name||row.recipient_email)+(row.recipient_email&&row.recipient_name?' · '+esc(row.recipient_email):'')+'</span>':'';
 const value=row.value_label?'<span class="reward-cert-value">'+esc(row.value_label)+'</span>':'';
 let actions='';
 if(bucket==='inbox'){
   actions='<div class="reward-cert-actions">'+
     '<button type="button" data-reward-send="'+Number(row.id)+'"'+(row.transferable?'':' disabled title="The issuing Merchant marked this certificate non-transferable"')+'>SEND</button>'+
     '<button class="primary" type="button" data-reward-claim="'+Number(row.id)+'">CLAIM</button></div>';
 }
 return '<article class="reward-cert"><div class="reward-cert-top"><div><div class="reward-cert-kicker">'+esc(row.merchant_name||'Reward')+'</div><h3>'+esc(row.reward_name||'Reward')+'</h3></div>'+value+'</div>'+
 '<div class="reward-cert-meta"><span>'+esc(row.campaign_name||'')+'</span>'+recipient+exp+sent+claimed+'</div>'+actions+'</article>';
}
function render(bucket){
 const list=qs('#rewardTrayList'),title=qs('#rewardTrayTitle'),sub=qs('#rewardTraySubtitle');
 if(!list)return;const rows=Array.isArray(state.tray?.[bucket])?state.tray[bucket]:[];
 const names={inbox:['Inbox','Certificates available to send or claim.'],sent:['Sent','Certificates you sent to your CRM contacts.'],claimed:['Claimed','Your claimed certificate history.']};
 title.textContent=names[bucket][0];sub.textContent=names[bucket][1];
 list.innerHTML=rows.length?rows.map(r=>card(r,bucket)).join(''):'<div class="reward-tray-empty"><strong>No '+esc(names[bucket][0].toLowerCase())+' certificates</strong><span>Your Reward activity will appear here.</span></div>';
 qsAllTabs().forEach(b=>b.classList.toggle('active',b.dataset.rewardTrayTab===bucket));
}
function qsAllTabs(){return Array.from(document.querySelectorAll('[data-reward-tray-tab]'));}
function open(bucket){
 if(!['inbox','sent','claimed'].includes(bucket))bucket='inbox';
 const thread=qs('#chatThread'),canvas=qs('#rewardTrayCanvas');if(!thread||!canvas)return;
 if(!active){
   savedChildren=[];
   Array.from(thread.children).forEach(el=>{if(el===canvas)return;savedChildren.push([el,el.hidden]);el.hidden=true;});
   document.body.classList.add('reward-tray-open');
 }
 active=bucket;canvas.hidden=false;render(bucket);
 if(!loaded)getState().catch(showError);
}
function close(){
 const canvas=qs('#rewardTrayCanvas');if(canvas)canvas.hidden=true;
 savedChildren.forEach(([el,wasHidden])=>{el.hidden=wasHidden;});savedChildren=[];
 document.body.classList.remove('reward-tray-open');qsAllTabs().forEach(b=>b.classList.remove('active'));active='';
}
function showError(err){
 const el=qs('#rewardTrayStatus');if(!el)return;el.textContent=err instanceof Error?err.message:String(err);el.className='reward-tray-status error';el.hidden=false;
}
function notice(msg){
 const el=qs('#rewardTrayStatus');if(!el)return;el.textContent=msg;el.className='reward-tray-status';el.hidden=false;setTimeout(()=>{el.hidden=true;},4500);
}
function openModal(id){const el=qs(id);if(el){el.hidden=false;document.body.style.overflow='hidden';}}
function closeModal(el){if(typeof el==='string')el=qs(el);if(el)el.hidden=true;document.body.style.overflow='';if(el?.id==='rewardClaimModal'){claimCtx=null;const q=qs('#rewardClaimQr');if(q)q.innerHTML='';}}
function contactOptions(){
 return '<option value="">Choose a Contact</option>'+state.contacts.map(c=>'<option value="'+Number(c.id)+'">'+esc(c.name)+' · '+esc(c.email)+(c.company?' · '+esc(c.company):'')+'</option>').join('');
}
function startSend(id){
 const form=qs('#rewardSendForm');form.reset();form.elements.issuance_id.value=String(id);form.elements.idempotency_key.value=(crypto.randomUUID?crypto.randomUUID():Date.now()+'-'+Math.random());
 form.elements.contact_id.innerHTML=contactOptions();openModal('#rewardSendModal');
}
async function prepareClaim(id){
 try{
   const data=await post({action:'prepare_claim',issuance_id:id});claimCtx=data.claim||null;if(!claimCtx)throw new Error('Claim preparation failed.');
   qs('#rewardClaimName').textContent=claimCtx.reward_name||'Reward';qs('#rewardClaimMerchant').textContent=claimCtx.merchant_name||'';
   const qr=qs('#rewardClaimQr');qr.innerHTML='';if(window.VP3RewardQR){const qrUrl=new URL(claimCtx.claim_terminal_url||'/campaign-claim.php',window.location.origin);qrUrl.hash='reward='+encodeURIComponent(String(claimCtx.credential||''));window.VP3RewardQR.render(qr,qrUrl.href,{scale:5});}
   const form=qs('#rewardClaimForm');form.reset();form.elements.issuance_id.value=String(id);
   form.elements.location_id.innerHTML='<option value="">No location</option>'+((claimCtx.locations||[]).map(l=>'<option value="'+Number(l.id)+'">'+esc(l.name)+'</option>').join(''));
   const can=!!claimCtx.can_process;form.elements.merchant_claim_code.disabled=!can;form.elements.location_id.disabled=!can;form.elements.order_ref.disabled=!can;form.querySelector('[type=submit]').disabled=!can;
   qs('#rewardClaimAuthority').hidden=can;qs('#rewardClaimTerminal').href=claimCtx.claim_terminal_url||'#';
   openModal('#rewardClaimModal');
 }catch(err){showError(err);}
}
function wire(){
 const top=qs('.chat-topbar'),actions=qs('.chat-topbar-actions');
 if(top&&actions&&!qs('#rewardTrayTabs')){
   const nav=document.createElement('nav');nav.id='rewardTrayTabs';nav.className='reward-tray-tabs';nav.setAttribute('aria-label','Rewards');
   nav.innerHTML=['inbox','sent','claimed'].map(k=>'<button class="reward-tray-tab" type="button" data-reward-tray-tab="'+k+'">'+k.toUpperCase()+' <span class="reward-tray-count" data-reward-count="'+k+'">0</span></button>').join('');
   top.insertBefore(nav,actions);
 }
 const thread=qs('#chatThread');
 if(thread&&!qs('#rewardTrayCanvas')){
   const canvas=document.createElement('section');canvas.id='rewardTrayCanvas';canvas.className='reward-tray-canvas';canvas.hidden=true;
   canvas.innerHTML='<header class="reward-tray-head"><div><small>Rewards</small><h1 id="rewardTrayTitle">Inbox</h1><p id="rewardTraySubtitle">Certificates available to send or claim.</p></div><button class="reward-tray-close" type="button" data-reward-tray-close aria-label="Close Rewards">×</button></header><div id="rewardTrayStatus" class="reward-tray-status" hidden></div><div id="rewardTrayList" class="reward-tray-list"></div>';
   thread.prepend(canvas);
 }
 if(!qs('#rewardSendModal')){
   document.body.insertAdjacentHTML('beforeend','<div class="reward-tray-modal" id="rewardSendModal" hidden><button class="reward-tray-modal-backdrop" type="button" data-reward-modal-close></button><section class="reward-tray-dialog" role="dialog" aria-modal="true" aria-labelledby="rewardSendTitle"><header><div><small>Reward certificate</small><h2 id="rewardSendTitle">Send Reward</h2></div><button class="reward-tray-dialog-close" type="button" data-reward-modal-close>×</button></header><form id="rewardSendForm" class="reward-tray-form"><input type="hidden" name="issuance_id"><input type="hidden" name="idempotency_key"><label>Send to CRM Contact<select name="contact_id" required></select></label><label>Note<textarea name="note" maxlength="500" placeholder="Optional note about this transfer"></textarea></label><div class="reward-tray-form-actions"><button class="reward-tray-btn" type="button" data-reward-modal-close>Cancel</button><button class="reward-tray-btn primary" type="submit">SEND</button></div></form></section></div>'+
   '<div class="reward-tray-modal" id="rewardClaimModal" hidden><button class="reward-tray-modal-backdrop" type="button" data-reward-modal-close></button><section class="reward-tray-dialog" role="dialog" aria-modal="true" aria-labelledby="rewardClaimTitle"><header><div><small id="rewardClaimMerchant"></small><h2 id="rewardClaimTitle"><span id="rewardClaimName">Claim Reward</span></h2></div><button class="reward-tray-dialog-close" type="button" data-reward-modal-close>×</button></header><div class="reward-tray-form"><div class="reward-claim-layout"><div id="rewardClaimQr" class="reward-claim-qr"></div><div class="reward-claim-copy"><strong>Merchant redemption QR</strong><span>Scan this QR at the Merchant Claim Terminal. It contains a fresh one-time Reward Credential and opens the terminal without putting that credential in a server request.</span><div id="rewardClaimAuthority" class="reward-claim-warning">This account is not an authorized claim operator for the issuing Merchant. A Merchant operator must scan the QR and complete the claim.</div><a id="rewardClaimTerminal" href="#" target="_blank" rel="noopener">Open Claim Terminal ↗</a></div></div><form id="rewardClaimForm" class="reward-tray-form" style="padding:0"><input type="hidden" name="issuance_id"><label>Merchant Claim Code<input name="merchant_claim_code" autocomplete="off" spellcheck="false" required placeholder="Enter Merchant Claim Code"></label><label>Location<select name="location_id"><option value="">No location</option></select></label><label>Order / receipt reference<input name="order_ref" maxlength="190"></label><div class="reward-tray-form-actions"><button class="reward-tray-btn" type="button" data-reward-modal-close>Cancel</button><button class="reward-tray-btn primary" type="submit">CLAIM</button></div></form></div></section></div>');
 }
 document.addEventListener('click',e=>{
   const tab=e.target.closest('[data-reward-tray-tab]');if(tab){if(tab.tagName==='A'&&!qs('#chatThread'))return;e.preventDefault();open(tab.dataset.rewardTrayTab);return;}
   if(e.target.closest('[data-reward-tray-close]')){close();return;}
   const send=e.target.closest('[data-reward-send]');if(send&&!send.disabled){startSend(Number(send.dataset.rewardSend));return;}
   const claim=e.target.closest('[data-reward-claim]');if(claim){prepareClaim(Number(claim.dataset.rewardClaim));return;}
   if(e.target.closest('[data-reward-modal-close]')){closeModal(e.target.closest('.reward-tray-modal'));return;}
 });
 qs('#rewardSendForm')?.addEventListener('submit',async e=>{
   e.preventDefault();const fd=new FormData(e.currentTarget);
   try{const data=await post({action:'send',issuance_id:Number(fd.get('issuance_id')),contact_id:Number(fd.get('contact_id')),note:String(fd.get('note')||''),idempotency_key:String(fd.get('idempotency_key')||'')});state={tray:data.tray||state.tray,contacts:data.contacts||state.contacts};renderCounts();closeModal('#rewardSendModal');active='sent';render('sent');notice('Reward sent and transfer recorded.');}catch(err){showError(err);}
 });
 qs('#rewardClaimForm')?.addEventListener('submit',async e=>{
   e.preventDefault();const fd=new FormData(e.currentTarget);
   try{const data=await post({action:'claim',issuance_id:Number(fd.get('issuance_id')),merchant_claim_code:String(fd.get('merchant_claim_code')||''),location_id:Number(fd.get('location_id')||0),order_ref:String(fd.get('order_ref')||'')});state.tray=data.tray||state.tray;renderCounts();closeModal('#rewardClaimModal');active='claimed';render('claimed');notice('Reward claimed and recorded.');}catch(err){showError(err);}
 });
 getState().catch(()=>{});
 const requested=new URL(location.href).searchParams.get('reward_tray');if(['inbox','sent','claimed'].includes(requested)){open(requested);}
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',wire,{once:true});else wire();
})();