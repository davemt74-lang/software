(() => {
  'use strict';
  const root=document.getElementById('phone-location'),panel=document.getElementById('phone-install-panel');
  if(!root||!panel)return;
  const status=document.getElementById('phone-install-status'),help=document.getElementById('phone-install-help'),button=document.getElementById('phone-install-button');
  const mode=window.matchMedia('(display-mode: standalone)');
  let prompt=null,workerState='not_observed',busy=false;
  const installed=()=>mode.matches||navigator.standalone===true;
  function render(){
    const active=installed();
    status.textContent=active?'Opened from your installed app.':'Opened in a browser.';
    help.textContent=active?'If this app is not connected, use a fresh pairing code or move your existing connection below.':'On Android, use Install app or Add to Home Screen if your browser offers it. Open the installed app to connect.';
    button.hidden=active||!prompt;button.disabled=busy||button.hidden;
    panel.dataset.workerState=workerState;
    window.dispatchEvent(new CustomEvent('vp3:phone-pwa-worker-state',{detail:{state:workerState}}));
  }
  window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();prompt=event;render();});
  window.addEventListener('appinstalled',()=>{prompt=null;render();});
  mode.addEventListener?.('change',render);
  button.addEventListener('click',async event=>{
    if(!event.isTrusted||busy||!prompt||installed()||document.hidden)return;
    const selected=prompt;prompt=null;busy=true;render();
    try{await selected.prompt();await selected.userChoice;}catch(_error){}finally{busy=false;render();}
  });
  render();
  if(!window.isSecureContext||!('serviceWorker' in navigator)){workerState='unsupported';render();return;}
  // Scope includes only this phone page (including query strings), never the main app or its APIs.
  const script=new URL('./phone-service-worker-v1.js',location.href);
  const scope=new URL('./phone-location.php',location.href);
  if(script.origin!==location.origin||scope.pathname!==location.pathname){workerState='unavailable';render();return;}
  navigator.serviceWorker.register(script.href,{scope:scope.href,updateViaCache:'none'})
    .then(reg=>{
      const observe=()=>{
        workerState=new URL(reg.scope).href!==scope.href?'unavailable':reg.active?.scriptURL===script.href?'registered':'activating';
        render();
      };
      const watch=()=>{reg.installing?.addEventListener('statechange',observe);reg.waiting?.addEventListener('statechange',observe);observe();};
      reg.addEventListener?.('updatefound',watch);watch();
    }).catch(()=>{workerState='unavailable';render();});
})();
