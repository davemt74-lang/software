(() => {
  'use strict';
  if(!document.querySelector('.account-canvas-content'))return;
  const csrf=document.querySelector('input[name="csrf_token"]')?.value||'';
  if(!csrf)return;
  const endpoint=new URL('./api/user-agent-system-v236.php',window.location.href);
  const chatUrl=new URL('./chat.php',window.location.href);
  window.STONEFELLOW_ACCOUNT_AGENT_V236={endpoint:endpoint.pathname,chatUrl:chatUrl.pathname,csrf};
  const build='account-light-shell-20260905';
  const computeBuild='agent-compute-v023-20260908-agent-compute-v026-scope-20260908';
  const assets=[
    ['link','data-account-agent-v236-css',new URL(`./account-shell.css?v=${build}`,window.location.href).href,build],
    ['link','data-agent-compute-v020-css',new URL(`./agent-compute-v020.css?v=${computeBuild}`,window.location.href).href,computeBuild],
    ['link','data-agent-compute-v021-css',new URL(`./agent-compute-v021.css?v=${computeBuild}`,window.location.href).href,computeBuild],
    ['link','data-agent-compute-v023-css',new URL(`./agent-compute-v023.css?v=${computeBuild}`,window.location.href).href,computeBuild],
    ['link','data-homeserver-capabilities-v024-css',new URL(`./homeserver-capabilities-v024.css?v=${computeBuild}`,window.location.href).href,computeBuild],
    ['script','data-account-agent-v236-js',new URL(`./account-agent-settings-v236.js?v=${computeBuild}`,window.location.href).href,computeBuild],
    ['script','data-agent-compute-v021-js',new URL(`./account-agent-compute-v021.js?v=${computeBuild}`,window.location.href).href,computeBuild],
    ['script','data-homeserver-capabilities-v024-js',new URL(`./account-homeserver-capabilities-v024.js?v=${computeBuild}`,window.location.href).href,computeBuild],
  ];
  for(const [kind,attr,src,version] of assets){
    const existing=document.querySelector(`${kind}[${attr}]`);
    if(existing){
      const current=kind==='link'?existing.href:existing.src;
      if(current&&current.includes(version))continue;
      existing.remove();
    }
    const el=document.createElement(kind);el.setAttribute(attr,'1');
    if(kind==='link'){el.rel='stylesheet';el.href=src;document.head.appendChild(el);}
    else{el.src=src;document.body.appendChild(el);}
  }
})();
