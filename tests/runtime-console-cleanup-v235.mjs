import fs from 'node:fs';
import assert from 'node:assert/strict';

const team = fs.readFileSync('team-chat-admin-v109.js', 'utf8');
assert.match(team, /const endpointUrl = new URL\(sourceEndpoint, window\.location\.href\);/);
assert.match(team, /assetBase = new URL\('\.\.\/', endpointUrl\)\.toString\(\);/);
assert.match(team, /team-chat-v109\.css\?v=' \+ BUILD, assetBase/);
assert.match(team, /team-chat-v109\.js\?v=' \+ BUILD, assetBase/);
assert.doesNotMatch(team, /team-chat-v109\.(?:css|js).*window\.location\.href/);

const media = fs.readFileSync('admin-track-media-v49.php', 'utf8');
assert.match(media, /function stonefellow_track_media_v235_fallback/);
assert.match(media, /FROM track_stems/);
assert.match(media, /X-Stonefellow-Media-Fallback: first-stem/);
assert.match(media, /stem-media-v34\.php\?id=/);
assert.match(media, /Location: .*true, 307/);

const voice = fs.readFileSync('api/agent-voice-v117.php', 'utf8');
const warmStart = voice.indexOf("if ($action === 'warm')");
const ticketStart = voice.indexOf("if (!in_array($action, ['ticket', 'speak'], true))");
assert.ok(warmStart >= 0 && ticketStart > warmStart);
const warmBlock = voice.slice(warmStart, ticketStart);
assert.match(warmBlock, /X-Stonefellow-Voice-Ready/);
assert.match(warmBlock, /\], 200\);/);
assert.doesNotMatch(warmBlock, /\$ready \? 200 : 503/);
assert.match(voice.slice(ticketStart), /ElevenLabs voice is not configured[\s\S]*503/);

const favicon = fs.statSync('favicon.ico');
assert.ok(favicon.isFile());
assert.ok(favicon.size > 500);

console.log('runtime console cleanup v235 contracts passed');
