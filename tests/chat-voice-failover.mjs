import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('../chat-voice.js', import.meta.url), 'utf8');

assert.match(source, /elevenLabsExclusive:false/, 'Chat must truthfully report that browser TTS is an allowed fallback');
assert.match(source, /elevenLabsPrimary:true/, 'Chat must explicitly declare ElevenLabs as the primary output');
assert.match(source, /function\s+browserSpeak\s*\(/, 'Chat must keep browser TTS as an explicit fallback');
assert.match(source, /startSystemVoiceFallback/, 'Chat must expose one system voice fallback path');
assert.match(source, /ElevenLabs failed\. Switching to system voice\./, 'Fallback must verbally disclose the voice change');
assert.match(source, /systemFallbackAnnounced/, 'Fallback disclosure must be stateful so it is not repeated on every turn');
assert.match(source, /proof\.systemFallbacks\+=1/, 'Fallback use must remain observable in proof counters');
assert.match(source, /if\(ready\)systemFallbackAnnounced=false/, 'Successful ElevenLabs recovery must reset the fallback disclosure state');
assert.match(source, /if\(premiumReady\)systemFallbackAnnounced=false/, 'Fast-stream ElevenLabs recovery must reset the fallback disclosure state');
assert.match(source, /elevenlabs-playback-failed/, 'Pre-start ElevenLabs playback failure must use the disclosed system fallback');
assert.match(source, /const fallbackText=String\(currentSpokenText\|\|'\'\)\.trim\(\)/, 'Fallback may replay only an answer that has not reached audible completion');
assert.doesNotMatch(source, /fallbackText=String\(currentSpokenText\|\|lastSpokenText/, 'Late post-playback errors must not replay the completed answer through TTS');
assert.match(source, /PREMIUM_READY'\s*,\s*\{primary:true,fallback:'system-tts'\}/, 'Premium-ready logging must describe the real primary/fallback policy');
assert.doesNotMatch(source, /PREMIUM_READY'\s*,\s*\{exclusive:true\}/, 'Chat must not report ElevenLabs as exclusive when TTS fallback is allowed');
assert.match(source, /new\s+window\.SpeechSynthesisUtterance\s*\(/, 'Browser fallback must instantiate the constructor from the same window object it feature-detects');
assert.doesNotMatch(source, /new\s+SpeechSynthesisUtterance\s*\(/, 'Browser fallback must not rely on an unqualified SpeechSynthesisUtterance global');
assert.match(source, /speechSynthesis\.speak\s*\(/, 'Fallback must use browser speech synthesis after disclosure');

console.log('Chat ElevenLabs failover notification contracts passed.');
