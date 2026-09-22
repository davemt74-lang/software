(()=>{
  const script=document.querySelector('script[data-vp3-funnel-endpoint]');
  const endpoint=script?.dataset?.vp3FunnelEndpoint||'';
  if(!endpoint)return;
  document.addEventListener('click',event=>{
    const link=event.target instanceof Element?event.target.closest('[data-vp3-cta]'):null;
    if(!link)return;
    const cta=(link.getAttribute('data-vp3-cta')||'').trim();
    const target=(link.getAttribute('data-vp3-target')||'').trim();
    if(!/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/.test(cta)||!/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/.test(target))return;
    try{
      const body=new URLSearchParams({cta,target});
      if(navigator.sendBeacon){navigator.sendBeacon(endpoint,body);return;}
      fetch(endpoint,{method:'POST',body,credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}}).catch(()=>{});
    }catch(e){}
  },{capture:true});
})();
