import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {join} from 'node:path';
import {startMusicHistoryRuntimeFixture} from './music-history-runtime-fixture-v1.mjs';

const f=await startMusicHistoryRuntimeFixture();
let checks=0;
const equal=(a,b,message)=>{assert.deepEqual(a,b,message);checks++;};
const ok=(a,message)=>{assert.ok(a,message);checks++;};
const accepted=value=>{equal(value.response.status,200,value.raw);equal(value.data.ok,true,value.raw);return value.data;};
const events=raw=>raw.trim().split('\n').filter(Boolean).map(line=>JSON.parse(line));
const voice=async body=>{const response=await f.request('/api/chat-stream-v121.php?agent=7',body);return {...response,events:events(response.raw)};};
const done=value=>{const data=value.events.find(event=>event.type==='done')?.data;ok(data,value.raw);return data;};
const voiceBody=(message,key,extra={})=>({conversation_id:14,user_agent_id:7,csrf_token:'fixture',message,
  music_request_id:key+'-music',idempotency_key:key,reminder_timezone:'UTC',...extra});
try{
  const initial=await f.state(),initialPlays=initial.track_play_sessions.length;
  const typed=accepted(await f.chat('Show my listening history'));
  equal(typed.music_history_v1.items.length,6,'Typed Chat uses the shared retained native/YT/child reader');
  equal(typed.media,[],'History cannot append fresh catalog recommendations');
  equal(typed.music_chat_v1,undefined);equal(typed.youtube_results,undefined);equal(typed.media_playback,undefined);
  equal(typed.music_history_v1.source_message_id,typed.assistant_message_id,'Exact actual assistant turn binds history');
  const refs=typed.music_history_v1.items.map(item=>item.entry_ref);
  equal(new Set(refs).size,6,'Each repeat/child receipt keeps its own ref');
  equal(typed.music_history_v1.items[0].source_id,typed.music_history_v1.items[3].source_id,'Repeated native song remains two ordered entries');
  equal(typed.music_history_v1.items[1].source_id,typed.music_history_v1.items[2].source_id,'Ordinary and child YouTube plays are separate receipts');
  const persisted=await f.state();
  const row=persisted.chat_messages.find(row=>Number(row.id)===typed.assistant_message_id);
  const context=JSON.parse(row.context_json);
  equal(context.music_history_v1.entry_refs,refs,'Persisted refs retain exact display ordering');
  for(const value of ['History Moonlight','YouTube Song 6','Fixture Channel','resume_seconds','youtube_video','thumbnail_url'])
    ok(!row.context_json.includes(value),'History context contains no copied '+value);
  ok(!row.message.includes('Moonlight'),'Durable assistant message is a generic receipt');
  equal(persisted.track_play_sessions.length,initialPlays,'Reading history creates no native listen');
  const loaded=accepted(await f.json('/api/chat-v236.php?agent=7',{action:'load',conversation_id:14,csrf_token:'fixture'}));
  const historical=loaded.messages.find(row=>Number(row.id)===typed.assistant_message_id);
  const stored=typeof historical.context_json==='string'?JSON.parse(historical.context_json):historical.context;
  equal(stored.music_history_v1.entry_refs,refs,'Actual Chat load retains only exact opaque snapshot');

  const ambiguous=accepted(await f.chat('Play that one'));
  equal(ambiguous.tool_receipt.status,'clarification_required');equal(ambiguous.music_chat_v1,undefined);
  const resumed=accepted(await f.chat('Resume the first one',{music_request_id:'typed-native-resume'}));
  equal(resumed.music_chat_v1.items.length,1);equal(resumed.music_chat_v1.items[0].source_id,'71');
  equal(resumed.music_chat_v1.history_operation,'resume');ok(resumed.music_chat_v1.autoplay?.action_id);
  const native=accepted(await f.music('resolve',{result_id:resumed.music_chat_v1.result_id,revision:resumed.music_chat_v1.revision,index:0}));
  equal(native.media_playback.operation,'resume');equal(native.media_playback.position_seconds,22);
  equal(native.media_playback.history_ref,f.seed.refs.native,'Resume references the first historical receipt, not generic last position');
  const saved=accepted(await f.music('playlist_create',{result_id:resumed.music_chat_v1.result_id,title:'History Road',request_key:'runtime-history-list'})).music_chat_v1;
  await f.chat('Show my listening history');
  const child=accepted(await f.chat('Resume the third entry',{music_request_id:'typed-child-resume'}));
  const childResolved=accepted(await f.music('resolve',{result_id:child.music_chat_v1.result_id,revision:child.music_chat_v1.revision,index:0}));
  equal(childResolved.resume_ms,15000,'Exact child receipt position differs from ordinary same-video12s');
  equal(childResolved.youtube_results.videos[0].video_id,'vp300000006');
  equal((await f.state()).track_play_sessions.length,initialPlays,'Preparing native/YT resume is not listening');

  await f.chat('Show my listening history');
  const added=accepted(await f.chat('Add the second song to my History Road playlist',{music_request_id:'runtime-history-add'}));
  equal(added.music_chat_v1.playlist.id,saved.playlist.id,'Conversational addition uses exact existing private target');
  equal(added.music_chat_v1.items.map(item=>item.source),['vp3','youtube']);
  equal(added.music_chat_v1.autoplay,undefined,'Playlist addition stays quiet');
  const addedRetry=accepted(await f.chat('Add the second song to my History Road playlist',{music_request_id:'runtime-history-add'}));
  equal(addedRetry.music_chat_v1.result_id,added.music_chat_v1.result_id);equal(addedRetry.tool_receipt.changes_applied,false);
  const ui=accepted(await f.history('query',{source:'vp3',limit:2})).music_history_v1;
  const prepared=accepted(await f.history('selection',{source_message_id:ui.source_message_id,revision:ui.revision,entry_ref:ui.items[0].entry_ref,
    operation:'add_to_playlist',request_id:'ui-history-picker'}));
  equal(prepared.music_chat_v1.autoplay,undefined);equal(prepared.music_chat_v1.source_message_id,prepared.assistant_message_id);
  const uiAdd=accepted(await f.chat('Add this song to my History Road playlist'));
  equal(uiAdd.music_chat_v1.playlist.id,saved.playlist.id,'Typed add-this recognizes a selection prepared through owner UI');
  equal(uiAdd.music_chat_v1.playlist_changed,false,'Existing identical song is a verified no-change');

  const body=voiceBody('Show my listening history','history-voice-query');
  const first=done(await voice(body));equal(first.input_mode,'voice');equal(first.music_history_v1.items.map(item=>item.entry_ref),refs);
  ok(first.answer.includes('History Moonlight')&&first.answer.includes('YouTube Song 6'),'Live factual voice answer uses current exact receipt titles');
  equal(first.media,[]);equal(first.music_chat_v1,undefined);equal(first.youtube_results,undefined);equal(first.media_playback,undefined);
  const countBefore=(await f.state()).chat_messages.length;
  const retry=done(await voice(body));equal(retry.music_history_v1.replayed,true);equal(retry.music_history_v1.source_message_id,first.assistant_message_id);
  equal((await f.state()).chat_messages.length,countBefore,'Same voice transport rehydrates without another Chat turn');
  const cacheName=createHash('sha1').update('voice-chat:user-1:agent-7|'+body.idempotency_key).digest('hex')+'.json';
  const cacheText=readFileSync(join(f.root,'private/runtime-v125/idempotency',cacheName),'utf8');
  const cache=JSON.parse(cacheText).result;
  equal(Object.keys(cache.music_history_v1).sort(),['agent_id','conversation_id','owner_user_id','protocol','revision','source_message_id','version']);
  for(const value of ['History Moonlight','YouTube Song 6','Fixture Channel','resume_seconds','entry_refs'])ok(!cacheText.includes(value),'Voice cache retains no '+value);
  ok((await voice({...body,message:'Show my VP3 history'})).events.some(event=>event.type==='error'),'Changed text cannot reuse transport identity');
  ok((await voice({...body,reminder_timezone:'America/New_York'})).events.some(event=>event.type==='error'),'Changed timezone cannot reuse history transport identity');
  ok((await voice({...body,conversation_id:15})).events.some(event=>event.type==='error'),'History replay cannot move to sibling Chat');

  await f.control("UPDATE tracks SET title='Current Moonlight' WHERE id=71");
  const fresh=done(await voice(body));equal(fresh.music_history_v1.items[0].title,'Current Moonlight','Cached voice rereads current metadata');
  ok(!JSON.stringify(fresh).includes('History Moonlight'),'Historical provider/catalog title is not copied back from cache');
  await f.control("UPDATE tracks SET title='REVOKED SECRET',owner_user_id=2,visibility='private' WHERE id=71");
  const revoked=done(await voice(body));equal(revoked.music_history_v1.items[0].play_available,false);
  equal(revoked.music_history_v1.items[0].source_id,'');ok(!JSON.stringify(revoked).includes('REVOKED SECRET'));
  await f.control("UPDATE tracks SET title='History Moonlight',owner_user_id=1,visibility='public' WHERE id=71");
  await f.control("INSERT INTO user_agent_data_rules(agent_id,resource_type,resource_id,access_mode) VALUES(7,'knowledge','playback-history','deny')");
  ok((await voice(body)).events.some(event=>event.type==='error'),'Current Agent history denial blocks transport restore');
  await f.control("DELETE FROM user_agent_data_rules WHERE agent_id=7 AND resource_id='playback-history'");
  await f.history('query',{source:'vp3',source_message_id:first.assistant_message_id,revision:first.music_history_v1.revision});
  ok((await voice(body)).events.some(event=>event.type==='error'),'A changed bound page cannot silently change cached ordinals');

  const voicePage=done(await voice(voiceBody('Show my listening history','voice-before-resume')));
  const resumeBody=voiceBody('Resume the first one','history-voice-resume');
  const voiceResume=done(await voice(resumeBody));equal(voiceResume.music_chat_v1.items[0].source_id,'71');
  const resolved=accepted(await f.music('resolve',{result_id:voiceResume.music_chat_v1.result_id,revision:voiceResume.music_chat_v1.revision,index:0}));
  equal(resolved.media_playback.position_seconds,22);equal(resolved.media_playback.history_ref,voicePage.music_history_v1.items[0].entry_ref);
  const voiceResumeReplay=done(await voice(resumeBody));equal(voiceResumeReplay.music_chat_v1.result_id,voiceResume.music_chat_v1.result_id);
  equal(voiceResumeReplay.music_chat_v1.autoplay,undefined,'Transport replay never reissues playback');
  ok((await voice({...resumeBody,reminder_timezone:'Pacific/Auckland'})).events.some(event=>event.type==='error'),'History selection transport also binds timezone');
  const newTransport=done(await voice({...resumeBody,idempotency_key:'history-voice-resume-other-transport'}));
  equal(newTransport.music_chat_v1.result_id,voiceResume.music_chat_v1.result_id,'Request receipt survives a new voice transport');
  equal(newTransport.music_chat_v1.autoplay,undefined);
  await f.control('UPDATE track_play_sessions SET user_id=2 WHERE id=?',[f.seed.native]);
  const clearedSelection=done(await voice(resumeBody));
  equal(clearedSelection.music_chat_v1.items[0].available,false,'Cached selection rechecks ownership of its exact historical receipt');
  equal(clearedSelection.music_chat_v1.items[0].source_id,'');
  ok(!JSON.stringify(clearedSelection).includes('History Moonlight'),'A playable catalog item cannot resurrect removed history metadata');
  await f.control('UPDATE track_play_sessions SET user_id=1 WHERE id=?',[f.seed.native]);

  const date=accepted(await f.chat('What did I listen to last night?',{reminder_timezone:'America/New_York'})).music_history_v1;
  ok(date.filters.from_at&&date.filters.until_at);equal(date.filters.from,null);equal(date.filters.to,null);
  equal(date.timezone,'America/New_York');ok(date.window_label.includes('18:00'),'Visible night window is explicit');
  const ytBody=voiceBody('Show my YouTube history','history-voice-youtube');
  const yt=done(await voice(ytBody));equal(yt.tool_receipt.capability,'music.history.read');
  equal(yt.music_history_v1.items.map(item=>item.resume_seconds),[12,15],'Voice preserves ordinary and MC1child receipt positions');
  equal(yt.youtube_results,undefined,'History voice never switches to a fresh provider search card');
  await f.control('UPDATE agent_connection_youtube_results_v1 SET expires_at=1');
  await f.control('UPDATE vp3_youtube_saved_playlists_v1 SET cached_until=1');
  const expired=done(await voice(ytBody));
  equal(expired.music_history_v1.items.map(item=>item.entry_ref),yt.music_history_v1.items.map(item=>item.entry_ref),'Expired source restore preserves exact old ordinals');
  ok(expired.music_history_v1.items.every(item=>!item.play_available&&!item.resume_available),'Expired voice history exposes no playback/resume');
  ok(!JSON.stringify(expired).includes('YouTube Song 6'),'Expired provider metadata is absent from card and spoken answer');
  const final=await f.state();equal(final.track_play_sessions.length,initialPlays,'No query, selection or transport replay manufactures a listen');
  equal(final.fixture_model_calls.length,0,'History factual answers do not consult a model/provider search');
  equal(final.fixture_archive_attempts.length,0,'Private history is not copied into Brain');
  console.log('MUSIC_HISTORY_RUNTIME_V1=PASS checks='+checks);
}catch(error){console.error(f.logs.slice(-9000));throw error;}
finally{f.close();}
