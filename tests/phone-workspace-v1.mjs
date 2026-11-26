import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../phone-workspace-v1.js',import.meta.url),'utf8');
class Element{
 constructor(tag,document){this.tagName=tag.toUpperCase();this.ownerDocument=document;this.children=[];this.parentElement=null;this.dataset={};this.attributes={};this.listeners={};this.hidden=false;this.disabled=false;this.paused=true;this.value='';this.selectionStart=0;this.selectionEnd=0;this.scrollTop=0;this._text='';this.className='';this.classList={add:n=>{this.className+=' '+n;},remove:n=>{this.className=this.className.split(' ').filter(v=>v!==n).join(' ');}};}
 get childNodes(){return this.children;}get isConnected(){return this===this.ownerDocument.body||this===this.ownerDocument.head||Boolean(this.parentElement?.isConnected);}get textContent(){return this._text+this.children.map(n=>n.textContent).join('');}set textContent(v){this._text=String(v);this.replaceChildren();}get id(){return this.attributes.id||'';}set id(v){this.attributes.id=String(v);}
 append(...items){for(const item of items){item.remove();item.parentElement=this;this.children.push(item);}}replaceChildren(...items){for(const item of this.children)item.parentElement=null;this.children=[];this.append(...items);}replaceWith(item){const parent=this.parentElement;if(!parent)return;item.remove();parent.children[parent.children.indexOf(this)]=item;item.parentElement=parent;this.parentElement=null;}remove(){if(this.parentElement){this.parentElement.children=this.parentElement.children.filter(n=>n!==this);this.parentElement=null;}}
 setAttribute(key,value){this.attributes[key]=String(value);}getAttribute(key){if(key==='hidden')return this.hidden?'':null;if(key.startsWith('data-')){const name=key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase());return this.dataset[name]??this.attributes[key]??null;}return this.attributes[key]??this[key]??null;}removeAttribute(key){delete this.attributes[key];if(key==='src')this.src='';}
 addEventListener(name,fn){(this.listeners[name]??=[]).push(fn);}removeEventListener(name,fn){this.listeners[name]=(this.listeners[name]||[]).filter(v=>v!==fn);}emit(name,event={}){event.target??=this;event.preventDefault??=()=>{};for(const fn of this.listeners[name]||[])fn(event);}click(){if(this.disabled)return;const event={target:this,preventDefault(){}};for(let current=this;current;current=current.parentElement)current.emit('click',event);this.ownerDocument.emit('click',event);}
 contains(item){return item===this||this.children.some(child=>child.contains(item));}focus(){this.ownerDocument.activeElement=this;}setSelectionRange(a,b){this.selectionStart=a;this.selectionEnd=b;}pause(){this.paused=true;}load(){}play(){this.paused=false;this.emit('play');return Promise.resolve();}
 matches(selector){const value=selector.trim();if(value.startsWith('.'))return this.className.split(' ').includes(value.slice(1));if(value.startsWith('#'))return this.id===value.slice(1);if(value.startsWith('[')){const match=value.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);return match?this.getAttribute(match[1])!==null&&(match[2]===undefined||String(this.getAttribute(match[1]))===match[2]):false;}return this.tagName===value.toUpperCase();}
 querySelectorAll(selector){const parts=selector.split(',');const result=[];const walk=parent=>{for(const child of parent.children){if(parts.some(p=>child.matches(p)))result.push(child);walk(child);}};walk(this);return result;}querySelector(selector){return this.querySelectorAll(selector)[0]||null;}closest(selector){for(let item=this;item;item=item.parentElement)if(item.matches(selector))return item;return null;}
}
class Events{constructor(){this.listeners={};}addEventListener(n,f){(this.listeners[n]??=[]).push(f);}removeEventListener(n,f){this.listeners[n]=(this.listeners[n]||[]).filter(v=>v!==f);}dispatchEvent(e){for(const f of this.listeners[e.type]||[])f(e);return true;}emit(n,e={}){this.dispatchEvent({type:n,...e});}}
const flush=async()=>{for(let i=0;i<40;i++)await Promise.resolve();};
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve};};
function setup(testOptions={}){
 const document=new Events();document.body=new Element('body',document);document.head=new Element('head',document);document.createElement=tag=>new Element(tag,document);document.getElementById=id=>[document.body,document.head,...document.body.querySelectorAll('*'),...document.head.querySelectorAll('*')].find(n=>n.id===id)||document.body.querySelector('#'+id);document.querySelectorAll=selector=>[...document.body.querySelectorAll(selector),...document.head.querySelectorAll(selector)];document.querySelector=selector=>document.querySelectorAll(selector)[0]||null;document.visibilityState='visible';
 const originalMatches=Element.prototype.matches;Element.prototype.matches=function(s){return s.trim()==='*'||originalMatches.call(this,s);};
 const toggle=document.createElement('button');toggle.setAttribute('data-phone-workspace-toggle','');document.body.append(toggle);const thread=document.createElement('section');thread.id='chatThread';document.body.append(thread);
 const window=new Events(),calls=[],devices=[],tracks=[],facts={claims:0,releases:0,registers:0,connects:0,destroyed:0,stateMethods:[],microphones:0,audioCloses:0,worklets:[],workletAssets:[]};let authorized=true,authorizationStatus=403,prepareGate=null,registerGate=null,contactGate=null,stateResponder=testOptions.stateResponder||null,requestResponder=testOptions.requestResponder||null;
 const state={numbers:[{id:1,phone_e164:'+16025550111',agent_id:2,voice_enabled:true,browser_enabled:true,recording_enabled:true}],selected_number_id:1,readiness:{ready:true},contacts:[],favorites:[],calls:[],voicemails:[{id:'job-4',call_id:'legacy-4',peer_e164:'+16025550222',audio_url:'/audio',transcript:'Synthetic transcript',report_url:'/report'}],active_call:null,post_call_policy:{enabled:false,retain_audio:false,transcribe:false,analyze:false,modules:['basic','qa','actions','decisions','followup']}};
 class Call extends Events{constructor(){super();this.track={readyState:'live',stop(){this.readyState='ended';}};tracks.push(this.track);this.stream={getTracks:()=>[this.track]};this.parameters={From:'+16025550333'};this.customParameters=new Map([['phone_call_id','a'.repeat(32)]]);}on(n,f){this.addEventListener(n,f);}status(){return 'open';}getLocalStream(){return this.stream;}accept(){this.emit('accept');}reject(){this.emit('reject');}disconnect(){this.emit('disconnect');}isMuted(){return false;}mute(){}}
 class Device extends Events{constructor(){super();devices.push(this);}emit(n,...args){for(const fn of this.listeners[n]||[])fn(...args);}on(n,f){this.addEventListener(n,f);}async register(){facts.registers++;if(registerGate)await registerGate.promise;this.emit('registered');}async connect(options){facts.connects++;facts.params=options;return new Call();}destroy(){facts.destroyed++;}disconnectAll(){}updateToken(){facts.updated=true;}}
 window.VP3_PHONE_WORKSPACE_V1={endpoint:'/api/phone-workspace-v1.php',urlbase:'/',csrf:'fixture',agent_id:2,conversation_id:10,sdk_url:'/assets/twilio-voice-2.18.5.min.js',greeting_endpoint:'/api/phone-voicemail-greeting-v1.php',greeting_capture_url:'/phone-voicemail-capture-v1.js'};
 window.Twilio={Device};window.StonefellowVoiceLeaseV122={acquireCapture(){facts.claims++;let live=true;const streams=new Set();return {isCurrent:()=>live,ownStream:s=>{if(facts.rejectStream){s.getTracks().forEach(t=>t.stop());return false;}streams.add(s);return true;},release(){if(!live)return;live=false;facts.releases++;for(const stream of streams)stream.getTracks().forEach(t=>t.stop());}};}};
 const fetch=async(url,options={})=>{const body=options.body instanceof FormData?{...Object.fromEntries(options.body),action:'greeting_upload'}:options.method==='POST'?JSON.parse(options.body):Object.fromEntries(new URL(url).searchParams);calls.push(body);if(body.action==='state')facts.stateMethods.push(options.method||'GET');if(!authorized)return {ok:false,status:authorizationStatus,json:async()=>({ok:false})};if(requestResponder){const response=await requestResponder({body,options,state});if(response)return response;}if(body.action==='state'&&stateResponder){const response=await stateResponder({body,options,state});if(response)return response;}let value={};if(body.action==='state')value={state:structuredClone(state)};if(body.action==='token')value={token:'x'.repeat(60),identity:'fixture',expires_at:Date.now()+3600000};if(body.action==='prepare_call'){if(prepareGate)await prepareGate.promise;value={call:{public_id:'b'.repeat(32),peer_e164:body.to_number,conversation_id:body.conversation_id,agent_id:body.agent_id},connect_params:{call_intent:'c'.repeat(64)}};}if(body.action==='save_recording_policy')value={policy:body};if(body.action==='save_caller_contact'){if(contactGate)await contactGate.promise;const call=state.calls.find(row=>(row.public_id||row.call_id)===body.call_id);const contact={id:91,name:body.name,phone_e164:call.peer_e164};state.contacts.push(contact);for(const row of state.calls)if(row.peer_e164===contact.phone_e164){row.contact_id=contact.id;row.contact_name=contact.name;}value={contact};}return {ok:true,status:200,json:async()=>({ok:true,...value})};};
 const timers=new Map();const schedule=(fn,ms)=>{if(!testOptions.manualTimers)return setTimeout(fn,ms);const id=Symbol('request timer');timers.set(id,{fn,ms});return id;};const unschedule=id=>{if(!timers.delete(id)&&typeof id!=='symbol')clearTimeout(id);};
 class AudioContext{constructor(){this.destination={};this.audioWorklet={addModule:async url=>{facts.workletAssets.push(url);}};}resume(){return Promise.resolve();}close(){facts.audioCloses++;return Promise.resolve();}createMediaStreamSource(){return {connect(){},disconnect(){}};}}
 class AudioWorkletNode{constructor(){this.port={onmessage:null};facts.worklets.push(this);}connect(){}disconnect(){}}
 window.AudioContext=AudioContext;window.AudioWorkletNode=AudioWorkletNode;
 const navigator={mediaDevices:{getUserMedia:async()=>{facts.microphones++;const track={readyState:'live',stop(){this.readyState='ended';}};tracks.push(track);const stream={getTracks:()=>[track]};if(testOptions.microphoneGate)await testOptions.microphoneGate.promise;return stream;}}};
 const sandbox={window,document,navigator,TextEncoder,crypto:globalThis.crypto,AudioWorkletNode,ArrayBuffer,DataView,Uint8Array,Blob,FormData,location:{href:'https://fixture.test/chat.php',origin:'https://fixture.test'},URL,Date,Number,String,Boolean,Map,Set,Promise,Object,Array,RegExp,JSON,fetch,AbortController,DOMException,CustomEvent:class{constructor(type,opts={}){this.type=type;this.detail=opts.detail;}},Event:class{constructor(type){this.type=type;}},setTimeout:schedule,clearTimeout:unschedule,setInterval:()=>1,clearInterval(){},console};
 vm.runInNewContext(source,sandbox);return {location:sandbox.location,window,document,toggle,thread,state,calls,devices,facts,tracks,setAuthorized:(v,status=403)=>{authorized=v;authorizationStatus=status;},setStateResponder:v=>stateResponder=v,setRequestResponder:v=>requestResponder=v,expireRequestTimers:()=>{for(const [id,timer] of [...timers])if(timer.ms===15000){timers.delete(id);timer.fn();}},setPrepareGate:v=>prepareGate=v,setRegisterGate:v=>registerGate=v,setContactGate:v=>contactGate=v,findButton:label=>document.querySelectorAll('button').find(n=>n.textContent===label),field:key=>document.querySelectorAll('[data-phone-focus-key]').find(n=>n.dataset.phoneFocusKey===key)};
}
let count=0;async function test(name,fn){await fn();count++;console.log('PASS '+name);}
await test('typed voicemail cards render without converting their identity',async()=>{const b=setup();await flush();const card=b.document.createElement('div');b.thread.append(card);b.window.VP3PhoneWorkspaceV1.bindMessage(card,{kind:'voicemail',voicemail_id:'job-4'});assert.match(card.textContent,/Synthetic transcript/);assert.equal(card.querySelectorAll('audio').length,1);assert.equal(card.querySelectorAll('a').length,1,'Missing transcript URL creates no bogus link');await card.querySelector('audio').play();await flush();assert.equal(b.calls.find(v=>v.action==='mark_voicemail_read').voicemail_id,'job-4');b.window.VP3PhoneWorkspaceV1.dispose();});
await test('missing, null and blank media URLs create no player or links',async()=>{for(const missing of [undefined,null,'','   ']){const b=setup();b.state.voicemails[0].audio_url=missing;b.state.voicemails[0].transcript_url=missing;b.state.voicemails[0].report_url=missing;await flush();await b.window.VP3PhoneWorkspaceV1.refresh();const card=b.document.createElement('div');b.thread.append(card);b.window.VP3PhoneWorkspaceV1.bindMessage(card,{kind:'voicemail',voicemail_id:'job-4'});assert.equal(card.querySelectorAll('audio').length,0);assert.equal(card.querySelectorAll('a').length,0);b.window.VP3PhoneWorkspaceV1.dispose();}});
await test('outbound capture has one owner and passes only an opaque call intent',async()=>{const b=setup();await flush();b.toggle.click();b.field('dial').value='+16025550555';const form=b.document.querySelectorAll('form').find(n=>n.className==='vp3-phone-dial');form.emit('submit');await flush();assert.equal(b.facts.connects,1);assert.equal(b.facts.claims,1);assert.equal(JSON.stringify(b.facts.params),JSON.stringify({params:{call_intent:'c'.repeat(64)}}));assert.equal(b.calls.find(v=>v.action==='prepare_call').recording_enabled,false);b.findButton('Hang up').click();assert.equal(b.facts.releases,1);assert.ok(b.tracks.every(t=>t.readyState==='ended'));b.window.VP3PhoneWorkspaceV1.dispose();});
await test('explicit per-call recording is separate from post-call policy opt-in',async()=>{const b=setup();b.state.post_call_policy.enabled=true;await flush();b.toggle.click();b.field('record-call').checked=true;b.field('record-call').emit('change');b.field('dial').value='+16025550555';b.document.querySelectorAll('form').find(n=>n.className==='vp3-phone-dial').emit('submit');await flush();assert.equal(b.calls.find(v=>v.action==='prepare_call').recording_enabled,true);b.window.VP3PhoneWorkspaceV1.dispose();});
await test('a pending Enable shares registration before dialing',async()=>{const b=setup(),gate=deferred();b.setRegisterGate(gate);await flush();b.toggle.click();b.findButton('Enable calls in this browser').click();await flush();b.field('dial').value='+16025550555';b.document.querySelectorAll('form').find(n=>n.className==='vp3-phone-dial').emit('submit');await flush();assert.equal(b.facts.registers,1);assert.equal(b.facts.connects,0);gate.resolve();await flush();assert.equal(b.facts.connects,1);b.window.VP3PhoneWorkspaceV1.dispose();});
await test('late preparation is cancelled in its original scope after context teardown',async()=>{const b=setup(),gate=deferred();await flush();b.setPrepareGate(gate);b.toggle.click();b.field('dial').value='+16025550555';b.document.querySelectorAll('form').find(n=>n.className==='vp3-phone-dial').emit('submit');await flush();b.window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true});assert.equal(b.facts.claims,b.facts.releases,'Context teardown releases pending capture immediately');gate.resolve();await flush();const cancelled=b.calls.find(v=>v.action==='cancel_prepared');assert.equal(cancelled.conversation_id,10);assert.equal(cancelled.call_id,'b'.repeat(32));assert.equal(b.facts.connects,0);assert.equal(b.facts.claims,b.facts.releases);b.window.VP3PhoneWorkspaceV1.dispose();});
await test('turning calls off cancels a delayed prepared receipt before retrying',async()=>{const b=setup(),gate=deferred();await flush();b.toggle.click();b.findButton('Enable calls in this browser').click();await flush();b.setPrepareGate(gate);b.field('dial').value='+16025550555';b.document.querySelectorAll('form').find(n=>n.className==='vp3-phone-dial').emit('submit');await flush();b.findButton('Turn off browser calls').click();assert.equal(b.facts.claims,b.facts.releases);gate.resolve();await flush();assert.equal(b.calls.find(v=>v.action==='cancel_prepared').conversation_id,10);b.setPrepareGate(null);b.field('dial').value='+16025550555';b.document.querySelectorAll('form').find(n=>n.className==='vp3-phone-dial').emit('submit');await flush();assert.equal(b.facts.connects,1);b.window.VP3PhoneWorkspaceV1.dispose();});
await test('canonical call cards replace transient incoming announcements',async()=>{const b=setup();await flush();b.toggle.click();b.findButton('Enable calls in this browser').click();await flush();const call=new (class extends Events{constructor(){super();this.customParameters=new Map([['phone_call_id','a'.repeat(32)]]);this.parameters={From:'+16025550199'};}on(n,f){this.addEventListener(n,f);}reject(){}getLocalStream(){return null;}})();b.devices[0].emit('incoming',call);assert.equal(b.thread.querySelectorAll('[data-phone-announcement]').length,1);const canonical=b.document.createElement('div');b.thread.append(canonical);b.window.VP3PhoneWorkspaceV1.bindMessage(canonical,{kind:'call',call_id:'a'.repeat(32)});assert.equal(b.thread.querySelectorAll('[data-phone-announcement]').length,0);assert.match(canonical.textContent,/Incoming phone call/);b.window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true});assert.equal(b.thread.querySelectorAll('[data-phone-announcement]').length,0);b.window.VP3PhoneWorkspaceV1.dispose();});
await test('mixed browser and legacy history stays read-only while legacy callback prepares a new call',async()=>{const b=setup();b.state.calls=[{public_id:'c'.repeat(32),call_id:'c'.repeat(32),peer_e164:'+16025550122',state:'completed',source_kind:'browser',browser_control:true},{public_id:'legacy-3',call_id:'legacy-3',peer_e164:'+16025550133',state:'voicemail_ready',source_kind:'legacy',browser_control:false}];await flush();await b.window.VP3PhoneWorkspaceV1.refresh();b.toggle.click();const card=b.document.createElement('div');b.thread.append(card);b.window.VP3PhoneWorkspaceV1.bindMessage(card,{kind:'call',call_id:'legacy-3'});assert.match(card.textContent,/voicemail ready/);assert.deepEqual(card.querySelectorAll('button').map(n=>n.textContent),['Call back','Add to contacts']);assert.equal(b.calls.some(v=>v.action==='bind_call'),false);card.querySelector('button').click();await flush();const prepared=b.calls.find(v=>v.action==='prepare_call');assert.equal(prepared.to_number,'+16025550133');assert.equal(b.calls.some(v=>v.action==='bind_call'),false);assert.equal(b.facts.connects,1);assert.equal(JSON.stringify(b.facts.params),JSON.stringify({params:{call_intent:'c'.repeat(64)}}));b.findButton('Hang up').click();let rejected=0;b.devices.at(-1).emit('incoming',{customParameters:new Map([['phone_call_id','legacy-3']]),reject(){rejected++;},getLocalStream(){return null;}});assert.equal(rejected,1,'A typed historical key can never acquire SDK incoming authority');assert.equal(card.querySelectorAll('button').some(n=>['Accept','Decline','Hang up'].includes(n.textContent)),false);b.window.VP3PhoneWorkspaceV1.dispose();});
await test('readiness false and assignment loss clear media and private controls',async()=>{const b=setup();await flush();b.toggle.click();b.field('dial').value='+16025550555';b.document.querySelectorAll('form').find(n=>n.className==='vp3-phone-dial').emit('submit');await flush();b.window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:10,agent_id:2,ready:false});assert.ok(b.tracks.every(t=>t.readyState==='ended'));b.window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:10,agent_id:2,ready:true});await flush();b.setAuthorized(false);await b.window.VP3PhoneWorkspaceV1.refresh();assert.equal(b.toggle.hidden,true);assert.equal(b.document.querySelector('.vp3-phone-workspace').hidden,true);b.window.VP3PhoneWorkspaceV1.dispose();});
await test('canonical Phone cards live inside the Chat content column and use projected contact details',async()=>{
 const b=setup();b.state.numbers[0].agent_name='My Agent';const id='d'.repeat(32);b.state.calls=[{public_id:id,call_id:id,peer_e164:'+16025550888',phone_e164:'+16025550111',number_id:1,agent_id:2,direction:'inbound',state:'completed',duration_seconds:125,created_at:'2026-11-23T12:00:00Z',contact_id:99,contact_name:'Caller <img src=x>',caller_familiar:true}];await flush();await b.window.VP3PhoneWorkspaceV1.refresh();
 const row=b.document.createElement('article');row.className='message assistant';const avatar=b.document.createElement('div');avatar.className='message-avatar';const body=b.document.createElement('div');body.className='message-body';row.append(avatar,body);b.thread.append(row);b.window.VP3PhoneWorkspaceV1.bindMessage(row,{kind:'call',call_id:id});
 assert.equal(row.children.length,2);assert.equal(body.querySelector('.vp3-phone-chat-card').parentElement,body);assert.match(body.textContent,/Incoming phone call/);assert.match(body.textContent,/Caller <img src=x>/);assert.match(body.textContent,/\+16025550888/);assert.match(body.textContent,/Saved contact/);assert.match(body.textContent,/2:05/);assert.match(body.textContent,/My Agent/);assert.equal(body.querySelectorAll('img').length,0);assert.equal(body.querySelectorAll('button').some(button=>button.textContent==='Add to contacts'),false);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('previous history is labelled accurately and legacy duration remains a recording duration',async()=>{
 const b=setup(),id='legacy-9';b.state.calls=[{public_id:id,peer_e164:'+16025550888',direction:'inbound',state:'voicemail_ready',duration_seconds:63,created_at:'2026-11-23T12:00:00Z'},{public_id:'legacy-8',peer_e164:'+16025550888',state:'completed',created_at:'2026-11-22T12:00:00Z'}];await flush();await b.window.VP3PhoneWorkspaceV1.refresh();const host=b.document.createElement('div');b.thread.append(host);b.window.VP3PhoneWorkspaceV1.bindMessage(host,{kind:'call',call_id:id});assert.match(host.textContent,/Seen in recent calls/);assert.match(host.textContent,/Recording duration1:03/);assert.doesNotMatch(host.textContent,/Saved contact|Call duration/);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('contact save derives the phone from its call, prevents duplicate saves and updates duplicate cards',async()=>{
 const b=setup(),gate=deferred(),id='e'.repeat(32);b.state.calls=[{public_id:id,peer_e164:'+16025550888',direction:'inbound',state:'completed'}];b.setContactGate(gate);await flush();await b.window.VP3PhoneWorkspaceV1.refresh();const hosts=[b.document.createElement('div'),b.document.createElement('div')];for(const host of hosts){b.thread.append(host);b.window.VP3PhoneWorkspaceV1.bindMessage(host,{kind:'call',call_id:id});}b.findButton('Add to contacts').click();
 let form=hosts[0].querySelector('form'),name=form.querySelector('input');name.value='Mint caller <script>';name.emit('input');name.focus();name.setSelectionRange(4,4);await b.window.VP3PhoneWorkspaceV1.refresh();form=hosts[0].querySelector('form');name=form.querySelector('input');assert.equal(name.value,'Mint caller <script>');assert.equal(name.selectionStart,4);assert.equal(b.document.activeElement,name);assert.equal(form.querySelectorAll('input')[1].readOnly,true);form.emit('submit');form.emit('submit');await flush();assert.equal(b.calls.filter(call=>call.action==='save_caller_contact').length,1);const saved=b.calls.find(call=>call.action==='save_caller_contact');assert.equal(saved.call_id,id);assert.equal(saved.name,'Mint caller <script>');assert.equal(Object.hasOwn(saved,'phone_number'),false);gate.resolve();await flush();for(const host of hosts){assert.match(host.textContent,/Mint caller <script>/);assert.match(host.textContent,/\+16025550888/);assert.equal(host.querySelectorAll('form').length,0);}b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('contact setup readiness disables only contact saving and late save responses cannot cross context',async()=>{
 const b=setup(),gate=deferred(),id='f'.repeat(32);b.state.calls=[{public_id:id,peer_e164:'+16025550888',direction:'inbound',state:'completed'}];b.state.contact_write_ready=false;await flush();await b.window.VP3PhoneWorkspaceV1.refresh();const host=b.document.createElement('div');b.thread.append(host);b.window.VP3PhoneWorkspaceV1.bindMessage(host,{kind:'call',call_id:id});assert.equal(b.findButton('Add to contacts').disabled,true);assert.equal(b.findButton('Call back').disabled,false);b.state.contact_write_ready=true;await b.window.VP3PhoneWorkspaceV1.refresh();b.findButton('Add to contacts').click();const form=host.querySelector('form');form.querySelector('input').value='Late contact';form.querySelector('input').emit('input');b.setContactGate(gate);form.emit('submit');await flush();b.window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true});gate.resolve();await flush();assert.doesNotMatch(host.textContent,/Late contact/);assert.equal(host.querySelectorAll('form').length,0);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('a playing recording stays live while contact forms and caller details update',async()=>{
 const b=setup(),id='8'.repeat(32);b.state.calls=[{public_id:id,peer_e164:'+16025550888',direction:'inbound',state:'completed'}];b.state.voicemails=[{id:'job-8',call_id:id,peer_e164:'+16025550888',audio_url:'/audio',read:true}];await flush();await b.window.VP3PhoneWorkspaceV1.refresh();const host=b.document.createElement('div');b.thread.append(host);b.window.VP3PhoneWorkspaceV1.bindMessage(host,{kind:'call',call_id:id});const audio=host.querySelector('audio');await audio.play();host.querySelectorAll('button').find(button=>button.textContent==='Add to contacts').click();assert.equal(host.querySelector('audio'),audio);assert.equal(audio.paused,false);let form=host.querySelector('form');assert.ok(form,'Playing audio does not block the contact form');form.querySelector('input').value='Saved while playing';form.querySelector('input').emit('input');form.emit('submit');await flush();assert.equal(host.querySelector('audio'),audio);assert.equal(audio.paused,false);assert.match(host.textContent,/Saved while playing/);assert.equal(host.querySelector('form'),null);b.window.VP3PhoneWorkspaceV1.dispose();assert.equal(audio.paused,true);
});
await test('initial state failures stop loading, keep server details private, and recover through read-only Retry',async()=>{
 const upstream='SQLSTATE private endpoint credential';
 const failures=[
  ['upgrade',{ok:false,status:503,json:async()=>({ok:false,error:'phone_upgrade_required',message:upstream})}],
  ['unavailable',{ok:false,status:503,json:async()=>({ok:false,error:'phone_unavailable',message:upstream})}],
  ['invalid JSON',{ok:true,status:200,json:async()=>{throw new SyntaxError(upstream);}}],
  ['invalid state',{ok:true,status:200,json:async()=>({ok:true,state:{numbers:[]}})}],
  ['network',new TypeError(upstream)],
  ['aborted request',new DOMException(upstream,'AbortError')],
 ];
 for(const [kind,result] of failures){
  const b=setup({stateResponder:async()=>{if(result instanceof Error)throw result;return result;}});
  await flush();b.toggle.click();await flush();
  const panel=b.document.querySelector('.vp3-phone-panel');
  assert.doesNotMatch(panel.textContent,/Loading assigned phone connections|Loading contacts|Loading recent calls|Loading voicemail/,kind+' must leave the loading state');
  assert.doesNotMatch(panel.textContent,/SQLSTATE|private endpoint credential/,kind+' must not expose upstream details');
  assert.equal(b.document.querySelectorAll('button').filter(button=>button.textContent==='Retry'&&!button.closest('[hidden]')).length,1,kind+' exposes one retry control');
  assert.equal(b.findButton('Retry').disabled,false);
  if(kind==='upgrade'){assert.match(panel.textContent,/Cloud database upgrade/);assert.match(panel.textContent,/upgrade\.php/);}
  const countBefore=b.calls.filter(call=>call.action==='state').length;
  b.setStateResponder(null);const retry=b.findButton('Retry');retry.click();retry.click();await flush();
  assert.equal(b.calls.filter(call=>call.action==='state').length,countBefore+1,kind+' shares one in-flight retry');
  assert.equal(b.field('number').value,'1',kind+' recovers the assigned number without a page reload');
  assert.ok(b.calls.every(call=>call.action==='state'),kind+' cannot prepare calls or mutate settings');
  assert.ok(b.facts.stateMethods.every(method=>method==='GET'));
  assert.equal(b.facts.claims,0);assert.equal(b.facts.registers,0);assert.equal(b.facts.connects,0);
  assert.equal(!b.findButton('Retry')||Boolean(b.findButton('Retry').closest('[hidden]')),true);
  b.window.VP3PhoneWorkspaceV1.dispose();
 }
});
await test('a timed-out state request exits loading and can be retried without a page reload',async()=>{
 const b=setup({manualTimers:true,stateResponder:({options})=>new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>reject(new DOMException('Fixture aborted','AbortError')),{once:true}))});
 b.toggle.click();await flush();assert.match(b.document.querySelector('.vp3-phone-panel').textContent,/Loading assigned phone connections/);
 b.expireRequestTimers();await flush();
 const panel=b.document.querySelector('.vp3-phone-panel');assert.doesNotMatch(panel.textContent,/Loading assigned phone connections/);assert.match(panel.textContent,/timed out|too long/i);assert.equal(b.findButton('Retry').disabled,false);
 b.setStateResponder(null);b.findButton('Retry').click();await flush();assert.equal(b.field('number').value,'1');assert.ok(b.calls.every(call=>call.action==='state'));b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('a failed refresh labels cached data and prevents dialing until recovery',async()=>{
 const b=setup();await flush();b.toggle.click();await flush();
 b.setStateResponder(async()=>({ok:false,status:503,json:async()=>({ok:false,error:'phone_unavailable'})}));await b.window.VP3PhoneWorkspaceV1.refresh();await flush();
 assert.equal(b.field('number').value,'1','The last confirmed number remains visible');
 assert.match(b.document.querySelector('.vp3-phone-panel').textContent,/cached|previously loaded|last (?:confirmed|loaded|saved)|may be out of date/i);
 assert.equal(b.findButton('Call').disabled,true);assert.equal(b.findButton('Enable calls in this browser').disabled,true);
 b.setStateResponder(null);b.findButton('Retry').click();await flush();assert.equal(b.findButton('Call').disabled,false);assert.equal(b.facts.connects,0);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('a delayed failure from an old Chat context cannot replace the current phone state',async()=>{
 const b=setup(),gate=deferred();await flush();b.toggle.click();await flush();
 b.setStateResponder(({body})=>Number(body.conversation_id)===10?gate.promise:undefined);
 const pending=b.window.VP3PhoneWorkspaceV1.refresh();await flush();b.state.numbers[0].phone_e164='+16025550999';b.window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true});await flush();
 assert.match(b.document.querySelector('.vp3-phone-panel').textContent,/\+16025550999/);
 gate.resolve({ok:false,status:503,json:async()=>({ok:false,error:'phone_upgrade_required',message:'Old context error'})});await pending;await flush();
 assert.match(b.document.querySelector('.vp3-phone-panel').textContent,/\+16025550999/);assert.doesNotMatch(b.document.querySelector('.vp3-phone-panel').textContent,/database upgrade|Old context error/);assert.equal(!b.findButton('Retry')||Boolean(b.findButton('Retry').closest('[hidden]')),true);assert.ok(b.calls.every(call=>call.action==='state'));b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('an unavailable recording schema leaves connections, contacts and call history usable',async()=>{
 const b=setup();b.state.recording_readiness={ready:false};b.state.voicemails=[];
 b.state.contacts=[{id:7,name:'Fixture saved caller',phone_e164:'+16025550888',favorite:false}];
 b.state.calls=[{public_id:'9'.repeat(32),peer_e164:'+16025550888',direction:'inbound',state:'completed'}];
 await flush();b.toggle.click();await flush();
 assert.equal(b.field('number').value,'1');assert.equal(b.findButton('Call').disabled,false);
 assert.equal(b.field('record-call'),undefined);assert.equal(b.findButton('Save after-call settings'),undefined);
 b.findButton('Contacts').click();assert.match(b.document.getElementById('vp3PhoneSection-contacts').textContent,/Fixture saved caller/);
 b.findButton('Recent calls').click();assert.match(b.document.getElementById('vp3PhoneSection-calls').textContent,/Fixture saved caller/);
 b.findButton('Voicemail').click();const voicemail=b.document.getElementById('vp3PhoneSection-voicemail');assert.match(voicemail.textContent,/Cloud database upgrade/);assert.match(voicemail.textContent,/upgrade\.php/);assert.doesNotMatch(voicemail.textContent,/No saved voicemail/);
 const retry=voicemail.querySelector('button');assert.equal(retry.textContent,'Retry');assert.equal(retry.disabled,false);
 b.state.recording_readiness={ready:true};retry.click();await flush();assert.match(voicemail.textContent,/No saved voicemail/);assert.ok(b.field('record-call'));assert.ok(b.findButton('Save after-call settings'));assert.ok(b.calls.every(call=>call.action==='state'));b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('authentication and assignment errors still erase private phone content rather than offering a retry',async()=>{
 for(const status of [401,403]){
  const b=setup();await flush();b.toggle.click();await flush();b.setAuthorized(false,status);await b.window.VP3PhoneWorkspaceV1.refresh();await flush();
  assert.equal(b.toggle.hidden,true);assert.equal(b.document.querySelector('.vp3-phone-workspace').hidden,true);assert.doesNotMatch(b.document.querySelector('.vp3-phone-panel').textContent,/\+16025550111|Synthetic transcript/);assert.equal(!b.findButton('Retry')||Boolean(b.findButton('Retry').closest('[hidden]')),true);b.window.VP3PhoneWorkspaceV1.dispose();
 }
});
function contactFixture(testOptions={}){
 let contact={id:7,name:'Ada Contact',phone_e164:'+16025550177',relationship:'Friend',notes:'Private project note',revision:'a'.repeat(64)},schedules=[];
 const reply=value=>({ok:true,status:200,json:async()=>({ok:true,...structuredClone(value)})});
 const b=setup({requestResponder:async({body,options,state})=>{
  const override=await testOptions.respond?.({body,options,state,contact,schedules,reply});if(override)return override;
  if(body.action==='phone_contact_detail'){if(testOptions.detailGate)await testOptions.detailGate.promise;return reply({contact,editable:true,schedules:{available:true,execution_enabled:false,items:schedules}});}
  if(body.action==='save_phone_contact'){
   if(testOptions.saveGate)await testOptions.saveGate.promise;
   contact={id:body.contact_id||7,name:body.name,phone_e164:body.phone,relationship:body.relationship,notes:body.notes,revision:'b'.repeat(64)};
   state.contacts=[{id:contact.id,name:contact.name,phone_e164:contact.phone_e164,relationship:contact.relationship}];return reply({contact,created:body.contact_id===0});
  }
  if(body.action==='save_phone_contact_schedule'){
   if(testOptions.scheduleGate)await testOptions.scheduleGate.promise;
   const schedule={event_id:body.event_id||12,revision:'c'.repeat(64),title:'Call '+contact.name,date:body.date,time:body.time,timezone:body.timezone,recurrence:body.recurrence,duration_minutes:body.duration_minutes,editable:true,objectives:body.objectives,status:'active',next_at:'2026-12-03T17:15:00+00:00',next_label:'Thu, Dec 3, 5:15 PM UTC',url:'https://fixture.test/calendar-event.php?id=12',execution_enabled:false};schedules=[schedule];return reply({schedule,created:body.event_id===0,execution_enabled:false});
  }
  if(body.action==='cancel_phone_contact_schedule'){schedules=[];return reply({event_id:body.event_id,cancelled:true,execution_enabled:false});}
 }});
 b.state.contact_details_ready=true;b.state.contacts=[{id:contact.id,name:contact.name,phone_e164:contact.phone_e164,relationship:contact.relationship}];return b;
}
async function openContacts(b){await flush();await b.window.VP3PhoneWorkspaceV1.refresh();b.toggle.click();b.findButton('Contacts').click();}
function setPhoneField(b,key,value,event='input'){const field=b.field(key);assert.ok(field,'Field '+key+' exists');field.value=value;field.emit(event);return b.field(key);}
await test('ddd shows a bounded spontaneous decision in its original Chat',async()=>{
 const b=setup();await flush();
 b.state.outreach_review={reason:'calling_window',at:'2026-11-26T14:00:00Z',questions:'private text'};
 await b.window.VP3PhoneWorkspaceV1.refresh();
 const read=()=>b.window.VP3PhoneWorkspaceV1.operatorDiagnostics(10,2);
 assert.match(JSON.stringify(read().pairs),/Outside allowed calling hours/);
 assert.doesNotMatch(JSON.stringify(read()),/private text/);
 assert.equal(b.window.VP3PhoneWorkspaceV1.operatorDiagnostics(11,2),null);
 b.state.outreach_review.reason='brainstorm_unavailable';await b.window.VP3PhoneWorkspaceV1.refresh();
 assert.match(JSON.stringify(read().pairs),/Private reason review unavailable/);
 b.state.outreach_review.reason='unrecognized confidential detail';
 await b.window.VP3PhoneWorkspaceV1.refresh();
 assert.doesNotMatch(JSON.stringify(read()),/spontaneous call review|confidential detail/);
 b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('Contact call agenda preserves questions and requires an explicit switch to initiate calls',async()=>{
 const agenda={revision:0,topics:'',questions:'',enabled:false,review_missed:false,review_account:false,number_id:0};
 const b=contactFixture({respond:({body,contact,reply})=>{
   if(body.action==='phone_contact_detail')return reply({contact,agenda,editable:true,schedules:{available:true,execution_enabled:false,items:[]}});
   if(body.action==='save_operator_agenda')return reply({agenda:{...body,revision:1}});
 }});
 await openContacts(b);b.findButton('Edit').click();await flush();b.findButton('Call agenda').click();
 setPhoneField(b,'agenda-questions','Can we confirm Friday?');setPhoneField(b,'agenda-topics','Launch date');
 assert.equal(b.field('agenda-enabled').checked,false);
 b.field('agenda-review_missed').checked=true;b.field('agenda-review_missed').emit('change');
 b.field('agenda-review_account').checked=true;b.field('agenda-review_account').emit('change');
 await b.window.VP3PhoneWorkspaceV1.refresh();
 assert.equal(b.field('agenda-questions').value,'Can we confirm Friday?');
 b.document.querySelector('.vp3-phone-agenda-form').emit('submit');await flush();
 const post=b.calls.find(item=>item.action==='save_operator_agenda');
 assert.equal(post.enabled,false);assert.equal(post.contact_id,7);assert.equal(post.questions,'Can we confirm Friday?');assert.equal(post.review_missed,true);assert.equal(post.review_account,true);
 assert.equal(b.facts.connects,0);b.findButton('Call agenda').click();assert.equal(b.field('agenda-topics').value,'Launch date');
 b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('Contacts add saves personal fields once and keeps drafts and focus across polling',async()=>{
 const gate=deferred(),b=contactFixture({saveGate:gate});await openContacts(b);b.findButton('+ Contact').click();
 for(const [key,value] of [['name','New <script> contact'],['phone','+16025550812'],['email','ada@example.test'],['relationship','Family'],['notes','Owner private <img src=x>\nCall about the project.']])setPhoneField(b,'contact-detail-'+key,value);
 const notes=b.field('contact-detail-notes');notes.focus();notes.setSelectionRange(6,6);await b.window.VP3PhoneWorkspaceV1.refresh();assert.equal(b.field('contact-detail-notes').value,'Owner private <img src=x>\nCall about the project.');assert.equal(b.document.activeElement,b.field('contact-detail-notes'));assert.equal(b.field('contact-detail-notes').selectionStart,6);
 const form=b.document.querySelector('.vp3-phone-contact-editor');form.emit('submit');form.emit('submit');await flush();
 const posts=b.calls.filter(call=>call.action==='save_phone_contact');assert.equal(posts.length,1);assert.equal(posts[0].contact_id,0);assert.equal(posts[0].expected_revision,'');assert.equal(posts[0].phone,'+16025550812');assert.equal(posts[0].email,'ada@example.test');assert.equal(posts[0].relationship,'Family');assert.equal(posts[0].notes,'Owner private <img src=x>\nCall about the project.');assert.equal(posts[0].csrf_token,'fixture');assert.equal(b.findButton('Save contact').disabled,true);
 gate.resolve();await flush();assert.equal(b.field('contact-detail-name').value,'New <script> contact');assert.equal(b.findButton('+ Scheduled call').disabled,false);assert.equal(b.document.querySelectorAll('img').length,0);assert.equal(b.document.querySelectorAll('script').length,0);assert.equal(b.facts.connects,0);assert.equal(b.facts.claims,0);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('incoming and outgoing call cards open a draft addressed to the matched contact',async()=>{
 for(const direction of ['inbound','outbound']){
  const b=contactFixture({respond:({body,contact,reply})=>body.action==='phone_contact_detail'?reply({contact:{...contact,email:'ada+calls@example.test'}}):undefined});
  const id='e'.repeat(32);
  b.state.calls=[{public_id:id,contact_id:7,contact_name:'Ada Contact',peer_e164:'+16025550177',direction,state:'answered'}];
  await flush();await b.window.VP3PhoneWorkspaceV1.refresh();
  const host=b.document.createElement('div');b.thread.append(host);b.window.VP3PhoneWorkspaceV1.bindMessage(host,{kind:'call',call_id:id});
  host.querySelectorAll('button').find(el=>el.textContent==='Send Email').click();await flush();
  assert.equal(b.location.href,'mailto:ada%2Bcalls@example.test');
  assert.equal(b.facts.connects,0);b.window.VP3PhoneWorkspaceV1.dispose();
 }
});
await test('active call can share a note with its linked handset without touching call audio',async()=>{
 const id='e'.repeat(32),device='b'.repeat(32),b=setup({requestResponder:({body,state})=>{
   if(body.action!=='share_call_item')return undefined;
   state.companion_cards[id].items.push({message:body.message,state:'pending'});
   return {ok:true,status:200,json:async()=>({ok:true,shared:true,exchange_id:body.exchange_id})};
 }});
 b.state.calls=[{public_id:id,state:'answered',direction:'inbound',conversation_id:10,contact_id:7,contact_name:'Ada',peer_e164:'+16025550177'}];
 b.state.companion_cards={[id]:{device_id:device,contact_id:7,name:'Ada’s phone',items:[]}};
 await flush();await b.window.VP3PhoneWorkspaceV1.refresh();
 const host=b.document.createElement('div');b.thread.append(host);b.window.VP3PhoneWorkspaceV1.bindMessage(host,{kind:'call',call_id:id});
 setPhoneField(b,'companion-'+id,'é'.repeat(501));
 assert.equal(b.findButton('Share to phone').disabled,true);
 assert.match(host.textContent,/Shorten this draft by 2 bytes/);
 assert.equal(b.calls.filter(row=>row.action==='share_call_item').length,0);
 setPhoneField(b,'companion-'+id,'Meet at the entrance\nhttps://example.test/source\nhttps://name:pass@example.test/private\nPlain text');b.findButton('Share to phone').click();await flush();
 const sent=b.calls.filter(row=>row.action==='share_call_item');assert.equal(sent.length,1);assert.equal(sent[0].device_id,device);
 const sourceLinks=host.querySelector('.vp3-phone-companion').querySelectorAll('a');
 assert.equal(sourceLinks.length,1);assert.equal(sourceLinks[0].href,'https://example.test/source');
 assert.equal(sourceLinks[0].rel,'noopener noreferrer');
 assert.match(host.textContent,/Meet at the entrance/);assert.equal(b.facts.connects,0);assert.equal(b.facts.microphones,0);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('existing private notes load only in contact detail and dirty fields block schedule changes',async()=>{
 const b=contactFixture();await openContacts(b);assert.doesNotMatch(b.document.querySelector('.vp3-phone-panel').textContent,/Private project note/);b.findButton('Edit').click();await flush();assert.equal(b.field('contact-detail-notes').value,'Private project note');assert.equal(b.field('contact-detail-relationship').value,'Friend');assert.doesNotMatch(b.thread.textContent,/Private project note/);
 setPhoneField(b,'contact-detail-phone','+16025550813');assert.equal(b.findButton('+ Scheduled call').disabled,true);assert.match(b.document.querySelector('.vp3-phone-panel').textContent,/Save the contact changes before editing a schedule/);
 b.document.querySelector('.vp3-phone-contact-editor').emit('submit');await flush();const saved=b.calls.find(call=>call.action==='save_phone_contact');assert.equal(saved.contact_id,7);assert.equal(saved.expected_revision,'a'.repeat(64));assert.equal(b.findButton('+ Scheduled call').disabled,false);b.findButton('Back to contacts').click();assert.doesNotMatch(b.document.querySelector('.vp3-phone-panel').textContent,/Private project note/);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('scheduled contact shows the last Cloud worker mode, errors and overdue runs',async()=>{
 const b=contactFixture();
 await openContacts(b);b.findButton('Edit').click();await flush();
 const panel=b.document.querySelector('.vp3-phone-panel');
 assert.match(panel.textContent,/Cloud worker status unavailable/);
 for(const [state,mode,outcome,label] of [
   ['observed','prepare','completed','Last run was preparation only'],
   ['observed','dispatch','completed','Last run allowed dialing'],
   ['stale','dispatch','failed','Last run is overdue · Last run reported an error · Last run allowed dialing'],
   ['observed',undefined,'completed','Last run mode unknown'],
   ['never_seen',undefined,'','No run observed']
 ]){
   b.state.schedule_worker={state,mode,outcome};
   await b.window.VP3PhoneWorkspaceV1.refresh();
   assert.ok(panel.textContent.includes(label),label);
 }
 assert.equal(b.calls.some(call=>call.action==='assign_scheduled_operator'),false);
 b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('daily and weekly contact schedules require explicit save and replay a stable request after failure',async()=>{
 let fail=true;const b=contactFixture({respond:({body})=>body.action==='save_phone_contact_schedule'&&fail?(fail=false,{ok:false,status:503,json:async()=>({ok:false,error:'phone_unavailable',message:'Internal SQL credential'})}):undefined});
 await openContacts(b);b.findButton('Edit').click();await flush();b.findButton('+ Scheduled call').click();
 setPhoneField(b,'contact-schedule-date','2026-12-03');setPhoneField(b,'contact-schedule-time','17:15');setPhoneField(b,'contact-schedule-timezone','UTC');setPhoneField(b,'contact-schedule-recurrence','daily','change');setPhoneField(b,'contact-schedule-duration_minutes','12');setPhoneField(b,'contact-schedule-objectives','Review project progress\nAgree the next step.');
 await b.window.VP3PhoneWorkspaceV1.refresh();assert.equal(b.field('contact-schedule-recurrence').value,'daily');assert.equal(b.field('contact-schedule-objectives').value,'Review project progress\nAgree the next step.');assert.equal(b.calls.some(call=>call.action==='save_phone_contact_schedule'),false);assert.match(b.document.querySelector('.vp3-phone-panel').textContent,/assign it to Operator for a scheduled agent call/);
 b.document.querySelector('.vp3-phone-schedule-form').emit('submit');await flush();assert.equal(b.field('contact-schedule-time').value,'17:15');assert.doesNotMatch(b.document.querySelector('.vp3-phone-panel').textContent,/Internal SQL credential/);b.document.querySelector('.vp3-phone-schedule-form').emit('submit');await flush();
 const saves=b.calls.filter(call=>call.action==='save_phone_contact_schedule');assert.equal(saves.length,2);assert.equal(saves[0].mutation_id,saves[1].mutation_id);assert.equal(saves[1].confirmed,true);assert.equal(saves[1].contact_revision,'a'.repeat(64));assert.equal(saves[1].recurrence,'daily');assert.equal(saves[1].duration_minutes,12);assert.equal(saves[1].time,'17:15');assert.match(b.document.querySelector('.vp3-phone-panel').textContent,/Every day/);assert.equal(b.facts.connects,0);
 b.findButton('Edit schedule').click();setPhoneField(b,'contact-schedule-recurrence','weekly','change');b.document.querySelector('.vp3-phone-schedule-form').emit('submit');await flush();const edit=b.calls.filter(call=>call.action==='save_phone_contact_schedule').at(-1);assert.equal(edit.event_id,12);assert.equal(edit.expected_revision,'c'.repeat(64));assert.equal(edit.recurrence,'weekly');assert.match(b.document.querySelector('.vp3-phone-panel').textContent,/Every week/);
 b.findButton('Cancel schedule').click();assert.equal(b.calls.some(call=>call.action==='cancel_phone_contact_schedule'),false);assert.match(b.document.querySelector('.vp3-phone-panel').textContent,/all future repeats/);b.findButton('Confirm cancellation').click();await flush();const cancel=b.calls.find(call=>call.action==='cancel_phone_contact_schedule');assert.equal(cancel.contact_id,7);assert.equal(cancel.event_id,12);assert.equal(cancel.expected_revision,'c'.repeat(64));assert.equal(cancel.confirmed,true);assert.match(b.document.querySelector('.vp3-phone-panel').textContent,/No scheduled calls/);assert.equal(b.facts.connects,0);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('duplicate schedule submits remain single flight and context changes erase pending private drafts',async()=>{
 const gate=deferred(),b=contactFixture({scheduleGate:gate});await openContacts(b);b.findButton('Edit').click();await flush();b.findButton('+ Scheduled call').click();setPhoneField(b,'contact-schedule-objectives','Confidential recurring objective');const form=b.document.querySelector('.vp3-phone-schedule-form');form.emit('submit');form.emit('submit');await flush();assert.equal(b.calls.filter(call=>call.action==='save_phone_contact_schedule').length,1);assert.equal(b.findButton('Save scheduled call').disabled,true);
 b.window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true});gate.resolve();await flush();assert.equal(b.field('contact-detail-notes'),undefined);assert.equal(b.field('contact-schedule-objectives'),undefined);assert.doesNotMatch(b.document.querySelector('.vp3-phone-panel').textContent,/Private project note|Confidential recurring objective|Every day/);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('late private contact details cannot cross Chat changes or auth loss',async()=>{
 for(const mode of ['context','auth']){
  const gate=deferred(),b=contactFixture({detailGate:gate});await openContacts(b);b.findButton('Edit').click();await flush();assert.match(b.document.querySelector('.vp3-phone-panel').textContent,/Loading contact/);
  if(mode==='context')b.window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true});else{b.setAuthorized(false,401);await b.window.VP3PhoneWorkspaceV1.refresh();}
  gate.resolve();await flush();assert.equal(b.field('contact-detail-notes'),undefined);assert.doesNotMatch(b.document.querySelector('.vp3-phone-panel').textContent,/Private project note|Loading contact/);if(mode==='auth')assert.equal(b.document.querySelector('.vp3-phone-workspace').hidden,true);b.window.VP3PhoneWorkspaceV1.dispose();
 }
});
const voicemailSettings=()=>({available:true,number_id:1,revision:0,rings:4,ring_seconds:24,transcribe:false,announce:true,greeting:{present:false,duration_seconds:0,preview_url:''}});
const voicemailResponse=({body,state})=>{
 if(body.action==='save_voicemail_settings')state.voicemail_settings={...state.voicemail_settings,revision:Number(body.expected_revision)+1,rings:body.rings,ring_seconds:body.rings*6,transcribe:body.transcribe,announce:body.announce};
 else if(body.action==='greeting_upload')state.voicemail_settings={...state.voicemail_settings,revision:Number(body.expected_revision)+1,greeting:{present:true,duration_seconds:1,preview_url:'/api/phone-voicemail-greeting-v1.php?number_id=1&revision=1'}};
 else if(body.action==='use_default_voicemail_greeting')state.voicemail_settings={...state.voicemail_settings,revision:Number(body.expected_revision)+1,greeting:{present:false,duration_seconds:0,preview_url:''}};
 else if(body.action==='delete_voicemail'){state.voicemails=state.voicemails.filter(row=>row.id!==body.voicemail_id);return {ok:true,status:200,json:async()=>({ok:true,deleted:true,redacted_chat_message_ids:[41]})};}
 else if(body.action==='post_voicemail')return {ok:true,status:200,json:async()=>({ok:true,posted:true,conversation_id:Number(body.conversation_id),message_id:41})};
 else if(body.action==='transcribe_voicemail')return {ok:true,status:200,json:async()=>({ok:true,queued:true})};else return undefined;
 return {ok:true,status:200,json:async()=>({ok:true,voicemail_settings:structuredClone(state.voicemail_settings)})};
};
const openVoicemail=async b=>{b.state.voicemail_settings=voicemailSettings();b.state.voicemails=[{id:'job-4',kind:'voicemail',call_id:'legacy-4',peer_e164:'+16025550222',audio_url:'/audio',transcript:'Saved private voicemail',can_transcribe:true,can_post:true,can_delete:true}];await flush();await b.window.VP3PhoneWorkspaceV1.refresh();b.toggle.click();await flush();b.findButton('Voicemail').click();return b.document.getElementById('vp3PhoneSection-voicemail');};
const recordGreeting=async b=>{b.findButton('Record greeting').emit('click',{isTrusted:true});await flush();for(let i=0;i<10;i++)b.facts.worklets.at(-1).port.onmessage({data:new ArrayBuffer(3200)});b.findButton('Stop recording greeting').click();await flush();};
await test('voicemail settings keep unsaved edits through polling and save one scoped revision',async()=>{
 const b=setup({requestResponder:voicemailResponse});const section=await openVoicemail(b);assert.match(section.textContent,/Approximate rings before voicemail/);assert.match(section.textContent,/4 rings · about 24 seconds/);assert.match(section.textContent,/waiting for the server worker/);
 const rings=b.field('voicemail-rings');rings.value='7';rings.emit('change');b.field('voicemail-transcribe').checked=true;b.field('voicemail-transcribe').emit('change');await b.window.VP3PhoneWorkspaceV1.refresh();assert.equal(b.field('voicemail-rings').value,'7');assert.equal(b.field('voicemail-transcribe').checked,true);
 const form=section.querySelector('.vp3-phone-voicemail-form');form.emit('submit');form.emit('submit');await flush();const saves=b.calls.filter(call=>call.action==='save_voicemail_settings');assert.equal(saves.length,1);assert.equal(JSON.stringify(saves[0]),JSON.stringify({action:'save_voicemail_settings',csrf_token:'fixture',number_id:1,expected_revision:0,rings:7,transcribe:true,announce:true,conversation_id:10,agent_id:2}));assert.equal(b.state.voicemail_settings.revision,1);assert.equal(b.state.post_call_policy.enabled,false);assert.equal(b.facts.claims,0);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('cached-state failures block voicemail mutations even through stale event handlers',async()=>{
 const b=setup({requestResponder:voicemailResponse});const section=await openVoicemail(b);const form=section.querySelector('.vp3-phone-voicemail-form'),record=b.findButton('Record greeting'),post=b.findButton('Post voicemail to Chat');b.findButton('Delete voicemail').click();const remove=b.findButton('Delete voicemail permanently');
 b.setStateResponder(async()=>({ok:false,status:503,json:async()=>({ok:false,error:'phone_unavailable'})}));await b.window.VP3PhoneWorkspaceV1.refresh();const before=b.calls.length;form.emit('submit');record.emit('click',{isTrusted:true});post.emit('click');remove.emit('click');await flush();assert.equal(b.calls.length,before);assert.equal(b.facts.claims,0);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('voicemail controls keep call recordings distinct and confirm deletion before scoped Chat redaction',async()=>{
 const b=setup({requestResponder:voicemailResponse});await openVoicemail(b);b.state.voicemails.push({id:'job-5',kind:'call_recording',call_id:'c'.repeat(32),peer_e164:'+16025550999',audio_url:'/call-audio',can_delete:false,can_post:false,can_transcribe:false});await b.window.VP3PhoneWorkspaceV1.refresh();const events=[];b.window.addEventListener('vp3:phone-workspace-event',event=>events.push(event.detail));
 const cards=b.document.getElementById('vp3PhoneSection-voicemail').querySelectorAll('.vp3-phone-voicemail');assert.equal(cards.length,2);assert.match(cards[1].textContent,/Call recording from/);assert.equal(cards[1].querySelectorAll('button').some(button=>/Delete|Transcribe|Post voicemail/.test(button.textContent)),false);
 b.findButton('Post voicemail to Chat').click();await flush();assert.equal(events[0].kind,'voicemail');assert.equal(events[0].conversation_id,10);assert.equal(events[0].agent_id,2);
 b.findButton('Delete voicemail').click();assert.equal(b.calls.some(call=>call.action==='delete_voicemail'),false);b.findButton('Keep voicemail').click();assert.ok(b.findButton('Delete voicemail'));b.findButton('Delete voicemail').click();const remove=b.findButton('Delete voicemail permanently');remove.click();remove.click();await flush();assert.equal(b.calls.filter(call=>call.action==='delete_voicemail').length,1);assert.equal(events.at(-1).kind,'voicemail_deleted');assert.equal(events.at(-1).voicemail_id,'job-4');assert.equal(JSON.stringify(events.at(-1).redacted_chat_message_ids),'[41]');assert.equal(b.state.voicemails.length,1);assert.doesNotMatch(b.document.querySelector('.vp3-phone-panel').textContent,/Saved private voicemail/);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('greeting recording requires a trusted gesture and releases its microphone before explicit preview save',async()=>{
 const b=setup({requestResponder:voicemailResponse});await openVoicemail(b);b.findButton('Record greeting').click();await flush();assert.equal(b.facts.claims,0);assert.equal(b.facts.microphones,0);await recordGreeting(b);
 assert.equal(b.facts.claims,1);assert.equal(b.facts.releases,1);assert.equal(b.facts.microphones,1);assert.equal(b.facts.audioCloses,1);assert.ok(b.tracks.every(track=>track.readyState==='ended'));assert.deepEqual(b.facts.workletAssets,['https://fixture.test/phone-voicemail-capture-v1.js']);assert.ok(b.findButton('Save greeting'));assert.equal(b.calls.some(call=>call.action==='greeting_upload'),false);
 const preview=b.document.querySelector('.vp3-phone-greeting').querySelector('audio');assert.ok(preview.src.startsWith('blob:'));b.findButton('Save greeting').click();await flush();const upload=b.calls.find(call=>call.action==='greeting_upload');assert.equal(upload.number_id,'1');assert.equal(upload.expected_revision,'0');assert.equal(upload.conversation_id,'10');assert.equal(upload.agent_id,'2');assert.equal(upload.csrf_token,'fixture');assert.equal(upload.greeting.size,32044);const bytes=Buffer.from(await upload.greeting.arrayBuffer());assert.equal(bytes.toString('ascii',0,4),'RIFF');assert.equal(bytes.readUInt32LE(24),16000);assert.equal(b.state.voicemail_settings.greeting.present,true);assert.equal(b.findButton('Save greeting'),undefined);assert.match(b.document.querySelector('.vp3-phone-greeting').textContent,/recorded greeting is active/);assert.equal(b.facts.connects,0);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('greeting capture releases on Cancel, tab, context, close, pagehide and lease loss',async()=>{
 for(const stop of ['cancel','tab','context','close','pagehide','lease']){
  const b=setup();await openVoicemail(b);b.findButton('Record greeting').emit('click',{isTrusted:true});await flush();assert.equal(b.facts.microphones,1);
  if(stop==='cancel')b.findButton('Cancel greeting').click();if(stop==='tab')b.findButton('Contacts').click();if(stop==='context')b.window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:12,agent_id:2,ready:true});if(stop==='close')b.findButton('Close').click();if(stop==='pagehide')b.window.emit('pagehide');if(stop==='lease')b.window.emit('stonefellow:voice-lease-lost');await flush();
  assert.equal(b.facts.claims,b.facts.releases,stop);assert.ok(b.tracks.every(track=>track.readyState==='ended'),stop);assert.equal(b.facts.audioCloses,1,stop);assert.equal(b.calls.some(call=>call.action==='greeting_upload'),false,stop);b.window.VP3PhoneWorkspaceV1.dispose();
 }
});
await test('late microphone permission and rejected ownership cannot start or retain a greeting',async()=>{
 const gate=deferred(),b=setup({microphoneGate:gate});await openVoicemail(b);b.findButton('Record greeting').emit('click',{isTrusted:true});await flush();b.findButton('Cancel greeting').click();gate.resolve();await flush();assert.equal(b.facts.worklets.length,0);assert.ok(b.tracks.every(track=>track.readyState==='ended'));assert.equal(b.facts.claims,b.facts.releases);b.window.VP3PhoneWorkspaceV1.dispose();
 const rejected=setup();await openVoicemail(rejected);rejected.facts.rejectStream=true;rejected.findButton('Record greeting').emit('click',{isTrusted:true});await flush();assert.equal(rejected.facts.worklets.length,0);assert.ok(rejected.tracks.every(track=>track.readyState==='ended'));assert.equal(rejected.facts.claims,rejected.facts.releases);assert.match(rejected.document.querySelector('.vp3-phone-notice').textContent,/ownership changed/);rejected.window.VP3PhoneWorkspaceV1.dispose();
});
await test('greeting upload clears private state on malformed auth responses before JSON is read',async()=>{
 for(const status of [401,403]){
  let decoded=0;const b=setup({requestResponder:({body})=>body.action==='greeting_upload'?{ok:false,status,json:async()=>{decoded++;throw new SyntaxError('private database error');}}:undefined});await openVoicemail(b);await recordGreeting(b);b.findButton('Save greeting').click();await flush();assert.equal(decoded,0);assert.equal(b.toggle.hidden,true);assert.doesNotMatch(b.document.querySelector('.vp3-phone-panel').textContent,/Saved private voicemail|private database error/);assert.equal(b.facts.claims,b.facts.releases);b.window.VP3PhoneWorkspaceV1.dispose();
 }
});
await test('malformed greeting confirmations never claim success or leak upstream details',async()=>{
 for(const result of [{ok:true},{ok:true,voicemail_settings:{...voicemailSettings(),number_id:9,revision:1}},{ok:true,voicemail_settings:{...voicemailSettings(),revision:0}}]){
  const b=setup({requestResponder:({body})=>body.action==='greeting_upload'?{ok:true,status:200,json:async()=>result}:undefined});await openVoicemail(b);await recordGreeting(b);b.findButton('Save greeting').click();await flush();assert.ok(b.findButton('Save greeting'));assert.doesNotMatch(b.document.querySelector('.vp3-phone-notice').textContent,/greeting is saved/);assert.match(b.document.querySelector('.vp3-phone-notice').textContent,/incomplete confirmation/);b.window.VP3PhoneWorkspaceV1.dispose();
 }
});
await test('late greeting saves cannot cross Cancel, selected line or Chat context',async()=>{
 for(const change of ['cancel','line','context']){
  const gate=deferred(),b=setup({requestResponder:({body})=>body.action==='greeting_upload'?gate.promise:undefined});await openVoicemail(b);await recordGreeting(b);b.findButton('Save greeting').click();await flush();
  if(change==='cancel')b.findButton('Cancel greeting').click();if(change==='line'){b.state.numbers=[{...b.state.numbers[0],id:2,phone_e164:'+16025550777'}];b.state.selected_number_id=2;b.state.voicemail_settings={...voicemailSettings(),number_id:2};await b.window.VP3PhoneWorkspaceV1.refresh();}if(change==='context')b.window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:12,agent_id:2,ready:true});
  gate.resolve({ok:true,status:200,json:async()=>({ok:true,voicemail_settings:{...voicemailSettings(),revision:1,greeting:{present:true,duration_seconds:1,preview_url:'/api/phone-voicemail-greeting-v1.php?number_id=1&revision=1'}}})});await flush();assert.equal(b.findButton('Save greeting'),undefined,change);assert.doesNotMatch(b.document.querySelector('.vp3-phone-notice').textContent,/personal voicemail greeting is saved/,change);assert.equal(b.facts.claims,b.facts.releases,change);b.window.VP3PhoneWorkspaceV1.dispose();
 }
});
await test('polled settings from another tab cannot advance an unsaved greeting revision',async()=>{
 const b=setup({requestResponder:({body})=>body.action==='greeting_upload'?{ok:false,status:409,json:async()=>({ok:false,error:'phone_voicemail_changed'})}:undefined});await openVoicemail(b);await recordGreeting(b);
 b.state.voicemail_settings={...voicemailSettings(),revision:3,rings:6,ring_seconds:36};await b.window.VP3PhoneWorkspaceV1.refresh();assert.equal(b.field('voicemail-rings').value,'6');b.findButton('Save greeting').click();await flush();
 assert.equal(b.calls.find(call=>call.action==='greeting_upload').expected_revision,'0','Upload remains bound to the greeting that the owner recorded and previewed');assert.match(b.document.querySelector('.vp3-phone-notice').textContent,/settings changed.*Record and review a new greeting/);assert.ok(b.findButton('Record again'));assert.equal(b.state.voicemail_settings.revision,3);b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('ordinary Recent calls exposes its scoped agenda review and clears findings on Chat changes',async()=>{
 const id='f'.repeat(32),b=setup({requestResponder:({body})=>body.action==='operator_objectives_review'?{ok:true,status:200,json:async()=>({ok:true,review:{view:'call_agenda',call_id:id,execution_enabled:false,coverage:'ready',topics:'Planning',questions:[{question:'Which day works?',state:'answer_suggested',answer:'Friday works.',source_url:'/artist-listening-evidence.php?session=1&plugin=qa&item=answer'}],continuity:{state:'permission_required',questions:[]}}})}:undefined});
 b.state.operator_objectives_available=true;
 b.state.calls=[{public_id:id,call_id:id,peer_e164:'+16025550122',state:'completed',source_kind:'browser',conversation_id:10,agent_id:2,number_id:1}];
 await flush();await b.window.VP3PhoneWorkspaceV1.refresh();b.toggle.click();b.findButton('Recent calls').click();
 const section=b.document.querySelector('#vp3PhoneSection-calls');
 const review=section.querySelectorAll('button').find(button=>button.textContent==='Review call agenda');assert.ok(review);
 review.emit('click',{isTrusted:true});await flush();
 assert.match(section.textContent,/Answer suggestion · review needed/);assert.match(section.textContent,/Which day works\?/);
 assert.equal(b.calls.filter(call=>call.action==='operator_objectives_review').length,1);assert.equal(b.facts.connects,0);assert.equal(b.facts.microphones,0);
 b.window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:12,agent_id:2,ready:true});await flush();
 assert.equal(b.document.querySelector('[data-managed-conversation-review]'),null);assert.equal(b.findButton('Review call agenda'),undefined);
 b.window.VP3PhoneWorkspaceV1.dispose();
});
await test('question preparation appears once per call card and ddd keeps only fixed counts and reasons',async()=>{
 const b=setup(),id='6'.repeat(32),receipt={kind:'operator_question_preparation_v1',reason:'open_question',pending_count:1,reviewed_count:1,fingerprint:'b'.repeat(64),source_url:'/artist-listening-evidence.php?session=7&item=open',prepared_epoch:1795737600,answer:'PRIVATE ANSWER <img src=x>'};
 b.state.calls=[{public_id:id,call_id:id,peer_e164:'+16025550122',state:'dialing',conversation_id:10,direction:'outbound',question_preparation:receipt}];b.state.question_preparation_review=receipt;
 await flush();await b.window.VP3PhoneWorkspaceV1.refresh();b.toggle.click();b.findButton('Recent calls').click();
 const section=b.document.querySelector('#vp3PhoneSection-calls');assert.match(section.textContent,/An unanswered finding leads this question list/);assert.match(section.textContent,/Reviewed answers omitted: 1/);
 assert.equal(section.querySelectorAll('a').filter(a=>a.textContent==='Review earlier call finding').length,1);assert.doesNotMatch(section.textContent,/PRIVATE ANSWER|<img/);
 const diagnostics=JSON.stringify(b.window.VP3PhoneWorkspaceV1.operatorDiagnostics(10,2));assert.match(diagnostics,/Last call question preparation/);assert.doesNotMatch(diagnostics,/PRIVATE ANSWER|artist-listening|16025550122/);
 await flush();b.state.calls[0].question_preparation.source_url='https://evil.test/artist-listening-evidence.php';await b.window.VP3PhoneWorkspaceV1.refresh();assert.equal(section.querySelectorAll('a').length,0);
 b.window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:12,agent_id:2,ready:true});assert.equal(b.window.VP3PhoneWorkspaceV1.operatorDiagnostics(10,2),null);assert.doesNotMatch(section.textContent,/unanswered finding/);assert.equal(b.facts.connects,0);assert.equal(b.facts.microphones,0);b.window.VP3PhoneWorkspaceV1.dispose();
});
console.log('PHONE_WORKSPACE_DOM=PASS ('+count+' cases)');
