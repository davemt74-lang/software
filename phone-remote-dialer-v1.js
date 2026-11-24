(() => {
  'use strict';
  const numberOK = n => typeof n==='string' && /^\+[1-9][0-9]{7,14}$/.test(n);
  const idOK = n => typeof n==='string' && /^[a-f0-9]{32}$/.test(n);
  const number = n => String(n).trim().replace(/[ ().-]/g,'');
  const make = (tag,text,cls) => {const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;};
  const button = text => {const e=make('button',text);e.type='button';return e;};
  const label = (text,input) => {const e=make('label',undefined,'phone-dialer-label');e.append(input,make('span',text));return e;};
  const scope = d => d?.status==='approved'&&idOK(d.id)&&Number.isSafeInteger(d.generation)&&Number.isSafeInteger(d.dialer?.revision)?d.id+'/'+d.generation+'/'+d.dialer.revision:'';
  const bound = d => ({device_id:d.id,generation:d.generation,dialer_revision:d.dialer.revision});
  const requestId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)),v=>v.toString(16).padStart(2,'0')).join('');
  const states = {pending:'Awaiting phone holder',handoff_requested:'Holder requested native handoff (call not verified)',declined:'Declined',canceled:'Canceled',expired:'Expired'};
  function live(d,received,clock=performance.now()){
    const p=d?.dialer,r=p?.request;
    return !!scope(d)&&p.enabled===true&&Number.isSafeInteger(p.server_epoch)&&r?.state==='pending'&&idOK(r.id)&&Number.isSafeInteger(r.expires_epoch)&&
      clock-received>=0&&clock-received<9000&&r.expires_epoch>p.server_epoch+(clock-received)/1000;
  }
  function canAuto(d,received,{visible,focused,mobile,clock=performance.now()}){
    const p=d?.dialer,r=p?.request;
    return visible&&focused&&mobile&&live(d,received,clock)&&p.auto_enabled===true&&r.auto_allowed===true&&r.auto_attempted===false&&numberOK(r.destination)&&r.destination===p.auto_number;
  }
  const mobile=()=>/iPhone|iPad|iPod|Android/i.test(navigator.userAgent||'')||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);
  const visible=()=>!document.hidden&&navigator.onLine!==false;
  const openNative=n=>{if(numberOK(n))window.location.assign('tel:'+n);};
  function holder({request}){
    const prefs=make('section',undefined,'phone-permission phone-dialer-settings');prefs.hidden=true;
    const form=make('form'),enabled=make('input'),auto=make('input'),dest=make('input'),save=make('button','Save dialer permissions'),feedback=make('p');
    enabled.type=auto.type='checkbox';dest.type='tel';dest.placeholder='+1 602 555 0100';dest.maxLength=32;dest.autocomplete='off';save.type='submit';feedback.setAttribute('role','status');
    form.append(label('Allow remote call requests',enabled),label('Automatically try to open the dialer for my approved number',auto),label('Approved automatic destination (international number)',dest),save);
    prefs.append(make('h2','Remote dialer'),make('p','Optional. Calls use your own phone service. You control the final call; carrier charges may apply. Only the exact number you approve can open automatically.'),form,feedback);
    const alert=make('section',undefined,'phone-dialer-alert');alert.hidden=true;alert.setAttribute('aria-label','Incoming dialer request');
    const heading=make('strong','Incoming call request'),target=make('p'),state=make('p'),actions=make('div',undefined,'phone-permission-buttons'),call=button('Call now'),decline=button('Decline');
    state.setAttribute('role','status');decline.className='secondary';actions.append(call,decline);alert.append(heading,target,state,actions);
    let device=null,received=-Infinity,ready=false,signature='',saving=false,operating=false,blocked=false,attempted='',dismissed='',lastRef=null;
    const current=(key,id)=>ready&&visible()&&scope(device)===key&&device.dialer.request?.id===id&&live(device,received);
    function controls(){auto.disabled=!enabled.checked||saving;dest.disabled=!enabled.checked||!auto.checked||saving;enabled.disabled=saving;save.disabled=saving||(!dest.disabled&&!numberOK(number(dest.value)));}
    enabled.addEventListener('change',()=>{if(!enabled.checked)auto.checked=false;controls();});
    auto.addEventListener('change',controls);dest.addEventListener('input',controls);
    function invalidate(){
      ready=false;device=null;received=-Infinity;alert.hidden=true;target.textContent='';state.textContent='';prefs.hidden=true;
      // Keep the prior response identity so foreground resume requires a fresh server response.
    }
    function renderAlert(){
      const r=device?.dialer?.request;
      const ok=ready&&visible()&&!blocked&&live(device,received)&&numberOK(r?.destination)&&dismissed!==scope(device)+'/'+r.id;
      alert.hidden=!ok;if(!ok){target.textContent='';state.textContent='';return;}
      target.textContent=r.destination;
      state.textContent=r.auto_attempted||attempted===scope(device)+'/'+r.id?'Automatic opening attempted or unavailable. Tap Call now if needed.':'Your approved connection prepared a call. Confirm in your phone app.';
      call.disabled=decline.disabled=operating;
    }
    async function tryAuto(){
      if(operating||saving||blocked||!ready||!canAuto(device,received,{visible:visible(),focused:document.hasFocus(),mobile:mobile()}))return;
      const d=device,r=d.dialer.request,key=scope(d),attempt=key+'/'+r.id;
      if(attempted===attempt||dismissed===attempt)return;
      attempted=attempt;operating=true;renderAlert();
      try{
        const result=await request('dialer_claim',{...bound(d),dialer_request_id:r.id});
        const returned=result?.device,rr=returned?.dialer?.request;
        // Revalidate the exact authenticated response and foreground after awaiting the claim.
        if(result?.dialer_claimed!==r.id||scope(returned)!==key||rr?.id!==r.id||rr.auto_attempted!==true||
           rr.destination!==r.destination||rr.auto_allowed!==true||returned.dialer.auto_number!==r.destination||
           !current(key,r.id)||!document.hasFocus()||blocked||saving||!mobile())return;
        openNative(r.destination);
      }catch{ /* The user still has the direct, trusted tap fallback. No automatic retry. */ }
      finally{operating=false;renderAlert();}
    }
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(!event.isTrusted||!ready||!visible()||saving||!scope(device))return;
      const d=device,body={...bound(d),enabled:enabled.checked,auto_number:enabled.checked&&auto.checked?number(dest.value):''};
      if(body.auto_number&&!numberOK(body.auto_number))return;
      saving=true;blocked=true;controls();renderAlert();feedback.textContent='Saving…';
      try{await request('dialer_preferences',body);feedback.textContent='Permissions saved.';blocked=false;}
      catch{feedback.textContent='Could not confirm permissions. Review and save again.';}
      finally{saving=false;controls();renderAlert();}
    });
    call.addEventListener('click',event=>{
      const d=device,r=d?.dialer?.request,key=scope(d);
      if(!event.isTrusted||operating||saving||blocked||!r||!current(key,r.id)||!numberOK(r.destination))return;
      dismissed=key+'/'+r.id;renderAlert();
      // Do not await a network request before the user gesture is handed to the OS.
      void request('dialer_handled',{...bound(d),dialer_request_id:r.id},true).catch(()=>{});
      try{openNative(r.destination);}catch{dismissed='';renderAlert();}
    });
    decline.addEventListener('click',async event=>{
      const d=device,r=d?.dialer?.request,key=scope(d);
      if(!event.isTrusted||operating||!r||!current(key,r.id))return;
      operating=true;renderAlert();
      try{await request('dialer_decline',{...bound(d),dialer_request_id:r.id});dismissed=key+'/'+r.id;}
      catch{feedback.textContent='Could not confirm decline. Check the connection.';}
      finally{operating=false;renderAlert();}
    });
    function bind(d,available){
      if(!available||!visible()||!scope(d)||typeof d.dialer.auto_number!=='string'){invalidate();return;}
      if(d!==lastRef){lastRef=d;received=performance.now();}
      if(!Number.isFinite(received))return;
      device=d;ready=true;prefs.hidden=false;
      const k=scope(d);
      if(signature!==k){
        signature=k;enabled.checked=d.dialer.enabled===true;auto.checked=d.dialer.auto_enabled===true;dest.value=d.dialer.auto_number;
        if(!saving){blocked=false;feedback.textContent='';}controls();
      }
      renderAlert();void tryAuto();
    }
    // Expiry and a lost foreground window clear the actionable number even without a poll.
    setInterval(renderAlert,1000);
    return {prefs,alert,bind,invalidate};
  }
  function owner({request}){
    const root=make('section',undefined,'phone-connected-profile phone-dialer-owner');root.hidden=true;
    const detail=make('p'),form=make('form',undefined,'phone-profile-form'),destination=make('input'),confirm=make('input'),send=make('button','Send call request','phone-primary'),cancel=button('Cancel dialer request'),feedback=make('p');
    destination.type='tel';destination.placeholder='+1 602 555 0100';destination.autocomplete='off';destination.maxLength=32;confirm.type='checkbox';send.type='submit';feedback.setAttribute('role','status');
    form.append(label('Destination number (international format)',destination),label('Ask this connected phone to open its own dialer',confirm),send);
    root.append(make('h3','Remote dialer'),detail,form,cancel,feedback);
    let device=null,signature='',busy=false,ready=false,draftId='',draftDestination='';
    function controls(){
      const p=device?.dialer,r=p?.request,pending=r?.state==='pending';
      root.hidden=!ready;form.hidden=!ready||!p?.enabled;cancel.hidden=!ready||!pending;
      destination.disabled=confirm.disabled=busy||pending;send.disabled=busy||pending||!p?.enabled||!confirm.checked||!numberOK(number(destination.value));cancel.disabled=busy;
      detail.textContent=!p?'Database upgrade required.':!p.enabled?'The connected phone holder has not enabled remote call requests.':
        (pending||r?.state?states[r.state]||'Status unavailable':'Ready to send a call request')+(p.auto_enabled?' · automatic opening restricted to the holder’s approved number':' · manual Call now only');
    }
    function bind(d,available){
      const k=scope(d);if(k!==signature){signature=k;destination.value='';confirm.checked=false;draftId=draftDestination='';feedback.textContent='';}
      device=d;ready=!!k&&available&&visible();controls();
    }
    destination.addEventListener('input',()=>{confirm.checked=false;controls();});confirm.addEventListener('change',controls);
    async function act(action,fields){
      const d=device,key=scope(d);busy=true;controls();feedback.textContent='Sending…';
      try{await request(action,{...bound(d),...fields});if(scope(device)!==key)return;feedback.textContent=action==='dialer_send'?'Request saved. This does not mean a call was placed.':'Request canceled.';destination.value='';confirm.checked=false;draftId=draftDestination='';}
      catch{if(scope(device)===key)feedback.textContent='Result unconfirmed. Refresh before retrying.';}
      finally{busy=false;controls();}
    }
    form.addEventListener('submit',event=>{
      event.preventDefault();if(!event.isTrusted||!ready||busy||send.disabled)return;
      const n=number(destination.value);if(!draftId||draftDestination!==n){draftId=requestId();draftDestination=n;}
      void act('dialer_send',{dialer_request_id:draftId,destination:n,confirmed:true});
    });
    cancel.addEventListener('click',event=>{if(event.isTrusted&&ready&&!busy&&device.dialer.request?.state==='pending')void act('dialer_cancel',{dialer_request_id:device.dialer.request.id});});
    return {root,bind};
  }
  window.VP3RemoteDialerV1={holder,owner,numberOK,scope,live,canAuto};
})();
