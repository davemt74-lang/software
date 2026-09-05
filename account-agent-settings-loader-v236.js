(() => {
  'use strict';
  if(!document.querySelector('.account-canvas-content'))return;
  const csrf=document.querySelector('input[name="csrf_token"]')?.value||'';
  if(!csrf)return;
  const endpoint=new URL('./api/user-agent-system-v236.php',window.location.href);
  const chatUrl=new URL('./chat.php',window.location.href);
  window.STONEFELLOW_ACCOUNT_AGENT_V236={endpoint:endpoint.pathname,chatUrl:chatUrl.pathname,csrf};
  const build='account-light-shell-20260905';
  const assets=[
    ['link','data-account-agent-v236-css',new URL(`./account-shell.css?v=${build}`,window.location.href).href],
    ['script','data-account-agent-v236-js',new URL(`./account-agent-settings-v236.js?v=${build}`,window.location.href).href],
  ];
  for(const [kind,attr,src] of assets){
    const existing=document.querySelector(`${kind}[${attr}]`);
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