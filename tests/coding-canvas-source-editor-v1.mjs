import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {createHash} from 'node:crypto';
const scope={TextEncoder};scope.window=scope;
const parser=fs.readFileSync(new URL('../assets/vendor/acorn-8.19.0.js',import.meta.url),'utf8');
assert.equal(createHash('sha256').update(parser).digest('hex'),'b95392d79457d0357555c5ed3e56302d074b92dff119bcf3b44179500b10e9df','The shipped parser is the reviewed, unmodified upstream distribution');
vm.runInNewContext(parser,scope);
vm.runInNewContext(fs.readFileSync(new URL('../coding-canvas-source-editor-v1.js',import.meta.url),'utf8'),scope);
const {diagnose}=scope.VP3CanvasSourceEditorV1;
const base={html:'<p>Source editor 🎨</p>',css:'p { color: green }',js:'const label="🎨";\nCanvasUI.text("p",label);'};
assert.equal(diagnose(base),null);
assert.equal(diagnose({...base,js:'globalThis.unwantedExecution=true; throw new Error("not run")'}),null);
assert.equal(scope.unwantedExecution,undefined,'Syntax inspection never executes app code');
const error=diagnose({...base,js:'const label="🎨";\nconst next = ;'});
assert.equal(error.language,'js');assert.equal(error.line,2);assert.equal(error.column,14);assert.equal(error.blocking,false);
assert.equal(error.pos,'const label="🎨";\nconst next = '.length);
assert.equal(diagnose({...base,js:'return 1;'}).line,1,'The parser uses the same strict-script scope as the isolated worker');
assert.equal(diagnose({...base,js:'await Promise.resolve();'}).language,'js');
assert.equal(diagnose({...base,js:'async function work(){return await Promise.resolve();}'}),null);
for(const [field,value] of [['html','<img src=x>'],['html','<p onclick="bad()">Bad</p>'],['html','<!doctype html><p>Bad</p>'],['css','p{background:url(https://leak.test)}'],['css','@import "x";'],['html','']]){
  const result=diagnose({...base,[field]:value});assert.equal(result.language,field);assert.equal(result.blocking,true);
}
assert.equal(diagnose({...base,css:'x'.repeat(16001)}).blocking,true);
assert.equal(diagnose({...base,html:'🎨'.repeat(6001)}).blocking,true,'Limits are UTF-8 byte limits');
assert.equal(diagnose({html:'x'.repeat(24000),css:'x'.repeat(16000),js:'x'.repeat(21000)}).blocking,true,'Combined artifact size remains bounded');
const started=Date.now();diagnose({...base,html:'<p'.repeat(10000)});assert.ok(Date.now()-started<1500,'Incomplete repeated markup stays responsive');
console.log('Canvas source diagnostics passed: reviewed parser integrity, exact Unicode-aware error positions, strict runtime syntax, no execution, preview-contract and byte limits.');
