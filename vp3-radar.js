(() => {
'use strict';
const script=document.currentScript;
if(!script)return;
const key=String(script.dataset.vp3Key||'').trim().toLowerCase();
if(!/^[a-f0-9]{40}$/.test(key))return;
const marker=`${key}|${location.pathname}`;
window.__vp3RadarSent=window.__vp3RadarSent||new Set();
if(window.__vp3RadarSent.has(marker))return;
window.__vp3RadarSent.add(marker);
let referrerHost='';
try{referrerHost=document.referrer?new URL(document.referrer).hostname:'';}catch(e){}
const endpoint=new URL('api/radar-collect.php',script.src);
endpoint.searchParams.set('key',key);
const payload=JSON.stringify({
  path:String(location.pathname||'/').slice(0,500),
  referrer_host:String(referrerHost||'').slice(0,190)
});
fetch(endpoint.href,{
  method:'POST',
  mode:'cors',
  credentials:'omit',
  keepalive:true,
  headers:{'Content-Type':'text/plain;charset=UTF-8'},
  body:payload
}).catch(()=>{});
})();
