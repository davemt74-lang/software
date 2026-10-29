(() => {
  'use strict';
  const boot=async()=>{
    const adapter=window.VP3_ORB_ADAPTER;
    if(!adapter||window.VP3AgentWelcome||new URLSearchParams(location.search).get('orb_embed')==='1')return;
    let preference=window.VP3_AGENT_PREFERENCE_STATE||null,permission=null,busy=false,disposed=false,generation=0,attempted=false;
    const key=`vp3:agent-welcome:${adapter.ownerKey}`,cooldown=30*60*1000;
    const notice=document.createElement('aside');notice.className='vp3-agent-setup-notice';notice.hidden=true;
    notice.innerHTML='<span role="status"></span><button type="button"></button>';
    document.body.append(notice);
    const text=notice.querySelector('span'),button=notice.querySelector('button');let action=null;
    const show=(message,label,fn)=>{text.textContent=message;button.textContent=label;action=fn;notice.hidden=false;};
    const recentlyGreeted=()=>{try{const at=Number(localStorage.getItem(key)||0);return at>0&&Date.now()-at<cooldown;}catch(_){return false;}};
    const markGreeted=()=>{try{localStorage.setItem(key,String(Date.now()));}catch(_){}notice.hidden=true;};
    function setup(){
      const menu=document.getElementById('chatProfileDropdown');
      if(menu){if(menu.hidden)document.getElementById('chatProfileButton')?.click();menu.querySelector('[data-vp3-agent-selector]')?.focus();}
      else adapter.openAgentSettings?.();
    }
    async function greet(manual=false){
      if(disposed||busy||!preference?.agent||!preference.enabled||document.hidden||recentlyGreeted())return;
      if(adapter.busy?.())return;
      if(!manual&&permission?.state!=='granted')return;
      const token=generation;busy=true;attempted=true;
      const current=()=>!disposed&&token===generation&&preference?.enabled===true&&!document.hidden;
      const run=async()=>{
        if(recentlyGreeted()||!current())return;
        const message=`Hi, I’m ${String(preference.agent.name||'your agent').slice(0,120)}. I’m here when you need me.`;
        window.VP3AgentOrb?.bubble(message);
        const spoken=await adapter.greet(message,current);
        if(!current())return;
        if(spoken===true)markGreeted();
        else show('Your agent is ready. Tap to allow greeting playback.','Hear greeting',()=>greet(true));
      };
      try{
        if(navigator.locks?.request)await navigator.locks.request(key,{ifAvailable:true},lock=>lock?run():undefined);
        else await run();
      }catch(_){if(current())show('Your agent is ready.','Hear greeting',()=>greet(true));}
      finally{busy=false;}
    }
    async function allowMicrophone(){
      try{
        const stream=await navigator.mediaDevices.getUserMedia({audio:true});
        stream.getTracks().forEach(track=>track.stop());
        if(disposed)return;
        if(permission?.state!=='granted')permission={state:'granted'};
        attempted=false;await greet(true);
      }catch(_){show('Microphone access is off. You can enable it in browser site settings.','Agent settings',setup);}
    }
    function refresh(){
      if(!preference||disposed)return;
      if(!preference.agent){show('Choose your default agent to finish setup.','Choose agent',setup);return;}
      if(!preference.enabled||!preference.allowed){notice.hidden=true;return;}
      if(recentlyGreeted()){notice.hidden=true;return;}
      if(permission?.state!=='granted'){show('Your agent is ready. Enable microphone access for your greeting.','Enable microphone',allowMicrophone);return;}
      if(!attempted)void greet();
    }
    button.addEventListener('click',()=>void action?.());
    const update=event=>{generation++;preference=event.detail;attempted=false;refresh();};
    const voice=event=>{generation++;if(preference)preference={...preference,enabled:event.detail?.enabled!==false};attempted=false;refresh();};
    const stop=event=>{if(event.target.closest?.('[data-orb-stop]')){generation++;attempted=true;notice.hidden=true;}};
    window.addEventListener('vp3:agent-preferences',update);window.addEventListener('stonefellow:agent-voice',voice);
    document.addEventListener('click',stop,true);
    try{permission=await navigator.permissions.query({name:'microphone'});permission.onchange=()=>{generation++;attempted=false;refresh();};}catch(_){}
    if(!preference&&adapter.welcomePreferences)try{preference=await adapter.welcomePreferences();}catch(_){}
    const timer=setInterval(refresh,2000);
    // Canonical voice introductions count as this session's welcome as well.
    window.addEventListener('vp3:agent-welcomed',markGreeted);
    document.addEventListener('visibilitychange',refresh);
    window.VP3AgentWelcome={refresh};refresh();
    window.addEventListener('pagehide',()=>{disposed=true;generation++;clearInterval(timer);if(permission)permission.onchange=null;window.removeEventListener('vp3:agent-preferences',update);window.removeEventListener('stonefellow:agent-voice',voice);document.removeEventListener('visibilitychange',refresh);document.removeEventListener('click',stop,true);notice.remove();},{once:true});
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else void boot();
})();
