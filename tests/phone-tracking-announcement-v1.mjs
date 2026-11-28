import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source=readFileSync(new URL('../chat.js',import.meta.url),'utf8');
const start=source.indexOf('  function conversationFeedActiveV1(');
const end=source.indexOf('  async function pollAgentActivity',start);
assert.ok(start>=0 && end>start && source.indexOf('presentPhoneUpdatesV1(data,activeConversationId,activityChatRevision)',end)>end);
assert.match(source.slice(end),/phoneChatForegroundV1\(activeConversationId\) \? \{phone_updates:true\}/);
assert.match(source.slice(end),/data\.phone_tracking_context && !request\.voiceSubmission && !existingReply && data\.phone_updates\) \{\s*presentPhoneUpdatesV1\(data,conversationId,request.revision\)/);
let enabled=true, participant=false, focused=true, authenticated=true;
const spoken=[];
const ctx={chatRevision:5,conversationId:3,historyParticipant:0,initiativePage:{owner:1,agent:7},thread:{isConnected:true,dataset:{view:'chat'}},
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
assert.equal(ctx.phoneChatForegroundV1(3),false,'an unfocused private Chat cannot consume first-fix announcements');
focused=true;authenticated=false;show();
authenticated=true;
assert.equal(ctx.phoneChatForegroundV1(3),true);
for(const change of [{owner_user_id:2},{agent_id:8},{conversation_id:8},{message_id:0}]) show(change);
assert.equal(spoken.length,0);
show();
assert.equal(spoken.length,1);
ctx.thread.dataset.view='coding';show();
assert.equal(spoken.length,2,'Canvas editor keeps this private conversation’s phone announcements active');
ctx.thread.dataset.view='player';show();
assert.equal(spoken.length,3,'Phone announcements follow the private Agent account in every tool view');
ctx.thread.isConnected=false;show();assert.equal(spoken.length,3,'A detached Chat cannot consume announcements');ctx.thread.isConnected=true;
ctx.thread.dataset.view='chat';
assert.equal(spoken[0].speech,update.speech);
assert.equal(spoken[0].options.autoReply,false);
assert.equal(spoken[0].check(),true);
enabled=false; assert.equal(spoken[0].check(),false);
enabled=true;ctx.conversationId=4; assert.equal(spoken[0].check(),false);
console.log('Phone tracking announces the active private conversation across Agent views, with account and playback fences.');
