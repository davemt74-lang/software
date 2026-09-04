(function(root,factory){
  'use strict';
  const api=factory();
  root.StonefellowStemAutomationMixerV211=api;
  if(root.document)api.install(root,root.document);
})(typeof globalThis!=='undefined'?globalThis:window,function(){
  'use strict';

  const BUILD='stem-automation-mixer-v211-20260901';
  const MODES=Object.freeze(['read','touch','latch','write']);
  const CORE_PARAMETERS=Object.freeze(['volume','pan','auxA','auxB']);
  const MAX_POINTS=1200;
  const EPSILON=.0005;

  const clone=value=>JSON.parse(JSON.stringify(value));
  const num=(value,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;
  const clamp=(value,min,max)=>Math.min(max,Math.max(min,num(value,min)));

  function normalizeMode(value){
    const clean=String(value||'read').toLowerCase();
    return MODES.includes(clean)?clean:'read';
  }

  function automationValueAt(points,time,fallback=0){
    const rows=(Array.isArray(points)?points:[])
      .filter(point=>Number.isFinite(Number(point?.t))&&Number.isFinite(Number(point?.v)))
      .slice()
      .sort((a,b)=>Number(a.t)-Number(b.t));
    if(!rows.length)return num(fallback,0);
    const t=Math.max(0,num(time,0));
    if(t<=Number(rows[0].t))return Number(rows[0].v);
    if(t>=Number(rows[rows.length-1].t))return Number(rows[rows.length-1].v);
    for(let index=1;index<rows.length;index++){
      const right=rows[index];
      if(t<=Number(right.t)){
        const left=rows[index-1];
        const span=Math.max(.000001,Number(right.t)-Number(left.t));
        const ratio=(t-Number(left.t))/span;
        return Number(left.v)+(Number(right.v)-Number(left.v))*ratio;
      }
    }
    return num(fallback,0);
  }

  function simplifyAutomationPoints(points,tolerance=.001,minSpacing=.035){
    const rows=(Array.isArray(points)?points:[])
      .filter(point=>Number.isFinite(Number(point?.t))&&Number.isFinite(Number(point?.v)))
      .map(point=>({t:Math.max(0,num(point.t,0)),v:num(point.v,0)}))
      .sort((a,b)=>a.t-b.t);
    if(rows.length<=2)return rows;
    const out=[rows[0]];
    for(let index=1;index<rows.length-1;index++){
      const prev=out[out.length-1];
      const current=rows[index];
      const next=rows[index+1];
      const span=Math.max(.000001,next.t-prev.t);
      const expected=prev.v+(next.v-prev.v)*((current.t-prev.t)/span);
      const closeToLine=Math.abs(expected-current.v)<=Math.max(0,num(tolerance,.001));
      const tooClose=current.t-prev.t<Math.max(.001,num(minSpacing,.035));
      if(closeToLine&&tooClose)continue;
      out.push(current);
    }
    out.push(rows[rows.length-1]);
    return out.slice(-MAX_POINTS);
  }

  function insertAutomationPoint(points,time,value,options={}){
    const tolerance=Math.max(.000001,num(options.tolerance,.001));
    const replaceWindow=Math.max(.001,num(options.replaceWindow,.04));
    const t=Math.max(0,num(time,0));
    const v=num(value,0);
    const rows=(Array.isArray(points)?clone(points):[])
      .filter(point=>Number.isFinite(Number(point?.t))&&Number.isFinite(Number(point?.v)));
    const nearby=rows.find(point=>Math.abs(Number(point.t)-t)<=replaceWindow);
    if(nearby){nearby.t=t;nearby.v=v;}
    else rows.push({t,v});
    rows.sort((a,b)=>Number(a.t)-Number(b.t));
    return simplifyAutomationPoints(rows,tolerance,replaceWindow*.55);
  }

  function copyAutomationRange(automation,start,end,parameters=CORE_PARAMETERS){
    const a=Math.max(0,Math.min(num(start,0),num(end,0)));
    const b=Math.max(a,Math.max(num(start,0),num(end,0)));
    if(!(b>a+EPSILON))return null;
    const payload={duration:b-a,parameters:{}};
    for(const parameter of parameters){
      const points=(automation?.[parameter]||[])
        .filter(point=>Number(point.t)>=a-EPSILON&&Number(point.t)<=b+EPSILON)
        .map(point=>({t:Number(point.t)-a,v:Number(point.v)}));
      if(points.length)payload.parameters[parameter]=points;
    }
    return Object.keys(payload.parameters).length?payload:null;
  }

  function pasteAutomationRange(automation,clipboard,anchor,options={}){
    const next=clone(automation||{});
    if(!clipboard?.parameters)return next;
    const at=Math.max(0,num(anchor,0));
    const duration=Math.max(0,num(clipboard.duration,0));
    for(const [parameter,copied] of Object.entries(clipboard.parameters)){
      const current=Array.isArray(next[parameter])?next[parameter]:[];
      let rows=options.replace===false
        ? current.slice()
        : current.filter(point=>Number(point.t)<at-EPSILON||Number(point.t)>at+duration+EPSILON);
      for(const point of copied||[]){
        rows=insertAutomationPoint(rows,at+Math.max(0,num(point.t,0)),num(point.v,0),{replaceWindow:.02,tolerance:.0008});
      }
      next[parameter]=rows;
    }
    return next;
  }

  function shiftAutomationRange(points,start,end,delta){
    const a=Math.max(0,Math.min(num(start,0),num(end,0)));
    const b=Math.max(a,Math.max(num(start,0),num(end,0)));
    const d=num(delta,0);
    return (Array.isArray(points)?points:[])
      .map(point=>{
        const copy={t:Number(point.t),v:Number(point.v)};
        if(copy.t>=a-EPSILON&&copy.t<=b+EPSILON)copy.t=Math.max(0,copy.t+d);
        return copy;
      })
      .sort((x,y)=>x.t-y.t);
  }

  function parsePluginTarget(value){
    const match=String(value||'').match(/^plugin:(\d+):([a-z0-9_-]+):([a-zA-Z0-9_-]+)$/);
    return match?{index:Number(match[1]),type:match[2],param:match[3]}:null;
  }

  function pluginParamSpec(type,param){
    const key=`${String(type||'')}:${String(param||'')}`;
    const map={
      'eq5:f1':[40,180,'Hz'],'eq5:f2':[120,700,'Hz'],'eq5:f3':[500,2500,'Hz'],'eq5:f4':[1800,8000,'Hz'],'eq5:f5':[6000,18000,'Hz'],
      'eq5:b1':[-18,18,'dB'],'eq5:b2':[-18,18,'dB'],'eq5:b3':[-18,18,'dB'],'eq5:b4':[-18,18,'dB'],'eq5:b5':[-18,18,'dB'],
      'delay:time':[.02,1.5,'s'],'delay:feedback':[0,.92,'%'],'delay:mix':[0,1,'%'],
      'compressor:threshold':[-60,0,'dB'],'compressor:ratio':[1,20,'x'],'compressor:knee':[0,40,'dB'],'compressor:attack':[.001,1,'s'],'compressor:release':[.01,3,'s'],'compressor:makeup':[-6,18,'dB'],
      'reverb:decay':[.2,8,'s'],'reverb:size':[.25,2.5,'x'],'reverb:damping':[800,20000,'Hz'],'reverb:mix':[0,1,'%']
    };
    const row=map[key]||[0,1,''];
    return {min:row[0],max:row[1],unit:row[2]};
  }

  function normalizePluginValue(type,param,value){
    const spec=pluginParamSpec(type,param);
    return clamp(value,spec.min,spec.max);
  }

  function pluginAutomationKey(index,type,param){
    return `plugin:${Math.max(0,Number(index)||0)}:${String(type||'').toLowerCase()}:${String(param||'')}`;
  }

  function rmsFromTimeDomain(bytes){
    if(!bytes||!bytes.length)return {rms:0,peak:0,db:-96};
    let sum=0;
    let peak=0;
    for(let index=0;index<bytes.length;index++){
      const sample=(Number(bytes[index])-128)/128;
      sum+=sample*sample;
      peak=Math.max(peak,Math.abs(sample));
    }
    const rms=Math.sqrt(sum/bytes.length);
    const db=rms>0?Math.max(-96,20*Math.log10(rms)):-96;
    return {rms,peak,db};
  }

  function install(root,document){
    if(!document||root.__STONEFELLOW_STEM_V211_INSTALLED__)return false;
    root.__STONEFELLOW_STEM_V211_INSTALLED__=true;

    const cfg=root.STONEFELLOW_STEM_STUDIO||{};
    const storageKey=`stonefellow:stem:v211:${Number(cfg.userId||0)}:${Number(cfg.trackId||0)}`;
    let bindAttempts=0;
    let observer=null;
    let frame=0;
    let lastFrame=0;
    let automationClipboard=null;
    let lastClipSnapshot=new Map();
    let lastPlaying=false;
    const touches=new Map();
    const meterState=new Map();
    const lastWrites=new Map();
    const pluginApplyCache=new Map();
    const graphMarkupCache=new WeakMap();

    let settings={
      density:'normal',
      followClips:true,
      modes:{},
      pluginTargets:{},
      pluginAutomation:{},
      draw:{},
      peakHoldMs:1500
    };

    try{
      const saved=JSON.parse(root.localStorage?.getItem(storageKey)||'null');
      if(saved&&typeof saved==='object')settings={...settings,...saved};
    }catch(error){}
    settings.modes=settings.modes&&typeof settings.modes==='object'?settings.modes:{};
    settings.pluginTargets=settings.pluginTargets&&typeof settings.pluginTargets==='object'?settings.pluginTargets:{};
    settings.pluginAutomation=settings.pluginAutomation&&typeof settings.pluginAutomation==='object'?settings.pluginAutomation:{};
    settings.draw=settings.draw&&typeof settings.draw==='object'?settings.draw:{};
    settings.density=['compact','normal','wide'].includes(settings.density)?settings.density:'normal';
    settings.followClips=settings.followClips!==false;

    const studio=()=>root.StonefellowStemStudioV91||null;
    const core=()=>root.STONEFELLOW_STUDIO_RUNTIME_V87||null;
    const v210=()=>root.StonefellowStemProfessionalEditingV210Runtime||null;
    const agent=()=>{try{return studio()?.getAgentState?.()||{};}catch(error){return{};}};
    const mix=()=>{try{return studio()?.getMixState?.()||{};}catch(error){return{};}};
    const playhead=()=>Math.max(0,num(studio()?.getLedgerState?.()?.playhead,core()?.getPosition?.()||0));
    const stemIds=()=>Object.keys(mix()?.stems||{}).map(Number).filter(id=>id>0);
    const selectedStemId=()=>Math.max(0,Number(agent().selected_id||0));

    function persist(){
      try{root.localStorage?.setItem(storageKey,JSON.stringify(settings));}catch(error){}
    }

    function modeFor(stemId){return normalizeMode(settings.modes[String(stemId)]||'read');}
    function setMode(stemId,mode){
      const id=String(Number(stemId)||0);
      settings.modes[id]=normalizeMode(mode);
      touches.delete(id);
      persist();
      syncModeUi(Number(id));
      return settings.modes[id];
    }

    function pluginBucket(stemId){
      const id=String(Number(stemId)||0);
      if(!settings.pluginAutomation[id]||typeof settings.pluginAutomation[id]!=='object')settings.pluginAutomation[id]={};
      return settings.pluginAutomation[id];
    }

    function currentRange(){
      const range=v210()?.getRange?.();
      if(range&&Number(range.end)>Number(range.start))return {start:Number(range.start),end:Number(range.end)};
      const loop=agent().loop||{};
      if(loop.active&&Number(loop.end)>Number(loop.start))return {start:Number(loop.start),end:Number(loop.end)};
      return null;
    }

    function parameterValueFromControl(control){
      if(!control)return null;
      const channel=control.closest?.('[data-mixer-stem]');
      const stemId=Number(channel?.dataset?.mixerStem||selectedStemId());
      if(stemId<1)return null;
      if(control.matches?.('[data-stem-volume]'))return {stemId,parameter:'volume',value:num(control.value,1)};
      if(control.matches?.('[data-stem-pan]'))return {stemId,parameter:'pan',value:num(control.value,0)};
      if(control.matches?.('[data-aux-send="a"]'))return {stemId,parameter:'auxA',value:num(control.value,0)};
      if(control.matches?.('[data-aux-send="b"]'))return {stemId,parameter:'auxB',value:num(control.value,0)};
      return null;
    }

    async function writeCorePoint(stemId,parameter,value,time=playhead(),force=false){
      if(!CORE_PARAMETERS.includes(parameter))return false;
      const key=`${stemId}:${parameter}`;
      const now=Date.now();
      const previous=lastWrites.get(key);
      if(!force&&previous&&Math.abs(previous.value-value)<.0008&&now-previous.time<260)return false;
      lastWrites.set(key,{value:Number(value),time:now});
      try{
        await studio()?.executeAgentCommand?.({type:'automation_point',stem_id:Number(stemId),parameter,time:Math.max(0,num(time,0)),value:Number(value)});
        return true;
      }catch(error){return false;}
    }

    function writePluginPoint(stemId,target,value,time=playhead()){
      const parsed=parsePluginTarget(target);
      if(!parsed)return false;
      const bucket=pluginBucket(stemId);
      const spec=pluginParamSpec(parsed.type,parsed.param);
      const safeValue=clamp(value,spec.min,spec.max);
      const key=`plugin:${stemId}:${target}`;
      const now=Date.now();
      const previous=lastWrites.get(key);
      const tolerance=Math.max(.0005,Math.abs(spec.max-spec.min)*.0008);
      if(previous&&Math.abs(previous.value-safeValue)<tolerance&&now-previous.time<160)return false;
      lastWrites.set(key,{value:safeValue,time:now});
      bucket[target]=insertAutomationPoint(bucket[target]||[],time,safeValue,{replaceWindow:.09,tolerance});
      persist();
      renderPluginGraph(Number(stemId));
      return true;
    }

    function pluginCurrentValue(stemId,target){
      const parsed=parsePluginTarget(target);
      if(!parsed)return null;
      const stem=(agent().stems||[]).find(row=>Number(row.id)===Number(stemId));
      const plugin=stem?.plugins?.[parsed.index];
      if(!plugin||String(plugin.type)!==parsed.type||!Object.prototype.hasOwnProperty.call(plugin.params||{},parsed.param))return null;
      return Number(plugin.params[parsed.param]);
    }

    function pluginTargetOptions(stemId){
      const stem=(agent().stems||[]).find(row=>Number(row.id)===Number(stemId));
      const rows=[];
      (stem?.plugins||[]).forEach((plugin,index)=>{
        Object.keys(plugin.params||{}).forEach(param=>{
          const value=pluginAutomationKey(index,plugin.type,param);
          rows.push({value,label:`${index+1} · ${String(plugin.type).toUpperCase()} · ${String(param).toUpperCase()}`});
        });
      });
      return rows;
    }

    function graphPoint(event,svg,spec){
      const rect=svg.getBoundingClientRect();
      const x=clamp((event.clientX-rect.left)/Math.max(1,rect.width),0,1);
      const y=clamp((event.clientY-rect.top)/Math.max(1,rect.height),0,1);
      const duration=Math.max(.01,num(agent().duration,1));
      return {t:x*duration,v:spec.max-(spec.max-spec.min)*y};
    }

    function renderPluginGraph(stemId){
      const lane=document.querySelector(`[data-arrange-stem="${Number(stemId)}"] [data-v211-plugin-lane]`);
      if(!lane)return;
      const target=settings.pluginTargets[String(stemId)]||'';
      const parsed=parsePluginTarget(target);
      lane.hidden=!parsed;
      if(!parsed)return;
      const svg=lane.querySelector('svg');
      const path=lane.querySelector('[data-v211-plugin-path]');
      const pointsGroup=lane.querySelector('[data-v211-plugin-points]');
      const readout=lane.querySelector('[data-v211-plugin-readout]');
      const spec=pluginParamSpec(parsed.type,parsed.param);
      const duration=Math.max(.01,num(agent().duration,1));
      const points=pluginBucket(stemId)[target]||[];
      const xy=point=>({x:clamp(Number(point.t)/duration,0,1)*1000,y:(1-clamp((Number(point.v)-spec.min)/Math.max(.000001,spec.max-spec.min),0,1))*82+3});
      const pathValue=points.map((point,index)=>{const p=xy(point);return `${index?'L':'M'} ${p.x} ${p.y}`;}).join(' ');
      if(path&&path.getAttribute('d')!==pathValue)path.setAttribute('d',pathValue);
      const pointsMarkup=points.map((point,index)=>{const p=xy(point);return `<circle data-v211-plugin-point="${index}" cx="${p.x}" cy="${p.y}" r="5" tabindex="0"></circle>`;}).join('');
      if(pointsGroup&&graphMarkupCache.get(pointsGroup)!==pointsMarkup){pointsGroup.innerHTML=pointsMarkup;graphMarkupCache.set(pointsGroup,pointsMarkup);}
      const current=automationValueAt(points,playhead(),pluginCurrentValue(stemId,target)||0);
      const readoutValue=`${String(parsed.param).toUpperCase()} ${formatPluginValue(parsed.type,parsed.param,current)}`;
      if(readout&&readout.textContent!==readoutValue)readout.textContent=readoutValue;
      if(svg)svg.dataset.v211Target=target;
    }

    function formatPluginValue(type,param,value){
      const spec=pluginParamSpec(type,param);
      if(spec.unit==='%')return `${Math.round(clamp(value,spec.min,spec.max)*100)}%`;
      if(spec.unit==='Hz')return Number(value)>=1000?`${(Number(value)/1000).toFixed(1)}kHz`:`${Math.round(Number(value))}Hz`;
      if(spec.unit==='s')return `${Number(value).toFixed(Number(value)<.1?3:2)}s`;
      if(spec.unit==='x')return `${Number(value).toFixed(2)}x`;
      if(spec.unit==='dB')return `${Number(value).toFixed(1)}dB`;
      return Number(value).toFixed(2);
    }

    function bindPluginGraph(stemId,lane){
      const svg=lane.querySelector('svg');
      if(!svg||svg.dataset.v211Bound)return;
      svg.dataset.v211Bound='1';
      let pointer=null;
      let pointIndex=-1;
      let drawLast=0;

      svg.addEventListener('pointerdown',event=>{
        if(event.button!==0)return;
        const target=settings.pluginTargets[String(stemId)]||'';
        const parsed=parsePluginTarget(target);
        if(!parsed)return;
        pointer=event.pointerId;
        pointIndex=Number(event.target?.dataset?.v211PluginPoint??-1);
        try{svg.setPointerCapture(pointer);}catch(error){}
        const spec=pluginParamSpec(parsed.type,parsed.param);
        const next=graphPoint(event,svg,spec);
        const bucket=pluginBucket(stemId);
        if(pointIndex>=0&&bucket[target]?.[pointIndex]){
          bucket[target][pointIndex]={t:next.t,v:next.v};
          bucket[target].sort((a,b)=>a.t-b.t);
        }else if(settings.draw[String(stemId)]===true){
          bucket[target]=insertAutomationPoint(bucket[target]||[],next.t,next.v,{replaceWindow:.025,tolerance:(spec.max-spec.min)*.0008});
        }
        persist();renderPluginGraph(stemId);event.preventDefault();event.stopPropagation();
      });
      svg.addEventListener('pointermove',event=>{
        if(event.pointerId!==pointer)return;
        const target=settings.pluginTargets[String(stemId)]||'';
        const parsed=parsePluginTarget(target);if(!parsed)return;
        const spec=pluginParamSpec(parsed.type,parsed.param);
        const next=graphPoint(event,svg,spec);
        const bucket=pluginBucket(stemId);
        if(pointIndex>=0&&bucket[target]?.[pointIndex]){
          bucket[target][pointIndex]={t:next.t,v:next.v};bucket[target].sort((a,b)=>a.t-b.t);
        }else if(settings.draw[String(stemId)]===true&&Date.now()-drawLast>28){
          drawLast=Date.now();bucket[target]=insertAutomationPoint(bucket[target]||[],next.t,next.v,{replaceWindow:.025,tolerance:(spec.max-spec.min)*.0008});
        }
        persist();renderPluginGraph(stemId);event.preventDefault();
      });
      const finish=event=>{if(event.pointerId!==pointer)return;try{svg.releasePointerCapture(pointer);}catch(error){}pointer=null;pointIndex=-1;};
      svg.addEventListener('pointerup',finish);svg.addEventListener('pointercancel',finish);
      svg.addEventListener('dblclick',event=>{
        const index=Number(event.target?.dataset?.v211PluginPoint??-1);if(index<0)return;
        const target=settings.pluginTargets[String(stemId)]||'';const bucket=pluginBucket(stemId);if(!bucket[target]?.[index])return;
        bucket[target].splice(index,1);persist();renderPluginGraph(stemId);event.preventDefault();
      });
    }

    function appendAutomationUi(stemId){
      const row=document.querySelector(`[data-arrange-stem="${Number(stemId)}"]`);
      const lane=row?.querySelector('[data-automation-lane]');
      const toolbar=lane?.querySelector('.daw-automation-toolbar');
      if(!lane||!toolbar||toolbar.querySelector('[data-v211-auto-tools]'))return;
      const wrap=document.createElement('div');
      wrap.className='sf-v211-auto-tools';wrap.dataset.v211AutoTools=String(stemId);
      wrap.innerHTML=`<label>MODE <select data-v211-mode><option value="read">READ</option><option value="touch">TOUCH</option><option value="latch">LATCH</option><option value="write">WRITE</option></select></label><button type="button" data-v211-draw>DRAW</button><label>PLUGIN <select data-v211-plugin-target><option value="">OFF</option></select></label><button type="button" data-v211-copy>AUTO COPY</button><button type="button" data-v211-paste disabled>AUTO PASTE</button><button type="button" data-v211-follow>${settings.followClips?'FOLLOW ON':'FOLLOW OFF'}</button>`;
      toolbar.appendChild(wrap);
      const pluginLane=document.createElement('div');pluginLane.className='sf-v211-plugin-lane';pluginLane.dataset.v211PluginLane=String(stemId);pluginLane.hidden=true;
      pluginLane.innerHTML=`<div class="sf-v211-plugin-head"><strong>PLUGIN AUTOMATION</strong><span data-v211-plugin-readout>—</span></div><svg viewBox="0 0 1000 88" preserveAspectRatio="none" aria-label="Plugin automation editor"><path data-v211-plugin-path></path><g data-v211-plugin-points></g></svg>`;
      lane.appendChild(pluginLane);

      const mode=wrap.querySelector('[data-v211-mode]');mode.value=modeFor(stemId);mode.addEventListener('change',()=>setMode(stemId,mode.value));
      const draw=wrap.querySelector('[data-v211-draw]');draw.classList.toggle('active',settings.draw[String(stemId)]===true);draw.addEventListener('click',()=>{settings.draw[String(stemId)]=settings.draw[String(stemId)]!==true;draw.classList.toggle('active',settings.draw[String(stemId)]===true);persist();});
      const pluginSelect=wrap.querySelector('[data-v211-plugin-target]');
      pluginTargetOptions(stemId).forEach(option=>{const el=document.createElement('option');el.value=option.value;el.textContent=option.label;pluginSelect.appendChild(el);});
      pluginSelect.value=settings.pluginTargets[String(stemId)]||'';
      pluginSelect.addEventListener('change',()=>{settings.pluginTargets[String(stemId)]=pluginSelect.value;persist();renderPluginGraph(stemId);});
      wrap.querySelector('[data-v211-copy]').addEventListener('click',()=>copyTrackRange(stemId));
      wrap.querySelector('[data-v211-paste]').addEventListener('click',()=>pasteTrackRange(stemId));
      wrap.querySelector('[data-v211-follow]').addEventListener('click',event=>{settings.followClips=!settings.followClips;persist();document.querySelectorAll('[data-v211-follow]').forEach(button=>button.textContent=settings.followClips?'FOLLOW ON':'FOLLOW OFF');event.currentTarget.classList.toggle('active',settings.followClips);});
      wrap.querySelector('[data-v211-follow]').classList.toggle('active',settings.followClips);
      bindPluginGraph(stemId,pluginLane);renderPluginGraph(stemId);
    }

    function syncModeUi(stemId){
      document.querySelectorAll(`[data-v211-auto-tools="${Number(stemId)}"] [data-v211-mode]`).forEach(select=>select.value=modeFor(stemId));
      const channel=document.querySelector(`[data-mixer-stem="${Number(stemId)}"]`);if(channel)channel.dataset.v211AutomationMode=modeFor(stemId);
      channel?.querySelector('[data-v211-mode-badge]')?.replaceChildren(document.createTextNode(modeFor(stemId).toUpperCase()));
    }

    function appendMixerUi(stemId){
      const channel=document.querySelector(`[data-mixer-stem="${Number(stemId)}"]`);if(!channel||channel.querySelector('[data-v211-meter]'))return;
      channel.dataset.v211AutomationMode=modeFor(stemId);
      const meter=document.createElement('div');meter.className='sf-v211-meter';meter.dataset.v211Meter=String(stemId);meter.innerHTML='<i data-v211-meter-fill></i><b data-v211-peak></b><output data-v211-db>-∞</output>';
      const fader=channel.querySelector('.daw-stem-fader-wrap')||channel.lastElementChild;fader?.insertAdjacentElement('beforebegin',meter);
      const trim=document.createElement('label');trim.className='sf-v211-trim';trim.innerHTML=`<span>TRIM</span><input type="range" min="-12" max="12" step="0.5" value="0" data-v211-trim="${stemId}"><output>0.0 dB</output><em data-v211-mode-badge>${modeFor(stemId).toUpperCase()}</em>`;channel.appendChild(trim);
      const agentStem=(agent().stems||[]).find(row=>Number(row.id)===Number(stemId));const input=trim.querySelector('input');const output=trim.querySelector('output');input.value=String(num(agentStem?.trim,0));output.textContent=`${num(agentStem?.trim,0).toFixed(1)} dB`;
      input.addEventListener('input',()=>{output.textContent=`${num(input.value,0).toFixed(1)} dB`;studio()?.executeAgentCommand?.({type:'track_trim',stem_id:Number(stemId),value:num(input.value,0)});});
    }

    function buildGlobalTools(){
      const toolbar=document.querySelector('.daw-mixer-toolbar');if(!toolbar||toolbar.querySelector('[data-v211-global]'))return false;
      const tools=document.createElement('div');tools.className='sf-v211-global';tools.dataset.v211Global=BUILD;
      tools.innerHTML=`<span>AUTOMATION / MIX</span><label>VIEW <select data-v211-density><option value="compact">COMPACT</option><option value="normal">NORMAL</option><option value="wide">WIDE</option></select></label><button type="button" data-v211-clear-solo>SOLO CLEAR</button><button type="button" data-v211-clear-mute>MUTE CLEAR</button><button type="button" data-v211-clean>AUTO CLEAN</button>`;
      toolbar.appendChild(tools);const density=tools.querySelector('[data-v211-density]');density.value=settings.density;density.addEventListener('change',()=>{settings.density=['compact','normal','wide'].includes(density.value)?density.value:'normal';persist();applyDensity();});
      tools.querySelector('[data-v211-clear-solo]').addEventListener('click',()=>document.querySelectorAll('[data-stem-solo].active,[data-stem-solo][aria-pressed="true"]').forEach(button=>button.click()));
      tools.querySelector('[data-v211-clear-mute]').addEventListener('click',()=>document.querySelectorAll('[data-stem-mute].active,[data-stem-mute][aria-pressed="true"]').forEach(button=>button.click()));
      tools.querySelector('[data-v211-clean]').addEventListener('click',()=>cleanSelectedTrackAutomation());
      applyDensity();return true;
    }

    function applyDensity(){
      const studioEl=document.getElementById('stemStudio');if(!studioEl)return;
      studioEl.classList.remove('sf-v211-mixer-compact','sf-v211-mixer-wide');
      if(settings.density==='compact')studioEl.classList.add('sf-v211-mixer-compact');
      if(settings.density==='wide')studioEl.classList.add('sf-v211-mixer-wide');
    }

    async function copyTrackRange(stemId){
      const range=currentRange();if(!range){showStatus('Select a time range or loop first.');return false;}
      const state=mix();const stem=state.stems?.[String(stemId)];if(!stem)return false;
      const coreClip=copyAutomationRange(stem.automation||{},range.start,range.end);
      const pluginPayload={duration:range.end-range.start,parameters:{}};const bucket=pluginBucket(stemId);
      for(const [key,points] of Object.entries(bucket)){
        const copied=(points||[]).filter(point=>Number(point.t)>=range.start-EPSILON&&Number(point.t)<=range.end+EPSILON).map(point=>({t:Number(point.t)-range.start,v:Number(point.v)}));if(copied.length)pluginPayload.parameters[key]=copied;
      }
      automationClipboard={duration:range.end-range.start,core:coreClip,plugin:Object.keys(pluginPayload.parameters).length?pluginPayload:null};
      document.querySelectorAll('[data-v211-paste]').forEach(button=>button.disabled=false);showStatus(`Copied ${automationClipboard.duration.toFixed(2)}s of automation.`);return true;
    }

    async function pasteTrackRange(stemId){
      if(!automationClipboard)return false;const state=mix();const stem=state.stems?.[String(stemId)];if(!stem)return false;const anchor=playhead();
      const before=studio()?.getLedgerState?.();const next=clone(state);next.stems[String(stemId)].automation=pasteAutomationRange(stem.automation||{},automationClipboard.core||{parameters:{}},anchor,{replace:true});
      studio()?.beginUndoGroup?.();try{studio()?.applyMixState?.(next);}finally{studio()?.endUndoGroup?.();}
      if(automationClipboard.plugin){const bucket=pluginBucket(stemId);for(const [key,points] of Object.entries(automationClipboard.plugin.parameters||{})){bucket[key]=(bucket[key]||[]).filter(point=>Number(point.t)<anchor-EPSILON||Number(point.t)>anchor+automationClipboard.duration+EPSILON);for(const point of points)bucket[key]=insertAutomationPoint(bucket[key],anchor+Number(point.t),Number(point.v),{replaceWindow:.02});}}
      persist();if(before)try{await studio()?.recordManualEdit?.(before,{action:'automation_range_paste',request:`Paste ${automationClipboard.duration.toFixed(2)}s automation to track ${stemId}`});}catch(error){}
      renderPluginGraph(stemId);showStatus('Automation pasted at playhead.');return true;
    }

    async function cleanSelectedTrackAutomation(){
      const stemId=selectedStemId();if(stemId<1)return false;const state=mix();const stem=state.stems?.[String(stemId)];if(!stem)return false;const before=studio()?.getLedgerState?.();const next=clone(state);
      for(const parameter of CORE_PARAMETERS)next.stems[String(stemId)].automation[parameter]=simplifyAutomationPoints(stem.automation?.[parameter]||[],parameter==='pan'?.003:.002,.06);
      const bucket=pluginBucket(stemId);for(const key of Object.keys(bucket)){const parsed=parsePluginTarget(key);const spec=parsed?pluginParamSpec(parsed.type,parsed.param):{min:0,max:1};bucket[key]=simplifyAutomationPoints(bucket[key],Math.abs(spec.max-spec.min)*.001,.06);}
      studio()?.beginUndoGroup?.();try{studio()?.applyMixState?.(next);}finally{studio()?.endUndoGroup?.();}persist();if(before)try{await studio()?.recordManualEdit?.(before,{action:'automation_clean',request:`Simplify automation track ${stemId}`});}catch(error){};renderPluginGraph(stemId);showStatus('Automation cleaned.');return true;
    }

    function showStatus(message){
      let node=document.getElementById('stemV211Status');if(!node){node=document.createElement('div');node.id='stemV211Status';node.className='sf-v211-status';document.body.appendChild(node);}node.textContent=String(message||'');node.classList.add('show');root.clearTimeout(node._timer);node._timer=root.setTimeout(()=>node.classList.remove('show'),1600);
    }

    function updateClipSnapshot(){
      const snapshot=new Map();for(const clip of agent().clips||[])snapshot.set(String(clip.id),{stemId:Number(clip.stem_id||0),start:Number(clip.start||0),end:Number(clip.start||0)+Number(clip.duration||0)});lastClipSnapshot=snapshot;
    }

    async function automationFollowClipEvent(event){
      if(!settings.followClips)return;const action=String(event?.detail?.action||'');if(!['clip_group_move','clip_nudge'].includes(action)){updateClipSnapshot();return;}
      const ids=(event?.detail?.ids||[]).map(String);if(!ids.length){updateClipSnapshot();return;}const current=new Map((agent().clips||[]).map(clip=>[String(clip.id),{stemId:Number(clip.stem_id||0),start:Number(clip.start||0),end:Number(clip.start||0)+Number(clip.duration||0)}]));
      const state=mix();const next=clone(state);let changed=false;
      for(const id of ids){const beforeClip=lastClipSnapshot.get(id);const afterClip=current.get(id);if(!beforeClip||!afterClip||beforeClip.stemId<1||beforeClip.stemId!==afterClip.stemId)continue;const delta=afterClip.start-beforeClip.start;if(Math.abs(delta)<EPSILON)continue;const stem=next.stems?.[String(beforeClip.stemId)];if(!stem)continue;for(const parameter of CORE_PARAMETERS){stem.automation[parameter]=shiftAutomationRange(stem.automation?.[parameter]||[],beforeClip.start,beforeClip.end,delta);}const bucket=pluginBucket(beforeClip.stemId);for(const key of Object.keys(bucket))bucket[key]=shiftAutomationRange(bucket[key],beforeClip.start,beforeClip.end,delta);changed=true;}
      if(changed){studio()?.applyMixState?.(next);persist();showStatus('Automation followed clip move.');}lastClipSnapshot=current;
    }

    function bindMixerWriting(){
      document.addEventListener('pointerdown',event=>{
        const info=parameterValueFromControl(event.target);if(info){const id=String(info.stemId);const mode=modeFor(info.stemId);if(mode!=='read')touches.set(id,{parameter:info.parameter,active:true,latched:mode==='latch'||mode==='write'});}
        if(event.target?.closest?.('#pluginEditor')){const stemId=selectedStemId();if(stemId>0&&modeFor(stemId)!=='read'&&settings.pluginTargets[String(stemId)])touches.set(String(stemId),{parameter:settings.pluginTargets[String(stemId)],active:true,latched:['latch','write'].includes(modeFor(stemId))});}
      },true);
      document.addEventListener('input',event=>{
        const info=parameterValueFromControl(event.target);if(!info)return;const mode=modeFor(info.stemId);const touch=touches.get(String(info.stemId));if(mode==='write'||(mode==='touch'&&touch?.active)||(mode==='latch'&&(touch?.active||touch?.latched)))void writeCorePoint(info.stemId,info.parameter,info.value);
      },true);
      document.addEventListener('pointerup',event=>{
        for(const [id,touch] of touches){touch.active=false;if(modeFor(Number(id))==='touch')touches.delete(id);else touches.set(id,touch);}
      },true);
    }

    function readControlValue(stemId,parameter){
      const channel=document.querySelector(`[data-mixer-stem="${Number(stemId)}"]`);if(!channel)return null;
      const selector=parameter==='volume'?'[data-stem-volume]':parameter==='pan'?'[data-stem-pan]':parameter==='auxA'?'[data-aux-send="a"]':'[data-aux-send="b"]';const input=channel.querySelector(selector);return input?num(input.value,0):null;
    }

    async function applyPluginAutomation(time){
      for(const stemId of stemIds()){
        const mode=modeFor(stemId);
        const touch=touches.get(String(stemId));
        const bucket=pluginBucket(stemId);
        for(const [target,points] of Object.entries(bucket)){
          if(!Array.isArray(points)||!points.length)continue;
          if(mode==='write'||(touch?.parameter===target&&(touch.active||touch.latched)))continue;
          const parsed=parsePluginTarget(target);if(!parsed)continue;
          const fallback=pluginCurrentValue(stemId,target);if(fallback===null)continue;
          const value=normalizePluginValue(parsed.type,parsed.param,automationValueAt(points,time,fallback));
          const key=`${stemId}:${target}`;
          const previous=pluginApplyCache.get(key);
          const tolerance=Math.max(.0005,Math.abs(pluginParamSpec(parsed.type,parsed.param).max-pluginParamSpec(parsed.type,parsed.param).min)*.0003);
          if(previous&&Math.abs(previous.value-value)<tolerance&&Date.now()-previous.time<250)continue;
          pluginApplyCache.set(key,{value,time:Date.now()});
          try{await studio()?.executeAgentCommand?.({type:'plugin_param',stem_id:Number(stemId),plugin_index:parsed.index,param:parsed.param,value});}catch(error){}
        }
      }
    }

    function pollAutomationWriting(now){
      const state=agent();const playing=Boolean(state.playing);const time=playhead();
      if(!playing&&lastPlaying){touches.clear();pluginApplyCache.clear();lastWrites.clear();}
      lastPlaying=playing;
      if(playing){
        for(const stemId of stemIds()){
          const mode=modeFor(stemId);if(mode==='write'){for(const parameter of CORE_PARAMETERS){const value=readControlValue(stemId,parameter);if(value!==null)void writeCorePoint(stemId,parameter,value,time);}}
          const touch=touches.get(String(stemId));if(touch?.latched&&CORE_PARAMETERS.includes(touch.parameter)){const value=readControlValue(stemId,touch.parameter);if(value!==null)void writeCorePoint(stemId,touch.parameter,value,time);}
          const pluginTarget=settings.pluginTargets[String(stemId)]||'';if(pluginTarget&&(mode==='write'||touch?.active||touch?.latched)){const current=pluginCurrentValue(stemId,pluginTarget);if(current!==null)writePluginPoint(stemId,pluginTarget,current,time);}
        }
        void applyPluginAutomation(time);
      }
    }

    function updateMeters(now){
      for(const stemId of stemIds()){
        const stem=core()?.getStem?.(Number(stemId));const channel=document.querySelector(`[data-mixer-stem="${Number(stemId)}"]`);const meter=channel?.querySelector('[data-v211-meter]');if(!stem||!meter)continue;
        const data=stem.timeDomainData||new Uint8Array(stem.analyserNode?.fftSize||0);if(stem.analyserNode&&data.length)stem.analyserNode.getByteTimeDomainData(data);const level=rmsFromTimeDomain(data);let hold=meterState.get(stemId)||{peakDb:-96,holdUntil:0};if(level.db>=hold.peakDb||now>=hold.holdUntil){hold.peakDb=level.db;hold.holdUntil=now+Math.max(250,num(settings.peakHoldMs,1500));}else if(now>hold.holdUntil-500)hold.peakDb=Math.max(level.db,hold.peakDb-.35);meterState.set(stemId,hold);
        const normalized=clamp((level.db+60)/60,0,1);const peakNorm=clamp((hold.peakDb+60)/60,0,1);meter.querySelector('[data-v211-meter-fill]')?.style.setProperty('height',`${normalized*100}%`);meter.querySelector('[data-v211-peak]')?.style.setProperty('bottom',`${peakNorm*100}%`);const output=meter.querySelector('[data-v211-db]');if(output)output.textContent=level.db<=-95?'-∞':`${level.db.toFixed(1)}`;
      }
    }

    let refreshQueued=false;
    function queueTrackUiRefresh(){
      if(refreshQueued)return;
      refreshQueued=true;
      root.requestAnimationFrame(()=>{refreshQueued=false;refreshTrackUi();});
    }

    function refreshTrackUi(){
      for(const stemId of stemIds()){appendAutomationUi(stemId);appendMixerUi(stemId);syncModeUi(stemId);}document.querySelectorAll('[data-v211-paste]').forEach(button=>button.disabled=!automationClipboard);applyDensity();
    }

    function loop(now){
      frame=root.requestAnimationFrame(loop);if(now-lastFrame<42)return;lastFrame=now;pollAutomationWriting(now);updateMeters(now);for(const stemId of stemIds())renderPluginGraph(stemId);
    }

    function lateBind(){
      if(!studio()||!core()||!buildGlobalTools()){bindAttempts+=1;if(bindAttempts<240)root.setTimeout(lateBind,60);return;}
      refreshTrackUi();updateClipSnapshot();bindMixerWriting();root.addEventListener('stonefellow:stem-edit-v210',automationFollowClipEvent);root.addEventListener('stonefellow:stem-edit-v209',event=>{if(!event?.detail?.action?.startsWith?.('clip_'))return;root.setTimeout(updateClipSnapshot,0);});
      observer=new MutationObserver(queueTrackUiRefresh);observer.observe(document.getElementById('stemStudio')||document.body,{childList:true,subtree:true});if(!frame)frame=root.requestAnimationFrame(loop);
      root.dispatchEvent(new CustomEvent('stonefellow:stem-automation-mixer-v211',{detail:{build:BUILD}}));
    }
    lateBind();

    root.addEventListener('pagehide',()=>{observer?.disconnect();if(frame)root.cancelAnimationFrame(frame);frame=0;},{once:true});

    root.StonefellowStemAutomationMixerV211Runtime={
      build:BUILD,
      getSettings:()=>clone(settings),
      setMode,
      getMode:modeFor,
      setDensity:value=>{settings.density=['compact','normal','wide'].includes(value)?value:'normal';persist();applyDensity();return settings.density;},
      getDensity:()=>settings.density,
      setFollowClips:value=>{settings.followClips=Boolean(value);persist();return settings.followClips;},
      copyRange:copyTrackRange,
      pasteRange:pasteTrackRange,
      clean:cleanSelectedTrackAutomation,
      pluginAutomation:()=>clone(settings.pluginAutomation)
    };
    return true;
  }

  return Object.freeze({
    build:BUILD,modes:MODES,coreParameters:CORE_PARAMETERS,normalizeMode,automationValueAt,
    simplifyAutomationPoints,insertAutomationPoint,copyAutomationRange,pasteAutomationRange,
    shiftAutomationRange,parsePluginTarget,pluginParamSpec,normalizePluginValue,
    pluginAutomationKey,rmsFromTimeDomain,install
  });
});
