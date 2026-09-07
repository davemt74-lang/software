(() => {
'use strict';
const script=document.currentScript;
if(!script)return;
const key=String(script.dataset.vp3Key||'').trim();
if(!/^[a-f0-9]{40}$/i.test(key))return;
let endpoint;
try{endpoint=new URL('api/analytics-collect.php',script.src);endpoint.searchParams.set('key',key);}catch(e){return;}
const sessionKey=`vp3-analytics:${key}`;
const ttl=30*60*1000;
function randomToken(){
  try{if(globalThis.crypto?.randomUUID)return crypto.randomUUID().replaceAll('-','');}catch(e){}
  try{const a=new Uint8Array(24);crypto.getRandomValues(a);return [...a].map(x=>x.toString(16).padStart(2,'0')).join('');}catch(e){}
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`;
}
function session(){
  const now=Date.now();let row=null;
  try{row=JSON.parse(sessionStorage.getItem(sessionKey)||'null');}catch(e){}
  if(!row||typeof row.id!=='string'||!/^[A-Za-z0-9_-]{20,96}$/.test(row.id)||!Number.isFinite(Number(row.last))||now-Number(row.last)>ttl)row={id:randomToken().slice(0,96),last:now};
  else row.last=now;
  try{sessionStorage.setItem(sessionKey,JSON.stringify(row));}catch(e){}
  return row.id;
}
function referrerHost(){try{return document.referrer?new URL(document.referrer).hostname:'';}catch(e){return '';}}
function eventName(value){return String(value||'').toLowerCase().trim().replace(/[^a-z0-9_.:-]+/g,'_').replace(/^_+|_+$/g,'').slice(0,64);}
function send(name='page_view',options={}){
  const event=eventName(name)||'page_view';
  const payload={session:session(),event,path:location.pathname||'/',referrer_host:referrerHost()};
  if(options&&Number.isFinite(Number(options.value)))payload.value=Math.max(-1e9,Math.min(1e9,Number(options.value)));
  if(options&&typeof options.label==='string'&&options.label.trim())payload.label=options.label.trim().slice(0,120);
  try{fetch(endpoint.href,{method:'POST',mode:'cors',credentials:'omit',keepalive:true,headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).catch(()=>{});}catch(e){}
}
let lastPath='';
function pageView(){const path=location.pathname||'/';if(path===lastPath)return;lastPath=path;send('page_view');}
function wrapHistory(name){const original=history[name];if(typeof original!=='function')return;history[name]=function(...args){const result=original.apply(this,args);queueMicrotask(pageView);return result;};}
wrapHistory('pushState');wrapHistory('replaceState');window.addEventListener('popstate',pageView);
window.VP3=window.VP3||{};
window.VP3.track=(name,options={})=>{const clean=eventName(name);if(!clean||clean==='page_view')return false;send(clean,options);return true;};
pageView();
})();