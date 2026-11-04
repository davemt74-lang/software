import fs from 'node:fs';

const voice=fs.readFileSync('chat-voice.js','utf8');
const chat=fs.readFileSync('chat.php','utf8');
const assert=(ok,label)=>{console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);};

assert(chat.includes('data-chat-lifecycle="canonical"'),'canonical Agent Chat lifecycle controller is active');
assert(!chat.includes('premium-autoplay-v148.js?v='),'Chat does not load a separate autoplay wrapper');
assert(chat.includes('premium-voice-v117.js?v=')&&chat.indexOf('premium-voice-v117.js?v=')<chat.indexOf('chat-voice.js?v='),'premium voice loads directly before the existing Chat controller');
assert(!voice.includes('boot.intro')&&!voice.includes('pendingIntroSpeech'),'Chat voice does not queue an unsolicited greeting');
assert(voice.includes("requestPremiumUnlock('listen-button')")&&voice.includes('premium.unlock()'),'explicit listening still unlocks premium audio');
assert(voice.includes('premium.speak(message'),'premium audio still plays active voice responses');

console.log('CHAT_AUTOPLAY_LIFECYCLE_V157=PASS');
