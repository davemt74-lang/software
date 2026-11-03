import fs from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
const decode=value=>String(value).replace(/&(amp|lt|gt|quot|#039);/g,(_,key)=>({amp:'&',lt:'<',gt:'>',quot:'"','#039':"'"}[key]));
export class Element {
  constructor(document,tag='div') {this.ownerDocument=document;this.tagName=tag.toUpperCase();this.children=[];this.attributes={};this.dataset={};this.events=[];this.parentElement=null;this.value='';this.checked=false;this.disabled=false;this.hidden=false;this.scrollTop=0;this.scrollHeight=100;this.clientHeight=100;this._html='';this._text=null;this.style={};}
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
  get textContent(){return this._text??(this.children.length?this.children.map(n=>n.textContent).join(''):decode(this._html.replace(/<[^>]*>/g,'')));}set textContent(value){this._text=String(value);this._html='';this.children=[];}
  matches(selector){const attrs=[...selector.matchAll(/\[([^\]=]+)(?:=['"]?([^'"\]]*)['"]?)?\]/g)];let simple=selector.replace(/\[[^\]]+\]/g,'');if(attrs.some(([,key,value])=>key.startsWith('data-')?((value===undefined)?this.dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]===undefined:this.dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]!==value):(value===undefined?this.attributes[key]===undefined:this.attributes[key]!==value)))return false;const id=simple.match(/#([\w-]+)/)?.[1];if(id&&this.id!==id)return false;for(const [,c] of simple.matchAll(/\.([\w-]+)/g))if(!this.classList.contains(c))return false;const tag=simple.match(/^[\w-]+/)?.[0];return !tag||this.tagName===tag.toUpperCase();}
  querySelectorAll(selector){const selectors=selector.split(',').map(s=>s.trim()),all=[];const walk=node=>{for(const child of node.children){all.push(child);walk(child);}};walk(this);return all.filter(node=>selectors.some(s=>{if(s.startsWith(':scope > '))return node.parentElement===this&&node.matches(s.slice(9));const parts=s.split(/\s+/);if(!node.matches(parts.pop()))return false;let parent=node.parentElement;while(parts.length){const part=parts.pop();while(parent&&!parent.matches(part))parent=parent.parentElement;if(!parent)return false;parent=parent.parentElement;}return true;}));}
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
  closest(selector){let node=this;while(node){if(selector.split(',').some(s=>node.matches(s.trim())))return node;node=node.parentElement;}return null;}
  get elements(){return Object.fromEntries(this.querySelectorAll('input,textarea,select').map(node=>[node.getAttribute('name'),node]));}
  addEventListener(type,callback,capture=false){this.events.push({type,callback,capture});}
  fire(type){const event={type,target:this,currentTarget:null,preventDefault(){}},chain=[];let node=this;while(node){chain.push(node);node=node.parentElement;}const results=[];for(const capture of [true,false])for(const item of capture?[...chain].reverse():chain)for(const listener of item.events.filter(x=>x.type===type&&x.capture===capture)){event.currentTarget=item;results.push(listener.callback(event));}return Promise.all(results);}
  removeEventListener(type,callback){this.events=this.events.filter(x=>x.type!==type||x.callback!==callback);}replaceChildren(...nodes){for(const old of this.children)old.parentElement=null;this.children=[];this._html='';this._text=null;this.append(...nodes);}getBoundingClientRect(){return this.rect||{left:0,top:20,right:500,bottom:200,width:500,height:180};}click(){if(!this.disabled)return this.fire('click');}dispatchEvent(event){return this.fire(event.type);}
  focus(){this.ownerDocument.activeElement=this;}setSelectionRange(start,end){this.selectionStart=start;this.selectionEnd=end;}scrollTo({top}){this.scrollTop=top;}scrollIntoView(){}
}
export class Document {
  constructor(){this.body=new Element(this,'body');this.activeElement=this.body;this.visibilityState='visible';this.hidden=false;this.events=[];this.head=new Element(this,'head');this.documentElement={clientWidth:900,clientHeight:900};}
  createElement(tag){return new Element(this,tag);}querySelectorAll(selector){return this.body.querySelectorAll(selector);}querySelector(selector){return this.body.querySelector(selector);}getElementById(id){return this.querySelector('#'+id);}addEventListener(type,fn){this.events.push({type,fn});}removeEventListener(type,fn){this.events=this.events.filter(x=>x.type!==type||x.fn!==fn);}dispatchEvent(event){for(const x of this.events)if(x.type===event.type)x.fn(event);}
}
export const contract='vp3.profile-attention.v1';
export const copy=value=>structuredClone(value);
export const attentionItem=(overrides={})=>({attention_id:11,notification_id:21,profile_event_id:31,public_conversation_id:101,public_message_id:201,source_status:'verified',source_role:'visitor',source_label:'Visitor one',title:'Visitor asks for help',display_text:'A public question.',state_revision:1,wake_generation:1,disposition:'pending',read:false,presented:false,snoozed_until_ms:null,eligible:true,reason:'',can_read:true,can_snooze:true,can_handle:true,can_ignore:true,presentation_owner:'profile_attention_pa4b',delivery_id:0,chat_conversation_id:0,chat_agent_id:0,chat_message_id:0,delivery_location:'none',...overrides});
export const settle=async()=>{for(let i=0;i<12;i++)await new Promise(resolve=>setImmediate(resolve));};
const read=name=>fs.readFileSync(new URL('../../'+name,import.meta.url),'utf8');
class CustomEventFixture extends Event{constructor(type,options={}){super(type);this.detail=options.detail;}}
export async function attentionFixture({items=[attentionItem()],mode='chat',restore=[],privateDraft=''}={}){
  const document=new Document(),window=new EventTarget(),requests=[],holds=[],failures=new Map(),timers=new Map(),opened=[],changes=[],rawRows=new Map(items.map(item=>[item.attention_id,copy(item)])),replays=new Map(),messages=[];
  let clock=1800000000000,nextTimer=0,countsOverride=null,stateOverride=null,offscreen=false,covered=false;
  document.body.innerHTML='<section id="chatThread" data-view="chat"></section><form id="chatForm"><textarea id="chatInput"></textarea></form><div id="attentionList"></div>';
  const thread=document.getElementById('chatThread'),input=document.getElementById('chatInput'),list=document.getElementById('attentionList');input.value=privateDraft;
  document.elementFromPoint=()=>covered?document.body:[...thread.children].at(-1)||thread;
  const setTimeoutFixture=(fn,ms)=>{const id=++nextTimer;timers.set(id,{fn,ms});return id;};
  const project=(raw,body)=>{const item=copy(raw),due=item.disposition==='snoozed'&&item.snoozed_until_ms<=clock;if(item.delivery_id)item.delivery_location=item.chat_conversation_id===body.conversation_id&&item.chat_agent_id===body.agent_id?'current':'elsewhere';if(item.read&&!due){item.eligible=false;item.reason||='read';}else if(item.presented&&!due){item.eligible=false;item.reason||='presented';}return item;};
  const envelope=()=>({ok:true,complete:true,contract,server_now_ms:clock,voice_allowed:false});
  const transport=async(url,options={})=>{
    const inputBody=options.body?JSON.parse(options.body):Object.fromEntries(new URL(url).searchParams);
    for(const key of ['conversation_id','agent_id','after_id','delivery_id','page'])if(inputBody[key]!==undefined)inputBody[key]=Number(inputBody[key]);
    const body=copy(inputBody);requests.push(body);
    const failed=failures.get(body.action);
    let data=envelope(),status=200;
    if(failed){status=failed.status||503;data={...data,ok:false,complete:false,code:failed.code||'UNAVAILABLE',counts:null,items:[]};}
    else if(body.action==='profile_attention_state'){
      const rank=item=>item.disposition==='snoozed'&&item.snoozed_until_ms<=clock?0:['handled','ignored'].includes(item.disposition)?3:!item.read&&!item.presented?1:2;
      const all=[...rawRows.values()].map(item=>project(item,body)).sort((a,b)=>rank(a)-rank(b)||a.notification_id-b.notification_id),page=body.page||0,items=all.slice(page*25,(page+1)*25);
      Object.assign(data,{items,counts:countsOverride||{unread:all.filter(item=>!item.read).length,attention:all.filter(item=>['pending','snoozed'].includes(item.disposition)).length},latest_id:items.length?Math.max(...items.map(i=>i.notification_id)):0,page,page_size:25,has_more:all.length>(page+1)*25,errors:[]},stateOverride||{});
    }else if(body.action==='profile_attention_item'){
      const row=[...rawRows.values()].find(item=>item.delivery_id===body.delivery_id);
      if(!row||row.chat_conversation_id!==body.conversation_id||row.chat_agent_id!==body.agent_id){status=403;data={...data,ok:false,complete:false};}else data.item=project(row,body);
    }else{
      const row=rawRows.get(body.attention_id),prior=replays.get(body.request_id);
      if(!row){status=403;data={...data,ok:false,complete:false};}
      else if(prior){if(JSON.stringify(prior)!==JSON.stringify(body)){status=409;data={...data,ok:false,complete:false};}else Object.assign(data,{item:project(row,body),duplicate:true});}
      else if(row.state_revision!==body.state_revision||row.wake_generation!==body.wake_generation){status=409;data={...data,ok:false,complete:false};}
      else{
        if(body.action==='profile_attention_present'){
          if(row.disposition==='snoozed'&&row.snoozed_until_ms<=clock){row.disposition='pending';row.snoozed_until_ms=null;row.wake_generation++;row.state_revision++;row.read=false;row.presented=false;row.delivery_id=0;}
          if(!row.delivery_id){row.delivery_id=row.attention_id+400;row.chat_message_id=row.attention_id+600;row.chat_conversation_id=body.conversation_id;row.chat_agent_id=body.agent_id;messages.push(copy(row));}
          Object.assign(data,{message:'Profile attention update.',context_json:{profile_attention:{contract,delivery_id:row.delivery_id,attention_id:row.attention_id,notification_id:row.notification_id,wake_generation:row.wake_generation}}});
        }else if(body.action==='profile_attention_ack') {row.presented=true;row.state_revision++;}
        else if(body.action==='profile_attention_update'){
          if(body.operation==='read'){row.read=true;row.can_read=false;}
          else if(body.operation==='snooze'){row.disposition='snoozed';row.snoozed_until_ms=clock+86400000;row.eligible=false;}
          else if(['handled','ignored'].includes(body.operation)){row.disposition=body.operation;row.eligible=false;row.can_snooze=row.can_handle=row.can_ignore=false;}
          row.state_revision++;
        }else throw Error('Unknown fixture action '+body.action);
        replays.set(body.request_id,body);Object.assign(data,{item:project(row,body),duplicate:false});
      }
    }
    const response=(extra={},code=status)=>({ok:code>=200&&code<300,status:code,json:async()=>copy({...data,...extra})});
    const at=holds.findIndex(held=>held.action===body.action);if(at>=0){const held=holds.splice(at,1)[0];return new Promise((resolve,reject)=>{held.work={body,data,release:(extra={},code=status)=>resolve(response(extra,code)),reject};});}
    return response();
  };
  Object.assign(window,{location:{href:'https://fixture.test/sub/chat.php',origin:'https://fixture.test'},innerWidth:900,innerHeight:900,setTimeout:setTimeoutFixture,clearTimeout:id=>timers.delete(id),PROFILE_ATTENTION_PA4B_CONFIG:{endpoint:'/sub/api/chat-notifications-brain-v240.php',csrfToken:'CSRF'},STONEFELLOW_CHAT_CONTINUITY:{conversationId:()=>999,agentId:()=>99,pendingVoiceSubmit:()=>false,isBusy:()=>false},VP3ProfileCallsPa3c:{speechBusy:()=>false}});
  const sandbox={window,document,crypto:webcrypto,Uint8Array,AbortController,URL,Event,CustomEvent:CustomEventFixture,fetch:transport,requestAnimationFrame:fn=>queueMicrotask(fn),console,Date};
  const context=vm.createContext(sandbox),chat=read('chat.js');
  const helpers=chat.slice(chat.indexOf('  function bindStoredMessagePa3('),chat.indexOf('  function formatPlaybackTime('));
  const exports=chat.slice(chat.indexOf('  const canonicalChatContinuity = {'),chat.indexOf('  function speakIntroV101('));
  vm.runInContext('let conversationId=42,contextReady=true,restoringConversation=0,initialRestorePending=false,busy=false,voiceMode=false;const thread=document.getElementById("chatThread"),cfg={agentId:7};function addMessage(role,text){const n=document.createElement("article");n.className="message assistant";const p=document.createElement("p");p.className="message-text";p.textContent=text;n.append(p);thread.append(n);return n;}'+helpers+exports+';window.__binding=(cid,aid)=>{conversationId=cid;cfg.agentId=aid;};',context);
  // The primary voice object intentionally differs from canonical V87.
  window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>999,agentId:()=>99,pendingVoiceSubmit:()=>false,isBusy:()=>false};
  for(const raw of restore){rawRows.set(raw.attention_id,copy(raw));window.STONEFELLOW_CHAT_CONTINUITY_V87.mountProfileAttention({contract,item:project(raw,{conversation_id:42,agent_id:7})});}
  vm.runInContext(read('profile-attention-pa4b.js'),context,{filename:'profile-attention-pa4b.js'});
  const controller=window.VP3ProfileAttentionPa4b.create({mode,onChange:state=>{changes.push(copy(state));list.innerHTML=controller.view();},onOpen:id=>opened.push(id)});
  list.addEventListener('click',event=>controller.handleClick(event));
  const fixture={window,document,input,thread,list,context,controller,requests,holds,failures,rawRows,opened,changes,messages,replays,settle,
    hold(action){const held={action};holds.push(held);return held;},refresh:options=>controller.refresh(options),
    row:id=>list.querySelector('[data-profile-attention-row="'+id+'"]'),button:(id,operation)=>list.querySelector('[data-profile-attention-row="'+id+'"] [data-profile-attention-action="'+operation+'"]'),
    async click(id,operation){await this.button(id,operation)?.fire('click');await settle();},
    async page(operation){await list.querySelector('[data-profile-attention-action="'+operation+'"]')?.fire('click');await settle();},
    async type(value){input.value=value;await input.fire('input');},
    setCounts:value=>countsOverride=value,setState:value=>stateOverride=value,
    async contextChange(cid=55,aid=8){window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.__binding(cid,aid);thread.replaceChildren();await settle();},
    hide(value=true){document.hidden=value;document.visibilityState=value?'hidden':'visible';},
    cover(value=true){covered=value;},
    clock:()=>clock,advance:ms=>{clock+=ms;},dispose:()=>controller.dispose(),
    async restore(){await controller.restoreSaved();await settle();}
  };
  return fixture;
}
