import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fixture} from './music-file-repair-fixture.mjs';
const f=await fixture();
try{
 let form=await f.form();assert.equal(form.status,200,f.logs);assert.ok(form.revision&&form.csrf,form.html+f.logs);assert.match(form.html,/Legacy song/);assert.match(form.html,/Unscoped song/);assert.match(form.html,/Bass.wav/);assert.doesNotMatch(form.html,/Foreign secret/);
 const context=JSON.parse(f.run("require STONEFELLOW_ROOT.'/includes/music-file-repair.php';echo json_encode(music_file_repair_context(db(),current_user(),'Which song audio files are missing?'));"));assert.equal(context.length,1);assert.doesNotMatch(context[0].text,/Foreign secret/);assert.match(context[0].text,/Read-only file report/);
 const before=f.snapshot();let r=await f.post(form,{user:0});assert.equal(r.status,401);r=await f.post(form,{token:'wrong'});assert.equal(r.status,419);r=await f.post(form,{user:14});assert.equal(r.status,403);r=await f.post(form,{user:13});assert.equal(r.status,403);r=await fetch(f.base+'/api/music-file-repair.php',{headers:{Accept:'application/json'}});assert.equal(r.status,405);
 r=await f.post(form,{bytes:Buffer.from('<html>not audio</html>')});assert.equal(r.status,422);r=await f.post(form,{bytes:null});assert.equal(r.status,422);assert.deepEqual(f.snapshot(),before,'Rejected uploads changed existing records');
 r=await f.post(form);const saved=await r.json();assert.equal(r.status,200,JSON.stringify(saved)+f.logs);assert.equal(saved.track.source_track_id,1);assert.equal(saved.track.catalog_track_id,10);
 const after=f.snapshot();for(const key of ['playlists','history','projects','stems','mixes'])assert.deepEqual(after[key],before[key],key+' changed during master replacement');
 const strip=rows=>rows.map(({audio_path,updated_at,...rest})=>rest);assert.deepEqual(strip(after.tracks),strip(before.tracks));assert.deepEqual(strip(after.catalog),strip(before.catalog));assert.equal(after.tracks[0].audio_path,after.catalog[0].audio_path);assert.ok(fs.existsSync(path.join(f.root,after.tracks[0].audio_path)));assert.equal(after.tracks.length,before.tracks.length);
 r=await f.post(form);assert.equal(r.status,409,'Stale replacement was not rejected');
 form=await f.form();const files=()=>fs.readdirSync(path.join(f.root,'uploads/artist-music/1')).sort();const storedBefore=files();const snap=f.snapshot();f.run(`db()->exec("CREATE TRIGGER fail_audio BEFORE UPDATE OF audio_path ON tracks BEGIN SELECT RAISE(ABORT,'forced failure'); END;");`);r=await f.post(form);assert.equal(r.status,500);assert.deepEqual(f.snapshot(),snap,'Failed transaction changed file links');assert.deepEqual(files(),storedBefore,'Failed transaction left an orphan upload');f.run("db()->exec('DROP TRIGGER fail_audio');");
 form=await f.form('source',2);r=await f.post(form,{kind:'source',id:2});assert.equal(r.status,200);assert.ok(fs.existsSync(path.join(f.root,'uploads/audio/old.wav')),'Usable previous master was deleted');
 form=await f.form('source',3);r=await f.post(form,{kind:'source',id:3});assert.equal(r.status,200,'Authorized unscoped legacy song could not be restored');assert.equal(f.snapshot().tracks.find(t=>t.id===3).workspace_id,null,'Legacy record was reassigned');
 form=await f.form('catalog',11);const count=f.snapshot().tracks.length;r=await f.post(form,{id:11});assert.equal(r.status,200);assert.equal(f.snapshot().tracks.length,count,'Catalog-only restore created a new Studio song');
 f.run("db()->exec('DELETE FROM artist_team_members WHERE member_user_id=12');");form=await f.form();r=await f.post(form,{user:12});assert.equal(r.status,403,'Revoked manager retained replacement authority');
 const notices=JSON.parse(f.run("echo json_encode(db()->query('SELECT * FROM notifications')->fetchAll());"));assert.ok(notices.length>=3);assert.ok(notices.every(n=>n.type==='agent_activity_music_audio_restored'));
 console.log('MUSIC_FILE_REPAIR_API=PASS actual multipart audio, identity and metadata preservation, playlists/history/projects/mixes, legacy and catalog-only songs, stale uploads, rollback cleanup, file retention, authority/CSRF/method and Brain activity');
}finally{f.close();}
