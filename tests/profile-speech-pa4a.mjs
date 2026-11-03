import assert from 'node:assert/strict';
import {speechFixture,speechItem} from './fixtures/profile-speech-pa4a.mjs';
import {FixtureCustomEvent} from './fixtures/profile-calls-pa3c.mjs';
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
const sourceOnly=f=>f.requests.filter(r=>r.path==='/api/profile-speech-pa4a.php').every(r=>!['text','message','voice_id','provider_url','audio_url'].some(key=>Object.hasOwn(r,key)));
const click=async(f,id=81)=>{f.speech(id).querySelector('[data-speech-action=play]').click();await f.settle();};
const sent=f=>f.requests.filter(r=>r.action==='doorbell_reply');
{
  const f=await speechFixture();const echo=[];f.window.addEventListener('stonefellow:agent-proactive-speech',event=>echo.push(event.detail));
  check(f.window.STONEFELLOW_CHAT_CONTINUITY!==f.window.STONEFELLOW_CHAT_CONTINUITY_V87&&typeof f.window.STONEFELLOW_CHAT_CONTINUITY.pendingVoiceSubmit==='function','actual voice IIFE replaces primary continuity before speech runs');
  check(f.requests.filter(r=>r.action==='owner_state'||r.action==='owner_delivery').every(r=>r.chat_conversation_id===42&&r.chat_agent_id===7),'speech uses canonical private Chat42 and Agent7 despite voice-layer overwrite');
  check(f.thread.querySelector('[data-chat-message-id="981"]')?.dataset.profileSpeechItem==='81','actual Chat mount binds the server speech marker to its private message');
  check(f.requests.every(r=>r.action!=='owner_claim')&&f.audio.length===0,'passive owner-directed speech item does not synthesize without automatic permission or Play');
  await f.refresh();check(f.thread.querySelectorAll('[data-speech-item="81"]').length===1,'polling reuses the exact mounted speech card');
  await click(f);check(f.audio.length===1&&!f.audio[0].paused&&f.providerCalls===1,'explicit Play obtains a source-bound claim, audio and permit before playback');
  check(sourceOnly(f)&&!JSON.stringify(f.requests).includes('Public message for the owner.'),'browser speech requests never submit source text or provider voice choices');
  const report=f.requests.find(r=>r.action==='owner_report');check(report.status==='playing'&&report.item_id===81&&report.chat_message_id===981&&/^[a-f0-9]{32}$/.test(report.play_token),'actual playing event reports the exact private delivery and permit');
  check(!JSON.stringify([...f.local.values()]).includes('tttt')&&f.urls.size===1,'speech ticket and memory audio URL do not enter persistent browser storage');
  f.audio[0].finish();await f.settle();check(f.urls.size===0&&f.requests.some(r=>r.action==='owner_report'&&r.status==='ended'),'ended media revokes its Blob URL and reports only client playback evidence');
  check(echo.length===2&&echo[0].state==='start'&&echo[1].state==='end'&&echo.every(event=>event.source==='profile-speech-pa4a'),'actual voice IIFE receives matched proactive echo lifecycle without stopping its own visitor playback');
  await click(f);const claims=f.requests.filter(r=>r.action==='owner_claim');check(f.providerCalls===1&&f.audio.length===2&&claims.length===2&&claims[0].request_id!==claims[1].request_id,'explicit terminal replay uses a new cache-linked claim and one new permit without another synthesis');await f.stop();
}
{
  const f=await speechFixture();const held=f.hold('owner_audio');void click(f);await f.settle();held.work.reject(new Error('Lost completed audio response'));await f.settle();await f.refresh();await click(f);
  const claims=f.requests.filter(r=>r.action==='owner_claim');check(claims.length===2&&claims[0].request_id===claims[1].request_id&&f.providerCalls===1&&!f.audio[0].paused,'uncertain nonterminal audio retry retains the original claim and reuses the completed artifact');await f.stop();
}
{
  const f=await speechFixture({items:[speechItem({id:82,kind:'arrival',public_conversation_id:0,turn_id:0,visitor_message_id:0,automatic_allowed:true,display_text:'A visitor opened your Profile.'})]});
  check(f.requests.filter(r=>r.action==='owner_claim').length===1&&f.audio.length===1,'eligible fresh arrival can announce once without inventing a public turn');
  await f.refresh();await f.advance(5000);check(f.requests.filter(r=>r.action==='owner_claim').length===1,'repeated state and delivery polling cannot automatically repeat the arrival');
  check(f.thread.querySelector('[data-profile-speech-item="82"]').dataset.profileSpeechConversation==='0','page-only arrival retains its honest zero public conversation marker');await f.stop();
}
{
  const f=await speechFixture();f.setGestureRequired(true);const echo=[];f.window.addEventListener('stonefellow:agent-proactive-speech',event=>echo.push(event.detail));
  const button=f.speech(81).querySelector('[data-speech-action=play]');await f.gestureClick(button);
  check(f.audio[0].paused&&f.urls.size===1&&button.textContent==='Tap to play'&&!button.disabled,'a fresh element prepared after network work retains its audio and offers a direct tap');
  check(!f.requests.some(r=>r.action==='owner_report')&&echo.length===0,'an unplayed gesture-blocked grant neither reports playing nor renews permission or attaches speech echo');
  const element=f.audio[0],before=f.requests.filter(r=>['owner_claim','owner_audio','owner_play'].includes(r.action)).length,mark=f.mediaEvents.length;
  await f.gestureClick(button);const next=f.mediaEvents.slice(mark);
  check(next[0]?.type==='play'&&next[0].gesture===true&&next[0].element===element,'the new tap synchronously plays the same prepared element before any fetch');
  check(!element.paused&&f.audio.length===1&&f.providerCalls===1&&f.requests.filter(r=>['owner_claim','owner_audio','owner_play'].includes(r.action)).length===before,'direct tap reuses the exact Blob and permit without another claim, download or synthesis');
  check(f.requests.some(r=>r.action==='owner_report'&&r.status==='playing')&&echo.length===1,'playing and speech echo begin only when the prepared element actually starts');await f.stop();
}
{
  const f=await speechFixture();f.setGestureRequired(true);const button=f.speech(81).querySelector('[data-speech-action=play]');await f.gestureClick(button);const original=f.audio[0];await f.advance(11000);
  check(original.paused&&original.playCalls===1&&f.urls.size===0&&f.speech(81).textContent.includes('permission expired'),'an unplayed prepared element expires under its original short permission without renewal');
  check(f.requests.filter(r=>r.action==='owner_claim').length===1&&f.providerCalls===1&&!f.requests.some(r=>r.action==='owner_report'&&r.status==='playing'),'permission expiry performs no automatic synthesis or audible-playback claim');
  await f.gestureClick(button);check(f.audio.length===2&&f.audio[1].paused&&button.textContent==='Tap to play'&&f.providerCalls===1,'explicit Play after expiry obtains cached reauthorization and prepares a fresh tap without synthesizing');
  await f.gestureClick(button);check(!f.audio[1].paused&&original.playCalls===1,'only a new current permit can start after the previous prepared element expired');await f.stop();
}
for(const interruption of ['call','context','stop']){
  const f=await speechFixture();f.setGestureRequired(true);await f.gestureClick(f.speech(81).querySelector('[data-speech-action=play]'));
  if(interruption==='call')await f.call(true);else if(interruption==='context')await f.changeContext();else{f.window.VP3ProfileSpeechPa4a.stop();await f.settle();}
  check(f.audio[0].paused&&f.audio[0].playCalls===1&&f.urls.size===0&&!f.requests.some(r=>r.action==='owner_report'&&r.status==='playing'),interruption+' discards gesture-blocked prepared audio without starting it');await f.stop();
}
{
  const f=await speechFixture();f.setGestureRequired(true);const button=f.speech(81).querySelector('[data-speech-action=play]');await f.gestureClick(button);f.failures.set('owner_report',{status:503});await f.advance(11000);
  check(button.disabled&&f.urls.size===0,'lost terminal report keeps the expired local grant stopped while the server still owns its short lease');
  await f.advance(21000);f.failures.delete('owner_report');await f.refresh();await f.gestureClick(button);
  const claims=f.requests.filter(r=>r.action==='owner_claim');check(claims.length===2&&claims[0].request_id!==claims[1].request_id&&f.providerCalls===1,'canonical expired-grant projection enables a new explicit cache-linked request after a lost terminal report');
  await f.gestureClick(button);check(!f.audio[1].paused&&f.audio[0].playCalls===1,'cached reauthorization never reuses the expired one-play permission');await f.stop();
}
for(const change of ['missing','label','text','attempt','terminal']){
  const f=await speechFixture({scopedOnly:true});f.setGestureRequired(true);await f.gestureClick(f.card(101).querySelector('[data-speech-action=visitor-play]'));
  if(change==='missing')f.records.delete(81);
  if(change==='label')f.records.get(81).visitor_label='Anonymous visitor';
  if(change==='text')f.records.get(81).display_text='A visitor opened your Profile.';
  if(change==='attempt')f.attempts.set(2,{id:2,itemId:81,ready:true,playback:'granted',playUntil:f.clock+30000});
  if(change==='terminal')f.attempts.get(1).playback='interrupted';
  await f.refresh();check(f.audio[0].paused&&f.audio[0].playCalls===1&&f.urls.size===0,'fresh '+change+' projection cancels prepared audio even for an explicitly selected visitor card');await f.stop();
}
{
  const f=await speechFixture();f.setGestureRequired(true);const held=f.hold('owner_state');void f.refresh();await f.settle();await f.gestureClick(f.speech(81).querySelector('[data-speech-action=play]'));held.work.resolve();await f.settle();
  check(f.urls.size===1&&f.speech(81).querySelector('[data-speech-action=play]').textContent==='Tap to play','a state read begun before the grant cannot invalidate the later exact prepared permit');await f.stop();
}
{
  const f=await speechFixture();await click(f);f.audio[0].onerror();await f.settle();
  check(f.audio[0].paused&&f.urls.size===0&&f.speech(81).textContent.includes('Audio playback failed')&&f.speech(81).textContent.includes('Public message for the owner.'),'media decode failure stops and releases audio while retaining the visitor text');
  check(f.requests.some(r=>r.action==='owner_report'&&r.status==='blocked')&&!f.requests.some(r=>r.action==='owner_report'&&r.status==='ended'),'media decode failure records blocked playback without claiming completion');await f.stop();
}
{
  const f=await speechFixture();await click(f);await f.advance(45000);
  check(!f.audio[0].paused&&f.requests.filter(r=>r.action==='owner_report'&&r.status==='playing').length>=5,'authorized long audio continues beyond the initial30s through fresh bounded playback reports');
  const renewals=f.requests.filter(r=>r.action==='owner_report'&&r.status==='playing');check(renewals.every((report,index)=>report.report_sequence===index+1)&&new Set(renewals.map(r=>r.request_id)).size===renewals.length&&new Set(renewals.map(r=>r.play_token)).size===1,'renewals use monotonic report sequences for one play permit and no replay');
  f.failures.set('owner_report',{status:403,code:'PROFILE_SPEECH_REVOKED'});await f.advance(8500);
  check(f.audio[0].paused&&f.urls.size===0,'remote source or call revocation stops local audio at the failed fresh authorization');await f.stop();
}
{
  const f=await speechFixture();await click(f);const held=f.hold('owner_report');await f.advance(8200);check(Boolean(held.work),'fixture holds a real periodic playback permission response');
  await f.advance(2200);check(f.audio[0].paused,'missing renewal stops media at the locally anchored10s authorization deadline');
  held.work.resolve();await f.settle();check(f.audio[0].paused&&f.audio[0].playCalls===1,'late renewal cannot revive stopped audio');await f.stop();
}
{
  const f=await speechFixture();const held=f.hold('owner_play');void click(f);await f.settle();check(Boolean(held.work),'fixture holds the actual play permit after buffered audio');await f.advance(31000);held.work.resolve();await f.settle();
  check(f.audio[0].playCalls===0&&f.urls.size===0,'delayed play permit consumes network time and cannot start beyond its original server deadline');await f.stop();
}
for(const interruption of ['call','capture','hidden','context','other_media']){
  const f=await speechFixture();await click(f);
  if(interruption==='call')await f.call(true);
  if(interruption==='capture'){f.window.StonefellowVoiceLeaseV122.acquireCapture('other-recorder');await f.advance(200);}
  if(interruption==='hidden')await f.hidden();
  if(interruption==='context')await f.changeContext();
  if(interruption==='other_media'){const event=new Event('play');Object.defineProperty(event,'target',{value:f.document.createElement('video')});f.document.dispatchEvent(event);await f.settle();}
  check(f.audio[0].paused&&f.urls.size===0,interruption+' interruption stops only visitor audio and revokes its URL');
  check(f.requests.filter(r=>r.action==='owner_claim').length===1,interruption+' interruption never automatically synthesizes a replacement');await f.stop();
}
{
  const f=await speechFixture();const held=f.hold('owner_audio');void click(f);await f.settle();await f.call(true);held.work.resolve();await f.settle();
  check(f.audio.length===0&&f.urls.size===0,'late buffered audio after accepted call is discarded before creating a player');await f.stop();
}
{
  const f=await speechFixture();f.window.StonefellowVoiceLeaseV122.acquireCapture('existing-recorder');await click(f);
  check(!f.requests.some(r=>r.action==='owner_claim')&&f.window.StonefellowVoiceLeaseV122.owns(),'speech yields to existing capture without acquiring, releasing or replacing its lease');await f.stop();
}
{
  const f=await speechFixture();const item=f.records.get(81);item.display_text='<img src=x onerror=bad()> Visitor text';await f.refresh();
  check(f.speech(81).querySelectorAll('img').length===0&&f.speech(81).textContent.includes('<img'),'public message content is literal text, never HTML or a private Chat instruction');await f.stop();
}
{
  const f=await speechFixture({privateDraft:'PRIVATE BEFORE CAPTURE'});await f.arm();
  check(f.recognizers.length===0&&f.input.value==='PRIVATE BEFORE CAPTURE','a pre-existing private draft prevents borrowed visitor voice capture');
  check(!f.card(101).querySelector('textarea').value&&sent(f).length===0,'pre-existing private text is never copied or sent to a visitor');await f.stop();
}
{
  const f=await speechFixture();const other=f.card(202).querySelector('textarea');other.value='OTHER VISITOR DRAFT';other.dispatchEvent(new Event('input'));await f.arm();
  check(f.window.VP3ConversationControls.isEnabled()&&f.recognizers.length===1,'explicit selected-card action borrows the existing responseWindow capture');
  const text='I can meet you at the front door in five minutes.';await f.recognize(text);await f.advance(2200);
  check(f.window.STONEFELLOW_CHAT_VOICE.submits===1&&f.card(101).querySelector('textarea').value===text,'actual fresh TRANSCRIPT_SUBMIT stages only the selected exact visitor draft');
  check(f.input.value===''&&other.value==='OTHER VISITOR DRAFT'&&sent(f).length===0,'proven speech clears only its empty borrowed composer and never sends or changes another visitor draft');
  check(!f.requests.some(r=>r.action==='send'),'exact staged speech is cancelled before the ordinary private Chat submit');
  f.card(101).querySelector('form').requestSubmit();await f.settle();const reply=sent(f)[0];
  check(sent(f).length===1&&reply.turn_id===101&&reply.conversation_id===1101&&reply.message===text,'only explicit Send uses the canonical owner action with the selected public thread');await f.advance(500);
  check(!f.window.VP3ConversationControls.isEnabled(),'review lifecycle releases borrowed voice capture without changing the saved voice preference');await f.stop();
}
for(const event of ['beforeinput','paste','input']){
  const f=await speechFixture();await f.arm();await f.type('PRIVATE DURING CAPTURE',event);await f.advance(2200);
  check(!f.window.VP3ConversationControls.isEnabled()&&f.input.value==='PRIVATE DURING CAPTURE',event+' private edit cancels borrowing and preserves the private draft');
  check(!f.card(101).querySelector('textarea').value&&sent(f).length===0,event+' private edit is never staged or sent to a visitor');await f.stop();
}
{
  const f=await speechFixture();let accept;f.window.VP3VoiceGuard={check:()=>new Promise(resolve=>accept=resolve),isKnownOnly:()=>false,detach(){}};await f.arm();await f.recognize('This voice reply is waiting on authorization.');await f.advance(1900);
  check(typeof accept==='function','fixture reaches actual asynchronous voice guard before submit');await f.type('PRIVATE WHILE VOICE GUARD WAITS');accept({allowed:true});await f.settle();await f.advance(500);
  check(f.window.STONEFELLOW_CHAT_VOICE.submits===0&&f.input.value==='PRIVATE WHILE VOICE GUARD WAITS'&&!f.card(101).querySelector('textarea').value,'late voice authorization cannot append or relay a newly edited private draft');await f.stop();
}
{
  const f=await speechFixture();await f.arm();f.input.value='ORDINARY PRIVATE SUBMIT';f.form.requestSubmit();await f.settle();
  check(f.requests.some(r=>r.action==='send'&&r.message==='ORDINARY PRIVATE SUBMIT')&&sent(f).length===0,'ordinary typed private submit is neither intercepted nor relayed to the selected visitor');await f.stop();
}
for(const change of ['revision','call','context']){
  const f=await speechFixture();await f.arm();await f.recognize('I will answer this exact visitor after a short pause.');
  if(change==='revision'){f.turns.get(101).revision++;await f.window.VP3_PROFILE_DOORBELL_PA3_RUNTIME.refresh();}
  if(change==='call')await f.call(true);if(change==='context')await f.changeContext();await f.advance(2200);
  check(sent(f).length===0&&!f.thread.querySelector('.profile-doorbell-reply textarea')?.value,change+' change cancels the armed target before any public staging or send');await f.stop();
}
{
  const f=await speechFixture();f.window.VP3ConversationControls.listen();await f.settle();
  check(f.window.VP3ConversationControls.state().state==='listening'&&f.window.StonefellowVoiceLeaseV122.owns(),'fixture uses actual same-tab idle Chat listening with its capture lease');
  const releases=f.window.STONEFELLOW_VOICE_LEASE_V122.releases;await click(f);await f.advance(35000);
  check(!f.audio[0]?.paused&&f.window.STONEFELLOW_CHAT_CONTINUITY.isBusy()&&f.window.STONEFELLOW_CHAT_VOICE.speechStarts===1,'idle Chat listening receives visitor speech through the existing proactive echo path without self-stopping');
  check(f.window.STONEFELLOW_VOICE_LEASE_V122.releases===releases&&f.window.VP3ConversationControls.isEnabled(),'visitor playback neither yields nor replaces the existing Chat capture owner');
  f.window.STONEFELLOW_CHAT_CONTINUITY.interrupt('fixture-stop');await f.settle();
  check(f.audio[0].paused&&f.window.VP3ConversationControls.isEnabled(),'actual voice interrupt stops visitor audio while preserving ordinary Chat listening');await f.stop();
}
{
  const f=await speechFixture({scopedOnly:true});f.turns.get(101).responder='agent';f.turns.get(101).can_reply=false;await f.window.VP3_PROFILE_DOORBELL_PA3_RUNTIME.refresh();
  check(!f.speech(81)&&!f.requests.some(r=>r.action==='owner_claim'),'Agent-only message remains absent from proactive speech delivery');
  f.card(101).querySelector('[data-speech-action=visitor-play]').click();await f.settle();
  check(f.requests.some(r=>r.action==='owner_state'&&r.public_conversation_id===1101)&&f.audio.length===1&&!f.audio[0].paused,'explicit visible Agent-thread Play requests only its exact public conversation and message');
  check(!f.requests.some(r=>['doorbell_claim','doorbell_reply','doorbell_delegate'].includes(r.action))&&f.turns.get(101).responder==='agent','manual listening to Agent-only text does not take over or alter its responder');await f.stop();
}
{
  const f=await speechFixture();const held=f.hold('owner_claim');void click(f);await f.settle();held.work.resolve({state:'outcome_unverified',ticket:null});await f.settle();
  check(f.providerCalls===0&&!f.speech(81).querySelector('[data-speech-action=retry]').hidden,'uncertain provider result offers explicit Retry while preserving text');await f.advance(5000);
  check(f.requests.filter(r=>r.action==='owner_claim').length===1&&!f.requests.some(r=>r.action==='owner_retry'),'unknown paid outcome never silently replays or regenerates');
  let disclosure='';f.window.confirm=text=>{disclosure=text;return true;};f.speech(81).querySelector('[data-speech-action=retry]').click();await f.settle();const retry=f.requests.find(r=>r.action==='owner_retry');
  check(disclosure.includes('additional credits')&&retry?.retry_of===1&&retry.retry_confirmed===true&&retry.mode==='manual','explicit billed retry discloses possible charge and binds the exact prior attempt');await f.stop();
}
{
  const f=await speechFixture({widget:true});const select=f.document.getElementById('profileVisitorSpeechPreference');
  check(select&&!select.disabled&&select.querySelectorAll('option').length===6,'actual native widget offers voluntary style and only approved named presets');
  select.value='female';select.dispatchEvent(new Event('change'));await f.settle();const save=f.requests.find(r=>r.action==='visitor_preference');
  check(save.style==='female'&&save.conversation_namespace==='c'.repeat(64)&&save.profile_token==='PROFILE_TOKEN'&&save.revision===1,'preference uses the captured native session namespace, token and revision');
  check(!Object.hasOwn(save,'voice_id')&&!Object.hasOwn(save,'text')&&f.providerCalls===0,'voluntary style preference neither selects provider identity nor starts synthesis');
  select.value='key:'+'a'.repeat(64);select.dispatchEvent(new Event('change'));await f.settle();const preset=f.requests.filter(r=>r.action==='visitor_preference').at(-1);
  check(preset.style==='preset'&&preset.preset_key==='a'.repeat(64),'approved named preset uses only its opaque public key');await f.stop();
}
console.log('PROFILE_SPEECH_PA4A=PASS ('+checks+' runtime assertions)');
