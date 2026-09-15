(function(){
  'use strict';
  var root=document.querySelector('[data-agent-intelligence]');
  if(!root)return;
  var toggle=root.querySelector('[data-agent-intelligence-toggle]');
  var body=document.getElementById('chatAgentIntelligenceBody');
  var form=document.getElementById('chatForm');
  var input=document.getElementById('chatInput');
  var newChat=document.getElementById('newChatButton');
  var cfg=window.VP3_AGENT_CHAT_INTELLIGENCE||{};
  var storageKey=String(cfg.storageKey||'vp3:agent-intelligence:collapsed');
  var userPinned=false;

  function setCollapsed(collapsed,persist){
    root.dataset.collapsed=collapsed?'true':'false';
    if(toggle)toggle.setAttribute('aria-expanded',collapsed?'false':'true');
    if(body)body.hidden=false;
    if(persist){
      userPinned=true;
      try{localStorage.setItem(storageKey,collapsed?'1':'0');}catch(error){}
    }
  }

  try{
    var stored=localStorage.getItem(storageKey);
    if(stored==='1'||stored==='0'){
      userPinned=true;
      setCollapsed(stored==='1',false);
    }
  }catch(error){}

  if(toggle)toggle.addEventListener('click',function(){
    setCollapsed(root.dataset.collapsed!=='true',true);
  });

  // Phase 17.9 keeps the portfolio inside the existing Agent Brief without
  // adding a dashboard or polling path. The control uses the canonical Chat
  // composer so server-side portfolio ranking remains authoritative.
  var pulse=root.querySelector('.chat-agent-intelligence-pulse');
  if(pulse&&!pulse.querySelector('[data-agent-objective-portfolio-control]')){
    var portfolio=document.createElement('button');
    portfolio.type='button';
    portfolio.className='chat-agent-intelligence-portfolio-control';
    portfolio.setAttribute('data-agent-objective-portfolio-control','');
    portfolio.setAttribute('data-agent-intelligence-prompt','Show my objective portfolio and explain what I should do first.');
    portfolio.textContent='Objective portfolio';
    pulse.appendChild(portfolio);
  }

  root.addEventListener('click',function(event){
    var button=event.target.closest('[data-agent-intelligence-prompt]');
    if(!button||!input||!form)return;
    var prompt=String(button.getAttribute('data-agent-intelligence-prompt')||'').trim();
    if(!prompt)return;
    input.value=prompt;
    input.dispatchEvent(new Event('input',{bubbles:true}));
    input.focus();
    if(typeof form.requestSubmit==='function')form.requestSubmit();
    else form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
    setCollapsed(true,false);
  });

  if(form)form.addEventListener('submit',function(){
    if(!userPinned)setCollapsed(true,false);
  });
  if(newChat)newChat.addEventListener('click',function(){
    if(!userPinned)setCollapsed(false,false);
  });

  var source=document.getElementById('vp3HomeServerStatus');
  var target=root.querySelector('[data-intelligence-homeserver-state]');
  var copy=root.querySelector('[data-intelligence-homeserver-copy]');
  var observer=null;
  function syncHomeServer(){
    if(!source||!target)return;
    var state=String(source.dataset.state||'').trim();
    var label=source.querySelector('.vp3-homeserver-status-label');
    var text=label?String(label.textContent||'').trim():'';
    if(state)target.dataset.intelligenceHomeserverState=state;
    if(text)target.textContent=text;
    if(copy&&text)copy.textContent=text+' · private capabilities remain HomeServer-authoritative.';
  }
  if(source&&target&&typeof MutationObserver!=='undefined'){
    syncHomeServer();
    observer=new MutationObserver(syncHomeServer);
    observer.observe(source,{attributes:true,childList:true,subtree:true,attributeFilter:['data-state']});
  }
  window.addEventListener('pagehide',function(){if(observer)observer.disconnect();},{once:true});
})();
