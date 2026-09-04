import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const helper = read('includes/artist-shows-v184.php');
const admin = read('admin/artist-shows.php');
const profile = read('profile.php');
const profileRuntime = read('includes/profile-agent.php');
const bootstrap = read('includes/bootstrap.php');
const upgrade = read('upgrade.php');
const header = read('admin/_header.php');
const legacyShows = read('admin/shows.php');
const workflow = read('.github/workflows/pr82-listening-recovery.yml');

assert.match(bootstrap, /artist-shows-v184\.php/);
assert.match(helper, /function artist_shows_v184_schema_ready/);
assert.match(helper, /column_exists\('artist_catalog_shows_v181','event_name'\)/);
assert.match(helper, /column_exists\('artist_catalog_shows_v181','show_status'\)/);
assert.match(helper, /ADD COLUMN event_name VARCHAR\(190\)/);
assert.match(helper, /ADD COLUMN show_status VARCHAR\(30\)/);
assert.match(helper, /'scheduled'=>'Scheduled'/);
assert.match(helper, /'postponed'=>'Postponed'/);
assert.match(helper, /'cancelled'=>'Cancelled'/);
assert.match(helper, /WHERE id=\? AND workspace_id=\? LIMIT 1/);
assert.match(helper, /show_date>=NOW\(\) AND is_published=1/);
assert.match(helper, /show_date<NOW\(\) AND is_published=1/);
assert.match(helper, /is_published=0/);
assert.match(helper, /max\(1,min\(500,\$limit\)\)/);

assert.match(admin, /user_has_role\('artist',\$user\)/);
assert.match(admin, /has_permission\('shows\.manage',\$user\)/);
assert.match(admin, /artist_shows_v184_ensure_schema\(\$pdo\)/);
assert.match(admin, /verify_csrf\(\)/);
assert.match(admin, /DELETE FROM artist_catalog_shows_v181 WHERE id=\? AND workspace_id=\?/);
assert.match(admin, /UPDATE artist_catalog_shows_v181 SET event_name=.*WHERE id=\? AND workspace_id=\?/s);
assert.match(admin, /INSERT INTO artist_catalog_shows_v181 \(workspace_id,event_name,show_date,venue,city,region,notes,ticket_url,show_status,is_published\)/);
assert.match(admin, /artist_workspace_v181_validate_external_url/);
assert.match(admin, /name="event_name"/);
assert.match(admin, /name="show_status"/);
assert.match(admin, /name="show_date" type="datetime-local"/);
assert.match(admin, /name="ticket_url" type="url"/);
assert.match(admin, /Publish on Artist Profile/);
for (const filter of ['upcoming','past','draft','all']) assert.match(admin, new RegExp(`filter=${filter}`));
assert.doesNotMatch(admin, /name="workspace_id"/);

// Canonical profile filters to upcoming public shows and retains event/status/ticket details.
assert.match(profileRuntime,/artist_workspace_v181_public_records\(\$kind,\$viewer,\$limit,\$wid\)/);
assert.match(profile, /strtotime\(\(string\)\(\$show\['show_date'\]/);
assert.match(profile, /\$when!==false&&\$when>=time\(\)/);
assert.match(profile, /\$show\['event_name'\]/);
assert.match(profile, /\$show\['show_status'\]/);
assert.match(profile, /artist_shows_v184_statuses/);
assert.match(profile, /\$status!=='cancelled'&&!empty\(\$show\['ticket_url'\]\)/);
assert.match(profile, /No upcoming published shows yet/);
assert.match(profile, /profile-show-actions/);

assert.match(header, /\$isArtistAdmin\?'\/admin\/artist-shows\.php':'\/admin\/shows\.php'/);
assert.match(legacyShows, /redirect\(url\('\/admin\/artist-shows\.php'\)\)/);
assert.match(upgrade, /artist_shows_v184_schema_ready\(\)/);
assert.match(upgrade, /artist_shows_v184_ensure_schema\(\)/);

assert.match(workflow,/Canonical Agent Chat voice architecture/,'workflow validates canonical Agent Chat voice architecture');
assert.match(workflow,/test -f chat-voice\.js/,'workflow requires canonical chat-voice.js');
assert.match(workflow,/test ! -e chat-voice-v142\.js/,'workflow rejects the superseded versioned Agent Chat controller');
assert.match(workflow,/test -f conversation-voice-v122\.js/,'workflow retains the active shared editor conversation controller during Section 1');

console.log('ARTIST_SHOWS_V184=PASS');