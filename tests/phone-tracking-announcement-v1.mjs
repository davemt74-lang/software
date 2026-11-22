import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source=readFileSync(new URL('../chat.js',import.meta.url),'utf8');
const start=source.indexOf('  function presentPhoneUpdatesV1(');
const end=source.indexOf('  async function pollAgentActivity',start);
assert.ok(start>=0 && end>start && source.indexOf('presentPhoneUpdatesV1(data,activeConversationId,activityChatRevision)',end)>end);
let enabled=true, participant=false, focused=true, authenticated=true;
const spoken=[];
const ctx={chatRevision:5,conversationId:3,historyParticipant:0,initiativePage:{owner:1,agent:7},thread:{dataset:{view:'chat'}},
  document:{visibilityState:'visible',hasFocus:()=>focused},initiativeIdentityMatchesV1:()=>authenticated,window:{
    VP3ParticipantContinuity:{selectedContext:()=>participant},
    VP3ConversationControls:{acceptsProactivePosts:()=>enabled},
    STONEFELLOW_NOTIFICATION_CENTER:{announceWhen:(speech,check,options)=>spoken.push({speech,check,options})}
  }};
vm.runInNewContext(source.slice(start,end),ctx);
const update={owner_user_id:1,agent_id:7,conversation_id:3,message_id:49,speech:'A GPS fix arrived.'};
const show=(change={})=>ctx.presentPhoneUpdatesV1({conversation_id:3,phone_updates:[{...update,...change}]},3,5);
enabled=false;show();
enabled=true;participant=true;show();
participant=false;focused=false;show();
focused=true;authenticated=false;show();
authenticated=true;
for(const change of [{owner_user_id:2},{agent_id:8},{conversation_id:8},{message_id:0}]) show(change);
assert.equal(spoken.length,0);
show();
assert.equal(spoken.length,1);
assert.equal(spoken[0].speech,update.speech);
assert.equal(spoken[0].options.autoReply,false);
assert.equal(spoken[0].check(),true);
enabled=false; assert.equal(spoken[0].check(),false);
enabled=true;ctx.conversationId=4; assert.equal(spoken[0].check(),false);
console.log('Phone tracking speaks only in the active private Chat, with announcements enabled through playback.');
