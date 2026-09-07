import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const enhancer = read('contacts-intelligence-outcomes-v316.js');
const shell = read('member-shell-v77.js');
const contacts = read('contacts.php');
const crm = read('includes/agent-crm.php');
const outcome = read('includes/agent-radar-conversion-outcome-v315.php');

assert.doesNotThrow(() => new Function(enhancer), 'CRM Intelligence learned-outcome enhancement must remain valid JavaScript');
assert.doesNotThrow(() => new Function(shell), 'shared member shell must remain valid JavaScript');

/* The enhancement is contacts-only and consumes the existing owner-scoped CRM projection. */
assert.ok(shell.includes("document.body.classList.contains('contacts-page')"), 'member shell must load the enhancement only on Contacts');
assert.ok(shell.includes('contacts-intelligence-outcomes-v316.js'), 'Contacts must load the dedicated learned-outcome enhancement');
assert.ok(enhancer.includes('window.VP3_CONTACTS'), 'enhancement must consume the existing server-owned Contacts bootstrap');
assert.ok(enhancer.includes("String(item?.event_type || '') === 'agent_brain_outcome_learned'"), 'Intelligence must select only canonical Brain learning receipts');
assert.ok(enhancer.includes("[data-agent-detail-tab=\"intelligence\"].active"), 'enhancement must only decorate the Intelligence tab');
assert.ok(enhancer.includes('data-agent-detail-open'), 'enhancement must bind the currently opened Agent CRM relationship identity');
assert.ok(enhancer.includes('agents[String(activeAgentId)]'), 'relationship data must resolve by exact bootstrapped contact ID');

/* The visible copy explains what was learned without overstating inferred intent. */
assert.ok(enhancer.includes('Agent Brain learning'), 'Intelligence must visibly identify Agent Brain learning');
assert.ok(enhancer.includes('Verified relationship outcomes'), 'Intelligence must distinguish verified outcomes from inferred relationship signals');
assert.ok(enhancer.includes('Verified conversion outcome'), 'learned conversion must be visibly labeled');
assert.ok(enhancer.includes('Source: Agent Radar'), 'learned outcome must visibly retain Agent Radar provenance');
assert.ok(enhancer.includes('Closed from canonical Agent Radar evidence, not inferred intent.'), 'UI must explain the evidence boundary');
assert.ok(enhancer.includes('item.summary'), 'Intelligence must show the canonical Radar audit summary instead of synthesizing a new outcome claim');

/* No second read API, store, tracking collector or policy path is introduced. */
assert.ok(!/fetch\s*\(/.test(enhancer), 'Intelligence enhancement must not add another API read');
assert.ok(!/localStorage|sessionStorage|document\.cookie|navigator\./.test(enhancer), 'Intelligence enhancement must not add identity or persistence collection');
assert.ok(!/data-agent-policy|access_request_decision|set_contact_policy/.test(enhancer), 'learned-outcome view must not mutate Agent Gateway or messaging permissions');
assert.ok(!/CREATE TABLE|ALTER TABLE/.test(outcome), 'learned outcome must remain on canonical stores');

/* Existing CRM/Radar path remains the data authority. */
assert.ok(outcome.includes("'agent_brain_outcome_learned'"), 'v315 must remain the canonical producer of learned outcome receipts');
assert.ok(crm.includes("$row['recent_activity']=$recent[$id]??[];"), 'Agent CRM must remain the server-side recent activity projection');
assert.ok(contacts.includes("'agents'=>$agentClientContacts"), 'Contacts must keep bootstrapping owner-visible Agent CRM records');
assert.ok(contacts.includes('data-agent-detail-tab="intelligence"'), 'canonical relationship modal must retain the Intelligence tab');

/* Dynamic rendering remains safe and disposable. */
assert.ok(enhancer.includes("replace(/[&<>\"']/g"), 'dynamic server text must be HTML escaped');
assert.ok(enhancer.includes("body.querySelector('[data-agent-brain-learning]')?.remove()"), 'decoration must replace rather than duplicate itself');
assert.ok(enhancer.includes('new MutationObserver'), 'enhancement must survive canonical modal rerenders');
assert.ok(enhancer.includes('observer.disconnect()'), 'modal observer must be cleaned up on page exit');

console.log('CRM_INTELLIGENCE_LEARNED_OUTCOMES_CONTRACT=PASS');
