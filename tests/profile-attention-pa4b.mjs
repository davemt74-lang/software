import assert from 'node:assert/strict';
import {attentionFixture,attentionItem,contract,settle} from './fixtures/profile-attention-pa4b.mjs';
let checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const issued=(f,action)=>f.requests.filter(input=>input.action===action);
const unavailable=item=>Object.assign(item,{source_status:'unavailable',source_label:'Source unavailable',display_text:'',eligible:false,reason:'source_authority_revoked',can_read:false,can_snooze:false,can_handle:false,can_ignore:false});

const plain=await attentionFixture();
check(plain.requests.length===0,'shared module declarations/controller do not start polling or navigation');
const valid=plain.window.VP3ProfileAttentionPa4b.normalizeItem;
check(!!valid(attentionItem({display_text:'🙂'.repeat(4000)})),'Unicode source bounds use characters rather than UTF-16 units');
for(const patch of [{attention_id:0},{profile_event_id:0},{state_revision:'1'},{read:1},{can_read:'true'},{source_role:'assistant'},{disposition:'seen'},{display_text:'x'.repeat(4001)},{title:'x'.repeat(241)},{source_label:'x'.repeat(101)},{wake_generation:-1},{delivery_location:'current'},{presentation_owner:'model_choice'}])check(valid(attentionItem(patch))===null,'malformed item is rejected: '+Object.keys(patch)[0]);
await plain.refresh();await settle();
check(plain.requests.every(input=>input.conversation_id===42&&input.agent_id===7),'all owner requests use canonical V87 CID/Agent despite primary voice overwrite');
check(issued(plain,'profile_attention_present').length===1&&plain.thread.children.length===1,'actual Chat marker helper mounts one exact saved card');
check(plain.thread.children[0].querySelector('.message-text').textContent==='Profile attention update.','private Chat marker remains neutral; public content is a separately attributed card');
check(plain.thread.textContent.includes('Visitor · Visitor one')&&plain.thread.textContent.includes('A public question.'),'current source is rendered with typed public actor attribution');
check(issued(plain,'profile_attention_ack').length===1&&issued(plain,'profile_attention_update').length===0,'visible ACK records presentation once without reading or disposition');
check(plain.controller.getState().items[0].presented&&!plain.controller.getState().items[0].read,'presented and unread remain independent');
check(issued(plain,'profile_attention_ack')[0].visible===true&&/^[a-f0-9]{32}$/.test(issued(plain,'profile_attention_ack')[0].tab_id),'ACK carries exact visible card/tab proof');
await plain.click(11,'read');
check(plain.controller.getState().items[0].read&&plain.controller.getState().items[0].disposition==='pending','explicit Read leaves attention disposition pending');
check(plain.controller.getState().counts.unread===0,'lifecycle mutation refreshes authoritative counts');
check(!plain.list.textContent.includes('caught up'),'zero unread alone cannot claim all attention is resolved');
await plain.click(11,'handled');
check(plain.controller.getState().items[0].disposition==='handled'&&!plain.button(11,'snooze'),'handled state cannot offer a reopening/snooze control');
check(plain.list.textContent.includes('caught up'),'caught-up state requires a complete zero unread and zero attention response');

const terminal=await attentionFixture({mode:'portal',items:[attentionItem({disposition:'ignored',eligible:false,can_snooze:false,can_handle:false,can_ignore:false})]});await terminal.refresh();
check(terminal.button(11,'read')&&!terminal.button(11,'snooze'),'terminal unread item still offers its independent Read action');await terminal.click(11,'read');
check(terminal.controller.getState().items[0].disposition==='ignored'&&terminal.controller.getState().items[0].read,'reading an ignored item never revives its disposition');
check(terminal.requests.every(input=>input.conversation_id===0&&input.agent_id===0)&&!issued(terminal,'profile_attention_present').length,'portal lists and updates owned attention without inventing a private Chat');

const draft=await attentionFixture({privateDraft:'Private unsent draft'});draft.input.focus();await draft.refresh();
check(issued(draft,'profile_attention_state').length===1&&!issued(draft,'profile_attention_present').length,'private draft defers presentation while allowing read-only list refresh');
check(draft.input.value==='Private unsent draft'&&draft.document.activeElement===draft.input,'notification refresh preserves private text and focus');
const delayedDraft=await attentionFixture();const waitPresent=delayedDraft.hold('profile_attention_present'),draftWork=delayedDraft.refresh();await settle();await delayedDraft.type('Typed while the notification was pending');waitPresent.work.release();await draftWork;await settle();
check(delayedDraft.thread.children.length===0&&!issued(delayedDraft,'profile_attention_ack').length,'typing during presentation prevents late mount and visible ACK');
check(delayedDraft.input.value==='Typed while the notification was pending','delayed presentation cannot consume or copy a private draft');
await delayedDraft.type('');await delayedDraft.refresh();await settle();
check(delayedDraft.thread.children.length===1,'saved exact delivery can be displayed later in the same Chat');

for(const action of ['profile_attention_state','profile_attention_present']){
  const f=await attentionFixture(),hold=f.hold(action),old=f.refresh();await settle();await f.contextChange(55,9);await f.type('New Chat draft');hold.work.release();await old;await settle();
  check(f.thread.children.length===0&&f.input.value==='New Chat draft'&&!issued(f,'profile_attention_ack').length,'late '+action+' cannot mount/ACK into another CID or Agent');
}
const elsewhere=await attentionFixture({items:[attentionItem({delivery_id:411,chat_message_id:611,chat_conversation_id:99,chat_agent_id:7,delivery_location:'elsewhere'})]});await elsewhere.refresh();
check(!issued(elsewhere,'profile_attention_present').length&&elsewhere.thread.children.length===0,'duplicate delivery elsewhere neither recreates nor opens the old private Chat');
check(elsewhere.list.textContent.includes('another Chat')&&elsewhere.opened.length===0,'elsewhere presentation is explained without silent navigation');
await elsewhere.click(11,'open');check(elsewhere.opened[0]===101,'explicit Open targets the exact public conversation, not private delivery CID');

const partial=await attentionFixture({mode:'portal'});await partial.refresh();partial.setState({complete:false,items:[],counts:null,errors:['source_unavailable']});await partial.refresh();
check(partial.controller.getState().items.length===1&&partial.controller.getState().counts.unread===1,'partial read preserves last verified list and counts');
check(partial.list.textContent.includes('incomplete')&&partial.list.textContent.includes('Retry')&&!partial.list.textContent.includes('caught up'),'partial state has a visible retry and no false empty success');
check(partial.button(11,'read').disabled,'unverified stale list cannot issue a lifecycle mutation');
partial.setState(null);partial.failures.set('profile_attention_state',{status:503});await partial.refresh();
check(partial.controller.getState().items.length===1&&partial.list.textContent.includes('last confirmed'),'failed refresh preserves known rows/counts with truthful status');
partial.failures.delete('profile_attention_state');await partial.refresh();check(!partial.controller.getState().error&&!partial.button(11,'read').disabled,'successful explicit refresh restores current controls');
partial.failures.set('profile_attention_state',{status:403});await partial.refresh();check(partial.controller.getState().items.length===0&&partial.controller.getState().counts===null,'current authorization failure hides private source detail instead of retaining it');
const never=await attentionFixture({mode:'portal'});never.failures.set('profile_attention_state',{status:503});await never.refresh();check(never.controller.getState().counts===null&&!never.list.textContent.includes('caught up'),'first failed read cannot become zero-count success');

const hidden=await attentionFixture();hidden.hide();await hidden.refresh();check(hidden.requests.length===0,'hidden main Chat does not initiate reads/delivery');
const covered=await attentionFixture();covered.cover();await covered.refresh();check(covered.thread.children.length===1&&!issued(covered,'profile_attention_ack').length,'card covered by another surface is not visibly acknowledged');
covered.cover(false);await covered.refresh();await settle();check(issued(covered,'profile_attention_ack').length===1,'visible restored card can receive its single ACK later');

const newer=await attentionFixture({mode:'portal'}),heldRead=newer.hold('profile_attention_state'),oldRead=newer.refresh();await settle();Object.assign(newer.rawRows.get(11),{state_revision:2,disposition:'handled',eligible:false,can_snooze:false,can_handle:false,can_ignore:false});await newer.refresh();heldRead.work.release();await oldRead;
check(newer.controller.getState().items[0].disposition==='handled'&&newer.controller.getState().items[0].state_revision===2,'reverse read completion cannot restore older lifecycle state');
const staleButton=newer.button(11,'read');Object.assign(newer.rawRows.get(11),{state_revision:3,read:true,can_read:false});await newer.refresh();const writesBefore=issued(newer,'profile_attention_update').length;await staleButton.fire('click');await settle();check(issued(newer,'profile_attention_update').length===writesBefore,'a detached stale action cannot mutate a newer item revision');

for(const change of ['revocation','disclosure']){
  const f=await attentionFixture(),hold=f.hold('profile_attention_present'),old=f.refresh();await settle();const row=f.rawRows.get(11);
  if(change==='revocation')unavailable(row);else Object.assign(row,{source_label:'A visitor',display_text:'Updated public wording'});
  await f.refresh({present:false});hold.work.release();await old;await settle();
  check(!f.thread.textContent.includes('Visitor one')&&!f.thread.textContent.includes('A public question.'),'fresh same-revision '+change+' dominates older presentation response');
  check(f.controller.getState().items[0].source_label===row.source_label,'older presentation cannot overwrite current source projection after '+change);
}
const stored=attentionItem({delivery_id:411,chat_message_id:611,chat_conversation_id:42,chat_agent_id:7,delivery_location:'current'});
const saved=await attentionFixture({restore:[stored]});saved.setState({items:[],counts:{unread:1,attention:1}});const heldItem=saved.hold('profile_attention_item'),restoring=saved.refresh({present:false});await settle();unavailable(saved.rawRows.get(11));saved.setState(null);await saved.refresh({present:false});heldItem.work.release();await restoring;
check(!saved.thread.textContent.includes('Visitor one')&&saved.thread.textContent.includes('Source unavailable'),'fresh revoke dominates an older saved-marker lookup with the same lifecycle revision');
const ack=await attentionFixture(),heldAck=ack.hold('profile_attention_ack'),acking=ack.refresh();await settle();unavailable(ack.rawRows.get(11));await ack.refresh({present:false});heldAck.work.release();await acking;await settle();
check(!ack.thread.textContent.includes('A public question.')&&ack.controller.getState().items[0].source_status==='unavailable','late visible ACK cannot restore a now-revoked source body');

const retry=await attentionFixture({mode:'portal'});await retry.refresh();retry.failures.set('profile_attention_update',{status:503});await retry.click(11,'handled');const first=issued(retry,'profile_attention_update')[0];retry.failures.delete('profile_attention_update');await retry.click(11,'handled');const second=issued(retry,'profile_attention_update')[1];
check(first.request_id===second.request_id&&JSON.stringify(first)===JSON.stringify(second),'uncertain lifecycle write retries exact original request/body without duplicating intent');
const conflict=await attentionFixture({mode:'portal'});await conflict.refresh();Object.assign(conflict.rawRows.get(11),{state_revision:2,disposition:'ignored',can_handle:false,can_ignore:false,can_snooze:false,eligible:false});await conflict.click(11,'handled');
check(issued(conflict,'profile_attention_update').length===1&&conflict.controller.getState().items[0].disposition==='ignored','409 refreshes current state without automatically replaying a stale action');
for(const action of ['profile_attention_present','profile_attention_ack']){
  const f=await attentionFixture();f.failures.set(action,{status:409});await f.refresh();await settle();const first=issued(f,action)[0];f.failures.delete(action);f.rawRows.get(11).state_revision++;await f.refresh();await settle();const retry=issued(f,action).at(-1);
  check(first.request_id!==retry.request_id&&retry.state_revision>first.state_revision&&f.controller.getState().items[0].presented,'known409 '+action+' discards stale expected revision and allows a current exact retry');f.dispose();
}
for(const action of ['profile_attention_present','profile_attention_ack'])for(const status of [401,403,419]){
  const f=await attentionFixture();f.failures.set(action,{status});await f.refresh();await settle();
  check(f.controller.getState().items.length===0&&!f.thread.textContent.includes('A public question.'),'current '+action+' '+status+' immediately hides source detail and controls');f.dispose();
}

const snooze=await attentionFixture({items:[attentionItem({disposition:'snoozed',snoozed_until_ms:1799999999000,eligible:true,read:true})]});await snooze.refresh();await settle();
check(snooze.controller.getState().items[0].wake_generation===2&&!snooze.controller.getState().items[0].read,'due snooze uses returned new wake generation and shows unread attention');
check(issued(snooze,'profile_attention_ack')[0].wake_generation===2,'visible ACK binds the awakened generation rather than the stale request generation');
const escaped=await attentionFixture({mode:'portal',items:[attentionItem({title:'<img src=x onerror=bad()>',display_text:'<script>bad()</script>'}),attentionItem({attention_id:12,notification_id:22,profile_event_id:32,public_conversation_id:202,public_message_id:202,title:'<img src=x onerror=bad()>'})]});await escaped.refresh();
check(!escaped.list.querySelector('script')&&!escaped.list.querySelector('img'),'public labels/body are escaped text, never executable markup');await escaped.click(12,'open');
check(escaped.opened[0]===202,'identical public titles cannot confuse the exact reply/conversation target');
check(plain.requests.every(body=>!Object.hasOwn(body,'display_text')&&!Object.hasOwn(body,'title')&&!Object.hasOwn(body,'message')),'read/presentation/disposition requests never relay raw public or private text');
check(!plain.requests.some(body=>/voice|speech|owner_reply/.test(body.action)),'generic attention replay performs no speech or owner message send');

const backlog=Array.from({length:65},(_,index)=>attentionItem({attention_id:index+1,notification_id:index+101,profile_event_id:index+201,public_conversation_id:index+301,public_message_id:index+401,
  ...(index>=55?{disposition:'handled',read:true,eligible:false,can_read:false,can_snooze:false,can_handle:false,can_ignore:false}:{})}));
const pages=await attentionFixture({items:backlog,privateDraft:'Keep the private paging draft'});await pages.refresh({present:false});const seen=new Set(pages.controller.getState().items.map(item=>item.attention_id));
check(pages.controller.getState().items.length===25&&pages.controller.getState().has_more&&pages.controller.getState().counts.unread===55,'first bounded page exposes all-owner counts and a next page');
await pages.page('next');for(const item of pages.controller.getState().items)seen.add(item.attention_id);
check(pages.controller.getState().page===1&&pages.controller.getState().items.length===25,'Next reaches the second actual server page');
await pages.page('next');for(const item of pages.controller.getState().items)seen.add(item.attention_id);
check(pages.controller.getState().page===2&&pages.controller.getState().items.length===15&&!pages.controller.getState().has_more&&seen.size===65,'all65 owned items including handled/read history are reachable');
check(!pages.requests.some(input=>['profile_attention_present','profile_attention_ack','profile_attention_update'].includes(input.action))&&pages.input.value==='Keep the private paging draft','list navigation performs no writes and preserves the private draft');
await pages.page('previous');check(pages.controller.getState().page===1,'Previous reloads the preceding current page');
const pageAction=pages.controller.getState().items[0];await pages.click(pageAction.attention_id,'read');
check(issued(pages,'profile_attention_update').at(-1).attention_id===pageAction.attention_id&&pages.controller.getState().page===1,'paged actions use exact visible source IDs and refresh the same page after reordering');

const pageSaved=attentionItem({...backlog[0],delivery_id:501,chat_message_id:701,chat_conversation_id:42,chat_agent_id:7,delivery_location:'current'});
const readOnlyPages=await attentionFixture({items:backlog,restore:[pageSaved]});await readOnlyPages.refresh({present:false});await readOnlyPages.page('next');await readOnlyPages.page('previous');
check(readOnlyPages.thread.textContent.includes('A public question.')&&!readOnlyPages.requests.some(input=>['profile_attention_present','profile_attention_ack','profile_attention_update'].includes(input.action)),'Previous/Next restoration of an actually visible unpresented saved card never ACKs or presents');

const pageRace=await attentionFixture({mode:'portal',items:backlog});await pageRace.refresh();const oldPage=pageRace.hold('profile_attention_state'),oldPageWork=pageRace.controller.refresh({page:1});await settle();await pageRace.controller.refresh({page:2});oldPage.work.release();await oldPageWork;
check(pageRace.controller.getState().page===2&&pageRace.controller.getState().items[0].attention_id===51,'reverse page responses cannot replace the newer selected page');
const priorPage=pageRace.controller.getState();pageRace.setState({page:1});await pageRace.controller.refresh({page:2});
check(pageRace.controller.getState().page===2&&pageRace.controller.getState().items[0].attention_id===priorPage.items[0].attention_id&&pageRace.controller.getState().counts.unread===priorPage.counts.unread&&pageRace.controller.getState().error,'mismatched echoed page retains prior confirmed rows/counts and shows a retry');pageRace.setState(null);
await pageRace.controller.refresh({page:1});const disclosure=pageRace.hold('profile_attention_state'),oldDisclosure=pageRace.controller.refresh({page:1});await settle();unavailable(pageRace.rawRows.get(26));await pageRace.controller.refresh({page:1});disclosure.work.release();await oldDisclosure;
check(pageRace.controller.getState().items.find(item=>item.attention_id===26)?.source_status==='unavailable','fresh same-page source revocation wins over an older page response at the same item revision');
pageRace.failures.set('profile_attention_state',{status:503});await pageRace.page('next');
check(pageRace.controller.getState().page===1&&pageRace.controller.getState().items.length===25&&pageRace.controller.getState().error,'failed Next retains the last confirmed page instead of clearing the list');
pageRace.failures.delete('profile_attention_state');await pageRace.refresh();check(pageRace.controller.getState().page===2&&!pageRace.controller.getState().error,'Retry loads the originally requested next page without resetting selection');

const moving=await attentionFixture({items:backlog}),oldPresentation=moving.hold('profile_attention_present'),movingWork=moving.refresh();await settle();await moving.controller.refresh({page:1});oldPresentation.work.release();await movingWork;
check(moving.controller.getState().page===1&&moving.thread.children.length===0&&!issued(moving,'profile_attention_ack').length&&issued(moving,'profile_attention_present').length===1,'navigation invalidates an earlier automatic present result and stops its remaining loop');
for(const f of [pages,readOnlyPages,pageRace,moving])f.dispose();
for(const f of [plain,terminal,draft,delayedDraft,elsewhere,partial,never,hidden,covered,newer,saved,ack,retry,conflict,snooze,escaped])f.dispose();
console.log(`PROFILE_ATTENTION_PA4B=${checks} assertions PASS`);
