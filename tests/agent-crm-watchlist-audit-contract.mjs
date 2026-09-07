import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const crm = read('includes/agent-crm.php');
const crmChat = read('includes/agent-crm-chat.php');
const accessChat = read('includes/agent-access-chat.php');
const radarChat = read('includes/agent-radar-chat.php');
const policyApi = read('api/agent-radar-policy.php');
const mainFeed = read('api/chat.php');
const contacts = read('contacts.php');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-crm-chat.php';"), 'bootstrap must load Agent CRM chat commands');
assert.ok(crm.includes('vp3_agent_crm_set_watch'), 'Agent CRM must expose an owner-scoped watch setter');
assert.ok(crm.includes("WHERE id=? AND owner_user_id=?"), 'watch changes must be owner scoped');
assert.ok(crm.includes("$metadata['crm_watch']"), 'watch state must live on the canonical Agent CRM contact metadata');
assert.ok(!crm.includes('CREATE TABLE'), 'watchlists must not create a second Agent CRM store');
assert.ok(crm.includes("'agent_watch_changed'"), 'watch changes must enter the Radar audit event trail');
assert.ok(crm.includes('vp3_agent_crm_audit_event'), 'Agent CRM must provide canonical audit event creation');
assert.ok(crm.includes("'radar_agent_watchlist'"), 'watched sessions must create a user-facing notification');
assert.ok(crm.includes("'radar_session'"), 'watch notifications must dedupe by Radar session rather than raw request');
assert.ok(crm.includes("(int)($contact['risk_score']??0)>=70"), 'high-risk contacts must skip lower-priority watch alerts');
assert.ok(crm.includes("source_type='radar_session'"), 'watch materialization must check existing session notifications');

assert.ok(policyApi.includes("$action==='set_contact_watch'"), 'canonical policy API must manage contact watch state');
assert.ok(policyApi.includes("'agent_policy_changed'"), 'UI/API Gateway changes must enter the contact audit trail');
assert.ok(policyApi.includes("'surface'=>'agent_gateway_api'"), 'audit trail must record the UI/API source');
assert.ok(radarChat.includes("'agent_policy_changed'"), 'Main Feed Gateway changes must enter the contact audit trail');
assert.ok(radarChat.includes("'surface'=>'main_feed'"), 'audit trail must record the Main Feed source');

assert.ok(crmChat.includes('show watched agents') || crmChat.includes('watched agents'), 'Main Feed must list watched agents');
assert.ok(crmChat.includes('stop watching'), 'Main Feed must support stop-watching commands');
assert.ok(crmChat.includes('vp3_agent_crm_set_watch'), 'watch commands must reuse canonical CRM state');
assert.ok(accessChat.includes('vp3_agent_crm_chat_tool'), 'watchlist commands must be dispatched before relationship/messaging parsing');

assert.ok(contacts.includes('data-contact-filter="watched"'), 'My Contacts must offer a Watched filter');
assert.ok(contacts.includes('data-agent-watch'), 'each Agent CRM contact must expose Watch/Stop watching');
assert.ok(contacts.includes("action:'set_contact_watch'"), 'CRM watch controls must use the canonical CSRF-protected policy API');
assert.ok(contacts.includes('id="agent-contact-'), 'Agent CRM contacts must have stable notification anchors');
assert.ok(contacts.includes('Watched contacts surface their next new session'), 'watch behavior must be explained in the contact UI');

assert.ok(mainFeed.includes('vp3_agent_crm_watchlist_refresh'), 'Main Feed polling must materialize watched-session alerts');
assert.ok(mainFeed.includes("time()-60"), 'watchlist polling must be session-throttled');
assert.ok(mainFeed.includes("'radar_agent_watchlist'"), 'watched-session notifications must appear in Main Feed activity');

console.log('AGENT_CRM_WATCHLIST_AUDIT_CONTRACT=PASS');
