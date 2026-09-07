import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const foundation = read('includes/agent-radar-foundation.php');
const intelligence = read('includes/agent-relationship-intelligence.php');
const relationshipChat = read('includes/agent-relationship-chat.php');
const accessChat = read('includes/agent-access-chat.php');
const radarApi = read('api/agent-radar.php');
const chatApi = read('api/chat.php');
const gatewayJs = read('profile-agent-radar-gateway.js');
const gatewayCss = read('profile-agent-radar-gateway.css');

assert.ok(foundation.includes("'engagement_score'" ) || foundation.includes('engagement_score TINYINT'), 'Agent CRM must retain engagement score');
assert.ok(foundation.includes('value_score TINYINT'), 'Agent CRM must retain value score');
assert.ok(foundation.includes('cost_score TINYINT'), 'Agent CRM must retain cost score');
assert.ok(foundation.includes('relationship_status VARCHAR'), 'Agent CRM must retain lifecycle status');
assert.ok(foundation.includes('inferred_intent VARCHAR'), 'Agent CRM must retain inferred intent');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-relationship-intelligence.php';"), 'bootstrap must load relationship intelligence');
assert.ok(bootstrap.includes("require_once __DIR__.'/agent-relationship-chat.php';"), 'bootstrap must load relationship chat');
assert.ok(intelligence.includes('VP3_AGENT_RELATIONSHIP_WINDOW_DAYS = 30'), 'relationship scoring must use a bounded recent window');
assert.ok(intelligence.includes('VP3_AGENT_OPPORTUNITY_THRESHOLD = 80'), 'opportunity notifications must have a high-confidence threshold');
assert.ok(intelligence.includes('VP3_AGENT_OPPORTUNITY_COOLDOWN_DAYS = 7'), 'opportunity notifications must have a cooldown');
assert.ok(intelligence.includes("'commercial_interest'"), 'relationship intelligence must recognize commercial-intent paths');
assert.ok(intelligence.includes("'structured_research'"), 'relationship intelligence must recognize structured Agent Manifest/content access');
assert.ok(intelligence.includes("'restricted_probe'"), 'relationship intelligence must isolate restricted probing from positive intent');
assert.ok(intelligence.includes('messaging_tokens'), 'relationship cost must include approved Agent Messaging token consumption');
assert.ok(intelligence.includes("(int)$contact['risk_score']>=40"), 'high-risk contacts must be excluded from proactive opportunities');
assert.ok(intelligence.includes("event_type='agent_opportunity_detected'"), 'opportunity alert cooldown must be backed by canonical Radar events');
assert.ok(intelligence.includes("create_notification((int)$user['id'],'radar_agent_opportunity'"), 'qualified opportunities must use the existing notification system');
assert.ok(intelligence.includes("agent_brain_v122_upsert_system_memory"), 'qualified opportunities must become durable Agent Brain context');
assert.ok(intelligence.includes("UPDATE vp3_agent_contacts SET engagement_score=?,value_score=?,cost_score=?"), 'scores must update the existing Agent CRM contact, not a duplicate CRM');
assert.ok(intelligence.includes('COUNT(DISTINCT property_id) properties_30d'), 'relationship intelligence must understand cross-property engagement within the owner account');

assert.ok(radarApi.includes('vp3_agent_relationship_refresh_owner'), 'Agent Radar API must refresh relationship intelligence');
assert.ok(radarApi.includes('vp3_agent_relationship_enrich_portal'), 'Agent Radar API must expose opportunity/recommendation data');
assert.ok(chatApi.includes("'radar_agent_opportunity'"), 'Main Feed activity must surface qualified Agent opportunities');

assert.ok(gatewayJs.includes('Value'), 'Gateway contact UI must display value');
assert.ok(gatewayJs.includes('Cost'), 'Gateway contact UI must display cost');
assert.ok(gatewayJs.includes('Engagement'), 'Gateway contact UI must display engagement');
assert.ok(gatewayJs.includes('Opportunity'), 'Gateway contact UI must display opportunity');
assert.ok(gatewayJs.includes('Recommended next step'), 'Gateway contact UI must display the relationship recommendation');
assert.ok(gatewayJs.includes('never grant private access automatically'), 'UI must state relationship scoring cannot silently grant private access');
assert.ok(gatewayCss.includes('.profile-agent-radar-intelligence-scores'), 'relationship score UI must have dedicated responsive styling');
assert.ok(gatewayCss.includes('@media(max-width:480px)'), 'relationship score UI must remain usable on small screens');

assert.ok(relationshipChat.includes('which agents should i allow'), 'Main Feed must understand advisory allow-ranking questions');
assert.ok(relationshipChat.includes('This is advisory only'), 'chat must explicitly refuse implicit permission changes from rankings');
assert.ok(relationshipChat.includes('most valuable agent'), 'chat must support value rankings');
assert.ok(relationshipChat.includes('most expensive agent'), 'chat must support cost rankings');
assert.ok(relationshipChat.includes('inferred intent'), 'chat must explain agent intent');
assert.ok(accessChat.includes('vp3_agent_relationship_chat_tool'), 'relationship queries must enter before generic Radar allow/block matching');

console.log('AGENT_RELATIONSHIP_INTELLIGENCE_CONTRACT=PASS');
