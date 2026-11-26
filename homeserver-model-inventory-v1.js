(()=>{'use strict';
const root=document.querySelector('[data-hs-model-inventory]');
if(!root)return;
const find=name=>root.querySelector('[data-inventory-'+name+']');
const button=find('refresh'),status=find('status'),summary=find('summary'),list=find('list');
const pill=document.getElementById('hsStatePill'),device=document.getElementById('hsDeviceId'),connection=document.getElementById('hsConnectCard');
if(!button||!status||!summary||!list||!pill||!device||!connection)return;
let generation=0,controller=null,signature='',busy=false;
const connected=()=>pill.dataset.state==='connected'&&device.textContent.trim()!==''&&device.textContent.trim()!=='—';
const binding=()=>pill.dataset.state+'|'+device.textContent.trim();
const updateButton=()=>{button.disabled=busy||!connected()||connection.getAttribute('aria-busy')==='true';};
function clear(message){
    generation++;
    if(controller)controller.abort();
    controller=null;busy=false;
    summary.hidden=true;list.hidden=true;list.replaceChildren();
    find('default').textContent='—';find('count').textContent='0';find('time').textContent='—';
    status.textContent=message;root.setAttribute('aria-busy','false');updateButton();
}
function sync(){
    const next=binding();
    if(next!==signature){
        signature=next;
        clear(connected()?'Refresh to discover models.':'Connect HomeServer to discover models.');
    }
    updateButton();
}
const states={
    reported:'Discovery complete. Local execution and routing eligibility are not verified.',
    disabled:'Ollama is disabled on HomeServer.',
    unreachable:'Ollama could not be reached on HomeServer.',
    unsupported:'This HomeServer version does not report model inventory. Update HomeServer and try again.',
    invalid_response:'HomeServer returned an invalid inventory.'
};
function render(data){
    const inv=data.inventory;
    if(!inv||inv.version!=='vp3.local-model-inventory.v1'||!Object.hasOwn(states,inv.state)||!Array.isArray(inv.models)||inv.models.length>256)throw new Error('invalid');
    status.textContent=states[inv.state]+(inv.truncated===true?' Only the first 256 reported entries were inspected.':'');
    find('default').textContent=typeof inv.configured_model==='string'?inv.configured_model+(inv.configured_model_reported===true?' (reported)':' (not reported)'):'Not configured';
    find('count').textContent=String(inv.models.length);
    const date=new Date(data.checked_at);
    find('time').textContent=Number.isNaN(date.getTime())?'Unknown':date.toLocaleString();
    summary.hidden=false;
    const fragment=document.createDocumentFragment();
    for(const model of inv.models){
        if(!model||typeof model.model!=='string'||model.model.length>160)throw new Error('invalid');
        const row=document.createElement('div');row.className='hs-info-grid';
        const cell=document.createElement('div');cell.style.gridColumn='1 / -1';cell.style.overflowWrap='anywhere';
        const title=document.createElement('strong');title.textContent=model.model;
        const note=document.createElement('p');note.className='hs-action-note';
        note.textContent=(model.configured_default===true?'Configured default · ':'')+'Reported · Execution unverified · Not eligible for routing';
        cell.append(title,note);row.append(cell);fragment.append(row);
    }
    list.replaceChildren(fragment);list.hidden=inv.models.length===0;
}
async function refresh(){
    if(busy||!connected()||connection.getAttribute('aria-busy')==='true')return;
    clear('Checking HomeServer model inventory…');
    const ticket=generation,expected=binding();
    busy=true;controller=new AbortController();root.setAttribute('aria-busy','true');updateButton();
    try{
        const response=await fetch(root.dataset.api,{method:'POST',credentials:'same-origin',cache:'no-store',
            headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
            body:new URLSearchParams({csrf_token:root.dataset.csrf}),signal:controller.signal});
        const data=await response.json();
        if(ticket!==generation||expected!==binding())return;
        if(!response.ok||data.ok!==true){
            const messages={403:'Model inventory access is unavailable.',409:'HomeServer pairing changed. Refresh the connection.',419:'Session expired. Reload this page.',429:'Please wait a few seconds before refreshing again.'};
            clear(messages[response.status]||'Model inventory could not be refreshed. Check HomeServer and try again.');return;
        }
        render(data);
    }catch(error){
        if(ticket===generation)clear('Model inventory could not be refreshed. Check HomeServer and try again.');
    }finally{
        if(ticket===generation){busy=false;controller=null;root.setAttribute('aria-busy','false');updateButton();}
    }
}
button.addEventListener('click',refresh);
const observer=new MutationObserver(sync);
observer.observe(pill,{attributes:true,attributeFilter:['data-state']});
observer.observe(device,{childList:true,characterData:true,subtree:true});
observer.observe(connection,{attributes:true,attributeFilter:['aria-busy']});
// Clear old inventory immediately on a local pairing action, even if it
// later restores the same displayed device ID.
for(const id of ['hsDisconnect','hsReconnect','hsRemove','hsStartOver','hsGenerateToken','hsRegenerateToken']){
    document.getElementById(id)?.addEventListener('click',()=>clear('Refresh to discover models.'));
}
window.addEventListener('pagehide',()=>clear('Refresh to discover models.'));
sync();
})();
