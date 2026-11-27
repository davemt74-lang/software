import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import {randomUUID} from 'node:crypto';

// Execute the shipped controller. This DOM/transport fixture verifies state and
// ordering only; actual PHP, source receipts and browser rendering have separate gates.
class Node {
  constructor(tag) { this.tag=tag;this.children=[];this.dataset={};this.attributes={};this.events={};this.className='';this.ownText=''; }
  get textContent() { return this.ownText+this.children.map(child=>child.textContent).join(''); }
  set textContent(text) { this.ownText=String(text);this.replaceChildren(); }
  get isConnected() { return this.tag==='body'||Boolean(this.parentNode?.isConnected); }
  append(...children) { for(const child of children){child.remove();child.parentNode=this;this.children.push(child);} }
  remove() { if(this.parentNode){this.parentNode.children=this.parentNode.children.filter(child=>child!==this);this.parentNode=null;} }
  replaceChildren(...children) { for(const child of [...this.children])child.remove();this.append(...children); }
  setAttribute(name,value) { this.attributes[name]=String(value); }
  removeAttribute(name) { delete this.attributes[name]; }
  addEventListener(type,callback,options={}) { (this.events[type]??=[]).push({callback,signal:options.signal}); }
  emit(type) { for(const item of this.events[type]??[])if(!item.signal?.aborted)item.callback({target:this,type,preventDefault(){}}); }
  click() { if(!this.disabled)this.emit('click'); }
  all(match) { return [...(match(this)?[this]:[]),...this.children.flatMap(child=>child.all(match))]; }
  matches(selector) {
    if(selector.startsWith('.'))return this.className.split(' ').includes(selector.slice(1));
    const match=/^\[data-([a-z-]+)\]$/.exec(selector);
    if(match)return Object.hasOwn(this.dataset,match[1].replace(/-([a-z])/g,(_,letter)=>letter.toUpperCase()));
    return this.tag===selector;
  }
  querySelector(selector) { return this.all(node=>node!==this&&node.matches(selector))[0]??null; }
  querySelectorAll(selector) { return this.all(node=>node!==this&&node.matches(selector)); }
}
const body=new Node('body'),events={};
const document={body,hidden:false,createElement:tag=>new Node(tag),
  addEventListener(type,callback){(events[type]??=[]).push(callback);},querySelectorAll:selector=>body.querySelectorAll(selector)};
class Media extends Node {
  constructor(id) { super('audio');this.className='chat-audio-player';this.dataset.trackId=String(id);this.paused=true;this.readyState=4; }
  async play() { this.paused=false;for(const fn of events.play??[])fn({target:this});this.emit('playing'); }
  pause() { this.paused=true;this.emit('pause'); }
}
const config={endpoint:'/api/music-chat-v1.php',userId:11,agentId:7,csrf:'fixture'};
const requests=[],starts=[],payloads=new Map(),denied=new Set(),storage=new Map();
let nextId=0,currentConversation=42,resolveGate=null;
const scope={owner_user_id:11,agent_id:7,conversation_id:42};
const id=()=> (++nextId).toString(16).padStart(32,'0');
function payload(items,{intent='show',automatic=false}={}) {
  const value={protocol:'vp3_music_chat_v1',version:1,...scope,result_id:id(),revision:'a'.repeat(64),intent,
    requested_count:items.length,items:items.map(([source,source_id])=>({source,source_id,title:source+' '+source_id,available:true})),coverage:{}};
  if(automatic)value.autoplay={action_id:id(),index:0,expires_at:Math.floor(Date.now()/1000)+600};
  payloads.set(value.result_id,value);return value;
}
const native=[];let yt=null;
const window={VP3_MUSIC_CHAT_CONFIG_V1:config,STONEFELLOW_CHAT:{userId:11,agentId:7,csrf:'fixture'},
  VP3ParticipantContinuity:{selectedContext:()=>null},addEventListener(type,callback){(events[type]??=[]).push(callback);},
  STONEFELLOW_CHAT_CONTINUITY:{conversationId:()=>currentConversation,async applyMediaPlayback(action,message,options){
    if(!options.current())return false;
    const audio=new Media(action.track_id);body.append(audio);native.push(audio);starts.push('vp3:'+action.track_id);
    options.onReady(audio);await audio.play();return true;
  }},VP3_CHAT_YOUTUBE_V1:{pauseForMusic(){yt?.pause();},playMusic(host,button,result,observer){
    const current={id:result.item.source_id,playing:false,pause(){this.playing=false;observer.onState('paused');},
      resume(){this.playing=true;window.VP3_MUSIC_CHAT_V1.claimSource('youtube',{musicToken:observer.token});observer.onState('playing');},
      stop(){this.playing=false;if(yt===this)yt=null;observer.onState('stopped');},end(){this.playing=false;observer.onState('ended');}};
    yt=current;starts.push('youtube:'+current.id);current.resume();return current;
  }}};
const fetch=async(url,options)=>{
  const input=JSON.parse(options.body);requests.push(input);
  const value=payloads.get(input.result_id);
  if(input.action==='resolve'){
    if(resolveGate)await resolveGate;
    if(denied.has(value?.items[input.index]?.source_id))return {ok:false,status:410,json:async()=>({ok:false,error:'Source withdrawn.'})};
    const item=value.items[input.index];
    return {ok:true,status:200,json:async()=>({ok:true,...scope,item,
      ...(item.source==='vp3'?{media_playback:{track_id:item.source_id}}:{youtube_results:{}})})};
  }
  if(input.action==='result')return {ok:true,status:200,json:async()=>({ok:true,music_chat_v1:{...value,autoplay:undefined}})};
  throw new Error('Unexpected fixture action '+input.action);
};
const context=vm.createContext({window,document,location:new URL('https://vp3.test/chat.php'),URL,HTMLMediaElement:Media,
  crypto:{randomUUID},sessionStorage:{getItem:key=>storage.get(key),setItem:(key,value)=>storage.set(key,value)},
  fetch,AbortController,setTimeout,clearTimeout,console});
vm.runInContext(await fs.readFile(new URL('../assets/js/music-chat-v1.js',import.meta.url),'utf8'),context);
const music=window.VP3_MUSIC_CHAT_V1;
const settle=async()=>{for(let step=0;step<16;step++)await Promise.resolve();};
async function attach(value,live=true) { const message=new Node('article');body.append(message);return music.attach(message,value,{live}); }
const activeNative=()=>native.filter(audio=>!audio.paused).map(audio=>audio.dataset.trackId);
const source=payload([['vp3','41']],{intent:'play',automatic:true});
const first=await attach(source);
assert.equal(music.isPlaying(),true);
assert.equal(music.command('Play the next one'),'next','A relative follow-up controls the active music queue');
assert.equal(music.command('Please play the previous track'),'previous','Previous follows the active queue through typed or voice commands');
assert.equal(music.command('Play "The Next One"'),null,'A quoted song title remains a catalog request');
assert.equal(music.command('Play one song next'),null,'Scheduling a selection next remains a queue request');
const added=payload([['youtube','video000001']],{intent:'queue_append',automatic:true});
await attach(added);
assert.deepEqual(starts,['vp3:41'],'Appending keeps the current track and does not start the added source');
const inserted=payload([['vp3','42']],{intent:'play_next',automatic:true});
await attach(inserted);
assert.deepEqual(starts,['vp3:41'],'Play-next does not interrupt current playback');
await music.runCommand(music.command('Play the next one'));
assert.equal(starts.at(-1),'vp3:42','Play-next inserts after current song');
await music.runCommand('next');
assert.equal(starts.at(-1),'youtube:video000001','Append retains its tail position');
assert.deepEqual(activeNative(),[],'YouTube pauses native audio');
await music.runCommand(music.command('Play the previous track'));
assert.equal(starts.at(-1),'vp3:42','Previous restores the same stored entry');
assert.equal(yt,null,'Native takeover stops YouTube');
const beforeEnd=starts.length;
native.at(-1).emit('ended');native.at(-1).emit('ended');await settle();
assert.equal(starts.length,beforeEnd+1,'Duplicate end notifications advance once');
assert.equal(starts.at(-1),'youtube:video000001');
const beforeRestore=requests.filter(row=>row.action==='resolve').length;
await attach(added);await attach(added,false);
assert.equal(requests.filter(row=>row.action==='resolve').length,beforeRestore,'Duplicate and restored queue action remain quiet');
await music.runCommand('stop');
assert.equal(music.command('Play the next one'),null,'Without an active music queue the existing scoped continuation route owns next');
const ready=payload([['vp3','41'],['youtube','video000002']],{intent:'queue_append',automatic:true});
const readyCard=await attach(ready);
assert.equal(music.isPlaying(),false);
assert.equal(starts.length,beforeEnd+1,'Ready queue does not play automatically');
assert.equal(readyCard.transport.hidden,false);
assert.equal(music.command('play queue'),'resume');
await music.runCommand('resume');assert.equal(starts.at(-1),'vp3:41');
await music.runCommand('next');assert.equal(starts.at(-1),'youtube:video000002');
denied.add('video000002');
const deniedResponse=await music.runCommand('resume');
assert.doesNotMatch(deniedResponse.answer,/^Resuming/);
assert.equal(yt.playing,false,'Revoked resume stops the previous audible player');
assert.equal(music.isPlaying(),false);denied.clear();
await music.runCommand('stop');
let release;
resolveGate=new Promise(resolve=>{release=resolve;});
const stale=payload([['vp3','42']],{intent:'play',automatic:true});
const pending=attach(stale);await settle();await music.runCommand('pause');
const beforeLate=starts.length;release();await pending;resolveGate=null;
assert.equal(starts.length,beforeLate,'Late resolution cannot defeat pause');
await music.runCommand('resume');assert.equal(starts.at(-1),'vp3:42');
resolveGate=new Promise(resolve=>{release=resolve;});
const staleAppend=payload([['youtube','video000003']],{intent:'queue_append',automatic:true});
const pendingAppend=attach(staleAppend);await settle();await music.runCommand('stop');release();await pendingAppend;resolveGate=null;
assert.equal(music.command('play queue'),null,'Stopping during queue admission discards the late addition');
for(const callback of events['stonefellow:chat-context-changing']??[])callback();
assert.equal(body.querySelectorAll('.vp3-music-chat').length,0,'Context change removes all private cards');
console.log('MUSIC_CHAT_CONTROLLER=PASS · exact mixed queue, append/next/previous, quiet readiness/replay, source ownership, revoked resume and late-response cancellation');
