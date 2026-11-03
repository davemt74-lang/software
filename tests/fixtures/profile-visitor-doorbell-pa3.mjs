export const copy=value=>JSON.parse(JSON.stringify(value));
export const contract='vp3.profile-handoff.v1';
export const chatChoice=(overrides={})=>({contract:'vp3.profile-chat-choice.v1',owner_name:'Profile owner',mode:'live',live_status:'waiting',live_text_available:true,can_end_live_chat:false,...overrides});
export function ownerCard(id=101,overrides={}){
  return {contract,turn_id:id,revision:1,conversation_id:id+1000,profile_agent_id:7,visitor_message_id:id+2000,
    state:'awaiting_delivery',responder:'waiting',visitor_label:'Visitor '+id,visitor_message:'Public visitor question '+id,
    agent_name:'Profile helper',acknowledged_at_ms:null,pickup_deadline_ms:null,server_now_ms:1800000000000,answer_message_id:0,
    can_claim:true,can_reply:true,can_delegate:true,can_resolve:true,
    history:[{id:id+2000,sender_type:'visitor',message:'Public visitor question '+id,created_at:'2027-01-15 08:00:00'}],
    ...overrides};
}
export const mainChatMarkup='<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside><main class="chat-main"><div class="chat-topbar-actions"></div><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section><div id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></div></main></div></body></html>';
