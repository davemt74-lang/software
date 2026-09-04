(() => {
  'use strict';
  if(!document.querySelector('.account-canvas-content'))return;
  const csrf=document.querySelector('input[name="csrf_token"]')?.value||'';
  if(!csrf)return;
  const endpoint=new URL('./api/user-agent-system-v236.php',window.location.href);
  const chatUrl=new URL('./chat.php',window.location.href);
  window.STONEFELLOW_ACCOUNT_AGENT_V236={endpoint:endpoint.pathname,chatUrl:chatUrl.pathname,csrf};
  window.STONEFELLOW_PROFILE_DASHBOARD={endpoint:new URL('./api/profile-agent.php',window.location.href).pathname,csrf};
  const build='live-wiring-20260903-2';
  const assets=[
    ['link','data-account-agent-v236',new URL(`./account-agent-settings-v236.css?v=${build}`,window.location.href).href],
    ['link','data-profile-dashboard',new URL(`./profile-dashboard.css?v=${build}`,window.location.href).href],
    ['script','data-account-agent-v236',new URL(`./account-agent-settings-v236.js?v=${build}`,window.location.href).href],
    ['script','data-profile-dashboard',new URL(`./profile-dashboard.js?v=${build}`,window.location.href).href],
  ];
  for(const [kind,attr,src] of assets){
    const existing=document.querySelector(`[${attr}]`);
    if(existing){
      const current=kind==='link'?existing.href:existing.src;
      if(current&&current.includes(build))continue;
      existing.remove();
    }
    const el=document.createElement(kind);el.setAttribute(attr,'1');
    if(kind==='link'){el.rel='stylesheet';el.href=src;document.head.appendChild(el);}
    else{el.src=src;document.body.appendChild(el);}
  }
})();
