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
assert.match(crm, /G-' \. strtoupper\(substr\(\$key, 0, 6\)\)/, 'contact reference must derive from the owner-scoped hash');

assert.match(profile, /profile_runtime_record_view\(\$pdo,\$profile,\$viewer\)/, 'public profile must use the canonical visitor runtime');
assert.doesNotMatch(profile, /profile_record_view\(\$pdo,\$profile,\$viewer\)/, 'public profile must not use the legacy session-only visit path');
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
assert.match(attentionApi, /profile_visitor_attention_decision_v243/, 'Agent Chat attention presentation must use visitor history context');
assert.match(attentionApi, /'profile_contact'=>\$contact/, 'persisted assistant turn carries safe contact context');
assert.match(attentionApi, /'response_timeout_ms'=>10000/, 'contextual visitor prompts retain the 10-second response window');

assert.match(contacts, /require_permission\('account\.access'\)/, 'My Contacts is an authenticated personal workspace');
assert.match(contacts, /profile_visitor_contact_list_v243/, 'My Contacts uses the canonical personal contact projection');
assert.match(contacts, /data-contact-filter="returning_visitor"/, 'CRM dashboard can filter returning visitors');
assert.match(contacts, /data-contact-filter="engaged"/, 'CRM dashboard can filter engaged visitors');
assert.match(contacts, /data-contact-filter="member"/, 'CRM dashboard can filter signed-in members');
assert.match(contacts, /visit_count/, 'CRM table displays true visit-session count');
assert.match(contacts, /page_view_count/, 'CRM table keeps page-view detail separate');
assert.match(contacts, /Privacy-first guest continuity/, 'CRM explains the anonymous continuity model');
assert.match(sidebar, /href="<\?= e\(url\('\/contacts\.php'\)\) \?>"[\s\S]*My Contacts/, 'left workspace sidebar exposes My Contacts');
assert.match(sidebar, /workspaceSidebarActive==='contacts'/, 'My Contacts has a real active sidebar state');
assert.match(memberNav, /'contacts','My Contacts',url\('\/contacts\.php'\)/, 'member navigation also exposes My Contacts');
assert.match(contactsCss, /@media\(max-width:760px\)/, 'My Contacts has a dedicated mobile layout');
assert.match(contactsCss, /grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/, 'mobile metrics remain compact without vertical sprawl');

console.log('PROFILE_VISITOR_CRM_V243_CONTRACT=PASS');
