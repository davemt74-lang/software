import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('homeserver-settings-v1210.js','utf8');
async function render(status){
  const elements=new Map();
  const element=id=>{
    if(!elements.has(id))elements.set(id,{dataset:{},hidden:false,textContent:'',innerHTML:'',className:'',addEventListener(){},setAttribute(){},querySelectorAll(){return [];}});
    return elements.get(id);
  };
  const root=element('root');root.dataset={api:'/status',csrf:'test'};
  const document={querySelector:()=>root,getElementById:element,visibilityState:'visible'};
  const context={document,console,URLSearchParams,Date,fetch:async()=>({ok:true,status:200,json:async()=>({ok:true,status})}),setInterval(){},setTimeout(){return 1;},clearTimeout(){}};
  vm.runInNewContext(source,context);
  await new Promise(resolve=>setImmediate(resolve));
  return element;
}
const live={connection_state:'connected',connected:true,paired:true,transport:'vp3_https',error:'',capabilities:[],paired_scopes:[]};
let el=await render({...live,reconciliation:{needs_reconciliation:true,last_error:'HomeServer shared context permission is unavailable: files.read'}});
assert.equal(el('hsStatePill').dataset.state,'connected');
assert.equal(el('hsStateLabel').textContent,'Reconciling');
assert.match(el('hsAlert').textContent,/files.read/);
assert.match(el('hsAlert').textContent,/HomeServer is connected/);
assert.equal(el('hsRecoveryActions').hidden,true);
console.log('PASS failed synchronization preserves connected transport and shows the exact permission error');
el=await render({...live,reconciliation:{needs_reconciliation:false,last_error:'',last_summary:{unavailable_datasets:{files:'permission_required:files.read'}}}});
assert.equal(el('hsStateLabel').textContent,'Connected');
assert.match(el('hsAlert').textContent,/Native HomeServer files are excluded/);
assert.match(el('hsAlert').className,/warning/);
assert.equal(el('hsStartOver').hidden,false); // Its recovery group remains hidden.
assert.equal(el('hsRecoveryActions').hidden,true);
console.log('PASS authorized continuity is current and missing file coverage is a warning');
el=await render({...live,reconciliation:{needs_reconciliation:false,last_error:'',last_summary:{unavailable_datasets:{}}}});
assert.equal(el('hsStateLabel').textContent,'Connected');
assert.equal(el('hsAlert').hidden,true);
console.log('PASS full permission coverage clears the warning');
