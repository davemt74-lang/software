(()=>{
'use strict';
const root=document.querySelector('[data-local-knowledge]');
if(!root)return;
const api=root.dataset.api||'';
const csrf=root.dataset.csrf||'';
const canManage=root.dataset.canManage==='1';
const $=id=>document.getElementById(id);
const els={
  state:$('lkState'),stateLabel:$('lkStateLabel'),alert:$('lkAlert'),refresh:$('lkRefresh'),
  collections:$('lkCollections'),mappings:$('lkMappings'),mapForm:$('lkMapForm'),
  mapCollection:$('lkMapCollection'),writeForm:$('lkWriteForm'),writeCollection:$('lkWriteCollection'),
  writeResult:$('lkWriteResult'),permission:$('lkPermission'),requestPermission:$('lkRequestPermission'),
  approval:$('lkApproval'),approvalCode:$('lkApprovalCode'),checkPermission:$('lkCheckPermission')
};
let snapshot=null;
let busy=false;

function setAlert(message,type='error'){
  if(!els.alert)return;
  if(!message){els.alert.hidden=true;els.alert.textContent='';els.alert.className='local-knowledge-alert';return;}
  els.alert.hidden=false;els.alert.textContent=message;els.alert.className='local-knowledge-alert'+(type==='success'?' success':'');
}
function setBusy(value,message=''){
  busy=!!value;
  root.setAttribute('aria-busy',busy?'true':'false');
  root.querySelectorAll('button').forEach(button=>{button.disabled=busy;});
  if(message)setAlert(message,'success');
}
function node(tag,className,text){const el=document.createElement(tag);if(className)el.className=className;if(text!==undefined)el.textContent=String(text);return el;}
function fmtDate(value){if(!value)return 'Not indexed yet';const normalized=String(value).includes('T')?String(value):String(value).replace(' ','T')+'Z';const d=new Date(normalized);return Number.isNaN(d.getTime())?String(value):d.toLocaleString();}
function statusLabel(status){const value=String(status||'pending').replaceAll('_',' ');return value.charAt(0).toUpperCase()+value.slice(1);}
function setState(data){
  const state=!data.paired?'not_connected':(!data.connected?'offline':(data.supported?'connected':'attention'));
  els.state.dataset.state=state;
  els.stateLabel.textContent=state==='connected'?'HomeServer Knowledge ready':state==='offline'?'HomeServer offline':state==='not_connected'?'HomeServer not connected':'HomeServer needs attention';
}
function fillSelect(select,collections,defaultKey){
  if(!select)return;
  const previous=select.value;
  select.replaceChildren();
  if(!collections.length){const option=document.createElement('option');option.value='';option.textContent='No collections available';select.append(option);return;}
  for(const collection of collections){const option=document.createElement('option');option.value=collection.collection_key;option.textContent=collection.name||collection.collection_key;select.append(option);}
  const preferred=collections.some(item=>item.collection_key===previous)?previous:(collections.some(item=>item.collection_key===defaultKey)?defaultKey:collections[0].collection_key);
  select.value=preferred;
}
function renderCollections(data){
  const collections=Array.isArray(data.collections)?data.collections:[];
  els.collections.replaceChildren();
  if(!collections.length){els.collections.append(node('div','lk-empty',data.connected?'No HomeServer Knowledge collections are available to this pairing.':'Connect HomeServer to load collections.'));}
  for(const collection of collections){
    const card=node('article','lk-collection');
    const top=node('div','lk-collection-top');
    const title=node('div','');title.append(node('h3','',collection.name||collection.collection_key));
    if(collection.description)title.append(node('p','',collection.description));
    top.append(title,node('span','lk-collection-key',collection.collection_key));
    const stats=node('div','lk-collection-stats');
    const sources=node('span','');sources.append(node('strong','',collection.source_count||0),document.createTextNode(' sources'));
    const items=node('span','');items.append(node('strong','',collection.direct_item_count||0),document.createTextNode(' direct items'));
    stats.append(sources,items);card.append(top,stats);els.collections.append(card);
  }
  fillSelect(els.mapCollection,collections,data.default_collection||'general');
  fillSelect(els.writeCollection,collections,data.default_collection||'general');
}
function renderMappings(data){
  const mappings=Array.isArray(data.mappings)?data.mappings:[];
  els.mappings.replaceChildren();
  if(!mappings.length){els.mappings.append(node('div','lk-empty',data.connected?'No local folders are mapped for this VP3 scope yet.':'HomeServer mappings are unavailable while offline.'));return;}
  for(const mapping of mappings){
    const row=node('article','lk-mapping');
    const head=node('div','lk-mapping-head');
    const title=node('div','');title.append(node('h3','',mapping.label||'Local folder'),node('p','',mapping.collection_name||mapping.collection_key||'Collection'));
    head.append(title,node('span','lk-status',statusLabel(mapping.status)));
    const meta=node('div','lk-mapping-meta');
    meta.append(node('span','',`${mapping.indexed_files||0} indexed`),node('span','',`${mapping.tracked_files||0} tracked`),node('span','',`${mapping.error_files||0} errors`),node('span','',mapping.recursive?'Subfolders included':'Top folder only'));
    const footer=node('div','lk-mapping-foot');footer.append(node('span','',fmtDate(mapping.last_scan_completed_at)));
    if(canManage){const remove=node('button','lk-button danger','Unmap');remove.type='button';remove.dataset.mappingId=mapping.mapping_id;remove.addEventListener('click',()=>unmap(mapping.mapping_id,mapping.label||'this folder'));footer.append(remove);}
    row.append(head,meta,footer);els.mappings.append(row);
  }
}
function renderPermission(data){
  if(!els.permission)return;
  const pending=!!(data.permission_upgrade&&data.permission_upgrade.pending);
  const code=data.permission_upgrade&&data.permission_upgrade.approval_code?String(data.permission_upgrade.approval_code):'';
  els.permission.hidden=!pending;
  if(els.approval){els.approval.hidden=!pending;if(code&&els.approvalCode)els.approvalCode.textContent=code;}
}
function render(data){
  snapshot=data||{};setState(snapshot);renderCollections(snapshot);renderMappings(snapshot);renderPermission(snapshot);
  if(snapshot.error)setAlert(snapshot.error);else setAlert('');
  const writable=!!(snapshot.connected&&snapshot.supported&&canManage);
  if(els.mapForm)els.mapForm.querySelectorAll('input,select,textarea,button').forEach(el=>{el.disabled=!writable||busy;});
  if(els.writeForm)els.writeForm.querySelectorAll('input,select,textarea,button').forEach(el=>{el.disabled=!writable||busy;});
}
async function request(options={}){
  const response=await fetch(api,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json',...(options.headers||{})},...options});
  const data=await response.json().catch(()=>({ok:false,error:'VP3 returned an invalid Local Knowledge response.',code:'invalid_response'}));
  if(!response.ok||!data.ok){const error=new Error(data.error||'Local Knowledge request failed.');error.code=data.code||'request_failed';throw error;}
  return data;
}
async function load(force=false){
  const data=await request({method:'GET',headers:{Accept:'application/json'},...(force?{}:{}) ,});
  render(data.snapshot||{});return data.snapshot||{};
}
async function post(action,fields={}){
  const body=new URLSearchParams({action,csrf_token:csrf});
  for(const [key,value] of Object.entries(fields)){if(value!==undefined&&value!==null)body.set(key,String(value));}
  return request({method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
}
function showPermission(error){
  if(!els.permission)return;
  if(error&&error.code==='permission_required'){
    els.permission.hidden=false;
    els.permission.scrollIntoView({behavior:'smooth',block:'center'});
  }
}
async function unmap(mappingId,label){
  if(busy||!canManage)return;
  if(!/^source-\d{1,18}$/.test(String(mappingId))||!confirm(`Stop using ${label} as a HomeServer Knowledge source?`))return;
  setBusy(true,'Removing the HomeServer mapping…');
  try{await post('unmap_folder',{mapping_id:mappingId});await load(true);setAlert('Local Knowledge folder unmapped.','success');}
  catch(error){setAlert(error.message);showPermission(error);}
  finally{setBusy(false);if(snapshot)render(snapshot);}
}
if(els.refresh)els.refresh.addEventListener('click',async()=>{if(busy)return;setBusy(true);try{await load(true);}catch(error){setAlert(error.message);}finally{setBusy(false);if(snapshot)render(snapshot);}});
if(els.mapForm)els.mapForm.addEventListener('submit',async event=>{
  event.preventDefault();if(busy||!canManage)return;
  const form=new FormData(els.mapForm);
  const fields={collection_key:form.get('collection_key')||'',label:form.get('label')||'',scan_interval_seconds:form.get('scan_interval_seconds')||'120',excludes:form.get('excludes')||''};
  if(form.get('recursive')!==null)fields.recursive='1';
  setBusy(true,'Waiting for the folder picker on HomeServer. Choose a folder there; its native location will not be sent to VP3.');
  try{
    const data=await post('map_folder',fields);
    if(data.result&&data.result.cancelled){setAlert('Folder selection was cancelled on HomeServer.');}
    else{await load(true);setAlert('HomeServer folder mapped and indexing started.','success');}
  }catch(error){setAlert(error.message);showPermission(error);}
  finally{setBusy(false);if(snapshot)render(snapshot);}
});
if(els.writeForm)els.writeForm.addEventListener('submit',async event=>{
  event.preventDefault();if(busy||!canManage)return;
  const form=new FormData(els.writeForm);
  setBusy(true,'Saving private knowledge to HomeServer…');
  try{
    const data=await post('write_item',{collection_key:form.get('collection_key')||'',title:form.get('title')||'',content:form.get('content')||'',kind:form.get('kind')||'summary'});
    const item=data.result&&data.result.item?data.result.item:null;
    if(els.writeResult)els.writeResult.textContent=item?`Saved “${item.title}” · ${item.chunk_count||0} chunks`:'Saved to HomeServer.';
    els.writeForm.querySelector('[name="title"]').value='';els.writeForm.querySelector('[name="content"]').value='';
    await load(true);setAlert('Private knowledge saved on HomeServer.','success');
  }catch(error){setAlert(error.message);showPermission(error);}
  finally{setBusy(false);if(snapshot)render(snapshot);}
});
if(els.requestPermission)els.requestPermission.addEventListener('click',async()=>{
  if(busy||!canManage)return;setBusy(true,'Creating a one-time HomeServer approval request…');
  try{
    const data=await post('request_write_permission');const result=data.result||{};
    els.permission.hidden=false;els.approval.hidden=false;els.approvalCode.textContent=result.approval_code||'Check HomeServer';
    setAlert(result.existing?'Complete the existing approval shown on HomeServer, then check again.':'Approve the displayed code locally in HomeServer.','success');
  }catch(error){setAlert(error.message);}
  finally{setBusy(false);if(snapshot)render(snapshot);if(els.permission)els.permission.hidden=false;}
});
if(els.checkPermission)els.checkPermission.addEventListener('click',async()=>{
  if(busy||!canManage)return;setBusy(true,'Checking HomeServer approval…');
  try{
    const data=await post('check_write_permission');
    if(data.result&&data.result.ready){await load(true);setAlert('Knowledge write access approved.','success');if(els.permission)els.permission.hidden=true;}
    else setAlert(`HomeServer approval is ${data.result&&data.result.status?data.result.status:'still pending'}.`);
  }catch(error){setAlert(error.message);}
  finally{setBusy(false);if(snapshot)render(snapshot);}
});
(async()=>{try{await load(false);}catch(error){setAlert(error.message);els.state.dataset.state='attention';els.stateLabel.textContent='Local Knowledge unavailable';}})();
})();
