import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';

const source=await fs.readFile(process.argv[2]||new URL('../agent-screen-share.js',import.meta.url),'utf8');
const mount='    mount();\n    return source;';
assert.ok(source.includes(mount),'Find the production share setup');
const script=source.replace(mount,`    ({root,video,preview,status,startButton,stopButton,captureButton,postReportButton,automatic,transcribe,pinAudioButton}=fixture.dom());
    source.start=start;
    return source;`).replace('  window.VP3AgentScreenShare=Object.freeze',`  window.createFixtureShare=(index)=>{
    const share=createShare(index,{});
    selected=share;
    shares.push(share);
    return share;
  };
  window.VP3AgentScreenShare=Object.freeze`);

function fixture({mainChat=true,enabled=true,surface='browser',audio=false,denied=false,index=0,result='music',amplitude=.05,defer=false}={}) {
  const nodes=new Map(),requests=[],audioRequests=[],captures=[],timers=new Map();
  let pickerInfo='',time=0;
  let recording,resolveAudio;
  const pendingAudio=new Promise(resolve=>{resolveAudio=resolve;});
  const element=selector=>({
    textContent:'',value:'',checked:false,dataset:{},attrs:{},items:[],
    setAttribute(name,value){this.attrs[name]=value;},
    addEventListener(){},removeAttribute(){},replaceChildren(...items){this.items=items;},
    append(...items){this.items.push(...items);},add(){},setCustomValidity(){},
    querySelector:child=>node(selector+' '+child),
  });
  const node=selector=>{
    if(!nodes.has(selector))nodes.set(selector,element(selector));
    return nodes.get(selector);
  };
  const dom=()=>({
    root:{querySelector:node},video:{play:async()=>{},readyState:0,videoWidth:0,videoHeight:0},
    preview:node('img'),status:node('[data-screen-status]'),startButton:node('[data-screen-start]'),
    stopButton:node('[data-screen-stop]'),captureButton:node('[data-screen-capture]'),postReportButton:node('[data-screen-post-report]'),
    automatic:node('[data-screen-auto]'),transcribe:node('[data-screen-transcribe]'),pinAudioButton:node('[data-screen-pin-audio]'),
  });
  node('[data-screen-transcribe]').checked=enabled;
  const audioTrack={readyState:'live',addEventListener(){},stop(){this.readyState='ended';}};
  const videoTrack={readyState:'live',getSettings:()=>({displaySurface:surface}),addEventListener(){},stop(){this.readyState='ended';}};
  const stream={getVideoTracks:()=>[videoTrack],getAudioTracks:()=>audio?[audioTrack]:[],getTracks:()=>audio?[videoTrack,audioTrack]:[videoTrack]};
  const session={session_id:'7837d000-0000-4000-8000-001001001001',status:'active',title:'Session',created_at:new Date().toISOString(),agent_id:0};
  class MediaRecorder {
    static isTypeSupported(){return true;}
    constructor(){this.state='inactive';this.mimeType='audio/webm';}
    start(){this.state='recording';recording=this;}
    stop(){
      this.state='inactive';
      this.ondataavailable({data:new Blob([new Uint8Array(128)])});
      this.onstop();
    }
  }
  class AudioContext {
    createMediaStreamSource(){return {connect(){},disconnect(){}};}
    createAnalyser(){return {getFloatTimeDomainData(buffer){buffer.fill(amplitude);}};}
    async resume(){}
    async close(){}
  }
  const window={MediaRecorder,addEventListener(){},dispatchEvent(){},VP3_ORB_ADAPTER:{
    ownerKey:'7',isMainChat:()=>mainChat,screenRequest:async()=>{throw Error('Visual frame should not be captured in this fixture');},
    screenAudio:async(body,details)=>{
      audioRequests.push(details);
      if(defer)await pendingAudio;
      if(result==='error')throw Error('Transcription service temporarily unavailable.');
      if(result==='empty')return {capture:null};
      const event={label:'music',start_seconds:0,end_seconds:3,provider:'elevenlabs',model:'scribe_v2',source:'shared_tab_audio',classification:'provider_label'};
      const capture={capture_id:details.capture_id,session_id:details.session_id,captured_at:details.captured_at,
        kind:'tab_audio',body:{transcript:result==='speech'?'A spoken phrase from the video.':'',duration_seconds:details.duration_seconds,audio_events:[event]}};
      captures.push(capture);
      return {capture};
    },
    screenHistory:async body=>{
      if(body.action==='start')return {session};
      if(body.action==='list')return {sessions:[session]};
      if(body.action==='timeline'||body.action==='history')return {session,captures};
      if(body.action==='end')return {ended:true};
      throw Error('Unexpected sharing action '+body.action);
    },
  }};
  const navigator={mediaDevices:{getDisplayMedia:async options=>{
    requests.push(JSON.parse(JSON.stringify(options)));
    pickerInfo=node('[data-screen-status]').textContent+' '+node('[data-screen-audio-status]').textContent;
    if(denied)throw new DOMException('', 'NotAllowedError');
    return stream;
  },getUserMedia(){throw Error('Agent Ears must use tab audio');}}};
  const context=vm.createContext({
    window,parent:window,location:{search:'',pathname:mainChat?'/chat.php':'/knowledge.php'},navigator,URLSearchParams,
    document:{readyState:'loading',addEventListener(){},createElement:element,createDocumentFragment:()=>element('fragment')},
    fixture:{dom},isSecureContext:true,Event,CustomEvent:Event,DOMException,AbortController,Blob,MediaRecorder,AudioContext,
    MediaStream:class{},Option:class{},crypto:{randomUUID:()=>session.session_id},performance:{now:()=>time},
    setTimeout:(fn,ms)=>{timers.set(ms,fn);return ms;},clearTimeout:id=>timers.delete(id),
    setInterval:(fn,ms)=>{timers.set(ms,fn);return ms;},clearInterval:id=>timers.delete(id),
  });
  vm.runInContext(script,context);
  const share=window.createFixtureShare(index);
  const settle=()=>new Promise(setImmediate);
  return {requests,audioRequests,audioTrack,nodes,share,start:async()=>{await share.start();await settle();},
    setAmplitude:value=>{amplitude=value;},setResult:value=>{result=value;},
    releaseAudio:async()=>{resolveAudio();await settle();},
    status:()=>node('[data-screen-audio-status]').textContent,
    pickerInfo:()=>pickerInfo,finishSegment:async()=>{
      assert.ok(recording&&timers.has(250),'Agent Ears started recording and metering');
      timers.get(250)();
      time+=15000;
      recording.stop();
      await settle();
    }};
}

const chatWithEars=fixture();
assert.equal(chatWithEars.requests.length,0,'No display permission is requested before the Share action');
await chatWithEars.start();
assert.equal(chatWithEars.requests[0].video.displaySurface,'browser');
assert.deepEqual(chatWithEars.requests[0].audio,{restrictOwnAudio:false,suppressLocalAudioPlayback:false});
assert.equal(chatWithEars.requests[0].systemAudio,'exclude');
assert.equal(chatWithEars.requests[0].selfBrowserSurface,'include');
assert.equal(chatWithEars.requests[0].preferCurrentTab,true);
assert.match(chatWithEars.pickerInfo(),/Agent Chat|current tab|This Tab/i);
assert.match(chatWithEars.pickerInfo(),/share tab audio/i);
assert.match(chatWithEars.status(),/share tab audio/i,'The no-audio state points back to permission');
assert.equal(chatWithEars.audioRequests.length,0);

const plainChat=fixture({enabled:false});
await plainChat.start();
assert.equal(plainChat.requests[0].audio,false);
assert.equal(plainChat.requests[0].selfBrowserSurface,'exclude');
assert.notEqual(plainChat.requests[0].preferCurrentTab,true);

const otherSite=fixture({mainChat:false});
await otherSite.start();
assert.equal(otherSite.requests[0].audio,true);
assert.equal(otherSite.requests[0].selfBrowserSurface,'exclude');
assert.notEqual(otherSite.requests[0].preferCurrentTab,true);

const additionalShare=fixture({index:1,enabled:false});
await additionalShare.start();
assert.equal(additionalShare.requests[0].video.displaySurface,'browser');
assert.equal(additionalShare.requests[0].monitorTypeSurfaces,'exclude');

const additionalWindow=fixture({index:1,surface:'window',audio:true});
await additionalWindow.start();
assert.equal(additionalWindow.share.active(),false,'Additional shares remain browser-tab only');
assert.equal(additionalWindow.audioTrack.readyState,'ended');
assert.equal(additionalWindow.audioRequests.length,0);

const windowAudio=fixture({surface:'window',audio:true});
await windowAudio.start();
assert.equal(windowAudio.audioTrack.readyState,'ended','Audio from a non-tab share is released');
assert.equal(windowAudio.audioRequests.length,0);

const cancelled=fixture({denied:true});
await cancelled.start();
assert.match(cancelled.nodes.get('[data-screen-status]').textContent,/cancelled or not allowed/i);
assert.equal(cancelled.share.active(),false);
assert.equal(cancelled.audioRequests.length,0);

const receiving=fixture({audio:true});
await receiving.start();
assert.match(receiving.status(),/receiving tab audio/i);
await receiving.finishSegment();
assert.equal(receiving.audioRequests.length,1);
assert.match(receiving.status(),/sound|music/i,'An audio-event-only result is distinguished from a speech transcript');
const soundEntries=receiving.nodes.get('[data-screen-transcript-log] ol').items[0].items;
assert.equal(soundEntries.length,1);
assert.deepEqual(soundEntries[0].attrs,{'data-screen-sound-entry':''});
assert.equal(soundEntries[0].items.at(-1).textContent,'music');

const speaking=fixture({audio:true,result:'speech'});
await speaking.start();
await speaking.finishSegment();
assert.equal(speaking.audioRequests.length,1);
assert.match(speaking.status(),/transcribed and saved/i);
const speechEntries=speaking.nodes.get('[data-screen-transcript-log] ol').items[0].items;
assert.equal(speechEntries.length,1);
assert.deepEqual(speechEntries[0].attrs,{'data-screen-transcript-entry':''});
assert.equal(speechEntries[0].items.at(-1).textContent,'A spoken phrase from the video.');

const silent=fixture({audio:true,amplitude:0});
await silent.start();
await silent.finishSegment();
assert.equal(silent.audioRequests.length,0);
assert.match(silent.status(),/No audio reached Agent Ears/i);
silent.setAmplitude(.05);
silent.setResult('speech');
await silent.finishSegment();
assert.equal(silent.audioRequests.length,1,'Playback resumes without reopening the sharing permission');
assert.match(silent.status(),/transcribed and saved/i);

const noSpeech=fixture({audio:true,result:'empty'});
await noSpeech.start();
await noSpeech.finishSegment();
assert.equal(noSpeech.audioRequests.length,1,'Audible audio is submitted even when the provider finds no speech');
assert.match(noSpeech.status(),/No speech or labelled sounds/i);
assert.doesNotMatch(noSpeech.status(),/No audio reached/i,'Silence and an empty recognition result remain distinct');

const providerFailure=fixture({audio:true,result:'error'});
await providerFailure.start();
await providerFailure.finishSegment();
assert.match(providerFailure.status(),/could not be transcribed.*temporarily unavailable/i);
assert.equal(providerFailure.share.active(),true,'A provider error does not end the authorized share');
providerFailure.setResult('speech');
await providerFailure.finishSegment();
assert.equal(providerFailure.audioRequests.length,2);
assert.match(providerFailure.status(),/transcribed and saved/i);

const cancelledUpload=fixture({audio:true,result:'speech',defer:true});
await cancelledUpload.start();
await cancelledUpload.finishSegment();
assert.equal(cancelledUpload.audioRequests.length,1);
cancelledUpload.share.discardAndStop();
await cancelledUpload.releaseAudio();
assert.equal(cancelledUpload.share.active(),false);
assert.equal(cancelledUpload.audioTrack.readyState,'ended');
assert.doesNotMatch(cancelledUpload.status(),/transcribed and saved/i,'A late result cannot revive a discarded audio run');
console.log('AGENT_EARS_PICKER=PASS · explicit permission, same-tab playback, ordinary and additional shares, missing audio, permission cancellation, speech/music/silence, playback recovery, empty results, provider retry and discarded late response');
