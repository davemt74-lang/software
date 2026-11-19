import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
import {WorkspaceElement,workspaceDocument} from './fixtures/profile-workspace-dom-v1.mjs';

// Run the complete owner Canvas module. Only its DOM, HTTP and preview runtime
// are controlled; clicks exercise the production save and stale-response paths.
class Element extends WorkspaceElement {
  prepend(...children){const previous=[...this.children];this.replaceChildren(...children,...previous);}
  removeAttribute(key){this.attributes.delete(key);if(key==='href')delete this.href;}
  scrollTo({top}){this.scrollTop=top;}
}
const source=fs.readFileSync(new URL('../chat-coding-canvas-v1.js',import.meta.url),'utf8');
const token=n=>String(n).padStart(64,'0');
const flush=async()=>{for(let i=0;i<8;i++)await new Promise(done=>setImmediate(done));};
let checks=0;
function check(ok,message){assert.ok(ok,message);checks++;console.log('PASS '+message);}
async function fixture(){
  const document=workspaceDocument(),window=new EventTarget(),timers=new Map(),requests=[];
  document.createElement=tag=>new Element(tag,document);
  document.createTextNode=text=>{const el=document.createElement('#text');el.textContent=text;return el;};
  document.addEventListener=()=>{};document.hidden=false;
  const make=(tag,id)=>{const el=document.createElement(tag);el.id=id;document.body.append(el);return el;};
  const thread=make('section','chatThread');thread.dataset.view='messages';
  make('div','chatComposerShell');make('form','chatForm');make('textarea','chatInput');
  let conversation=42,sequence=0;
  const id='00000000-0000-4000-8000-000000000001',other='00000000-0000-4000-8000-000000000002';
  const canvases=new Map([id,other].map((key,i)=>[key,{id:key,title:i?'Second Canvas':'Public Canvas',agent_id:7,conversation_id:42,folder_id:i+1,active_revision:1,draft_revision:null}]));
  const state={listed:false,generation:1,publication:1,published:true,canList:true,reason:null,error:null,override:null};
  const holds=[];
  const publication=()=>({state:state.published?'published':'unpublished',slug:'sample-canvas',url:state.published?'/vp3/apps/sample-canvas':null,revision:state.published?1:null,version:token(state.publication)});
  const listing=()=>({profile_canvas:{listed:state.listed,version:token(state.generation),url:state.listed&&state.published&&state.canList?'/vp3/test-owner/canvas/sample-canvas':null},eligibility:{can_list:state.canList&&state.published,reason:state.reason||(!state.published?'Publish a version first.':null)}});
  const respond=(data,status=200)=>({ok:status<400,json:async()=>structuredClone(data)});
  const fetch=async(raw,options)=>{
    const endpoint=new URL(raw,'https://canvas.test'),body=JSON.parse(options.body);
    requests.push({path:endpoint.pathname,body});
    assert.equal(body.csrf_token,'csrf-fixture');assert.equal(body.agent_id,7);assert.equal(options.credentials,'same-origin');
    const profile=endpoint.pathname==='/vp3/api/profile-canvases-v1.php';
    let data;
    if(profile){
      assert.ok(canvases.has(body.canvas_id));
      if(state.error)return respond({ok:false,error:state.error},503);
      if(body.action==='set'){
        if(body.expected_revision!==token(state.generation)||body.expected_publication!==token(state.publication))return respond({ok:false,error:'The publication changed. Reload before applying this change.'},409);
        state.listed=body.listed;state.generation++;
      }else assert.equal(body.action,'status');
      data={ok:true,...listing(),...(state.override||{})};
    }else{
      assert.equal(endpoint.pathname,'/vp3/api/coding-canvas-v1.php');
      if(body.action==='resume')data={ok:true,job:null};
      else if(body.action==='list')data={ok:true,canvases:[...canvases.values()],command_catalog:[]};
      else if(body.action==='open'){
        const revision={id:1,status:'active',test_passed:true,artifact:{html:'<p>Public page</p>',css:'',js:''},commands:[]};
        data={ok:true,canvas:canvases.get(body.canvas_id),revision,revisions:[revision],state:{revision:token(1),value:{}}};
      }else if(body.action==='publication_status')data={ok:true,publication:publication(),eligibility:{can_publish:true,reason:null}};
      else if(body.action==='unpublish'){state.published=false;state.publication++;data={ok:true,publication:publication(),eligibility:{can_publish:true,reason:null}};}
      else throw Error('Unexpected Canvas action '+body.action);
    }
    const held=holds.shift();
    if(held&&profile){held.arrived({body,data,release:()=>held.resolve(respond(data))});return await new Promise(resolve=>{held.resolve=resolve;});}
    if(held)holds.unshift(held);
    return respond(data);
  };
  const location=new URL('https://canvas.test/vp3/chat.php');
  Object.assign(window,{STONEFELLOW_CHAT:{userId:1,agentId:7,workingAttentionBinding:'owner-chat'},
    VP3_CODING_CANVAS_V1:{endpoint:'/vp3/api/coding-canvas-v1.php',csrf:'csrf-fixture',agentId:7},
    STONEFELLOW_CHAT_CONTINUITY:{conversationId:()=>conversation,agentId:()=>7,isReady:()=>true,isRestoring:()=>false,
      showChatView:()=>{thread.dataset.view='messages';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));}},
    VP3CanvasRuntimeV1:{channel:'fixture',stateLimit:65536,maxText:8000,token:()=>token(++sequence),createDocument:()=>'',sourceKeys:{},publicSourceKeys:[],normalizeSource:()=>{},unavailableSource:()=>{}},location});
  const schedule=(callback,delay)=>{const key=++sequence;timers.set(key,{callback,delay});return key;};
  vm.runInNewContext(source,{window,document,fetch,location,URL,URLSearchParams,Event,AbortController,crypto:webcrypto,
    setTimeout:schedule,clearTimeout:key=>timers.delete(key),setInterval:schedule,clearInterval:key=>timers.delete(key),
    localStorage:{setItem(){},getItem(){return null;}}});
  await flush();
  const panel=document.getElementById('chatCodingCanvasV1');
  const button=text=>panel.querySelectorAll('button').find(el=>el.textContent===text);
  const profile=panel.querySelector('.vp3-coding-canvas-profile');
  const checkbox=profile.querySelector('input');
  const status=profile.querySelector('.vp3-coding-canvas-status');
  const save=button('Save Profile visibility');
  const publishing=panel.querySelector('.vp3-coding-canvas-publishing');
  async function open(canvas=id){await window.VP3CodingCanvasV1.open(canvas);button('Edit app').click();await flush();publishing.open=true;publishing.dispatchEvent({type:'toggle'});await flush();}
  await open();
  return {state,requests,window,panel,profile,checkbox,status,save,button,open,id,other,
    change:async value=>{checkbox.checked=value;checkbox.dispatchEvent({type:'change'});await flush();},
    reload:async()=>{window.dispatchEvent(new Event('focus'));await flush();},
    hold:()=>new Promise(arrived=>holds.push({arrived})),
    chatChanged:()=>{conversation++;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));},
    click:async text=>{button(text).click();await flush();}};
}

{
  const f=await fixture();
  check(!f.checkbox.checked&&!f.checkbox.disabled&&f.save.disabled,'a published Canvas starts unlisted and needs an explicit changed setting');
  check(f.profile.textContent.includes('full page with your Profile Agent chat'),'owner sees the full-page and shared Profile Agent explanation');
  await f.change(true);
  check(!f.save.disabled&&!f.requests.some(r=>r.body.action==='set'),'checking Show on my Profile alone does not change publication');
  await f.click('Save Profile visibility');
  const saved=f.requests.find(r=>r.body.action==='set');
  check(saved.path==='/vp3/api/profile-canvases-v1.php'&&saved.body.canvas_id===f.id&&saved.body.conversation_id===42&&saved.body.expected_revision===token(1)&&saved.body.expected_publication===token(1),'save binds the exact Canvas, Chat, listing and publication versions under an installation subpath');
  check(f.state.listed&&f.checkbox.checked&&f.save.disabled&&f.profile.querySelector('a').href==='https://canvas.test/vp3/test-owner/canvas/sample-canvas','successful save exposes the verified full-page Profile Canvas link');
  const before=f.requests.filter(r=>r.body.action==='status').length;
  await f.click('Unpublish…');await f.click('Confirm unpublish');
  check(f.requests.filter(r=>r.body.action==='status').length>before&&f.profile.querySelector('a').hidden,'unpublishing refreshes Profile visibility and removes the unavailable public link');
  check(f.checkbox.checked&&!f.checkbox.disabled,'an unpublished Canvas can still be removed from the Profile selection');
  await f.change(false);await f.click('Save Profile visibility');
  check(!f.state.listed&&!f.checkbox.checked&&f.checkbox.disabled,'removing an unpublished Canvas saves explicitly and leaves adding disabled');
}
{
  const f=await fixture();await f.change(true);f.state.publication++;
  await f.click('Save Profile visibility');
  check(!f.state.listed&&!f.checkbox.checked&&f.status.textContent.includes('publication changed'),'a stale publication cannot silently add a different public version and explains the conflict');
  await f.change(true);await f.click('Save Profile visibility');
  check(f.state.listed&&f.requests.filter(r=>r.body.action==='set').at(-1).body.expected_publication===token(2),'retry after refresh uses the current exact publication');
}
{
  const f=await fixture();f.state.error='Run Database Upgrade to enable Profile Canvases.';await f.reload();
  check(f.checkbox.disabled&&f.save.disabled&&f.status.textContent.includes('Database Upgrade'),'unavailable storage explains the issue and disables unchecked settings');
  f.state.error=null;await f.click('Reload Profile visibility');
  check(!f.checkbox.disabled&&f.status.textContent==='','visibility status can recover without leaving the current Canvas');
  f.state.override={profile_canvas:{listed:true,version:token(2),url:'https://external.test/steal'}};await f.reload();
  check(f.profile.querySelector('a').hidden&&f.save.disabled&&f.status.textContent.includes('could not be verified'),'untrusted returned destinations never become Profile links');
}
{
  const f=await fixture();f.state.listed=true;const pending=f.hold();f.window.dispatchEvent(new Event('focus'));const held=await pending;f.state.listed=false;
  await f.open(f.other);held.release();await flush();
  check(f.panel.querySelector('.vp3-coding-canvas-workspace-title').textContent.includes('Second Canvas')&&!f.checkbox.checked,'late settings from an earlier Canvas cannot replace the new selection');
  f.window.VP3ParticipantContinuity={selectedContext:()=>({id:9})};
  await f.change(true);const before=f.requests.length;await f.click('Save Profile visibility');
  check(f.requests.length===before,'participant context cannot send a Profile visibility mutation');
}
{
  const f=await fixture();await f.change(true);const pending=f.hold();f.save.click();const held=await pending;
  f.chatChanged();f.state.listed=false;await f.open(f.other);held.release();await flush();
  check(!f.checkbox.checked&&!f.status.textContent.includes('added')&&f.requests.filter(r=>r.body.action==='set').length===1,
    'a save response from a previous Chat cannot update or resend in the new Chat');
}
console.log(`PASS ${checks} Profile Canvas owner controls checks`);
