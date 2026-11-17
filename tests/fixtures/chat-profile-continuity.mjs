import assert from 'node:assert/strict';
import fs from 'node:fs';

const source=fs.readFileSync(new URL('../../chat.js',import.meta.url),'utf8');

// Load actual top-level helpers used by the Profile/voice integration. A
// contiguous source range also contains unrelated Initiative event bindings
// and cannot represent the private Chat closure without all of their state.
function chatFunction(name){
  const start=source.search(new RegExp('^  (?:async )?function '+name+'\\(','m'));
  const end=source.indexOf('\n  }',start);
  assert.ok(start>=0&&end>start,'Production Chat helper exists: '+name);
  return source.slice(start,end+4)+'\n';
}

export const profileChatHelpers='let initializingConversationPa3=null;\n'+[
  'composerDraftValue','bindStoredMessagePa3','bindProfileCallPa3c','bindProfileSpeechPa4a','bindProfileAttentionPa4b',
  'ensureConversationPa3','mountProfileHandoffPa3','mountProfileCallPa3c','mountProfileSpeechPa4a','mountProfileAttentionPa4b'
].map(chatFunction).join('');

export const continuityExportHelpers=['stageSpecialistPrompt','parkCurrentProactiveReplyV1','showChatTurnNotice',
  'chatCanvasStatus','proactiveVoiceStatus','refreshGmailUpdatesV1'].map(chatFunction).join('');

const exportStart=source.indexOf('  const canonicalChatContinuity = {');
const exportEnd=source.indexOf('  function speakIntroV101(',exportStart);
assert.ok(exportStart>=0&&exportEnd>exportStart,'Production Chat continuity export exists');
export const canonicalContinuityExport=source.slice(exportStart,exportEnd);
