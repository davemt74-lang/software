import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const lifecycle = read('includes/team-workspace-lifecycle-v350.php');
const legacy = read('includes/artist-workspaces-v104.php');
const team = read('team.php');
const invite = read('team-invite.php');
const login = read('login.php');
const signup = read('signup.php');
const teamSubscription = read('includes/team-subscription.php');
const social = read('includes/social-network-v320.php');
const teamChat = read('api/team-chat-v320.php');
const workspacePicker = read('admin/team-workspaces.php');
const bootstrap = read('includes/bootstrap.php');
const setup = read('setup.php');
const upgrade = read('upgrade.php');
const chat = read('chat.php');

// Canonical lifecycle + invitation persistence.
assert.match(lifecycle, /CREATE TABLE IF NOT EXISTS workspace_memberships_v350/);
assert.match(lifecycle, /membership_status VARCHAR\(20\) NOT NULL DEFAULT 'active'/);
assert.match(lifecycle, /CREATE TABLE IF NOT EXISTS workspace_team_invitations_v350/);
assert.match(lifecycle, /token_hash CHAR\(64\) NOT NULL/);
assert.match(lifecycle, /invitation_status VARCHAR\(20\) NOT NULL DEFAULT 'pending'/);
assert.match(lifecycle, /DATE_ADD\(NOW\(\),INTERVAL 7 DAY\)/);
assert.match(lifecycle, /bin2hex\(random_bytes\(32\)\)/);
assert.match(lifecycle, /hash\('sha256',\$raw\)/);

// DDL must never implicitly commit an active membership/invite transaction.
assert.match(lifecycle, /if\(\$pdo->inTransaction\(\)\)\{\s*workspace_team_v350_require_schema\(\$pdo\);\s*return;/s);
assert.match(lifecycle, /Never run DDL from inside a membership\/invitation transaction/);

// Membership mutation is serialized and re-read under row locks.
assert.match(lifecycle, /workspace_team_v350_user\(\$pdo,\$ownerId,true\)/);
assert.match(lifecycle, /workspace_team_v350_user\(\$pdo,\$memberId,true\)/);
assert.match(lifecycle, /workspace_team_v350_membership\(\$pdo,\$ownerId,\$memberId,true\)/);
assert.match(lifecycle, /workspace_team_v350_invitation\(\$pdo,\$inviteId,true\)/);
assert.match(lifecycle, /workspace_team_v350_assert_can_activate/);
assert.match(lifecycle, /team_subscription_state\(\$owner,\$pdo\)/);
assert.match(lifecycle, /hash_equals\(strtolower\(\(string\)\$invite\['invited_email'\]\),strtolower\(\(string\)\$user\['email'\]\)\)/);
assert.match(lifecycle, /expires_at/);

// Legacy Team storage is active-only projection; lifecycle history is durable.
assert.match(lifecycle, /WHERE wm\.membership_status<>'active'/);
assert.match(lifecycle, /FROM workspace_memberships_v350 WHERE membership_status='active'/);
assert.match(lifecycle, /membership_status='suspended'/);
assert.match(lifecycle, /membership_status='removed'/);
assert.match(legacy, /workspace_team_v350_activate_member/);
assert.match(legacy, /workspace_team_v350_set_status\(\$pdo,\$artistUserId,\$memberUserId,'removed'\)/);

// Pending invitations are not memberships and owners never create another user's password.
assert.match(team, /workspace_team_v350_create_invitation/);
assert.match(team, /Pending invitations do not consume a Team seat/);
assert.doesNotMatch(team, /INSERT INTO users/);
assert.doesNotMatch(team, /password_hash\(/);
assert.doesNotMatch(team, /Temporary password/i);
assert.match(team, /workspace_team_v350_set_status\(\$pdo,\$ownerUserId,\$targetId,'suspended'\)/);
assert.match(team, /workspace_team_v350_set_status\(\$pdo,\$ownerUserId,\$targetId,'active'\)/);
assert.match(team, /workspace_team_v350_set_status\(\$pdo,\$ownerUserId,\$targetId,'removed'\)/);

// Invite acceptance is tied to the signed-in account and survives auth without open redirect input.
assert.match(invite, /workspace_team_v350_accept_invitation/);
assert.match(invite, /workspace_team_v350_decline_invitation/);
assert.match(invite, /pending_team_invite_token/);
assert.match(login, /pending_team_invite_token/);
assert.match(signup, /pending_team_invite_token/);
assert.doesNotMatch(login, /[?&]return_to=/);
assert.doesNotMatch(signup, /[?&]return_to=/);

// Existing collaboration and messaging paths consume only the active projection.
assert.match(teamSubscription, /FROM artist_team_members/);
assert.match(social, /FROM artist_team_members/);
assert.match(teamChat, /FROM artist_team_members/);
assert.match(social, /You are not an active member of that workspace/);

// Multi-workspace picker uses modern contextual surfaces, never a global Manager/Artist identity.
assert.match(workspacePicker, /music-workspace\.php\?workspace=/);
assert.match(workspacePicker, /producer-tracks\.php\?artist_id=/);
assert.doesNotMatch(workspacePicker, /user_has_role\('manager'/);
assert.doesNotMatch(workspacePicker, /user_has_role\('artist'/);

// Setup/upgrade/bootstrap own the lifecycle installation contract.
assert.match(bootstrap, /team-workspace-lifecycle-v350\.php/);
assert.match(setup, /workspace_team_v350_ensure_schema\(\$pdo\)/);
assert.match(upgrade, /workspace_team_v350_schema_ready\(\)/);
assert.match(upgrade, /workspace_team_v350_ensure_schema\(\$pdo\)/);

// Sticky Agent composer: Video Editor control must remain removed at the render source.
assert.doesNotMatch(chat, /chatVideoEditorButton/);
assert.doesNotMatch(chat, /chat-video-editor-button/);
assert.doesNotMatch(chat, /Open Video Editor/);

console.log('TEAM_WORKSPACE_LIFECYCLE_V350=PASS');
