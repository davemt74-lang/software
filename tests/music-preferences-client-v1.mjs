import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import {randomUUID} from 'node:crypto';

// Shipped preference controller with an explicit DOM/transport boundary.
// SQL, real HTTP, browser geometry and canonical receipts have separate gates.
class Node {
  constructor(tag) { this.tag=tag;this.children=[];this.dataset={};this.attributes={};this.events={};this.className='';this.ownText='';this.hidden=false; }
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
const body=new Node('body'),events={},requests=[],timers=new Map();
let timerId=0,checks=0,currentConversation=43,replyOwner=11,denyRead=false,pendingSet=null,loseSet=false,skipFailures=0,metadataAvailable=true;
let delayedSetResponse=null,delayedReadResponse=null;
let preference='neutral',revision=0,writes=0;
const receipts=new Set();
const check=(actual,message)=>{assert.ok(actual,message);checks++;};
const equal=(actual,expected,message)=>{assert.deepEqual(actual,expected,message);checks++;};
const json=value=>JSON.parse(JSON.stringify(value));
const scope={owner_user_id:11,agent_id:7,conversation_id:43};
const item={source:'vp3',source_id:'71',title:'A <script>song</script>',available:true};
const selection={kind:'result',result_id:'a'.repeat(32),revision:'b'.repeat(64),index:0};
const state=()=>({protocol:'vp3_music_preferences_v1',...scope,owner_user_id:replyOwner,...item,preference,revision,
  ...(metadataAvailable?{}:{source_id:'',title:'Unavailable song'}),metadata_available:metadataAvailable,
  has_saved_preference:preference!=='neutral',reason:'Saved choice.'});
const response=(data,status=200)=>({ok:status>=200&&status<300,status,json:async()=>data});
const document={body,hidden:false,createElement:tag=>new Node(tag),querySelector:selector=>body.querySelector(selector)};
const window={VP3_MUSIC_PREFERENCES_V1_CONFIG:{endpoint:'/api/music-preferences-v1.php',csrf:'fixture',userId:11,agentId:7,conversationId:43},
  STONEFELLOW_CHAT:{userId:11,agentId:7,csrf:'fixture'},STONEFELLOW_CHAT_CONTINUITY:{conversationId:()=>currentConversation},
  VP3ParticipantContinuity:{selectedContext:()=>null},
  addEventListener(type,callback){(events[type]??=[]).push(callback);},
  dispatchEvent(event){for(const callback of events[event.type]??[])callback(event);}};
const fetch=async(url,options)=>{
  const input=JSON.parse(options.body);requests.push({url,body:options.body,input});
  equal(url.origin,'https://vp3.test','Preference transport remains on the current origin');
  if(input.action==='read'){
    const reply=denyRead?response({ok:false,error:'Source revoked.'},403):response({ok:true,state:state()});
    if(delayedReadResponse){const pending=delayedReadResponse;delayedReadResponse=null;await pending;}
    return reply;
  }
  if(input.action==='set'){
    if(pendingSet)await pendingSet;
    if(receipts.has(input.request_id))return response({ok:true,state:state(),replayed:true});
    if(input.expected_revision!==revision)return response({ok:false,error:'Stale choice.'},409);
    preference=input.preference==='clear'?'neutral':input.preference;revision++;writes++;receipts.add(input.request_id);
    if(loseSet){const kind=loseSet;loseSet=false;if(kind==='503')return response({ok:false,error:'Upstream temporarily unavailable.'},503);
      throw new Error('Response lost after commit.');}
    const acknowledged={ok:true,state:state(),replayed:false};
    if(delayedSetResponse){const pending=delayedSetResponse;delayedSetResponse=null;await pending;}
    return response(acknowledged);
  }
  if(input.action==='skip'){
    if(skipFailures-->0)throw new Error('Skip response lost.');
    return response({ok:true,recorded:true,replayed:false});
  }
  if(input.action==='list')return response({ok:true,preferences:{protocol:'vp3_music_preferences_v1',...scope,
    items:[{reference:'mp1.unavailable',source:'youtube',source_id:'',title:'PRIVATE PROVIDER TITLE',artist:'PRIVATE ARTIST',
      preference:'neutral',has_saved_preference:true,revision:1,metadata_available:false,reason:'Source unavailable.'}],has_more:false,next_cursor:null,
    coverage:{summary:'Current private preferences.'}}});
  throw new Error('Unexpected action '+input.action);
};
const context=vm.createContext({window,document,location:new URL('https://vp3.test/chat.php'),URL,crypto:{randomUUID},fetch,
  AbortController,CustomEvent:class{constructor(type,options={}){this.type=type;this.detail=options.detail;}},queueMicrotask,
  setTimeout(fn,delay){const id=++timerId;timers.set(id,{fn,delay});return id;},clearTimeout:id=>timers.delete(id),console});
vm.runInContext(await fs.readFile(new URL('../assets/js/music-preferences-v1.js',import.meta.url),'utf8'),context);
const preferences=window.VP3_MUSIC_PREFERENCES_V1;
const settle=async()=>{for(let i=0;i<50;i++)await Promise.resolve();};
function attach(overrides={}){const host=new Node('li');body.append(host);return preferences.attachItem(host,{payload:scope,item,selection,...overrides});}
const first=attach(),second=attach({selection:{kind:'history',source_message_id:21,revision:'c'.repeat(64),entry_ref:'mh1.receipt'}});
await settle();
equal(requests.filter(row=>row.input.action==='read').length,2,'Both exact current views quietly read their preference');
equal(requests.filter(row=>row.input.action==='set').length,0,'Rendering does not write preferences');
check(first.buttons.clear.disabled,'Clear is disabled for a neutral choice');
equal(first.buttons.like.attributes['aria-pressed'],'false','Neutral Like is not pressed');
let release;
pendingSet=new Promise(resolve=>{release=resolve;});
first.buttons.like.click();first.buttons.like.click();await settle();
equal(requests.filter(row=>row.input.action==='set').length,1,'A pending explicit click cannot create duplicate writes');
const firstBody=requests.find(row=>row.input.action==='set').input;
equal(json(firstBody.selection),selection,'The exact saved selection, not copied metadata, binds the change');
check(!('title'in firstBody)&&!('source_id'in firstBody),'The browser cannot supply source metadata as mutation authority');
release();pendingSet=null;await settle();
equal(writes,1,'Exactly one Like committed');
equal(first.buttons.like.attributes['aria-pressed'],'true','Acknowledged Like is visible');
equal(second.buttons.like.attributes['aria-pressed'],'true','The other history row refreshes the same song choice');
loseSet=true;
first.buttons.dislike.click();await settle();
equal(writes,2,'The simulated lost response occurs after a real committed change');
check(!first.retryButton.hidden,'An uncertain mutation offers Retry');
check(first.buttons.like.disabled&&first.buttons.dislike.disabled,'An uncertain mutation cannot become a different new write');
const lost=requests.filter(row=>row.input.action==='set').at(-1).body;
preference='like';revision++;
first.retryButton.click();await settle();
equal(requests.filter(row=>row.input.action==='set').at(-1).body,lost,'Retry retains identical request ID, CAS revision, selection and choice');
equal(writes,2,'An old retry does not resurrect or repeat a committed choice');
equal(first.buttons.like.attributes['aria-pressed'],'true','Retry displays the server’s current preference rather than its old desired choice');
preference='dislike';revision++;
const writesBeforeConflict=writes;
first.buttons.clear.click();await settle();
equal(writes,writesBeforeConflict,'A stale CAS does not change the saved choice');
equal(first.buttons.dislike.attributes['aria-pressed'],'true','CAS conflict quietly rereads the current choice');
check(first.retryButton.hidden,'A rejected CAS is not presented as an uncertain applied write');
first.buttons.clear.click();await settle();
equal(preference,'neutral','A fresh explicit Clear removes the preference');
check(first.buttons.clear.disabled,'Cleared preference returns to neutral controls');
loseSet='503';first.buttons.like.click();await settle();
const unavailableWrite=requests.filter(row=>row.input.action==='set').at(-1).body;
check(!first.retryButton.hidden,'A 503 can follow a committed write and remains retryable with the same identity');
first.retryButton.click();await settle();
equal(requests.filter(row=>row.input.action==='set').at(-1).body,unavailableWrite,'A 503 retry preserves its frozen mutation body');
first.buttons.clear.click();await settle();
let releaseOlder;
delayedSetResponse=new Promise(resolve=>{releaseOlder=resolve;});
first.buttons.like.click();await settle();
const writesBeforeNewerRead=writes;
check(first.busy&&preference==='like','An accepted mutation can still have an outstanding response');
preference='neutral';revision++;
const newer=attach();await settle();
equal(newer.state.preference,'neutral','Another current view can acknowledge a newer cleared revision');
releaseOlder();await settle();
equal(first.state.preference,'neutral','A delayed older success cannot replace a known newer preference');
equal(first.state.revision,revision,'The superseded response triggers a fresh exact-selection read');
equal(writes,writesBeforeNewerRead,'Refreshing an older successful response never sends another mutation');
check(first.retry===null&&first.retryButton.hidden,'An acknowledged superseded success is not an uncertain write');
let releaseOlderRead;
delayedReadResponse=new Promise(resolve=>{releaseOlderRead=resolve;});
const olderRead=attach();await settle();
preference='dislike';revision++;
const currentRead=attach();await settle();
equal(currentRead.state.preference,'dislike','A concurrent read can observe a newer explicit preference');
releaseOlderRead();await settle();
equal(olderRead.state.preference,'dislike','A delayed older read cannot replace a newer observed choice');
equal(olderRead.state.revision,revision,'A delayed older read refreshes against the current source revision');
equal(writes,writesBeforeNewerRead,'Read ordering adds no preference mutations');
replyOwner=99;
first.refreshButton.click();await settle();
check(first.state===null&&first.buttons.like.disabled,'A mismatched response scope cannot expose or change another owner’s choice');
replyOwner=11;
let revoked=0;denyRead=true;
const denied=attach({onUnavailable:()=>{revoked++;}});await settle();
equal(revoked,1,'Current-source refusal asks the parent view to refresh its metadata');
check(denied.buttons.like.disabled&&denied.buttons.dislike.disabled,'Revoked choices stay disabled');
denyRead=false;
let redactedRefreshes=0;
const redacted=attach({onUnavailable:()=>{redactedRefreshes++;}});await settle();
loseSet=true;redacted.buttons.like.click();await settle();metadataAvailable=false;
redacted.retryButton.click();await settle();
equal(redactedRefreshes,1,'A replay after source revocation refreshes the parent’s current metadata');
check(redacted.retry===null&&redacted.retryButton.hidden&&redacted.buttons.like.disabled,
  'A redacted successful replay ends the uncertain retry and disables stale controls');
check(!redacted.buttons.like.attributes['aria-label'].includes('script'),'Redacted replay removes old titles from feedback accessibility labels');
metadataAvailable=true;preference='neutral';revision++;
const skipsBefore=requests.filter(row=>row.input.action==='skip').length;
skipFailures=1;
preferences.recordSkip(scope,selection,{kind:'n',id:'123'});preferences.recordSkip(scope,selection,{kind:'n',id:'123'});await settle();
equal(requests.filter(row=>row.input.action==='skip').length,skipsBefore+1,'Repeated local notification emits one canonical skip');
const skipBody=requests.filter(row=>row.input.action==='skip').at(-1).body;
for(const [id,timer]of [...timers])if(timer.delay===500){timers.delete(id);timer.fn();}
await settle();
equal(requests.filter(row=>row.input.action==='skip').at(-1).body,skipBody,'A skip transport retry is byte-for-byte identical');
const confirmedSkips=requests.filter(row=>row.input.action==='skip').length;
preferences.recordSkip(scope,selection,{kind:'n',id:'0'});
document.hidden=true;preferences.recordSkip(scope,selection,{kind:'n',id:'124'});document.hidden=false;await settle();
equal(requests.filter(row=>row.input.action==='skip').length,confirmedSkips,'Invalid receipts and hidden-document intent cannot create skips');
check(preferences.openSaved(body,scope),'Saved choices open in the current document');await settle();
const panel=body.querySelector('.vp3-music-preferences-panel');
check(panel.textContent.includes('Unavailable song'),'Unavailable choices retain a removable placeholder');
check(!panel.textContent.includes('PRIVATE PROVIDER TITLE')&&!panel.textContent.includes('PRIVATE ARTIST'),'Unavailable saved choices cannot redisplay revoked metadata');
const unavailableRow=panel.querySelector('.vp3-music-preferences-saved-item');
equal(unavailableRow.querySelectorAll('button').filter(node=>node.dataset.musicPreference).map(node=>node.dataset.musicPreference),['clear'],
  'Unavailable stored choices expose only Clear');
check(!unavailableRow.querySelectorAll('button').find(node=>node.dataset.musicPreference==='clear').disabled,
  'A saved choice disabled by a consent change can still be explicitly cleared');
const beforeRestoreWrites=writes;
const message=new Node('article');body.append(message);
await preferences.attach(message,{protocol:'vp3_music_preferences_v1',...scope,action:'feedback',reference:'mp1.saved',preference:'like'});
await settle();
equal(writes,beforeRestoreWrites,'Restored feedback performs no mutation');
equal(message.querySelectorAll('button').find(node=>node.dataset.musicPreference==='like').attributes['aria-pressed'],'false',
  'Old Like feedback rehydrates the current cleared state');
check(message.textContent.includes('A <script>song</script>')&&message.querySelectorAll('script').length===0,
  'Current provider text is rendered as text without interpreting markup');
const listMessage=new Node('article');body.append(listMessage);
await preferences.attach(listMessage,{protocol:'vp3_music_preferences_v1',...scope,action:'list'});await settle();
check(message.isConnected&&listMessage.querySelector('.vp3-music-preferences-panel'),
  'Restored list and feedback cards coexist in ordinary Chat');
const stale=attach();await settle();
pendingSet=new Promise(resolve=>{release=resolve;});stale.buttons.like.click();await settle();
currentConversation=44;window.dispatchEvent({type:'stonefellow:chat-context-changing'});release();pendingSet=null;await settle();
equal(body.querySelectorAll('.vp3-music-preferences').length,0,'Changing Chat removes all private row controls');
equal(body.querySelectorAll('.vp3-music-preferences-panel').length,0,'Changing Chat removes the saved preference panel');
check(stale.host.isConnected===false,'A late mutation response cannot recreate an old Chat view');
console.log(`MUSIC_PREFERENCES_CLIENT_V1=PASS checks=${checks}`);
