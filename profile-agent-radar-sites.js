(() => {
'use strict';
const cfg=window.PROFILE_AGENT_PORTAL;
const host=document.getElementById('profileAgentRadar');
if(!cfg?.radarSitesEndpoint||!cfg?.radarScriptUrl||!cfg?.analyticsScriptUrl||!cfg?.csrf||!host)return;
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
function agentManifestUrl(site){
  const endpoint=new URL('agent-manifest.php',new URL(cfg.radarSitesEndpoint,window.location.origin));
  endpoint.searchParams.set('key',String(site.public_key||''));
  return endpoint.href;
}
function trackingSnippet(site){
  const analytics=new URL(cfg.analyticsScriptUrl,window.location.origin).href;
  const radar=new URL(cfg.radarScriptUrl,window.location.origin).href;
  const manifest=agentManifestUrl(site);
  const key=String(site.public_key||'');
  return `<link rel="alternate" type="application/vnd.vp3.agent+json" href="${manifest}">\n<script async src="${analytics}" data-vp3-key="${key}"></script>\n<script async src="${radar}" data-vp3-key="${key}"></script>`;
}
function serverEndpoint(site){
  const endpoint=new URL('radar-server-collect.php',new URL(cfg.radarSitesEndpoint,window.location.origin));
  endpoint.searchParams.set('key',String(site.public_key||''));
  return endpoint.href;
}
function phpSnippet(site,token){
  const endpoint=serverEndpoint(site);
  return `<?php
(static function (): void {
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return;

    // Keep normal human requests entirely local. Only likely automation asks
    // VP3 for a Radar/Gateway decision.
    if (!preg_match('/(?:chatgpt-user|oai-searchbot|gptbot|claude-user|claudebot|claude-searchbot|perplexity-user|perplexitybot|\\bbot\\b|crawler|spider|slurp|scrapy|headless|python-requests|curl\\/|wget\\/|httpclient)/i', $ua)) return;

    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    $referrerHost = $referer !== '' ? (string)(parse_url($referer, PHP_URL_HOST) ?: '') : '';
    $payload = json_encode([
        'path' => $path,
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'referrer_host' => $referrerHost,
        'user_agent' => $ua,
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) return;

    $url = '${endpoint}';
    $token = '${token}';
    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-VP3-Radar-Token: '.$token],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 250,
                CURLOPT_TIMEOUT_MS => 500,
            ]);
            $result = @curl_exec($ch);
            if (is_string($result)) $raw = $result;
            curl_close($ch);
        }
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\\r\\nX-VP3-Radar-Token: ".$token."\\r\\n",
            'content' => $payload,
            'timeout' => 0.5,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($url, false, $context);
        if (is_string($result)) $raw = $result;
    }

    // Fail open if VP3 is unreachable or returns an invalid response.
    if (!is_string($raw) || $raw === '') return;
    $decoded = json_decode($raw, true);
    $decision = is_array($decoded) && is_array($decoded['decision'] ?? null) ? $decoded['decision'] : null;
    if (!is_array($decision) || !array_key_exists('allowed', $decision) || $decision['allowed'] !== false) return;

    $status = (int)($decision['status_code'] ?? 403);
    if ($status < 400 || $status > 599) $status = 403;
    http_response_code($status);
    header('X-VP3-Agent-Gateway: '.preg_replace('/[^a-z_-]/i', '', (string)($decision['action'] ?? 'block')));
    if ($status === 429) {
        $retry = max(60, min(3600, (int)($decision['retry_after'] ?? 1800)));
        header('Retry-After: '.$retry);
        exit('Too many automated requests.');
    }
    exit('Automated access denied.');
})();
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
  return `<div class="profile-agent-radar-server"><div class="profile-agent-radar-server-head"><div><span>Server-side Radar + Gateway</span><p>Captures known agents and bounded unknown automation before page output, including crawlers that never run JavaScript. Contact policies can allow, monitor, limit or block the request.</p></div><div class="profile-agent-radar-server-actions"><b class="${configured?'configured':'off'}">${configured?'Token configured':'Not configured'}</b><button type="button" data-server-rotate="${id}">${configured?'Rotate Token':'Create Server Token'}</button>${configured?`<button type="button" data-server-revoke="${id}">Revoke</button>`:''}</div></div>${token?`<div class="profile-agent-radar-server-secret"><strong>New token created — copy this server gate now.</strong><p>The token is shown only in this browser session after creation/rotation. Put this code at the top of a shared PHP bootstrap before output. Never place the token in HTML or client JavaScript. The gate fails open if VP3 cannot be reached.</p><code>${esc(phpSnippet(site,token))}</code><button type="button" data-copy-server="${id}">Copy PHP Gate</button></div>`:configured?`<div class="profile-agent-radar-server-configured">Server-side Radar + Gateway is configured. VP3 stores only the token hash, so the existing plaintext token cannot be shown again. Rotate it if you need a new install snippet.</div>`:`<div class="profile-agent-radar-server-configured">Create a token to reveal the one-time PHP server gate.</div>`}</div>`;
}
function siteCard(site){
  const active=Number(site.is_active)===1,verified=!!site.verified_at,total=Number(site.session_count||0),human=Number(site.human_session_count||0),agents=Number(site.agent_session_count||0);
  return `<article class="profile-agent-radar-site ${active?'active':'paused'}"><div class="profile-agent-radar-site-head"><div><span>${active?'Connected site':'Paused site'}</span><strong>${esc(site.label||site.domain)}</strong><small>${esc(site.domain)}</small></div><div class="profile-agent-radar-site-status"><b class="${verified?'verified':'pending'}">${verified?'Verified':'Awaiting signal'}</b><button type="button" data-site-toggle="${Number(site.id)}" data-site-active="${active?'0':'1'}">${active?'Pause':'Reactivate'}</button></div></div><div class="profile-agent-radar-site-stats"><span>Sessions <b>${total.toLocaleString()}</b></span><span>Human <b>${human.toLocaleString()}</b></span><span>Agents <b>${agents.toLocaleString()}</b></span><span>Events <b>${Number(site.event_count||0).toLocaleString()}</b></span><span>Last activity <b>${site.last_event_at?esc(relative(site.last_event_at)):'None yet'}</b></span></div><div class="profile-agent-radar-install"><div><span>VP3 Tracking + Agent Manifest</span><p>Add this once near the end of ${esc(site.domain)}. It advertises the public Agent Manifest, adds anonymous human Analytics, and identifies maintained AI/automation signatures that execute JavaScript.</p></div><code>${esc(trackingSnippet(site))}</code><button type="button" data-copy-site="${Number(site.id)}">Copy VP3 Tracking</button></div>${serverSetup(site)}</article>`;
}
function render(){
  const shell=host.querySelector('[data-radar-sites-shell]');
  if(!shell)return;
  if(!sitesState){shell.innerHTML='<div class="profile-agent-radar-sites-loading">Loading connected sites…</div>';return;}
  const sites=Array.isArray(sitesState.sites)?sitesState.sites:[];
  for(const id of [...serverTokens.keys()])if(!sites.some(s=>Number(s.id)===id&&s.server_token_configured))serverTokens.delete(id);
  shell.innerHTML=`<div class="profile-agent-radar-sites-head"><div><span>Connected Sites</span><h2>Extend VP3 Analytics + Agent Radar</h2><p>One browser install adds the public Agent Manifest, anonymous human analytics and Agent Radar. Add the authenticated server gate when you also want non-JavaScript crawler detection and enforceable Agent Gateway policies.</p></div><div class="profile-agent-radar-site-capacity">${esc(capacityLabel())}</div></div><form class="profile-agent-radar-site-form" data-radar-site-form><label><span>Website domain</span><input name="domain" placeholder="example.com" autocomplete="url" required></label><label><span>Label</span><input name="label" placeholder="Main website" maxlength="190"></label><button type="submit"${sitesState.can_add?'':' disabled'}>${sitesState.can_add?'Add Site':'Site Limit Reached'}</button></form>${!sitesState.can_add&&sitesState.limit!==null?'<div class="profile-agent-radar-sites-limit">Pause an active site or change the package entitlement before adding another site.</div>':''}<div class="profile-agent-radar-site-list">${sites.length?sites.map(siteCard).join(''):'<div class="profile-agent-radar-sites-empty">No external websites are connected yet.</div>'}</div><div class="profile-agent-radar-sites-privacy">Human Analytics uses a short-lived session-scoped random ID and stores no raw IP or fingerprint. Browser Agent Radar stores no human sessions. The public Agent Manifest contains no CRM, Analytics, Gateway rules, token balances or HomeServer data.</div>`;
}
async function load(silent=true){
  if(busy)return;busy=true;
  try{const d=await request();sitesState=d.state;ensureShell();render();if(!silent)setNotice('Connected Sites refreshed.');}
  catch(err){setNotice(err.message,true);}finally{busy=false;}
}
host.addEventListener('submit',async e=>{
  const form=e.target.closest('[data-radar-site-form]');if(!form)return;e.preventDefault();
  const f=new FormData(form),button=form.querySelector('button[type=submit]');if(button)button.disabled=true;
  try{const d=await request({action:'create',domain:String(f.get('domain')||''),label:String(f.get('label')||'')});sitesState=d.state;render();setNotice('Connected site saved. Copy VP3 Tracking, then add the optional server gate for full Agent Gateway enforcement.');}
  catch(err){setNotice(err.message,true);}finally{if(button)button.disabled=false;}
});
host.addEventListener('click',async e=>{
  const toggle=e.target.closest('[data-site-toggle]');
  if(toggle){toggle.disabled=true;try{const d=await request({action:'set_active',property_id:Number(toggle.dataset.siteToggle||0),is_active:Number(toggle.dataset.siteActive||0)});sitesState=d.state;render();setNotice(Number(toggle.dataset.siteActive||0)?'Connected site reactivated.':'Connected site paused.');}catch(err){setNotice(err.message,true);toggle.disabled=false;}return;}
  const copy=e.target.closest('[data-copy-site]');
  if(copy){const site=(sitesState?.sites||[]).find(s=>Number(s.id)===Number(copy.dataset.copySite));if(!site)return;try{await navigator.clipboard.writeText(trackingSnippet(site));copy.textContent='Copied';setTimeout(()=>{if(copy.isConnected)copy.textContent='Copy VP3 Tracking';},1200);}catch(err){setNotice('Copy failed. Select the VP3 Tracking snippet manually.',true);}return;}
  const rotate=e.target.closest('[data-server-rotate]');
  if(rotate){const id=Number(rotate.dataset.serverRotate||0);rotate.disabled=true;try{const d=await request({action:'rotate_server_token',property_id:id});sitesState=d.state;serverTokens.set(id,String(d.server_token||''));render();setNotice('Server token created. Copy the PHP gate now; VP3 will not store the plaintext token.');}catch(err){setNotice(err.message,true);rotate.disabled=false;}return;}
  const revoke=e.target.closest('[data-server-revoke]');
  if(revoke){const id=Number(revoke.dataset.serverRevoke||0);revoke.disabled=true;try{const d=await request({action:'revoke_server_token',property_id:id});sitesState=d.state;serverTokens.delete(id);render();setNotice('Server token revoked. Requests using the old token will no longer be accepted.');}catch(err){setNotice(err.message,true);revoke.disabled=false;}return;}
  const copyServer=e.target.closest('[data-copy-server]');
  if(copyServer){const id=Number(copyServer.dataset.copyServer||0),site=(sitesState?.sites||[]).find(s=>Number(s.id)===id),token=serverTokens.get(id)||'';if(!site||!token)return;try{await navigator.clipboard.writeText(phpSnippet(site,token));copyServer.textContent='Copied';setTimeout(()=>{if(copyServer.isConnected)copyServer.textContent='Copy PHP Gate';},1200);}catch(err){setNotice('Copy failed. Select the PHP gate manually.',true);}return;}
});
const observer=new MutationObserver(()=>ensureShell());
observer.observe(host,{childList:true});
ensureShell();
load(true);
timer=setInterval(()=>{if(document.visibilityState==='visible')load(true);},30000);
window.addEventListener('beforeunload',()=>{clearInterval(timer);observer.disconnect();serverTokens.clear();});
})();