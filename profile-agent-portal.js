(() => {
'use strict';
const cfg=window.PROFILE_AGENT_PORTAL;
const root=document.getElementById('profileAgentPortal');
const app=document.querySelector('.profile-agent-app')||root;
if(!cfg?.endpoint||!cfg?.csrf||!root||!app)return;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const notice=document.getElementById('profileAgentNotice');
const service=document.getElementById('profileAgentServiceStatus');
const metrics=document.getElementById('profileAgentMetrics');
const attentionHost=document.getElementById('profileAgentAttention');
const conversationsHost=document.getElementById('profileAgentConversations');
const threadHost=document.getElementById('profileAgentThread');
const visitorsHost=document.getElementById('profileAgentVisitors');
const radarHost=document.getElementById('profileAgentRadar');
const agentHost=document.getElementById('profileAgentSettings');
const knowledgeHost=document.getElementById('profileAgentKnowledge');
const profileHost=document.getElementById('profileAgentProfileSettings');
const analyticsHost=document.getElementById('profileAgentAnalytics');
let state=null,radarState=null,radarFilter='all',selectedConversation=0,selectedSession=0,refreshTimer=null,requestBusy=false;
const setNotice=(message='',error=false)=>{notice.textContent=message;notice.className=`profile-agent-notice${error?' error':''}`;};
async function req(action,payload=null){
  const post=payload!==null;
  const options=post?{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,csrf_token:cfg.csrf,...payload})}:{credentials:'same-origin',cache:'no-store'};
  const url=post?cfg.endpoint:`${cfg.endpoint}?action=${encodeURIComponent(action)}`;
  const r=await fetch(url,options),d=await r.json().catch(()=>null);
  if(!r.ok||!d?.ok)throw new Error(d?.error||'Profile Agent request failed.');
  return d;
}
async function radarReq(){
  if(!cfg.radarEndpoint)return null;
  const r=await fetch(cfg.radarEndpoint,{credentials:'same-origin',cache:'no-store'}),d=await r.json().catch(()=>null);
  if(!r.ok||!d?.ok)throw new Error(d?.error||'Agent Radar request failed.');
  return d.radar||null;
}
async function uploadProfileMedia(mediaType,file){
  if(!file)throw new Error('Choose an image first.');
  if(file.size>5*1024*1024)throw new Error('Profile images must be 5 MB or smaller.');
  const form=new FormData();form.set('action','save_profile_media');form.set('csrf_token',cfg.csrf);form.set('media_type',mediaType);form.set('media_file',file);
  const r=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',body:form}),d=await r.json().catch(()=>null);
  if(!r.ok||!d?.ok)throw new Error(d?.error||'Profile image upload failed.');
  return d;
}
function parseDate(value){if(!value)return null;const iso=String(value).replace(' ','T');const d=new Date(iso);return Number.isNaN(d.getTime())?null:d;}
function relative(value){const d=parseDate(value);if(!d)return String(value||'');const seconds=Math.round((Date.now()-d.getTime())/1000);if(seconds<60)return 'just now';if(seconds<3600)return `${Math.max(1,Math.round(seconds/60))}m ago`;if(seconds<86400)return `${Math.round(seconds/3600)}h ago`;if(seconds<604800)return `${Math.round(seconds/86400)}d ago`;return d.toLocaleDateString();}
function recentlyActive(value){const d=parseDate(value);return !!d&&(Date.now()-d.getTime())<=5*60*1000;}
function activateTab(name){
  const tabs=[...document.querySelectorAll('[data-pa-tab]')],views=[...root.querySelectorAll('[data-pa-view]')];
  if(!views.some(v=>v.dataset.paView===name))name='inbox';
  tabs.forEach(b=>b.classList.toggle('active',b.dataset.paTab===name));
  views.forEach(v=>v.classList.toggle('active',v.dataset.paView===name));
  if(root.scrollTop>0)root.scrollTo({top:0,behavior:'auto'});
  try{localStorage.setItem('profile-agent-portal-tab',name);}catch(e){}
}
function renderService(){
  const s=state?.public_agent_status||{};
  const map={
    live:['Live',`Public Profile Agent is live${s.agent_name?` · ${s.agent_name}`:''}.`,'live'],
    profile_private:['Profile unpublished','Publish your profile to make the Profile Agent available.','warn'],
    public_disabled:['Profile Agent off','Turn on the public Profile Agent switch to accept visitor conversations.','warn'],
    no_agent_selected:['Choose an agent','Select an active agent to represent your public profile.','warn'],
    agent_missing:['Agent unavailable','The selected Profile Agent no longer exists. Choose another agent.','warn'],
    agent_inactive:['Agent inactive','Activate the selected agent before making the Profile Agent public.','warn']
  };
  const row=map[s.reason]||map.public_disabled;
  service.className=`profile-agent-service-status ${row[2]}`;
  service.textContent=`${row[0]} · ${row[1]}`;
}
function renderMetrics(){
  const a=state?.analytics||{};
  const rows=[['Active now',a.active_visitors||0],['Profile views',a.total_views||0],['Conversations',a.total_conversations||0],['Open inbox',a.open_conversations||0],['Needs attention',a.needs_attention||0]];
  metrics.innerHTML=rows.map(([label,value])=>`<article class="profile-agent-metric"><strong>${Number(value).toLocaleString()}</strong><span>${esc(label)}</span></article>`).join('');
}
function attentionHtml(){
  const rows=Array.isArray(state?.attention)?state.attention:[];
  if(!rows.length)return '';
  return `<div class="profile-agent-subhead">Needs attention</div>${rows.map(a=>`<article class="profile-agent-attention-item ${Number(a.priority)>=80?'high':''}"><div class="profile-agent-row-main"><strong>${esc(a.headline||'Needs attention')}</strong><p>${esc(a.summary||'')}</p><small>${esc(a.visitor_label||'Visitor')} · ${relative(a.created_at)}</small></div><div class="profile-agent-row-actions">${Number(a.source_conversation_id||0)>0?`<button type="button" data-open-conversation="${Number(a.source_conversation_id)}">Open</button>`:''}<button type="button" data-attention="${Number(a.id)}" data-attention-action="ignored">Ignore</button>${Number(a.priority)>=80?`<button type="button" data-attention="${Number(a.id)}" data-attention-action="snooze">Later</button>`:''}</div></article>`).join('')}`;
}
function renderInbox(){
  attentionHost.innerHTML=attentionHtml();
  const rows=Array.isArray(state?.conversations)?state.conversations:[];
  conversationsHost.innerHTML=`<div class="profile-agent-subhead">Conversations</div>`+(rows.length?rows.map(c=>`<article class="profile-agent-conversation-row ${Number(c.id)===selectedConversation?'selected':''}" data-open-conversation="${Number(c.id)}"><div class="profile-agent-row-main"><strong>${esc(c.visitor_label||'Visitor')}</strong><p>${esc(c.last_summary||'Conversation')}</p><small>${relative(c.last_message_at)} · started ${relative(c.started_at)}</small></div><div class="profile-agent-row-actions"><span class="profile-agent-badge ${esc(c.status||'open')}">${esc(String(c.status||'open').replace('_',' '))}</span></div></article>`).join(''):`<div class="profile-agent-empty">No visitor conversations yet.</div>`);
}
function visitorAvatar(v){if(v?.identity_disclosed&&v.avatar_url)return `<img src="${esc(v.avatar_url)}" alt="">`;return `<span>${esc(String(v?.visitor_label||'Visitor').charAt(0).toUpperCase()||'?')}</span>`;}
function visitorMeta(v){if(v?.identity_disclosed){const bits=[];if(v.username)bits.push(`@${v.username}`);if(v.role_label)bits.push(v.role_label);if(v.relationship_scope&&v.relationship_scope!=='none')bits.push(v.relationship_scope);return bits.join(' · ');}return v?.signed_in?'Signed-in member · identity private':'Guest visitor';}
function renderVisitors(){
  const rows=Array.isArray(state?.visits)?state.visits:[];
  visitorsHost.innerHTML=rows.length?rows.map(v=>{const live=!!v.active_now||recentlyActive(v.last_seen_at),selected=Number(v.profile_session_id||v.id)===selectedSession;return `<article class="profile-agent-visitor-row ${selected?'selected':''}" data-profile-session="${Number(v.profile_session_id||v.id||0)}"><div class="profile-agent-visitor-avatar">${visitorAvatar(v)}</div><div class="profile-agent-row-main"><strong>${esc(v.visitor_label||'Visitor')}</strong><p>${esc(visitorMeta(v))}</p><small>${Number(v.view_count||0)} profile view${Number(v.view_count||0)===1?'':'s'}${v.last_message_at?' · has chatted':''} · first seen ${relative(v.first_seen_at)} · last seen ${relative(v.last_seen_at)}</small>${v.profile_url?`<a href="${esc(v.profile_url)}" target="_blank" rel="noopener">View profile ↗</a>`:''}</div><div class="profile-agent-presence ${live?'live':''}">${live?'Active now':v.signed_in?'Member':'Guest'}</div></article>`;}).join(''):`<div class="profile-agent-empty">No profile visitors yet.</div>`;
}
function radarClassLabel(value){return ({human:'Human',ai_user_agent:'AI Agent',ai_search:'Search',ai_crawler:'Crawler',automated_unknown:'Unknown'})[value]||String(value||'Unknown').replaceAll('_',' ');}
function radarMatches(value){return radarFilter==='all'||radarFilter===value;}
function radarRiskClass(score){score=Number(score||0);return score>=70?'high':score>=40?'medium':'low';}
function radarPaths(contactId){
  const rows=(radarState?.events||[]).filter(e=>Number(e.agent_contact_id)===Number(contactId));
  return [...new Set(rows.map(e=>String(e.path||'')).filter(Boolean))].slice(0,3);
}
function radarContactCards(){
  const rows=[];
  if(radarMatches('human')){
    (state?.visits||[]).slice(0,60).forEach(v=>rows.push({kind:'human',date:v.last_seen_at,html:`<article class="profile-agent-radar-contact human"><div class="profile-agent-radar-contact-head"><div class="profile-agent-visitor-avatar">${visitorAvatar(v)}</div><div><span class="profile-agent-radar-type human">Human</span><strong>${esc(v.visitor_label||'Visitor')}</strong><small>${esc(visitorMeta(v))}</small></div></div><div class="profile-agent-radar-scores"><span>Status <b>${v.signed_in?'Member':'Guest'}</b></span><span>Views <b>${Number(v.view_count||0)}</b></span><span>Last seen <b>${esc(relative(v.last_seen_at))}</b></span></div><div class="profile-agent-radar-paths"><span>Recent page</span><code>Public profile</code></div></article>`}));
  }
  (radarState?.contacts||[]).filter(c=>radarMatches(c.visitor_class)).forEach(c=>{
    const paths=radarPaths(c.id),risk=Number(c.risk_score||0),known=String(c.verification_status||'unknown')==='known';
    const status=known?'Known signature':String(c.verification_status||'unknown').replaceAll('_',' ');
    rows.push({kind:c.visitor_class,date:c.last_seen_at,html:`<article class="profile-agent-radar-contact ${esc(radarRiskClass(risk))}"><div class="profile-agent-radar-contact-head"><div class="profile-agent-radar-avatar">${esc(String(c.display_name||'A').charAt(0).toUpperCase())}</div><div><span class="profile-agent-radar-type ${esc(c.visitor_class)}">${esc(radarClassLabel(c.visitor_class))}</span><strong>${esc(c.display_name||'Automated agent')}</strong><small>${esc(c.operator_name||'Unidentified operator')}${c.registry_purpose?` · ${esc(c.registry_purpose)}`:''}</small></div><span class="profile-agent-radar-verification ${known?'known':'unknown'}">${esc(status)} · ${Number(c.confidence_score||0)}%</span></div><div class="profile-agent-radar-scores"><span>Trust <b>${Number(c.trust_score||0)}/100</b></span><span class="risk ${esc(radarRiskClass(risk))}">Risk <b>${risk}/100</b></span><span>Relationship <b>${esc(String(c.relationship_status||'new').replaceAll('_',' '))}</b></span><span>Sessions <b>${Number(c.session_count||0)}</b></span><span>Views <b>${Number(c.page_view_count||0)}</b></span><span>Value <b>${Number(c.value_score||0)}/100</b></span></div>${c.inferred_intent?`<div class="profile-agent-radar-intent"><span>Inferred intent</span><strong>${esc(c.inferred_intent)}</strong><small>${Number(c.intent_confidence||0)}% confidence</small></div>`:''}<div class="profile-agent-radar-paths"><span>Recent pages</span>${paths.length?paths.map(p=>`<code>${esc(p)}</code>`).join(''):'<small>No page history yet.</small>'}</div><footer>First seen ${esc(relative(c.first_seen_at))} · Last seen ${esc(relative(c.last_seen_at))} · ${Number(c.request_count||0)} request${Number(c.request_count||0)===1?'':'s'}</footer></article>`});
  });
  rows.sort((a,b)=>(parseDate(b.date)?.getTime()||0)-(parseDate(a.date)?.getTime()||0));
  return rows.map(x=>x.html).join('');
}
function radarTimeline(){
  const rows=[];
  if(radarMatches('human')){
    (state?.activity||[]).forEach(e=>rows.push({date:e.created_at,html:`<article class="profile-agent-radar-event human"><span class="profile-agent-radar-event-dot"></span><div><strong>${esc(e.visitor_label||'Human visitor')} · ${esc(String(e.event_type||'profile activity').replaceAll('_',' '))}</strong><p>${e.referrer_host?`Referrer ${esc(e.referrer_host)}`:'Public profile activity'}</p><small>${esc(relative(e.created_at))}</small></div><span class="profile-agent-radar-type human">Human</span></article>`}));
  }
  (radarState?.events||[]).filter(e=>radarMatches(e.visitor_class)).forEach(e=>rows.push({date:e.occurred_at,html:`<article class="profile-agent-radar-event ${esc(radarRiskClass(e.risk_score))}"><span class="profile-agent-radar-event-dot"></span><div><strong>${esc((e.operator_name?e.operator_name+' · ':'')+(e.display_name||'Automated agent'))}</strong><p>${esc(e.path||'/')} · ${esc(String(e.event_type||'activity').replaceAll('_',' '))} · risk ${Number(e.risk_score||0)}/100</p><small>${esc(relative(e.occurred_at))} · ${esc(e.property_label||'VP3 Profile')} · ${esc(e.severity||'low')}</small></div><span class="profile-agent-radar-type ${esc(e.visitor_class)}">${esc(radarClassLabel(e.visitor_class))}</span></article>`}));
  rows.sort((a,b)=>(parseDate(b.date)?.getTime()||0)-(parseDate(a.date)?.getTime()||0));
  return rows.slice(0,80).map(x=>x.html).join('');
}
function radarSessions(){
  const rows=[];
  if(radarMatches('human')){
    (state?.visits||[]).forEach(v=>rows.push({date:v.last_seen_at,html:`<div class="profile-agent-radar-session"><div><strong>${esc(v.visitor_label||'Visitor')}</strong><small>Human · ${v.signed_in?'member':'guest'}</small></div><code>Public profile</code><span>${Number(v.view_count||0)} view${Number(v.view_count||0)===1?'':'s'}</span><span>${esc(relative(v.first_seen_at))} → ${esc(relative(v.last_seen_at))}</span></div>`}));
  }
  (radarState?.sessions||[]).filter(s=>radarMatches(s.visitor_class)).forEach(s=>rows.push({date:s.last_seen_at,html:`<div class="profile-agent-radar-session"><div><strong>${esc((s.operator_name?s.operator_name+' · ':'')+(s.display_name||'Automated agent'))}</strong><small>${esc(radarClassLabel(s.visitor_class))} · ${esc(s.verification_status||'unknown')}</small></div><code>${esc(s.entry_path||'/')}${s.exit_path&&s.exit_path!==s.entry_path?` → ${esc(s.exit_path)}`:''}</code><span>${Number(s.page_view_count||0)} view${Number(s.page_view_count||0)===1?'':'s'} · ${Number(s.request_count||0)} req.</span><span>${esc(relative(s.started_at))} → ${esc(relative(s.last_seen_at))}</span></div>`}));
  rows.sort((a,b)=>(parseDate(b.date)?.getTime()||0)-(parseDate(a.date)?.getTime()||0));
  return rows.slice(0,60).map(x=>x.html).join('');
}
function renderRadar(){
  if(!radarHost)return;
  const radar=radarState||{},rs=radar.stats||{},humanSessions=Number(state?.analytics?.visitor_sessions||0);
  if(radarState&&!radar.ready){radarHost.innerHTML='<div class="profile-agent-panel"><div class="profile-agent-empty">Agent Radar storage is not ready. Run the VP3 upgrade before using Radar.</div></div>';return;}
  const filters=[['all','All'],['human','Human'],['ai_user_agent','AI Agent'],['ai_search','Search'],['ai_crawler','Crawler'],['automated_unknown','Unknown']];
  const contacts=radarContactCards(),timeline=radarTimeline(),sessions=radarSessions();
  radarHost.innerHTML=`<div class="profile-agent-radar-hero"><div><span>Agent Radar</span><h2>Who and what is visiting your VP3 profile</h2><p>Human visitors stay in the privacy-preserving visitor CRM. Automated traffic is classified into Agent CRM contacts with trust, risk, relationship and activity history.</p></div><button type="button" id="profileAgentRadarRefresh">Refresh Radar</button></div><div class="profile-agent-radar-metrics"><article><strong>${humanSessions.toLocaleString()}</strong><span>Human sessions</span></article><article><strong>${Number(rs.agent_contacts||0).toLocaleString()}</strong><span>Agent contacts</span></article><article><strong>${Number(rs.events_24h||0).toLocaleString()}</strong><span>Agent events · 24h</span></article><article class="${Number(rs.high_risk_24h||0)>0?'alert':''}"><strong>${Number(rs.high_risk_24h||0).toLocaleString()}</strong><span>High risk · 24h</span></article><article><strong>${Number(rs.known_agents||0).toLocaleString()}</strong><span>Known signatures</span></article></div><div class="profile-agent-radar-filters" role="toolbar" aria-label="Radar visitor filters">${filters.map(([key,label])=>`<button type="button" data-radar-filter="${key}" class="${radarFilter===key?'active':''}">${label}</button>`).join('')}</div><div class="profile-agent-radar-layout"><section class="profile-agent-panel profile-agent-radar-contacts"><div class="profile-agent-section-head"><div><span>Contacts</span><h2>Visitor identities</h2></div></div><div class="profile-agent-radar-contact-list">${contacts||'<div class="profile-agent-empty">No contacts match this filter yet.</div>'}</div></section><section class="profile-agent-panel profile-agent-radar-timeline"><div class="profile-agent-section-head"><div><span>Activity</span><h2>Recent timeline</h2></div></div><div class="profile-agent-radar-event-list">${timeline||'<div class="profile-agent-empty">No activity matches this filter yet.</div>'}</div></section></div><section class="profile-agent-panel profile-agent-radar-sessions"><div class="profile-agent-section-head"><div><span>Sessions</span><h2>Recent sessions and paths</h2></div></div><div class="profile-agent-radar-session-head"><span>Identity</span><span>Path</span><span>Traffic</span><span>Window</span></div><div>${sessions||'<div class="profile-agent-empty">No sessions match this filter yet.</div>'}</div></section><div class="profile-agent-radar-note">Known signature means the request matched a maintained Agent Radar User-Agent signature. It does not mean cryptographic identity verification. Radar does not display or persist raw IP addresses or raw User-Agent strings.</div>`;
}
function agentOptions(selected){
  const agents=Array.isArray(state?.agents)?state.agents:[];
  return `<option value="0">Choose an agent</option>`+agents.map(a=>`<option value="${Number(a.id)}"${Number(a.id)===Number(selected)?' selected':''}>${esc(a.display_name)}${Number(a.is_active)?'':' · inactive'}</option>`).join('');
}
function renderAgentSettings(){
  const p=state?.profile||{},s=state?.public_agent_status||{};
  const selected=Number(p.profile_agent_id||s.suggested_agent_id||0);
  agentHost.innerHTML=`<div class="profile-agent-panel-grid"><form class="profile-agent-card" id="paAgentForm"><h2>Public Profile Agent</h2><p>This is the single service control for your public-facing agent. The selected agent must be active.</p><label class="profile-agent-field"><span>Agent</span><select name="profile_agent_id">${agentOptions(selected)}</select></label><label class="profile-agent-toggle"><input type="checkbox" name="profile_agent_enabled"${Number(p.profile_agent_enabled)?' checked':''}> Accept public Profile Agent conversations</label><label class="profile-agent-field"><span>Greeting</span><textarea name="profile_agent_greeting" maxlength="500">${esc(p.profile_agent_greeting||'')}</textarea></label><label class="profile-agent-field"><span>Public agent instructions</span><textarea name="profile_agent_instructions" maxlength="4000">${esc(p.profile_agent_instructions||'')}</textarea></label><div class="profile-agent-form-actions"><button class="primary" type="submit">Save Profile Agent</button></div></form><div class="profile-agent-card"><h3>Service status</h3><p>${esc(service.textContent||'')}</p><div><strong>${s.agent_name?esc(s.agent_name):'No active service agent'}</strong></div><p>Public service requires a published profile, the public switch above, and an active selected agent. Private Agent Chat remains separate.</p>${state.profile_url?`<div class="profile-agent-form-actions"><a class="profile-agent-view-profile" href="${esc(state.profile_url)}?preview=1" target="_blank" rel="noopener">Preview as visitor ↗</a></div>`:''}</div></div>`;
}
function audienceOptions(value){return [['inherit','Existing visibility'],['private','Private'],['connections','Connections'],['collaborators','Collaborators'],['public','Public']].map(([v,l])=>`<option value="${v}"${v===value?' selected':''}>${l}</option>`).join('');}
function renderKnowledge(){
  const policies=state?.policies||{};
  knowledgeHost.innerHTML=`<div class="profile-agent-card"><h2>Knowledge Access</h2><p>Your Profile Agent can use your personal Knowledge Base only when you explicitly allow Knowledge here and the selected audience permits that visitor. Unpublished personal notes are never exposed by inherited visibility alone.</p><div class="profile-agent-policy-list">${Object.entries(policies).map(([type,x])=>`<div class="profile-agent-policy" data-policy="${esc(type)}"><div><strong>${esc(x.label)}</strong><small>${esc(x.description||'')}</small></div><label class="profile-agent-toggle"><input type="checkbox" data-policy-allow${x.profile_agent_allowed?' checked':''}> Allow</label><select data-policy-audience>${audienceOptions(x.audience_scope)}</select></div>`).join('')}</div></div>`;
}
const urlField=(p,name,label)=>`<label class="profile-agent-field"><span>${esc(label)}</span><input type="url" name="${name}" value="${esc(p[name]||'')}"></label>`;
function renderProfileSettings(){
  const p=state?.profile||{},media=state?.profile_media||{},url=state?.profile_url||state?.profile_url_example||'/username';
  const avatar=media.avatar_url||'',cover=media.cover_url||'';
  profileHost.innerHTML=`<div class="profile-agent-media-grid"><form class="profile-agent-media-card" data-profile-media-form="avatar"><div class="profile-agent-media-preview avatar">${avatar?`<img src="${esc(avatar)}" alt="Current profile image">`:`<span>${esc(String(p.display_name||'P').charAt(0).toUpperCase())}</span>`}</div><div><h3>Profile image</h3><p>JPG, PNG or WEBP · up to 5 MB.</p><input type="file" name="media_file" accept="image/jpeg,image/png,image/webp" required><button type="submit">Upload profile image</button></div></form><form class="profile-agent-media-card cover" data-profile-media-form="cover"><div class="profile-agent-media-preview cover">${cover?`<img src="${esc(cover)}" alt="Current cover image">`:'<span>Full-width cover image</span>'}</div><div><h3>Cover image</h3><p>Displayed full width across the top of your public profile.</p><input type="file" name="media_file" accept="image/jpeg,image/png,image/webp" required><button type="submit">Upload cover image</button></div></form></div><form class="profile-agent-card" id="paProfileForm"><h2>Public Profile</h2><p>This is the identity visitors see before they start a Profile Agent conversation.</p><label class="profile-agent-field"><span>Username</span><input name="username" maxlength="60" required value="${esc(p.username||'')}" placeholder="username"></label><code class="profile-agent-url">${esc(new URL(url,window.location.origin).href)}</code><label class="profile-agent-field"><span>Bio</span><textarea name="bio" maxlength="4000">${esc(p.bio||'')}</textarea></label><div class="profile-agent-panel-grid">${urlField(p,'website_url','Website')}${urlField(p,'instagram_url','Instagram')}${urlField(p,'tiktok_url','TikTok')}${urlField(p,'youtube_url','YouTube')}${urlField(p,'spotify_url','Spotify')}${urlField(p,'apple_music_url','Apple Music')}</div><label class="profile-agent-toggle"><input type="checkbox" name="is_public"${Number(p.is_public)?' checked':''}> Publish my profile</label><label class="profile-agent-toggle"><input type="checkbox" name="share_visit_identity"${Number(p.share_visit_identity)?' checked':''}> Let signed-in profiles know when I visit them</label><div class="profile-agent-form-actions"><button class="primary" type="submit">Save Profile</button>${state.profile_url?`<a class="profile-agent-view-profile" href="${esc(state.profile_url)}?preview=1" target="_blank" rel="noopener">View as visitor ↗</a>`:''}</div></form>`;
}
function renderAnalytics(){
  const a=state?.analytics||{};
  const responsePct=Number(a.total_conversations||0)>0?Math.round((Number(a.owner_joined||0)/Number(a.total_conversations||1))*100):0;
  analyticsHost.innerHTML=`<div class="profile-agent-card"><h2>Profile Agent Analytics</h2><p>A service-level snapshot from your existing visitor, conversation, and attention records.</p><div class="profile-agent-analytics-grid"><article class="profile-agent-analytics-card"><strong>${Number(a.total_views||0).toLocaleString()}</strong><span>Total profile views</span></article><article class="profile-agent-analytics-card"><strong>${Number(a.visitor_sessions||0).toLocaleString()}</strong><span>Visitor sessions</span></article><article class="profile-agent-analytics-card"><strong>${Number(a.active_visitors||0).toLocaleString()}</strong><span>Active in last 5 minutes</span></article><article class="profile-agent-analytics-card"><strong>${Number(a.total_conversations||0).toLocaleString()}</strong><span>Total conversations</span></article><article class="profile-agent-analytics-card"><strong>${Number(a.open_conversations||0).toLocaleString()}</strong><span>Open conversations</span></article><article class="profile-agent-analytics-card"><strong>${responsePct}%</strong><span>Owner joined conversations</span></article></div></div>`;
}
function renderAll(){renderService();renderMetrics();renderInbox();renderVisitors();renderRadar();renderAgentSettings();renderKnowledge();renderProfileSettings();renderAnalytics();const unread=Number(state?.notifications?.unread||0),bell=document.getElementById('chatNotificationButton');if(bell){let badge=bell.querySelector(':scope > span');if(unread>0){if(!badge){badge=document.createElement('span');bell.appendChild(badge);}badge.textContent=unread>99?'99+':String(unread);}else badge?.remove();}bindDynamic();}
async function refresh(silent=false){
  if(requestBusy)return;requestBusy=true;
  try{const [d,r]=await Promise.all([req('owner_state'),radarReq().catch(()=>null)]);state=d.state;if(r)radarState=r;renderAll();if(!silent)setNotice('Profile Agent workspace refreshed.');}
  catch(err){setNotice(err.message,true);}finally{requestBusy=false;}
}
async function openConversation(id){
  selectedConversation=Number(id||0);renderInbox();threadHost.innerHTML='<div class="profile-agent-thread-empty"><strong>Loading conversation…</strong></div>';
  try{const d=await req('conversation_messages',{conversation_id:selectedConversation});const c=d.conversation,m=d.messages||[];threadHost.innerHTML=`<div class="profile-agent-thread-head"><div><strong>${esc(c.visitor_label||'Visitor')}</strong><small>${esc(String(c.status||'open').replace('_',' '))} · last message ${relative(c.last_message_at)}</small></div><div class="profile-agent-row-actions"><button type="button" data-thread-status="open">Open</button><button type="button" data-thread-status="resolved">Resolve</button></div></div><div class="profile-agent-thread-messages">${m.map(x=>`<div class="profile-agent-message ${esc(x.sender_type)}"><small>${esc(x.sender_type==='visitor'?'Visitor':x.sender_type==='owner'?'You':'Profile Agent')}</small>${esc(x.message)}</div>`).join('')||'<div class="profile-agent-empty">No messages yet.</div>'}</div><form class="profile-agent-reply" id="paReplyForm"><input name="message" maxlength="4000" autocomplete="off" placeholder="Reply as profile owner"><button type="submit">Send Reply</button></form>`;bindThread();}
  catch(err){threadHost.innerHTML=`<div class="profile-agent-thread-empty"><strong>${esc(err.message)}</strong></div>`;}
}
function bindThread(){
  threadHost.querySelector('#paReplyForm')?.addEventListener('submit',async e=>{e.preventDefault();const input=e.currentTarget.elements.message,message=input.value.trim();if(!message)return;input.disabled=true;try{const d=await req('owner_reply',{conversation_id:selectedConversation,message});state=d.state;renderAll();await openConversation(selectedConversation);setNotice('Reply sent.');}catch(err){setNotice(err.message,true);}finally{input.disabled=false;}});
  threadHost.querySelectorAll('[data-thread-status]').forEach(b=>b.addEventListener('click',async()=>{try{const d=await req('conversation_status',{conversation_id:selectedConversation,status:b.dataset.threadStatus});state=d.state;renderAll();await openConversation(selectedConversation);setNotice(b.dataset.threadStatus==='resolved'?'Conversation resolved.':'Conversation reopened.');}catch(err){setNotice(err.message,true);}}));
}
function bindDynamic(){
  profileHost.querySelectorAll('[data-profile-media-form]').forEach(form=>form.addEventListener('submit',async e=>{e.preventDefault();const input=form.querySelector('input[type=file]'),file=input?.files?.[0];const button=form.querySelector('button[type=submit]');if(button)button.disabled=true;try{const d=await uploadProfileMedia(form.dataset.profileMediaForm,file);state=d.state;renderAll();setNotice(form.dataset.profileMediaForm==='cover'?'Cover image updated.':'Profile image updated.');}catch(err){setNotice(err.message,true);}finally{if(button)button.disabled=false;}}));
  document.getElementById('paAgentForm')?.addEventListener('submit',async e=>{e.preventDefault();const f=new FormData(e.currentTarget);try{const d=await req('save_profile_agent',{profile_agent_id:Number(f.get('profile_agent_id')||0),profile_agent_enabled:f.get('profile_agent_enabled')?1:0,profile_agent_greeting:f.get('profile_agent_greeting'),profile_agent_instructions:f.get('profile_agent_instructions')});state=d.state;renderAll();setNotice('Profile Agent settings saved.');}catch(err){setNotice(err.message,true);}});
  document.getElementById('paProfileForm')?.addEventListener('submit',async e=>{e.preventDefault();const f=new FormData(e.currentTarget);try{const d=await req('save_profile',{username:f.get('username'),bio:f.get('bio'),website_url:f.get('website_url'),instagram_url:f.get('instagram_url'),tiktok_url:f.get('tiktok_url'),youtube_url:f.get('youtube_url'),spotify_url:f.get('spotify_url'),apple_music_url:f.get('apple_music_url'),is_public:f.get('is_public')?1:0,share_visit_identity:f.get('share_visit_identity')?1:0});state=d.state;renderAll();setNotice('Public profile saved.');}catch(err){setNotice(err.message,true);}});
  knowledgeHost.querySelectorAll('[data-policy]').forEach(row=>{const allow=row.querySelector('[data-policy-allow]'),audience=row.querySelector('[data-policy-audience]');const save=async()=>{try{const d=await req('save_profile_access',{resource_type:row.dataset.policy,profile_agent_allowed:allow.checked?1:0,audience_scope:audience.value});state=d.state;renderAll();setNotice('Knowledge access updated.');}catch(err){setNotice(err.message,true);}};allow?.addEventListener('change',save);audience?.addEventListener('change',save);});
  document.getElementById('profileAgentRadarRefresh')?.addEventListener('click',async()=>{try{radarState=await radarReq();renderRadar();setNotice('Agent Radar refreshed.');}catch(err){setNotice(err.message,true);}});
}
app.addEventListener('click',async e=>{
  const tab=e.target.closest('[data-pa-tab]');if(tab){activateTab(tab.dataset.paTab);return;}
  const filter=e.target.closest('[data-radar-filter]');if(filter){radarFilter=filter.dataset.radarFilter||'all';renderRadar();return;}
  const open=e.target.closest('[data-open-conversation]');if(open){activateTab('inbox');openConversation(Number(open.dataset.openConversation));return;}
  const attention=e.target.closest('[data-attention]');if(attention){try{const d=await req('attention_action',{attention_id:Number(attention.dataset.attention),attention_action:attention.dataset.attentionAction});state=d.state;renderAll();setNotice('Attention item updated.');}catch(err){setNotice(err.message,true);}return;}
});
document.getElementById('profileAgentRefresh')?.addEventListener('click',()=>refresh(false));
const deepLink=new URLSearchParams(window.location.search);selectedConversation=Number(deepLink.get('conversation')||0);selectedSession=Number(deepLink.get('session')||0);let initialTab=deepLink.get('tab')||'';try{if(!initialTab)initialTab=localStorage.getItem('profile-agent-portal-tab')||'inbox';}catch(e){if(!initialTab)initialTab='inbox';}activateTab(initialTab);
refresh(true).then(()=>{if(selectedConversation){activateTab('inbox');openConversation(selectedConversation);}else if(selectedSession){activateTab('visitors');renderVisitors();const row=visitorsHost.querySelector(`[data-profile-session="${selectedSession}"]`);row?.scrollIntoView({block:'center'});}});
refreshTimer=setInterval(()=>{if(document.visibilityState==='visible')refresh(true);},15000);
window.addEventListener('beforeunload',()=>clearInterval(refreshTimer));
})();