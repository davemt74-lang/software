import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createHash,randomUUID} from 'node:crypto';
import {join} from 'node:path';
import {startMusicPreferencesRuntimeFixture} from './music-preferences-runtime-fixture-v1.mjs';

const f=await startMusicPreferencesRuntimeFixture();
let checks=0;
const equal=(actual,expected,label)=>{assert.deepEqual(actual,expected,label);checks++;};
const ok=(actual,label)=>{assert.ok(actual,label);checks++;};
const accepted=value=>{equal(value.response.status,200,value.raw);equal(value.data.ok,true,value.raw);return value.data;};
const events=raw=>raw.trim().split('\n').filter(Boolean).map(line=>JSON.parse(line));
const voice=async body=>{const response=await f.request('/api/chat-stream-v121.php?agent=7',body);return {...response,events:events(response.raw)};};
const done=value=>{const result=value.events.find(event=>event.type==='done')?.data;ok(result,value.raw);return result;};
const voiceBody=(message,key,extra={})=>({conversation_id:14,agent_context:{user_agent_id:7},csrf_token:'fixture',message,
  music_request_id:key+'-music',idempotency_key:key,...extra});
const pref=async reference=>accepted(await f.preference('read',{selection:{kind:'preference',reference}})).state;
const write=async(reference,preference,expected_revision)=>accepted(await f.preference('set',{
  selection:{kind:'preference',reference},preference,expected_revision,request_id:randomUUID()})).state;
const noActions=reply=>{
  equal(reply.music_chat_v1,undefined);equal(reply.youtube_results,undefined);equal(reply.media_playback,undefined);
  equal(reply.media,[]);ok(!JSON.stringify(reply).includes('autoplay'),'Preference response contains no playback instruction.');
};
const safeContext=(row,label)=>{
  const text=row.context_json||'';
  for(const value of ['History Moonlight','YouTube Song 6','vp300000006','Fixture Channel','resume_seconds','youtube_video','thumbnail_url','autoplay'])
    ok(!text.includes(value),label+' contains no '+value);
};
try{
  const initial=await f.state(),initialPlays=initial.track_play_sessions.length;
  const page=accepted(await f.chat('Show my listening history'));
  equal(page.music_history_v1.items.length,6);
  const ambiguous=accepted(await f.chat('I like that song'));
  equal(ambiguous.tool_receipt.status,'clarification_required');
  equal((await f.preferenceState()).music_preferences_v1.length,0,'Ambiguous deictic feedback does not invent a choice.');
  const typedKey='preferences-typed-like',typedQuery='I like the second song';
  const typed=accepted(await f.chat(typedQuery,{music_request_id:typedKey}));
  ok(typed.music_preferences_v1,'An explicit numbered answer resolves the same history after clarification.');
  equal(typed.music_preferences_v1.action,'feedback');noActions(typed);
  const youtubeReference=typed.music_preferences_v1.reference;
  const liked=await pref(youtubeReference);
  equal([liked.source,liked.source_id,liked.preference],['youtube','vp300000006','like']);
  const firstState=await f.preferenceState();
  equal(firstState.music_preferences_v1.length,1);equal(firstState.music_preference_requests_v1.length,1);
  const persisted=await f.state();
  const row=persisted.chat_messages.find(item=>Number(item.id)===typed.assistant_message_id);
  safeContext(row,'Persisted feedback');
  const context=JSON.parse(row.context_json);
  equal(context.music_preferences_v1.reference,youtubeReference);equal(context.skip_brain_archive,true);
  equal(row.message,'Your current music preference is shown below.');
  equal(persisted.track_play_sessions.length,initialPlays);
  const typedUser=persisted.chat_messages.find(item=>Number(item.id)===typed.user_message_id);
  if(typedUser)equal(JSON.parse(typedUser.context_json).skip_brain_archive,true,'Private feedback user turns stay out of Brain archive.');
  const cleared=accepted(await f.chat('Clear my preference for this song',{music_request_id:'preferences-typed-clear'}));
  equal(cleared.music_preferences_v1.reference,youtubeReference);equal((await pref(youtubeReference)).preference,'neutral');
  const beforeTypedRetry=await f.preferenceState();
  const typedRetry=accepted(await f.chat(typedQuery,{music_request_id:typedKey}));
  equal(typedRetry.music_preferences_v1.reference,youtubeReference);equal((await pref(youtubeReference)).preference,'neutral');
  equal(await f.preferenceState(),beforeTypedRetry,'Old typed Like request cannot restore a cleared preference.');noActions(typedRetry);
  const changedTyped=await f.chat('I dislike the second song',{music_request_id:typedKey});
  ok(changedTyped.response.status>=400,'Typed request identity cannot be reused for a different explicit instruction.');

  const list=accepted(await f.chat('Show my music preferences'));equal(list.music_preferences_v1.action,'list');noActions(list);
  ok(!JSON.stringify(list.music_preferences_v1).includes('items'),'List Chat payload stays a current-read reference.');
  const loaded=accepted(await f.json('/api/chat-v236.php?agent=7',{action:'load',conversation_id:14,csrf_token:'fixture'}));
  const restored=loaded.messages.find(item=>Number(item.id)===typed.assistant_message_id);
  safeContext(restored,'Reopened feedback');
  equal((await f.state()).track_play_sessions.length,initialPlays,'Loading Chat cannot play feedback items.');

  await f.chat('Show my listening history');
  const voiceRequest=voiceBody('I dislike the first song','preferences-voice-dislike');
  const spoken=done(await voice(voiceRequest));equal(spoken.input_mode,'voice');noActions(spoken);
  const nativeReference=spoken.music_preferences_v1.reference;
  const disliked=await pref(nativeReference);
  equal([disliked.source,disliked.source_id,disliked.preference],['vp3','71','dislike']);
  const chatCount=(await f.state()).chat_messages.length,voiceState=await f.preferenceState();
  const voiceRetry=done(await voice(voiceRequest));equal(voiceRetry.music_preferences_v1.reference,nativeReference);noActions(voiceRetry);
  equal((await f.state()).chat_messages.length,chatCount,'Frozen voice retry writes no duplicate Chat turns.');
  equal(await f.preferenceState(),voiceState,'Frozen voice retry changes no preference or request receipts.');
  const cacheName=createHash('sha1').update('voice-chat:user-1:agent-7|'+voiceRequest.idempotency_key).digest('hex')+'.json';
  const cacheText=readFileSync(join(f.root,'private/runtime-v125/idempotency',cacheName),'utf8');
  const cached=JSON.parse(cacheText).result;
  equal(Object.keys(cached.music_preferences_v1).sort(),['action','agent_id','conversation_id','owner_user_id','protocol','reference']);
  equal(cached.music_preferences_requested_conversation_id,14,'Voice cache binds the original requested conversation separately from the allocated conversation.');
  equal(voiceRetry.music_preferences_requested_conversation_id,undefined,'Internal request binding is omitted from the replay response.');
  for(const value of ['History Moonlight','YouTube Song 6','vp300000006','resume_seconds','native_revision','youtube_video'])ok(!cacheText.includes(value),'Voice cache retains no '+value);
  ok((await voice({...voiceRequest,message:'I like the first song'})).events.some(event=>event.type==='error'),'Changed voice text cannot reuse frozen transport identity.');
  ok((await voice({...voiceRequest,conversation_id:15})).events.some(event=>event.type==='error'),'Voice feedback cannot cross conversation boundaries.');
  const resetConversation=await voice({...voiceRequest,conversation_id:0});
  ok(resetConversation.events.some(event=>event.type==='error'),'An existing-Chat voice request cannot be retried as a new-Chat request.');
  equal(resetConversation.events.find(event=>event.type==='error').error,'The music preference response belongs to a different request.');
  ok(!resetConversation.events.some(event=>event.type==='start'||event.type==='done'),'Changed zero conversation identity returns no cached feedback or Chat binding.');
  equal((await f.state()).chat_messages.length,chatCount,'Rejected zero conversation identity creates no Chat turns.');
  equal(await f.preferenceState(),voiceState,'Rejected zero conversation identity cannot mutate the saved choice.');

  // A genuinely new-Chat request retains its original zero id across a frozen retry.
  const newChatRequest=voiceBody('Show my music preferences','preferences-voice-new-chat',{conversation_id:0});
  const newChat=done(await voice(newChatRequest));noActions(newChat);
  equal(newChat.music_preferences_v1.action,'list');
  ok(newChat.conversation_id>0&&newChat.conversation_id!==14,'New-Chat preference read allocates its own private conversation.');
  equal(newChat.music_preferences_v1.conversation_id,newChat.conversation_id);
  const newCacheName=createHash('sha1').update('voice-chat:user-1:agent-7|'+newChatRequest.idempotency_key).digest('hex')+'.json';
  const newCache=JSON.parse(readFileSync(join(f.root,'private/runtime-v125/idempotency',newCacheName),'utf8')).result;
  equal(newCache.music_preferences_requested_conversation_id,0,'New-Chat cache retains zero as the original requested conversation.');
  const newChatState=await f.state();
  const newChatRetry=done(await voice(newChatRequest));noActions(newChatRetry);
  equal([newChatRetry.conversation_id,newChatRetry.assistant_message_id,newChatRetry.replayed],
    [newChat.conversation_id,newChat.assistant_message_id,true],'Original zero-id retry returns the same allocated Chat and message.');
  equal(newChatRetry.music_preferences_requested_conversation_id,undefined);
  equal((await f.state()).chat_messages.length,newChatState.chat_messages.length,'New-Chat retry never duplicates saved turns.');
  equal(await f.preferenceState(),voiceState,'New-Chat read and retry do not create preferences or request receipts.');

  // A direct named request remains playable even when that song is explicitly disliked.
  const exact=accepted(await f.chat('Play "History Moonlight" from VP3'));
  equal(exact.music_chat_v1.items.map(item=>[item.source,item.source_id]),[['vp3','71']]);
  ok(exact.music_chat_v1.autoplay?.action_id,'Explicit named playback keeps the existing MC1 action.');
  equal((await pref(nativeReference)).preference,'dislike');
  equal((await f.state()).track_play_sessions.length,initialPlays,'Preparing an exact play still does not manufacture listening.');

  // An old voice Like/Dislike rehydrates current state after another explicit clear.
  const neutral=await write(nativeReference,'clear',disliked.revision);equal(neutral.preference,'neutral');
  const beforeOldVoice=await f.preferenceState();
  const old=done(await voice(voiceRequest));equal(old.music_preferences_v1.reference,nativeReference);noActions(old);
  equal((await pref(nativeReference)).preference,'neutral');equal(await f.preferenceState(),beforeOldVoice);
  const newTransport=done(await voice({...voiceRequest,idempotency_key:'preferences-voice-dislike-other-transport'}));
  equal(newTransport.music_preferences_v1.reference,nativeReference);equal((await pref(nativeReference)).preference,'neutral');
  equal(await f.preferenceState(),beforeOldVoice,'New voice transport with the same music request also preserves cleared state.');

  await f.control("UPDATE tracks SET title='REVOKED MUSIC PREFERENCE',owner_user_id=2,visibility='private' WHERE id=71");
  const revoked=done(await voice(voiceRequest));noActions(revoked);ok(!JSON.stringify(revoked).includes('REVOKED MUSIC PREFERENCE'));
  const hidden=await pref(nativeReference);equal(hidden.metadata_available,false);equal(hidden.source_id,'');
  await f.control("UPDATE tracks SET title='History Moonlight',owner_user_id=1,visibility='public' WHERE id=71");
  await f.control("INSERT INTO user_agent_data_rules(agent_id,resource_type,resource_id,access_mode) VALUES(7,'knowledge','playback-history','deny')");
  ok((await voice(voiceRequest)).events.some(event=>event.type==='error'),'Current Agent policy blocks voice cache restoration.');
  await f.control("DELETE FROM user_agent_data_rules WHERE agent_id=7 AND resource_id='playback-history'");
  await f.control('UPDATE chat_conversations SET user_id=2 WHERE id=14');
  ok((await voice(voiceRequest)).events.some(event=>event.type==='error'),'Lost conversation ownership blocks preference transport restore.');
  await f.control('UPDATE chat_conversations SET user_id=1 WHERE id=14');

  await f.chat('Show my YouTube listening history');
  const ytBody=voiceBody('I like the second song','preferences-voice-youtube');
  const yt=done(await voice(ytBody));noActions(yt);equal(yt.music_preferences_v1.reference,youtubeReference);
  equal((await pref(youtubeReference)).preference,'like');
  await f.control('UPDATE agent_connection_youtube_results_v1 SET expires_at=1');
  await f.control('UPDATE vp3_youtube_saved_playlists_v1 SET cached_until=1');
  const expired=done(await voice(ytBody));noActions(expired);
  ok(!JSON.stringify(expired).includes('YouTube Song 6'),'Expired provider metadata cannot be restored from spoken feedback.');
  equal((await pref(youtubeReference)).metadata_available,false);
  await f.control("UPDATE agent_connection_youtube_consent_v1 SET policy_version='revoked' WHERE owner_user_id=1");
  const optedOut=done(await voice(ytBody));noActions(optedOut);ok(!JSON.stringify(optedOut).includes('vp300000006'));
  const final=await f.state();
  equal(final.track_play_sessions.length,initialPlays,'Preference reads/writes and voice replays never fabricate listening.');
  equal(final.fixture_model_calls.length,0,'Explicit preference handling does not request a provider/model completion.');
  equal(final.fixture_archive_attempts.length,0,'Private feedback is not copied to Brain memory.');
  equal((await f.preferenceState()).music_deliberate_skips_v1.length,0,'Typed or voice dislikes never fabricate Next observations.');
  console.log('MUSIC_PREFERENCES_RUNTIME_V1=PASS checks='+checks);
}catch(error){console.error(f.logs.slice(-10000));throw error;}
finally{f.close();}
