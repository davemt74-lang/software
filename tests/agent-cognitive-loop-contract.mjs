import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const runtime = read('includes/agent-runtime-v125.php');
const loop = read('includes/agent-cognitive-loop-v310.php');
const analytics = read('includes/vp3-analytics-intelligence.php');
const continuity = read('includes/agent-chat-continuity-v101.php');
const surface = read('includes/agent-surface-context-v131.php');
const voice = read('chat-voice.js');

/* One explicit Agent Brain cognitive loop is loaded and activated. */
assert.ok(bootstrap.includes("require_once __DIR__.'/agent-cognitive-loop-v310.php';"), 'bootstrap must load the canonical cognitive loop');
assert.ok(bootstrap.includes('agent_cognitive_loop_v310_boot();'), 'bootstrap must activate/schedule the cognitive loop');
assert.ok(runtime.includes("$kind==='cognitive-loop'"), 'existing background worker must execute cognitive-loop jobs');
assert.ok(runtime.includes('agent_cognitive_loop_v310_run($user)'), 'background worker must call the canonical Brain loop');
assert.ok(loop.includes('OBSERVE -> CORRELATE -> REMEMBER -> PRIORITIZE -> PLAN -> SURFACE -> LEARN'), 'cognitive loop stages must be explicitly defined');
assert.ok(loop.includes("'loop'=>'observe-correlate-remember-prioritize-plan-surface-learn'"), 'persisted Brain state must identify the cognitive loop');
assert.ok(loop.includes("agent_brain_v122_upsert_system_memory($user,'cognitive_state','main-loop'"), 'current cognitive state must live in Agent Brain');
assert.ok(loop.includes('VP3_AGENT_COGNITIVE_LOOP_INTERVAL_SECONDS_V310 = 300'), 'loop must be bounded by a five-minute schedule interval');
assert.ok(!/CREATE TABLE|ALTER TABLE/.test(loop), 'cognitive loop must not create a parallel persistence schema');

/* Observation and correlation reuse the canonical subsystems. */
assert.ok(loop.includes('agent_memory_v123_reconcile_user'), 'loop must reconcile Agent Brain memory lifecycle');
assert.ok(loop.includes('vp3_agent_relationship_refresh_owner'), 'loop must refresh Agent Radar/CRM relationship intelligence');
assert.ok(loop.includes('vp3_agent_crm_watchlist_refresh'), 'loop must consume the existing CRM watchlist path');
assert.ok(loop.includes('vp3_analytics_intelligence_signals_v310'), 'loop must consume Analytics intelligence');
assert.ok(loop.includes('agent_proactive_v123_suggestions'), 'loop must reuse the existing proactive evidence/scoring pool');
assert.ok(loop.includes('agent_action_v124_source_feedback'), 'loop must reuse learned source outcomes');
assert.ok(loop.includes('agent_action_v124_suppression'), 'loop must reuse suggestion suppression/cooldowns');
assert.ok(loop.includes('agent_action_v124_risk'), 'loop must reuse canonical action risk classification');
assert.ok(loop.includes('agent_action_v124_plan'), 'loop must reuse canonical action planning');

/* Analytics becomes Brain evidence, including profile + website traffic spikes. */
assert.ok(bootstrap.includes("require_once __DIR__.'/vp3-analytics-intelligence.php';"), 'bootstrap must load Analytics intelligence');
assert.ok(analytics.includes('profile_visit_sessions'), 'native profile traffic must feed Analytics intelligence');
assert.ok(analytics.includes('vp3_radar_sessions'), 'connected site and Agent traffic must use canonical Radar/Analytics sessions');
assert.ok(analytics.includes("event_type='analytics_traffic_spike'"), 'spikes must be persisted as canonical Radar/Analytics events');
assert.ok(analytics.includes("create_notification($uid,'analytics_traffic_spike'"), 'material traffic spikes must use the existing notification system');
assert.ok(analytics.includes('current_agent_sessions'), 'spike evidence must distinguish recognized/automated Agent sessions');
assert.ok(analytics.includes('vp3_analytics_intelligence_top_agents_v310'), 'traffic spikes must correlate the most active recognized agents when available');
assert.ok(analytics.includes("without assuming visitor intent") || analytics.includes('without assuming visitor intent.'), 'Analytics intelligence must avoid unsupported intent claims');
assert.ok(!/REMOTE_ADDR|document\.cookie|localStorage/.test(analytics), 'server Analytics intelligence must not introduce visitor fingerprinting or raw IP collection');
assert.ok(!/CREATE TABLE|ALTER TABLE/.test(analytics), 'Analytics intelligence must reuse existing stores');

/* Agent Brain owns prioritization; surfaces consume it. */
assert.ok(surface.indexOf('agent_cognitive_loop_v310_state') < surface.indexOf('agent_proactive_v123_suggestions'), 'surface context must consume Brain priorities before any cold-start fallback');
assert.ok(surface.includes("'source'=>'agent_brain_cognitive'"), 'surface context must identify cognitive priorities as Agent Brain state');
assert.ok(continuity.includes('agent_cognitive_loop_v310_priority_items'), 'Main Feed return briefing must consume Agent Brain priorities');
assert.ok(continuity.includes('$brainPriorities?:'), 'legacy ecosystem scan must be a cold-start fallback only');
assert.ok(continuity.includes("'voice_summary'=>"), 'return briefing must expose a voice summary of the same Brain priorities');

/* Priority changes use the normal conversation instead of a parallel updates panel. */
assert.ok(loop.includes('agent_chat_v101_append_ecosystem_message'), 'material priority changes must use the canonical Main Feed writer');
assert.ok(loop.includes("'source'=>'agent_cognitive_loop'"), 'cognitive messages must be explicitly sourced');
assert.ok(loop.includes("'skip_brain_archive'=>true"), 'Brain must not parse its own cognitive briefing back into itself');
assert.ok(continuity.includes("empty($context['skip_brain_archive'])"), 'canonical chat writer must honor the cognitive feedback-loop guard');
assert.ok(!loop.includes('agent-update-overlay') && !loop.includes('agent-updates-overlay'), 'cognitive priorities must not recreate the removed Agent Updates panel');

/* Canonical premium voice already speaks the intro update list; Brain priorities feed that same list. */
assert.ok(voice.includes('const boot=window.STONEFELLOW_CHAT_VOICE_BOOT||{};'), 'canonical voice must own the server return briefing');
assert.ok(voice.includes('function introTexts(intro)'), 'canonical voice must have one intro formatter');
assert.ok(voice.includes('Here are the priorities I found.'), 'canonical voice must speak prioritized intro updates');
assert.ok(voice.includes('speakAnswer(pendingIntroSpeech)'), 'priority briefing must use the canonical premium voice speech path');

console.log('AGENT_COGNITIVE_LOOP_CONTRACT=PASS');
