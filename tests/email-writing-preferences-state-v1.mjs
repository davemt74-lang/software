import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

let respond=async()=>({ok:true,status:200,json:async()=>({ok:true})}),timerId=0;
const requests=[],timers=new Map();
const sandbox=vm.createContext({window:{},URL,Map,Set,AbortController,DOMException,Event,
  location:{href:'https://writing.test/chat.php',origin:'https://writing.test'},
  setTimeout:(fn,delay)=>{timers.set(++timerId,{fn,delay});return timerId;},clearTimeout:id=>timers.delete(id),
  fetch:async(url,options)=>{requests.push({url,...options});return respond(url,options);}});
vm.runInContext(readFileSync(new URL('../email-writing-preferences-v1.js',import.meta.url),'utf8'),sandbox);
const C=sandbox.window.VP3EmailWritingPreferencesContractV1,create=sandbox.window.VP3EmailWritingPreferencesV1.createClient;
let assertions=0;
const clean=value=>value===undefined?undefined:JSON.parse(JSON.stringify(value));
const equal=(actual,expected,message)=>{assert.deepEqual(clean(actual),expected,message);assertions++;};
const ok=(value,message)=>{assert.ok(value,message);assertions++;};
const rejects=(callback,message)=>{assert.throws(callback,undefined,message);assertions++;};
const rejectsAsync=async(callback,message)=>{await assert.rejects(callback,undefined,message);assertions++;};
const ref=n=>n.toString(16).padStart(32,'0'),hash=n=>n.toString(16).padStart(64,'0');
const draft={id:ref(1),edit_revision:hash(1)},context={owner_key:'1',agent_id:7,conversation_id:42,csrf_token:'fixture-csrf'};
const base={draft_id:draft.id,eligible:false,reason:'owner_edit_required',capture_id:ref(2),edit_revision:draft.edit_revision,
  soul_version:0,soul_revision:hash(3),proposal:null};
const status=(changes={})=>({ok:true,preference:{...base,...changes}});
const proposal={id:ref(3),revision:1,state:'ready',category:'length',preference:'Use short paragraphs and a direct closing.',reason:null,accepted_version:null};
equal(C.status(status(),draft),base);
equal(C.status(status({eligible:true,reason:null}),draft).eligible,true);
equal(C.status(status({reason:null,proposal}),draft).proposal.preference,proposal.preference);
for(const change of [{draft_id:ref(99)},{edit_revision:hash(99)},{capture_id:'invalid'},{soul_revision:'invalid'},
  {soul_version:-1},{eligible:1},{eligible:true},{reason:'unknown_failure'},{proposal:{...proposal,revision:0}},
  {proposal:{...proposal,state:'automatically_saved'}},{proposal:{...proposal,category:'recipient'}},
  {proposal:{...proposal,preference:'🙂'.repeat(601)}},{proposal:{...proposal,accepted_version:0}}])
  rejects(()=>C.status(status(change),draft),'Unverified eligibility/proposal identity cannot enable a save.');
for(const state of C.states){
  equal(C.status(status({reason:null,proposal:{...proposal,state}}),draft).proposal.state,state);
}
equal(C.status(status({reason:'publication_unavailable',proposal:{...proposal,state:'publishing',reason:'publication_unavailable'}}),draft).proposal.preference,proposal.preference);
equal(C.preferenceText('  Keep the opening direct.  '),'Keep the opening direct.');
equal(C.preferenceText('🙂'.repeat(600)),'🙂'.repeat(600),'The bound counts Unicode characters, not UTF-16 code units.');
for(const value of ['', ' '.repeat(4),'x'.repeat(601),'Two\nparagraphs','Tabs\there','Line\u2028separator','Paragraph\u2029separator','Control\u0000text'])
  rejects(()=>C.preferenceText(value),'Only a bounded plain paragraph can be explicitly accepted.');

const profile={text:'Use clear, warm email language.\n',version:2,revision:hash(4),history:[{version:2,current:true},{version:1,current:false},{version:0,current:false}],next_cursor:null};
const prefs=changes=>({ok:true,preferences:{...profile,...changes}});
equal(C.preferences(prefs({})),profile);
const long='🙂'.repeat(24000)+'\n';
equal(C.preferences(prefs({text:long,selected_version:1,selected_text:long})).text,long,'Canonical 24,000-character content retains its stored final newline.');
ok(Buffer.byteLength(JSON.stringify({action:'preferences_save',...context,text:long}))>50000,'The non-ASCII fixture exercises the expanded settings request budget.');
for(const changes of [{text:'x'.repeat(24002)},{version:-1},{revision:'bad'},{history:[{version:2,current:false}]},
  {history:[{version:1,current:false},{version:1,current:false}]},{next_cursor:-1},{selected_version:3,selected_text:'Text'},
  {selected_version:1},{selected_text:'Missing version'}])rejects(()=>C.preferences(prefs(changes)));

let current=true,accessChanged=0;
const client=create({endpoint:'/api/chat-email-v1.php',context,isCurrent:()=>current,onAccessChanged:()=>accessChanged++});
respond=async()=>({ok:true,status:200,json:async()=>status()});
await client.request('preference_status',{draft_id:draft.id});
equal(JSON.parse(requests.at(-1).body),{draft_id:draft.id,...context,action:'preference_status'});
equal(requests.at(-1).credentials,'same-origin');equal(requests.at(-1).cache,'no-store');equal(requests.at(-1).redirect,'error');
equal(timers.size,0,'Completed reads release their timeout.');
await client.request('preferences_get',{owner_key:'999',agent_id:99});
equal(JSON.parse(requests.at(-1).body).owner_key,'1');equal(JSON.parse(requests.at(-1).body).agent_id,7,'Fields cannot replace the captured private scope.');
await rejectsAsync(()=>client.request('confirm'),'The writing-preference client cannot send an email.');
rejects(()=>create({endpoint:'https://elsewhere.test/api',context}));
for(const changed of [{owner_key:'0'},{conversation_id:0},{agent_id:-1},{csrf_token:''}])rejects(()=>create({endpoint:'/api',context:{...context,...changed}}));

const native=create({endpoint:'/api/phone-location-v1.php?workspace_email=1&grant=opaque',context,fixedDraftId:draft.id});
await native.request('preferences_get');
equal(JSON.parse(requests.at(-1).body).draft_id,draft.id,'Phone management stays bound to the exact native draft.');
await rejectsAsync(()=>native.request('preferences_save',{draft_id:ref(99),text:profile.text}));
respond=async()=>({ok:false,status:403,json:async()=>({ok:false,error:'Access changed.'})});
await rejectsAsync(()=>client.request('preferences_get'));equal(accessChanged,1);

let release;
respond=async()=>new Promise(resolve=>{release=()=>resolve({ok:true,status:200,json:async()=>status()});});
const delayed=client.request('preference_status',{draft_id:draft.id});await Promise.resolve();current=false;release();
await rejectsAsync(()=>delayed,'A late read cannot re-enter a changed Chat or owner scope.');current=true;
let pendingSignal;
respond=async(_url,input)=>new Promise((_resolve,reject)=>{pendingSignal=input.signal;input.signal.addEventListener('abort',()=>reject(new DOMException('Aborted','AbortError')));});
const slow=client.request('preference_prepare',{draft_id:draft.id,capture_id:ref(2),edit_revision:draft.edit_revision});await Promise.resolve();
const timeout=[...timers.values()][0];equal(timeout.delay,125000);timeout.fn();await rejectsAsync(()=>slow);ok(pendingSignal.aborted);equal(timers.size,0);
const countBefore=requests.length;equal(requests.filter(r=>JSON.parse(r.body).action==='preference_prepare').length,1,'A timeout never dispatches an implicit second preparation.');
const pending=native.request('preferences_get');await Promise.resolve();native.dispose();ok(pendingSignal.aborted);await rejectsAsync(()=>pending);
await rejectsAsync(()=>native.request('preferences_get'));equal(requests.length,countBefore+1);client.dispose();
console.log(`Email writing preferences state: ${assertions} assertions passed.`);
