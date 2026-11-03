import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// Controlled DOM for the production IIFEs. The browser companion test executes
// the same flows in Chromium; this runner needs no downloaded browser or server.
const decode=value=>String(value).replace(/&(amp|lt|gt|quot|#039);/g,(_,key)=>({amp:'&',lt:'<',gt:'>',quot:'"','#039':"'"}[key]));
class Element {
  constructor(document,tag='div') {this.ownerDocument=document;this.tagName=tag.toUpperCase();this.children=[];this.attributes={};this.dataset={};this.events=[];this.parentElement=null;this.value='';this.checked=false;this.disabled=false;this.hidden=false;this.scrollTop=0;this.scrollHeight=100;this.clientHeight=100;this._html='';this._text=null;}
  get className(){return this.attributes.class||'';}set className(value){this.attributes.class=value;}
  get classList(){const node=this;return {contains:c=>node.className.split(/\s+/).includes(c),add:(...classes)=>node.className=[...new Set([...node.className.split(/\s+/),...classes])].join(' ').trim(),remove:(...classes)=>node.className=node.className.split(/\s+/).filter(c=>!classes.includes(c)).join(' '),toggle(c,on){const exists=this.contains(c);if(on===undefined?exists:!on)this.remove(c);else this.add(c);}};}
  get id(){return this.attributes.id||'';}set id(value){this.setAttribute('id',value);}
  get isConnected(){return this===this.ownerDocument.body||!!this.parentElement?.isConnected;}
  setAttribute(name,value){this.attributes[name]=String(value);if(name.startsWith('data-'))this.dataset[name.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=String(value);if(name==='value')this.value=decode(value);if(name==='checked')this.checked=true;if(name==='disabled')this.disabled=true;}
  getAttribute(name){return this.attributes[name]??null;}
  append(...nodes){for(const node of nodes){node.parentElement=this;this.children.push(node);}}
  appendChild(node){this.append(node);return node;}
  insertBefore(node,before){if(!before)return this.appendChild(node);node.parentElement=this;this.children.splice(this.children.indexOf(before),0,node);return node;}
  remove(){if(this.parentElement)this.parentElement.children=this.parentElement.children.filter(n=>n!==this);this.parentElement=null;}
  contains(node){while(node){if(node===this)return true;node=node.parentElement;}return false;}
  get innerHTML(){return this._html;}
  set innerHTML(html){this._html=String(html);this._text=null;for(const old of this.children)old.parentElement=null;this.children=[];const stack=[this];for(const token of this._html.match(/<[^>]+>|[^<]+/g)||[]){if(token.startsWith('</')){if(stack.length>1){const closed=stack.pop();if(closed.tagName==='TEXTAREA')closed.value=decode(closed._html);}}else if(token.startsWith('<')){const name=token.match(/^<([\w-]+)/)?.[1];if(!name)continue;const node=new Element(this.ownerDocument,name);const attrs=token.slice(name.length+1).replace(/\/?\s*>$/,'');for(const m of attrs.matchAll(/([^\s=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s]+)))?/g))node.setAttribute(m[1],decode(m[2]??m[3]??m[4]??''));stack.at(-1).append(node);if(!['input','img','br','meta','hr','link'].includes(name)&&!token.endsWith('/>'))stack.push(node);}else if(stack.at(-1)!==this)stack.at(-1)._html+=token;}for(const select of this.querySelectorAll('select'))select.value=(select.querySelector('option[selected]')||select.querySelector('option'))?.getAttribute('value')||'';}
  get textContent(){return this._text??decode(this._html.replace(/<[^>]*>/g,''));}set textContent(value){this._text=String(value);this._html='';this.children=[];}
  matches(selector){const attrs=[...selector.matchAll(/\[([^\]=]+)(?:=['"]?([^'"\]]*)['"]?)?\]/g)];let simple=selector.replace(/\[[^\]]+\]/g,'');if(attrs.some(([,key,value])=>key.startsWith('data-')?((value===undefined)?this.dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]===undefined:this.dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]!==value):(value===undefined?this.attributes[key]===undefined:this.attributes[key]!==value)))return false;const id=simple.match(/#([\w-]+)/)?.[1];if(id&&this.id!==id)return false;for(const [,c] of simple.matchAll(/\.([\w-]+)/g))if(!this.classList.contains(c))return false;const tag=simple.match(/^[\w-]+/)?.[0];return !tag||this.tagName===tag.toUpperCase();}
  querySelectorAll(selector){const selectors=selector.split(',').map(s=>s.trim()),all=[];const walk=node=>{for(const child of node.children){all.push(child);walk(child);}};walk(this);return all.filter(node=>selectors.some(s=>{if(s.startsWith(':scope > '))return node.parentElement===this&&node.matches(s.slice(9));const parts=s.split(/\s+/);if(!node.matches(parts.pop()))return false;let parent=node.parentElement;while(parts.length){const part=parts.pop();while(parent&&!parent.matches(part))parent=parent.parentElement;if(!parent)return false;parent=parent.parentElement;}return true;}));}
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
  closest(selector){let node=this;while(node){if(selector.split(',').some(s=>node.matches(s.trim())))return node;node=node.parentElement;}return null;}
  get elements(){return Object.fromEntries(this.querySelectorAll('input,textarea,select').map(node=>[node.getAttribute('name'),node]));}
  addEventListener(type,callback,capture=false){this.events.push({type,callback,capture});}
  fire(type){const event={type,target:this,currentTarget:null,preventDefault(){}},chain=[];let node=this;while(node){chain.push(node);node=node.parentElement;}const results=[];for(const capture of [true,false])for(const item of capture?[...chain].reverse():chain)for(const listener of item.events.filter(x=>x.type===type&&x.capture===capture)){event.currentTarget=item;results.push(listener.callback(event));}return Promise.all(results);}
  focus(){this.ownerDocument.activeElement=this;}setSelectionRange(start,end){this.selectionStart=start;this.selectionEnd=end;}scrollTo({top}){this.scrollTop=top;}scrollIntoView(){}
}
class Document {
  constructor(){this.body=new Element(this,'body');this.activeElement=this.body;this.visibilityState='visible';}
  createElement(tag){return new Element(this,tag);}querySelectorAll(selector){return this.body.querySelectorAll(selector);}querySelector(selector){return this.body.querySelector(selector);}getElementById(id){return this.querySelector('#'+id);}addEventListener(){}
}
class FormDataFixture {
  constructor(form){this.fields=new Map();if(form)for(const [name,node] of Object.entries(form.elements))if(node.getAttribute('type')!=='checkbox'||node.checked)this.fields.set(name,node.getAttribute('type')==='checkbox'?'on':node.value);}
  get(name){return this.fields.get(name)??null;}set(name,value){this.fields.set(name,value);}
}
const clone=value=>JSON.parse(JSON.stringify(value)),tick=()=>new Promise(resolve=>setImmediate(resolve));
let checks=0;const check=(ok,message)=>{assert.ok(ok,message);checks++;};
function fixture(surface){
  const document=new Document(),calls=[],pending=[],holds=[],timers=[];
  document.body.innerHTML='<div class="chat-topbar-actions"><div id="chatProfileMenu"></div></div><button id="chatNotificationButton"></button>';
  if(surface==='portal'){
    const app=document.createElement('main');app.className='profile-agent-app';document.body.append(app);
    app.innerHTML='<div id="profileAgentNotice"></div><div id="profileAgentServiceStatus"></div><div id="profileAgentMetrics"></div><div id="profileAgentPortal">'+['inbox','visitors','radar','agent','knowledge','profile','analytics'].map(name=>`<section data-pa-view="${name}"></section>`).join('')+['profileAgentAttention','profileAgentConversations','profileAgentThread','profileAgentVisitors','profileAgentRadar','profileAgentSettings','profileAgentKnowledge','profileAgentProfileSettings','profileAgentAnalytics'].map(id=>`<div id="${id}"></div>`).join('')+'</div>';
  }
  const profile={username:'owner',profile_agent_id:7,profile_agent_enabled:1,profile_agent_greeting:'Saved greeting'},threads=new Map([101,202].map(id=>[id,{conversation:{id,visitor_label:'Visitor '+id,status:'open'},messages:[{sender_type:'visitor',message:'Question '+id}]}]));
  const ownerState=()=>({profile:clone(profile),profile_url:'/owner',agents:[],policies:{},analytics:{},notifications:{},conversations:[...threads.values()].map(x=>x.conversation),activity:[],visits:[]});
  const response=payload=>({ok:payload.ok!==false,json:async()=>payload});
  const fetch=async(url,options={})=>{
    const input=options.body?JSON.parse(options.body):{action:new URL(url,'http://fixture.test').searchParams.get('action')};calls.push(input);let payload={ok:true};
    if(input.action==='owner_state')payload.state=ownerState();
    else if(input.action==='conversation_messages')Object.assign(payload,clone(threads.get(input.conversation_id)));
    else if(['owner_reply','conversation_status'].includes(input.action)){payload.conversation_id=input.conversation_id;payload.state=ownerState();}
    else if(input.action==='save_profile_agent'){Object.assign(profile,input);payload.state=ownerState();}
    else throw Error('Unexpected request '+input.action);
    const index=holds.findIndex(h=>h.action===input.action&&(h.id===undefined||h.id===input.conversation_id));
    if(index>=0){holds.splice(index,1);return new Promise(resolve=>pending.push({input,payload,release:extra=>resolve(response({...payload,...extra}))}));}
    return response(payload);
  };
  const window={location:{origin:'http://fixture.test',search:''},addEventListener(){},STONEFELLOW_AGENT_IDENTITY_V236:{profileAgentEndpoint:'/api/profile-agent.php',csrf:'fixture'},PROFILE_AGENT_PORTAL:{endpoint:'/api/profile-agent.php',csrf:'fixture'}};
  const sandbox={window,document,fetch,FormData:FormDataFixture,URL,URLSearchParams,console,localStorage:{getItem:()=>'',setItem(){}},requestAnimationFrame:fn=>fn(),setTimeout:()=>1,setInterval:fn=>{timers.push(fn);return timers.length;},clearInterval(){}};
  const source=fs.readFileSync(new URL('../'+(surface==='activity'?'profile-activity-chat.js':'profile-agent-portal.js'),import.meta.url),'utf8'),end=source.lastIndexOf('})();');
  const exports=surface==='activity'?'loadConversation,refresh,openCanvas':'openConversation,refresh,activateTab';
  vm.runInNewContext(source.slice(0,end)+`window.test={${exports}};`+source.slice(end),sandbox);
  const selectors=surface==='activity'?{form:'[data-profile-reply]',messages:'[data-profile-thread-messages]',notice:'[data-profile-thread-notice]',status:'[data-profile-resolve]'}:{form:'#paReplyForm',messages:'.profile-agent-thread-messages',notice:'[data-thread-notice]',status:'[data-thread-status="resolved"]'};
  return {surface,window,document,calls,threads,pending,holds,timers,selectors,load:id=>(window.test.loadConversation||window.test.openConversation)(id),form:()=>document.querySelector(selectors.form),input:()=>document.querySelector(selectors.form)?.elements.message,messages:()=>document.querySelector(selectors.messages)?.textContent,hold:(action,id)=>holds.push({action,id}),setDraft(text){const input=this.input();input.value=text;input.fire('input');},notice:()=>document.querySelector(selectors.notice)?.textContent};
}
for(const surface of ['activity','portal']){
  const f=fixture(surface);await tick();if(surface==='activity')f.window.test.openCanvas({tab:'conversations'});
  f.hold('conversation_messages',101);const a=f.load(101);f.hold('conversation_messages',202);const b=f.load(202);
  f.pending[1].release();await b;f.pending[0].release();await a;
  check(f.messages()==='VisitorQuestion 202',surface+' reverse thread reads retain the selected visitor');
  f.setDraft('Visible visitor reply');f.hold('owner_reply',202);const sending=f.form().fire('submit');
  check(f.calls.at(-1).conversation_id===202,surface+' actual displayed form callback uses its own conversation');
  f.pending.at(-1).release();await sending;check(f.input().value==='',surface+' exact mutation acknowledgment clears sent draft');
  for(const action of ['owner_reply','conversation_status'])for(const fail of [false,true]){
    await f.load(101);f.setDraft('Old visitor draft');f.hold(action,101);
    const mutating=action==='owner_reply'?f.form().fire('submit'):f.document.querySelector(f.selectors.status).fire('click');
    const mutation=f.pending.at(-1);await f.load(202);f.setDraft('Current visitor draft');mutation.release(fail?{ok:false,error:'STALE failure'}:{});await mutating;
    check(f.form().dataset.conversationId==='202'&&f.input().value==='Current visitor draft'&&!f.notice().includes('STALE'),surface+' stale '+action+' '+(fail?'failure':'success')+' cannot change current view');
  }
  await f.load(101);f.setDraft('Keep until confirmed');f.hold('owner_reply',101);const uncertain=f.form().fire('submit');f.pending.at(-1).release({conversation_id:202});await uncertain;
  check(f.input().value==='Keep until confirmed'&&f.notice().includes('could not be confirmed'),surface+' foreign mutation acknowledgment cannot clear the draft');
  const input=f.input();input.focus();input.setSelectionRange(2,6);f.setDraft('Unsent poll draft');
  f.threads.get(101).messages.push({sender_type:'visitor',message:'Incoming message'});await f.window.test.refresh();
  check(f.messages().includes('Incoming message')&&f.input()===input&&input.value==='Unsent poll draft'&&f.document.activeElement===input&&input.selectionStart===2,surface+' actual refresh preserves composer while updating transcript');
  await f.load(202);check(f.input().value==='Current visitor draft',surface+' draft belongs to visitor 202');await f.load(101);check(f.input().value==='Unsent poll draft',surface+' draft belongs to visitor 101');
  f.setDraft('Confirmed reply before read failure');f.hold('owner_reply',101);const confirmed=f.form().fire('submit');const ack=f.pending.at(-1);f.hold('conversation_messages',101);ack.release();await tick();f.pending.at(-1).release({ok:false,error:'Refresh unavailable'});await confirmed;
  check(f.notice().includes('Reply sent.')&&f.notice().includes('could not be loaded')&&f.input().value==='',surface+' confirmed write and failed transcript refresh remain distinct');
  f.hold('conversation_messages',202);const bad=f.load(202);f.pending.at(-1).release({conversation:{id:101},messages:[{message:'WRONG'}]});await bad;
  check(f.form()===null,surface+' invalid read identity cannot mount a composer');
}
const p=fixture('portal');await tick();p.window.test.activateTab('agent');
const greeting=p.document.querySelector('#paAgentForm [name="profile_agent_greeting"]');greeting.value='Dirty greeting';await greeting.fire('input');greeting.focus();
await p.window.test.refresh(true);await p.window.test.refresh(true);
check(p.document.querySelector('#paAgentForm [name="profile_agent_greeting"]')===greeting&&greeting.value==='Dirty greeting'&&p.document.activeElement===greeting,'portal periodic refresh preserves dirty settings node and focus');
p.document.body.focus();await p.window.test.refresh(true);check(p.document.querySelector('#paAgentForm [name="profile_agent_greeting"]')===greeting,'portal preserves a dirty form after focus leaves');
p.hold('save_profile_agent');const saving=p.document.getElementById('paAgentForm').fire('submit');check(p.calls.filter(x=>x.action==='save_profile_agent').length===1,'retained settings form is bound only once');
greeting.value='Edited while saving';await greeting.fire('input');p.pending.at(-1).release();await saving;
check(greeting.value==='Edited while saving'&&p.document.querySelector('#paAgentForm [name="profile_agent_greeting"]')===greeting,'settings acknowledgment cannot erase edits made during the save');
console.log(`PROFILE_OWNER_THREADS_PA1=${checks} assertions PASS`);
