(()=>{'use strict';
const root=document.querySelector('[data-homeserver-settings]');if(!root)return;
const api=root.dataset.api||'',acceptanceApi=root.dataset.acceptanceApi||'',csrf=root.dataset.csrf||'',byId=id=>document.getElementById(id);
const els={
 pill:byId('hsStatePill'),label:byId('hsStateLabel'),alert:byId('hsAlert'),card:byId('hsConnectCard'),
 title:byId('hsConnectionTitle'),detail:byId('hsConnectionDetail'),stepper:byId('hsStepper'),
 tokenPanel:byId('hsTokenPanel'),generate:byId('hsGenerateToken'),tokenResult:byId('hsTokenResult'),
 token:byId('hsPairingToken'),copy:byId('hsCopyToken'),regenerate:byId('hsRegenerateToken'),
 connectedActions:byId('hsConnectedActions'),disconnectedActions:byId('hsDisconnectedActions'),
 recoveryActions:byId('hsRecoveryActions'),startOver:byId('hsStartOver'),
 reconnect:byId('hsReconnect'),disconnect:byId('hsDisconnect'),remove:byId('hsRemove'),
 info:byId('hsConnectionInfo'),capsCard:byId('hsCapabilitiesCard'),caps:byId('hsCapabilities'),
 refresh:byId('hsRefresh'),deviceName:byId('hsDeviceName'),deviceId:byId('hsDeviceId'),
 relayStatus:byId('hsRelayStatus'),lastSeen:byId('hsLastSeen'),version:byId('hsVersion'),
 reconnectStatus:byId('hsReconnectStatus'),build:byId('hsBuild'),scopeCount:byId('hsScopeCount'),
 acceptanceCard:byId('hsReleaseAcceptanceCard'),acceptanceRun:byId('hsRunReleaseAcceptance'),
 acceptanceStatus:byId('hsReleaseAcceptanceStatus'),acceptanceSummary:byId('hsReleaseAcceptanceSummary'),
 acceptanceChecks:byId('hsReleaseAcceptanceChecks')
};
let busy=false,pollTimer=null,pollCount=0,rawToken='';
const labels={not_connected:'Not connected',connecting:'Connecting',connected:'Connected',reconciling:'Reconciling',connection_error:'Connection error',disconnected:'Disconnected'};
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function setBusy(v){busy=!!v;if(els.card)els.card.setAttribute('aria-busy',busy?'true':'false');root.querySelectorAll('button').forEach(button=>{button.disabled=busy;});}
function showAlert(message,type='error'){if(!els.alert)return;if(!message){els.alert.hidden=true;els.alert.textContent='';els.alert.className='hs-alert';return;}els.alert.hidden=false;els.alert.textContent=message;els.alert.className='hs-alert'+(type==='success'?' success':'');}
function fmtDate(value){if(!value)return'Not yet';const raw=String(value),d=new Date(raw.includes('T')?raw:raw.replace(' ','T')+'Z');return Number.isNaN(d.getTime())?raw:d.toLocaleString();}
async function request(url,options={}){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json',...(options.headers||{})},...options});let data={};try{data=await r.json();}catch(_){const e=new Error('HomeServer returned an invalid response.');e.status=r.status;throw e;}if(!r.ok||!data.ok){const e=new Error(data.error||'HomeServer request failed.');e.status=r.status;e.payload=data;throw e;}return data;}
function getStatus(force=false){const target=force?(api+(api.includes('?')?'&':'?')+'refresh=1'):api;return request(target,{method:'GET'});}
function renderAcceptance(result){if(!result)return;const summary=result.summary||{},ready=Number(summary.ready||0),warnings=Number(summary.warnings||0),blocked=Number(summary.blocked||0),productionReady=Boolean(result.production_ready);if(els.acceptanceStatus)els.acceptanceStatus.textContent=productionReady?'Ready for HomeServer 2.3':'Needs attention';if(els.acceptanceSummary)els.acceptanceSummary.textContent=ready+' ready · '+warnings+' warnings · '+blocked+' blocked';if(els.acceptanceChecks){const checks=Array.isArray(result.checks)?result.checks:[];els.acceptanceChecks.innerHTML=checks.length?checks.map(check=>'<span title="'+esc(check?.detail||'')+'">'+esc(check?.label||'Check')+': '+esc(String(check?.status||'unknown').replaceAll('_',' '))+'</span>').join(''):'<span>No checks returned.</span>';}}
async function runReleaseAcceptance(){if(busy||!acceptanceApi)return;setBusy(true);try{const body=new URLSearchParams({csrf_token:csrf});const data=await request(acceptanceApi,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});renderAcceptance(data.acceptance||{});showAlert(data.acceptance?.production_ready?'HomeServer 2.3 release acceptance passed.':'HomeServer release acceptance found items that need attention.',data.acceptance?.production_ready?'success':'error');}catch(e){renderError(e);}finally{setBusy(false);}}
function post(action){const body=new URLSearchParams({action,csrf_token:csrf});return request(api,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});}
function stopPoll(){if(pollTimer)clearTimeout(pollTimer);pollTimer=null;pollCount=0;}
function tokenPending(status){return ['pending','redeeming'].includes(status?.account_pairing?.status||'');}
function setSteps(status){const state=status.connection_state||'not_connected',pending=tokenPending(status);els.stepper.hidden=!['not_connected','connecting'].includes(state);els.stepper.querySelectorAll('.hs-step').forEach((node,index)=>{node.classList.remove('active','complete');const n=index+1;if(state==='connecting'||pending){if(n===1)node.classList.add('complete');if(n===2)node.classList.add('active');}else if(n===1)node.classList.add('active');});}
function render(status,{preserveAlert=false}={}){status=status||{};const state=status.connection_state||'not_connected',reconciling=state==='connected'&&Boolean(status.reconciliation?.needs_reconciliation),displayState=reconciling?'reconciling':state;
 const account=status.account_pairing||{},pending=tokenPending(status);
 if(els.pill)els.pill.dataset.state=state;if(els.label)els.label.textContent=labels[displayState]||'HomeServer';setSteps({...status,connection_state:state});
 els.tokenPanel.hidden=state!=='not_connected';els.tokenResult.hidden=!rawToken;
 els.connectedActions.hidden=!['connected','connection_error'].includes(state);
 els.disconnectedActions.hidden=state!=='disconnected'||status.can_remove===false;
 els.recoveryActions.hidden=!['connection_error','disconnected'].includes(state);
 els.info.hidden=state==='not_connected';els.capsCard.hidden=!['connected','connection_error'].includes(state);
 if(state==='not_connected'){els.title.textContent='Connect HomeServer';els.detail.textContent=pending?('A VP3 pairing token is active'+(account.expires_at?' until '+fmtDate(account.expires_at):'')+'. Paste it into HomeServer → VP3 Cloud Connection.'):'Generate a one-time token below, then paste it into HomeServer → VP3 Cloud Connection.';}
 else if(state==='connecting'){els.title.textContent='Connecting to HomeServer';els.detail.textContent='HomeServer is redeeming the account token and starting the outbound VP3 HTTPS session.';}
 else if(state==='connected'){els.title.textContent=reconciling?'HomeServer connected — reconciling':'HomeServer connected';els.detail.textContent=reconciling?'VP3 has the live HomeServer heartbeat and is reconciling Agent Brain/data continuity before HomeServer-backed context is treated as current.':'VP3 is receiving the live HomeServer HTTPS heartbeat and continuity is current for the capabilities authorized for this pairing.';}
 else if(state==='connection_error'){els.title.textContent='HomeServer is not connected';els.detail.textContent='The pairing is saved, but the live HTTPS heartbeat is unavailable. Retry the connection or start a new pairing.';}
 else{els.title.textContent='HomeServer disconnected';els.detail.textContent='VP3 Cloud credentials are no longer active for this pairing. Remove it or start a fresh pairing.';}
 els.generate.textContent=pending?'Generate New Pairing Token':'Generate Pairing Token';
 els.deviceName.textContent=status.device_name||'HomeServer';els.deviceId.textContent=status.device_id||'—';
 els.relayStatus.textContent=status.transport==='vp3_https'?'VP3 HTTPS Relay':'Custom WebSocket Relay';
 els.lastSeen.textContent=fmtDate(status.last_seen_at);els.version.textContent=status.installed_version||'—';
 els.reconnectStatus.textContent=reconciling?'reconciliation pending':(status.reconnect_status||'—').replaceAll('_',' ');els.build.textContent=status.build||'—';
 const scopes=Array.isArray(status.paired_scopes)?status.paired_scopes:[];els.scopeCount.textContent=String(scopes.length);
 const caps=Array.isArray(status.capabilities)?status.capabilities:[];els.caps.innerHTML=caps.length?caps.map(v=>'<span>'+esc(v)+'</span>').join(''):'<span>None reported</span>';
 els.reconnect.hidden=state!=='connection_error';els.disconnect.hidden=!['connected','connection_error','connecting'].includes(state);els.remove.hidden=state!=='disconnected'||status.can_remove===false;if(els.acceptanceCard)els.acceptanceCard.hidden=state!=='connected'||reconciling;
 if(status.error&&!preserveAlert)showAlert(status.error);else if(!busy&&!preserveAlert)showAlert('');
 if(pending&&!pollTimer)schedulePoll();if(['connected','disconnected','connection_error'].includes(state)&&!pending)stopPoll();}
function renderError(e){if(e?.payload?.status)render(e.payload.status,{preserveAlert:true});else if(els.pill){els.pill.dataset.state='connection_error';els.label.textContent=labels.connection_error;}showAlert(e?.message||'HomeServer request failed.');}
function applyTokenResponse(data,message){const token=data.account_pairing_token||{},value=token.token||'';if(!value)throw new Error('VP3 did not return the new pairing token.');rawToken=value;els.token.textContent=value;els.tokenResult.hidden=false;render(data.status||{});showAlert(message,'success');schedulePoll();}
async function generateToken(){if(busy)return;setBusy(true);try{const data=await post('generate_pairing_token');applyTokenResponse(data,'Pairing token generated. Paste it into HomeServer → VP3 Cloud Connection.');}catch(e){renderError(e);}finally{setBusy(false);}}
function schedulePoll(){stopPoll();pollCount=0;const tick=async()=>{if(pollCount++>300){stopPoll();showAlert('HomeServer connection or reconciliation did not finish. Refresh status or start over if the problem persists.');return;}try{const data=await getStatus(true);const status=data.status||{},reconciling=status.connection_state==='connected'&&Boolean(status.reconciliation?.needs_reconciliation);render(status);if(status.connection_state==='connected'&&!reconciling){stopPoll();showAlert('HomeServer connected and continuity reconciled successfully.','success');return;}if(tokenPending(status)||reconciling)pollTimer=setTimeout(tick,3000);else stopPoll();}catch(e){stopPoll();renderError(e);}};pollTimer=setTimeout(tick,2000);}
els.generate?.addEventListener('click',generateToken);els.regenerate?.addEventListener('click',generateToken);
els.copy?.addEventListener('click',async()=>{if(!rawToken)return;try{await navigator.clipboard.writeText(rawToken);showAlert('Pairing token copied.','success');}catch(_){showAlert('Copy was blocked by the browser. Select the token and copy it manually.');}});
els.test?.addEventListener('click',async()=>{if(busy)return;setBusy(true);try{const data=await post('test_connection');render(data.status||{});const rt=data.round_trip||{};showAlert('Cloud → HomeServer → Cloud verified'+(rt.latency_ms!=null?' in '+rt.latency_ms+' ms':'')+'.','success');}catch(e){renderError(e);}finally{setBusy(false);}});els.acceptanceRun?.addEventListener('click',runReleaseAcceptance);
els.reconnect?.addEventListener('click',async()=>{if(busy)return;setBusy(true);try{const data=await post('reconnect');render(data.status||{});}catch(e){renderError(e);}finally{setBusy(false);}});
els.disconnect?.addEventListener('click',async()=>{if(busy||!confirm('Disconnect VP3 from this HomeServer?'))return;setBusy(true);try{const data=await post('disconnect');rawToken='';els.tokenResult.hidden=true;render(data.status||{});showAlert('HomeServer disconnected from VP3 Cloud.','success');}catch(e){renderError(e);}finally{setBusy(false);}});
els.remove?.addEventListener('click',async()=>{if(busy||!confirm('Remove this disconnected HomeServer pairing from VP3 Cloud?'))return;setBusy(true);try{const data=await post('remove');rawToken='';els.tokenResult.hidden=true;render(data.status||{});showAlert('HomeServer pairing record removed.','success');}catch(e){renderError(e);}finally{setBusy(false);}});
els.startOver?.addEventListener('click',async()=>{if(busy||!confirm('Clear the saved HomeServer pairing and generate a fresh VP3 pairing token?'))return;setBusy(true);try{const data=await post('reset_pairing');applyTokenResponse(data,'Old pairing cleared. A fresh VP3 pairing token is ready.');}catch(e){renderError(e);}finally{setBusy(false);}});
els.refresh?.addEventListener('click',async()=>{if(busy)return;setBusy(true);try{const data=await getStatus(true);render(data.status||{});}catch(e){renderError(e);}finally{setBusy(false);}});
(async()=>{try{const data=await getStatus(false);render(data.status||{});}catch(e){renderError(e);}})();
setInterval(async()=>{if(document.visibilityState!=='visible'||busy||pollTimer)return;try{const data=await getStatus(false);render(data.status||{});}catch(e){renderError(e);}},10000);
})();