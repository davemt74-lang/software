import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

let respond=async()=>({ok:true,status:200,json:async()=>({ok:true})});
const requests=[];
const sandbox=vm.createContext({window:{},Intl,Date,Map,Set,URL,AbortController,DOMException,setTimeout,clearTimeout,
  location:{href:'https://calls.test/phone-location.php',origin:'https://calls.test'},fetch:async(url,input)=>{requests.push({url:String(url),...input});return respond(url,input);}});
vm.runInContext(readFileSync(new URL('../agent-email-call-settings-v1.js',import.meta.url),'utf8'),sandbox);
const C=sandbox.window.VP3EmailCallSettingsContractV1,client=sandbox.window.VP3EmailCallSettingsV1.createClient;
let assertions=0;
const clean=value=>value===undefined?undefined:JSON.parse(JSON.stringify(value));
const equal=(actual,expected,message)=>{assert.deepEqual(clean(actual),expected,message);assertions++;};
const ok=(value,message)=>{assert.ok(value,message);assertions++;};
const rejects=(callback,message)=>{assert.throws(callback,undefined,message);assertions++;};
const rejectsAsync=async(callback,message)=>{await assert.rejects(callback,undefined,message);assertions++;};
const device='d'.repeat(32),item='1'.repeat(32),request='2'.repeat(32),scope='a'.repeat(64),csrf='f'.repeat(64);
const phone={device_id:device,enrollment_revision:3,scope_revision:scope,agent_id:0,conversation_id:42,csrf_token:csrf};
const normal={owner_key:'7',agent_id:0,conversation_id:42,csrf_token:csrf};
const echo={device_id:device,enrollment_revision:3,scope_revision:scope};
const state={ok:true,scope:echo,policy:{revision:0,urgent_enabled:false,drafts_enabled:false,grace_seconds:300,device_id:null,number_id:0},
  devices:[{device_id:device,label:'My phone',agent_name:'Main Agent',ready:true,reason:'eligible',lines:[{number_id:5,label:'Private calling line'}]}],
  owner_phone:{masked_number:'••• ••• 0199',updates_requested:true,time_zone:'America/Phoenix',window_start:'09:00',window_end:'17:00',max_calls_per_day:1,min_gap_minutes:240,max_duration_seconds:180},
  readiness:{state:'off',reason:'off',message:'Email calls are off.'},decision:null,active_call:null,
  links:{account_phone:'/account.php#agent-phone',phone_connection:'/phone-location.php'}};
const call={request_id:request,revision:2,state:'submitted',reason:'requested',call_id:'3'.repeat(32),updated_at:'2026-11-27T10:00:00Z'};
const active={request_id:request,item_ref:item,revision:2,state:'submitted',call_id:call.call_id,source_kind:'gmail_draft'};

equal(C.context({...phone,owner_key:'999'}),phone,'Holder transport does not manufacture an owner identity.');
equal(C.context(normal),normal);
for(const value of [{...phone,device_id:'bad'},{...phone,enrollment_revision:0},{...phone,scope_revision:null},{...normal,owner_key:'0'},
  {...normal,agent_id:-1},{...normal,conversation_id:1.2},{...normal,csrf_token:''}])rejects(()=>C.context(value),'Malformed authority is rejected.');
equal(C.state(state,phone).policy.device_id,'','No phone selection is invented from an empty saved policy.');
equal(C.state({...state,active_call:active},phone).active_call,active);
equal(C.state({...state,scope:undefined},normal).readiness.state,'off','Authenticated Chat uses its own scope path.');
for(const change of [{device_id:'e'.repeat(32)},{enrollment_revision:4},{scope_revision:'b'.repeat(64)}])
  rejects(()=>C.state({...state,scope:{...echo,...change}},phone),'A changed holder or Agent cannot reuse private call settings.');
for(const change of [{revision:-1},{urgent_enabled:'yes'},{drafts_enabled:1},{grace_seconds:0},{grace_seconds:3601},{device_id:'bad'},{number_id:-1}])
  rejects(()=>C.state({...state,policy:{...state.policy,...change}},phone),'Saved policy has typed bounded fields.');
for(const change of [{time_zone:'Unknown/Zone'},{window_start:'24:00'},{window_end:'17:99'},{updates_requested:'yes'},{max_calls_per_day:-1},{min_gap_minutes:10081},{max_duration_seconds:3601}])
  rejects(()=>C.state({...state,owner_phone:{...state.owner_phone,...change}},phone),'Calling hours and limits must be verifiable.');
for(const value of ['https://elsewhere.test/owner','//elsewhere.test/owner','/\\elsewhere.test','javascript:alert(1)','/account.php\n'])
  rejects(()=>C.state({...state,links:{...state.links,account_phone:value}},phone),'Settings never invent arbitrary navigation targets.');
rejects(()=>C.state({...state,devices:[state.devices[0],state.devices[0]]},phone));
rejects(()=>C.state({...state,devices:[{...state.devices[0],lines:[{number_id:0,label:'Invalid'}]}]},phone));
rejects(()=>C.state({...state,readiness:{...state.readiness,state:'physically_away'}},phone),'Browser absence is not an authority/readiness state.');
for(const change of [{request_id:'bad'},{revision:0},{state:'actively_speaking'},{call_id:'CA-provider-sid'}])
  rejects(()=>C.call({...call,...change}),'Only native typed call metadata is accepted.');
rejects(()=>C.call({...active,source_kind:'image'},true));
rejects(()=>C.call({...active,item_ref:'bad'},true));
const statuses={ok:true,scope:echo,items:[{item_ref:item,call}],active_call:active};
equal(C.statuses(statuses,phone,[item]).items[0].call,call);
equal(C.statuses({...statuses,items:[],active_call:null},phone,[item]).items,[{item_ref:item,call:null}],'An omitted item clears its earlier private call metadata.');
for(const change of [{items:[{item_ref:'4'.repeat(32),call}]},{items:[{item_ref:item,call},{item_ref:item,call}]},
  {scope:{...echo,enrollment_revision:4}},{items:[{item_ref:item,call:{...call,updated_at:'yesterday'}}]}])
  rejects(()=>C.statuses({...statuses,...change},phone,[item]),'Call status cannot escape the exact requested item/scope.');

const calls=C.createCallState();calls.merge(C.statuses(statuses,phone,[item]));
equal(calls.get(item).state,'submitted');
calls.merge({items:[{item_ref:item,call:{...call,revision:3,state:'ended'}}],active_call:null});
calls.merge({items:[{item_ref:item,call:{...call,revision:2,state:'submitted'}}],active_call:null});
equal(calls.get(item).state,'ended','A delayed call response cannot replace a newer outcome.');
const source={source_revision:'b'.repeat(64),revision:8,receipt:{read_at:null}},before=JSON.stringify(source);
calls.merge({items:[{item_ref:item,call:{...call,revision:4,state:'uncertain'}}],active_call:null});
equal(JSON.stringify(source),before,'Call progress has no access to or mutation of Gmail semantic/receipt state.');
calls.clear();equal(calls.get(item),null);equal(calls.active(),null);

let current=true;
const holderClient=client({endpoint:'/api/phone-location-v1.php?workspace_calls=1',context:phone,isCurrent:()=>current});
respond=async()=>({ok:true,status:200,json:async()=>state});
await holderClient.request('state');
equal(JSON.parse(requests.at(-1).body),{...phone,action:'state'});equal(requests.at(-1).headers['X-VP3-Phone'],'1');
equal(requests.at(-1).credentials,'same-origin');equal(requests.at(-1).redirect,'error');
await holderClient.request('save',{expected_revision:0,selected_device_id:device,number_id:5,confirmed:true,urgent_enabled:true,drafts_enabled:false,grace_seconds:300});
const body=JSON.parse(requests.at(-1).body);equal(body.selected_device_id,device);equal(body.device_id,device,'Selected destination and cookie-bound phone retain separate fields.');
await holderClient.request('save',{expected_revision:1,selected_device_id:device,number_id:5,confirmed:false,urgent_enabled:false,drafts_enabled:false,grace_seconds:300});
equal(JSON.parse(requests.at(-1).body).confirmed,false,'Turning off both categories does not require new calling consent.');
const normalClient=client({endpoint:'/api/agent-email-calls-v1.php',context:normal});
await normalClient.request('state');equal(requests.at(-1).headers['X-VP3-Email-Calls'],'1');equal(JSON.parse(requests.at(-1).body).owner_key,'7');
rejects(()=>client({endpoint:'https://elsewhere.test/api/calls',context:normal}));
await rejectsAsync(()=>holderClient.request('confirm'), 'The settings client cannot confirm email or dial a call.');
let release;respond=async()=>new Promise(resolve=>{release=()=>resolve({ok:true,status:200,json:async()=>state});});
const delayed=holderClient.request('state');await Promise.resolve();current=false;release();
await rejectsAsync(()=>delayed,'Late settings responses cannot cross an enrollment/session boundary.');
current=true;let pendingSignal;respond=async(_url,input)=>new Promise((_resolve,reject)=>{pendingSignal=input.signal;input.signal.addEventListener('abort',()=>reject(new DOMException('Aborted','AbortError')));});
const pending=holderClient.request('state');await Promise.resolve();holderClient.dispose();ok(pendingSignal.aborted,'Disposal aborts private reads.');await rejectsAsync(()=>pending);
await rejectsAsync(()=>holderClient.request('state'),'A disposed controller cannot write even if a caller keeps it.');normalClient.dispose();
console.log(`Email call settings state: ${assertions} assertions passed.`);
