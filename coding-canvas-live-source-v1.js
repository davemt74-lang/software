(() => {
  'use strict';
  // Read a prefix of the flat Canvas JSON contract. Never evaluate incomplete code.
  function sourcePrefix(raw) {
    const out={html:'',css:'',js:''};
    let i=raw.indexOf('{');if(i<0)return out;i++;
    const space=()=>{while(/\s/.test(raw[i]||'')&&i<raw.length)i++;};
    function string(){
      if(raw[i++]!=='"')return null;
      let value='';
      while(i<raw.length){
        const c=raw[i++];if(c==='"')return {value,done:true};
        if(c!=='\\'){value+=c;continue;}
        if(i>=raw.length)break;
        const escape=raw[i++],simple={'"':'"','\\':'\\','/':'/','b':'\b','f':'\f','n':'\n','r':'\r','t':'\t'};
        if(Object.hasOwn(simple,escape))value+=simple[escape];
        else if(escape==='u'){
          const digits=raw.slice(i,i+4);if(digits.length<4)break;
          if(!/^[a-fA-F0-9]{4}$/.test(digits))return null;
          value+=String.fromCharCode(parseInt(digits,16));i+=4;
        }else return null;
      }
      return {value,done:false};
    }
    for(let n=0;n<8&&i<raw.length;n++){
      space();const key=string();if(!key?.done)break;
      space();if(raw[i++]!==':')break;space();
      if(raw[i]!=='"'){
        // Optional workflow definitions may precede a source field in the same object.
        if(!['[','{'].includes(raw[i]))break;
        let depth=0,complete=false;
        while(i<raw.length){
          if(raw[i]==='"'){if(!string()?.done)break;continue;}
          const c=raw[i++];if(c==='['||c==='{')depth++;
          if(c===']'||c==='}')if(--depth===0){complete=true;break;}
        }
        if(!complete)break;space();if(raw[i++]!==',')break;continue;
      }
      const value=string();if(!value)break;
      if(Object.hasOwn(out,key.value))out[key.value]=value.value;
      if(!value.done)break;space();if(raw[i++]!==',')break;
    }
    return out;
  }
  function mount({root,fetchSource,recoverSource,onRecovered,onError}) {
    const make=(tag,text='')=>{const el=document.createElement(tag);el.textContent=text;return el;};
    const details=make('details');details.open=true;details.className='vp3-coding-live-source';
    const heading=make('summary','Live generated code');
    const status=make('p');status.setAttribute('role','status');
    const explanation=make('p','Received code appears here as it is written. It is untested. Your working version stays available below.');
    const language=make('select');language.setAttribute('aria-label','Live code language');
    for(const [value,label] of [['html','HTML'],['css','CSS'],['js','JavaScript'],['raw','Received response']]){
      const option=make('option',label);option.value=value;language.append(option);
    }
    const code=make('pre');code.setAttribute('aria-label','Live generated source');code.tabIndex=0;
    const actions=make('div');actions.className='vp3-coding-canvas-controls';
    const refresh=make('button','Refresh received code');refresh.type='button';
    const save=make('button','Save received code as draft');save.type='button';save.hidden=true;
    actions.append(refresh,save);details.append(heading,status,explanation,language,code,actions);root.append(details);root.hidden=true;
    let context=null,snapshot=null,request=null,timer=null,serial=0,blocked=false,saving=false,saveAttempt=null;
    const active=()=>context&&['queued','running','paused'].includes(context.state);
    const same=(key,number)=>context?.key===key&&serial===number;
    function draw(){
      const text=snapshot?.text||'',parts=sourcePrefix(text);
      code.textContent=language.value==='raw'?text:parts[language.value];
      if(!code.textContent)code.textContent=active()?'Waiting for '+(language.value==='raw'?'the response':language.options[language.selectedIndex].text)+'…':'No received code is available for this section.';
      save.hidden=!snapshot?.can_recover&&!saveAttempt?.uncertain;
      save.disabled=saving||context?.blocked===true;
      save.textContent=saveAttempt?.uncertain?'Recover saved draft':'Save received code as draft';
      refresh.disabled=Boolean(request)||saving;
      if(!blocked)status.textContent=(active()?'Receiving code':'Received code')+' · '+new TextEncoder().encode(text).length.toLocaleString()+' bytes'+
        (snapshot?.terminal?' · Provider finished':text?' · Completion not yet confirmed':' · Waiting for the provider');
    }
    function cancel(){clearTimeout(timer);timer=null;request?.abort();request=null;serial++;}
    async function load(){
      if(!context||request||saving||blocked)return;
      const selected=context,number=serial,controller=new AbortController();request=controller;draw();
      try{
        const data=await fetchSource(selected.runId,controller.signal);
        if(!same(selected.key,number))return;
        const value=data.received_source;
        if(value!==null&&(!value||value.run_id!==selected.runId||typeof value.text!=='string'||value.text.length>200000))throw Error('The received code could not be verified.');
        snapshot=value;blocked=false;draw();
      }catch(error){
        if(!same(selected.key,number)||error.name==='AbortError')return;
        blocked=true;status.textContent=error.message||'Received code could not be loaded. Refresh to check the existing run.';
        if([401,403,404,419].includes(error.status)){snapshot=null;code.textContent='';save.hidden=true;}
      }finally{
        if(same(selected.key,number)){
          request=null;refresh.disabled=saving;
          if(active()&&!blocked)timer=setTimeout(()=>void load(),1000);
        }
      }
    }
    language.addEventListener('change',draw);
    refresh.addEventListener('click',()=>{blocked=false;clearTimeout(timer);void load();});
    save.addEventListener('click',async()=>{
      if(!context||saving||context.blocked||(!snapshot?.can_recover&&!saveAttempt?.uncertain))return;
      const selected=context,number=serial;
      saveAttempt??={id:crypto.randomUUID(),uncertain:false};saving=true;clearTimeout(timer);draw();
      try{
        const data=await recoverSource(selected.runId,saveAttempt.id);
        if(!same(selected.key,number))return;
        saveAttempt=null;snapshot.can_recover=false;onRecovered(data);status.textContent='Received code saved as a new draft. Test it before using this version.';
      }catch(error){
        if(!same(selected.key,number))return;
        saveAttempt.uncertain=!error.status;onError(error.message||'The draft save could not be confirmed. Recover it with the same request.');
      }finally{if(same(selected.key,number)){saving=false;draw();}}
    });
    return {update(next){
      if(!next){cancel();context=null;snapshot=null;saveAttempt=null;blocked=false;saving=false;code.textContent='';root.hidden=true;return;}
      const changed=context?.key!==next.key,stateChanged=context?.state!==next.state;
      if(changed){cancel();snapshot=null;saveAttempt=null;blocked=false;saving=false;}
      context=next;root.hidden=false;draw();
      if(changed||(stateChanged&&!request)){clearTimeout(timer);void load();}
    },clear(){this.update(null);},busy:()=>saving};
  }
  window.VP3CanvasLiveSourceV1=Object.freeze({mount,sourcePrefix});
})();
