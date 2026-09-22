import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const funnel = read('includes/vp3-funnel.php');
const shell = read('includes/vp3-public.php');
const signup = read('signup.php');
const login = read('login.php');
const pricing = read('pricing.php');
const demo = read('book-demo.php');
const index = read('index.php');
const ctaEndpoint = read('api/public-funnel-event.php');
const ctaClient = read('vp3-public-funnel.js');
const onboarding = read('includes/onboarding-intelligence.php');

assert.match(funnel, /VP3_FUNNEL_SESSION_KEY/, 'funnel intent must use one canonical session key');
assert.match(funnel, /function vp3_funnel_safe_return/, 'funnel must own one safe return validator');
assert.match(funnel, /rawurldecode\(/, 'safe return validation must inspect encoded redirect variants');
assert.match(funnel, /function vp3_funnel_capture_public_source/, 'shared public pages must have a canonical source capture helper');
assert.match(funnel, /'origin'/, 'funnel must preserve first-touch public origin separately from current source');
for (const source of ['chrome-extension','annotations','video-meetings','agent-analytics']) assert.ok(funnel.includes(`'${source}'`), `current public source must be recognized: ${source}`);
for (const workflow of ['workflow.browser','workflow.meetings','workflow.analytics','workflow.commerce','workflow.homeserver','workflow.calendar','workflow.booking','workflow.teams']) assert.ok(funnel.includes(workflow), `public funnel must seed onboarding interest ${workflow}`);
assert.match(funnel, /function vp3_funnel_finish_auth/, 'auth completion must have one canonical funnel handoff');
assert.match(funnel, /onboarding_intelligence_save_progress/, 'funnel intent must reuse canonical onboarding intelligence');
assert.match(funnel, /featureInterests/, 'public source must be able to become onboarding feature interest');
assert.doesNotMatch(funnel, /CREATE TABLE|ALTER TABLE|onboarding_intelligence_ensure_schema\(/, 'public funnel requests must never perform onboarding DDL');
assert.doesNotMatch(funnel, /REMOTE_ADDR|HTTP_USER_AGENT|session_id\(/, 'funnel telemetry must not collect visitor fingerprinting data');
assert.match(funnel, /'cta_click'/, 'first-party funnel telemetry must allow bounded CTA click events');
assert.match(ctaEndpoint, /vp3_funnel_event\('cta_click'/, 'CTA endpoint must use canonical funnel telemetry');
assert.doesNotMatch(ctaEndpoint, /REMOTE_ADDR|HTTP_USER_AGENT|session_id\(|CREATE TABLE|ALTER TABLE/, 'CTA endpoint must not collect fingerprinting data or perform DDL');
assert.match(ctaClient, /navigator\.sendBeacon/, 'CTA client should use navigation-safe beacon delivery');
assert.match(index, /vp3_funnel_capture_public_source\('index'\)/, 'homepage must establish public source/origin attribution');

assert.match(shell, /require_once __DIR__\.'\/vp3-funnel\.php'/, 'canonical public shell must load funnel continuity');
assert.match(shell, /vp3_funnel_capture_public_source\(\)/, 'canonical public shell must capture the marketing source');
assert.match(shell, /vp3-public-header<\?= \$compact \? ' compact' : '' \?>/, 'compact auth header behavior must be preserved');
assert.match(shell, /vp3_public_link\('\/signup\.php'\)/, 'shared Get VP3 action must preserve funnel query state');
assert.match(shell, /vp3_public_link\('\/login\.php'\)/, 'shared login action must preserve funnel query state');

for (const [name, source] of Object.entries({signup, login})) {
  assert.match(source, /vp3_funnel_capture\(/, `${name} must restore incoming funnel state before auth`);
  assert.match(source, /vp3_funnel_finish_auth\(login_destination\(\), current_user\(\)\)/, `${name} must hand intent into canonical onboarding before redirect`);
  assert.match(source, /\['plan','billing','origin','source','return_to'\]/, `${name} form must preserve validated funnel fields across validation failures`);
  assert.doesNotMatch(source, /vp3_funnel_take_destination\(login_destination\(\)\)/, `${name} must not bypass onboarding persistence with the legacy redirect-only consumer`);
}

assert.match(signup, /subscription_assign_default_trial\(\$userId\)/, 'signup must still assign the configured default Free Trial');
assert.match(signup, /\$_SESSION\['subscription_onboarding'\]\s*=\s*1/, 'existing onboarding compatibility flag must remain intact');
assert.match(onboarding, /feature_interest_json/, 'canonical onboarding store must remain the existing user-agent preference record');
assert.match(onboarding, /onboarding_intelligence_package_recommendation/, 'existing package recommendation must remain the consumer of onboarding feature interests');

assert.match(pricing, /vp3_funnel_url\(\$funnelPath,\['plan'=>\(string\)\$package\['id'\],'billing'=>'monthly','source'=>'pricing'\]\)/, 'pricing CTAs must carry plan, billing, and source intent');
assert.match(pricing, /searchParams\.set\('billing',billing\)/, 'billing toggle must update CTA intent before navigation');
assert.match(demo, /vp3_funnel_event\('demo_submit'\)/, 'demo funnel must retain anonymous conversion telemetry');
assert.match(demo, /\['plan','billing','origin','source','return_to'\]/, 'demo form must preserve validated funnel state across submission');

console.log('public funnel + onboarding continuity contract: PASS');
