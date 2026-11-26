/* Visible phone notifications only. No fetch interception, private caches or background sensors. */
'use strict';
const phoneAppURL=new URL('./phone-location.php',self.location.href);
const phoneReceiptURL=new URL('./api/phone-push-receipt-v1.php',self.location.href);
const phonePushRef=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
const phoneReceiptToken=value=>typeof value==='string'&&/^[A-Za-z0-9_-]{42}[AEIMQUYcgkosw048]$/.test(value);
const phonePushScope=()=>self.registration.scope===phoneAppURL.href&&phoneAppURL.origin===self.location.origin;
function phonePushPayload(event){
  try{
    const raw=event.data?.text();if(typeof raw!=='string'||raw.length>1024||!phonePushScope())return null;
    const value=JSON.parse(raw);
    if(!value||Array.isArray(value)||value.v!==1||Object.keys(value).some(key=>!['v','event_ref','receipt_token'].includes(key))||!phonePushRef(value.event_ref)||!phoneReceiptToken(value.receipt_token))return null;
    return {v:1,event_ref:value.event_ref,receipt_token:value.receipt_token};
  }catch(_error){return null;}
}
async function phonePushDisplayed(payload){
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),5000);
  try{
    // This narrow capability reports only a successful showNotification call.
    // It is never used to read private content, resolve a source or claim a tap.
    await fetch(phoneReceiptURL.href,{method:'POST',credentials:'omit',mode:'same-origin',cache:'no-store',redirect:'error',referrerPolicy:'no-referrer',signal:controller.signal,
      headers:{'Content-Type':'application/json','X-VP3-Phone':'1'},body:JSON.stringify({event_ref:payload.event_ref,receipt_token:payload.receipt_token,kind:'displayed'})});
  }catch(_error){/* A lost receipt does not change the visible notification. */}
  finally{clearTimeout(timer);}
}
function phonePushWindow(client){
  try{const url=new URL(client.url);return url.origin===phoneAppURL.origin&&url.pathname===phoneAppURL.pathname&&!url.username&&!url.password;}catch(_error){return false;}
}
async function phonePushOpen(reference){
  const target=new URL(phoneAppURL.href);target.searchParams.set('push_event',reference);
  let windows=[];try{windows=await self.clients.matchAll({type:'window',includeUncontrolled:true});}catch(_error){}
  const candidates=windows.filter(phonePushWindow).slice(0,30).sort((a,b)=>Number(Boolean(b.focused))-Number(Boolean(a.focused)));
  for(const client of candidates){
    try{
      // Reload this exact app route to let its normal HttpOnly device session
      // resolve the opaque event. No push field can supply a URL or an action.
      if(typeof client.navigate!=='function'||typeof client.focus!=='function')continue;
      const opened=await client.navigate(target.href);
      if(opened&&phonePushWindow(opened)&&typeof opened.focus==='function'){await opened.focus();return;}
    }catch(_error){/* The tab may have closed between lookup and navigation. */}
  }
  if(typeof self.clients.openWindow==='function')await self.clients.openWindow(target.href);
}
self.addEventListener('install',event=>{event.waitUntil(self.skipWaiting());});
self.addEventListener('activate',event=>{event.waitUntil(self.clients.claim());});
self.addEventListener('push',event=>{
  const payload=phonePushPayload(event);
  // Every push attempts a visible alert, including invalid or unsupported data.
  // Duplicate delivery uses the same tag instead of silently discarding a push.
  const options={body:'Open VP3 Phone to check your updates.',icon:new URL('./assets/phone-pwa/icon-192.png',self.location.href).href,
    tag:payload?'vp3-phone-'+payload.event_ref:'vp3-phone-update',renotify:false,silent:false,data:payload?{v:1,event_ref:payload.event_ref}:null};
  event.waitUntil(Promise.resolve().then(()=>self.registration.showNotification('VP3 Phone',options)).then(()=>payload?phonePushDisplayed(payload):undefined));
});
self.addEventListener('notificationclick',event=>{
  event.notification.close();const data=event.notification.data;
  if(event.action||!phonePushScope()||data?.v!==1||!phonePushRef(data.event_ref)||Object.keys(data).some(key=>!['v','event_ref'].includes(key))||event.notification.tag!=='vp3-phone-'+data.event_ref)return;
  event.waitUntil(phonePushOpen(data.event_ref));
});
