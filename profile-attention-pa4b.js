(() => {
  'use strict';
  const CONTRACT = 'vp3.profile-attention.v1';
  const cfg = window.PROFILE_ATTENTION_PA4B_CONFIG || {};
  const integer = (value, zero = false) => Number.isSafeInteger(value) && value >= (zero ? 0 : 1);
  const text = (value, limit) => typeof value === 'string' && Array.from(value).length <= limit;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[c]));
  const token = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
  const roles = {visitor:'Visitor', owner:'Profile owner', agent:'Profile Agent', system:'System'};
  const operations = {read:'Mark read', snooze:'Snooze 24 hours', handled:'Mark handled', ignored:'Ignore'};
  const permission = {read:'can_read', snooze:'can_snooze', handled:'can_handle', ignored:'can_ignore'};
  const canonical = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 || {};
  const scopeKey = scope => `${scope.conversation_id}:${scope.agent_id}`;
  const sourceKey = item => [item.attention_id, item.notification_id, item.profile_event_id, item.public_conversation_id, item.public_message_id].join(':');
  function normalizeItem(raw) {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null;
    for (const key of ['attention_id','notification_id','profile_event_id','state_revision','wake_generation']) if (!integer(raw[key])) return null;
    for (const key of ['public_conversation_id','public_message_id','delivery_id','chat_conversation_id','chat_agent_id','chat_message_id']) if (!integer(raw[key], true)) return null;
    for (const key of ['read','presented','eligible','can_read','can_snooze','can_handle','can_ignore']) if (typeof raw[key] !== 'boolean') return null;
    if (!['verified','historical_unverified','unavailable'].includes(raw.source_status) || !Object.hasOwn(roles, raw.source_role)
      || !['pending','snoozed','handled','ignored'].includes(raw.disposition)
      || !['profile_attention_pa4b','profile_handoff_pa3','profile_speech_pa4a'].includes(raw.presentation_owner)
      || !['none','current','elsewhere'].includes(raw.delivery_location)
      || !text(raw.title,240) || !text(raw.source_label,100) || !text(raw.display_text,4000) || !text(raw.reason,100)
      || !(raw.snoozed_until_ms === null || integer(raw.snoozed_until_ms, true))) return null;
    if (raw.delivery_location === 'none' ? raw.delivery_id !== 0 || raw.chat_message_id !== 0 || raw.chat_conversation_id !== 0
      : !integer(raw.delivery_id) || !integer(raw.chat_message_id) || !integer(raw.chat_conversation_id)) return null;
    if (raw.source_status === 'verified' && !integer(raw.profile_event_id)) return null;
    const out = {};
    for (const key of ['attention_id','notification_id','profile_event_id','public_conversation_id','public_message_id','source_status','source_role','source_label','title','display_text','state_revision','wake_generation','disposition','read','presented','snoozed_until_ms','eligible','reason','can_read','can_snooze','can_handle','can_ignore','presentation_owner','delivery_id','chat_conversation_id','chat_agent_id','chat_message_id','delivery_location']) out[key] = raw[key];
    return Object.freeze(out);
  }
  function envelope(data) {
    return data && data.contract === CONTRACT && data.voice_allowed === false && typeof data.ok === 'boolean'
      && typeof data.complete === 'boolean' && integer(data.server_now_ms, true);
  }
  function error(message, status = 0, code = '') { return Object.assign(new Error(message), {status, code}); }
  async function request(action, input, post = false) {
    if (!cfg.endpoint || !cfg.csrfToken) throw error('Profile attention is unavailable. Reload to retry.');
    const endpoint = new URL(cfg.endpoint, window.location.href);
    if (endpoint.origin !== window.location.origin) throw error('Profile attention is unavailable.');
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 10000);
    try {
      const options = {credentials:'same-origin', cache:'no-store', signal:controller.signal};
      if (post) Object.assign(options, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action, csrf_token:cfg.csrfToken, ...input})});
      else { endpoint.searchParams.set('action', action); for (const [key,value] of Object.entries(input)) endpoint.searchParams.set(key, String(value)); }
      const response = await fetch(endpoint.toString(), options);
      const data = await response.json().catch(() => null);
      if (!response.ok || !envelope(data)) throw error(response.status === 409 ? 'This attention item changed. Refresh and review its current state.' : 'Profile attention could not be verified. The last confirmed list is retained. Retry to load its current state.', response.status, typeof data?.code === 'string' ? data.code : '');
      return data;
    } catch (cause) {
      if (cause?.status) throw cause;
      throw error('Profile attention could not be loaded. Your last confirmed list and drafts are unchanged.');
    } finally { window.clearTimeout(timeout); }
  }
  function create(options = {}) {
    const chatMode = options.mode === 'chat';
    const rows = new Map(), attempts = new Map(), pending = new Set(), acknowledging = new Set(), itemErrors = new Map(), mounted = new Map();
    let snapshot = {items:[], counts:null, complete:false, known:false, has_more:false, page:0, page_size:25, error:'', loading:false};
    let epoch = 0, readSequence = 0, sourceObservation = 0, pageEpoch = 0, requestedPage = 0, disposed = false, draftRevision = 0, refreshing = false;
    const clientId = token(), tabId = token();
    const scope = () => {
      const value = chatMode ? {conversation_id:Number(canonical().conversationId?.()), agent_id:Number(canonical().agentId?.())} : {conversation_id:0, agent_id:0};
      return integer(value.conversation_id,true) && integer(value.agent_id,true) ? value : null;
    };
    const capture = () => {const current=scope(); return current ? {...current, epoch, draftRevision} : null;};
    const current = bound => !!bound && !disposed && bound.epoch === epoch && scope() && scopeKey(bound) === scopeKey(scope());
    const emit = () => {if (!disposed) options.onChange?.(getState());};
    const getState = () => ({...snapshot, items:[...snapshot.items], counts:snapshot.counts ? {...snapshot.counts} : null});
    function idle(bound, preserveDraft = true) {
      if (!current(bound) || !chatMode || bound.conversation_id < 1 || document.hidden) return false;
      const c = canonical(), voice = window.STONEFELLOW_CHAT_CONTINUITY;
      if (c.isReady?.() !== true || c.isRestoring?.() || c.isBusy?.() || voice?.pendingVoiceSubmit?.()
        || (voice !== c && voice?.isBusy?.()) || window.VP3ProfileCallsPa3c?.speechBusy?.()) return false;
      if (document.getElementById('chatThread')?.dataset.view !== 'chat') return false;
      if (['processing','speaking','recording'].includes(String(document.body.dataset.stonefellowAgentState || ''))) return false;
      return !preserveDraft || (bound.draftRevision === draftRevision && !String(document.getElementById('chatInput')?.value || '').trim());
    }
    function sameItem(a,b) {return !!a && !!b && sourceKey(a) === sourceKey(b) && a.state_revision === b.state_revision && a.wake_generation === b.wake_generation;}
    function merge(item) {
      const old = rows.get(item.attention_id);
      if (old && (item.state_revision < old.state_revision || item.wake_generation < old.wake_generation)) return false;
      if (old && sourceKey(old) !== sourceKey(item)) return false;
      rows.set(item.attention_id, item);
      snapshot.items = snapshot.items.map(row => row.attention_id === item.attention_id ? item : row);
      sourceObservation++;
      return true;
    }
    function markUnavailable(message, clear = false) {
      sourceObservation++; snapshot = {...snapshot, complete:false, loading:false, error:message};
      if (clear) {snapshot.items=[]; snapshot.counts=null; snapshot.known=false; rows.clear();}
      for (const element of mounted.values()) renderSaved(element, null, message);
      emit();
    }
    function invalidate() {
      epoch++; readSequence++; sourceObservation++; pageEpoch++; requestedPage=0; refreshing=false;
      snapshot={items:[],counts:null,complete:false,known:false,has_more:false,page:0,page_size:25,error:'',loading:false};
      rows.clear(); itemErrors.clear(); pending.clear();
      for (const element of mounted.values()) renderSaved(element,null,'Loading the current Profile attention…');
      mounted.clear(); emit();
    }
    async function refresh(options = {}) {
      const navigation=Object.hasOwn(options,'page'),page=navigation?options.page:requestedPage;
      if(!integer(page,true)||page>100000)return false;
      const present=!navigation && page===0 && (options.present ?? chatMode)===true;
      const bound=capture(); if (!bound || disposed || (chatMode && document.hidden)) return false;
      if(navigation){requestedPage=page;pageEpoch++;sourceObservation++;}
      const pageVersion=pageEpoch;
      const serial=++readSequence; refreshing=true; snapshot={...snapshot,loading:true};
      if(navigation)emit();
      try {
        const data=await request('profile_attention_state',{conversation_id:bound.conversation_id,agent_id:bound.agent_id,after_id:0,page});
        if (!current(bound) || serial!==readSequence || pageVersion!==pageEpoch || page!==requestedPage) return false;
        if (!data.ok || !data.complete || !Array.isArray(data.items) || data.items.length>25 || !Array.isArray(data.errors) || data.errors.length
          || !data.counts || !integer(data.counts.unread,true) || !integer(data.counts.attention,true)
          || !integer(data.latest_id,true) || typeof data.has_more!=='boolean' || data.page!==page || data.page_size!==25) {
          markUnavailable('Profile attention is incomplete. The last confirmed list and counts are shown. Retry to check again.'); return false;
        }
        const items=data.items.map(normalizeItem);
        if (items.some(item=>!item) || new Set(items.map(item=>item.attention_id)).size!==items.length
          || items.some(item=>{const old=rows.get(item.attention_id);return old && (sourceKey(old)!==sourceKey(item) || old.state_revision>item.state_revision || old.wake_generation>item.wake_generation);} )) {
          markUnavailable('Profile attention changed while loading. Retry to check its current state.');return false;
        }
        sourceObservation++;
        for (const item of items) rows.set(item.attention_id,item);
        snapshot={items,counts:{unread:data.counts.unread,attention:data.counts.attention},complete:true,known:true,has_more:data.has_more,page,page_size:25,error:'',loading:false};
        emit();
        if (chatMode && current(bound)) {
          await restoreSaved(bound,{acknowledge:present});
          if (present && idle(bound) && serial===readSequence && pageVersion===pageEpoch && page===requestedPage) {
            let delivered=0;
            for (const item of snapshot.items) {
              if (!idle(bound) || delivered>=5 || serial!==readSequence || pageVersion!==pageEpoch || page!==requestedPage) break;
              if (item.presentation_owner==='profile_handoff_pa3') {window.dispatchEvent(new Event('vp3:profile-handoff-refresh'));continue;}
              if (item.presentation_owner==='profile_speech_pa4a') {window.VP3ProfileSpeechPa4a?.refresh?.();continue;}
              if (item.eligible && !item.reason && item.source_status==='verified' && item.delivery_location!=='elsewhere') {
                await presentItem(item,bound); delivered++;
              }
            }
          }
        }
        return true;
      } catch (cause) {
        if (current(bound) && serial===readSequence) {const denied=[401,403,419].includes(cause.status);markUnavailable(denied?'Current Profile attention access could not be verified. Retry after restoring access.':cause.message,denied);}
        return false;
      } finally {if(current(bound)&&serial===readSequence){refreshing=false;snapshot.loading=false;emit();}}
    }
    function marker(element) {
      if (!element?.isConnected) return null;
      const d=element.dataset || {};
      const value={delivery_id:Number(d.profileAttentionDelivery),attention_id:Number(d.profileAttentionId),notification_id:Number(d.profileAttentionNotification),wake_generation:Number(d.profileAttentionWake),chat_message_id:Number(d.chatMessageId),chat_conversation_id:Number(d.chatConversationId)};
      return Object.values(value).every(v=>integer(v)) ? value : null;
    }
    function markerMatches(element,item,bound) {
      const m=marker(element);
      return current(bound) && m && ['delivery_id','attention_id','notification_id','wake_generation','chat_message_id','chat_conversation_id'].every(key=>m[key]===item[key])
        && item.delivery_location==='current' && item.chat_conversation_id===bound.conversation_id && item.chat_agent_id===bound.agent_id;
    }
    function visible(element) {
      if (!element?.isConnected || document.hidden || element.closest('[hidden]')) return false;
      const r=element.getBoundingClientRect(),height=window.innerHeight || document.documentElement.clientHeight,width=window.innerWidth || document.documentElement.clientWidth;
      const left=Math.max(0,r.left),right=Math.min(width,r.right),top=Math.max(0,r.top),bottom=Math.min(height,r.bottom);
      if (right-left<2 || bottom-top<2) return false;
      const hit=document.elementFromPoint((left+right)/2,(top+bottom)/2);
      return !!hit && (element===hit || element.contains(hit));
    }
    function rowHtml(item, card = false) {
      const status=[item.read?'Read':'Unread', item.presented?'Presented':'Not yet presented', {pending:'Needs attention',snoozed:'Snoozed',handled:'Handled',ignored:'Ignored'}[item.disposition]];
      if (item.snoozed_until_ms!==null && item.disposition==='snoozed') status.push('Until '+new Date(item.snoozed_until_ms).toLocaleString());
      const stale=!!snapshot.error, locked=pending.has(item.attention_id)||(snapshot.loading&&requestedPage!==snapshot.page), key=item.attention_id;
      const source=roles[item.source_role]+(item.source_label?' · '+item.source_label:'');
      const sourceNote=item.source_status==='historical_unverified'?'Historical source; current authorization could not be verified.':item.source_status==='unavailable'?'Source unavailable.':'';
      return `<article class="profile-attention-card${card?' in-chat':''}" data-profile-attention-row="${key}" data-profile-attention-revision="${item.state_revision}" data-profile-attention-generation="${item.wake_generation}">
        <small class="profile-attention-source">${esc(source)}</small><strong>${esc(item.title || 'Profile attention')}</strong>
        ${item.display_text?`<p class="profile-attention-text">${esc(item.display_text)}</p>`:''}<p class="profile-attention-state">${esc(status.join(' · '))}</p>
        ${sourceNote?`<p>${esc(sourceNote)}</p>`:''}${item.delivery_location==='elsewhere'?'<p>Already presented in another Chat. Your current Chat stays selected.</p>':''}
        ${itemErrors.has(key)?`<p class="profile-attention-error" role="alert">${esc(itemErrors.get(key))}</p>`:''}
        <div class="profile-attention-actions">${item.public_conversation_id>0 && item.source_status==='verified'?`<button type="button" data-profile-attention-action="open"${stale||locked?' disabled':''}>Open visitor conversation</button>`:''}
        ${Object.entries(operations).filter(([operation])=>item[permission[operation]]).map(([operation,label])=>`<button type="button" data-profile-attention-action="${operation}"${stale||locked?' disabled':''}>${locked?'Updating…':esc(label)}</button>`).join('')}</div></article>`;
    }
    function view() {
      const counts=snapshot.counts;
      const summary=snapshot.error ? snapshot.error : !snapshot.known ? 'Loading Profile attention…'
        : counts.unread===0 && counts.attention===0 && !snapshot.has_more ? 'You are caught up on Profile attention.' : `${counts.unread} unread · ${counts.attention} needing attention`;
      const paging=snapshot.known?`<nav class="profile-attention-pages" aria-label="Profile attention pages"><button type="button" data-profile-attention-action="previous"${snapshot.loading||snapshot.page===0?' disabled':''}>Previous</button><span>Page ${snapshot.page+1}${snapshot.loading?' · Loading…':''}</span><button type="button" data-profile-attention-action="next"${snapshot.loading||!snapshot.has_more||snapshot.page>=100000?' disabled':''}>Next</button></nav><p class="profile-attention-page-note">Up to 25 items per page. Items can move when their status changes.</p>`:'';
      return `<section class="profile-attention-section"><div class="chat-activity-section-head"><div><strong>Profile attention</strong><p role="status">${esc(summary)}</p></div><button type="button" data-profile-attention-action="refresh">${snapshot.error?'Retry':'Refresh'}</button></div>${snapshot.items.map(item=>rowHtml(item)).join('')}${paging}</section>`;
    }
    function renderSaved(element,item,message='') {
      if (!element?.isConnected) return;
      let host=element.querySelector('[data-profile-attention-card]');
      if (!host) {host=document.createElement('section');host.dataset.profileAttentionCard='';(element.querySelector('.message-body')||element).appendChild(host);}
      const placeholder=element.querySelector('.message-text');if(placeholder)placeholder.hidden=true;
      host.innerHTML=item ? rowHtml(item,true) : `<p class="profile-attention-error" role="status">${esc(message || 'Loading current Profile attention…')}</p><button type="button" data-profile-attention-action="refresh">Retry</button>`;
    }
    async function restoreSaved(bound = capture(),{acknowledge:allowAck=requestedPage===0}={}) {
      if (!current(bound) || !chatMode || canonical().isReady?.()!==true || canonical().isRestoring?.()) return;
      const restorePageEpoch=pageEpoch;
      const elements=Array.from(document.querySelectorAll('#chatThread [data-profile-attention-delivery]')).slice(-25);
      for (const element of elements) {
        if(restorePageEpoch!==pageEpoch)return;
        const saved=marker(element);if (!saved || saved.chat_conversation_id!==bound.conversation_id || !current(bound)) continue;
        const observedAtStart=sourceObservation;
        mounted.set(saved.delivery_id,element);
        let item=snapshot.items.find(row=>markerMatches(element,row,bound));
        try {
          if (!item) {
            const observed=sourceObservation;
            const data=await request('profile_attention_item',{delivery_id:saved.delivery_id,conversation_id:bound.conversation_id,agent_id:bound.agent_id});
            if (!current(bound) || !element.isConnected || observed!==sourceObservation || restorePageEpoch!==pageEpoch) continue;
            item=data.ok && data.complete ? normalizeItem(data.item) : null;
          }
          if (!item || !markerMatches(element,item,bound) || !merge(item)) throw error('This saved Profile attention could not be verified. Retry to refresh.');
          renderSaved(element,item);
          if (allowAck && idle(bound) && item.eligible && !item.presented && !item.reason && item.presentation_owner==='profile_attention_pa4b') await acknowledge(item,element,bound);
        } catch (cause) {if(current(bound)&&observedAtStart===sourceObservation) renderSaved(element,null,cause.message);}
      }
    }
    function operationInput(item,bound) {return {attention_id:item.attention_id,notification_id:item.notification_id,state_revision:item.state_revision,wake_generation:item.wake_generation,conversation_id:bound.conversation_id,agent_id:bound.agent_id};}
    async function write(operation,item,bound,extra={}) {
      const key=[scopeKey(bound),sourceKey(item),operation].join('|');
      const observed=sourceObservation;
      let attempt=attempts.get(key);
      if (!attempt) {attempt={body:{...operationInput(item,bound),...extra,request_id:token()},source:sourceKey(item)};attempts.set(key,attempt);}
      const action=operation==='present'?'profile_attention_present':operation==='ack'?'profile_attention_ack':'profile_attention_update';
      let data;
      try {data=await request(action,attempt.body,true);} catch(cause) {if(cause.status===409)attempts.delete(key);throw cause;}
      const next=data.ok && data.complete && typeof data.duplicate==='boolean' ? normalizeItem(data.item) : null;
      if (!next || sourceKey(next)!==attempt.source || next.state_revision<item.state_revision || next.wake_generation<item.wake_generation) throw error('The attention update could not be confirmed. Retry the same action to check its result.');
      attempts.delete(key);
      return {data,item:next,observed};
    }
    async function presentItem(item,bound) {
      if (!idle(bound) || !snapshot.complete || !sameItem(rows.get(item.attention_id),item)) return false;
      const observedAtStart=sourceObservation;
      try {
        const result=await write('present',item,bound);
        if (!idle(bound) || result.observed!==sourceObservation || !merge(result.item)) return false;
        const next=result.item;
        itemErrors.delete(item.attention_id);
        if (next.presentation_owner!=='profile_attention_pa4b' || next.delivery_location!=='current' || !next.eligible || next.reason || next.source_status!=='verified') {emit();return false;}
        const element=canonical().mountProfileAttention?.({contract:CONTRACT,item:next});
        if (!element || !markerMatches(element,next,bound)) throw error('Profile attention was saved, but its current Chat card could not be mounted. Retry to display it.');
        mounted.set(next.delivery_id,element);renderSaved(element,next);emit();
        await new Promise(resolve=>requestAnimationFrame(resolve));
        if (idle(bound)) await acknowledge(next,element,bound);
        return true;
      } catch (cause) {if(current(bound)&&observedAtStart===sourceObservation){if([401,403,419].includes(cause.status))markUnavailable('Current Profile attention access could not be verified.',true);else{itemErrors.set(item.attention_id,cause.message);emit();}}return false;}
    }
    async function acknowledge(item,element,bound) {
      if (!idle(bound) || !markerMatches(element,item,bound) || !visible(element) || item.presented || !sameItem(rows.get(item.attention_id),item)) return false;
      const observedAtStart=sourceObservation,key=[bound.epoch,item.delivery_id,item.state_revision].join(':');
      if(acknowledging.has(key))return false;acknowledging.add(key);
      try {
        const result=await write('ack',item,bound,{delivery_id:item.delivery_id,chat_message_id:item.chat_message_id,visible:true,tab_id:tabId,client_instance_id:clientId});
        if (!current(bound) || result.observed!==sourceObservation || !markerMatches(element,result.item,bound) || !merge(result.item)) return false;
        itemErrors.delete(item.attention_id);
        renderSaved(element,result.item);emit();return true;
      } catch (cause) {if(current(bound)&&observedAtStart===sourceObservation){if([401,403,419].includes(cause.status))markUnavailable('Current Profile attention access could not be verified.',true);else{itemErrors.set(item.attention_id,cause.message);renderSaved(element,item);emit();}}return false;}
      finally{acknowledging.delete(key);}
    }
    async function update(item,operation) {
      const bound=capture();if (!current(bound) || !Object.hasOwn(permission,operation) || !item[permission[operation]] || !sameItem(rows.get(item.attention_id),item) || pending.has(item.attention_id) || snapshot.error) return false;
      const observedAtStart=sourceObservation;
      pending.add(item.attention_id);itemErrors.delete(item.attention_id);readSequence++;emit();
      try {
        const result=await write(operation,item,bound,{operation,...(operation==='snooze'?{snooze_seconds:86400}:{})});
        if (!current(bound)) return false;
        if (result.observed!==sourceObservation || !merge(result.item)) throw error('The attention item changed. Refresh its current state.');
        itemErrors.delete(item.attention_id);
        options.onMutation?.(result.item);
        for(const element of mounted.values())if(markerMatches(element,result.item,bound))renderSaved(element,result.item);
        return true;
      } catch(cause) {
        if (current(bound) && observedAtStart===sourceObservation) {
          itemErrors.set(item.attention_id,cause.message);
          if(cause.status===409) for(const [key,attempt] of attempts)if(attempt.source===sourceKey(item))attempts.delete(key);
          if([401,403,419].includes(cause.status))markUnavailable('Current Profile attention access could not be verified.',true);
        }
        return false;
      } finally {
        pending.delete(item.attention_id);
        if(current(bound)){emit();await refresh({present:false});}
      }
    }
    function handleClick(event) {
      const button=event.target.closest?.('[data-profile-attention-action]');if(!button)return false;
      event.preventDefault();event.stopPropagation?.();if(button.disabled)return true;
      const operation=button.dataset.profileAttentionAction;
      if(operation==='next'||operation==='previous'){
        if(snapshot.known&&!snapshot.loading&&(operation==='next'?snapshot.has_more&&snapshot.page<100000:snapshot.page>0))void refresh({page:snapshot.page+(operation==='next'?1:-1),present:false});
        return true;
      }
      if(operation==='refresh'){void refresh();return true;}
      const node=button.closest('[data-profile-attention-row]'),item=rows.get(Number(node?.dataset.profileAttentionRow));
      if(!item || Number(node.dataset.profileAttentionRevision)!==item.state_revision || Number(node.dataset.profileAttentionGeneration)!==item.wake_generation){void refresh({present:false});return true;}
      if(operation==='open') {if(snapshot.complete && !snapshot.error && item.source_status==='verified' && item.public_conversation_id>0)options.onOpen?.(item.public_conversation_id,item);return true;}
      if(Object.hasOwn(permission,operation))void update(item,operation);
      return true;
    }
    const edited=()=>{draftRevision++;};
    const changed=()=>invalidate();
    const mountedMessage=event=>{if(chatMode && marker(event.detail?.element))void restoreSaved(capture());};
    if(chatMode){document.getElementById('chatInput')?.addEventListener('input',edited);window.addEventListener('stonefellow:chat-context-changing',changed);window.addEventListener('vp3:chat-canvas-view-changed',changed);window.addEventListener('vp3:profile-attention-message',mountedMessage);document.getElementById('chatThread')?.addEventListener('click',handleClick);}
    function dispose(){disposed=true;epoch++;readSequence++;if(chatMode){document.getElementById('chatInput')?.removeEventListener('input',edited);window.removeEventListener('stonefellow:chat-context-changing',changed);window.removeEventListener('vp3:chat-canvas-view-changed',changed);window.removeEventListener('vp3:profile-attention-message',mountedMessage);document.getElementById('chatThread')?.removeEventListener('click',handleClick);}}
    return Object.freeze({refresh,view,handleClick,getState,restoreSaved,dispose,isRefreshing:()=>refreshing});
  }
  window.VP3ProfileAttentionPa4b=Object.freeze({contract:CONTRACT,create,normalizeItem});
})();
