import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

// Run the complete published module through its normal mount/fetch/render entry.
// Native MySQL tests prove the server projection; this fixture proves its display and management route.
const code=readFileSync(new URL('../agent-top-of-mind-v1.js',import.meta.url),'utf8');
let checks=0;
async function render(item,agent=7){
  const host={isConnected:true,innerHTML:'',contains:()=>false,querySelector:()=>null,querySelectorAll:()=>[]};
  const window={STONEFELLOW_CHAT:{userId:1,agentId:agent,initialConversationId:11,workingAttentionBinding:'private-session',csrf:'fixture',chatUrl:'/vp3/chat.php'},
    STONEFELLOW_NOTIFICATION_DRAWER:{topOfMindEndpoint:'/vp3/api/agent-top-of-mind-v1.php'},
    setTimeout:()=>1,clearTimeout:()=>{},addEventListener:()=>{}};
  const document={hidden:false,activeElement:null,body:{dataset:{},classList:{contains:()=>false}},querySelector:()=>null,addEventListener:()=>{}};
  const calls=[];
  const sandbox={window,document,location:{origin:'https://vp3.example.test',href:'https://vp3.example.test/vp3/chat.php'},URL,AbortController,console,
    MutationObserver:class{observe(){}},fetch:async(url,options)=>{
      calls.push({url:String(url),options});return {ok:true,json:async()=>({ok:true,state:{contract:'vp3.top-of-mind.v1',owner_user_id:1,
        agent_id:agent,conversation_id:11,working_attention_binding:'private-session',observed_at:new Date().toISOString(),state:'ready',items:[],
        gmail_inbox:{state:'checked',matters:{items:[item],closed:[]}}}})};
    }};
  vm.runInNewContext(code,sandbox,{filename:'agent-top-of-mind-v1.js'});
  window.VP3TopOfMindFeedV1.mount(host,true);
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(calls.length,1);assert.equal(calls[0].options.headers['X-VP3-Working-Attention'],'private-session');
  assert.ok(!calls[0].options.method||calls[0].options.method==='GET');
  return host.innerHTML;
}
const base={id:'a'.repeat(64),revision:1,stage:'awaiting_reply',subject:'Owner email <img src=x onerror=bad>',waiting_for:'recipient@example.test',
  next_wake_at:'2026-12-28T12:00:00Z',next_check_at:null};
const tracked={followthrough_ref:'b'.repeat(32),agent_id:7,conversation_id:12,outcome:'resolved',paused:false,awaiting_new_deadline:false,
  deadline_at:'2026-11-28T12:00:00Z',check:{state:'never_checked',result:null,checked_at:null}};
let html=await render({...base,response_followthrough:tracked});
assert.match(html,/Tracking resolved/);assert.doesNotMatch(html,/Waiting for a reply|Waiting for recipient/);
assert.doesNotMatch(html,/data-top-of-mind-email|Mark resolved|Snooze 1 hour/);
assert.match(html,/href="\/vp3\/chat\.php\?agent=7&amp;conversation_id=12&amp;gmail_followthrough=b{32}">Manage follow-up/);
assert.match(html,/Owner email &lt;img/);assert.doesNotMatch(html,/<img src=x/);checks++;
console.log('PASS canonical resolved tracking replaces derivative waiting state and routes only to exact scoped follow-up management');

html=await render({...base,stage:'snoozed',response_followthrough:{...tracked,outcome:'overdue',paused:true}});
assert.match(html,/Tracking paused/);assert.doesNotMatch(html,/>Resume<|data-top-of-mind-email/);assert.match(html,/Manage follow-up/);checks++;
console.log('PASS canonical pause cannot expose a legacy matter Resume or resolve control');

html=await render({...base,response_followthrough:{...tracked,outcome:'overdue',check:{state:'verified',result:'no_reply',checked_at:'2026-11-27T12:01:00Z'}}});
assert.match(html,/Response overdue/);assert.match(html,/Response deadline/);assert.match(html,/No matching reply in the checked thread as of/);
assert.doesNotMatch(html,/Watch until|Waiting for a reply/);checks++;
console.log('PASS true response deadline and timestamped exact-thread evidence replace legacy watch retention wording');

html=await render({...base,response_followthrough:{...tracked,outcome:'waiting',awaiting_new_deadline:true}});
assert.match(html,/choose a new deadline for another draft/);assert.doesNotMatch(html,/data-top-of-mind-email/);checks++;
console.log('PASS a sent follow-up requests a new owner deadline instead of implying another automatic draft');

html=await render(base);assert.match(html,/Watch until/);assert.match(html,/data-top-of-mind-email="snooze"/);assert.doesNotMatch(html,/Response deadline|Manage follow-up/);checks++;
console.log('PASS legacy matter controls remain available when no canonical response tracker exists');

html=await render({...base,response_followthrough:{...tracked,agent_id:8}});
assert.doesNotMatch(html,/Tracking resolved|gmail_followthrough=|Response deadline/);checks++;
console.log('PASS a different selected Agent cannot surface or route another Agent deadline');

html=await render({...base,response_followthrough:{...tracked,agent_id:0}},0);
assert.match(html,/agent=system&amp;conversation_id=12&amp;gmail_followthrough=b{32}/);checks++;
console.log('PASS System Agent management uses the canonical system route');
console.log(`TOP_OF_MIND_FOLLOWTHROUGH=PASS (${checks} groups)`);
