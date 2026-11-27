(() => {
  'use strict';
  window.VP3PhoneShellV1=({chat})=>{
    const get=id=>document.getElementById('phone-'+id),root=get('location'),menu=get('user-menu');
    if(!root||!menu||!chat)return null;
    const summary=menu.querySelector('summary');
    async function open(view){
      menu.open=false;
      const fn={home:chat.openHome,chat:chat.openChat,requests:chat.openRequests,connection:chat.openConnection,notifications:chat.openConnection}[view];
      if(!fn||await fn()===false)return;
      if(view==='notifications'){
        get('notifications')?.scrollIntoView({block:'start'});
        const target=get('push-enable');if(target&&!target.hidden&&!target.disabled)target.focus({preventScroll:true});
      }
    }
    for(const button of root.querySelectorAll('[data-phone-open]'))button.addEventListener('click',event=>{
      if(event.isTrusted)void open(button.dataset.phoneOpen);
    });
    get('tab-info')?.addEventListener('click',()=>{menu.open=false;});
    document.addEventListener('pointerdown',event=>{if(menu.open&&!menu.contains(event.target))menu.open=false;});
    menu.addEventListener('keydown',event=>{if(event.key==='Escape'){menu.open=false;summary.focus();event.preventDefault();}});
    let identity='';
    function bind(device,owner,confirmed){
      const next=device?.id||'';if(next!==identity){menu.open=false;identity=next;}
      get('menu-label').textContent=device?.name||'Account';
      get('menu-connection').textContent=device?.status==='approved'&&owner?'Connected to '+owner:'This phone is not connected.';
      const active=device?.status==='approved';
      if(get('home-status'))get('home-status').textContent=active?(confirmed?'Connected and ready.':'Checking the connection…'):'Connect this phone to get started.';
      const pending=device?.communication?.information_pending,known=Number.isSafeInteger(pending)&&pending>=0;
      if(get('home-requests'))get('home-requests').textContent=active&&known&&pending>0?pending+' information request'+(pending===1?'':'s')+' to review.':'Review messages and requests when you are ready.';
      const count=get('requests-count');count.hidden=!active||!known||pending===0;count.textContent=known&&pending>0?String(Math.min(99,pending))+(pending>99?'+':''):'';
      if(get('home-notifications'))get('home-notifications').hidden=!active||device?.push?.state==='registered'&&device?.push?.delivery?.ready===true;
    }
    return {bind};
  };
})();
