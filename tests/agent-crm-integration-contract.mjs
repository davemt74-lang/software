import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const crm = read('includes/agent-crm.php');
const contacts = read('contacts.php');
const css = read('contacts.css');
const policyApi = read('api/agent-radar-policy.php');
const radarChat = read('includes/agent-radar-chat.php');
const accessChat = read('includes/agent-access-chat.php');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-crm.php';"), 'bootstrap must load the Agent CRM projection');
assert.ok(crm.includes('vp3_agent_crm_contacts'), 'Agent CRM must expose an owner-facing contact projection');
assert.ok(crm.includes('FROM vp3_agent_contacts c'), 'Agent CRM must project canonical Agent Radar contacts rather than copy them');
assert.ok(!crm.includes('CREATE TABLE'), 'Agent CRM projection must not create a duplicate contact store');
assert.ok(crm.includes('WHERE c.owner_user_id=?'), 'Agent CRM contact reads must remain owner scoped');
assert.ok(crm.includes('vp3_radar_gateway_contact_policy_map'), 'Agent CRM must reuse canonical Gateway policies');
assert.ok(crm.includes('vp3_agent_access_owner_list'), 'Agent CRM must reuse canonical Agent Messaging grants');
assert.ok(crm.includes("vp3_agent_relationship_refresh_owner($pdo,$user,$limit,false)"), 'Agent CRM must refresh relationship intelligence without generating page-load alerts');
assert.ok(crm.includes("$row['recent_activity']"), 'Agent CRM must include recent Radar activity per contact');

assert.ok(contacts.includes("data-contact-filter=\"agent\""), 'My Contacts must offer an Agents filter');
assert.ok(contacts.includes("data-contact-filter=\"high_risk\""), 'My Contacts must offer a high-risk agent filter');
assert.ok(contacts.includes("data-contact-filter=\"opportunity\""), 'My Contacts must offer an opportunity filter');
assert.ok(contacts.includes('People + AI agents + relationship history'), 'My Contacts must clearly present the unified relationship model');
assert.ok(contacts.includes('data-agent-policy="allow"'), 'Agent CRM rows must expose Allow');
assert.ok(contacts.includes('data-agent-policy="monitor"'), 'Agent CRM rows must expose Monitor');
assert.ok(contacts.includes('data-agent-policy="limit"'), 'Agent CRM rows must expose Limit');
assert.ok(contacts.includes('data-agent-policy="block"'), 'Agent CRM rows must expose Block');
assert.ok(contacts.includes('data-agent-message-decision="allow_once"'), 'pending Agent Messaging requests must support Allow once in CRM');
assert.ok(contacts.includes('data-agent-message-decision="allow"'), 'pending Agent Messaging requests must support Always allow in CRM');
assert.ok(contacts.includes('data-agent-message-decision="deny"'), 'Agent Messaging must support deny/revoke in CRM');
assert.ok(contacts.includes("policyEndpoint'=>url('/api/agent-radar-policy.php')"), 'CRM writes must reuse the canonical CSRF-protected Agent Gateway API');
assert.ok(contacts.includes('One CRM, separate privacy boundaries'), 'CRM must explain the human/agent privacy boundary');
assert.ok(contacts.includes('does not merge an AI agent into a human contact'), 'CRM must keep humans and automated identities distinct');
assert.ok(contacts.includes("url('/chat.php')"), 'My Contacts must keep Agent Chat one click away');
assert.ok(contacts.includes("url('/profile-agent.php?tab=radar')"), 'My Contacts must keep Agent Radar one click away');

assert.ok(policyApi.includes('hash_equals(csrf_token(),$csrf)'), 'per-contact CRM control writes must remain CSRF protected');
assert.ok(policyApi.includes("$action==='set_contact_policy'"), 'canonical policy API must remain the Gateway write surface');
assert.ok(policyApi.includes("$action==='access_request_decision'"), 'canonical policy API must remain the Messaging approval write surface');
assert.ok(accessChat.includes('vp3_agent_relationship_chat_tool'), 'Agent Chat must keep relationship intelligence accessible outside CRM');
assert.ok(radarChat.includes('vp3_agent_access_chat_tool'), 'Radar chat dispatcher must keep Agent Messaging/relationship controls reachable');

assert.ok(css.includes('.contacts-agent-manage'), 'Agent CRM details must have isolated styling');
assert.ok(css.includes('@media(max-width:760px)'), 'unified CRM must retain mobile behavior');
assert.ok(css.includes('.contacts-agent-row.high-risk'), 'high-risk CRM contacts must have visible treatment');

console.log('AGENT_CRM_INTEGRATION_CONTRACT=PASS');
