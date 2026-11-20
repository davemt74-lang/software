(() => {
  'use strict';
  if(window.VP3MarketingActivationV1)return;
  const elem=(tag,text='',klass='')=>{
    const el=document.createElement(tag);el.textContent=text;
    if(klass)el.className=klass;return el;
  };
  const button=(text,fn)=>{
    const b=elem('button',text);b.type='button';b.addEventListener('click',fn);return b;
  };
  const revision=/^[a-f0-9]{64}$/;
  const token=/^[0-9]{1,12}\.[a-f0-9]{64}$/;
  const nonce=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
  const states=new Set(['draft','scheduled','paused']);
  const readinessStates=new Set(['ready','limited','needs_setup','unavailable']);
  const visibilityStates=new Set(['private','unlisted','profile_public','unavailable']);
  function normalize(raw,id){
    if(!raw||raw.id!==id||!Number.isSafeInteger(raw.merchant_id)||raw.merchant_id<1
      ||!states.has(raw.status)||!Number.isSafeInteger(raw.version)||raw.version<0
      ||typeof raw.name!=='string'||raw.name.length>160
      ||typeof raw.type!=='string'||raw.type.length>90
      ||!readinessStates.has(raw.readiness)||!visibilityStates.has(raw.landing_visibility)
      ||!revision.test(String(raw.revision))
      ||!Array.isArray(raw.checks)||raw.checks.length>4
      ||!Array.isArray(raw.blockers)||raw.blockers.length>6
      ||typeof raw.reward_count!=='number'||!Number.isSafeInteger(raw.reward_count)||raw.reward_count<0
      ||typeof raw.can_activate!=='boolean'
      ||typeof raw.landing_published_on_activation!=='boolean'
      ||raw.profile_publication_is_separate!==true
      ||raw.may_enable_configured_automations!==true
      ||raw.will_send_messages_directly!==false
      ||raw.will_issue_rewards_directly!==false)return null;
    const allowedNames=new Set(['location','reward','claim_code','audience']);
    const checks=[];
    for(const check of raw.checks){
      if(!check||!allowedNames.has(check.name)||!['ready','limited','missing'].includes(check.state)
        ||typeof check.detail!=='string'||check.detail.length>260)return null;
      checks.push(check);
    }
    for(const line of raw.blockers)if(typeof line!=='string'||line.length>300)return null;
    if(raw.can_activate&&(raw.readiness!=='ready'||raw.blockers.length>0))return null;
    return {
      ...raw,checks,blockers:[...raw.blockers],
      name:raw.name.slice(0,160),type:raw.type.slice(0,90)
    };
  }
  function mount(host,{current,request,onClose,onActivated},campaignId){
    if(!host||!current?.()||typeof request!=='function'||!Number.isSafeInteger(campaignId)||campaignId<1)return null;
    const root=elem('section','','vp3-marketing-activation');
    root.dataset.marketingActivation='';
    const header=elem('header','','vp3-marketing-activation-header');
    header.append(elem('h3','Campaign Activation Review'),button('Back to campaigns',()=>onClose?.()));
    const intro=elem('p','Review current Campaign readiness and publication effects before approving activation. No campaign is activated just by opening this review.','vp3-marketing-workspace-muted');
    const status=elem('p','','vp3-marketing-activation-status');
    status.setAttribute('role','status');status.setAttribute('aria-live','polite');
    const details=elem('div','','vp3-marketing-activation-details');
    const actions=elem('div','','vp3-marketing-workspace-card-actions');
    const refresh=button('Refresh readiness',()=>void load());
    const preview=button('Review activation',()=>void review());
    actions.append(refresh,preview);
    const approval=elem('section','','vp3-marketing-activation-approval');
    approval.hidden=true;
    const approvalHeading=elem('h4','Confirm Campaign activation');
    const approvalText=elem('p');
    const confirmationActions=elem('div','','vp3-marketing-workspace-card-actions');
    const confirm=button('Confirm & activate Campaign',()=>void activate());
    const cancel=button('Return to review',()=>discard());
    confirmationActions.append(confirm,cancel);
    approval.append(approvalHeading,approvalText,confirmationActions);
    root.append(header,intro,details,actions,approval,status);
    host.replaceChildren(root);
    let disposed=false,busy=false,sequence=0,evidence=null,approved=null,requestKey=nonce();
    const live=()=>!disposed&&root.isConnected&&current();
    const say=text=>{if(live())status.textContent=text;};
    function discard(regenerate=true){
      approved=null;approval.hidden=true;approvalText.textContent='';
      if(regenerate)requestKey=nonce();
    }
    function loading(v){
      busy=v;refresh.disabled=v;preview.disabled=v||!evidence?.can_activate;
      confirm.disabled=v;cancel.disabled=v;
    }
    const bullet=(title,value)=>{
      const row=elem('p');row.append(elem('strong',title+': '),document.createTextNode(String(value)));
      return row;
    };
    function render(){
      if(!live()||!evidence)return;
      details.replaceChildren();
      const card=elem('div','','vp3-marketing-activation-summary');
      card.append(
        bullet('Campaign',evidence.name),
        bullet('Type',evidence.type||'Unspecified'),
        bullet('Current status',evidence.status),
        bullet('Campaign version',evidence.version),
        bullet('Current readiness',evidence.readiness.replaceAll('_',' ')),
        bullet('Linked Reward Products',evidence.reward_count),
        bullet('Starts (UTC)',evidence.starts_at||'Unscheduled'),
        bullet('Ends (UTC)',evidence.ends_at||'Unscheduled'),
        bullet('Landing visibility',evidence.landing_visibility.replaceAll('_',' ')),
        bullet('Public landing on activation',evidence.landing_published_on_activation?'Yes':'No')
      );
      details.append(card);
      const effects=elem('div','','vp3-marketing-activation-effects');
      effects.append(elem('h4','Activation effects'),
        elem('p',evidence.landing_published_on_activation
          ?'Activation can publish the saved Campaign landing page. Its visibility and schedule determine when visitors can reach it.'
          :'The saved landing page remains private at activation.'));
      effects.append(elem('p','Existing Campaign automations may become eligible to run after activation. Profile placement is a separate approval. This action does not directly send messages or issue Rewards.'));
      details.append(effects);
      const checkTitle=elem('h4','Current setup checks');
      details.append(checkTitle);
      const list=elem('ul','','vp3-marketing-activation-checks');
      for(const check of evidence.checks){
        const item=elem('li');
        item.append(elem('strong',check.name.replaceAll('_',' ')+' — '+check.state+': '),
          document.createTextNode(check.detail));
        list.append(item);
      }
      if(!evidence.checks.length)list.append(elem('li','No readiness checks were available.'));
      details.append(list);
      if(evidence.blockers.length){
        const warning=elem('div','','vp3-marketing-activation-blockers');
        warning.append(elem('h4','Activation blocked'));
        const entries=elem('ul');
        for(const text of evidence.blockers)entries.append(elem('li',text));
        warning.append(entries);details.append(warning);
      }
      preview.disabled=busy||!evidence.can_activate;
    }
    async function load(){
      if(!live()||busy)return;
      discard();evidence=null;details.replaceChildren();
      const seq=++sequence;loading(true);say('Checking Merchant authority, Campaign version and readiness…');
      const data=await request('marketing_activation_open',{campaign_id:campaignId});
      if(!live()||seq!==sequence)return;
      loading(false);
      const fresh=normalize(data?.activation,campaignId);
      if(!fresh){say('Campaign activation evidence could not be verified. Return to campaigns or refresh.');return;}
      evidence=fresh;render();
      say(fresh.can_activate
        ?'All current readiness checks are verified. Review the activation effects before confirming.'
        :'Campaign cannot be activated yet. Resolve the listed readiness or schedule blockers in Campaigns & Rewards.');
    }
    async function review(){
      if(!live()||busy||!evidence?.can_activate)return;
      discard();
      const snapshot=evidence;
      const seq=++sequence;loading(true);say('Rechecking the exact Campaign before issuing a short-lived approval…');
      const result=await request('marketing_activation_preview',{
        campaign_id:campaignId,revision:snapshot.revision,request_key:requestKey
      });
      if(!live()||seq!==sequence)return;
      loading(false);
      const fresh=normalize(result?.activation,campaignId);
      if(!fresh||fresh.revision!==snapshot.revision||!fresh.can_activate
        ||typeof result?.review_token!=='string'||!token.test(result.review_token)){
        discard();say('The Campaign or readiness changed. Refresh and review the latest details.');return;
      }
      evidence=fresh;render();
      approved={token:result.review_token,nonce:requestKey,revision:fresh.revision};
      approvalText.textContent=fresh.name+' (version '+fresh.version+'): activate this Campaign now.'
        +(fresh.landing_published_on_activation?' Its saved landing page may become publicly accessible.':' Its landing page will remain private.')
        +' Existing automations may subsequently run. This does not directly send messages or issue Rewards.';
      approval.hidden=false;
      say('Confirm only if the activation and public exposure described here are intended.');
      approval.scrollIntoView({block:'nearest',behavior:'smooth'});
    }
    async function activate(){
      if(!live()||busy||!approved||!evidence?.can_activate
        ||approved.revision!==evidence.revision||approved.nonce!==requestKey)return;
      const payload={campaign_id:campaignId,revision:approved.revision,
        request_key:approved.nonce,review_token:approved.token,confirmed:true};
      const seq=++sequence;loading(true);say('Activating through Campaigns & Rewards…');
      const result=await request('marketing_activation_confirm',payload);
      if(!live()||seq!==sequence)return;
      loading(false);
      if(!result||result.activated!==true||result.campaign?.id!==campaignId
        ||result.campaign.status!=='active'||result.outbound_sent_by_this_action!==false){
        discard();say('Activation could not be verified. Refresh the Campaign before trying again.');return;
      }
      discard(false);say('Campaign activation verified by Campaigns & Rewards.');
      onActivated?.(result.campaign);
    }
    void load();
    return {host:root,dispose(){disposed=true;sequence++;root.remove();}};
  }
  window.VP3MarketingActivationV1=Object.freeze({mount});
})();
