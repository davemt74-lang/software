(() => {
  'use strict';
  if(window.VP3MarketingCanvasLinkV1)return;
  const n=(tag,text='',klass='')=>{
    const el=document.createElement(tag);el.textContent=text;
    if(klass)el.className=klass;return el;
  };
  const button=(text,fn)=>{
    const b=n('button',text);b.type='button';b.addEventListener('click',fn);return b;
  };
  const positive=x=>Number.isSafeInteger(x)&&x>0;
  const idPattern=/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/;
  const proofPattern=/^[a-f0-9]{64}$/;
  function mount(host,{current,request,submit,campaigns,onClose,onAuthorityChanged},campaignId=0) {
    if(!host||!current?.()||typeof request!=='function'||typeof submit!=='function')return null;
    const wrap=n('section','','vp3-marketing-canvas-link');
    wrap.dataset.marketingCanvasLink='';
    const head=n('div','','vp3-marketing-canvas-link-head');
    head.append(n('h3','Link saved Canvas creative'),
      button('Back to creative',()=>onClose()));
    const description=n('p','Link a tested Canvas version from this private Agent Chat to a Campaign. This does not publish a Canvas, start a Campaign or send a promotion.','vp3-marketing-workspace-muted');
    const status=n('p','','vp3-marketing-canvas-link-status');
    status.setAttribute('role','status');status.setAttribute('aria-live','polite');
    const controls=n('div','','vp3-marketing-canvas-link-fields');
    const campaignLabel=n('label');campaignLabel.append(n('span','Campaign'));
    const campaignSelect=n('select');campaignLabel.append(campaignSelect);
    const canvasLabel=n('label');canvasLabel.append(n('span','Tested Canvas version'));
    const canvasSelect=n('select');canvasLabel.append(canvasSelect);
    controls.append(campaignLabel,canvasLabel);
    const currentLink=n('p','','vp3-marketing-workspace-muted');
    const actions=n('div','','vp3-marketing-workspace-card-actions');
    const refresh=button('Reload choices',()=>void load());
    const review=button('Review link',()=>showReview());
    actions.append(refresh,review);
    const approval=n('section','','vp3-marketing-canvas-link-review');approval.hidden=true;
    const reviewText=n('p');
    const approve=button('Confirm private Canvas link',()=>void link());
    const edit=button('Change selection',()=>resetReview());
    const approvalActions=n('div','','vp3-marketing-workspace-card-actions');
    approvalActions.append(approve,edit);approval.append(n('h4','Review private Campaign creative'),reviewText,approvalActions);
    wrap.append(head,description,controls,currentLink,actions,approval,status);
    host.replaceChildren(wrap);
    let disposed=false,busy=false,serial=0,data=null,selected=null;
    const campaignRows=(Array.isArray(campaigns)?campaigns:[]).filter(row=>
      positive(row?.id)&&Number.isSafeInteger(row.version)&&row.version>=0
      &&['draft','scheduled','active','paused'].includes(row.status));
    function active(){return !disposed&&wrap.isConnected&&current();}
    function message(text){if(active())status.textContent=text;}
    const opt=(select,value,label)=>{
      const option=n('option',label);option.value=String(value);select.append(option);
    };
    for(const row of campaignRows)opt(campaignSelect,row.id,String(row.name||'Campaign').slice(0,160));
    if(campaignRows.some(x=>x.id===campaignId))campaignSelect.value=String(campaignId);
    function resetReview() {
      selected=null;approval.hidden=true;reviewText.textContent='';
    }
    function controlsBusy(value){
      busy=value;for(const element of [campaignSelect,canvasSelect,refresh,review,approve,edit])element.disabled=value;
    }
    function choiceSelected(){
      const v=canvasSelect.value;
      return data?.choices.find(row=>String(row.id)+':'+row.version===v)||null;
    }
    function choicesVerified(content,expectedId) {
      const value=content?.creative,campaign=value?.campaign;
      if(!value||!campaign||campaign.id!==expectedId
        ||typeof campaign.title!=='string'||!Number.isSafeInteger(campaign.version)||campaign.version<0
        ||!['ready','unavailable'].includes(value.coverage)
        ||!Array.isArray(value.choices)||value.choices.length>24)return null;
      const clean=[];
      for(const row of value.choices){
        if(!row||typeof row.id!=='string'||!idPattern.test(row.id)
          ||!Number.isSafeInteger(row.version)||row.version<1||typeof row.proof!=='string'
          ||!proofPattern.test(row.proof)||typeof row.title!=='string')return null;
        clean.push({id:row.id,version:row.version,title:row.title.slice(0,130),
          proof:row.proof,attached:row.attached===true});
      }
      return {campaign:{id:campaign.id,version:campaign.version,title:campaign.title.slice(0,160)},
        choices:clean,coverage:value.coverage,
        linked:value.linked&&['linked','unavailable'].includes(value.linked.state)
          ?{state:value.linked.state,label:String(value.linked.label||'Private Canvas').slice(0,180)}:null,
        link_endpoint:typeof value.link_endpoint==='string'?value.link_endpoint:null};
    }
    async function load() {
      if(!active()||busy)return;
      const target=Number(campaignSelect.value),known=campaignRows.find(c=>c.id===target);
      resetReview();data=null;canvasSelect.replaceChildren();
      if(!known){message('No Campaign available in this review. Create a draft first.');return;}
      const epoch=++serial;controlsBusy(true);message('Checking saved Canvas versions for this private Chat…');
      const response=await request('marketing_canvas_choices',{campaign_id:target});
      if(!active()||epoch!==serial)return;
      controlsBusy(false);
      const verified=choicesVerified(response,target);
      if(!verified){message('Current Canvas selections could not be verified. Reload this Campaign.');return;}
      if(verified.campaign.version!==known.version){
        message('This Campaign version changed. Refresh Marketing to review its current state.');return;
      }
      data=verified;
      for(const item of verified.choices){
        opt(canvasSelect,item.id+':'+item.version,
          item.title+' · v'+item.version+(item.attached?' · Media attached':''));
      }
      currentLink.textContent=verified.linked?.state==='linked'
        ?'Current saved link: '+verified.linked.label
        :verified.linked?.state==='unavailable'
          ?'Previous Canvas link is no longer verifiable. Review a current tested version.'
          :'No private Canvas linked in this Chat.';
      review.disabled=!verified.choices.length||verified.coverage!=='ready'||!verified.link_endpoint;
      message(verified.choices.length?'Choose the tested Canvas version, then review its Campaign link.'
        :'No tested Canvas versions are available in this Chat. Build and test one with the Coding Agent first.');
    }
    function showReview(){
      if(!active()||busy||!data)return;
      const row=choiceSelected();
      if(!row){message('Choose an available, tested Canvas version first.');return;}
      selected={campaign_id:data.campaign.id,campaign_version:data.campaign.version,
        canvas_id:row.id,canvas_version:row.version,canvas_proof:row.proof};
      reviewText.textContent='Campaign: '+data.campaign.title+' (v'+data.campaign.version+')'
        +' · Canvas: '+row.title+' (v'+row.version+')'
        +(row.attached?' · Media attached':'')+'. This saves a private creative link only; no promotion is published.';
      approval.hidden=false;
      message('Review this exact Campaign and Canvas version before confirming.');
    }
    async function link(){
      if(!active()||busy||!selected||!data||!data.link_endpoint)return;
      const currentChoice=choiceSelected();
      if(!currentChoice||selected.canvas_id!==currentChoice.id||selected.canvas_version!==currentChoice.version
        ||selected.canvas_proof!==currentChoice.proof
        ||selected.campaign_id!==data.campaign.id||selected.campaign_version!==data.campaign.version) {
        resetReview();message('The selection changed. Review the current version again.');return;
      }
      controlsBusy(true);message('Saving the reviewed private Canvas link…');
      const saved=await submit({...selected},data.link_endpoint);
      if(!active())return;
      controlsBusy(false);resetReview();
      if(saved===null){
        message('The link could not be verified. No publication was requested. Reload choices before retrying.');
        return;
      }
      message('Private Canvas link saved. No campaign was published or promotion sent.');
      void load();
    }
    campaignSelect.addEventListener('change',()=>void load());
    canvasSelect.addEventListener('change',()=>resetReview());
    if(campaignRows.length)void load();
    else message('No editable Campaigns in this review. Create a private Campaign draft first.');
    return {dispose(){disposed=true;serial++;wrap.remove();}};
  }
  window.VP3MarketingCanvasLinkV1=Object.freeze({mount});
})();
