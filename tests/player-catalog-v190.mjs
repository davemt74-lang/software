import fs from 'node:fs';
import assert from 'node:assert/strict';

const functions = fs.readFileSync('includes/functions.php', 'utf8');
const player = fs.readFileSync('includes/player-v76.php', 'utf8');
const chat = fs.readFileSync('chat-legacy-v108.php', 'utf8');

// Regression: creating the Artist Workspace schema must not replace the
// platform catalog. This was the production-wide empty-player root cause.
assert.match(functions, /function merge_player_track_catalogs\(/);
assert.match(functions, /\$platformTracks = \$pdo->query\(/);
assert.match(functions, /artist_workspace_v181_schema_ready\(\$pdo\)[\s\S]*artist_workspace_v181_public_records\('tracks', current_user\(\)\)/);
assert.match(functions, /merge_player_track_catalogs\(\$platformTracks, \$artistTracks\)[\s\S]*'can_view_track'/);
assert.doesNotMatch(functions, /if \(artist_workspace_v181_schema_ready\(\$pdo\)\) \{\s*\$rows = artist_workspace_v181_public_records\('tracks'/);

// Migrated workspace shadows cannot duplicate platform songs; truly native
// artist releases retain the existing reserved player-id contract.
assert.match(functions, /isset\(\$platformIds\[\$sourceTrackId\]\)/);
assert.match(functions, /1000000000 \+ \$artistTrackId/);

// Both the Player model and the rendered chat UI consume the repaired shared
// catalog, so the result is not dependent on Fan/Admin/Artist role labels.
assert.match(player, /foreach \(get_tracks\(\) as \$track\)/);
assert.match(chat, /\$chatTracks = get_tracks\(\)/);

console.log('PLAYER_CATALOG_V190=PASS');
