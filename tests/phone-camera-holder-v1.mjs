import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../phone-camera-holder-v1.js',import.meta.url),'utf8');
const template = readFileSync(new URL('../phone-location.php',import.meta.url),'utf8');
assert.match(template,/geolocation=\(self\), camera=\(self\), microphone=\(\)/);
assert.ok(template.indexOf('phone-camera-ice-v1.js') < template.indexOf('phone-camera-holder-v1.js'));
const drain = () => new Promise(resolve => setImmediate(resolve));

function fixture({inScope,conflict=false,app=false,recorder} = {}) {
  const el = new Map();
  const document = Object.assign(new EventTarget(),{hidden:false,getElementById:id => {
    if (!el.has(id)) el.set(id,Object.assign(new EventTarget(),{hidden:true,textContent:'',dataset:{},setAttribute(){},focus(){},getClientRects(){return [];},pause(){},async play(){}}));
    return el.get(id);
  }});
  if (app) Object.assign(document.getElementById('phone-camera'),{tagName:'DIALOG',open:false,
    show(){this.open=true;},showModal(){this.open=true;},close(){this.open=false;}});
  const window = Object.assign(new EventTarget(),{isSecureContext:true,VP3PhoneRecording:recorder});
  const pending = {};
  const capture = new Promise((resolve,reject) => Object.assign(pending,{resolve,reject}));
  const track = Object.assign(new EventTarget(),{readyState:'live',stop(){this.readyState='ended';}});
  const navigator = {onLine:true,mediaDevices:{getUserMedia:opts => {ops.push(['capture',opts]);return capture;}}};
  const storage = new Map(), ops = [], timers = new Map();
  const peers = [];
  let tick, pc, camera, now = 100, fail = false, mapOfferFailure=false;
  class Peer extends EventTarget {
    constructor(config) {super(); this.config=config; this.connectionState='new'; pc=this; peers.push(this);}
    close() {this.connectionState='closed';}
    addTransceiver(track,opts) {ops.push(['transceiver',track,opts]);}
    async createOffer() {return {type:'offer',sdp:'video offer'};}
    async setLocalDescription(sdp) {this.localDescription=sdp;}
    async setRemoteDescription(sdp) {this.remoteDescription=sdp;}
  }
  window.RTCPeerConnection = Peer;
  window.VP3PhoneCameraICE={gather:async (peer,signal) => {signal.throwIfAborted(); return peer.localDescription.sdp;}};
  const ctx = {window,document,navigator,location:{pathname:'/vp3/phone-location.php'},sessionStorage:{
    getItem:key=>storage.get(key)??null,setItem:(key,val)=>storage.set(key,val),removeItem:key=>storage.delete(key)},
    setInterval:fn=>{tick=fn;},setTimeout:(fn,ms)=>{const id={fn,ms};timers.set(id,fn);return id;},
    clearTimeout:key=>timers.delete(key),RTCPeerConnection:Peer,AbortController,Date:{now:()=>now*1000}};
  vm.runInNewContext(source,ctx);
  const request = async (action,body={}) => {
    ops.push([action,body]);
    if (fail) throw new Error('Network interrupted');
    if (action==='camera_prepare') camera={session_id:'from-device',device_id:'phone-a',state:'requested',expires_at:now+80};
    if (action==='camera_start') {
      assert.equal(body.session_id,camera.session_id);
      camera.state='sharing';
      if (conflict) throw Object.assign(new Error('Already granted'),{status:409});
    }
    if (action==='camera_offer') assert.equal(body.sdp,'video offer');
    if (action==='camera_map_offer' && mapOfferFailure) throw new Error('Map dismissed');
    if (action==='camera_stop' && body.session_id===camera?.session_id) camera=null;
    return {camera:camera?{...camera}:null,ice_servers:[{urls:'stun:fixture.invalid'}]};
  };
  const client=window.VP3PhoneCameraHolder(request,inScope);
  const button=(name)=>document.getElementById('phone-camera-'+name);
  return {ops,el:button,track,client,peers,get peer(){return pc;},tick:()=>tick(),
    setViewer(surface,id,answer){camera[surface+'_viewer']=id ? {viewer_id:id,answer_sdp:answer} : null;},
    failMapOffer(){mapOfferFailure=true;},advance(seconds){now+=seconds;},
    fireViewerTimeout(){for(const [timer,fn] of [...timers])if(timer.ms===60000){timers.delete(timer);fn();}},
    click:name=>button(name).dispatchEvent(new Event('click')),
    request(id='session-a',device_id='phone-a'){camera={session_id:id,device_id,state:'requested',expires_at:now+80};},
    bind(id='phone-a'){client.bind(id,'Map owner');},
    capture(){pending.resolve({getVideoTracks:()=>[track],getTracks:()=>[track]});},
    deny(){pending.reject(Object.assign(new Error('Camera unavailable'),{name:'NotAllowedError'}));},
    answer(){Object.assign(camera,{answer_sdp:'editor answer',state:'answered'});},
    hide(){document.hidden=true; document.dispatchEvent(new Event('visibilitychange'));},
    revoke(){camera=null;},expire(){now+=81;},networkFail(){fail=true;}};
}

{
  const f=fixture(); f.request(); f.bind(); await drain();
  assert.equal(f.el('start').hidden,false);
  assert.match(f.el('status').textContent,/Map owner.*private editor/);
  assert.deepEqual(f.ops.map(x=>x[0]),['camera_status']);
  f.click('start'); f.capture(); await drain();
  assert.equal(f.ops.filter(x=>x[0]==='capture').length,1);
  assert.equal(f.ops.find(x=>x[0]==='capture')[1].audio,false);
  assert.equal(f.ops.find(x=>x[0]==='transceiver')[2].direction,'sendonly');
  assert.equal(f.el('preview').hidden,false);
  assert.equal(f.el('stop').hidden,false);
  assert.equal(f.peer.config.iceServers[0].urls,'stun:fixture.invalid');
  f.answer(); await f.tick();
  assert.equal(f.peer.remoteDescription.sdp,'editor answer');
  const heartbeats=f.ops.filter(([action,body])=>action==='camera_status' && body.heartbeat===true);
  assert.ok(heartbeats.length>0);
  assert.ok(heartbeats.every(([,body])=>body.session_id==='session-a'));
  f.click('stop');
  assert.equal(f.track.readyState,'ended'); assert.equal(f.peer.connectionState,'closed');
  assert.equal(f.el('preview').srcObject,null);
}
{
  const f=fixture(); f.request(); f.bind(); await drain();
  f.click('start'); f.click('stop'); f.capture(); await drain();
  assert.equal(f.track.readyState,'ended');
  assert.ok(!f.ops.some(x=>x[0]==='camera_start'||x[0]==='camera_offer'));
}
{
  const f=fixture(); f.request(); f.bind(); await drain();
  f.click('start'); f.deny(); await drain();
  assert.match(f.el('status').textContent,/permission was declined/);
  assert.ok(!f.ops.some(x=>x[0]==='camera_start'||x[0]==='camera_offer'));
}
{
  const f=fixture(); f.request('foreign','phone-b'); f.bind(); await drain();
  assert.equal(f.el('start').hidden,true);
  f.click('start'); assert.ok(!f.ops.some(x=>x[0]==='capture'));
}
{
  const f=fixture({conflict:true});
  f.request(); f.bind(); await drain(); f.click('start'); f.capture(); await drain();
  assert.equal(f.track.readyState,'ended','losing page releases its own camera');
  assert.ok(!f.ops.some(([action])=>action==='camera_stop'),'losing page leaves the winning source alone');
  await f.tick();
  assert.ok(!f.ops.some(([action,body])=>action==='camera_status' && body.heartbeat),'reading an active session cannot renew the source lease');
}
{
  let privateChat=true;
  const f=fixture({inScope:()=>privateChat});
  f.request(); f.bind(); await drain(); f.click('start');
  privateChat=false;
  f.capture(); await f.tick();
  assert.equal(f.track.readyState,'ended','a late camera prompt cannot run in another conversation');
  assert.ok(!f.ops.some(([action])=>action==='camera_start'||action==='camera_offer'));
  assert.ok(f.ops.every(([action,body])=>action!=='camera_stop'||body.requested_only===true));
}
for (const reason of ['hide','revoke','expire','networkFail','unbind','replacement']) {
  const f=fixture(); f.request(); f.bind(); await drain();
  f.click('start'); f.capture(); await drain();
  if (reason==='unbind') f.bind(null);
  else if (reason==='replacement') f.request('session-b');
  else f[reason]();
  await f.tick();
  assert.equal(f.track.readyState,'ended',reason);
  assert.equal(f.peer.connectionState,'closed',reason);
  assert.equal(f.el('preview').srcObject,null,reason);
  assert.equal(f.ops.filter(x=>x[0]==='capture').length,1,reason);
  if (reason==='replacement') {
    assert.equal(f.el('start').hidden,false);
    assert.ok(!f.ops.some(x=>x[0]==='camera_stop' && x[1].session_id==='session-b'));
  }
}
console.log('PASS Phone holder camera consent, send-only video, private answer, stale permission and lifecycle cleanup.');

{
  const f=fixture({app:true}); f.bind(); await drain();
  f.client.open(); assert.equal(f.el('local').hidden,false);
  assert.deepEqual(f.ops.map(op=>op[0]),['camera_status'],'opening the app leaves the camera off');
  f.click('lens'); f.click('local'); f.capture(); await drain();
  assert.equal(f.ops.at(-1)[1].video.facingMode.ideal,'user');
  assert.equal(f.el('preview').hidden,false);
  assert.ok(!f.ops.some(op=>op[0]==='camera_prepare'||op[0]==='camera_start'),'private local preview signals nothing');
  f.click('start'); await drain();
  assert.equal(f.ops.filter(op=>op[0]==='capture').length,1,'sharing reuses the preview track');
  assert.deepEqual(f.ops.filter(op=>['camera_prepare','camera_start','camera_offer'].includes(op[0])).map(op=>op[0]),
    ['camera_prepare','camera_start','camera_offer']);
  f.click('close'); await drain();
  assert.equal(f.track.readyState,'ended');
  assert.equal(f.peer.connectionState,'closed');
  assert.equal(f.ops.at(-1)[0],'camera_stop');
}
{
  const f=fixture({app:true}); f.bind(); await drain(); f.client.open();
  f.click('start'); f.click('close'); f.capture(); await drain();
  assert.equal(f.track.readyState,'ended');
  assert.ok(!f.ops.some(op=>op[0]==='camera_prepare'||op[0]==='camera_start'),'closing during permission prompt cancels sharing');
}
{
  const f=fixture({app:true}); f.bind(); await drain(); f.client.open(); f.click('local'); f.capture(); await drain();
  f.hide(); assert.equal(f.track.readyState,'ended','suspended local preview releases the lens');
}
{
  const f=fixture({app:true}); f.bind(); await drain();
  f.client.open(); f.click('local'); f.capture(); await drain();
  f.request('incoming'); await f.tick();
  assert.equal(f.el('title').textContent,'Camera request');
  assert.match(f.el('status').textContent,/Map owner requested this phone/);
  assert.equal(f.el('decline').hidden,false);
  assert.ok(!f.ops.some(([action])=>action==='camera_start'||action==='camera_offer'));
  f.click('decline'); await drain();
  assert.equal(f.el('preview').hidden,false);
  assert.equal(f.track.readyState,'live','rejecting incoming sharing preserves the local preview');
  assert.equal(f.ops.at(-1)[1].requested_only,true);
  f.request('second-request'); await f.tick(); f.click('start'); await drain();
  assert.equal(f.ops.find(([action])=>action==='camera_start')[1].session_id,'second-request');
  assert.equal(f.ops.filter(([action])=>action==='capture').length,1,'accepting reuses the still-local track');
  assert.ok(!f.ops.some(([action])=>action==='camera_prepare'),'accept the owner’s exact request');
}
console.log('PASS On-phone camera launcher, local preview, independent share, stop, and late consent cleanup.');


{
  const f=fixture(), idA='a'.repeat(32), idB='b'.repeat(32);
  f.request(); f.bind(); await drain();
  f.click('start'); f.capture(); await drain();
  const editor=f.peer;
  const tag=f.el('preview');
  let frames=2;
  Object.assign(tag,{videoWidth:320,readyState:4,paused:false,getVideoPlaybackQuality:()=>({totalVideoFrames:frames})});
  await f.tick();
  const lastBeat=()=>f.ops.filter(([name,input])=>name==='camera_status' && input.heartbeat===true).at(-1)[1];
  assert.equal(lastBeat().map_source_live,true,'the accepted camera sends fresh frame evidence before a viewer opens');
  f.advance(6); await f.tick(); assert.equal(lastBeat().map_source_live,false,'a frozen camera cannot renew its live marker');
  ++frames; await f.tick(); assert.equal(lastBeat().map_source_live,true);
  f.track.muted=true; await f.tick(); assert.equal(lastBeat().map_source_live,false);
  f.track.muted=false;
  f.setViewer('map',idA); await f.tick(); await drain();
  assert.equal(f.peers.length,2);
  assert.equal(f.ops.filter(([name])=>name==='capture').length,1,'map uses the granted track without another camera request');
  const offers=f.ops.filter(([name])=>name==='camera_map_offer');
  assert.equal(offers.length,1);
  assert.equal(offers[0][1].session_id,'session-a');
  assert.equal(offers[0][1].viewer_id,idA);
  assert.strictEqual(f.ops.filter(([name])=>name==='transceiver').at(-1)[1],f.track);
  f.setViewer('map',idA,'map answer'); await f.tick();
  const map=f.peer;
  assert.equal(map.remoteDescription.sdp,'map answer');
  assert.equal(editor.remoteDescription,undefined,'the map answer cannot take the editor slot');
  map.connectionState='connected'; map.dispatchEvent(new Event('connectionstatechange'));
  f.fireViewerTimeout();
  assert.equal(f.track.readyState,'live','a connected map remains usable when no editor connects');
  f.answer(); await f.tick(); assert.equal(editor.remoteDescription.sdp,'editor answer');
  editor.connectionState='disconnected'; editor.dispatchEvent(new Event('connectionstatechange'));
  assert.equal(f.track.readyState,'live','an editor transport loss leaves the map viewer running');
  f.setViewer('map',null); await f.tick();
  assert.equal(map.connectionState,'closed'); assert.equal(f.track.readyState,'live');
  assert.equal(editor.connectionState,'disconnected','closing the map only releases its own peer');
  f.setViewer('map',idB); await f.tick(); await drain();
  assert.equal(f.peers.length,3,'reopening creates a fresh one-way peer');
  assert.equal(f.ops.filter(([name])=>name==='capture').length,1);
  f.click('stop');
  assert.equal(f.track.readyState,'ended');
  assert.ok(f.peers.every(peer=>peer.connectionState==='closed'));
}
{
  const f=fixture(); f.request(); f.bind(); await drain();
  f.click('start'); f.capture(); await drain();
  const editor=f.peer; f.failMapOffer(); f.setViewer('map','c'.repeat(32)); await f.tick(); await drain();
  assert.equal(f.peer.connectionState,'closed');
  f.answer(); await f.tick();
  assert.equal(editor.remoteDescription.sdp,'editor answer');
  assert.equal(f.track.readyState,'live','map signaling errors do not stop the primary editor or capture');
}
{
  const f=fixture(), mapId='d'.repeat(32), chatId='e'.repeat(32);
  f.request(); f.bind(); await drain();
  f.click('start'); f.capture(); await drain();
  const editor=f.peer;
  f.setViewer('map',mapId); f.setViewer('chat',chatId); await f.tick(); await drain();
  assert.equal(f.peers.length,3,'editor, map, and Chat negotiate independently from one grant');
  const map=f.peers[1], chat=f.peers[2];
  assert.equal(f.ops.find(([action])=>action==='camera_map_offer')[1].viewer_id,mapId);
  assert.equal(f.ops.find(([action])=>action==='camera_chat_offer')[1].viewer_id,chatId);
  assert.equal(f.ops.filter(([name])=>name==='capture').length,1);
  assert.ok(f.ops.filter(([name])=>name==='transceiver').every(([,track,options])=>track===f.track && options.direction==='sendonly'));
  f.answer(); f.setViewer('map',mapId,'map answer'); f.setViewer('chat',chatId,'Chat answer'); await f.tick();
  assert.equal(editor.remoteDescription.sdp,'editor answer');
  assert.equal(map.remoteDescription.sdp,'map answer');
  assert.equal(chat.remoteDescription.sdp,'Chat answer');
  chat.connectionState='connected'; chat.dispatchEvent(new Event('connectionstatechange'));
  f.setViewer('map',null); await f.tick();
  editor.connectionState='disconnected'; editor.dispatchEvent(new Event('connectionstatechange'));
  f.fireViewerTimeout();
  assert.equal(map.connectionState,'closed'); assert.equal(chat.connectionState,'connected');
  assert.equal(f.track.readyState,'live','the Chat preview can continue alone');
  f.setViewer('chat',null); await f.tick();
  assert.equal(chat.connectionState,'closed');
  f.fireViewerTimeout();
  assert.equal(f.track.readyState,'ended','the original idle timeout returns after all viewers leave');
}
console.log('PASS Shared track freshness, simultaneous editor/map/Chat offers and answers, private viewer close/reopen and cleanup.');

{
  const calls=[];
  let activeStream;
  const recorder=()=>({
    sync(camera,stream){calls.push([camera?.session_id,stream]); activeStream=stream;},
    end(){if(activeStream)calls.push(['end',activeStream.getVideoTracks()[0].readyState]);}
  });
  const f=fixture({app:true,recorder});
  f.bind(); await drain();
  f.client.open(); f.click('local'); f.capture(); await drain();
  assert.ok(calls.every(([,stream])=>!stream),'the recorder has no track during local preview');
  f.click('start'); await drain();
  assert.equal(activeStream.getVideoTracks()[0],f.track,'recorder receives the already granted video track');
  f.click('stop'); await drain();
  assert.ok(calls.some(([event,state])=>event==='end' && state==='live'),'recorder finalizes before the source is released');
  assert.equal(f.track.readyState,'ended');
  fixture({recorder:()=>{throw Error('A signed-in Chat must not mount the holder recorder');}});
}

{
  let approved=false;
  const f=fixture({app:true,recorder:()=>({sync(){},end(){},isActive:()=>approved})});
  f.request(); f.bind(); await drain(); f.click('start'); f.capture(); await drain();
  approved=true;
  f.fireViewerTimeout();
  assert.equal(f.track.readyState,'live','a newly approved recording keeps an unviewed camera alive');
  f.peer.connectionState='disconnected'; f.peer.dispatchEvent(new Event('connectionstatechange'));
  assert.equal(f.track.readyState,'live','editor transport loss leaves a holder-approved recording running');
  approved=false; await f.tick(); f.fireViewerTimeout();
  assert.equal(f.track.readyState,'ended','idle timeout resumes after the recording operation finishes');
}
{
  const f=fixture({app:true,recorder:()=>({sync(){},end(){},isActive:()=>true})});
  f.request(); f.bind(); await drain(); f.click('start'); f.capture(); await drain(); f.hide();
  assert.equal(f.track.readyState,'ended','recording cannot keep a suspended holder camera active');
}
console.log('PASS Holder recording consent, stream lifecycle, local activity timeout and suspension.');
