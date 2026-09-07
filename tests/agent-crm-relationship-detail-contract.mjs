import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const crm = read('includes/agent-crm.php');
const contacts = read('contacts.php');
const css = read('contacts.css');
const detailCss = read('contacts-agent-detail-v312.css');

/* Agent CRM relationship analytics reuse the canonical Radar/Analytics ledger. */
assert.ok(crm.includes('function vp3_agent_crm_analytics('), 'Agent CRM must expose one batched relationship analytics projection');
assert.ok(crm.includes('vp3_radar_sessions'), 'Agent CRM analytics must read canonical Radar sessions');
assert.ok(crm.includes('vp3_radar_events'), 'Agent CRM analytics must read canonical Radar events');
assert.ok(crm.includes('vp3_radar_properties'), 'Agent CRM analytics must resolve canonical VP3 properties');
assert.ok(crm.includes("DATE_SUB(NOW(),INTERVAL 30 DAY)"), 'relationship analytics must have a bounded 30-day window');
assert.ok(crm.includes("DATE_SUB(NOW(),INTERVAL 7 DAY)"), 'relationship analytics must include current 7-day metrics');
assert.ok(crm.includes("DATE_SUB(NOW(),INTERVAL 14 DAY)"), 'relationship analytics must compare the prior week and build a 14-day timeline');
assert.ok(crm.includes("'sessions_previous_7d'"), 'relationship analytics must retain prior-week session counts');
assert.ok(crm.includes("'view_change_pct'"), 'relationship analytics must expose week-over-week view movement');
assert.ok(crm.includes("'properties'=>[]"), 'relationship analytics must expose per-property activity');
assert.ok(crm.includes("'top_paths'=>[]"), 'relationship analytics must expose top paths');
assert.ok(crm.includes("'timeline'=>[]"), 'relationship analytics must expose a daily activity timeline');
assert.ok(crm.includes('$analyticsMap=vp3_agent_crm_analytics($pdo,$owner,$ids);'), 'all visible Agent CRM contacts must be enriched in one batched analytics call');
assert.ok(crm.includes("$row['analytics']=$analyticsMap[$id]??[];"), 'batched relationship analytics must attach to canonical Agent CRM rows');
assert.ok(!/CREATE TABLE|ALTER TABLE/.test(crm), 'relationship detail must not create a parallel analytics or CRM schema');
assert.ok(!/REMOTE_ADDR|HTTP_X_FORWARDED_FOR/.test(crm), 'relationship analytics must not add raw IP tracking');

/* Each automated relationship opens one shared five-tab detail modal. */
assert.ok(contacts.includes('data-agent-detail-open'), 'Agent rows must expose an Open relationship control');
assert.ok(contacts.includes('Open relationship'), 'Agent rows must clearly label the relationship detail action');
assert.ok(contacts.includes('id="agentRelationshipModal"'), 'Contacts must use one shared relationship modal');
for (const [key,label] of [['overview','Overview'],['analytics','Analytics'],['activity','Activity'],['permissions','Permissions'],['intelligence','Intelligence']]) {
  assert.ok(contacts.includes(`data-agent-detail-tab="${key}"`), `relationship modal must expose ${label} tab`);
  assert.ok(contacts.includes(`>${label}</button>`), `relationship modal must visibly label ${label}`);
}
assert.ok(!contacts.includes('<details class="contacts-agent-manage">'), 'old buried Manage contact disclosure must be removed from Agent rows');
assert.ok(contacts.includes('contacts_agent_client_record'), 'server must project a bounded owner-visible relationship record for the modal');
assert.ok(contacts.includes("'agents'=>$agentClientContacts"), 'modal data must be bootstrapped from the canonical Agent CRM projection');
assert.ok(contacts.includes('JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT'), 'embedded relationship JSON must use HTML-safe JSON flags');
assert.ok(contacts.includes("const esc=value=>"), 'dynamic modal rendering must HTML-escape server strings');

/* Overview / Analytics / Activity are relationship views, not new tracking collectors. */
assert.ok(contacts.includes('function renderOverview(agent)'), 'modal must render a relationship overview');
assert.ok(contacts.includes('function renderAnalytics(agent)'), 'modal must render per-agent analytics');
assert.ok(contacts.includes('Properties / sites'), 'Analytics must show profile/connected-site property activity');
assert.ok(contacts.includes('Top paths'), 'Analytics must show top paths');
assert.ok(contacts.includes('Relationship activity timeline'), 'Analytics must show recent trend/history');
assert.ok(contacts.includes('function renderActivity(agent)'), 'modal must render Agent Radar activity history');
assert.ok(contacts.includes('Agent Radar ledger'), 'activity history must identify its canonical evidence source');
assert.ok(!/navigator\.|document\.cookie|localStorage|sessionStorage/.test(contacts), 'relationship detail must not introduce browser fingerprinting or a second analytics collector');

/* Permissions stay on the canonical Agent Gateway / Agent Messaging APIs. */
assert.ok(contacts.includes("'policyEndpoint'=>url('/api/agent-radar-policy.php')"), 'relationship permissions must use the canonical Agent Radar policy endpoint');
assert.ok(contacts.includes("action:'set_contact_watch'"), 'watchlist changes must use the existing Agent CRM action');
assert.ok(contacts.includes("action:'set_contact_policy'"), 'website access changes must use the existing Agent Gateway action');
assert.ok(contacts.includes("action:'access_request_decision'"), 'Agent Messaging decisions must use the existing access-request action');
for (const action of ['allow','monitor','limit','block']) {
  assert.ok(contacts.includes(`data-agent-policy=\"${action}\"`) || contacts.includes(`data-agent-policy="${action}"`), `Permissions must expose ${action} Gateway control`);
}
assert.ok(contacts.includes('These controls use the canonical Agent Gateway contact policy'), 'Permissions tab must explain that CRM is not a second policy authority');

/* Intelligence is evidence-aware and keeps human contacts separate. */
assert.ok(contacts.includes('function renderIntelligence(agent)'), 'modal must expose relationship intelligence');
assert.ok(contacts.includes('No unsupported intent is inferred.'), 'relationship intelligence must not manufacture intent when evidence is absent');
assert.ok(contacts.includes('VP3 does not merge an AI agent into a human contact'), 'human and automated CRM privacy boundaries must stay explicit');

/* Detail UI is responsive and loaded from the existing contacts stylesheet. */
assert.ok(css.startsWith("@import url('contacts-agent-detail-v312.css');"), 'existing contacts surface must load the dedicated relationship detail styles');
assert.ok(detailCss.includes('.contacts-agent-modal-panel'), 'relationship modal must have a bounded panel layout');
assert.ok(detailCss.includes('.contacts-agent-tabs'), 'relationship tabs must be styled');
assert.ok(detailCss.includes('.contacts-detail-metrics'), 'relationship metrics must be styled');
assert.ok(detailCss.includes('.contacts-timeline-track'), 'Analytics trend visualization must be styled');
assert.ok(detailCss.includes('@media(max-width:560px)'), 'relationship modal must have a small-screen layout');

console.log('AGENT_CRM_RELATIONSHIP_DETAIL_CONTRACT=PASS');
