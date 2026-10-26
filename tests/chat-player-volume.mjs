import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync('chat.js','utf8');
const start=source.indexOf('  let playerVolume = 1;');
const end=source.indexOf('  let conversationId =',start);
assert.ok(start>0&&end>start);
for(const [stored,expected] of [[null,1],['',1],['  ',1],['0',0],['0.7',0.7],['bad',1],['-1',0],['2',1]]){
 const sandbox={localStorage:{getItem:()=>stored},PLAYER_VOLUME_KEY:'fixture'};
 vm.createContext(sandbox);vm.runInContext(source.slice(start,end)+'globalThis.result=playerVolume;',sandbox);
 assert.equal(sandbox.result,expected,`Volume preference ${JSON.stringify(stored)}`);
}
const sandbox={localStorage:{getItem(){throw Error('storage unavailable');}},PLAYER_VOLUME_KEY:'fixture'};
vm.createContext(sandbox);vm.runInContext(source.slice(start,end)+'globalThis.result=playerVolume;',sandbox);assert.equal(sandbox.result,1);
console.log('CHAT_PLAYER_VOLUME=PASS missing/empty defaults audible; explicit mute and saved volume retained');
