import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const permissions = read('includes/permissions.php');
const permissionExt = read('includes/permissions-v105.php');
const auth = read('includes/auth.php');
const chat = read('chat.php');
const chatLegacy = read('chat-legacy-v108.php');
const header = read('includes/header.php');
const adminHeader = read('admin/_header.php');
const uiPermissions = read('api/ui-permissions-v187.php');
const agentBrain = read('includes/agent-brain-v82.php');
const agentTools = read('includes/agent-tools-v84.php');

const coreKeys = [
  'account.access','chat.access','artist_listening.access','knowledge.access',
  'knowledge.manage','investor.access','admin.access','producer.access','team.manage',
  'listening.view','track_notes.manage','tracks.manage','albums.manage','shows.manage',
  'photos.manage','merch.manage','posts.manage','messages.manage','profile.manage',
  'users.manage','ai.manage','permissions.manage',
];
const fan = new Set(['account.access','chat.access','knowledge.access','playlists.manage']);
const artist = new Set([
  'account.access','chat.access','artist_listening.access','admin.access','team.manage',
  'listening.view','track_notes.manage','tracks.manage','albums.manage','shows.manage',
  'photos.manage','merch.manage','posts.manage','messages.manage','profile.manage',
  'knowledge.access','knowledge.manage','playlists.manage','release.manage','credits.manage',
]);
const admin = new Set([...coreKeys,'playlists.manage','release.manage','credits.manage']);
const matrix = {fan, artist, admin};
const effective = roles => new Set(roles.flatMap(role => [...(matrix[role] || [])]));

// Default role contract.
for (const key of ['account.access','chat.access','knowledge.access','playlists.manage']) {
  assert.equal(fan.has(key), true, `Fan must receive ${key}`);
}
for (const key of [...coreKeys,'release.manage','credits.manage']) {
  if (!['account.access','chat.access','knowledge.access'].includes(key)) {
    assert.equal(fan.has(key), false, `Fan must not receive ${key}`);
  }
}
assert.equal(artist.has('artist_listening.access'), true);
assert.equal(artist.has('users.manage'), false);
assert.equal(artist.has('permissions.manage'), false);
assert.deepEqual(effective(['fan','artist']), new Set([...fan,...artist]));
assert.equal(effective(['fan','admin']).size, admin.size);
assert.deepEqual(effective(['fan']), fan, 'removing an account type must remove its grants');

// Identity is resolved fresh from persistence, not from a stale payload/session.
assert.match(auth, /user_account_types_for_user_id/);
assert.match(permissions, /Persisted identity is authoritative/);
assert.doesNotMatch(
  permissions.match(/function user_roles_for_user[\s\S]*?function user_has_role/)?.[0] || '',
  /\$user\['roles'\]/,
);
assert.match(permissions, /reset_current_user_cache\(\)/);

// A primary Fan must not inherit an unverified historical Admin row. Secondary
// account types become effective only after Admin > Users explicitly saves them.
assert.match(permissions, /assigned_explicitly_at IS NOT NULL/);
assert.match(permissions, /role=\? OR assigned_explicitly_at IS NOT NULL/);
assert.match(permissions, /VALUES \(\?,\?,NOW\(\)\)/);
assert.match(permissions, /ADD COLUMN assigned_explicitly_at DATETIME NULL/);
const effectivePersistedRoles = (primaryRole, storedRows) => [
  primaryRole,
  ...storedRows
    .filter(row => row.role === primaryRole || row.explicit === true)
    .map(row => row.role),
].filter((role, index, roles) => roles.indexOf(role) === index);
assert.deepEqual(
  effectivePersistedRoles('fan', [
    {role:'fan', explicit:false},
    {role:'admin', explicit:false},
    {role:'artist', explicit:false},
  ]),
  ['fan'],
  'unverified historical roles must not elevate a primary Fan',
);
assert.deepEqual(
  effectivePersistedRoles('fan', [
    {role:'fan', explicit:true},
    {role:'artist', explicit:true},
  ]),
  ['fan','artist'],
  'explicitly saved multi-role accounts must retain their union',
);

// Permission synchronization must not silently restore removed matrix entries.
const seed = permissions.match(/function seed_permission_catalog[\s\S]*?function role_has_permission/)?.[0] || '';
assert.doesNotMatch(seed, /'manager'\s*=>\s*\['listening\.view'\]/);
assert.doesNotMatch(seed, /artist_listening_permission_seed_v180/);
assert.match(seed, /if \(\$existing === 0\)/);

// Rendered UI contracts.
assert.match(chat, /has_permission\('artist_listening\.access', \$user\)/);
assert.match(chatLegacy, /\$chatCanCreatePlaylist = permission_v105_has\('playlists\.manage', \$user\)/);
assert.doesNotMatch(chatLegacy, /\$chatCanCreatePlaylist = has_permission\('chat\.access'/);
assert.match(header, /has_permission\('admin\.access'/);
assert.match(adminHeader, /has_permission\('tracks\.manage'/);
assert.match(adminHeader, /has_permission\('permissions\.manage'/);
assert.match(uiPermissions, /'playlist'=>permission_v105_has\('playlists\.manage',\$user\)/);
assert.match(uiPermissions, /'artist_listening_access'=>has_permission\('artist_listening\.access',\$user\)/);
assert.match(agentBrain, /if \(has_permission\('account\.access', \$user\)\)[\s\S]*'key'=>'agent_brain'/);
assert.match(agentBrain, /if \(has_permission\('chat\.access', \$user\)\)[\s\S]*'key'=>'agent_chat'[\s\S]*'key'=>'video_editor'/);
assert.match(agentBrain, /if \(permission_v105_has\('playlists\.manage', \$user\)\)[\s\S]*'key'=>'playlists'/);
assert.match(agentBrain, /if \(has_permission\('artist_listening\.access', \$user\)\)[\s\S]*'key'=>'artist_listening'/);
assert.match(agentBrain, /has_permission\('admin\.access', \$user\)[\s\S]*team_chat_role_allowed\(\$user\)/);
assert.doesNotMatch(agentTools.match(/function booking_agent_available[\s\S]*?\n}/)?.[0] || '', /\$user\['role'\]/);

// Direct page/API guards: controls being hidden is never the only defense.
const endpointPermissions = new Map([
  ['artist-listening.php', 'artist_listening.access'],
  ['api/artist-listening-v174.php', 'artist_listening.access'],
  ['admin/tracks.php', 'tracks.manage'],
  ['admin/albums.php', 'albums.manage'],
  ['admin/shows.php', 'shows.manage'],
  ['admin/photos.php', 'photos.manage'],
  ['admin/posts.php', 'posts.manage'],
  ['admin/merch.php', 'merch.manage'],
  ['admin/users.php', 'users.manage'],
  ['admin/permissions.php', 'permissions.manage'],
  ['admin/ai.php', 'ai.manage'],
]);
for (const [path, permission] of endpointPermissions) {
  const escaped = permission.replaceAll('.', '\\.');
  assert.match(read(path), new RegExp("(?:require_permission|has_permission)\\('" + escaped + "'"));
}
assert.match(permissionExt, /chat-create-v76\.php[\s\S]*playlists\.manage/);
assert.match(permissionExt, /player-library-v76\.php[\s\S]*playlists\.manage/);

console.log('PERMISSION_ARCHITECTURE_V188=PASS');
