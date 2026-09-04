import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(path, 'utf8');

const crm = read('includes/crm-v180.php');
const dashboard = read('admin/crm.php');
const lead = read('admin/crm-lead.php');
const demo = read('book-demo.php');
const bootstrap = read('includes/bootstrap.php');
const ecosystem = read('includes/agent-ecosystem-v118.php');
const adminHeader = read('admin/_header.php');
const sql = read('upgrade-stonefellow-v180-crm.sql');

assert.match(crm, /function crm_v180_can_manage/);
assert.match(crm, /user_has_role\('admin'/,
  'CRM authorization must be tied to the Admin account type');
assert.match(crm, /CRM access is restricted to Stonefellow Admin accounts/);

for (const table of ['crm_contacts', 'crm_leads', 'crm_activities', 'crm_tasks']) {
  assert.match(crm, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));
  assert.match(sql, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));
}

assert.match(crm, /crm_v180_create_demo_lead/);
assert.match(crm, /bool \$notify = true/,
  'Demo lead creation must support quiet historical imports');
assert.match(crm, /\$pdo, false\)/,
  'Historical Book a Demo imports must not flood notifications or Agent Chat');
assert.match(crm, /crm_v180_notify_new_lead/);
assert.match(crm, /user_account_types_for_user_id/,
  'Admin recipients must carry their complete account-type context into Agent Chat');
assert.match(crm, /agent_chat_v101_append_ecosystem_message/,
  'New CRM demo requests should be pushed into Admin Agent Chat');
assert.match(crm, /crm_v180_agent_opportunities/,
  'CRM must expose proactive follow-up opportunities');
assert.match(crm, /follow-up date that has passed/);
assert.match(crm, /Upcoming demo:/);
assert.match(crm, /Unassigned CRM lead:/);
assert.match(crm, /CRM lead may be stalled:/);
assert.doesNotMatch(crm, /New demo lead:/,
  'New leads already create a notification and direct Agent Chat item and must not be duplicated by proactive scan');
assert.match(crm, /function crm_v180_parse_datetime/);
assert.match(crm, /CRM tasks can only be assigned to an Admin account/);

assert.match(demo, /crm_v180_create_demo_lead/,
  'Book a Demo submissions must feed the CRM');
assert.match(demo, /crm_v180_schema_ready\(\$pdo\)/,
  'Anonymous Book a Demo requests must not trigger schema DDL');
assert.match(demo, /Public requests never perform schema DDL/);
assert.match(demo, /if \(!\$crmStored\)/,
  'Existing contact-message notification must remain as a fallback');

assert.match(bootstrap, /crm-v180\.php/,
  'CRM helpers must be available throughout the application');
assert.match(ecosystem, /crm_v180_agent_opportunities/,
  'Proactive ecosystem scan must include CRM opportunities');
assert.match(ecosystem, /crm_v180_can_manage\(\$user\)/,
  'Proactive CRM data must remain Admin-only');
assert.match(ecosystem, /topic'\]\s*===\s*'Book a Demo'/,
  'Generic Messages scan should suppress duplicate Book a Demo opportunities once CRM is ready');

assert.match(dashboard, /crm_v180_require_admin/);
assert.match(lead, /crm_v180_require_admin/);
assert.match(dashboard, /Dashboard/);
assert.match(dashboard, /Pipeline/);
assert.match(dashboard, /Follow-up tasks/);
assert.match(lead, /Activity timeline/);
assert.match(lead, /Mark Contacted/);
assert.match(lead, /Create Task/);
assert.match(lead, /crm_v180_parse_datetime\(\$nextFollow/,
  'Lead follow-up dates must be validated instead of silently coercing invalid dates');
assert.match(lead, /crm_v180_parse_datetime\(\$demoAt/,
  'Demo dates must be validated instead of silently coercing invalid dates');
assert.match(lead, /\$existingClosed/,
  'Saving a closed lead must preserve its original closed timestamp');
assert.match(lead, /stage_changed_at=CASE WHEN \?=1/,
  'Stage-change timestamps must be driven by the pre-update comparison');

const dashboardOpportunityScans = dashboard.match(/crm_v180_agent_opportunities/g) ?? [];
assert.equal(dashboardOpportunityScans.length, 1,
  'The CRM dashboard should calculate its attention list once per request');

assert.match(adminHeader, /\$adminCrmVisible/);
assert.match(adminHeader, /crm_v180_can_manage\(\$user\)/,
  'Admin navigation must not expose CRM to non-admin account types');
assert.match(adminHeader, />CRM</);

console.log('CRM v180 backend contract: PASS');
