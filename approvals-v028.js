(function(){
  'use strict';
  const app=document.getElementById('approvalsApp');
  if(!app)return;
  const endpoint=String(app.dataset.endpoint||'');
  const csrf=String(app.dataset.csrf||'');
  const list=document.getElementById('approvalsList');
  const count=document.getElementById('approvalsCount');
  const refresh=document.getElementById('approvalsRefresh');
  const permission=document.getElementById('approvalsPermission');
  const upgrade=document.getElementById('approvalsUpgrade');
  const check=document.getElementById('approvalsCheckPermission');
  const codeWrap=document.getElementById('approvalsCodeWrap');
  const code=document.getElementById('approvalsCode');
  const stateBadge=document.getElementById('approvalsState');
  const stateTitle=document.getElementById('approvalsStatusTitle');
  const stateDetail=document.getElementById('approvalsStatusDetail');
  const policySummary=document.getElementById('approvalsPolicySummary');
  const policyList=document.getElementById('approvalsPolicyList');
  const tabs=[...document.querySelectorAll('[data-approval-status]')];
  let currentStatus='pending';
  let busy=false;
  let policyByKey=new Map();

  const policyLabels={read_only:'Read only',safe_automatic:'Safe automatic',approval_required:'Approval required',sensitive_high_impact:'Sensitive / high-impact'};
  const policyReasons={unpaired:'Pair HomeServer to view effective Agent permissions.',offline:'HomeServer is offline. VP3 does not keep a shadow copy of its policy.',unsupported:'Update HomeServer to enable action-policy visibility.',credentials_unavailable:'Re-pair HomeServer to restore policy visibility.',remote_unavailable:'HomeServer policy could not be read through the Remote Bridge.',invalid_response:'HomeServer returned an unsupported policy response.'};
  const text=(node,value)=>{if(node)node.textContent=value==null?'':String(value)};
  const formatDate=value=>{if(!value)return '';const raw=String(value);const d=new Date(raw.includes('T')?raw:raw.replace(' ','T'));return Number.isNaN(d.getTime())?raw:d.toLocaleString()};
  const actionLabel=value=>{const key=String(value||'');if(key==='memory.write')return 'Allow memory write';if(key==='tasks.create')return 'Create task';return key?`Approve ${key}`:'HomeServer action request'};
  const setBusy=value=>{busy=Boolean(value);[refresh,upgrade,check,...tabs].filter(Boolean).forEach(node=>{node.disabled=busy;node.setAttribute('aria-busy',busy?'true':'false')})};

  function policyBadge(mode){const badge=document.createElement('span');badge.className='agent-policy-badge';badge.dataset.policy=String(mode||'');badge.textContent=policyLabels[mode]||'Policy unavailable';return badge;}

  function renderPolicy(policy){
    if(!policySummary||!policyList)return;
    policyByKey=new Map();
    policySummary.replaceChildren();policyList.replaceChildren();
    if(!policy||policy.available!==true){
      const state=document.createElement('span');state.textContent='Policy unavailable';policySummary.appendChild(state);
      const empty=document.createElement('div');empty.className='agent-policy-empty';empty.textContent=policyReasons[String(policy&&policy.reason||'')]||'Effective HomeServer tool policy is not available.';policyList.appendChild(empty);return;
    }
    const counts=policy.counts&&typeof policy.counts==='object'?policy.counts:{};
    [['safe_automatic','Automatic'],['approval_required','Approval'],['read_only','Read only'],['sensitive_high_impact','Local-only']].forEach(([key,label])=>{const chip=document.createElement('span');const strong=document.createElement('strong');strong.textContent=Number(counts[key]||0).toLocaleString();chip.append(strong,document.createTextNode(` ${label}`));policySummary.appendChild(chip)});
    const tools=Array.isArray(policy.tools)?policy.tools:[];
    tools.forEach(tool=>{
      const key=String(tool.key||'');if(key)policyByKey.set(key,tool);
      const row=document.createElement('div');row.className='agent-policy-tool';
      const copy=document.createElement('div');copy.className='agent-policy-tool-copy';
      const name=document.createElement('strong');name.textContent=String(tool.name||key||'HomeServer tool');
      const meta=document.createElement('small');meta.textContent=key;if(tool.inherited){const inherited=document.createElement('span');inherited.className='agent-policy-inherited';inherited.textContent=' · default';meta.appendChild(inherited)}
      copy.append(name,meta);row.append(copy,policyBadge(String(tool.policy_mode||'')));policyList.appendChild(row);
    });
    if(!tools.length){const empty=document.createElement('div');empty.className='agent-policy-empty';empty.textContent='HomeServer reported policy support but no tools are currently available to VP3.';policyList.appendChild(empty)}
  }

  async function parse(response){let data={};try{data=await response.json()}catch(_){throw new Error('VP3 returned an invalid approvals response.');}if(!response.ok)throw new Error(data.error||`Approvals request failed (${response.status}).`);return data;}
  async function post(action,extra){const body=new URLSearchParams({action,csrf_token:csrf,...(extra||{})});const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});return parse(response);}

  function renderState(data){
    const state=data&&data.state?data.state:{};const permissionState=String(state.permission||'unknown');const pendingUpgrade=Boolean(state.permission_upgrade_pending);const supported=Boolean(state.supported);const connected=Boolean(state.connected);const paired=Boolean(state.paired);stateBadge.className='approvals-state';
    if(data&&data.ok){stateBadge.classList.add('ready');text(stateBadge,'Ready');text(stateTitle,'HomeServer approvals are connected');text(stateDetail,'VP3 can review only action requests created by this VP3 pairing. HomeServer remains the execution authority.');}
    else if(pendingUpgrade||permissionState==='permission_required'){stateBadge.classList.add('warning');text(stateBadge,'Permission required');text(stateTitle,'One permission upgrade is required');text(stateDetail,data&&data.error?data.error:'Approve the HomeServer permission upgrade to review actions from VP3.');}
    else if(!paired){stateBadge.classList.add('warning');text(stateBadge,'Not paired');text(stateTitle,'Connect HomeServer to use federated approvals');text(stateDetail,data&&data.error?data.error:'Pair HomeServer from the VP3 HomeServer control.');}
    else if(!connected){stateBadge.classList.add('error');text(stateBadge,'Offline');text(stateTitle,'HomeServer is offline');text(stateDetail,data&&data.error?data.error:'Bring HomeServer online and refresh.');}
    else if(!supported||permissionState==='unsupported'){stateBadge.classList.add('warning');text(stateBadge,'Update required');text(stateTitle,'HomeServer needs approval federation support');text(stateDetail,data&&data.error?data.error:'Update HomeServer and refresh the connection.');}
    else if(permissionState==='authorization'){stateBadge.classList.add('error');text(stateBadge,'Re-pair required');text(stateTitle,'HomeServer authorization needs to be renewed');text(stateDetail,data&&data.error?data.error:'Re-pair HomeServer to restore authorization.');}
    else{stateBadge.classList.add('error');text(stateBadge,'Unavailable');text(stateTitle,'HomeServer approvals are unavailable');text(stateDetail,data&&data.error?data.error:'Refresh the HomeServer connection and try again.');}
    const showPermission=paired&&connected&&supported&&(pendingUpgrade||permissionState==='permission_required');if(permission)permission.hidden=!showPermission;const approvalCode=String(state.approval_code||'');if(codeWrap)codeWrap.hidden=!approvalCode;if(code)text(code,approvalCode||'—');if(upgrade)upgrade.hidden=Boolean(approvalCode);
  }

  function requestCard(item){
    const article=document.createElement('article');article.className='approvals-request';article.dataset.requestId=String(item.id||'');
    const copy=document.createElement('div');copy.className='approvals-request-copy';const head=document.createElement('div');head.className='approvals-request-head';const actionKey=String(item.action_key||'');
    const title=document.createElement('h3');title.textContent=actionLabel(actionKey);head.appendChild(title);const origin=document.createElement('span');origin.className='approvals-origin';origin.textContent='VP3 → HomeServer';head.appendChild(origin);if(actionKey){const tool=document.createElement('span');tool.className='approvals-tool';tool.textContent=actionKey;head.appendChild(tool)}
    const effective=policyByKey.get(actionKey);if(effective&&effective.policy_mode)head.appendChild(policyBadge(String(effective.policy_mode)));
    const status=document.createElement('span');status.className='approvals-request-status';status.textContent=String(item.status||currentStatus);head.appendChild(status);copy.appendChild(head);
    const detail=document.createElement('p');detail.textContent=effective&&effective.policy_mode==='approval_required'?'HomeServer requires approval before this tool can execute. Private tool arguments stay on HomeServer.':'This approval is scoped to your VP3 pairing. Private tool arguments stay on HomeServer.';copy.appendChild(detail);
    const meta=document.createElement('div');meta.className='approvals-request-meta';const requestId=document.createElement('span');requestId.textContent=`Request ${String(item.id||'').slice(0,32)}`;meta.appendChild(requestId);if(item.created_at){const created=document.createElement('span');created.textContent=`Created ${formatDate(item.created_at)}`;meta.appendChild(created)}if(item.expires_at){const expires=document.createElement('span');expires.textContent=`Expires ${formatDate(item.expires_at)}`;meta.appendChild(expires)}copy.appendChild(meta);article.appendChild(copy);
    if(String(item.status||currentStatus)==='pending'){const actions=document.createElement('div');actions.className='approvals-request-actions';const deny=document.createElement('button');deny.type='button';deny.className='approvals-button danger';deny.textContent='Deny';deny.dataset.decision='deny';const approve=document.createElement('button');approve.type='button';approve.className='approvals-button primary';approve.textContent='Approve';approve.dataset.decision='approve';actions.append(deny,approve);article.appendChild(actions)}return article;
  }

  function renderItems(items,error){list.replaceChildren();const rows=Array.isArray(items)?items:[];text(count,`${rows.length} request${rows.length===1?'':'s'}`);if(error){const message=document.createElement('div');message.className='approvals-error';message.textContent=String(error);list.appendChild(message);return}if(!rows.length){const empty=document.createElement('div');empty.className='approvals-empty';empty.textContent=currentStatus==='pending'?'No HomeServer actions are waiting for your approval.':'No HomeServer approval history in this status.';list.appendChild(empty);return}rows.forEach(item=>list.appendChild(requestCard(item)));}

  async function load(){if(busy||!endpoint)return;setBusy(true);try{const response=await fetch(`${endpoint}?status=${encodeURIComponent(currentStatus)}&limit=100`,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const data=await parse(response);renderState(data);renderPolicy(data.policy);renderItems(data.items,data.ok?'':data.error||'HomeServer approvals are unavailable.');}catch(error){renderPolicy({available:false,reason:'remote_unavailable'});renderItems([],error&&error.message?error.message:'HomeServer approvals are unavailable.');renderState({ok:false,state:{},error:error&&error.message?error.message:''});}finally{setBusy(false)}}
  async function review(requestId,decision){if(busy)return;const verb=decision==='approve'?'approve':'deny';if(!window.confirm(`${verb==='approve'?'Approve':'Deny'} this HomeServer action request? HomeServer will remain the execution authority.`))return;setBusy(true);try{await post(decision,{request_id:requestId})}catch(error){window.alert(error&&error.message?error.message:'HomeServer could not review this request.')}finally{setBusy(false);await load()}}

  list.addEventListener('click',event=>{const button=event.target.closest('button[data-decision]');if(!button)return;const row=button.closest('[data-request-id]');if(!row)return;review(String(row.dataset.requestId||''),String(button.dataset.decision||''))});
  tabs.forEach(tab=>tab.addEventListener('click',()=>{if(busy)return;currentStatus=String(tab.dataset.approvalStatus||'pending');tabs.forEach(node=>node.classList.toggle('active',node===tab));load()}));refresh&&refresh.addEventListener('click',load);
  upgrade&&upgrade.addEventListener('click',async()=>{if(busy)return;setBusy(true);try{const data=await post('upgrade_permission');if(codeWrap)codeWrap.hidden=false;text(code,data.approval_code||'—');upgrade.hidden=true}catch(error){window.alert(error&&error.message?error.message:'Could not request approval permission.')}finally{setBusy(false)}});
  check&&check.addEventListener('click',async()=>{if(busy)return;setBusy(true);try{const data=await post('check_permission');if(data.ready){if(permission)permission.hidden=true;if(codeWrap)codeWrap.hidden=true}else{window.alert('HomeServer has not approved the permission upgrade yet.')}}catch(error){window.alert(error&&error.message?error.message:'Could not check HomeServer approval.')}finally{setBusy(false);await load()}});
  load();
})();
