import fs from 'node:fs';

const runtime = fs.readFileSync('artist-listening-recognition.js', 'utf8');
const page = fs.readFileSync('artist-listening.php', 'utf8');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

assert(runtime.includes("const RESTART_DELAY_MS = 35;"), 'recognition continuity must use the short restart delay');
assert(runtime.includes('this._scheduleRestart(RESTART_DELAY_MS);'), 'native recognizer resets must restart inside the continuity wrapper');
assert(runtime.includes("window.SpeechRecognition = StonefellowContinuousRecognition;"), 'SpeechRecognition must be wrapped before capture runtime starts');
assert(runtime.includes("window.webkitSpeechRecognition = StonefellowContinuousRecognition;"), 'webkitSpeechRecognition must be wrapped too');
assert(runtime.includes('stonefellow:artist-listening-recognition-reset'), 'native resets must be observable without ending the logical session');
assert(runtime.includes('This wrapper owns only gap-minimized native recognition continuity.'), 'recognition wrapper must not own transcript rendering');
assert(!runtime.includes('data-listening-transcript'), 'recognition continuity must not render transcript UI');
assert(!runtime.includes('Media' + 'Recorder'), 'retained audio must remain outside recognition continuity');

const build = 'artist-listening-normalized-20260903';
const recognitionIndex = page.indexOf(`/artist-listening-recognition.js?v=${build}`);
const realtimeIndex = page.indexOf('/artist-listening-realtime.js?v=e07b7c39');
const captureIndex = page.indexOf('/artist-listening.js?v=9ac023be');
assert(recognitionIndex >= 0, 'Artist Listening page must load the canonical recognition runtime');
assert(realtimeIndex >= 0 && realtimeIndex < recognitionIndex, 'realtime reconciliation must load before recognition starts');
assert(captureIndex >= 0, 'Artist Listening page must load the fixed capture module with its content hash');
assert(recognitionIndex < captureIndex, 'recognition continuity must load before capture reads SpeechRecognition');
assert(!/artist-listening-(?:continuous|recognition)-v\d+\.js/.test(page), 'numbered recognition frontend layers must not return');

console.log('ARTIST_LISTENING_RECOGNITION=PASS');
