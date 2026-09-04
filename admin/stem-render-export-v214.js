(function(root,factory){
  'use strict';
  const api=factory();
  root.StonefellowStemRenderExportV214=api;
  if(root.document)api.install(root,root.document);
})(typeof globalThis!=='undefined'?globalThis:window,function(){
  'use strict';

  const BUILD='stem-render-export-v214-20260901';
  const MIN_RANGE=.05;
  const clamp=(v,min,max)=>Math.min(max,Math.max(min,Number.isFinite(Number(v))?Number(v):min));
  const dbToGain=db=>Math.pow(10,Number(db||0)/20);
  const gainToDb=value=>20*Math.log10(Math.max(1e-12,Math.abs(Number(value)||0)));
  const clone=value=>JSON.parse(JSON.stringify(value));

  function sanitizeFilename(value){
    const clean=String(value||'Render')
      .replace(/[\\/:*?"<>|\u0000-\u001f]/g,' ')
      .replace(/\s+/g,' ')
      .trim()
      .replace(/[. ]+$/g,'');
    return (clean||'Render').slice(0,140);
  }

  function validRange(value,duration=0){
    const total=Math.max(MIN_RANGE,Number(duration||0));
    if(!value)return {start:0,end:total,duration:total};
    const start=clamp(value.start,0,total);
    const end=clamp(value.end,start,total);
    if(end<=start+MIN_RANGE/2)return null;
    return {start,end,duration:end-start};
  }

  function stemRows(state){
    if(Array.isArray(state?.stems))return state.stems.map((stem,index)=>({key:String(stem?.id??index),id:Number(stem?.id??index),stem}));
    if(state?.stems&&typeof state.stems==='object')return Object.entries(state.stems).map(([key,stem])=>({key:String(key),id:Number(stem?.id??key),stem}));
    return [];
  }

  function roleMap(agentState){
    const map=new Map();
    (agentState?.stems||[]).forEach(stem=>map.set(Number(stem?.id||0),String(stem?.role||'')));
    return map;
  }

  function isVocalRole(role){return /(^|\b)(vocal|vocals|voice|lead vocal|backing vocal|bgv)(\b|$)/i.test(String(role||''));}

  function prepareVariantState(state,agentState,variant,options={}){
    const next=clone(state||{});
    const roles=roleMap(agentState||{});
    const selected=new Set((options.stemIds||[]).map(Number).filter(id=>id>0));
    const vocalGain=dbToGain(Number(options.vocalDeltaDb||0));
    let changed=0;

    stemRows(next).forEach(row=>{
      const stem=row.stem||{};
      const role=roles.get(Number(row.id))||String(stem.role||'');
      const vocal=isVocalRole(role);
      let muted=Boolean(stem.muted);

      if(variant==='instrumental')muted=vocal;
      else if(variant==='acapella')muted=!vocal;
      else if(variant==='selected')muted=!selected.has(Number(row.id));
      else if(variant==='stem')muted=Number(row.id)!==Number(options.stemId||0);

      if(Boolean(stem.muted)!==muted){stem.muted=muted;changed+=1;}
      if((variant==='vocal_up'||variant==='vocal_down')&&vocal){
        const before=Number(stem.volume??1);
        const after=clamp(before*vocalGain,0,1.5);
        if(Math.abs(after-before)>1e-9){stem.volume=after;changed+=1;}
      }
      if(variant==='stem'&&Number(row.id)===Number(options.stemId||0)){
        if(stem.muted){stem.muted=false;changed+=1;}
      }
      if(Boolean(stem.solo)){
        stem.solo=false;
        changed+=1;
      }

      if(options.includeTrackFx===false&&Array.isArray(stem.plugins)){
        stem.plugins.forEach(plugin=>{
          if(plugin&&plugin.enabled!==false){plugin.enabled=false;changed+=1;}
        });
      }
    });

    if(options.includeMasterFx===false){
      for(const key of ['masterPlugins','master_plugins']){
        if(Array.isArray(next[key]))next[key].forEach(plugin=>{if(plugin&&plugin.enabled!==false){plugin.enabled=false;changed+=1;}});
      }
      if(next.fixedPluginTargets?.master?.plugins&&Array.isArray(next.fixedPluginTargets.master.plugins)){
        next.fixedPluginTargets.master.plugins.forEach(plugin=>{if(plugin&&plugin.enabled!==false){plugin.enabled=false;changed+=1;}});
      }
    }

    return {state:next,changed};
  }

  function flattenChunks(chunks,channels=2){
    const clean=Array.isArray(chunks)?chunks:[];
    const total=clean.reduce((sum,chunk)=>sum+Math.max(0,Number(chunk?.[0]?.length||0)),0);
    const result=[];
    for(let channel=0;channel<channels;channel+=1){
      const out=new Float32Array(total);let offset=0;
      clean.forEach(chunk=>{
        const source=chunk?.[Math.min(channel,(chunk?.length||1)-1)]||chunk?.[0]||new Float32Array(0);
        out.set(source,offset);offset+=source.length;
      });
      result.push(out);
    }
    return result;
  }

  function trimPcm(channels,frames){
    const count=Math.max(0,Math.floor(Number(frames)||0));
    return (channels||[]).map(channel=>channel.slice(0,Math.min(count,channel.length)));
  }

  function linearResample(channels,sourceRate,targetRate){
    const source=Math.max(8000,Number(sourceRate)||48000);
    const target=Math.max(8000,Number(targetRate)||source);
    if(source===target)return (channels||[]).map(channel=>new Float32Array(channel));
    const sourceLength=Math.max(0,channels?.[0]?.length||0);
    const targetLength=Math.max(1,Math.round(sourceLength*target/source));
    return (channels||[]).map(channel=>{
      const out=new Float32Array(targetLength);
      const scale=source/target;
      for(let i=0;i<targetLength;i+=1){
        const pos=i*scale;
        const lo=Math.min(channel.length-1,Math.floor(pos));
        const hi=Math.min(channel.length-1,lo+1);
        const frac=pos-lo;
        out[i]=(channel[lo]||0)*(1-frac)+(channel[hi]||0)*frac;
      }
      return out;
    });
  }

  function downmixMono(channels){
    if(!channels?.length)return [new Float32Array(0)];
    if(channels.length===1)return [new Float32Array(channels[0])];
    const length=Math.min(...channels.map(channel=>channel.length));
    const mono=new Float32Array(length);
    for(let i=0;i<length;i+=1){
      let value=0;
      channels.forEach(channel=>{value+=Number(channel[i]||0);});
      mono[i]=value/channels.length;
    }
    return [mono];
  }

  function samplePeak(channels){
    let peak=0;let clipped=0;
    (channels||[]).forEach(channel=>{
      for(let i=0;i<channel.length;i+=1){
        const value=Math.abs(Number(channel[i]||0));
        if(value>peak)peak=value;
        if(value>1)clipped+=1;
      }
    });
    return {linear:peak,db:gainToDb(peak),clipped};
  }

  function truePeakApprox(channels){
    let peak=0;
    (channels||[]).forEach(channel=>{
      for(let i=0;i<channel.length;i+=1){
        const a=Number(channel[i]||0);
        const b=Number(channel[Math.min(channel.length-1,i+1)]||a);
        for(let step=0;step<4;step+=1){
          const t=step/4;
          const value=Math.abs(a+(b-a)*t);
          if(value>peak)peak=value;
        }
      }
    });
    return {linear:peak,db:gainToDb(peak)};
  }

  function rmsLevel(channels){
    let sum=0;let count=0;
    (channels||[]).forEach(channel=>{
      for(let i=0;i<channel.length;i+=1){const v=Number(channel[i]||0);sum+=v*v;count+=1;}
    });
    const rms=Math.sqrt(sum/Math.max(1,count));
    return {linear:rms,db:gainToDb(rms)};
  }

  function integratedLufs(channels,sampleRate=48000){
    if(!channels?.length||!channels[0]?.length)return -Infinity;
    const rate=Math.max(8000,Number(sampleRate)||48000);
    const block=Math.max(1,Math.round(rate*.4));
    const hop=Math.max(1,Math.round(rate*.1));
    const length=Math.min(...channels.map(channel=>channel.length));
    const energies=[];
    for(let start=0;start+block<=length;start+=hop){
      let sum=0;
      for(let i=start;i<start+block;i+=1){
        let frame=0;
        channels.forEach(channel=>{const v=Number(channel[i]||0);frame+=v*v;});
        sum+=frame/channels.length;
      }
      const ms=sum/block;
      const loudness=-.691+10*Math.log10(Math.max(1e-15,ms));
      if(loudness>-70)energies.push({ms,loudness});
    }
    if(!energies.length)return -Infinity;
    const mean=energies.reduce((sum,row)=>sum+row.ms,0)/energies.length;
    const preliminary=-.691+10*Math.log10(Math.max(1e-15,mean));
    const gate=Math.max(-70,preliminary-10);
    const gated=energies.filter(row=>row.loudness>=gate);
    if(!gated.length)return preliminary;
    const gatedMean=gated.reduce((sum,row)=>sum+row.ms,0)/gated.length;
    return -.691+10*Math.log10(Math.max(1e-15,gatedMean));
  }

  function analyzePcm(channels,sampleRate=48000){
    const peak=samplePeak(channels);
    const truePeak=truePeakApprox(channels);
    const rms=rmsLevel(channels);
    return {
      peak:peak.linear,
      peakDb:peak.db,
      truePeak:truePeak.linear,
      truePeakDb:truePeak.db,
      rms:rms.linear,
      rmsDb:rms.db,
      lufs:integratedLufs(channels,sampleRate),
      clippedSamples:peak.clipped,
      frames:Number(channels?.[0]?.length||0),
      sampleRate:Number(sampleRate||0),
      duration:Number(channels?.[0]?.length||0)/Math.max(1,Number(sampleRate)||1)
    };
  }

  function applyGain(channels,gain){
    const scalar=Number.isFinite(Number(gain))?Number(gain):1;
    return (channels||[]).map(channel=>{
      const out=new Float32Array(channel.length);
      for(let i=0;i<channel.length;i+=1)out[i]=Number(channel[i]||0)*scalar;
      return out;
    });
  }

  function normalizePcm(channels,sampleRate,options={}){
    const before=analyzePcm(channels,sampleRate);
    const mode=String(options.mode||'none');
    let gainDb=0;
    if(mode==='peak'&&Number.isFinite(before.peakDb))gainDb=Number(options.peakTargetDb??-1)-before.peakDb;
    else if(mode==='lufs'&&Number.isFinite(before.lufs))gainDb=Number(options.lufsTarget??-14)-before.lufs;
    let gain=dbToGain(gainDb);
    const ceiling=Number(options.ceilingDb??-.3);
    const predictedPeak=before.truePeak*gain;
    const ceilingGain=dbToGain(ceiling);
    if(predictedPeak>ceilingGain&&predictedPeak>0){
      gain*=ceilingGain/predictedPeak;
      gainDb=gainToDb(gain);
    }
    const pcm=Math.abs(gain-1)<1e-9?(channels||[]).map(channel=>new Float32Array(channel)):applyGain(channels,gain);
    return {pcm,gain,gainDb,before,after:analyzePcm(pcm,sampleRate)};
  }

  function seededNoise(seed){
    let value=(Number(seed)||1)>>>0;
    return ()=>{
      value=(Math.imul(value,1664525)+1013904223)>>>0;
      return value/4294967296;
    };
  }

  function encodeWav(channels,sampleRate=48000,bitDepth=24,dither=true,seed=214){
    const channelCount=Math.max(1,Math.min(2,channels?.length||1));
    const frames=Math.max(0,channels?.[0]?.length||0);
    const rate=Math.max(8000,Math.min(192000,Math.round(Number(sampleRate)||48000)));
    const depth=[16,24,32].includes(Number(bitDepth))?Number(bitDepth):24;
    const float=depth===32;
    const bytesPerSample=depth/8;
    const blockAlign=channelCount*bytesPerSample;
    const dataBytes=frames*blockAlign;
    const buffer=new ArrayBuffer(44+dataBytes);
    const view=new DataView(buffer);
    const writeAscii=(offset,text)=>{for(let i=0;i<text.length;i+=1)view.setUint8(offset+i,text.charCodeAt(i));};
    writeAscii(0,'RIFF');view.setUint32(4,36+dataBytes,true);writeAscii(8,'WAVE');writeAscii(12,'fmt ');
    view.setUint32(16,16,true);view.setUint16(20,float?3:1,true);view.setUint16(22,channelCount,true);
    view.setUint32(24,rate,true);view.setUint32(28,rate*blockAlign,true);view.setUint16(32,blockAlign,true);view.setUint16(34,depth,true);
    writeAscii(36,'data');view.setUint32(40,dataBytes,true);
    const random=seededNoise(seed);let offset=44;
    const lsb=depth===16?1/32768:depth===24?1/8388608:0;
    for(let frame=0;frame<frames;frame+=1){
      for(let channel=0;channel<channelCount;channel+=1){
        let sample=Number(channels[Math.min(channel,channels.length-1)]?.[frame]||0);
        if(!float&&dither&&lsb>0)sample+=(random()-random())*lsb;
        sample=Math.max(-1,Math.min(1,sample));
        if(float){view.setFloat32(offset,sample,true);offset+=4;}
        else if(depth===16){view.setInt16(offset,sample<0?Math.round(sample*32768):Math.round(sample*32767),true);offset+=2;}
        else{
          let value=sample<0?Math.round(sample*8388608):Math.round(sample*8388607);
          if(value<0)value+=16777216;
          view.setUint8(offset,value&255);view.setUint8(offset+1,(value>>8)&255);view.setUint8(offset+2,(value>>16)&255);offset+=3;
        }
      }
    }
    return buffer;
  }

  function renderLabel(variant){
    const labels={master:'Master',instrumental:'Instrumental',acapella:'Acapella',vocal_up:'Vocal Up',vocal_down:'Vocal Down',selected:'Selected Stems',stems:'Stems'};
    return labels[String(variant)]||'Render';
  }

  function buildJobs(mode,agentState,selectedIds=[]){
    const stems=Array.isArray(agentState?.stems)?agentState.stems:[];
    const selected=new Set((selectedIds||[]).map(Number));
    if(mode==='queue')return ['master','instrumental','acapella','vocal_up','vocal_down'].map(variant=>({variant,capture:'master',label:renderLabel(variant)}));
    if(mode==='all_stems')return stems.map(stem=>({variant:'stem',capture:'stem',stemId:Number(stem.id),label:String(stem.name||`Stem ${stem.id}`)}));
    if(mode==='selected_stems')return stems.filter(stem=>selected.has(Number(stem.id))).map(stem=>({variant:'stem',capture:'stem',stemId:Number(stem.id),label:String(stem.name||`Stem ${stem.id}`)}));
    return [{variant:mode,capture:'master',label:renderLabel(mode)}];
  }

  function install(root,document){
    if(!document||root.__STONEFELLOW_STEM_V214_INSTALLED__)return false;
    root.__STONEFELLOW_STEM_V214_INSTALLED__=true;

    const cfg=root.STONEFELLOW_STEM_STUDIO||{};
    const ext=root.STONEFELLOW_STEM_RENDER_V214||{};
    let bindAttempts=0;
    let modal=null;
    let results=[];
    let rendering=false;
    let mp3Available=false;
    const objectUrls=new Set();
    const versionKey=`stonefellow:stem:v214:versions:${Number(cfg.userId||0)}:${Number(cfg.trackId||0)}`;

    const runtime=()=>root.STONEFELLOW_STUDIO_RUNTIME_V87||null;
    const studio=()=>root.StonefellowStemStudioV91||null;
    const v209=()=>root.StonefellowStemEditingV209Runtime||null;
    const v210=()=>root.StonefellowStemProfessionalEditingV210Runtime||null;
    const agent=()=>{try{return studio()?.getAgentState?.()||{};}catch(error){return{};}};

    function projectDuration(){return Math.max(MIN_RANGE,Number(agent().duration||cfg.duration||0)||MIN_RANGE);}
    function selectedStemIds(){
      const ids=new Set();
      const clips=agent().clips||[];
      (v209()?.getSelection?.()||[]).forEach(id=>{
        const clip=clips.find(row=>String(row?.id||'')===String(id));
        if(Number(clip?.stem_id)>0)ids.add(Number(clip.stem_id));
      });
      const current=Number(runtime()?.getSelectedStemId?.()||0);if(current>0)ids.add(current);
      return [...ids];
    }

    function nextVersion(label,extension){
      let map={};try{map=JSON.parse(root.localStorage?.getItem(versionKey)||'{}')||{};}catch(error){}
      const key=sanitizeFilename(label).toLowerCase();const version=Math.max(0,Number(map[key]||0))+1;map[key]=version;
      try{root.localStorage?.setItem(versionKey,JSON.stringify(map));}catch(error){}
      return `${sanitizeFilename(String(cfg.projectTitle||agent().project_title||'Stonefellow'))} - ${sanitizeFilename(label)} v${version}.${extension}`;
    }

    async function endpointRequest(action,fields={}){
      if(!ext.endpoint)throw new Error('Render service is unavailable.');
      const form=new root.FormData();form.append('csrf_token',String(cfg.csrf||''));form.append('action',action);form.append('track_id',String(cfg.trackId||0));
      Object.entries(fields).forEach(([key,value])=>{if(value!==undefined&&value!==null)form.append(key,value);});
      const response=await root.fetch(ext.endpoint,{method:'POST',credentials:'same-origin',body:form});
      return response;
    }

    async function detectCapabilities(){
      if(!ext.endpoint)return false;
      try{
        const response=await endpointRequest('capabilities');const data=await response.json();mp3Available=Boolean(response.ok&&data?.ok&&data?.mp3);
      }catch(error){mp3Available=false;}
      updateFormatUi();return mp3Available;
    }

    function rangeForUi(){
      const mode=modal?.querySelector('[data-v214-range]')?.value||'full';
      if(mode==='selection'){
        const range=validRange(v210()?.getRange?.(),projectDuration());
        if(!range)throw new Error('Select a timeline range before rendering Selected Range.');
        return range;
      }
      return validRange(null,projectDuration());
    }

    function setStatus(text,error=false){
      const node=modal?.querySelector('[data-v214-status]');if(!node)return;node.textContent=String(text||'');node.classList.toggle('error',Boolean(error));
    }

    function updateProgress(done,total,label=''){
      const bar=modal?.querySelector('[data-v214-progress]');const text=modal?.querySelector('[data-v214-progress-text]');
      const ratio=total>0?clamp(done/total,0,1):0;if(bar)bar.style.width=`${Math.round(ratio*100)}%`;if(text)text.textContent=label||`${done}/${total}`;
    }

    function updateFormatUi(){
      const format=modal?.querySelector('[data-v214-format]');if(!format)return;
      const mp3=format.querySelector('option[value="mp3"]');if(mp3){mp3.disabled=!mp3Available;mp3.textContent=mp3Available?'MP3':'MP3 · encoder unavailable';}
      if(format.value==='mp3'&&!mp3Available)format.value='wav';
    }

    function buildModal(){
      if(document.getElementById('stemRenderDialogV214')){modal=document.getElementById('stemRenderDialogV214');return true;}
      modal=document.createElement('div');modal.id='stemRenderDialogV214';modal.className='sf-v214-modal';modal.hidden=true;
      modal.innerHTML=`<div class="sf-v214-backdrop" data-v214-close></div><section class="sf-v214-card" role="dialog" aria-modal="true" aria-labelledby="stemRenderTitleV214"><header><div><span>PROFESSIONAL PRINT</span><h3 id="stemRenderTitleV214">Render / Export</h3></div><button type="button" data-v214-close aria-label="Close">×</button></header><div class="sf-v214-grid"><label>OUTPUT<select data-v214-mode><option value="master">Master</option><option value="instrumental">Instrumental</option><option value="acapella">Acapella</option><option value="vocal_up">Vocal Up +2 dB</option><option value="vocal_down">Vocal Down -2 dB</option><option value="all_stems">All Stems</option><option value="selected_stems">Selected Stems</option><option value="queue">Master Delivery Set</option></select></label><label>RANGE<select data-v214-range><option value="full">Full Song</option><option value="selection">Selected Range</option></select></label><label>FORMAT<select data-v214-format><option value="wav">WAV</option><option value="mp3">MP3</option></select></label><label>SAMPLE RATE<select data-v214-rate><option value="44100">44.1 kHz</option><option value="48000" selected>48 kHz</option><option value="88200">88.2 kHz</option><option value="96000">96 kHz</option></select></label><label>BIT DEPTH<select data-v214-depth><option value="16">16-bit</option><option value="24" selected>24-bit</option><option value="32">32-bit float</option></select></label><label>CHANNELS<select data-v214-channels><option value="2" selected>Stereo</option><option value="1">Mono</option></select></label><label>NORMALIZE<select data-v214-normalize><option value="none">Off</option><option value="peak">Peak</option><option value="lufs">LUFS</option></select></label><label>PEAK TARGET<input data-v214-peak type="number" step="0.1" min="-12" max="0" value="-1"></label><label>LUFS TARGET<input data-v214-lufs type="number" step="0.5" min="-30" max="-5" value="-14"></label><label>CEILING<input data-v214-ceiling type="number" step="0.1" min="-6" max="0" value="-0.3"></label><label class="sf-v214-check"><input data-v214-dither type="checkbox" checked><span>Dither integer WAV</span></label><label class="sf-v214-check"><input data-v214-track-fx type="checkbox" checked><span>Track FX</span></label><label class="sf-v214-check"><input data-v214-master-fx type="checkbox" checked><span>Master FX</span></label></div><p class="sf-v214-note">Render prints the live Stonefellow audio graph in real time, so current automation, routing, post-fader track processing and master processing are captured exactly as heard. Individual stems print from each post-fader track output.</p><div class="sf-v214-actions"><button type="button" data-v214-source-files>SOURCE FILES</button><button type="button" class="primary" data-v214-render>RENDER</button></div><div class="sf-v214-status" data-v214-status>READY</div><div class="sf-v214-progress"><i data-v214-progress></i></div><small data-v214-progress-text></small><div class="sf-v214-results" data-v214-results></div></section>`;
      document.body.appendChild(modal);
      modal.querySelectorAll('[data-v214-close]').forEach(button=>button.addEventListener('click',()=>{if(!rendering)modal.hidden=true;}));
      modal.querySelector('[data-v214-render]').addEventListener('click',()=>void renderFromUi());
      modal.querySelector('[data-v214-source-files]').addEventListener('click',()=>{
        modal.hidden=true;const old=document.querySelector('[data-studio-export-audio]');old?.click();
      });
      updateFormatUi();return true;
    }

    function addLaunchButtons(){
      const source=document.querySelector('[data-studio-export-audio]');
      if(source?.parentElement&&!document.querySelector('[data-v214-open-render]')){
        const button=document.createElement('button');button.type='button';button.dataset.v214OpenRender=BUILD;button.innerHTML='<strong>Render Mix / Stems</strong><span>Print the current mix, delivery versions or processed stems</span>';source.parentElement.insertBefore(button,source);button.addEventListener('click',openModal);
      }
      const toolbar=document.querySelector('.daw-mixer-toolbar');
      if(toolbar&&!toolbar.querySelector('[data-v214-toolbar]')){
        const button=document.createElement('button');button.type='button';button.className='sf-v214-toolbar-button';button.dataset.v214Toolbar=BUILD;button.textContent='RENDER';button.addEventListener('click',openModal);toolbar.appendChild(button);
      }
    }

    function openModal(){buildModal();addLaunchButtons();modal.hidden=false;setStatus('READY');updateProgress(0,1,'Ready to render');void detectCapabilities();}

    async function wait(ms){return new Promise(resolve=>root.setTimeout(resolve,ms));}

    async function seekViaTimeline(time){
      const target=clamp(time,0,projectDuration());const rt=runtime();
      if(typeof rt?.seek==='function'){await rt.seek(target,false);return true;}
      const live=studio();
      try{
        const result=await live?.executeAgentCommand?.({type:'seek',time:target,position:target});
        if(result?.status==='success')return true;
      }catch(error){}
      const surface=document.getElementById('dawTimelineSurface');
      const rect=surface?.getBoundingClientRect?.();
      if(!surface||!(rect?.width>0))throw new Error('Timeline seek surface is unavailable.');
      const clientX=rect.left+clamp(target/projectDuration(),0,1)*rect.width;
      const eventInit={bubbles:true,cancelable:true,clientX,clientY:rect.top+Math.min(18,rect.height/2),button:0,buttons:1};
      try{surface.dispatchEvent(new root.PointerEvent('pointerdown',eventInit));surface.dispatchEvent(new root.PointerEvent('pointerup',{...eventInit,buttons:0}));}catch(error){surface.dispatchEvent(new root.MouseEvent('mousedown',eventInit));surface.dispatchEvent(new root.MouseEvent('mouseup',{...eventInit,buttons:0}));}
      surface.dispatchEvent(new root.MouseEvent('click',{...eventInit,buttons:0}));
      const deadline=Date.now()+2600;
      while(Date.now()<deadline){if(Math.abs(Number(rt?.getPosition?.()||0)-target)<.08)return true;await wait(40);}
      throw new Error('Stem Studio could not seek to the render start position.');
    }

    function createCapture(source,ctx){
      const factory=ctx?.createScriptProcessor||ctx?.createJavaScriptNode;if(!ctx||!source||!factory)throw new Error('This browser cannot capture the Studio output.');
      const processor=factory.call(ctx,4096,2,2);const sink=ctx.createGain();sink.gain.value=0;
      const chunks=[];let active=false;
      processor.onaudioprocess=event=>{
        if(!active)return;const input=event.inputBuffer;const rows=[];const channels=Math.max(1,Math.min(2,input.numberOfChannels||1));
        for(let channel=0;channel<2;channel+=1){const data=input.getChannelData(Math.min(channel,channels-1));rows.push(new Float32Array(data));}
        chunks.push(rows);
      };
      source.connect(processor);processor.connect(sink);sink.connect(ctx.destination);
      return {
        chunks,
        start(){active=true;},
        stop(){active=false;processor.onaudioprocess=null;try{source.disconnect(processor);}catch(error){}try{processor.disconnect();sink.disconnect();}catch(error){}},
      };
    }

    async function printLiveGraph(job,range){
      const rt=runtime();rt?.ensureAudioGraph?.();const ctx=rt?.getContext?.();if(!ctx)throw new Error('Web Audio is unavailable.');if(ctx.state==='suspended')await ctx.resume();
      if(rt.isCoreRecording?.())throw new Error('Stop recording before rendering.');
      if(rt.isPlaying?.())rt.pause?.();
      await seekViaTimeline(range.start);await wait(120);
      const source=job.capture==='stem'?rt.getStemCaptureSource?.(job.stemId):rt.getMasterSource?.();if(!source)throw new Error(`${job.label}: render source is unavailable.`);
      const capture=createCapture(source,ctx);capture.start();
      const started=Date.now();await rt.play?.();
      const timeout=Math.max(15000,range.duration*2500+8000);
      try{
        while(true){
          const pos=Number(rt.getPosition?.()||0);
          if(pos>=range.end-.025)break;
          if(Date.now()-started>timeout)throw new Error(`${job.label}: render timed out.`);
          if(!rt.isPlaying?.()&&Date.now()-started>700)break;
          await wait(22);
        }
      }finally{rt.pause?.();capture.stop();}
      const raw=flattenChunks(capture.chunks,2);const expected=Math.max(1,Math.round(range.duration*Number(ctx.sampleRate||48000)));const trimmed=trimPcm(raw,expected);
      if((trimmed[0]?.length||0)<Math.min(expected*.65,Number(ctx.sampleRate||48000)*.2))throw new Error(`${job.label}: too little audio was captured.`);
      return {channels:trimmed,sampleRate:Number(ctx.sampleRate||48000)};
    }

    async function highQualityResample(channels,sourceRate,targetRate){
      if(Number(sourceRate)===Number(targetRate))return channels.map(channel=>new Float32Array(channel));
      const Offline=root.OfflineAudioContext||root.webkitOfflineAudioContext;
      if(!Offline)return linearResample(channels,sourceRate,targetRate);
      try{
        const count=Math.max(1,channels.length);const frames=Math.max(1,channels[0]?.length||1);const sourceCtx=runtime()?.getContext?.();
        const buffer=(sourceCtx||new (root.AudioContext||root.webkitAudioContext)()).createBuffer(count,frames,sourceRate);
        channels.forEach((channel,index)=>buffer.copyToChannel(channel,index));
        const targetFrames=Math.max(1,Math.round(frames*targetRate/sourceRate));const offline=new Offline(count,targetFrames,targetRate);const node=offline.createBufferSource();node.buffer=buffer;node.connect(offline.destination);node.start(0);const rendered=await offline.startRendering();
        return Array.from({length:count},(_,index)=>new Float32Array(rendered.getChannelData(index)));
      }catch(error){console.warn('v214 offline resample fallback',error);return linearResample(channels,sourceRate,targetRate);}
    }

    async function mp3FromWav(wav,filename){
      const blob=new root.Blob([wav],{type:'audio/wav'});const response=await endpointRequest('transcode_mp3',{filename,wav:blob});
      if(!response.ok){const data=await response.json().catch(()=>({error:'MP3 encoding failed.'}));throw new Error(data.error||'MP3 encoding failed.');}
      return response.blob();
    }

    function registerResult(job,blob,filename,report,warning=''){
      const url=root.URL.createObjectURL(blob);objectUrls.add(url);const item={job,blob,filename,report,url,warning};results.push(item);renderResults();return item;
    }

    function reportText(report){
      const fmt=value=>Number.isFinite(Number(value))?Number(value).toFixed(1):'—';
      return `Peak ${fmt(report.peakDb)} dBFS · True peak ${fmt(report.truePeakDb)} dBTP · LUFS ${fmt(report.lufs)} · RMS ${fmt(report.rmsDb)} dBFS`;
    }

    function renderResults(){
      const host=modal?.querySelector('[data-v214-results]');if(!host)return;host.innerHTML='';
      results.forEach(result=>{
        const row=document.createElement('article');row.innerHTML=`<div><strong></strong><span></span><small></small></div><a>DOWNLOAD</a>`;
        row.querySelector('strong').textContent=result.filename;row.querySelector('span').textContent=reportText(result.report);row.querySelector('small').textContent=result.warning||`${result.report.sampleRate} Hz · ${result.report.duration.toFixed(2)}s`;
        const link=row.querySelector('a');link.href=result.url;link.download=result.filename;host.appendChild(row);
      });
    }

    async function renderJob(job,range,settings,originalState){
      const live=studio();
      const delta=job.variant==='vocal_up'?2:job.variant==='vocal_down'?-2:0;
      const prepared=prepareVariantState(originalState,agent(),job.variant,{stemId:job.stemId,vocalDeltaDb:delta,includeTrackFx:settings.trackFx,includeMasterFx:settings.masterFx});
      live.applyMixState?.(prepared.state);await wait(160);
      const printed=await printLiveGraph(job,range);
      let pcm=await highQualityResample(printed.channels,printed.sampleRate,settings.rate);
      if(settings.channels===1)pcm=downmixMono(pcm);
      const normalized=normalizePcm(pcm,settings.rate,{mode:settings.normalize,peakTargetDb:settings.peak,lufsTarget:settings.lufs,ceilingDb:settings.ceiling});
      const report=normalized.after;
      const wav=encodeWav(normalized.pcm,settings.rate,settings.depth,settings.dither,214+results.length);
      let warning='';let blob;let extension=settings.format;
      if(settings.format==='mp3'){
        try{blob=await mp3FromWav(wav,`${sanitizeFilename(job.label)}.wav`);}
        catch(error){blob=new root.Blob([wav],{type:'audio/wav'});extension='wav';warning=`MP3 unavailable — WAV created instead. ${error?.message||''}`.trim();}
      }else blob=new root.Blob([wav],{type:'audio/wav'});
      const filename=nextVersion(job.label,extension);return registerResult(job,blob,filename,report,warning);
    }

    function settingsFromUi(){
      return {
        mode:String(modal.querySelector('[data-v214-mode]').value||'master'),
        format:String(modal.querySelector('[data-v214-format]').value||'wav'),
        rate:Number(modal.querySelector('[data-v214-rate]').value||48000),
        depth:Number(modal.querySelector('[data-v214-depth]').value||24),
        channels:Number(modal.querySelector('[data-v214-channels]').value||2),
        normalize:String(modal.querySelector('[data-v214-normalize]').value||'none'),
        peak:Number(modal.querySelector('[data-v214-peak]').value||-1),
        lufs:Number(modal.querySelector('[data-v214-lufs]').value||-14),
        ceiling:Number(modal.querySelector('[data-v214-ceiling]').value||-.3),
        dither:Boolean(modal.querySelector('[data-v214-dither]').checked),
        trackFx:Boolean(modal.querySelector('[data-v214-track-fx]').checked),
        masterFx:Boolean(modal.querySelector('[data-v214-master-fx]').checked)
      };
    }

    async function renderFromUi(){
      if(rendering)return;const live=studio();const rt=runtime();if(!live||!rt)return;
      let range;try{range=rangeForUi();}catch(error){setStatus(error.message,true);return;}
      const settings=settingsFromUi();const selection=selectedStemIds();const jobs=buildJobs(settings.mode,agent(),selection);
      if(!jobs.length){setStatus(settings.mode==='selected_stems'?'Select at least one track or clip first.':'No stems are available to render.',true);return;}
      rendering=true;results.forEach(item=>{if(item.url){root.URL.revokeObjectURL(item.url);objectUrls.delete(item.url);}});results=[];renderResults();
      const originalState=live.getMixState?.();const originalPosition=Number(rt.getPosition?.()||0);const wasPlaying=Boolean(rt.isPlaying?.());
      const button=modal.querySelector('[data-v214-render]');button.disabled=true;button.textContent='RENDERING…';setStatus(`Printing ${jobs.length} output${jobs.length===1?'':'s'} in real time…`);updateProgress(0,jobs.length,'Preparing');
      try{
        if(wasPlaying)rt.pause?.();
        for(let index=0;index<jobs.length;index+=1){
          const job=jobs[index];updateProgress(index,jobs.length,`Printing ${job.label} · ${index+1}/${jobs.length}`);setStatus(`PRINTING · ${job.label}`);
          await renderJob(job,range,settings,originalState);updateProgress(index+1,jobs.length,`${job.label} complete`);
        }
        setStatus(`RENDER COMPLETE · ${results.length} FILE${results.length===1?'':'S'}`);
      }catch(error){console.error('Stem v214 render failed',error);setStatus(error?.message||'Render failed.',true);}
      finally{
        try{live.applyMixState?.(originalState);await wait(120);await seekViaTimeline(originalPosition);if(wasPlaying)await rt.play?.();}catch(error){console.warn('v214 render state restore',error);}
        rendering=false;button.disabled=false;button.textContent='RENDER';
      }
    }

    function lateBind(){
      if(!runtime()||!studio()||!buildModal()){
        bindAttempts+=1;if(bindAttempts<240)root.setTimeout(lateBind,60);else root.__STONEFELLOW_STEM_V214_INSTALLED__=false;return;
      }
      addLaunchButtons();void detectCapabilities();
      root.StonefellowStemRenderExportV214Runtime={build:BUILD,open:openModal,render:renderFromUi,getResults:()=>results.map(item=>({filename:item.filename,report:clone(item.report),warning:item.warning})),analyze:analyzePcm,encodeWav};
      root.dispatchEvent(new CustomEvent('stonefellow:stem-render-export-v214',{detail:{build:BUILD}}));
    }
    lateBind();

    root.addEventListener('pagehide',()=>{objectUrls.forEach(url=>root.URL.revokeObjectURL(url));objectUrls.clear();},{once:true});
    return true;
  }

  return Object.freeze({
    build:BUILD,
    sanitizeFilename,
    validRange,
    stemRows,
    isVocalRole,
    prepareVariantState,
    flattenChunks,
    trimPcm,
    linearResample,
    downmixMono,
    samplePeak,
    truePeakApprox,
    rmsLevel,
    integratedLufs,
    analyzePcm,
    applyGain,
    normalizePcm,
    encodeWav,
    renderLabel,
    buildJobs,
    install
  });
});
