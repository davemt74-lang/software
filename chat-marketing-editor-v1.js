(() => {
  'use strict';
  if(window.VP3MarketingEditorV1)return;
  const el=(tag,text='',className='')=>{
    const node=document.createElement(tag);
    node.textContent=text;if(className)node.className=className;
    return node;
  };
  function button(text,action) {
    const b=el('button',text);b.type='button';b.addEventListener('click',action);return b;
  }
  const positive=value=>Number.isSafeInteger(Number(value))&&Number(value)>0&&/^[1-9]\d*$/.test(String(value))?Number(value):0;
  const nonce=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),v=>v.toString(16).padStart(2,'0')).join('');
  function mount(host,{current,request,onClose,onSaved},campaignId=0,briefPrefill=null) {
    if(!host||typeof current!=='function'||typeof request!=='function'||!current())return null;
    // Only a source-revision-bound owner-selected brief may initialize NEW
    // draft fields. Existing saved drafts are never overwritten by this hint.
    const textSafe=(v,max,multiline=false)=>typeof v==='string'&&v.trim()&&v.length<=max
      &&!(multiline?/[<>\x00-\x08\x0b-\x1f\x7f]/:/[<>\x00-\x1f\x7f]/).test(v);
    const initialBrief=!positive(campaignId)&&briefPrefill
      &&/^marketing:(?:campaign-outcome|product-plan):[1-9]\d{0,15}$/.test(briefPrefill.source_ref||'')
      &&/^[a-f0-9]{64}$/.test(briefPrefill.source_revision||'')
      &&textSafe(briefPrefill.title,190)&&textSafe(briefPrefill.objective,500)
      &&textSafe(briefPrefill.description,4000,true)
      ?{title:briefPrefill.title,objective:briefPrefill.objective,
        description:briefPrefill.description,source:briefPrefill.source_ref}:null;
    const editor=el('section','','vp3-marketing-inline-editor');
    editor.dataset.marketingEditor='';
    const title=el('h3',campaignId?'Edit private Campaign draft':'Create a private Campaign');
    const help=el('p','Draft only. Nothing is published, messaged or issued from this editor. Publication stays in Campaigns & Rewards.','vp3-marketing-workspace-muted');
    if(initialBrief)help.append(document.createTextNode(
      ' Suggested text is drawn from your chosen Marketing opportunity. Confirm Merchant, location, type, reward and every field before saving.'));
    const message=el('p','','vp3-marketing-editor-status');message.setAttribute('role','status');message.setAttribute('aria-live','polite');
    const toolbar=el('div','','vp3-marketing-workspace-card-actions');
    const back=button('Back to campaigns',()=>onClose());toolbar.append(back);
    const form=el('form','','vp3-marketing-editor-form');
    form.setAttribute('novalidate','');
    const fields=el('div','','vp3-marketing-editor-fields');
    const labels={},inputs={};
    function inputField(key,text,type='text',limit=190) {
      const label=el('label');
      label.append(el('span',text));
      const input=type==='textarea'?el('textarea'):el('input');
      if(type!=='textarea')input.type=type;
      input.name=key;input.maxLength=limit;
      if(type==='textarea')input.rows=3;
      label.append(input);fields.append(label);
      labels[key]=label;inputs[key]=input;return input;
    }
    const merchantLabel=el('label');merchantLabel.append(el('span','Merchant'));
    const merchant=el('select');merchantLabel.append(merchant);fields.append(merchantLabel);
    const typeLabel=el('label');typeLabel.append(el('span','Campaign Type'));
    const type=el('select');typeLabel.append(type);fields.append(typeLabel);
    const titleInput=inputField('title','Campaign name', 'text',190);titleInput.required=true;
    const slugInput=inputField('slug','Public URL slug (reserved until publication)','text',120);
    inputField('subtitle','Subtitle','text',500);
    inputField('cta_label','Call to action','text',80);
    inputField('objective','Marketing objective','text',500);
    inputField('description','Description','textarea',4000);
    inputField('terms','Campaign terms','textarea',6000);
    const dates=el('div','','vp3-marketing-editor-dates');
    for(const [key,labelText] of [['starts_at','Starts (UTC)'],['ends_at','Ends (UTC)']]){
      const label=el('label');label.append(el('span',labelText));
      const input=el('input');input.type='datetime-local';input.name=key;label.append(input);
      dates.append(label);inputs[key]=input;labels[key]=label;
    }
    fields.append(dates);
    const placeLabel=el('label');placeLabel.append(el('span','Merchant Location'));
    const place=el('select');placeLabel.append(place);fields.append(placeLabel);
    const rewardPanel=el('fieldset','','vp3-marketing-editor-rewards');
    rewardPanel.append(el('legend','Campaign Rewards'));
    const rewardHelp=el('p','Choose only active Reward Products. Rewards are not issued by saving a draft.','vp3-marketing-workspace-muted');
    const rewards=el('div','','vp3-marketing-editor-reward-choices');rewardPanel.append(rewardHelp,rewards);
    const previewPanel=el('section','','vp3-marketing-editor-preview');
    previewPanel.hidden=true;
    const previewHeading=el('h4','Review private draft before saving');
    const reviewCopy=el('div','','vp3-marketing-editor-summary');
    const save=button('Confirm & save private draft',()=>void commit());
    const cancelPreview=button('Edit draft',()=>invalidate(false));
    const confirmation=el('div','','vp3-marketing-workspace-card-actions');
    confirmation.append(save,cancelPreview);
    previewPanel.append(previewHeading,reviewCopy,confirmation);
    const actions=el('div','','vp3-marketing-workspace-card-actions');
    const preview=button('Review draft',()=>void review());actions.append(preview);
    form.append(fields,rewardPanel,actions,previewPanel);
    editor.append(toolbar,title,help,message,form);
    host.replaceChildren(editor);
    let closed=false,serial=0,selectedMerchant=0,revision='new',savedCampaign=positive(campaignId),
      catalog=null,approval=null,requestKey=nonce(),busy=false;
    const live=()=>!closed&&editor.isConnected&&current();
    const say=text=>{if(live())message.textContent=text;};
    function clearFields() {
      for(const input of Object.values(inputs))input.value='';
      place.replaceChildren();rewards.replaceChildren();type.replaceChildren();
      title.textContent='Create a private Campaign';
    }
    function invalidate(fresh=true) {
      approval=null;
      if(fresh)requestKey=nonce();
      previewPanel.hidden=true;
    }
    form.addEventListener('submit',event=>{event.preventDefault();void review();});
    form.addEventListener('input',()=>invalidate());
    form.addEventListener('change',()=>invalidate());
    function busyState(value) {
      busy=value;
      for(const ctl of form.querySelectorAll('button,input,textarea,select'))ctl.disabled=value;
      back.disabled=value;
    }
    const option=(select,name,value)=>{
      const opt=el('option',name);opt.value=String(value);select.append(opt);
    };
    function setCatalog(data,reset=true) {
      if(!data||!Array.isArray(data.merchants)||!Array.isArray(data.types)
        ||!Array.isArray(data.rewards)||!Array.isArray(data.locations))throw Error('The Campaign catalog could not be verified.');
      catalog=data;selectedMerchant=positive(data.merchant_id);
      if(reset)merchant.replaceChildren();
      if(reset)for(const row of data.merchants){
        if(!positive(row?.id)||typeof row.name!=='string')continue;
        option(merchant,row.name.slice(0,140),row.id);
      }
      merchant.value=String(selectedMerchant);
      type.replaceChildren();
      for(const row of data.types){
        if(typeof row.key!=='string'||typeof row.label!=='string')continue;
        option(type,row.label.slice(0,100),row.key);
      }
      place.replaceChildren();option(place,'All locations',0);
      for(const row of data.locations)if(positive(row?.id)&&typeof row.name==='string')option(place,row.name.slice(0,140),row.id);
      rewards.replaceChildren();
      for(const row of data.rewards){
        if(!positive(row?.id)||typeof row.name!=='string')continue;
        const label=el('label','','vp3-marketing-editor-reward');
        const checkbox=el('input');checkbox.type='checkbox';checkbox.value=String(row.id);
        label.append(checkbox,el('span',row.name.slice(0,140)));rewards.append(label);
      }
      if(!rewards.childElementCount)rewards.append(el('p','No active Rewards in this Merchant. You can save a draft, then create or activate a Reward before publishing.','vp3-marketing-workspace-muted'));
      say(selectedMerchant?'Select campaign details and review a private draft.':'Create a Merchant in Campaigns & Rewards before starting a draft.');
      preview.disabled=!selectedMerchant||!data.types.length;
    }
    async function load(merchantId=0,existingId=0) {
      if(!live())return;
      const seq=++serial;
      busyState(true);invalidate();
      say(existingId?'Opening the latest saved Campaign draft…':'Loading your authorized Merchants and Campaign Types…');
      const result=await request(existingId?'marketing_editor_open':'marketing_editor_options',
        existingId?{campaign_id:existingId}:{merchant_id:merchantId});
      if(!live()||seq!==serial)return;
      busyState(false);
      if(!result){say('The editor is unavailable. Choose Back to campaigns or retry.');return;}
      try {
        setCatalog(result.catalog);
        if(existingId) {
          const draft=result.draft;
          if(!draft||draft.id!==existingId||draft.status!=='draft'||draft.merchant_id!==selectedMerchant
            ||typeof draft.revision!=='string'||!/^[a-f0-9]{64}$/.test(draft.revision)) {
            throw Error('The saved Campaign draft could not be verified.');
          }
          revision=draft.revision;savedCampaign=existingId;title.textContent='Edit private Campaign draft';
          for(const key of Object.keys(inputs)) {
            if(key==='starts_at'||key==='ends_at')inputs[key].value=String(draft.fields?.[key]||'').replace(' ','T').slice(0,16);
            else inputs[key].value=String(draft.fields?.[key]||'');
          }
          type.value=String(draft.fields?.campaign_type||'');
          place.value=String(draft.fields?.location_id||0);
          const chosen=new Set(Array.isArray(draft.fields?.reward_ids)?draft.fields.reward_ids:[]);
          for(const check of rewards.querySelectorAll('input[type="checkbox"]'))check.checked=chosen.has(Number(check.value));
          merchant.disabled=true;
        }else {
          clearInputValues();
          revision='new';savedCampaign=0;merchant.disabled=false;
          type.value=dataFirstType();
          if(initialBrief){
            inputs.title.value=initialBrief.title;
            inputs.objective.value=initialBrief.objective;
            inputs.description.value=initialBrief.description;
            say('Private proposal loaded from '+initialBrief.source+'. Choose Merchant details and review before saving.');
          }
        }
        invalidate();requestKey=nonce();
      }catch(error){say(error.message);}
    }
    function clearInputValues() {
      for(const input of Object.values(inputs))input.value='';
      place.value='0';
      for(const input of rewards.querySelectorAll('input[type="checkbox"]'))input.checked=false;
      title.textContent='Create a private Campaign';
    }
    const dataFirstType=()=>catalog?.types?.[0]?.key||'';
    merchant.addEventListener('change',()=>{
      if(savedCampaign||busy)return;
      const id=positive(merchant.value);
      clearInputValues();revision='new';
      void load(id);
    });
    function values() {
      const chosen=[...rewards.querySelectorAll('input[type="checkbox"]:checked')].map(el=>positive(el.value));
      return {
        title:inputs.title.value.trim(),campaign_type:type.value,
        slug:inputs.slug.value.trim(),subtitle:inputs.subtitle.value.trim(),cta_label:inputs.cta_label.value.trim(),
        objective:inputs.objective.value.trim(),description:inputs.description.value.trim(),terms:inputs.terms.value.trim(),
        starts_at:inputs.starts_at.value,ends_at:inputs.ends_at.value,
        location_id:Number(place.value)||0,reward_ids:chosen
      };
    }
    function parameters(){
      return {merchant_id:selectedMerchant,campaign_id:savedCampaign,revision,request_key:requestKey,fields:values()};
    }
    async function review() {
      if(!live()||busy||!selectedMerchant)return;
      const fields=values();
      if(!fields.title||!fields.campaign_type){say('Add a Campaign name and type before reviewing.');return;}
      const params=parameters();const seq=++serial;
      busyState(true);say('Checking the exact private draft and Merchant permissions…');
      const data=await request('marketing_editor_preview',params);
      if(!live()||seq!==serial)return;
      busyState(false);
      if(!data||!data.review_token||data.preview?.status!=='draft'||data.preview?.will_publish!==false){
        say('Draft review could not be verified. Check the form and try again.');return;
      }
      approval={token:data.review_token,key:requestKey,values:JSON.stringify(params)};
      reviewCopy.replaceChildren();
      const item=data.preview;
      for(const [label,value] of [
        ['Campaign',item.title],['Type',item.campaign_type],['Reserved URL',item.slug],
        ['Description',item.description],['Objective',item.objective],
        ['Subtitle',item.subtitle],['Call to action',item.cta_label],
        ['Terms',item.terms],['Merchant Location',item.location],
        ['Active Reward selections',Array.isArray(item.reward_names)?item.reward_names.join(', '):String(item.reward_count)],
        ['Starts (UTC)',item.starts_at||'Unscheduled'],['Ends (UTC)',item.ends_at||'Unscheduled'],
        ['Publication','Not published']
      ]){
        const line=el('p');line.append(el('strong',label+': '),document.createTextNode(String(value||'—').slice(0,4000)));
        reviewCopy.append(line);
      }
      previewPanel.hidden=false;
      say('Review these details. Confirming saves a private draft only.');
      previewPanel.scrollIntoView({block:'nearest',behavior:'smooth'});
    }
    async function commit(){
      if(!live()||busy||!approval||approval.key!==requestKey||approval.values!==JSON.stringify(parameters())){
        say('The draft changed. Review it again before saving.');invalidate(false);return;
      }
      const seq=++serial;const params=parameters();const token=approval.token;
      busyState(true);say('Saving the reviewed private Campaign draft…');
      const data=await request('marketing_editor_save',{...params,confirmed:true,review_token:token});
      if(!live()||seq!==serial)return;
      busyState(false);
      if(!data||data.saved!==true||data.published!==false||!positive(data.draft?.id)){
        say('The save could not be verified. Your draft is unchanged in this editor.');return;
      }
      approval=null;
      say('Private Campaign draft saved. Nothing published or sent.');
      onSaved(data.draft);
    }
    void load(0,positive(campaignId));
    return {dispose(){closed=true;serial++;editor.remove();}};
  }
  window.VP3MarketingEditorV1=Object.freeze({mount});
})();
