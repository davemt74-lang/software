(() => {
  'use strict';
  window.VP3PhoneLocationCheckinsV1=({request})=>{
    const root=document.getElementById('phone-checkins');if(!root)return null;
    const get=name=>document.getElementById('phone-checkin-'+name),status=get('status'),start=get('start'),stop=get('stop'),learn=get('learn'),patterns=get('patterns'),recent=get('recent');
    const duration=seconds=>{const minutes=Math.floor(Math.max(0,seconds)/60);return Math.floor(minutes/60)+'h '+minutes%60+'m';};
    let device=null,available=false,busy=false,signature='',epoch=0,started=null,pendingId=null,serverOffset=0;
    const randomId=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');
    const make=(tag,text)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;return el;};
    function clock(){
      const active=device?.checkins?.active,now=Math.floor(Date.now()/1000)+serverOffset;
      if(active){
        const fresh=device.location_state==='live'&&Number.isSafeInteger(device.fix_at)&&now-device.fix_at<=60;
        status.textContent='Checked in'+(active.label?' at '+active.label:'')+' · '+duration(now-active.started_epoch)+(fresh?'':' · Location updates paused; departure is unconfirmed.');
      }
    }
    async function act(action,fields){
      if(!device||!available||busy||document.hidden)return;
      const d=device,version=epoch;busy=true;render();
      try{
        const result=await request(action,{device_id:d.id,generation:d.generation,confirmed:true,...fields});
        if(version!==epoch||result?.device?.id!==d.id)return;
        device=result.device;pendingId=null;status.textContent=action==='location_checkout'?'Checked out.':action==='checkin_forget'?'Check-in learning forgotten.':'Saved.';signature='';
      }catch{if(version===epoch)status.textContent='Could not confirm this change. Refresh your phone connection before retrying.';}
      finally{if(version===epoch){busy=false;render();}}
    }
    function render(){
      const data=device?.checkins,active=data?.active,now=Math.floor(Date.now()/1000)+serverOffset;
      root.hidden=device?.status!=='approved';
      const fresh=device?.allow_location&&device.location_state==='live'&&Number.isSafeInteger(device.fix_at)&&now-device.fix_at<=60&&device.accuracy<=50;
      start.hidden=!!active;stop.hidden=!active;
      start.disabled=!available||busy||data?.ready!==true||!fresh;
      stop.disabled=!available||busy||!active;
      learn.disabled=!available||busy||!!active;
      get('forget').disabled=!available||busy||data?.ready!==true;
      if(active)clock();
      else if(data?.ready!==true)status.textContent='Run Database Upgrade to enable location check-ins.';
      else if(!started)status.textContent=fresh?'Ready to check in at your current location.':'Start location sharing and wait for an accurate location to check in.';
      const next=JSON.stringify([data?.patterns,data?.recent,busy,available]);if(signature===next)return;signature=next;
      patterns.replaceChildren();recent.replaceChildren();
      for(const p of data?.patterns||[]){
        const card=make('section');card.append(make('p',p.label?p.label+' · '+p.visits+' check-ins on '+p.distinct_days+' days':p.question));
        const form=make('form'),label=make('label','Place name'),input=make('input'),save=make('button','Remember place name');input.maxLength=64;input.required=true;input.value=p.label||'';input.setAttribute('aria-label','Place name');save.type='submit';input.disabled=save.disabled=busy||!available;
        label.append(input);form.append(label,save);form.addEventListener('submit',event=>{event.preventDefault();if(event.isTrusted&&input.value.trim())void act('checkin_label',{visit_id:p.visit_id,version:p.version,label:input.value.trim()});});card.append(form);patterns.append(card);
      }
      for(const r of data?.recent||[]){
        const text=(r.label||'Location visit')+' · '+duration(r.duration_seconds)+' · '+new Date(r.started_epoch*1000).toLocaleString()+(r.reason==='left_area'?' · Automatically checked out after leaving.':r.reason==='sharing_stopped'?' · Ended when location sharing stopped.':' · Checked out.');
        recent.append(make('li',text));
      }
    }
    start.addEventListener('click',event=>{if(event.isTrusted){started=true;pendingId=pendingId||randomId();void act('location_checkin',{visit_id:pendingId,learn:learn.checked});}});
    stop.addEventListener('click',event=>{const active=device?.checkins?.active;if(event.isTrusted&&active)void act('location_checkout',{visit_id:active.visit_id,version:active.version});});
    get('forget').addEventListener('click',event=>{if(event.isTrusted){learn.checked=false;void act('checkin_forget',{});}});
    const timer=setInterval(()=>{if(!document.hidden)clock();},1000);window.addEventListener('pagehide',()=>clearInterval(timer),{once:true});
    function bind(d,ready){
      if(device?.id!==d?.id||device?.generation!==d?.generation){epoch++;busy=false;signature='';pendingId=null;started=null;learn.checked=false;}
      device=d;available=ready;
      if(Number.isSafeInteger(d?.checkins?.server_epoch))serverOffset=d.checkins.server_epoch-Math.floor(Date.now()/1000);
      render();
    }
    return {bind};
  };
})();
