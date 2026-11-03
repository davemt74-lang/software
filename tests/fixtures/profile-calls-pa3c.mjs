export const contract='vp3.profile-call.v1';
export const copy=value=>structuredClone(value);
export function callCard(role='visitor',overrides={}){
  const now=1800000000000;
  return {contract,call_id:41,revision:1,conversation_id:31,role,offered_by:role==='owner'?'visitor':'owner',mode:'video',state:'offered',
    server_now_ms:now,offer_expires_at_ms:now+60000,authority_valid_until_ms:0,connect_until_ms:null,provider_observed_at_ms:0,
    connection_verified:false,owner_connected:false,visitor_connected:false,cleanup_pending:false,reason:'',dispatch_due:false,
    can_accept:true,can_decline:true,can_cancel:false,can_end:false,can_join:false,can_return_to_agent:false,
    ...(role==='owner'?{visitor_label:'Visitor forty-one',agent_name:'Profile helper',chat_conversation_id:42,chat_agent_id:7,chat_message_id:701,delivery_id:'d'.repeat(32)}:{}),...overrides};
}

// Browser-boundary fixture only. The production call surface, identity bridge,
// renderer, capture lease and teardown code are executed without substitution.
// There is no network media connection or physical microphone in these tests.
export function installFakeMedia(window){
  const stats={rooms:[],microphones:0,cameras:0,connections:0,disconnects:0,tracks:[],holds:new Map()};
  const event={TrackSubscribed:'trackSubscribed',TrackUnsubscribed:'trackUnsubscribed',LocalTrackPublished:'localTrackPublished',LocalTrackUnpublished:'localTrackUnpublished',ParticipantConnected:'participantConnected',ParticipantDisconnected:'participantDisconnected',Reconnecting:'reconnecting',SignalReconnecting:'signalReconnecting',Reconnected:'reconnected',Disconnected:'disconnected',AudioPlaybackStatusChanged:'audioPlaybackStatusChanged'};
  function track(kind){
    const value={kind,source:kind==='audio'?'microphone':'camera',stopped:false,stops:0,elements:[],
      stop(){this.stopped=true;this.stops++;},attach(){const element=window.document.createElement(kind==='audio'?'audio':'video');this.elements.push(element);return element;},
      detach(){const old=this.elements;this.elements=[];return old;}};
    const raw={get readyState(){return value.stopped?'ended':'live';},stop:()=>value.stop()};value.mediaStream={getTracks:()=>[raw]};stats.tracks.push(value);return value;
  }
  function work(kind,perform){
    const held=stats.holds.get(kind);if(!held)return Promise.resolve().then(perform);stats.holds.delete(kind);
    return new Promise((resolve,reject)=>{held.pending={resolve:()=>{perform();resolve();},reject:()=>{perform();reject(new Error('Late fixture media failure.'));}};});
  }
  class Room{
    constructor(){this.handlers=new Map();this.remoteParticipants=new Map();this.canPlaybackAudio=true;
      this.localParticipant={identity:'',trackPublications:new Map(),isMicrophoneEnabled:false,isCameraEnabled:false,
        setMicrophoneEnabled:enabled=>{stats.microphones++;return work('microphone',()=>this.publish('audio',enabled));},
        setCameraEnabled:enabled=>{stats.cameras++;return work('camera',()=>this.publish('video',enabled));}};stats.rooms.push(this);}
    on(name,handler){if(!this.handlers.has(name))this.handlers.set(name,[]);this.handlers.get(name).push(handler);return this;}
    emit(name,...args){for(const handler of this.handlers.get(name)||[])handler(...args);}
    connect(_url,token){stats.connections++;this.localParticipant.identity=token.includes('OWNER')?'owner-41':'visitor-41';return work('connect',()=>{});}
    publish(kind,enabled){
      const p=this.localParticipant,key=kind==='audio'?'isMicrophoneEnabled':'isCameraEnabled';p[key]=enabled;
      if(enabled){const publication={track:track(kind)};publication.track.local=true;p.trackPublications.set(kind,publication);this.emit(event.LocalTrackPublished,publication,p);}
      else{const publication=p.trackPublications.get(kind);publication?.track.stop();p.trackPublications.delete(kind);if(publication)this.emit(event.LocalTrackUnpublished,publication);}
    }
    disconnect(){stats.disconnects++;return Promise.resolve();}
    startAudio(){return Promise.resolve();}
    addPeer(identity='owner-41',kind='audio'){const p={identity,trackPublications:new Map()},t=track(kind);p.trackPublications.set(kind,{track:t});this.remoteParticipants.set(identity,p);this.emit(event.ParticipantConnected,p);this.emit(event.TrackSubscribed,t,{track:t},p);return p;}
  }
  window.LivekitClient={Room,RoomEvent:event};
  stats.hold=kind=>{const held={pending:null};stats.holds.set(kind,held);return held;};
  window.__callMediaFixture=stats;return stats;
}

export class FixtureCustomEvent extends Event{constructor(type,options={}){super(type);this.detail=options.detail;}}
export class Element extends EventTarget{
  constructor(document,tag='div'){super();this.ownerDocument=document;this.tagName=tag.toUpperCase();this.children=[];this.parentElement=null;this.attrs={};this.dataset={};this.style={};this._text='';this.value='';this.hidden=false;this.disabled=false;this.checked=false;this.scrollTop=0;this.scrollHeight=1000;this.clientHeight=700;this.classList={add:(...names)=>{this.className=[...new Set([...this.className.split(/\s+/),...names])].join(' ');},toggle:(name,on)=>{const names=new Set(this.className.split(/\s+/).filter(Boolean));if(on)names.add(name);else names.delete(name);this.className=[...names].join(' ');}};}
  get id(){return this.attrs.id||'';}set id(v){this.attrs.id=String(v);}
  get className(){return this.attrs.class||'';}set className(v){this.attrs.class=String(v);}
  get type(){return this.attrs.type||'';}set type(v){this.attrs.type=String(v);}
  get parentNode(){return this.parentElement;}
  get isConnected(){return this===this.ownerDocument.body||this===this.ownerDocument.head||Boolean(this.parentElement?.isConnected);}
  setAttribute(key,value){this.attrs[key]=String(value);if(key.startsWith('data-'))this.dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=String(value);}
  getAttribute(key){return this.attrs[key]??null;}
  append(...children){for(let child of children){if(typeof child==='string')child=this.ownerDocument.createTextNode(child);child.remove();child.parentElement=this;this.children.push(child);}}
  appendChild(child){this.append(child);return child;}
  insertBefore(child,before){child.remove();child.parentElement=this;const at=this.children.indexOf(before);this.children.splice(at<0?this.children.length:at,0,child);return child;}
  remove(){if(this.parentElement)this.parentElement.children=this.parentElement.children.filter(n=>n!==this);this.parentElement=null;}
  replaceChildren(...children){for(const child of this.children)child.parentElement=null;this.children=[];this.append(...children);}
  set innerHTML(v){if(v!=='')throw new Error('Only clearing DOM is implemented.');this._text='';this.replaceChildren();}
  get textContent(){return this._text+this.children.map(n=>n.textContent).join('');}set textContent(v){this._text=String(v);this.replaceChildren();}
  matches(selector){
    const attrs=[...selector.matchAll(/\[([^\]=]+)(?:=['"]?([^'"\]]*)['"]?)?\]/g)];
    if(attrs.some(([,key,value])=>{const actual=key.startsWith('data-')?this.dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]:this.attrs[key];return value===undefined?actual===undefined:actual!==value;}))return false;
    const simple=selector.replace(/\[[^\]]+\]/g,''),tag=simple.match(/^[\w-]+/)?.[0],id=simple.match(/#([\w-]+)/)?.[1];
    return (!tag||this.tagName===tag.toUpperCase())&&(!id||this.id===id)&&[...simple.matchAll(/\.([\w-]+)/g)].every(([,c])=>this.className.split(/\s+/).includes(c));
  }
  querySelectorAll(selector){const out=[];const walk=n=>{for(const child of n.children){if(selector.split(',').some(s=>child.matches(s.trim())))out.push(child);walk(child);}};walk(this);return out;}
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
  click(){if(!this.disabled)this.dispatchEvent(new Event('click'));}
  focus(){this.ownerDocument.activeElement=this;}
  requestSubmit(){this.dispatchEvent(new Event('submit',{cancelable:true}));}
  play(){return Promise.resolve();}
}
export class Document extends EventTarget{
  constructor(){super();this.body=new Element(this,'body');this.head=new Element(this,'head');this.visibilityState='visible';this.hidden=false;this.currentScript={dataset:{voiceCaptureScope:'fixture-user'}};}
  createElement(tag){return new Element(this,tag);}createTextNode(text){const n=new Element(this,'#text');n.textContent=text;return n;}
  querySelector(selector){return this.body.querySelector(selector)||this.head.querySelector(selector);}getElementById(id){return this.querySelector('#'+id);}
}
