(() => {
  'use strict';
  window.VP3PhonePushSubscriptionsV1=({request})=>{
    const root=document.getElementById('phone-location'),info=document.getElementById('phone-info');
    const controls=document.getElementById('phone-push-controls'),status=document.getElementById('phone-guide-notification-state');
    const enable=document.getElementById('phone-push-enable'),disable=document.getElementById('phone-push-disable'),feedback=document.getElementById('phone-push-feedback');
    if(!root||!info||!controls||!status||!enable||!disable||!feedback)return null;
    const scope=new URL('./phone-location.php',location.href),script=new URL('./phone-service-worker-v1.js',location.href);
    const installed=()=>navigator.standalone===true||window.matchMedia('(display-mode: standalone)').matches;
    const supported=()=>window.isSecureContext&&'serviceWorker' in navigator&&'PushManager' in window&&'Notification' in window&&!!crypto.subtle;
    const permission=()=>supported()?Notification.permission:'unsupported';
    let d=null,ready=false,version=0,busy=false,inspecting=false,checkedAt=0,registration=null,local=null,localHash='',localKeyOK=false,observed=false,notice='';
    const view=()=>d?.push;
    const usable=()=>ready&&!document.hidden&&!info.hidden&&d?.status==='approved'&&!busy&&navigator.onLine!==false;
    const hasKey=p=>p&&typeof p.application_server_key==='string'&&/^[A-Za-z0-9_-]{87}$/.test(p.application_server_key)&&/^[a-f0-9]{64}$/.test(p.key_id||'');
    const registered=p=>p?.state==='registered';
    const sameLocal=p=>!!local&&localKeyOK&&registered(p)&&localHash===p.endpoint_hash;
    const canEnable=()=>usable()&&supported()&&installed()&&registration&&observed&&hasKey(view())&&view().enrollment_enabled===true
      &&permission()!=='denied'&&(!local||localKeyOK)&&!sameLocal(view());
    function keyBytes(value){
      const binary=atob(value.replace(/-/g,'+').replace(/_/g,'/')+'='.repeat((4-value.length%4)%4));
      return Uint8Array.from(binary,c=>c.charCodeAt(0));
    }
    function keyMatches(sub,p){
      try{
        const actual=new Uint8Array(sub.options.applicationServerKey||[]),expected=keyBytes(p.application_server_key);
        return sub.options.userVisibleOnly===true&&actual.length===expected.length&&actual.every((v,i)=>v===expected[i]);
      }catch(_error){return false;}
    }
    async function fingerprint(endpoint){
      const raw=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(endpoint));
      return Array.from(new Uint8Array(raw),v=>v.toString(16).padStart(2,'0')).join('');
    }
    function exact(reg){
      return !!reg?.active&&reg.scope===scope.href&&reg.active.scriptURL===script.href&&!!reg.pushManager;
    }
    function render(){
      const p=view(),perm=permission(),isApproved=d?.status==='approved';
      controls.hidden=!isApproved;
      enable.hidden=!isApproved||!supported()||!installed()||!p?.enrollment_enabled||perm==='denied'||sameLocal(p)||(local&&!localKeyOK);
      enable.disabled=!canEnable();
      enable.textContent=local?'Save notification subscription':'Enable notifications';
      disable.hidden=!isApproved||!p||!['registered','unavailable'].includes(p.state);
      disable.disabled=!usable()||disable.hidden;
      let label='Background notifications are not enabled yet.';
      if(isApproved&&p){
        if(!installed())label='Install VP3 Phone on your Home Screen first.';
        else if(!supported())label='Notifications are not supported in this app.';
        else if(perm==='denied')label='Notifications are blocked in device settings.';
        else if(!p.enrollment_enabled&&!registered(p))label='Notification enrollment is not available yet.';
        else if(!ready)label='Connection status is unconfirmed.';
        else if(registered(p)&&sameLocal(p)&&perm==='granted')label='Notifications allowed · subscription saved.';
        else if(registered(p)&&observed&&!sameLocal(p))label='Saved subscription needs attention on this installation.';
        else if(local&&!localKeyOK)label='This app has a subscription for a different server key. Ask the owner for help.';
        else if(!registration||!observed)label='Checking notification setup…';
        else if(local)label='Browser subscription exists. Save it for this connection.';
        else if(perm==='granted')label='Permission allowed · subscription not saved.';
        else label='Notifications are off.';
      }
      if(status.textContent!==label)status.textContent=label;
      if(feedback.textContent!==notice)feedback.textContent=notice;
    }
    async function inspect(force=false){
      if(!d||d.status!=='approved'||!ready||!installed()||!supported()||document.hidden||info.hidden||busy||inspecting||(!force&&checkedAt>0&&performance.now()-checkedAt<10000))return;
      inspecting=true;checkedAt=performance.now();
      const v=version,p=view();
      try{
        const reg=await navigator.serviceWorker.getRegistration(scope.href);
        if(v!==version)return;
        if(!exact(reg)){registration=null;observed=false;checkedAt=0;return;}
        const sub=await reg.pushManager.getSubscription();
        const hash=sub?await fingerprint(sub.endpoint):'';
        if(v!==version||view()?.key_id!==p?.key_id)return;
        registration=reg;local=sub;localHash=hash;localKeyOK=!!sub&&hasKey(p)&&keyMatches(sub,p);observed=true;
      }catch(_error){if(v===version){registration=null;observed=false;}}
      finally{inspecting=false;render();}
    }
    function reset(){
      version++;registration=null;local=null;localHash='';localKeyOK=false;observed=false;checkedAt=0;notice='';
    }
    function bind(next,available){
      const changed=(next?.id||null)!==(d?.id||null)||next?.status!==d?.status||next?.push?.key_id!==d?.push?.key_id;
      if(changed)reset();
      d=next;ready=Boolean(available);
      render();void inspect(changed);
    }
    enable.addEventListener('click',async event=>{
      // Safari requires subscription initiation in the original user gesture.
      // No server request, worker-ready await or permission prompt occurs first.
      if(!event.isTrusted||!canEnable())return;
      const p=view(),id=d.id,v=version,reg=registration;
      busy=true;notice='';render();
      try{
        const pending=local?Promise.resolve(local):reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:keyBytes(p.application_server_key)});
        const sub=await pending;
        if(v!==version||d?.id!==id||document.hidden||info.hidden||!ready||view()?.revision!==p.revision||view()?.key_id!==p.key_id)return;
        if(!keyMatches(sub,p)||permission()!=='granted')throw Error('Notification permission not confirmed');
        const encoded=sub.toJSON();
        if(!encoded||typeof encoded.endpoint!=='string'||!encoded.keys)throw Error('Invalid subscription');
        const subscription={endpoint:encoded.endpoint,expirationTime:encoded.expirationTime??null,keys:{p256dh:encoded.keys.p256dh,auth:encoded.keys.auth}};
        const hash=await fingerprint(subscription.endpoint);
        if(v!==version||d?.id!==id||document.hidden||info.hidden||!ready||view()?.revision!==p.revision||view()?.key_id!==p.key_id)return;
        const result=await request('push_register',{device_id:id,subscription_revision:p.revision,confirmed:true,key_id:p.key_id,subscription});
        if(v!==version||d?.id!==id)return;
        if(result.push?.state!=='registered'||result.push.endpoint_hash!==hash)throw Error('Subscription not confirmed');
        local=sub;localHash=hash;localKeyOK=true;observed=true;
        notice='Subscription saved. Background delivery is not active yet.';
      }catch(_error){if(v===version)notice=permission()==='denied'?'Allow notifications in device settings if you change your mind.':'Subscription was not confirmed. Review your connection and try again.';}
      finally{busy=false;render();void inspect(true);}
    });
    disable.addEventListener('click',async event=>{
      if(!event.isTrusted||!usable()||disable.hidden||disable.disabled)return;
      const id=d.id,p=view(),v=version,sub=sameLocal(p)?local:null;
      busy=true;notice='';render();
      try{
        const result=await request('push_disable',{device_id:id,subscription_revision:p.revision,confirmed:true});
        if(v!==version||d?.id!==id)return;
        if(result.push?.state!=='off')throw Error('Disable not confirmed');
        // Disable VP3 authorization first. Only remove the exact local sub
        // known to belong to this connection, never another pairing's sub.
        if(sub){
          try{await sub.unsubscribe();}catch(_error){}
        }
        notice='VP3 notification subscription turned off.';
      }catch(_error){if(v===version)notice='Could not confirm turn-off. You can also disable alerts in device settings.';}
      finally{busy=false;render();void inspect(true);}
    });
    new MutationObserver(()=>{if(!info.hidden){render();void inspect(true);}}).observe(info,{attributes:true,attributeFilter:['hidden']});
    window.addEventListener('pageshow',()=>{render();void inspect(true);});
    window.addEventListener('pagehide',()=>{reset();ready=false;render();});
    document.addEventListener('visibilitychange',()=>{if(!document.hidden){render();void inspect(true);}});
    render();return {bind};
  };
})();
