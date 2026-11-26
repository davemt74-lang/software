import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const context=vm.createContext({window:{},AbortController,Date,Map,Set,URL,DOMException});
vm.runInContext(readFileSync(new URL('../phone-agent-workspace-v1.js',import.meta.url),'utf8'),context);
const contract=context.window.VP3PhoneAgentWorkspaceContractV1;
let assertions=0;
const equal=(actual,expected,message)=>{assert.deepEqual(actual===undefined?undefined:JSON.parse(JSON.stringify(actual)),expected,message);assertions++;};
const rejects=(callback,message)=>{assert.throws(callback,undefined,message);assertions++;};
const ok=(value,message)=>{assert.ok(value,message);assertions++;};
const device='d'.repeat(32),scopeRevision='a'.repeat(64),sourceRevision='b'.repeat(64);
const status={ok:true,device_id:device,state:'ready',owner_eligible:true,enrolled:true,revision:2,enrollment_revision:2,
  scope_revision:scopeRevision,csrf_token:'f'.repeat(64),agent:{id:0,name:'Main Agent'},agents:[{id:0,name:'Main Agent'}],conversation_id:7,
  preferences:{notifications_enabled:false},counts:null};
const scope={device_id:device,enrollment_revision:2,scope_revision:scopeRevision,agent_id:0};
const receipt={seen_at:null,read_at:null,acknowledged_at:null,snoozed_until:null};
const row={item_ref:'1'.repeat(32),revision:1,state_version:1,source_kind:'gmail_draft',source_revision:sourceRevision,agent_id:0,conversation_id:7,
  title:'Review the proposal',summary:'The saved email is ready for your review.',state:'needs_review',needs_action:true,priority:'important',
  occurred_at:'2026-11-27T10:00:00Z',updated_at:'2026-11-27T10:00:00Z',receipt,
  target:{kind:'gmail_draft',agent_id:0,conversation_id:7,draft_id:'2'.repeat(32)}};
const envelope={ok:true,...scope};
delete envelope.agent_id;
const page={...envelope,items:[row],counts:{unread:1,needs_action:1,complete:true},has_more:false,next_cursor:null};

equal(contract.status(status,device).counts,null,'Status does not invent counts before source discovery.');
equal(contract.status({...status,counts:{unread:3,needs_action:1,complete:false}},device).counts,{unread:3,needs_action:1,complete:false});
for(const change of [{owner_eligible:false},{enrolled:false},{csrf_token:''},{scope_revision:null},{revision:3},{device_id:'e'.repeat(32)},
  {agent:{id:-1,name:'Wrong'}},{state:'ready',agents:[{id:0,name:'Main'},{id:0,name:'Duplicate'}]}]){
  rejects(()=>contract.status({...status,...change},device),'Malformed ready status cannot grant owner access.');
}
const holder={...status,state:'sign_in_required',owner_eligible:false,enrolled:false,scope_revision:null,revision:0,enrollment_revision:0,role:'owner'};
const safeHolder=contract.status(holder,device);
equal(safeHolder.agents,[],'A role label never exposes the owner Agent list.');
equal(safeHolder.csrf_token,'','A holder projection does not retain an owner CSRF token.');
equal(safeHolder.agent,null);
equal(contract.status({...status,state:'not_enrolled',enrolled:false,scope_revision:null,revision:4,enrollment_revision:4},device).enrollment_revision,4,'A revoked enrollment can be enrolled again at its current revision.');
equal(contract.item(row,0).needs_action,true,'Unread and needs_action are independent.');
const read={...row,revision:2,state_version:2,receipt:{...receipt,read_at:'2026-11-27T10:01:00Z'}};
equal(contract.item(read,0).needs_action,true,'Marking an email read does not approve the draft.');
equal(contract.item(read,0).receipt.read_at,'2026-11-27T10:01:00Z');
for(const change of [{agent_id:4},{conversation_id:9},{source_revision:'not-a-revision'},{state_version:0},{state:'sent_by_model'},
  {needs_action:'yes'},{priority:'emergency'},{updated_at:'yesterday'},{receipt:{...receipt,read_at:true}},
  {target:{...row.target,agent_id:4}},{target:{...row.target,conversation_id:9}},{target:{...row.target,url:'https://untrusted.test'}}]){
  rejects(()=>contract.item({...row,...change},0),'Unscoped or malformed source data is rejected.');
}
for(const [kind,target] of [
  ['gmail_attention',{kind:'gmail_attention',agent_id:0,conversation_id:7,item_ref:'a'.repeat(64),generation:2,origin:'gmail_mailbox',conversation_role:'discussion_target'}],
  ['agent_result',{kind:'chat_message',agent_id:0,conversation_id:7,message_id:120}],
  ['image',{kind:'image',agent_id:0,conversation_id:7,generation_id:'33333333-3333-4333-8333-333333333333'}],
  ['canvas',{kind:'canvas',agent_id:0,conversation_id:7,canvas_id:'44444444-4444-4444-8444-444444444444',revision:3}]
])equal(contract.item({...row,source_kind:kind,target},0).target,target,'Native typed targets retain exact source identity.');
const attention={kind:'gmail_attention',agent_id:0,conversation_id:7,item_ref:'a'.repeat(64),generation:2,origin:'gmail_mailbox',conversation_role:'discussion_target'};
for(const change of [{item_ref:'a'.repeat(32)},{generation:0},{origin:'chat'},{conversation_role:'original_source'}]){
  rejects(()=>contract.item({...row,source_kind:'gmail_attention',target:{...attention,...change}},0),'Mailbox-wide email retains its exact provenance and discussion target.');
}
equal(contract.page(page,scope).items.length,1);
for(const change of [{device_id:'e'.repeat(32)},{enrollment_revision:3},{scope_revision:'c'.repeat(64)},
  {has_more:true},{next_cursor:'cursor'},{items:[row,row]},{next_cursor:'x'.repeat(2049),has_more:true}]){
  rejects(()=>contract.page({...page,...change},scope),'A page cannot cross enrollment or pagination boundaries.');
}
equal(contract.page({...page,has_more:true,next_cursor:'opaque-next-page'},scope).next_cursor,'opaque-next-page');
const native={...envelope,item:row,detail:{kind:'gmail_draft',target:row.target}};
equal(contract.detail(native,scope).item.item_ref,row.item_ref);
rejects(()=>contract.detail({...native,detail:{kind:'gmail_draft',target:{...row.target,draft_id:'f'.repeat(32)}}},scope),'Detail cannot substitute a different native draft.');
const resultRow={...row,source_kind:'agent_result',target:{kind:'chat_message',agent_id:0,conversation_id:7,message_id:120}};
equal(contract.detail({...envelope,item:resultRow,detail:{kind:'agent_result',text:'<script>literal data</script>',truncated:true}},scope).detail,
  {kind:'agent_result',text:'<script>literal data</script>',truncated:true},'Plain result content remains data, with explicit truncation.');

const state=contract.createState();
equal(state.bind('phone-A/enrollment-1'),true);
const first=state.capture(),pending=state.request();
ok(state.current(first),'Current scope accepts its own response.');
equal(state.bind('phone-A/enrollment-1'),false,'Incidental refresh preserves an open detail scope.');
ok(state.current(first),'Unchanged scope does not invalidate dirty native views.');
state.merge(row);equal(state.merge(read).revision,2);
equal(state.merge(row).revision,2,'A delayed list cannot replace a just-read item with its old unread state.');
equal(state.merge({...read,state_version:1,revision:3}).revision,2,'A lower visible state version cannot overwrite a later state.');
equal(state.merge({...read,state_version:3,revision:1}).revision,2,'A lower control revision cannot overwrite a later state.');
const revised={...row,revision:3,state_version:3,source_revision:'c'.repeat(64)};
equal(state.merge(revised).receipt.read_at,null,'A genuinely changed source can become unread again at a newer version.');
equal(state.bind('phone-A/enrollment-2'),true);
ok(pending.controller.signal.aborted,'Changing enrollment aborts in-flight private requests.');
equal(state.current(first),false,'A delayed result from the former enrollment cannot render.');
equal(state.get(row.item_ref),undefined,'A changed enrollment removes cached private items.');
const second=state.capture(),next=state.request();
state.clear();ok(next.controller.signal.aborted,'Logout/revocation aborts the active request.');equal(state.current(second),false);
equal(state.current(state.capture()),false,'An empty or revoked scope cannot accept any response.');
state.bind('phone-B/enrollment-1');const third=state.capture(),cancelled=state.request();state.cancel();
ok(cancelled.controller.signal.aborted,'Hidden-page requests can be stopped without discarding the editing scope.');
ok(state.current(third),'Pausing network reads does not silently discard a current edit.');
cancelled.finish();pending.finish();next.finish();
console.log(`Phone Agent workspace state: ${assertions} assertions passed.`);
