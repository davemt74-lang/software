import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const crm = read('includes/profile-visitor-crm-v243.php');
const runtime = read('includes/profile-agent-runtime.php');
const profile = read('profile.php');
const notifications = read('includes/notifications.php');
const attentionApi = read('api/chat-notifications-brain-v240.php');
const contacts = read('contacts.php');
const contactsCss = read('contacts.css');
const sidebar = read('includes/workspace-sidebar-v82.php');
const mainSidebar = read('includes/main-sidebar.php');
const memberNav = read('includes/member-navigation.php');
const bootstrap = read('includes/bootstrap.php');

assert.match(bootstrap, /profile-visitor-crm-v243\.php/, 'bootstrap must load the personal visitor/CRM continuity layer');
assert.match(crm, /STONEFELLOW_PROFILE_VISITOR_COOKIE_V243/, 'guest continuity owns one first-party browser identifier');
assert.match(crm, /random_bytes\(32\)/, 'new guest identifiers must be cryptographically random');
assert.match(crm, /'httponly'\s*=>\s*true/, 'guest continuity cookie must be HTTP-only');
assert.match(crm, /'samesite'\s*=>\s*'Lax'/, 'guest continuity cookie must use SameSite=Lax');
assert.match(crm, /hash\('sha256', profile_visitor_cookie_token_v243\(\$ownerUserId\) \. '\|' \. \$ownerUserId\)/, 'stored identity must be an owner-scoped hash, not the raw cookie');
assert.doesNotMatch(crm, /REMOTE_ADDR|HTTP_USER_AGENT|device[_ -]?fingerprint|canvas[_ -]?fingerprint/i, 'guest continuity must not fingerprint IPs or devices');
assert.match(crm, /contact_ref/, 'owner receives a stable pseudonymous contact reference');
assert.match(crm, /STONEFELLOW_PROFILE_CONTACT_REF_HEX_V243\s*=\s*16/, 'CRM display references must use 64 bits of the owner-scoped hash');
assert.match(crm, /G-' \. strtoupper\(substr\(\$key, 0, STONEFELLOW_PROFILE_CONTACT_REF_HEX_V243\)\)/, 'contact reference must derive from the owner-scoped hash');

assert.match(profile, /profile_runtime_record_view\(\$pdo,\$profile,\$viewer\)/, 'public profile must use the canonical visitor runtime');
assert.doesNotMatch(profile, /profile_record_view\(\$pdo,\$profile,\$viewer\)/, 'public profile must not use the legacy session-only visit path');
assert.match(runtime, /visitor_user_id=COALESCE\(VALUES\(visitor_user_id\),visitor_user_id\)/, 'anonymous returns must preserve an established signed-in contact association');
assert.match(runtime, /CASE WHEN VALUES\(visitor_user_id\) IS NULL THEN identity_disclosed ELSE VALUES\(identity_disclosed\) END/, 'anonymous returns must not silently erase established disclosure while signed-in visits remain authoritative');
assert.match(runtime, /created_at>=DATE_SUB\(NOW\(\),INTERVAL 30 MINUTE\)/, 'refreshes within 30 minutes must not become separate return visits');
assert.match(runtime, /profile_visitor_session_visit_count_v243[\s\S]*\+1/, 'new return events carry an actual visit number');
assert.match(runtime, /\$metadata\['returning'\]=\$metadata\['visit_number'\]>1/, 'visit metadata explicitly records returning status');
assert.match(crm, /COUNT\(DISTINCT CASE WHEN e\.event_type='profile_view' THEN e\.id END\) AS visit_count/, 'CRM visit count must use distinct visit events');
assert.match(crm, /COUNT\(DISTINCT CASE WHEN m\.sender_type='visitor' THEN m\.id END\) AS visitor_message_count/, 'CRM message count must not multiply messages across joined visit events');
assert.match(crm, /page_view_count/, 'raw page views remain available separately from visit sessions');
assert.match(crm, /utm_source[\s\S]*utm_medium[\s\S]*utm_campaign/, 'entry attribution captures standard UTM fields');
assert.match(crm, /referrer_host/, 'entry attribution captures external referrer host only');

assert.match(crm, /Would you like me to say hello first\?/, 'first visit should become a conversational owner prompt');
assert.match(crm, /They have chatted with [\s\S]* before/, 'returning visitors with chat history should be recognized');
assert.match(crm, /Last time they asked/, 'prior Profile Agent question can inform the next owner prompt');
assert.match(crm, /still waiting on your input/, 'pending owner questions outrank generic visit prompts');
assert.match(crm, /I do not have enough approved information to answer accurately/, 'needs-owner prompts preserve the no-guessing boundary');
assert.match(crm, /relationship_scope/, 'known relationship state is part of the decision context when identity may be disclosed');

assert.match(notifications, /\$sourceType === 'profile_event'/, 'Profile Agent source events are promoted through the canonical notification gate');
assert.match(notifications, /profile_profile_view/, 'legacy duplicate profile prefix remains readable during rollout');
assert.match(notifications, /if \(\$type === 'profile_profile_view'\) \$type = 'profile_view';/, 'new profile-view notifications normalize to one canonical type');
assert.match(attentionApi, /function chat_notifications_v240_contextual_decision\(/, 'contextual visitor enrichment has one isolated presentation boundary');
assert.match(attentionApi, /profile_visitor_attention_decision_v243/, 'Agent Chat attention presentation must use visitor history context');
assert.match(attentionApi, /catch \(Throwable \$e\)[\s\S]{0,320}return null;/, 'CRM enrichment failure must degrade to the canonical notification instead of dropping attention delivery');
assert.match(attentionApi, /notification_attention_message\(\$notification\)/, 'canonical attention remains the delivery fallback when contextual enrichment is unavailable');
assert.match(attentionApi, /'profile_contact'=>\$contact/, 'persisted assistant turn carries safe contact context');
assert.match(attentionApi, /'response_timeout_ms'=>10000/, 'contextual visitor prompts retain the 10-second response window');

assert.match(contacts, /require_permission\('account\.access'\)/, 'My Contacts is an authenticated personal workspace');
assert.match(contacts, /profile_visitor_contact_list_v243/, 'My Contacts uses the canonical personal contact projection');
assert.match(contacts, /data-contact-filter="returning_visitor"/, 'CRM dashboard can filter returning visitors');
assert.match(contacts, /data-contact-filter="engaged"/, 'CRM dashboard can filter engaged visitors');
assert.match(contacts, /data-contact-filter="member"/, 'CRM dashboard can filter signed-in members');
assert.match(contacts, /visit_count/, 'CRM table displays true visit-session count');
assert.match(contacts, /page_view_count/, 'CRM table keeps page-view detail separate');
assert.match(contacts, /data-label="Stage"[\s\S]*data-label="Visits"[\s\S]*data-label="Last activity"/, 'contact cells provide labels for the mobile card layout');
assert.match(contacts, /Privacy-first guest continuity/, 'CRM explains the anonymous continuity model');
assert.match(sidebar, /require __DIR__ \. '\/main-sidebar\.php';/, 'workspace sidebar delegates to the canonical member sidebar');
assert.match(mainSidebar, /href="<\?= e\(url\('\/contacts\.php'\)\) \?>"[\s\S]*My Contacts/, 'canonical member sidebar exposes My Contacts');
assert.match(mainSidebar, /mainSidebarActive === 'contacts'/, 'My Contacts has a real active sidebar state');
assert.match(memberNav, /'contacts','My Contacts',url\('\/contacts\.php'\)/, 'member navigation also exposes My Contacts');
assert.match(contactsCss, /@media\(max-width:760px\)/, 'My Contacts has a dedicated mobile layout');
assert.match(contactsCss, /grid-template-columns:repeat\(3,minmax\(0,1fr\)\)/, 'tablet metrics remain compact without vertical sprawl');
assert.match(contactsCss, /\.contacts-board-head\{display:none\}/, 'mobile layout removes the desktop-only table header');
assert.match(contactsCss, /\.contacts-row\{min-width:0;grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/, 'mobile contact rows become two-column cards instead of a wide horizontal table');
assert.match(contactsCss, /\.contacts-cell::before\{content:attr\(data-label\)/, 'mobile cards expose a label for each CRM value');

console.log('PROFILE_VISITOR_CRM_V243_CONTRACT=PASS');
