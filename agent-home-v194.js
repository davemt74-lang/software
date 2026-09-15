(function(){
  'use strict';
  var config=window.VP3_AGENT_HOME||{};
  var card=document.querySelector('[data-agent-home-homeserver]');
  if(!card||!config.homeserverEndpoint)return;
  var label=card.querySelector('[data-home-status-label]');
  var detail=card.querySelector('[data-home-status-detail]');
  var capabilities=card.querySelector('[data-home-capabilities]');
  var controller=typeof AbortController!=='undefined'?new AbortController():null;
  var timeout=controller?window.setTimeout(function(){controller.abort();},7000):0;
  fetch(config.homeserverEndpoint,{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller?controller.signal:undefined})
    .then(function(response){if(!response.ok)throw new Error('status');return response.json();})
    .then(function(payload){
      var status=payload&&payload.status?payload.status:null;
      if(!status)return;
      var state=String(status.state||((status.connected)?'connected':(status.paired?'paired':'unpaired')));
      card.dataset.state=state;
      if(label)label.textContent=status.connected?'Connected':(status.paired?'Paired · offline':'Not paired');
      if(detail){
        var parts=[];
        if(status.installed_version)parts.push('Version '+status.installed_version);
        if(status.update_available)parts.push('Update available');
        if(!parts.length)parts.push(status.connected?'HomeServer is available to this VP3 account.':'Connect HomeServer to add private models, Knowledge, tools and capabilities.');
        detail.textContent=parts.join(' · ');
      }
      if(capabilities){
        var list=[];
        if(Array.isArray(status.capabilities))list=status.capabilities;
        else if(status.capabilities&&typeof status.capabilities==='object')list=Object.keys(status.capabilities).filter(function(key){return status.capabilities[key];});
        var features=Array.isArray(status.features)?status.features:[];
        var count=Math.max(list.length,features.length);
        capabilities.textContent=status.connected?(count?count+' reported capabilities':'Connected; capability registry available from HomeServer.'):'Capabilities load from HomeServer when connected.';
      }
    })
    .catch(function(){/* Cached server-rendered state remains authoritative fallback. */})
    .finally(function(){if(timeout)window.clearTimeout(timeout);});
  window.addEventListener('pagehide',function(){if(controller)controller.abort();},{once:true});
})();
