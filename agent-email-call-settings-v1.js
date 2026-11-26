(() => {
  'use strict';
  if (window.VP3EmailCallSettingsV1) return;
  const ref = value => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value);
  const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
  const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  const integer = (value,min=0,max=Number.MAX_SAFE_INTEGER) => Number.isSafeInteger(value) && value >= min && value <= max;
  const text = (value,max) => typeof value === 'string' && value.length <= max && !/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/.test(value);
  const timestamp = value => value === null || typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)$/.test(value) && Number.isFinite(Date.parse(value));
  const reason = value => typeof value === 'string' && /^[a-z][a-z0-9_]{0,63}$/.test(value);
  const callLabels = Object.freeze({prepared:'Email call prepared',submitting:'Starting email call',submitted:'Email call requested',
    uncertain:'Call outcome unconfirmed',ended:'Email call ended',missed:'Email call missed',blocked:'Email call paused'});
  const setupMessages = Object.freeze({workspace_enrollment_required:'Enable your private Agent workspace on this phone first.',
    owner_phone_required:'Save your main phone number in Account phone settings.',owner_updates_off:'Enable requested Agent updates in Account phone settings.',
    recipient_changed:'Use the same main phone number in Account settings and this phone’s communication profile.',
    recipient_permission_required:'In Phone connection, allow spontaneous Agent calls, including recording and transcription.',
    line_required:'Assign an active Agent calling line before turning on email calls.',enrollment_changed:'Reconnect this phone’s private Agent workspace, then save email call permission again.',
    device_changed:'Reconnect this approved owner phone before turning on email calls.'});
  const check = (value,message='Email call settings could not be verified. Refresh to check the current settings.') => {
    if (!value) {const error=new Error(message);error.code='invalid_email_calls_response';throw error;}
  };
  function safeLink(value) {
    check(typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') && !value.includes('\\') && !/[\u0000-\u0020\u007f]/.test(value));
    return value;
  }
  function context(value) {
    check(object(value) && text(value.csrf_token,256) && value.csrf_token.length >= 1);
    if (Object.hasOwn(value,'device_id')) {
      check(ref(value.device_id) && integer(value.enrollment_revision,1) && hash(value.scope_revision) && integer(value.agent_id) && integer(value.conversation_id,1));
      return {device_id:value.device_id,enrollment_revision:value.enrollment_revision,scope_revision:value.scope_revision,
        agent_id:value.agent_id,conversation_id:value.conversation_id,csrf_token:value.csrf_token};
    }
    check(typeof value.owner_key === 'string' && /^[1-9]\d{0,18}$/.test(value.owner_key) && integer(value.agent_id) && integer(value.conversation_id,1));
    return {owner_key:value.owner_key,agent_id:value.agent_id,conversation_id:value.conversation_id,csrf_token:value.csrf_token};
  }
  function scopeResponse(raw,current) {
    check(object(raw) && raw.ok === true);
    if (current.device_id) check(object(raw.scope) && raw.scope.device_id === current.device_id
      && raw.scope.enrollment_revision === current.enrollment_revision && raw.scope.scope_revision === current.scope_revision,
      'Your phone or Agent authorization changed. Reopen Email calls from your current connection.');
  }
  function call(value,active=false) {
    if (value === null) return null;
    check(object(value) && ref(value.request_id) && integer(value.revision,1) && Object.hasOwn(callLabels,value.state)
      && (value.call_id === null || ref(value.call_id)));
    const result={request_id:value.request_id,revision:value.revision,state:value.state,call_id:value.call_id};
    if (active) {
      check(ref(value.item_ref) && ['gmail_draft','gmail_attention'].includes(value.source_kind));
      result.item_ref=value.item_ref;result.source_kind=value.source_kind;
    } else {
      check(reason(value.reason) && timestamp(value.updated_at) && value.updated_at !== null);
      result.reason=value.reason;result.updated_at=value.updated_at;
    }
    return result;
  }
  function state(raw,current) {
    scopeResponse(raw,current);
    const saved=raw.policy,owner=raw.owner_phone;
    check(object(saved) && integer(saved.revision) && typeof saved.urgent_enabled === 'boolean' && typeof saved.drafts_enabled === 'boolean'
      && integer(saved.grace_seconds,60,3600) && (saved.device_id === null || saved.device_id === '' || ref(saved.device_id)) && integer(saved.number_id)
      && Array.isArray(raw.devices) && raw.devices.length <= 100);
    const ids=new Set(),devices=raw.devices.map(value => {
      check(object(value) && ref(value.device_id) && !ids.has(value.device_id) && text(value.label,480) && text(value.agent_name,480)
        && typeof value.ready === 'boolean' && reason(value.reason) && Array.isArray(value.lines) && value.lines.length <= 100);
      ids.add(value.device_id);const numbers=new Set();
      const lines=value.lines.map(line => {check(object(line) && integer(line.number_id,1) && !numbers.has(line.number_id) && text(line.label,480));
        numbers.add(line.number_id);return {number_id:line.number_id,label:line.label};});
      return {device_id:value.device_id,label:value.label,agent_name:value.agent_name,ready:value.ready,reason:value.reason,lines};
    });
    check(object(owner) && text(owner.masked_number,160) && typeof owner.updates_requested === 'boolean' && text(owner.time_zone,80)
      && /^([01]\d|2[0-3]):[0-5]\d$/.test(owner.window_start) && /^([01]\d|2[0-3]):[0-5]\d$/.test(owner.window_end)
      && integer(owner.max_calls_per_day,0,100) && integer(owner.min_gap_minutes,0,10080) && integer(owner.max_duration_seconds,0,3600));
    try {new Intl.DateTimeFormat('en',{timeZone:owner.time_zone});} catch {check(false);}
    check(object(raw.readiness) && ['off','ready','blocked'].includes(raw.readiness.state) && reason(raw.readiness.reason) && text(raw.readiness.message,2000));
    check(raw.decision === null || object(raw.decision) && reason(raw.decision.reason) && timestamp(raw.decision.at) && raw.decision.at !== null);
    check(object(raw.links));
    return {policy:{revision:saved.revision,urgent_enabled:saved.urgent_enabled,drafts_enabled:saved.drafts_enabled,grace_seconds:saved.grace_seconds,
      device_id:saved.device_id||'',number_id:saved.number_id},devices,
      owner_phone:{masked_number:owner.masked_number,updates_requested:owner.updates_requested,time_zone:owner.time_zone,
        window_start:owner.window_start,window_end:owner.window_end,max_calls_per_day:owner.max_calls_per_day,min_gap_minutes:owner.min_gap_minutes,max_duration_seconds:owner.max_duration_seconds},
      readiness:{state:raw.readiness.state,reason:raw.readiness.reason,message:raw.readiness.message},
      decision:raw.decision ? {reason:raw.decision.reason,at:raw.decision.at} : null,active_call:call(raw.active_call,true),
      links:{account_phone:safeLink(raw.links.account_phone),phone_connection:safeLink(raw.links.phone_connection)}};
  }
  function statuses(raw,current,requested) {
    scopeResponse(raw,current);check(Array.isArray(requested) && requested.length <= 40 && requested.every(ref));
    check(Array.isArray(raw.items) && raw.items.length <= 40);const seen=new Set(),allowed=new Set(requested);
    const items=raw.items.map(value => {check(object(value) && ref(value.item_ref) && allowed.has(value.item_ref) && !seen.has(value.item_ref));
      seen.add(value.item_ref);return {item_ref:value.item_ref,call:call(value.call)};});
    for(const itemRef of requested)if(!seen.has(itemRef))items.push({item_ref:itemRef,call:null});
    return {items,active_call:call(raw.active_call,true)};
  }
  // Call progress has its own version. It never writes an email's semantic source revision or receipt.
  function createCallState() {
    const entries=new Map();let active=null;
    return Object.freeze({
      merge(value) {
        for (const item of value.items) {
          const previous=entries.get(item.item_ref),next=item.call;
          if (previous && next && previous.request_id === next.request_id && next.revision < previous.revision) continue;
          entries.set(item.item_ref,next);
        }
        const next=value.active_call;
        if (!active || !next || active.request_id !== next.request_id || next.revision >= active.revision) active=next;
        return active;
      },
      get:itemRef => entries.get(itemRef)||null,active:() => active,clear(){entries.clear();active=null;}
    });
  }
  function createClient({endpoint,context:input,isCurrent=() => true}) {
    const current=context(input),url=new URL(endpoint,location.href);
    check(url.origin === location.origin && !url.username && !url.password);
    let disposed=false;const requests=new Set();
    const valid=() => !disposed && isCurrent();
    async function request(action,fields={}) {
      check(['state','save','statuses'].includes(action));if (!valid()) throw new DOMException('Context changed','AbortError');
      const controller=new AbortController();requests.add(controller);const timeout=setTimeout(() => controller.abort(),15000);
      try {
        const response=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',referrerPolicy:'no-referrer',
          headers:{'Content-Type':'application/json',[current.device_id?'X-VP3-Phone':'X-VP3-Email-Calls']:'1'},signal:controller.signal,
          body:JSON.stringify({...fields,...current,action})});
        const value=await response.json().catch(() => null);
        if (!valid() || controller.signal.aborted) throw new DOMException('Context changed','AbortError');
        if (!response.ok || value?.ok !== true) {
          const error=new Error(text(value?.message,2000) ? value.message : 'Email calls could not be checked. Try Refresh.');
          error.status=response.status;error.code=reason(value?.error)?value.error:'email_calls_unavailable';throw error;
        }
        scopeResponse(value,current);return value;
      } finally {clearTimeout(timeout);requests.delete(controller);}
    }
    return Object.freeze({context:current,request,dispose(){disposed=true;for(const request of requests)request.abort();requests.clear();}});
  }
  window.VP3EmailCallSettingsContractV1=Object.freeze({context,state,statuses,call,createCallState,labels:callLabels});

  function mount({host,endpoint,context:input,isCurrent=() => true,onAccessChanged,onSaved}) {
    if (!(host instanceof HTMLElement)) return null;
    const client=createClient({endpoint,context:input,isCurrent});
    const node=(tag,value='',className='') => {const item=document.createElement(tag);item.textContent=value;if(className)item.className=className;return item;};
    const button=(label,handler) => {const item=node('button',label);item.type='button';item.addEventListener('click',event => {if(event.isTrusted)void handler(event);});return item;};
    let saved=null,baseline='',disposed=false,loading=false,saving=false,conflict=false,serial=0,leave=null;
    const form=node('form','','vp3-email-call-settings');form.noValidate=true;form.setAttribute('aria-label','Email calls');
    const title=node('h3','Email calls'),intro=node('p','Let your Agent call your connected phone when selected email work still needs you after a grace period.','vp3-email-call-intro');
    const readiness=node('p','Checking email call settings…','vp3-email-call-readiness');readiness.setAttribute('role','status');
    const fields=node('fieldset');fields.hidden=true;
    const devices=node('select'),lines=node('select'),grace=node('input');grace.type='number';grace.min='1';grace.max='60';grace.step='1';grace.inputMode='numeric';
    const field=(label,control) => {const item=node('label','','vp3-email-call-field');item.append(node('span',label),control);return item;};
    const toggle=label => {const item=node('label','','vp3-email-call-toggle'),input=node('input');input.type='checkbox';item.append(input,node('span',label));return {item,input};};
    const urgent=toggle('Urgent incoming email'),drafts=toggle('Agent-prepared email drafts ready for review');
    const consent=toggle('Allow recorded and transcribed AI calls about these email items to my connected phone.');
    const policy=node('div','','vp3-email-call-policy'),links=node('div','','vp3-email-call-actions'),deviceHint=node('p','','vp3-email-call-intro');deviceHint.hidden=true;
    const graceNote=node('p','Wait this long for you to review the item before considering a call. Viewing a notification alone does not approve an email.','vp3-email-call-intro');
    const scopeNote=node('p','Calls use your shared owner calling hours and limits. The Agent does not use browser activity to decide whether you are away.','vp3-email-call-intro');
    fields.append(field('Connected phone',devices),field('Calling line',lines),deviceHint,urgent.item,drafts.item,field('Grace period (minutes)',grace),graceNote,policy,scopeNote,consent.item);
    const feedback=node('p','','vp3-email-call-feedback');feedback.setAttribute('role','status');feedback.setAttribute('aria-live','polite');
    const actions=node('div','','vp3-email-call-actions'),save=node('button','Save email calls');save.type='submit';
    const refreshButton=button('Refresh',() => refresh({explicit:true}));actions.append(save,refreshButton);
    const leaveHost=node('div','','vp3-email-call-leave');leaveHost.hidden=true;
    form.append(title,intro,readiness,fields,feedback,actions,links,leaveHost);host.append(form);
    const valid=() => !disposed && isCurrent();
    const values=() => ({urgent_enabled:urgent.input.checked,drafts_enabled:drafts.input.checked,grace_seconds:Number(grace.value)*60,
      device_id:devices.value,number_id:Number(lines.value)});
    const key=value => JSON.stringify([value.urgent_enabled,value.drafts_enabled,value.grace_seconds,value.device_id,value.number_id]);
    const dirty=() => saved !== null && key(values()) !== baseline;
    const message=(value,error=false) => {feedback.textContent=value;feedback.dataset.error=String(error);};
    const selected=() => saved?.devices.find(value => value.device_id === devices.value);
    function controls() {
      fields.disabled=loading || saving || !saved || !valid();
      save.disabled=loading || saving || !saved || !dirty() || conflict || !valid();refreshButton.disabled=loading || saving || !valid();
      consent.item.hidden=!(urgent.input.checked || drafts.input.checked);
      const chosen=selected();deviceHint.hidden=chosen?.ready===true;
      deviceHint.textContent=chosen?.ready?'':setupMessages[chosen?.reason]||'Connect an approved owner phone with an active Agent calling line to use email calls.';
      for(const item of leaveHost.querySelectorAll('button'))item.disabled=saving || loading;
    }
    function lineOptions(value) {
      lines.replaceChildren();const choices=selected()?.lines||[];
      if (!choices.length) {const empty=node('option','No eligible calling line');empty.value='0';lines.append(empty);}
      for(const choice of choices){const item=node('option',choice.label);item.value=String(choice.number_id);lines.append(item);}
      if (choices.some(choice => choice.number_id === value)) lines.value=String(value);
      else lines.value=choices.length ? String(choices[0].number_id) : '0';
    }
    function details(value) {
      readiness.dataset.state=value.readiness.state;readiness.textContent=value.readiness.message || (value.readiness.state === 'off' ? 'Email calls are off.' : 'Check the current calling requirements.');
      const owner=value.owner_phone,description=node('dl');
      for(const [name,content] of [['Your number',owner.masked_number || 'Not connected'],['Calling hours',owner.window_start+'–'+owner.window_end+' · '+owner.time_zone],
        ['Shared limits',owner.max_calls_per_day+' '+(owner.max_calls_per_day===1?'call':'calls')+'/day · at least '+owner.min_gap_minutes+' minutes apart'],
        ['Maximum duration',Math.ceil(owner.max_duration_seconds/60)+' '+(owner.max_duration_seconds===60?'minute':'minutes')]]) description.append(node('dt',name),node('dd',content));
      policy.replaceChildren(description);
      if (!owner.updates_requested) policy.append(node('p','Agent update calls are off in your account. Enable them there before turning on email calls.'));
      links.replaceChildren();
      for(const [label,href] of [['Number, hours and limits',value.links.account_phone],['Phone connection',value.links.phone_connection]]) {
        const link=node('a',label);link.href=href;links.append(link);
      }
    }
    function apply(value,{preserve=false}={}) {
      const previous=saved,editing=preserve && dirty();saved=value;details(value);fields.hidden=false;
      if (editing) {
        if (conflict || previous.policy.revision !== value.policy.revision) {conflict=true;message('Email call settings changed elsewhere. Use the current settings before saving again.',true);showReload();}
        controls();return;
      }
      conflict=false;devices.replaceChildren();
      if (!value.devices.length) {const empty=node('option','Connect your owner phone first');empty.value='';devices.append(empty);}
      for(const candidate of value.devices){const option=node('option',candidate.label+' · '+candidate.agent_name);option.value=candidate.device_id;devices.append(option);}
      if(value.devices.some(candidate=>candidate.device_id===value.policy.device_id))devices.value=value.policy.device_id;
      else if (value.policy.device_id) {const option=node('option','Previously selected phone — unavailable');option.value=value.policy.device_id;devices.append(option);devices.value=value.policy.device_id;}
      else devices.value=value.devices[0]?.device_id||'';
      lineOptions(value.policy.number_id);urgent.input.checked=value.policy.urgent_enabled;drafts.input.checked=value.policy.drafts_enabled;
      grace.value=String(value.policy.grace_seconds/60);consent.input.checked=false;baseline=key(values());controls();
    }
    function showReload() {
      if(feedback.querySelector('button'))return;
      const reset=button('Use current settings',() => {if(saved && !saving){apply(saved);message('Current settings loaded. Review them before saving.');}});
      feedback.append(document.createTextNode(' '),reset);
    }
    function failed(error) {
      if(error?.name==='AbortError' || !valid())return;
      if([401,403,419].includes(error.status) || error.code==='invalid_email_calls_response') {
        fields.hidden=true;policy.replaceChildren();links.replaceChildren();saved=null;baseline='';readiness.dataset.state='blocked';readiness.textContent='Email call settings are no longer available in this context.';
        message(error.message,true);onAccessChanged?.(error);
      } else {
        readiness.dataset.state='blocked';readiness.textContent='Current email call readiness could not be verified. Refresh to check it.';
        message(error.message || 'Email calls could not be checked. Try Refresh.',true);
      }
    }
    async function refresh({explicit=false}={}) {
      if(!valid() || loading || saving)return false;
      const request=++serial;loading=true;controls();if(explicit)message('Checking current email call settings…');
      try{
        const result=state(await client.request('state'),client.context);if(!valid() || request!==serial)return false;
        apply(result,{preserve:true});if(!conflict && explicit)message(dirty()?'Current call readiness checked. Your unsaved choices are kept.':'Current email call settings loaded.');return true;
      }catch(error){if(request===serial)failed(error);return false;}
      finally{if(valid() && request===serial){loading=false;controls();}}
    }
    function validation(value) {
      if(!integer(value.grace_seconds,60,3600) || value.grace_seconds%60) return 'Choose a grace period from 1 to 60 whole minutes.';
      if(value.urgent_enabled || value.drafts_enabled){
        const device=selected();
        if(!device?.ready || !device.lines.some(line=>line.number_id===value.number_id))return 'Choose an eligible owner phone and calling line in Phone connection first.';
        if(!saved.owner_phone.updates_requested)return 'Enable Agent update calls in your account before turning on email calls.';
        if(!consent.input.checked)return 'Confirm that you allow recorded and transcribed AI calls about these email items.';
      }
      return '';
    }
    async function submit() {
      if(!valid() || loading || saving || !saved || conflict || !dirty())return false;
      const value=values(),error=validation(value);if(error){message(error,true);return false;}
      const request=++serial;saving=true;controls();message('Saving email call choices…');
      try{
        const result=state(await client.request('save',{expected_revision:saved.policy.revision,urgent_enabled:value.urgent_enabled,drafts_enabled:value.drafts_enabled,
          grace_seconds:value.grace_seconds,selected_device_id:value.device_id,number_id:value.number_id,confirmed:consent.input.checked}),client.context);
        if(!valid() || request!==serial)return false;apply(result);message(value.urgent_enabled || value.drafts_enabled ? 'Email call choices saved. New eligible work will use these choices.' : 'Email calls are off.');onSaved?.(result);if(leave)finishLeave(true);return true;
      }catch(error){
        if(request===serial){failed(error);if(error?.status===409 && valid()){conflict=true;message('Email call settings changed. Refresh before saving again; your choices have not been applied.',true);}}
        return false;
      }finally{if(valid() && request===serial){saving=false;controls();}}
    }
    form.addEventListener('submit',event=>{event.preventDefault();if(event.isTrusted)void submit();});
    for(const control of [urgent.input,drafts.input,grace,consent.input,lines])control.addEventListener('input',()=>{if(!saving){if(control!==consent.input)consent.input.checked=false;controls();}});
    devices.addEventListener('change',()=>{if(!saving){lineOptions(0);consent.input.checked=false;controls();}});
    function finishLeave(value){if(!leave)return;const held=leave;leave=null;leaveHost.hidden=true;leaveHost.replaceChildren();held.resolve(value);}
    function requestClose() {
      if(disposed || !dirty())return Promise.resolve(true);if(leave)return leave.promise;
      let resolve;const promise=new Promise(done=>{resolve=done;});leave={resolve,promise};leaveHost.hidden=false;
      const keep=button('Keep editing',()=>finishLeave(false));
      leaveHost.replaceChildren(node('p','You have unsaved Email calls choices.'),keep,
        button('Save choices and leave',async()=>{if(await submit())finishLeave(true);}),button('Discard changes',()=>{if(!saving && saved){apply(saved);message('');finishLeave(true);}}));
      controls();keep.focus();return promise;
    }
    void refresh();
    return Object.freeze({refresh,requestClose,isDirty:dirty,dispose(){if(disposed)return;disposed=true;serial++;client.dispose();finishLeave(false);form.remove();}});
  }
  window.VP3EmailCallSettingsV1=Object.freeze({mount,createClient});
})();
