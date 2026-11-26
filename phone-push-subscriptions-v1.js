(() => {
  'use strict';
  window.VP3PhonePushSubscriptionsV1=({request,onResolved=()=>{},onUnavailable=()=>{}})=>{
    const root=document.getElementById('phone-location'),info=document.getElementById('phone-info');
    const controls=document.getElementById('phone-push-controls'),status=document.getElementById('phone-guide-notification-state');
    const enable=document.getElementById('phone-push-enable'),disable=document.getElementById('phone-push-disable'),feedback=document.getElementById('phone-push-feedback');
    const test=document.getElementById('phone-push-test'),testState=document.getElementById('phone-push-test-state');
    if(!root||!info||!controls||!status||!enable||!disable||!feedback)return null;
    const scope=new URL('./phone-location.php',location.href),script=new URL('./phone-service-worker-v1.js',location.href);
    const installed=()=>navigator.standalone===true||window.matchMedia('(display-mode: standalone)').matches;
    const supported=()=>window.isSecureContext&&'serviceWorker' in navigator&&'PushManager' in window&&'Notification' in window&&!!crypto.subtle;
    const permission=()=>supported()?Notification.permission:'unsupported';
    let d=null,ready=false,version=0,busy=false,inspecting=false,checkedAt=0,registration=null,local=null,localHash='',localKeyOK=false,observed=false,notice='';
    let testRequest=null,testReceipt=null,testNotice='',pendingEvent=null,resolving=false;
    const reference=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
    const epoch=value=>Number.isSafeInteger(value)&&value>=0;
    const testStates=new Set(['queued','accepted','retry','uncertain','failed','cancelled','expired']);
    const view=()=>d?.push;
    const usable=()=>ready&&!document.hidden&&!info.hidden&&d?.status==='approved'&&!busy&&navigator.onLine!==false;
    const hasKey=p=>p&&typeof p.application_server_key==='string'&&/^[A-Za-z0-9_-]{87}$/.test(p.application_server_key)&&/^[a-f0-9]{64}$/.test(p.key_id||'');
    const registered=p=>p?.state==='registered';
    const sameLocal=p=>!!local&&localKeyOK&&registered(p)&&localHash===p.endpoint_hash;
    const canEnable=()=>usable()&&supported()&&installed()&&registration&&observed&&hasKey(view())&&view().enrollment_enabled===true
      &&permission()!=='denied'&&(!local||localKeyOK)&&!sameLocal(view());
    const binding=()=>({device_id:d?.id,subscription_revision:view()?.revision,key_id:view()?.key_id});
    const current=expected=>d?.status==='approved'&&d.id===expected.device_id&&view()?.revision===expected.subscription_revision&&view()?.key_id===expected.key_id;
    const canTest=()=>usable()&&supported()&&installed()&&registration&&observed&&exact(registration)&&sameLocal(view())&&permission()==='granted'&&view()?.delivery?.enabled===true&&view()?.delivery?.ready===true;
    function testResult(raw,expected){
      if(!raw||!reference(raw.event_ref)||raw.device_id!==expected.device_id||raw.subscription_revision!==expected.subscription_revision||!testStates.has(raw.state))return null;
      const result={event_ref:raw.event_ref,device_id:raw.device_id,subscription_revision:raw.subscription_revision,state:raw.state};
      for(const key of ['created_epoch','expires_epoch','provider_accepted_epoch','client_observed_epoch','opened_epoch']){
        if(!epoch(raw[key]))return null;result[key]=raw[key];
      }
      if(result.created_epoch<1||result.expires_epoch<=result.created_epoch||[result.provider_accepted_epoch,result.client_observed_epoch,result.opened_epoch].some(value=>value>Math.floor(Date.now()/1000)+300))return null;
      return result;
    }
    const testSettled=receipt=>receipt&&(receipt.client_observed_epoch>0||receipt.opened_epoch>0||['failed','cancelled','expired'].includes(receipt.state)||receipt.expires_epoch<=Math.floor(Date.now()/1000));
    function testDescription(receipt){
      if(['cancelled','expired'].includes(receipt.state))return 'This test notification is no longer active. Send another test when notifications are ready.';
      if(receipt.opened_epoch>0)return 'The test notification was opened in VP3 Phone.';
      if(receipt.client_observed_epoch>0)return 'This installation reported showing the test notification.';
      if(receipt.state==='failed')return 'The test could not be sent. Check your notification setup before trying again.';
      if(receipt.expires_epoch<=Math.floor(Date.now()/1000))return 'The test notification window expired without a display report. Send another test when notifications are ready.';
      if(receipt.provider_accepted_epoch>0||receipt.state==='accepted')return 'The push service accepted the test. Display on this device is not confirmed.';
      if(receipt.state==='uncertain')return 'The send result is uncertain. Display on this device is not confirmed.';
      if(receipt.state==='retry')return 'The test is waiting for the server to retry. Display on this device is not confirmed.';
      return 'Test notification queued. Check this device for an alert.';
    }
    function deliveryLabel(){
      if(testNotice)return testNotice;
      const p=view(),delivery=p?.delivery,expected=binding();
      const saved=testResult(delivery?.last_test,expected),own=testResult(testReceipt,expected);
      const receipt=saved&&(!own||saved.event_ref===own.event_ref||saved.created_epoch>own.created_epoch)?saved:own;
      if(receipt)return testDescription(receipt);
      if(!delivery?.enabled)return 'Background delivery is not active yet.';
      if(!delivery.ready)return delivery.worker?.state==='observed'?'Notification delivery is not ready. Ask the owner to check the server setup.':'Notification delivery is waiting for the server worker.';
      return 'Send a test to check this installation. A saved subscription does not confirm delivery.';
    }
    function consumeNavigation(){
      try{
        const url=new URL(location.href),values=url.searchParams.getAll('push_event');
        if(url.origin!==scope.origin||url.pathname!==scope.pathname||!values.length)return;
        pendingEvent=values.length===1&&reference(values[0])?values[0]:null;
        url.searchParams.delete('push_event');history.replaceState(null,'',url.pathname+url.search+url.hash);
      }catch(_error){pendingEvent=null;}
    }
    async function resolveNavigation(){
      if(!pendingEvent||resolving||!ready||document.hidden||navigator.onLine===false||d?.status!=='approved')return;
      const expected=binding(),ref=pendingEvent,v=version;resolving=true;
      try{
        const result=await request('push_resolve',{device_id:expected.device_id,event_ref:ref});
        if(v!==version||!current(expected))return;
        if(document.hidden||!ready)return;
        pendingEvent=null;
        const receipt=testResult(result,expected);
        if(!receipt||receipt.event_ref!==ref||receipt.opened_epoch<1||!result.target||!['info','chat','requests','agent'].includes(result.target.tab))throw Error('Notification source unavailable');
        const target={tab:result.target.tab};
        const fields=target.tab==='chat'?['tab','request_id']:target.tab==='requests'?['tab','exchange_id']:target.tab==='agent'?['tab','item_ref']:['tab'];
        if(Object.keys(result.target).some(key=>!fields.includes(key)))throw Error('Invalid notification source');
        if(target.tab==='chat'){if(typeof result.target.request_id!=='string'||!/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/.test(result.target.request_id))throw Error('Invalid notification source');target.request_id=result.target.request_id;}
        if(target.tab==='requests'){if(!reference(result.target.exchange_id))throw Error('Invalid notification source');target.exchange_id=result.target.exchange_id;}
        if(target.tab==='agent'){if(!reference(result.target.item_ref))throw Error('Invalid notification source');target.item_ref=result.target.item_ref;}
        testNotice=result.target.tab==='info'?'Notification opened in VP3 Phone.':'';render();
        onResolved({event_ref:ref,device_id:expected.device_id,subscription_revision:expected.subscription_revision,state:receipt.state,opened_epoch:receipt.opened_epoch,target});
      }catch(_error){if(v===version&&current(expected)){pendingEvent=null;testNotice='This notification is no longer available. Review the current connection in VP3 Phone.';render();onUnavailable();}}
      finally{if(v===version)resolving=false;}
    }
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
      if(test){test.hidden=!isApproved||!supported()||!installed()||!registered(p)||p?.delivery?.enabled!==true;test.disabled=test.hidden||!canTest();}
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
      if(testState){testState.hidden=!isApproved;if(testState.textContent!==deliveryLabel())testState.textContent=deliveryLabel();}
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
      finally{if(v===version){inspecting=false;render();}}
    }
    function reset(){
      version++;registration=null;local=null;localHash='';localKeyOK=false;observed=false;checkedAt=0;notice='';testRequest=testReceipt=null;testNotice='';resolving=busy=inspecting=false;
    }
    function bind(next,available){
      const changed=(next?.id||null)!==(d?.id||null)||next?.status!==d?.status||next?.push?.key_id!==d?.push?.key_id;
      if(changed){if(d?.id)pendingEvent=null;reset();}
      else if(next?.push?.revision!==d?.push?.revision){testRequest=testReceipt=null;testNotice='';}
      d=next;ready=Boolean(available);
      render();void inspect(changed);void resolveNavigation();
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
        notice=result.push?.delivery?.ready===true?'Subscription saved. Send a test notification to check this installation.':'Subscription saved. Background delivery is not active yet.';
      }catch(_error){if(v===version)notice=permission()==='denied'?'Allow notifications in device settings if you change your mind.':'Subscription was not confirmed. Review your connection and try again.';}
      finally{if(v===version){busy=false;render();void inspect(true);}}
    });
    test?.addEventListener('click',async event=>{
      if(!event.isTrusted||!canTest())return;
      const expected=binding(),v=version;
      const saved=testResult(view()?.delivery?.last_test,expected);
      if(testRequest&&[saved,testResult(testReceipt,expected)].some(receipt=>receipt?.event_ref===testRequest.event_ref&&testSettled(receipt)))testRequest=null;
      if(!testRequest||!current(testRequest))testRequest={...expected,request_id:Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('')};
      const intent=testRequest;busy=true;testNotice='Sending test request…';render();
      try{
        const result=await request('push_test',{device_id:expected.device_id,subscription_revision:expected.subscription_revision,confirmed:true,request_id:intent.request_id});
        if(v!==version||!current(expected)||testRequest!==intent||!ready||document.hidden||info.hidden)return;
        const receipt=testResult(result,expected);if(!receipt||(intent.event_ref&&intent.event_ref!==receipt.event_ref))throw Error('Test not confirmed');
        intent.event_ref=receipt.event_ref;testReceipt=receipt;testNotice='';
        if(testSettled(receipt))testRequest=null;
      }catch(_error){if(v===version&&current(expected))testNotice='The test request could not be confirmed. Try again to check the same test.';}
      finally{if(v===version){busy=false;render();}}
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
      finally{if(v===version){busy=false;render();void inspect(true);}}
    });
    new MutationObserver(()=>{if(!info.hidden){render();void inspect(true);}}).observe(info,{attributes:true,attributeFilter:['hidden']});
    window.addEventListener('pageshow',()=>{render();void inspect(true);void resolveNavigation();});
    window.addEventListener('pagehide',()=>{pendingEvent=null;reset();ready=false;render();});
    document.addEventListener('visibilitychange',()=>{if(!document.hidden){render();void inspect(true);void resolveNavigation();}});
    consumeNavigation();render();return {bind};
  };
})();
