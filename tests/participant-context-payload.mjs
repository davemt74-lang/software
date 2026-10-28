import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync('chat-voice.js','utf8');
const code=source.split('\n').find(line=>line.startsWith('  async function attachContext('));assert.ok(code);
const selected={participant_id:'7',display_name:'David',source:'manual_selection',conversation_note:'Prefers brief replies',authentication_authority:false};
const voice={...selected,source:'local_voice_similarity',confidence:.95};
for(const [label,wasVoice,matched,expected] of [['typed',false,null,selected],['matched voice',true,voice,voice],['uncertain voice',true,null,undefined]]){
 const ctx={window:{VP3VoiceGuard:{takeContext:()=>matched,wasVoiceTurn:()=>wasVoice},VP3ParticipantContinuity:{selectedContext:()=>selected}},AgentContext:{refresh:async()=>({surface:'chat',conversation_id:41})}};
 vm.createContext(ctx);vm.runInContext(code+';globalThis.attach=attachContext;',ctx);
 const result=await ctx.attach({action:'send',message:'How are you?',conversation_id:41});
 assert.deepEqual(result.agent_context.voice_speaker,expected,label);assert.equal(result.agent_context.conversation_id,41);assert.equal(result.action,'send');
}
console.log('PARTICIPANT_CONTEXT_PAYLOAD=PASS (typed, matched voice, uncertain voice)');
