(() => {
  'use strict';
  // Serialized into the trusted iframe. This function has no external closures.
  function createHost(root,{send,dispatch,select,pending,healthy,ready,mode}) {
    const completed=new Map();let recorded=[],recording=mode==='record',running=false,saves=0,savedState={},lastChange=Date.now();
    const fail=(reason,step=0)=>Object.assign(new Error(reason),{reason,step});
    const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
    const text=(s,max,empty=false)=>typeof s==='string'&&(empty||s.trim())&&new TextEncoder().encode(s).length<=max&&!/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test(s);
    function validate(check){
      if(!check||!text(check.name,80)||!Array.isArray(check.steps)||!check.steps.length||check.steps.length>12||typeof check.persist!=='boolean')throw fail('render');
      for(const step of check.steps){
        if(!['click','fill','check'].includes(step?.action)||!text(step.selector,200)
            ||(step.action==='fill'&&!text(step.value,300,true))||(step.action==='check'&&typeof step.value!=='boolean'))throw fail('render');
      }
      const e=check.expect;
      if(!e||!['text','value','checked'].includes(e.kind)||!text(e.selector,200)
          ||(e.kind==='checked'?typeof e.value!=='boolean':!text(e.value,300,e.kind==='value'))
          ||(e.kind==='text'&&!['contains','equals','absent'].includes(e.match)))throw fail('render');
      return check;
    }
    function target(selector,absent=false){
      let nodes;try{nodes=selector==='@app'?[root]:select(selector);}catch{throw fail('target_missing');}
      if(!nodes.length&&absent)return null;
      if(nodes.length!==1)throw fail('target_missing');
      return nodes[0];
    }
    function visible(el){
      if(!el||!root.contains(el)&&el!==root||el.closest('[hidden]'))return false;
      const style=getComputedStyle(el);return style.display!=='none'&&style.visibility!=='hidden'&&Boolean(el.getClientRects().length);
    }
    function selector(el){
      if(el.id){const id='#'+CSS.escape(el.id);if(id.length<=200&&root.querySelectorAll(id).length===1)return id;}
      const parts=[];let node=el;
      while(node&&node!==root&&parts.length<8){
        const siblings=[...node.parentElement.children].filter(s=>s.localName===node.localName);
        parts.unshift(node.localName+':nth-of-type('+(siblings.indexOf(node)+1)+')');node=node.parentElement;
      }
      const value=':scope > '+parts.join(' > ');return node===root&&value.length<=200?value:null;
    }
    function record(el,type){
      if(!recording||running||!healthy()||!ready())return;
      let step;
      if(type==='click'){
        el=el.closest('button,summary,[role="button"]')||el;
        if(el.matches('input,select,textarea,label')||!root.contains(el))return;
        step={action:'click',selector:selector(el)};
      }else if(el.matches('input[type="checkbox"],input[type="radio"]')){
        if(type!=='change')return;
        step={action:'check',selector:selector(el),value:el.checked};
      }else if(el.matches('input:not([type="password"]),select,textarea')){
        if(!text(el.value,300,true))return;
        step={action:'fill',selector:selector(el),value:el.value};
      }else return;
      if(!step.selector)return;
      const last=recorded.at(-1);
      if(step.action==='fill'&&last?.action==='fill'&&last.selector===step.selector)recorded[recorded.length-1]=step;
      else if(recorded.length<12)recorded.push(step);
      else{recording=false;send('workflow_record_limit');return;}
      send('workflow_record',{steps:structuredClone(recorded)});
    }
    async function settle(activations){
      const began=Date.now();
      while(healthy()&&Date.now()-began<2500){
        const done=activations.every(a=>a.ids.every(id=>completed.get(a.gesture)?.has(id)));
        if(done&&!pending.size&&Date.now()-lastChange>=160)return;
        await delay(20);
      }
      throw fail(healthy()?'timeout':'source_runtime');
    }
    async function step(action,index){
      const el=target(action.selector);
      if(!visible(el)||el.disabled||el.readOnly&&action.action!=='click')throw fail('wrong_control',index);
      const activations=[];
      if(action.action==='fill'){
        if(!el.matches('input:not([type="password"]):not([type="checkbox"]):not([type="radio"]),select,textarea'))throw fail('wrong_control',index);
        el.value=action.value;if(el.value!==action.value)throw fail('wrong_control',index);
        activations.push(dispatch(el,'input'),dispatch(el,'change'));
      }else if(action.action==='check'){
        if(!el.matches('input[type="checkbox"],input[type="radio"]'))throw fail('wrong_control',index);
        el.checked=action.value;activations.push(dispatch(el,'click'),dispatch(el,'input'),dispatch(el,'change'));
      }else{
        el.click();const activation=dispatch(el,'click');
        if(!activation.ids.length&&!el.matches('summary'))throw fail('handler_missing',index);
        activations.push(activation);
      }
      lastChange=Date.now();await settle(activations);
    }
    function expectation(expect){
      const el=target(expect.selector,expect.kind==='text'&&expect.match==='absent');
      if(expect.kind==='text'){
        const value=visible(el)?el.innerText:'';
        return expect.match==='absent'?!value.includes(expect.value):expect.match==='equals'?value.trim()===expect.value:value.includes(expect.value);
      }
      if(!visible(el))return false;
      if(expect.kind==='checked')return el.matches('input[type="checkbox"],input[type="radio"]')&&el.checked===expect.value;
      return el.matches('input,select,textarea,output')&&el.value===expect.value;
    }
    async function run({run_id,check,verify_only=false}){
      if(running||recording||!healthy()||!ready()||!text(run_id,80))return;
      running=true;let index=0;
      try{
        validate(check);
        if(!verify_only){for(const action of check.steps){index++;send('workflow_progress',{run_id,step:index});await step(action,index);}}
        index=check.steps.length+1;
        if(!expectation(check.expect))throw fail('expectation_failed',index);
        if(check.persist&&!verify_only&&!saves)throw fail('save_missing',index);
        send('workflow_done',{run_id,passed:true,step:0,reason:'none',sample_state:check.persist&&!verify_only?structuredClone(savedState):null});
      }catch(error){send('workflow_done',{run_id,passed:false,step:error.step||index,reason:error.reason||'render'});}
      finally{running=false;completed.clear();}
    }
    return {
      record,run,
      done(gesture,id){if(!completed.has(gesture))completed.set(gesture,new Set());completed.get(gesture).add(id);if(completed.size>50)completed.delete(completed.keys().next().value);},
      changed(){lastChange=Date.now();},
      saved(value,error){if(!error){saves++;savedState=structuredClone(value);lastChange=Date.now();}},
      stopRecord(){recording=false;return structuredClone(recorded);},
    };
  }
  window.VP3CanvasWorkflowRuntimeV1=Object.freeze({createHost});
})();
