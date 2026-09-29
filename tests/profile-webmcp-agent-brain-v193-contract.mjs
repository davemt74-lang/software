import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const bridge=read('includes/agent-profile-webmcp-v193.php');
const boundary=read('includes/agent-tool-authorization-v400.php');
const textChat=read('includes/agent-chat-runtime-v2160.php');
const streamChat=read('api/chat-stream-v121.php');
const resolver=read('includes/profile-webmcp-capability-resolver-v190.php');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(bridge,/VP3_AGENT_PROFILE_WEBMCP_V193/);
assert.match(bridge,/profile-webmcp-v100\.php/);
assert.match(bridge,/function vp3_agent_profile_webmcp_intent_v193/);
assert.match(bridge,/my\\s\+\(\?:public\\s\+\)\?profile|profile\\s\+webmcp|on\\s\+my\\s\+profile/,'routing must require explicit Profile/WebMCP language');
assert.match(bridge,/function vp3_agent_profile_webmcp_plan_v193/);
assert.match(bridge,/profile_for_user\(\$pdo,\$userId,false\)/,'planner must bind to authenticated user Profile');
assert.match(bridge,/empty\(\$profile\['is_public'\]\)\|\|empty\(\$profile\['is_active'\]\)/,'planner must require public active Profile');
assert.match(bridge,/\['surface'=>'agent_brain'\]/,'planner must use canonical agent_brain resolver surface');
assert.match(bridge,/vp3_profile_webmcp_resolve_intent_v100/,'planner must reuse canonical WebMCP intent resolver');
assert.match(bridge,/vp3_profile_webmcp_tool_catalog_v100/,'recommended tools must derive from trusted catalog');
assert.match(bridge,/'execution_allowed'=>false/,'Agent Brain must remain planning-only');
assert.match(bridge,/'requires_signed_profile_surface'=>true/,'transaction execution must hand off to signed Profile surface');
assert.match(bridge,/vp3_profile_webmcp_resume_issue_v194/,'Agent handoff must issue a session-bound resume token');
assert.match(bridge,/\$profilePath=\(string\)\$resume\['path'\]/,'Agent handoff action must use the validated same-origin resume path');
assert.doesNotMatch(bridge,/vp3_profile_webmcp_dispatch_v191|_confirm_v1(?:50|60|70|82|83)\s*\(/,'Agent Brain bridge must not execute transactional WebMCP tools');

assert.match(bridge,/function vp3_agent_profile_webmcp_authorize_plan_v193/);
assert.match(bridge,/\(int\)\(\$plan\['profile_user_id'\].*!==\$userId/s,'sanitizer must reject plans not bound to the authenticated principal');
assert.match(bridge,/hash_equals\(\(string\)\$profile\['username'\],\(string\)\(\$plan\['profile_username'\]/,'sanitizer must bind plan to exact Profile username');
assert.match(bridge,/array_fill_keys\(\$resolution\['allowed_tools'\],true\)/,'sanitizer must recompute allowed tools');
assert.match(bridge,/isset\(\$allowed\[\$name\]\)/,'unresolved tools must be removed');

assert.match(boundary,/agent-profile-webmcp-v193\.php/);
assert.match(boundary,/vp3_agent_profile_webmcp_plan_v193/);
assert.match(boundary,/vp3_agent_profile_webmcp_authorize_plan_v193/);
assert.match(boundary,/profile_webmcp_plan/);
assert.match(boundary,/profile_webmcp\.plan/);
const profileRoute=boundary.indexOf('vp3_agent_profile_webmcp_plan_v193');
const teamRoute=boundary.indexOf('agent_team_scheduling_tools_query_v610');
assert.ok(profileRoute>0&&teamRoute>profileRoute,'explicit Profile WebMCP routing must occur before generic scheduling routes');
assert.match(boundary,/vp3_agent_tool_authorize_result_v400\(\$profileWebmcp,\$user,\$query\)/,'Profile plan must pass canonical v4 authorization boundary');

assert.match(textChat,/'profile_webmcp_plan'=>is_array\(\$toolResult\['profile_webmcp_plan'\]/,'text Chat must persist sanitized Profile WebMCP plan');
assert.match(streamChat,/'profile_webmcp_plan'=>is_array\(\$toolResult\['profile_webmcp_plan'\]/,'streaming Chat must persist sanitized Profile WebMCP plan');

assert.match(resolver,/'agent_brain'/);
assert.match(resolver,/'execution_allowed'=>\$surface!=='agent_brain'/,'10A resolver must remain execution-denying for Agent Brain');

assert.match(workflow,/agent-profile-webmcp-v193\.php/);
assert.match(workflow,/profile-webmcp-agent-brain-v193-contract\.mjs/);
assert.match(workflow,/profile-webmcp-agent-brain-v193\.php/);
assert.match(recovery,/profile-webmcp-agent-brain-v193-contract\.mjs/);
assert.match(recovery,/profile-webmcp-agent-brain-v193\.php/);

console.log('PROFILE_WEBMCP_AGENT_BRAIN_V193_CONTRACT=PASS');
