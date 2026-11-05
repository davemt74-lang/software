import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const read=p=>fs.readFileSync(p,'utf8');
let checks=0;const check=(value,message)=>{assert.ok(value,message);checks++;};
const drawer=read('chat-notifications-drawer-v240.js');
const poll=drawer.slice(drawer.indexOf('  async function pollAttention('),drawer.indexOf('  function startAttentionPolling('));
let draft='',restoring=false,calls=[],presented=[],profileRefreshes=0,failPresentation=false,response={latest_id:30,items:[{id:20,source_type:'radar_event'},{id:30,source_type:'radar_event'}]};
const context=vm.createContext({window:{VP3_COGNITIVE_PRESENTATION_V510:{ownsAttention:true}},document:{hidden:false,getElementById:()=>({value:draft})},profileAttention:{refresh:async()=>profileRefreshes++},continuity:()=>({isRestoring:()=>restoring}),chatCanvasAvailable:()=>true,request:async(a,b,q)=>{calls.push(q);return response;},presentAttention:async(item,speak)=>{presented.push({item,speak});if(failPresentation)throw Error('canvas unavailable');}});
vm.runInContext('let attentionBusy=false,attentionCursor=0,profileAttentionCursor=0;'+poll+';globalThis.api={pollAttention,get cursor(){return profileAttentionCursor}};',context);
await context.api.pollAttention();check(calls.length===0,'Cognitive owner does not restart generic legacy attention');
draft='Unsent draft';await context.api.pollAttention(false,'profile');check(calls.length===0&&profileRefreshes===1,'Typed Profile controller owns its read/presentation fence while a draft defers the legacy Radar handoff');draft='';
restoring=true;await context.api.pollAttention(false,'profile');check(calls.length===0,'Initial conversation restore defers the priority handoff');restoring=false;
await context.api.pollAttention(false,'profile');check(calls[0].scope==='profile'&&calls[0].after_id===0,'Retained scoped handoff bootstraps existing Radar notifications after typed Profile refresh');
check(context.api.cursor===30&&presented.length===2,'Successful canvas presentation advances scoped cursor');
check(!presented[0].speak&&presented[1].speak,'Initial backlog voices only the final priority');
response={latest_id:50,items:[{id:50}]};failPresentation=true;
await context.api.pollAttention(false,'profile');check(context.api.cursor===30,'Canvas failure does not consume cursor');
failPresentation=false;await context.api.pollAttention(false,'profile');check(calls.at(-1).after_id===30&&context.api.cursor===50,'Retry resumes from last acknowledged canvas batch');
context.document.hidden=true;const count=calls.length;await context.api.pollAttention(false,'profile');check(calls.length===count,'Hidden pages do not poll');
// Exercise the existing writer-to-canvas acknowledgement sequence itself.
const present=drawer.slice(drawer.indexOf('  async function presentAttention('),drawer.indexOf('  async function pollAttention('));
let surfaced=false,reads=0,speech=0,duplicate=false;
let typed=0;const canvas=vm.createContext({window:{},document:{getElementById:()=>({value:''})},profileAttention:{refresh:async()=>typed++},request:async()=>({handled:true,duplicate,conversation_id:10,message_id:101,message:'Radar attention update'}),activeConversationId:()=>10,activeAgentId:()=>0,showAttentionConversation:async()=>surfaced,queueSpeech:()=>speech++,requestState:async()=>{reads++;return true;},render:()=>{},syncMainFeedBrainOutcomeControls:()=>{}});
vm.runInContext(present+';globalThis.presentAttention=presentAttention;',canvas);
await assert.rejects(canvas.presentAttention({id:5}));check(reads===0&&speech===0,'An unseen turn is neither marked read nor voiced');
surfaced=true;await canvas.presentAttention({id:5,source_type:'radar_event'});check(reads===1&&speech===1,'Confirmed non-Profile Radar turn retains existing acknowledgement and voice transport');
duplicate=true;await canvas.presentAttention({id:5});check(reads===2&&speech===1,'A duplicate durable turn is not announced twice');
await canvas.presentAttention({id:6,source_type:'profile_event'});check(typed===1&&reads===2&&speech===1,'Exact Profile sources delegate before legacy read or speech');
const mount=drawer.slice(drawer.indexOf('  async function showAttentionConversation('),drawer.indexOf('  function voiceButton('));
let cid=10,agent=7,opened=0,syncs=0,found='';const binding=vm.createContext({continuity:()=>({conversationId:()=>cid,syncConversation:async()=>{syncs++;},openConversation:async()=>opened++}),activeConversationId:()=>cid,activeAgentId:()=>agent,document:{getElementById:()=>({value:''}),querySelector:selector=>{found=selector;return {};}}});
vm.runInContext(mount+';globalThis.mount=showAttentionConversation;',binding);
check(await binding.mount(99,101)===false&&opened===0&&syncs===0,'Retained Radar duplicate elsewhere cannot auto-open its old private Chat');
check(await binding.mount(10,101)===true&&found.includes('data-chat-message-id="101"')&&found.includes('data-chat-conversation-id="10"'),'Legacy current Chat display binds saved message ID instead of matching public text');
// Run the actual admin polling/renderer with a tiny DOM adapter, including an
// installation under a URL subdirectory and attacker-controlled display text.
class Node{
 constructor(tag='div'){this.tag=tag;this.children=[];this.textContent='';this.className='';this.parent=null;}
 appendChild(n){n.parent=this;this.children.push(n);return n;}append(...nodes){nodes.forEach(n=>this.appendChild(n));}replaceChildren(){this.children=[];}
 remove(){this.parent.children=this.parent.children.filter(n=>n!==this);}
 querySelector(s){return this.children.find(n=>s.startsWith('.')?n.className===s.slice(1):n.tag===s)||null;}
}
const button=new Node('button'),dropdown=new Node(),head=new Node(),list=new Node();head.className='admin-notification-head';list.className='admin-notification-list';dropdown.append(head,list);
const listeners=new Map(),intervals=[],cleared=[];let fetches=0,resolveFetch;
const doc={hidden:false,currentScript:{src:'https://site.test/sub/admin/admin.js?v=build'},createElement:t=>new Node(t),addEventListener:(k,f)=>listeners.set(k,f),removeEventListener:k=>listeners.delete(k)};
const win={location:{href:'https://site.test/sub/admin/index.php'},setInterval:(f,ms)=>{intervals.push({f,ms});return intervals.length;},clearInterval:id=>cleared.push(id),addEventListener:(k,f)=>listeners.set(k,f)};
const admin=vm.createContext({URL,notificationButton:button,notificationDropdown:dropdown,document:doc,window:win,fetch:async(url,options)=>{fetches++;check(url.href==='https://site.test/sub/api/member-notifications.php','Admin uses the configured subdirectory, not site root');check(options.credentials==='same-origin'&&options.cache==='no-store','Admin polls persisted authenticated notifications');return new Promise(r=>resolveFetch=r);}});
const adminSource=read('admin/admin.js');
vm.runInContext(adminSource.slice(adminSource.indexOf('  const notificationBase'),adminSource.indexOf('  const safeStorageGet'))+';globalThis.api={renderAdminNotifications,refreshAdminNotifications};',admin);
check(intervals[0].ms===15000,'Admin auto-refresh is scheduled');
await admin.api.refreshAdminNotifications();check(fetches===1,'Overlapping admin refreshes are coalesced');
resolveFetch({ok:true,json:async()=>({ok:true,unread:2,items:[{title:'<img onerror=bad()>',body:'<script>bad()</script>',open_url:'/sub/notifications.php?open=4',is_read:false},{title:'Bad URL',body:'x',open_url:'https://outside.test/',is_read:false}]})});
await new Promise(setImmediate);await new Promise(setImmediate);
check(button.children[0].textContent==='2','Unread badge updates without a page reload');
check(list.children.length===1&&list.children[0].href==='https://site.test/sub/notifications.php?open=4','Notification renderer rejects offsite navigation');
check(list.children[0].children[1].children[0].textContent==='<img onerror=bad()>','Notification title is text, not executable HTML');
check(list.children[0].children[1].children[1].textContent==='<script>bad()</script>','Message preview is text, not executable HTML');
admin.api.renderAdminNotifications({ok:true,unread:0,items:[]});check(button.children.length===0&&list.children[0].textContent==='No notifications yet.','Empty state removes stale unread badge and rows');
doc.hidden=true;await intervals[0].f();check(fetches===1,'Hidden admin pages suspend polling');
listeners.get('pagehide')();check(cleared.includes(1)&&!listeners.has('visibilitychange'),'Admin cleans up its timer and visibility handler');
const cognitive=read('chat-cognitive-presentation-v510.js');
let handoffs=0;
const owner=vm.createContext({AbortController,document:{hidden:false},window:{STONEFELLOW_NOTIFICATION_CENTER:{pollProfileAttention:()=>handoffs++}}});
const bridge=cognitive.slice(cognitive.indexOf('  let state = null;'),cognitive.indexOf('  let busy = false;'));
vm.runInContext(bridge+';globalThis.api={refreshProfileAttention,loadState:()=>{state={};},invalidatePage:()=>pageController.abort()};',owner);
owner.api.refreshProfileAttention();
check(handoffs===0,'Profile handoff waits for the bound presentation state to load');
owner.api.loadState();
owner.api.refreshProfileAttention();
check(handoffs===1,'Canonical presentation owner performs the visible-page scoped handoff');
owner.document.hidden=true;
owner.api.refreshProfileAttention();
check(handoffs===1,'Hidden Chat pages do not refresh Profile handoffs');
owner.document.hidden=false;
owner.api.invalidatePage();
owner.api.refreshProfileAttention();
check(handoffs===1,'An invalidated Chat page stops refreshing Profile handoffs');
console.log(`PROFILE_NOTIFICATIONS_V1341_UI=${checks} assertions PASS`);
