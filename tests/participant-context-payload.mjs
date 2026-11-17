import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync('chat-voice.js','utf8');
const code=source.match(/^  async function attachContext\([^)]*\)\{[\s\S]*?^  \}/m)?.[0];
assert.ok(code);
const selected={participant_id:'7',display_name:'David',source:'manual_selection',conversation_note:'Prefers brief replies',authentication_authority:false};
const voice={...selected,source:'local_voice_similarity',confidence:.95};
for(const [label,wasVoice,matched,expected,proactive] of [
 ['typed',false,null,selected],['matched voice',true,voice,voice],['uncertain voice',true,null,undefined],
 ['checked proactive reply',true,null,voice,{replySpeaker:voice}],
 ['unmatched proactive reply',false,voice,undefined,{replySpeaker:null}],
]){
 let guardReads=0;
 const ctx={window:{VP3VoiceGuard:{takeContext:()=>{guardReads++;return matched;},wasVoiceTurn:()=>wasVoice},VP3ParticipantContinuity:{selectedContext:()=>selected}},AgentContext:{refresh:async()=>({surface:'chat',conversation_id:41})}};
 vm.createContext(ctx);vm.runInContext(code+';globalThis.attach=attachContext;',ctx);
 const result=await ctx.attach({action:'send',message:'How are you?',conversation_id:41},proactive);
 assert.deepEqual(result.agent_context.voice_speaker,expected,label);assert.equal(result.agent_context.conversation_id,41);assert.equal(result.action,'send');
 if(proactive)assert.equal(guardReads,0,'A proactive reply retains only its own checked speaker context');
}
console.log('PARTICIPANT_CONTEXT_PAYLOAD=PASS (typed, matched/uncertain voice, checked/unmatched proactive replies)');
