import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const flush=async()=>{for(let n=0;n<8;n++)await new Promise(resolve=>setImmediate(resolve));};
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
function mount(kind){
  const requests=[],events=new Map(),timers=[],shells=new Map(),notice={textContent:'',className:''};
  let failReads=true;
  const host={
    querySelector(selector){return shells.get(selector)??null;},
    prepend(element){shells.set(element.dataset.radarGatewayShell?'[data-radar-gateway-shell]':'[data-radar-sites-shell]',element);},
    append(element){this.prepend(element);},
    addEventListener(type,callback){events.set(type,callback);},
  };
  const cfg={csrf:'fixture-csrf',radarEndpoint:'/api/agent-radar.php',radarPolicyEndpoint:'/api/agent-radar-policy.php',
    radarSitesEndpoint:'/api/agent-radar-sites.php',radarScriptUrl:'/vp3-radar.js',analyticsScriptUrl:'/vp3-analytics.js'};
  const context={console,URL,Date,Map,Set,setTimeout,clearTimeout,
    window:{PROFILE_AGENT_PORTAL:cfg,location:{origin:'https://example.test'},addEventListener(){},
      VP3ProfileAgentPortalHttpV1:{async request(url,options,timeout){
        requests.push({url,options,timeout});
        if(failReads||options.method==='POST')throw Object.assign(new Error('Request deadline reached.'),{code:'PROFILE_REQUEST_TIMEOUT'});
        return url===cfg.radarEndpoint?{ok:true,radar:{ready:true,contacts:[]}}:
          url===cfg.radarSitesEndpoint?{ok:true,state:{sites:[],can_add:true,limit:null}}:{ok:true,policies:{}};
      }},
    },
    document:{visibilityState:'visible',getElementById(id){return id==='profileAgentRadar'?host:id==='profileAgentNotice'?notice:null;},
      createElement(){return {dataset:{},innerHTML:'',className:'',insertAdjacentElement(_where,element){host.prepend(element);}};}},
    MutationObserver:class{observe(){}disconnect(){}},
    FormData:class{constructor(form){this.fields=form.fields;}get(key){return this.fields[key];}},
    setInterval(callback){timers.push(callback);return timers.length;},clearInterval(){},
    fetch(){throw new Error('Radar clients must use the shared bounded transport.');},
  };
  vm.createContext(context);vm.runInContext(fs.readFileSync(new URL(`../profile-agent-radar-${kind}.js`,import.meta.url),'utf8'),context);
  return {requests,events,timers,shells,notice,recover(){failReads=false;}};
}

for(const kind of ['gateway','sites']){
  const fixture=mount(kind);await flush();
  check(fixture.requests.length>0&&fixture.requests.every(request=>request.timeout===15000&&request.options.cache==='no-store'),`${kind}: reads use the shared bounded private transport`);
  check(fixture.notice.textContent==='Request deadline reached.',`${kind}: read timeout is visible instead of silently hanging`);
  fixture.recover();fixture.timers[0]();await flush();
  check([...fixture.shells.values()].some(shell=>shell.innerHTML.includes(kind==='gateway'?'Agent Gateway':'Connected Sites')),`${kind}: a later poll recovers after the timeout clears busy state`);
  const before=fixture.requests.length,button={disabled:false};
  if(kind==='gateway'){
    button.dataset={contactId:'7',gatewayAction:'monitor'};
    await fixture.events.get('click')({target:{closest:selector=>selector==='[data-gateway-action]'?button:null}});
  }else{
    const form={fields:{domain:'example.test',label:'Example'},querySelector:()=>button};
    await fixture.events.get('submit')({preventDefault(){},target:{closest:selector=>selector==='[data-radar-site-form]'?form:null}});
  }
  await flush();const mutation=fixture.requests.at(-1),body=JSON.parse(mutation.options.body);
  check(mutation.timeout===45000&&mutation.options.method==='POST'&&body.csrf_token==='fixture-csrf',`${kind}: mutations retain POST and CSRF with a separate bounded deadline`);
  check(fixture.notice.textContent.includes('check whether the change was saved')&&button.disabled===false,`${kind}: uncertain write asks for a state refresh and releases its control`);
  check(fixture.requests.length===before+1,`${kind}: a timed-out write is never automatically repeated`);
}
console.log(`PROFILE_RADAR_CLIENTS_V1=PASS (${checks} checks; production clients)`);
