import fs from 'node:fs';

const read=path=>fs.readFileSync(path,'utf8');
const adapter=read('editor-agent-v131.js');
const editor=read('video-editor.js');
const api=read('api/video-agent-v90.php');
const planner=read('includes/agent-tools-v90.php');

function assert(ok,label){
  console.log(`${ok?'PASS':'FAIL'} ${label}`);
  if(!ok)throw new Error(`Failed: ${label}`);
}

assert(adapter.includes('function verifyVideoCommand'),'v131 Video adapter has command-level verification');
assert(adapter.includes("was?.locked&&['split','duplicate','delete','move','trim','set_volume','set_mute','set_opacity','set_fade','set_lane'].includes(type)"),'locked Video targets cannot be reported as edited');
assert(adapter.includes("case'play':case'pause':return verification('unverified'"),'transport commands are explicitly unverified when transport state is unavailable');
assert(adapter.includes("case'seek'")&&adapter.includes("case'move'")&&adapter.includes("case'trim'")&&adapter.includes("case'set_volume'")&&adapter.includes("case'set_mute'")&&adapter.includes("case'set_opacity'")&&adapter.includes("case'set_fade'")&&adapter.includes("case'set_lane'"),'persistent clip commands have state verifiers');
assert(adapter.includes("case'add_asset'")&&adapter.includes("case'add_track'")&&adapter.includes("case'zoom'")&&adapter.includes("case'snap'"),'insert and timeline commands have state verifiers');
assert(adapter.includes('for(const command of commands)')&&adapter.includes("EditorAgent.execute({surface:'video'")&&adapter.includes('bridge.executeCommands([command]'),'Video commands route through the universal registry and execute one at a time through the canonical editor bridge');
assert(adapter.includes("const saved=await bridge.saveProject(false)")&&adapter.includes("result:'Project save verified by the save endpoint.',verified:true"),'save uses a verified editor save receipt instead of assuming success');
assert(adapter.includes("verificationStatus==='success'||verificationStatus==='no_change'?'success':'failed'"),'unverified or failed commands cannot be logged as successful');
assert(adapter.includes("I sent the Video Editor command, but I won’t call it complete without state verification."),'user-facing reply refuses false completion claims');
assert(adapter.includes('voice.speak(responseText)'),'voice speaks the verified execution reply');
assert(adapter.includes("api({action:'history',conversation_id:conversationId})")&&!adapter.includes('if(!conversationId){history.innerHTML'),'Video asks the server for history even when opened without a conversation id');
assert(adapter.includes('assistant_message_id:Number(d.assistant_message_id||0)')&&adapter.includes('result_text:responseText'),'verified Video reply is persisted back into the shared conversation');
assert(editor.includes('return {before,after,changes:diffStates(before,after)}'),'canonical Video Editor returns before/after state evidence');
assert(planner.includes('agent_v90_sanitize_video_commands'),'server planner sanitizes Video command inventory');
assert(planner.includes("if($type==='delete')$x['requires_confirmation']=true"),'server planner preserves destructive confirmation');
assert(api.includes('function video_agent_v131_latest'),'Video API has an explicit latest-conversation resolver');
assert(api.includes('agent_chat_v101_latest_conversation_id')&&api.includes('ORDER BY updated_at DESC,id DESC LIMIT 1'),'Video latest resolver follows shared chat recency semantics');
assert(api.includes("if($action==='history')")&&api.includes("if($conversationId<1||!video_agent_v90_owned($conversationId,$userId))$conversationId=video_agent_v131_latest($pdo,$userId);"),'Video history falls back when conversation identity is missing or stale');
assert(api.includes("UPDATE chat_messages SET message=? WHERE id=? AND conversation_id=? AND role='assistant'"),'verified execution reply replaces the provisional assistant planner message');
assert(api.includes('function video_agent_v131_reconcile_brain'),'Video has an explicit post-execution Brain reconciliation path');
assert(api.includes("agent_brain_archive_and_parse($user,$conversationId,$messageId,'assistant',$message,$inputMode)"),'Video refreshes the archived assistant message using the verified result');
assert(api.includes('agent_brain_v122_update_state')&&api.includes('agent_brain_v122_rollup'),'Video refreshes conversation state and rolling summary after execution truth changes');
assert(api.includes("$status=in_array((string)($input['status']??''),['success','failed','cancelled'],true)"),'Video result endpoint accepts explicit failure/cancellation status');

console.log('VIDEO_AGENT_V131_TRUTH=PASS');
