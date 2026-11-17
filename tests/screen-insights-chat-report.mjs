import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';

function dataKey(input) {
  return input.replace(/-([a-z])/g,(_all,letter)=>letter.toUpperCase());
}
class Node {
  constructor(tag) { this.tag=tag; this.className=''; this.dataset={}; this.children=[]; this.listeners={}; this.attributes={}; this.text=''; }
  set textContent(value) { this.text=String(value); this.children=[]; }
  get textContent() { return this.text+this.children.map(child=>typeof child==='string'?child:child.textContent).join(''); }
  append(...children) {
    for (const child of children) { if (typeof child==='object') child.parent=this; this.children.push(child); }
  }
  replaceChildren(...children) {
    for (const child of this.children) if (typeof child==='object') child.parent=null;
    this.children=[]; this.append(...children);
  }
  remove() {
    if (!this.parent) return;
    this.parent.children=this.parent.children.filter(child=>child!==this); this.parent=null;
  }
  setAttribute(name,value) { this.attributes[name]=value; }
  matches(selector) {
    if (selector.startsWith('.')) return this.className.split(' ').includes(selector.slice(1));
    if (selector.startsWith('[data-')) {
      const [,field,value]=selector.match(/^\[data-([a-z-]+)(?:="([^"]+)")?\]$/);
      return value===undefined ? Object.hasOwn(this.dataset,dataKey(field)) : this.dataset[dataKey(field)]===value;
    }
    return this.tag===selector;
  }
  closest(selector) { return this.matches(selector) ? this : this.parent?.closest(selector) || null; }
  querySelectorAll(selector) {
    return this.children.flatMap(child => typeof child==='string' ? [] :
      [...(child.matches(selector) ? [child] : []),...child.querySelectorAll(selector)]);
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  contains(node) { return node===this || this.children.some(child=>typeof child!=='string' && child.contains(node)); }
  addEventListener(type,listener) { (this.listeners[type] ||= []).push(listener); }
  fire(type,event) { for (const listen of this.listeners[type] || []) listen(event); }
}

const js=await fs.readFile(new URL('../chat-screen-insights-v1.js',import.meta.url),'utf8');
const chatSource=await fs.readFile(new URL('../chat.js',import.meta.url),'utf8');
const css=await fs.readFile(new URL('../chat-screen-insights-v1.css',import.meta.url),'utf8');
assert.match(chatSource,/screen_insights_v1:raw\.source==='agent_screen_insights'/);
assert.equal((chatSource.match(/bindScreenInsightsV1\((?:renderedMessage|stored),context\.screen_insights_v1\)/g)||[]).length,3);
assert.doesNotMatch(js,/From your sharing sessions|setInterval|thread\.append/);
assert.match(css,/\.vp3-screen-insight-actions\s*\{[\s\S]*flex-wrap: wrap/);

const thread=new Node('article');
thread.dataset.view='chat';
const mode={value:'tasks'};
const document={getElementById:id=>id==='chatThread'?thread:id==='vp3ChatTaskMode'?mode:null,createElement:tag=>new Node(tag)};
const sameOrigin=new URL('https://vp3.test/chat.php');
const events={},requests=[],syncs=[],drafts=[];
const location={href:sameOrigin.href,origin:sameOrigin.origin};
let activeConversation=10,savedResult=null,nextReport=null,answer='Screen-share wrap-up.',holdNextRead=false,releaseRead=null,foreign=false;
const window={VP3_SCREEN_INSIGHTS:{endpoint:'/api/agent-screen-insights-v1.php',ownerKey:'7',agentId:5,csrf:'test-csrf'},
  STONEFELLOW_CHAT_CONTINUITY_V87:{agentId:()=>5,conversationId:()=>activeConversation,isReady:()=>true,isRestoring:()=>false,
    syncConversation:id=>{syncs.push(id);},stageSpecialistPrompt:(text,id)=>{drafts.push([text,id]);return true;}},
  addEventListener:(event,handler)=>{events[event]=handler;}};
const sessionId='1100aa00-0000-0000-0000-00a0011ab110';
const captureId='2200aa00-0000-0000-0000-00a0011ab110';
const audioId='4400aa00-0000-0000-0000-00a0011ab110';
const handoffId='3300aa00-0000-0000-0000-00a0011ab110';
const card={key:'a'.repeat(64),review_hash:'b'.repeat(64),title:'Test <Captured> summary',why:'The source supports this note.',
  kind:'wrapup',sources:[{title:'Private screen session',url:'/knowledge.php?folder=1',captured_at:'2026-11-17T12:30:00Z',
    session_id:sessionId,capture_id:captureId},{title:'External redirect',url:'https://outside.test/knowledge.php'}]};
const report={owner_user_id:7,agent_id:5,conversation_id:10,message_id:11,session_id:sessionId,cards:[card],handoffs:[]};
nextReport=report;
const fakeFetch=async (url,request)=>{
  const body=request.method==='GET' ? Object.fromEntries(url.searchParams) : JSON.parse(request.body);
  assert.equal(url.origin,sameOrigin.origin);
  assert.equal(body.owner_key,'7');
  assert.equal(Number(body.agent_id),5);
  requests.push({method:request.method,body});
  let payload={ok:true,owner_key:foreign?'8':'7',agent_id:5};
  if (request.method==='GET') {
    assert.equal(body.action,'chat_report'); assert.equal(body.conversation_id,'10'); assert.equal(body.message_id,'11');
    payload={...payload,answer,report:nextReport};
    if (holdNextRead) {
      holdNextRead=false;
      await new Promise(resolve=>{releaseRead=resolve;});
    }
  } else {
    assert.equal(body.conversation_id,10); assert.equal(body.report_message_id,11); assert.equal(body.csrf_token,'test-csrf');
    if (body.action==='handoff') {
      assert.equal(body.kind,'research'); assert.equal(body.handoff_id,handoffId);
      savedResult={handoff_id:handoffId,kind:'research',status:'prepared',source_current:true,target_id:'project-123',url:'/research-project.php?project=123'};
      nextReport={...report,handoffs:[savedResult]};
    }
    if (body.action==='decide') { assert.equal(body.review_hash,card.review_hash); nextReport={...nextReport,cards:[]}; }
    if (body.action==='sync_results') { assert.equal(body.handoff_id,handoffId); payload.url='/knowledge.php?folder=1'; }
  }
  return {ok:true,status:200,json:async()=>payload};
};
const record=new Node('article'); record.className='message assistant'; Object.assign(record.dataset,{chatMessageRole:'assistant',chatConversationId:'10',chatMessageId:'11'});
const body=new Node('div');body.className='message-body';
const label=new Node('div');label.className='message-role';label.textContent='Longfellow';
const text=new Node('div');text.className='message-text';text.textContent=answer;
body.append(label,text);record.append(body);thread.append(record);
const ctx=vm.createContext({window,document,location,URL,URLSearchParams,fetch:fakeFetch,crypto:{randomUUID:()=>handoffId},
  sessionStorage:{getItem:()=>null,setItem:()=>{}},Event});
vm.runInContext(js,ctx);
const mount=window.VP3_CHAT_SCREEN_INSIGHTS_V1.mount;
assert.equal(mount(record,{...report,conversation_id:12}),false);
assert.equal(body.children.length,2);
assert.equal(mount(record,{...report,agent_id:6}),false);
assert.equal(mount(record,{...report,owner_user_id:8}),false);
assert.equal(mount(record,report),true);
assert.equal(mount(record,report),true);
assert.equal(thread.children.length,1);
const host=body.querySelector('.vp3-screen-insight-report');
assert.equal(host.parent,body);
assert.equal(record.querySelectorAll('.vp3-screen-insight-report').length,1);
assert.equal(host.attributes['aria-label'],'Screen-sharing report');
assert.match(host.textContent,/Test <Captured> summary/);
assert.match(host.textContent,/Why this suggestion\?/);
assert.equal(host.querySelectorAll('a').length,1);
assert.equal(host.querySelector('a').href,'https://vp3.test/knowledge.php?folder=1');
const flush=()=>new Promise(setImmediate);
const press=async action=>{
  const target=host.querySelector('[data-insight-action="'+action+'"]');
  assert(target,'report contains action '+action);
  host.fire('click',{target});
  await flush();
};
await press('remind');
assert.equal(mode.value,'chat');
assert.deepEqual(drafts,[[ 'Remind me to follow up on Test <Captured> summary. Source: https://vp3.test/knowledge.php?folder=1',10]]);
const why=host.querySelector('details');
why.open=true;
await press('research');
assert.equal(host.querySelector('details').open,true);
assert.match(host.textContent,/Open Research project/);
await press('sync');
assert.match(host.textContent,/Latest results saved to the session Knowledge folder/);
await press('dismiss');
assert.equal(host.querySelector('h3'),null);
assert.match(host.textContent,/Restore dismissed suggestions/);
assert.match(host.textContent,/Suggestion dismissed/);
assert.equal(requests.some(request=>request.body.action==='feed'),false);

const audioReport={...report,audio:{knowledge_url:'/knowledge.php?folder=1&screen_session='+sessionId,segments:[{
  capture_id:audioId,captured_at:'2026-11-17T12:30:05Z',text:'Spoken <img src=x onerror=alert(1)> excerpt.',sounds:['music'],
}]}};
nextReport=audioReport;
assert.equal(mount(record,audioReport),true);
const audioBlock=host.querySelector('[data-screen-report-audio]');
assert(audioBlock);
assert.match(audioBlock.textContent,/Agent Ears transcript excerpts/);
assert.match(audioBlock.textContent,/Spoken <img src=x onerror=alert\(1\)> excerpt\./);
assert.match(audioBlock.textContent,/Detected sounds · music/);
assert.equal(audioBlock.querySelector('img'),null,'Transcript text never becomes markup');
assert.equal(audioBlock.querySelector('time').dateTime,'2026-11-17T12:30:05.000Z');
assert.equal(audioBlock.querySelector('a').href,'https://vp3.test/knowledge.php?folder=1&screen_session='+sessionId);
audioBlock.querySelector('details').open=true;
const changedAudio={...audioReport,audio:{...audioReport.audio,segments:[{...audioReport.audio.segments[0],text:'New current excerpt.'}]}};
assert.equal(mount(record,changedAudio),true);
assert.equal(host.querySelector('[data-screen-report-audio]').querySelector('details').open,true,'Refresh preserves expanded audio excerpts');
assert.equal(record.querySelectorAll('.vp3-screen-insight-report').length,1);

nextReport=report;
answer='Reviewed source removed.';
const beforeAudioReview=requests.length;
events['vp3:screen-observation-reviewed']({detail:{captureId:audioId}});
await flush();
assert.equal(requests.length,beforeAudioReview+1,'Audio review refreshes its report even when the audio is absent from card sources');
assert.equal(host.querySelector('[data-screen-report-audio]'),null);
assert.equal(text.textContent,answer);

const malformed={...report,cards:[null,'bad',card],handoffs:[null,'bad'],audio:{knowledge_url:'https://outside.test/knowledge.php',segments:[
  null,{capture_id:audioId,captured_at:'invalid date',text:'bad time',sounds:[]},
  {capture_id:audioId,captured_at:'2026-11-17T12:30:05Z',text:'x'.repeat(1400),sounds:[null,{},' music ',' ',...Array(12).fill('s'.repeat(90))]},
]}};
assert.equal(mount(record,malformed),true);
const safeAudio=host.querySelector('[data-screen-report-audio]');
assert.equal(safeAudio.querySelectorAll('li').length,1,'Malformed audio segments are ignored');
assert.equal(safeAudio.querySelector('p').textContent.length,1000,'Long transcripts remain bounded');
assert.equal(safeAudio.querySelector('a'),null,'External Knowledge URLs are not rendered');
assert.doesNotMatch(safeAudio.textContent,/bad time/);
assert.equal(mount(record,{...report,session_id:'invalid'}),false,'Unbound reports cannot retain private audio');
assert.equal(body.querySelector('.vp3-screen-insight-report'),null);
assert.equal(mount(record,audioReport),true);

holdNextRead=true;
nextReport=audioReport;answer='Old answer containing private audio.';
events['vp3:screen-observation-reviewed']({detail:{captureId:audioId}});
await flush();
assert.equal(typeof releaseRead,'function');
nextReport=report;answer='Current review removed the old audio.';
events['vp3:screen-observation-reviewed']({detail:{captureId:audioId}});
await flush();
assert.equal(text.textContent,answer);
releaseRead();await flush();
assert.equal(text.textContent,answer,'An older response cannot restore withdrawn excerpts');
assert.equal(body.querySelector('[data-screen-report-audio]'),null);

assert.equal(mount(record,audioReport),true);
holdNextRead=true;nextReport=audioReport;answer='Late response for previous Chat.';
events['vp3:screen-observation-reviewed']({detail:{captureId:audioId}});
await flush();
const unchanged=text.textContent;
activeConversation=15;releaseRead();await flush();
assert.equal(text.textContent,unchanged,'A pending projection cannot update a different active conversation');
activeConversation=10;

const soundsOnly={...audioReport,audio:{...audioReport.audio,segments:[{...audioReport.audio.segments[0],text:''}]}};
assert.equal(mount(record,soundsOnly),true);
assert.match(body.querySelector('[data-screen-report-audio]').textContent,/Agent Ears detected sounds/);
foreign=true;
events['vp3:screen-observation-reviewed']({detail:{captureId:audioId}});
await flush();
assert.equal(body.querySelector('.vp3-screen-insight-report'),null,'Account mismatch clears previously rendered private excerpts');
assert.match(text.textContent,/unavailable/);
foreign=false;
assert.equal(mount(record,report),true);
events['vp3:screen-report-posted']({detail:{conversation_id:15,message_id:12}});
events['vp3:screen-report-posted']({detail:{conversation_id:10,message_id:'12'}});
assert.deepEqual(syncs,[]);
events['vp3:screen-report-posted']({detail:{conversation_id:10,message_id:12}});
assert.deepEqual(syncs,[10]);
assert.equal(thread.children.length,1,'only canonical Chat can create a message');
activeConversation=15;
const beforeWrongChat=text.textContent;
events['vp3:screen-observation-reviewed']({detail:{sessionId}});
await flush();
assert.equal(text.textContent,beforeWrongChat);
activeConversation=10;
nextReport=null;
answer='There are no current observations left in this screen-share wrap-up.';
events['vp3:screen-observation-reviewed']({detail:{sessionId}});
await flush();
assert.equal(text.textContent,answer);
assert.equal(body.querySelector('.vp3-screen-insight-report'),null);
assert.equal(record.parent,thread,'the normal assistant row survives source removal');
console.log('SCREEN_INSIGHTS_CHAT_REPORT=PASS');
