import assert from 'node:assert/strict';
import fs from 'node:fs';

const css = fs.readFileSync(new URL('../chat-media-overlays.css', import.meta.url), 'utf8');
const chat = fs.readFileSync(new URL('../chat.php', import.meta.url), 'utf8');
const legacy = fs.readFileSync(new URL('../chat-legacy-v108.php', import.meta.url), 'utf8');

assert.match(chat, /chat-media-overlays\.css\?v=/, 'Chat loads the canonical media overlay repair after the agent theme');
assert.match(chat, /\$themeRuntime\s*\n\s*\. \$mediaOverlayRuntime/, 'Media overlay stylesheet is ordered after the agent theme');

assert.match(legacy, /id=\\?"chatQueueDrawer\\?"/, 'Queue drawer remains present');
assert.match(legacy, /id=\\?"chatPlaylistEditor\\?"/, 'Playlist editor remains present');
assert.match(legacy, /data-track-action=\\?"playlist\\?"/, 'Add-to-playlist action remains present');

assert.match(css, /\.chat-player-overlay\s*\{[\s\S]*?z-index:4200;/, 'Player overlay stacks above ordinary Chat popovers');
assert.match(css, /body:has\(\.chat-player-overlay:not\(\[hidden\]\)\)[\s\S]*?overflow:hidden;/, 'Open media overlays lock page scrolling without relying on a single JS body class');
assert.match(css, /\.chat-player-overlay \.chat-playlist-editor\s*\{[\s\S]*?justify-self:center;[\s\S]*?max-height:calc\(100dvh - 32px\);/, 'Playlist editor is centered and viewport-contained');
assert.match(css, /body\[data-agent-theme="light"\] \.chat-player-drawer\s*\{[\s\S]*?background:#fff;/, 'Modern player drawers follow the white Chat theme');
assert.match(css, /body\[data-agent-theme="light"\] \.chat-queue-modal,[\s\S]*?\.chat-playlist-modal\s*\{[\s\S]*?background:#fff;/, 'Legacy queue and playlist modals follow the white Chat theme');
assert.match(css, /@media\(max-width:760px\)[\s\S]*?\.chat-player-drawer:not\(\.chat-playlist-editor\)[\s\S]*?max-height:min\(86dvh,760px\);/, 'Mobile drawers remain within the viewport');

console.log('chat media overlay contracts passed');
