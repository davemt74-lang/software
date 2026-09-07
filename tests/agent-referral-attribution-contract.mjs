import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const upgrade = read('upgrade.php');
const attribution = read('includes/agent-referral-attribution.php');
const relationship = read('includes/agent-relationship-intelligence.php');
const relationshipChat = read('includes/agent-relationship-chat.php');
const analytics = read('vp3-analytics.js');
const collect = read('api/analytics-collect.php');
const message = read('api/agent-message.php');
const chat = read('api/chat.php');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-referral-attribution.php';"), 'bootstrap must load Agent referral attribution');
assert.ok(bootstrap.includes('vp3_agent_referral_request_boot();'), 'native VP3 profile requests must be eligible for first-party referral capture');
assert.ok(upgrade.includes('vp3_agent_referral_schema_ready()'), 'database readiness must include referral attribution');
assert.ok(upgrade.includes('vp3_agent_referral_ensure_schema();'), 'database upgrade must install referral attribution');

assert.ok(attribution.includes('CREATE TABLE IF NOT EXISTS vp3_agent_referrals'), 'attribution must persist hashed referral grants');
assert.ok(attribution.includes('CREATE TABLE IF NOT EXISTS vp3_agent_referral_events'), 'attribution must persist deduped outcome events');
assert.ok(attribution.includes('UNIQUE KEY uq_vp3_agent_referral_token (token_hash)'), 'raw referral tokens must not be the database identity');
assert.ok(attribution.includes("hash('sha256',$token)"), 'referral tokens must be hashed before storage/lookup');
assert.ok(attribution.includes('UNIQUE KEY uq_vp3_agent_referral_event (referral_id,session_hash,event_type)'), 'same referral/session/outcome must be deduped');
assert.ok(attribution.includes("hash('sha256','vp3-agent-referral|'"), 'browser/session attribution must be stored as a one-way session hash');
assert.ok(attribution.includes("vp3_radar_looks_automated($userAgent)"), 'agent/crawler requests must never count as human referral outcomes');
assert.ok(attribution.includes("VP3_ANALYTICS_CONVERSION_EVENTS"), 'conversion attribution must use the canonical Analytics conversion catalog');
assert.ok(attribution.includes("str_starts_with($eventName,'conversion.')"), 'custom conversion.* events must be supported');
assert.ok(attribution.includes("$dedupeType=$conversion?'conversion:'.$eventName:'referral'"), 'ordinary non-conversion events must remain referral-only');
assert.ok(attribution.includes("'agent_human_referral'"), 'human arrivals must create canonical Radar referral events');
assert.ok(attribution.includes("'agent_referral_conversion'"), 'explicit conversions must create canonical Radar conversion events');
assert.ok(attribution.includes("'radar_agent_conversion'"), 'explicit conversions must use the existing notification system');
assert.ok(attribution.includes("'attribution'=>'first_party_token'"), 'Radar events must identify the attribution method');
assert.ok(!attribution.includes('REMOTE_ADDR'), 'referral attribution must not persist or depend on raw IP addresses');
assert.ok(!attribution.includes('email'), 'referral attribution runtime must not persist human email identity');
assert.ok(attribution.includes("str_contains($path,'/api/')"), 'native referral capture must not count API/status polling routes as profile referrals');

assert.ok(analytics.includes('sessionStorage.setItem(referralKey,token)'), 'connected-site attribution must remain first-party session storage');
assert.ok(analytics.includes('payload.agent_referral=agentReferral'), 'Analytics events must carry the referral token when present');
assert.ok(!analytics.includes('localStorage.setItem(referralKey'), 'referral attribution must not create a long-lived cross-session browser identifier');
assert.ok(collect.includes('vp3_agent_referral_external_collect'), 'connected-site Analytics collector must apply referral attribution server-side');

assert.ok(message.includes('vp3_agent_referral_create_for_message'), 'approved Agent Messaging must be able to issue a human referral URL');
assert.ok(message.includes("'attribution'=>'first_party_token'"), 'Agent Messaging response must label the referral method');
assert.ok(message.includes('VP3 stores only a hash of the referral token'), 'Agent Messaging must disclose the privacy boundary to calling agents');

assert.ok(relationship.includes('referrals_30d'), 'relationship value must consume explicit attributed referrals');
assert.ok(relationship.includes('conversions_30d'), 'relationship value must consume explicit attributed conversions');
assert.ok(relationship.includes('conversion_value_30d'), 'relationship value must support attributed conversion value');
assert.ok(relationship.includes('referral_count=?,conversion_count=?'), 'explicit attribution must become authoritative CRM referral/conversion counts');
assert.ok(relationshipChat.includes('ordinary HTTP referrers are not counted as AI-generated customers'), 'Main Feed must explain the strict outcome attribution semantics');
assert.ok(relationshipChat.includes('generated any customers'), 'Main Feed must answer whether AI agents generated customers');

// This notification is added to Main Feed activity in the same feature stack.
assert.ok(chat.includes("'radar_agent_opportunity'"), 'relationship opportunities must remain visible in Main Feed');

console.log('AGENT_REFERRAL_ATTRIBUTION_CONTRACT=PASS');
