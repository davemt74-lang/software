import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const scope={window:{}};
vm.runInNewContext(fs.readFileSync(new URL('../coding-canvas-diff-v1.js',import.meta.url),'utf8'),scope);
const {compare}=scope.window.VP3CanvasDiffV1;
function roundtrip(before,after){
  const result=compare(before,after);
  const old=result.chunks.filter(c=>c.type!=='add').flatMap(c=>c.lines).join('\n');
  const next=result.chunks.filter(c=>c.type!=='remove').flatMap(c=>c.lines).join('\n');
  assert.equal(old,before);assert.equal(next,after);
  return result;
}
const change=roundtrip('title\nold\nfooter','title\nnew\nextra\nfooter');
assert.equal(change.added,2);assert.equal(change.removed,1);
assert.equal(roundtrip('same','same').added,0);
assert.equal(roundtrip('same','same').removed,0);
roundtrip('','one');roundtrip('one','');roundtrip('','');
roundtrip('a\na\nb\na','a\nb\na\na');
roundtrip('<script>parent.secret()</script>','<img src=x onerror=bad()>');
roundtrip('日本語\n🎨\n','日本語\nCanvas\n🎨\n');
for(let i=0;i<25;i++){
  const lines=Array.from({length:20},(_,n)=>'line '+((n+i)%7));
  const next=[...lines];next.splice(i%20,1,'added '+i,'next '+i);
  const result=roundtrip(lines.join('\n'),next.join('\n'));
  assert.equal(result.added,2);assert.equal(result.removed,1);
}
const started=Date.now();
const large=roundtrip(Array.from({length:4500},(_,i)=>'old '+i).join('\n'),Array.from({length:4500},(_,i)=>'new '+i).join('\n'));
assert.equal(large.coarse,true);assert.equal(large.added,4500);assert.equal(large.removed,4500);
assert.ok(Date.now()-started<3000,'Large rewrites must stay responsive');
assert.throws(()=>compare('x'.repeat(60001),''));
assert.throws(()=>compare({},''));
console.log('Canvas diff passed: exact reconstruction, insertions/removals, repeated lines, empty/Unicode/plain-text source and bounded large rewrites.');
