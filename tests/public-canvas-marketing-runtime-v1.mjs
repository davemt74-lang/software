import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const runtime=fs.readFileSync(new URL('../coding-canvas-runtime-v1.js',import.meta.url),'utf8');
const ui=fs.readFileSync(new URL('../chat-coding-canvas-v1.js',import.meta.url),'utf8');
const commands=fs.readFileSync(new URL('../includes/coding-canvas-public-view-v1.php',import.meta.url),'utf8');
const context={window:{},location:{origin:'https://vp3.example'}};
vm.runInNewContext(runtime,context,{filename:'coding-canvas-runtime-v1.js'});
const host=context.window.VP3CanvasRuntimeV1;
assert.ok(host);
for (const [command,key,url] of [
 ['public-campaigns','publicCampaigns','/vp3/campaign/autumn-sale'],
 ['public-rewards','publicRewards','/vp3/campaign/autumn-sale'],
 ['public-media','publicMedia','/vp3/owner-public'],
]) {
 assert.equal(host.sourceKeys[command],key);
 assert.ok(host.publicSourceKeys.includes(key));
 const good=host.normalizeSource(command,{status:'ready',rows:[{id:'offer-1',title:'Approved',description:'Public',url}]},'/vp3');
 assert.equal(good.payload.rows.length,1);
 assert.equal(good.links.length,1);
 assert.equal(good.links[0].url,url);
 for (const bad of ['//outside.example/evil','https://outside.example/evil','/vp3/private','/vp3/campaign/autumn-sale?token=foo','/vp3/campaign/autumn-sale#hash','/other/campaign/autumn-sale']) {
  const rejected=host.normalizeSource(command,{status:'ready',rows:[{id:'offer-1',title:'Safe',url:bad}]},'/vp3');
  assert.equal(rejected.links.length,0,command+' '+bad);
 }
 const denied=host.normalizeSource(command,{status:'unavailable',rows:[{id:'offer-1',url}]},'/vp3');
 assert.equal(denied.payload.rows.length,0);
 assert.ok(ui.includes("'"+command+"'"));
 assert.ok(commands.includes("'"+command+"'"));
}
console.log('Public Canvas runtime: 3 sources, canonical links, external/hidden routes and unavailable payloads passed');
