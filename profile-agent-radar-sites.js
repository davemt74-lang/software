(() => {
'use strict';
const cfg=window.PROFILE_AGENT_PORTAL;
const host=document.getElementById('profileAgentRadar');
if(!cfg?.radarSitesEndpoint||!cfg?.radarScriptUrl||!cfg?.csrf||!host)return;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const notice=document.getElementById('profileAgentNotice');
let sitesState=null,busy=false,timer=null;
function setNotice(message='',error=false){if(!notice)return;notice.textContent=message;notice.className=`profile-agent-notice${error?' error':''}`;}
function relative(value){if(!value)return '';const d=new Date(String(value).replace(' ','T'));if(Number.isNaN(d.getTime()))return String(value);const s=Math.round((Date.now()-d.getTime())/1000);if(s<60)return 'just now';if(s<3600)return `${Math.max(1,Math.round(s/60))}m ago`;if(s<86400)return `${Math.round(s/3600)}h ago`;if(s<604800)return `${Math.round(s/86400)}d ago`;return d.toLocaleDateString();}
async function request(payload=null){
  const options=payload?{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:cfg.csrf,...payload})}:{credentials:'same-origin',cache:'no-store'};
  const r=await fetch(cfg.radarSitesEndpoint,options),d=await r.json().catch(()=>null);
  if(!r.ok||!d?.ok)throw new Error(d?.error||'Connected Sites request failed.');
  return d.state;
}
function snippet(site){
  const src=new URL(cfg.radarScriptUrl,window.location.origin).href;
  return `<script async src="${src}" data-vp3-key="${String(site.public_key||'')}"></script>`;
}
function capacityLabel(){
  if(sitesState?.limit===null)return `${Number(sitesState?.active_count||0)} active · unlimited plan`;
  return `${Number(sitesState?.active_count||0)} of ${Number(sitesState?.limit||0)} active`;
}
function ensureShell(){
  let shell=host.querySelector('[data-radar-sites-shell]');
  if(shell)return shell;
  shell=document.createElement('section');
  shell.className='profile-agent-radar-sites-shell';
  shell.dataset.radarSitesShell='1';
  host.prepend(shell);
  render();
  return shell;
}
function siteCard(site){
  const active=Number(site.is_active)===1,verified=!!site.verified_at;
  return `<article class="profile-agent-radar-site ${active?'active':'paused'}"><div class="profile-agent-radar-site-head"><div><span>${active?'Connected site':'Paused site'}</span><strong>${esc(site.label||site.domain)}</strong><small>${esc(site.domain)}</small></div><div class="profile-agent-radar-site-status"><b class="${verified?'verified':'pending'}">${verified?'Verified':'Awaiting signal'}</b><button type="button" data-site-toggle="${Number(site.id)}" data-site-active="${active?'0':'1'}">${active?'Pause':'Reactivate'}</button></div></div><div class="profile-agent-radar-site-stats"><span>Agent sessions <b>${Number(site.session_count||0).toLocaleString()}</b></span><span>Radar events <b>${Number(site.event_count||0).toLocaleString()}</b></span><span>Last agent event <b>${site.last_event_at?esc(relative(site.last_event_at)):'None yet'}</b></span></div><div class="profile-agent-radar-install"><div><span>Browser install snippet</span><p>Add this once before the closing <code>&lt;/body&gt;</code> tag on ${esc(site.domain)}. The first valid automated visit that renders the script marks the site verified.</p></div><code>${esc(snippet(site))}</code><button type="button" data-copy-site="${Number(site.id)}">Copy</button></div></article>`;
}
function render(){
  const shell=host.querySelector('[data-radar-sites-shell]');
  if(!shell)return;
  if(!sitesState){shell.innerHTML='<div class="profile-agent-radar-sites-loading">Loading connected sites…</div>';return;}
  const sites=Array.isArray(sitesState.sites)?sitesState.sites:[];
  shell.innerHTML=`<div class="profile-agent-radar-sites-head"><div><span>Connected Sites</span><h2>Extend Agent Radar to your websites</h2><p>Register a domain, install one privacy-minimal browser script, and JavaScript-capable automated visitors flow into the same Agent CRM and Radar timeline as your VP3 profile.</p></div><div class="profile-agent-radar-site-capacity">${esc(capacityLabel())}</div></div><form class="profile-agent-radar-site-form" data-radar-site-form><label><span>Website domain</span><input name="domain" placeholder="example.com" autocomplete="url" required></label><label><span>Label</span><input name="label" placeholder="Main website" maxlength="190"></label><button type="submit"${sitesState.can_add?'':' disabled'}>${sitesState.can_add?'Add Site':'Site Limit Reached'}</button></form>${!sitesState.can_add&&sitesState.limit!==null?'<div class="profile-agent-radar-sites-limit">Pause an active site or change the package entitlement before adding another site.</div>':''}<div class="profile-agent-radar-site-list">${sites.length?sites.map(siteCard).join(''):'<div class="profile-agent-radar-sites-empty">No external websites are connected yet.</div>'}</div><div class="profile-agent-radar-sites-privacy">The install script uses no cookies, local storage, account identity or fingerprinting. It sends pathname and referrer host only. Normal human browser traffic is discarded by the Agent Radar collector. This browser collector sees agents that render JavaScript; non-JavaScript crawlers require the server-side Radar layer.</div>`;
}
async function load(silent=true){
  if(busy)return;busy=true;
  try{sitesState=await request();ensureShell();render();if(!silent)setNotice('Connected Sites refreshed.');}
  catch(err){setNotice(err.message,true);}finally{busy=false;}
}
host.addEventListener('submit',async e=>{
  const form=e.target.closest('[data-radar-site-form]');if(!form)return;e.preventDefault();
  const f=new FormData(form),button=form.querySelector('button[type=submit]');if(button)button.disabled=true;
  try{sitesState=await request({action:'create',domain:String(f.get('domain')||''),label:String(f.get('label')||'')});render();setNotice('Connected site saved. Install the snippet on that domain to begin verification.');}
  catch(err){setNotice(err.message,true);}finally{if(button)button.disabled=false;}
});
host.addEventListener('click',async e=>{
  const toggle=e.target.closest('[data-site-toggle]');
  if(toggle){toggle.disabled=true;try{sitesState=await request({action:'set_active',property_id:Number(toggle.dataset.siteToggle||0),is_active:Number(toggle.dataset.siteActive||0)});render();setNotice(Number(toggle.dataset.siteActive||0)?'Connected site reactivated.':'Connected site paused.');}catch(err){setNotice(err.message,true);toggle.disabled=false;}return;}
  const copy=e.target.closest('[data-copy-site]');
  if(copy){const site=(sitesState?.sites||[]).find(s=>Number(s.id)===Number(copy.dataset.copySite));if(!site)return;try{await navigator.clipboard.writeText(snippet(site));copy.textContent='Copied';setTimeout(()=>{if(copy.isConnected)copy.textContent='Copy';},1200);}catch(err){setNotice('Copy failed. Select the install snippet manually.',true);}return;}
});
const observer=new MutationObserver(()=>ensureShell());
observer.observe(host,{childList:true});
ensureShell();
load(true);
timer=setInterval(()=>{if(document.visibilityState==='visible')load(true);},30000);
window.addEventListener('beforeunload',()=>{clearInterval(timer);observer.disconnect();});
})();