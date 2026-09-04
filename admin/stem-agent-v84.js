(() => {
  'use strict';
  const cfg=window.STONEFELLOW_STUDIO_AGENT;
  if(!cfg || !cfg.endpoint || !cfg.trackId)return;
  const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
  const key=`stonefellow:studio-agent:v84:${cfg.userId}:${cfg.trackId}`;
  let sessionId=Number(localStorage.getItem(key)||0);
  let busy=false;
  const trigger=document.createElement('button'); trigger.type='button'; trigger.className='daw-small-button studio-agent-trigger'; trigger.textContent='AI'; trigger.title='Open Stonefellow Studio Agent';
  (document.querySelector('.daw-canvas-actions')||document.body).appendChild(trigger);
  const panel=document.createElement('aside'); panel.className='studio-agent-panel'; panel.id='studioAgentPanel'; panel.innerHTML=`<header><div><span>AGENT BRAIN</span><strong>Studio Agent</strong><small>${esc(cfg.trackTitle||'Project')}</small></div><button type="button" data-agent-close>×</button></header><div class="studio-agent-history" data-agent-history></div><form data-agent-form><textarea rows="2" maxlength="2000" placeholder="Mix, edit, play, save…"></textarea><button type="submit">Send</button></form>`; document.body.appendChild(panel);
  const history=panel.querySelector('[data-agent-history]'), form=panel.querySelector('[data-agent-form]'), input=form.querySelector('textarea');
  panel.hidden=true;
  async function api(payload){ const r=await fetch(cfg.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({csrf_token:cfg.csrf,track_id:Number(cfg.trackId),session_id:sessionId,...payload})}); const d=await r.json().catch(()=>({ok:false,error:'Invalid Studio Agent response.'})); if(!r.ok||!d.ok)throw new Error(d.error||'Studio Agent request failed.'); if(d.session_id){sessionId=Number(d.session_id);localStorage.setItem(key,String(sessionId));}return d; }
  function line(role,text,status=''){ const el=document.createElement('article'); el.className=`studio-agent-line ${role}`; el.innerHTML=`<small>${role==='user'?'You':'Stonefellow'}${status?` · ${esc(status)}`:''}</small><div>${esc(text)}</div>`; history.appendChild(el); history.scrollTop=history.scrollHeight; return el; }
  function stemRow(id){ return document.querySelector(`.daw-track-row[data-stem-id="${Number(id)}"]`)||document.querySelector(`[data-stem-channel="${Number(id)}"]`)?.closest('[data-stem-channel]'); }
  function isOn(button){ return !!button && (button.classList.contains('active')||button.getAttribute('aria-pressed')==='true'); }
  function clickState(button,want){ if(!button)return false; if(isOn(button)!==want)button.click(); return true; }
  function nativePluginType(value){ const v=String(value||'').toLowerCase(); if(v.includes('compress'))return 'compressor'; if(v.includes('delay'))return 'delay'; if(v.includes('reverb')||v==='verb')return 'reverb'; if(v.includes('limit'))return 'limiter'; if(v.includes('eq'))return 'eq5'; return ''; }
  function setRange(row,selector,value,stemId=0){ const id=Number(stemId||row?.dataset?.stemId||0); const mixer=id>0?document.querySelector(`[data-mixer-stem="${id}"]`):null; const el=mixer?.querySelector(selector)||row?.querySelector(selector); if(!el)return false; el.value=String(value); el.dispatchEvent(new Event('input',{bubbles:true})); el.dispatchEvent(new Event('change',{bubbles:true})); return true; }
  async function execute(command){ const type=String(command.type||''); let ok=true, result='Done'; const row=command.stem_id?stemRow(command.stem_id):null;
    if(command.requires_confirmation && !window.confirm(`Stonefellow Agent wants to ${type}. Continue?`))return {ok:false,status:'cancelled',result:'User cancelled'};
    switch(type){
      case 'play': document.getElementById('stemPlayButton')?.click(); break;
      case 'pause': { const b=document.getElementById('stemPlayButton'); if(b && (b.classList.contains('active')||b.getAttribute('aria-label')==='Pause'))b.click(); else result='Playback already paused'; break; }
      case 'save': document.getElementById('studioSaveButton')?.click(); break;
      case 'save_as': document.getElementById('studioSaveAsButton')?.click(); break;
      case 'tempo': { const e=document.getElementById('sessionTempo'); if(!e){ok=false;break;} e.value=String(command.value); e.dispatchEvent(new Event('input',{bubbles:true})); e.dispatchEvent(new Event('change',{bubbles:true})); break; }
      case 'reset_tempo': document.getElementById('resetSessionTempo')?.click(); break;
      case 'library': (document.querySelector('[data-open-track-library]')||document.getElementById('openTrackLibrary'))?.click(); break;
      case 'select': row?.querySelector('[data-track-select]')?.click(); break;
      case 'inspector': (row?.querySelector('[data-open-track-inspector]')||document.querySelector(`[data-open-track-inspector="${Number(command.stem_id)}"]`))?.click(); break;
      case 'mute': ok=clickState(row?.querySelector('[data-stem-mute]'),true); break;
      case 'unmute': ok=clickState(row?.querySelector('[data-stem-mute]'),false); break;
      case 'solo': ok=clickState(row?.querySelector('[data-stem-solo]'),true); break;
      case 'unsolo': ok=clickState(row?.querySelector('[data-stem-solo]'),false); break;
      case 'volume': ok=setRange(row,'[data-stem-volume]',Number(command.value),command.stem_id); break;
      case 'pan': ok=setRange(row,'[data-stem-pan]',Number(command.value),command.stem_id); break;
      case 'plugin_picker': { const b=document.querySelector(`[data-add-track-plugin="${Number(command.stem_id)}"]`); if(!b){ok=false;break;} b.click(); const mapped=nativePluginType(command.plugin); if(mapped){window.setTimeout(()=>{const option=document.querySelector(`[data-plugin-type="${mapped}"]`); option?.click();},90); result=`Added ${command.plugin} to the selected track`; }else{result='Opened plugin picker';} break; }
      case 'automation': { const b=document.querySelector(`[data-automation-toggle="${Number(command.stem_id)}"]`); if(b)b.click(); else ok=false; break; }
      case 'arm': { const b=row?.querySelector('[data-sidebar-track-arm],[data-track-arm]')||document.querySelector(`[data-sidebar-track-arm="${Number(command.stem_id)}"]`); if(b)b.click(); else ok=false; break; }
      case 'monitor': document.getElementById('studioMonitorButton')?.click(); break;
      case 'record': document.getElementById('studioRecordButton')?.click(); break;
      default: ok=false; result=`Unsupported action: ${type}`;
    }
    if(!ok && result==='Done')result=`Could not find the Studio control for ${type}`;
    return {ok,status:ok?'success':'failed',result};
  }
  async function load(){ try{const d=await api({action:'history'}); history.innerHTML=''; (d.history||[]).forEach(h=>line(h.role==='user'?'user':'assistant',h.message_text||'',h.status||''));}catch(e){line('assistant',e.message,'error');} }
  trigger.addEventListener('click',()=>{panel.hidden=!panel.hidden;if(!panel.hidden){load();setTimeout(()=>input.focus(),20);}}); panel.querySelector('[data-agent-close]').addEventListener('click',()=>panel.hidden=true);
  form.addEventListener('submit',async e=>{e.preventDefault(); if(busy||!input.value.trim())return; const msg=input.value.trim(); input.value=''; line('user',msg); busy=true; try{const d=await api({action:'send',message:msg}); const reply=line('assistant',d.answer||''); const results=[]; for(const command of d.commands||[]){results.push(await execute(command));} const failed=results.some(r=>r.status==='failed'); const cancelled=results.some(r=>r.status==='cancelled'); const status=failed?'failed':cancelled?'cancelled':'success'; const result=results.map(r=>r.result).join('; ')||'No UI command required'; reply.querySelector('small').textContent+=` · ${status}`; await api({action:'result',history_id:Number(d.history_id||0),status,result}); }catch(err){line('assistant',err.message,'error');}finally{busy=false;input.focus();}});
})();
