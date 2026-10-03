/* Passive scene status; bounded snapshots, no capture or consent writes. */
(()=>{'use strict';
const connections={recent_contact:'Recent HomeServer contact',contact_stale:'HomeServer contact is stale',disconnected:'HomeServer reported disconnected',unknown:'HomeServer connection unknown',not_reported:'No HomeServer report'};
for(const panel of document.querySelectorAll('[data-agent-eyes-experience]')){
 const list=panel.querySelector('[data-eyes-list]');let epoch=0,busy=false,controller=null,timers=[];
 const clock=()=>performance.now();
 const clearTimers=()=>{for(const id of timers)clearTimeout(id);timers=[];};
 const text=(parent,tag,value)=>{const n=document.createElement(tag);n.textContent=value;parent.append(n);return n;};
 function unavailable(message){clearTimers();list.replaceChildren();text(list,'p',message);}
 function render(statuses,elapsed){
  clearTimers();list.replaceChildren();
  for(const item of statuses.slice(0,20)){
   const card=document.createElement('div');list.append(card);
   text(card,'h3',String(item.site_label||'HomeServer'));text(card,'p',item.connection_label||connections[item.connection]||connections.unknown);
   if(item.device_id)text(card,'p','Device: '+item.device_id);
   const title=text(card,'strong',String(item.title||'Scene status unavailable'));
   const meaning=text(card,'p','No current scene meaning is available.');
   const age=typeof item.age_seconds==='number'?item.age_seconds+elapsed:NaN;
   if(item.state==='available'&&Number.isFinite(age)&&age>=0&&age<60){
    meaning.textContent=String(item.meaning||'');
    timers.push(setTimeout(()=>{title.textContent='Scene expired';meaning.textContent='No current scene meaning is available. Complete a new supervised observation on HomeServer.';},Math.max(0,(60-age)*1000)));
   }else if(item.state==='available')title.textContent='Scene expired';
   if(item.observed_at)text(card,'p','Last observed: '+item.observed_at);
   text(card,'p',String(item.guidance||'Check Agent Eyes locally on HomeServer.'));
   text(card,'p',String(item.consent_note||'Cloud cannot confirm current local consent.'));
   const contactAge=typeof item.contact_age_seconds==='number'?item.contact_age_seconds+elapsed:NaN;
   if(item.connection==='recent_contact'&&Number.isFinite(contactAge)){
    const contact=card.children[1];
    if(contactAge>=300)contact.textContent=connections.contact_stale;
    else timers.push(setTimeout(()=>{contact.textContent=connections.contact_stale;},Math.max(0,(300-contactAge)*1000)));
   }
  }
 }
 async function refresh(){
  if(busy||document.visibilityState!=='visible')return;
  busy=true;const current=++epoch,started=clock(),requestController=new AbortController();controller=requestController;
  // Never keep an old scene while a status request is unresolved.
  unavailable('Checking scene status…');
  const timeout=setTimeout(()=>requestController.abort(),10000);
  try{
   const response=await fetch(panel.dataset.endpoint,{credentials:'same-origin',cache:'no-store',signal:requestController.signal,headers:{Accept:'application/json'}});
   const data=await response.json();
   if(current!==epoch||document.visibilityState!=='visible')return;
   if(!response.ok||data.ok!==true||!Array.isArray(data.statuses))throw Error('status_unavailable');
   render(data.statuses,(clock()-started)/1000);
  }catch(_){if(current===epoch&&document.visibilityState==='visible')unavailable('Scene status unavailable. Check HomeServer connection and Tracky plugin settings, then refresh.');}
  finally{clearTimeout(timeout);if(current===epoch){busy=false;controller=null;}}
 }
 panel.querySelector('[data-eyes-refresh]').addEventListener('click',()=>void refresh());
 document.addEventListener('visibilitychange',()=>{epoch++;controller?.abort();controller=null;busy=false;unavailable('Refresh to check current scene status.');if(document.visibilityState==='visible')void refresh();});
 setInterval(()=>void refresh(),15000);void refresh();
}
})();
