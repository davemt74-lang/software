(() => {
  'use strict';
  const cfg=window.STONEFELLOW_ACTIVITY||{};if(!cfg.endpoint||!cfg.csrf)return;
  let lastMeaningful=Date.now(),hiddenAt=document.hidden?Date.now():0,lastState='',taskTitle=String(cfg.taskTitle||''),taskKey=String(cfg.taskKey||'');
  let timer=0,sending=false;
  const linkedConversation=()=>Number(window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()||cfg.conversationId||new URLSearchParams(location.search).get('conversation_id')||0);
  const context=()=>({track_id:Number(cfg.trackId||0),project_id:Number(cfg.projectId||0),conversation_id:linkedConversation(),task_title:taskTitle,task_kind:cfg.surface||'chat',task_key:taskKey,path:location.pathname+location.search,visible:!document.hidden});
  const activeMedia=()=>{if(!['stem','video'].includes(String(cfg.surface||'')))return false;return [...document.querySelectorAll('audio,video')].some(el=>!el.paused&&!el.ended&&el.readyState>1&&!el.closest('.chat-media-studio'));};
  const recording=()=>!!document.querySelector('.recording,[data-recording="true"],[data-live-recording="true"]');
  function classify(){const now=Date.now(),elapsed=(now-lastMeaningful)/1000;if(recording()||activeMedia())return 'working';if(document.hidden){const hidden=(now-(hiddenAt||now))/1000;return hidden>120?'idle':'paused';}if(elapsed<=120)return 'working';if(elapsed<=480)return 'paused';return 'idle';}
  async function heartbeat(reason='timer',force=false){const state=classify();if(sending)return;if(!force&&state===lastState&&reason!=='timer')return;sending=true;try{const r=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'heartbeat',csrf_token:cfg.csrf,surface:cfg.surface||'chat',state,reason,context:context()})});const d=await r.json().catch(()=>null);if(r.ok&&d?.ok){if(lastState&&lastState!==state)document.dispatchEvent(new CustomEvent('stonefellow:proactive-refresh',{detail:{activity:d.activity}}));lastState=state;document.documentElement.dataset.agentActivity=state;}}catch(e){}finally{sending=false;}}
  function meaningful(reason='interaction'){lastMeaningful=Date.now();if(document.hidden)return;const prior=lastState;lastState='';heartbeat(reason,true);if(prior==='idle'||prior==='paused')document.dispatchEvent(new CustomEvent('stonefellow:proactive-refresh'));}
  ['pointerdown','keydown','input','change','submit','dragstart','drop'].forEach(type=>document.addEventListener(type,e=>{if(type==='keydown'&&['Shift','Control','Alt','Meta'].includes(e.key))return;meaningful(type);},{capture:true,passive:type==='pointerdown'}));
  document.addEventListener('stonefellow:task-start',e=>{taskTitle=String(e.detail?.title||taskTitle);taskKey=String(e.detail?.key||taskKey);meaningful('task_start');});
  document.addEventListener('visibilitychange',()=>{hiddenAt=document.hidden?Date.now():0;heartbeat(document.hidden?'hidden':'visible',true);});
  window.addEventListener('pagehide',()=>{lastMeaningful=0;const form=new FormData();form.append('action','heartbeat');form.append('csrf_token',String(cfg.csrf));form.append('surface',String(cfg.surface||'chat'));form.append('state','idle');form.append('reason','pagehide');form.append('context',JSON.stringify(context()));try{navigator.sendBeacon?.(cfg.endpoint,form);}catch(e){}});
  window.StonefellowAgentActivity={markTask:(title,key='')=>{taskTitle=String(title||taskTitle);taskKey=String(key||taskKey);meaningful('task_mark');},snapshot:()=>({state:classify(),taskTitle,taskKey})};
  timer=setInterval(()=>heartbeat('timer',true),30000);setTimeout(()=>heartbeat('load',true),250);
})();
