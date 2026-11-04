/* Independently authorized display shares. Chat follows the preview and Agent Ears can stay on a pinned tab. */
(() => {
  'use strict';
  if(window.VP3AgentScreenShare)return;
  const adapter=window.VP3_ORB_ADAPTER;if(!adapter)return;
  const owner=String(adapter.ownerKey),embedded=new URLSearchParams(location.search).get('orb_embed')==='1'&&parent!==window;
  const maxSources=3,shares=[];
  let selected=null,pinnedAudio=null,pickerOwner=null,sharedAnalysis=null,lastAnalysisOwner=null,lastSharedAnalysis=0;
  let audioHandoff=Promise.resolve();
  const supported=()=>isSecureContext&&navigator.mediaDevices?.getDisplayMedia&&adapter.screenRequest;
  const syncControls=()=>shares.forEach(share=>share.controls());
  const audioTarget=()=>pinnedAudio||selected;
  function holdAudio(done){
    audioHandoff=Promise.allSettled([audioHandoff,done]).then(()=>{});
  }
  function syncAudioTarget(previous){
    const next=audioTarget();
    if(previous!==next){
      previous?.pauseAudio();
      next?.resumeAudio();
    }
    syncControls();
  }
  function pinAudio(share){
    if(share&&!share.canPinAudio())return;
    const previous=audioTarget();
    pinnedAudio=share;
    syncAudioTarget(previous);
  }
  function stopSession(id){
    shares.find(share=>share.session()===id)?.discardAndStop();
  }

  function createShare(index,host){
    const label=index===0?'Share 1':'Tab '+(index+1);
    let stream=null,starting=false,generation=0,timer=0,controller=null,latest=null,observation=null,lastAnalyzedFrame=null,lastAnalysis=0,analyzedFingerprint=null;
    let root,video,preview,status,startButton,stopButton,captureButton,automatic,transcribe,pinAudioButton;
    const clean=value=>String(value||'').slice(0,2000);
    let sessionId=null,selectedSession=null,historyEpoch=0,audioRun=null,recentAudio=[];
    let timelineSession=null,timelineEpoch=0,timelineBefore=null,timelineLoading=false;
    const timelineCaptures=new Map();
    const uid=()=>crypto.randomUUID();
    const historyApi=(body,options={})=>adapter.screenHistory(body,options);
    const historySay=text=>{const p=root?.querySelector('[data-screen-history-status]');if(p)p.textContent=text;};
    const audioSay=text=>{const p=root?.querySelector('[data-screen-audio-status]');if(p)p.textContent=text;};
    function timelineTime(at){
      const origin=Date.parse(timelineSession?.created_at);
      if(!Number.isFinite(origin)||at<origin)return new Date(at).toLocaleTimeString();
      const tenths=Math.floor((at-origin)/100);
      return String(Math.floor(tenths/600)).padStart(2,'0')+':'+String(Math.floor(tenths/10)%60).padStart(2,'0')+'.'+tenths%10;
    }
    function timelineInterval(start,end){
      return timelineTime(start)+(end>start?'–'+timelineTime(end):'');
    }
    function renderTimeline(message=''){
      const panel=root.querySelector('[data-screen-transcript-log]');
      const list=panel.querySelector('ol');
      panel.hidden=!timelineSession;
      if(!timelineSession){list.replaceChildren();return;}
      const entries=[],audioSegments=[];
      for(const row of timelineCaptures.values()){
        const at=Date.parse(row.captured_at),body=row.body||{};
        if(!Number.isFinite(at)||row.review&&row.review.state!=='active')continue;
        if(row.kind==='screen'&&body.annotation_logging===true&&body.observation?.on_screen_translation){
          entries.push({at,end:at,text:body.observation.on_screen_translation,kind:'annotation'});
        }else if(row.kind==='tab_audio'&&body.transcript){
          const duration=Number(body.duration_seconds);
          const end=duration>0&&duration<=35?at+Math.round(duration*1000):at;
          if(end>at)audioSegments.push({at,end});
          const cues=body.cues;
          if(Array.isArray(cues)&&cues.length&&cues.every(c=>typeof c?.text==='string'&&c.text&&Number.isFinite(c.start_seconds)&&Number.isFinite(c.end_seconds)&&c.start_seconds>=0&&c.end_seconds>=c.start_seconds&&c.end_seconds<=duration)){
            for(const cue of cues)entries.push({at:at+Math.round(cue.start_seconds*1000),end:at+Math.round(cue.end_seconds*1000),text:cue.text,kind:'transcript'});
          }else entries.push({at,end,text:body.transcript,kind:'transcript'});
        }
      }
      entries.sort((a,b)=>a.at-b.at||a.end-b.end);
      const fragment=document.createDocumentFragment();
      for(const entry of entries){
        const row=document.createElement('li');row.setAttribute('data-screen-'+entry.kind+'-entry','');
        const time=document.createElement('time');time.dateTime=new Date(entry.at).toISOString();time.title=time.dateTime;time.textContent=timelineInterval(entry.at,entry.end);
        const label=document.createElement('strong');label.textContent=entry.kind==='transcript'?'Agent Ears':'On-screen caption / translation';
        const body=document.createElement('p');body.textContent=entry.text;
        row.append(time,' ',label,body);
        if(entry.kind==='annotation'){
          const matched=audioSegments.find(s=>s.at<=entry.at&&entry.at<s.end);
          if(matched){const audio=document.createElement('small');audio.textContent='Audio segment '+timelineInterval(matched.at,matched.end);row.append(audio);}
        }
        fragment.append(row);
      }
      list.replaceChildren(fragment);
      panel.querySelector('[data-screen-transcript-status]').textContent=message||(!entries.length?'No transcript or caption entries for this session yet.':'');
      const earlier=panel.querySelector('[data-screen-transcript-earlier]');
      earlier.hidden=!timelineBefore;earlier.disabled=timelineLoading;
    }
    function rememberTimelineCapture(row){
      if(!timelineSession||row?.session_id!==timelineSession.session_id)return;
      if(row.kind!=='tab_audio'&&!(row.body?.annotation_logging===true&&row.body?.observation?.on_screen_translation)){
        if(timelineCaptures.delete(row.capture_id))renderTimeline();
        return;
      }
      timelineCaptures.set(row.capture_id,row);renderTimeline();
    }
    async function loadTimeline(before=null){
      if(!timelineSession||timelineLoading)return;
      const epoch=timelineEpoch,id=timelineSession.session_id;
      timelineLoading=true;renderTimeline('Loading session log…');
      let error='';
      try{
        const data=await historyApi({action:'timeline',session_id:id,...(before?{before}:{})},{read:true});
        if(epoch!==timelineEpoch)return;
        if(data.session?.session_id!==id||!Array.isArray(data.captures))throw Error('Session log unavailable.');
        for(const row of data.captures)if(row.session_id===id&&!timelineCaptures.has(row.capture_id))timelineCaptures.set(row.capture_id,row);
        timelineBefore=data.next_before||null;
      }catch(e){error=e.message||'Session log unavailable.';}
      finally{if(epoch===timelineEpoch){timelineLoading=false;renderTimeline(error);}}
    }
    function selectTimeline(session,refresh=false){
      if(!refresh&&session?.session_id===timelineSession?.session_id)return;
      timelineEpoch++;timelineSession=session||null;timelineBefore=null;timelineLoading=false;timelineCaptures.clear();renderTimeline();
      if(timelineSession)void loadTimeline();
    }
    function historyRow(row){
      const card=document.createElement('article');card.className='vp3-screen-capture';card.dataset.captureId=row.capture_id;
      const title=document.createElement('strong');title.textContent=(row.kind==='tab_audio'?'Tab audio':'Screen capture')+' · '+new Date(row.captured_at).toLocaleString();card.append(title);
      const text=document.createElement('p');text.textContent=row.body.observation?.summary||row.body.transcript||'Saved capture · ready to analyze';card.append(text);
      for(const fact of row.body.observation?.observations||[]){const p=document.createElement('p');p.textContent=`${fact.label}: ${fact.detail} (${fact.confidence} confidence)`;card.append(p);}
      if(row.body.note){const p=document.createElement('p');p.textContent='Your note: '+row.body.note;card.append(p);}
      const actions=document.createElement('div');actions.className='vp3-eyes-actions';
      const choices=row.kind==='screen'?[['view','View capture'],['analyze','Analyze again']]:[];
      choices.push(['chat','Send to Chat'],['research','Research in Chat'],['brain',Number(row.memory_id)>0?'Update Brain':'Save to Brain']);
      for(const [action,label] of choices){const b=document.createElement('button');b.type='button';b.textContent=label;b.dataset.captureAction=action;actions.append(b);}
      card.append(actions);window.VP3_SCREEN_REVIEW_CAPTURE?.(card,row,{insightsEndpoint:window.VP3_ORB_CONFIG?.screenInsightsEndpoint,ownerKey:owner,csrf:window.VP3_ORB_CONFIG?.csrf,agentId:adapter.conversationRef?.()?.agent||0},()=>refreshHistory());return card;
    }
    async function refreshHistory(id=selectedSession){
      if(!adapter.screenHistory)return;const epoch=++historyEpoch;
      try{
        const list=await historyApi({action:'list'},{read:true});if(epoch!==historyEpoch)return;
        const select=root.querySelector('[data-screen-sessions]');select.replaceChildren(new Option('Choose a sharing session',''));
        for(const s of list.sessions)select.add(new Option(`${s.title} · ${new Date(s.created_at).toLocaleString()}`,s.session_id));
        const session=list.sessions.find(s=>s.session_id===id);
        selectedSession=session?.session_id||null;select.value=selectedSession||'';selectTimeline(session);
        const host=root.querySelector('[data-screen-history]');host.replaceChildren();const link=root.querySelector('[data-screen-folder]');link.hidden=true;
        if(!selectedSession){historySay(list.sessions.length?'Choose a session to see its captures.':'No saved sharing sessions yet.');return;}
        const data=await historyApi({action:'history',session_id:selectedSession},{read:true});if(epoch!==historyEpoch)return;
        link.href=data.session.knowledge_url;link.textContent='Open in My Knowledge · '+data.session.folder_name;link.hidden=false;
        for(const r of data.captures.slice(0,50))host.append(historyRow(r));
        historySay(`${data.captures.length} recent saved captures${data.captures.length>50?' · showing latest 50':''}.`);
      }catch(e){if(epoch===historyEpoch)historySay(e.message||'Sharing history unavailable.');}
    }
    async function persistFrame(frame,token,signal,analyzeIt){
      const sid=sessionId;if(!sid)throw Error('Wait for the sharing session folder to be ready.');
      const toBrain=root.querySelector('[data-screen-brain]').checked;const captureId=uid();const note=root.querySelector('[data-screen-note]')?.value.trim()||'';
      let result=await historyApi({action:'capture',session_id:sid,capture_id:captureId,image:frame.image,captured_at:frame.captured_at,surface:frame.surface,note,...(frame.annotationLogging?{annotation_logging:true}:{})},{signal});
      if(token!==generation||!stream)return null;
      if(analyzeIt)result=await historyApi({action:'analyze',capture_id:captureId},{signal});
      if(token!==generation||!stream)return null;
      rememberTimelineCapture(result.capture);
      if(toBrain&&root.querySelector('[data-screen-brain]').checked&&analyzeIt){try{result=await historyApi({action:'brain',capture_id:captureId},{signal});}catch(e){historySay('Capture saved. Brain: '+e.message);}}
      if(selectedSession===sid)void refreshHistory(sid);return result.capture;
    }
    function finishAudio(flush=true){
      const run=audioRun;if(!run)return Promise.resolve();audioRun=null;run.finishing=true;run.discard=!flush;
      clearTimeout(run.timer);clearInterval(run.meterTimer);if(!flush){run.controller?.abort();run.queue=[];}
      run.source?.disconnect();run.context?.close().catch(()=>{});
      const meter=root?.querySelector('[data-screen-audio-meter]');if(meter)meter.value=0;
      if(run.recorder?.state==='recording')run.recorder.stop();else run.resolveRecording?.();
      const done=run.finished.then(()=>run.pending);
      holdAudio(done);
      return done;
    }
    function resumeAudio(token){
      async function waitForHandoff(){
        while(audioTarget()===source&&token===generation&&stream&&!starting){
          const handoff=audioHandoff;
          await handoff;
          if(handoff!==audioHandoff)continue;
          if(audioTarget()===source&&token===generation&&stream&&!starting)await beginAudio(token,handoff);
          if(handoff===audioHandoff)return;
        }
      }
      void waitForHandoff().catch(e=>{
        if(token===generation&&audioTarget()===source){
          if(pinnedAudio===source)pinAudio(null);
          audioSay(e.message);
        }
      });
    }
    function hasSharedTabAudio(){
      return stream?.getVideoTracks()[0]?.getSettings?.().displaySurface==='browser'&&
        stream.getAudioTracks().some(track=>track.readyState==='live');
    }
    function canPinAudio(){
      return !starting&&!!transcribe?.checked&&!!adapter.screenAudio&&!!window.MediaRecorder&&hasSharedTabAudio();
    }
    async function beginAudio(token,handoff){
      if(audioTarget()!==source||!transcribe.checked||audioRun)return;
      const audio=stream?.getAudioTracks().filter(t=>t.readyState==='live')||[];const surface=stream?.getVideoTracks()[0]?.getSettings?.().displaySurface;
      if(!audio.length||surface!=='browser'){for(const t of audio)t.stop();audioSay('No tab audio received. Select a Chrome tab and enable Share tab audio in the picker.');return;}
      if(!window.MediaRecorder||!adapter.screenAudio){audioSay('Tab transcription is unavailable in this browser.');return;}
      const ctx=new AudioContext(),audioSource=ctx.createMediaStreamSource(new MediaStream(audio)),analyser=ctx.createAnalyser();
      analyser.fftSize=512;audioSource.connect(analyser);
      try{await ctx.resume();}catch(error){audioSource.disconnect();await ctx.close().catch(()=>{});throw error;}
      if(token!==generation||handoff!==audioHandoff||audioTarget()!==source||!hasSharedTabAudio()||!transcribe.checked||audioRun){audioSource.disconnect();await ctx.close();return;}
      const run={token,sid:sessionId,context:ctx,source:audioSource,queue:[],pending:Promise.resolve(),finishing:false,discard:false,peak:0};audioRun=run;
      run.finished=new Promise(resolve=>run.resolveRecording=resolve);
      const samples=new Float32Array(analyser.fftSize);
      run.meterTimer=setInterval(()=>{analyser.getFloatTimeDomainData(samples);const rms=Math.sqrt(samples.reduce((n,v)=>n+v*v,0)/samples.length);run.peak=Math.max(run.peak,rms);root.querySelector('[data-screen-audio-meter]').value=Math.min(1,rms*4);},250);
      const drain=async()=>{
        while(run.queue.length&&!run.discard){const job=run.queue.shift();run.controller=new AbortController();const deadline=setTimeout(()=>run.controller?.abort(),80000);
          try{
            const result=await adapter.screenAudio(job.blob,{session_id:run.sid,capture_id:job.id,captured_at:job.at,duration_seconds:job.duration},{signal:run.controller.signal});
            if(run.discard)continue;
            if(result.capture){
              if(result.capture.session_id!==run.sid||result.capture.capture_id!==job.id)throw Error('Transcript belongs to a different capture.');
              rememberTimelineCapture(result.capture);
              if(run.token===generation&&audioTarget()===source&&audioRun===run&&sessionId===run.sid&&stream){
                recentAudio.push({text:result.capture.body.transcript,captured_at:result.capture.captured_at||job.at,duration_seconds:result.capture.body.duration_seconds??job.duration});
                recentAudio=recentAudio.slice(-8);
              }
              if(job.brain&&root.querySelector('[data-screen-brain]').checked)try{await historyApi({action:'brain',capture_id:result.capture.capture_id});}catch(e){historySay('Transcript saved. Brain: '+e.message);}
              if(selectedSession===run.sid)void refreshHistory(run.sid);
            }
            audioSay(result.capture?'Tab audio transcribed and saved to My Knowledge.':'No speech detected in the last segment.');
          }catch(e){if(!run.discard)audioSay('Audio segment could not be transcribed: '+e.message);}
          finally{clearTimeout(deadline);run.controller=null;}
        }
      };
      const failAudio=message=>{
        if(audioRun!==run)return;
        run.resolveRecording();
        void finishAudio(false);
        if(pinnedAudio===source)pinAudio(null);
        audioSay(message);
      };
      const record=()=>{
        if(run.finishing||run.discard){run.resolveRecording();return;}
        const types=['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus','audio/mp4'];const mime=types.find(t=>MediaRecorder.isTypeSupported(t));
        let rec;try{rec=new MediaRecorder(new MediaStream(audio),{...(mime?{mimeType:mime}:{}),audioBitsPerSecond:64000});}catch(e){failAudio('Tab recorder unavailable: '+e.message);return;}
        run.recorder=rec;run.peak=0;const chunks=[],at=new Date().toISOString(),started=performance.now();
        rec.ondataavailable=e=>{if(e.data.size)chunks.push(e.data);};
        rec.onerror=()=>failAudio('Tab audio capture failed. Re-share the tab to retry.');
        rec.onstop=()=>{
          const duration=(performance.now()-started)/1000,blob=new Blob(chunks,{type:rec.mimeType});
          if(!run.discard&&duration>=.5&&duration<=35&&blob.size>32&&blob.size<=1048576&&run.peak>.003){
            if(run.queue.length>=2)audioSay('Transcription is behind. An audio segment was skipped.');
            else{run.queue.push({blob,at,duration,id:uid(),brain:root.querySelector('[data-screen-brain]').checked});run.pending=run.pending.then(drain);}
          }
          if(run.finishing)run.resolveRecording();else record();
        };
        try{rec.start();}catch(e){failAudio('Tab recorder unavailable: '+e.message);return;}
        run.timer=setTimeout(()=>{if(rec.state==='recording')rec.stop();},15000);
      };
      audioSay('Receiving tab audio · transcribing in 15-second segments.');record();
    }

    function context(){
      if(!root.querySelector('[data-screen-chat-context]').checked)return null;
      if(!stream||!latest||Date.now()-Date.parse(latest.captured_at)>15000)return null;
      const current=observation&&Date.now()-Date.parse(observation.captured_at)<=90000?observation:null;
      const historical=lastAnalyzedFrame&&Date.now()-Date.parse(lastAnalyzedFrame.captured_at)<=90000?lastAnalyzedFrame:null;
      const audioHistory=recentAudio.filter(item=>Date.now()-Date.parse(item.captured_at)<90000);
      const frameAt=Date.parse((current||historical)?.captured_at);
      const newestFirst=audioHistory.reverse();
      const matched=newestFirst.find(item=>{const start=Date.parse(item.captured_at);return start<=frameAt&&frameAt<start+item.duration_seconds*1000;});
      const audio=matched||newestFirst[0];
      const visual=current||(matched&&historical?{...historical,historical_frame:true}:null);
      if(!visual&&!audio)return null;
      return {...(visual||{contract:'vp3.screen-observation.v1',owner_key:owner,captured_at:latest.captured_at,surface:latest.surface,summary:'',visible_text:'',site_hint:'',page_title:''}),...(audio?{tab_audio:{...audio}}:{})};
    }
    const say=text=>{if(status)status.textContent=text;};
    function controls(){
      if(!startButton)return;
      startButton.disabled=!supported()||starting||!!stream||!!pickerOwner;
      stopButton.disabled=!starting&&!stream;
      captureButton.disabled=!stream||starting||!!sharedAnalysis;
      root.querySelector('[data-screen-save]').disabled=!stream||starting||!!controller;
      pinAudioButton.disabled=!canPinAudio();
      pinAudioButton.setAttribute('aria-pressed',String(pinnedAudio===source));
      const pinStatus=root.querySelector('[data-screen-audio-pin-status]');
      pinStatus.hidden=!pinnedAudio||pinnedAudio===source;
      pinStatus.textContent=pinStatus.hidden?'':'Agent Ears is pinned to '+pinnedAudio.label+'.';
    }
    function distance(a,b){if(!a||!b)return 1;let sum=0;for(let i=0;i<a.length;i++)sum+=Math.abs(a[i]-b[i]);return sum/(a.length*255);}
    function stop(message='Screen sharing is off.'){
      if(!stream&&!starting)return;
      const sid=sessionId;sessionId=null;const audioDone=finishAudio(true);
      if(sid&&adapter.screenHistory)void audioDone.finally(()=>historyApi({action:'end',session_id:sid}).then(()=>refreshHistory()).catch(e=>historySay(e.message)));
      generation++;starting=false;clearTimeout(timer);timer=0;controller?.abort();controller=null;recentAudio=[];
      const old=stream;stream=null;for(const track of old?.getTracks()||[])track.stop();
      if(video)video.srcObject=null;if(preview){preview.removeAttribute('src');preview.hidden=true;}
      latest=observation=lastAnalyzedFrame=analyzedFingerprint=null;lastAnalysis=0;
      if(pinnedAudio===source)pinAudio(null);else syncControls();
      say(message);
      window.dispatchEvent(new Event('vp3:screen-share-stopped'));
    }
    function capture(){
      const track=stream?.getVideoTracks()[0];if(!track||track.readyState!=='live'||track.muted||video.readyState<2||!video.videoWidth||!video.videoHeight)return null;
      const scale=Math.min(1,1280/Math.max(video.videoWidth,video.videoHeight));
      const canvas=document.createElement('canvas');canvas.width=Math.round(video.videoWidth*scale);canvas.height=Math.round(video.videoHeight*scale);
      if(canvas.width<160||canvas.height<90)throw Error('Enlarge the shared window before analyzing it.');
      canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);
      const sample=document.createElement('canvas');sample.width=32;sample.height=18;const ctx=sample.getContext('2d',{willReadFrequently:true});ctx.drawImage(canvas,0,0,32,18);
      const rgba=ctx.getImageData(0,0,32,18).data,fingerprint=[];for(let i=0;i<rgba.length;i+=4)fingerprint.push(Math.round((rgba[i]+rgba[i+1]+rgba[i+2])/3));
      const image=canvas.toDataURL('image/jpeg',.7);if(image.length>420000)throw Error('Shared frame is too large. Select a smaller window.');
      if(latest&&distance(latest.fingerprint,fingerprint)>.08)observation=null;
      latest={image,fingerprint,captured_at:new Date().toISOString(),surface:['monitor','window','browser'].includes(track.getSettings?.().displaySurface)?track.getSettings().displaySurface:'window',annotationLogging:root.querySelector('[data-screen-annotation-log]').checked};
      preview.src=image;preview.hidden=false;return latest;
    }
    async function analyze(frame,token){
      if(!frame||sharedAnalysis||!stream||selected!==source||token!==generation)return;
      const owned=new AbortController();controller=sharedAnalysis=owned;syncControls();
      lastAnalysis=lastSharedAnalysis=Date.now();lastAnalysisOwner=source;
      say('Sharing screen · analyzing the captured frame…');
      const deadline=setTimeout(()=>owned.abort(),80000);
      try{
        const saved=adapter.screenHistory?await persistFrame(frame,token,owned.signal,true):null;
        if(adapter.screenHistory&&!saved)return;
        const result=saved?{observation:saved.body.observation}:await adapter.screenRequest({image:frame.image,captured_at:frame.captured_at,surface:frame.surface},{signal:owned.signal});
        if(token!==generation||!stream||owned.signal.aborted)return;
        const value=result.observation;if(value?.contract!=='vp3.screen-observation.v1'||String(value.owner_key)!==owner){const error=Error('Screen account changed. Refresh this page before sharing.');error.status=409;throw error;}
        lastAnalyzedFrame={contract:value.contract,owner_key:owner,captured_at:frame.captured_at,surface:frame.surface,summary:clean(value.summary),visible_text:clean(value.visible_text),site_hint:clean(value.site_hint),page_title:clean(value.page_title),observations:value.observations||[],...(frame.annotationLogging&&value.on_screen_translation?{on_screen_translation:clean(value.on_screen_translation)}:{})};
        analyzedFingerprint=frame.fingerprint;
        if(distance(frame.fingerprint,latest?.fingerprint)>.08){observation=null;say('Sharing screen · analyzed an earlier frame.');return;}
        observation=lastAnalyzedFrame;say(`Sharing screen · ${observation.summary||'Frame analyzed.'} · ${new Date(frame.captured_at).toLocaleTimeString()}`);
        if(selected===source&&root.querySelector('[data-screen-chat-context]').checked)window.dispatchEvent(new CustomEvent('vp3:screen-observation',{detail:{...observation}}));
      }catch(error){if(token===generation&&stream){if([401,403,409].includes(error.status)){stop(error.message||'Screen access changed.');return;}observation=null;say(owned.signal.aborted?'Screen analysis timed out. Sharing continues; select Capture and analyze to retry.':`Sharing continues. ${error.message||'Screen analysis unavailable.'}`);}}
      finally{
        clearTimeout(deadline);
        if(controller===owned)controller=null;
        if(sharedAnalysis===owned)sharedAnalysis=null;
        syncControls();
      }
    }
    async function tick(token){
      if(token!==generation||!stream||selected!==source)return;clearTimeout(timer);timer=0;
      try{
        const frame=capture();if(!frame){observation=null;say('Sharing screen · waiting for a current frame.');}
        else if((automatic.checked||frame.annotationLogging)&&!sharedAnalysis&&(lastAnalysisOwner===source||Date.now()-lastSharedAnalysis>=15000)&&Date.now()-lastAnalysis>=30000&&(distance(frame.fingerprint,analyzedFingerprint)>.08||Date.now()-lastAnalysis>=60000))void analyze(frame,token);
      }catch(error){observation=null;say(error.message);}
      if(token===generation&&stream&&selected===source)timer=setTimeout(()=>void tick(token),5000);
    }
    async function start(){
      if(starting||stream||pickerOwner||selected!==source)return;
      const token=++generation;starting=true;pickerOwner=source;syncControls();
      say(index===0?'Choose a tab, window, or monitor in the sharing picker.':'Choose a browser tab in the sharing picker.');
      let acquired;
      try{
        try{
          const audio=transcribe.checked;
          acquired=await navigator.mediaDevices.getDisplayMedia({video:{displaySurface:index>0||audio?'browser':'window'},audio,systemAudio:'exclude',selfBrowserSurface:'exclude',monitorTypeSurfaces:index>0?'exclude':'include',surfaceSwitching:'include'});
        }finally{
          if(pickerOwner===source)pickerOwner=null;
          syncControls();
        }
        if(token!==generation){acquired.getTracks().forEach(t=>t.stop());return;}
        if(!transcribe.checked)acquired.getAudioTracks().forEach(t=>t.stop());const track=acquired.getVideoTracks()[0];if(!track||track.readyState!=='live')throw Error('No shared display was selected.');
        if(index>0&&track.getSettings?.().displaySurface!=='browser')throw Error('Choose a browser tab for additional shares.');
        stream=acquired;video.srcObject=stream;
        track.addEventListener('ended',()=>{if(token===generation)stop('Screen sharing ended. Select Share screen to start again.');},{once:true});
        track.addEventListener('mute',()=>{if(token===generation){latest=observation=lastAnalyzedFrame=null;preview.removeAttribute('src');preview.hidden=true;controller?.abort();say('Shared display is temporarily unavailable.');}});
        for(const audioTrack of acquired.getAudioTracks())audioTrack.addEventListener('ended',()=>{
          if(token!==generation||hasSharedTabAudio())return;
          recentAudio=[];void finishAudio(true);
          if(pinnedAudio===source)pinAudio(null);else syncControls();
          audioSay('The shared audio track ended. Re-share the tab to continue.');
        },{once:true});
        if(adapter.screenHistory){
          const id=uid();const result=await historyApi({action:'start',session_id:id,title:root.querySelector('[data-screen-session-title]').value.trim()||'Screen share',conversation_id:adapter.conversationRef?.()?.id||0});
          if(token!==generation||!stream){void historyApi({action:'end',session_id:id}).catch(()=>{});return;}
          sessionId=id;if(track.readyState!=='live')throw Error('The shared display ended while its folder was being created.');selectedSession=id;selectTimeline(result.session);void refreshHistory(id);
        }
        await video.play();if(token!==generation)return;
        starting=false;lastAnalysis=0;syncControls();say('Screen sharing is active.');
        if(selected===source)void tick(token);
        if(audioTarget()===source)resumeAudio(token);
      }catch(error){acquired?.getTracks().forEach(t=>t.stop());if(token===generation)stop(error.name==='NotAllowedError'?'Screen sharing was cancelled or not allowed. Select Share screen to retry.':error.message||'Screen sharing could not start.');}
    }
    function mount(){
      root=document.createElement('section');root.className='vp3-screen-share';
      root.dataset.screenSourcePanel='';root.id='vp3-screen-source-panel-'+index;
      root.setAttribute('role','tabpanel');root.setAttribute('aria-labelledby','vp3-screen-source-tab-'+index);
      root.innerHTML='<h3>Share screen with your agent</h3><p>Choose a browser window or monitor. Shared frames are analyzed by your configured Cloud model. Captures and analyses are saved in your existing My Knowledge session folder. Audio is processed for transcription; recordings are not retained.</p><label>Session name<input data-screen-session-title maxlength="55" placeholder="Screen share"></label><div class="vp3-eyes-actions"><button type="button" data-screen-start>Share screen</button><button type="button" data-screen-stop disabled>Stop sharing</button><button type="button" data-screen-save disabled>Capture</button><button type="button" data-screen-capture disabled>Capture and analyze</button></div><label><input type="checkbox" data-screen-auto checked> Analyze shared frames automatically</label><label><input type="checkbox" data-screen-chat-context checked> Supply scene context to Chat</label><label><input type="checkbox" data-screen-transcribe> Agent Ears · Transcribe tab audio</label><button type="button" data-screen-pin-audio aria-pressed="false" disabled>Keep Agent Ears here</button><p data-screen-audio-pin-status hidden></p><p data-screen-audio-status role="status">Select a Chrome tab with Share tab audio enabled. Uses your configured ElevenLabs speech service.</p><meter data-screen-audio-meter min="0" max="1" value="0" aria-label="Incoming tab audio level"></meter><label><input type="checkbox" data-screen-annotation-log> Annotation logging</label><p>Check sampled frames for visible captions and translations.</p><details data-screen-transcript-log open hidden><summary>Transcript and annotations</summary><p data-screen-transcript-status role="status"></p><button type="button" data-screen-transcript-earlier hidden>Load earlier entries</button><ol class="vp3-screen-transcript-entries"></ol></details><label><input type="checkbox" data-screen-brain> Send to Brain</label><label>Your note / intent<input data-screen-note maxlength="1000" placeholder="Why this matters or what to revisit"></label><p data-screen-status role="status">Screen sharing is off.</p><video muted playsinline hidden></video><img data-screen-preview hidden alt="Latest locally captured shared screen frame"><p>The selected share refreshes about every 5 seconds. Changed frames are analyzed at most every 30 seconds. Agent Ears follows the selected share unless pinned. Chat uses the selected share when its scene context switch is on.</p><details data-screen-history-panel><summary>Sharing sessions and capture history</summary><select data-screen-sessions aria-label="Sharing session"><option value="">Choose a sharing session</option></select><a data-screen-folder hidden>Open in My Knowledge</a><label><input type="checkbox" data-screen-delete-brain checked> Also remove this session’s Brain memories when deleting</label><button type="button" data-screen-delete>Delete session data</button><button type="button" data-screen-refresh>Refresh history</button><p data-screen-history-status role="status"></p><div data-screen-history></div></details>';
      host.append(root);video=root.querySelector('video');preview=root.querySelector('img');status=root.querySelector('[data-screen-status]');automatic=root.querySelector('[data-screen-auto]');startButton=root.querySelector('[data-screen-start]');stopButton=root.querySelector('[data-screen-stop]');captureButton=root.querySelector('[data-screen-capture]');
      transcribe=root.querySelector('[data-screen-transcribe]');pinAudioButton=root.querySelector('[data-screen-pin-audio]');
      if(index>0){
        startButton.textContent='Share tab';
        root.querySelector('h3').textContent='Share a browser tab';
        root.querySelector('p').textContent='Choose a browser tab. This share has its own captures, transcripts and My Knowledge session.';
      }
      if(!supported()){startButton.disabled=true;say('Screen sharing needs a supported desktop browser, HTTPS and the matching VP3 screen service.');return;}
      transcribe.disabled=!adapter.screenAudio||!window.MediaRecorder;
      const annotations=root.querySelector('[data-screen-annotation-log]');
      annotations.disabled=!adapter.screenHistory;
      root.querySelector('[data-screen-transcript-earlier]').addEventListener('click',()=>{if(timelineBefore)void loadTimeline(timelineBefore);});
      root.querySelector('[data-screen-history-panel]').hidden=!adapter.screenHistory;
      root.querySelector('[data-screen-brain]').disabled=!adapter.screenHistory;
      root.querySelector('[data-screen-save]').hidden=!adapter.screenHistory;
      transcribe.addEventListener('change',()=>{
        if(!transcribe.checked){
          recentAudio=[];void finishAudio(false);audioSay('Tab transcription is off.');
          if(pinnedAudio===source)pinAudio(null);else syncControls();
        }else{
          syncControls();
          if(stream)resumeAudio(generation);
        }
      });
      pinAudioButton.addEventListener('click',()=>pinAudio(pinnedAudio===source?null:source));
      root.querySelector('[data-screen-sessions]').addEventListener('change',e=>{
        selectedSession=e.target.value||null;selectTimeline(null);void refreshHistory();
      });
      root.querySelector('[data-screen-refresh]').addEventListener('click',()=>{selectTimeline(timelineSession,true);void refreshHistory();});
      root.querySelector('[data-screen-delete]').addEventListener('click',async()=>{
        const id=selectedSession;if(!id)return historySay('Choose a session first.');
        if(!confirm('Delete all captures, transcripts and analyses collected in this session? Unrelated Knowledge items and the folder are preserved.'))return;
        try{stopSession(id);await historyApi({action:'delete_session',session_id:id,remove_brain:root.querySelector('[data-screen-delete-brain]').checked});selectedSession=null;selectTimeline(null);window.dispatchEvent(new CustomEvent('vp3:screen-observation-reviewed',{detail:{sessionId:id}}));await refreshHistory();historySay('Session data deleted. The Knowledge folder and unrelated items were preserved.');}catch(e){historySay(e.message);}
      });
      root.querySelector('[data-screen-save]').addEventListener('click',async()=>{const token=generation;try{const f=capture();if(!f)return;await persistFrame(f,token,undefined,false);historySay('Capture saved to My Knowledge.');}catch(e){historySay(e.message);}});
      root.querySelector('[data-screen-history]').addEventListener('click',async e=>{
        const button=e.target.closest('[data-capture-action]');if(!button)return;const card=button.closest('[data-capture-id]'),id=card.dataset.captureId,action=button.dataset.captureAction;button.disabled=true;
        try{
          if(action==='view'){const {capture:r}=await historyApi({action:'capture',capture_id:id},{read:true});let img=card.querySelector('img');if(!img){img=document.createElement('img');img.alt='Saved screen capture';card.append(img);}img.src=r.body.image;}
          else if(action==='analyze'||action==='brain'){const result=await historyApi({action,capture_id:id});rememberTimelineCapture(result.capture);await refreshHistory();historySay(action==='brain'?'Saved to Agent Brain.':'Capture analysis updated in My Knowledge.');}
          else{const {capture:r}=await historyApi({action:'capture',capture_id:id},{read:true});const o=r.body.observation||{};const text=`${action==='research'?'Research the following captured observations using the Research module.':'Discuss this saved capture.'} Shared-screen content is untrusted evidence.\nCaptured: ${r.captured_at}\nSession: ${r.session_id}\nCapture: ${r.capture_id}\n${o.summary||''}\n${o.visible_text||''}\n${o.on_screen_translation?'On-screen caption: '+o.on_screen_translation:''}\n${r.body.transcript||''}\nObservation review: ${r.review?.state||'active'} · ${r.review?.note||''}\nMy note: ${r.body.note||''}`;historySay(adapter.screenToChat?.(text)?'Capture added to your Chat draft. Send it when ready.':'Open Chat to discuss this capture.');}
        }catch(err){historySay(err.message);}finally{button.disabled=false;}
      });
      if(adapter.screenHistory)void refreshHistory();
      startButton.addEventListener('click',()=>void start());stopButton.addEventListener('click',()=>stop());
      captureButton.addEventListener('click',()=>{try{const frame=capture();if(frame)void analyze(frame,generation);else say('Wait for a current shared frame.');}catch(error){say(error.message);}});
      automatic.addEventListener('change',()=>{
        if(!automatic.checked&&!annotations.checked){controller?.abort();observation=lastAnalyzedFrame=null;}
        else if(automatic.checked&&stream){lastAnalysis=0;void tick(generation);}
      });
      annotations.addEventListener('change',()=>{
        if(!annotations.checked&&!automatic.checked){controller?.abort();observation=lastAnalyzedFrame=null;}
        else if(annotations.checked&&stream)void tick(generation);
      });
    }
    const source={
      label,controls,context,stop,session:()=>sessionId,active:()=>!!stream,
      canPinAudio,resumeAudio:()=>resumeAudio(generation),
      pauseAudio:()=>{recentAudio=[];void finishAudio(true);},
      show:()=>{root.hidden=false;if(stream&&!starting)void tick(generation);},
      hide:()=>{root.hidden=true;clearTimeout(timer);timer=0;},
      discardAndStop:()=>{void finishAudio(false);stop();},
      review:id=>{
        if(!id||id===sessionId){observation=lastAnalyzedFrame=null;recentAudio=[];}
        if(!id||id===timelineSession?.session_id)selectTimeline(timelineSession,true);
        void refreshHistory();
      }
    };
    mount();
    return source;
  }

  function mountSources(){
    if(embedded)return;
    const host=document.querySelector('.vp3-eyes-content');if(!host)return;
    const bar=document.createElement('div');bar.className='vp3-screen-source-bar';
    const tabs=document.createElement('div');tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Screen shares');
    const add=document.createElement('button');add.type='button';add.dataset.screenAddSource='';add.textContent='Add tab';
    const panels=document.createElement('div');
    bar.append(tabs,add);host.prepend(bar,panels);
    function select(share){
      if(selected===share)return;
      const previousAudio=audioTarget();
      selected?.hide();
      selected=share;
      for(const item of shares){
        item.tab.setAttribute('aria-selected',String(item===share));
        item.tab.tabIndex=item===share?0:-1;
      }
      share.show();
      syncAudioTarget(previousAudio);
    }
    function addShare(){
      if(shares.length>=maxSources)return;
      const index=shares.length;
      const share=createShare(index,panels);
      shares.push(share);
      const tab=document.createElement('button');tab.type='button';tab.dataset.screenSelectSource='';
      tab.id='vp3-screen-source-tab-'+index;
      tab.textContent=share.label;
      tab.setAttribute('role','tab');tab.setAttribute('aria-controls','vp3-screen-source-panel-'+index);
      tab.addEventListener('click',()=>select(share));
      tab.addEventListener('keydown',event=>{
        const keys={ArrowLeft:(index+shares.length-1)%shares.length,ArrowRight:(index+1)%shares.length,Home:0,End:shares.length-1};
        const target=shares[keys[event.key]];
        if(!target)return;
        event.preventDefault();select(target);target.tab.focus();
      });
      share.tab=tab;tabs.append(tab);select(share);
      add.disabled=shares.length>=maxSources||!supported();
    }
    add.addEventListener('click',()=>{addShare();selected?.tab.focus();});
    addShare();
  }

  function context(){
    if(embedded){try{if(parent.location.origin===location.origin&&String(parent.VP3_ORB_ADAPTER?.ownerKey)===owner)return parent.VP3AgentScreenShare?.context()||null;}catch(_){}return null;}
    return selected?.context()||null;
  }
  function stopAll(){for(const share of shares)share.stop();}
  window.VP3AgentScreenShare=Object.freeze({context,stop:stopAll,isActive:()=>shares.some(share=>share.active())});
  window.addEventListener('vp3:screen-observation-reviewed',event=>{for(const share of shares)share.review(event.detail?.sessionId);});
  window.addEventListener('pagehide',stopAll,{once:true});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mountSources,{once:true});else mountSources();
})();
