import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const profile = read('profile.php');
const wrapper = read('profile-v900.php');
const media = read('includes/profile-public-media-v174.php');
const mediaRoute = read('api/profile-media.php');
const routes = read('.htaccess');
const memberHeader = read('includes/member-header.php');
const calendar = read('includes/user-calendar-v1300.php');
const css = read('profile-cleanup-v174.css');

assert.match(routes, /profile-v900\.php\?username=\$1/, 'vanity profiles must still route through profile-v900.php');
assert.match(wrapper, /require __DIR__ \. '\/profile\.php'/, 'vanity route must use one canonical profile renderer');
assert.doesNotMatch(wrapper, /ob_start\(|profile-commerce-grid-v900|str_replace\(/, 'profile wrapper must not inject duplicate profile UI');

assert.match(profile, /includes\/member-header\.php/, 'authenticated profiles must use the universal VP3 member header');
assert.match(profile, /\$memberHeaderClass=['"]['"];/, 'profile must not apply a profile-specific member-header variant');
assert.match(memberHeader, /data-member-header/, 'shared VP3 header marker must remain available');

assert.match(profile, /\$profileTabs=\[\]/, 'profile tabs must be constructed from relevance rather than a fixed list');
assert.match(profile, /if\(\$bio!==['"]['"]\|\|\$links\)\$profileTabs\['about'\]/, 'About must only appear when public About content exists');
assert.match(profile, /if\(\$shows\)\$profileTabs\['calendar'\]=['"]Calendar['"]/, 'Calendar tab must only appear when public events exist');
assert.match(profile, /if\(\$bookingTypes\)\$profileTabs\['booking'\]=['"]Booking['"]/, 'Booking tab must only appear when public booking types exist');
assert.match(profile, /if\(\$commerceProducts\)\$profileTabs\['products'\]=['"]Products['"]/, 'Products tab must only appear when public products exist');
assert.match(profile, /if\(\$tracks\|\|\$albums\)\$profileTabs\['music'\]/, 'Music tab must only appear when public music exists');
assert.match(profile, /if\(\$photos\)\$profileTabs\['photos'\]/, 'Photos tab must only appear when public photos exist');
assert.match(profile, /if\(\$posts\)\$profileTabs\['posts'\]/, 'Posts tab must only appear when public posts exist');
assert.match(profile, /if\(\$merch\)\$profileTabs\['merch'\]/, 'Merch tab must only appear when public merch exists');
assert.doesNotMatch(profile, /\['music'=>'Music','shows'=>'Shows'/, 'fixed legacy tab list must stay removed');

assert.match(profile, /This user has no public calendar events\./, 'zero-public-event profile must show the requested calendar notice');
assert.match(profile, /data-profile-panel="booking"/, 'Booking must render inside main tab navigation');
assert.match(profile, /agent_scheduling_public_schedule_v450/, 'Booking tab must use the public scheduling projection');
assert.match(profile, /agent_scheduling_public_events_v450/, 'Booking tab must only expose active public event types');
assert.match(profile, /data-profile-panel="products"/, 'Products must render inside main tab navigation');
assert.match(profile, /profile_commerce_products_for_profile_v900\(\$pdo,\$profile,true,40\)/, 'Products tab must use the public Commerce projection');
assert.doesNotMatch(profile, /profile-about">/, 'standalone duplicate About card must stay removed');
assert.doesNotMatch(profile, /user_calendar_events/, 'public profile must never query the private personal calendar table');
assert.match(calendar, /user_calendar_events/, 'private user calendar remains owned by its separate calendar domain');

assert.match(profile, /profile_public_media_url_v174\([^\n]*avatar_path[^\n]*['"]avatars['"]\)/, 'avatar must use safe public media resolution');
assert.match(profile, /profile_public_media_url_v174\([^\n]*cover_path[^\n]*['"]profile-covers['"]\)/, 'cover must use safe public media resolution');
assert.match(profile, /artist-profile-image\.php\?user_id=/, 'invalid or missing local profile media must retain the artist-workspace fallback');
assert.match(media, /'\/uploads\/' \. \$bucket \. '\/'/, 'media normalization must produce one canonical uploads path');
assert.match(media, /\$path = '\/' \. ltrim\(\$path, '\/'\)/, 'legacy relative upload paths must normalize to a leading slash');
assert.match(media, /str_contains\(\$file, '\/'\)/, 'media normalization must reject nested/traversal filenames');
assert.match(media, /realpath\(STONEFELLOW_ROOT \. '\/uploads\/' \. \$bucket\)/, 'media file access must stay constrained to the expected upload bucket');
assert.match(mediaRoute, /WHERE u\.avatar_path IN \(\?,\?\)/, 'avatar owner lookup must accept canonical and legacy relative path forms');
assert.match(mediaRoute, /WHERE p\.cover_path IN \(\?,\?\)/, 'cover owner lookup must accept canonical and legacy relative path forms');
assert.match(mediaRoute, /profile_public_media_file_v174/, 'public media route must use the constrained resolver');
assert.doesNotMatch(`${profile}\n${media}\n${mediaRoute}`, /\bdave\b|David Evans|user_id\s*===?\s*1\b/i, 'profile media fix must not hardcode the main admin account');

assert.match(css, /\.profile-booking-list/, 'Booking tab must have canonical profile layout');
assert.match(css, /\.profile-products-list/, 'Products tab must have canonical profile layout');
assert.match(css, /\.profile-public-calendar-empty/, 'calendar empty notice must be styled as profile status UI');

console.log('profile-cleanup-v174-contract: ok');
