(() => {
  'use strict';
  if (window.VP3EmailWritingPreferencesV1) return;
  const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  const integer = (value,min=0) => Number.isSafeInteger(value) && value >= min;
  const ref = value => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value);
  const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
  const text = (value,max) => typeof value === 'string' && Array.from(value).length <= max && !/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/.test(value);
  const states = new Set(['preparing','dispatched','ready','no_style_change','failed','dismissed','publishing','accepted','conflict']);
  const categories = new Set(['tone','length','greeting','signoff','formatting','word_choice']);
  const reasons = Object.freeze({
    not_installed:'Writing preference suggestions are not available on this server yet.',
    generated_baseline_unavailable:'This draft does not have a verified Agent-written version to compare.',
    draft_unavailable:'This saved draft is no longer available for a writing preference.',
    source_changed:'The saved email changed. Review its current version before remembering a preference.',
    owner_edit_required:'Edit and save an Agent draft to suggest a writing preference.',
    authority_changed:'Access changed. Reopen this email before reviewing a writing preference.',
    soul_changed:'Your writing preferences changed elsewhere. Check the current writing style before continuing.',
    model_unavailable:'The writing model is unavailable. No preference was saved.',
    invalid_response:'The Agent could not produce a verified writing preference. No preference was saved.',
    unsafe_suggestion:'The proposed preference was not suitable to save. No preference was saved.',
    no_style_change:'No reusable writing preference was found in this edit. Your saved email is unchanged.',
    dismissed:'This suggestion was dismissed. No writing preference was saved.',
    preparation_interrupted:'Preparation was interrupted. This edit will not be generated again automatically.',
    dispatch_uncertain:'The suggestion request could not be confirmed. It will not be sent again for this edit.',
    publication_pending:'Checking whether this writing preference was saved…',
    publication_unavailable:'Saving could not be confirmed. You can retry saving the exact preference shown below.',
    publication_conflict:'Your writing preferences changed while saving. Check the current writing style before continuing.'
  });
  const actions = new Set(['preference_status','preference_prepare','preference_accept','preference_dismiss',
    'preferences_get','preferences_save','preferences_restore','preferences_reset']);
  function check(value,message='Writing preferences could not be verified. Reload the current version before continuing.') {
    if (!value) throw new Error(message);
  }
  function checkedStatus(raw,draft) {
    const value=raw?.preference;
    check(raw?.ok===true && object(value) && ref(value.draft_id) && (!draft || value.draft_id===draft.id)
      && typeof value.eligible==='boolean' && (value.reason===null || Object.hasOwn(reasons,value.reason))
      && (value.capture_id===null || ref(value.capture_id)) && hash(value.edit_revision)
      && integer(value.soul_version) && hash(value.soul_revision));
    if (draft) check(value.edit_revision===draft.edit_revision,'This saved email changed. Reload the draft before remembering a preference.');
    const p=value.proposal;
    check(p===null || object(p) && ref(p.id) && integer(p.revision,1) && states.has(p.state)
      && (p.category===null || categories.has(p.category)) && text(p.preference,600)
      && (p.reason===null || Object.hasOwn(reasons,p.reason)) && (p.accepted_version===null || integer(p.accepted_version,1)));
    check(!value.eligible || value.capture_id!==null && value.reason===null && p===null);
    return {...value,proposal:p ? {...p} : null};
  }
  function checkedPreferences(raw) {
    const value=raw?.preferences;
    check(raw?.ok===true && object(value) && text(value.text,24001) && integer(value.version) && hash(value.revision)
      && Array.isArray(value.history) && value.history.length<=100 && (value.next_cursor===null || integer(value.next_cursor)));
    const seen=new Set();
    for (const item of value.history) {
      check(object(item) && integer(item.version) && item.version<=value.version && !seen.has(item.version)
        && typeof item.current==='boolean' && item.current===(item.version===value.version));seen.add(item.version);
    }
    check(value.selected_version===undefined && value.selected_text===undefined
      || integer(value.selected_version) && value.selected_version<=value.version && text(value.selected_text,24001));
    return {...value,history:value.history.map(item=>({...item}))};
  }
  function preferenceText(value) {
    const trimmed=typeof value==='string' ? value.trim() : '';
    check(text(trimmed,600) && trimmed.length>0 && !/[\p{Cc}\p{Zl}\p{Zp}]/u.test(trimmed),'Use one paragraph of 1–600 characters for this writing preference.');
    return trimmed;
  }
  function createClient({endpoint,context,isCurrent=()=>true,onAccessChanged,fixedDraftId}={}) {
    let url;
    try {url=new URL(endpoint,location.href);} catch {throw new Error('Writing preferences are unavailable.');}
    check(url.origin===location.origin && !url.username && !url.password && object(context)
      && typeof context.owner_key==='string' && /^[1-9]\d{0,18}$/.test(context.owner_key)
      && integer(context.agent_id) && integer(context.conversation_id,1) && text(context.csrf_token,256) && context.csrf_token.length>0);
    check(fixedDraftId===undefined || ref(fixedDraftId));
    const scope={owner_key:context.owner_key,agent_id:context.agent_id,conversation_id:context.conversation_id,csrf_token:context.csrf_token};
    const life=new AbortController();let disposed=false;
    return {
      async request(action,fields={},options={}) {
        check(actions.has(action),'Choose a supported writing preference action.');
        if (disposed || !isCurrent()) throw new DOMException('The private view changed.','AbortError');
        if (fixedDraftId) check(fields.draft_id===undefined || fields.draft_id===fixedDraftId,'This preference belongs to another draft.');
        const controller=new AbortController();let timedOut=false;
        const abort=()=>controller.abort();
        for (const signal of [life.signal,options.signal].filter(Boolean)) {
          if (signal.aborted) abort();else signal.addEventListener('abort',abort,{once:true});
        }
        const timeout=setTimeout(()=>{timedOut=true;controller.abort();},action==='preference_prepare'?125000:25000);
        try {
          const response=await fetch(url.href,{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,
            headers:{'Content-Type':'application/json'},body:JSON.stringify({...fields,...scope,action,...(fixedDraftId?{draft_id:fixedDraftId}:{})})});
          const result=await response.json().catch(()=>null);
          if (disposed || !isCurrent() || controller.signal.aborted && !timedOut) throw new DOMException('The private view changed.','AbortError');
          if (timedOut) throw new Error('The request timed out. Check its current status before taking another action.');
          if (!response.ok || result?.ok!==true) {
            if ([401,403,419].includes(response.status)) onAccessChanged?.();
            const error=new Error(result?.message || result?.error || 'Writing preferences could not be saved. Your text is kept.');
            error.status=response.status;throw error;
          }
          return result;
        } catch(error) {
          if (timedOut) throw new Error('The request timed out. Check its current status before taking another action.');
          throw error;
        } finally {
          clearTimeout(timeout);
          for (const signal of [life.signal,options.signal].filter(Boolean)) signal.removeEventListener('abort',abort);
        }
      },
      dispose(){disposed=true;life.abort();}
    };
  }
  const node=(tag,value='',className='')=>{const result=document.createElement(tag);result.textContent=value;if(className)result.className=className;return result;};
  const button=(label,handler,className='')=>{const result=node('button',label,className);result.type='button';result.addEventListener('click',()=>void handler());return result;};
  const grow=input=>{input.style.height='auto';input.style.height=Math.max(100,input.scrollHeight)+'px';};
  const field=(label,input)=>{const result=node('label','','vp3-email-writing-field');result.append(node('span',label),input);return result;};
  function mount(options,management=false) {
    const {host,isCurrent=()=>true,onDirtyChange}=options;
    if (!host || typeof options.request!=='function') return null;
    const life=new AbortController();let disposed=false,draft=null,draftKey='',emailDirty=false,emailBusy=false,status=null;
    let statusSerial=0,statusController=null,poll=null,preparing=false,writing=false,closedOffer='',wantSuggestion=false;
    let proposalId='',proposalKey='',proposalSoulRevision='',proposalSoulVersion=0,suggestionSaved='',frozenPublication=false;
    let preferences=null,styleSaved='',conflict=null,managementSerial=0,pageCursor=null,pages=[],selectedVersion=null;
    let closePromise=null,closeResolve=null,lastDirty=false;
    const current=()=>!disposed && isCurrent() && host.isConnected;
    const request=(action,fields={},signal=life.signal)=>options.request(action,{...(draft?{draft_id:draft.id}:{}),...fields},{signal});
    const root=node('section','','vp3-email-writing');root.setAttribute('aria-label','Email writing preferences');host.append(root);
    const head=node('div','','vp3-email-writing-head');
    const remember=button('Remember this writing preference',()=>openSuggestion());remember.hidden=true;
    const openStyle=button('Writing preferences',()=>openManagement(),'vp3-email-writing-link');head.append(remember,openStyle);
    const note=node('p','','vp3-email-writing-status');note.setAttribute('role','status');note.setAttribute('aria-live','polite');
    const suggestion=node('section','','vp3-email-writing-panel');suggestion.hidden=true;
    const suggestionTitle=node('h3','Remember a writing preference');suggestionTitle.tabIndex=-1;
    const suggestionInput=node('textarea');suggestionInput.rows=3;suggestionInput.maxLength=1200;
    const count=node('small'),suggestionVersion=node('small');
    const suggestionField=field('Preference for future emails',suggestionInput);
    const accept=button('Save writing preference',()=>acceptPreference(),'vp3-email-writing-primary');
    const dismiss=button('Dismiss',()=>dismissPreference());
    const checkStatus=button('Check suggestion status',()=>refreshStatus());
    const suggestionActions=node('div','','vp3-email-writing-actions');suggestionActions.append(accept,dismiss,checkStatus);
    suggestion.append(suggestionTitle,node('p','This applies to future Agent email drafts across your account. It does not change this email.'),
      suggestionVersion,suggestionField,count,suggestionActions);
    const manager=node('section','','vp3-email-writing-panel');manager.hidden=true;
    const managerTitle=node('h3','Writing preferences');managerTitle.tabIndex=-1;
    const versionNote=node('small');const style=node('textarea');style.rows=6;style.maxLength=48002;
    const saveStyle=button('Save writing style',()=>saveManagement('preferences_save',{text:style.value}),'vp3-email-writing-primary');
    const reset=button('Reset to default',()=>confirmManagement('preferences_reset'));
    const refresh=button('Reload writing style',()=>reloadStyle());
    const closeStyle=button('Close preferences',async()=>{if(await requestClose()){manager.hidden=true;openStyle.focus();}});
    const styleActions=node('div','','vp3-email-writing-actions');styleActions.append(saveStyle,refresh,reset,closeStyle);
    const conflictBox=node('section','','vp3-email-writing-conflict');conflictBox.hidden=true;
    const history=node('details','','vp3-email-writing-history');history.append(node('summary','Writing preference history'));
    const versions=node('ul','','vp3-email-writing-versions');
    const historyActions=node('nav','','vp3-email-writing-actions');historyActions.setAttribute('aria-label','Writing preference history pages');
    const newer=button('Newer versions',()=>{const cursor=pages.pop();return loadManagement({cursor:cursor??null,page:true});});
    const older=button('Older versions',()=>{pages.push(pageCursor);return loadManagement({cursor:preferences.next_cursor,page:true});});
    historyActions.append(newer,older);
    const preview=node('section');preview.hidden=true;
    history.append(versions,historyActions,preview);
    const confirmBox=node('section','','vp3-email-writing-conflict');confirmBox.hidden=true;
    manager.append(managerTitle,node('p','Your account’s Email Specialist uses this style for future drafts. Edit or remove wording below, then save.'),
      versionNote,field('Email writing style',style),conflictBox,styleActions,history,confirmBox);
    const leave=node('section','','vp3-email-writing-leave');leave.hidden=true;leave.setAttribute('role','alert');
    root.append(head,note,suggestion,manager,leave);
    const suggestionDirty=()=>!suggestion.hidden && !frozenPublication && suggestionInput.value!==suggestionSaved;
    const styleDirty=()=>!manager.hidden && preferences!==null && style.value!==styleSaved;
    const dirty=()=>suggestionDirty() || styleDirty();
    function dirtyChanged(){const value=dirty();if(value!==lastDirty){lastDirty=value;onDirtyChange?.(value);}}
    function feedback(message,error=false){if(!current())return;note.textContent=message;note.dataset.error=String(error);}
    function failure(error){if(current()&&error.name!=='AbortError')feedback(error.message,true);}
    const canAccept=()=>status && status.edit_revision===draft?.edit_revision && proposalKey===draftKey && proposalId===status.proposal?.id
      && status.soul_revision===proposalSoulRevision && !emailDirty && !emailBusy && !writing && (status.proposal.state==='ready' && status.reason===null
        || status.proposal.state==='publishing' && status.proposal.reason==='publication_unavailable' && status.reason==='publication_unavailable');
    function controls(){
      if(!current())return;
      const p=status?.proposal,hasReady=p?.state==='ready'&&status.reason===null,hasPending=p?.state==='publishing';
      remember.hidden=management || !draft || closedOffer===draftKey || !(status?.eligible || hasReady || hasPending);
      remember.textContent=hasPending?'Review pending preference':hasReady?'Review writing preference':'Remember this writing preference';
      remember.disabled=emailDirty||emailBusy||preparing||writing;
      openStyle.disabled=writing;
      accept.disabled=!canAccept();accept.textContent=frozenPublication?'Retry saving preference':'Save writing preference';
      suggestionInput.readOnly=frozenPublication;suggestionInput.disabled=writing;
      suggestionField.hidden=count.hidden=['preparing','dispatched'].includes(p?.state);
      suggestionVersion.textContent=proposalSoulRevision ? 'Reviewed against writing style version '+proposalSoulVersion+'.' : '';
      dismiss.disabled=writing||!p||proposalKey!==draftKey||proposalId!==p.id||['publishing','accepted','dismissed'].includes(p.state);
      checkStatus.disabled=writing;checkStatus.hidden=!preparing&&!p;
      count.textContent=Array.from(suggestionInput.value).length+' of 600 characters · One paragraph';
      saveStyle.disabled=writing||preferences===null||Boolean(conflict)||!styleDirty();reset.disabled=writing||preferences===null||Boolean(conflict);
      refresh.disabled=writing;closeStyle.disabled=writing;style.disabled=writing||preferences===null;
      newer.hidden=pages.length===0;older.hidden=preferences?.next_cursor===null||!preferences;newer.disabled=older.disabled=writing;
      root.setAttribute('aria-busy',String(writing||preparing));dirtyChanged();
    }
    suggestionInput.addEventListener('input',()=>{grow(suggestionInput);controls();});
    style.addEventListener('input',()=>{grow(style);controls();});
    window.addEventListener('resize',()=>{if(current()){grow(suggestionInput);grow(style);}},{signal:life.signal});
    function showSuggestion(p,focus=false,review=false){
      if(!p)return;
      const preserve=proposalId===p.id&&suggestionDirty();
      if(review||proposalId!==p.id||!proposalSoulRevision){proposalSoulRevision=status.soul_revision;proposalSoulVersion=status.soul_version;}
      proposalId=p.id;proposalKey=draftKey;
      frozenPublication=p.state==='publishing';
      if(!preserve||frozenPublication){suggestionSaved=p.preference;suggestionInput.value=p.preference;}
      suggestion.hidden=false;manager.hidden=true;grow(suggestionInput);controls();
      if(focus)(suggestionField.hidden?suggestionTitle:suggestionInput).focus({preventScroll:true});
    }
    function statusMessage(value){
      const p=value?.proposal;
      if(p?.state==='accepted'&&p.accepted_version!==value.soul_version)return 'This preference was saved in version '+p.accepted_version+'. Current writing preferences are version '+value.soul_version+'.';
      if(value?.reason && !['owner_edit_required','generated_baseline_unavailable'].includes(value.reason))return reasons[value.reason];
      if(p?.reason)return reasons[p.reason];
      return ({preparing:'Preparing a writing preference from your saved edit…',dispatched:'The Agent is comparing your saved edit…',
        no_style_change:reasons.no_style_change,failed:'A writing preference could not be prepared. No preference was saved.',
        dismissed:reasons.dismissed,publishing:reasons.publication_pending,
        accepted:'Writing preference saved for future emails. This email is unchanged.',conflict:reasons.publication_conflict})[p?.state]||'';
    }
    function applyStatus(value){
      const old=status?.proposal,p=value.proposal;
      if(old&&p&&old.id===p.id&&p.revision<old.revision)return;
      const stalePreferenceEdit=suggestionDirty()&&proposalKey!==draftKey;
      status=value;feedback(statusMessage(value));
      if(!stalePreferenceEdit && manager.hidden && p && (['preparing','dispatched'].includes(p.state) || p.state==='ready'&&value.reason===null || p.state==='publishing') && wantSuggestion){
        const focus=suggestion.hidden;showSuggestion(p,focus);
      }
      if(!suggestion.hidden&&!['accepted','dismissed'].includes(p?.state)&&proposalSoulRevision&&value.soul_revision!==proposalSoulRevision)
        feedback('Writing preferences changed elsewhere. Review the current writing style, then reopen this suggestion before saving.',true);
      if(stalePreferenceEdit)feedback('The saved email changed. Your preference text is kept, but this suggestion cannot be saved.',true);
      if(!stalePreferenceEdit&&(p?.state==='accepted'||p?.state==='dismissed')){
        suggestion.hidden=true;suggestionSaved=suggestionInput.value;frozenPublication=false;wantSuggestion=false;
      }
      controls();schedulePoll();
    }
    function schedulePoll(){
      clearTimeout(poll);poll=null;
      if(!current()||!draft||emailDirty)return;
      if(preparing||['preparing','dispatched','publishing'].includes(status?.proposal?.state)&&status?.proposal?.reason!=='publication_unavailable')
        poll=setTimeout(()=>void refreshStatus(),2500);
    }
    async function refreshStatus(){
      if(!current()||!draft||emailDirty)return;
      const key=draftKey,exact={id:draft.id,edit_revision:draft.edit_revision},serial=++statusSerial;
      statusController?.abort();const controller=statusController=new AbortController();
      const abort=()=>controller.abort();life.signal.addEventListener('abort',abort,{once:true});
      try{
        const result=await request('preference_status',{draft_id:exact.id},controller.signal);
        if(current()&&key===draftKey&&serial===statusSerial)applyStatus(checkedStatus(result,exact));
      }catch(error){if(current()&&key===draftKey&&serial===statusSerial){status=null;failure(error);controls();}}
      finally{life.signal.removeEventListener('abort',abort);if(statusController===controller)statusController=null;}
    }
    async function openSuggestion(){
      if(!current()||emailDirty||emailBusy||writing||preparing)return;
      if(!manager.hidden && !await requestClose())return;
      if(!suggestion.hidden && proposalKey!==draftKey && !await requestClose())return;
      if(proposalSoulRevision && proposalSoulRevision!==status?.soul_revision && preferences?.revision!==status?.soul_revision){
        await openManagement();feedback('Review the current writing style, then reopen this suggestion before saving.',true);return;
      }
      manager.hidden=true;wantSuggestion=true;
      if(status?.proposal?.state==='ready'&&status.reason===null){showSuggestion(status.proposal,true,true);return;}
      if(status?.proposal?.state==='publishing'){showSuggestion(status.proposal,true,true);return;}
      if(!status?.eligible)return;
      const key=draftKey,exact={id:draft.id,edit_revision:draft.edit_revision},capture=status.capture_id;
      preparing=true;feedback('Preparing a writing preference from your saved edit…');controls();schedulePoll();
      try{await request('preference_prepare',{draft_id:exact.id,capture_id:capture,edit_revision:exact.edit_revision});}
      catch(error){if(current()&&key===draftKey)failure(error);}
      finally{preparing=false;if(current()&&key===draftKey){await refreshStatus();controls();}}
    }
    async function acceptPreference(){
      if(!current()||!canAccept())return;
      let preference;
      try{preference=frozenPublication?status.proposal.preference:preferenceText(suggestionInput.value);}catch(error){failure(error);suggestionInput.focus();return;}
      const key=draftKey,exact={id:draft.id,edit_revision:draft.edit_revision},p=status.proposal;
      writing=true;controls();feedback(frozenPublication?'Checking the exact writing preference save…':'Saving this writing preference…');
      try{
        const result=await request('preference_accept',{draft_id:exact.id,proposal_id:p.id,proposal_revision:p.revision,soul_revision:proposalSoulRevision,preference});
        if(current()&&key===draftKey){suggestionSaved=preference;suggestionInput.value=preference;applyStatus(checkedStatus(result,exact));window.dispatchEvent(new Event('vp3:email-writing-preferences-changed'));}
      }catch(error){if(current()&&key===draftKey){failure(error);await refreshStatus();}}
      finally{writing=false;controls();}
    }
    async function dismissPreference(){
      if(!current()||writing||!status?.proposal)return;
      const key=draftKey,exact={id:draft.id,edit_revision:draft.edit_revision},p=status.proposal;
      writing=true;controls();
      try{
        const result=await request('preference_dismiss',{draft_id:exact.id,proposal_id:p.id,proposal_revision:p.revision});
        if(current()&&key===draftKey){wantSuggestion=false;suggestionSaved=suggestionInput.value;applyStatus(checkedStatus(result,exact));}
      }catch(error){if(current()&&key===draftKey)failure(error);}
      finally{writing=false;controls();}
    }
    function renderHistory(){
      versions.replaceChildren();
      for(const item of preferences.history){
        const row=node('li');row.append(button('Version '+item.version,()=>loadManagement({version:item.version,cursor:pageCursor,preview:true})),
          node('span',item.current?'Current version':'Saved version','vp3-email-writing-version-meta'));versions.append(row);
      }
      if(!preferences.history.length)versions.append(node('li','No earlier saved versions.'));
      controls();
    }
    function applyPreferences(value,{replace=false,preview:showPreview=false}={}){
      if(preferences&&value.version<preferences.version)return;
      const changed=preferences&&value.revision!==preferences.revision;
      if(changed&&styleDirty()&&!replace){
        conflict=value;conflictBox.hidden=false;conflictBox.replaceChildren(node('p','Writing preferences changed elsewhere. Your unsaved text is kept.'));
        conflictBox.append(button('Load current writing style',()=>{applyPreferences(conflict,{replace:true});feedback('Current writing style loaded.');}));
      }else if(replace||!preferences||!styleDirty()){
        styleSaved=value.text;style.value=value.text;conflict=null;conflictBox.hidden=true;grow(style);
      }
      preferences=value;versionNote.textContent='Current version '+value.version;renderHistory();
      if(showPreview&&value.selected_version!==undefined){
        selectedVersion=value.selected_version;preview.hidden=false;preview.replaceChildren(node('h4','Version '+selectedVersion),node('p',value.selected_text,'vp3-email-writing-preview'));
        if(selectedVersion!==value.version)preview.append(button('Restore this version',()=>confirmManagement('preferences_restore',selectedVersion)));
      }
      controls();
    }
    async function loadManagement({cursor=null,version,preview:showPreview=false,page=false,replace=false}={}){
      if(!current()||writing)return;
      const serial=++managementSerial;feedback('Loading writing preferences…');
      try{
        const result=await request('preferences_get',{...(cursor!==null?{cursor}:{}),...(version!==undefined?{version}:{})});
        if(!current()||serial!==managementSerial)return;
        pageCursor=cursor;applyPreferences(checkedPreferences(result),{replace,preview:showPreview});
        if(page){preview.hidden=true;selectedVersion=null;}feedback('');
      }catch(error){if(serial===managementSerial)failure(error);}
    }
    async function openManagement(){
      if(!current()||writing)return;
      if(!suggestion.hidden&&!await requestClose())return;
      wantSuggestion=false;suggestion.hidden=true;manager.hidden=false;grow(style);managerTitle.focus({preventScroll:true});
      await loadManagement();
    }
    async function reloadStyle(){
      if(!await requestClose())return;
      pages=[];pageCursor=null;preview.hidden=true;selectedVersion=null;await loadManagement({replace:true});
    }
    function confirmManagement(action,version){
      if(!current()||writing||!preferences||conflict)return;
      if(styleDirty()){feedback('Save or discard your writing style edits before restoring or resetting.',true);style.focus();return;}
      confirmBox.hidden=false;confirmBox.replaceChildren(node('p',action==='preferences_restore'
        ? 'Restore version '+version+' for future Agent emails? The current email stays unchanged.'
        : 'Reset the Email Specialist to its default writing style? Saved version history will remain available.'));
      const row=node('div','','vp3-email-writing-actions');row.append(button(action==='preferences_restore'?'Confirm restore':'Confirm reset',()=>saveManagement(action,version===undefined?{}:{version}),'vp3-email-writing-primary'),
        button('Cancel',()=>{confirmBox.hidden=true;}));confirmBox.append(row);row.firstElementChild.focus();
    }
    async function saveManagement(action,fields){
      if(!current()||writing||!preferences||conflict)return;
      if(action==='preferences_save'&&(!text(fields.text.trim(),24000)||Array.from(fields.text.trim()).length<20)){
        feedback('Enter between 20 and 24,000 characters for the email writing style.',true);style.focus();return;
      }
      const revision=preferences.revision;writing=true;managementSerial++;controls();feedback('Saving writing preferences…');
      try{
        const result=await request(action,{soul_revision:revision,...fields});
        if(current()){
          pages=[];pageCursor=null;preview.hidden=true;confirmBox.hidden=true;applyPreferences(checkedPreferences(result),{replace:true});
          feedback('Writing preferences saved for future emails. This email is unchanged.');window.dispatchEvent(new Event('vp3:email-writing-preferences-changed'));
          if(draft)void refreshStatus();
        }
      }catch(error){if(current()){failure(error);if(error.status===409){writing=false;await loadManagement();}}}
      finally{writing=false;controls();}
    }
    function requestClose(){
      if(!current())return Promise.resolve(true);
      if(writing){feedback('Wait for the writing preference action to finish before leaving.');return Promise.resolve(false);}
      if(!dirty())return Promise.resolve(true);
      if(closePromise)return closePromise;
      closePromise=new Promise(resolve=>{closeResolve=resolve;});
      const finish=allowed=>{
        if(!closeResolve)return;
        const resolve=closeResolve;closeResolve=null;closePromise=null;leave.hidden=true;
        if(allowed){suggestionInput.value=suggestionSaved;style.value=styleSaved;grow(suggestionInput);grow(style);controls();}
        else (!manager.hidden?style:suggestionInput).focus();resolve(allowed);
      };
      leave.hidden=false;leave.replaceChildren(node('p','Keep editing your writing preference, or discard these unsaved preference edits? Your email is unchanged.'));
      const row=node('div','','vp3-email-writing-actions');row.append(button('Keep editing preference',()=>finish(false)),button('Discard preference edits',()=>finish(true)));leave.append(row);row.firstElementChild.focus();
      return closePromise;
    }
    function updateDraft(value,{dirty:changed=false,busy=false}={}){
      if(!current())return;
      emailDirty=changed;emailBusy=busy;
      const next=value&&ref(value.id)&&hash(value.edit_revision)?{id:value.id,edit_revision:value.edit_revision}:null;
      const key=next?next.id+':'+next.edit_revision:'';
      const changedKey=key!==draftKey;draft=next;
      if(changedKey){
        draftKey=key;statusSerial++;statusController?.abort();clearTimeout(poll);status=null;closedOffer='';
        if(!suggestionDirty()){suggestion.hidden=true;proposalId='';proposalKey='';wantSuggestion=false;}
        else feedback('The saved email changed. Your preference text is kept, but this suggestion cannot be saved.',true);
        if(next&&!emailDirty)void refreshStatus();
      }
      if(emailDirty){clearTimeout(poll);poll=null;}
      controls();
    }
    if(management){head.hidden=true;manager.hidden=false;closeStyle.hidden=true;void loadManagement();}
    controls();
    return {root,updateDraft,open:openManagement,requestClose,isDirty:dirty,isBusy:()=>writing,
      refresh:()=>management?loadManagement():refreshStatus(),
      dispose(){disposed=true;life.abort();statusController?.abort();clearTimeout(poll);closeResolve?.(false);closeResolve=null;root.remove();}
    };
  }
  window.VP3EmailWritingPreferencesContractV1=Object.freeze({status:checkedStatus,preferences:checkedPreferences,preferenceText,reasons,states:[...states]});
  window.VP3EmailWritingPreferencesV1=Object.freeze({createClient,mountComposer:options=>mount(options),mountSettings:options=>mount(options,true)});
})();
