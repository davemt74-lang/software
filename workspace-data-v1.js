(() => {
  'use strict';
  const $=id=>document.getElementById(id);
  const endpoint=document.querySelector('script[data-endpoint]').dataset.endpoint;
  const labels={profile:'Profile',contacts:'Contacts',crm:'CRM',knowledge:'Knowledge & folders',transcriptions:'Transcriptions',calendar:'Calendar & tasks',schedules:'Schedules',meetings:'Meetings',products:'Products',orders:'Orders',agents:'Agents & memory',chats:'Chats',notifications:'Notifications',music:'Music',workspace_other:'Other account data',artist_workspace:'Artist workspace'};
  let offset=0,sequence=0,debounce=null;
  const time=value=>value?new Date(/(?:Z|[+-]\d{2}:?\d{2})$/i.test(value)?value:String(value).replace(' ','T')+'Z').toLocaleString():'Not yet synchronized';
  function renderFields(host, data) {
    host.replaceChildren();
    for (const [key, value] of Object.entries(data)) {
      if (value === null || value === '' || /^(?:id|.*_id|source_app_key|.*_path)$/.test(key)) continue;
      const label = document.createElement('dt');
      label.textContent = key.replace(/_json$/, '').replaceAll('_', ' ').replace(/^./, char => char.toUpperCase());
      const content = document.createElement('dd');
      let text = typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value);
      if (/_json$/.test(key)) { try { text = JSON.stringify(JSON.parse(text), null, 2); } catch (_) {} }
      content.textContent = text.slice(0, 20000);
      if (text.length > 20000) {
        const more = document.createElement('button');more.type = 'button';more.textContent = 'Show full text';
        more.addEventListener('click', () => { content.textContent = text; });content.append(more);
      }
      host.append(label, content);
    }
    if (!host.children.length) host.textContent = 'No additional details.';
  }
  async function request(params){
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
    try{
      const response=await fetch(endpoint+'?'+new URLSearchParams(params),{credentials:'same-origin',cache:'no-store',signal:controller.signal});
      const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Workspace unavailable');return data;
    }finally{clearTimeout(timeout);}
  }
  async function load(){
    const seq=++sequence,dataset=$('workspaceDataDataset').value;
    try{
      const data=await request({dataset,q:$('workspaceDataSearch').value,offset});if(seq!==sequence)return;
      $('workspaceDataStatus').textContent='Last synchronized: '+time(data.synced_at);
      const host=$('workspaceDataRecords');host.replaceChildren();
      for(const item of data.items){
        const button=document.createElement('button');button.type='button';button.className='workspace-data-record';
        const title=document.createElement('strong');title.textContent=item.title;
        const preview=document.createElement('p');preview.textContent=item.preview||item.table;
        const stamp=document.createElement('small');stamp.textContent=time(item.updated_at);
        button.append(title,preview,stamp);host.append(button);
        button.addEventListener('click',async()=>{
          button.disabled=true;try{const details=await request({dataset,key:item.table+':'+item.source_id});if(seq!==sequence)return;$('workspaceDataTitle').textContent=item.title;renderFields($('workspaceDataText'),details.items[0]?.data||{});$('workspaceDataDetail').showModal();}catch(error){$('workspaceDataStatus').textContent=error.message;}finally{button.disabled=false;}
        });
      }
      if(!data.items.length)host.textContent='No synchronized records yet. Automatic sync starts when your updated HomeServer is connected.';
      $('workspaceDataPrevious').disabled=offset===0;$('workspaceDataNext').disabled=offset+50>=data.count;
      $('workspaceDataPage').textContent=data.count?`${offset+1}–${Math.min(offset+50,data.count)} of ${data.count}`:'0 records';
    }catch(error){if(seq===sequence)$('workspaceDataStatus').textContent=error.message;}
  }
  for(const [value,label] of Object.entries(labels)){const option=document.createElement('option');option.value=value;option.textContent=label;$('workspaceDataDataset').append(option);}
  $('workspaceDataDataset').value='contacts';
  $('workspaceDataDataset').addEventListener('change',()=>{offset=0;$('workspaceDataDetail').close();load();});
  $('workspaceDataSearch').addEventListener('input',()=>{clearTimeout(debounce);debounce=setTimeout(()=>{offset=0;load();},250);});
  $('workspaceDataPrevious').addEventListener('click',()=>{offset=Math.max(0,offset-50);load();});
  $('workspaceDataNext').addEventListener('click',()=>{offset+=50;load();});
  window.addEventListener('pagehide',()=>{sequence++;clearTimeout(debounce);});
  load();
})();
