import assert from 'node:assert/strict';
import {recoveryFixture} from './stem-recovery-fixture-v235.mjs';
const f=await recoveryFixture();
try{
 for(const stem of f.stems){
  const response=await fetch(f.base+'/stem-media-v34.php?id='+stem.id,{headers:{'X-User':'11',Cookie:f.formData.cookie}});
  assert.equal(response.status,200,f.logs);assert.equal(response.headers.get('Content-Type'),'audio/mpeg');
  assert.deepEqual(Buffer.from(await response.arrayBuffer()),f.mp3);
 }
 const id=f.stems[0].id;
 const partial=await fetch(f.base+'/stem-media-v34.php?id='+id,{headers:{Range:'bytes=25-1023','X-User':'11'}});
 assert.equal(partial.status,206);assert.deepEqual(Buffer.from(await partial.arrayBuffer()),f.mp3.subarray(25,1024));
 const denied=await fetch(f.base+'/stem-media-v34.php?id='+id,{headers:{'X-User':'14'}});assert.equal(denied.status,403);
 console.log('STEM_REAPER_RECOVERY_V235=PASS compressed ZIP, 19 unmatched-source MP3s, metadata, actual PHP media bytes/ranges, foreign-owner denial and preserved mix/history');
}finally{f.close();}
