(() => {
  'use strict';
  const cfg=window.STONEFELLOW_AGENT_IDENTITY_V236||{};
  if(!cfg.displayName)return;
  const thread=document.getElementById('chatThread');
  const top=document.querySelector('.chat-topbar-title');
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const build='live-wiring-20260903-2';

  async function settingsRequest(action='',payload={}){
    if(!cfg.endpoint)throw new Error('Agent settings are unavailable.');
    const options=action?{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,csrf_token:cfg.csrf,...payload})}:{credentials:'same-origin',cache:'no-store'};
    const r=await fetch(cfg.endpoint,options);const d=await r.json().catch(()=>null);
    if(!r.ok||!d?.ok)throw new Error(d?.error||'Agent setup failed.');
    return d;
  }

  function chatUrlFor(agentValue){
    const target=new URL(cfg.chatBaseUrl||'./chat.php',window.location.href);
    const current=new URLSearchParams(window.location.search);
    current.set('agent',String(agentValue));
    target.search=current.toString();
    return target.pathname+target.search+target.hash;
  }
  function agentUrl(agentId){return chatUrlFor(Number(agentId));}
  function systemUrl(){return chatUrlFor('system');}

  function applyMessageIdentity(message){
    if(!message?.matches?.('.message.assistant'))return;
    const role=message.querySelector('.message-role'),avatar=message.querySelector('.message-avatar');
    if(role)role.textContent=cfg.displayName;
    if(avatar)avatar.textContent=String(cfg.displayName).trim().charAt(0).toUpperCase()||'S';
    message.dataset.agentIdentityV236=String(cfg.agentId||0);
  }
  function applyIdentity(scope=document){
    applyMessageIdentity(scope);
    scope.querySelectorAll?.('.message.assistant').forEach(applyMessageIdentity);
  }

  function ensureHeaderCss(){
    const old=document.querySelector('link[data-chat-header-ui]');
    if(old?.href?.includes(build))return;
    old?.remove();
    const css=document.createElement('link');
    css.rel='stylesheet';css.dataset.chatHeaderUi='1';
    css.href=new URL(`./chat-header-ui.css?v=${build}`,window.location.href).href;
    document.head.appendChild(css);
  }

  function profileMenuLink(nav,label,href,key,before=null){
    if(!nav||!href||nav.querySelector(`[data-live-profile-link="${key}"]`))return;
    const a=document.createElement('a');a.href=href;a.dataset.liveProfileLink=key;
    a.innerHTML=`<span>${esc(label)}</span><span>↗</span>`;
    if(before&&before.parentNode===nav)nav.insertBefore(a,before);else nav.appendChild(a);
  }

  async function wireProfileMenu(){
    const nav=document.querySelector('.chat-profile-links');if(!nav)return;
    const logout=nav.querySelector('a.logout');
    profileMenuLink(nav,'Agent Settings',cfg.accountUrl||'./account.php#agents-data','agent-settings',logout);
    profileMenuLink(nav,'Profile Agent Dashboard',new URL('./account.php#profile-agent',window.location.href).pathname+'#profile-agent','profile-agent',logout);
    try{
      const endpoint=new URL('./api/profile-agent.php',window.location.href);endpoint.searchParams.set('action','owner_state');
      const r=await fetch(endpoint,{credentials:'same-origin',cache:'no-store'}),d=await r.json().catch(()=>null);
      if(r.ok&&d?.ok&&d.state?.profile_url){
        const oldArtist=[...nav.querySelectorAll('a')].find(a=>/View Artist Profile/i.test(a.textContent||''));
        if(oldArtist)oldArtist.remove();
        const before=nav.querySelector('[data-live-profile-link="agent-settings"]')||logout;
        profileMenuLink(nav,'My Profile',d.state.profile_url,'my-profile',before);
      }
    }catch(_e){}
  }

  if(window.STONEFELLOW_CHAT)window.STONEFELLOW_CHAT.agentDisplayName=cfg.displayName;
  if(top){
    top.dataset.userAgentV236=String(cfg.agentId||0);
    const strong=top.querySelector('strong'),span=top.querySelector('span');
    if(strong)strong.textContent=cfg.displayName;
    if(span)span.textContent=Number(cfg.agentId)>0?`Your Stonefellow agent · ${cfg.displayName}`:'Universal system agent';
  }
  applyIdentity();
  ensureHeaderCss();
  wireProfileMenu();

  const observer=new MutationObserver(records=>records.forEach(record=>record.addedNodes.forEach(node=>{if(node.nodeType===1)applyIdentity(node);})));observer.observe(document.body,{childList:true,subtree:true});window.addEventListener('pagehide',()=>observer.disconnect(),{once:true});

  // Proactive attention is a UI/runtime extension only. It does not replace or wrap
  // the canonical Chat transport, voice, LISTEN/barge/echo, or TTS runtime.
  if(thread&&!document.querySelector('[data-agent-attention-runtime]')){
    const css=document.createElement('link');css.rel='stylesheet';css.dataset.agentAttentionRuntime='1';css.href=new URL(`./agent-attention.css?v=${build}`,window.location.href).href;document.head.appendChild(css);
    const js=document.createElement('script');js.dataset.agentAttentionRuntime='1';js.src=new URL(`./agent-attention.js?v=${build}`,window.location.href).href;document.body.appendChild(js);
  }

  const requestedAgent=new URLSearchParams(window.location.search).get('agent');
  if(Number(cfg.agentId)===0&&requestedAgent!=='system'&&!cfg.showOnboarding&&cfg.endpoint&&cfg.chatBaseUrl){
    settingsRequest().then(data=>{
      const agents=Array.isArray(data.state?.agents)?data.state.agents:[];
      const active=agents.filter(a=>Number(a.is_active));
      const preferred=active.find(a=>Number(a.is_default))||active[0]||null;
      if(preferred)window.location.replace(agentUrl(preferred.id));
    }).catch(()=>{});
  }

  if(!thread||Number(cfg.agentId)>0||!cfg.showOnboarding||!cfg.endpoint)return;
  const card=document.createElement('section');card.className='chat-agent-name-card-v236';card.id='chatAgentNameCardV236';card.innerHTML=`<small>Name your Stonefellow agent</small><h3>What would you like to name your agent?</h3><p>You can keep <strong>${esc(cfg.systemName)}</strong>, or give your Stonefellow agent a personal name. Renaming it does not create a weaker assistant or reduce access to your private account data. Public Profile Agent and network-sharing permissions stay separate.</p><form class="chat-agent-name-form-v236"><input name="agent_name" maxlength="190" autocomplete="off" placeholder="Name your agent" aria-label="Name your agent"><button type="submit">Use This Name</button></form><div class="chat-agent-name-actions-v236"><button type="button" data-keep-system>Keep ${esc(cfg.systemName)}</button><a href="${esc(cfg.accountUrl||'account.php#agents-data')}" style="color:#918983;font-size:.68rem;text-decoration:none">Agent settings ↗</a><span class="chat-agent-name-status-v236" role="status" aria-live="polite"></span></div>`;
  const firstMessage=thread.querySelector('.message');if(firstMessage)firstMessage.insertAdjacentElement('beforebegin',card);else thread.prepend(card);
  const form=card.querySelector('form'),input=card.querySelector('input'),status=card.querySelector('.chat-agent-name-status-v236'),keep=card.querySelector('[data-keep-system]');
  form.addEventListener('submit',async event=>{event.preventDefault();const name=String(input.value||'').trim();if(!name){input.focus();status.textContent='Enter a name first.';return;}const submit=form.querySelector('button');submit.disabled=true;status.textContent='Saving…';try{const data=await settingsRequest('create_agent',{display_name:name,agent_role:'personal'}),agents=data.state?.agents||[],agent=agents.find(a=>String(a.display_name).toLowerCase()===name.toLowerCase())||agents.find(a=>Number(a.is_default))||agents.find(a=>Number(a.is_active));if(!agent)throw new Error('The agent name was saved but could not be opened.');status.textContent=`Opening ${agent.display_name}…`;window.location.assign(agentUrl(agent.id));}catch(error){status.textContent=error.message;submit.disabled=false;}});
  keep.addEventListener('click',async()=>{keep.disabled=true;status.textContent=`Keeping ${cfg.systemName}…`;try{await settingsRequest('dismiss_onboarding');window.history.replaceState({},'',systemUrl());card.remove();}catch(error){status.textContent=error.message;keep.disabled=false;}});
})();
