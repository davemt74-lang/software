import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const operator = read('includes/agent-operator-intelligence.php');
const accessChat = read('includes/agent-access-chat.php');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-operator-intelligence.php';"), 'bootstrap must load Agent operator intelligence');
assert.ok(operator.includes('vp3_agent_operator_summaries'), 'operator intelligence must expose owner relationship summaries');
assert.ok(operator.includes('FROM vp3_agent_contacts'), 'operator intelligence must aggregate canonical Agent CRM contacts');
assert.ok(operator.includes('WHERE owner_user_id=?'), 'operator summaries must remain owner scoped');
assert.ok(operator.includes('GROUP BY operator_name'), 'operator intelligence must group child agents by operator organization');
assert.ok(operator.includes('vp3_agent_operator_contacts'), 'operator details must expose child Agent CRM contacts');
assert.ok(operator.includes('c.owner_user_id=?'), 'operator child-contact reads must remain owner scoped');
assert.ok(operator.includes('vp3_agent_operator_policy'), 'operator intelligence must reuse operator-wide Agent Gateway rules');
assert.ok(operator.includes('vp3_radar_gateway_scoped_rules'), 'operator policy must read the canonical Gateway rule system');
assert.ok(operator.includes('private_reputation_score'), 'operator intelligence must calculate an owner-private reputation score');
assert.ok(operator.includes('not a global blacklist'), 'operator summaries must explicitly reject a global blacklist model');
assert.ok(operator.includes('not shared across VP3 customers'), 'operator reputation must explicitly stay private across customers');
assert.ok(!operator.includes('CREATE TABLE'), 'operator intelligence must not create a global/shared reputation store');
assert.ok(!operator.includes('INSERT INTO'), 'operator intelligence must remain a read model and not publish reputation data');
assert.ok(operator.includes("'what has'"), 'operator chat intent must support natural operator activity questions');
assert.ok(operator.includes("'operator reputation'"), 'operator chat intent must support reputation questions');
assert.ok(operator.includes('what\\s+has'), 'operator parser must support “what has OpenAI been doing” style questions');
assert.ok(operator.includes('show\\s+(.+?)\\s+agents?'), 'operator parser must support “show OpenAI agents” questions');
assert.ok(accessChat.includes('vp3_agent_operator_chat_tool'), 'Main Feed dispatcher must call operator intelligence');
const operatorPos=accessChat.indexOf('vp3_agent_operator_chat_tool');
const crmPos=accessChat.indexOf('vp3_agent_crm_chat_tool');
const relationPos=accessChat.indexOf('vp3_agent_relationship_chat_tool');
assert.ok(operatorPos>=0&&crmPos>operatorPos&&relationPos>crmPos, 'operator read queries must dispatch before CRM watch and relationship parsing');

console.log('AGENT_OPERATOR_INTELLIGENCE_CONTRACT=PASS');
