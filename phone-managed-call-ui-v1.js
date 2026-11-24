(() => {
  'use strict';
  const idOK = v => typeof v==='string'&&/^[a-f0-9]{32}$/.test(v);
  const numberOK = v => typeof v==='string'&&/^\+[1-9][0-9]{7,14}$/.test(v);
  const normalize = v => String(v).trim().replace(/[ ().-]/g,'');
  const make=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;};
  const button=(text,cls)=>{const e=make('button',text,cls);e.type='button';return e;};
  const label=(text,input)=>{const e=make('label',undefined,'phone-dialer-label');e.append(input,make('span',text));return e;};
  const visible=()=>!document.hidden&&navigator.onLine!==false;
  const key=d=>d?.status==='approved'&&idOK(d.id)&&Number.isSafeInteger(d.generation)?d.id+'/'+d.generation+'/'+(d.communication?.revision??0):'';
  const bound=d=>({device_id:d.id,generation:d.generation});
  const randomId=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),v=>v.toString(16).padStart(2,'0')).join('');
  const reasons={upgrade_required:'Managed calling requires the latest Cloud upgrade.',no_assigned_line:'An authorized, voice-enabled Twilio line is required.',chat_required:'Open the paired Agent Chat to establish the private conversation.',voice_required:'The account voice allowance is not active.',permission_required:'Both users must allow calls and the holder must save a phone number.',carrier_not_ready:'The assigned carrier service is not configured.',outside_calling_hours:'Outside the connected user’s permitted calling hours.',unavailable:'Managed calling is unavailable. Refresh or check configuration.'};
  const labels={pending:'Awaiting connected-user approval',approved:'Approved — no call has been placed',declined:'Declined',canceled:'Canceled',expired:'Expired',unavailable:'Permissions or assignment changed',revoked:'Permission withdrawn',dispatch_claimed:'Carrier dispatch reserved',finished:'Call ended',dialing:'Dialing',ringing:'Destination ringing',answered:'Destination answered',completed:'Completed',busy:'Destination busy',no_answer:'No answer',failed:'Failed'};
  const active=r=>r&&['pending','approved','dispatch_claimed','revoked'].includes(r.state);
  const stateText=r=>r?.call?.dispatch_state==='uncertain'?'Carrier result uncertain — do not retry':labels[r?.call?.state||r?.state]||'No current managed request';
  function remaining(p,received){
    if(!p?.request||!Number.isSafeInteger(p.server_epoch)||!Number.isSafeInteger(p.request.expires_epoch))return 0;
    return Math.max(0,p.request.expires_epoch-p.server_epoch-(performance.now()-received)/1000);
  }
  const recordingText=p=>'Save call audio'+(p?.transcribe?' · Cloud AI transcription':'')+(p?.analyze?' · Cloud AI conversation report':'');
  function owner({request,directRoot,openBrowser}){
    const root=make('section',undefined,'phone-connected-profile phone-managed-modes');root.hidden=true;
    const heading=make('h3','Calling modes'),tabs=make('div',undefined,'phone-managed-mode-tabs');
    tabs.setAttribute('role','group');tabs.setAttribute('aria-label','Calling mode');
    const choices=[['personal','Personal cellular'],['managed','Twilio managed callback'],['browser','Browser call']];
    const buttons=new Map();
    const personal=make('div');if(directRoot)personal.append(directRoot);
    const managed=make('div',undefined,'phone-managed-panel'),browser=make('div',undefined,'phone-managed-panel');
    const detail=make('p'),state=make('p'),feedback=make('p');state.setAttribute('role','status');feedback.setAttribute('role','status');
    const form=make('form',undefined,'phone-profile-form'),line=make('select'),dest=make('input'),confirm=make('input'),send=make('button','Request managed callback approval','phone-primary'),cancel=button('Cancel managed request'),reconcile=button('Check carrier status'),end=button('End managed call','phone-primary');
    line.setAttribute('aria-label','Assigned Twilio line');dest.type='tel';dest.autocomplete='off';dest.placeholder='+1 602 555 0100';dest.maxLength=32;
    confirm.type='checkbox';send.type='submit';
    const record=make('input');record.type='checkbox';
    const recordLabel=label('Request recording with participant consent',record),recordDetail=make('p',undefined,'phone-detail-meta');
    form.append(label('Assigned Twilio line',line),label('Managed destination number (international format)',dest),recordLabel,recordDetail,label('Ask the holder to review this exact destination. No live call will be placed in this release.',confirm),send);
    managed.append(make('h4','Managed callback review'),make('p','Twilio will eventually call the connected phone first, then bridge the approved destination after keypad confirmation. Live dispatch and recording startup remain disabled. You may request recording consent for review. Existing managed calls can be checked or ended using the carrier controls.'),detail,state,form,cancel,reconcile,end,feedback);
    const browserButton=button('Open browser calling','phone-primary');
    browser.append(make('h4','Browser call to connected user'),make('p','Use your existing assigned line and browser audio to call this connected phone. The Phone drawer requires confirmation.'),browserButton);
    let device=null,scope='',mode='personal',ready=false,busy=false,linesSignature='',policySignature='',draftId='',draftKey='',received=0,lastProjection=null;
    function setMode(next){mode=next;controls();}
    for(const [v,t] of choices){const b=button(t);b.addEventListener('click',()=>setMode(v));buttons.set(v,b);tabs.append(b);}
    root.append(heading,tabs,personal,managed,browser);
    function controls(){
      const p=device?.managed_callback,r=p?.request;
      root.hidden=!ready;personal.hidden=mode!=='personal';managed.hidden=mode!=='managed';browser.hidden=mode!=='browser';
      for(const [v,b] of buttons)b.setAttribute('aria-pressed',String(mode===v));
      detail.textContent=p?.available?'Ready to prepare a review request.':reasons[p?.reason]||'Managed calling requires a private connected-phone profile.';
      const expired=r&&['pending','approved'].includes(r.state)&&remaining(p,received)<=0;
      state.textContent=expired?'Expired':stateText(r)+(r?.call?.state==='completed'?' · '+r.call.duration_seconds+' seconds':'');
      buttons.get('managed').textContent=active(r)&&!expired?'Twilio managed callback · '+(labels[r.state]||'Active'):'Twilio managed callback';
      const options=Array.isArray(p?.lines)?p.lines.filter(n=>Number.isSafeInteger(n.id)&&n.id>0&&numberOK(n.phone_e164)):[];
      const next=JSON.stringify(options);
      if(next!==linesSignature){
        const old=line.value;line.replaceChildren();
        for(const n of options){const opt=make('option',n.phone_e164+(n.agent_name?' · '+n.agent_name:''));opt.value=String(n.id);line.append(opt);}
        if(options.some(n=>String(n.id)===old))line.value=old;
        else confirm.checked=false;
        linesSignature=next;
      }
      const selectedPolicy=options.find(n=>String(n.id)===line.value)?.recording_policy;
      const policyOK=Number.isSafeInteger(selectedPolicy?.revision)&&selectedPolicy.revision>0&&selectedPolicy.retain_audio===true;
      const policyKey=JSON.stringify([line.value,policyOK?selectedPolicy:null]);
      if(policyKey!==policySignature){policySignature=policyKey;record.checked=false;confirm.checked=false;}
      recordLabel.hidden=recordDetail.hidden=!policyOK;
      recordDetail.textContent=policyOK?recordingText(selectedPolicy)+' · both call participants must explicitly agree.':'';
      if(r?.recording?.requested)state.textContent+=' · Recording: '+String(r.recording.state||'requested').replaceAll('_',' ');
      const blocked=active(r)&&!expired;
      form.hidden=!p?.available;
      const disabled=busy||blocked||!ready||!visible()||!p?.available;
      dest.disabled=confirm.disabled=line.disabled=disabled;record.disabled=disabled||!policyOK;
      send.disabled=disabled||!confirm.checked||!numberOK(normalize(dest.value))||!options.some(n=>String(n.id)===line.value);
      cancel.hidden=!r||!['pending','approved'].includes(r.state)||expired;
      cancel.disabled=busy||!ready||!visible();
      const control=r?.call?.managed_control;
      reconcile.hidden=control?.can_reconcile!==true;end.hidden=control?.can_end!==true;
      reconcile.disabled=end.disabled=busy||!ready||!visible();
      if(control?.missed_leg)state.textContent+=' · No answer: '+(control.missed_leg==='holder'?'connected phone':'destination');
      if(control?.end_state&&control.end_state!=='none')state.textContent+=' · End request: '+control.end_state.replaceAll('_',' ');
      if(control?.read_state&&control.read_state!=='none')state.textContent+=' · Carrier check: '+control.read_state.replaceAll('_',' ');
      browserButton.disabled=!ready||busy||device?.communication?.call_permission_saved!==true||!device?.agent_chat?.conversation_id;
    }
    function bind(d,available){
      const k=key(d);
      if(k!==scope){scope=k;mode='personal';dest.value='';confirm.checked=false;record.checked=false;draftId=draftKey='';feedback.textContent='';}
      device=d;ready=!!k&&available&&visible();
      if(!ready){dest.value='';confirm.checked=false;record.checked=false;draftId=draftKey='';}
      if(d?.managed_callback!==lastProjection){lastProjection=d?.managed_callback;received=performance.now();}
      controls();
    }
    async function act(action,fields){
      const d=device,k=key(d);busy=true;feedback.textContent='Saving…';controls();
      try{
        const value=await request(action,{...bound(d),...fields});
        if(scope!==k||!ready)return;
        if(action==='managed_end'||action==='managed_reconcile'){
          const c=value?.call?.managed_control;
          feedback.textContent=action==='managed_end'?(c?.end_state==='parent_ended'?'Carrier confirmed the holder leg ended.':'End request submitted or fenced. Check carrier status; hang up on the phone if needed.'):'Carrier status checked; unresolved states remain unconfirmed.';
        }else if(value?.managed_callback?.request?.state){
          feedback.textContent=action==='managed_prepare'?'Review request saved. No call has been placed.':'Request canceled.';
          dest.value='';confirm.checked=false;record.checked=false;draftId=draftKey='';
        }else feedback.textContent='Request saved; refresh the connected phone for current status.';
      }catch(error){if(scope===k)feedback.textContent='Request not confirmed. Refresh before retrying.';}
      finally{busy=false;controls();}
    }
    dest.addEventListener('input',()=>{confirm.checked=false;controls();});
    line.addEventListener('change',()=>{confirm.checked=false;record.checked=false;controls();});
    record.addEventListener('change',()=>{confirm.checked=false;controls();});
    confirm.addEventListener('change',controls);
    form.addEventListener('submit',event=>{
      event.preventDefault();if(!event.isTrusted||!ready||!visible()||send.disabled||busy)return;
      const p=device.communication,n=normalize(dest.value),numberId=Number(line.value);
      if(!idOK(p?.profile_id)||!numberOK(n)||!Number.isSafeInteger(numberId))return;
      const policy=device.managed_callback?.lines?.find(x=>x.id===numberId)?.recording_policy;
      const recording=record.checked&&!record.disabled&&Number.isSafeInteger(policy?.revision)?{recording_requested:true,recording_policy_revision:policy.revision}:{};
      const sig=JSON.stringify([scope,numberId,n,recording]);
      if(!draftId||draftKey!==sig){draftId=randomId();draftKey=sig;}
      void act('managed_prepare',{managed_request_id:draftId,profile_id:p.profile_id,profile_revision:p.revision,phone_revision:p.phone_revision,number_id:numberId,destination_e164:n,confirmed:true,...recording});
    });
    cancel.addEventListener('click',event=>{
      const r=device?.managed_callback?.request;
      if(event.isTrusted&&ready&&visible()&&!busy&&!cancel.hidden&&idOK(r?.request_id))void act('managed_cancel',{managed_request_id:r.request_id});
    });
    reconcile.addEventListener('click',event=>{
      const c=device?.managed_callback?.request?.call;
      if(event.isTrusted&&ready&&visible()&&!busy&&!reconcile.hidden&&idOK(c?.public_id))void act('managed_reconcile',{call_id:c.public_id});
    });
    end.addEventListener('click',event=>{
      const c=device?.managed_callback?.request?.call;
      if(event.isTrusted&&ready&&visible()&&!busy&&!end.hidden&&idOK(c?.public_id)&&window.confirm('End this managed call and block any pending destination connection?'))void act('managed_end',{call_id:c.public_id,confirmed:true});
    });
    browserButton.addEventListener('click',event=>{
      if(event.isTrusted&&ready&&visible()&&!browserButton.disabled)openBrowser(device,browserButton);
    });
    return {root,bind,setMode};
  }
  function holder({request}){
    const root=make('section',undefined,'phone-dialer-alert phone-managed-holder');root.hidden=true;root.setAttribute('aria-label','Managed callback review');
    const heading=make('strong','Twilio managed callback'),detail=make('p'),destination=make('p',undefined,'phone-managed-destination'),state=make('p'),feedback=make('p');
    state.setAttribute('role','status');feedback.setAttribute('role','status');
    const consent=make('input');consent.type='checkbox';
    const check=label('I approve this exact destination for review. No live call or recording will start.',consent);
    const recordConsent=make('input');recordConsent.type='checkbox';
    const recordCheck=label('I also agree to the listed recording and processing options.',recordConsent),recordDetails=make('p');
    const actions=make('div',undefined,'phone-permission-buttons'),approve=button('Approve managed request','phone-primary'),decline=button('Decline managed request','secondary');
    actions.append(approve,decline);
    root.append(heading,detail,destination,state,check,recordDetails,recordCheck,actions,feedback);
    let device=null,scope='',requestKey='',ready=false,busy=false,received=0,lastProjection=null,invalidatedProjection=null;
    function controls(){
      const p=device?.managed_callback,r=p?.request;
      const fresh=performance.now()-received<10000;
      const show=ready&&visible()&&fresh&&!!r;
      root.hidden=!show;
      detail.textContent='Review only · live calls and recording are disabled.';
      const pending=show&&r?.state==='pending'&&remaining(p,received)>0&&numberOK(r.destination_e164)&&p.available===true;
      const approved=show&&r?.state==='approved'&&remaining(p,received)>0;
      destination.textContent=pending?r.destination_e164:'';
      state.textContent=show?(remaining(p,received)<=0&&['pending','approved'].includes(r.state)?'Expired':stateText(r)):'';
      check.hidden=!pending;approve.hidden=!pending;decline.hidden=!(pending||approved);actions.hidden=approve.hidden&&decline.hidden;
      const recordRequested=r?.recording?.requested===true;
      recordCheck.hidden=recordDetails.hidden=!pending||!recordRequested;
      recordDetails.textContent=pending&&recordRequested?recordingText(r.recording):'';
      recordConsent.disabled=busy||!pending||!recordRequested;
      consent.disabled=busy||!pending;approve.disabled=busy||!pending||!consent.checked||(recordRequested&&!recordConsent.checked);decline.disabled=busy||!(pending||approved);
      if(!show){consent.checked=false;recordConsent.checked=false;feedback.textContent='';}
    }
    function bind(d,available){
      const k=key(d),rk=k+'/'+(d?.managed_callback?.request?.request_id||'')+'/'+(d?.managed_callback?.request?.state||'')+'/'+(d?.managed_callback?.request?.recording?.revision||0);
      if(k!==scope||rk!==requestKey){scope=k;requestKey=rk;consent.checked=false;recordConsent.checked=false;feedback.textContent='';}
      const projection=d?.managed_callback;
      if(!available||!visible())invalidatedProjection=projection||lastProjection;
      if(projection&&projection!==invalidatedProjection)invalidatedProjection=null;
      device=d;ready=!!k&&available&&visible()&&!!projection&&projection!==invalidatedProjection;
      if(projection!==lastProjection){lastProjection=projection;received=performance.now();}
      controls();
    }
    function invalidate(){invalidatedProjection=lastProjection;device=null;scope=requestKey='';ready=false;consent.checked=false;recordConsent.checked=false;feedback.textContent='';controls();}
    consent.addEventListener('change',controls);recordConsent.addEventListener('change',controls);
    async function respond(decision){
      const d=device,r=d?.managed_callback?.request,k=key(d);
      if(!ready||!visible()||busy||!idOK(r?.request_id))return;
      busy=true;controls();feedback.textContent='Saving…';
      try{
        const body={...bound(d),managed_request_id:r.request_id,decision};
        if(decision==='approve'){
          body.confirmed=true;body.destination_e164=r.destination_e164;
          if(r.recording?.requested===true){body.recording_consent=true;body.recording_consent_revision=r.recording.revision;}
        }
        const result=await request('managed_respond',body);
        if(scope!==k||!ready)return;
        const saved=result?.device?.managed_callback?.request;
        feedback.textContent=saved?.state==='approved'?'Approved. No live call was placed.':saved?.state==='declined'?'Declined.':'Review updated.';
        consent.checked=false;
      }catch(error){if(scope===k)feedback.textContent='Unable to confirm the response. Check the connection.';}
      finally{busy=false;controls();}
    }
    approve.addEventListener('click',event=>{if(event.isTrusted&&!approve.disabled&&!approve.hidden)void respond('approve');});
    decline.addEventListener('click',event=>{if(event.isTrusted&&!decline.disabled&&!decline.hidden)void respond('decline');});
    setInterval(controls,1000);
    return {root,bind,invalidate};
  }
  window.VP3ManagedCallUIV1=Object.freeze({owner,holder,remaining,stateText});
})();
