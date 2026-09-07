(() => {
'use strict';
const cfg=window.PROFILE_AGENT_PORTAL;
const host=document.getElementById('profileAgentRadar');
if(!cfg?.radarSitesEndpoint||!cfg?.radarScriptUrl||!cfg?.csrf||!host)return;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const notice=document.getElementById('profileAgentNotice');
const serverTokens=new Map();
let sitesState=null,busy=false,timer=null;
function setNotice(message='',error=false){if(!notice)return;notice.textContent=message;notice.className=`profile-agent-notice${error?' error':''}`;}
function relative(value){if(!value)return '';const d=new Date(String(value).replace(' ','T'));if(Number.isNaN(d.getTime()))return String(value);const s=Math.round((Date.now()-d.getTime())/1000);if(s<60)return 'just now';if(s<3600)return `${Math.max(1,Math.round(s/60))}m ago`;if(s<86400)return `${Math.round(s/3600)}h ago`;if(s<604800)return `${Math.round(s/86400)}d ago`;return d.toLocaleDateString();}
async function request(payload=null){
  const options=payload?{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:cfg.csrf,...payload})}:{credentials:'same-origin',cache:'no-store'};
  const r=await fetch(cfg.radarSitesEndpoint,options),d=await r.json().catch(()=>null);
  if(!r.ok||!d?.ok)throw new Error(d?.error||'Connected Sites request failed.');
  return d;
}
function browserSnippet(site){
  const src=new URL(cfg.radarScriptUrl,window.location.origin).href;
  return `<script async src="${src}" data-vp3-key="${String(site.public_key||'')}"></script>`;
}
function serverEndpoint(site){
  const endpoint=new URL('radar-server-collect.php',new URL(cfg.radarSitesEndpoint,window.location.origin));
  endpoint.searchParams.set('key',String(site.public_key||''));
  return endpoint.href;
}
function phpSnippet(site,token){
  const endpoint=serverEndpoint(site);
  return `<?php
register_shutdown_function(static function (): void {
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return;

    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    $referrerHost = $referer !== '' ? (string)(parse_url($referer, PHP_URL_HOST) ?: '') : '';
    $payload = json_encode([
        'path' => $path,
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'status_code' => http_response_code(),
        'referrer_host' => $referrerHost,
        'user_agent' => $ua,
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) return;

    $url = '${endpoint}';
    $token = '${token}';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) return;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-VP3-Radar-Token: '.$token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 250,
            CURLOPT_TIMEOUT_MS => 500,
        ]);
        @curl_exec($ch);
        curl_close($ch);
        return;
    }

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-VP3-Radar-Token: ".$token."\r\n",
        'content' => $payload,
        'timeout' => 0.5,
        'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $context);
});
`;
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
function serverSetup(site){
  const id=Number(site.id||0),configured=!!site.server_token_configured,token=serverTokens.get(id)||'';
  return `<div class="profile-agent-radar-server"><div class="profile-agent-radar-server-head"><div><span>Server-side Radar</span><p>Captures known agents and bounded unknown automation even when the crawler never runs JavaScript.</p></div><div class="profile-agent-radar-server-actions"><b class="${configured?'configured':'off'}">${configured?'Token configured':'Not configured'}</b><button type="button" data-server-rotate="${id}">${configured?'Rotate Token':'Create Server Token'}</button>${configured?`<button type="button" data-server-revoke="${id}">Revoke</button>`:''}</div></div>${token?`<div class="profile-agent-radar-server-secret"><strong>New token created — copy this server snippet now.</strong><p>The token is shown only in this browser session after creation/rotation. Put this code in a server-side PHP bootstrap or shared template. Never place the token in HTML or client JavaScript.</p><code>${esc(phpSnippet(site,token))}</code><button type="button" data-copy-server="${id}">Copy PHP Snippet</button></div>`:configured?`<div class="profile-agent-radar-server-configured">Server-side Radar is configured. VP3 stores only the token hash, so the existing plaintext token cannot be shown again. Rotate it if you need a new install snippet.</div>`:`<div class="profile-agent-radar-server-configured">Create a token to reveal the one-time PHP server integration snippet.</div>`}</div>`;
}
function siteCard(site){
  const active=Number(site.is_active)===1,verified=!!site.verified_at;
  return `<article class="profile-agent-radar-site ${active?'active':'paused'}"><div class="profile-agent-radar-site-head"><div><span>${active?'Connected site':'Paused site'}</span><strong>${esc(site.label||site.domain)}</strong><small>${esc(site.domain)}</small></div><div class="profile-agent-radar-site-status"><b class="${verified?'verified':'pending'}">${verified?'Verified':'Awaiting signal'}</b><button type="button" data-site-toggle="${Number(site.id)}" data-site-active="${active?'0':'1'}">${active?'Pause':'Reactivate'}</button></div></div><div class="profile-agent-radar-site-stats"><span>Agent sessions <b>${Number(site.session_count||0).toLocaleString()}</b></span><span>Radar events <b>${Number(site.event_count||0).toLocaleString()}</b></span><span>Last agent event <b>${site.last_event_at?esc(relative(site.last_event_at)):'None yet'}</b></span></div><div class="profile-agent-radar-install"><div><span>Browser install snippet</span><p>Add this once before the closing <code>&lt;/body&gt;</code> tag on ${esc(site.domain)}. It detects maintained Radar signatures that execute JavaScript.</p></div><code>${esc(browserSnippet(site))}</code><button type="button" data-copy-site="${Number(site.id)}">Copy</button></div>${serverSetup(site)}</article>`;
}
function render(){
  const shell=host.querySelector('[data-radar-sites-shell]');
  if(!shell)return;
  if(!sitesState){shell.innerHTML='<div class="profile-agent-radar-sites-loading">Loading connected sites…</div>';return;}
  const sites=Array.isArray(sitesState.sites)?sitesState.sites:[];
  for(const id of [...serverTokens.keys()])if(!sites.some(s=>Number(s.id)===id&&s.server_token_configured))serverTokens.delete(id);
  shell.innerHTML=`<div class="profile-agent-radar-sites-head"><div><span>Connected Sites</span><h2>Extend Agent Radar to your websites</h2><p>Use the browser collector for JavaScript-capable agents and the authenticated server collector for crawlers that never render the page. Both feed the same Agent CRM and Radar timeline.</p></div><div class="profile-agent-radar-site-capacity">${esc(capacityLabel())}</div></div><form class="profile-agent-radar-site-form" data-radar-site-form><label><span>Website domain</span><input name="domain" placeholder="example.com" autocomplete="url" required></label><label><span>Label</span><input name="label" placeholder="Main website" maxlength="190"></label><button type="submit"${sitesState.can_add?'':' disabled'}>${sitesState.can_add?'Add Site':'Site Limit Reached'}</button></form>${!sitesState.can_add&&sitesState.limit!==null?'<div class="profile-agent-radar-sites-limit">Pause an active site or change the package entitlement before adding another site.</div>':''}<div class="profile-agent-radar-site-list">${sites.length?sites.map(siteCard).join(''):'<div class="profile-agent-radar-sites-empty">No external websites are connected yet.</div>'}</div><div class="profile-agent-radar-sites-privacy">Browser collection sends pathname and referrer host only and uses no cookies or fingerprinting. Server-side collection additionally transmits the incoming User-Agent transiently for classification plus method/status; raw User-Agent values are not stored. Human traffic is discarded by both collectors.</div>`;
}
async function load(silent=true){
  if(busy)return;busy=true;
  try{const d=await request();sitesState=d.state;ensureShell();render();if(!silent)setNotice('Connected Sites refreshed.');}
  catch(err){setNotice(err.message,true);}finally{busy=false;}
}
host.addEventListener('submit',async e=>{
  const form=e.target.closest('[data-radar-site-form]');if(!form)return;e.preventDefault();
  const f=new FormData(form),button=form.querySelector('button[type=submit]');if(button)button.disabled=true;
  try{const d=await request({action:'create',domain:String(f.get('domain')||''),label:String(f.get('label')||'')});sitesState=d.state;render();setNotice('Connected site saved. Install browser and/or server collection for that domain.');}
  catch(err){setNotice(err.message,true);}finally{if(button)button.disabled=false;}
});
host.addEventListener('click',async e=>{
  const toggle=e.target.closest('[data-site-toggle]');
  if(toggle){toggle.disabled=true;try{const d=await request({action:'set_active',property_id:Number(toggle.dataset.siteToggle||0),is_active:Number(toggle.dataset.siteActive||0)});sitesState=d.state;render();setNotice(Number(toggle.dataset.siteActive||0)?'Connected site reactivated.':'Connected site paused.');}catch(err){setNotice(err.message,true);toggle.disabled=false;}return;}
  const copy=e.target.closest('[data-copy-site]');
  if(copy){const site=(sitesState?.sites||[]).find(s=>Number(s.id)===Number(copy.dataset.copySite));if(!site)return;try{await navigator.clipboard.writeText(browserSnippet(site));copy.textContent='Copied';setTimeout(()=>{if(copy.isConnected)copy.textContent='Copy';},1200);}catch(err){setNotice('Copy failed. Select the browser install snippet manually.',true);}return;}
  const rotate=e.target.closest('[data-server-rotate]');
  if(rotate){const id=Number(rotate.dataset.serverRotate||0);rotate.disabled=true;try{const d=await request({action:'rotate_server_token',property_id:id});sitesState=d.state;serverTokens.set(id,String(d.server_token||''));render();setNotice('Server token created. Copy the PHP snippet now; VP3 will not store the plaintext token.');}catch(err){setNotice(err.message,true);rotate.disabled=false;}return;}
  const revoke=e.target.closest('[data-server-revoke]');
  if(revoke){const id=Number(revoke.dataset.serverRevoke||0);revoke.disabled=true;try{const d=await request({action:'revoke_server_token',property_id:id});sitesState=d.state;serverTokens.delete(id);render();setNotice('Server token revoked. Requests using the old token will no longer be accepted.');}catch(err){setNotice(err.message,true);revoke.disabled=false;}return;}
  const copyServer=e.target.closest('[data-copy-server]');
  if(copyServer){const id=Number(copyServer.dataset.copyServer||0),site=(sitesState?.sites||[]).find(s=>Number(s.id)===id),token=serverTokens.get(id)||'';if(!site||!token)return;try{await navigator.clipboard.writeText(phpSnippet(site,token));copyServer.textContent='Copied';setTimeout(()=>{if(copyServer.isConnected)copyServer.textContent='Copy PHP Snippet';},1200);}catch(err){setNotice('Copy failed. Select the PHP snippet manually.',true);}return;}
});
const observer=new MutationObserver(()=>ensureShell());
observer.observe(host,{childList:true});
ensureShell();
load(true);
timer=setInterval(()=>{if(document.visibilityState==='visible')load(true);},30000);
window.addEventListener('beforeunload',()=>{clearInterval(timer);observer.disconnect();serverTokens.clear();});
})();