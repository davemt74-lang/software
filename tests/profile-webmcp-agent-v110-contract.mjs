import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const service=read('includes/profile-agent-public-service-v110.php');
const answer=read('includes/profile-agent-answer-v1.php');
const webmcp=read('includes/profile-webmcp-v100.php');
const resolver=read('includes/profile-webmcp-capability-resolver-v190.php');
const webapi=read('api/profile-webmcp-v100.php');
const router=read('includes/profile-webmcp-tool-router-v191.php');
const profileApi=read('api/profile-agent.php');
const runtime=read('profile-webmcp-v100.js');

for(const name of ['vp3.agent.get','vp3.agent.conversation.get','vp3.agent.message.send','vp3.agent.owner_handoff.request']){
  assert.match(webmcp,new RegExp(name.replaceAll('.','\\.')),'server catalog '+name);
  assert.match(runtime,new RegExp(name.replaceAll('.','\\.')),'browser catalog '+name);
}
assert.match(resolver,/\$viewerId<1\|\|\$viewerId!==\$ownerUserId/,'owner must not receive visitor Profile Agent capability');
assert.match(webmcp,/registeredCapabilities/,'intent resolver must derive adapter readiness from registered tools');
assert.match(webmcp,/requires_domain_adapter/,'intent resolver must report only genuinely missing adapters');

assert.match(service,/vp3_profile_agent_public_conversation_v390/,'conversation authority must use v3.90 boundary');
assert.match(service,/vp3_profile_agent_public_admit_v1\(\$pdo,\$ctx\)/,'canonical admission must apply before starting or continuing a thread');
assert.match(service,/c\.owner_user_id=\? AND c\.profile_session_id=\?/,'starting another thread must not reset owner/session admission');
assert.match(service,/vp3_profile_agent_public_admit_v1\(\$pdo,\$ctx\);[\s\S]*vp3_profile_agent_public_create_bound_v1/,'admission precedes new conversation allocation');
assert.match(service,/profile_agent_context\(/,'approved Profile Agent context retained');
assert.match(service,/profile_agent_transcript_brain_context_v255/,'transcript brain context retained');
assert.match(service,/require_once __DIR__\.'\/profile-agent-answer-v1\.php'/,'public service must load the canonical Profile answer helper');
assert.match(service,/vp3_profile_answer_generate_v1\(\$pdo,\$ctx,\$query,\$history,\$context,\$guard\)/,'public service must use shared inference with its current-authority guard');
assert.match(answer,/homeserver_profile_v235_answer\(\$owner,\$query,\$history,\$context,true\)/,'HomeServer Profile Agent compute retains the structured response contract');
assert.match(answer,/ai_generate_chat_response\(\$query,\$history,\$context,\$ctx\['owner_user'\],'profile_agent',\$control\)/,'remote inference must use owner quota and explicit Profile Agent scope');
assert.doesNotMatch(service+answer,/chat_local_answer/,'a deterministic retrieval reply must not impersonate a model answer');
assert.match(answer,/'quota_exhausted'=>'quota_handoff'/,'exhausted account allowance must select owner handoff');
assert.match(service,/if\(\$needsOwner\)\{\s*vp3_profile_owner_request_create_v1/,'inference handoff must persist a durable owner request');
assert.match(service,/profile_agent_needs_owner/,'owner escalation retained');
assert.match(service,/vp3_profile_agent_agent_may_reply_v390\(\$current\)/,'owner takeover must be rechecked after model generation');
assert.match(service,/profile:identity','profile:rules/,'internal identity/rule context must not be emitted as source citations');
assert.match(service,/OWNER_VISITOR_PREVIEW_REQUIRED/,'owner-as-visitor must fail closed');
assert.match(service,/CONVERSATION_NOT_FOUND/,'conversation mismatch must fail closed');

assert.match(router,/vp3_profile_agent_public_context_v110/);
assert.match(router,/vp3_profile_agent_public_message_service_v110/);
assert.match(router,/vp3_profile_agent_public_request_owner_v110/);
assert.match(webapi,/vp3_profile_webmcp_dispatch_v191/);
assert.match(webapi,/VP3ProfileAgentPublicException/);

assert.match(profileApi,/profile-agent-public-service-v110\.php/,'legacy public chat API must load shared service');
assert.match(profileApi,/vp3_profile_agent_public_state_service_v110/,'legacy state must use shared service');
assert.match(profileApi,/vp3_profile_agent_public_message_service_v110/,'legacy message must use shared service');
assert.match(profileApi,/vp3_profile_agent_public_poll_service_v110/,'legacy polling must use shared service');
const publicStart=profileApi.indexOf('$username=profile_username_normalize');
assert.ok(publicStart>0);
const publicBlock=profileApi.slice(publicStart);
assert.doesNotMatch(publicBlock,/INSERT INTO profile_agent_messages/,'public controller must not duplicate canonical message persistence');
assert.doesNotMatch(publicBlock,/chat_remote_answer|chat_local_answer/,'public controller must not duplicate answer engine');

console.log('PROFILE_WEBMCP_AGENT_V110_CONTRACT=PASS');
