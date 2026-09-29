import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const service=read('includes/profile-agent-public-service-v110.php');
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
assert.match(service,/profile_agent_rate_check\(\$pdo,\$cid\)/,'canonical per-conversation rate limit retained');
assert.match(service,/profile_agent_context\(/,'approved Profile Agent context retained');
assert.match(service,/profile_agent_transcript_brain_context_v255/,'transcript brain context retained');
assert.match(service,/homeserver_profile_v235_answer/,'HomeServer Profile Agent compute retained');
assert.match(service,/chat_remote_answer/,'remote answer fallback retained');
assert.match(service,/chat_local_answer/,'local answer fallback retained');
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
